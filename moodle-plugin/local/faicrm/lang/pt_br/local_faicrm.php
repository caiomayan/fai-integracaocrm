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
 * Portuguese (Brazil) language strings for local_faicrm.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['aindapodefazerprova'] = 'O candidato ainda pode fazer a prova.';
$string['candidatonaomatriculado'] = 'O candidato não está matriculado neste curso.';
$string['candidatosuspenso'] = 'O candidato está suspenso ou com cadastro incompleto no Moodle.';
$string['contanaopermitida'] = 'Este usuário não pode entrar na prova pelo acesso automático.';
$string['diag_sso_liberados_bad'] = 'Nenhuma categoria e nenhum curso liberado: o acesso automático está desligado. Escolha abaixo as categorias ou os cursos dos vestibulares.';
$string['diag_sso_liberados_ok'] = '{$a->categorias} categoria(s) e {$a->cursos} curso(s) liberado(s) para o acesso automático.';
$string['diag_sso_servico_habilitado_bad'] = 'O serviço web "CRM SSO Vestibular FAI" não existe ou está desabilitado.';
$string['diag_sso_servico_habilitado_ok'] = 'O serviço web "CRM SSO Vestibular FAI" está habilitado.';
$string['diag_sso_servico_token_bad'] = 'O serviço web "CRM SSO Vestibular FAI" ainda não tem token válido.';
$string['diag_sso_servico_token_ok'] = 'O serviço web "CRM SSO Vestibular FAI" tem um token válido.';
$string['diag_sso_servico_usuario_bad'] = 'O serviço web "CRM SSO Vestibular FAI" ainda não tem usuário autorizado (crie a conta ws_sso e autorize-a).';
$string['diag_sso_servico_usuario_ok'] = 'O serviço web "CRM SSO Vestibular FAI" tem um usuário autorizado.';
$string['diag_userkey_cria_atualiza_bad'] = 'O auth_userkey está configurado para criar ou atualizar usuários: desligue as duas opções (o CRM cadastra candidatos pela API de integração).';
$string['diag_userkey_cria_atualiza_ok'] = 'O auth_userkey não cria nem atualiza usuários.';
$string['diag_userkey_funcao_so_no_servico_dele_bad'] = 'A função auth_userkey_request_login_url também está em outros serviços ({$a}). Retire-a deles.';
$string['diag_userkey_funcao_so_no_servico_dele_ok'] = 'A função auth_userkey_request_login_url está só no serviço web do auth_userkey.';
$string['diag_userkey_generatekey_bad'] = 'Estes papéis podem gerar chaves de login para qualquer usuário (auth/userkey:generatekey): {$a}. Retire a permissão.';
$string['diag_userkey_generatekey_ok'] = 'Nenhum papel tem a permissão auth/userkey:generatekey.';
$string['diag_userkey_habilitado_bad'] = 'O plugin auth_userkey não está instalado ou habilitado: o acesso automático está desligado.';
$string['diag_userkey_habilitado_ok'] = 'O plugin auth_userkey está instalado e habilitado.';
$string['diag_userkey_keylifetime_bad'] = 'A validade da chave do auth_userkey é {$a} segundos; o local_faicrm usa no máximo 120. Reduza para 120 ou menos.';
$string['diag_userkey_keylifetime_ok'] = 'A validade da chave do auth_userkey está dentro do limite (até 120 segundos).';
$string['diag_userkey_servico_sem_usuarios_bad'] = 'O serviço web do auth_userkey tem usuários autorizados ou tokens ({$a}): eles poderiam gerar link de acesso para qualquer conta. Remova-os.';
$string['diag_userkey_servico_sem_usuarios_ok'] = 'O serviço web do auth_userkey não tem usuários autorizados nem tokens.';
$string['diag_userkey_urls_bad'] = 'A configuração "SSO URL" ou "Redirect URL" do auth_userkey está preenchida: esvazie, para um login que falhe não mandar o candidato para outro lugar.';
$string['diag_userkey_urls_ok'] = 'As configurações "SSO URL" e "Redirect URL" do auth_userkey estão vazias.';
$string['eventlinkprovagerado'] = 'Link de acesso à prova gerado';
$string['faicrm:gerarlinkprova'] = 'Gerar o link de acesso automático à prova';
$string['linkinvalido'] = 'Este link de acesso já foi usado ou expirou. Volte ao CRM e clique de novo em "Realizar minha prova".';
$string['locktimeout'] = 'Outra operação para este candidato está em andamento. Tente de novo.';
$string['prazoinvalido'] = 'O prazo deve ser uma data válida no formato AAAA-MM-DD.';
$string['prazonopassado'] = 'O prazo não pode estar no passado.';
$string['privacy:metadata:preference:novatentativa'] = 'Registra uma tentativa extra da prova liberada para o usuário pelo CRM, para o cancelamento restaurar a exceção como estava.';
$string['provaencerrada'] = 'A prova está encerrada: informe um prazo para a nova tentativa.';
$string['provanaoliberada'] = 'Esta prova não está liberada para o acesso automático.';
$string['semexcecao'] = 'Não há nova tentativa liberada para este candidato.';
$string['servicenotfound'] = 'O serviço externo "{$a}" não foi encontrado. O plugin local_faicrm está instalado (Administração do site > Notificações)?';
$string['sso_categorias'] = 'Categorias liberadas';
$string['sso_categorias_desc'] = 'Os cursos destas categorias (e das subcategorias) ficam liberados para o acesso automático. Valem junto com os cursos liberados abaixo: só as provas de cursos liberados geram link, e só para contas que sejam alunos de cursos liberados. Quem tem papel em qualquer curso fora deles fica de fora. Se nenhuma categoria e nenhum curso estiverem marcados, o acesso automático fica desligado.';
$string['sso_cursos'] = 'Cursos liberados';
$string['sso_cursos_desc'] = 'Libere aqui cursos específicos (por exemplo, quando os vestibulares estão na mesma categoria que outros cursos). Valem junto com as categorias liberadas. Se nenhum curso e nenhuma categoria estiverem marcados, o acesso automático fica desligado.';
$string['ssodiagnostico'] = 'Diagnóstico do acesso automático';
$string['ssoindisponivel'] = 'O acesso automático à prova não está habilitado no Moodle.';
$string['ssosettings'] = 'FAI CRM: acesso automático à prova';
$string['studentrolenotfound'] = 'O papel com shortname "student" não foi encontrado.';
$string['tentativaemandamento'] = 'O candidato tem uma tentativa em andamento.';
$string['tentativajainiciada'] = 'O candidato já iniciou a nova tentativa.';
