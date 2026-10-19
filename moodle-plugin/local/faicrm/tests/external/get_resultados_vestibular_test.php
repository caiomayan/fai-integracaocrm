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

/**
 * Tests for the essay and objective scores of the results of the vestibular (spec 008, CA-43).
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_faicrm\external\get_resultados_vestibular
 */
final class get_resultados_vestibular_test extends \advanced_testcase {
    /** @var \stdClass curso */
    private $curso;

    /**
     * Cenário: um curso e o administrador como quem consulta.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $this->curso = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
    }

    /**
     * Cria uma prova com a nota máxima dada e as questões na ordem dada. As questões são criadas na ordem inversa,
     * então a da posição 1 tem o MAIOR id do banco.
     *
     * @param array $slots lista de [tipo (essay|truefalse|description), nota máxima da questão]
     * @param int $grade nota máxima da prova
     * @param int $metodo método de avaliação (QUIZ_GRADEHIGHEST etc.)
     * @param \stdClass|null $curso curso (null = o do cenário)
     * @return \stdClass a prova (com cmid)
     */
    private function prova(array $slots, int $grade = 1000, int $metodo = QUIZ_GRADEHIGHEST, ?\stdClass $curso = null): \stdClass {
        $gen = $this->getDataGenerator();
        $curso = $curso ?? $this->curso;
        $prova = $gen->create_module('quiz', [
            'course' => $curso->id, 'questionsperpage' => 0, 'grade' => $grade, 'sumgrades' => 0,
            'attempts' => 0, 'grademethod' => $metodo,
        ]);
        $qgen = $gen->get_plugin_generator('core_question');
        $categoria = $qgen->create_question_category();
        $perguntas = [];
        foreach (array_reverse(array_keys($slots)) as $i) {
            $perguntas[$i] = $qgen->create_question($slots[$i][0], null, ['category' => $categoria->id]);
        }
        ksort($perguntas);
        foreach ($perguntas as $i => $pergunta) {
            quiz_add_quiz_question($pergunta->id, $prova, 0, $slots[$i][1]);
        }
        \mod_quiz\quiz_settings::create($prova->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        return $prova;
    }

    /**
     * A prova padrão: redação (essay, 200) na posição 1 e quatro objetivas de 200, nota máxima 1000.
     *
     * @param int $metodo método de avaliação
     * @return \stdClass
     */
    private function prova_padrao(int $metodo = QUIZ_GRADEHIGHEST): \stdClass {
        return $this->prova(
            [['essay', 200], ['truefalse', 200], ['truefalse', 200], ['truefalse', 200], ['truefalse', 200]],
            1000,
            $metodo
        );
    }

    /**
     * Cria um candidato matriculado como estudante.
     *
     * @param string $username usuário
     * @param \stdClass|null $curso curso (null = o do cenário)
     * @return \stdClass
     */
    private function candidato(string $username, ?\stdClass $curso = null): \stdClass {
        $usuario = $this->getDataGenerator()->create_user(['username' => $username]);
        $this->getDataGenerator()->enrol_user($usuario->id, ($curso ?? $this->curso)->id, 'student');
        return $usuario;
    }

    /**
     * Faz e envia uma tentativa de verdade.
     *
     * @param \stdClass $prova prova
     * @param \stdClass $usuario candidato
     * @param array $respostas slot => resposta ('1' certo e '0' errado nas objetivas; texto ou null na redação)
     * @param int $numero número da tentativa
     * @param \stdClass|null $anterior registro da tentativa anterior
     * @return \mod_quiz\quiz_attempt a tentativa finalizada
     */
    private function tentar(
        \stdClass $prova,
        \stdClass $usuario,
        array $respostas,
        int $numero = 1,
        ?\stdClass $anterior = null
    ): \mod_quiz\quiz_attempt {
        $quizobj = \mod_quiz\quiz_settings::create($prova->id, $usuario->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $agora = time() + $numero;
        $tentativa = quiz_create_attempt($quizobj, $numero, $anterior ?: false, $agora, false, $usuario->id);
        quiz_start_new_attempt($quizobj, $quba, $tentativa, $numero, $agora);
        quiz_attempt_save_started($quizobj, $quba, $tentativa);
        $obj = \mod_quiz\quiz_attempt::create($tentativa->id);
        foreach ($respostas as $slot => $resposta) {
            if ($resposta === null) {
                continue;
            }
            $dados = $obj->get_question_type_name($slot) === 'essay'
                ? ['answer' => $resposta, 'answerformat' => FORMAT_HTML] : ['answer' => $resposta];
            $obj->get_question_usage()->process_action($slot, $dados);
        }
        $obj->process_finish($agora + 1, false, null, true);
        return \mod_quiz\quiz_attempt::create($tentativa->id);
    }

    /**
     * Corrige a redação (ou outra questão) à mão e recalcula a nota da prova.
     *
     * @param \mod_quiz\quiz_attempt $tentativa tentativa
     * @param int $slot posição da questão
     * @param float $nota nota na escala da questão (0 a 200 na prova padrão)
     */
    private function corrigir(\mod_quiz\quiz_attempt $tentativa, int $slot, float $nota): void {
        global $DB;
        $quba = \question_engine::load_questions_usage_by_activity($tentativa->get_uniqueid());
        $quba->manual_grade($slot, 'ok', $nota, FORMAT_HTML);
        \question_engine::save_questions_usage_by_activity($quba);
        $DB->set_field('quiz_attempts', 'sumgrades', $quba->get_total_mark(), ['id' => $tentativa->get_attemptid()]);
        $quizobj = \mod_quiz\quiz_settings::create($tentativa->get_quizid(), $tentativa->get_userid());
        $quizobj->get_grade_calculator()->recompute_final_grade($tentativa->get_userid());
    }

    /**
     * Respostas das objetivas da prova padrão (slots 2 a 5).
     *
     * @param int $certas quantas acertar
     * @param string|null $redacao texto da redação (null = em branco)
     * @return array
     */
    private function respostas(int $certas, ?string $redacao = 'Meu texto'): array {
        $respostas = [1 => $redacao];
        for ($slot = 2; $slot <= 5; $slot++) {
            $respostas[$slot] = ($slot - 1) <= $certas ? '1' : '0';
        }
        return $respostas;
    }

    /**
     * Chama a operação 4.5 e devolve os candidatos por username.
     *
     * @param \stdClass|null $curso curso
     * @return array
     */
    private function resultados(?\stdClass $curso = null): array {
        $pagina = get_resultados_vestibular::execute(($curso ?? $this->curso)->id);
        $porusuario = [];
        foreach ($pagina['candidatos'] as $candidato) {
            $porusuario[$candidato['username']] = $candidato;
        }
        return $porusuario;
    }

    /**
     * Confere as duas notas de um candidato.
     *
     * @param float|null $redacao esperada
     * @param float|null $objetivas esperada
     * @param array $candidato candidato do resultado
     */
    private function assert_notas(?float $redacao, ?float $objetivas, array $candidato): void {
        $this->assertArrayHasKey('nota_redacao', $candidato);
        $this->assertArrayHasKey('nota_objetivas', $candidato);
        foreach ([['nota_redacao', $redacao], ['nota_objetivas', $objetivas]] as [$campo, $esperado]) {
            if ($esperado === null) {
                $this->assertNull($candidato[$campo], $campo);
            } else {
                $this->assertIsFloat($candidato[$campo], $campo);
                $this->assertEqualsWithDelta($esperado, $candidato[$campo], 0.001, $campo);
            }
        }
    }

    public function test_os_campos_novos_estao_descritos_como_numero_ou_nulo(): void {
        $estrutura = get_resultados_vestibular::execute_returns()->keys['candidatos']->content->keys;

        foreach (['nota_redacao', 'nota_objetivas'] as $campo) {
            $this->assertArrayHasKey($campo, $estrutura);
            $this->assertSame(PARAM_FLOAT, $estrutura[$campo]->type);
            $this->assertSame(NULL_ALLOWED, $estrutura[$campo]->allownull);
            $this->assertNotSame('', $estrutura[$campo]->desc);
        }
    }

    public function test_aprovacao_redacao_e_objetivas_maiores_que_zero_e_somam_a_nota(): void {
        $prova = $this->prova_padrao();
        $candidato = $this->candidato('11111111111');
        $tentativa = $this->tentar($prova, $candidato, $this->respostas(3));
        $this->corrigir($tentativa, 1, 150);

        $resultado = $this->resultados()['11111111111'];

        $this->assert_notas(150.0, 600.0, $resultado);
        $this->assertEqualsWithDelta($resultado['nota'], $resultado['nota_redacao'] + $resultado['nota_objetivas'], 0.001);
        $this->assertEqualsWithDelta(750.0, $resultado['nota'], 0.001);
    }

    public function test_redacao_zerada_corrigida_e_zero_e_nao_nulo(): void {
        $prova = $this->prova_padrao();
        $candidato = $this->candidato('22222222222');
        $this->corrigir($this->tentar($prova, $candidato, $this->respostas(2)), 1, 0);

        $this->assert_notas(0.0, 400.0, $this->resultados()['22222222222']);
    }

    public function test_objetivas_zeradas_e_zero_e_nao_nulo(): void {
        $prova = $this->prova_padrao();
        $candidato = $this->candidato('33333333333');
        $this->corrigir($this->tentar($prova, $candidato, $this->respostas(0)), 1, 100);

        $this->assert_notas(100.0, 0.0, $this->resultados()['33333333333']);
    }

    public function test_correcao_pendente_devolve_nulo_nos_dois(): void {
        $prova = $this->prova_padrao();
        $candidato = $this->candidato('44444444444');
        $this->tentar($prova, $candidato, $this->respostas(4));

        $this->assert_notas(null, null, $this->resultados()['44444444444']);
    }

    public function test_sem_tentativa_devolve_nulo_nos_dois(): void {
        $this->prova_padrao();
        $this->candidato('55555555555');

        $this->assert_notas(null, null, $this->resultados()['55555555555']);
    }

    public function test_tentativa_em_andamento_nao_conta(): void {
        $prova = $this->prova_padrao();
        $candidato = $this->candidato('55555555556');
        $quizobj = \mod_quiz\quiz_settings::create($prova->id, $candidato->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $tentativa = quiz_create_attempt($quizobj, 1, false, time(), false, $candidato->id);
        quiz_start_new_attempt($quizobj, $quba, $tentativa, 1, time());
        quiz_attempt_save_started($quizobj, $quba, $tentativa);

        $this->assert_notas(null, null, $this->resultados()['55555555556']);
    }

    public function test_redacao_em_branco_vale_zero_e_nao_fica_pendente(): void {
        $prova = $this->prova_padrao();
        $candidato = $this->candidato('66666666666');
        $this->tentar($prova, $candidato, $this->respostas(2, null));

        $this->assert_notas(0.0, 400.0, $this->resultados()['66666666666']);
    }

    public function test_escala_diferente_da_soma_das_questoes(): void {
        // Questões somam 10 (redação 2 + quatro objetivas de 2), nota máxima da prova 1000: fator 100.
        $prova = $this->prova([['essay', 2], ['truefalse', 2], ['truefalse', 2], ['truefalse', 2], ['truefalse', 2]], 1000);
        $candidato = $this->candidato('77777777777');
        $tentativa = $this->tentar($prova, $candidato, $this->respostas(3));
        $this->corrigir($tentativa, 1, 1.5);

        $resultado = $this->resultados()['77777777777'];

        $this->assert_notas(150.0, 600.0, $resultado);
        $this->assertEqualsWithDelta(750.0, $resultado['nota'], 0.001);
    }

    public function test_a_posicao_1_nao_e_o_id_1_do_banco_de_questoes(): void {
        global $DB;
        $prova = $this->prova_padrao();
        $candidato = $this->candidato('88888888888');
        $this->corrigir($this->tentar($prova, $candidato, $this->respostas(1)), 1, 120);

        // A redação é a questão do slot 1 e foi criada por último (o maior id).
        $ids = $DB->get_records_sql(
            "SELECT qs.slot, qv.questionid
               FROM {quiz_slots} qs
               JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
               JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid
              WHERE qs.quizid = :quiz",
            ['quiz' => $prova->id]
        );
        $this->assertSame(max(array_column($ids, 'questionid')), $ids[1]->questionid);
        $this->assert_notas(120.0, 200.0, $this->resultados()['88888888888']);
    }

    public function test_descricao_no_inicio_nao_conta_como_posicao_1(): void {
        $prova = $this->prova([['description', 0], ['essay', 200], ['truefalse', 200], ['truefalse', 200],
            ['truefalse', 200], ['truefalse', 200]]);
        $candidato = $this->candidato('88888888889');
        // Slots: 1 descrição, 2 redação, 3 a 6 objetivas.
        $tentativa = $this->tentar($prova, $candidato, [2 => 'texto', 3 => '1', 4 => '1', 5 => '0', 6 => '0']);
        $this->corrigir($tentativa, 2, 90);

        $this->assert_notas(90.0, 400.0, $this->resultados()['88888888889']);
    }

    /**
     * Três tentativas numa recaptação: (redação, objetivas) = (100, 200), (150, 600) e (50, 200).
     *
     * @param int $metodo método de avaliação
     * @param string $username usuário
     */
    private function recaptacao(int $metodo, string $username): void {
        $prova = $this->prova_padrao($metodo);
        $candidato = $this->candidato($username);
        $a1 = $this->tentar($prova, $candidato, $this->respostas(1), 1);
        $this->corrigir($a1, 1, 100);
        $a2 = $this->tentar($prova, $candidato, $this->respostas(3), 2, $a1->get_attempt());
        $this->corrigir($a2, 1, 150);
        $a3 = $this->tentar($prova, $candidato, $this->respostas(1), 3, $a2->get_attempt());
        $this->corrigir($a3, 1, 50);
    }

    public function test_metodo_maior_nota_usa_a_tentativa_de_maior_nota(): void {
        $this->recaptacao(QUIZ_GRADEHIGHEST, '99999999991');

        $this->assert_notas(150.0, 600.0, $this->resultados()['99999999991']);
    }

    public function test_metodo_primeira_tentativa(): void {
        $this->recaptacao(QUIZ_ATTEMPTFIRST, '99999999992');

        $this->assert_notas(100.0, 200.0, $this->resultados()['99999999992']);
    }

    public function test_metodo_ultima_tentativa(): void {
        $this->recaptacao(QUIZ_ATTEMPTLAST, '99999999993');

        $this->assert_notas(50.0, 200.0, $this->resultados()['99999999993']);
    }

    public function test_metodo_media_tira_a_media_de_cada_parte(): void {
        $this->recaptacao(QUIZ_GRADEAVERAGE, '99999999994');

        $resultado = $this->resultados()['99999999994'];

        $this->assert_notas(100.0, 333.33, $resultado);
        $this->assertEqualsWithDelta($resultado['nota'], $resultado['nota_redacao'] + $resultado['nota_objetivas'], 0.01);
    }

    public function test_maior_nota_com_empate_fica_com_a_primeira(): void {
        $prova = $this->prova_padrao(QUIZ_GRADEHIGHEST);
        $candidato = $this->candidato('99999999995');
        // Totais iguais (400) com divisões diferentes: (200, 200) na primeira e (0, 400) na segunda.
        $a1 = $this->tentar($prova, $candidato, $this->respostas(1), 1);
        $this->corrigir($a1, 1, 200);
        $a2 = $this->tentar($prova, $candidato, $this->respostas(2), 2, $a1->get_attempt());
        $this->corrigir($a2, 1, 0);

        $this->assert_notas(200.0, 200.0, $this->resultados()['99999999995']);
    }

    public function test_pendencia_em_qualquer_tentativa_finalizada_anula_mesmo_no_metodo_primeira(): void {
        $prova = $this->prova_padrao(QUIZ_ATTEMPTFIRST);
        $candidato = $this->candidato('99999999996');
        $a1 = $this->tentar($prova, $candidato, $this->respostas(3), 1);
        $this->corrigir($a1, 1, 100);
        $this->tentar($prova, $candidato, $this->respostas(4), 2, $a1->get_attempt());

        $this->assert_notas(null, null, $this->resultados()['99999999996']);
    }

    public function test_duas_provas_com_tentativa_finalizada_devolve_nulo(): void {
        $prova1 = $this->prova_padrao();
        $prova2 = $this->prova_padrao();
        $candidato = $this->candidato('99999999997');
        $this->corrigir($this->tentar($prova1, $candidato, $this->respostas(3)), 1, 100);
        $this->corrigir($this->tentar($prova2, $candidato, $this->respostas(3)), 1, 100);

        $this->assert_notas(null, null, $this->resultados()['99999999997']);
    }

    public function test_outra_prova_sem_tentativa_nao_atrapalha(): void {
        $prova1 = $this->prova_padrao();
        $this->prova_padrao();
        $candidato = $this->candidato('99999999998');
        $this->corrigir($this->tentar($prova1, $candidato, $this->respostas(3)), 1, 100);

        $this->assert_notas(100.0, 600.0, $this->resultados()['99999999998']);
    }

    public function test_os_campos_antigos_continuam_iguais(): void {
        $prova = $this->prova_padrao();
        $candidato = $this->candidato('12121212121');
        $this->corrigir($this->tentar($prova, $candidato, $this->respostas(3)), 1, 150);

        $resultado = $this->resultados()['12121212121'];

        foreach (
            ['username', 'firstname', 'lastname', 'email', 'courseid', 'nota', 'concluido', 'datamatricula', 'dataprova',
            'dataconclusao', 'tentativas', 'podefazerprova'] as $campo
        ) {
            $this->assertArrayHasKey($campo, $resultado);
        }
        $this->assertSame(1, $resultado['tentativas']);
    }

    public function test_o_numero_de_consultas_nao_cresce_com_o_numero_de_candidatos(): void {
        global $DB;
        $curso1 = $this->getDataGenerator()->create_course();
        $curso5 = $this->getDataGenerator()->create_course();
        $prova1 = $this->prova(
            [['essay', 200], ['truefalse', 200], ['truefalse', 200], ['truefalse', 200], ['truefalse', 200]],
            1000,
            QUIZ_GRADEHIGHEST,
            $curso1
        );
        $prova5 = $this->prova(
            [['essay', 200], ['truefalse', 200], ['truefalse', 200], ['truefalse', 200], ['truefalse', 200]],
            1000,
            QUIZ_GRADEHIGHEST,
            $curso5
        );
        $this->corrigir($this->tentar($prova1, $this->candidato('13000000001', $curso1), $this->respostas(3)), 1, 100);
        for ($i = 1; $i <= 5; $i++) {
            $usuario = $this->candidato('1300000001' . $i, $curso5);
            $this->corrigir($this->tentar($prova5, $usuario, $this->respostas($i % 5)), 1, 20 * $i);
        }

        // Aquece caches (contexto, capacidades, curso) para medir só o que depende do número de candidatos.
        get_resultados_vestibular::execute($curso1->id);
        get_resultados_vestibular::execute($curso5->id);

        $antes = $DB->perf_get_reads();
        get_resultados_vestibular::execute($curso1->id);
        $umcandidato = $DB->perf_get_reads() - $antes;
        $antes = $DB->perf_get_reads();
        $pagina = get_resultados_vestibular::execute($curso5->id);
        $cinco = $DB->perf_get_reads() - $antes;

        $this->assertCount(5, $pagina['candidatos']);
        $this->assertSame($umcandidato, $cinco, "consultas com 1 candidato: $umcandidato, com 5: $cinco");
    }

    // Filtro opcional por CPF (spec 009, CA-47).

    /**
     * Chama a operação 4.5 com o filtro de username.
     *
     * @param string $username filtro
     * @param string $de dataprovade
     * @param string $ate dataprovaate
     * @return array a página
     */
    private function filtrar(string $username, string $de = '', string $ate = ''): array {
        return get_resultados_vestibular::execute($this->curso->id, 1, $de, $ate, $username);
    }

    /**
     * Cenário do filtro: um candidato com nota e outro com correção pendente.
     */
    private function cenario_do_filtro(): void {
        $prova = $this->prova_padrao();
        $this->corrigir($this->tentar($prova, $this->candidato('10100000001'), $this->respostas(3)), 1, 150);
        $this->tentar($prova, $this->candidato('10100000002'), $this->respostas(2));
    }

    public function test_o_parametro_username_e_opcional_e_vazio_por_padrao(): void {
        $parametros = get_resultados_vestibular::execute_parameters()->keys;

        $this->assertArrayHasKey('username', $parametros);
        $this->assertSame(PARAM_RAW, $parametros['username']->type);
        $this->assertSame(VALUE_DEFAULT, $parametros['username']->required);
        $this->assertSame('', $parametros['username']->default);
    }

    public function test_filtro_devolve_so_o_candidato_com_os_mesmos_valores_da_lista(): void {
        $this->cenario_do_filtro();
        $completa = $this->resultados()['10100000001'];

        $pagina = $this->filtrar('10100000001');

        $this->assertSame(1, $pagina['total']);
        $this->assertSame(1, $pagina['totalpaginas']);
        $this->assertCount(1, $pagina['candidatos']);
        $this->assertEquals($completa, $pagina['candidatos'][0]);
        $this->assertEqualsWithDelta(150.0, $pagina['candidatos'][0]['nota_redacao'], 0.001);
        $this->assertEqualsWithDelta(600.0, $pagina['candidatos'][0]['nota_objetivas'], 0.001);
    }

    public function test_filtro_com_candidato_pendente_devolve_os_nulos(): void {
        $this->cenario_do_filtro();

        $pagina = $this->filtrar('10100000002');

        $this->assertSame(1, $pagina['total']);
        $this->assert_notas(null, null, $pagina['candidatos'][0]);
    }

    public function test_maiusculas_e_espacos_nas_pontas_nao_atrapalham(): void {
        $this->getDataGenerator()->enrol_user(
            $this->getDataGenerator()->create_user(['username' => 'abc12345678'])->id,
            $this->curso->id,
            'student'
        );

        foreach (['ABC12345678', '  abc12345678  ', " Abc12345678	"] as $valor) {
            $this->assertSame(1, $this->filtrar($valor)['total'], $valor);
        }
    }

    public function test_quem_nao_e_estudante_do_curso_devolve_vazio_sem_erro(): void {
        $gen = $this->getDataGenerator();
        $this->cenario_do_filtro();
        $professor = $gen->create_user(['username' => '20200000001']);
        $gen->enrol_user($professor->id, $this->curso->id, 'editingteacher');
        $outrocurso = $gen->create_course();
        $gen->enrol_user($gen->create_user(['username' => '20200000002'])->id, $outrocurso->id, 'student');
        $excluido = $this->candidato('20200000003');
        delete_user($excluido);

        foreach (['20200000001', '20200000002', '99999999999', '20200000003'] as $username) {
            $pagina = $this->filtrar($username);

            $this->assertSame(0, $pagina['total'], $username);
            $this->assertSame(0, $pagina['totalpaginas'], $username);
            $this->assertSame([], $pagina['candidatos'], $username);
        }
    }

    public function test_nao_e_busca_parcial_nem_aceita_curinga(): void {
        $this->cenario_do_filtro();

        foreach (['1010000000', '101', '%', '_', '1010000000_', '10100000%', '%1', '1010000000%'] as $valor) {
            $this->assertSame(0, $this->filtrar($valor)['total'], $valor);
        }
    }

    public function test_combina_com_o_periodo_pelo_e(): void {
        $this->cenario_do_filtro();
        $hoje = date('Y-m-d');
        $amanha = date('Y-m-d', time() + 86400);
        $ontem = date('Y-m-d', time() - 86400);

        $this->assertSame(1, $this->filtrar('10100000001', $ontem, $amanha)['total']);
        $this->assertSame(1, $this->filtrar('10100000001', $hoje, $hoje)['total']);
        $this->assertSame(0, $this->filtrar('10100000001', $amanha)['total']);
        $this->assertSame(0, $this->filtrar('10100000001', '', $ontem)['total']);
    }

    public function test_vazio_ou_ausente_devolve_a_lista_completa_como_antes(): void {
        $this->cenario_do_filtro();

        $ausente = get_resultados_vestibular::execute($this->curso->id);
        $vazio = $this->filtrar('');
        $espacos = $this->filtrar('   ');

        $this->assertSame(2, $ausente['total']);
        $this->assertEquals($ausente, $vazio);
        $this->assertEquals($ausente, $espacos);
    }

    public function test_username_com_mais_de_100_caracteres_e_erro_de_parametro(): void {
        $this->expectException(\core\exception\invalid_parameter_exception::class);
        $this->expectExceptionMessage('username length');

        $this->filtrar(str_repeat('1', 101));
    }

    public function test_username_com_exatamente_100_caracteres_e_aceito(): void {
        $this->assertSame(0, $this->filtrar(str_repeat('1', 100))['total']);
    }

    public function test_o_filtro_so_olha_contas_locais(): void {
        global $DB;
        $candidato = $this->candidato('30300000001');
        $DB->set_field('user', 'mnethostid', 99, ['id' => $candidato->id]);

        $this->assertSame(0, $this->filtrar('30300000001')['total']);
    }
}
