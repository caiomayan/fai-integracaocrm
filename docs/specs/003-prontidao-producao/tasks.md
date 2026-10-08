# 003 — Tarefas

| # | Tarefa | Reqs | Dono | Status |
|---|---|---|---|---|
| P1 | Privacy provider; classe de configuração + `cli/configurar.php` + `cli/verificar.php`; `setup.php` passa a usar a classe; README/CHANGES do plugin; `scripts/empacotar-plugin.sh`; `docs/implantacao-producao.md`; servidor de produção (placeholder) no openapi.yaml; versão 1.7.0 | RP-01…RP-04, RP-06, RP-07 | Ferro (Sonnet · medium) | ✅ |
| P2 | Moodle Code Checker (moodle-cs) no plugin + correções; revisão de segurança dos scripts (senha e token nunca em log ou arquivo) | RP-05 | Muralha (Opus · high) | ✅ |
| P3 | QA de produção simulada: Moodle limpo, instalação pelo zip, só os scripts (CP-01…CP-04) + regressão local (CP-06) | CP-01…CP-06 | Lupa (Opus · medium) | ✅ |

## Resultado (2026-10-08): APROVADO para homologação
- P1 (Ferro): privacy provider, `cli/configurar.php`, `cli/verificar.php`, zip, guia.
- P2 (Muralha): phpcs moodle com 0 erros e 0 avisos. Corrigido bug **alto**: validade na autorização bloqueava todas as chamadas no Moodle 4.5. Senha do ws_crm via CSPRNG; empacotamento com lista do que pode entrar no zip.
- P3 (Lupa): produção simulada (Moodle limpo, política de senha ligada, curso id 3, zip validado pelo validador do Moodle, só scripts). CP-01 a CP-06 OK; 6 correções no guia (descompactação, `--ip`, wwwroot, cron inicial, Bruno com dados fixos, plano de volta atrás testado).
- Plugin **1.7.1** (2026100801).
