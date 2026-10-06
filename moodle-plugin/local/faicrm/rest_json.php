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
 * REST nativo. Extras: roleid padrão (student) na matrícula e HTTP 204 para retorno null.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Carrega só depois das validações; o config.php só é carregado no final.

/**
 * Responde erro no formato nativo do Moodle e encerra.
 *
 * @param int $status código HTTP
 * @param string $errorcode código do erro
 * @param string $message mensagem
 */
function local_faicrm_rest_json_error(int $status, string $errorcode, string $message): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'exception' => 'moodle_exception',
        'errorcode' => $errorcode,
        'message' => $message,
    ]);
    die;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    local_faicrm_rest_json_error(405, 'methodnotallowed', 'Método não permitido: use POST.');
}

$wsfunction = $_GET['wsfunction'] ?? '';
if (!is_string($wsfunction) || $wsfunction === '') {
    local_faicrm_rest_json_error(400, 'missingwsfunction', 'Parâmetro wsfunction ausente na query string.');
}

$raw = file_get_contents('php://input');
if (is_string($raw) && strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
    $raw = substr($raw, 3);
}
if ($raw === false || trim($raw) === '') {
    $body = [];
} else {
    $body = json_decode($raw, true);
    // Precisa ser objeto (ou {}) — rejeita escalares, listas e JSON inválido.
    if (!is_array($body) || ($body !== [] && array_is_list($body))) {
        local_faicrm_rest_json_error(400, 'invalidjson', 'Corpo inválido: envie um objeto JSON.');
    }
}

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
}

$_POST = $body;
if ($token !== '') {
    $_POST['wstoken'] = $token;
}
$_POST['wsfunction'] = $wsfunction;
$_POST['moodlewsrestformat'] = 'json';
$_GET = ['wsfunction' => $wsfunction, 'moodlewsrestformat' => 'json'];
$_REQUEST = array_merge($_GET, $_POST);

// Replica webservice/rest/server.php (o adaptador roda o servidor nativo ele mesmo).
define('NO_DEBUG_DISPLAY', true);
define('WS_SERVER', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/webservice/rest/locallib.php');

if (!webservice_protocol_is_enabled('rest')) {
    header('HTTP/1.0 403 Forbidden');
    debugging('The server died because the web services or the REST protocol are not enable', DEBUG_DEVELOPER);
    die;
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

// RF-09: retorno nativo null vira 204 sem corpo. O run() termina com die; o shutdown
// roda antes de o buffer ser descarregado, então ainda dá para trocar status e headers.
ob_start();
register_shutdown_function(function () {
    if (ob_get_level() > 0 && trim((string) ob_get_contents()) === 'null') {
        ob_clean();
        header_remove('Content-Type');
        http_response_code(204);
    }
});

$server = new webservice_rest_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
$server->run();
die;

/**
 * Raises Early WS Exception in REST format (igual ao webservice/rest/server.php).
 *
 * Usada pelo lib/setup.php quando WS_SERVER está definido, para que falhas no
 * setup do Moodle (ex.: banco fora do ar) também saiam em JSON.
 *
 * @param Exception $ex Raised exception.
 */
function raise_early_ws_exception(Exception $ex): void {
    global $CFG;
    require_once("$CFG->dirroot/webservice/rest/locallib.php");
    $server = new webservice_rest_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
    $server->set_rest_format();
    $server->exception_handler($ex);
}
