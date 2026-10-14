# Contrato da API — Integração CRM ↔ Moodle (Vestibular FAI)

API para o CRM **cadastrar candidatos**, **matriculá-los no curso do Vestibular**, **consultar nota, conclusão e datas da prova**, **liberar uma nova tentativa da prova** (recaptação) e **levar o candidato já logado à prova** (acesso automático).

Referência formal: [`openapi.yaml`](openapi.yaml) (OpenAPI 3.0).

**Versão do contrato:** 1.9.1, de 13/10/2026 (plugin `local_faicrm` 1.9.1). Para o CRM, a 1.9.1 é igual à 1.9.0.

---

## 0. O que mudou nesta versão — o que o CRM precisa fazer

**Novidade:** a **operação 4.10**, de acesso automático à prova (SSO). O candidato clica em **"Realizar minha prova"** no CRM e abre a prova no Moodle **já logado**, sem senha e sem segundo login.

**As operações 4.1 a 4.9 não mudaram:** mesmos endereços, parâmetros, respostas e erros, com o mesmo `MOODLE_API_TOKEN`. Nada do que já funciona precisa ser alterado.

### ⚠️ Diferença em relação ao que estava previsto
O CRM foi preparado para chamar `auth_userkey_request_login_url` em `/webservice/rest/server.php`, ler `loginurl` e acrescentar `wantsurl`. **Não use essa função.** Ela gera link de login para **qualquer** usuário do Moodle, inclusive administradores, e o token não pode ser restringido por IP, porque o CRM não tem IP fixo. Por segurança, a FAI deixa essa função **desligada**.

No lugar dela, use a **`local_faicrm_gerar_link_prova`** (4.10). Ela só gera link para **candidatos** matriculados nas provas dos cursos de vestibular liberados pela FAI. As diferenças:

| | Previsto antes (não usar) | Agora (usar) |
|---|---|---|
| Função | `auth_userkey_request_login_url` | **`local_faicrm_gerar_link_prova`** |
| Endereço | `/webservice/rest/server.php` (formulário) | **`/local/faicrm/rest_json.php/local_faicrm_gerar_link_prova`** (JSON, como as outras operações) |
| Autenticação | `wstoken` no corpo | header **`Authorization: Bearer {MOODLE_SSO_TOKEN}`** |
| Corpo | `user[username]=<CPF>` | **`{"username": "<CPF>", "cmid": <id de acesso da prova>}`** |
| Resposta | `{"loginurl": "..."}` | **`{"url": "...", "expiraem": "..."}`** |
| Destino | o CRM acrescentava `&wantsurl=...` | **já vem no link**: não acrescente nada |

### Pré-requisitos do lado da FAI (não são tarefa do CRM)
Antes de o SSO funcionar, a FAI configura no Moodle:
- o plugin `local_faicrm` 1.9.1 e o `auth_userkey`;
- o **curso de cada vestibular liberado para o acesso automático** (em "Cursos liberados" ou "Categorias liberadas");
- a conta técnica `ws_sso` e o token, que a FAI entrega ao CRM.

Sem o curso liberado, a 4.10 responde `403` "Esta prova não está liberada para o acesso automático.". Sem a configuração, responde `503` "O acesso automático à prova não está habilitado no Moodle.". Nos dois casos o problema é de configuração no Moodle, **não do CRM**: avise a equipe da FAI.

**A cada vestibular novo:** a FAI libera o curso novo no Moodle e informa o `cmid` da prova nova. O CRM só passa a usar esse `cmid`.

### Checklist de implementação no CRM
1. **Segredo novo `MOODLE_SSO_TOKEN`**, enviado à parte por um canal seguro. Guarde-o só no backend.
   - Ele é **diferente** do `MOODLE_API_TOKEN`, que continua igual.
   - Cada token só funciona nas suas operações: o do SSO só chama a 4.10, e o da API não chama a 4.10. Usar o token errado dá `403`.
   - O token vale **1 ano**. Quando vencer, a 4.10 responde `401`, e a FAI envia um token novo.
2. **Configuração do `cmid` da prova**, o id de acesso. No Vestibular 2027.1 é **`22`**.
   - O `cmid` também vem na operação 4.7, campo `cmid`.
   - Não confunda com o `quizid` (`id`, usado na 4.8 e na 4.9).
   - Guarde os ids **por vestibular** (`courseid`, `quizid` e `cmid`), porque cada vestibular tem os seus. Em um vestibular novo, o CRM só configura o `cmid` da prova nova, depois que a FAI liberar o curso (veja os pré-requisitos).
3. **No clique de "Realizar minha prova"**, o **backend** do CRM:
   1. chama a 4.10 com o CPF do candidato logado no CRM (o mesmo `username` do cadastro, só dígitos) e o `cmid`;
   2. com `200`, responde ao navegador com **redirecionamento `302` para `url`**, enviando `Cache-Control: no-store` e `Referrer-Policy: no-referrer`;
   3. com erro, mostra a mensagem ao candidato (tabela da 4.10) e **não redireciona**.

   - **O candidato precisa estar cadastrado e matriculado antes** (fluxo de inscrição, 4.1 a 4.3). Senão, a 4.10 responde `404` ou `409`.
   - **Se o front-end for uma SPA** e não puder receber o `302`, o backend devolve a `url` ao front-end. O front-end navega **na hora** com `window.location.replace(url)`, sem guardá-la em estado, `localStorage` nem log. Não use `fetch` ou XHR para abrir a `url`, porque a sessão do Moodle precisa ser criada no navegador.
   - **Pode abrir na mesma aba ou numa aba nova.** Os dois funcionam.
   - **Timeout de 10 s** na chamada à 4.10. Em `500` ou `503`, tente **uma vez** de novo depois de alguns segundos. Em `4xx`, não repita: mostre a mensagem.
   - **O botão pode ficar sempre visível.** Se o candidato já tiver usado todas as tentativas ou a prova estiver fechada, a página da prova no Moodle informa isso. Para esconder ou trocar o texto do botão, use o `podefazerprova` dos resultados (4.5).
4. **Gere um link novo a cada clique.** Não reaproveite nem guarde links:
   - o link vale **2 minutos**;
   - funciona **uma vez só**;
   - cada link novo invalida o anterior.
5. **Não grave a `url` nem a resposta da 4.10 em log**, banco ou ferramenta de monitoramento. Durante a validade, o link equivale a uma senha. Também não o envie por e-mail, SMS ou WhatsApp.
6. **Não envie o IP do candidato.** O fluxo não precisa dele.
7. **Teste de aceite** com o candidato piloto, depois que a FAI avisar que o Moodle está configurado:
   - clicar em "Realizar minha prova" → abre a prova já logado;
   - voltar e clicar de novo → abre de novo, porque o CRM gerou um link novo;
   - abrir uma `url` antiga (já usada) → o Moodle mostra "Este link de acesso já foi usado ou expirou…".

Se o computador já estiver logado no Moodle com outra pessoa (computador compartilhado), o Moodle troca para o candidato certo sozinho. O CRM não precisa fazer nada.

---

## 1. Configuração

| Item | Valor |
|---|---|
| URL base | `https://vestibular.faifaculdade.com.br` |
| Endpoint | `POST {URL base}/local/faicrm/rest_json.php/{operação}` |
| Token das operações 4.1 a 4.9 | fornecido à parte (`MOODLE_API_TOKEN`) |
| Token do acesso automático (só a 4.10) | fornecido à parte (`MOODLE_SSO_TOKEN`) |
| `courseid` do Vestibular | fornecido à parte |
| `quizid` da prova | fornecido à parte |
| `cmid` da prova (acesso automático, 4.10) | `22` no Vestibular 2027.1 (ou pela 4.7) |

Exemplo completo:

```http
POST https://vestibular.faifaculdade.com.br/local/faicrm/rest_json.php/local_faicrm_get_resultados_vestibular
Authorization: Bearer {TOKEN}
Content-Type: application/json

{ "courseid": 10, "pagina": 1 }
```

> Nos exemplos deste documento, `10` é o id do curso, `25` o id da prova e `1548` o id de um candidato. Use os valores reais.

**`quizid` × `cmid`:** o Moodle identifica a mesma prova por dois números.

| Número | O que identifica | Onde usar |
|---|---|---|
| `quizid` | a **prova em si**: questões, nota e tentativas | 4.8 e 4.9 |
| `cmid` | a **página da prova dentro do curso**: o número depois de `view.php?id=` no endereço da prova | 4.10 |

No Vestibular 2027.1, o `quizid` é `11` e o `cmid` é `22`. Os dois vêm na operação 4.7 (campos `id` e `cmid`).

---

## 2. Regras gerais

- **Método:** sempre `POST`. Qualquer outro método → `405`.
- **Autenticação:** header `Authorization: Bearer {TOKEN}`, com o `MOODLE_API_TOKEN` nas operações 4.1 a 4.9 e o `MOODLE_SSO_TOKEN` na 4.10. Os tokens ficam **só no backend do CRM**; nunca no navegador nem no app do candidato.
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
| `403` | Operação ou papel não permitido, token de outra operação, ou chamada de um IP não autorizado. Na 4.10, também prova ou conta não liberada para o acesso automático | Não repetir; verificar a configuração ou mostrar a `message` |
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
| 4.10 | `local_faicrm_gerar_link_prova` | Link de acesso automático à prova (**token do SSO**) |

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
| `id` | id da **prova em si** (`quizid`): questões, nota e tentativas. Usado nas operações 4.8 e 4.9 |
| `cmid` | id da **página da prova no curso** (*course module id*), o número que aparece no endereço `…/mod/quiz/view.php?id={cmid}`. Usado na operação 4.10 |
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

### 4.10 Link de acesso automático à prova — `local_faicrm_gerar_link_prova`

Gera um **link de uso único** que leva o candidato à prova **já logado**, sem senha. Vale para as provas dos cursos de vestibular que a FAI liberou no Moodle. Para um vestibular novo, a FAI libera o curso novo no Moodle e o CRM só usa o `cmid` da prova nova. Use o token **`MOODLE_SSO_TOKEN`**: o `MOODLE_API_TOKEN` não chama esta operação, e o token do SSO não chama as outras.

```json
{ "username": "12345678900", "cmid": 22 }
```

| Campo | Tipo | Obrigatório | Regra |
|---|---|---|---|
| `username` | texto | sim | CPF do candidato (o mesmo `username` do cadastro) |
| `cmid` | inteiro | sim | id de acesso da prova (o `cmid` da 4.7) |

**200**
```json
{
  "url": "https://vestibular.faifaculdade.com.br/local/faicrm/entrar.php?key=…&cmid=22",
  "expiraem": "2026-10-13T10:02:00-03:00"
}
```

Redirecione o navegador do candidato para `url` **na hora**. O link vale até `expiraem` (2 minutos) e **uma vez só**. O destino (a prova) já vem no link, então não acrescente nada a ele.

Erros, na ordem em que são verificados:

| Status | `message` | Causa | O que o CRM faz |
|---|---|---|---|
| `401` | Token de acesso inválido ou ausente. | `MOODLE_SSO_TOKEN` errado ou vencido | Alertar a equipe técnica. Ao candidato: "Não foi possível abrir a prova agora. Tente novamente mais tarde." |
| `403` | Operação não permitida para esta integração. | Usou o `MOODLE_API_TOKEN` (ou outro token) | Corrigir a configuração (usar o `MOODLE_SSO_TOKEN`) |
| `503` | O acesso automático à prova não está habilitado no Moodle. | Configuração pendente na FAI | Alertar a equipe técnica; mostrar a `message` |
| `404` | Prova não encontrada. | `cmid` errado ou prova excluída | Conferir o `cmid` configurado |
| `403` | Esta prova não está liberada para o acesso automático. | O curso da prova não foi liberado pela FAI para o acesso automático (ex.: vestibular novo ainda não liberado) | Alertar a equipe técnica da FAI |
| `404` | Candidato não encontrado. | CPF sem cadastro no Moodle | Cadastrar e matricular (4.2, 4.3) e tentar de novo |
| `403` | Este usuário não pode entrar na prova pelo acesso automático. | Conta de equipe, administrador, ou aluno de curso que não é de vestibular | Mostrar a `message`; o candidato procura a FAI |
| `409` | O candidato está suspenso ou com cadastro incompleto no Moodle. | Conta suspensa no Moodle | Mostrar a `message`; o candidato procura a FAI |
| `409` | O candidato não está matriculado neste curso. | Sem matrícula ativa no curso da prova | Matricular (4.3) e tentar de novo, se ele tiver direito |
| `503` | Serviço temporariamente indisponível. Tente novamente mais tarde. | Instabilidade passageira | Tentar de novo depois de alguns segundos |

Erros **depois** do redirecionamento aparecem na tela do Moodle, não na API. Exemplo: o link aberto depois de 2 minutos ou pela segunda vez mostra "Este link de acesso já foi usado ou expirou. Volte ao CRM e clique de novo em "Realizar minha prova"." O candidato só precisa voltar ao CRM e clicar de novo.

Também funciona pelo servidor REST nativo do Moodle (`/webservice/rest/server.php`, `wsfunction=local_faicrm_gerar_link_prova`, `moodlewsrestformat=json`), com os mesmos parâmetros. Mesmo assim, **use o `rest_json.php`**: ele devolve os erros acima em português e não deixa a resposta em cache.

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

### Acesso automático à prova ("Realizar minha prova")
1. O candidato clica em "Realizar minha prova" no CRM.
2. O **backend** do CRM chama a 4.10 com o CPF dele e o `cmid` da prova, usando o `MOODLE_SSO_TOKEN`.
3. O CRM responde ao navegador com um **redirecionamento** (`302`) para `url`, com `Cache-Control: no-store` e `Referrer-Policy: no-referrer`. Numa SPA, o front-end navega na hora com `window.location.replace(url)`.
4. O Moodle loga o candidato e abre a prova.

Cuidados: o link equivale a uma senha por 2 minutos.
- Não grave a `url` nem a resposta em log.
- Não envie o link por e-mail ou WhatsApp.
- Gere um link novo a cada clique, porque cada link novo invalida o anterior.
