# Registro das notas do Maestri (antes da simplificação de 08/10/2026)

Cópia integral das notas do canvas, guardada antes de reduzi-las a um resumo. Os detalhes de reprodução e o histórico ficam aqui.

---

## Nota: FAI · Estado atual

# FAI · Estado atual
_Atualizado: 2026-10-06_

**Fonte da verdade:** `docs/` (SDD). Isto aqui é só memória operacional.

## Fase
- ✅ 001 Ambiente dev — commit 95eb0c5
- ✅ 002 Integração CRM — implementada e verificada (T7: CA-01..07 ok). **Não commitada** — aguardando revisão do Caio.

## O que existe
- Plugin `moodle-plugin/local/faicrm` (serviço CRM Vestibular FAI + função de resultados)
- `docker/moodle/setup.php` (WS, papel, ws_crm, token, curso VEST20271 id=2, quiz 1000 pts)
- `docker/moodle/simular_prova.php --username= --acertos=0..5`
- `bruno/` (8 reqs) · `docs/integracao-crm.md` (guia CRM/FAI)

## Em andamento (RF-07 — API JSON)
- Agentes no canvas: ✅ **Ferro** (Sonnet · medium) concluiu T8–T10. ✅ **Lupa** (Opus · medium) QA **APROVADO** (CA-01..10, Bruno 9/9, instalação do zero ok). Plugin 1.1.0 (2026100602).
- Adaptador `local/faicrm/rest_json.php`: JSON → servidor REST nativo (segurança nativa).

## Em andamento (RF-08/RF-09)
- ✅ Ferro: roleid opcional (padrão student) + HTTP 204 quando o retorno é null (T12, plugin 1.2.0). ✅ Lupa: QA **APROVADO** (CA-01..12).
- Decisão do Caio: o ws_crm continua podendo atribuir **só student**.

## Em andamento (RF-10 — erros prontos p/ front)
- 07/10: notebook foi desligado no meio do T15; Docker e stack reiniciados, agentes reorientados. ✅ Lupa: T15 **APROVADO** (CA-01..14). RF-10 commitado e enviado: `e075a05`. README enxuto para cliente: `c06cacb` (técnico em docs/desenvolvimento.md).
- ✅ **Muralha** (Opus · high, segurança) concluiu o T14 (+ correção do redact do log pedida pela Lupa): erros = só `{"message"}` em PT + status HTTP (400/401/403/404/409/500/503), detalhes só no log com X-Request-Id, sem CORS. Plugin → 1.3.0. Depois Lupa faz QA (T15).

## Em andamento (RF-11 — paginação dos resultados)
- ✅ Ferro: T16 (plugin 1.4.0; resposta {total, pagina, porpagina, totalpaginas, candidatos}).
- ✅ Lupa: T17 **APROVADO** sem defeitos. Com 1500 candidatos: 12–16 consultas SQL por página (antes 1506), página de 100 em ~128 ms. ✅ Commit `34b4b49` enviado ao GitHub.

## Em andamento (RF-12 — datas + filtro por data da prova)
- Ideia do Luciano. Campos: datamatricula, dataprova (fim da tentativa finalizada), dataconclusao (ISO 8601). Filtro: dataprovade/dataprovaate.
- ✅ Ferro: T18 (plugin 1.5.0; Bruno 16/16). ✅ Lupa: T19 **APROVADO** sem defeitos (CA-17/18/19 ok; 1500 candidatos, 14–16 consultas/página). RF-11 + RF-12 **não commitados**.
- Decisão do Caio: filtro **só por data** (AAAA-MM-DD); resposta segue com data e hora. Bruno: candidato novo a cada execução, sem valores fixos, token como secret.
- ✅ Ferro: T20 (plugin 1.5.1 + Bruno sem valores fixos; 2 rodadas seguidas verdes). ✅ Commit `22c7024` enviado ao GitHub (RF-11 + RF-12 + T20). Repositório limpo.
- RF-11 (paginação) e RF-12 serão commitados juntos, quando o Caio pedir.

## Em andamento (RF-13…RF-16)
- RF-13: matrícula repetida → 409; desmatrícula de quem não está matriculado → 404 (tudo ou nada).
- RF-14: porpagina fixo em 100 (o CRM escolhe só a página).
- RF-15: operação também no caminho (/rest_json.php/<função>) para o OpenAPI.
- RF-16: docs/openapi.yaml + página Swagger publicada.
- ✅ Ferro T21 + T24 (plugin 1.6.1: 409 só com matrícula manual ativa). ✅ Lupa T22 APROVADO. ✅ Swagger publicado: https://claude.ai/artifact/QMuhHW612LCKuqTjApCth3. **Não commitado** (aguarda o Caio).

## Em andamento (Bruno hardcoded)
- Pedido do Caio: requisições com JSON fixo e pronto, sem testes, scripts nem variáveis; token colocado à mão em Collection → Auth.
- Candidatos fixos criados: Maria da Silva = userid 72 (12345678900); João Santos = userid 73 (98765432100). O 02 cria a Ana Souza (11122233344).
- ✅ Coleção reescrita: 23 requisições com JSON fixo, sem testes, scripts nem variáveis; token em Collection → Auth (collection.bru, fora do Git). Verificada pelo maestro (23/23 com os status esperados).
- ✅ docs/contrato-api-crm.md (resumo do contrato para o time do CRM). Commit final `4529963` enviado ao GitHub. Repositório limpo.

## Em andamento — Spec 003: prontidão para produção
- Objetivo: homologação e produção serem só instalar e configurar (sem ajuste de código depois).
- ✅ Ferro P1 concluído (plugin 1.7.0). ✅ Muralha P2 (plugin 1.7.1; phpcs moodle 0 erros; corrigido bug ALTO: validade na autorização bloqueava todas as chamadas no Moodle 4.5; senha do ws_crm via CSPRNG). ✅ Lupa P3 APROVADO (produção simulada: Moodle limpo + zip + só scripts; 6 correções no guia). **Spec 003 concluída: pronto para homologação.** Commit `216945f` + tag `v1.7.1` + release com o zip: https://github.com/caiomayan/fai-integracaocrm/releases/tag/v1.7.1 Bruno 01/07 restaurados (o app tinha salvo versões antigas).
- (P1) privacy provider, cli/configurar.php, cli/verificar.php, zip do plugin, docs/implantacao-producao.md, versão 1.7.0.
- Depois: Muralha P2 (Moodle Code Checker + segurança dos scripts) → Lupa P3 (produção simulada: Moodle limpo + zip + só scripts).

## Em andamento — Spec 004: nova tentativa (recaptação) + catálogo
- Pedido do Caio após a apresentação: o CRM libera uma nova tentativa da prova SÓ para um candidato (user override nativo; histórico preservado; nota pela regra do questionário; prazo opcional); cancelar; listar cursos (visível + datas); listar provas do curso; resultados com tentativas/podefazerprova.
- Exige matrícula ativa; 409 se ainda pode fazer ou se há tentativa em andamento. Capability nova: mod/quiz:manageoverrides.
- ✅ Ferro N1 (plugin 1.8.0; Bruno 30/30; prova local agora com 1 tentativa). ✅ Muralha N2 (1.8.1: cancelar preserva os ajustes da FAI na exceção; trava contra corrida). ✅ Lupa N3 APROVADO (2 correções altas re-testadas). **Spec 004 concluída.** Commit `4055b8b` + release v1.8.2 (Latest): https://github.com/caiomayan/fai-integracaocrm/releases/tag/v1.8.2
- Spec corrigida: cancelar dá 409 só se usou MAIS que o limite original (>), não ≥.

## Commit
- ✅ `c5f0a94 feat: integração CRM…` (✅ enviado ao GitHub, main). Ignorados: `bruno/collection.bru`, `.maestri/`.
- Os `vars:pre-request` do Caio nos .bru 01–03 ficaram só locais (aparecem como modificados no git status; não commitar).

## Próximo passo
Caio revisa → commit (sem co-autoria) → pendências com Luciano (senha, e-mail único, IDs reais, SSO).

---

## Nota: FAI · Histórico do projeto

# FAI · Histórico do projeto
_Integração CRM ↔ Moodle — Vestibular FAI · Última atualização: 06/10/2026_

## Objetivo
O CRM (de terceiros) cadastra o candidato no Moodle, matricula no curso do Vestibular e depois consulta **nota** e **conclusão**. Nós construímos o serviço no Moodle e simulamos o CRM com o **Bruno**.

**Fluxo:** CRM → localiza/cria candidato → matricula no curso → candidato faz a prova → Moodle corrige → CRM consulta nota + concluído.

---

## ✅ Fase 1 — Ambiente de desenvolvimento (06/10)
- Docker com 3 containers: **db** (MariaDB 10.11), **moodle** (PHP 8.1 + Apache), **cron** (tarefas agendadas a cada minuto).
- Moodle **4.5.14+ (Build 20261002)**, a mesma linha do Moodle da FAI (4.5.14+ Build 20260928). Instalado em português, fuso de São Paulo.
- Acesso: http://localhost:8080 — `admin` / `Admin@12345`.
- Repositório criado: github.com/caiomayan/fai-integracaocrm, commit `95eb0c5 chore: setup inicial`.
 - O `.env` não é versionado (usar o `.env.example`).
 - Commits sem co-autoria do Claude.

## ✅ Fase 2 — Integração CRM (06/10) — aguardando revisão, **não commitada**
**Decisões tomadas com o Caio**
- **Cadastro e matrícula:** 100% funções nativas do Moodle (`core_user_create_users`, `enrol_manual_enrol_users`, papel estudante `roleid=5`).
- **Resultados:** uma única função própria, **somente leitura**, que devolve a lista de candidatos com `username, firstname, lastname, email, courseid, nota, concluido`.
- **nota** = **Total do curso** (gradebook). **concluido** = conclusão do curso.

**O que foi construído**
- **Plugin `local_faicrm`:**
 - cria o serviço **"CRM Vestibular FAI"** só com as funções necessárias;
 - traz a função `local_faicrm_get_resultados_vestibular`.
- **`setup.php` (automático a cada subida):**
 - liga os web services (REST);
 - cria o papel "Integração CRM" e o usuário técnico `ws_crm`;
 - gera o token permanente e o grava em `output/token.txt`;
 - cria o curso **Vestibular 2027.1** (id 2), com a prova de 5 questões valendo 1000 pontos e a conclusão do curso ligada à prova.
- **`simular_prova.php`:** simula o candidato fazendo a prova com N acertos (200 pontos cada).
- **Coleção Bruno** (`bruno/`), 8 requisições: localizar, criar, matricular, resultados, conclusão nativa, notas nativas, desmatricular, token inválido.
- **Documentação SDD** em `docs/`, que é a fonte da verdade:
 - specs com requisitos, design e tarefas;
 - guia para o time do CRM e para a FAI: `docs/integracao-crm.md`.

**Como foi feito:** 3 agentes em paralelo — plugin (Opus), setup/Docker (Opus), Bruno/docs (Sonnet). Depois o orquestrador integrou e testou tudo.

**Verificação (instalação do zero, numa cópia isolada do ambiente)**
| Teste | Resultado |
|---|---|
| Criar candidato | ✅ devolve o id; username repetido é recusado |
| Matricular | ✅ |
| Resultados antes da prova | ✅ `nota: null, concluido: false` |
| Resultados depois da prova (4 acertos) | ✅ `nota: 800, concluido: true` |
| Token inválido / função fora do serviço | ✅ erros corretos |
| Coleção Bruno | ✅ 8/8 requisições, 10/10 testes |

**Ajustes feitos na verificação**
- O envio de e-mail foi desligado no ambiente local: não há servidor de e-mail, e isso quebrava o fim da prova.
- Os testes do Bruno foram corrigidos.

---

## ✅ Fase 2.1 — API em JSON (06/10) — aguardando revisão, **não commitada**
**Pedido do Caio:** o serviço deve receber e responder em **JSON**. Assim fica independente do formato do Moodle, é mais fácil trocar de CRM ou de plataforma e dá para simular no Bruno.

**Problema encontrado:** o servidor REST nativo do Moodle só aceita form-urlencoded e ignora corpo JSON.

**Solução:** um adaptador no plugin, `POST /local/faicrm/rest_json.php?wsfunction=<função>`.
- Header `Authorization: Bearer <token>`; o corpo é o próprio JSON do cliente (`{"users":[...]}`, `{"enrolments":[...]}`).
- O adaptador só traduz a requisição. Token, permissões e validação continuam com o Moodle nativo.
- O endpoint antigo (form) continua funcionando.

**Equipe no canvas:** Ferro implementou (Sonnet · esforço médio); Lupa fez o QA (Opus · esforço médio); o maestro coordenou.

**QA: APROVADO**
- Critérios CA-01 a CA-10 OK; Bruno com 9/9 requisições; instalação do zero OK.
- 1 defeito médio corrigido: `courseid: null` expunha o caminho interno do servidor.
- 2 defeitos baixos corrigidos: BOM UTF-8 no corpo e exemplos da doc.
- A coleção Bruno agora usa body JSON + Bearer, e ganhou o teste 98 (JSON inválido).

---

## ✅ Fase 2.2 — Ajustes na matrícula (06/10) — aguardando revisão, **não commitada**
**Pedidos do Caio**
1. Matricular retornava 200 com corpo `null`. Agora retorna **204 No Content**; o mesmo vale para desmatricular.
2. **roleid opcional** na matrícula: se o CRM não mandar, o papel é **student** (procurado pelo nome, não fixo em 5, então funciona no Moodle da FAI); se mandar, o valor é respeitado.

**Decisão do Caio:** o usuário técnico continua podendo atribuir **só student**. Outro papel → erro `wsusercannotassign`. Se precisar, o admin libera no Moodle, sem código.

**Entregue (plugin 1.2.0)**
- O adaptador agora roda o servidor nativo do Moodle ele mesmo.
- Bruno: o 03 não manda roleid; o novo 03b manda.

**QA: APROVADO**
- CA-01 a CA-12 OK; Bruno 10/10; instalação do zero OK.
- 1 defeito médio corrigido: falhas no início do Moodle (ex.: banco fora do ar) saíam em HTML com detalhes internos; agora saem em JSON.

---

## ✅ Fase 2.3 — Erros prontos para o front + segurança (06–07/10) — commit `e075a05` (enviado ao GitHub)
**Pedido do Caio:** erros devolvem só `{"message": "descrição em português"}`, prontos para o front do CRM. **Decisão:** a message vem com o status HTTP coerente, para o CRM poder tomar decisões pelo status.

| Situação | HTTP |
|---|---|
| JSON inválido, campo faltando ou errado | 400 |
| Token inválido, ausente ou expirado | 401 |
| Sem permissão ou papel não permitido | 403 |
| Curso, candidato ou operação inexistente | 404 |
| Método diferente de POST | 405 |
| Usuário ou e-mail duplicado; curso ou candidato em estado que impede a matrícula | 409 |
| Corpo maior que 1 MB | 413 |
| Erro interno | 500 |
| Serviço desligado, manutenção ou banco fora | 503 |

**Segurança**
- Nenhum detalhe interno na resposta. Os detalhes vão para o log do servidor (sem token nem senha), achados pelo header `X-Request-Id`.
- Saiu o CORS aberto; entraram os headers nosniff e no-store.
- Limite de 1 MB no corpo.

**Equipe:** **Muralha** (agente de segurança, Opus · esforço alto) implementou; **Lupa** fez o QA.

**QA: APROVADO**
- CA-01 a CA-14 OK, inclusive numa instalação do zero e nos testes destrutivos.
- 1 defeito médio corrigido: a limpeza de senha no log estragava a linha quando a senha era curta.
- Plugin 1.3.0.

**Observação:** o notebook foi desligado no meio do QA. Os agentes foram retomados de onde pararam, sem perda de trabalho.

---

## ✅ Fase 2.4 — Paginação dos resultados (07/10) — commit `22c7024`
**Pedido do Caio:** paginar a lista de candidatos para não pesar quando houver muitos.

**Como ficou (plugin 1.4.0)**
- Parâmetros opcionais `pagina` (padrão 1) e `porpagina` (padrão 100, máximo 500).
- A resposta virou um objeto: `{total, pagina, porpagina, totalpaginas, candidatos: [...]}`.
- Paginação feita no banco; a nota e a conclusão são buscadas só para os candidatos da página, com a conclusão numa única consulta.
- Ordem estável (sobrenome, nome, id): ninguém repete nem some entre páginas.

**Equipe:** Ferro implementou (Sonnet); Lupa fez o QA (Opus).

**QA: APROVADO, sem defeitos.** Teste com **1500 candidatos**:
| | Antes (1.3.0) | Agora (1.4.0) |
|---|---|---|
| Consultas SQL | 1506 (todos de uma vez) | 12–16 por página (não cresce) |
| Tempo | 1,0–1,5 s por chamada | ~128 ms por página de 100 |

---

## ✅ Fase 2.5 — Datas e filtro por data da prova (07/10) — commit `22c7024` (filtro só por data, decisão do Caio; plugin 1.5.1)
**Origem:** ideia conversada com o Luciano, para o CRM poder filtrar, por exemplo, só as provas feitas em certa data ou entre datas.

**Como ficou (plugin 1.5.0)**
- Cada candidato traz `datamatricula`, `dataprova` e `dataconclusao` (ISO 8601, ex.: `2026-10-07T14:32:00-03:00`, ou `null`).
- `dataprova` é o fim da **tentativa** finalizada do questionário; se houver várias, vale a última. Tentativa em andamento, preview e abandonada não contam.
- Filtros opcionais `dataprovade` e `dataprovaate` (`AAAA-MM-DD` ou com hora), aplicados no banco junto com a paginação.

**QA: APROVADO, sem defeitos.**
- Teste com 1500 candidatos e provas em 20 dias: os filtros trouxeram exatamente quem devia, e cada página custou de 14 a 16 consultas SQL, com ou sem filtro.

---

## ✅ Fase 2.6 — Matrículas sem silêncio, página fixa e documentação Swagger (07/10) — commit `34b4b49`
**Pedidos do Caio**
- Matricular quem já está matriculado → **409**; desmatricular quem não está → **404**. Antes as duas devolviam 204.
- Itens por página **fixos em 100**; o CRM escolhe só a página.
- Requisições do Bruno com datas prontas: hoje, últimos 30 dias e data sem provas.
- Documento tipo **Swagger**.

**Decisão:** para a integração, "matriculado" significa ter matrícula **manual ativa**. Matrícula suspensa é reativada, e matrícula por outro método não bloqueia.

**Entregue (plugin 1.6.1)**
- `docs/openapi.yaml`: OpenAPI 3.0, 11 rotas.
- A operação também pode ir no caminho: `/rest_json.php/<função>`.
- Página de referência publicada: https://claude.ai/artifact/QMuhHW612LCKuqTjApCth3

**QA (Lupa): APROVADO.** Tudo ou nada conferido no banco; caminho e query com respostas idênticas; OpenAPI validado contra a API real (63 pares).

---

## ✅ Fase 3 — Prontidão para produção (08/10) — plugin 1.7.1 · release https://github.com/caiomayan/fai-integracaocrm/releases/tag/v1.7.1
**Objetivo:** homologação e produção serem só instalar e configurar, sem ajuste de código depois.

**Entregue**
- **Zip do plugin** pronto para instalar (`scripts/empacotar-plugin.sh` → `dist/local_faicrm-1.7.1.zip`).
- `cli/configurar.php`: configura tudo sozinho (web services, papel, usuário técnico, serviço) e gera o token com IP e validade.
- `cli/verificar.php --courseid=N`: diagnóstico OK/FALHA de tudo o que a integração precisa.
- Provedor de privacidade (LGPD/GDPR); padrão de código do Moodle com 0 erros.
- Guia `docs/implantacao-producao.md`: homologação → produção, painel ou scripts, plano de volta atrás.

**Achado importante (Muralha):** no Moodle 4.5, uma validade na *autorização* do usuário no serviço **bloqueia todas as chamadas** (comparação invertida no Moodle). Corrigido: a validade vai só no token.

**QA (Lupa): APROVADO.** Produção simulada: Moodle limpo, política de senha ligada, curso com id diferente, zip validado pelo próprio Moodle, configurado só com os scripts e fluxo completo OK.

---

## ✅ Fase 4 — Recaptação e catálogo (08/10) — plugin 1.8.2 · release v1.8.2
**Pedido do Caio após a apresentação:** o CRM deve poder oferecer a um candidato antigo a chance de **refazer a prova** (recaptação), e também listar os cursos (com visível/ativo) e as provas de cada curso.

**Decisões**
- A nova tentativa vale **só para aquele candidato** (exceção nativa do Moodle).
- O histórico é preservado; a nota segue a regra da prova; o prazo é opcional.
- O candidato precisa estar matriculado; responde 409 se ele ainda pode fazer a prova ou se há uma tentativa em andamento.
- Existe rota para cancelar a liberação.
- Os resultados ganham `tentativas` e `podefazerprova`.

**Rotas novas:** `listar_cursos`, `listar_provas`, `liberar_nova_tentativa`, `cancelar_nova_tentativa`.

**QA (Lupa): APROVADO.**
- Duas correções altas: liberações seguintes não podiam ser canceladas; o prazo da FAI se perdia ao cancelar (agora o cancelar restaura o estado anterior).
- Volume: 1500 candidatos e 828 exceções, com 0 divergências no `podefazerprova`.
- Instalação do zero pelo zip OK.

---

## ⚠️ Pontos de atenção
- O Moodle **recusa e-mail repetido** entre contas, além do username repetido. O CRM precisa considerar isso.
- No Bruno, cada candidato novo precisa de username **e** e-mail diferentes.
- O Bruno grava o `userid` em `bruno/environments/local.bru`; limpar antes de commitar.
- Localmente a política de senha está desligada, para aceitar senha = CPF.
- No servidor da FAI, conferir se o Apache/FastCGI repassa o header `Authorization` (necessário para o Bearer).
- Ficaram usuários de teste do QA na stack local (:8080); somem com `docker compose down -v`.

## ❓ Pendências com Luciano / FAI
- [ ] Política de senha do Moodle da FAI (senha = CPF numérico pode ser recusada)
- [ ] Configuração de e-mail repetido (`allowaccountssameemail`)
- [ ] IDs reais do curso Vestibular 2027.1 e do questionário
- [ ] Login transparente do candidato (SSO), fora do escopo atual
- [ ] Instalar o plugin `local_faicrm` no Moodle da FAI (checklist em `docs/integracao-crm.md`)

## ▶️ Próximos passos
1. ✅ Fases 2, 2.1 e 2.2 commitadas e enviadas ao GitHub (`c5f0a94`).
2. Levar as pendências ao Luciano.
3. Levar as pendências ao Luciano.
4. Planejar a implantação no Moodle da FAI.

---

## Nota: FAI · Decisões & armadilhas

# FAI · Decisões & armadilhas
- Cadastro/matrícula **nativos**; só a função de resultados é própria (somente leitura).
- nota = **Total do curso**; concluido = conclusão do curso (precisa do **cron**).
- Moodle REST: body **form-urlencoded** (users[0][username]=…), não JSON.
- passwordpolicy=0 só local — validar política da FAI.
- Commits: **sem** Co-Authored-By do Claude. Autor = Caio.
- `.env` não versionado; usar `.env.example`.
- Git Bash: usar `MSYS_NO_PATHCONV=1` em `docker compose exec` com caminhos /var/...
- CLI install deixa pendente `noreplyaddress` → setado no entrypoint.
- Delegação: próximas vezes criar agentes como **nós no canvas Maestri** (maestri-manager), não subagentes em background.
- E-mail duplicado → invalidparameter (allowaccountssameemail=0).
- Bruno grava `userid` em environments/local.bru ao rodar — limpar antes de commitar.
- Local tem `noemailever=true` (sem SMTP).
- Testes de instalação do zero: stack isolada `-p fai-t7` porta 8081 (não destruir a do Caio).
- API principal = **JSON** via `/local/faicrm/rest_json.php` (Bearer). Nativo form segue como alternativa.
- No servidor da FAI, conferir se o Apache/FastCGI repassa o header Authorization.
- curl no Git Bash corrompe acentos em argumentos → usar `--data-binary @arquivo.json`.
- Moodle 4.5: NUNCA pôr 'Válido até' na AUTORIZAÇÃO do usuário no serviço (o SQL nativo compara invertido e bloqueia tudo). Validade só no TOKEN.
- Copiar arquivos do plugin sem rodar o upgrade dá 500 (classes novas precisam de purge/upgrade).
- Nomes de curso/prova saem crus (texto do Moodle): o front do CRM deve ESCAPAR ao exibir.
- Recaptação: só exceção de USUÁRIO (nunca de grupo). O cancelar desfaz só a liberação (attempts) e mantém tempo extra/senha/abertura da FAI.
- Login/SSO do candidato: fora do escopo (pendência com Luciano).
- **Não testar token expirado no token real**: o Moodle APAGA o token com validuntil vencido ao autenticar (webservice/lib.php authenticate_by_token). Use um token descartável.
- Erros do adaptador (1.3.0): `{"message"}` + HTTP; detalhe no log do container (`docker logs fai-moodle-moodle-1 | grep "local_faicrm rest_json"`), procurar pelo X-Request-Id.

---

## Nota: FAI · Referência para o CRM

# FAI · Referência para o CRM
_Dados que o CRM precisa conhecer · conferidos no banco do Moodle local em 06/10/2026_

> ⚠️ **IDs locais ≠ IDs da FAI.** O adaptador não depende do número do `roleid` de estudante: usa o shortname `student`. Já curso, questionário, serviço e usuários **mudam** na FAI: confirmar com o Luciano.

## Endpoint
| Item | Valor |
|---|---|
| URL (JSON) | `POST {baseUrl}/local/faicrm/rest_json.php/<função>` (a forma `?wsfunction=<função>` também vale; se as duas vierem, têm de ser iguais). Contrato completo: `docs/openapi.yaml` |
| baseUrl local | `http://localhost:8080` |
| Headers | `Content-Type: application/json` · `Authorization: Bearer <token>` |
| Token local | arquivo `output/token.txt` (permanente, do usuário `ws_crm`) |
| Alternativa nativa (form) | `POST {baseUrl}/webservice/rest/server.php` + `wstoken`, `wsfunction`, `moodlewsrestformat=json` |

## Papéis (roleid)
| roleid | shortname | Uso |
|---|---|---|
| 1 | manager | gestor do site (não usar) |
| 2 | coursecreator | criador de cursos (não usar) |
| 3 | editingteacher | professor com edição |
| 4 | teacher | professor sem edição |
| **5** | **student** | **candidato: papel padrão da matrícula (o `roleid` é opcional; sem ele o adaptador procura o shortname `student`)** |
| 6 | guest | visitante (não é para matrícula) |
| 7 | user | usuário autenticado (papel automático, não é para matrícula) |
| 8 | frontpage | página inicial (não é para matrícula) |
| 9 | integracaocrm | papel do usuário técnico `ws_crm` (só local; na FAI terá outro id) |

O `roleid` na matrícula é **opcional**: omitido, o adaptador usa o papel de shortname `student` (consulta ao banco, não fixo em 5); enviado, é respeitado. O usuário técnico só tem permissão para atribuir **student**: qualquer outro roleid é recusado com HTTP 403 "Não é permitido matricular com este papel." (nativo: `wsusercannotassign`; testado com 3). Itens com e sem roleid podem ser misturados.

## IDs do ambiente local
| Item | ID | Detalhe |
|---|---|---|
| Curso Vestibular 2027.1 | **courseid = 2** | shortname `VEST20271`, conclusão de curso ligada |
| Questionário (prova) | quizid = 1 · cmid = 2 | "Prova Vestibular 2027.1", nota máx. **1000**, 5 questões (200 cada) |
| Serviço web | serviceid = 2 | "CRM Vestibular FAI" · shortname `crm_vestibular_fai` · só usuários autorizados |
| Usuário técnico | userid = 3 | `ws_crm` (auth manual) |
| Admin | userid = 2 | `admin` |
| Inscrição manual do curso | enrolid = 1 | método `manual`, papel padrão 5, ativo |

## Funções liberadas no serviço
| Função | Para quê |
|---|---|
| `core_user_get_users_by_field` | localizar candidato (`field`=username/email/id/idnumber) |
| `core_user_create_users` | criar candidato → devolve `id` |
| `enrol_manual_enrol_users` | matricular (`userid`, `courseid`; `roleid` opcional) → 204; matrícula manual ativa → 409 |
| `enrol_manual_unenrol_users` | desmatricular → 204; sem matrícula manual → 404 |
| `local_faicrm_get_resultados_vestibular` | resultados: nota + concluído + datas + `tentativas` + `podefazerprova` (`courseid`) |
| `local_faicrm_listar_cursos` | lista cursos (pagina, visivel opcional; inclui ocultos; 100/pág) |
| `local_faicrm_listar_provas` | provas do curso (`courseid`): tentativas, método de nota, abertura/fechamento |
| `local_faicrm_liberar_nova_tentativa` | recaptação: +1 tentativa SÓ para o candidato (`quizid`,`userid`,`prazo` opcional AAAA-MM-DD) → 200 |
| `local_faicrm_cancelar_nova_tentativa` | desfaz a liberação (`quizid`,`userid`) → 204 |
| `core_completion_get_course_completion_status` | conclusão nativa, 1 usuário |
| `gradereport_user_get_grade_items` | notas nativas detalhadas |
| `core_enrol_get_enrolled_users` | matriculados no curso |
| `core_course_get_courses_by_field` | consultar curso (ex.: por shortname) |
| `mod_quiz_get_quizzes_by_courses` | consultar a prova |
| `mod_quiz_get_user_attempts` | tentativas do candidato |

## Regras dos campos do candidato
| Campo | Regra |
|---|---|
| `username` | obrigatório, **único**, minúsculo (CPF só com dígitos funciona) |
| `email` | obrigatório, **único** (`allowaccountssameemail = 0`) |
| `password` | obrigatório; local sem política (`passwordpolicy = 0`); **na FAI pode exigir maiúscula/símbolo** |
| `firstname` / `lastname` | obrigatórios |
| `auth` | `manual` |
| `idnumber` | opcional; usamos o mesmo valor do username |

## Resposta de resultados
Objeto paginado: `{total, pagina, porpagina, totalpaginas, candidatos: [...]}`. Parâmetros: `courseid`, `pagina` (>= 1, padrão 1); **100 por página, fixo** (`porpagina` vem sempre 100 na resposta e NÃO é parâmetro: enviá-lo → 400 "Dados inválidos: porpagina não é aceito; o serviço usa 100 por página."); ordem estável (sobrenome, nome, id); página além da última → `candidatos: []`; fora da faixa → 400 em PT. Para ler tudo: repetir de `pagina` 1 até `totalpaginas`. Cada item de `candidatos`: `username, firstname, lastname, email, courseid, nota, concluido, datamatricula, dataprova, dataconclusao`. As 3 datas são ISO 8601 com fuso (ex.: `2026-10-07T14:32:00-03:00`) ou `null`; `dataprova` = fim da última tentativa finalizada do questionário. Filtros opcionais `dataprovade`/`dataprovaate` (SÓ data `AAAA-MM-DD`, fuso America/Sao_Paulo; "de" = 00:00:00 e "até" = 23:59:59 do dia; com hora → 400): só entram candidatos com prova no intervalo; `total`/`totalpaginas` respeitam o filtro; formato inválido (inclusive com hora) ou de > até → 400 em PT ("Dados inválidos: dataprovade deve estar no formato AAAA-MM-DD."). A resposta continua com data e hora completas
- `nota`: Total do curso, de 0 a 1000; `null` se o candidato ainda não fez a prova.
- `concluido`: `true` quando o curso foi concluído (prova com nota). Depende do cron, que roda a cada 1 min.
- `tentativas`: tentativas da prova finalizadas; `podefazerprova`: `true` se pode iniciar/continuar uma tentativa agora (matrícula ativa, prova visível e aberta, tentativa disponível ou em andamento, considerando a exceção).

## Recaptação (plugin 1.8.0 · spec 004)
Papel `integracaocrm` ganha só `mod/quiz:manageoverrides` (20 capabilities). A prova local passou a permitir 1 tentativa (`attempts=1`). Liberar cria uma exceção de usuário nativa do quiz (`attempts = finalizadas + 1`, `timeclose = prazo` se enviado) SÓ para o candidato; nada é apagado e a nota segue o método da prova (local: maior). Ordem das checagens (liberar): 404 prova/candidato → 409 sem matrícula ativa → 409 tentativa em andamento → 409 ainda pode fazer → 400 prazo inválido/no passado → 400 prova encerrada sem prazo. Cancelar: 404 sem exceção; 409 se já iniciou a extra; senão 204. Bruno 10 a 13b (12 só dá 200 se a Maria já finalizou a prova: simular_prova.php; para refazer mantendo o histórico use --manter).

## Sucesso sem dados = HTTP 204
Matricular e desmatricular (retorno nativo `null`) respondem **204 No Content**, sem corpo e sem Content-Type; trate 204 como sucesso. Funções com dados: 200 + JSON. O endpoint nativo segue respondendo 200 com `null`.

## Erros (adaptador JSON, plugin 1.3.0 · RF-10)
Corpo **só** `{"message": "..."}` em português + status HTTP de erro. Detalhe técnico só no log do Moodle; use o header `X-Request-Id` para achar. Decidir pelo **status**, não pelo texto.
| HTTP | Quando | message |
|---|---|---|
| 400 | corpo não é objeto JSON | O corpo da requisição deve ser um objeto JSON válido. |
| 400 | faltou / inválido `?wsfunction=` | Informe a operação desejada (parâmetro wsfunction). / Operação inválida: verifique o parâmetro wsfunction. |
| 400 | campo faltando / tipo errado / em branco | Dados inválidos: o campo X é obrigatório. / ...verifique o campo X. / ...não pode ficar em branco. |
| 400 | e-mail inválido / senha fora da política | Dados inválidos: e-mail inválido. / A senha não atende à política de senhas do Moodle. |
| 401 | token ausente, errado ou expirado | Token de acesso inválido ou ausente. |
| 403 | função fora do serviço / sem permissão | Operação não permitida para esta integração. |
| 403 | roleid diferente de student | Não é permitido matricular com este papel. |
| 404 | curso / candidato / prova inexistente | Curso não encontrado. / Candidato não encontrado. / Prova não encontrada. |
| 404 | wsfunction não existe | Operação não encontrada: verifique o parâmetro wsfunction. |
| 405 | método diferente de POST | Método não permitido. Use POST. |
| 409 | candidato com matrícula MANUAL ATIVA no curso (suspensa é reativada → 204; outro método não bloqueia → 204; tudo ou nada em lote) | O candidato já está matriculado neste curso. |
| 404 | desmatricular quem não tem matrícula manual no curso (tudo ou nada em lote) | O candidato não está matriculado neste curso. |
| 400 | caminho inválido / caminho e query diferentes | Operação inválida: verifique o caminho da requisição. / Operação inválida: informe wsfunction só no caminho ou só na query. |
| 409 | liberar nova tentativa: sem matrícula ativa / em andamento / ainda pode fazer | O candidato não está matriculado neste curso. / O candidato tem uma tentativa em andamento. / O candidato ainda pode fazer a prova. |
| 409 | cancelar nova tentativa já iniciada | O candidato já iniciou a nova tentativa. |
| 404 | cancelar sem exceção | Não há nova tentativa liberada para este candidato. |
| 400 | liberar: prazo / prova encerrada | Dados inválidos: prazo deve estar no formato AAAA-MM-DD. / ...prazo não pode estar no passado. / A prova está encerrada: informe um prazo para a nova tentativa. |
| 409 | username já existe | Já existe um candidato com este usuário. |
| 409 | e-mail já existe | Já existe um candidato com este e-mail. |
| 409 | curso sem inscrição manual / candidato suspenso | O curso não aceita matrícula manual no momento. / O candidato está suspenso ou com cadastro incompleto no Moodle. |
| 413 | corpo > 1 MB | O corpo da requisição é grande demais (máximo de 1 MB). |
| 500 | falha interna | Erro interno. Tente novamente mais tarde. |
| 503 | WS/REST/serviço desabilitado, manutenção, banco fora | Serviço temporariamente indisponível. Tente novamente mais tarde. |
O endpoint nativo (form) continua com os errorcodes nativos (HTTP 200).

## A confirmar na FAI (Luciano)
- [ ] courseid real do Vestibular 2027.1 e quizid da prova
- [ ] papel student existe com shortname `student` (o adaptador o localiza sozinho)
- [ ] política de senha e `allowaccountssameemail`
- [ ] URL base e o token do serviço em produção

---

## Nota: FAI · Autenticação

# FAI · Autenticação do serviço
_Como o CRM se autentica no Moodle · conferido no código e no banco local em 07/10/2026_

## Resumo
**Sim, é o token nativo de web service do Moodle**, o mesmo `wstoken` de sempre. O adaptador JSON só muda **por onde** o token chega:
- **Antes (nativo):** `wstoken=<token>` dentro do corpo form-urlencoded.
- **Agora (JSON):** header `Authorization: Bearer <token>`. O adaptador copia esse valor para o `wstoken` e chama o servidor REST **nativo** (`WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN`).

Quem valida tudo é o Moodle. Nosso código não decide nada sobre autenticação.

## O token
| Item | Valor local |
|---|---|
| Tipo | **permanente** (`EXTERNAL_TOKEN_PERMANENT`, tabela `mdl_external_tokens`) |
| Pertence a | usuário técnico **`ws_crm`** (id 3, auth manual, não é admin) |
| Vale para | **só** o serviço **"CRM Vestibular FAI"** (`crm_vestibular_fai`) |
| Formato | 32 caracteres hexadecimais |
| Validade | sem vencimento (`validuntil = 0`) |
| Restrição de IP | nenhuma (`iprestriction` vazio) |
| Gerado por | `setup.php` → `\core_external\util::generate_token()` (API oficial). Fica em `output/token.txt` |

## O que o Moodle checa a cada chamada (nativo)
1. **O token existe** e é do tipo permanente → senão **401** "Token de acesso inválido ou ausente."
2. **Não venceu** (`validuntil`). Se venceu, o Moodle **apaga o token** → **401**.
3. **IP permitido** (`iprestriction`), se configurado → **403**.
4. **O usuário `ws_crm` está ativo**: não excluído, não suspenso, confirmado. Se o site estiver em manutenção → **503**.
5. **O serviço está ligado** e o `ws_crm` é **usuário autorizado** nele (o serviço é "só usuários autorizados") → **403**.
6. **A função chamada está no serviço**. As 11 funções liberadas estão na nota "Referência para o CRM"; qualquer outra → **403** (ou **404** se nem existir).
7. **Permissões (capabilities)** do papel "Integração CRM" no contexto do curso. Exemplo: só pode atribuir o papel **student**; outro papel → **403**.
8. **Validação dos parâmetros** da função → **400**.

## O que o nosso adaptador acrescenta (segurança)
- O token **só** é aceito pelo header Bearer. Se vier `wstoken` no corpo ou na query, é **descartado**.
- O token **nunca aparece** na resposta nem no log (as senhas também não).
- Erros saem só com `{"message"}`: sem stack, sem caminhos, sem SQL.
- Sem CORS aberto: o token é para uso **servidor-a-servidor**, nunca no navegador do candidato.
- Limite de 1 MB no corpo, e só POST.
- Testado pela Lupa (QA): token inválido, ausente, expirado e no corpo → **401**; usuário técnico suspenso → **403**; serviço desligado → **503**.

## Está em bom estado?
✅ **Sim, para desenvolvimento e para a demo.** É o mecanismo oficial do Moodle, com mínimo privilégio:
- usuário próprio;
- serviço restrito;
- 11 funções liberadas;
- só pode atribuir o papel student;
- nada de admin.

⚠️ **Para produção na FAI**, recomendo:
| # | Recomendação | Por quê |
|---|---|---|
| 1 | **HTTPS obrigatório** | O token vai no header; sem TLS, ele trafega em texto claro |
| 2 | **Restringir IP** do token (ou do usuário no serviço) aos IPs do backend do CRM | Um token vazado fica inútil fora da rede do CRM |
| 3 | **Token próprio de produção**, gerado no Moodle da FAI (nunca reaproveitar o local) | Separar os ambientes |
| 4 | **Guardar o token num cofre/secret** do CRM, nunca em código, front ou planilha | Requisito do próprio desenho do cliente |
| 5 | **Rotação**: definir validade (ex.: 6–12 meses) e um procedimento de troca | Hoje o token não vence; se vencer, o Moodle apaga e a integração para até gerar outro |
| 6 | **Rate limit / WAF** no proxy da FAI | Nem o Moodle nem o adaptador limitam tentativas (o token tem 128 bits, então adivinhar é inviável, mas o limite protege contra abuso) |
| 7 | Confirmar que o **Apache/proxy repassa o header `Authorization`** para o PHP | Sem isso, o Bearer não chega e tudo vira 401 |
| 8 | Monitorar o evento nativo **`webservice_login_failed`** e reter o **error_log** | Auditoria; o `X-Request-Id` liga a resposta à linha do log |

ℹ️ O Moodle guarda o token **em texto** no banco (`mdl_external_tokens`). Quem tem acesso ao banco tem acesso ao token. É o padrão do Moodle; a mitigação é a restrição de IP (item 2).

## Como trocar o token (rotação)
1. **No Moodle:** Administração → Servidor → Web services → **Gerenciar tokens** → criar um novo para `ws_crm` no serviço "CRM Vestibular FAI" (com validade e IP).
2. **No CRM:** atualizar o secret.
3. **No Moodle:** apagar o token antigo.

Localmente, `docker compose down -v` + `up` gera um token novo em `output/token.txt` (e aí o Bruno precisa ser atualizado).

---

## Nota: FAI · Validação final

# FAI · Validação final (07/10/2026)
_Moodle 4.5.14+ · PHP 8.1 · plugin local_faicrm **1.8.2** · notebook (:8080), simulando o CRM (API) e o candidato (navegador)_

| # | Verificação | Esperado | Resultado |
|---|---|---|---|
| 1 | Ambiente sobe (Docker + setup automático) | Moodle no ar, serviço configurado | ✅ |
| 2 | Localizar candidato inexistente | 200 `[]` | ✅ |
| 3 | Criar candidato (JSON do cliente, com acentos) | 200 `[{id, username}]` | ✅ |
| 4 | Usuário ou e-mail repetido | 409 com message em PT | ✅ |
| 5 | Matricular sem roleid | 204, entra como estudante | ✅ |
| 6 | Matricular como professor (roleid 3) | 403 "Não é permitido matricular com este papel." | ✅ |
| 7 | Resultados logo após a matrícula | `nota null, concluido false`, `datamatricula` preenchida, `dataprova null` | ✅ |
| 8 | **Prova real pelo navegador** (login do candidato, 4/5 acertos, envio) | 800/1000, tentativa "Finalizada" | ✅ |
| 9 | Resultados depois da prova | `nota 800, concluido true`, `dataprova` = fim da tentativa (`2026-10-07T10:32:10-03:00`) | ✅ conclusão imediata |
| 10 | Datas conferidas no banco | horário em UTC = horário -03:00 da API | ✅ |
| 11 | Filtro por data da prova (hoje / ontem) | hoje → 1; ontem → 0 | ✅ |
| 12 | Filtro com hora ou data inválida | 400 "…formato AAAA-MM-DD." | ✅ |
| 13 | Paginação (`pagina`/`porpagina`) | ninguém repete nem falta; limites inválidos → 400 | ✅ |
| 14 | Volume (1500 candidatos, QA) | 12–16 consultas SQL por página; ~120–240 ms | ✅ |
| 15 | Desmatricular | 204 | ✅ |
| 16 | Token inválido / ausente | 401 | ✅ |
| 17 | Função fora do serviço / curso inexistente | 403 / 404 | ✅ |
| 18 | JSON malformado / GET / corpo > 1 MB | 400 / 405 / 413 | ✅ |
| 19 | Erros sem vazamento (stack, caminho, SQL, token) | só `{"message"}` | ✅ |
| 20 | Headers de segurança e log com X-Request-Id, sem token | presentes | ✅ |
| 21 | Endpoint nativo do Moodle (form) | continua funcionando | ✅ |
| 22 | Coleção Bruno real (2 rodadas seguidas, candidato novo a cada vez) | tudo verde | ✅ 17/17 req · 23/23 testes |
| 23 | Relatório de notas do curso (admin, navegador) | candidato com 800 na prova e no total | ✅ console sem erros |
| 24 | Repositório: tudo commitado e enviado, token fora do Git | limpo | ✅ `34b4b49` |
| 25 | Matricular de novo → 409; desmatricular de novo → 404 | mensagens em PT | ✅ |
| 26 | Matrícula suspensa é reativada; matrícula por outro método não bloqueia | 204 | ✅ |
| 27 | Itens por página fixos em 100 (`porpagina` → 400) | 400 em PT | ✅ |
| 28 | Operação no caminho = operação na query | respostas idênticas; divergente → 400 | ✅ |
| 29 | OpenAPI (`docs/openapi.yaml`) | lint válido; exemplos = API real | ✅ |
| 30 | Bruno com datas prontas (hoje, 30 dias, sem provas) | 22/22 req · 29/29 testes | ✅ |
| 31 | **Recaptação de ponta a ponta (1.8.2)**: Maria faz a prova no navegador (400) → CRM libera → só ela vê "Fazer uma outra tentativa" com o prazo 31/12 → refaz no navegador (800) → resultados com tentativas 2 | o João, na mesma situação, continua bloqueado | ✅ |
| 32 | Erros da recaptação (liberar antes da prova, liberar 2x, cancelar 2x, cancelar ou liberar com a tentativa em andamento, cancelar depois de usada) | 409/404 | ✅ |
| 33 | Catálogo (cursos, só visíveis, provas do curso) e coleção Bruno completa (31 requisições) | status esperados | ✅ |
| — | Pendências com a FAI (IDs reais, senha, e-mail, header Authorization, SSO) | depende do Luciano | ⏳ |
