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
POST {baseUrl}/local/faicrm/rest_json.php/<função>
Content-Type: application/json
Authorization: Bearer <token>
```

Local: `baseUrl = http://localhost:8080`. A operação vai **no caminho** (`/local/faicrm/rest_json.php/core_user_create_users`); a forma antiga `?wsfunction=<função>` na query continua valendo. Se as duas vierem, têm de ser iguais (senão 400). O contrato completo, com exemplos, está em [`docs/openapi.yaml`](openapi.yaml) (OpenAPI 3.0). O corpo é um objeto JSON com os parâmetros da função, **no mesmo formato dos exemplos do cliente**. Corpo vazio ou `{}` = sem parâmetros. A resposta é sempre JSON.

O adaptador (`local/faicrm/rest_json.php`) só converte o corpo e delega ao servidor REST nativo do Moodle: autenticação, permissões e validação são as nativas. As chaves `wstoken`, `wsfunction` e `moodlewsrestformat` no corpo são ignoradas (token só pelo header, função só pelo caminho ou pela query).

Exemplo (criar candidato):
```bash
curl -X POST "http://localhost:8080/local/faicrm/rest_json.php/core_user_create_users" \
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
Guarde o `id`. Criar de novo o mesmo `username` devolve **HTTP 409** (`Já existe um candidato com este usuário.`), sem duplicar. Por isso o CRM deve localizar antes de criar.

> **E-mail também precisa ser único.** Com a configuração padrão do Moodle (`allowaccountssameemail = 0`), criar um candidato com um e-mail que outra conta já usa também devolve **HTTP 409** (`Já existe um candidato com este e-mail.`), mesmo com `username` diferente. Verificado no ambiente local. Confirmar a configuração no Moodle da FAI.

### 3. Matricular — `enrol_manual_enrol_users`
Corpo (números como número). O `roleid` é **opcional**: sem ele, o adaptador usa o papel `student` (procurado pelo shortname, não fixo em 5):
```json
{"enrolments": [{"userid": 123, "courseid": 2}]}
```
Com `roleid` explícito (5 = Estudante no Moodle padrão), ele é respeitado:
```json
{"enrolments": [{"roleid": 5, "userid": 123, "courseid": 2}]}
```
O usuário técnico só pode atribuir o papel Estudante: outro `roleid` devolve **HTTP 403** (`Não é permitido matricular com este papel.`). Itens com e sem `roleid` podem ser misturados na mesma chamada.

**Quem já está matriculado:** matricular um candidato que já tem **matrícula manual ativa** no curso devolve **HTTP 409** (`O candidato já está matriculado neste curso.`). Matrícula manual **suspensa** é reativada pela função nativa (204), e matrícula por **outro método** (autoinscrição etc.) não bloqueia: a manual é criada (204). Com vários itens vale **tudo ou nada**: se um conflitar, nenhum é matriculado. A checagem vem depois da autenticação e antes da validação do papel.

Resposta de sucesso: **HTTP 204**, sem corpo.

### 4. Resultados — `local_faicrm_get_resultados_vestibular`
Corpo: `{"courseid": 2, "pagina": 1}` (mais os filtros de data da prova, opcionais). Devolve os candidatos (estudantes) do curso **paginados, 100 por página**, ordenados por sobrenome, nome e id (ordem estável).

| parâmetro | obrigatório | padrão | regra |
|---|---|---|---|
| `courseid` | sim | | id do curso |
| `pagina` | não | 1 | maior ou igual a 1 |
| `dataprovade` | não | | só candidatos com prova a partir deste dia (`AAAA-MM-DD`, 00:00:00) |
| `dataprovaate` | não | | só candidatos com prova até este dia (`AAAA-MM-DD`, até 23:59:59) |

```json
{"total": 1234, "pagina": 1, "porpagina": 100, "totalpaginas": 13,
 "candidatos": [{"username":"12345678900","firstname":"Maria","lastname":"da Silva","email":"maria@email.com","courseid":2,"nota":760,"concluido":true,
                  "datamatricula":"2026-10-06T11:00:36-03:00","dataprova":"2026-10-07T09:42:53-03:00","dataconclusao":"2026-10-07T09:42:53-03:00"}]}
```
- `total`: candidatos do curso; `totalpaginas = ceil(total / 100)` (0 se não há candidatos). `porpagina` vem sempre `100` na resposta e **não é um parâmetro**: enviá-lo devolve HTTP 400 (`Dados inválidos: porpagina não é aceito; o serviço usa 100 por página.`).
- Página além da última: `candidatos: []`, com os metadados corretos. Curso sem candidatos: `total: 0, totalpaginas: 0, candidatos: []`.
- `pagina` menor que 1: HTTP 400 com `{"message": "Dados inválidos: pagina deve ser maior ou igual a 1."}`.
- Logo após a matrícula: `"nota": null, "concluido": false`.

**Filtro por data da prova.** `dataprova` é o horário em que a **última tentativa finalizada** do questionário terminou. Com `dataprovade` e/ou `dataprovaate`, só entram candidatos com `dataprova` dentro do intervalo (inclusive); quem ainda não fez a prova fica de fora, e `total` e `totalpaginas` já respeitam o filtro.
- Formato: **somente data**, `AAAA-MM-DD`, no fuso America/Sao_Paulo. `dataprovade` vale a partir de 00:00:00 do dia e `dataprovaate` até 23:59:59 do dia (os dois dias entram). Hora no filtro não é aceita.
- Um dia exato: `dataprovade` e `dataprovaate` iguais, por exemplo `{"courseid": 2, "dataprovade": "2026-10-07", "dataprovaate": "2026-10-07"}`.
- Formato inválido (ex.: `07/10/2026`, `2026-02-30`, ou com hora como `2026-10-07T10:00`): HTTP 400, `{"message": "Dados inválidos: dataprovade deve estar no formato AAAA-MM-DD."}` (o mesmo para `dataprovaate`). `dataprovade` posterior a `dataprovaate`: HTTP 400, `{"message": "Dados inválidos: dataprovade não pode ser posterior a dataprovaate."}`.
- Texto vazio (`""`) equivale a não filtrar.

**Como percorrer todas as páginas:** comece em `pagina = 1` e repita até `pagina = totalpaginas` (ou até `candidatos` vir vazio). A ordem é estável, então cada candidato aparece uma única vez.

Campos de cada item de `candidatos`:

| campo | tipo | observação |
|---|---|---|
| username | texto | |
| firstname | texto | |
| lastname | texto | |
| email | texto | |
| courseid | inteiro | |
| nota | número ou `null` | Total do curso no gradebook, valor bruto; `null` se ainda sem nota |
| concluido | booleano | conclusão do curso (course completion) |
| datamatricula | texto ISO 8601 ou `null` | primeira matrícula do candidato no curso |
| dataprova | texto ISO 8601 ou `null` | fim da última tentativa finalizada do questionário; `null` se ainda não fez a prova |
| dataconclusao | texto ISO 8601 ou `null` | quando o curso foi concluído; `null` se não concluiu |

Na **resposta**, as datas trazem dia, hora e fuso do servidor, por exemplo `2026-10-07T14:32:00-03:00`.

A conclusão depende do cron do Moodle; pode levar alguns minutos após a prova.

### 5. Desmatricular — `enrol_manual_unenrol_users`
Corpo: `{"enrolments": [{"userid": 123, "courseid": 2}]}` (`roleid` opcional). Resposta de sucesso: **HTTP 204**, sem corpo.

Desmatricular quem **não tem matrícula manual** no curso devolve **HTTP 404** (`O candidato não está matriculado neste curso.`). Também vale tudo ou nada.

### Funções nativas auxiliares
`core_completion_get_course_completion_status` (`courseid`, `userid`) e `gradereport_user_get_grade_items` (`courseid`, `userid` opcional) permitem conferir conclusão e notas. Também estão no serviço: `core_course_get_courses_by_field`, `core_enrol_get_enrolled_users`, `mod_quiz_get_quizzes_by_courses`, `mod_quiz_get_user_attempts`.

## Erros

Quando a função não devolve dados (matricular e desmatricular), o adaptador responde **HTTP 204 No Content**, sem corpo e sem `Content-Type`; trate 204 como sucesso. Funções com dados respondem 200 com JSON.

Todo erro responde com um **status HTTP de erro** (4xx/5xx) e um corpo com **apenas** a mensagem, em português e pronta para exibir ao usuário final:
```json
{"message": "Já existe um candidato com este usuário."}
```
Detalhes técnicos (exceção, código interno, SQL, caminhos) **não** vão na resposta: ficam no log do servidor Moodle. Toda resposta do adaptador traz o header `X-Request-Id`; ao abrir um chamado, informe esse valor para localizar o detalhe no log. O CRM deve decidir pelo **status HTTP**, não pelo texto da mensagem (o texto pode mudar).

| Situação | HTTP | message |
|---|---|---|
| Corpo não é um objeto JSON válido | 400 | O corpo da requisição deve ser um objeto JSON válido. |
| Falta a operação (nem caminho nem `?wsfunction=`) | 400 | Informe a operação desejada (parâmetro wsfunction). |
| Caminho da operação inválido | 400 | Operação inválida: verifique o caminho da requisição. |
| Caminho e `?wsfunction=` diferentes | 400 | Operação inválida: informe wsfunction só no caminho ou só na query. |
| `wsfunction` com caracteres inválidos | 400 | Operação inválida: verifique o parâmetro wsfunction. |
| Campo obrigatório ausente | 400 | Dados inválidos: o campo `<campo>` é obrigatório. |
| Campo com tipo/valor inválido | 400 | Dados inválidos: verifique o campo `<campo>`. |
| Campo obrigatório em branco (username, firstname, lastname) | 400 | Dados inválidos: o campo `<campo>` não pode ficar em branco. |
| Campo não reconhecido no corpo | 400 | Dados inválidos: há campos não reconhecidos em `<campo>`. |
| E-mail em formato inválido | 400 | Dados inválidos: e-mail inválido. |
| Senha fora da política do site | 400 | A senha não atende à política de senhas do Moodle. |
| Outro dado inválido | 400 | Dados inválidos: verifique os dados enviados. |
| Token ausente, inválido ou expirado | 401 | Token de acesso inválido ou ausente. |
| Função fora do serviço, sem permissão, usuário técnico bloqueado | 403 | Operação não permitida para esta integração. |
| Matrícula com papel diferente de Estudante | 403 | Não é permitido matricular com este papel. |
| Curso inexistente | 404 | Curso não encontrado. |
| Desmatricular quem não tem matrícula manual | 404 | O candidato não está matriculado neste curso. |
| Candidato (`userid`) inexistente ou excluído | 404 | Candidato não encontrado. |
| Prova (`quizid`) inexistente | 404 | Prova não encontrada. |
| `wsfunction` que não existe no Moodle | 404 | Operação não encontrada: verifique o parâmetro wsfunction. |
| Método diferente de POST | 405 | Método não permitido. Use POST. |
| Candidato com matrícula manual ativa no curso | 409 | O candidato já está matriculado neste curso. |
| Username já existe | 409 | Já existe um candidato com este usuário. |
| E-mail já existe | 409 | Já existe um candidato com este e-mail. |
| Curso sem inscrição manual ativa | 409 | O curso não aceita matrícula manual no momento. |
| Candidato suspenso ou não confirmado | 409 | O candidato está suspenso ou com cadastro incompleto no Moodle. |
| Corpo maior que 1 MB | 413 | O corpo da requisição é grande demais (máximo de 1 MB). |
| Qualquer outra falha interna | 500 | Erro interno. Tente novamente mais tarde. |
| Web services/REST/serviço desabilitado, manutenção, banco fora do ar | 503 | Serviço temporariamente indisponível. Tente novamente mais tarde. |

`<campo>` aparece com nome amigável: usuário, senha, nome, sobrenome, e-mail, curso, candidato (`userid`), papel (`roleid`), candidatos (`users`), matrículas (`enrolments`) etc.

Headers de toda resposta do adaptador: `X-Request-Id`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`. Não há `Access-Control-Allow-Origin` (CORS): a API é só para o backend do CRM. Respostas 401 trazem `WWW-Authenticate: Bearer`; 405 traz `Allow: POST`.

## Alternativa: endpoint nativo (form-urlencoded)

O endpoint nativo continua funcionando, para quem preferir:
```
POST {baseUrl}/webservice/rest/server.php
Content-Type: application/x-www-form-urlencoded
```
Parâmetros fixos: `wstoken`, `wsfunction`, `moodlewsrestformat=json`. No endpoint nativo a matrícula continua exigindo `roleid`, o sucesso é `200` com corpo `null` e os erros seguem o formato nativo do Moodle (HTTP 200 com `{"exception", "errorcode", "message"}`). Os arrays vão indexados (`users[0][username]=12345678900`, `enrolments[0][roleid]=5`, `values[0]=12345678900`), com colchetes codificados na URL se a biblioteca HTTP não o fizer.

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
