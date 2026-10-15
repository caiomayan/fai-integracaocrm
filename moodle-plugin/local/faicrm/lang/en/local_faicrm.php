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
 * English language strings for local_faicrm.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['aindapodefazerprova'] = 'The candidate can still take the quiz.';
$string['candidatonaomatriculado'] = 'The candidate is not enrolled in this course.';
$string['candidatosuspenso'] = 'The candidate is suspended or has an incomplete account in Moodle.';
$string['contanaopermitida'] = 'This user cannot enter the quiz through the automatic access.';
$string['diag_sso_liberados_bad'] = 'No course category and no course is released: the automatic access is off. Choose the categories or courses of the entrance exams below.';
$string['diag_sso_liberados_ok'] = '{$a->categorias} course category(ies) and {$a->cursos} course(s) released for the automatic access.';
$string['diag_sso_servico_habilitado_bad'] = 'The web service "CRM SSO Vestibular FAI" does not exist or is disabled.';
$string['diag_sso_servico_habilitado_ok'] = 'The web service "CRM SSO Vestibular FAI" is enabled.';
$string['diag_sso_servico_token_bad'] = 'The web service "CRM SSO Vestibular FAI" has no valid token yet.';
$string['diag_sso_servico_token_ok'] = 'The web service "CRM SSO Vestibular FAI" has a valid token.';
$string['diag_sso_servico_usuario_bad'] = 'The web service "CRM SSO Vestibular FAI" has no authorised user yet (create the ws_sso account and authorise it).';
$string['diag_sso_servico_usuario_ok'] = 'The web service "CRM SSO Vestibular FAI" has an authorised user.';
$string['diag_userkey_cria_atualiza_bad'] = 'auth_userkey is set to create or update users: turn both off (the CRM registers candidates through the integration API).';
$string['diag_userkey_cria_atualiza_ok'] = 'auth_userkey does not create or update users.';
$string['diag_userkey_funcao_so_no_servico_dele_bad'] = 'The function auth_userkey_request_login_url is also in other services ({$a}). Remove it from them.';
$string['diag_userkey_funcao_so_no_servico_dele_ok'] = 'The function auth_userkey_request_login_url is only in the web service of auth_userkey.';
$string['diag_userkey_generatekey_bad'] = 'These roles can generate login keys for any user (auth/userkey:generatekey): {$a}. Remove the permission.';
$string['diag_userkey_generatekey_ok'] = 'No role has the capability auth/userkey:generatekey.';
$string['diag_userkey_habilitado_bad'] = 'The auth_userkey plugin is not installed or not enabled: the automatic access is off.';
$string['diag_userkey_habilitado_ok'] = 'The auth_userkey plugin is installed and enabled.';
$string['diag_userkey_keylifetime_bad'] = 'The key life time of auth_userkey is {$a} seconds; the plugin local_faicrm uses at most 120. Lower it to 120 or less.';
$string['diag_userkey_keylifetime_ok'] = 'The key life time of auth_userkey is within the limit (up to 120 seconds).';
$string['diag_userkey_servico_sem_usuarios_bad'] = 'The web service of auth_userkey has authorised users or tokens ({$a}): they could generate a login link for any account. Remove them.';
$string['diag_userkey_servico_sem_usuarios_ok'] = 'The web service of auth_userkey has no authorised users and no tokens.';
$string['diag_userkey_urls_bad'] = 'The auth_userkey setting SSO URL or redirect URL is filled in: clear it, so a failed login does not send the candidate elsewhere.';
$string['diag_userkey_urls_ok'] = 'The auth_userkey settings SSO URL and redirect URL are empty.';
$string['eventlinkprovagerado'] = 'Quiz access link generated';
$string['faicrm:gerarlinkprova'] = 'Generate automatic access links to the quiz';
$string['linkinvalido'] = 'This access link has already been used or has expired. Go back to the CRM and click "Take my exam" again.';
$string['locktimeout'] = 'Another operation for this candidate is in progress. Try again.';
$string['pluginname'] = 'FAI CRM integration';
$string['prazoinvalido'] = 'The deadline must be a valid date in the format YYYY-MM-DD.';
$string['prazonopassado'] = 'The deadline cannot be in the past.';
$string['privacy:metadata:preference:novatentativa'] = 'Records an extra quiz attempt released for the user by the CRM: the attempts and closing date of the user override before the release and the values the release set, so that cancelling it restores them.';
$string['provaencerrada'] = 'The quiz is closed: provide a deadline for the new attempt.';
$string['provanaoliberada'] = 'This quiz is not released for the automatic access.';
$string['semexcecao'] = 'There is no extra attempt released for this candidate.';
$string['servicenotfound'] = 'The external service "{$a}" was not found. Is the local_faicrm plugin installed (Site administration > Notifications)?';
$string['sso_categorias'] = 'Released categories';
$string['sso_categorias_desc'] = 'The courses of these categories (subcategories included) are released for the automatic access. Together with the released courses below: the CRM can generate a login link to a quiz only when the quiz is in a released course, and only for accounts that are students of released courses. Leave both empty to turn the automatic access off.';
$string['sso_cursos'] = 'Released courses';
$string['sso_cursos_desc'] = 'Release specific courses (for example, when the entrance exams are in the same category as other courses). They count together with the released categories. If no course and no category is selected, the automatic access is off.';
$string['ssodiagnostico'] = 'Automatic access diagnostic';
$string['ssoindisponivel'] = 'The automatic access to the quiz is not enabled in Moodle.';
$string['ssosettings'] = 'FAI CRM: automatic access to the quiz';
$string['studentrolenotfound'] = 'The role with shortname "student" was not found.';
$string['tentativaemandamento'] = 'The candidate has an attempt in progress.';
$string['tentativajainiciada'] = 'The candidate has already started the extra attempt.';
