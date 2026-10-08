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
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_faicrm\util;
use mod_quiz\local\override_manager;
use mod_quiz\quiz_settings;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Releases one more quiz attempt for ONE candidate, using a native user override of the quiz.
 *
 * Nothing is deleted: the previous attempts stay in the history.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class liberar_nova_tentativa extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'quizid' => new external_value(PARAM_INT, 'Quiz id', VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
            'userid' => new external_value(PARAM_INT, 'User id', VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
            'prazo' => new external_value(
                PARAM_RAW,
                'Optional deadline for this candidate (YYYY-MM-DD, until 23:59:59 server time)',
                VALUE_DEFAULT,
                '',
                NULL_NOT_ALLOWED
            ),
        ]);
    }

    /**
     * Grants one extra attempt to the user.
     *
     * Checks, in order: quiz and user exist, active enrolment, no attempt in progress, no attempt left,
     * valid deadline, quiz not closed without a deadline.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @param string $prazo Optional deadline (YYYY-MM-DD).
     * @return array Quiz id, user id, attempts allowed and deadline.
     */
    public static function execute(int $quizid, int $userid, string $prazo = ''): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'quizid' => $quizid, 'userid' => $userid, 'prazo' => $prazo,
        ]);
        $quizid = $params['quizid'];
        $userid = $params['userid'];
        $prazo = trim($params['prazo']);

        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id', MUST_EXIST);

        $quiz->cmid = $cm->id;
        $manager = new override_manager($quiz, $context);
        $manager->require_manage_capability();

        if (!is_enrolled(context_course::instance($quiz->course), $userid, '', true)) {
            throw new moodle_exception('candidatonaomatriculado', 'local_faicrm');
        }
        // Checks and save under the candidate lock: two simultaneous calls must not both grant (or duplicate).
        $lock = util::lock_candidate($quiz->id, $userid);
        try {
            [$finished, $inprogress] = util::count_attempts($quiz->id, $userid);
            if ($inprogress > 0) {
                throw new moodle_exception('tentativaemandamento', 'local_faicrm');
            }
            // Effective settings of this user (the quiz settings with the existing overrides applied).
            $effective = quiz_settings::create($quiz->id, $userid)->get_quiz();
            if ((int) $effective->attempts === 0 || $finished < (int) $effective->attempts) {
                throw new moodle_exception('aindapodefazerprova', 'local_faicrm');
            }

            $timeclose = null;
            if ($prazo !== '') {
                $timeclose = util::parse_deadline($prazo);
            } else if (!empty($effective->timeclose) && $effective->timeclose < time()) {
                throw new moodle_exception('provaencerrada', 'local_faicrm');
            }

            // Create or update the user override, keeping any other setting the existing override already had.
            $data = ['quiz' => $quiz->id, 'userid' => $userid, 'attempts' => $finished + 1];
            $existing = $DB->get_record('quiz_overrides', ['quiz' => $quiz->id, 'userid' => $userid], '*', IGNORE_MULTIPLE);
            if ($existing) {
                $data['id'] = $existing->id;
                foreach (['timeopen', 'timeclose', 'timelimit', 'password'] as $setting) {
                    $data[$setting] = $existing->$setting;
                }
            }
            if ($timeclose !== null) {
                $data['timeclose'] = $timeclose;
            }
            // What the override was before the CRM touched it, so that cancelar can restore it exactly. When the
            // override is still the one written by an earlier release (nobody changed it since), that earlier
            // baseline is kept; otherwise the current override (or its absence) is the baseline.
            $state = util::get_release_state($quiz->id, $userid);
            $continuing = $state && $existing && (int) $state->overrideid === (int) $existing->id
                && util::override_value($existing->attempts) === util::override_value($state->set->attempts)
                && util::override_value($existing->timeclose, true) === util::override_value($state->set->timeclose, true);
            $prev = $continuing ? $state->prev : (object) [
                'existed' => (bool) $existing,
                'attempts' => $existing ? util::override_value($existing->attempts) : null,
                'timeclose' => $existing ? util::override_value($existing->timeclose, true) : null,
            ];

            $settings = array_intersect_key(
                $manager->parse_formdata($data),
                array_flip(['timeopen', 'timeclose', 'timelimit', 'attempts', 'password'])
            );
            if ($existing && !array_filter($settings, fn($value) => $value !== null)) {
                // The new limit equals the quiz limit (an earlier override had reduced it) and nothing else is
                // overridden: the native API refuses an empty override, and removing it gives the same result.
                $manager->delete_overrides([$existing]);
                util::set_release_state($quiz->id, $userid, null);
            } else {
                $id = $manager->save_override($data);
                $saved = $DB->get_record('quiz_overrides', ['id' => $id], 'id, attempts, timeclose', MUST_EXIST);
                util::set_release_state($quiz->id, $userid, (object) [
                    'overrideid' => (int) $saved->id,
                    'prev' => $prev,
                    'set' => (object) [
                        'attempts' => util::override_value($saved->attempts),
                        'timeclose' => util::override_value($saved->timeclose, true),
                    ],
                ]);
            }
        } finally {
            $lock->release();
        }

        return [
            'quizid' => $quiz->id,
            'userid' => $userid,
            'tentativaspermitidas' => $finished + 1,
            'prazo' => util::format_date($timeclose),
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'tentativaspermitidas' => new external_value(PARAM_INT, 'Attempts now allowed for this user'),
            'prazo' => new external_value(
                PARAM_RAW,
                'Deadline set for this user (ISO 8601), null when none',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
        ]);
    }
}
