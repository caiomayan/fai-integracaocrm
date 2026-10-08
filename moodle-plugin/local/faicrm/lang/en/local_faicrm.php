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
 * English language strings for local_faicrm.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['aindapodefazerprova'] = 'The candidate can still take the quiz.';
$string['candidatonaomatriculado'] = 'The candidate is not enrolled in this course.';
$string['locktimeout'] = 'Another operation for this candidate is in progress. Try again.';
$string['pluginname'] = 'FAI CRM integration';
$string['prazoinvalido'] = 'The deadline must be a valid date in the format YYYY-MM-DD.';
$string['prazonopassado'] = 'The deadline cannot be in the past.';
$string['privacy:metadata:preference:novatentativa'] = 'Records an extra quiz attempt released for the user by the CRM: the attempts and closing date of the user override before the release and the values the release set, so that cancelling it restores them.';
$string['provaencerrada'] = 'The quiz is closed: provide a deadline for the new attempt.';
$string['semexcecao'] = 'There is no extra attempt released for this candidate.';
$string['servicenotfound'] = 'The external service "{$a}" was not found. Is the local_faicrm plugin installed (Site administration > Notifications)?';
$string['studentrolenotfound'] = 'The role with shortname "student" was not found.';
$string['tentativaemandamento'] = 'The candidate has an attempt in progress.';
$string['tentativajainiciada'] = 'The candidate has already started the extra attempt.';
