<?php
// Simula um candidato fazendo a "Prova Vestibular 2027.1" (curso VEST20271) com N acertos (spec 002, CA-05).
//
// Uso (dentro do container):
//   php /opt/fai/simular_prova.php --username=12345678900 --acertos=4
//   docker compose exec -u www-data moodle php /opt/fai/simular_prova.php --username=12345678900 --acertos=4
//
// Cria e envia uma tentativa REAL do questionário (mesmo fluxo do aluno: iniciar → responder → enviar),
// atualiza o livro de notas e roda imediatamente a agregação de conclusão do curso (sem esperar o cron).

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/querylib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/lib/phpunit/classes/util.php'); // Data generators (mod_quiz).

const FAI_COURSE    = 'VEST20271';
const FAI_QUIZ_NAME = 'Prova Vestibular 2027.1';

[$options, $unrecognised] = cli_get_params(
    ['username' => '', 'acertos' => null, 'help' => false],
    ['u' => 'username', 'a' => 'acertos', 'h' => 'help']
);
$usage = "Uso: php simular_prova.php --username=USUARIO --acertos=N   (N de 0 a 5)\n";
if ($unrecognised) {
    cli_error('Opções desconhecidas: ' . implode(', ', $unrecognised) . "\n" . $usage);
}
if ($options['help']) {
    echo $usage;
    exit(0);
}
$username = core_text::strtolower(trim((string) $options['username']));
if ($username === '' || $options['acertos'] === null || !preg_match('/^\d+$/', (string) $options['acertos'])) {
    cli_error($usage);
}
$acertos = (int) $options['acertos'];

function out(string $msg): void {
    mtrace('[simular_prova] ' . $msg);
}

$course = $DB->get_record('course', ['shortname' => FAI_COURSE]);
if (!$course) {
    cli_error('[simular_prova] ERRO: curso ' . FAI_COURSE . ' não existe (rode /opt/fai/setup.php).');
}
$quiz = $DB->get_record('quiz', ['course' => $course->id, 'name' => FAI_QUIZ_NAME], '*', IGNORE_MULTIPLE);
if (!$quiz) {
    cli_error('[simular_prova] ERRO: questionário "' . FAI_QUIZ_NAME . '" não existe (rode /opt/fai/setup.php).');
}
$cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
$numslots = $DB->count_records('quiz_slots', ['quizid' => $quiz->id]);
if ($acertos > $numslots) {
    cli_error("[simular_prova] ERRO: --acertos deve estar entre 0 e {$numslots}.");
}

$user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0]);
if (!$user) {
    cli_error("[simular_prova] ERRO: usuário '{$username}' não existe.");
}
$coursecontext = context_course::instance($course->id);
if (!is_enrolled($coursecontext, $user, 'mod/quiz:attempt', true)) {
    cli_error("[simular_prova] ERRO: usuário '{$username}' não está inscrito (ativo, com papel de estudante) no curso " .
        FAI_COURSE . '. Matricule-o antes (ex.: enrol_manual_enrol_users).');
}

// O ambiente local não tem MTA: sem isso o e-mail de confirmação do envio do quiz aborta o script.
$CFG->noemailever = true;

// A tentativa é feita "como" o candidato ($USER), igual ao fluxo real.
\core\session\manager::set_user($user);

$generator = phpunit_util::get_data_generator();
/** @var mod_quiz_generator $quizgen */
$quizgen = $generator->get_plugin_generator('mod_quiz');

// Simulação determinística: remove tentativas anteriores do candidato (e a conclusão do curso dele),
// para que o resultado reflita exatamente --acertos (o método de avaliação do quiz é "nota mais alta").
$previous = quiz_get_user_attempts($quiz->id, $user->id, 'all', true);
if ($previous) {
    foreach ($previous as $old) {
        quiz_delete_attempt($old, $quiz);
    }
    $DB->delete_records('course_completion_crit_compl', ['course' => $course->id, 'userid' => $user->id]);
    $DB->delete_records('course_completions', ['course' => $course->id, 'userid' => $user->id]);
    cache::make('core', 'coursecompletion')->purge();
    out(count($previous) . ' tentativa(s) anterior(es) removida(s) e conclusão do curso reiniciada');
}

// 1. Inicia a tentativa (generator do mod_quiz → quiz_prepare_and_start_new_attempt, igual a startattempt.php).
$attempt = $quizgen->create_attempt($quiz->id, $user->id);
$attemptobj = quiz_attempt::create($attempt->id);

// 2. Responde: as N primeiras questões certas, o resto errado. Monta o mesmo POST que o navegador enviaria.
// (O generator::submit_responses usa quiz_attempt::get_question_usage(), que só funciona sob PHPUnit.)
$slots = $attemptobj->get_slots();
$postdata = ['slots' => implode(',', $slots)];
foreach (array_values($slots) as $i => $slot) {
    $qa = $attemptobj->get_question_attempt($slot);
    $question = $qa->get_question();
    $correct = $question->get_correct_response();          // ['answer' => índice na ordem embaralhada]
    $choice = $correct['answer'];
    if ($i >= $acertos) {
        foreach ($question->get_order($qa) as $key => $answerid) {
            if ($question->answers[$answerid]->fraction <= 0) {
                $choice = $key;
                break;
            }
        }
    }
    $postdata[$qa->get_qt_field_name('answer')] = $choice;
    $postdata[$qa->get_control_field_name('sequencecheck')] = $qa->get_sequence_check_count();
}
$attemptobj->process_submitted_actions(time(), false, $postdata);

// 3. Envia ("Enviar tudo e terminar"): calcula a nota, atualiza o gradebook e a conclusão da atividade.
$attemptobj->process_finish(time(), false);

// Recalcula o Total do curso já (é o valor que local_faicrm_get_resultados_vestibular devolve como `nota`).
grade_regrade_final_grades($course->id);

// 4. Conclusão do curso agora (o que o cron faria em \core\task\completion_regular_task).
\core\session\manager::set_user(get_admin());
\core_completion\api::mark_course_completions_activity_criteria(['courseid' => $course->id, 'userid' => $user->id]);
$ccid = $DB->get_field('course_completions', 'id', ['course' => $course->id, 'userid' => $user->id]);
if ($ccid) {
    aggregate_completions((int) $ccid);
}
cache::make('core', 'coursecompletion')->purge();

// 5. Resultado.
$attemptrow = $DB->get_record('quiz_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
$quizgrade = $DB->get_field('quiz_grades', 'grade', ['quiz' => $quiz->id, 'userid' => $user->id]);
$coursegrade = grade_get_course_grade($user->id, $course->id);

$completioninfo = new completion_info($course);
$cmcompletion = $completioninfo->get_data(get_fast_modinfo($course, $user->id)->get_cm($cm->id), false, $user->id);
$ccompletion = new completion_completion(['userid' => $user->id, 'course' => $course->id]);

out(sprintf('usuário=%s (id=%d) tentativa=%d (nº %d) estado=%s', $username, $user->id, $attempt->id,
    $attemptrow->attempt, $attemptrow->state));
out(sprintf('acertos=%d/%d  nota do questionário=%s/%s  total do curso=%s', $acertos, $numslots,
    $quizgrade === false ? '-' : format_float($quizgrade, 2), format_float($quiz->grade, 0),
    ($coursegrade && $coursegrade->grade !== null) ? format_float($coursegrade->grade, 2) : '-'));
out(sprintf('conclusão da atividade=%s  curso concluído=%s', [
        COMPLETION_INCOMPLETE => 'incompleta', COMPLETION_COMPLETE => 'completa',
        COMPLETION_COMPLETE_PASS => 'completa (aprovado)', COMPLETION_COMPLETE_FAIL => 'completa (reprovado)',
    ][$cmcompletion->completionstate] ?? $cmcompletion->completionstate,
    $ccompletion->is_complete() ? 'sim (' . userdate($ccompletion->timecompleted, '%d/%m/%Y %H:%M') . ')' : 'não'));
exit(0);
