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

use core\hook\output\before_http_headers;
use mod_quiz\event\attempt_submitted;
use moodle_url;
use stdClass;

/**
 * Return to the CRM after the quiz (spec 007): the whole logic, with the session isolated in three methods.
 *
 * Flow: the login URL of the SSO goes through iniciar.php with a signature (HMAC of user|quiz|expiry) that marks the quiz
 * of the link in the session -> the attempt is submitted with the candidate online -> a pending return is stored -> the
 * next quiz page is diverted to concluido.php, which consumes the pending return once, logs the candidate out and sends
 * the browser to the configured URL. The destination only ever comes from the plugin settings, never from the request.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class retorno {
    /** Session key: the quiz (cmid) of the link the candidate came in through. */
    private const SESSION_MARKED = 'local_faicrm_retorno';
    /** Session key: the submitted attempt that waits for the confirmation page. */
    private const SESSION_PENDING = 'local_faicrm_retorno_pendente';
    /** @var bool Whether this request is already diverting (guards against the re-entry of the hook). */
    private static $diverting = false;
    /** Page types of the quiz where the pending return is diverted. */
    private const DIVERT_PAGETYPES = ['mod-quiz-review', 'mod-quiz-view'];
    /** Purpose prefix of the signed message, so a signature made for this can not be used for anything else. */
    private const SIGNATURE_PURPOSE = 'retorno|v1|';
    /** Seconds a signed start link is still accepted after the key it came with has expired. */
    private const SIGNATURE_TOLERANCE = 60;

    // Configuration.

    /**
     * The single validation rule of the return URL, used when saving the setting and when using it: empty (off), or
     * a PARAM_URL with scheme and host, without user info, spaces or control characters, https (http only while Moodle is http).
     *
     * @param string $value The value.
     * @return bool
     */
    public static function is_valid_url(string $value): bool {
        global $CFG;

        if ($value === '') {
            return true;
        }
        if (preg_match('/[\x00-\x20\x7f]/', $value) || clean_param($value, PARAM_URL) !== $value) {
            return false;
        }
        $parts = parse_url($value);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        return $scheme === 'https' || ($scheme === 'http' && strpos($CFG->wwwroot, 'http://') === 0);
    }

    /**
     * The configured value, trimmed (empty when off), valid or not.
     *
     * @return string
     */
    public static function raw_url(): string {
        return trim((string) get_config('local_faicrm', 'retorno_url'));
    }

    /**
     * The return URL to use now: null when off or when the stored value is not acceptable (fails closed).
     *
     * @return string|null
     */
    public static function url(): ?string {
        $value = self::raw_url();
        return $value !== '' && self::is_valid_url($value) ? $value : null;
    }

    /**
     * Whether the return is on (a valid URL is set).
     *
     * @return bool
     */
    public static function enabled(): bool {
        return self::url() !== null;
    }

    /**
     * Final URL: the base URL with status=concluida and the quiz cmid added (the query it already has is kept,
     * same-named values are replaced). Nothing personal is ever added.
     *
     * @param string $base The configured URL.
     * @param int $cmid Course module id of the quiz.
     * @return string
     */
    public static function build_url(string $base, int $cmid): string {
        $url = new moodle_url($base);
        $url->param('status', 'concluida');
        $url->param('cmid', $cmid);
        return $url->out(false);
    }

    /**
     * Final URL for a quiz, built from the configuration; null when the return is off or invalid.
     *
     * @param int $cmid Course module id of the quiz.
     * @return string|null
     */
    public static function final_url(int $cmid): ?string {
        $base = self::url();
        return $base === null ? null : self::build_url($base, $cmid);
    }

    // Marking tied to the link (HMAC).

    /**
     * The secret of the signatures. It is only READ at run time (it is created by the install and the upgrade, never in a
     * request) and never shown or logged.
     *
     * @return string Empty when it is missing.
     */
    private static function secret(): string {
        return (string) get_config('local_faicrm', 'sso_secret');
    }

    /**
     * Whether the secret of the signatures exists.
     *
     * @return bool
     */
    public static function has_secret(): bool {
        return self::secret() !== '';
    }

    /**
     * Creates the secret of the signatures when it is empty. Called by the install and the upgrade only.
     */
    public static function ensure_secret(): void {
        if (!self::has_secret()) {
            set_config('sso_secret', random_string(64), 'local_faicrm');
        }
    }

    /**
     * Signature of a start link: HMAC-SHA256 of 'retorno|v1|user|quiz|expiry'. Empty when the secret is missing.
     *
     * @param int $userid User the link was made for.
     * @param int $cmid Course module id of the quiz.
     * @param int $exp Expiry of the key (timestamp).
     * @return string
     */
    public static function sign(int $userid, int $cmid, int $exp): string {
        if (!self::has_secret()) {
            return '';
        }
        return hash_hmac('sha256', self::SIGNATURE_PURPOSE . $userid . '|' . $cmid . '|' . $exp, self::secret());
    }

    /**
     * Marks the quiz as the quiz of the return, only when the signature is the one made for this user, quiz and expiry,
     * and the expiry is not past (one minute of tolerance).
     *
     * @param int $userid The logged-in user.
     * @param int $cmid Course module id from the address.
     * @param int $exp Expiry from the address.
     * @param string $sig Signature from the address.
     * @return bool Whether the session was marked.
     */
    public static function mark_signed(int $userid, int $cmid, int $exp, string $sig): bool {
        $expected = self::sign($userid, $cmid, $exp);
        if ($sig === '' || $expected === '' || !hash_equals($expected, $sig) || time() > $exp + self::SIGNATURE_TOLERANCE) {
            return false;
        }
        self::session_set(self::SESSION_MARKED, $cmid);
        return true;
    }

    /**
     * The quiz marked in the session.
     *
     * @return int|null
     */
    public static function marked_cmid(): ?int {
        $value = self::session_get(self::SESSION_MARKED);
        return $value === null ? null : (int) $value;
    }

    // Pending return.

    /**
     * The pending return: {cmid, attemptid, userid, time}.
     *
     * @return stdClass|null
     */
    public static function pending(): ?stdClass {
        $value = self::session_get(self::SESSION_PENDING);
        return $value instanceof stdClass ? $value : null;
    }

    /**
     * Observer of an attempt submitted: stores the pending return when the candidate was online, the attempt is the one
     * of the session user (who submitted it himself, not "logged in as"), the quiz is the one marked from the link and the
     * return URL is valid. It never redirects and never throws.
     *
     * @param attempt_submitted $event The event.
     */
    public static function on_attempt_submitted(attempt_submitted $event): void {
        global $USER;

        try {
            $other = $event->other;
            $userid = (int) ($USER->id ?? 0);
            $cmid = (int) $event->contextinstanceid;
            $accepted = is_array($other)
                && ($other['studentisonline'] ?? null) === true
                && $userid > 0
                && (int) $event->relateduserid === $userid
                && (int) ($other['submitterid'] ?? 0) === $userid
                && !\core\session\manager::is_loggedinas()
                && self::marked_cmid() === $cmid
                && self::enabled();
            if ($accepted) {
                self::session_set(self::SESSION_PENDING, (object) [
                    'cmid' => $cmid,
                    'attemptid' => (int) $event->objectid,
                    'userid' => $userid,
                    'time' => time(),
                ]);
            }
        } catch (\Throwable $e) {
            debugging('local_faicrm: retorno after the quiz failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Decides whether the page must be diverted to the confirmation page: the page is the review or the start page of the
     * quiz of the pending return, the attempt is finished and belongs to the user, and the return URL is valid now.
     * When any condition fails, the pending return is discarded and the normal flow goes on.
     *
     * @param string $pagetype Page type.
     * @param int $cmid Course module id of the page.
     * @param int $userid The logged-in user.
     * @return bool
     */
    public static function decide_divert(string $pagetype, int $cmid, int $userid): bool {
        global $DB;

        $pending = self::pending();
        if ($pending === null) {
            return false;
        }
        $divert = in_array($pagetype, self::DIVERT_PAGETYPES, true)
            && $pending->cmid === $cmid
            && $pending->userid === $userid
            && self::enabled()
            && $DB->record_exists('quiz_attempts', ['id' => $pending->attemptid, 'userid' => $userid, 'state' => 'finished']);
        if (!$divert) {
            self::session_set(self::SESSION_PENDING, null);
        }
        return $divert;
    }

    /**
     * Hook before the HTTP headers: leaves at once when there is no pending return or when this request is already
     * diverting, otherwise diverts to the confirmation page when {@see decide_divert()} says so.
     *
     * The guard matters because redirect() called inside header() can end up in header() again (debug output printed, or some
     * user agents), which would run this hook again with the pending return still there: an endless recursion.
     *
     * @param before_http_headers $hook The hook.
     */
    public static function before_http_headers(before_http_headers $hook): void {
        global $PAGE, $USER;

        if (self::$diverting || self::pending() === null) {
            return;
        }
        $cmid = $PAGE->cm ? (int) $PAGE->cm->id : 0;
        if (self::decide_divert((string) $PAGE->pagetype, $cmid, (int) $USER->id)) {
            self::$diverting = true;
            redirect(new moodle_url('/local/faicrm/concluido.php', ['sesskey' => sesskey()]));
        }
    }

    /**
     * Lets the next request of a test divert again (the flag lives as long as the PHP process).
     */
    public static function reset_diverting(): void {
        self::$diverting = false;
    }

    /**
     * Consumes the pending return: it is good once only.
     *
     * @return stdClass|null The pending return, or null when there is none.
     */
    public static function consume(): ?stdClass {
        $pending = self::pending();
        self::session_set(self::SESSION_PENDING, null);
        return $pending;
    }

    // Confirmation page.

    /**
     * Whether the sesskey of the request is the right one. A missing sesskey is simply "no" (confirm_sesskey() alone would
     * show a missing-parameter error).
     *
     * @param string $sesskey The sesskey received (empty when absent).
     * @return bool
     */
    public static function sesskey_ok(string $sesskey): bool {
        return $sesskey !== '' && confirm_sesskey($sesskey);
    }

    /**
     * Body of the confirmation page. The message is plain text: escaped, with line breaks.
     *
     * @param string $url Final URL (already built from the settings).
     * @param string $message Optional message of the settings.
     * @return string
     */
    public static function confirmation_html(string $url, string $message): string {
        $html = \html_writer::tag('h2', get_string('retorno_sucesso', 'local_faicrm'));
        $html .= \html_writer::tag('p', get_string('retorno_espere', 'local_faicrm'));
        if (trim($message) !== '') {
            $html .= \html_writer::tag('p', nl2br(s(trim($message))));
        }
        $html .= \html_writer::link($url, get_string('retorno_voltar', 'local_faicrm'), [
            'class' => 'btn btn-primary',
            'rel' => 'noreferrer',
        ]);
        return $html;
    }

    /**
     * Meta tag that sends the browser to the URL after 5 seconds (no JavaScript needed).
     *
     * @param string $url Final URL.
     * @return string
     */
    public static function refresh_meta(string $url): string {
        return '<meta http-equiv="refresh" content="5;url=' . s($url) . '">';
    }

    // Session (the only place that touches $SESSION).

    /**
     * Reads a value of the session.
     *
     * @param string $name Session key.
     * @return mixed Null when not set.
     */
    private static function session_get(string $name) {
        global $SESSION;
        return $SESSION->$name ?? null;
    }

    /**
     * Writes a value to the session, or removes the key with null.
     *
     * @param string $name Session key.
     * @param mixed $value Value.
     */
    private static function session_set(string $name, $value): void {
        global $SESSION;
        if ($value === null) {
            unset($SESSION->$name);
        } else {
            $SESSION->$name = $value;
        }
    }
}
