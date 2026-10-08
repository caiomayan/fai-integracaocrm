# Histórico de versões — local_faicrm

## 1.8.4 (2026100814)
- A-01 (decisão final, spec 005): sai `moodle/site:viewuseridentity` do papel `integracaocrm` (volta a 20 capabilities). Ela expunha o e-mail (e os campos de "Mostrar identidade do usuário") de qualquer conta, inclusive da equipe. A busca por e-mail não é oferecida (responde `[]`); o CRM localiza pelo CPF (`username`/`idnumber`) ou pelo `id`. O `configurar.php` retira essa permissão de quem recebeu a 1.8.3 (rode-o depois do upgrade) e o `verificar.php` acusa FALHA se ela estiver no papel. Sai o aviso de `showuseridentity`.

## 1.8.3 (2026100813)
Correções da auditoria de cliente (spec 005):
- A-01: o papel `integracaocrm` ganha `moodle/site:viewuseridentity` (21 capabilities). Sem ela, `core_user_get_users_by_field` com `field=email` devolvia `[]` (o core só devolve o usuário se o campo pesquisado estiver visível). O e-mail passa a vir na resposta. `verificar.php` avisa se `email` não estiver em `showuseridentity`. Rode `cli/configurar.php` depois do upgrade.
- A-02: a raiz do corpo JSON precisa ser um objeto: `[]`, listas, escalares e `null` → 400 "O corpo da requisição deve ser um objeto JSON válido." (corpo vazio e `{}` seguem como sem parâmetros).
- A-03: o `cancelar` desfaz só a última liberação e restaura a exceção imediatamente anterior a ela (inclusive a criada por uma liberação anterior do CRM). Antes, voltava ao estado original da FAI.

## 1.8.2 (2026100812)
- `cancelar_nova_tentativa` desfaz exatamente o que o `liberar` fez: o `liberar` passa a registrar, numa preferência do candidato (`local_faicrm_novatentativa_<quizid>`), como a exceção estava antes (existia?, attempts, fechamento) e o que ele gravou; o `cancelar` volta attempts e fechamento a esses valores (removendo a exceção quando ela não existia) e mantém um fechamento que a FAI tenha mudado depois. Antes, um prazo da FAI na exceção se perdia ou era trocado pelo prazo do CRM depois do cancelamento.
- Sem registro (liberação feita antes desta versão), o `cancelar` mantém o fechamento da exceção e só retira as tentativas.
- Privacidade: o provedor deixa de ser `null_provider` e declara/exporta essa preferência; `db/uninstall.php` a remove na desinstalação.

## 1.8.1 (2026100811)
- Revisão de segurança da recaptação (spec 004, N2):
  - `liberar` e `cancelar` rodam sob uma trava por candidato e prova (`core\lock`): chamadas simultâneas não duplicam a exceção (a tabela `quiz_overrides` não tem índice único). Trava não obtida em 10 s → 503.
  - `cancelar` não apaga mais ajustes feitos pela FAI na exceção do candidato (abertura, tempo limite, senha): só retira a tentativa extra. Exceção sem tentativas não conta como nova tentativa liberada (404).
  - `liberar`: quando o novo limite fica igual ao da prova (uma exceção anterior tinha reduzido o limite) e não sobra outro ajuste, a exceção é removida em vez de dar erro.

## 1.8.0 (2026100810)
- Recaptação: `local_faicrm_liberar_nova_tentativa` (uma tentativa a mais da prova para UM candidato, com exceção de usuário nativa do quiz; prazo opcional) e `local_faicrm_cancelar_nova_tentativa`.
- Catálogo: `local_faicrm_listar_cursos` (paginado, com visibilidade e datas) e `local_faicrm_listar_provas`.
- Resultados: `tentativas` e `podefazerprova` em cada candidato (calculados em lote por página).
- O papel `integracaocrm` ganha `mod/quiz:manageoverrides` (20 capabilities); `configurar.php` e `verificar.php` já a conhecem.

## 1.7.1 (2026100801)
- Código no padrão do Moodle Code Checker (moodle-cs): sem erros nem avisos. As classes do adaptador foram para `classes/rest_json/` (`handler`, `server`, `conflict_exception`); o comportamento da API não muda.
- `cli/configurar.php`: `--validade-dias` vale só para o token (exige `--gerar-token`, de 1 a 3650 dias). A autorização do `ws_crm` no serviço fica sempre sem validade: no Moodle 4.5 uma validade ali bloqueia todas as chamadas (403). `--ip` é normalizado e há aviso para faixas `/0`.
- Senha do `ws_crm` (criada uma vez, nunca exibida) passa a vir de `random_string()` (CSPRNG, 40 caracteres + sufixo para políticas de senha); o `generate_password()` do core gerava palavra + dígito + palavra quando a política de senha está desligada.
- `cli/verificar.php`: FALHA se a autorização do `ws_crm` tiver validade; AVISO para capabilities além das necessárias, papéis atribuíveis além de student e bloqueio de conta por tentativas desligado.
- `scripts/empacotar-plugin.sh` (fora do plugin): recusa links simbólicos, arquivos ocultos, de token/segredo/log e tipos fora do padrão de plugin.

## 1.7.0 (2026100800)
- Provedor de privacidade (`null_provider`): o plugin não guarda dados pessoais.
- `cli/configurar.php` (configuração idempotente: web services, REST, conclusão, papel `integracaocrm`, usuário `ws_crm`, autorização no serviço, token opcional) e `cli/verificar.php` (diagnóstico OK/FALHA/AVISO).
- Lógica de configuração na classe `local_faicrm\setup\configurador`.

## 1.6.1 (2026100609)
- Matricular só devolve 409 se o candidato já tem matrícula **manual ativa** (suspensa é reativada; outro método não bloqueia).

## 1.6.0 (2026100608)
- Matricular quem já está matriculado devolve 409; desmatricular quem não tem matrícula manual devolve 404 (tudo ou nada em lote).
- Resultados: `porpagina` deixa de ser parâmetro (fixo em 100).
- A operação também pode ir no caminho: `/rest_json.php/<função>`.

## 1.5.1 (2026100607)
- Filtros `dataprovade` e `dataprovaate` aceitam só `AAAA-MM-DD`.

## 1.5.0 (2026100606)
- Resultados: `datamatricula`, `dataprova` e `dataconclusao` (ISO 8601 com fuso) e filtro por data da prova.

## 1.4.0 (2026100605)
- Resultados paginados (`pagina`, `porpagina`; resposta com `total`, `totalpaginas`, `candidatos`); consultas em lote.

## 1.3.0 (2026100604)
- Erros do adaptador no formato `{"message": "..."}` em português, com status HTTP coerente e `X-Request-Id`.

## 1.2.0 (2026100603)
- Matrícula sem `roleid` usa o papel `student`; retorno nativo `null` vira HTTP 204.

## 1.1.0 (2026100601)
- Adaptador JSON `rest_json.php` (corpo JSON e `Authorization: Bearer`).

## 1.0.0 (2026100600)
- Primeira versão: função `local_faicrm_get_resultados_vestibular` (nota e conclusão) e serviço `crm_vestibular_fai`.
