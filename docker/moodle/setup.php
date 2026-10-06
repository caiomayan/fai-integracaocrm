<?php
// Configuração idempotente do ambiente local da integração CRM ↔ Moodle (spec 002, D-07).
//
// Executado pelo entrypoint a cada subida (como www-data):  php /opt/fai/setup.php
// Pode ser executado manualmente quantas vezes quiser — só cria o que estiver faltando.
//
// Faz:  configs de WS/conclusão/senha · papel integracaocrm · usuário ws_crm · autorização no
//       serviço crm_vestibular_fai · token permanente · curso VEST20271 + questionário + critério
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
const FAI_SERVICE       = 'crm_vestibular_fai';
const FAI_ROLE          = 'integracaocrm';
const FAI_WSUSER        = 'ws_crm';
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
// 1. Configurações do site.
// ---------------------------------------------------------------------------------------------
$configs = [
    'enablewebservices'    => 1,
    'webserviceprotocols'  => 'rest',
    'enablecompletion'     => 1,
    'passwordpolicy'       => 0,
];
foreach ($configs as $name => $value) {
    if ((string) get_config('core', $name) !== (string) $value) {
        set_config($name, $value);
        out("config {$name}={$value} (alterado)");
    }
}
out('configs ok (enablewebservices, webserviceprotocols=rest, enablecompletion, passwordpolicy=0)');

// ---------------------------------------------------------------------------------------------
// 2. Papel de sistema integracaocrm.
// ---------------------------------------------------------------------------------------------
$capabilities = [
    'webservice/rest:use',
    'moodle/user:create',
    'moodle/user:viewdetails',
    'moodle/user:viewalldetails',
    'moodle/user:viewhiddendetails',
    'moodle/course:view',
    'moodle/course:viewhiddencourses',
    'moodle/course:viewparticipants',
    'moodle/course:enrolreview',
    'enrol/manual:enrol',
    'enrol/manual:unenrol',
    'moodle/role:assign',
    'moodle/grade:viewall',
    'gradereport/user:view',
    'moodle/course:viewhiddenactivities',
    'mod/quiz:view',
    'mod/quiz:viewreports',
    'report/completion:view',
    'moodle/site:accessallgroups',
];

$role = $DB->get_record('role', ['shortname' => FAI_ROLE]);
if (!$role) {
    $roleid = create_role('Integração CRM', FAI_ROLE,
        'Papel técnico do usuário ws_crm, usado pela integração CRM ↔ Moodle (serviço crm_vestibular_fai).');
    out('papel ' . FAI_ROLE . " criado (id={$roleid})");
} else {
    $roleid = (int) $role->id;
}
if (array_values(get_role_contextlevels($roleid)) !== [CONTEXT_SYSTEM]) {
    set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
}
$capchanged = 0;
$existingcaps = $DB->get_records_menu('role_capabilities',
    ['roleid' => $roleid, 'contextid' => $syscontext->id], '', 'capability, permission');
foreach ($capabilities as $cap) {
    if (!get_capability_info($cap)) {
        out("AVISO: capability {$cap} não existe nesta versão do Moodle — ignorada");
        continue;
    }
    if (($existingcaps[$cap] ?? null) != CAP_ALLOW) {
        assign_capability($cap, CAP_ALLOW, $roleid, $syscontext->id, true);
        $capchanged++;
    }
}
if ($capchanged) {
    $syscontext->mark_dirty();
}
$studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
if (!$DB->record_exists('role_allow_assign', ['roleid' => $roleid, 'allowassign' => $studentroleid])) {
    core_role_set_assign_allowed($roleid, $studentroleid);
    out('papel ' . FAI_ROLE . ' agora pode atribuir student');
}
out('papel ' . FAI_ROLE . " ok (id={$roleid}, capabilities alteradas={$capchanged})");

// ---------------------------------------------------------------------------------------------
// 3. Usuário técnico ws_crm + atribuição do papel no contexto de sistema.
// ---------------------------------------------------------------------------------------------
$wsuser = $DB->get_record('user', ['username' => FAI_WSUSER, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0]);
if (!$wsuser) {
    $newuser = (object) [
        'username'   => FAI_WSUSER,
        'auth'       => 'manual',
        'password'   => generate_password(24) . 'Aa1!',
        'firstname'  => 'Integração',
        'lastname'   => 'CRM',
        'email'      => 'ws_crm@localhost.local',
        'confirmed'  => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'lang'       => $CFG->lang ?? 'en',
    ];
    $userid = user_create_user($newuser, true, false);
    $wsuser = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    out(FAI_WSUSER . " criado (id={$userid})");
}
$wsuserid = (int) $wsuser->id;
if ($wsuser->suspended) {
    $DB->set_field('user', 'suspended', 0, ['id' => $wsuserid]);
    out(FAI_WSUSER . ' estava suspenso — reativado');
}
if (!user_has_role_assignment($wsuserid, $roleid, $syscontext->id)) {
    role_assign($roleid, $wsuserid, $syscontext->id);
    out('papel ' . FAI_ROLE . ' atribuído a ' . FAI_WSUSER . ' (sistema)');
}
out(FAI_WSUSER . " ok (id={$wsuserid})");

// ---------------------------------------------------------------------------------------------
// 4. Serviço (declarado pelo plugin local_faicrm), autorização e token.
// ---------------------------------------------------------------------------------------------
$service = $DB->get_record('external_services', ['shortname' => FAI_SERVICE]);
if (!$service) {
    fail('serviço "' . FAI_SERVICE . '" não encontrado em external_services. ' .
        'O plugin local_faicrm (moodle-plugin/local/faicrm, montado em /var/www/html/local/faicrm) ' .
        'está presente e instalado? Rode: php admin/cli/upgrade.php --non-interactive');
}
if (!$service->enabled) {
    $DB->set_field('external_services', 'enabled', 1, ['id' => $service->id]);
    $service->enabled = 1;
    out('serviço ' . FAI_SERVICE . ' estava desabilitado — habilitado');
}
if (!$DB->record_exists('external_services_users', ['externalserviceid' => $service->id, 'userid' => $wsuserid])) {
    $DB->insert_record('external_services_users', (object) [
        'externalserviceid' => $service->id,
        'userid'            => $wsuserid,
        'iprestriction'     => null,
        'validuntil'        => null,
        'timecreated'       => time(),
    ]);
    out(FAI_WSUSER . ' autorizado no serviço ' . FAI_SERVICE);
}

$now = time();
$tokens = $DB->get_records_select('external_tokens',
    'userid = :userid AND externalserviceid = :serviceid AND tokentype = :tokentype
     AND (validuntil = 0 OR validuntil IS NULL OR validuntil > :now)',
    ['userid' => $wsuserid, 'serviceid' => $service->id, 'tokentype' => EXTERNAL_TOKEN_PERMANENT, 'now' => $now],
    'timecreated ASC, id ASC');
if ($tokens) {
    $token = reset($tokens)->token;
    out('token permanente existente reaproveitado');
} else {
    $token = \core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $wsuserid, $syscontext, 0, '',
        'CRM Vestibular FAI (setup local)');
    out('token permanente gerado');
}
out('serviço ' . FAI_SERVICE . " ok (id={$service->id})");

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
