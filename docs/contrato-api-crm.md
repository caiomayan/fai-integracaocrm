# Contrato da API — Integração CRM ↔ Moodle (Vestibular FAI)

API para o CRM **cadastrar candidatos**, **matriculá-los no curso do Vestibular**, **consultar nota, conclusão e datas da prova**, **liberar uma nova tentativa da prova** (recaptação), **levar o candidato já logado à prova** (acesso automático) e **recebê-lo de volta quando ele termina a prova** (retorno).

Referência formal: [`openapi.yaml`](openapi.yaml) (OpenAPI 3.0).

**Versão do contrato:** 1.10.1, de 19/10/2026 (plugin `local_faicrm` 1.10.1).

---

## 0. O que mudou nesta versão — o que o CRM precisa fazer

### Novo na 1.10.1
A 1.10.1 junta numa versão só o que já estava pronto (o retorno ao CRM) com as notas da 1.9.2, e acrescenta um filtro opcional na 4.5. **Nada do que o CRM já usa muda:** as operações, os endereços, os campos, os erros e os tokens continuam iguais.

1. **Retorno ao CRM depois da prova** (seção **6**). Quando o candidato que entrou pela 4.10 termina a prova, o Moodle mostra "Prova enviada com sucesso!" e, em 5 segundos, o leva de volta a uma **página do CRM**, com `?status=concluida&cmid=<prova>`.
   - **O que o CRM precisa fazer:** criar essa página e **informar o endereço dela à FAI**, que o configura no Moodle. Veja o checklist da seção 6.
   - Enquanto a FAI não configurar o endereço, nada muda: o candidato continua no Moodle ao terminar a prova, como hoje.
2. **Filtro opcional por CPF na 4.5** (`username`). Em vez de ler a turma inteira, o CRM pode consultar **um candidato só**:
   ```json
   { "courseid": 10, "username": "12345678900" }
   ```
   - A resposta tem o mesmo formato de sempre, com `total` 0 ou 1.
   - **O que o CRM precisa fazer:** nada é obrigatório. **Recomendado:** para acompanhar quem está com a correção pendente (`nota_redacao`/`nota_objetivas` `null`), ou quem acabou de voltar da prova pelo retorno, consulte **por CPF**, só esses candidatos, em vez de ler todas as páginas a cada minuto. Veja "Consultar um candidato só" na 4.5 e "Acompanhamento dos resultados" (seção 5).

### Da 1.9.2: notas da redação e das objetivas nos resultados (4.5)
Cada candidato da operação **4.5** ganha dois campos:
- **`nota_redacao`**: nota da redação (a questão 1 da prova), de **0 a 200**;
- **`nota_objetivas`**: a soma das outras questões, de **0 a 800**.

Com a correção concluída, **`nota_redacao + nota_objetivas = nota`**. Quando a correção está **pendente**, os dois vêm **`null`**, nunca zero.

Nada mais muda: as outras operações, os outros campos da 4.5, os tokens e o SSO continuam iguais. A regra completa e os exemplos estão na **4.5**.

**O que o CRM precisa fazer:** se o CRM já lê `nota_redacao` e `nota_objetivas` da 4.5 com esses nomes, **não precisa mudar nada**. Mesmo assim, confira os pontos do **checklist da 4.5** ("Orientações para o CRM"):
- **redação zerada zera a nota no CRM:** o Moodle não zera a `nota` total, e o CRM considera a nota final 0;
- os valores podem ter decimais;
- `null` é diferente de `0`;
- com `null`, não se decide pela `nota`;
- a 4.5 precisa ser consultada de novo até sair do pendente.

Depois, valide a jornada com o candidato piloto.

### Da 1.9.0: acesso automático à prova (SSO)

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
- o plugin `local_faicrm` (1.9.1 ou mais nova; hoje, 1.10.1) e o `auth_userkey`;
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

```json
{ "courseid": 10, "username": "12345678900" }
```

| Campo | Tipo | Obrigatório | Regra |
|---|---|---|---|
| `courseid` | inteiro | sim | id do curso |
| `pagina` | inteiro | não | começa em 1 (padrão 1). **100 candidatos por página**, fixo |
| `dataprovade` | texto | não | `AAAA-MM-DD`: só quem fez a prova a partir desse dia (00:00:00) |
| `dataprovaate` | texto | não | `AAAA-MM-DD`: só quem fez a prova até esse dia (23:59:59) |
| `username` | texto | não | **CPF** do candidato (o mesmo `username` do cadastro, 4.1/4.2): traz **só ele** (1.10.1). Vazio = todos |

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
    "tentativas": 1, "podefazerprova": false,
    "nota_redacao": 160, "nota_objetivas": 600
  }]
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `total` | inteiro | candidatos encontrados (com os filtros aplicados) |
| `totalpaginas` | inteiro | `0` quando não há candidatos |
| `nota` | número ou `null` | nota da prova, de 0 a 1000; `null` se ainda não fez |
| `nota_redacao` | número ou `null` | nota da **redação**, de 0 a 200 (1.9.2). Veja as regras abaixo |
| `nota_objetivas` | número ou `null` | soma das **objetivas**, de 0 a 800 (1.9.2). Veja as regras abaixo |
| `concluido` | booleano | `true` quando o Moodle marcou o curso como concluído (pode levar alguns minutos depois da prova) |
| `datamatricula` | data ou `null` | quando foi matriculado |
| `dataprova` | data ou `null` | quando finalizou a prova (última tentativa finalizada) |
| `dataconclusao` | data ou `null` | quando concluiu |
| `tentativas` | inteiro | quantas tentativas da prova finalizou |
| `podefazerprova` | booleano | `true` se pode iniciar ou continuar uma tentativa agora |

- Para ler todos os candidatos, chame `pagina` de 1 até `totalpaginas`. Uma página além da última traz `candidatos: []`.
- Erros: `400` para `pagina` menor que 1, data em formato inválido, `dataprovade` depois de `dataprovaate`, `username` com mais de 100 caracteres, ou envio de `porpagina`. `404` "Curso não encontrado."

#### Consultar um candidato só (`username`, 1.10.1)
Envie o CPF do candidato em `username` para receber **só ele**, com todos os campos de sempre (inclusive `nota_redacao` e `nota_objetivas`):
```json
{ "courseid": 10, "username": "12345678900" }
```
- **Mesmo valor do cadastro:** é o `username` usado na 4.1/4.2, **só dígitos**, sem pontos nem traço. A comparação é **exata**: não é busca por parte do CPF. Maiúsculas e espaços nas pontas são ignorados.
- **Encontrou:** `total: 1`, `totalpaginas: 1` e o candidato em `candidatos`.
- **Não encontrou** (CPF não cadastrado, não matriculado como estudante nesse curso, ou conta excluída): **`200`** com `total: 0`, `totalpaginas: 0` e `candidatos: []`. Não é erro. Se o CRM esperava o candidato ali, confira o cadastro e a matrícula (4.1 e 4.3).
- **Combina com as datas:** com `dataprovade`/`dataprovaate`, o candidato só vem se a prova dele estiver no período.
- **Quando usar:** para acompanhar candidatos **específicos**, como quem está com a correção pendente ou quem acabou de voltar da prova pelo retorno (seção 6). Para sincronizar a turma inteira, continue sem `username`, percorrendo as páginas.
- **Uma chamada por candidato.** Não é preciso paginar. Para muitos candidatos de uma vez, prefira a lista completa com `dataprovade`/`dataprovaate`, em vez de centenas de chamadas por CPF.

#### `nota_redacao` e `nota_objetivas` (1.9.2)
**De onde vêm:**
- **Redação** = a nota da **questão da posição 1 da prova**, na ordem em que aparece para o candidato. Não é o id 1 do banco de questões. Um texto de instruções sem nota antes dela não conta como questão.
- **Objetivas** = a soma de **todas as outras questões**.
- Na mesma escala da `nota`: na prova da FAI, redação de 0 a 200 e objetivas de 0 a 800. Duas casas decimais.
- **Mesma tentativa do resultado final:** os dois campos vêm da mesma tentativa que gerou a `nota`, conforme o `metodonota` da prova (4.7). Com `maior`, é a tentativa de maior nota. Com `media`, é a média de cada parte entre as tentativas. Por isso, com a correção concluída, **`nota_redacao + nota_objetivas = nota`**.

**Quando vêm `null` (nunca zero nesses casos):**
- **correção pendente:** alguma tentativa finalizada da prova ainda tem questão esperando correção manual, como a redação ainda não corrigida pela banca. Nesse caso a `nota` também costuma vir `null`;
- o candidato **ainda não finalizou** a prova;
- o curso tem **mais de uma prova** com tentativa finalizada do candidato, e não dá para saber qual é a do resultado. Isso não acontece no Vestibular, que tem uma prova por curso.

**Zero é zero:** uma redação corrigida com 0, ou deixada **em branco** e encerrada, vem `0`. Objetivas todas erradas vêm `0`.

**Como o CRM usa** (regra definida pela FAI, aplicada pelo CRM):

| `nota_redacao` | `nota_objetivas` | Situação | O CRM faz |
|---|---|---|---|
| `> 0` | `> 0` | aprovado | aprovação automática, mensagem de parabéns e pré-matrícula |
| `= 0` | qualquer valor | correção concluída, redação zerada | **considerar a nota final 0** (a `nota` do Moodle não é zerada) e oferecer nova chance |
| qualquer valor | `= 0` | correção concluída, objetivas zeradas | oferecer nova chance |
| `null` | `null` | correção pendente ou prova não feita | "a avaliação está sendo preparada", sem aprovar nem reprovar |

- Não há nota mínima total nesse processo. A mínima de 400 é só do ENEM.
- Os dois campos vêm `null` **juntos**, ou com valor **juntos**. Na dúvida, trate `null` em qualquer um como pendente.
- Para saber se o candidato fez a prova, use `dataprova` e `tentativas`. Use `podefazerprova` para saber se ele ainda pode fazer.

#### Orientações para o CRM (checklist)
Se o CRM foi implementado com os nomes e a regra acima, ele já funciona. Confira só estes pontos:

1. **Regra de negócio: redação zerada zera a nota no CRM.** No Moodle, uma redação corrigida com **0** (ou em branco) **não zera a nota total**. A `nota` continua sendo a soma das objetivas. Exemplo: `"nota": 520, "nota_redacao": 0, "nota_objetivas": 520`.
   - Pela regra do vestibular, quem zera a redação não tem o resultado aproveitado. **Quem aplica isso é o CRM:** com `nota_redacao = 0` e a correção concluída, o CRM considera a **nota final do candidato como 0** e oferece a nova chance.
   - O CRM **não deve exibir nem gravar a `nota` do Moodle** (520 no exemplo) como resultado desse candidato.
   - O Moodle continua devolvendo a `nota` real da prova e as duas partes separadas. A decisão de zerar é do CRM.
2. **Decida pelos dois campos novos, não pela `nota`.**
   - Aprovação: `nota_redacao > 0` **e** `nota_objetivas > 0`.
   - Nova chance: um dos dois igual a `0`.
   - Pendente: algum dos dois `null`.

   A `nota` sozinha não diz se o candidato cumpriu os dois requisitos.
3. **`null` não é `0`.** Nunca converta `null` em zero, nem por padrão de campo, banco ou serialização. Fazer isso daria "nova chance" a quem ainda nem foi corrigido. Só `0` de verdade (corrigido com zero ou em branco) leva à nova chance.
4. **Com `null`, não olhe a `nota`.** No caso raro de recaptação, com a 1ª tentativa corrigida e a 2ª esperando correção, a `nota` pode ter valor enquanto os dois campos estão `null`. Mesmo assim, o certo é "avaliação sendo preparada".
5. **Decimais:** os valores são números com até **2 casas** (`150`, `150.5`, `333.33`). Não arredonde para comparar com zero: `0.5` é maior que zero. Se o CRM exibir as notas, formate com até 2 casas.
6. **Consulte de novo até sair do pendente.** A redação é corrigida pela banca depois do envio, então o mesmo candidato passa de `null` para os valores finais. Continue consultando a 4.5 (sob demanda ou em intervalos, como em "Acompanhamento dos resultados", seção 5) até os dois campos terem valor.
   - **Recomendado (1.10.1):** consulte **só os pendentes, por CPF** (`username`), em vez de ler a turma toda a cada vez. Veja "Consultar um candidato só", acima.
   - Para a turma, use `dataprovade`/`dataprovaate` para limitar a consulta às provas recentes.
7. **O resultado pode mudar depois:**
   - se a banca **revisar** a nota de uma redação já corrigida, os valores mudam na próxima consulta;
   - numa **nova tentativa** (4.8), os campos passam a refletir a tentativa que vale para o resultado final, conforme o `metodonota` da prova.

   Não trate a primeira resposta com valor como definitiva se o processo permitir revisão.
8. **A soma confere com a `nota`:** `nota_redacao + nota_objetivas = nota`, com até 2 casas. O CRM pode usar isso como conferência. Se não bater, avise a FAI: isso só acontece se alguém alterar a nota do curso à mão no livro de notas, ou se o curso do vestibular tiver outra atividade valendo nota além da prova.
9. **A escala depende da prova:** 0–200 e 0–800 valem porque a prova da FAI tem nota máxima 1000 e a redação vale 20%. Se a FAI mudar a prova, a escala acompanha. A regra do CRM (`> 0` e `= 0`) continua valendo do mesmo jeito.
10. **Os campos sempre vêm na resposta**, com número ou `null`, em todo candidato e em toda página. Não é preciso tratar campo ausente.
11. **Teste com o piloto:** depois da atualização na produção, consulte a 4.5 do candidato piloto e confira:
    - os dois campos têm valor, a soma dá a `nota` e o CRM mostra a mensagem certa (parabéns e pré-matrícula, ou nova chance);
    - num candidato com a redação ainda não corrigida, o CRM mantém "a avaliação está sendo preparada".

**Exemplo** (dados fictícios), um candidato em cada situação:
```json
{
  "total": 5, "pagina": 1, "porpagina": 100, "totalpaginas": 1,
  "candidatos": [
    { "username": "00000000001", "firstname": "Candidato", "lastname": "Aprovado", "email": "aprovado@example.com", "courseid": 10,
      "nota": 740, "concluido": true, "datamatricula": "2026-10-15T08:00:00-03:00", "dataprova": "2026-10-15T09:30:00-03:00",
      "dataconclusao": "2026-10-15T09:31:00-03:00", "tentativas": 1, "podefazerprova": false,
      "nota_redacao": 140, "nota_objetivas": 600 },
    { "username": "00000000002", "firstname": "Candidato", "lastname": "Redacao Zerada", "email": "redacao@example.com", "courseid": 10,
      "nota": 520, "concluido": true, "datamatricula": "2026-10-15T08:00:00-03:00", "dataprova": "2026-10-15T09:40:00-03:00",
      "dataconclusao": "2026-10-15T09:41:00-03:00", "tentativas": 1, "podefazerprova": false,
      "nota_redacao": 0, "nota_objetivas": 520 },
    { "username": "00000000003", "firstname": "Candidato", "lastname": "Objetivas Zeradas", "email": "objetivas@example.com", "courseid": 10,
      "nota": 150, "concluido": true, "datamatricula": "2026-10-15T08:00:00-03:00", "dataprova": "2026-10-15T09:50:00-03:00",
      "dataconclusao": "2026-10-15T09:51:00-03:00", "tentativas": 1, "podefazerprova": false,
      "nota_redacao": 150, "nota_objetivas": 0 },
    { "username": "00000000004", "firstname": "Candidato", "lastname": "Correcao Pendente", "email": "pendente@example.com", "courseid": 10,
      "nota": null, "concluido": false, "datamatricula": "2026-10-15T08:00:00-03:00", "dataprova": "2026-10-15T10:00:00-03:00",
      "dataconclusao": null, "tentativas": 1, "podefazerprova": false,
      "nota_redacao": null, "nota_objetivas": null },
    { "username": "00000000005", "firstname": "Candidato", "lastname": "Sem Prova", "email": "semprova@example.com", "courseid": 10,
      "nota": null, "concluido": false, "datamatricula": "2026-10-15T08:00:00-03:00", "dataprova": null,
      "dataconclusao": null, "tentativas": 0, "podefazerprova": true,
      "nota_redacao": null, "nota_objetivas": null }
  ]
}
```

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
- **Turma inteira:** percorrer as páginas de 1 até `totalpaginas`. Para sincronizar só as provas recentes, usar `dataprovade` / `dataprovaate`.
- **Candidatos específicos (1.10.1):** consultar **por CPF** (`username`), um por chamada. É o jeito recomendado para:
  - acompanhar quem está com a **correção pendente** (`nota_redacao`/`nota_objetivas` `null`) até sair o resultado;
  - atualizar o status de quem **acabou de voltar da prova** pelo retorno (seção 6).

  Assim o CRM lê só quem interessa, e não a turma toda a cada consulta.
- Intervalo sugerido para os pendentes: a cada **10–15 minutos**. A redação depende da correção da banca, que pode levar horas ou dias.

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

---

## 6. Retorno ao CRM depois da prova

### Como funciona
1. O candidato entra na prova pelo link da 4.10.
2. Ele termina a prova: clica em **"Enviar tudo e terminar"**, ou o **tempo da prova acaba** com a prova aberta.
3. O Moodle mostra uma tela de confirmação: **"Prova enviada com sucesso! Você será levado de volta em 5 segundos."**, a mensagem opcional configurada pela FAI e o botão **"Voltar agora"**. O candidato **não vê** nota nem respostas.
4. O Moodle **encerra a sessão** do candidato e o leva para a **URL de retorno**, com dois parâmetros acrescentados:

```
GET {URL de retorno do CRM}?status=concluida&cmid=22
```

| Parâmetro | Valor | Significado |
|---|---|---|
| `status` | `concluida` | o candidato enviou a prova |
| `cmid` | inteiro | id de acesso da prova que ele terminou, o mesmo usado na 4.10 |

- Se a URL de retorno já tiver parâmetros, como `?origem=moodle`, eles são mantidos, e os dois acima são acrescentados.
- **Nenhum dado pessoal** vem na URL: nem CPF, nem nome, nem nota.
- **Quando não há retorno:**
  - o candidato entrou no Moodle pelo login normal, e não pelo link da 4.10;
  - terminou outra prova, diferente da do link;
  - fechou o navegador antes de enviar, e a tentativa foi fechada depois pelo Moodle;
  - a FAI ainda não configurou a URL de retorno.

  Nesses casos ele fica no Moodle, como hoje.

**Pré-requisito do lado da FAI:** o plugin `local_faicrm` **1.10.1** (ou mais nova) na produção e a URL de retorno configurada (roteiro do painel, passo S10). Até lá, o candidato continua no Moodle ao terminar a prova.

### Checklist de implementação no CRM
1. **Criar a página de retorno**, por exemplo `https://<crm>/vestibular/prova-enviada`:
   - **precisa ser `https://`**;
   - precisa abrir **sem depender de sessão do Moodle**, porque o candidato chega **deslogado** do Moodle;
   - se a sessão do candidato no CRM tiver expirado, a página deve funcionar mesmo assim, por exemplo mostrando "Prova enviada. Entre no portal para acompanhar o resultado.", ou levar ao login do CRM e depois de volta a ela.
2. **Informar essa URL à FAI**, que a configura no Moodle. Sem isso, não há retorno.
   - A URL **não pode levar segredo nem identificador** (`?token=`, `?apikey=`, `?cpf=`). Ela vai para todos os candidatos e fica no histórico do navegador e nos logs.
   - Parâmetros fixos sem segredo podem ir, como `?origem=moodle`.
3. **Não use os parâmetros como prova de nada.** Qualquer pessoa pode abrir `…?status=concluida&cmid=22` no navegador, e um pré-visualizador de links ou um robô também. Use-os só para **escolher a mensagem** da página, como "Sua prova foi enviada".
   - **A página de retorno não muda nada no CRM:** não avança a etapa do candidato, não marca "fez a prova" e não envia e-mail.
   - **Para registrar que o candidato fez a prova**, e com qual nota, consulte os **resultados (4.5)**: `dataprova`, `tentativas`, `nota` e `concluido` daquele candidato.
   - **Para atualizar o status logo depois do retorno**, a página pode disparar, no **backend**, uma consulta à 4.5 **com o CPF do candidato** (`username`, 1.10.1), que traz só ele.
     - Faça a consulta para o candidato **da sessão do CRM**, nunca para um candidato escolhido por parâmetro da URL.
     - Sem a sessão do CRM (expirada), não consulte: mostre a mensagem genérica e deixe o acompanhamento normal (seção 5) atualizar o status.
     - Limite a frequência.
     - A nota pode levar alguns minutos para aparecer, e questões abertas podem depender de correção.
   - **A falta do retorno também não prova nada.** O candidato pode ter fechado o navegador, a tentativa pode ter sido fechada pelo Moodle ou a rede pode ter caído. O CRM nunca depende do retorno para registrar a prova: a fonte da verdade é sempre a 4.5.
   - **Valide os parâmetros:** `status` só pode ser `concluida`, e `cmid` só um inteiro de uma prova conhecida. Fora disso, mostre a mensagem genérica.
   - **Nunca mostre os parâmetros crus no HTML**, por risco de XSS, e **nunca redirecione com base neles**.
   - Não conte com o `Referer`: ele chega vazio.
4. **Use o `cmid` para saber de qual prova (vestibular) ele voltou**, caso o CRM tenha mais de um vestibular ativo.
5. **Teste de aceite** com o candidato piloto, depois que a FAI configurar a URL:
   - "Realizar minha prova" → fazer a prova → "Enviar tudo e terminar" → tela "Prova enviada com sucesso!" → em 5 s, a página de retorno do CRM, com `status=concluida&cmid=22`;
   - voltar ao Moodle pelo endereço direto → o candidato está deslogado.
