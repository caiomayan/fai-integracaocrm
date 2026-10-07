# 002 — Tarefas

Legenda: ⬜ a fazer · 🚧 em andamento · ✅ feito · Dono = agente responsável.

| # | Tarefa | Reqs | Dono | Status |
|---|---|---|---|---|
| T1 | Plugin `local_faicrm`: version, lang, services.php (serviço + função), external `get_resultados_vestibular` | RF-05, RF-06, RNF-01/02 | agente-plugin (Opus) | 🚧 |
| T2 | `setup.php`: configs WS/completion/senha, papel, `ws_crm`, autorização, token, curso + quiz + critério de conclusão, `output/` | RF-06, RNF-03, CA-01 | agente-setup (Opus) | 🚧 |
| T3 | `simular_prova.php` (tentativa real com N acertos + agregação de conclusão) | CA-05 | agente-setup (Opus) | 🚧 |
| T4 | Integração Docker: mounts no compose, COPY dos scripts, chamada no entrypoint | RNF-03 | agente-setup (Opus) | 🚧 |
| T5 | Coleção Bruno (env local + 8 requisições) | CA-07 | agente-bruno (Sonnet) | 🚧 |
| T6 | Docs: README (seção integração) + `docs/integracao-crm.md` (guia para o CRM / FAI) | — | agente-bruno (Sonnet) | ✅ |
| T8 | Adaptador JSON `rest_json.php` + bump de versão do plugin (1.1.0) | RF-07, CA-08/09/10 | Ferro (Sonnet · medium) | ✅ |
| T9 | Converter os `.bru` para JSON + `98-json-invalido.bru` | RF-07, CA-07 | Ferro (Sonnet · medium) | ✅ |
| T10 | Docs: `integracao-crm.md` e README passam a usar JSON como formato principal | RF-07 | Ferro (Sonnet · medium) | ✅ |
| T11 | QA: revisão de código + testes CA-07…CA-10 + regressão CA-01…CA-06 | todos | Lupa (Opus · medium) | ✅ APROVADO |
| T12 | Adaptador: roleid opcional (padrão student) + 204 para retorno null; Bruno (03 sem roleid, testes de 204) e docs | RF-08, RF-09, CA-11, CA-12 | Ferro (Sonnet · medium) | ✅ |
| T13 | QA de T12 + regressão CA-01…CA-10 | CA-01…CA-12 | Lupa (Opus · medium) | ✅ APROVADO |
| T14 | Tratamento de erros RF-10 (mapa errorcode→HTTP+message PT, log com X-Request-Id, headers de segurança) + revisão de segurança do adaptador; Bruno e docs | RF-10, CA-13, CA-14 | Muralha (Opus · high) | ✅ |
| T15 | QA de T14 + regressão CA-01…CA-12 | CA-01…CA-14 | Lupa (Opus · medium) | ✅ APROVADO |
| T16 | Paginação de resultados (RF-11): função, mensagens 400 no adaptador, versão 1.4.0, Bruno (04 + casos de página), docs e nota de referência | RF-11, CA-15, CA-16 | Ferro (Sonnet · medium) | ✅ |
| T17 | QA de T16 com volume (≥ 1000 candidatos na fai-qa) + regressão CA-01…CA-14 | CA-01…CA-16 | Lupa (Opus · medium) | ✅ APROVADO |
| T18 | Datas (datamatricula, dataprova, dataconclusao) + filtro dataprovade/dataprovaate (RF-12); 400 no adaptador; versão 1.5.0; Bruno, docs, README e nota de referência | RF-12, CA-17, CA-18, CA-19 | Ferro (Sonnet · medium) | ✅ |
| T19 | QA de T18 com volume e datas variadas + regressão | CA-01…CA-19 | Lupa (Opus · medium) | ✅ APROVADO |
| T20 | Filtro só por data (AAAA-MM-DD) + Bruno sem valores fixos (02 gera candidato único, userid encadeado, token como secret do ambiente, 03c para papel não permitido) | RF-12, CA-07, CA-18 | Ferro (Sonnet · medium) | ✅ |
| T21 | RF-13 (409/404 em matrícula/desmatrícula repetidas), RF-14 (porpagina fixo 100), RF-15 (operação no caminho), versão 1.6.0; Bruno (datas prontas: hoje, 30 dias, sem provas, inválida; 409 e 404 repetidos); docs; RF-16 `docs/openapi.yaml` | RF-13…RF-16, CA-20…CA-23 | Ferro (Sonnet · medium) | ✅ |
| T22 | QA de T21 + conferência do OpenAPI contra respostas reais + regressão | CA-01…CA-23 | Lupa (Opus · medium) | ✅ APROVADO |
| T24 | RF-13 ajustado: 409 só com matrícula manual ATIVA (suspensa reativa; outro método não bloqueia) + linha no desenvolvimento.md sobre o token secreto na CLI | RF-13, CA-20 | Ferro (Sonnet · medium) | ✅ |
| T23 | Página Swagger publicada a partir do openapi.yaml | RF-16 | maestro | ✅ https://claude.ai/artifact/QMuhHW612LCKuqTjApCth3 |
| T7 | Verificação ponta a ponta (`down -v`, up, CA-01…CA-07) + navegador | todos os CA | orquestrador | ✅ |

## Resultado da verificação (T7 — 2026-10-06)
Instalação do zero numa stack isolada (`-p fai-t7`, porta 8081) + stack principal atualizada sem perda de dados.
- CA-01 ✅ token de `output/token.txt` funciona.
- CA-02 ✅ cria `[{id, username}]`; username duplicado → `invalidparameter`. **E-mail duplicado também** → `invalidparameter` (documentado).
- CA-03 ✅ matrícula retorna `null`.
- CA-04 ✅ `nota: null, concluido: false`.
- CA-05 ✅ `simular_prova.php --acertos=4` → `nota: 800, concluido: true`.
- CA-06 ✅ `invalidtoken` / `accessexception`.
- CA-07 ✅ Bruno CLI: 8/8 requisições, 10/10 testes.
- Navegador: serviço "CRM Vestibular FAI" listado; console limpo.

Ajustes do orquestrador: `$CFG->noemailever` no config dev (envio de quiz quebrava sem SMTP); testes Bruno aceitam corpo `null`; design corrigido (`core_role_set_assign_allowed`).

## Resultado do QA do RF-07 (T11, Lupa, 2026-10-06): APROVADO
- CA-01 a CA-10 OK na stack principal (:8080) e numa instalação do zero (stack isolada `fai-qa` em :8081, plugin 2026100602).
- Bruno CLI: 9/9 requisições, 11/11 testes, 4/4 asserts.
- Achados corrigidos pelo Ferro:
  - (médio) `{"courseid":null}` gerava TypeError expondo o caminho interno. A função passou a usar `NULL_NOT_ALLOWED` (versão 2026100602) e agora devolve `invalidparameter`.
  - (baixo) BOM UTF-8 no corpo dava `invalidjson`; agora o BOM é removido.
  - (baixo) Exemplos da doc corrigidos.
- Comportamentos aceitos: corpo `[]` é tratado como `{}`; `{"courseid":true}` vira 1 (coerção nativa, inofensiva).

## Resultado do QA do RF-08/RF-09 (T13, Lupa, 2026-10-06): APROVADO
- CA-01 a CA-12 OK na :8080 e numa instalação do zero (plugin 2026100603).
- Bruno CLI: 10/10 requisições, 12/12 testes.
- Achado corrigido pelo Ferro:
  - (médio) Ao deixar de usar o server.php, o adaptador perdeu o `raise_early_ws_exception()`. Falhas no início do Moodle (ex.: banco fora) saíam em HTML, com stack trace. Agora saem em JSON (`dbconnectionfailed`).
- Comportamentos aceitos: `roleid: null` é tratado como ausente (vira student); a busca do papel student acontece antes da autenticação (1 SELECT de leitura, sem saída).

## Resultado do QA do RF-10 (T15, Lupa, 2026-10-07): APROVADO
- CA-01 a CA-14 OK na :8080 e numa instalação do zero (plugin 2026100604 / 1.3.0).
- Todas as linhas da tabela de erros foram provocadas com curl, incluindo os casos destrutivos na stack isolada: 503 e 500 (banco fora, manutenção, serviço desligado) e token expirado.
- Bruno CLI: 13/13 requisições, 17/17 testes.
- Achado corrigido pelo Muralha:
  - (médio) O `redact()` trocava a senha em todos os campos do log. Com senha curta, quebrava o `requestid` e impedia achar a linha pelo `X-Request-Id`. Agora a redação vale só para message/debuginfo/where, e só em ocorrência delimitada (qualquer ocorrência para segredos com 8 ou mais caracteres).
- Infos aceitas:
  - candidato suspenso é matriculado normalmente pelo nativo;
  - username com maiúsculas cai na mensagem genérica de campo;
  - o 413 diz "1 MB", mas o limite é 1 MiB.
- Desvios do Muralha aceitos pelo QA: 409 também para conflito de estado; 413; wsfunction inexistente = 404 (só alcançável depois da autenticação).
- Interrupção em 06–07/10: o notebook foi desligado no meio do T15; o trabalho foi retomado na etapa da fai-qa.

## Resultado do QA do RF-11 (T17, Lupa, 2026-10-07): APROVADO, sem defeitos
- **CA-15:** percorrendo as páginas, cada candidato aparece exatamente uma vez, inclusive 3 candidatos com o mesmo nome (desempate pelo id), testado com porpagina 1, 2, 3, 4 e 7. Página além da última → `[]` com metadados. Limites inválidos → 400 em PT. Curso vazio → `total 0`.
- **CA-16 (volume, 1500 candidatos na fai-qa):**
  - Consultas SQL por página: 12 a 16, **iguais com 100 e com 1500 candidatos**, ou seja, não crescem.
  - Versão antiga (1.3.0): 1506 consultas e 1,0–1,5 s para trazer os 1500 de uma vez.
  - HTTP: página de 100 com mediana de 128 ms; página de 500 com 95 ms; percorrer os 1500 em páginas de 100 leva 1,2–1,3 s.
- Consistência total × lista OK nos casos: papel atribuído 2 vezes, usuário suspenso, usuário excluído e papel em contexto pai. Sem injeção (`get_in_or_equal`).
- Infos aceitas: `concluido` agora vem de `course_completions.timecompleted` direto (equivalente ao anterior); candidato suspenso continua aparecendo, como antes.

## Resultado do QA do RF-12 (T19, Lupa, 2026-10-07): APROVADO, sem defeitos
- **CA-17:**
  - Datas em ISO 8601 com -03:00, batendo com o banco.
  - Duas inscrições: vale a menor data de matrícula.
  - Várias tentativas: vale a última finalizada. Tentativa em andamento, preview e abandonada são ignoradas.
- **CA-18:** testados de, até, dia exato, 23:59:59, meia-noite e segundos exatos. Candidatos sem prova ficam fora quando há filtro. `total` e `totalpaginas` coerentes. 11 formatos inválidos e payloads de SQL → 400.
- **CA-19 (1500 candidatos, 1693 tentativas espalhadas por 20 dias):**
  - 14 a 16 consultas SQL por página, com ou sem filtro: não crescem.
  - 8 intervalos percorridos trouxeram exatamente o conjunto calculado no banco (ex.: 375/375, 1349/1349).
  - Página de 100 com mediana de 117–236 ms.
- Infos:
  1. `ate` com HH:MM vale HH:MM:00 (segue a spec).
  2. O subselect MAX(timefinish) deve ser acompanhado se o volume chegar a dezenas de milhares de tentativas.
  3. Bruno 04c/04d com `seq` repetido (cosmético).

## Resultado do QA de RF-13…RF-16 (T22, Lupa) e ajustes (T24, Ferro), 2026-10-07: APROVADO
- **CA-20:** matrícula repetida → 409; desmatrícula de quem não está matriculado → 404; tudo ou nada conferido no banco; sem token → 401. Corrida com 4 matrículas simultâneas → 1 matrícula só (aceitável).
- **CA-21:** 100 por página fixo; com 150 candidatos → 2 páginas; `porpagina` → 400.
- **CA-22:** caminho e query devolvem respostas idênticas byte a byte; formas divergentes → 400.
- **CA-23:** lint válido; 63 pares (operação, status) conferidos contra a API real.
- Correção do Ferro: exemplos do OpenAPI que a API não devolvia.
- Decisão do Caio (T24): o 409 vale só com matrícula manual ATIVA. Matrícula suspensa é reativada (204); matrícula por outro método não bloqueia. Plugin 1.6.1.
- T23: página de referência (estilo Swagger) publicada a partir do openapi.yaml.
