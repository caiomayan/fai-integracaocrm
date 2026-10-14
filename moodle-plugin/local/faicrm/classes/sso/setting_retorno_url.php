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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Text setting of the return URL: accepts empty, https://, and http:// only when Moodle itself is http.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_retorno_url extends \admin_setting_configtext {
    /**
     * Validates the value with {@see retorno::is_valid_url()}.
     *
     * @param string $data The value.
     * @return true|string True when valid, otherwise the error message.
     */
    public function validate($data) {
        $result = parent::validate($data);
        if ($result !== true) {
            return $result;
        }
        return retorno::is_valid_url(trim((string) $data)) ? true : get_string('retorno_url_invalida', 'local_faicrm');
    }
}
