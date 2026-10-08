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
 * Diagnóstico da integração CRM: lista OK, FALHA ou AVISO de cada item. Veja --help.
 *
 * Só lê; não altera nada. Sai com código 1 se houver alguma FALHA.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/completionlib.php');

use local_faicrm\setup\configurador;

[$options, $unrecognised] = cli_get_params(['help' => false, 'courseid' => ''], ['h' => 'help']);

$help = <<<'EOT'
Diagnóstico da integração CRM ↔ Moodle (plugin local_faicrm). Só lê, não altera nada.

Verifica: web services e REST ligados; serviço ativo; usuário técnico (ws_crm) ativo, autorizado e
com o papel; capabilities do papel; token válido; cron recente; política de senha (aviso).
Com --courseid também verifica o curso: existe, conclusão ligada, critério de conclusão por
atividade de questionário, inscrição manual ativa e papel student.

Uso:
  php local/faicrm/cli/verificar.php [--courseid=N]

Saída: [ OK ], [FALHA] ou [AVISO] por item. O código de saída é 1 se houver alguma FALHA, 0 caso contrário.
EOT;

if ($options['help']) {
    cli_writeln($help);
    exit(0);
}
if ($unrecognised) {
    cli_error('Opções desconhecidas: ' . implode(', ', $unrecognised) . "\nUse --help para ver as opções.");
}
$courseid = 0;
if ($options['courseid'] !== '') {
    if (!ctype_digit((string) $options['courseid']) || (int) $options['courseid'] < 1) {
        cli_error('--courseid deve ser um número inteiro maior que zero.');
    }
    $courseid = (int) $options['courseid'];
}

$failures = 0;
$warnings = 0;

/**
 * Imprime o resultado de um item.
 *
 * @param string $status OK, FALHA ou AVISO
 * @param string $message descrição
 */
function faicrm_report(string $status, string $message): void {
    global $failures, $warnings;
    if ($status === 'FALHA') {
        $failures++;
    } else if ($status === 'AVISO') {
        $warnings++;
    }
    cli_writeln(sprintf('[%s] %s', str_pad($status, 5, ' ', STR_PAD_BOTH), $message));
}

/**
 * Imprime OK ou FALHA conforme a condição.
 *
 * @param bool $condition condição
 * @param string $ok mensagem quando verdadeira
 * @param string $fail mensagem quando falsa
 */
function faicrm_check(bool $condition, string $ok, string $fail): void {
    faicrm_report($condition ? 'OK' : 'FALHA', $condition ? $ok : $fail);
}

cli_writeln('Integração CRM: verificação');
cli_writeln('');

// Web services e REST.
faicrm_check(
    !empty($CFG->enablewebservices),
    'Web services ligados',
    'Web services desligados (Administração > Funcionalidades avançadas)'
);
$protocols = array_filter(array_map('trim', explode(',', (string) get_config('core', 'webserviceprotocols'))));
faicrm_check(
    in_array('rest', $protocols, true),
    'Protocolo REST ligado',
    'Protocolo REST desligado (Administração > Servidor > Serviços web > Gerenciar protocolos)'
);

// Serviço.
$service = $DB->get_record('external_services', ['shortname' => configurador::SERVICO]);
if (!$service) {
    faicrm_report('FALHA', 'Serviço "' . configurador::SERVICO . '" não existe (o plugin local_faicrm está instalado?)');
} else {
    faicrm_check(
        (bool) $service->enabled,
        'Serviço "' . configurador::SERVICO . '" ativo',
        'Serviço "' . configurador::SERVICO . '" desabilitado'
    );
}

// Usuário técnico.
$user = $DB->get_record('user', ['username' => configurador::USUARIO, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0]);
if (!$user) {
    faicrm_report('FALHA', 'Usuário técnico ' . configurador::USUARIO . ' não existe (rode cli/configurar.php)');
} else {
    $active = !$user->suspended && $user->auth !== 'nologin';
    faicrm_check(
        $active,
        'Usuário ' . configurador::USUARIO . ' ativo',
        'Usuário ' . configurador::USUARIO . ' suspenso ou sem login'
    );
    if ($service) {
        $auth = $DB->get_record('external_services_users', ['externalserviceid' => $service->id, 'userid' => $user->id]);
        if (!$auth) {
            faicrm_report('FALHA', 'Usuário ' . configurador::USUARIO . ' não está autorizado no serviço');
        } else if (!empty($auth->validuntil)) {
            // No Moodle 4.5 a validade da autorização é comparada ao contrário em load_function_info():
            // validade futura bloqueia todas as chamadas (403). A validade deve ficar só no token.
            faicrm_report('FALHA', 'Autorização do ' . configurador::USUARIO . ' no serviço tem validade (' .
                userdate($auth->validuntil) . '); remova-a (rode cli/configurar.php) e use a validade do token');
        } else {
            faicrm_report('OK', 'Usuário ' . configurador::USUARIO . ' autorizado no serviço' .
                (!empty($auth->iprestriction) ? ' (IPs: ' . $auth->iprestriction . ')' : ''));
        }
    }
}

// Papel e capabilities.
$syscontext = context_system::instance();
$role = $DB->get_record('role', ['shortname' => configurador::PAPEL]);
if (!$role) {
    faicrm_report('FALHA', 'Papel ' . configurador::PAPEL . ' não existe (rode cli/configurar.php)');
} else {
    if ($user) {
        faicrm_check(
            user_has_role_assignment($user->id, $role->id, $syscontext->id),
            'Papel ' . configurador::PAPEL . ' atribuído ao ' . configurador::USUARIO . ' (sistema)',
            'Papel ' . configurador::PAPEL . ' não está atribuído ao ' . configurador::USUARIO
        );
    }
    $caps = $DB->get_records_menu(
        'role_capabilities',
        ['roleid' => $role->id, 'contextid' => $syscontext->id],
        '',
        'capability, permission'
    );
    $missing = [];
    foreach (configurador::CAPABILITIES as $cap) {
        if (get_capability_info($cap) && ($caps[$cap] ?? null) != CAP_ALLOW) {
            $missing[] = $cap;
        }
    }
    faicrm_check(
        !$missing,
        'Capabilities do papel presentes (' . count(configurador::CAPABILITIES) . ')',
        'Capabilities ausentes no papel: ' . implode(', ', $missing)
    );
    $extra = array_diff(array_keys(array_filter($caps, function ($permission) {
        return $permission == CAP_ALLOW;
    })), configurador::CAPABILITIES);
    if ($extra) {
        faicrm_report('AVISO', 'Capabilities além das necessárias no papel (mínimo privilégio): ' . implode(', ', $extra));
    }
    $studentid = $DB->get_field('role', 'id', ['shortname' => 'student']);
    faicrm_check(
        $studentid && $DB->record_exists('role_allow_assign', ['roleid' => $role->id, 'allowassign' => $studentid]),
        'Papel pode atribuir student',
        'Papel não pode atribuir student'
    );
    $otherassign = $DB->get_fieldset_select(
        'role_allow_assign',
        'allowassign',
        'roleid = :roleid AND allowassign <> :studentid',
        ['roleid' => $role->id, 'studentid' => (int) $studentid]
    );
    if ($otherassign) {
        [$insql, $inparams] = $DB->get_in_or_equal($otherassign);
        $names = $DB->get_fieldset_select('role', 'shortname', "id $insql", $inparams);
        faicrm_report('AVISO', 'Papel também pode atribuir outros papéis além de student: ' . implode(', ', $names));
    }
}

// Token.
if ($service && $user) {
    $tokens = $DB->get_records_select(
        'external_tokens',
        'userid = :userid AND externalserviceid = :serviceid AND tokentype = :type
         AND (validuntil = 0 OR validuntil IS NULL OR validuntil > :now)',
        ['userid' => $user->id, 'serviceid' => $service->id, 'type' => EXTERNAL_TOKEN_PERMANENT, 'now' => time()]
    );
    if (!$tokens) {
        faicrm_report('FALHA', 'Nenhum token válido para o ' . configurador::USUARIO . ' (rode cli/configurar.php --gerar-token)');
    } else {
        faicrm_report('OK', count($tokens) . ' token(s) válido(s) para o ' . configurador::USUARIO);
        $unrestricted = array_filter($tokens, function ($t) {
            return empty($t->iprestriction);
        });
        // A restrição de IP da autorização do ws_crm no serviço vale para todos os tokens dele.
        $authip = !empty($auth) && !empty($auth->iprestriction);
        if ($unrestricted && !$authip) {
            faicrm_report('AVISO', count($unrestricted) . ' token(s) sem restrição de IP (recomendado restringir em produção)');
        }
    }
}

// Cron: a conclusão do curso depende da tarefa agendada de conclusão.
$task = $DB->get_record('task_scheduled', ['classname' => '\\core\\task\\completion_regular_task']);
if (!$task) {
    faicrm_report('FALHA', 'Tarefa agendada de conclusão (completion_regular_task) não encontrada');
} else if (!empty($task->disabled)) {
    faicrm_report('FALHA', 'Tarefa agendada de conclusão está desabilitada');
} else if (empty($task->lastruntime)) {
    faicrm_report('FALHA', 'Cron nunca executou a tarefa de conclusão (configure o cron do Moodle)');
} else {
    $age = time() - (int) $task->lastruntime;
    faicrm_check(
        $age <= HOURSECS,
        'Cron executado recentemente (tarefa de conclusão: ' . userdate($task->lastruntime) . ')',
        'Cron parado: a tarefa de conclusão rodou pela última vez em ' . userdate($task->lastruntime)
    );
}

// Bloqueio de conta por tentativas (o ws_crm tem auth manual; a senha é aleatória e ninguém a conhece).
if (empty($CFG->lockoutthreshold)) {
    faicrm_report('AVISO', 'Bloqueio de conta por tentativas de login desligado (recomendado em produção: ' .
        'Administração > Segurança > Política do site > Limite de bloqueio de conta)');
}

// Política de senha (informativo).
if (empty($CFG->passwordpolicy)) {
    faicrm_report('AVISO', 'Política de senha desligada (a senha enviada na criação do candidato não é validada)');
} else {
    faicrm_report('AVISO', sprintf(
        'Política de senha ligada (mín. %d caracteres, %d dígito(s), %d minúscula(s), %d maiúscula(s), %d símbolo(s)): ' .
        'a senha enviada pelo CRM deve atendê-la',
        $CFG->minpasswordlength,
        $CFG->minpassworddigits,
        $CFG->minpasswordlower,
        $CFG->minpasswordupper,
        $CFG->minpasswordnonalphanum
    ));
}

// Curso.
if ($courseid) {
    cli_writeln('');
    cli_writeln('Curso ' . $courseid);
    $course = $DB->get_record('course', ['id' => $courseid]);
    if (!$course) {
        faicrm_report('FALHA', 'Curso ' . $courseid . ' não existe');
    } else {
        faicrm_report('OK', 'Curso existe: ' . format_string($course->fullname) . ' (' . $course->shortname . ')');
        faicrm_check(
            !empty($CFG->enablecompletion) && $course->enablecompletion,
            'Conclusão de curso ligada (site e curso)',
            'Conclusão de curso desligada (' . (empty($CFG->enablecompletion) ? 'no site' : 'neste curso') . ')'
        );

        $criteria = $DB->get_records_sql(
            "SELECT cc.id, m.name AS modname
               FROM {course_completion_criteria} cc
               JOIN {course_modules} cm ON cm.id = cc.moduleinstance
               JOIN {modules} m ON m.id = cm.module
              WHERE cc.course = :course AND cc.criteriatype = :type",
            ['course' => $courseid, 'type' => COMPLETION_CRITERIA_TYPE_ACTIVITY]
        );
        $quizcriteria = array_filter($criteria, function ($c) {
            return $c->modname === 'quiz';
        });
        faicrm_check(
            (bool) $quizcriteria,
            'Critério de conclusão por atividade de questionário configurado',
            'O curso não tem critério de conclusão por atividade de questionário (Conclusão do curso > Atividade)'
        );

        $manual = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        $enabledplugins = array_filter(explode(',', (string) get_config('core', 'enrol_plugins_enabled')));
        faicrm_check(
            $manual && $manual->status == ENROL_INSTANCE_ENABLED && in_array('manual', $enabledplugins, true),
            'Inscrição manual ativa no curso',
            'Inscrição manual inexistente ou desabilitada no curso (ou plugin de inscrição manual desligado)'
        );
    }
    faicrm_check(
        $DB->record_exists('role', ['shortname' => 'student']),
        'Papel student existe',
        'Papel com shortname "student" não existe'
    );
}

cli_writeln('');
cli_writeln(sprintf('Resultado: %d falha(s), %d aviso(s)', $failures, $warnings));
exit($failures > 0 ? 1 : 0);
