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

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_faicrm\util;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Lists the quizzes (provas) of a course. Read only.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class listar_provas extends external_api {
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
     * Returns the quizzes of the course, ordered by name and id.
     *
     * @param int $courseid Course id.
     * @return array Course id and quizzes.
     */
    public static function execute(int $courseid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $courseid = $params['courseid'];

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('mod/quiz:view', $context);

        $methods = [
            QUIZ_GRADEHIGHEST => 'maior',
            QUIZ_GRADEAVERAGE => 'media',
            QUIZ_ATTEMPTFIRST => 'primeira',
            QUIZ_ATTEMPTLAST => 'ultima',
        ];
        $quizzes = $DB->get_records_sql(
            "SELECT q.id, cm.id AS cmid, q.name, cm.visible, q.grade, q.attempts, q.grademethod, q.timeopen, q.timeclose
               FROM {quiz} q
               JOIN {modules} m ON m.name = 'quiz'
               JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = q.id
              WHERE q.course = :course AND cm.deletioninprogress = 0
           ORDER BY q.name, q.id",
            ['course' => $courseid]
        );
        $provas = [];
        foreach ($quizzes as $quiz) {
            $provas[] = [
                'id' => (int) $quiz->id,
                'cmid' => (int) $quiz->cmid,
                'nome' => $quiz->name,
                'visivel' => (bool) $quiz->visible,
                'notamaxima' => (float) $quiz->grade,
                'tentativaspermitidas' => (int) $quiz->attempts,
                'metodonota' => $methods[(int) $quiz->grademethod] ?? 'maior',
                'abertura' => util::format_date($quiz->timeopen),
                'fechamento' => util::format_date($quiz->timeclose),
            ];
        }
        return ['courseid' => $courseid, 'provas' => $provas];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $date = fn(string $desc) => new external_value(PARAM_RAW, $desc, VALUE_REQUIRED, null, NULL_ALLOWED);
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'provas' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Quiz id'),
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'nome' => new external_value(PARAM_RAW, 'Quiz name'),
                    'visivel' => new external_value(PARAM_BOOL, 'Whether the quiz is visible'),
                    'notamaxima' => new external_value(PARAM_FLOAT, 'Maximum grade'),
                    'tentativaspermitidas' => new external_value(PARAM_INT, 'Attempts allowed (0 = unlimited)'),
                    'metodonota' => new external_value(PARAM_ALPHA, 'Grading method: maior, media, primeira or ultima'),
                    'abertura' => $date('Opening date (ISO 8601), null when none'),
                    'fechamento' => $date('Closing date (ISO 8601), null when none'),
                ])
            ),
        ]);
    }
}
