<?php
// This file is part of Moodle - http://moodle.org/

/**
 * External function to set a manual grade on a single essay question slot
 * within a quiz attempt.
 *
 * @package    local_aigrader
 * @copyright  2026 Your Organisation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aigrader\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/lib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use context_module;
use mod_quiz\quiz_attempt;
use question_engine;
use invalid_parameter_exception;
use moodle_exception;

class set_essay_grade extends external_api {

    /**
     * Parameter definition for execute().
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'quiz attempt id (from mdl_quiz_attempts)'),
            'slot'      => new external_value(PARAM_INT, 'question slot number within the attempt'),
            'grade'     => new external_value(PARAM_FLOAT, 'grade (mark) to award for this question, in the question\'s own mark scale'),
            'feedback'  => new external_value(PARAM_RAW, 'feedback comment to attach to the question, may contain HTML', VALUE_DEFAULT, ''),
            'feedbackformat' => new external_value(PARAM_INT, 'FORMAT_* constant for the feedback text', VALUE_DEFAULT, FORMAT_HTML),
        ]);
    }

    /**
     * Grade one essay question slot in a quiz attempt and push the result
     * through Moodle's normal quiz regrading / gradebook update pipeline.
     *
     * @param int $attemptid
     * @param int $slot
     * @param float $grade
     * @param string $feedback
     * @param int $feedbackformat
     * @return array
     */
    public static function execute(int $attemptid, int $slot, float $grade,
            string $feedback = '', int $feedbackformat = FORMAT_HTML): array {

        global $DB, $USER;

        // 1. Validate parameters against the declared structure.
        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid'      => $attemptid,
            'slot'           => $slot,
            'grade'          => $grade,
            'feedback'       => $feedback,
            'feedbackformat' => $feedbackformat,
        ]);

        // 2. Load the attempt. quiz_attempt::create() throws if the id is bad.
        try {
            $attemptobj = quiz_attempt::create($params['attemptid']);
        } catch (\Exception $e) {
            throw new moodle_exception('invalidattemptid', 'local_aigrader', '', null, $e->getMessage());
        }

        // 3. Validate context and capability. This is the standard Moodle
        // web-service security pattern: set the correct context BEFORE doing
        // anything else, then require the capability that governs this action.
        //
        // We check local/aigrader:setessaygrade rather than mod/quiz:grade
        // so an AI service account can be granted exactly this permission
        // and nothing else — it cannot use the full teacher grading UI,
        // even if its token or account credentials are ever exposed.
        $context = context_module::instance($attemptobj->get_cmid());
        self::validate_context($context);
        require_capability('local/aigrader:setessaygrade', $context);

        // 4. Make sure the slot actually exists and is an essay-type question
        // that is currently pending manual grading. This avoids silently
        // "grading" the wrong slot or overwriting an auto-graded question.
        $qa = $attemptobj->get_question_attempt($params['slot']);
        if ($qa === null) {
            throw new invalid_parameter_exception('No question found in slot ' . $params['slot']
                . ' for attempt ' . $params['attemptid']);
        }
        if ($qa->get_question()->get_type_name() !== 'essay') {
            throw new invalid_parameter_exception('Slot ' . $params['slot'] . ' is not an essay question.');
        }

        // 5. Perform the actual manual grading action using the question
        // engine's own API for this — question_usage_by_activity::manual_grade().
        // This is the same method Moodle's own quiz grading report and test
        // suite use (see mod/quiz/tests/quiz_notify_attempt_manual_grading_completed_test.php).
        //
        // Note: we deliberately do NOT use quiz_attempt::process_submitted_actions()
        // for this. Its $simulatedresponses parameter is for simulating a
        // STUDENT'S ANSWER to a question (i.e. faking what they typed), not
        // for submitting a teacher's mark/comment — passing mark/comment
        // data through it is silently ignored. manual_grade() is the correct,
        // purpose-built API for grading actions.
        //
        // We load the question usage via question_engine::load_questions_usage_by_activity()
        // rather than $attemptobj->get_question_usage(), since the latter is
        // explicitly restricted by Moodle core to unit-test contexts only.
        $transaction = $DB->start_delegated_transaction();

        $quba = question_engine::load_questions_usage_by_activity($attemptobj->get_uniqueid());
        $quba->manual_grade($params['slot'], $params['feedback'], $params['grade'], $params['feedbackformat']);
        question_engine::save_questions_usage_by_activity($quba);

        // 6. Update the attempt's sumgrades/timemodified and push the result
        // through to the gradebook. This mirrors exactly what
        // quiz_attempt::process_submitted_actions() itself does internally
        // after a grading action, so gradebook behaviour stays consistent
        // with what the built-in grading UI produces.
        //
        // get_attempt() returns the attempt's live stdClass by reference, so
        // mutating it here keeps $attemptobj's internal state in sync before
        // we call recompute_final_grade() below.
        $attempt = $attemptobj->get_attempt();
        $attempt->timemodified = time();
        if ($attempt->state === \mod_quiz\quiz_attempt::FINISHED) {
            $attempt->sumgrades = $quba->get_total_mark();
        }
        $DB->update_record('quiz_attempts', $attempt);

        if (!$attemptobj->is_preview() && $attempt->state === \mod_quiz\quiz_attempt::FINISHED) {
            $attemptobj->get_quizobj()->get_grade_calculator()->recompute_final_grade($attemptobj->get_userid());
        }

        $transaction->allow_commit();

        // 7. Re-fetch the attempt to return fresh totals to the caller.
        $attemptobj = quiz_attempt::create($params['attemptid']);
        $qa = $attemptobj->get_question_attempt($params['slot']);

        return [
            'attemptid'   => $params['attemptid'],
            'slot'        => $params['slot'],
            'mark'        => (float) $qa->get_mark(),
            'maxmark'     => (float) $qa->get_max_mark(),
            'sumgrades'   => (float) $attemptobj->get_sum_marks(),
            'quizgrade'   => (float) $attemptobj->get_quiz()->grade,
            'status'      => $qa->get_state_class(true), // e.g. "graded"/"manuallygraded"
        ];
    }

    /**
     * Return structure definition for execute().
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'quiz attempt id'),
            'slot'      => new external_value(PARAM_INT, 'question slot number'),
            'mark'      => new external_value(PARAM_FLOAT, 'mark now recorded for this question'),
            'maxmark'   => new external_value(PARAM_FLOAT, 'maximum mark available for this question'),
            'sumgrades' => new external_value(PARAM_FLOAT, 'recalculated raw total for the whole attempt'),
            'quizgrade' => new external_value(PARAM_FLOAT, 'the quiz\'s configured maximum grade'),
            'status'    => new external_value(PARAM_ALPHANUMEXT, 'resulting question state, e.g. manuallygraded'),
        ]);
    }
}
