# 007 — Tarefas

| # | Tarefa | RF/CA | Status |
|---|---|---|---|
| T1 | Testes (vermelho) do `retorno` e do `link::login_url` | CA-38 | ✅ |
| T2 | `retorno.php`, `iniciar.php`, `concluido.php`, observer, hook, settings, diagnóstico, strings, 1.10.0 (verde, phpcs) | RF-29–34 | ✅ |
| T3 | Revisão de segurança do código (Muralha) | CA-41 | ✅ |
| T4 | Navegador: enviar, tempo acabando, sem efeito em quem não deve (Lupa) | CA-39, CA-40 | ✅ |
| T5 | Roteiro do painel, contrato do CRM, OpenAPI, CHANGES, sdd; regressão; release 1.10.0 | CA-42 | ✅ |

## Resultado (14/10)
- **PHPUnit:** 149 testes, 357 asserções, verde. **moodle-cs:** 0 erros e 0 avisos.
- **Segurança (Muralha):** revisão do desenho e do código, sem crítico nem alto. Corrigidos:
  - a marcação presa ao link por HMAC;
  - o observer só depois do commit;
  - a trava contra laço;
  - a URL revalidada na hora de usar;
  - o segredo criado na instalação ou na atualização;
  - o prefixo de finalidade na assinatura.
- **QA (Lupa), no navegador:** CA-39 e CA-40 OK, inclusive o tempo acabando, "acessar como", o professor e o cron. Também conferidos o `sesskey` faltando ou errado, o parâmetro malicioso ignorado, os cabeçalhos e a troca do cookie. Regressão da 006 OK.
- **Versão:** release 1.10.0, `version` 2026101401. O passo de atualização precisou de uma versão acima da 2026101400 já instalada nos ambientes de teste.
- **Pacote:** `dist/local_faicrm-1.10.0.zip`, SHA-256 `5bfc1afc6f4fc8d3d32a9a22622f46aec15f1cbb177702822f66622b71c6f027`.
