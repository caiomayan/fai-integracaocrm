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
 * Adaptador JSON para o servidor REST nativo do Moodle (D-08 / RF-07).
 *
 * POST /local/faicrm/rest_json.php?wsfunction=<função>
 * Authorization: Bearer <token>, corpo JSON com os parâmetros da função.
 * Sem lógica de negócio própria: converte o corpo para $_POST e executa o servidor
 * REST nativo. Extras: roleid padrão (student) na matrícula, HTTP 204 para retorno null
 * e erros prontos para o front (RF-10): status HTTP coerente + {"message"} em português,
 * detalhes só no log do servidor, correlacionados pelo header X-Request-Id.
 *
 * As classes ficam em classes/rest_json/ (handler: respostas de erro, log e shutdown;
 * server: servidor REST nativo com send_error próprio; conflict_exception: RF-13).
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_faicrm\rest_json\handler;
use local_faicrm\rest_json\server;

// Replica webservice/rest/server.php (o adaptador roda o servidor nativo ele mesmo).
define('NO_DEBUG_DISPLAY', true);
define('WS_SERVER', true);

// phpcs:ignore moodle.Files.RequireLogin.Missing -- autenticação por token, feita pelo servidor REST nativo.
require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/webservice/rest/locallib.php');

// Buffer de saída e shutdown (RF-09/RF-10). As validações abaixo não dependem do Moodle.
handler::start();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    handler::fail(405, 'Método não permitido. Use POST.', 'methodnotallowed');
}

// A operação pode vir na query (?wsfunction=) e/ou no caminho (/rest_json.php/<função>).
$fromquery = $_GET['wsfunction'] ?? '';
$frompath = '';
$pathinfo = (string) ($_SERVER['PATH_INFO'] ?? '');
if ($pathinfo !== '' && $pathinfo !== '/') {
    // Nomes de função do Moodle: minúsculas, dígitos e _. Evita lixo no log e na consulta.
    if (!preg_match('#^/([a-z][a-z0-9_]{0,199})$#', $pathinfo, $m)) {
        handler::fail(400, 'Operação inválida: verifique o caminho da requisição.', 'invalidwsfunction');
    }
    $frompath = $m[1];
}
if (!is_string($fromquery)) {
    handler::fail(400, 'Operação inválida: verifique o parâmetro wsfunction.', 'invalidwsfunction');
}
if ($fromquery !== '' && $frompath !== '' && $fromquery !== $frompath) {
    handler::fail(
        400,
        'Operação inválida: informe wsfunction só no caminho ou só na query.',
        'conflictingwsfunction'
    );
}
$wsfunction = $frompath !== '' ? $frompath : $fromquery;
if ($wsfunction === '') {
    handler::fail(400, 'Informe a operação desejada (parâmetro wsfunction).', 'missingwsfunction');
}
if (!preg_match('/^[a-z][a-z0-9_]{0,199}$/', $wsfunction)) {
    handler::fail(400, 'Operação inválida: verifique o parâmetro wsfunction.', 'invalidwsfunction');
}
handler::$wsfunction = $wsfunction;

$toolarge = 'O corpo da requisição é grande demais (máximo de 1 MB).';
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > handler::MAXBODY) {
    handler::fail(413, $toolarge, 'payloadtoolarge');
}
$raw = file_get_contents('php://input', false, null, 0, handler::MAXBODY + 1);
if (is_string($raw) && strlen($raw) > handler::MAXBODY) {
    handler::fail(413, $toolarge, 'payloadtoolarge');
}
if (is_string($raw) && strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
    $raw = substr($raw, 3);
}
if ($raw === false || trim($raw) === '') {
    $body = [];
} else {
    // A raiz precisa ser um objeto JSON ({} inclusive): rejeita listas (até []), escalares, null e JSON inválido
    // (A-02). Decodificado como objeto, [] e {} se distinguem; depois, como array associativo para o $_POST.
    // Profundidade 32 sobra para as estruturas das funções do serviço.
    if (!(json_decode($raw, false, 32) instanceof stdClass)) {
        handler::fail(400, 'O corpo da requisição deve ser um objeto JSON válido.', 'invalidjson');
    }
    $body = (array) json_decode($raw, true, 32);
}
unset($raw);
handler::collect_passwords($body);

foreach (['wstoken', 'wsfunction', 'moodlewsrestformat'] as $reserved) {
    unset($body[$reserved]);
}

// Token via Authorization: Bearer (mod_php pode não expor em $_SERVER).
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '') {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) {
            $authorization = $value;
            break;
        }
    }
}
$token = '';
if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $matches)) {
    $token = $matches[1];
    handler::$secrets[] = $token;
}
unset($authorization, $headers, $matches);

$_POST = $body;
if ($token !== '') {
    $_POST['wstoken'] = $token;
}
$_POST['wsfunction'] = $wsfunction;
$_POST['moodlewsrestformat'] = 'json';
$_GET = ['wsfunction' => $wsfunction, 'moodlewsrestformat' => 'json'];
$_REQUEST = array_merge($_GET, $_POST);
unset($body, $token);

if (!webservice_protocol_is_enabled('rest')) {
    handler::fail(503, handler::MSG_503, 'protocoldisabled');
}

// RF-08: matrícula sem roleid usa o papel de shortname 'student'.
if ($wsfunction === 'enrol_manual_enrol_users' && isset($_POST['enrolments']) && is_array($_POST['enrolments'])) {
    $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
    if ($studentroleid) {
        foreach ($_POST['enrolments'] as $i => $enrolment) {
            if (is_array($enrolment) && !isset($enrolment['roleid'])) {
                $_POST['enrolments'][$i]['roleid'] = (int) $studentroleid;
            }
        }
    }
}

$server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
$server->run();
die;

/**
 * Raises Early WS Exception (chamada pelo lib/setup.php quando WS_SERVER está definido).
 *
 * Falhas no setup do Moodle (ex.: banco fora do ar) também saem no formato do RF-10.
 *
 * @param Exception $ex Raised exception.
 */
function raise_early_ws_exception(Exception $ex): void {
    if (handler::$requestid === '') {
        // A falha aconteceu dentro do config.php, antes do handler::start().
        handler::start();
    }
    [$status, $message] = handler::map($ex, false);
    handler::fail($status, $message, (string) ($ex->errorcode ?? ''), $ex);
}
