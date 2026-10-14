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
 * Confirmation page after the quiz is submitted: consumes the pending return (good once), logs the candidate out and
 * sends the browser to the configured URL after 5 seconds, or at the click of the button (spec 007, RF-32, T-04).
 * The only parameter it reads is sesskey; the destination comes only from the settings.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The page works on a pending return kept in the session; without one it only goes to the home page.
// phpcs:ignore moodle.Files.RequireLogin.Missing
require(__DIR__ . '/../../config.php');

use local_faicrm\sso\retorno;

// First thing: the pending return is used up, so nothing below can make a loop.
$pending = retorno::consume();
$sesskeyok = retorno::sesskey_ok(optional_param('sesskey', '', PARAM_ALPHANUM));
$url = $pending !== null && $sesskeyok ? retorno::final_url($pending->cmid) : null;
if ($url === null) {
    redirect(new moodle_url('/'));
}

// The session ends before anything is shown.
require_logout();

header('Referrer-Policy: no-referrer');

$PAGE->set_url(new moodle_url('/local/faicrm/concluido.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('login');
// Moodle sends the Cache-Control of the page itself: not cacheable means no-store.
$PAGE->set_cacheable(false);
$PAGE->set_title(get_string('retorno_titulo', 'local_faicrm'));
$PAGE->set_heading(get_string('retorno_titulo', 'local_faicrm'));
$CFG->additionalhtmlhead .= retorno::refresh_meta($url);

echo $OUTPUT->header();
echo retorno::confirmation_html($url, (string) get_config('local_faicrm', 'retorno_mensagem'));
echo $OUTPUT->footer();
