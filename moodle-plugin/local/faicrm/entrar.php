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

/**
 * Entry page of the automatic access link: checks the key, logs out whoever is logged in as another user, then sends
 * the browser to the login page of auth_userkey (spec 006, T-15). No other logic.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The page is public on purpose: the key in the link is the credential, checked by the login page of auth_userkey.
// phpcs:ignore moodle.Files.RequireLogin.Missing
require(__DIR__ . '/../../config.php');

use local_faicrm\sso\link;

$key = required_param('key', PARAM_ALPHANUM);
$cmid = required_param('cmid', PARAM_INT);

$PAGE->set_url(new moodle_url('/local/faicrm/entrar.php', ['key' => $key, 'cmid' => $cmid]));
$PAGE->set_context(context_system::instance());

if (!link::available()) {
    throw new moodle_exception('ssoindisponivel', 'local_faicrm');
}

// Validate before anything else: an invalid, used or expired key (or one made for another quiz) shows our own error and
// never reaches the login page of auth_userkey, which would log the current user out.
$check = link::entry_check($key, $cmid, isloggedin() ? (int) $USER->id : 0);
if (!$check['valid']) {
    throw new moodle_exception('linkinvalido', 'local_faicrm');
}
if ($check['logout']) {
    require_logout();
}

// The link is a credential for up to 120 seconds: do not leak it through the Referer or the cache.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
redirect(new moodle_url(link::login_url($key, $cmid, $check['userid'], $check['exp'])));
