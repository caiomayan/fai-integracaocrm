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

use core\hook\output\before_http_headers;
use mod_quiz\event\attempt_submitted;

/**
 * Tests for the return to the CRM after the quiz (spec 007, CA-38).
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_faicrm\sso\retorno
 * @covers     \local_faicrm\sso\setting_retorno_url
 */
final class retorno_test extends \advanced_testcase {
    /** @var \stdClass candidato */
    private $aluno;
    /** @var \stdClass curso da prova */
    private $curso;
    /** @var \stdClass prova */
    private $prova;
    /** @var \mod_quiz\quiz_attempt tentativa iniciada do candidato */
    private $tentativa;

    /**
     * Cenário: retorno ligado, candidato logado, uma prova com uma tentativa iniciada.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $gen = $this->getDataGenerator();

        $this->aluno = $gen->create_user();
        $this->curso = $gen->create_course();
        $gen->enrol_user($this->aluno->id, $this->curso->id, 'student');
        $this->prova = $gen->create_module('quiz', [
            'course' => $this->curso->id, 'questionsperpage' => 0, 'grade' => 100.0, 'sumgrades' => 1,
        ]);
        $qgen = $gen->get_plugin_generator('core_question');
        $categoria = $qgen->create_question_category();
        $pergunta = $qgen->create_question('shortanswer', null, ['category' => $categoria->id]);
        quiz_add_quiz_question($pergunta->id, $this->prova);

        $quizobj = \mod_quiz\quiz_settings::create($this->prova->id, $this->aluno->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $tentativa = quiz_create_attempt($quizobj, 1, false, time(), false, $this->aluno->id);
        quiz_start_new_attempt($quizobj, $quba, $tentativa, 1, time());
        quiz_attempt_save_started($quizobj, $quba, $tentativa);
        $this->tentativa = \mod_quiz\quiz_attempt::create($tentativa->id);

        $this->setUser($this->aluno);
        set_config('retorno_url', 'https://crm.example/retorno', 'local_faicrm');
    }

    /**
     * Course module id da prova do cenário.
     *
     * @return int
     */
    private function cmid(): int {
        return (int) $this->prova->cmid;
    }

    /**
     * Cria o evento de envio da tentativa do cenário.
     *
     * @param bool $online se o aluno estava online
     * @param int|null $submitter quem enviou (null = o aluno)
     * @param int|null $owner dono da tentativa (null = o aluno)
     * @return attempt_submitted
     */
    private function evento(bool $online = true, ?int $submitter = null, ?int $owner = null): attempt_submitted {
        return attempt_submitted::create([
            'objectid' => $this->tentativa->get_attemptid(),
            'relateduserid' => $owner ?? $this->aluno->id,
            'courseid' => $this->curso->id,
            'context' => \context_module::instance($this->cmid()),
            'other' => [
                'submitterid' => $submitter ?? $this->aluno->id,
                'quizid' => $this->prova->id,
                'studentisonline' => $online,
            ],
        ]);
    }

    /**
     * Marca a prova como a do retorno, com a assinatura certa para o usuário logado (o que a iniciar.php faz).
     *
     * @param int|null $cmid prova (null = a do cenário)
     */
    private function marcar(?int $cmid = null): void {
        global $USER;
        $cmid = $cmid ?? $this->cmid();
        $exp = time() + 60;
        $this->assertTrue(retorno::mark_signed((int) $USER->id, $cmid, $exp, retorno::sign((int) $USER->id, $cmid, $exp)));
    }

    /**
     * Envia a tentativa de verdade e entrega ao observer o evento como ele chega num pedido web (no PHPUnit o submitterid
     * do evento real é nulo, porque o teste roda em CLI).
     */
    private function enviar_online(): void {
        $this->tentativa->process_finish(time(), false, null, true);
        retorno::on_attempt_submitted($this->evento());
    }

    // URL final.

    public function test_build_url_acrescenta_status_e_cmid(): void {
        $url = retorno::build_url('https://crm.example/retorno', 22);

        $this->assertSame('https://crm.example/retorno?status=concluida&cmid=22', $url);
    }

    public function test_build_url_mantem_a_query_existente(): void {
        $url = new \moodle_url(retorno::build_url('https://crm.example/retorno?origem=moodle&x=1', 22));

        $this->assertSame('moodle', $url->get_param('origem'));
        $this->assertSame('1', $url->get_param('x'));
        $this->assertSame('concluida', $url->get_param('status'));
        $this->assertSame('22', $url->get_param('cmid'));
    }

    public function test_build_url_substitui_valores_de_mesmo_nome(): void {
        $url = new \moodle_url(retorno::build_url('https://crm.example/retorno?status=outra&cmid=1', 22));

        $this->assertSame('concluida', $url->get_param('status'));
        $this->assertSame('22', $url->get_param('cmid'));
        $this->assertSame(2, substr_count($url->out(false), '='));
    }

    public function test_build_url_nao_leva_dado_pessoal(): void {
        $url = retorno::build_url('https://crm.example/retorno', 22);

        $this->assertSame('status=concluida&cmid=22', parse_url($url, PHP_URL_QUERY));
    }

    public function test_final_url_so_com_a_configuracao_ligada(): void {
        $this->assertSame('https://crm.example/retorno?status=concluida&cmid=22', retorno::final_url(22));

        set_config('retorno_url', '', 'local_faicrm');
        $this->assertNull(retorno::final_url(22));
    }

    // Validação da configuração (a mesma regra ao salvar e ao usar).

    public function test_validacao_vazio_e_https_valem(): void {
        $this->assertTrue(retorno::is_valid_url(''));
        $this->assertTrue(retorno::is_valid_url('https://crm.example/retorno'));
        $this->assertTrue(retorno::is_valid_url('https://crm.example:8443/a/b?x=1#topo'));
    }

    public function test_validacao_http_so_se_o_moodle_for_http(): void {
        global $CFG;

        $CFG->wwwroot = 'https://moodle.example';
        $this->assertFalse(retorno::is_valid_url('http://crm.example/retorno'));

        $CFG->wwwroot = 'http://localhost:8080';
        $this->assertTrue(retorno::is_valid_url('http://crm.example/retorno'));
        $this->assertTrue(retorno::is_valid_url('http://localhost:3000/retorno'));
        $this->assertTrue(retorno::is_valid_url('https://crm.example/retorno'));
    }

    /**
     * Valores que nunca valem.
     *
     * @return array[]
     */
    public static function urls_invalidas(): array {
        return [
            'texto solto' => ['lixo'],
            'javascript' => ['javascript:alert(1)'],
            'ftp' => ['ftp://crm.example/retorno'],
            'data' => ['data:text/html,<b>x</b>'],
            'sem host' => ['https://'],
            'relativa ao protocolo' => ['//crm.example/retorno'],
            'caminho' => ['/local/faicrm/concluido.php'],
            'com usuário e senha' => ['https://usuario:senha@crm.example/retorno'],
            'só usuário' => ['https://usuario@crm.example/retorno'],
            'com espaço' => ['https://crm.example/a b'],
            'com quebra de linha' => ["https://crm.example/a\r\nSet-Cookie: x=1"],
        ];
    }

    /**
     * Valores inválidos são recusados.
     *
     * @dataProvider urls_invalidas
     * @param string $valor valor inválido
     */
    public function test_validacao_recusa_lixo(string $valor): void {
        $this->assertFalse(retorno::is_valid_url($valor));
    }

    public function test_a_classe_do_setting_recusa_o_invalido_e_aceita_o_valido(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $setting = new setting_retorno_url('local_faicrm/retorno_url', 'URL', 'desc', '');

        $this->assertTrue($setting->validate(''));
        $this->assertTrue($setting->validate('https://crm.example/retorno'));
        $this->assertIsString($setting->validate('javascript:alert(1)'));
        $this->assertIsString($setting->validate('https://usuario:senha@crm.example/retorno'));
    }

    public function test_url_configurada_vazia_e_desligado(): void {
        $this->assertSame('https://crm.example/retorno', retorno::url());
        $this->assertTrue(retorno::enabled());

        set_config('retorno_url', '', 'local_faicrm');
        $this->assertNull(retorno::url());
        $this->assertFalse(retorno::enabled());
    }

    public function test_url_invalida_forcada_na_configuracao_vira_desligado(): void {
        // Um valor que não passou pela tela (CLI, config.php) nunca vira destino.
        foreach (['javascript:alert(1)', 'https://usuario:senha@crm.example/x', 'lixo'] as $valor) {
            set_config('retorno_url', $valor, 'local_faicrm');

            $this->assertNull(retorno::url(), $valor);
            $this->assertFalse(retorno::enabled(), $valor);
            $this->assertNull(retorno::final_url(22), $valor);
        }
    }

    public function test_http_gravado_e_o_moodle_virou_https_desliga(): void {
        global $CFG;
        $CFG->wwwroot = 'http://localhost:8080';
        set_config('retorno_url', 'http://crm.example/retorno', 'local_faicrm');
        $this->assertNotNull(retorno::url());

        $CFG->wwwroot = 'https://moodle.example';
        $this->assertNull(retorno::url());
    }

    // Marcação presa ao link (HMAC).

    public function test_assinatura_certa_marca(): void {
        global $USER;
        $exp = time() + 60;

        $this->assertTrue(retorno::mark_signed((int) $USER->id, 22, $exp, retorno::sign((int) $USER->id, 22, $exp)));
        $this->assertSame(22, retorno::marked_cmid());
    }

    public function test_sem_assinatura_nao_marca(): void {
        global $USER;

        $this->assertFalse(retorno::mark_signed((int) $USER->id, 22, time() + 60, ''));
        $this->assertNull(retorno::marked_cmid());
    }

    public function test_assinatura_de_outro_usuario_nao_marca(): void {
        global $USER;
        $exp = time() + 60;

        $this->assertFalse(retorno::mark_signed((int) $USER->id, 22, $exp, retorno::sign((int) $USER->id + 1, 22, $exp)));
        $this->assertNull(retorno::marked_cmid());
    }

    public function test_assinatura_de_outra_prova_nao_marca(): void {
        global $USER;
        $exp = time() + 60;

        $this->assertFalse(retorno::mark_signed((int) $USER->id, 22, $exp, retorno::sign((int) $USER->id, 23, $exp)));
        $this->assertNull(retorno::marked_cmid());
    }

    public function test_validade_alterada_invalida_a_assinatura(): void {
        global $USER;
        $exp = time() + 60;

        $this->assertFalse(retorno::mark_signed((int) $USER->id, 22, $exp + 3600, retorno::sign((int) $USER->id, 22, $exp)));
    }

    public function test_assinatura_vencida_nao_marca_mas_tem_a_tolerancia_de_um_minuto(): void {
        global $USER;
        $velha = time() - 3600;
        $this->assertFalse(retorno::mark_signed((int) $USER->id, 22, $velha, retorno::sign((int) $USER->id, 22, $velha)));

        $recente = time() - 30;
        $this->assertTrue(retorno::mark_signed((int) $USER->id, 22, $recente, retorno::sign((int) $USER->id, 22, $recente)));
    }

    public function test_remarcar_outra_prova_sem_assinatura_mantem_a_marcacao_original(): void {
        global $USER;
        $this->marcar(22);

        $this->assertFalse(retorno::mark_signed((int) $USER->id, 99, time() + 60, ''));

        $this->assertSame(22, retorno::marked_cmid());
    }

    public function test_o_segredo_vem_da_instalacao_e_nao_muda(): void {
        $segredo = get_config('local_faicrm', 'sso_secret');

        $this->assertSame(64, strlen($segredo));
        $this->assertNotSame('', retorno::sign(1, 22, 100));
        $this->assertSame(retorno::sign(1, 22, 100), retorno::sign(1, 22, 100));
        $this->assertSame($segredo, get_config('local_faicrm', 'sso_secret'));
    }

    public function test_a_assinatura_tem_prefixo_de_finalidade(): void {
        $esperado = hash_hmac('sha256', 'retorno|v1|7|22|100', get_config('local_faicrm', 'sso_secret'));

        $this->assertSame($esperado, retorno::sign(7, 22, 100));
    }

    public function test_sem_segredo_nao_assina_nao_marca_e_nao_grava_configuracao(): void {
        global $USER;
        unset_config('sso_secret', 'local_faicrm');

        $this->assertSame('', retorno::sign((int) $USER->id, 22, time() + 60));
        $this->assertFalse(retorno::mark_signed((int) $USER->id, 22, time() + 60, ''));
        $this->assertFalse(retorno::mark_signed((int) $USER->id, 22, time() + 60, retorno::sign((int) $USER->id, 22, time() + 60)));
        $this->assertNull(retorno::marked_cmid());
        // Em execução o segredo só é lido: nunca nasce num pedido.
        $this->assertFalse(get_config('local_faicrm', 'sso_secret'));
    }

    public function test_o_install_e_o_upgrade_criam_o_segredo_so_se_vazio(): void {
        unset_config('sso_secret', 'local_faicrm');
        retorno::ensure_secret();
        $criado = get_config('local_faicrm', 'sso_secret');
        $this->assertSame(64, strlen($criado));

        retorno::ensure_secret();
        $this->assertSame($criado, get_config('local_faicrm', 'sso_secret'));
    }

    // Trava de reentrada do hook.

    /**
     * Prepara a página como a revisão da prova do cenário, com o retorno pendente de uma tentativa enviada.
     *
     * @return before_http_headers
     */
    private function hook_na_revisao(): before_http_headers {
        global $PAGE;
        $this->marcar();
        $this->enviar_online();
        // A página do cenário já tem o tema montado; usa uma nova, como num pedido de verdade.
        $PAGE = new \moodle_page();
        $PAGE->set_url('/mod/quiz/review.php');
        $PAGE->set_cm(get_coursemodule_from_id('quiz', $this->cmid()), $this->curso);
        $PAGE->set_pagetype('mod-quiz-review');
        return new before_http_headers($PAGE->get_renderer('core'));
    }

    public function test_o_hook_nao_redireciona_duas_vezes_na_mesma_requisicao(): void {
        retorno::reset_diverting();
        $hook = $this->hook_na_revisao();

        $redirecionou = false;
        try {
            retorno::before_http_headers($hook);
        } catch (\Throwable $e) {
            $redirecionou = true;
        }
        $this->assertTrue($redirecionou, 'a primeira chamada deve redirecionar');
        $this->assertNotNull(retorno::pending());

        // Reentrada (redirect() dentro do header() com debug ligado): a 2ª chamada sai sem redirecionar.
        retorno::before_http_headers($hook);
        retorno::reset_diverting();
    }

    public function test_sesskey_ausente_ou_errada_nao_confirma_e_nao_lanca_erro(): void {
        $this->assertFalse(retorno::sesskey_ok(''));
        $this->assertFalse(retorno::sesskey_ok('errada'));
        $this->assertTrue(retorno::sesskey_ok(sesskey()));
    }

    public function test_o_hook_sem_pendente_sai_sem_fazer_nada(): void {
        global $PAGE;
        retorno::reset_diverting();

        retorno::before_http_headers(new before_http_headers($PAGE->get_renderer('core')));

        $this->assertNull(retorno::pending());
    }

    // Retorno pendente.

    public function test_envio_online_do_mesmo_usuario_na_prova_do_link_grava_o_pendente(): void {
        $this->marcar();

        retorno::on_attempt_submitted($this->evento());

        $pendente = retorno::pending();
        $this->assertSame($this->cmid(), $pendente->cmid);
        $this->assertSame((int) $this->tentativa->get_attemptid(), $pendente->attemptid);
        $this->assertSame((int) $this->aluno->id, $pendente->userid);
        $this->assertGreaterThan(0, $pendente->time);
    }

    public function test_sem_marcacao_nao_grava_o_pendente(): void {
        retorno::on_attempt_submitted($this->evento());

        $this->assertNull(retorno::pending());
    }

    public function test_aluno_offline_nao_grava_o_pendente(): void {
        $this->marcar();

        retorno::on_attempt_submitted($this->evento(false));

        $this->assertNull(retorno::pending());
    }

    public function test_quem_enviou_nao_e_o_dono_nao_grava_o_pendente(): void {
        $this->marcar();
        $professor = $this->getDataGenerator()->create_user();

        retorno::on_attempt_submitted($this->evento(true, (int) $professor->id));

        $this->assertNull(retorno::pending());
    }

    public function test_tentativa_de_outro_usuario_nao_grava_o_pendente(): void {
        $this->marcar();
        $outro = $this->getDataGenerator()->create_user();

        retorno::on_attempt_submitted($this->evento(true, (int) $outro->id, (int) $outro->id));

        $this->assertNull(retorno::pending());
    }

    public function test_outra_prova_nao_grava_o_pendente(): void {
        $this->marcar($this->cmid() + 1000);

        retorno::on_attempt_submitted($this->evento());

        $this->assertNull(retorno::pending());
    }

    public function test_sem_url_configurada_nao_grava_o_pendente(): void {
        $this->marcar();
        set_config('retorno_url', '', 'local_faicrm');

        retorno::on_attempt_submitted($this->evento());

        $this->assertNull(retorno::pending());
    }

    public function test_url_invalida_nao_grava_o_pendente(): void {
        $this->marcar();
        set_config('retorno_url', 'javascript:alert(1)', 'local_faicrm');

        retorno::on_attempt_submitted($this->evento());

        $this->assertNull(retorno::pending());
    }

    public function test_acessar_como_o_candidato_nao_grava_o_pendente(): void {
        $this->setAdminUser();
        \core\session\manager::loginas((int) $this->aluno->id, \context_system::instance());
        $this->marcar();

        retorno::on_attempt_submitted($this->evento());

        $this->assertNull(retorno::pending());
    }

    // Desvio.

    /**
     * Páginas que desviam, ou não, com um pendente na prova do cenário.
     *
     * @return array[]
     */
    public static function paginas(): array {
        return [
            'revisão da prova' => ['mod-quiz-review', true, true],
            'página inicial da prova' => ['mod-quiz-view', true, true],
            'outra página da prova' => ['mod-quiz-attempt', true, false],
            'revisão de outra prova' => ['mod-quiz-review', false, false],
            'página do curso' => ['course-view-topics', true, false],
            'sem página' => ['', true, false],
        ];
    }

    /**
     * Só a revisão e a página inicial da prova do pendente desviam.
     *
     * @dataProvider paginas
     * @param string $pagetype tipo da página
     * @param bool $mesmaprova se a página é da prova do pendente
     * @param bool $esperado se deve desviar
     */
    public function test_decisao_de_desvio_com_pendente(string $pagetype, bool $mesmaprova, bool $esperado): void {
        $this->marcar();
        $this->enviar_online();
        $this->assertNotNull(retorno::pending());
        $cmid = $mesmaprova ? $this->cmid() : $this->cmid() + 1;

        $this->assertSame($esperado, retorno::decide_divert($pagetype, $cmid, (int) $this->aluno->id));
    }

    public function test_sem_pendente_nunca_desvia(): void {
        $this->marcar();

        $this->assertFalse(retorno::decide_divert('mod-quiz-review', $this->cmid(), (int) $this->aluno->id));
        $this->assertFalse(retorno::decide_divert('mod-quiz-view', $this->cmid(), (int) $this->aluno->id));
    }

    public function test_tentativa_nao_finalizada_nao_desvia_e_descarta_o_pendente(): void {
        $this->marcar();
        retorno::on_attempt_submitted($this->evento());
        $this->assertNotNull(retorno::pending());

        $this->assertFalse(retorno::decide_divert('mod-quiz-review', $this->cmid(), (int) $this->aluno->id));

        $this->assertNull(retorno::pending());
    }

    public function test_outro_usuario_na_pagina_nao_desvia_e_descarta_o_pendente(): void {
        $this->marcar();
        $this->enviar_online();

        $this->assertFalse(retorno::decide_divert('mod-quiz-review', $this->cmid(), (int) $this->aluno->id + 1));

        $this->assertNull(retorno::pending());
    }

    public function test_url_que_ficou_invalida_nao_desvia_e_descarta_o_pendente(): void {
        $this->marcar();
        $this->enviar_online();
        set_config('retorno_url', 'javascript:alert(1)', 'local_faicrm');

        $this->assertFalse(retorno::decide_divert('mod-quiz-review', $this->cmid(), (int) $this->aluno->id));

        $this->assertNull(retorno::pending());
    }

    // Consumo único.

    public function test_o_pendente_so_pode_ser_consumido_uma_vez(): void {
        $this->marcar();
        $this->enviar_online();

        $primeiro = retorno::consume();
        $this->assertSame($this->cmid(), $primeiro->cmid);
        $this->assertNull(retorno::consume());
        $this->assertNull(retorno::pending());
        $this->assertFalse(retorno::decide_divert('mod-quiz-review', $this->cmid(), (int) $this->aluno->id));
    }

    public function test_consumir_sem_pendente_devolve_nulo(): void {
        $this->assertNull(retorno::consume());
    }

    // Integração com a tentativa de verdade.

    public function test_o_observer_esta_registrado_depois_do_commit(): void {
        global $CFG;
        $observers = [];
        require($CFG->dirroot . '/local/faicrm/db/events.php');

        $this->assertCount(1, $observers);
        $this->assertSame('\mod_quiz\event\attempt_submitted', $observers[0]['eventname']);
        $this->assertSame('\local_faicrm\sso\retorno::on_attempt_submitted', $observers[0]['callback']);
        $this->assertFalse($observers[0]['internal']);
    }

    public function test_o_evento_real_do_envio_online_traz_studentisonline_verdadeiro(): void {
        $sink = $this->redirectEvents();

        $this->tentativa->process_finish(time(), false, null, true);

        $eventos = array_filter($sink->get_events(), fn($e) => $e instanceof attempt_submitted);
        $this->assertCount(1, $eventos);
        $this->assertTrue(reset($eventos)->other['studentisonline']);
    }

    public function test_tentativa_enviada_pelo_cron_nao_grava_o_pendente(): void {
        $this->marcar();

        $this->tentativa->process_finish(time(), false, null, false);

        $this->assertNull(retorno::pending());
    }

    public function test_envio_por_cli_nunca_grava_o_pendente(): void {
        $this->marcar();

        // Em CLI (o cron) o submitterid do evento é nulo.
        $this->tentativa->process_finish(time(), false, null, true);

        $this->assertNull(retorno::pending());
    }

    public function test_enviar_sem_a_marcacao_nao_grava_o_pendente(): void {
        $this->enviar_online();

        $this->assertNull(retorno::pending());
    }

    // Tela de confirmação.

    public function test_a_mensagem_sai_escapada_e_com_quebras_de_linha(): void {
        $url = 'https://crm.example/retorno?status=concluida&cmid=22';
        $html = retorno::confirmation_html($url, "Boa sorte!
<script>alert(1)</script>");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<br', $html);
    }

    public function test_o_botao_leva_a_url_escapada_e_rel_noreferrer(): void {
        $html = retorno::confirmation_html('https://crm.example/retorno?status=concluida&cmid=22', '');

        $this->assertStringContainsString('href="https://crm.example/retorno?status=concluida&amp;cmid=22"', $html);
        $this->assertStringContainsString('rel="noreferrer"', $html);
        $this->assertStringContainsString(get_string('retorno_voltar', 'local_faicrm'), $html);
        $this->assertStringContainsString(get_string('retorno_sucesso', 'local_faicrm'), $html);
    }

    public function test_o_meta_refresh_e_de_5_segundos_com_a_url_escapada(): void {
        $meta = retorno::refresh_meta('https://crm.example/retorno?a=1&b="2"');

        $this->assertStringContainsString('http-equiv="refresh"', $meta);
        $this->assertStringContainsString('content="5;url=https://crm.example/retorno?a=1&amp;b=&quot;2&quot;"', $meta);
    }
}
