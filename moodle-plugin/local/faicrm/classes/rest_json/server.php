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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/rest/locallib.php');

/**
 * Servidor REST nativo com erros no formato do RF-10.
 *
 * Só troca a forma de enviar o erro (send_error) e marca a fase de autenticação;
 * autenticação, permissões, validação e execução continuam 100% nativas.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class server extends \webservice_rest_server {
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
     * Checa o estado das matrículas (RF-13) depois da autenticação e antes da função nativa.
     *
     * Tudo ou nada: com um item em conflito nada é executado. Entrada malformada, curso ou usuário
     * inexistente seguem para a validação e os erros nativos (400/404 de curso e candidato).
     */
    protected function execute() {
        $enrol = $this->functionname === 'enrol_manual_enrol_users';
        $unenrol = $this->functionname === 'enrol_manual_unenrol_users';
        if (($enrol || $unenrol) && is_array($this->parameters['enrolments'] ?? null)) {
            global $DB;
            foreach ($this->parameters['enrolments'] as $item) {
                if (
                    !is_array($item) || !isset($item['userid'], $item['courseid'])
                        || !is_numeric($item['userid']) || !is_numeric($item['courseid'])
                ) {
                    continue;
                }
                $userid = (int) $item['userid'];
                $courseid = (int) $item['courseid'];
                if (
                    !$DB->record_exists('course', ['id' => $courseid])
                        || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])
                ) {
                    continue;
                }
                if ($enrol) {
                    // Só matrícula manual ATIVA bloqueia: suspensa é reativada pela função nativa e
                    // matrícula por outro método não impede a manual.
                    $already = $DB->record_exists_sql(
                        'SELECT 1 FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                          WHERE e.courseid = ? AND e.enrol = ? AND ue.userid = ? AND ue.status = ?',
                        [$courseid, 'manual', $userid, ENROL_USER_ACTIVE]
                    );
                    if ($already) {
                        throw new conflict_exception('alreadyenrolled');
                    }
                } else {
                    $manual = $DB->record_exists_sql(
                        'SELECT 1 FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                          WHERE e.courseid = ? AND e.enrol = ? AND ue.userid = ?',
                        [$courseid, 'manual', $userid]
                    );
                    if (!$manual) {
                        throw new conflict_exception('notenrolled');
                    }
                }
            }
        }
        parent::execute();
    }

    /**
     * Envia o erro como {"message"} + status HTTP; o detalhe vai só para o log.
     *
     * @param \Throwable $ex exceção
     */
    protected function send_error($ex = null) {
        [$status, $message] = handler::map($ex, $this->authenticating);
        // Serviço desabilitado aparece como accessexception (o SQL nativo filtra enabled = 1).
        if (
            $status === 403 && !$this->authenticating && $this->restricted_serviceid
                && ($ex->errorcode ?? '') === 'accessexception'
        ) {
            global $DB;
            try {
                if (!$DB->get_field('external_services', 'enabled', ['id' => $this->restricted_serviceid])) {
                    [$status, $message] = [503, handler::MSG_503];
                }
            } catch (\Throwable $e) {
                // Na dúvida, mantém o 403.
                unset($e);
            }
        }
        handler::fail($status, $message, (string) ($ex->errorcode ?? ''), $ex);
    }
}
