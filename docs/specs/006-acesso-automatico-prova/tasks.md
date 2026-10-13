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
| T8 | 1.9.1: cursos liberados (D-11), testes, docs e reteste | RF-28b, CA-37 | ✅ |

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

## Resultado da 1.9.1 (13/10)
- **PHPUnit:** 76 testes, 177 asserções, verde. **moodle-cs:** 0 erros e 0 avisos.
- **QA (Lupa), CA-37:** OK.
  - só o curso liberado gera link;
  - outro curso da mesma categoria → 403;
  - aluno de outro curso → 403;
  - nada marcado → 503;
  - categoria e curso juntos → a união dos dois;
  - curso excluído é ignorado.
- **Upgrade 1.9.0 → 1.9.1 pelo painel:** OK. Regressão Bruno: 6/6.
- **Pacote:** `dist/local_faicrm-1.9.1.zip`, SHA-256 `59b56dca43dafb220425e480e00b7c6973001744901405d6723b1df4cbac19e1`.
- **Conhecido (baixo):** num Moodle **sem nenhum curso**, o campo "Cursos liberados" fica sem opções e não é gravado. Por isso, a página Notificações volta sempre para "Novas configurações". Some ao criar o primeiro curso. A produção não é afetada.
