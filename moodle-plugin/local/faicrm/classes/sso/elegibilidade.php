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

namespace local_faicrm\sso;

use context_course;
use context_module;
use context_system;
use core_course_category;
use moodle_exception;
use stdClass;

/**
 * Rules of the automatic access to the quiz: which quizzes and which accounts may be given a login link.
 *
 * Each method throws when the rule fails and returns quietly otherwise.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class elegibilidade {
    /** Capabilities a candidate account must not have in the system context. */
    public const DANGEROUS_CAPABILITIES = [
        'moodle/site:config',
        'moodle/user:create',
        'moodle/user:update',
        'moodle/role:assign',
        'moodle/course:update',
        'moodle/user:loginas',
    ];

    /** Extra capabilities a candidate must not have in the quiz context (a wrong override on the Student role). */
    public const DANGEROUS_QUIZ_CAPABILITIES = [
        'mod/quiz:viewreports',
        'mod/quiz:grade',
        'mod/quiz:manage',
        'mod/quiz:manageoverrides',
        'moodle/grade:viewall',
    ];

    /**
     * Category ids released for the automatic access (setting local_faicrm/sso_categorias), as the multiselect stores them.
     *
     * @return int[]
     */
    public static function configured_categories(): array {
        $value = (string) get_config('local_faicrm', 'sso_categorias');
        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $value)), 'strlen'));
        return array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
    }

    /**
     * Released categories plus all their subcategories.
     *
     * @return int[]
     */
    public static function released_categories(): array {
        $all = [];
        foreach (self::configured_categories() as $id) {
            $category = core_course_category::get($id, IGNORE_MISSING, true);
            if ($category) {
                $all[] = (int) $category->id;
                $all = array_merge($all, array_map('intval', $category->get_all_children_ids()));
            }
        }
        return array_values(array_unique($all));
    }

    /**
     * The feature is off when no category is released (fails closed).
     *
     * @throws moodle_exception ssoindisponivel
     */
    public static function require_categories(): void {
        if (!self::released_categories()) {
            throw new moodle_exception('ssoindisponivel', 'local_faicrm');
        }
    }

    /**
     * Finds the quiz by its course module id.
     *
     * @param int $cmid Course module id.
     * @return stdClass The course module.
     * @throws \dml_missing_record_exception when it is not a quiz or is being deleted.
     */
    public static function find_quiz(int $cmid): stdClass {
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm || !empty($cm->deletioninprogress)) {
            throw new \dml_missing_record_exception('quiz');
        }
        return $cm;
    }

    /**
     * The course of the quiz must be inside a released category.
     *
     * @param stdClass $course Course of the quiz.
     * @throws moodle_exception provanaoliberada
     */
    public static function require_released_quiz(stdClass $course): void {
        if (!in_array((int) $course->category, self::released_categories(), true)) {
            throw new moodle_exception('provanaoliberada', 'local_faicrm');
        }
    }

    /**
     * Finds the candidate by username (local accounts only, not deleted).
     *
     * @param string $username Username (CPF).
     * @return stdClass The user.
     * @throws \dml_missing_record_exception
     */
    public static function find_candidate(string $username): stdClass {
        global $CFG, $DB;
        $user = $DB->get_record('user', [
            'username' => \core_text::strtolower(trim($username)),
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ], '*', IGNORE_MISSING);
        if (!$user) {
            // No SQL and no parameters in the exception: the CPF must not reach the error log.
            throw new \dml_missing_record_exception('user');
        }
        return $user;
    }

    /**
     * The account must be of a candidate: no admin, no guest, no other role than Student, every role only in
     * courses (or activities) of the released categories, and no dangerous permission in the system context.
     *
     * @param stdClass $user The user.
     * @param context_module $quizcontext Context of the quiz (permissions are checked there too).
     * @throws moodle_exception contanaopermitida
     */
    public static function require_candidate_account(stdClass $user, context_module $quizcontext): void {
        global $DB;

        if (is_siteadmin($user) || isguestuser($user)) {
            throw new moodle_exception('contanaopermitida', 'local_faicrm');
        }

        // Role assignments that are not Student in a course (or activity) of a released category.
        $categories = self::released_categories();
        [$coursecatsql, $coursecatparams] = $DB->get_in_or_equal($categories, SQL_PARAMS_NAMED, 'catc');
        [$modcatsql, $modcatparams] = $DB->get_in_or_equal($categories, SQL_PARAMS_NAMED, 'catm');
        $bad = $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.userid = :userid
                AND (r.shortname <> :student
                     OR NOT (
                         (ctx.contextlevel = :levelcourse AND EXISTS (
                             SELECT 1 FROM {course} c
                              WHERE c.id = ctx.instanceid AND c.category $coursecatsql))
                         OR (ctx.contextlevel = :levelmodule AND EXISTS (
                             SELECT 1 FROM {course_modules} cm
                               JOIN {course} cmc ON cmc.id = cm.course
                              WHERE cm.id = ctx.instanceid AND cmc.category $modcatsql))
                     ))",
            [
                'userid' => $user->id,
                'student' => 'student',
                'levelcourse' => CONTEXT_COURSE,
                'levelmodule' => CONTEXT_MODULE,
            ] + $coursecatparams + $modcatparams
        );
        if ($bad > 0) {
            throw new moodle_exception('contanaopermitida', 'local_faicrm');
        }

        // Permissions given to every authenticated user (or by a system override) are not visible in the assignments.
        $system = context_system::instance();
        foreach (self::DANGEROUS_CAPABILITIES as $capability) {
            if (has_capability($capability, $system, $user)) {
                throw new moodle_exception('contanaopermitida', 'local_faicrm');
            }
        }

        // In the quiz (which inherits course and category): the same permissions plus the quiz ones.
        foreach (array_merge(self::DANGEROUS_CAPABILITIES, self::DANGEROUS_QUIZ_CAPABILITIES) as $capability) {
            if (has_capability($capability, $quizcontext, $user)) {
                throw new moodle_exception('contanaopermitida', 'local_faicrm');
            }
        }
    }

    /**
     * The account must be able to log in: not suspended, confirmed, not "nologin" and with an enabled authentication.
     *
     * @param stdClass $user The user.
     * @throws moodle_exception candidatosuspenso
     */
    public static function require_active_account(stdClass $user): void {
        if ($user->suspended || !$user->confirmed || $user->auth === 'nologin' || !is_enabled_auth($user->auth)) {
            throw new moodle_exception('candidatosuspenso', 'local_faicrm');
        }
    }

    /**
     * The account must be able to attempt the quiz. Checked after the enrolment, so a candidate who is not enrolled
     * gets the enrolment error; an enrolment without any role is refused here.
     *
     * @param stdClass $user The user.
     * @param context_module $quizcontext Context of the quiz.
     * @throws moodle_exception contanaopermitida
     */
    public static function require_can_attempt(stdClass $user, context_module $quizcontext): void {
        if (!has_capability('mod/quiz:attempt', $quizcontext, $user)) {
            throw new moodle_exception('contanaopermitida', 'local_faicrm');
        }
    }

    /**
     * The candidate must have an active enrolment in the course of the quiz.
     *
     * @param stdClass $user The user.
     * @param stdClass $course Course of the quiz.
     * @throws moodle_exception candidatonaomatriculado
     */
    public static function require_enrolment(stdClass $user, stdClass $course): void {
        if (!is_enrolled(context_course::instance($course->id), $user, '', true)) {
            throw new moodle_exception('candidatonaomatriculado', 'local_faicrm');
        }
    }
}
