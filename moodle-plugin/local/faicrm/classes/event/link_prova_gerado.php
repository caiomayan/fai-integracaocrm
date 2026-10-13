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

namespace local_faicrm\event;

/**
 * An automatic access link to a quiz was generated for a candidate (audit; the key is never recorded).
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_prova_gerado extends \core\event\base {
    /**
     * Initialises the event: context is the quiz module, objectid its course module id, relateduserid the candidate.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'course_modules';
    }

    /**
     * Name of the event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventlinkprovagerado', 'local_faicrm');
    }

    /**
     * Description for the log (no key in it).
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' generated an automatic access link to the quiz with course module id " .
            "'{$this->objectid}' for the user with id '{$this->relateduserid}'.";
    }

    /**
     * Link to the quiz.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/quiz/view.php', ['id' => $this->objectid]);
    }

    /**
     * Mapping of the object id for backup/restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'course_modules', 'restore' => \core\event\base::NOT_MAPPED];
    }
}
