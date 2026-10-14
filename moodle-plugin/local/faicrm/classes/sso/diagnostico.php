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

namespace local_faicrm\sso;

/**
 * Read-only diagnostic of the automatic access (SSO): the settings that make it unsafe or unusable.
 *
 * Shown at the top of the settings page and in cli/verificar.php. It changes nothing.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnostico {
    /** Name of the web service that auth_userkey declares (it has no shortname). */
    private const USERKEY_SERVICE_NAME = 'User key authentication web service';

    /**
     * Runs all the checks.
     *
     * Without auth_userkey enabled there is only one item (a warning): nothing else can be checked or used.
     *
     * @return array[] List of ['code' => string, 'status' => 'OK'|'AVISO'|'FALHA', 'text' => string].
     */
    public static function run(): array {
        $items = [];
        $add = function (string $code, string $status, $a = null, string $suffix = '') use (&$items): void {
            $key = 'diag_' . $code . '_' . ($suffix !== '' ? $suffix : ($status === 'OK' ? 'ok' : 'bad'));
            $items[] = ['code' => $code, 'status' => $status, 'text' => get_string($key, 'local_faicrm', $a)];
        };

        if (!link::available()) {
            $add('userkey_habilitado', 'AVISO');
            return $items;
        }
        $add('userkey_habilitado', 'OK');

        self::check_userkey($add);
        self::check_sso($add);
        return $items;
    }

    /**
     * Whether any item is a failure.
     *
     * @param array[] $items Result of {@see run()}.
     * @return bool
     */
    public static function has_failures(array $items): bool {
        foreach ($items as $item) {
            if ($item['status'] === 'FALHA') {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks of the settings of auth_userkey.
     *
     * @param callable $add Adds an item: code, status and an optional string argument.
     */
    private static function check_userkey(callable $add): void {
        global $DB;

        // The web service of auth_userkey must have no authorised users and no tokens (it can mint links for any account).
        $service = $DB->get_record('external_services', ['name' => self::USERKEY_SERVICE_NAME], 'id', IGNORE_MULTIPLE);
        $used = 0;
        if ($service) {
            $used = $DB->count_records('external_services_users', ['externalserviceid' => $service->id])
                + $DB->count_records('external_tokens', ['externalserviceid' => $service->id]);
        }
        $add('userkey_servico_sem_usuarios', $used ? 'FALHA' : 'OK', $used);

        // No role may generate keys through the plugin's own capability.
        $roles = $DB->get_fieldset_sql(
            "SELECT DISTINCT r.shortname
               FROM {role_capabilities} rc
               JOIN {role} r ON r.id = rc.roleid
              WHERE rc.capability = :capability AND rc.permission = :allow",
            ['capability' => 'auth/userkey:generatekey', 'allow' => CAP_ALLOW]
        );
        $add('userkey_generatekey', $roles ? 'FALHA' : 'OK', implode(', ', $roles));

        // The function that mints links for any user must not be in any other service.
        $others = $DB->get_fieldset_sql(
            "SELECT DISTINCT es.name
               FROM {external_services_functions} esf
               JOIN {external_services} es ON es.id = esf.externalserviceid
              WHERE esf.functionname = :function AND es.name <> :name",
            ['function' => 'auth_userkey_request_login_url', 'name' => self::USERKEY_SERVICE_NAME]
        );
        $add('userkey_funcao_so_no_servico_dele', $others ? 'FALHA' : 'OK', implode(', ', $others));

        $urlsset = (string) get_config('auth_userkey', 'ssourl') !== ''
            || (string) get_config('auth_userkey', 'redirecturl') !== '';
        $add('userkey_urls', $urlsset ? 'AVISO' : 'OK');

        $creates = !empty(get_config('auth_userkey', 'createuser')) || !empty(get_config('auth_userkey', 'updateuser'));
        $add('userkey_cria_atualiza', $creates ? 'AVISO' : 'OK');

        $lifetime = (int) get_config('auth_userkey', 'keylifetime');
        $add('userkey_keylifetime', $lifetime > link::MAX_LIFETIME ? 'AVISO' : 'OK', $lifetime);
    }

    /**
     * Checks of our own settings: released categories or courses and the SSO web service.
     *
     * @param callable $add Adds an item: code, status and an optional string argument.
     */
    private static function check_sso(callable $add): void {
        global $DB;

        $released = (object) [
            'categorias' => count(elegibilidade::configured_categories()),
            'cursos' => count(elegibilidade::configured_courses()),
        ];
        $add('sso_liberados', elegibilidade::released_categories() || $released->cursos ? 'OK' : 'AVISO', $released);

        $service = $DB->get_record('external_services', ['shortname' => 'crm_sso_vestibular_fai']);
        $add('sso_servico_habilitado', $service && $service->enabled ? 'OK' : 'FALHA');
        if (!$service) {
            return;
        }
        $users = $DB->count_records('external_services_users', ['externalserviceid' => $service->id]);
        $add('sso_servico_usuario', $users ? 'OK' : 'AVISO');

        $tokens = $DB->count_records_select(
            'external_tokens',
            'externalserviceid = :service AND (validuntil IS NULL OR validuntil = 0 OR validuntil > :now)',
            ['service' => $service->id, 'now' => time()]
        );
        $add('sso_servico_token', $tokens ? 'OK' : 'AVISO');

        self::check_retorno($add);
    }

    /**
     * Check of the return to the CRM after the quiz: off is fine (informative), on shows the domain, an invalid URL fails
     * (the return stays off).
     *
     * @param callable $add Adds an item: code, status, string argument and string suffix.
     */
    private static function check_retorno(callable $add): void {
        $add('retorno_segredo', retorno::has_secret() ? 'OK' : 'FALHA');

        $raw = retorno::raw_url();
        if ($raw === '') {
            $add('retorno', 'OK', null, 'desligado');
        } else if (retorno::url() === null) {
            $add('retorno', 'FALHA', null, 'invalida');
        } else {
            $add('retorno', 'OK', (string) parse_url($raw, PHP_URL_HOST), 'ligado');
        }
    }
}
