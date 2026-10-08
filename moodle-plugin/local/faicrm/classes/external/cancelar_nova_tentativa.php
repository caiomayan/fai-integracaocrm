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

use context_module;
use core_external\external_api;
use core_external\external_description;
use core_external\external_function_parameters;
use core_external\external_value;
use local_faicrm\util;
use mod_quiz\local\override_manager;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Cancels the extra attempt released for a candidate (removes the user override of the quiz).
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cancelar_nova_tentativa extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'quizid' => new external_value(PARAM_INT, 'Quiz id', VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
            'userid' => new external_value(PARAM_INT, 'User id', VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
        ]);
    }

    /**
     * Removes the user override, unless the candidate already used the extra attempt.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     */
    public static function execute(int $quizid, int $userid): void {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['quizid' => $quizid, 'userid' => $userid]);
        $quizid = $params['quizid'];
        $userid = $params['userid'];

        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id', MUST_EXIST);

        $quiz->cmid = $cm->id;
        $manager = new override_manager($quiz, $context);
        $manager->require_manage_capability();

        $lock = util::lock_candidate($quiz->id, $userid);
        try {
            // Only an override that grants attempts is a released extra attempt. An override with just
            // opening, time limit or password comes from the FAI (e.g. accessibility) and is not ours to remove.
            $overrides = array_filter(
                $DB->get_records('quiz_overrides', ['quiz' => $quiz->id, 'userid' => $userid]),
                fn($override) => $override->attempts !== null
            );
            if (!$overrides) {
                throw new moodle_exception('semexcecao', 'local_faicrm');
            }
            // The released attempt was used when finished + in progress reach the limit the release recorded
            // (finished at that moment + 1). This holds for the first release and for any later one.
            [$finished, $inprogress] = util::count_attempts($quiz->id, $userid);
            $granted = max(array_map(fn($override) => (int) $override->attempts, $overrides));
            if ($finished + $inprogress >= $granted) {
                throw new moodle_exception('tentativajainiciada', 'local_faicrm');
            }
            $state = util::get_release_state($quiz->id, $userid);
            foreach ($overrides as $override) {
                $data = array_intersect_key(
                    (array) $override,
                    array_flip(['id', 'quiz', 'userid', 'timeopen', 'timeclose', 'timelimit', 'password'])
                );
                $recorded = $state && (int) $state->overrideid === (int) $override->id
                    && util::override_value($override->attempts) === util::override_value($state->set->attempts);
                if ($recorded) {
                    // Undo exactly what the release wrote: attempts and deadline go back to the values they had before
                    // (none, when the override did not exist). A deadline the FAI changed after the release stays.
                    $data['attempts'] = $state->prev->attempts;
                    if (util::override_value($override->timeclose, true) === util::override_value($state->set->timeclose, true)) {
                        $data['timeclose'] = $state->prev->timeclose;
                    }
                } else if (util::override_has_manual_settings($override)) {
                    // No recorded release (or the FAI changed the attempts since): the origin of the other settings is
                    // unknown, so keep them all and drop only the attempts.
                    $data['attempts'] = null;
                } else {
                    $manager->delete_overrides([$override]);
                    continue;
                }
                $settings = array_intersect_key(
                    $manager->parse_formdata($data),
                    array_flip(['timeopen', 'timeclose', 'timelimit', 'attempts', 'password'])
                );
                if (array_filter($settings, fn($value) => $value !== null)) {
                    $manager->save_override($data);
                } else {
                    // Nothing left to override (e.g. the release created it): remove it.
                    $manager->delete_overrides([$override]);
                }
            }
            util::set_release_state($quiz->id, $userid, null);
        } finally {
            $lock->release();
        }
    }

    /**
     * Describes the return value (none: the response is empty).
     *
     * @return external_description|null
     */
    public static function execute_returns(): ?external_description {
        return null;
    }
}
