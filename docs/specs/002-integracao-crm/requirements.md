# 002 — Integração CRM ↔ Moodle (Vestibular FAI) — Requisitos

## Contexto
O CRM (de terceiros) decide quando o candidato está apto à prova. O Moodle recebe o candidato, matricula no curso do Vestibular e devolve nota e conclusão. Testamos simulando o CRM com **Bruno**.

Fluxo: CRM → cria/localiza usuário → matricula no curso → candidato faz a prova (quiz) → Moodle corrige → CRM consulta nota + concluído.

## Requisitos funcionais
- **RF-01 Localizar candidato** por `username` (função nativa `core_user_get_users_by_field`).
- **RF-02 Criar candidato** com `username, password, firstname, lastname, email` (`auth=manual`, `idnumber` opcional) via `core_user_create_users`; o Moodle devolve o `id` gerado.
- **RF-03 Matricular** o candidato no curso com `roleid=5` (student) via `enrol_manual_enrol_users` (`courseid`, `userid`, `roleid`).
- **RF-04 Desmatricular** via `enrol_manual_unenrol_users`.
- **RF-05 Resultados**: uma chamada por curso devolve um array, um item por candidato (estudante do curso):
  `username, firstname, lastname, email, courseid, nota, concluido`.
  - `nota` = **Total do curso** no gradebook (float; `null` se ainda sem nota).
  - `concluido` = **conclusão do curso** (course completion), boolean.
  - CPF não faz parte da resposta (o `username` provavelmente será o CPF, mas isso não é tratado aqui).
- **RF-07 API JSON**: todas as chamadas aceitam corpo **JSON** (`Content-Type: application/json`) no mesmo formato dos exemplos do cliente (`{"users":[...]}`, `{"enrolments":[...]}`) e respondem em **JSON**. Motivo: desacoplar do formato do Moodle, facilitar troca de CRM/plataforma e simular no Bruno. O endpoint nativo form-urlencoded continua funcionando.
- **RF-08 roleid opcional na matrícula** (endpoint JSON): em `enrol_manual_enrol_users`, cada item de `enrolments` pode omitir `roleid`; nesse caso usa-se o papel **student**, procurado pelo shortname `student` (não fixo em 5, para funcionar em qualquer Moodle). Se `roleid` for enviado, ele é respeitado. A permissão do `ws_crm` continua **só student** (decisão do Caio, 06/10): outro papel → `wsusercannotassign`.
- **RF-09 HTTP 204** (endpoint JSON): quando o retorno nativo da função é `null` (ex.: matricular, desmatricular), o adaptador responde **204 No Content**, sem corpo.
- **RF-10 Erros prontos para o front** (endpoint JSON): todo erro responde com corpo **apenas** `{"message": "<descrição em português, clara para o usuário final>"}` e **status HTTP coerente** (decisão do Caio, 06/10): 400 dado inválido · 401 token ausente/inválido · 403 sem permissão · 404 não encontrado · 405 método · 409 duplicado (username/e-mail) · 500 erro interno · 503 serviço desabilitado. Detalhes técnicos (exception, errorcode, debuginfo, caminhos) **nunca** vão na resposta: vão para o log do servidor, com um id de correlação devolvido no header `X-Request-Id`. Sucessos não mudam (200 + JSON, ou 204).
- **RF-11 Paginação dos resultados** (07/10): `local_faicrm_get_resultados_vestibular` aceita `pagina` (≥ 1, padrão 1) e `porpagina` (1–500, padrão 100). A paginação acontece **no banco** e nota e conclusão são buscadas **só para os candidatos da página** (conclusão em lote, sem uma consulta por candidato). Ordem estável: sobrenome, nome, id. **Mudança de contrato:** a resposta passa a ser um objeto `{total, pagina, porpagina, totalpaginas, candidatos: [...]}` (cada candidato com os mesmos 7 campos de antes).
- **RF-12 Datas e filtro por data da prova** (07/10, ideia discutida com o Luciano): cada candidato nos resultados ganha `datamatricula`, `dataprova` e `dataconclusao`, em ISO 8601 com fuso (ex.: `"2026-10-07T14:32:00-03:00"`), ou `null` quando não existe. `dataprova` = horário em que a **tentativa** (attempt) do questionário foi **finalizada**; se houver várias, vale a última finalizada. Filtros opcionais `dataprovade` e `dataprovaate` aceitam **só data** `AAAA-MM-DD` (decisão do Caio, 07/10): `de` = 00:00:00 e `até` = 23:59:59 do dia, fuso America/Sao_Paulo. A **resposta** continua com data e hora completas. Com filtro, só entram candidatos com `dataprova` dentro do intervalo; `total` e paginação respeitam o filtro. Decisão do Caio: só o filtro por data da prova, por enquanto.
- **RF-06 Serviço dedicado** "CRM Vestibular FAI" com **apenas** as funções necessárias, usuário técnico próprio e token permanente. Sem acesso administrativo amplo.

## Requisitos não funcionais
- **RNF-01** Cadastro/matrícula executados por funções nativas; código próprio só para RF-05 (somente leitura) e para o adaptador JSON (RF-07), que **não** reimplementa autenticação, permissão nem validação: delega ao servidor REST nativo.
- **RNF-02** Compatível com Moodle 4.5.x / PHP 8.1 (o plugin precisa ser instalável no Moodle da FAI).
- **RNF-03** Ambiente local reproduzível: `docker compose down -v && up -d --build` recria tudo, inclusive serviço, token, curso e prova de teste.
- **RNF-04** Token só no backend (Bruno/CRM), nunca no navegador do candidato.

## Critérios de aceite
- **CA-01** Após subir o ambiente, `output/token.txt` contém um token que funciona em `/webservice/rest/server.php`.
- **CA-02** Criar candidato devolve `[{id, username}]`; criar de novo com o mesmo username devolve erro nativo (sem duplicar).
- **CA-03** Matricular com o id devolvido retorna `null` (sucesso nativo) e o candidato aparece em Participantes como Estudante.
- **CA-04** Resultados logo após a matrícula: candidato com `nota: null, concluido: false`.
- **CA-05** Depois que o candidato faz a prova (pelo navegador ou pelo helper de simulação) e o cron roda: `nota` = nota obtida (escala 0–1000) e `concluido: true`.
- **CA-06** Token inválido → `invalidtoken`; função fora do serviço → `accessexception`.
- **CA-07** A coleção Bruno executa o fluxo completo (localizar → criar → matricular → resultados) usando só variáveis de ambiente, com **corpo JSON**.
- **CA-08** O endpoint JSON aceita exatamente os JSONs de exemplo do cliente para criar e matricular e devolve as mesmas respostas do endpoint nativo.
- **CA-09** JSON malformado → erro JSON `invalidjson`; método diferente de POST → erro JSON `methodnotallowed` (HTTP 405); sem token → erro nativo em JSON; chaves `wstoken`/`wsfunction`/`moodlewsrestformat` no corpo não sobrescrevem o header/query.
- **CA-11** Matrícula JSON sem `roleid` → 204 e o candidato fica como student; com `"roleid":5` → 204; com `"roleid":3` → `wsusercannotassign`; itens mistos (com e sem roleid) na mesma chamada funcionam.
- **CA-12** Matricular e desmatricular com sucesso → HTTP 204, corpo vazio; funções que retornam dados continuam 200 + JSON; erros continuam JSON.
> **Nota (RF-10):** no endpoint JSON, o formato de erro de CA-02, CA-06, CA-09 e CA-11 passa a ser `{"message"}` + status HTTP da tabela do design (ex.: token inválido = 401, não mais `errorcode: invalidtoken`). No endpoint nativo, esses CAs continuam como estão.
- **CA-13** Cada erro conhecido (JSON inválido, sem wsfunction, método, token inválido/ausente, função fora do serviço, papel não permitido, username duplicado, e-mail duplicado, parâmetro faltando ou com tipo errado, curso inexistente, erro interno) devolve o status da tabela do design e corpo só com `message` em português.
- **CA-14** Nenhuma resposta de erro contém stack trace, caminho de arquivo, nome de classe/exception, SQL ou o token; o log do servidor tem o detalhe e o mesmo `X-Request-Id`.
> **Nota (RF-11):** em CA-04, CA-05 e CA-08, os candidatos passam a vir dentro de `candidatos` no objeto paginado.
- **CA-15** Com N candidatos: sem parâmetros → página 1 com até 100 candidatos e `total = N`; percorrer todas as páginas devolve cada candidato **exatamente uma vez** (sem repetidos nem faltando, inclusive com nomes iguais); página além da última → `candidatos: []` com os metadados corretos; `porpagina` 0, negativo ou maior que 500, ou `pagina` < 1 → 400 com message em PT; curso sem candidatos → `total: 0, totalpaginas: 0, candidatos: []`.
- **CA-16** Custo: o número de consultas SQL de uma página **não cresce** com o total de candidatos do curso (medido com ≥ 1000 candidatos) e o tempo de uma página de 100 fica na mesma ordem de uma chamada com poucos candidatos.
- **CA-17** Candidato só matriculado: `datamatricula` preenchida, `dataprova` e `dataconclusao` `null`. Depois da prova: `dataprova` = fim da tentativa finalizada, e `dataconclusao` preenchida após a conclusão. Datas em ISO 8601 com `-03:00`, batendo com o banco.
- **CA-18** Filtro: só `dataprovade`, só `dataprovaate`, os dois, e um dia exato (`de` = `até` = mesma data) devolvem só os candidatos certos, com `total` e `totalpaginas` coerentes; candidatos sem prova ficam de fora quando há filtro; formato inválido (inclusive data com hora) ou `de` > `até` → 400 com message em PT; sem filtro, o comportamento é igual ao do RF-11.
- **CA-19** Custo: com filtro e com volume (≥ 1000 candidatos), as consultas SQL por página continuam sem crescer com o total.
- **CA-10** O endpoint nativo `/webservice/rest/server.php` (form-urlencoded) continua funcionando como antes.

## Fora do escopo
Login transparente/SSO do candidato; status "aprovado"; webhooks do Moodle → CRM.
