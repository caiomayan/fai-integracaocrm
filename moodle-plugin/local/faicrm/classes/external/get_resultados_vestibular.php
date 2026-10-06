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

namespace local_faicrm\external;

use completion_completion;
use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/querylib.php');
require_once($CFG->dirroot . '/completion/completion_completion.php');

/**
 * Returns the vestibular results (course total and course completion) of the students of a course.
 *
 * Read only: this function does not write any data.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_resultados_vestibular extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
        ]);
    }

    /**
     * Returns, for each user with the student role in the course, the course total and completion status.
     *
     * @param int $courseid Course id.
     * @return array List of results ordered by lastname, firstname.
     */
    public static function execute(int $courseid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $courseid = $params['courseid'];

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/grade:viewall', $context);
        require_capability('moodle/course:viewparticipants', $context);

        $role = $DB->get_record('role', ['shortname' => 'student'], 'id');
        if (!$role) {
            throw new moodle_exception('studentrolenotfound', 'local_faicrm');
        }

        $users = get_role_users($role->id, $context, false,
            'u.id, u.username, u.firstname, u.lastname, u.email', 'u.lastname, u.firstname');
        if (empty($users)) {
            return [];
        }

        $coursegrades = grade_get_course_grades($courseid, array_keys($users));

        $results = [];
        foreach ($users as $user) {
            $nota = null;
            if (isset($coursegrades->grades[$user->id])) {
                $grade = $coursegrades->grades[$user->id]->grade;
                // Null means not graded; false means the grade is pending regrade.
                if ($grade !== null && $grade !== false) {
                    $nota = (float) $grade;
                }
            }

            $completion = new completion_completion(['userid' => $user->id, 'course' => $courseid]);

            $results[] = [
                'username' => $user->username,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'courseid' => $courseid,
                'nota' => $nota,
                'concluido' => $completion->is_complete(),
            ];
        }

        return $results;
    }

    /**
     * Describes the return value.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'username' => new external_value(PARAM_RAW, 'Username'),
                'firstname' => new external_value(PARAM_NOTAGS, 'First name'),
                'lastname' => new external_value(PARAM_NOTAGS, 'Last name'),
                'email' => new external_value(PARAM_RAW, 'Email address'),
                'courseid' => new external_value(PARAM_INT, 'Course id'),
                'nota' => new external_value(PARAM_FLOAT, 'Course total (raw value), null when not graded yet',
                    VALUE_REQUIRED, null, NULL_ALLOWED),
                'concluido' => new external_value(PARAM_BOOL, 'Whether the user has completed the course'),
            ])
        );
    }
}
