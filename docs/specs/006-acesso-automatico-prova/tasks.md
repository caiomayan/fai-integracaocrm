# 006 — Tarefas

| # | Tarefa | RF/CA | Status |
|---|---|---|---|
| T1 | PHPUnit no ambiente Docker (composer, locale, `phpunit_prefix`) e `auth_userkey` 2022081902 na imagem | CA-31 | ✅ |
| T2 | Testes (vermelho): `tests/external/gerar_link_prova_test.php` e `tests/rest_json/handler_test.php` | CA-31 | ✅ |
| T3 | `gerar_link_prova`, `db/access.php`, `db/services.php`, handler, strings, versão 1.9.0 (verde) | RF-23, RF-25, RF-27 | ✅ |
| T4 | Fluxo real no navegador (Playwright) e segurança dos tokens | CA-32, CA-33 | ✅ |
| T5 | Papel `implantacao/papel-ssocrm.xml` e roteiro só pelo painel, validado num Moodle limpo | RF-25, CA-34 | ✅ |
| T6 | Bruno, OpenAPI, contrato, CHANGES, README, sdd.md; moodle-cs; regressão | CA-35 | ✅ |
| T7 | Pacote `local_faicrm-1.9.0.zip` e nota para o time do CRM | CA-35 | ✅ |

## Resultado (13/10)
- **PHPUnit:** 68 testes, 163 asserções, verde. **moodle-cs** (moodle e moodle-extra): 0 erros, 0 avisos.
- **QA (Lupa):** detalhes em `output/teste-spec006-2026-10-13.md` (local, fora do Git).
  - CA-32: fluxo no navegador, inclusive o caso "logado como outra pessoa", depois da `entrar.php`;
  - CA-33: segurança;
  - CA-34: instalação e configuração só pelo painel, num Moodle limpo;
  - CA-35: regressão Bruno, 33/33;
  - CA-36: categorias.
- **Segurança (Muralha):** revisões do desenho e do código, sem crítico nem alto pendente. O risco aceito é o T-09: o `/auth/userkey/login.php` aberto direto desloga.
- **Pacote:** `dist/local_faicrm-1.9.0.zip`, SHA-256 `966117ff964c2f14dcadfa8fc98db9f6cdd4bb7131178b3068708e853fef013f`.
