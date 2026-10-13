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
 * Settings page of the automatic access to the quiz (SSO).
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_faicrm_sso', get_string('ssosettings', 'local_faicrm'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        // Read-only diagnostic at the top (the production setup is done only through the admin pages).
        $icons = ['OK' => '✔', 'AVISO' => '⚠', 'FALHA' => '✖'];
        $classes = ['OK' => 'text-success', 'AVISO' => 'text-warning', 'FALHA' => 'text-danger'];
        $list = '';
        foreach (\local_faicrm\sso\diagnostico::run() as $item) {
            $list .= html_writer::tag(
                'li',
                html_writer::tag('strong', $icons[$item['status']] . ' ' . $item['status'], ['class' => $classes[$item['status']]])
                    . ' ' . s($item['text'])
            );
        }
        $settings->add(new admin_setting_heading(
            'local_faicrm/ssodiagnostico',
            get_string('ssodiagnostico', 'local_faicrm'),
            html_writer::tag('ul', $list, ['class' => 'list-unstyled'])
        ));
        $settings->add(new admin_setting_configmultiselect(
            'local_faicrm/sso_categorias',
            get_string('sso_categorias', 'local_faicrm'),
            get_string('sso_categorias_desc', 'local_faicrm'),
            [],
            core_course_category::make_categories_list()
        ));
    }
}
