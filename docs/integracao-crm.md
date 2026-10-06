# Integração CRM ↔ Moodle — Vestibular FAI

Guia para o time do CRM e para a equipe da FAI. Detalhes de projeto: `docs/specs/002-integracao-crm/`.

## Fluxo

```
CRM (backend)                                   Moodle
  |-- 1. localizar candidato (username) ------->|  core_user_get_users_by_field
  |-- 2. criar candidato, se não existir ------>|  core_user_create_users      -> devolve id
  |-- 3. matricular no curso (student) ------->|  enrol_manual_enrol_users
  |                                             |  candidato faz a prova (quiz) no Moodle
  |                                             |  Moodle corrige e calcula a conclusão
  |-- 4. consultar resultados do curso -------->|  local_faicrm_get_resultados_vestibular
  |<-- nota + concluido por candidato ----------|
  |-- 5. (opcional) desmatricular ------------->|  enrol_manual_unenrol_users
```

Cadastro e matrícula usam funções nativas do Moodle. Só a consulta de resultados usa código próprio (plugin `local_faicrm`, somente leitura).

## Autenticação

- Cada chamada leva o token permanente do usuário técnico `ws_crm` (serviço "CRM Vestibular FAI") no header `Authorization: Bearer <token>`.
- **O token fica apenas no backend do CRM.** Nunca o envie ao navegador do candidato.
- O serviço expõe somente as funções listadas neste guia (mais algumas de consulta), sem acesso administrativo.

## Endpoint (JSON)

```
POST {baseUrl}/local/faicrm/rest_json.php?wsfunction=<função>
Content-Type: application/json
Authorization: Bearer <token>
```

Local: `baseUrl = http://localhost:8080`. A função vai na query string (`wsfunction`); o corpo é um objeto JSON com os parâmetros da função, **no mesmo formato dos exemplos do cliente**. Corpo vazio ou `{}` = sem parâmetros. A resposta é sempre JSON.

O adaptador (`local/faicrm/rest_json.php`) só converte o corpo e delega ao servidor REST nativo do Moodle: autenticação, permissões e validação são as nativas. As chaves `wstoken`, `wsfunction` e `moodlewsrestformat` no corpo são ignoradas (token só pelo header, função só pela query).

Exemplo (criar candidato):
```bash
curl -X POST "http://localhost:8080/local/faicrm/rest_json.php?wsfunction=core_user_create_users" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"users":[{"username":"12345678900","password":"Senha@123","firstname":"Maria","lastname":"da Silva","email":"maria@email.com","auth":"manual","idnumber":"12345678900"}]}'
```

> **Testando com curl no Windows (Git Bash):** acentos passados direto no argumento (`-d '{"firstname":"João"}'`) podem sair com codificação errada; isso é um problema do shell, não da API. Salve o corpo num arquivo UTF-8 e use `--data-binary @corpo.json`. No Bruno isso não acontece.

## Chamadas

### 1. Localizar candidato — `core_user_get_users_by_field`
Corpo:
```json
{"field": "username", "values": ["12345678900"]}
```
Resposta se existe (campos adicionais omitidos):
```json
[{"id": 123, "username": "12345678900", "firstname": "Maria", "lastname": "da Silva", "email": "maria@email.com"}]
```
Se não existe: `[]`.

### 2. Criar candidato — `core_user_create_users`
Corpo (`idnumber` é opcional; usamos o CPF):
```json
{"users": [{"username": "12345678900", "password": "Senha@123", "firstname": "Maria", "lastname": "da Silva", "email": "maria@email.com", "auth": "manual", "idnumber": "12345678900"}]}
```
Resposta:
```json
[{"id": 123, "username": "12345678900"}]
```
Guarde o `id`. Criar de novo o mesmo `username` devolve erro (`invalidparameter`), sem duplicar. Por isso o CRM deve localizar antes de criar.

> **E-mail também precisa ser único.** Com a configuração padrão do Moodle (`allowaccountssameemail = 0`), criar um candidato com um e-mail que outra conta já usa também devolve `invalidparameter`, mesmo com `username` diferente. Verificado no ambiente local. Confirmar a configuração no Moodle da FAI.

### 3. Matricular — `enrol_manual_enrol_users`
Corpo (números como número). O `roleid` é **opcional**: sem ele, o adaptador usa o papel `student` (procurado pelo shortname, não fixo em 5):
```json
{"enrolments": [{"userid": 123, "courseid": 2}]}
```
Com `roleid` explícito (5 = Estudante no Moodle padrão), ele é respeitado:
```json
{"enrolments": [{"roleid": 5, "userid": 123, "courseid": 2}]}
```
O usuário técnico só pode atribuir o papel Estudante: outro `roleid` devolve `wsusercannotassign`. Itens com e sem `roleid` podem ser misturados na mesma chamada.

Resposta de sucesso: **HTTP 204**, sem corpo.

### 4. Resultados — `local_faicrm_get_resultados_vestibular`
Corpo: `{"courseid": 2}`. Devolve um item por candidato (estudante) do curso, ordenado por sobrenome e nome.
```json
[{"username":"12345678900","firstname":"Maria","lastname":"da Silva","email":"maria@email.com","courseid":2,"nota":760,"concluido":true}]
```
Logo após a matrícula: `"nota": null, "concluido": false`.

| campo | tipo | observação |
|---|---|---|
| username | texto | |
| firstname | texto | |
| lastname | texto | |
| email | texto | |
| courseid | inteiro | |
| nota | número ou `null` | Total do curso no gradebook, valor bruto; `null` se ainda sem nota |
| concluido | booleano | conclusão do curso (course completion) |

A conclusão depende do cron do Moodle; pode levar alguns minutos após a prova.

### 5. Desmatricular — `enrol_manual_unenrol_users`
Corpo: `{"enrolments": [{"userid": 123, "courseid": 2}]}` (`roleid` opcional). Resposta de sucesso: **HTTP 204**, sem corpo.

### Funções nativas auxiliares
`core_completion_get_course_completion_status` (`courseid`, `userid`) e `gradereport_user_get_grade_items` (`courseid`, `userid` opcional) permitem conferir conclusão e notas. Também estão no serviço: `core_course_get_courses_by_field`, `core_enrol_get_enrolled_users`, `mod_quiz_get_quizzes_by_courses`, `mod_quiz_get_user_attempts`.

## Erros

Quando a função não devolve dados (matricular e desmatricular), o adaptador responde **HTTP 204 No Content**, sem corpo e sem `Content-Type`; trate 204 como sucesso. Funções com dados respondem 200 com JSON.

Erros do Moodle seguem o formato nativo, com **HTTP 200** (o CRM deve verificar o campo `exception`):
```json
{"exception": "core\\exception\\moodle_exception", "errorcode": "invalidtoken", "message": "Token inválido - token não encontrado"}
```

| errorcode | significado |
|---|---|
| `invalidtoken` | token incorreto, removido ou ausente (sem header Bearer) |
| `accessexception` | função fora do serviço ou usuário sem permissão |
| `invalidparameter` | parâmetro inválido ou duplicado (ex.: username ou e-mail já existe) |
| `wsusercannotassign` | `roleid` diferente de Estudante (o usuário técnico só atribui Estudante) |
| `invalidrecord` | registro inexistente (ex.: curso) |

Erros do próprio adaptador, no mesmo formato JSON:

| errorcode | HTTP | significado |
|---|---|---|
| `invalidjson` | 400 | corpo não é um objeto JSON válido |
| `missingwsfunction` | 400 | falta `wsfunction` na query string |
| `methodnotallowed` | 405 | método diferente de POST |

## Alternativa: endpoint nativo (form-urlencoded)

O endpoint nativo continua funcionando, para quem preferir:
```
POST {baseUrl}/webservice/rest/server.php
Content-Type: application/x-www-form-urlencoded
```
Parâmetros fixos: `wstoken`, `wsfunction`, `moodlewsrestformat=json`. No endpoint nativo a matrícula continua exigindo `roleid`, e o sucesso é `200` com corpo `null`. Os arrays vão indexados (`users[0][username]=12345678900`, `enrolments[0][roleid]=5`, `values[0]=12345678900`), com colchetes codificados na URL se a biblioteca HTTP não o fizer.

## Implantação no Moodle da FAI

- [ ] Instalar o plugin `local_faicrm` em `local/faicrm` (copiar a pasta `moodle-plugin/local/faicrm`) e executar o upgrade (Administração do site → Notificações).
- [ ] Habilitar web services (Funcionalidades avançadas) e o protocolo **REST** (Servidor → Web services → Gerenciar protocolos).
- [ ] Criar o usuário técnico `ws_crm` e o papel de sistema "Integração CRM" (`integracaocrm`) com as capabilities do design (`docs/specs/002-integracao-crm/design.md`); atribuir o papel ao usuário no contexto de sistema e permitir que ele atribua o papel Estudante.
- [ ] Em Web services → Serviços externos, confirmar o serviço "CRM Vestibular FAI" (criado pelo plugin) e **autorizar** o usuário `ws_crm` nele.
- [ ] Gerar o token permanente do `ws_crm` nesse serviço e entregá-lo ao CRM por canal seguro.
- [ ] Conferir a **política de senha** do site: localmente está desligada para aceitar senha = CPF numérico. Na FAI, a senha enviada na criação precisa atender à política (pendência externa).
- [ ] Preencher o `courseid` real do curso do Vestibular e confirmar: conclusão de curso habilitada, critério de conclusão (a prova), papel Estudante (shortname `student`; o adaptador o localiza sozinho) e cron em execução.
- [ ] Confirmar que `local/faicrm/rest_json.php` responde (o servidor web da FAI deve repassar o header `Authorization`; com Apache/FastCGI pode ser preciso `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`).
- [ ] Testar o fluxo 1 a 4 com um candidato de teste (coleção Bruno em `bruno/`, que usa o adaptador JSON).
