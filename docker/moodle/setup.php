<?php
// Configuração idempotente do ambiente local da integração CRM ↔ Moodle (spec 002, D-07).
//
// Executado pelo entrypoint a cada subida (como www-data):  php /opt/fai/setup.php
// Pode ser executado manualmente quantas vezes quiser — só cria o que estiver faltando.
//
// Faz:  configuração da integração via \local_faicrm\setup\configurador (WS/REST/conclusão · papel
//       integracaocrm · usuário ws_crm · autorização no serviço crm_vestibular_fai) · token permanente ·
//       e o que é só de desenvolvimento: passwordpolicy=0 · curso VEST20271 + questionário + critério
//       de conclusão · escreve /opt/fai/output/token.txt e ids.json.

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/accesslib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');
require_once($CFG->dirroot . '/lib/phpunit/classes/util.php'); // Data generators (como o tool_generator faz).

const FAI_OUTPUT_DIR    = '/opt/fai/output';
const FAI_COURSE        = 'VEST20271';
const FAI_COURSE_NAME   = 'Vestibular 2027.1';
const FAI_QUIZ_NAME     = 'Prova Vestibular 2027.1';
const FAI_QUIZ_GRADE    = 1000;
const FAI_QCAT_NAME     = 'Prova Vestibular 2027.1';

function out(string $msg): void {
    mtrace('[setup] ' . $msg);
}

function fail(string $msg): void {
    cli_writeln('[setup] ERRO: ' . $msg, STDERR);
    exit(1);
}

// Operações administrativas (criar papel, curso, token...) exigem um $USER válido.
\core\session\manager::set_user(get_admin());
$syscontext = context_system::instance();

// ---------------------------------------------------------------------------------------------
// 1 a 4. Configuração da integração (papel, usuário ws_crm, serviço, autorização): classe do plugin,
//        a mesma usada por local/faicrm/cli/configurar.php em produção.
// ---------------------------------------------------------------------------------------------
$configurador = new \local_faicrm\setup\configurador('out');

// Só desenvolvimento: política de senha desligada (aceita senha = CPF numérico nos testes locais).
if ((string) get_config('core', 'passwordpolicy') !== '0') {
    set_config('passwordpolicy', 0);
    out('config passwordpolicy=0 (alterado)');
}

try {
    $configurador->ligar_servicos();
    $roleid = $configurador->garantir_papel();
    $wsuserid = $configurador->garantir_usuario($roleid);
    $service = $configurador->autorizar_servico($wsuserid);
} catch (moodle_exception $e) {
    fail($e->getMessage() . ' Rode: php admin/cli/upgrade.php --non-interactive');
}

// Token permanente: reaproveita o existente (o mesmo de output/token.txt) ou gera um.
$token = $configurador->token_existente($service, $wsuserid);
if ($token !== null) {
    out('token permanente existente reaproveitado');
} else {
    $token = $configurador->gerar_token($service, $wsuserid, '', 0, 'CRM Vestibular FAI (setup local)');
}

// ---------------------------------------------------------------------------------------------
// 5. Curso VEST20271.
// ---------------------------------------------------------------------------------------------
$course = $DB->get_record('course', ['shortname' => FAI_COURSE]);
if (!$course) {
    $course = create_course((object) [
        'fullname'         => FAI_COURSE_NAME,
        'shortname'        => FAI_COURSE,
        'category'         => core_course_category::get_default()->id,
        'format'           => 'topics',
        'numsections'      => 1,
        'enablecompletion' => 1,
        'visible'          => 1,
        'summary'          => 'Curso do processo seletivo (ambiente local da integração CRM).',
        'summaryformat'    => FORMAT_HTML,
    ]);
    out('curso ' . FAI_COURSE . " criado (id={$course->id})");
} else if (!$course->enablecompletion) {
    $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);
    rebuild_course_cache($course->id, true);
    $course->enablecompletion = 1;
    out('curso ' . FAI_COURSE . ': enablecompletion ativado');
}
$coursecontext = context_course::instance($course->id);
// Inscrição manual ativa (create_course adiciona as instâncias padrão; garante mesmo assim).
$manual = enrol_get_plugin('manual');
$manualinstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
if (!$manualinstance) {
    $manual->add_default_instance($course);
    out('instância de inscrição manual criada');
} else if ($manualinstance->status != ENROL_INSTANCE_ENABLED) {
    $manual->update_status($manualinstance, ENROL_INSTANCE_ENABLED);
    out('instância de inscrição manual reativada');
}
out('curso ' . FAI_COURSE . " ok (id={$course->id})");

// ---------------------------------------------------------------------------------------------
// 6. Questionário (grade 1000, 5 questões de múltipla escolha, conclusão ao receber nota).
// ---------------------------------------------------------------------------------------------
$generator = phpunit_util::get_data_generator();
/** @var mod_quiz_generator $quizgen */
$quizgen = $generator->get_plugin_generator('mod_quiz');
/** @var core_question_generator $qgen */
$qgen = $generator->get_plugin_generator('core_question'); // Só create_question_category().

$quiz = $DB->get_record('quiz', ['course' => $course->id, 'name' => FAI_QUIZ_NAME], '*', IGNORE_MULTIPLE);
if (!$quiz) {
    $created = $quizgen->create_instance([
        'course'             => $course->id,
        'name'               => FAI_QUIZ_NAME,
        'intro'              => 'Prova do vestibular: 5 questões de múltipla escolha, 200 pontos cada.',
        'grade'              => FAI_QUIZ_GRADE,
        'sumgrades'          => 0,
        'attempts'           => 0,
        'questionsperpage'   => 0,
        'shuffleanswers'     => 1,
        'preferredbehaviour' => 'deferredfeedback',
        'completion'         => COMPLETION_TRACKING_AUTOMATIC,
        'completionusegrade' => 1,
        'section'            => 1,
    ]);
    $quiz = $DB->get_record('quiz', ['id' => $created->id], '*', MUST_EXIST);
    out("questionário criado (id={$quiz->id})");
}
$cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
$quiz->cmid = $cm->id;

// Garante a configuração de conclusão da atividade (completion=2, completionusegrade=1 → gradeitemnumber 0).
if ($cm->completion != COMPLETION_TRACKING_AUTOMATIC || $cm->completiongradeitemnumber === null) {
    $DB->update_record('course_modules', (object) [
        'id' => $cm->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completiongradeitemnumber' => 0,
    ]);
    rebuild_course_cache($course->id, true);
    out('conclusão automática por nota ajustada no questionário');
}
if ((float) $quiz->grade != FAI_QUIZ_GRADE) {
    $DB->set_field('quiz', 'grade', FAI_QUIZ_GRADE, ['id' => $quiz->id]);
    $quiz->grade = FAI_QUIZ_GRADE;
    quiz_grade_item_update($quiz);
    out('nota máxima do questionário ajustada para ' . FAI_QUIZ_GRADE);
}

// Questões: só adiciona se o questionário ainda não tem nenhuma.
$questions = [
    ['Qual é a capital do Brasil?',
        ['Brasília', 'Rio de Janeiro', 'São Paulo', 'Salvador']],
    ['Quanto é 7 × 8?',
        ['56', '54', '64', '48']],
    ['Qual é o maior planeta do Sistema Solar?',
        ['Júpiter', 'Saturno', 'Terra', 'Marte']],
    ['Quem escreveu "Dom Casmurro"?',
        ['Machado de Assis', 'José de Alencar', 'Clarice Lispector', 'Carlos Drummond de Andrade']],
    ['Qual é a fórmula química da água?',
        ['H2O', 'CO2', 'O2', 'NaCl']],
];
$numslots = $DB->count_records('quiz_slots', ['quizid' => $quiz->id]);
if ($numslots == 0) {
    // Categoria de questões no banco do curso (reaproveitada se já existir).
    $qcat = $DB->get_record('question_categories', ['name' => FAI_QCAT_NAME, 'contextid' => $coursecontext->id],
        '*', IGNORE_MULTIPLE);
    if (!$qcat) {
        $qcat = $qgen->create_question_category([
            'name'      => FAI_QCAT_NAME,
            'contextid' => $coursecontext->id,
            'info'      => 'Questões da prova do vestibular 2027.1',
        ]);
    }
    // As questões são criadas pela API de tipos de questão (save_question), a mesma usada pelo formulário de edição.
    // (O core_question_generator::create_question depende de classes do PHPUnit, ausentes no pacote de distribuição.)
    $qtype = question_bank::get_qtype('multichoice');
    foreach ($questions as $i => [$text, $answers]) {
        $form = (object) [
            'category'                 => $qcat->id . ',' . $coursecontext->id,
            'name'                     => sprintf('Questão %d', $i + 1),
            'questiontext'             => ['text' => '<p>' . s($text) . '</p>', 'format' => FORMAT_HTML],
            'generalfeedback'          => ['text' => '<p>Resposta correta: ' . s($answers[0]) . '</p>', 'format' => FORMAT_HTML],
            'defaultmark'              => 1,
            'penalty'                  => 0.3333333,
            'single'                   => 1,
            'shuffleanswers'           => 1,
            'answernumbering'          => 'abc',
            'showstandardinstruction'  => 0,
            'shownumcorrect'           => 1,
            'correctfeedback'          => ['text' => 'Resposta correta.', 'format' => FORMAT_HTML],
            'partiallycorrectfeedback' => ['text' => 'Resposta parcialmente correta.', 'format' => FORMAT_HTML],
            'incorrectfeedback'        => ['text' => 'Resposta incorreta.', 'format' => FORMAT_HTML],
            'answer'                   => array_map(fn($a) => ['text' => $a, 'format' => FORMAT_PLAIN], $answers),
            'fraction'                 => ['1.0', '0.0', '0.0', '0.0'],
            'feedback'                 => array_fill(0, count($answers), ['text' => '', 'format' => FORMAT_HTML]),
            'status'                   => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
        ];
        $question = $qtype->save_question((object) ['qtype' => 'multichoice', 'createdby' => 0, 'idnumber' => null], $form);
        quiz_add_quiz_question($question->id, $quiz, 0, 1);
    }
    out(count($questions) . ' questões de múltipla escolha adicionadas ao questionário');
}
// Recalcula sumgrades (5) — a nota final é escalada para grade=1000 (200 por acerto).
$quizobj = \mod_quiz\quiz_settings::create($quiz->id);
$quizobj->get_grade_calculator()->recompute_quiz_sumgrades();
$quiz = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
out(sprintf('questionário ok (id=%d, cmid=%d, questões=%d, sumgrades=%s, grade=%s)',
    $quiz->id, $cm->id, $DB->count_records('quiz_slots', ['quizid' => $quiz->id]),
    format_float($quiz->sumgrades, 0), format_float($quiz->grade, 0)));

// ---------------------------------------------------------------------------------------------
// 7. Critério de conclusão do curso = conclusão do questionário.
// ---------------------------------------------------------------------------------------------
$hascriterion = $DB->record_exists('course_completion_criteria', [
    'course' => $course->id, 'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY, 'moduleinstance' => $cm->id,
]);
if (!$hascriterion) {
    $criterion = new completion_criteria_activity();
    $data = (object) ['id' => $course->id, 'criteria_activity' => [$cm->id => 1]];
    $criterion->update_config($data);
    // Agregação "todas" (padrão) explícita, como o formulário de conclusão grava.
    if (!$DB->record_exists('course_completion_aggr_methd', ['course' => $course->id, 'criteriatype' => null])) {
        $aggr = new completion_aggregation(['course' => $course->id, 'criteriatype' => null]);
        $aggr->setMethod(COMPLETION_AGGREGATION_ALL);
        $aggr->save();
    }
    cache::make('core', 'coursecompletion')->purge();
    out('critério de conclusão do curso criado (atividade cmid=' . $cm->id . ')');
}
out('critério de conclusão ok');

// ---------------------------------------------------------------------------------------------
// 8. Saída: output/token.txt e output/ids.json.
// ---------------------------------------------------------------------------------------------
if (!is_dir(FAI_OUTPUT_DIR) && !@mkdir(FAI_OUTPUT_DIR, 0777, true)) {
    fail('não foi possível criar ' . FAI_OUTPUT_DIR);
}
$ids = [
    'courseid'  => (int) $course->id,
    'quizid'    => (int) $quiz->id,
    'cmid'      => (int) $cm->id,
    'serviceid' => (int) $service->id,
];
if (@file_put_contents(FAI_OUTPUT_DIR . '/token.txt', $token . "\n") === false
        || @file_put_contents(FAI_OUTPUT_DIR . '/ids.json', json_encode($ids, JSON_PRETTY_PRINT) . "\n") === false) {
    fail('não foi possível escrever em ' . FAI_OUTPUT_DIR . ' (permissão do usuário ' . get_current_user() . '?)');
}
out('output/token.txt e output/ids.json gravados: ' . json_encode($ids));
out('concluído');
exit(0);
