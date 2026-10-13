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

namespace local_faicrm\external;

use context_system;
use core_external\external_api;
use local_faicrm\util;
use moodle_url;

/**
 * Tests for local_faicrm_gerar_link_prova (spec 006, RF-23 and RF-25).
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_faicrm\external\gerar_link_prova
 */
final class gerar_link_prova_test extends \advanced_testcase {
    /** @var \stdClass curso do Vestibular */
    private $course;
    /** @var \stdClass prova (questionário) */
    private $quiz;
    /** @var \stdClass candidato matriculado como estudante */
    private $candidato;
    /** @var \stdClass conta técnica do SSO (ws_sso) */
    private $ws;

    /**
     * Curso com prova, candidato matriculado, auth_userkey habilitado e a conta ws_sso com a permissão.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('auth', 'userkey');
        set_config('keylifetime', 120, 'auth_userkey');

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        // Só os cursos desta categoria (e subcategorias) estão liberados para o acesso automático.
        set_config('sso_categorias', (string) $this->course->category, 'local_faicrm');
        $this->quiz = $gen->create_module('quiz', ['course' => $this->course->id]);
        $this->candidato = $gen->create_user(['username' => '12345678900']);
        $gen->enrol_user($this->candidato->id, $this->course->id, 'student');

        $this->ws = $gen->create_user(['username' => 'ws_sso']);
        $roleid = $gen->create_role(['shortname' => 'ssocrm']);
        $system = context_system::instance();
        assign_capability('local/faicrm:gerarlinkprova', CAP_ALLOW, $roleid, $system->id);
        role_assign($roleid, $this->ws->id, $system->id);
        $this->setUser($this->ws);
    }

    /**
     * Chama a função como o CRM (parâmetros e retorno validados pela descrição da função).
     *
     * @param string $username username do candidato
     * @param int|null $cmid cmid da prova (padrão: a prova do curso)
     * @return array retorno limpo
     */
    private function gerar(string $username, ?int $cmid = null): array {
        $result = gerar_link_prova::execute($username, $cmid ?? (int) $this->quiz->cmid);
        return external_api::clean_returnvalue(gerar_link_prova::execute_returns(), $result);
    }

    /**
     * Espera uma moodle_exception com o errorcode dado.
     *
     * @param string $errorcode errorcode esperado
     * @param callable $call chamada
     */
    private function assert_erro(string $errorcode, callable $call): void {
        try {
            $call();
            $this->fail("Esperava a exceção {$errorcode}");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * Espera "registro não encontrado" na tabela dada (404 no rest_json).
     *
     * @param string $table tabela esperada
     * @param callable $call chamada
     */
    private function assert_nao_encontrado(string $table, callable $call): void {
        try {
            $call();
            $this->fail("Esperava dml_missing_record_exception em {$table}");
        } catch (\dml_missing_record_exception $e) {
            $this->assertSame($table, $e->tablename);
        }
    }

    public function test_gera_link_de_uso_unico_que_leva_a_prova(): void {
        global $DB, $CFG;

        $antes = time();
        $result = $this->gerar('12345678900');
        $depois = time();

        $url = new moodle_url($result['url']);
        // O link leva à página de entrada do plugin (T-15), que depois redireciona ao login do auth_userkey.
        $this->assertSame($CFG->wwwroot . '/local/faicrm/entrar.php', $url->out_omit_querystring());
        $this->assertSame((string) $this->quiz->cmid, $url->get_param('cmid'));
        $this->assertNull($url->get_param('wantsurl'));

        $keys = $DB->get_records('user_private_key', ['script' => 'auth/userkey', 'userid' => $this->candidato->id]);
        $this->assertCount(1, $keys);
        $key = reset($keys);
        $this->assertSame($key->value, $url->get_param('key'));
        $this->assertGreaterThanOrEqual($antes + 120, (int) $key->validuntil);
        $this->assertLessThanOrEqual($depois + 120, (int) $key->validuntil);
        $this->assertEmpty($key->iprestriction);
        $this->assertSame(util::format_date((int) $key->validuntil), $result['expiraem']);
    }

    public function test_nova_chamada_invalida_o_link_anterior(): void {
        global $DB;

        $primeiro = new moodle_url($this->gerar('12345678900')['url']);
        $segundo = new moodle_url($this->gerar('12345678900')['url']);

        $values = $DB->get_fieldset_select(
            'user_private_key',
            'value',
            'script = :script AND userid = :userid',
            ['script' => 'auth/userkey', 'userid' => $this->candidato->id]
        );
        $this->assertSame([$segundo->get_param('key')], $values);
        $this->assertNotSame($primeiro->get_param('key'), $segundo->get_param('key'));
    }

    public function test_validade_padrao_de_60_segundos_quando_nao_configurada(): void {
        global $DB;

        unset_config('keylifetime', 'auth_userkey');
        $antes = time();
        $this->gerar('12345678900');

        $validuntil = (int) $DB->get_field('user_private_key', 'validuntil', ['userid' => $this->candidato->id]);
        $this->assertGreaterThanOrEqual($antes + 60, $validuntil);
        $this->assertLessThanOrEqual(time() + 60, $validuntil);
    }

    public function test_link_sem_restricao_de_ip_mesmo_com_a_restricao_do_plugin_ligada(): void {
        global $DB;

        set_config('iprestriction', 1, 'auth_userkey');
        $this->gerar('12345678900');

        $this->assertEmpty($DB->get_field('user_private_key', 'iprestriction', ['userid' => $this->candidato->id]));
    }

    public function test_sem_permissao(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        $this->gerar('12345678900');
    }

    public function test_sso_indisponivel_com_o_auth_userkey_desabilitado(): void {
        set_config('auth', '');

        $this->assert_erro('ssoindisponivel', fn() => $this->gerar('12345678900'));
    }

    public function test_prova_inexistente_ou_atividade_que_nao_e_prova(): void {
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);

        $this->assert_nao_encontrado('quiz', fn() => $this->gerar('12345678900', 999999));
        $this->assert_nao_encontrado('quiz', fn() => $this->gerar('12345678900', (int) $forum->cmid));
    }

    public function test_candidato_inexistente_ou_excluido(): void {
        $excluido = $this->getDataGenerator()->create_user(['username' => '99999999999']);
        delete_user($excluido);

        $this->assert_nao_encontrado('user', fn() => $this->gerar('00000000000'));
        $this->assert_nao_encontrado('user', fn() => $this->gerar('99999999999'));
    }

    public function test_contas_que_nao_sao_de_candidato(): void {
        global $DB;

        $gen = $this->getDataGenerator();
        $system = context_system::instance();

        // Admin e guest, mesmo matriculados como estudante.
        $gen->enrol_user(get_admin()->id, $this->course->id, 'student');
        $this->assert_erro('contanaopermitida', fn() => $this->gerar(get_admin()->username));
        $this->assert_erro('contanaopermitida', fn() => $this->gerar('guest'));

        // Estudante aqui e professor em outro curso.
        $professor = $gen->create_user(['username' => '11111111111']);
        $gen->enrol_user($professor->id, $this->course->id, 'student');
        $gen->enrol_user($professor->id, $gen->create_course()->id, 'editingteacher');
        $this->assert_erro('contanaopermitida', fn() => $this->gerar('11111111111'));

        // Conta técnica com papel de sistema sem arquétipo (como o ws_crm).
        $tecnico = $gen->create_user(['username' => 'ws_crm']);
        $gen->enrol_user($tecnico->id, $this->course->id, 'student');
        role_assign($gen->create_role(['shortname' => 'integracaocrm']), $tecnico->id, $system->id);
        $this->assert_erro('contanaopermitida', fn() => $this->gerar('ws_crm'));

        // Gestor numa categoria.
        $gestor = $gen->create_user(['username' => '22222222222']);
        $gen->enrol_user($gestor->id, $this->course->id, 'student');
        $managerid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($managerid, $gestor->id, \context_coursecat::instance($this->course->category)->id);
        $this->assert_erro('contanaopermitida', fn() => $this->gerar('22222222222'));

        // A própria conta do SSO.
        $this->assert_erro('contanaopermitida', fn() => $this->gerar('ws_sso'));
    }

    public function test_estudante_em_mais_de_um_curso_liberado_pode_entrar(): void {
        // Outro curso da mesma categoria liberada.
        $outro = $this->getDataGenerator()->create_course(['category' => $this->course->category]);
        $this->getDataGenerator()->enrol_user($this->candidato->id, $outro->id, 'student');

        $this->assertArrayHasKey('url', $this->gerar('12345678900'));
    }

    public function test_estudante_de_curso_fora_das_categorias_liberadas_nao_entra(): void {
        $gen = $this->getDataGenerator();
        $regular = $gen->create_course(['category' => $gen->create_category()->id]);
        $gen->enrol_user($this->candidato->id, $regular->id, 'student');

        $this->assert_erro('contanaopermitida', fn() => $this->gerar('12345678900'));
    }

    public function test_papel_de_estudante_fora_de_curso_ou_atividade_nao_entra(): void {
        global $DB;

        $studentid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        role_assign($studentid, $this->candidato->id, \context_coursecat::instance($this->course->category)->id);

        $this->assert_erro('contanaopermitida', fn() => $this->gerar('12345678900'));
    }

    public function test_estudante_com_papel_na_atividade_de_curso_liberado_entra(): void {
        global $DB;

        $studentid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        role_assign($studentid, $this->candidato->id, \context_module::instance($this->quiz->cmid)->id);

        $this->assertArrayHasKey('url', $this->gerar('12345678900'));
    }

    public function test_sem_categoria_configurada_o_sso_fica_indisponivel(): void {
        unset_config('sso_categorias', 'local_faicrm');
        $this->assert_erro('ssoindisponivel', fn() => $this->gerar('12345678900'));

        set_config('sso_categorias', '', 'local_faicrm');
        $this->assert_erro('ssoindisponivel', fn() => $this->gerar('12345678900'));
    }

    public function test_prova_fora_das_categorias_liberadas(): void {
        $gen = $this->getDataGenerator();
        $curso = $gen->create_course(['category' => $gen->create_category()->id]);
        $prova = $gen->create_module('quiz', ['course' => $curso->id]);

        $this->assert_erro('provanaoliberada', fn() => $this->gerar('12345678900', (int) $prova->cmid));
    }

    public function test_prova_em_subcategoria_de_categoria_liberada(): void {
        $gen = $this->getDataGenerator();
        $pai = $gen->create_category();
        $filha = $gen->create_category(['parent' => $pai->id]);
        $curso = $gen->create_course(['category' => $filha->id]);
        $prova = $gen->create_module('quiz', ['course' => $curso->id]);
        $candidato = $gen->create_user(['username' => '13579246800']);
        $gen->enrol_user($candidato->id, $curso->id, 'student');
        set_config('sso_categorias', (string) $pai->id, 'local_faicrm');

        $this->assertArrayHasKey('url', $this->gerar('13579246800', (int) $prova->cmid));
    }

    public function test_varias_categorias_liberadas_como_o_multiselect_grava(): void {
        $gen = $this->getDataGenerator();
        $outra = $gen->create_category();
        $curso = $gen->create_course(['category' => $outra->id]);
        $prova = $gen->create_module('quiz', ['course' => $curso->id]);
        $candidato = $gen->create_user(['username' => '24681357900']);
        $gen->enrol_user($candidato->id, $curso->id, 'student');
        set_config('sso_categorias', $this->course->category . ',' . $outra->id, 'local_faicrm');

        $this->assertArrayHasKey('url', $this->gerar('24681357900', (int) $prova->cmid));
    }

    public function test_candidato_suspenso_ou_com_login_bloqueado(): void {
        $gen = $this->getDataGenerator();
        foreach (
            [
                ['username' => '33333333333', 'suspended' => 1],
                ['username' => '44444444444', 'auth' => 'nologin'],
                ['username' => '55555555555', 'auth' => 'email'],
            ] as $dados
        ) {
            $user = $gen->create_user($dados);
            $gen->enrol_user($user->id, $this->course->id, 'student');
            $this->assert_erro('candidatosuspenso', fn() => $this->gerar($dados['username']));
        }
    }

    public function test_sem_matricula_ativa_no_curso_da_prova(): void {
        $gen = $this->getDataGenerator();
        $semmatricula = $gen->create_user(['username' => '66666666666']);
        $gen->enrol_user($semmatricula->id, $gen->create_course()->id, 'student');
        $suspensa = $gen->create_user(['username' => '77777777777']);
        $gen->enrol_user($suspensa->id, $this->course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);

        $this->assert_erro('candidatonaomatriculado', fn() => $this->gerar('66666666666'));
        $this->assert_erro('candidatonaomatriculado', fn() => $this->gerar('77777777777'));
    }

    public function test_papel_copiado_de_estudante_nao_e_aceito(): void {
        $gen = $this->getDataGenerator();
        // O arquétipo é só um rótulo: só o papel de shortname "student" vale.
        $monitor = $gen->create_role(['shortname' => 'monitor', 'archetype' => 'student']);
        role_assign($monitor, $this->candidato->id, \context_course::instance($this->course->id)->id);

        $this->assert_erro('contanaopermitida', fn() => $this->gerar('12345678900'));
    }

    public function test_papel_em_contexto_de_usuario_nao_e_aceito(): void {
        $gen = $this->getDataGenerator();
        $parent = $gen->create_role(['shortname' => 'parent', 'archetype' => 'user']);
        $filho = $gen->create_user();
        role_assign($parent, $this->candidato->id, \context_user::instance($filho->id)->id);

        $this->assert_erro('contanaopermitida', fn() => $this->gerar('12345678900'));
    }

    /**
     * Capabilities perigosas no contexto de sistema.
     *
     * @return array
     */
    public static function capabilities_perigosas(): array {
        return array_map(fn($cap) => [$cap], [
            'moodle/site:config',
            'moodle/user:create',
            'moodle/user:update',
            'moodle/role:assign',
            'moodle/course:update',
            'moodle/user:loginas',
        ]);
    }

    /**
     * Uma capability perigosa dada a todos os usuários autenticados barra o candidato.
     *
     * @dataProvider capabilities_perigosas
     * @param string $capability capability dada ao papel "Usuário autenticado"
     */
    public function test_capability_perigosa_no_sistema_barra_o_candidato(string $capability): void {
        global $DB;

        // Permissão dada a todos os usuários autenticados (o candidato não tem papel extra).
        $userrole = (int) $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability($capability, CAP_ALLOW, $userrole, context_system::instance()->id, true);

        $this->assert_erro('contanaopermitida', fn() => $this->gerar('12345678900'));
    }

    public function test_com_o_app_movel_ligado_o_candidato_entra(): void {
        global $DB;

        // O Moodle dá webservice/rest:use ao papel "Usuário autenticado" quando o app móvel é ligado (padrão em HTTPS).
        $userrole = (int) $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability('webservice/rest:use', CAP_ALLOW, $userrole, context_system::instance()->id, true);

        $this->assertArrayHasKey('url', $this->gerar('12345678900'));
    }

    public function test_override_de_permissao_na_prova_barra_o_candidato(): void {
        global $DB;

        // Um override errado no papel Estudante, só no curso da prova.
        $studentid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability(
            'mod/quiz:viewreports',
            CAP_ALLOW,
            $studentid,
            \context_course::instance($this->course->id)->id,
            true
        );

        $this->assert_erro('contanaopermitida', fn() => $this->gerar('12345678900'));
    }

    public function test_matriculado_sem_papel_nao_entra(): void {
        global $DB;

        $gen = $this->getDataGenerator();
        $sempapel = $gen->create_user(['username' => '19283746500']);
        $gen->enrol_user($sempapel->id, $this->course->id, 0);
        $this->assertFalse($DB->record_exists('role_assignments', ['userid' => $sempapel->id]));

        $this->assert_erro('contanaopermitida', fn() => $this->gerar('19283746500'));
    }

    public function test_gerar_para_outra_prova_invalida_a_chave_da_primeira(): void {
        global $DB;

        $provab = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $primeira = new moodle_url($this->gerar('12345678900')['url']);
        $segunda = new moodle_url($this->gerar('12345678900', (int) $provab->cmid)['url']);

        $values = $DB->get_fieldset_select(
            'user_private_key',
            'value',
            'script = :script AND userid = :userid',
            ['script' => 'auth/userkey', 'userid' => $this->candidato->id]
        );
        $this->assertSame([$segunda->get_param('key')], $values);
        $this->assertNotSame($primeira->get_param('key'), $segunda->get_param('key'));
    }

    public function test_trava_por_usuario_vale_para_todas_as_provas(): void {
        $lock = util::lock_user_sso((int) $this->candidato->id);
        $this->assertInstanceOf(\core\lock\lock::class, $lock);
        $lock->release();
    }

    public function test_candidato_inexistente_nao_expoe_o_cpf_no_erro(): void {
        try {
            $this->gerar('98765432111');
            $this->fail('Esperava dml_missing_record_exception');
        } catch (\dml_missing_record_exception $e) {
            $this->assertSame('user', $e->tablename);
            $this->assertStringNotContainsString('98765432111', (string) $e->debuginfo);
            $this->assertStringNotContainsString('98765432111', $e->getMessage());
        }
    }

    public function test_categoria_liberada_e_depois_excluida_deixa_o_sso_indisponivel(): void {
        $categoria = $this->getDataGenerator()->create_category();
        set_config('sso_categorias', (string) $categoria->id, 'local_faicrm');
        \core_course_category::get($categoria->id)->delete_full(false);

        $this->assert_erro('ssoindisponivel', fn() => $this->gerar('12345678900'));
    }

    public function test_candidato_nao_confirmado(): void {
        $gen = $this->getDataGenerator();
        $user = $gen->create_user(['username' => '12312312312', 'confirmed' => 0]);
        $gen->enrol_user($user->id, $this->course->id, 'student');

        $this->assert_erro('candidatosuspenso', fn() => $this->gerar('12312312312'));
    }

    public function test_so_conta_local_e_ignora_username_de_outro_mnethost(): void {
        global $CFG, $DB;

        $gen = $this->getDataGenerator();
        // Só existe uma conta de outro host com esse username: não é encontrada.
        $gen->create_user(['username' => '88888888888', 'mnethostid' => $CFG->mnet_localhost_id + 1]);
        $this->assert_nao_encontrado('user', fn() => $this->gerar('88888888888'));

        // Com as duas contas, vale a local.
        $local = $gen->create_user(['username' => '88888888888', 'mnethostid' => $CFG->mnet_localhost_id]);
        $gen->enrol_user($local->id, $this->course->id, 'student');
        $this->gerar('88888888888');
        $this->assertTrue($DB->record_exists('user_private_key', ['script' => 'auth/userkey', 'userid' => $local->id]));
    }

    public function test_prova_em_exclusao_nao_e_encontrada(): void {
        global $DB;

        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $this->quiz->cmid]);

        $this->assert_nao_encontrado('quiz', fn() => $this->gerar('12345678900'));
    }

    public function test_validade_limitada_a_120_segundos(): void {
        global $DB;

        set_config('keylifetime', 86400, 'auth_userkey');
        $this->gerar('12345678900');

        $validuntil = (int) $DB->get_field('user_private_key', 'validuntil', ['userid' => $this->candidato->id]);
        $this->assertLessThanOrEqual(time() + 120, $validuntil);
        $this->assertGreaterThan(time() + 100, $validuntil);
    }

    public function test_registra_o_evento_de_auditoria_sem_a_chave(): void {
        global $DB;

        $sink = $this->redirectEvents();
        $result = $this->gerar('12345678900');
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof \local_faicrm\event\link_prova_gerado
        ));
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('c', $event->crud);
        $this->assertSame(\core\event\base::LEVEL_OTHER, $event->edulevel);
        $this->assertSame((int) $this->candidato->id, (int) $event->relateduserid);
        $this->assertSame((int) $this->ws->id, (int) $event->userid);
        $this->assertSame((int) $this->quiz->cmid, (int) $event->contextinstanceid);
        $this->assertSame(CONTEXT_MODULE, (int) $event->contextlevel);
        $this->assertSame((int) $this->quiz->cmid, (int) $event->objectid);
        $this->assertSame('course_modules', $event->objecttable);

        // A chave não aparece em nenhum campo nem na descrição.
        $key = (string) $DB->get_field('user_private_key', 'value', ['userid' => $this->candidato->id]);
        $this->assertNotSame('', $key);
        $this->assertStringNotContainsString($key, json_encode($event->get_data()));
        $this->assertStringNotContainsString($key, $event->get_description());
        $this->assertStringNotContainsString($key, $event->get_url()->out(false));
        $this->assertStringNotContainsString($key, $event::get_name());
        $this->assertStringContainsString($key, $result['url']);
    }

    public function test_funcao_so_no_servico_do_sso(): void {
        global $DB;

        $sso = $DB->get_record('external_services', ['shortname' => 'crm_sso_vestibular_fai'], '*', MUST_EXIST);
        $this->assertEquals(1, $sso->restrictedusers);
        $this->assertSame(
            ['local_faicrm_gerar_link_prova'],
            $DB->get_fieldset_select('external_services_functions', 'functionname', 'externalserviceid = ?', [$sso->id])
        );

        $crm = $DB->get_field('external_services', 'id', ['shortname' => 'crm_vestibular_fai'], MUST_EXIST);
        $this->assertFalse($DB->record_exists('external_services_functions', [
            'externalserviceid' => $crm, 'functionname' => 'local_faicrm_gerar_link_prova',
        ]));
    }
}
