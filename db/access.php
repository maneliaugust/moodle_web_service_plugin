<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Capabilities for local_aigrader.
 *
 * Defines local/aigrader:setessaygrade, a narrow capability that grants
 * only the ability to call the local_aigrader_set_essay_grade web service
 * function. This is deliberately kept separate from mod/quiz:grade so an
 * AI grading service account can be given exactly this permission and
 * nothing else — it will not be able to log into the quiz grading UI,
 * edit marks by hand, or do anything else a full teacher role can do,
 * even if its token is exposed or its account reused elsewhere.
 *
 * No archetypes are granted this by default. You explicitly assign it to
 * whatever role your AI service account holds (see README.md for the
 * suggested setup).
 *
 * Note: the AI service account's role also needs two CORE capabilities
 * that this file does not define (they already exist in Moodle):
 * mod/quiz:view and webservice/rest:use. See README.md for why.
 *
 * @package    local_aigrader
 * @copyright  2026 Your Organisation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/aigrader:setessaygrade' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'   => [
            // Deliberately empty: this capability is not granted to any
            // built-in role by default. Create a dedicated role for your
            // AI service account and assign it there (see README.md).
        ],
    ],
];

