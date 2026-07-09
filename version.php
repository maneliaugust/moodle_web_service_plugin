<?php
// This file is part of Moodle - http://moodle.org/
//
// This plugin is free software distributed under the same terms as Moodle.

/**
 * Version details for local_aigrader.
 *
 * @package    local_aigrader
 * @copyright  2026 Your Organisation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_aigrader';
$plugin->version   = 2026070705;      // YYYYMMDDXX
$plugin->requires  = 2024100700;      // Moodle 4.5 (the version 5.3 upgrades from)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.2.0';

// Note: this plugin has been built and tested end-to-end against your
// live Moodle 5.3dev (build 20260624) instance — the code reflects the
// actual current APIs (mod_quiz\quiz_attempt namespace, core_external\
// classes, question_usage_by_activity::manual_grade(), and
// $quizobj->get_grade_calculator()->recompute_final_grade()), not
// assumptions from older documentation. See README.md's "Notes" section
// for the full list of API differences we hit and worked around.
// Since 5.3 is still an active dev branch (code freeze 24 Aug 2026), a
// future build could still shift things further — if you pull a newer
// build and something breaks, that's the first place to look.
