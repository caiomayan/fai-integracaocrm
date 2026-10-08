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

namespace local_faicrm;

use moodle_exception;

/**
 * Helpers shared by the web service functions of local_faicrm.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class util {
    /**
     * Formats a timestamp as ISO 8601 in the server time zone.
     *
     * @param int|string|null $timestamp Unix timestamp (empty means none).
     * @return string|null
     */
    public static function format_date($timestamp): ?string {
        if (empty($timestamp)) {
            return null;
        }
        $date = new \DateTime('@' . (int) $timestamp);
        $date->setTimezone(\core_date::get_server_timezone_object());
        return $date->format('c');
    }

    /**
     * Parses a deadline (YYYY-MM-DD) into the last second of that day, in the server time zone.
     *
     * @param string $value Date as YYYY-MM-DD.
     * @return int Timestamp of the day at 23:59:59.
     * @throws moodle_exception prazoinvalido when the format is wrong, prazonopassado when the day has passed.
     */
    public static function parse_deadline(string $value): int {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new moodle_exception('prazoinvalido', 'local_faicrm');
        }
        $date = new \DateTime('now', \core_date::get_server_timezone_object());
        $date->setDate((int) $m[1], (int) $m[2], (int) $m[3]);
        $date->setTime(23, 59, 59);
        if ($date->getTimestamp() < time()) {
            throw new moodle_exception('prazonopassado', 'local_faicrm');
        }
        return $date->getTimestamp();
    }

    /**
     * Counts the attempts of a user in a quiz (previews are ignored).
     *
     * Finished and abandoned attempts both use up the attempt limit, so both count as finished here.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @return int[] [finished attempts, attempts in progress (in progress or overdue)]
     */
    public static function count_attempts(int $quizid, int $userid): array {
        global $DB;
        $finished = 0;
        $inprogress = 0;
        $rows = $DB->get_records_sql(
            'SELECT state, COUNT(1) AS total
               FROM {quiz_attempts}
              WHERE quiz = :quiz AND userid = :userid AND preview = 0
           GROUP BY state',
            ['quiz' => $quizid, 'userid' => $userid]
        );
        foreach ($rows as $row) {
            if (in_array($row->state, ['finished', 'abandoned'], true)) {
                $finished += (int) $row->total;
            } else {
                $inprogress += (int) $row->total;
            }
        }
        return [$finished, $inprogress];
    }

    /**
     * Takes the lock of one candidate in one quiz, so that liberar/cancelar never run at the same time for them.
     *
     * Without it, two simultaneous calls could both pass the checks and create two overrides for the same user
     * (quiz_overrides has no unique index and override_manager checks duplicates before inserting).
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @return \core\lock\lock The lock (release it in a finally block).
     * @throws moodle_exception locktimeout when the lock is not obtained in 10 seconds.
     */
    public static function lock_candidate(int $quizid, int $userid): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('local_faicrm');
        $lock = $factory->get_lock("novatentativa_{$quizid}_{$userid}", 10);
        if (!$lock) {
            throw new moodle_exception('locktimeout', 'local_faicrm');
        }
        return $lock;
    }

    /**
     * Whether the user override has settings other than the attempts (opening, closing, time limit or password).
     *
     * Used by cancelar when there is no recorded release state: the origin of those settings is unknown, so they are
     * kept (they may come from the FAI, e.g. extra time or an extended deadline for accessibility).
     *
     * @param \stdClass $override Record of quiz_overrides.
     * @return bool
     */
    public static function override_has_manual_settings(\stdClass $override): bool {
        return !empty($override->timeopen) || !empty($override->timeclose) || !empty($override->timelimit)
            || (string) $override->password !== '';
    }

    /** Prefix of the user preference that records a release (followed by the quiz id). */
    public const PREF_RELEASE = 'local_faicrm_novatentativa_';

    /**
     * Normalises an override value (attempts or timeclose) for comparisons: null, '' and (for dates) 0 mean "not set".
     *
     * @param mixed $value Value from quiz_overrides or from the recorded state.
     * @param bool $isdate Whether 0 also means "not set".
     * @return int|null
     */
    public static function override_value($value, bool $isdate = false): ?int {
        if ($value === null || $value === '' || ($isdate && (int) $value === 0)) {
            return null;
        }
        return (int) $value;
    }

    /**
     * Returns the recorded release of a candidate in a quiz: what the override was before the CRM released an attempt
     * ("prev": existed, attempts, timeclose) and what the release wrote ("set": attempts, timeclose).
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @return \stdClass|null
     */
    public static function get_release_state(int $quizid, int $userid): ?\stdClass {
        $value = get_user_preferences(self::PREF_RELEASE . $quizid, null, $userid);
        $state = $value === null ? null : json_decode($value);
        if (!is_object($state) || !isset($state->overrideid, $state->prev, $state->set)) {
            return null;
        }
        return $state;
    }

    /**
     * Records (or, with null, forgets) the release of a candidate in a quiz.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @param \stdClass|null $state State to record, or null to remove it.
     */
    public static function set_release_state(int $quizid, int $userid, ?\stdClass $state): void {
        if ($state === null) {
            unset_user_preference(self::PREF_RELEASE . $quizid, $userid);
        } else {
            set_user_preference(self::PREF_RELEASE . $quizid, json_encode($state), $userid);
        }
    }
}
