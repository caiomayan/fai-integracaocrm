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
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// O config.php só é carregado depois das validações do próprio adaptador.

/**
 * Estado da requisição e respostas de erro do adaptador (RF-10).
 *
 * Não depende do Moodle: é usada antes do config.php, pelo servidor REST e no shutdown.
 */
final class local_faicrm_rest_json {
    /** Tamanho máximo do corpo, em bytes (1 MiB). */
    const MAXBODY = 1048576;

    /** Token ausente ou inválido. */
    const MSG_401 = 'Token de acesso inválido ou ausente.';
    /** Sem permissão. */
    const MSG_403 = 'Operação não permitida para esta integração.';
    /** Falha interna. */
    const MSG_500 = 'Erro interno. Tente novamente mais tarde.';
    /** Indisponível. */
    const MSG_503 = 'Serviço temporariamente indisponível. Tente novamente mais tarde.';

    /** Nomes amigáveis dos campos, para as mensagens de dado inválido. */
    const LABELS = [
        'username' => 'usuário',
        'password' => 'senha',
        'firstname' => 'nome',
        'lastname' => 'sobrenome',
        'email' => 'e-mail',
        'auth' => 'tipo de autenticação',
        'idnumber' => 'número de identificação',
        'lang' => 'idioma',
        'theme' => 'tema',
        'courseid' => 'curso',
        'userid' => 'candidato',
        'roleid' => 'papel',
        'users' => 'candidatos',
        'enrolments' => 'matrículas',
        'field' => 'campo de busca',
        'values' => 'valores',
        'timestart' => 'início da matrícula',
        'timeend' => 'fim da matrícula',
        'suspend' => 'suspensão',
    ];

    /** @var string id de correlação (header X-Request-Id e log) */
    public static $requestid = '';
    /** @var string função pedida (só para o log; já validada) */
    public static $wsfunction = '';
    /** @var string[] valores que nunca podem ir para o log (token e senhas) */
    public static $secrets = [];
    /** @var bool resposta de erro já enviada */
    public static $responded = false;

    /**
     * Gera o id da requisição, abre o buffer de saída e registra o shutdown.
     */
    public static function start(): void {
        self::$requestid = bin2hex(random_bytes(8));
        ob_start();
        register_shutdown_function([self::class, 'shutdown']);
    }

    /**
     * Headers comuns a todas as respostas do adaptador (sucesso ou erro).
     *
     * Sem Access-Control-Allow-Origin: o token só é usado no backend (RNF-04).
     */
    public static function common_headers(): void {
        header_remove('Access-Control-Allow-Origin');
        header_remove('X-Powered-By');
        header('X-Request-Id: ' . self::$requestid);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }

    /**
     * Responde o erro ({"message"} + status), registra o detalhe no log e encerra.
     *
     * @param int $status código HTTP
     * @param string $message mensagem para o usuário final
     * @param string $errorcode código interno (só para o log)
     * @param Throwable|null $ex exceção original (só para o log)
     * @param bool $exit encerra o script (false no shutdown, para não pular os handlers do Moodle)
     */
    public static function fail(int $status, string $message, string $errorcode, ?Throwable $ex = null,
            bool $exit = true): void {
        if (!self::$responded) {
            self::$responded = true;
            self::log($status, $errorcode, $ex);

            // Descarta qualquer saída parcial (HTML de erro, aviso do PHP, resposta nativa).
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                header_remove();
                http_response_code($status);
                header('Content-Type: application/json; charset=utf-8');
                self::common_headers();
                if ($status === 401) {
                    header('WWW-Authenticate: Bearer');
                } else if ($status === 405) {
                    header('Allow: POST');
                }
            }
            echo json_encode(['message' => $message], JSON_UNESCAPED_UNICODE);
        }
        if ($exit) {
            exit(1);
        }
    }

    /**
     * Registra o erro no log do servidor (uma linha JSON), sem token nem senha.
     *
     * @param int $status código HTTP respondido
     * @param string $errorcode código do erro
     * @param Throwable|null $ex exceção original
     */
    private static function log(int $status, string $errorcode, ?Throwable $ex): void {
        $entry = [
            'requestid' => self::$requestid,
            'status' => $status,
            'wsfunction' => self::$wsfunction,
            'errorcode' => $errorcode,
        ];
        if ($ex !== null) {
            $entry['exception'] = get_class($ex);
            $entry['message'] = $ex->getMessage();
            $entry['debuginfo'] = isset($ex->debuginfo) && is_scalar($ex->debuginfo) ? (string) $ex->debuginfo : null;
            if ($status >= 500) {
                $entry['where'] = $ex->getFile() . ':' . $ex->getLine();
            }
        }
        // Só os textos vindos da exceção podem conter segredo; requestid, wsfunction (validado por regex),
        // errorcode e exception ficam intactos para a correlação.
        foreach (['message', 'debuginfo', 'where'] as $key) {
            if (isset($entry[$key]) && is_string($entry[$key])) {
                $entry[$key] = self::redact($entry[$key]);
            }
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
        error_log('local_faicrm rest_json ' . json_encode($entry, $flags));
    }

    /**
     * Remove token, senhas e hashes de senha de um texto que vai para o log.
     *
     * @param string $text texto original
     * @return string texto sem segredos
     */
    private static function redact(string $text): string {
        $secrets = array_filter(array_unique(self::$secrets), function ($s) {
            return $s !== '';
        });
        usort($secrets, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        foreach ($secrets as $secret) {
            // Segredo longo: qualquer ocorrência. Qualquer tamanho: ocorrência delimitada (não colada em
            // letra/dígito), para uma senha curta como "e" ou "1" não destruir o resto do texto.
            if (strlen($secret) >= 8) {
                $text = str_replace($secret, '[omitido]', $text);
            }
            $text = preg_replace('/(?<![A-Za-z0-9])' . preg_quote($secret, '/') . '(?![A-Za-z0-9])/', '[omitido]', $text);
        }
        // Hashes de senha (bcrypt / sha-crypt) que possam aparecer no SQL de um debuginfo.
        return preg_replace('/\$(?:2[abxy]|5|6)\$[^\s\'",)\]]+/', '[omitido]', $text);
    }

    /**
     * Guarda os valores das chaves "password" do corpo, em qualquer nível, para o redact.
     *
     * @param mixed $data corpo decodificado
     */
    public static function collect_passwords($data): void {
        if (!is_array($data)) {
            return;
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                self::collect_passwords($value);
            } else if (is_string($key) && strcasecmp($key, 'password') === 0 && is_scalar($value)) {
                self::$secrets[] = (string) $value;
            }
        }
    }

    /**
     * Traduz uma exceção do Moodle em [status HTTP, mensagem].
     *
     * @param Throwable $ex exceção
     * @param bool $authenticating a exceção aconteceu durante a autenticação do token
     * @return array [int status, string message]
     */
    public static function map(Throwable $ex, bool $authenticating): array {
        $code = isset($ex->errorcode) && is_string($ex->errorcode) ? $ex->errorcode : '';
        $debug = isset($ex->debuginfo) && is_string($ex->debuginfo) ? $ex->debuginfo : '';

        if ($code === 'dbconnectionfailed' || $code === 'sitemaintenance' || $ex instanceof dml_connection_exception) {
            return [503, self::MSG_503];
        }
        // Token inexistente (invalidtoken) ou expirado (accessexception "Invalid token - token expired").
        if ($code === 'invalidtoken' || ($code === 'accessexception' && preg_match('/^Invalid (session based )?token/', $debug))) {
            return [401, self::MSG_401];
        }
        if ($authenticating) {
            if ($code === 'accessexception' && strpos($debug, 'Web services are not enabled') === 0) {
                return [503, self::MSG_503];
            }
            // Usuário técnico suspenso/sem login, IP não autorizado, sem webservice/rest:use etc.
            return [403, self::MSG_403];
        }

        switch ($code) {
            case 'accessexception':
            case 'nopermissions':
            case 'requireloginerror':
            case 'restrictedcontextexception':
            case 'servicerequireslogin':
                return [403, self::MSG_403];
            case 'servicenotavailable':
                return [503, self::MSG_503];
            case 'wsusercannotassign':
                return [403, 'Não é permitido matricular com este papel.'];
            case 'wsnoinstance':
            case 'wscannotenrol':
            case 'wscannotunenrol':
                return [409, 'O curso não aceita matrícula manual no momento.'];
            case 'usernotconfirmed':
            case 'suspended':
                return [409, 'O candidato está suspenso ou com cadastro incompleto no Moodle.'];
            case 'userdeleted':
            case 'invaliduser':
                return [404, 'Candidato não encontrado.'];
            case 'guestsarenotallowed':
                return [400, 'Dados inválidos: este usuário não pode ser matriculado.'];
            case 'errorcoursecontextnotvalid':
            case 'invalidcourseid':
                return [404, 'Curso não encontrado.'];
            case 'invalidcoursemodule':
                return [404, 'Atividade não encontrada.'];
            case 'usernamelowercase':
                return [400, 'Dados inválidos: o usuário deve ter só letras minúsculas.'];
            case 'invalidusername':
                return [400, 'Dados inválidos: o usuário contém caracteres não permitidos.'];
            case 'invalidparameter':
                return self::map_invalid_parameter($debug);
        }

        if ($ex instanceof dml_missing_record_exception) {
            switch ($ex->tablename) {
                case 'course':
                    return [404, 'Curso não encontrado.'];
                case 'user':
                    return [404, 'Candidato não encontrado.'];
                case 'quiz':
                    return [404, 'Prova não encontrada.'];
                case 'external_functions':
                    return [404, 'Operação não encontrada: verifique o parâmetro wsfunction.'];
                default:
                    return [404, 'Registro não encontrado.'];
            }
        }
        // Política de senha: user_create_user() lança moodle_exception com o texto da política como errorcode.
        if (strpos($code, '<div>') === 0 && self::$wsfunction === 'core_user_create_users') {
            return [400, 'A senha não atende à política de senhas do Moodle.'];
        }
        return [500, self::MSG_500];
    }

    /**
     * Traduz invalid_parameter_exception pelo debuginfo (sempre presente na exceção,
     * independente do nível de debug do site).
     *
     * @param string $debug debuginfo da exceção
     * @return array [int status, string message]
     */
    private static function map_invalid_parameter(string $debug): array {
        // Mensagens de core_user_create_users (user/externallib.php).
        if (strpos($debug, 'Username already exists') === 0) {
            return [409, 'Já existe um candidato com este usuário.'];
        }
        if (strpos($debug, 'Email address already exists') === 0) {
            return [409, 'Já existe um candidato com este e-mail.'];
        }
        if (strpos($debug, 'Email address is invalid') === 0) {
            return [400, 'Dados inválidos: e-mail inválido.'];
        }
        if (strpos($debug, 'Invalid password:') === 0) {
            return [400, 'Dados inválidos: o campo senha é obrigatório.'];
        }
        if (preg_match('/^The field ([a-z_]+) cannot be blank/', $debug, $m)) {
            return [400, 'Dados inválidos: o campo ' . self::label($m[1]) . ' não pode ficar em branco.'];
        }
        foreach (['authentication type' => 'auth', 'language code' => 'lang', 'theme' => 'theme'] as $text => $field) {
            if (strpos($debug, 'Invalid ' . $text . ':') === 0) {
                return [400, 'Dados inválidos: verifique o campo ' . self::label($field) . '.'];
            }
        }
        // external_api::validate_context() com contexto inexistente (ex.: matrícula em curso que não existe).
        if (strpos($debug, 'Context does not exist') === 0) {
            return [404, 'Curso não encontrado.'];
        }

        // external_api::validate_parameters(): "chave => <msg>: chave => <msg>: <detalhe>".
        // Só o prefixo é lido: as chaves vêm da descrição da função, nunca do valor enviado.
        $path = [];
        $rest = $debug;
        while (preg_match('/^([A-Za-z0-9_]+) => [^:]*: /', $rest, $m)) {
            if (!ctype_digit($m[1])) {
                $path[] = $m[1];
            }
            $rest = substr($rest, strlen($m[0]));
        }
        if (preg_match('/^Missing required key in single structure: ([A-Za-z0-9_]+)/', $rest, $m)) {
            return [400, 'Dados inválidos: o campo ' . self::label($m[1]) . ' é obrigatório.'];
        }
        if (strpos($rest, 'Unexpected keys') === 0) {
            return [400, 'Dados inválidos: há campos não reconhecidos' .
                ($path ? ' em ' . self::label(end($path)) : '') . '.'];
        }
        if ($path) {
            return [400, 'Dados inválidos: verifique o campo ' . self::label(end($path)) . '.'];
        }
        return [400, 'Dados inválidos: verifique os dados enviados.'];
    }

    /**
     * Nome amigável do campo.
     *
     * @param string $field nome técnico (da descrição da função)
     * @return string
     */
    private static function label(string $field): string {
        return self::LABELS[$field] ?? $field;
    }

    /**
     * Shutdown: erro fatal vira 500 em JSON; retorno nativo null vira 204 (RF-09).
     *
     * O run() nativo termina com die; o shutdown roda antes de o buffer ser descarregado,
     * então ainda dá para trocar status e headers.
     */
    public static function shutdown(): void {
        if (self::$responded) {
            return;
        }
        $error = error_get_last();
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if ($error !== null && in_array($error['type'], $fatal, true)) {
            $ex = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
            self::fail(500, self::MSG_500, 'fatalerror', $ex, false);
            return;
        }
        if (headers_sent()) {
            return;
        }
        self::common_headers();
        if (ob_get_level() > 0 && trim((string) ob_get_contents()) === 'null') {
            ob_clean();
            header_remove('Content-Type');
            http_response_code(204);
        }
    }
}

local_faicrm_rest_json::start();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    local_faicrm_rest_json::fail(405, 'Método não permitido. Use POST.', 'methodnotallowed');
}

$wsfunction = $_GET['wsfunction'] ?? '';
if (!is_string($wsfunction) || $wsfunction === '') {
    local_faicrm_rest_json::fail(400, 'Informe a operação desejada (parâmetro wsfunction).', 'missingwsfunction');
}
// Nomes de função do Moodle: minúsculas, dígitos e _. Evita lixo no log e na consulta.
if (!preg_match('/^[a-z][a-z0-9_]{0,199}$/', $wsfunction)) {
    local_faicrm_rest_json::fail(400, 'Operação inválida: verifique o parâmetro wsfunction.', 'invalidwsfunction');
}
local_faicrm_rest_json::$wsfunction = $wsfunction;

$toolarge = 'O corpo da requisição é grande demais (máximo de 1 MB).';
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > local_faicrm_rest_json::MAXBODY) {
    local_faicrm_rest_json::fail(413, $toolarge, 'payloadtoolarge');
}
$raw = file_get_contents('php://input', false, null, 0, local_faicrm_rest_json::MAXBODY + 1);
if (is_string($raw) && strlen($raw) > local_faicrm_rest_json::MAXBODY) {
    local_faicrm_rest_json::fail(413, $toolarge, 'payloadtoolarge');
}
if (is_string($raw) && strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
    $raw = substr($raw, 3);
}
if ($raw === false || trim($raw) === '') {
    $body = [];
} else {
    // Profundidade 32 sobra para as estruturas das funções do serviço.
    $body = json_decode($raw, true, 32);
    // Precisa ser objeto (ou {}): rejeita escalares, listas e JSON inválido.
    if (!is_array($body) || ($body !== [] && array_is_list($body))) {
        local_faicrm_rest_json::fail(400, 'O corpo da requisição deve ser um objeto JSON válido.', 'invalidjson');
    }
}
unset($raw);
local_faicrm_rest_json::collect_passwords($body);

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
    local_faicrm_rest_json::$secrets[] = $token;
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

// Replica webservice/rest/server.php (o adaptador roda o servidor nativo ele mesmo).
define('NO_DEBUG_DISPLAY', true);
define('WS_SERVER', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/webservice/rest/locallib.php');

/**
 * Servidor REST nativo com erros no formato do RF-10.
 *
 * Só troca a forma de enviar o erro (send_error) e marca a fase de autenticação;
 * autenticação, permissões, validação e execução continuam 100% nativas.
 */
class local_faicrm_rest_json_server extends webservice_rest_server {
    /** @var bool autenticação do token em andamento */
    protected $authenticating = false;

    /**
     * Autenticação nativa, marcando a fase (erros aqui são de token/acesso).
     */
    protected function authenticate_user() {
        $this->authenticating = true;
        parent::authenticate_user();
        $this->authenticating = false;
    }

    /**
     * Envia o erro como {"message"} + status HTTP; o detalhe vai só para o log.
     *
     * @param Throwable $ex exceção
     */
    protected function send_error($ex = null) {
        [$status, $message] = local_faicrm_rest_json::map($ex, $this->authenticating);
        // Serviço desabilitado aparece como accessexception (o SQL nativo filtra enabled = 1).
        if ($status === 403 && !$this->authenticating && $this->restricted_serviceid
                && ($ex->errorcode ?? '') === 'accessexception') {
            global $DB;
            try {
                if (!$DB->get_field('external_services', 'enabled', ['id' => $this->restricted_serviceid])) {
                    [$status, $message] = [503, local_faicrm_rest_json::MSG_503];
                }
            } catch (Throwable $e) {
                // Na dúvida, mantém o 403.
                unset($e);
            }
        }
        local_faicrm_rest_json::fail($status, $message, (string) ($ex->errorcode ?? ''), $ex);
    }
}

if (!webservice_protocol_is_enabled('rest')) {
    local_faicrm_rest_json::fail(503, local_faicrm_rest_json::MSG_503, 'protocoldisabled');
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

$server = new local_faicrm_rest_json_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
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
    [$status, $message] = local_faicrm_rest_json::map($ex, false);
    local_faicrm_rest_json::fail($status, $message, (string) ($ex->errorcode ?? ''), $ex);
}
