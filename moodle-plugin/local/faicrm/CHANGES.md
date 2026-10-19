# Histórico de versões — local_faicrm

## 1.10.1 (2026101900)
Junta numa versão só a 1.10.0 (retorno ao CRM depois da prova) e a 1.9.2 (notas da redação e das objetivas), abaixo, e acrescenta o filtro por CPF (spec 009):
- A operação `local_faicrm_get_resultados_vestibular` (4.5) ganha o parâmetro opcional **`username`** (o CPF): devolve só aquele candidato, com todos os campos (inclusive `nota_redacao` e `nota_objetivas`). A comparação é exata, depois de tirar espaços das pontas e passar para minúsculas; não há busca parcial nem curinga. Só contas locais e não excluídas.
- Candidato que não existe, que não é estudante do curso ou que foi excluído: resposta normal vazia (`total` 0), sem erro. Combina com `dataprovade`/`dataprovaate` pelo "E". Vazio ou ausente: a turma inteira, como antes.
- Mais de 100 caracteres: 400 "Dados inválidos: username deve ter no máximo 100 caracteres."
- Atualizando a partir da 1.9.2 (produção), o passo da atualização cria o segredo do retorno (`sso_secret`), como na 1.10.0. A partir da 1.10.0 não há passo novo.

## 1.10.0 (2026101401)
Retorno ao CRM depois da prova (spec 007):
- Configuração nova na página do acesso automático: **"URL de retorno após a prova"** (`local_faicrm/retorno_url`; só `https://`, ou `http://` se o Moodle for http; vazia = desligado) e **"Mensagem ao concluir"** (opcional).
- Quem entrou pelo link do SSO e **envia a prova do link** com o navegador aberto (botão ou fim do tempo) vê a tela "Prova enviada com sucesso!", sem nota nem respostas, tem a sessão encerrada e é levado em 5 s (ou no botão "Voltar agora") para a URL configurada com `status=concluida&cmid=<prova>`. Nada pessoal vai na URL.
- Sem efeito em login normal, em outra prova, com a URL vazia ou em tentativa enviada pelo cron.
- Segurança: a marcação do retorno é presa ao link por uma assinatura HMAC (segredo criado na instalação/atualização, nunca em um pedido); o desvio tem trava contra reentrada; só desvia com a tentativa finalizada.
- O link do SSO passa pela página nova `iniciar.php` antes da prova. O diagnóstico ganhou o item do retorno.

## 1.9.2 (2026101302)
Notas da redação e das objetivas nos resultados do vestibular (spec 008):
- A operação `local_faicrm_get_resultados_vestibular` ganha dois campos por candidato: **`nota_redacao`** (a questão da posição 1 da prova, a primeira com nota na ordem da prova; uma "Descrição" no início não conta) e **`nota_objetivas`** (todas as outras questões), na mesma escala da `nota`; a redação mais as objetivas dá a nota da tentativa. Duas casas decimais.
- A tentativa é a do resultado final, conforme o método de avaliação da prova (maior nota, primeira, última, ou a média de cada parte).
- `null` nos dois quando não há tentativa finalizada, quando alguma tentativa finalizada da prova espera correção manual, ou quando o candidato tem tentativas finalizadas em mais de uma prova do curso. Redação em branco vale 0.
- O cálculo é feito em lote para a página, sem uma consulta por candidato. Nada mais muda.

## 1.9.1 (2026101301)
- Acesso automático à prova: além das categorias, é possível liberar **cursos específicos** (configuração nova "Cursos liberados", `local_faicrm/sso_cursos`), para quando todos os cursos estão numa categoria só. Categorias e cursos valem juntos (união); curso excluído é ignorado e a página inicial nunca entra. Sem nenhuma categoria e nenhum curso, o acesso automático continua desligado.
- O diagnóstico do acesso automático passa a contar categorias **ou** cursos liberados.

## 1.9.0 (2026101300)
Acesso automático à prova (SSO, spec 006):
- Função nova **`local_faicrm_gerar_link_prova`** (`username` + `cmid`) → `{url, expiraem}`. Gera um link de **uso único** do plugin User key authentication (`auth_userkey`) que leva o candidato **já logado** à prova.
- O link só sai para **candidato**: uma conta só com o papel Estudante, sem permissões perigosas, ativa e confirmada, e com matrícula ativa no curso da prova. Admin, equipe e contas técnicas → 403.
- A validade é a do "User key life time", limitada a 120 s. Não há restrição de IP, e cada link novo invalida o anterior.
- Cada link gerado registra o evento `link_prova_gerado` (candidato e prova; nunca a chave).
- Serviço novo **`crm_sso_vestibular_fai`** ("CRM SSO Vestibular FAI"), só com essa função e com token próprio (`MOODLE_SSO_TOKEN` no CRM). Capability nova `local/faicrm:gerarlinkprova`. Papel pronto: `implantacao/papel-ssocrm.xml`.
- O `auth_userkey` é opcional. Sem ele, só a função nova responde 503, e as rotas existentes não mudam.
- O link devolvido aponta para uma **página de entrada própria** (`/local/faicrm/entrar.php`), que, se o navegador estiver logado como **outra pessoa** e a chave for válida, desloga essa pessoa antes de seguir para o login do `auth_userkey`. Corrige, sem trocar o plugin, o erro "Sua sessão expirou" da versão 2022081902 (conserto 607ec45 do upstream). A chave fica amarrada à prova (só abre a prova para a qual foi gerada) e é validada antes de qualquer redirecionamento: link já usado, vencido ou de outra prova mostra um erro da própria página, sem deslogar ninguém.
- Primeiros testes PHPUnit do plugin (`tests/`).

## 1.8.5 (2026100815)
- Erro `usernotenroled` do Moodle (ex.: `core_completion_get_course_completion_status` para um candidato não matriculado) passa a responder **409** "O candidato não está matriculado neste curso.", em vez de 500 "Erro interno".

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
