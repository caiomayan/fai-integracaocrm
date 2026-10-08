<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Web service functions and pre-built service for local_faicrm.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_faicrm_get_resultados_vestibular' => [
        'classname' => 'local_faicrm\external\get_resultados_vestibular',
        'methodname' => 'execute',
        'description' => 'Returns, for every student of a course, the raw course total grade and the course completion status.',
        'type' => 'read',
        'capabilities' => 'moodle/grade:viewall, moodle/course:viewparticipants',
    ],
    'local_faicrm_listar_cursos' => [
        'classname' => 'local_faicrm\external\listar_cursos',
        'methodname' => 'execute',
        'description' => 'Lists the courses of the site (paginated), including hidden ones.',
        'type' => 'read',
        'capabilities' => 'moodle/course:viewhiddencourses',
    ],
    'local_faicrm_listar_provas' => [
        'classname' => 'local_faicrm\external\listar_provas',
        'methodname' => 'execute',
        'description' => 'Lists the quizzes of a course with attempts, grading method and dates.',
        'type' => 'read',
        'capabilities' => 'mod/quiz:view',
    ],
    'local_faicrm_liberar_nova_tentativa' => [
        'classname' => 'local_faicrm\external\liberar_nova_tentativa',
        'methodname' => 'execute',
        'description' => 'Releases one more quiz attempt for one candidate through a native user override.',
        'type' => 'write',
        'capabilities' => 'mod/quiz:manageoverrides',
    ],
    'local_faicrm_cancelar_nova_tentativa' => [
        'classname' => 'local_faicrm\external\cancelar_nova_tentativa',
        'methodname' => 'execute',
        'description' => 'Cancels the extra attempt released for a candidate (removes the quiz user override).',
        'type' => 'write',
        'capabilities' => 'mod/quiz:manageoverrides',
    ],
];

$services = [
    'CRM Vestibular FAI' => [
        'shortname' => 'crm_vestibular_fai',
        'enabled' => 1,
        'restrictedusers' => 1,
        'downloadfiles' => 0,
        'uploadfiles' => 0,
        'functions' => [
            'core_user_create_users',
            'core_user_get_users_by_field',
            'enrol_manual_enrol_users',
            'enrol_manual_unenrol_users',
            'core_course_get_courses_by_field',
            'core_enrol_get_enrolled_users',
            'mod_quiz_get_quizzes_by_courses',
            'mod_quiz_get_user_attempts',
            'core_completion_get_course_completion_status',
            'gradereport_user_get_grade_items',
            'local_faicrm_get_resultados_vestibular',
            'local_faicrm_listar_cursos',
            'local_faicrm_listar_provas',
            'local_faicrm_liberar_nova_tentativa',
            'local_faicrm_cancelar_nova_tentativa',
        ],
    ],
];
