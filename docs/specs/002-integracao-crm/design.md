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
- Resposta: `application/json`; sucesso = exatamente o retorno nativo (exceto `null` → 204); erro = formato nativo `{"exception","errorcode","message"}` (HTTP 200, como o nativo).
- Erros do próprio adaptador (mesmo formato JSON): `invalidjson` (corpo não é objeto JSON válido, HTTP 400), `methodnotallowed` (não-POST, HTTP 405), `missingwsfunction` (sem `wsfunction`, HTTP 400). Sem header Bearer → segue para o nativo, que responde o erro de token em JSON.
- O endpoint nativo form-urlencoded continua funcionando (compatibilidade).

## Coleção Bruno
**(Atualizado — RF-07)** Todas as requisições usam o adaptador: `POST {{baseUrl}}/local/faicrm/rest_json.php?wsfunction=…`, `auth:bearer { token: {{token}} }`, `body:json`. Sem `wstoken`/`moodlewsrestformat` no corpo. Acrescentar `98-json-invalido.bru`. A descrição abaixo (form-urlencoded) vale só para o endpoint nativo.

`POST {{baseUrl}}/webservice/rest/server.php`, body `form-urlencoded` com `wstoken={{token}}`, `wsfunction=…`, `moodlewsrestformat=json`.
Env `local`: `baseUrl=http://localhost:8080`, `token`, `courseid`, `roleid=5`, `username=12345678900`, `password`, `firstname`, `lastname`, `email`, `userid` (preenchido por script).
Requisições: 01 Localizar · 02 Criar (post-response salva `userid`) · 03 Matricular · 04 Resultados · 05 Completion nativo · 06 Notas nativo · 07 Desmatricular · 99 Token inválido.
