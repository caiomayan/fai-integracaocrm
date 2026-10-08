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

namespace local_faicrm\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;
use local_faicrm\util;

/**
 * Privacy provider for local_faicrm.
 *
 * The only personal data the plugin stores is a user preference per quiz recording an extra attempt released by the
 * CRM (what the user's quiz override was before, and what the release wrote), so that cancelling restores it exactly.
 * Everything else goes through the standard Moodle APIs. User preferences are deleted by core with the user.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Describes the personal data stored by the plugin.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_user_preference(util::PREF_RELEASE . '<quizid>', 'privacy:metadata:preference:novatentativa');
        return $collection;
    }

    /**
     * Exports the release records of a user.
     *
     * @param int $userid The user id.
     */
    public static function export_user_preferences(int $userid) {
        $preferences = get_user_preferences(null, null, $userid);
        foreach ($preferences as $name => $value) {
            if (strpos($name, util::PREF_RELEASE) === 0) {
                writer::export_user_preference(
                    'local_faicrm',
                    $name,
                    $value,
                    get_string('privacy:metadata:preference:novatentativa', 'local_faicrm')
                );
            }
        }
    }
}
