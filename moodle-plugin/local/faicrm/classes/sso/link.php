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

use moodle_url;

/**
 * Generates the one-time login link of the auth_userkey plugin (the only class that knows that plugin).
 *
 * To replace auth_userkey by another login mechanism, only this class changes.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link {
    /** Script name of the keys of auth_userkey (the same as core_userkey_manager uses). */
    public const SCRIPT = 'auth/userkey';
    /** Lifetime used when the plugin has none configured, in seconds. */
    public const DEFAULT_LIFETIME = 60;
    /** Longest lifetime we accept, in seconds, whatever the plugin is configured to. */
    public const MAX_LIFETIME = 120;

    /**
     * Whether auth_userkey is installed and enabled.
     *
     * @return bool
     */
    public static function available(): bool {
        global $CFG;
        return is_enabled_auth('userkey') && file_exists($CFG->dirroot . '/auth/userkey/login.php');
    }

    /**
     * Lifetime of a new key: the "User key life time" of the plugin, 60 s when empty, at most 120 s.
     *
     * @return int seconds
     */
    public static function lifetime(): int {
        $configured = (int) get_config('auth_userkey', 'keylifetime');
        return $configured > 0 ? min($configured, self::MAX_LIFETIME) : self::DEFAULT_LIFETIME;
    }

    /**
     * Creates a new key for the user (invalidating the previous one) and builds the login URL.
     *
     * The key has no IP restriction: the CRM does not know the IP of the candidate.
     *
     * @param int $userid User id.
     * @param int $cmid Course module id of the quiz the link leads to.
     * @return array [string login url, int timestamp when the key expires]
     */
    public static function generate(int $userid, int $cmid): array {
        $validuntil = time() + self::lifetime();
        delete_user_key(self::SCRIPT, $userid);
        $key = create_user_key(self::SCRIPT, $userid, $cmid, null, $validuntil);
        // The plugin ignores the instance of the key; we tie it to the quiz so a key opens only the quiz it was made for.

        return [self::entry_url($key, $cmid), $validuntil];
    }

    /**
     * URL of our entry page (the link the CRM receives). It then redirects to the login page of auth_userkey.
     *
     * @param string $key The key.
     * @param int $cmid Course module id of the quiz.
     * @return string
     */
    public static function entry_url(string $key, int $cmid): string {
        return (new moodle_url('/local/faicrm/entrar.php', ['key' => $key, 'cmid' => $cmid]))->out(false);
    }

    /**
     * URL of the login page of auth_userkey, taking the candidate to the quiz after the login.
     *
     * The destination is always built here (an internal quiz URL), never taken from outside.
     *
     * @param string $key The key.
     * @param int $cmid Course module id of the quiz.
     * @return string
     */
    public static function login_url(string $key, int $cmid): string {
        $wantsurl = new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);
        return (new moodle_url('/auth/userkey/login.php', ['key' => $key, 'wantsurl' => $wantsurl->out(false)]))->out(false);
    }

    /**
     * Checks the key of an entry link before anybody is redirected or logged out.
     *
     * The key is valid when it exists (script auth/userkey), is still inside its validity and was made for this quiz
     * (instance = cmid). The current user must be logged out only when the key is valid and belongs to ANOTHER user:
     * the login page of the auth_userkey version we run logs out whoever is logged in before it validates the key
     * (upstream fix 607ec45), so an invalid key must never reach it.
     *
     * @param string $key The key from the link.
     * @param int $cmid The course module id from the link.
     * @param int $currentuserid The logged-in user (0 when nobody is logged in).
     * @return array ['valid' => bool, 'logout' => bool]
     */
    public static function entry_check(string $key, int $cmid, int $currentuserid): array {
        global $DB;

        $record = $key === '' ? false : $DB->get_record(
            'user_private_key',
            ['script' => self::SCRIPT, 'value' => $key],
            'userid, instance, validuntil'
        );
        $valid = $record
            && (empty($record->validuntil) || (int) $record->validuntil >= time())
            && (int) $record->instance === $cmid;
        if (!$valid) {
            return ['valid' => false, 'logout' => false];
        }
        return ['valid' => true, 'logout' => $currentuserid > 0 && (int) $record->userid !== $currentuserid];
    }
}
