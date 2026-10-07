# 002 — Tarefas

Legenda: ⬜ a fazer · 🚧 em andamento · ✅ feito · Dono = agente responsável.

| # | Tarefa | Reqs | Dono | Status |
|---|---|---|---|---|
| T1 | Plugin `local_faicrm`: version, lang, services.php (serviço + função), external `get_resultados_vestibular` | RF-05, RF-06, RNF-01/02 | agente-plugin (Opus) | 🚧 |
| T2 | `setup.php`: configs WS/completion/senha, papel, `ws_crm`, autorização, token, curso + quiz + critério de conclusão, `output/` | RF-06, RNF-03, CA-01 | agente-setup (Opus) | 🚧 |
| T3 | `simular_prova.php` (tentativa real com N acertos + agregação de conclusão) | CA-05 | agente-setup (Opus) | 🚧 |
| T4 | Integração Docker: mounts no compose, COPY dos scripts, chamada no entrypoint | RNF-03 | agente-setup (Opus) | 🚧 |
| T5 | Coleção Bruno (env local + 8 requisições) | CA-07 | agente-bruno (Sonnet) | 🚧 |
| T6 | Docs: README (seção integração) + `docs/integracao-crm.md` (guia para o CRM / FAI) | — | agente-bruno (Sonnet) | 🚧 |
| T8 | Adaptador JSON `rest_json.php` + bump de versão do plugin (1.1.0) | RF-07, CA-08/09/10 | Ferro (Sonnet · medium) | ✅ |
| T9 | Converter os `.bru` para JSON + `98-json-invalido.bru` | RF-07, CA-07 | Ferro (Sonnet · medium) | ✅ |
| T10 | Docs: `integracao-crm.md` e README passam a usar JSON como formato principal | RF-07 | Ferro (Sonnet · medium) | ✅ |
| T11 | QA: revisão de código + testes CA-07…CA-10 + regressão CA-01…CA-06 | todos | Lupa (Opus · medium) | ✅ APROVADO |
| T12 | Adaptador: roleid opcional (padrão student) + 204 para retorno null; Bruno (03 sem roleid, testes de 204) e docs | RF-08, RF-09, CA-11, CA-12 | Ferro (Sonnet · medium) | ✅ |
| T13 | QA de T12 + regressão CA-01…CA-10 | CA-01…CA-12 | Lupa (Opus · medium) | ✅ APROVADO |
| T14 | Tratamento de erros RF-10 (mapa errorcode→HTTP+message PT, log com X-Request-Id, headers de segurança) + revisão de segurança do adaptador; Bruno e docs | RF-10, CA-13, CA-14 | Muralha (Opus · high) | ✅ |
| T15 | QA de T14 + regressão CA-01…CA-12 | CA-01…CA-14 | Lupa (Opus · medium) | ✅ APROVADO |
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
