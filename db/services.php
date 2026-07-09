<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Web service definitions for local_aigrader.
 *
 * @package    local_aigrader
 * @copyright  2026 Your Organisation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_aigrader_set_essay_grade' => [
        'classname'   => 'local_aigrader\external\set_essay_grade',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Grade a single essay question slot within a quiz attempt and update the gradebook.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/aigrader:setessaygrade',
    ],
];

// A dedicated external service so you can scope a token to just this function
// rather than granting a token access to every enabled web service function.
$services = [
    'AI Essay Grader' => [
        'functions'       => ['local_aigrader_set_essay_grade'],
        'restrictedusers' => 1,     // Requires explicit user authorisation for this service.
        'enabled'         => 1,
        'shortname'       => 'local_aigrader_service',
        'downloadfiles'   => 0,
        'uploadfiles'     => 0,
    ],
];
