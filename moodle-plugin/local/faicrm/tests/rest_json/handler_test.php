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
 * Tests for the error mapping of the rest_json adapter (spec 006 errors).
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_faicrm\rest_json\handler
 */
final class handler_test extends \basic_testcase {
    /**
     * Erros do acesso automático à prova.
     *
     * @return array [errorcode, status, mensagem]
     */
    public static function erros_do_sso(): array {
        return [
            ['ssoindisponivel', 503, 'O acesso automático à prova não está habilitado no Moodle.'],
            ['provanaoliberada', 403, 'Esta prova não está liberada para o acesso automático.'],
            ['contanaopermitida', 403, 'Este usuário não pode entrar na prova pelo acesso automático.'],
            ['candidatosuspenso', 409, 'O candidato está suspenso ou com cadastro incompleto no Moodle.'],
            ['candidatonaomatriculado', 409, 'O candidato não está matriculado neste curso.'],
        ];
    }

    /**
     * Cada erro do acesso automático vira o status e a mensagem esperados.
     *
     * @dataProvider erros_do_sso
     * @param string $errorcode errorcode lançado pela função
     * @param int $status status esperado
     * @param string $message mensagem esperada
     */
    public function test_erros_do_sso(string $errorcode, int $status, string $message): void {
        $this->assertSame([$status, $message], handler::map(new \moodle_exception($errorcode, 'local_faicrm'), false));
    }

    public function test_prova_e_candidato_nao_encontrados(): void {
        $this->assertSame([404, 'Prova não encontrada.'], handler::map(new \dml_missing_record_exception('quiz'), false));
        $this->assertSame([404, 'Candidato não encontrado.'], handler::map(new \dml_missing_record_exception('user'), false));
    }

    public function test_username_longo_demais_vira_400_com_a_mensagem_em_portugues(): void {
        $erro = new \core\exception\invalid_parameter_exception('username length');

        $this->assertSame(
            [400, 'Dados inválidos: username deve ter no máximo 100 caracteres.'],
            handler::map($erro, false)
        );
    }
}
