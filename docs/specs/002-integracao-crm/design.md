# 002 — Design

## Visão geral
```
Bruno / CRM ──POST JSON──▶ /local/faicrm/rest_json.php?wsfunction=X   (Authorization: Bearer <token>)
                                │ adaptador: JSON → parâmetros nativos, formato de resposta = json
                                ▼
                      /webservice/rest/server.php  (servidor REST nativo: token, serviço, permissões, validação)
                                                  │ serviço "CRM Vestibular FAI" (crm_vestibular_fai)
         nativas: core_user_* / enrol_manual_* / core_completion_* / gradereport_user_* / mod_quiz_*
         própria: local_faicrm_get_resultados_vestibular  (plugin local_faicrm, somente leitura)
```

## Decisões
- **D-01** Cadastro/matrícula nativos; o CRM orquestra: localizar → (criar se não existir) → matricular com o `id` retornado.
- **D-02** Resultados por função agregadora própria (não existe função nativa única; a nativa de completion é 1 usuário por chamada).
- **D-03** `nota` = Total do curso (`grade_get_course_grades`), não a nota do quiz: não depende do id do quiz e segue válida se a FAI adicionar itens.
- **D-04** O serviço é declarado em `db/services.php` do plugin (versionado e reproduzível). No Moodle da FAI basta instalar o plugin, autorizar o usuário e gerar o token.
- **D-05** O servidor REST nativo do Moodle **só lê form-urlencoded** (`$_GET`/`$_POST`, ver `webservice/rest/locallib.php`). Por isso existe o adaptador JSON (D-08); o endpoint nativo segue disponível.
- **D-08** Adaptador JSON no plugin (`local/faicrm/rest_json.php`): decodifica o corpo JSON, injeta em `$_POST` junto com `wstoken` (do header Bearer), `wsfunction` (da query) e `moodlewsrestformat=json`, e entrega ao servidor REST nativo (`webservice/rest/server.php`). Sem lógica de negócio, sem reimplementar segurança. Escolhido em vez do plugin de comunidade `webservice_restjson` para não adicionar dependência de terceiros.
- **D-06** Local: `passwordpolicy=0` (senha = CPF numérico). Validar a política da FAI (pendência externa).
- **D-07** Ambiente configurado por `docker/moodle/setup.php` idempotente, rodado pelo entrypoint a cada subida.

## Contrato — `local_faicrm_get_resultados_vestibular`
- Classe `local_faicrm\external\get_resultados_vestibular` (estende `core_external\external_api`), método `execute`, `type => read`.
- Capabilities exigidas (no contexto do curso): `moodle/grade:viewall`, `moodle/course:viewparticipants`.
- Parâmetro: `courseid` (PARAM_INT, obrigatório).
- Candidatos = usuários com papel `student` (shortname) no contexto do curso, ordenados por `lastname, firstname`.
- Retorno `external_multiple_structure` de:

| campo | tipo | observação |
|---|---|---|
| username | PARAM_RAW | |
| firstname | PARAM_NOTAGS | |
| lastname | PARAM_NOTAGS | |
| email | PARAM_RAW | |
| courseid | PARAM_INT | |
| nota | PARAM_FLOAT, `NULL_ALLOWED` | Total do curso, valor bruto; `null` sem nota |
| concluido | PARAM_BOOL | `completion_completion->is_complete()` |

Exemplo (JSON da resposta REST):
```json
[{"username":"12345678900","firstname":"Maria","lastname":"da Silva","email":"maria@email.com","courseid":2,"nota":760,"concluido":true}]
```

### Atualização RF-11: paginação (substitui a entrada e a saída acima)
- Parâmetros: `courseid` (PARAM_INT, obrigatório), `pagina` (PARAM_INT, `VALUE_DEFAULT` 1), `porpagina` (PARAM_INT, `VALUE_DEFAULT` 100, máximo 500). Valores fora da faixa → `invalid_parameter_exception`; o adaptador traduz para 400 com message em PT (ex.: "Dados inválidos: porpagina deve estar entre 1 e 500.").
- Consulta: `count_role_users` (ou SQL equivalente) para o `total`; `get_role_users(..., sort 'u.lastname, u.firstname, u.id', limitfrom = (pagina-1)*porpagina, limitnum = porpagina)` para a página.
- Nota: `grade_get_course_grades($courseid, <ids da página>)`.
- Conclusão: **uma** consulta em `course_completions` (`course = :c AND userid IN (...) AND timecompleted IS NOT NULL`) para a página; não instanciar `completion_completion` por candidato.
- Retorno (`external_single_structure`):
```json
{"total": 1234, "pagina": 2, "porpagina": 100, "totalpaginas": 13,
 "candidatos": [{"username":"…","firstname":"…","lastname":"…","email":"…","courseid":2,"nota":760,"concluido":true}]}
```
- `totalpaginas = ceil(total / porpagina)` (0 quando `total = 0`). Página além da última → `candidatos: []`.

### Atualização RF-12: datas e filtro por data da prova
- Novos campos em cada item de `candidatos` (PARAM_RAW, `NULL_ALLOWED`):
  - `datamatricula`: menor `user_enrolments.timecreated` do usuário nas instâncias de inscrição do curso;
  - `dataprova`: maior `quiz_attempts.timefinish` com `state = 'finished'` e `preview = 0`, em questionários do curso;
  - `dataconclusao`: `course_completions.timecompleted`.
- Formatação: timestamp → `DateTime` no fuso do servidor (`core_date::get_server_timezone_object()`, America/Sao_Paulo) → `format('c')`; `0`/`null` → `null`.
- Novos parâmetros opcionais `dataprovade` e `dataprovaate` (PARAM_RAW, `VALUE_DEFAULT ''`). Parse estrito: **só** `AAAA-MM-DD` (regex + checkdate), no fuso do servidor; `de` → 00:00:00 e `ate` → 23:59:59 do dia. Valor com hora → 400 (decisão do Caio, 07/10). Inválido ou `de` > `ate` → `invalid_parameter_exception` → 400 no adaptador ("Dados inválidos: dataprovade deve estar no formato AAAA-MM-DD." / "Dados inválidos: dataprovade não pode ser posterior a dataprovaate.").
- Com filtro, a condição sobre a **última** tentativa finalizada (a mesma da `dataprova` devolvida) entra **no SQL** do `total` e da página: mesmo FROM/WHERE nos dois, para manter a consistência do RF-11 (`count_role_users` não aceita condição extra, então use SQL próprio com `DISTINCT u.id`).
- As datas da página saem em lote (uma consulta por tipo de data para os ids da página), sem consulta por candidato.

## Serviço "CRM Vestibular FAI"
`db/services.php` → `$services['CRM Vestibular FAI']`: `shortname=crm_vestibular_fai`, `enabled=1`, `restrictedusers=1`, `downloadfiles=0`, `uploadfiles=0`, funções:
`core_user_create_users, core_user_get_users_by_field, enrol_manual_enrol_users, enrol_manual_unenrol_users, core_course_get_courses_by_field, core_enrol_get_enrolled_users, mod_quiz_get_quizzes_by_courses, mod_quiz_get_user_attempts, core_completion_get_course_completion_status, gradereport_user_get_grade_items, local_faicrm_get_resultados_vestibular`.

## Usuário técnico e papel
- Usuário `ws_crm` (auth manual, senha aleatória, e-mail `ws_crm@localhost.local`).
- Papel de sistema `integracaocrm` ("Integração CRM"), atribuído a `ws_crm` no contexto de sistema, com:
  `webservice/rest:use, moodle/user:create, moodle/user:viewdetails, moodle/user:viewalldetails, moodle/user:viewhiddendetails, moodle/course:view, moodle/course:viewhiddencourses, moodle/course:viewparticipants, moodle/course:enrolreview, enrol/manual:enrol, enrol/manual:unenrol, moodle/role:assign, moodle/grade:viewall, gradereport/user:view, moodle/course:viewhiddenactivities, mod/quiz:view, mod/quiz:viewreports, report/completion:view, moodle/site:accessallgroups`.
  Permitir que `integracaocrm` atribua `student` (`core_role_set_assign_allowed`).
- Autorizado no serviço (`external_services_users`); token permanente gerado com `\core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $userid, context_system::instance())` — reaproveita o existente se houver.

## Dados de teste (setup)
- Curso **Vestibular 2027.1** (shortname `VEST20271`), `enablecompletion=1`, inscrição manual ativa (padrão).
- Questionário **Prova Vestibular 2027.1**: `grade=1000`, 5 questões de múltipla escolha (uma correta cada) → cada acerto vale 200. Conclusão de atividade automática ao receber nota (`completion=2, completionusegrade=1`).
- Critério de conclusão do curso: conclusão do questionário (`COMPLETION_CRITERIA_TYPE_ACTIVITY`).
- Saída em `output/` (bind mount `./output:/opt/fai/output`): `token.txt`, `ids.json` (`{"courseid":…, "quizid":…, "cmid":…, "serviceid":…}`).

## Arquivos
| caminho | conteúdo |
|---|---|
| `moodle-plugin/local/faicrm/` | `version.php`, `lang/en/local_faicrm.php`, `db/services.php`, `classes/external/get_resultados_vestibular.php` |
| `docker/moodle/setup.php` | configuração idempotente (CLI_SCRIPT) |
| `docker/moodle/simular_prova.php` | `--username=X --acertos=N` (0–5): cria e envia tentativa real do quiz (generator do mod_quiz) e roda a agregação de conclusão |
| `docker/moodle/entrypoint.sh` | após install/upgrade: `php /opt/fai/setup.php` |
| `docker-compose.yml` | mounts `./moodle-plugin/local/faicrm:/var/www/html/local/faicrm` e `./output:/opt/fai/output` (moodle e cron) |
| `moodle-plugin/local/faicrm/rest_json.php` | adaptador JSON (D-08) |
| `bruno/` | coleção Bruno (env `local`) |

## Contrato — adaptador JSON `rest_json.php`
- `POST {baseUrl}/local/faicrm/rest_json.php?wsfunction=<função>`
- Headers: `Content-Type: application/json`, `Authorization: Bearer <token>`.
- Corpo: objeto JSON com os parâmetros da função, idêntico aos exemplos do cliente (ex.: `{"users":[{"username":"…","password":"…","firstname":"…","lastname":"…","email":"…","auth":"manual","idnumber":"…"}]}`). Corpo vazio ou `{}` = sem parâmetros.
- Chaves reservadas `wstoken`, `wsfunction`, `moodlewsrestformat` no corpo são **descartadas** (token só pelo header, função só pela query).
- **roleid padrão (RF-08)**: só para `wsfunction=enrol_manual_enrol_users`, itens de `enrolments` sem `roleid` recebem o id do papel `student` (consulta `role.shortname='student'`). Para isso o adaptador carrega o `config.php` e executa ele mesmo o servidor nativo (`new webservice_rest_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN)->run()`, como o `webservice/rest/server.php` faz), em vez de `chdir` + `require` do server.php.
- **204 (RF-09)**: se o corpo nativo de sucesso for `null`, a resposta é HTTP 204 sem corpo (via buffer de saída).
- Resposta: `application/json`; sucesso = exatamente o retorno nativo (exceto `null` → 204); erro = `{"message"}` + status HTTP (RF-10, ver abaixo).
- Erros do próprio adaptador (mesmo formato RF-10): `invalidjson` (400), `missingwsfunction` (400), `invalidwsfunction` (400), `methodnotallowed` (405), `payloadtoolarge` (413), `protocoldisabled` (503). Sem header Bearer → segue para o nativo, que recusa o token (401).
- O endpoint nativo form-urlencoded continua funcionando (compatibilidade).

## Contrato — erros do adaptador (RF-10)
Como funciona (T14): o adaptador usa uma subclasse do servidor nativo (`local_faicrm_rest_json_server extends webservice_rest_server`) que só sobrescreve `send_error()` (recebe a **exceção original**, com `errorcode` e `debuginfo`, independente do nível de debug do site) e marca a fase de `authenticate_user()`. Autenticação, permissões, validação e execução continuam nativas. Falhas antes do servidor (setup do Moodle, `raise_early_ws_exception`) e erros fatais do PHP (shutdown) usam o mesmo formato. Errorcodes confirmados no Moodle 4.5.14:

| Situação | errorcode / origem (Moodle 4.5) | HTTP | message |
|---|---|---|---|
| Corpo não é objeto JSON (ou profundidade > 32) | (adaptador) invalidjson | 400 | "O corpo da requisição deve ser um objeto JSON válido." |
| Falta `?wsfunction=` | (adaptador) missingwsfunction | 400 | "Informe a operação desejada (parâmetro wsfunction)." |
| `wsfunction` fora de `^[a-z][a-z0-9_]{0,199}$` | (adaptador) invalidwsfunction | 400 | "Operação inválida: verifique o parâmetro wsfunction." |
| Método ≠ POST | (adaptador) methodnotallowed | 405 | "Método não permitido. Use POST." |
| Corpo > 1 MiB | (adaptador) payloadtoolarge | 413 | "O corpo da requisição é grande demais (máximo de 1 MB)." |
| Token ausente/inválido | invalidtoken | 401 | "Token de acesso inválido ou ausente." |
| Token expirado | accessexception (debuginfo "Invalid token - token expired") | 401 | idem |
| Função fora do serviço / sem capability | accessexception, nopermissions, requireloginerror, restrictedcontextexception, servicerequireslogin | 403 | "Operação não permitida para esta integração." |
| Usuário técnico suspenso / IP / sem `webservice/rest:use` (fase de autenticação) | wsaccessusersuspended, wsaccessusernologin, accessexception, … | 403 | idem |
| Papel não permitido | wsusercannotassign | 403 | "Não é permitido matricular com este papel." |
| Username duplicado | invalidparameter (debuginfo "Username already exists: …") | 409 | "Já existe um candidato com este usuário." |
| E-mail duplicado | invalidparameter (debuginfo "Email address already exists: …") | 409 | "Já existe um candidato com este e-mail." |
| Campo obrigatório ausente | invalidparameter (debuginfo "…Missing required key in single structure: X") | 400 | "Dados inválidos: o campo X é obrigatório." |
| Tipo/valor errado | invalidparameter (debuginfo "X => …: <detalhe>") | 400 | "Dados inválidos: verifique o campo X." |
| Campo em branco | invalidparameter ("The field X cannot be blank") | 400 | "Dados inválidos: o campo X não pode ficar em branco." |
| Campo não reconhecido | invalidparameter ("Unexpected keys (…)") | 400 | "Dados inválidos: há campos não reconhecidos em X." |
| E-mail inválido / auth / lang / theme / senha ausente | invalidparameter (debuginfo específico) | 400 | "Dados inválidos: e-mail inválido." / "…verifique o campo X." / "…o campo senha é obrigatório." |
| Username com maiúsculas / caracteres inválidos | usernamelowercase, invalidusername | 400 | "Dados inválidos: o usuário …" |
| Senha fora da política | moodle_exception com errorcode = texto da política (`<div>…`) | 400 | "A senha não atende à política de senhas do Moodle." |
| Matricular o guest | guestsarenotallowed | 400 | "Dados inválidos: este usuário não pode ser matriculado." |
| Curso inexistente | dml_missing_record_exception tabela course (invalidrecord); invalidparameter "Context does not exist" (enrol); errorcoursecontextnotvalid; invalidcourseid | 404 | "Curso não encontrado." |
| Candidato inexistente/excluído | dml_missing_record_exception tabela user (invaliduser); userdeleted | 404 | "Candidato não encontrado." |
| Prova inexistente | dml_missing_record_exception tabela quiz | 404 | "Prova não encontrada." |
| Função inexistente no Moodle | dml_missing_record_exception tabela external_functions | 404 | "Operação não encontrada: verifique o parâmetro wsfunction." |
| Curso sem inscrição manual | wsnoinstance, wscannotenrol, wscannotunenrol | 409 | "O curso não aceita matrícula manual no momento." |
| Candidato suspenso/não confirmado | suspended, usernotconfirmed | 409 | "O candidato está suspenso ou com cadastro incompleto no Moodle." |
| Web services/REST desabilitado | (adaptador) protocoldisabled | 503 | "Serviço temporariamente indisponível. Tente novamente mais tarde." |
| Serviço "CRM Vestibular FAI" desabilitado | accessexception + `external_services.enabled = 0` | 503 | idem |
| Manutenção / banco fora | sitemaintenance, dbconnectionfailed | 503 | idem |
| Qualquer outro / falha interna / erro fatal do PHP | * (coding_exception, dml_write_exception, invalidresponse, fatalerror…) | 500 | "Erro interno. Tente novamente mais tarde." |

- Resposta de erro: só `{"message": …}`, `Content-Type: application/json; charset=utf-8`, header `X-Request-Id`.
- Log: `error_log` (uma linha JSON, prefixo `local_faicrm rest_json`) com requestid, status, wsfunction, errorcode, exception, message e debuginfo (+ arquivo:linha só nos 5xx). **Nunca** loga o token nem a senha: nos campos `message`, `debuginfo` e `where`, os valores do token e de toda chave `password` do corpo (ocorrência delimitada, ou qualquer ocorrência se tiver 8+ caracteres) e hashes de senha são trocados por `[omitido]`; `requestid`, `wsfunction`, `errorcode` e `exception` nunca são alterados (correlação).
- Headers de segurança (em **todas** as respostas do adaptador, inclusive sucesso): `X-Request-Id`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`; sem `Access-Control-Allow-Origin` (o token só é usado no backend, RNF-04) e sem `X-Powered-By`. 401 traz `WWW-Authenticate: Bearer`; 405 traz `Allow: POST`.
- O endpoint nativo `/webservice/rest/server.php` **não** muda (CA-10).

## Coleção Bruno
**(Atualizado — RF-07)** Todas as requisições usam o adaptador: `POST {{baseUrl}}/local/faicrm/rest_json.php?wsfunction=…`, `auth:bearer { token: {{token}} }`, `body:json`. Sem `wstoken`/`moodlewsrestformat` no corpo. Acrescentar `98-json-invalido.bru`. A descrição abaixo (form-urlencoded) vale só para o endpoint nativo.

`POST {{baseUrl}}/webservice/rest/server.php`, body `form-urlencoded` com `wstoken={{token}}`, `wsfunction=…`, `moodlewsrestformat=json`.
Env `local`: `baseUrl=http://localhost:8080`, `token`, `courseid`, `roleid=5`, `username=12345678900`, `password`, `firstname`, `lastname`, `email`, `userid` (preenchido por script).
Requisições: 01 Localizar · 02 Criar (post-response salva `userid`) · 03 Matricular · 04 Resultados · 05 Completion nativo · 06 Notas nativo · 07 Desmatricular · 99 Token inválido.
