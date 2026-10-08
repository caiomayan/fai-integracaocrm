# 004 — Tarefas

| # | Tarefa | Reqs | Dono | Status |
|---|---|---|---|---|
| N1 | Funções listar_cursos, listar_provas, liberar_nova_tentativa e cancelar_nova_tentativa; campos novos nos resultados; serviço e capability; mensagens e status no adaptador; versão 1.8.0; OpenAPI, contrato, implantação, Bruno (JSON fixo) e nota de referência | RF-17…RF-22 | Ferro (Sonnet · medium) | ✅ |
| N2 | Revisão de segurança: capability nova, escopo da exceção (só o candidato), sem escalada | RF-19, RF-20, RF-22 | Muralha (Opus · high) | ✅ |
| N3 | QA: CA-24…CA-30, com recaptação pelo navegador/simulador, volume e regressão | CA-24…CA-30 | Lupa (Opus · medium) | ✅ |

## Resultado (2026-10-08): APROVADO — plugin 1.8.2
- **N1 (Ferro):** 4 funções novas, `tentativas`/`podefazerprova` em lote, capability `mod/quiz:manageoverrides`, docs e Bruno.
- **N2 (Muralha):** o cancelar preserva os ajustes da FAI; trava contra corrida (30 simultâneos → 1 exceção); exceção vazia removida.
- **N3 (Lupa):** CA-24 a CA-30 OK, inclusive volume (1500 candidatos, 828 exceções: `podefazerprova` com 0 divergências contra o cálculo direto no banco; 18 consultas por página, constante), instalação do zero pelo zip e Bruno com 31/31.
- **Correções ALTAS durante o QA:**
  1. O cancelar comparava com o limite original, então liberações seguintes nunca podiam ser canceladas (Ferro).
  2. O prazo da FAI se perdia ao cancelar: agora há compare-and-restore com uma preferência do candidato (Muralha, RF-20b).
- **Infos documentadas no contrato:** exceções de grupo fora do `podefazerprova`; 503 da trava; escapar nomes no front.
