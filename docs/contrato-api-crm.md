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
| **204** | sucesso sem corpo (matricular, desmatricular e cancelar nova tentativa) |
| 400 | dado inválido (campo faltando, formato errado, JSON inválido) |
| 401 | token ausente ou inválido |
| 403 | operação ou papel não permitido para a integração, ou chamada de um IP não liberado |
| 404 | curso, prova, candidato, matrícula ou nova tentativa não encontrados |
| 405 | método diferente de POST |
| 409 | conflito: candidato ou e-mail já existe; candidato já matriculado (ou não matriculado, na recaptação); candidato ainda pode fazer a prova, tem tentativa em andamento ou já iniciou a nova tentativa |
| 413 | corpo maior que 1 MB |
| 500 / 503 | erro interno / serviço indisponível (tentar de novo depois) |

Todo erro tem o mesmo corpo, com a mensagem em português, pronta para exibir:

```json
{ "message": "Já existe um candidato com este e-mail." }
```

Toda resposta traz o header `X-Request-Id`. Informe esse valor ao suporte para localizar a chamada no log.

> **Ao exibir no front:** nomes de curso, de prova e de candidato vêm como texto do Moodle. **Escape** esses textos antes de inserir no HTML.

---

## 1. Localizar candidato — `core_user_get_users_by_field`

```json
{ "field": "username", "values": ["12345678900"] }
```
`field` pode ser `username`, `idnumber` ou `id`. A busca por `email` **não é suportada**: responde `[]` mesmo que a conta exista (o usuário técnico não tem permissão para ver e-mails, por privacidade). O CRM localiza pelo **CPF** (`username` ou `idnumber`) ou pelo `id`.

**200**: lista dos encontrados; `[]` se não existe. Vêm também outros campos nativos (`fullname`, `suspended`, `lang`…); o `email` **não** vem. A exceção é nativa do Moodle: uma conta configurada para "mostrar o e-mail a todos" tem o e-mail visível para qualquer usuário logado, inclusive para a integração.
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
**409** "O candidato já está matriculado neste curso.": só quando ele já tem uma matrícula **manual ativa**. Uma matrícula manual **suspensa** é reativada (204), e uma matrícula por **outro método** (ex.: autoinscrição) não bloqueia: a matrícula manual é criada (204). Com vários itens, vale tudo ou nada: se um falhar, nenhum é matriculado.

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
    "dataconclusao": "2026-10-07T14:32:10-03:00",
    "tentativas": 1, "podefazerprova": false
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
| `tentativas` | quantas tentativas da prova o candidato **finalizou** |
| `podefazerprova` | `true` se ele pode iniciar ou continuar uma tentativa agora (matriculado, prova visível e aberta, tentativa disponível ou em andamento) |

As datas vêm em ISO 8601 com fuso. Para ler todos os candidatos, chame `pagina` de 1 até `totalpaginas`.

> **Rematrícula:** se um candidato for desmatriculado e matriculado de novo, o Moodle mantém a conclusão e a tentativa antigas. Ele pode voltar com `concluido: true` e `dataprova` preenchida, mas com `nota: null`. Esse é o comportamento nativo do Moodle.

## 6. Listar cursos — `local_faicrm_listar_cursos`

```json
{ "pagina": 1, "visivel": true }
```
`pagina` (padrão 1) e `visivel` (opcional: `true` só os visíveis, `false` só os ocultos; sem ele, todos) são opcionais. São 100 cursos por página; a página inicial do Moodle não entra.

**200**
```json
{
  "total": 2, "pagina": 1, "porpagina": 100, "totalpaginas": 1,
  "cursos": [{
    "id": 2, "shortname": "VEST20271", "nome": "Vestibular 2027.1",
    "categoriaid": 1, "categoria": "Categoria 1",
    "visivel": true, "datainicio": null, "datafim": null
  }]
}
```
Ordem: nome, id. Curso **ativo** = `visivel: true` e dentro de `datainicio`/`datafim` (as datas vêm em ISO 8601 com fuso, ou `null`).

## 7. Listar as provas de um curso — `local_faicrm_listar_provas`

```json
{ "courseid": 2 }
```
**200**
```json
{
  "courseid": 2,
  "provas": [{
    "id": 1, "cmid": 2, "nome": "Prova Vestibular 2027.1", "visivel": true,
    "notamaxima": 1000, "tentativaspermitidas": 1, "metodonota": "maior",
    "abertura": null, "fechamento": null
  }]
}
```
`tentativaspermitidas`: 0 = ilimitado. `metodonota`: `maior`, `media`, `primeira` ou `ultima` (como a nota do candidato é calculada com várias tentativas). **404** "Curso não encontrado."

## 8. Liberar nova tentativa — `local_faicrm_liberar_nova_tentativa`

Recaptação: dá **uma tentativa a mais da prova para um candidato específico**, sem mexer nos outros. Nada é apagado: as tentativas antigas ficam no histórico, e a nota segue a regra de nota da prova (com "maior nota", refazer nunca piora).

```json
{ "quizid": 1, "userid": 72, "prazo": "2026-12-31" }
```
`prazo` é opcional (`AAAA-MM-DD`, vale até 23:59:59 de Brasília, só para esse candidato).

**200**
```json
{ "quizid": 1, "userid": 72, "tentativaspermitidas": 2, "prazo": "2026-12-31T23:59:59-03:00" }
```

Erros, nesta ordem de verificação:

| Status | message |
|---|---|
| 404 | Prova não encontrada. / Candidato não encontrado. |
| 409 | O candidato não está matriculado neste curso. |
| 409 | O candidato tem uma tentativa em andamento. |
| 409 | O candidato ainda pode fazer a prova. |
| 400 | Dados inválidos: prazo deve estar no formato AAAA-MM-DD. / Dados inválidos: prazo não pode estar no passado. |
| 400 | A prova está encerrada: informe um prazo para a nova tentativa. |
| 503 | Serviço temporariamente indisponível. Tente novamente mais tarde. (outra chamada para o mesmo candidato e prova estava em andamento; tente de novo) |

## 9. Cancelar nova tentativa — `local_faicrm_cancelar_nova_tentativa`

Desfaz a liberação enquanto o candidato ainda não usou a tentativa extra.

```json
{ "quizid": 1, "userid": 72 }
```
**204**: cancelada, sem corpo.
**404** "Não há nova tentativa liberada para este candidato." · **409** "O candidato já iniciou a nova tentativa." · **503** se outra chamada para o mesmo candidato estiver em andamento (tente de novo).

O cancelamento devolve a exceção ao estado de antes da liberação: tempo extra, senha ou prazo que a FAI tenha dado ao candidato são mantidos.

## Consultas auxiliares (nativas do Moodle)

Elas devolvem o formato nativo do Moodle. Os detalhes estão no `openapi.yaml`.

| Operação | Corpo | Para quê |
|---|---|---|
| `core_course_get_courses_by_field` | `{"field":"shortname","value":"VEST20271"}` | descobrir o `courseid` (para listar todos, use a seção 6) |
| `mod_quiz_get_quizzes_by_courses` | `{"courseids":[2]}` | descobrir o id da prova (para uma lista enxuta, use a seção 7) |
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
5. **Recaptação** (refazer a prova): nos resultados, `tentativas ≥ 1` e `podefazerprova: false` indicam quem já fez e não pode refazer. **Liberar a nova tentativa** (seção 8) para aquele candidato; avisá-lo pelos canais do CRM; ele refaz a prova no Moodle; os resultados mostram `tentativas` e `nota` atualizados. Se mudar de ideia antes de ele começar, **cancelar** (seção 9). Um 409 "ainda pode fazer" ou "tentativa em andamento" significa que não é preciso liberar.

## A confirmar com a FAI antes da produção

- `baseUrl` e token de produção.
- `courseid` do Vestibular 2027.1 e id da prova.
- Política de senha (senha = CPF pode ser recusada).
- Se o servidor repassa o header `Authorization` ao PHP.
- Se a FAI usa exceções de **grupo** nas provas: o `podefazerprova` dos resultados considera só as exceções de candidato (as que a recaptação cria).
- O CRM deve chamar exatamente a URL oficial do Moodle (`wwwroot`): com outro host, o Moodle redireciona (303) com uma página HTML em vez de JSON.
- Se o token tiver restrição de IP, as chamadas precisam sair do IP liberado (senão: 403).
