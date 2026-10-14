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

use moodle_url;

/**
 * Tests for the URLs and the key check of the entry page of the automatic access (spec 006, T-15).
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_faicrm\sso\link
 */
final class link_test extends \advanced_testcase {
    /**
     * Creates a key of auth_userkey for a user, tied to a quiz.
     *
     * @param int $userid user id
     * @param int|null $validuntil expiry timestamp (null = no expiry)
     * @param int $cmid course module id the key is tied to
     * @return string the key
     */
    private function key_for(int $userid, ?int $validuntil, int $cmid = 22): string {
        return create_user_key('auth/userkey', $userid, $cmid, null, $validuntil);
    }

    /**
     * Resultado do entry_check só com valid e logout.
     *
     * @param string $key chave
     * @param int $cmid prova
     * @param int $current usuário logado
     * @return array
     */
    private function check(string $key, int $cmid, int $current): array {
        $result = link::entry_check($key, $cmid, $current);
        return array_intersect_key($result, ['valid' => 1, 'logout' => 1]);
    }

    public function test_entry_url_aponta_para_a_pagina_de_entrada(): void {
        global $CFG;

        $url = new moodle_url(link::entry_url('abc123', 22));

        $this->assertSame($CFG->wwwroot . '/local/faicrm/entrar.php', $url->out_omit_querystring());
        $this->assertSame('abc123', $url->get_param('key'));
        $this->assertSame('22', $url->get_param('cmid'));
        $this->assertNull($url->get_param('wantsurl'));
    }

    public function test_login_url_leva_ao_login_do_plugin_passando_pela_pagina_de_inicio_assinada(): void {
        global $CFG;
        $this->resetAfterTest();

        $url = new moodle_url(link::login_url('abc123', 22, 7, 1900000000));

        $this->assertSame($CFG->wwwroot . '/auth/userkey/login.php', $url->out_omit_querystring());
        $this->assertSame('abc123', $url->get_param('key'));
        $inicio = new moodle_url($url->get_param('wantsurl'));
        $this->assertSame($CFG->wwwroot . '/local/faicrm/iniciar.php', $inicio->out_omit_querystring());
        $this->assertSame('22', $inicio->get_param('cmid'));
        $this->assertSame('1900000000', $inicio->get_param('exp'));
        $this->assertSame(retorno::sign(7, 22, 1900000000), $inicio->get_param('sig'));
    }

    public function test_entry_check_devolve_o_dono_e_a_validade_da_chave_valida(): void {
        $this->resetAfterTest();
        $dono = $this->getDataGenerator()->create_user();
        $validuntil = time() + 60;
        $key = $this->key_for((int) $dono->id, $validuntil);

        $result = link::entry_check($key, 22, 0);

        $this->assertSame((int) $dono->id, $result['userid']);
        $this->assertSame($validuntil, $result['exp']);
    }

    public function test_generate_amarra_a_chave_a_prova(): void {
        global $DB;
        $this->resetAfterTest();
        $dono = $this->getDataGenerator()->create_user();

        [$url] = link::generate((int) $dono->id, 22);

        $key = (new moodle_url($url))->get_param('key');
        $this->assertSame('22', (string) $DB->get_field('user_private_key', 'instance', ['value' => $key]));
        $this->assertSame(['valid' => true, 'logout' => false], $this->check($key, 22, 0));
        $this->assertSame(['valid' => false, 'logout' => false], $this->check($key, 23, (int) $dono->id + 1));
    }

    public function test_chave_valida_de_outro_usuario_desloga(): void {
        $this->resetAfterTest();
        $dono = $this->getDataGenerator()->create_user();
        $outro = $this->getDataGenerator()->create_user();
        $key = $this->key_for((int) $dono->id, time() + 60);

        $this->assertSame(['valid' => true, 'logout' => true], $this->check($key, 22, (int) $outro->id));
    }

    public function test_chave_sem_validade_definida_e_valida(): void {
        $this->resetAfterTest();
        $dono = $this->getDataGenerator()->create_user();
        $outro = $this->getDataGenerator()->create_user();
        $key = $this->key_for((int) $dono->id, null);

        $this->assertSame(['valid' => true, 'logout' => true], $this->check($key, 22, (int) $outro->id));
    }

    public function test_chave_do_mesmo_usuario_e_valida_sem_logout(): void {
        $this->resetAfterTest();
        $dono = $this->getDataGenerator()->create_user();
        $key = $this->key_for((int) $dono->id, time() + 60);

        $this->assertSame(['valid' => true, 'logout' => false], $this->check($key, 22, (int) $dono->id));
    }

    public function test_ninguem_logado_nao_ha_logout(): void {
        $this->resetAfterTest();
        $dono = $this->getDataGenerator()->create_user();
        $key = $this->key_for((int) $dono->id, time() + 60);

        $this->assertSame(['valid' => true, 'logout' => false], $this->check($key, 22, 0));
    }

    public function test_chave_inexistente_e_invalida_e_nao_desloga(): void {
        $this->resetAfterTest();
        $outro = $this->getDataGenerator()->create_user();

        $this->assertSame(
            ['valid' => false, 'logout' => false],
            $this->check('chavequenaoexiste', 22, (int) $outro->id)
        );
    }

    public function test_chave_vencida_e_invalida_e_nao_desloga(): void {
        $this->resetAfterTest();
        $dono = $this->getDataGenerator()->create_user();
        $outro = $this->getDataGenerator()->create_user();
        $key = $this->key_for((int) $dono->id, time() - 5);

        $this->assertSame(['valid' => false, 'logout' => false], $this->check($key, 22, (int) $outro->id));
    }

    public function test_chave_de_outra_prova_e_invalida_e_nao_desloga(): void {
        $this->resetAfterTest();
        $dono = $this->getDataGenerator()->create_user();
        $outro = $this->getDataGenerator()->create_user();
        $key = $this->key_for((int) $dono->id, time() + 60, 22);

        $this->assertSame(['valid' => false, 'logout' => false], $this->check($key, 23, (int) $outro->id));
    }

    public function test_so_considera_chaves_do_auth_userkey(): void {
        $this->resetAfterTest();
        $dono = $this->getDataGenerator()->create_user();
        $outro = $this->getDataGenerator()->create_user();
        // Uma chave de outro script (por exemplo, a do feed de calendário) não vale e não pode deslogar ninguém.
        $key = create_user_key('core_calendar', (int) $dono->id, 22, null, time() + 60);

        $this->assertSame(['valid' => false, 'logout' => false], $this->check($key, 22, (int) $outro->id));
    }
}
