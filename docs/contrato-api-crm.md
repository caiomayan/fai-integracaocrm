# Contrato da API — Integração CRM ↔ Moodle (Vestibular FAI)

API para o CRM **cadastrar candidatos**, **matriculá-los no curso do Vestibular**, **consultar nota, conclusão e datas da prova** e **liberar uma nova tentativa da prova** (recaptação).

Referência formal: [`openapi.yaml`](openapi.yaml) (OpenAPI 3.0).

---

## 1. Configuração

| Item | Valor |
|---|---|
| URL base | `https://vestibular.faifaculdade.com.br` |
| Endpoint | `POST {URL base}/local/faicrm/rest_json.php/{operação}` |
| Token | fornecido à parte |
| `courseid` do Vestibular | fornecido à parte |
| `quizid` da prova | fornecido à parte |

Exemplo completo:

```http
POST https://vestibular.faifaculdade.com.br/local/faicrm/rest_json.php/local_faicrm_get_resultados_vestibular
Authorization: Bearer {TOKEN}
Content-Type: application/json

{ "courseid": 10, "pagina": 1 }
```

> Nos exemplos deste documento, `10` é o id do curso, `25` o id da prova e `1548` o id de um candidato. Use os valores reais.

---

## 2. Regras gerais

- **Método:** sempre `POST`. Qualquer outro método → `405`.
- **Autenticação:** header `Authorization: Bearer {TOKEN}`. O token fica **só no backend do CRM**; nunca no navegador nem no app do candidato.
- **Corpo:** `Content-Type: application/json`, sempre um **objeto** JSON (`{}` quando a operação não tem parâmetros). Lista, texto ou número na raiz → `400`. Limite de 1 MB → acima disso `413`.
- **Respostas:** sempre JSON, em UTF-8. Sucesso sem conteúdo → `204` (corpo vazio).
- **Datas:** ISO 8601 com fuso (`2026-10-07T14:32:10-03:00`) ou `null`. Filtros de data recebem só a data (`AAAA-MM-DD`, horário de Brasília).
- **Rastreio:** toda resposta traz o header `X-Request-Id`. Registre-o nos logs do CRM e informe-o ao suporte quando houver problema.
- **Textos:** nomes de curso, prova e candidato vêm como texto livre do Moodle. **Escape-os** antes de inserir em HTML.

## 3. Erros

Todo erro tem o mesmo formato, com a mensagem em português, pronta para exibir ao usuário:

```json
{ "message": "Já existe um candidato com este e-mail." }
```

| Status | Significado | O que o CRM deve fazer |
|---|---|---|
| `400` | Dado inválido (campo faltando, formato errado, senha fraca, JSON inválido) | Corrigir o pedido; mostrar a `message` |
| `401` | Token ausente ou inválido | Verificar a configuração do token |
| `403` | Operação ou papel não permitido, ou chamada de um IP não autorizado | Não repetir; verificar a configuração |
| `404` | Curso, prova, candidato, matrícula ou nova tentativa não encontrados | Tratar como "não existe" |
| `405` | Método diferente de POST | Corrigir o pedido |
| `409` | Conflito com o estado atual (já existe, já matriculado, ainda pode fazer a prova…) | Ver a regra de cada operação |
| `413` | Corpo maior que 1 MB | Reduzir o pedido |
| `500` / `503` | Erro interno / serviço temporariamente indisponível | Tentar de novo depois, com intervalo crescente |

---

## 4. Operações

| # | Operação | Para quê |
|---|---|---|
| 4.1 | `core_user_get_users_by_field` | Localizar candidato |
| 4.2 | `core_user_create_users` | Criar candidato |
| 4.3 | `enrol_manual_enrol_users` | Matricular no curso |
| 4.4 | `enrol_manual_unenrol_users` | Desmatricular |
| 4.5 | `local_faicrm_get_resultados_vestibular` | Resultados (nota, conclusão, datas, tentativas) |
| 4.6 | `local_faicrm_listar_cursos` | Listar cursos |
| 4.7 | `local_faicrm_listar_provas` | Listar as provas de um curso |
| 4.8 | `local_faicrm_liberar_nova_tentativa` | Liberar nova tentativa (recaptação) |
| 4.9 | `local_faicrm_cancelar_nova_tentativa` | Cancelar a nova tentativa |

### 4.1 Localizar candidato — `core_user_get_users_by_field`

```json
{ "field": "username", "values": ["12345678900"] }
```

| Campo | Tipo | Obrigatório | Regra |
|---|---|---|---|
| `field` | texto | sim | `username` (CPF), `idnumber` ou `id` |
| `values` | lista de texto | sim | um ou mais valores a procurar |

**200**: lista dos encontrados; `[]` se não existe.
```json
[{ "id": 1548, "username": "12345678900", "idnumber": "12345678900", "firstname": "Maria", "lastname": "da Silva", "fullname": "Maria da Silva", "auth": "manual", "suspended": false }]
```
A busca por e-mail não é suportada. O e-mail não vem na resposta.

### 4.2 Criar candidato — `core_user_create_users`

```json
{
  "users": [{
    "username": "12345678900",
    "password": "Vestibular@2027",
    "firstname": "Maria",
    "lastname": "da Silva",
    "email": "maria@email.com",
    "auth": "manual",
    "idnumber": "12345678900"
  }]
}
```

| Campo | Tipo | Obrigatório | Regra |
|---|---|---|---|
| `username` | texto | sim | único; minúsculo; use o **CPF só com dígitos** |
| `password` | texto | sim | precisa atender à política de senha: **mínimo 8 caracteres, com maiúscula, minúscula, número e símbolo**. O CPF puro é recusado |
| `firstname`, `lastname` | texto | sim | nome e sobrenome |
| `email` | texto | sim | e-mail válido e **único** entre as contas |
| `auth` | texto | não | `manual` |
| `idnumber` | texto | não | identificador externo; recomendado: o CPF |

**200**: guarde o `id` no CRM (`moodle_user_id`).
```json
[{ "id": 1548, "username": "12345678900" }]
```

| Erro | `message` |
|---|---|
| `409` | Já existe um candidato com este usuário. |
| `409` | Já existe um candidato com este e-mail. |
| `400` | A senha não atende à política de senhas do Moodle. |
| `400` | Dados inválidos: o campo {campo} é obrigatório. |

### 4.3 Matricular no curso — `enrol_manual_enrol_users`

```json
{ "enrolments": [{ "userid": 1548, "courseid": 10 }] }
```

| Campo | Tipo | Obrigatório | Regra |
|---|---|---|---|
| `enrolments[].userid` | inteiro | sim | id do candidato |
| `enrolments[].courseid` | inteiro | sim | id do curso |
| `enrolments[].roleid` | inteiro | não | padrão: Estudante (`5`); outro papel → `403` |

**204**: matriculado.

| Erro | `message` |
|---|---|
| `409` | O candidato já está matriculado neste curso. |
| `403` | Não é permitido matricular com este papel. |
| `404` | Curso não encontrado. / Candidato não encontrado. |

- O `409` só ocorre se ele já tem uma matrícula ativa. Uma matrícula suspensa é reativada (`204`).
- Com vários itens, vale **tudo ou nada**: se um falhar, nenhum é matriculado.

### 4.4 Desmatricular — `enrol_manual_unenrol_users`

```json
{ "enrolments": [{ "userid": 1548, "courseid": 10 }] }
```
**204**: desmatriculado. · **404** "O candidato não está matriculado neste curso."

O histórico de provas é mantido. Se o candidato for matriculado de novo, `tentativas`, `concluido` e `dataprova` voltam como estavam, mas `nota` volta `null`.

### 4.5 Resultados do vestibular — `local_faicrm_get_resultados_vestibular`

```json
{ "courseid": 10, "pagina": 1, "dataprovade": "2026-10-01", "dataprovaate": "2026-10-31" }
```

| Campo | Tipo | Obrigatório | Regra |
|---|---|---|---|
| `courseid` | inteiro | sim | id do curso |
| `pagina` | inteiro | não | começa em 1 (padrão 1). **100 candidatos por página**, fixo |
| `dataprovade` | texto | não | `AAAA-MM-DD`: só quem fez a prova a partir desse dia (00:00:00) |
| `dataprovaate` | texto | não | `AAAA-MM-DD`: só quem fez a prova até esse dia (23:59:59) |

**200**
```json
{
  "total": 1234, "pagina": 1, "porpagina": 100, "totalpaginas": 13,
  "candidatos": [{
    "username": "12345678900", "firstname": "Maria", "lastname": "da Silva",
    "email": "maria@email.com", "courseid": 10,
    "nota": 760, "concluido": true,
    "datamatricula": "2026-10-01T09:15:00-03:00",
    "dataprova": "2026-10-07T14:32:10-03:00",
    "dataconclusao": "2026-10-07T14:32:10-03:00",
    "tentativas": 1, "podefazerprova": false
  }]
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `total` | inteiro | candidatos encontrados (com os filtros aplicados) |
| `totalpaginas` | inteiro | `0` quando não há candidatos |
| `nota` | número ou `null` | nota da prova, de 0 a 1000; `null` se ainda não fez |
| `concluido` | booleano | `true` quando o Moodle marcou o curso como concluído (pode levar alguns minutos depois da prova) |
| `datamatricula` | data ou `null` | quando foi matriculado |
| `dataprova` | data ou `null` | quando finalizou a prova (última tentativa finalizada) |
| `dataconclusao` | data ou `null` | quando concluiu |
| `tentativas` | inteiro | quantas tentativas da prova finalizou |
| `podefazerprova` | booleano | `true` se pode iniciar ou continuar uma tentativa agora |

- Para ler todos os candidatos, chame `pagina` de 1 até `totalpaginas`. Uma página além da última traz `candidatos: []`.
- Erros: `400` para `pagina` menor que 1, data em formato inválido, `dataprovade` depois de `dataprovaate`, ou envio de `porpagina`. `404` "Curso não encontrado."

### 4.6 Listar cursos — `local_faicrm_listar_cursos`

```json
{ "pagina": 1, "visivel": true }
```

| Campo | Tipo | Obrigatório | Regra |
|---|---|---|---|
| `pagina` | inteiro | não | padrão 1; 100 cursos por página |
| `visivel` | booleano | não | `true`: só visíveis; `false`: só ocultos; sem o campo: todos |

**200**
```json
{
  "total": 2, "pagina": 1, "porpagina": 100, "totalpaginas": 1,
  "cursos": [{
    "id": 10, "shortname": "VEST20271", "nome": "Vestibular 2027.1",
    "categoriaid": 1, "categoria": "Vestibular",
    "visivel": true, "datainicio": "2026-09-01T00:00:00-03:00", "datafim": null
  }]
}
```
Ordem: nome, id. Um curso está **ativo** quando `visivel: true` e a data atual está entre `datainicio` e `datafim` (`null` = sem limite).

### 4.7 Listar as provas de um curso — `local_faicrm_listar_provas`

```json
{ "courseid": 10 }
```

**200**
```json
{
  "courseid": 10,
  "provas": [{
    "id": 25, "cmid": 87, "nome": "Prova Vestibular 2027.1", "visivel": true,
    "notamaxima": 1000, "tentativaspermitidas": 1, "metodonota": "maior",
    "abertura": null, "fechamento": null
  }]
}
```

| Campo | Significado |
|---|---|
| `id` | id da prova (`quizid`, usado nas operações 4.8 e 4.9) |
| `tentativaspermitidas` | `0` = ilimitado |
| `metodonota` | como a nota é calculada com várias tentativas: `maior`, `media`, `primeira` ou `ultima` |
| `abertura` / `fechamento` | data ou `null` |

**404** "Curso não encontrado."

### 4.8 Liberar nova tentativa — `local_faicrm_liberar_nova_tentativa`

Dá **uma tentativa a mais da prova para um candidato específico**. Os outros candidatos não são afetados. As tentativas anteriores ficam no histórico, e a nota segue o `metodonota` da prova (com `maior`, refazer nunca piora a nota).

```json
{ "quizid": 25, "userid": 1548, "prazo": "2026-12-31" }
```

| Campo | Tipo | Obrigatório | Regra |
|---|---|---|---|
| `quizid` | inteiro | sim | id da prova |
| `userid` | inteiro | sim | id do candidato |
| `prazo` | texto | não | `AAAA-MM-DD`: a nova tentativa vale até 23:59:59 desse dia, só para esse candidato |

**200**
```json
{ "quizid": 25, "userid": 1548, "tentativaspermitidas": 2, "prazo": "2026-12-31T23:59:59-03:00" }
```

Erros, na ordem em que são verificados:

| Status | `message` |
|---|---|
| `404` | Prova não encontrada. / Candidato não encontrado. |
| `409` | O candidato não está matriculado neste curso. |
| `409` | O candidato tem uma tentativa em andamento. |
| `409` | O candidato ainda pode fazer a prova. |
| `400` | Dados inválidos: prazo deve estar no formato AAAA-MM-DD. / Dados inválidos: prazo não pode estar no passado. |
| `400` | A prova está encerrada: informe um prazo para a nova tentativa. |
| `503` | Serviço temporariamente indisponível. Tente novamente mais tarde. (outra chamada para o mesmo candidato e prova estava em andamento) |

### 4.9 Cancelar nova tentativa — `local_faicrm_cancelar_nova_tentativa`

Desfaz a última liberação enquanto o candidato ainda não a usou.

```json
{ "quizid": 25, "userid": 1548 }
```

**204**: cancelada. A configuração do candidato volta a ser a de antes da liberação.

| Status | `message` |
|---|---|
| `404` | Não há nova tentativa liberada para este candidato. |
| `409` | O candidato já iniciou a nova tentativa. |
| `503` | Serviço temporariamente indisponível. Tente novamente mais tarde. |

---

## 5. Fluxos

### Inscrição de um candidato
1. **Localizar** pelo CPF (4.1, `field: "username"`).
2. Se vier `[]`, **criar** (4.2) e guardar o `id`. Se vier um candidato, usar o `id` dele.
3. **Matricular** (4.3) com o `id` e o `courseid`. Um `409` "já está matriculado" pode ser tratado como sucesso.
4. Guardar no CRM: `moodle_user_id`, `moodle_course_id`, `moodle_quiz_id` e o status da matrícula.

### Acompanhamento dos resultados
- Consultar **sob demanda** ou em **intervalos** (ex.: a cada 10–15 minutos), nunca em loop contínuo.
- Percorrer as páginas de 1 até `totalpaginas`.
- Para sincronizar só as provas recentes, usar `dataprovade` / `dataprovaate`.

### Recaptação (refazer a prova)
1. Nos resultados, `tentativas ≥ 1` com `podefazerprova: false` indica quem já fez a prova e não pode refazer.
2. **Liberar a nova tentativa** (4.8) para o candidato escolhido, com `prazo` se a oferta tiver validade.
3. Avisar o candidato pelos canais do CRM.
4. Ele refaz a prova no Moodle. Nos resultados, `tentativas` aumenta e `nota` é atualizada conforme o `metodonota`.
5. Se a oferta for cancelada antes de ele começar: **cancelar** (4.9).

Um `409` "ainda pode fazer a prova" ou "tentativa em andamento" significa que não é preciso liberar.
