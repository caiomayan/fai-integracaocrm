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

namespace local_faicrm\setup;

use context_system;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/accesslib.php');
require_once($CFG->dirroot . '/user/lib.php');

/**
 * Configuração idempotente da integração CRM (papel, usuário técnico, serviço e token).
 *
 * Usada por cli/configurar.php (implantação) e por docker/moodle/setup.php (desenvolvimento).
 * Não altera a política de senha e não cria cursos.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class configurador {
    /** Shortname do serviço externo declarado em db/services.php. */
    public const SERVICO = 'crm_vestibular_fai';
    /** Shortname do papel técnico. */
    public const PAPEL = 'integracaocrm';
    /** Username do usuário técnico. */
    public const USUARIO = 'ws_crm';

    /** Capabilities do papel integracaocrm (design da spec 002 + spec 004). */
    public const CAPABILITIES = [
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
        'mod/quiz:manageoverrides',
        'report/completion:view',
        'moodle/site:accessallgroups',
    ];

    /** @var callable|null função que recebe cada mensagem de progresso */
    private $log;

    /**
     * Cria o configurador.
     *
     * @param callable|null $log recebe uma string por mensagem de progresso
     */
    public function __construct(?callable $log = null) {
        $this->log = $log;
    }

    /**
     * Registra uma mensagem de progresso.
     *
     * @param string $message mensagem
     */
    private function out(string $message): void {
        if ($this->log !== null) {
            ($this->log)($message);
        }
    }

    /**
     * Executa todos os passos de configuração, sem gerar token.
     *
     * @return array [roleid, userid, service] ids do papel e do usuário e o registro do serviço
     */
    public function configurar(): array {
        $this->ligar_servicos();
        $roleid = $this->garantir_papel();
        $userid = $this->garantir_usuario($roleid);
        $service = $this->autorizar_servico($userid);
        return [$roleid, $userid, $service];
    }

    /**
     * Liga web services, o protocolo REST (mantendo os outros já ligados) e o acompanhamento de conclusão.
     */
    public function ligar_servicos(): void {
        $protocols = array_filter(array_map('trim', explode(',', (string) get_config('core', 'webserviceprotocols'))));
        if (!in_array('rest', $protocols, true)) {
            $protocols[] = 'rest';
        }
        $configs = [
            'enablewebservices' => 1,
            'webserviceprotocols' => implode(',', $protocols),
            'enablecompletion' => 1,
        ];
        foreach ($configs as $name => $value) {
            if ((string) get_config('core', $name) !== (string) $value) {
                set_config($name, $value);
                $this->out("config {$name}={$value} (alterado)");
            }
        }
        $this->out('configs ok (enablewebservices, protocolo rest, enablecompletion)');
    }

    /**
     * Cria ou atualiza o papel de sistema integracaocrm, com as capabilities e a permissão de atribuir só student.
     *
     * @return int id do papel
     */
    public function garantir_papel(): int {
        global $DB;
        $syscontext = context_system::instance();

        $role = $DB->get_record('role', ['shortname' => self::PAPEL]);
        if (!$role) {
            $roleid = create_role(
                'Integração CRM',
                self::PAPEL,
                'Papel técnico do usuário ' . self::USUARIO . ', usado pela integração CRM ↔ Moodle (serviço ' .
                self::SERVICO . ').'
            );
            $this->out('papel ' . self::PAPEL . " criado (id={$roleid})");
        } else {
            $roleid = (int) $role->id;
        }
        if (array_values(get_role_contextlevels($roleid)) !== [CONTEXT_SYSTEM]) {
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }

        $changed = 0;
        $existing = $DB->get_records_menu(
            'role_capabilities',
            ['roleid' => $roleid, 'contextid' => $syscontext->id],
            '',
            'capability, permission'
        );
        foreach (self::CAPABILITIES as $cap) {
            if (!get_capability_info($cap)) {
                $this->out("AVISO: capability {$cap} não existe nesta versão do Moodle; ignorada");
                continue;
            }
            if (($existing[$cap] ?? null) != CAP_ALLOW) {
                assign_capability($cap, CAP_ALLOW, $roleid, $syscontext->id, true);
                $changed++;
            }
        }
        if ($changed) {
            $syscontext->mark_dirty();
        }

        $studentid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        if (!$DB->record_exists('role_allow_assign', ['roleid' => $roleid, 'allowassign' => $studentid])) {
            core_role_set_assign_allowed($roleid, $studentid);
            $this->out('papel ' . self::PAPEL . ' agora pode atribuir student');
        }
        $this->out('papel ' . self::PAPEL . " ok (id={$roleid}, capabilities alteradas={$changed})");
        return $roleid;
    }

    /**
     * Senha aleatória forte para o usuário técnico (ninguém precisa dela: o acesso é só por token).
     *
     * Usa random_string() (random_bytes, CSPRNG): 40 caracteres alfanuméricos (~238 bits). O generate_password()
     * do core não serve: sem política de senha ele gera só palavra + dígito + palavra de um dicionário (rand()).
     * O sufixo garante 4 maiúsculas, 4 minúsculas, 4 dígitos e 4 símbolos, para qualquer política de senha usual.
     *
     * @return string senha
     */
    private static function senha_aleatoria(): string {
        return random_string(40) . 'ABCDabcd1234!#$%';
    }

    /**
     * Garante o usuário técnico (senha aleatória forte, nunca exibida) e o papel no contexto de sistema.
     *
     * @param int $roleid id do papel integracaocrm
     * @return int id do usuário
     */
    public function garantir_usuario(int $roleid): int {
        global $CFG, $DB;
        $syscontext = context_system::instance();

        $user = $DB->get_record('user', ['username' => self::USUARIO, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0]);
        if (!$user) {
            $userid = user_create_user((object) [
                'username' => self::USUARIO,
                'auth' => 'manual',
                'password' => self::senha_aleatoria(),
                'firstname' => 'Integração',
                'lastname' => 'CRM',
                'email' => 'ws_crm@localhost.local',
                'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id,
                'lang' => $CFG->lang ?? 'en',
            ], true, false);
            $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
            $this->out(self::USUARIO . " criado (id={$userid})");
        }
        $userid = (int) $user->id;
        if ($user->suspended) {
            $DB->set_field('user', 'suspended', 0, ['id' => $userid]);
            $this->out(self::USUARIO . ' estava suspenso; reativado');
        }
        if (!user_has_role_assignment($userid, $roleid, $syscontext->id)) {
            role_assign($roleid, $userid, $syscontext->id);
            $this->out('papel ' . self::PAPEL . ' atribuído a ' . self::USUARIO . ' (sistema)');
        }
        $this->out(self::USUARIO . " ok (id={$userid})");
        return $userid;
    }

    /**
     * Valida uma lista de IPs ou faixas separados por vírgula (formato do iprestriction do Moodle).
     *
     * @param string $ip lista de IPs/faixas
     * @return bool
     */
    public static function ip_valido(string $ip): bool {
        $items = array_filter(array_map('trim', explode(',', $ip)));
        if (!$items) {
            return false;
        }
        foreach ($items as $item) {
            $valid = \core\ip_utils::is_ip_address($item) || \core\ip_utils::is_ipv4_range($item)
                || \core\ip_utils::is_ipv6_range($item);
            if (!$valid) {
                return false;
            }
        }
        return true;
    }

    /**
     * Normaliza a lista de IPs/faixas (sem espaços nem itens vazios), no formato gravado pelo Moodle.
     *
     * @param string $ip lista de IPs/faixas separados por vírgula
     * @return string lista normalizada
     */
    public static function normalizar_ip(string $ip): string {
        return implode(',', array_filter(array_map('trim', explode(',', $ip))));
    }

    /**
     * Indica se a lista libera qualquer origem (0.0.0.0/0 ou ::/0), o que anula a restrição.
     *
     * @param string $ip lista de IPs/faixas
     * @return bool
     */
    public static function ip_libera_tudo(string $ip): bool {
        foreach (explode(',', self::normalizar_ip($ip)) as $item) {
            if (preg_match('#/0$#', $item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Habilita o serviço e autoriza o usuário técnico nele (IP opcional).
     *
     * A autorização fica sempre sem validade: no Moodle 4.5 a consulta de webservice_server::load_function_info()
     * compara external_services_users.validuntil ao contrário (validuntil < agora), então uma validade futura
     * bloqueia todas as chamadas (403). A validade da integração é a do token (gerar_token).
     *
     * @param int $userid id do usuário técnico
     * @param string $ip restrição de IP (vazio = não alterar)
     * @return stdClass registro do serviço
     */
    public function autorizar_servico(int $userid, string $ip = ''): stdClass {
        global $DB;

        $service = $DB->get_record('external_services', ['shortname' => self::SERVICO]);
        if (!$service) {
            throw new moodle_exception('servicenotfound', 'local_faicrm', '', self::SERVICO);
        }
        if (!$service->enabled) {
            $DB->set_field('external_services', 'enabled', 1, ['id' => $service->id]);
            $service->enabled = 1;
            $this->out('serviço ' . self::SERVICO . ' estava desabilitado; habilitado');
        }

        $auth = $DB->get_record('external_services_users', ['externalserviceid' => $service->id, 'userid' => $userid]);
        if (!$auth) {
            $DB->insert_record('external_services_users', (object) [
                'externalserviceid' => $service->id,
                'userid' => $userid,
                'iprestriction' => $ip !== '' ? $ip : null,
                'validuntil' => null,
                'timecreated' => time(),
            ]);
            $this->out(self::USUARIO . ' autorizado no serviço ' . self::SERVICO);
        } else {
            $changed = false;
            if ($ip !== '' && $auth->iprestriction !== $ip) {
                $auth->iprestriction = $ip;
                $changed = true;
                $this->out('restrição de IP da autorização de ' . self::USUARIO . ' atualizada');
            }
            if (!empty($auth->validuntil)) {
                $auth->validuntil = null;
                $changed = true;
                $this->out('validade removida da autorização de ' . self::USUARIO .
                    ' (bloquearia o acesso no Moodle 4.5; a validade fica no token)');
            }
            if ($changed) {
                $DB->update_record('external_services_users', $auth);
            }
        }
        $this->out('serviço ' . self::SERVICO . " ok (id={$service->id})");
        return $service;
    }

    /**
     * Devolve um token permanente ainda válido do usuário técnico no serviço, se existir.
     *
     * @param stdClass $service registro do serviço
     * @param int $userid id do usuário técnico
     * @return string|null token ou null
     */
    public function token_existente(stdClass $service, int $userid): ?string {
        global $DB;
        $tokens = $DB->get_records_select(
            'external_tokens',
            'userid = :userid AND externalserviceid = :serviceid AND tokentype = :tokentype
             AND (validuntil = 0 OR validuntil IS NULL OR validuntil > :now)',
            ['userid' => $userid, 'serviceid' => $service->id, 'tokentype' => EXTERNAL_TOKEN_PERMANENT, 'now' => time()],
            'timecreated ASC, id ASC'
        );
        return $tokens ? reset($tokens)->token : null;
    }

    /**
     * Gera um token permanente novo para o usuário técnico (IP e validade opcionais).
     *
     * @param stdClass $service registro do serviço
     * @param int $userid id do usuário técnico
     * @param string $ip restrição de IP (vazio = sem restrição)
     * @param int $validuntil timestamp de validade (0 = sem validade)
     * @param string $name nome do token (aparece na administração)
     * @return string token
     */
    public function gerar_token(
        stdClass $service,
        int $userid,
        string $ip = '',
        int $validuntil = 0,
        string $name = 'CRM Vestibular FAI'
    ): string {
        $token = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            $userid,
            context_system::instance(),
            $validuntil,
            $ip,
            $name
        );
        $this->out('token permanente gerado');
        return $token;
    }
}
