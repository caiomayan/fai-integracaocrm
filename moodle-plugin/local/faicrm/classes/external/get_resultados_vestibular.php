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
use core\exception\invalid_parameter_exception;
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
require_once($CFG->dirroot . '/mod/quiz/lib.php');

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
    /** Fixed page size (RF-14): not a parameter. */
    public const PORPAGINA = 100;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
            'pagina' => new external_value(PARAM_INT, 'Page number, starting at 1', VALUE_DEFAULT, 1, NULL_NOT_ALLOWED),
            'dataprovade' => new external_value(PARAM_RAW, 'Only candidates whose last finished attempt is at or after this ' .
                'date (YYYY-MM-DD, server time zone; from 00:00:00)', VALUE_DEFAULT, '', NULL_NOT_ALLOWED),
            'dataprovaate' => new external_value(
                PARAM_RAW,
                'Only candidates whose last finished attempt is at or before this ' .
                'date (YYYY-MM-DD, server time zone; until 23:59:59 of the day)',
                VALUE_DEFAULT,
                '',
                NULL_NOT_ALLOWED
            ),
        ]);
    }

    /**
     * Parses a strict date filter (YYYY-MM-DD only) into a timestamp in the server time zone.
     *
     * @param string $value YYYY-MM-DD.
     * @param string $name Parameter name (for the error message).
     * @param bool $endofday Whether the timestamp is the end of the day (23:59:59) instead of the start (00:00:00).
     * @return int Timestamp.
     */
    protected static function parse_date(string $value, string $name, bool $endofday): int {
        if (
            !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) ||
                !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            throw new invalid_parameter_exception($name . ' format');
        }
        $date = new \DateTime('now', \core_date::get_server_timezone_object());
        $date->setDate((int) $m[1], (int) $m[2], (int) $m[3]);
        $endofday ? $date->setTime(23, 59, 59) : $date->setTime(0, 0, 0);
        return $date->getTimestamp();
    }

    /**
     * Formats a timestamp as ISO 8601 in the server time zone.
     *
     * @param int|string|null $timestamp Unix timestamp.
     * @return string|null
     */
    protected static function format_date($timestamp): ?string {
        if (empty($timestamp)) {
            return null;
        }
        $date = new \DateTime('@' . (int) $timestamp);
        $date->setTimezone(\core_date::get_server_timezone_object());
        return $date->format('c');
    }

    /**
     * Computes, in batch for a page of users, the finished attempts and whether each can take the exam now.
     *
     * A fixed number of queries, independent of the number of users: quizzes of the course, attempts,
     * user overrides and active enrolments of the page.
     *
     * @param int $courseid Course id.
     * @param int[] $userids Ids of the users of the page.
     * @return array [userid => ['tentativas' => int, 'podefazerprova' => bool]]
     */
    protected static function calcular_tentativas(int $courseid, array $userids): array {
        global $DB;

        $now = time();
        $result = [];
        foreach ($userids as $userid) {
            $result[$userid] = ['tentativas' => 0, 'podefazerprova' => false];
        }

        $quizzes = $DB->get_records_sql(
            "SELECT q.id, q.attempts, q.timeopen, q.timeclose, cm.visible
               FROM {quiz} q
               JOIN {modules} m ON m.name = 'quiz'
               JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = q.id
              WHERE q.course = :course AND cm.deletioninprogress = 0",
            ['course' => $courseid]
        );
        if (!$quizzes) {
            return $result;
        }
        [$uinsql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'au');
        [$qinsql, $qparams] = $DB->get_in_or_equal(array_keys($quizzes), SQL_PARAMS_NAMED, 'aq');

        // Attempts per quiz and user: finished and abandoned use up the limit; in progress/overdue can be continued.
        $used = [];
        $running = [];
        $rs = $DB->get_recordset_sql(
            "SELECT quiz, userid, state, COUNT(1) AS total
               FROM {quiz_attempts}
              WHERE quiz $qinsql AND userid $uinsql AND preview = 0
           GROUP BY quiz, userid, state",
            $qparams + $uparams
        );
        foreach ($rs as $row) {
            if (in_array($row->state, ['finished', 'abandoned'], true)) {
                $used[$row->quiz][$row->userid] = ($used[$row->quiz][$row->userid] ?? 0) + (int) $row->total;
                if ($row->state === 'finished') {
                    $result[$row->userid]['tentativas'] += (int) $row->total;
                }
            } else {
                $running[$row->quiz][$row->userid] = true;
            }
        }
        $rs->close();

        // User overrides (attempts and opening/closing dates).
        $overrides = [];
        $rs = $DB->get_recordset_sql(
            "SELECT quiz, userid, attempts, timeopen, timeclose
               FROM {quiz_overrides}
              WHERE quiz $qinsql AND userid $uinsql",
            $qparams + $uparams
        );
        foreach ($rs as $row) {
            $overrides[$row->quiz][$row->userid] = $row;
        }
        $rs->close();

        // Active enrolments of the page (enabled instance and plugin, inside the enrolment dates).
        $plugins = array_filter(explode(',', (string) get_config('core', 'enrol_plugins_enabled')));
        [$pinsql, $pparams] = $DB->get_in_or_equal($plugins ?: ['manual'], SQL_PARAMS_NAMED, 'ap');
        $enrolled = $DB->get_records_sql(
            "SELECT DISTINCT ue.userid
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :ecourse AND e.status = :enabled AND e.enrol $pinsql
                    AND ue.status = :active AND ue.userid $uinsql
                    AND ue.timestart <= :now1 AND (ue.timeend = 0 OR ue.timeend > :now2)",
            ['ecourse' => $courseid, 'enabled' => ENROL_INSTANCE_ENABLED, 'active' => ENROL_USER_ACTIVE,
                'now1' => $now, 'now2' => $now] + $pparams + $uparams
        );

        $coursevisible = (bool) get_course($courseid)->visible;
        foreach ($userids as $userid) {
            if (!$coursevisible || !isset($enrolled[$userid])) {
                continue;
            }
            foreach ($quizzes as $quiz) {
                if (!$quiz->visible) {
                    continue;
                }
                if (!empty($running[$quiz->id][$userid])) {
                    $result[$userid]['podefazerprova'] = true;
                    break;
                }
                $override = $overrides[$quiz->id][$userid] ?? null;
                $timeopen = $override && $override->timeopen !== null ? (int) $override->timeopen : (int) $quiz->timeopen;
                $timeclose = $override && $override->timeclose !== null ? (int) $override->timeclose : (int) $quiz->timeclose;
                $limit = $override && $override->attempts !== null ? (int) $override->attempts : (int) $quiz->attempts;
                $open = ($timeopen === 0 || $timeopen <= $now) && ($timeclose === 0 || $now <= $timeclose);
                if ($open && ($limit === 0 || ($used[$quiz->id][$userid] ?? 0) < $limit)) {
                    $result[$userid]['podefazerprova'] = true;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * Computes, in batch for a page of users, the essay score (the first question of the quiz) and the objective score (all
     * the other questions), on the scale of the quiz grade (spec 008).
     *
     * The quiz is the one of the course where the user has finished attempts (more than one quiz: null). The attempt is the
     * one the grading method of the quiz picks. A finished attempt of the quiz waiting for manual grading makes both null.
     * A fixed number of queries, independent of the number of users: the finished attempts and the last step of each question.
     *
     * @param int $courseid Course id.
     * @param int[] $userids Ids of the users of the page.
     * @return array [userid => ['nota_redacao' => float|null, 'nota_objetivas' => float|null]]
     */
    protected static function calcular_notas(int $courseid, array $userids): array {
        global $DB;

        $result = [];
        foreach ($userids as $userid) {
            $result[$userid] = ['nota_redacao' => null, 'nota_objetivas' => null];
        }

        [$uinsql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'nu');
        $attempts = $DB->get_records_sql(
            "SELECT qa.id, qa.userid, qa.quiz, qa.attempt, qa.uniqueid, q.grade, q.sumgrades, q.grademethod
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE q.course = :course AND qa.state = 'finished' AND qa.preview = 0 AND qa.userid $uinsql
           ORDER BY qa.userid, qa.attempt, qa.id",
            ['course' => $courseid] + $uparams
        );
        if (!$attempts) {
            return $result;
        }

        // Last step of every question of those attempts: state, fraction and maximum mark.
        [$qinsql, $qparams] = $DB->get_in_or_equal(array_column($attempts, 'uniqueid'), SQL_PARAMS_NAMED, 'nq');
        $slots = [];
        $rs = $DB->get_recordset_sql(
            "SELECT qatt.id, qatt.questionusageid, qatt.slot, qatt.maxmark, qas.state, qas.fraction
               FROM {question_attempts} qatt
               JOIN {question_attempt_steps} qas ON qas.questionattemptid = qatt.id
              WHERE qatt.questionusageid $qinsql
                    AND qas.sequencenumber = (SELECT MAX(last.sequencenumber)
                                                FROM {question_attempt_steps} last
                                               WHERE last.questionattemptid = qatt.id)",
            $qparams
        );
        foreach ($rs as $row) {
            $slots[$row->questionusageid][(int) $row->slot] = $row;
        }
        $rs->close();

        $byuser = [];
        foreach ($attempts as $attempt) {
            $byuser[$attempt->userid][] = $attempt;
        }
        foreach ($byuser as $userid => $list) {
            $notas = self::notas_do_usuario($list, $slots);
            if ($notas !== null) {
                $result[$userid] = $notas;
            }
        }
        return $result;
    }

    /**
     * The two scores of one user, from the finished attempts (ordered by attempt number) and the last step of each question.
     *
     * @param \stdClass[] $list Finished attempts of the user, with the quiz grade, sumgrades and grademethod.
     * @param array $slots [uniqueid => [slot => row with maxmark, state, fraction]]
     * @return array|null ['nota_redacao' => float, 'nota_objetivas' => float], or null when it can not be known
     */
    protected static function notas_do_usuario(array $list, array $slots): ?array {
        $quizzes = array_unique(array_column($list, 'quiz'));
        $quiz = $list[0];
        if (count($quizzes) > 1 || (float) $quiz->sumgrades <= 0) {
            return null;
        }
        $factor = (float) $quiz->grade / (float) $quiz->sumgrades;

        $parts = [];
        foreach ($list as $attempt) {
            $questions = $slots[$attempt->uniqueid] ?? [];
            ksort($questions);
            $essay = null;
            $total = 0.0;
            foreach ($questions as $slot => $question) {
                if ($question->state === 'needsgrading') {
                    return null;
                }
                $mark = (float) $question->fraction * (float) $question->maxmark;
                $total += $mark;
                if ($essay === null && (float) $question->maxmark > 0) {
                    $essay = $mark;
                }
            }
            if ($essay === null) {
                return null;
            }
            $essayscore = round($essay * $factor, 2);
            $totalscore = round($total * $factor, 2);
            $parts[] = ['redacao' => $essayscore, 'objetivas' => round($totalscore - $essayscore, 2), 'total' => $totalscore];
        }

        switch ((int) $quiz->grademethod) {
            case QUIZ_ATTEMPTFIRST:
                $chosen = $parts[0];
                break;
            case QUIZ_ATTEMPTLAST:
                $chosen = $parts[count($parts) - 1];
                break;
            case QUIZ_GRADEAVERAGE:
                $chosen = [
                    'redacao' => round(array_sum(array_column($parts, 'redacao')) / count($parts), 2),
                    'objetivas' => round(array_sum(array_column($parts, 'objetivas')) / count($parts), 2),
                ];
                break;
            default:
                // Highest grade; a tie keeps the first attempt.
                $chosen = $parts[0];
                foreach ($parts as $part) {
                    if ($part['total'] > $chosen['total']) {
                        $chosen = $part;
                    }
                }
        }
        return ['nota_redacao' => (float) $chosen['redacao'], 'nota_objetivas' => (float) $chosen['objetivas']];
    }

    /**
     * Returns one page of the users with the student role in the course, with course total, completion and dates.
     *
     * @param int $courseid Course id.
     * @param int $pagina Page number (>= 1).
     * @param string $dataprovade Optional lower bound for the exam date.
     * @param string $dataprovaate Optional upper bound for the exam date.
     * @return array Page metadata and the list of results ordered by lastname, firstname, id.
     */
    public static function execute(
        int $courseid,
        int $pagina = 1,
        string $dataprovade = '',
        string $dataprovaate = ''
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'pagina' => $pagina,
            'dataprovade' => $dataprovade, 'dataprovaate' => $dataprovaate,
        ]);
        $courseid = $params['courseid'];
        $pagina = $params['pagina'];
        $porpagina = self::PORPAGINA;

        if ($pagina < 1) {
            throw new invalid_parameter_exception('pagina must be greater than or equal to 1');
        }
        $from = trim($params['dataprovade']) === '' ? null : self::parse_date($params['dataprovade'], 'dataprovade', false);
        $to = trim($params['dataprovaate']) === '' ? null : self::parse_date($params['dataprovaate'], 'dataprovaate', true);
        if ($from !== null && $to !== null && $from > $to) {
            throw new invalid_parameter_exception('dataprovade after dataprovaate');
        }

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/grade:viewall', $context);
        require_capability('moodle/course:viewparticipants', $context);

        $role = $DB->get_record('role', ['shortname' => 'student'], 'id');
        if (!$role) {
            throw new moodle_exception('studentrolenotfound', 'local_faicrm');
        }

        // Last finished attempt (the exam date) of each user, over the quizzes of the course.
        $lastattempt = "SELECT qa.userid, MAX(qa.timefinish) AS lastfinish
                          FROM {quiz_attempts} qa
                          JOIN {quiz} q ON q.id = qa.quiz
                         WHERE q.course = :qcourse AND qa.state = 'finished' AND qa.preview = 0
                      GROUP BY qa.userid";

        // The same FROM/WHERE feeds the total and the page.
        $sqlparams = ['ctxid' => $context->id, 'roleid' => $role->id];
        $join = '';
        $where = 'u.deleted = 0';
        if ($from !== null || $to !== null) {
            $join = "JOIN ($lastattempt) lp ON lp.userid = u.id";
            $sqlparams['qcourse'] = $courseid;
            if ($from !== null) {
                $where .= ' AND lp.lastfinish >= :datafrom';
                $sqlparams['datafrom'] = $from;
            }
            if ($to !== null) {
                $where .= ' AND lp.lastfinish <= :datato';
                $sqlparams['datato'] = $to;
            }
        }
        $fromwhere = "FROM {user} u
                      JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = :ctxid AND ra.roleid = :roleid
                      $join
                     WHERE $where";

        $total = (int) $DB->count_records_sql("SELECT COUNT(DISTINCT u.id) $fromwhere", $sqlparams);
        $totalpaginas = (int) ceil($total / $porpagina);
        $page = [
            'total' => $total,
            'pagina' => $pagina,
            'porpagina' => $porpagina,
            'totalpaginas' => $totalpaginas,
            'candidatos' => [],
        ];
        if ($total === 0 || $pagina > $totalpaginas) {
            return $page;
        }

        $users = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname, u.email
               $fromwhere
           ORDER BY u.lastname, u.firstname, u.id",
            $sqlparams,
            ($pagina - 1) * $porpagina,
            $porpagina
        );
        if (empty($users)) {
            return $page;
        }
        $userids = array_keys($users);

        $coursegrades = grade_get_course_grades($courseid, $userids);

        // One query per kind of data for the whole page.
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $completions = $DB->get_records_select('course_completions', "course = :courseid AND userid $insql " .
            'AND timecompleted IS NOT NULL', ['courseid' => $courseid] + $inparams, '', 'userid, timecompleted');
        $enrolments = $DB->get_records_sql(
            "SELECT ue.userid, MIN(ue.timecreated) AS firstenrol
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :ecourse AND ue.userid $insql
           GROUP BY ue.userid",
            ['ecourse' => $courseid] + $inparams
        );
        $attempts = $DB->get_records_sql(
            "SELECT lpa.userid, lpa.lastfinish FROM ($lastattempt) lpa WHERE lpa.userid $insql",
            ['qcourse' => $courseid] + $inparams
        );

        $tentativas = self::calcular_tentativas($courseid, $userids);
        $notas = self::calcular_notas($courseid, $userids);

        foreach ($users as $user) {
            $nota = null;
            if (isset($coursegrades->grades[$user->id])) {
                $grade = $coursegrades->grades[$user->id]->grade;
                // Null means not graded; false means the grade is pending regrade.
                if ($grade !== null && $grade !== false) {
                    $nota = (float) $grade;
                }
            }

            $page['candidatos'][] = [
                'username' => $user->username,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'courseid' => $courseid,
                'nota' => $nota,
                'nota_redacao' => $notas[$user->id]['nota_redacao'],
                'nota_objetivas' => $notas[$user->id]['nota_objetivas'],
                'concluido' => isset($completions[$user->id]),
                'datamatricula' => self::format_date($enrolments[$user->id]->firstenrol ?? null),
                'dataprova' => self::format_date($attempts[$user->id]->lastfinish ?? null),
                'dataconclusao' => self::format_date($completions[$user->id]->timecompleted ?? null),
                'tentativas' => $tentativas[$user->id]['tentativas'],
                'podefazerprova' => $tentativas[$user->id]['podefazerprova'],
            ];
        }

        return $page;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $date = fn(string $desc) => new external_value(PARAM_RAW, $desc, VALUE_REQUIRED, null, NULL_ALLOWED);
        return new external_single_structure([
            'total' => new external_value(PARAM_INT, 'Total of students in the course (after the date filter)'),
            'pagina' => new external_value(PARAM_INT, 'Current page'),
            'porpagina' => new external_value(PARAM_INT, 'Results per page (fixed)'),
            'totalpaginas' => new external_value(PARAM_INT, 'Total of pages (0 when there are no students)'),
            'candidatos' => new external_multiple_structure(
                new external_single_structure([
                    'username' => new external_value(PARAM_RAW, 'Username'),
                    'firstname' => new external_value(PARAM_NOTAGS, 'First name'),
                    'lastname' => new external_value(PARAM_NOTAGS, 'Last name'),
                    'email' => new external_value(PARAM_RAW, 'Email address'),
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'nota' => new external_value(
                        PARAM_FLOAT,
                        'Course total (raw value), null when not graded yet',
                        VALUE_REQUIRED,
                        null,
                        NULL_ALLOWED
                    ),
                    'nota_redacao' => new external_value(
                        PARAM_FLOAT,
                        'Score of the first question of the quiz (the essay), on the scale of the quiz grade, 2 decimals; ' .
                        'null when there is no finished attempt, the correction is pending or the quiz is ambiguous',
                        VALUE_REQUIRED,
                        null,
                        NULL_ALLOWED
                    ),
                    'nota_objetivas' => new external_value(
                        PARAM_FLOAT,
                        'Score of all the other questions of the quiz, on the scale of the quiz grade, 2 decimals (the essay ' .
                        'plus the objectives is the quiz grade); null when there is no finished attempt, the correction is ' .
                        'pending or the quiz is ambiguous',
                        VALUE_REQUIRED,
                        null,
                        NULL_ALLOWED
                    ),
                    'concluido' => new external_value(PARAM_BOOL, 'Whether the user has completed the course'),
                    'datamatricula' => $date('Enrolment date (ISO 8601), null when none'),
                    'dataprova' => $date('End of the last finished quiz attempt (ISO 8601), null when none'),
                    'dataconclusao' => $date('Course completion date (ISO 8601), null when not completed'),
                    'tentativas' => new external_value(PARAM_INT, 'Finished attempts in the quizzes of the course'),
                    'podefazerprova' => new external_value(PARAM_BOOL, 'Whether the user can start or continue an attempt now'),
                ])
            ),
        ]);
    }
}
