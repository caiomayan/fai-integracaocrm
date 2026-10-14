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
 * Start page of the automatic access: the login by key lands here. Marks the quiz of the link for the return to the CRM
 * only when the address carries the signature made for this user, quiz and expiry (spec 007, RF-29, T-01), and always
 * goes on to the quiz. Any other parameter is ignored.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_faicrm\sso\retorno;

require_login();
$cmid = required_param('cmid', PARAM_INT);
$exp = optional_param('exp', 0, PARAM_INT);
$sig = optional_param('sig', '', PARAM_ALPHANUM);

retorno::mark_signed((int) $USER->id, $cmid, $exp, $sig);

// The destination is always an internal quiz URL.
redirect(new moodle_url('/mod/quiz/view.php', ['id' => $cmid]));
