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

/**
 * Tests for the read-only diagnostic of the automatic access (spec 006, T-13).
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_faicrm\sso\diagnostico
 */
final class diagnostico_test extends \advanced_testcase {
    /** @var \stdClass categoria liberada */
    private $categoria;

    /**
     * Cenário saudável: auth_userkey habilitado e sem uso indevido, uma categoria liberada e o serviço do SSO pronto.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        set_config('auth', 'userkey');
        set_config('keylifetime', 120, 'auth_userkey');

        $gen = $this->getDataGenerator();
        $this->categoria = $gen->create_category();
        set_config('sso_categorias', (string) $this->categoria->id, 'local_faicrm');

        // O serviço do SSO tem um usuário autorizado e um token válido.
        $ws = $gen->create_user(['username' => 'ws_sso']);
        $service = $DB->get_record('external_services', ['shortname' => 'crm_sso_vestibular_fai'], '*', MUST_EXIST);
        $DB->insert_record('external_services_users', (object) [
            'externalserviceid' => $service->id, 'userid' => $ws->id, 'timecreated' => time(),
        ]);
        \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            $ws->id,
            \context_system::instance(),
            0,
            '',
            'sso'
        );
    }

    /**
     * Devolve o item do diagnóstico com o código dado.
     *
     * @param string $code código do item
     * @return array item com status e texto
     */
    private function item(string $code): array {
        foreach (diagnostico::run() as $item) {
            if ($item['code'] === $code) {
                return $item;
            }
        }
        $this->fail("Item {$code} não encontrado no diagnóstico");
    }

    public function test_tudo_ok(): void {
        $items = diagnostico::run();

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertSame(['code', 'status', 'text'], array_keys($item));
            $this->assertSame('OK', $item['status'], $item['code'] . ': ' . $item['text']);
            $this->assertNotSame('', $item['text']);
        }
        $this->assertFalse(diagnostico::has_failures($items));
    }

    public function test_auth_userkey_desabilitado_e_so_um_aviso(): void {
        set_config('auth', '');

        $items = diagnostico::run();

        $this->assertCount(1, $items);
        $this->assertSame('userkey_habilitado', $items[0]['code']);
        $this->assertSame('AVISO', $items[0]['status']);
        $this->assertFalse(diagnostico::has_failures($items));
    }

    public function test_servico_do_auth_userkey_com_usuario_autorizado_e_falha(): void {
        global $DB;

        $service = $DB->get_record('external_services', ['name' => 'User key authentication web service'], '*', MUST_EXIST);
        $DB->insert_record('external_services_users', (object) [
            'externalserviceid' => $service->id, 'userid' => get_admin()->id, 'timecreated' => time(),
        ]);

        $this->assertSame('FALHA', $this->item('userkey_servico_sem_usuarios')['status']);
        $this->assertTrue(diagnostico::has_failures(diagnostico::run()));
    }

    public function test_funcao_do_auth_userkey_em_outro_servico_e_falha(): void {
        global $DB;

        $sso = $DB->get_field('external_services', 'id', ['shortname' => 'crm_sso_vestibular_fai'], MUST_EXIST);
        $DB->insert_record('external_services_functions', (object) [
            'externalserviceid' => $sso, 'functionname' => 'auth_userkey_request_login_url',
        ]);

        $this->assertSame('FALHA', $this->item('userkey_funcao_so_no_servico_dele')['status']);
    }

    public function test_capability_de_gerar_chave_em_um_papel_e_falha(): void {
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'chaveiro', 'archetype' => 'user']);
        assign_capability('auth/userkey:generatekey', CAP_ALLOW, $roleid, \context_system::instance()->id, true);

        $this->assertSame('FALHA', $this->item('userkey_generatekey')['status']);
    }

    public function test_ssourl_preenchido_e_aviso(): void {
        set_config('ssourl', 'https://crm.example/login', 'auth_userkey');

        $this->assertSame('AVISO', $this->item('userkey_urls')['status']);
    }

    public function test_keylifetime_600_e_aviso(): void {
        set_config('keylifetime', 600, 'auth_userkey');

        $this->assertSame('AVISO', $this->item('userkey_keylifetime')['status']);
    }

    public function test_criar_ou_atualizar_usuario_ligado_e_aviso(): void {
        set_config('createuser', 1, 'auth_userkey');

        $this->assertSame('AVISO', $this->item('userkey_cria_atualiza')['status']);
    }

    public function test_sem_categorias_e_aviso(): void {
        unset_config('sso_categorias', 'local_faicrm');

        $this->assertSame('AVISO', $this->item('sso_categorias')['status']);
    }

    public function test_servico_do_sso_desabilitado_e_falha(): void {
        global $DB;

        $DB->set_field('external_services', 'enabled', 0, ['shortname' => 'crm_sso_vestibular_fai']);

        $this->assertSame('FALHA', $this->item('sso_servico_habilitado')['status']);
    }

    public function test_servico_do_sso_sem_usuario_e_sem_token_e_aviso(): void {
        global $DB;

        $id = $DB->get_field('external_services', 'id', ['shortname' => 'crm_sso_vestibular_fai'], MUST_EXIST);
        $DB->delete_records('external_services_users', ['externalserviceid' => $id]);
        $DB->delete_records('external_tokens', ['externalserviceid' => $id]);

        $this->assertSame('AVISO', $this->item('sso_servico_usuario')['status']);
        $this->assertSame('AVISO', $this->item('sso_servico_token')['status']);
    }
}
