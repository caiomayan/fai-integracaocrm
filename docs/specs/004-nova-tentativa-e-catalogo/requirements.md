# 004 — Nova tentativa (recaptação) e catálogo de cursos e provas

## Contexto
Pedido do Caio depois da apresentação (08/10): o CRM precisa oferecer a um candidato do passado a chance de **refazer a prova**, por exemplo para quem tirou nota baixa. Também precisa **listar os cursos** do Moodle, com o atributo ativo/visível, e **as provas de um curso**.

## Decisões (tomadas com o Caio)
- **D-01** Refazer = **liberar nova tentativa** com uma exceção de usuário nativa do Moodle (*user override*), **só para aquele candidato**. Nada é apagado: as tentativas antigas ficam no histórico.
- **D-02** A nota segue a **regra de nota do questionário** configurada pela FAI. Com "maior nota", refazer nunca piora.
- **D-03** Prazo **opcional** enviado pelo CRM (`prazo`, `AAAA-MM-DD`, vale até 23:59:59 de Brasília, só para aquele candidato).
- **D-04** "Ativo" na lista de cursos = `visivel` + `datainicio`/`datafim`.
- **D-05** Liberar exige o candidato **matriculado** (matrícula ativa) no curso da prova; senão → 409.
- **D-06** Se o candidato **ainda pode fazer** a prova (tem tentativa disponível) ou tem **tentativa em andamento** → 409, sem conceder nada.
- **D-07** Resultados ganham `tentativas` e `podefazerprova`.
- **D-08** Existe rota para **cancelar** a nova tentativa liberada.

## Requisitos
- **RF-17 Listar cursos** — `local_faicrm_listar_cursos`: parâmetros `pagina` (≥ 1, padrão 1; 100 por página fixo, como no RF-14) e `visivel` (opcional, booleano). Devolve `{total, pagina, porpagina, totalpaginas, cursos: [{id, shortname, nome, categoriaid, categoria, visivel, datainicio, datafim}]}`. Exclui o curso da página inicial (id 1). Inclui os cursos ocultos (com `visivel: false`). Datas em ISO 8601 com fuso ou `null` (`datafim` 0 → `null`). Ordem: nome, id.
- **RF-18 Listar provas de um curso** — `local_faicrm_listar_provas`: parâmetro `courseid`. Devolve `{courseid, provas: [{id, cmid, nome, visivel, notamaxima, tentativaspermitidas, metodonota, abertura, fechamento}]}`.
  - `tentativaspermitidas`: 0 = ilimitado.
  - `metodonota`: `maior`, `media`, `primeira` ou `ultima`.
  - Datas em ISO 8601 ou `null`.
  - Curso inexistente → 404.
- **RF-19 Liberar nova tentativa** — `local_faicrm_liberar_nova_tentativa`: parâmetros `quizid`, `userid` e `prazo` (opcional). Regras, nesta ordem:
  - Prova inexistente → 404 "Prova não encontrada."; candidato inexistente → 404 "Candidato não encontrado.".
  - Sem matrícula ativa no curso → 409 "O candidato não está matriculado neste curso.".
  - Tentativa em andamento → 409 "O candidato tem uma tentativa em andamento.".
  - Ainda tem tentativa disponível (limite ilimitado, ou finalizadas < limite efetivo, considerando uma exceção existente) → 409 "O candidato ainda pode fazer a prova.".
  - `prazo` inválido ou no passado → 400.
  - Prova encerrada (fechamento no passado) e sem `prazo` → 400 "A prova está encerrada: informe um prazo para a nova tentativa.".
  - Sucesso: cria ou atualiza a exceção do candidato com `attempts = finalizadas + 1` e `timeclose = prazo`, se informado. Usar a API nativa (`\mod_quiz\local\override_manager` do 4.5), que dispara eventos e limpa cache. Responde **200** `{quizid, userid, tentativaspermitidas, prazo}`.
- **RF-20 Cancelar nova tentativa** — `local_faicrm_cancelar_nova_tentativa`: parâmetros `quizid` e `userid`.
  - Sem exceção para o candidato → 404 "Não há nova tentativa liberada para este candidato.".
  - Já iniciou ou finalizou a tentativa que a liberação concedeu (finalizadas + em andamento **≥** o limite que a exceção gravou, que é finalizadas no momento da liberação + 1; vale para a 1ª e para as liberações seguintes, corrigido no N3) → 409 "O candidato já iniciou a nova tentativa.".
  - Sucesso → **204**. Remove a exceção criada pelo liberar. **Refinado no N2:** se a exceção também tiver ajustes da FAI (abertura, tempo ou senha), só a liberação (attempts) é desfeita e o resto fica. Uma exceção sem attempts (só acessibilidade) não conta como liberação → 404.
- **RF-20b Restaurar o estado anterior (N3, achado da Lupa, corrigido pelo Muralha):** o liberar registra numa **preferência do candidato** (`local_faicrm_novatentativa_<quizid>`, JSON pequeno) como a exceção estava antes e o que ele gravou. O cancelar faz compare-and-restore: volta attempts e prazo ao que eram antes; se não havia exceção, ela é removida. Um prazo que a FAI mudou depois da liberação é mantido. Sem o registro (liberação antiga), mantém o prazo e só desfaz attempts.
- **RF-20a Concorrência (N2):** liberar e cancelar rodam sob uma trava por (prova, candidato). Chamadas simultâneas não criam exceções duplicadas. Se a trava não vier em 10 s → 503 genérico.
- **RF-21 Resultados com tentativas** — `local_faicrm_get_resultados_vestibular` ganha, em cada candidato:
  - `tentativas`: tentativas **finalizadas** nas provas do curso (preview fora);
  - `podefazerprova`: `true` se pode iniciar ou continuar uma tentativa agora, considerando matrícula ativa, prova visível, abertura/fechamento (inclusive da exceção) e o limite efetivo.
  - Cálculo em lote por página, sem consulta por candidato (mantém o CA-16/CA-19).
- **RF-22 Serviço e permissões:** as 4 funções novas entram no serviço `crm_vestibular_fai`. O papel `integracaocrm` ganha **só** `mod/quiz:manageoverrides` (o configurador e o verificar.php passam a conhecer 20 capabilities). A versão do plugin vai para **1.8.0**.

## Critérios de aceite
- **CA-24** Listar cursos: o curso oculto aparece com `visivel: false`; o filtro `visivel` funciona; a paginação segue as regras do RF-14; datas corretas contra o banco.
- **CA-25** Listar provas: os campos batem com a configuração do questionário (limite, método, nota máxima, abertura e fechamento).
- **CA-26** Fluxo de recaptação:
  - candidato fez a prova (nota 400) → liberar → 200 → só ele ganha +1 (outro candidato no mesmo estado continua sem tentativa) → `podefazerprova: true` → refaz pelo navegador ou pelo simulador (nota 800) → resultados com `tentativas: 2` e nota de acordo com o método;
  - liberar de novo antes de refazer → 409 "ainda pode fazer";
  - durante a tentativa → 409 "em andamento".
- **CA-27** Regras de erro do RF-19 e do RF-20 (não matriculado, prova encerrada sem prazo, prazo no passado, cancelar sem exceção, cancelar depois de iniciar) com os status e mensagens definidos; cancelar antes de iniciar → 204 e `podefazerprova: false`.
- **CA-28** Prazo: com `prazo`, a exceção tem o fechamento no fim daquele dia e só vale para o candidato.
- **CA-29** Desempenho dos resultados mantido (consultas por página não crescem com o volume) com os campos novos.
- **CA-30** Regressão das specs 002 e 003 (Bruno, verificar.php, configurar.php idempotente) + OpenAPI, contrato e coleção Bruno atualizados.
