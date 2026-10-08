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
 * Configura a integração CRM (idempotente). Veja --help.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'gerar-token' => false,
    'ip' => '',
    'validade-dias' => '',
], ['h' => 'help']);

$help = <<<'EOT'
Configura a integração CRM ↔ Moodle (plugin local_faicrm). Pode ser executado quantas vezes quiser:
só cria o que estiver faltando.

O que faz:
  - liga web services, o protocolo REST e o acompanhamento de conclusão;
  - cria/atualiza o papel de sistema "integracaocrm" (só atribui Estudante);
  - cria o usuário técnico "ws_crm" (senha aleatória, nunca exibida) e atribui o papel;
  - habilita o serviço "crm_vestibular_fai" e autoriza o ws_crm nele.
Não altera a política de senha e não cria cursos.

Uso:
  php local/faicrm/cli/configurar.php [--gerar-token [--ip=IPS] [--validade-dias=N]]

Opções:
  --gerar-token        Gera um token permanente novo e o imprime UMA vez na saída (não é gravado em
                       arquivo; guarde-o em local seguro). Sem esta opção nenhum token é criado.
  --ip=IPS             Restringe a autorização do ws_crm no serviço (e o token, se gerado) a estes IPs
                       ou faixas, separados por vírgula. Ex.: --ip=200.10.20.30,10.0.0.0/24
  --validade-dias=N    O token gerado expira em N dias (1 a 3650). Só com --gerar-token. Padrão: sem
                       validade. A autorização do ws_crm no serviço fica sempre sem validade (no
                       Moodle 4.5 uma validade ali bloqueia todas as chamadas).
  -h, --help           Mostra esta ajuda.

Exemplos:
  php local/faicrm/cli/configurar.php
  php local/faicrm/cli/configurar.php --gerar-token --ip=200.10.20.30 --validade-dias=365
EOT;

if ($options['help']) {
    cli_writeln($help);
    exit(0);
}
if ($unrecognised) {
    cli_error('Opções desconhecidas: ' . implode(', ', $unrecognised) . "\nUse --help para ver as opções.");
}

$ip = trim((string) $options['ip']);
if ($ip !== '') {
    if (!\local_faicrm\setup\configurador::ip_valido($ip)) {
        cli_error('--ip inválido. Informe IPs ou faixas separados por vírgula, ex.: 200.10.20.30,10.0.0.0/24');
    }
    $ip = \local_faicrm\setup\configurador::normalizar_ip($ip);
    if (\local_faicrm\setup\configurador::ip_libera_tudo($ip)) {
        mtrace('[configurar] AVISO: --ip contém uma faixa /0, que libera qualquer origem (sem restrição real).');
    }
}
$validuntil = 0;
if ($options['validade-dias'] !== '') {
    $dias = (string) $options['validade-dias'];
    if (!ctype_digit($dias) || (int) $dias < 1 || (int) $dias > 3650) {
        cli_error('--validade-dias deve ser um número inteiro de 1 a 3650.');
    }
    if (!$options['gerar-token']) {
        cli_error('--validade-dias só tem efeito com --gerar-token (a validade é do token).');
    }
    $validuntil = time() + (int) $dias * DAYSECS;
}
if (!$options['gerar-token'] && $ip !== '') {
    mtrace('[configurar] AVISO: --ip vale para a autorização do ws_crm no serviço (todos os tokens dele); nenhum token é gerado.');
}

// Operações administrativas exigem um usuário válido na sessão.
\core\session\manager::set_user(get_admin());

$configurador = new \local_faicrm\setup\configurador(function (string $message): void {
    mtrace('[configurar] ' . $message);
});

try {
    $configurador->ligar_servicos();
    $roleid = $configurador->garantir_papel();
    $userid = $configurador->garantir_usuario($roleid);
    $service = $configurador->autorizar_servico($userid, $ip);
    if ($options['gerar-token']) {
        $token = $configurador->gerar_token($service, $userid, $ip, $validuntil);
        cli_writeln('');
        cli_writeln('TOKEN (exibido uma única vez; copie agora e guarde em local seguro):');
        cli_writeln($token);
        cli_writeln('');
        cli_writeln('Tokens gerados antes continuam válidos; revogue os que não forem mais usados em');
        cli_writeln('Administração do site > Servidor > Serviços web > Gerenciar tokens.');
    } else {
        mtrace('[configurar] nenhum token gerado (use --gerar-token para criar um)');
    }
} catch (moodle_exception $e) {
    cli_error('ERRO: ' . $e->getMessage());
}
mtrace('[configurar] concluído');
exit(0);
