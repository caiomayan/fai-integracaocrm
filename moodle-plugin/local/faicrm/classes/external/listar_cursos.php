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

use context_system;
use core\exception\invalid_parameter_exception;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_faicrm\util;

/**
 * Lists the courses of the site (paginated), including hidden ones. Read only.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class listar_cursos extends external_api {
    /** Fixed page size. */
    public const PORPAGINA = 100;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'pagina' => new external_value(PARAM_INT, 'Page number, starting at 1', VALUE_DEFAULT, 1, NULL_NOT_ALLOWED),
            'visivel' => new external_value(
                PARAM_BOOL,
                'Only visible (true) or only hidden (false) courses; omit for all',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
        ]);
    }

    /**
     * Returns one page of courses ordered by name and id (the front page course is excluded).
     *
     * @param int $pagina Page number (>= 1).
     * @param bool|null $visivel Visibility filter (null = all).
     * @return array Page metadata and courses.
     */
    public static function execute(int $pagina = 1, ?bool $visivel = null): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['pagina' => $pagina, 'visivel' => $visivel]);
        $pagina = $params['pagina'];
        $visivel = $params['visivel'];
        if ($pagina < 1) {
            throw new invalid_parameter_exception('pagina must be greater than or equal to 1');
        }

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/course:viewhiddencourses', $context);

        $where = 'c.id <> :siteid';
        $sqlparams = ['siteid' => SITEID];
        if ($visivel !== null) {
            $where .= ' AND c.visible = :visible';
            $sqlparams['visible'] = $visivel ? 1 : 0;
        }

        $porpagina = self::PORPAGINA;
        $total = (int) $DB->count_records_sql("SELECT COUNT(1) FROM {course} c WHERE $where", $sqlparams);
        $totalpaginas = (int) ceil($total / $porpagina);
        $result = [
            'total' => $total,
            'pagina' => $pagina,
            'porpagina' => $porpagina,
            'totalpaginas' => $totalpaginas,
            'cursos' => [],
        ];
        if ($total === 0 || $pagina > $totalpaginas) {
            return $result;
        }

        $courses = $DB->get_records_sql(
            "SELECT c.id, c.shortname, c.fullname, c.category, cc.name AS categoryname, c.visible, c.startdate, c.enddate
               FROM {course} c
          LEFT JOIN {course_categories} cc ON cc.id = c.category
              WHERE $where
           ORDER BY c.fullname, c.id",
            $sqlparams,
            ($pagina - 1) * $porpagina,
            $porpagina
        );
        foreach ($courses as $course) {
            $result['cursos'][] = [
                'id' => (int) $course->id,
                'shortname' => $course->shortname,
                'nome' => $course->fullname,
                'categoriaid' => (int) $course->category,
                'categoria' => $course->categoryname ?? '',
                'visivel' => (bool) $course->visible,
                'datainicio' => util::format_date($course->startdate),
                'datafim' => util::format_date($course->enddate),
            ];
        }
        return $result;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $date = fn(string $desc) => new external_value(PARAM_RAW, $desc, VALUE_REQUIRED, null, NULL_ALLOWED);
        return new external_single_structure([
            'total' => new external_value(PARAM_INT, 'Total of courses (after the visibility filter)'),
            'pagina' => new external_value(PARAM_INT, 'Current page'),
            'porpagina' => new external_value(PARAM_INT, 'Results per page (fixed)'),
            'totalpaginas' => new external_value(PARAM_INT, 'Total of pages (0 when there are no courses)'),
            'cursos' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Course id'),
                    'shortname' => new external_value(PARAM_RAW, 'Course short name'),
                    'nome' => new external_value(PARAM_RAW, 'Course full name'),
                    'categoriaid' => new external_value(PARAM_INT, 'Category id'),
                    'categoria' => new external_value(PARAM_RAW, 'Category name'),
                    'visivel' => new external_value(PARAM_BOOL, 'Whether the course is visible'),
                    'datainicio' => $date('Course start date (ISO 8601), null when none'),
                    'datafim' => $date('Course end date (ISO 8601), null when none'),
                ])
            ),
        ]);
    }
}
