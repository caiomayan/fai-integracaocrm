# 007 — Design

## Fluxo
```
CRM → 4.10 → url = …/local/faicrm/entrar.php?key=K&cmid=C
entrar.php → /auth/userkey/login.php?key=K&wantsurl=…/local/faicrm/iniciar.php?cmid=C&exp=E&sig=H   (antes: wantsurl = a prova)
login por chave
  → iniciar.php: confere H = HMAC(userid|C|E) com o usuário logado e E ainda válido
  → $SESSION->local_faicrm_retorno = {cmid C} → /mod/quiz/view.php?id=C
… prova … "Enviar tudo e terminar" (ou o tempo acabou)
evento attempt_submitted, depois do commit (studentisonline, cmid = C, mesmo usuário, URL válida)
  → $SESSION->local_faicrm_retorno_pendente = {cmid, attemptid, userid, time}
processattempt.php → review.php
  → hook before_http_headers (pendente + página da prova C + tentativa 'finished' + URL válida)
  → redirect concluido.php?sesskey=…
concluido.php: consome o pendente (1ª linha) → confirm_sesskey → require_logout() → tela "Prova enviada…"
  → 5 s / "Voltar agora" → <retorno_url>?status=concluida&cmid=C
```

## Arquivos
| Arquivo | Mudança |
|---|---|
| `classes/sso/retorno.php` | o retorno inteiro: configuração, URL final, marcação, pendente, decisão de desvio, consumo |
| `classes/sso/link.php` | `login_url()` passa a usar `wantsurl = iniciar.php?cmid=…` |
| `iniciar.php` | marca a sessão (RF-29) e vai para a prova |
| `concluido.php` | tela de confirmação (RF-32) |
| `db/events.php` | observer de `\mod_quiz\event\attempt_submitted` → `retorno::on_attempt_submitted` |
| `db/hooks.php` | callback de `\core\hook\output\before_http_headers` → `retorno::before_http_headers` |
| `settings.php` | `retorno_url` (texto validado) e `retorno_mensagem` (área de texto) |
| `classes/sso/diagnostico.php` | item "retorno após a prova" |
| `lang/en`, `lang/pt_br` | strings |
| `version.php` | 1.10.0 / 2026101400 |
| `tests/sso/retorno_test.php` | PHPUnit (CA-38) |

## Decisões técnicas
- **T-01 Marcação presa ao link (Muralha, MÉDIO-1).**
  - A `link::login_url()` põe no `wantsurl` a `iniciar.php` com `cmid`, `exp` (o `validuntil` da chave) e `sig` = HMAC-SHA256 de `userid|cmid|exp`.
  - O segredo é o `local_faicrm/sso_secret`, com `random_string(64)` gerado na primeira vez que for usado. Nunca é exibido nem logado.
  - A `iniciar.php` exige login e confere `hash_equals(sig, HMAC($USER->id|cmid|exp))` e `time() <= exp + 60`. Só então grava a marcação.
  - Sem `sig`, com `sig` de outro usuário ou de outra prova, ou vencido: **não marca nada** e só vai para a prova.
  - Por isso a remarcação manual, ou por CSRF, de `iniciar.php?cmid=Y` não funciona.
  - Não depende do `$SESSION->userkey`, que é um detalhe interno do plugin de terceiros. Com isso, o caso "o candidato já estava logado como ele mesmo" também ganha retorno.
  - Ignora qualquer outro parâmetro e redireciona sempre para `/mod/quiz/view.php?id=<cmid>`, com o `cmid` como PARAM_INT.
  - Por que não na sessão, pela `entrar.php`: gravar no `$SESSION` depois do `require_logout()`, na mesma requisição, é o problema de sessão fechada que o Lupa achou na spec 006.
- **T-02 O evento roda na requisição do candidato, depois do commit (Muralha, MÉDIO-2).**
  - Observer com `'internal' => false`: ele roda depois do commit da transação do `process_finish`, ainda na mesma requisição e com o mesmo `$SESSION`.
  - O observer grava o pendente `{cmid, attemptid, userid, time}` só se:
    - `studentisonline === true`;
    - `relateduserid == submitterid == $USER->id`;
    - não for "acessar como" (`\core\session\manager::is_loggedinas()`);
    - o `cmid` do evento (`contextinstanceid`) for o da marcação;
    - a URL de retorno for válida.
  - Ele **nunca redireciona e nunca lança exceção**.
  - No envio pelo cron, a sessão é outra, não tem marcação e `studentisonline` é falso: nada acontece.
- **T-03 O desvio acontece na próxima página**, no hook `before_http_headers`.
  - Sai na primeira linha se não houver pendente, então o custo nas outras páginas é zero.
  - Só desvia se:
    - a página for `mod-quiz-review` ou `mod-quiz-view`;
    - `$PAGE->cm->id` for o `cmid` do pendente;
    - a tentativa do pendente estiver com `state = 'finished'` e for do `$USER->id`;
    - a URL de retorno for válida agora.
  - Se alguma condição não fechar, **descarta o pendente** e segue o fluxo normal. Nunca desvia um professor revisando a tentativa, porque o pendente só existe na sessão do candidato.
  - O desvio vai para `concluido.php?sesskey=<sesskey>`.
- **T-04 `concluido.php` (Muralha, MÉDIO-3a e BAIXO-2):**
  - **Primeira linha depois do require:** lê e **apaga** o pendente (uso único). Assim não há laço, mesmo que algo falhe depois.
  - Sem pendente, sem `confirm_sesskey()` ou com URL inválida → `redirect($CFG->wwwroot)`, sem deslogar.
  - Com tudo certo: monta a URL, chama `require_logout()` antes de qualquer saída e mostra a página com `pagelayout` `login` (ou `embedded`), sem menu, blocos ou nome do usuário.
  - A mensagem configurada sai com `nl2br(s(...))`, nunca como HTML. A URL vai com `s($url->out(false))` no `meta refresh` (5 s) e no `href` do botão, que leva `rel="noreferrer"`.
  - Envia `Referrer-Policy: no-referrer` e `Cache-Control: no-store`.
  - Não lê nenhum parâmetro além do `sesskey`. `url`, `return`, `cmid` e outros são ignorados.
- **T-05 URL final:** `retorno::build_url(string $base, int $cmid)` usa `moodle_url` com a URL base, que mantém a query existente, e acrescenta `status=concluida` e `cmid`. Valores já presentes com esses nomes são substituídos.
- **T-06 Validação da URL (Muralha, MÉDIO-3b):** a mesma regra vale **ao salvar** (o `validate()` da setting) e **na hora de usar** (`retorno::url()`), porque a configuração pode vir de `forced_plugin_settings` ou do `cfg.php`, ou o site pode mudar de http para https.
  - Regra: PARAM_URL; `parse_url` com `scheme` e `host`; sem `usuário:senha@`; sem espaço nem caractere de controle; `https`, ou `http` só se o `$CFG->wwwroot` for `http` no momento.
  - URL inválida = **retorno desligado** (falha fechada), e o diagnóstico mostra **FALHA**.
  - O destino **só** vem da configuração, nunca da requisição (CA-41).
- **T-07 Sem dados novos persistidos:** só `$SESSION`, sem tabela nem preferência. O provider de privacidade não muda.
- **T-08 Compatibilidade:** links gerados pela 1.9.x que ainda estejam abertos levam direto à prova e funcionam sem retorno. Nada muda na API da 4.10.
