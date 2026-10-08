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
 * Uninstall steps for local_faicrm.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Removes the user preferences that record extra attempts released by the CRM.
 *
 * The quiz overrides themselves are native Moodle data and are kept.
 *
 * @return bool
 */
function xmldb_local_faicrm_uninstall() {
    global $DB;
    $DB->delete_records_select('user_preferences', $DB->sql_like('name', ':name'), ['name' => 'local_faicrm_novatentativa_%']);
    return true;
}
