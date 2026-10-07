# Contrato da API — Integração CRM ↔ Moodle (Vestibular FAI)

Resumo para quem vai integrar o CRM. Referência completa: [`openapi.yaml`](openapi.yaml) (abre no Swagger, Postman ou Bruno).

## Visão geral

```
CRM ──▶ localiza/cria o candidato ──▶ matricula no curso do Vestibular ──▶ candidato faz a prova no Moodle
 ▲                                                                                │
 └───────────────────────── consulta nota, conclusão e datas ◀───────────────────┘
```

## Como chamar

| Item | Valor |
|---|---|
| URL | `POST {baseUrl}/local/faicrm/rest_json.php/{operação}` |
| `baseUrl` | local: `http://localhost:8080` · produção: **a definir com a FAI** |
| Autenticação | header `Authorization: Bearer {token}` (token só no backend do CRM, nunca no navegador) |
| Corpo | `Content-Type: application/json`, sempre um **objeto** JSON |
| Método | **sempre POST**; outro método devolve 405 |

A operação também pode ir na query: `.../rest_json.php?wsfunction={operação}`.

## Respostas e erros

| Status | Quando |
|---|---|
| **200** | sucesso, com corpo JSON |
| **204** | sucesso sem corpo (matricular e desmatricular) |
| 400 | dado inválido (campo faltando, formato errado, JSON inválido) |
| 401 | token ausente ou inválido |
| 403 | operação ou papel não permitido para a integração |
| 404 | curso, candidato ou matrícula não encontrados |
| 405 | método diferente de POST |
| 409 | conflito: candidato ou e-mail já existe, ou candidato já matriculado |
| 413 | corpo maior que 1 MB |
| 500 / 503 | erro interno / serviço indisponível (tentar de novo depois) |

Todo erro tem o mesmo corpo, com a mensagem em português, pronta para exibir:

```json
{ "message": "Já existe um candidato com este e-mail." }
```

Toda resposta traz o header `X-Request-Id`. Informe esse valor ao suporte para localizar a chamada no log.

---

## 1. Localizar candidato — `core_user_get_users_by_field`

```json
{ "field": "username", "values": ["12345678900"] }
```
`field` pode ser `username`, `email`, `idnumber` ou `id`.

**200**: lista dos encontrados; `[]` se não existe. Vêm também outros campos nativos (`fullname`, `suspended`, `lang`…). O e-mail não vem na resposta, mas dá para buscar por ele.
```json
[{ "id": 72, "username": "12345678900", "idnumber": "12345678900", "firstname": "Maria", "lastname": "da Silva", "auth": "manual", "suspended": false }]
```

## 2. Criar candidato — `core_user_create_users`

```json
{
  "users": [{
    "username": "12345678900",
    "password": "12345678900",
    "firstname": "Maria",
    "lastname": "da Silva",
    "email": "maria@email.com",
    "auth": "manual",
    "idnumber": "12345678900"
  }]
}
```

| Campo | Obrigatório | Regra |
|---|---|---|
| `username` | sim | único, minúsculo (CPF só com dígitos funciona) |
| `password` | sim | precisa atender à política de senha do Moodle da FAI |
| `firstname`, `lastname` | sim | |
| `email` | sim | único entre as contas |
| `auth` | não | `manual` |
| `idnumber` | não | identificador externo (ex.: o CPF) |

**200**: o `id` gerado pelo Moodle. **Guarde no CRM** como `moodle_user_id`.
```json
[{ "id": 72, "username": "12345678900" }]
```
**409** se o username ou o e-mail já existem.

## 3. Matricular no curso — `enrol_manual_enrol_users`

```json
{ "enrolments": [{ "userid": 72, "courseid": 2 }] }
```
`roleid` é opcional; o padrão é Estudante. A integração só pode matricular como Estudante: outro papel devolve 403.

**204**: matriculado, sem corpo.
**409** "O candidato já está matriculado neste curso." Com vários itens, vale tudo ou nada: se um falhar, nenhum é matriculado.

## 4. Desmatricular — `enrol_manual_unenrol_users`

```json
{ "enrolments": [{ "userid": 72, "courseid": 2 }] }
```
**204**: desmatriculado. **404** "O candidato não está matriculado neste curso."

## 5. Resultados do vestibular — `local_faicrm_get_resultados_vestibular`

```json
{ "courseid": 2, "pagina": 1, "dataprovade": "2026-10-01", "dataprovaate": "2026-10-07" }
```

| Campo | Obrigatório | Regra |
|---|---|---|
| `courseid` | sim | id do curso do Vestibular |
| `pagina` | não | começa em 1 (padrão 1) |
| `dataprovade`, `dataprovaate` | não | só data, `AAAA-MM-DD` (horário de Brasília); filtra pela data da prova |

São **100 candidatos por página**, um valor fixo. Mandar `porpagina` devolve 400.

**200**
```json
{
  "total": 1234, "pagina": 1, "porpagina": 100, "totalpaginas": 13,
  "candidatos": [{
    "username": "12345678900", "firstname": "Maria", "lastname": "da Silva",
    "email": "maria@email.com", "courseid": 2,
    "nota": 760, "concluido": true,
    "datamatricula": "2026-10-01T09:15:00-03:00",
    "dataprova": "2026-10-07T14:32:10-03:00",
    "dataconclusao": "2026-10-07T14:32:10-03:00"
  }]
}
```

| Campo | Significado |
|---|---|
| `nota` | 0 a 1000; `null` se ainda não fez a prova |
| `concluido` | `true` quando o Moodle marcou o curso como concluído |
| `datamatricula` | quando foi matriculado |
| `dataprova` | quando finalizou a prova (última tentativa finalizada); `null` se não fez |
| `dataconclusao` | quando concluiu; `null` se não concluiu |

As datas vêm em ISO 8601 com fuso. Para ler todos os candidatos, chame `pagina` de 1 até `totalpaginas`.

## Consultas auxiliares (nativas do Moodle)

Elas devolvem o formato nativo do Moodle. Os detalhes estão no `openapi.yaml`.

| Operação | Corpo | Para quê |
|---|---|---|
| `core_course_get_courses_by_field` | `{"field":"shortname","value":"VEST20271"}` | descobrir o `courseid` |
| `mod_quiz_get_quizzes_by_courses` | `{"courseids":[2]}` | descobrir o id da prova (`moodle_quiz_id`) |
| `mod_quiz_get_user_attempts` | `{"quizid":1,"userid":72,"status":"all"}` | tentativas do candidato |
| `core_completion_get_course_completion_status` | `{"courseid":2,"userid":72}` | conclusão de um candidato |
| `gradereport_user_get_grade_items` | `{"courseid":2,"userid":72}` | notas detalhadas de um candidato |
| `core_enrol_get_enrolled_users` | `{"courseid":2}` | matriculados no curso |

---

## Fluxo recomendado para o CRM

1. **Localizar** pelo `username`. Se vier `[]`, **criar** e guardar o `id`. Se vier um candidato, usar o `id` dele.
2. **Matricular** com `userid` + `courseid`. Um **409** significa que ele já estava matriculado: pode tratar como sucesso.
3. Guardar no CRM: `crm_candidate_id`, `moodle_user_id`, `moodle_course_id`, `moodle_quiz_id` e o status da matrícula.
4. **Consultar os resultados** quando precisar (sob demanda ou a cada X minutos, não em loop), percorrendo as páginas.

## A confirmar com a FAI antes da produção

- `baseUrl` e token de produção.
- `courseid` do Vestibular 2027.1 e id da prova.
- Política de senha (senha = CPF pode ser recusada).
- Se o servidor repassa o header `Authorization` ao PHP.
