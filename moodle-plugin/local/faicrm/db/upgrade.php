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
 * Upgrade steps of local_faicrm.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs the upgrade steps.
 *
 * @param int $oldversion Version the plugin is upgrading from.
 * @return bool
 */
function xmldb_local_faicrm_upgrade($oldversion) {
    if ($oldversion < 2026101401) {
        // Spec 007: the secret that signs the start link of the return to the CRM (only when empty).
        \local_faicrm\sso\retorno::ensure_secret();
        upgrade_plugin_savepoint(true, 2026101401, 'local', 'faicrm');
    }

    return true;
}
