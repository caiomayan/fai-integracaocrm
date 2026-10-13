# 006 — Design

## Fluxo
```
Candidato clica "Realizar minha prova" no CRM
  │
  ▼
CRM (backend) ── POST /local/faicrm/rest_json.php/local_faicrm_gerar_link_prova
                 Authorization: Bearer <MOODLE_SSO_TOKEN>
                 {"username": "12345678900", "cmid": 22}
  ◀── 200 {"url": "https://<moodle>/local/faicrm/entrar.php?key=…&cmid=22",
           "expiraem": "2026-10-13T10:02:00-03:00"}
  │
  ▼
CRM redireciona o navegador para "url" (302 ou window.location)
  │
  ▼
local_faicrm (entrar.php): outra pessoa logada e chave válida? → desloga → redireciona (requisição nova)
  │
  ▼
auth_userkey (login.php): a chave existe e está na validade? → apaga a chave → login do candidato → página da prova
```

## Arquivos
| Arquivo | Mudança |
|---|---|
| `classes/external/gerar_link_prova.php` | função nova (RF-23), só orquestra |
| `classes/sso/elegibilidade.php`, `classes/sso/link.php` | regras e geração do link (T-11) |
| `classes/event/link_prova_gerado.php` | evento de auditoria "Link de acesso à prova gerado" (T-08) |
| `entrar.php` | página de entrada do link (T-15) |
| `settings.php` | página "FAI CRM: acesso automático à prova" (RF-28), com o diagnóstico no topo |
| `classes/sso/diagnostico.php`, `cli/verificar.php` | diagnóstico do SSO (T-13) |
| `db/services.php` | função `local_faicrm_gerar_link_prova` + serviço `crm_sso_vestibular_fai` |
| `db/access.php` | capability `local/faicrm:gerarlinkprova` (novo arquivo) |
| `classes/rest_json/handler.php` | mensagens de `ssoindisponivel`, `contanaopermitida` e `candidatosuspenso`; rótulo do `cmid` |
| `lang/en/local_faicrm.php` | strings dos erros e da capability |
| `version.php` | 1.9.0 |
| `tests/external/gerar_link_prova_test.php` | PHPUnit (CA-31) |
| `implantacao/papel-ssocrm.xml` | papel "SSO CRM" para importar |
| `docker/moodle/Dockerfile`, `entrypoint.sh`, `docker-compose.yml`, `scripts/phpunit.sh` | PHPUnit no ambiente local; `auth_userkey` 2022081902 na imagem |

## Decisões técnicas
- **T-01 A chave é gerada pelas funções do core** (`delete_user_key` e `create_user_key` com script `auth/userkey`, o mesmo do `core_userkey_manager`), sem chamar as classes internas do plugin.
  - A validade vem de `get_config('auth_userkey', 'keylifetime')`. Vazia ou 0 vira 60 s, como no plugin. **No máximo 120 s**, mesmo que o painel diga mais (Muralha, MÉDIO-3).
  - Não gravamos restrição de IP (D-05).
  - O `login.php` do plugin valida a chave do mesmo jeito: confere existência, validade e IP (vazio = livre), depois apaga e loga.
- **T-02 A permissão é verificada no contexto de sistema** (`validate_context(context_system)` + `require_capability`). A conta `ws_sso` não precisa ver cursos (`moodle/course:view`). Por isso a função não chama `validate_context` no módulo, que exigiria acesso ao curso.
- **T-03 Conta de candidato** = não é admin, não é guest, **todos os `role_assignments` são do papel de shortname `student`** (o mesmo papel do RF-08) e **não tem nenhuma capability perigosa no sistema**.
  - O arquétipo não serve: é só um rótulo, e um papel copiado de Estudante e ampliado passaria (Muralha, ALTO-2).
  - Capabilities perigosas: `moodle/site:config`, `moodle/user:create`, `moodle/user:update`, `moodle/role:assign`, `moodle/course:update` e `moodle/user:loginas`. Elas pegam permissões dadas ao "Usuário autenticado" ou overrides de sistema. **`webservice/rest:use` não entra:** com o app móvel ligado (o padrão em HTTPS), o Moodle a dá a todo usuário autenticado (`admin_setting_enablemobileservice`), e ela sozinha não dá acesso a nenhum serviço restrito.
  - Ficam de fora: papéis técnicos, professor, gestor e papéis em contexto de usuário (pais/mentor).
  - Matrícula por cohort, meta ou categoria grava `role_assignment` de Estudante, então passa.
  - A consulta é uma só, portátil (MariaDB e PostgreSQL).
- **T-03a Permissões na prova (Muralha, MÉDIO-2 do código):**
  - as capabilities perigosas também são conferidas no **contexto da prova**, que herda curso e categoria;
  - na prova, a lista ganha `mod/quiz:viewreports`, `mod/quiz:grade`, `mod/quiz:manage`, `mod/quiz:manageoverrides` e `moodle/grade:viewall`, para pegar um override errado no papel Estudante;
  - a conta precisa ter `mod/quiz:attempt` na prova; senão → `contanaopermitida`. Isso cobre quem está matriculado sem papel (BAIXO-4). Essa checagem roda **depois** da de matrícula: quem não está matriculado continua recebendo 409 "não matriculado", e quem está matriculado sem papel recebe 403.
- **T-03b Candidato** buscado por `username` + `mnethostid = $CFG->mnet_localhost_id` + `deleted = 0`. `suspended`, `confirmed = 0`, `nologin` ou autenticação desabilitada → `candidatosuspenso`, porque o `complete_user_login` do core não confere nada disso.
- **T-03c Prova:** `get_coursemodule_from_id('quiz', cmid)`, com `deletioninprogress = 0`.
- **T-04 Sem dependência no `version.php`.** O `auth_userkey` é verificado na hora (`is_enabled_auth('userkey')` e a existência de `/auth/userkey/login.php`). Sem ele → `ssoindisponivel` (503).
- **T-05 `wantsurl`** = `new moodle_url('/mod/quiz/view.php', ['id' => cmid])`. É sempre uma URL interna, porque o CRM não escolhe o destino.
- **T-06 Erros:**

  | Situação | Exceção | Status | Mensagem |
  |---|---|---|---|
  | Prova não encontrada | `dml_missing_record_exception('quiz')` | 404 | (já mapeada) |
  | Candidato não encontrado | `dml_missing_record_exception('user')` | 404 | (já mapeada) |
  | SSO indisponível | `moodle_exception('ssoindisponivel')` | 503 | — |
  | Conta não permitida | `moodle_exception('contanaopermitida')` | 403 | — |
  | Candidato suspenso | `moodle_exception('candidatosuspenso')` | 409 | mesma do `suspended` |
  | Sem matrícula ativa | `candidatonaomatriculado` | 409 | (já existe) |
- **T-07 Concorrência:** a geração roda sob uma **trava por candidato**, válida para todas as provas, porque o `delete_user_key` apaga as chaves do usuário em todas elas. Assim, duas chamadas simultâneas, mesmo para provas diferentes, deixam **só uma** chave válida (Muralha, BAIXO-3 do desenho e BAIXO-1 do código).
- **T-08 Auditoria:** o evento **`\local_faicrm\event\link_prova_gerado`** vai para o log padrão (Muralha, MÉDIO-4).
  - Campos: contexto do módulo da prova, `userid` = ws_sso, `relateduserid` = candidato, `objectid` = cmid. A chave **nunca** vai no evento.
  - Sem tabela própria. O provider de privacidade não muda, porque o log padrão já tem o dele.

  Além disso, a auditoria nativa continua:
  - Cada chamada do token aparece nos logs do web service.
  - Cada login gera o evento "Usuário logado" (`\core\event\user_loggedin`) com o IP do candidato.

  O `auth_userkey` tem o próprio provider de privacidade.
- **T-09 Riscos conhecidos do `auth_userkey`** (não mexemos no plugin):
  - **Logout por CSRF:** abrir `/auth/userkey/login.php?key=qualquer`, inclusive como imagem, desloga quem estiver logado. O impacto é interromper a prova; o quiz salva as respostas automaticamente. Mitigação opcional pela TI: aceitar esse caminho só como navegação de topo (`Sec-Fetch-Dest: document`).
  - **`wantsurl` externo:** só funciona com uma chave válida. Nós geramos sempre um destino interno.
  - **O link vale como credencial por até 120 s.** O CRM redireciona no servidor, não grava a URL, usa HTTPS e `Referrer-Policy: no-referrer`.
  - **Navegador já logado como outra pessoa:** a 2022081902 tem o bug corrigido no upstream 607ec45. O CA-32 testa esse caso; se falhar, a recomendação é a versão 2025120301.
- **T-10 Alcance (Muralha, ALTO-1; decisão do Caio em 13/10):** só as **categorias de curso liberadas** (`local_faicrm/sso_categorias`, com as subcategorias).
  - A prova precisa estar num curso dessas categorias.
  - O candidato só pode ter papéis (só de Estudante) em contextos de curso ou de atividade de cursos dessas categorias.
  - A consulta é portátil e **não usa `LIKE`** (o `$DB->sql_like` do Moodle recusa uma coluna como padrão): um `role_assignment` em contexto de curso vale se `course.category` está nas categorias liberadas, e em contexto de atividade se o `course_modules.course` da atividade está. As subcategorias são expandidas em PHP (`get_all_children_ids`) antes da consulta.
  - Sem categoria configurada → `ssoindisponivel` (falha fechada).
- **T-10b Cursos liberados (D-11, 1.9.1):** `elegibilidade` passa a trabalhar com o **conjunto de cursos liberados**.
  - O conjunto = os ids de curso das categorias liberadas (com as subcategorias) ∪ `sso_cursos`. O id 1 (página inicial) nunca entra.
  - A prova liberada e a conta de candidato usam esse conjunto (`course.id IN (…)`), sem LIKE, portátil.
  - Falha fechada: sem categoria e sem curso → `ssoindisponivel`.
  - `settings.php`: `admin_setting_configmultiselect` com os cursos (`get_courses()` sem o id 1), rótulo "nome completo (nome breve, id N)".
- **T-11 Desacoplamento (D-09):**
  - `classes/sso/elegibilidade.php` tem as regras (prova liberada? conta de candidato?);
  - `classes/sso/link.php` gera a chave e a URL (o único ponto que conhece o `auth_userkey`);
  - `classes/external/gerar_link_prova.php` só valida os parâmetros e a permissão, chama os dois e dispara o evento.
  
  Para trocar o `auth_userkey` por outro mecanismo, basta mexer no `link.php`. Para tirar o SSO de vez, saem esses arquivos, o `settings.php`, a capability e o serviço, e o resto da integração não muda.
- **T-13 Diagnóstico (Muralha, MÉDIO-1 do código):** `classes/sso/diagnostico.php` (só leitura) devolve uma lista de itens OK, AVISO ou FALHA.
  - **Itens do `auth_userkey`:**
    - instalado e habilitado;
    - serviço "User key authentication web service" sem usuários autorizados e sem tokens;
    - `auth/userkey:generatekey` em nenhum papel;
    - `auth_userkey_request_login_url` em nenhum outro serviço;
    - `ssourl` e `redirecturl` vazios;
    - `createuser` e `updateuser` desligados;
    - `keylifetime` de no máximo 120.
  - **Itens nossos:**
    - categorias liberadas;
    - serviço `crm_sso_vestibular_fai` habilitado, com usuário autorizado e token válido.
  - Aparece **no topo da página de configuração do SSO**, porque a produção é só pelo painel, e no `cli/verificar.php`, que ganha uma seção SSO.
- **T-14 Riscos aceitos:**
  - Quem tem o token consegue distinguir as respostas: 404 (CPF ou prova inexistente), 403 (conta de equipe ou prova fora das categorias), 409 e 200. Aceitável, porque o token é restrito ao backend do CRM e as mensagens precisam ser claras para o CRM (BAIXO-2 do código).
  - No `server.php` nativo, o sucesso sai com `Cache-Control: private` em vez de `no-store`. Por isso o contrato recomenda o `rest_json.php` (BAIXO-5).
- **T-15 Página de entrada (D-10):** `entrar.php`, com `key` (PARAM_ALPHANUM) e `cmid` (PARAM_INT).
  - Sem `auth_userkey` habilitado → erro `ssoindisponivel`.
  - Se houver alguém logado (inclusive guest), a chave existir (script `auth/userkey`), estiver na validade e for de **outro** usuário → `require_logout()`.
  - Em todos os casos, `redirect()` para `/auth/userkey/login.php?key=…&wantsurl=<moodle>/mod/quiz/view.php?id=cmid`. O destino é montado por nós, nunca vem de fora.
  - Antes do redirect, envia `Referrer-Policy: no-referrer` e `Cache-Control: no-store`.
  - **A chave fica amarrada à prova:** o `create_user_key` grava `instance = cmid`, e o `auth_userkey` ignora esse campo.
  - **Chave inválida, vencida ou de outra prova** (`instance` ≠ `cmid`): a própria `entrar.php` mostra o erro "Este link de acesso já foi usado ou expirou. Volte ao CRM e clique de novo em "Realizar minha prova"." e **não** redireciona. Assim não desloga ninguém (o `login.php` do plugin deslogaria antes de validar). O Lupa achou isso no reteste.
  - Reabrir o `login.php` do plugin direto continua deslogando (risco do plugin, T-09).
  - A decisão fica em `link::entry_check(key, cmid, currentuserid)` (testável), que devolve se a chave é válida para essa prova e se é preciso deslogar. As URLs ficam em `link::entry_url(key, cmid)` e `link::login_url(key, cmid)`.
  - Continua sendo o único ponto que conhece o `auth_userkey` (`link.php` + `entrar.php`).
- **T-12 Configuração** em `settings.php`: uma página própria em Plugins locais, com `admin_setting_configmultiselect` e as categorias de `core_course_category::make_categories_list()`.
