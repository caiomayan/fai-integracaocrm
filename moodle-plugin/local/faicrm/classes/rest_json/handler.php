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

namespace local_faicrm\rest_json;

/**
 * Estado da requisição e respostas de erro do adaptador (RF-10).
 *
 * Usada pelo rest_json.php, pelo servidor REST (server) e no shutdown.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class handler {
    /** Tamanho máximo do corpo, em bytes (1 MiB). */
    public const MAXBODY = 1048576;

    /** Token ausente ou inválido. */
    public const MSG_401 = 'Token de acesso inválido ou ausente.';
    /** Sem permissão. */
    public const MSG_403 = 'Operação não permitida para esta integração.';
    /** Falha interna. */
    public const MSG_500 = 'Erro interno. Tente novamente mais tarde.';
    /** Indisponível. */
    public const MSG_503 = 'Serviço temporariamente indisponível. Tente novamente mais tarde.';

    /** Nomes amigáveis dos campos, para as mensagens de dado inválido. */
    public const LABELS = [
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
        'courseids' => 'cursos',
        'visivel' => 'visivel',
        'prazo' => 'prazo',
        'quizid' => 'prova',
        'pagina' => 'pagina',
        'porpagina' => 'porpagina',
        'dataprovade' => 'dataprovade',
        'dataprovaate' => 'dataprovaate',
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
     * @param \Throwable|null $ex exceção original (só para o log)
     * @param bool $exit encerra o script (false no shutdown, para não pular os handlers do Moodle)
     */
    public static function fail(
        int $status,
        string $message,
        string $errorcode,
        ?\Throwable $ex = null,
        bool $exit = true
    ): void {
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
     * @param \Throwable|null $ex exceção original
     */
    private static function log(int $status, string $errorcode, ?\Throwable $ex): void {
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
     * @param \Throwable $ex exceção
     * @param bool $authenticating a exceção aconteceu durante a autenticação do token
     * @return array [int status, string message]
     */
    public static function map(\Throwable $ex, bool $authenticating): array {
        $code = isset($ex->errorcode) && is_string($ex->errorcode) ? $ex->errorcode : '';
        $debug = isset($ex->debuginfo) && is_string($ex->debuginfo) ? $ex->debuginfo : '';

        if ($code === 'dbconnectionfailed' || $code === 'sitemaintenance' || $ex instanceof \dml_connection_exception) {
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
            case 'alreadyenrolled':
                return [409, 'O candidato já está matriculado neste curso.'];
            case 'notenrolled':
                return [404, 'O candidato não está matriculado neste curso.'];
            // Nova tentativa (spec 004): lançadas por local_faicrm_liberar/cancelar_nova_tentativa.
            case 'candidatonaomatriculado':
            case 'usernotenroled':
                return [409, 'O candidato não está matriculado neste curso.'];
            case 'tentativaemandamento':
                return [409, 'O candidato tem uma tentativa em andamento.'];
            case 'aindapodefazerprova':
                return [409, 'O candidato ainda pode fazer a prova.'];
            case 'tentativajainiciada':
                return [409, 'O candidato já iniciou a nova tentativa.'];
            case 'semexcecao':
                return [404, 'Não há nova tentativa liberada para este candidato.'];
            case 'prazoinvalido':
                return [400, 'Dados inválidos: prazo deve estar no formato AAAA-MM-DD.'];
            case 'prazonopassado':
                return [400, 'Dados inválidos: prazo não pode estar no passado.'];
            case 'provaencerrada':
                return [400, 'A prova está encerrada: informe um prazo para a nova tentativa.'];
            case 'locktimeout':
                // Trava do candidato não obtida em 10 s (liberar/cancelar simultâneos).
                return [503, self::MSG_503];
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

        if ($ex instanceof \dml_missing_record_exception) {
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
        // Paginação dos resultados (get_resultados_vestibular).
        if (strpos($debug, 'pagina must be') === 0) {
            return [400, 'Dados inválidos: pagina deve ser maior ou igual a 1.'];
        }
        foreach (['dataprovade', 'dataprovaate'] as $field) {
            if ($debug === $field . ' format') {
                return [400, 'Dados inválidos: ' . $field . ' deve estar no formato AAAA-MM-DD.'];
            }
        }
        if ($debug === 'dataprovade after dataprovaate') {
            return [400, 'Dados inválidos: dataprovade não pode ser posterior a dataprovaate.'];
        }
        // Contexto inexistente em external_api::validate_context() (ex.: matrícula em curso que não existe).
        if (strpos($debug, 'Context does not exist') === 0) {
            return [404, 'Curso não encontrado.'];
        }

        // Formato de external_api::validate_parameters(): "chave => <msg>: chave => <msg>: <detalhe>".
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
        if (
            strpos($rest, 'Unexpected keys') === 0
                && in_array(self::$wsfunction, ['local_faicrm_get_resultados_vestibular', 'local_faicrm_listar_cursos'], true)
                && preg_match('/\\bporpagina\\b/', $rest)
        ) {
            return [400, 'Dados inválidos: porpagina não é aceito; o serviço usa 100 por página.'];
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
            $ex = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
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
