# 006 — Acesso automático à prova (SSO CRM → Moodle)

## Contexto
Task do Trello [#930](https://trello.com/c/NRLE5onj/930-fai-autenticação-sso-ao-crm-no-vestibular) (13/10). O candidato entra no CRM com o código dele e, ao clicar em **"Realizar minha prova"**, a prova precisa abrir **já autenticada**: sem outra senha e sem um segundo login.

O plugin da comunidade **User key authentication** (`auth_userkey` 2022081902, Catalyst) faz o login por um link de uso único. O Luciano já o instalou na produção. Lendo o código dele, vimos que a função `auth_userkey_request_login_url` gera um link para **qualquer** usuário, inclusive o admin. O CRM não tem IP fixo, então não dá para restringir o token por IP. Se o token vazar, o site inteiro fica exposto.

## Decisões (tomadas com o Caio, 13/10)
- **D-01** O CRM **não chama** a função do `auth_userkey`. Ele chama uma função nossa, **`local_faicrm_gerar_link_prova`**, que só gera link para **candidato** do Vestibular. O serviço do próprio `auth_userkey` fica **sem usuário autorizado**.
- **D-02** Usamos a **chave e a tela de login do `auth_userkey`**: tabela nativa `user_private_key`, script `auth/userkey`, página `/auth/userkey/login.php`. A chave é de uso único, e a validade vem da configuração "User key life time" do plugin (**120 s** na FAI).
- **D-03** Serviço e token **separados** da integração atual: o serviço **"CRM SSO Vestibular FAI"** (`crm_sso_vestibular_fai`), só com essa função, usado por uma conta técnica dedicada (`ws_sso`). No CRM, o token novo é o segredo `MOODLE_SSO_TOKEN`; o `MOODLE_API_TOKEN` continua como está.
- **D-04** O link já leva **direto à prova** (`wantsurl` = `/mod/quiz/view.php?id=<cmid>`). O CRM só redireciona.
- **D-05** A **restrição por IP do `auth_userkey` não se aplica** aos nossos links. O CRM não sabe o IP do candidato, e com a restrição ligada a chave ficaria presa ao IP do servidor do CRM.
- **D-06** Criar ou atualizar usuário pelo SSO **não existe** nesse fluxo. O cadastro e a matrícula continuam com o `MOODLE_API_TOKEN` (spec 002).
- **D-07** O `auth_userkey` é **dependência opcional**: o `local_faicrm` instala e funciona sem ele. Só a função nova responde 503 enquanto o plugin não estiver instalado e habilitado.
- **D-08** O Moodle da FAI pode ter **outros cursos** além dos vestibulares (aulas, materiais), e outros vestibulares podem vir. Por isso o acesso automático só vale para as **categorias de curso liberadas** no painel do plugin. Vestibular novo criado numa categoria liberada já funciona; o resto do Moodle fica fora. Sem categoria liberada, o SSO fica desligado (falha fechada).
- **D-09** O SSO fica **desacoplado** do resto da integração e pode ser desligado ou removido sem afetar as outras rotas:
  - código próprio (`classes/sso/`), com a função externa só orquestrando;
  - serviço, capability, papel e token próprios;
  - configuração própria;
  - dependência opcional do `auth_userkey`.

- **D-10** (13/10, teste do Lupa) Na versão de produção do `auth_userkey` (2022081902), abrir o link num navegador **já logado como outra pessoa** dá "Sua sessão expirou", e o navegador fica deslogado. É o bug corrigido no upstream 607ec45 (2025120301), versão que não está no Plugins directory. Em vez de trocar o plugin da produção, o link aponta para uma **página de entrada nossa** (`/local/faicrm/entrar.php`):
  - se outra pessoa estiver logada **e a chave for válida**, a página desloga essa pessoa;
  - depois redireciona para o `login.php` do plugin, já numa requisição nova, com a sessão limpa.

  Funciona com a 2022081902 e com versões futuras.

## Requisitos
- **RF-23 Gerar link de acesso à prova:** função `local_faicrm_gerar_link_prova`, com os parâmetros `username` (CPF) e `cmid` (id de acesso da prova, ex.: 22). As regras, nesta ordem:
  - sem a permissão `local/faicrm:gerarlinkprova` → 403;
  - `auth_userkey` não instalado ou não habilitado → 503 "O acesso automático à prova não está habilitado no Moodle.";
  - nenhuma categoria liberada na configuração → 503 (a mesma mensagem acima);
  - `cmid` inexistente ou que não é questionário → 404 "Prova não encontrada.";
  - prova de um curso **fora das categorias liberadas** (as subcategorias contam) → 403 "Esta prova não está liberada para o acesso automático.";
  - candidato inexistente ou excluído → 404 "Candidato não encontrado.";
  - **conta que não é de candidato** → 403 "Este usuário não pode entrar na prova pelo acesso automático.". Entram aqui:
    - administrador do site;
    - visitante (guest);
    - qualquer usuário com algum papel que **não seja exatamente o papel Estudante** (shortname `student`), em qualquer contexto. Um papel copiado de Estudante também fica de fora;
    - qualquer usuário com papel (mesmo de Estudante) em algum curso **fora das categorias liberadas**, ou em contexto que não é curso nem atividade (ex.: aluno de um curso regular, mesmo que também seja candidato);
    - qualquer usuário com uma permissão perigosa no contexto de sistema (ex.: `moodle/site:config`, `moodle/user:loginas`), mesmo que dada ao papel "Usuário autenticado" (revisão do Muralha, ALTO-2);
  - candidato suspenso, não confirmado ou com login bloqueado (`nologin`, autenticação desabilitada) → 409 "O candidato está suspenso ou com cadastro incompleto no Moodle.";
  - sem **matrícula ativa** no curso da prova → 409 "O candidato não está matriculado neste curso.";
  - sucesso → 200 `{url, expiraem}`:
    - `url` é `https://<moodle>/local/faicrm/entrar.php?key=<chave>&cmid=<cmid>` (D-10);
    - `expiraem` é o fim da validade da chave (ISO 8601).

  Cada chamada **invalida o link anterior** do mesmo candidato. A validade é a do "User key life time", **limitada a 120 s** (um ajuste errado no painel não vira link de um dia).
  Cada link gerado registra o evento **"Link de acesso à prova gerado"** no log do Moodle, com o candidato e a prova (auditoria).
- **RF-24 Login pelo link:** com o link dentro da validade, o navegador do candidato entra logado e cai na página da prova. O que o `auth_userkey` já garante, e que precisa ser verificado:
  - um link usado de novo falha;
  - um link vencido falha;
  - se outra pessoa estiver logada no navegador, ela é deslogada e o candidato entra.
- **RF-25 Permissão e serviço:**
  - a capability nova é `local/faicrm:gerarlinkprova`, de nível sistema e sem papel padrão;
  - o serviço `crm_sso_vestibular_fai` tem usuários restritos e **só** essa função;
  - a função **não entra** no `crm_vestibular_fai`;
  - o papel pronto para importar é `implantacao/papel-ssocrm.xml`, com só `local/faicrm:gerarlinkprova` e `webservice/rest:use`.
- **RF-28 Configuração:** página do plugin em **Administração do site → Plugins → Plugins locais → FAI CRM: acesso automático à prova**, com a configuração `local_faicrm/sso_categorias` (várias categorias de curso; padrão: nenhuma).
- **RF-26 Endpoints:** a função funciona no `rest_json.php`, que é o recomendado (JSON e erros em português, como as outras rotas), e no servidor REST nativo (`/webservice/rest/server.php`).
- **RF-27 Versão:** o plugin vai para a **1.9.0**.

## Critérios de aceite
- **CA-31** PHPUnit cobre todas as regras do RF-23, a invalidação do link anterior, a validade configurada (e a padrão de 60 s quando vazia) e a chave sem restrição de IP.
- **CA-32** Fluxo real no navegador (Playwright), com o token do serviço SSO:
  - gerar o link → abrir → o candidato fica logado na página da prova;
  - abrir o mesmo link de novo → erro;
  - link vencido → erro;
  - navegador já logado como outra pessoa → entra como o candidato.
- **CA-33** Segurança:
  - o token SSO não chama nenhuma outra função (403);
  - o token da integração (`crm_vestibular_fai`) não chama `gerar_link_prova` (403);
  - pedir link para `admin`, para o `ws_crm` ou para um professor → 403;
  - o serviço do `auth_userkey` continua sem usuário autorizado.
- **CA-34** Instalação e configuração **só pelo painel**, seguindo o roteiro novo do `implantacao-painel.md` num Moodle limpo:
  - instalar o `auth_userkey` e o `local_faicrm` 1.9.0 por zip;
  - importar o papel e criar o `ws_sso`;
  - gerar o token e validar o login pelo link.
- **CA-36** Categorias: uma prova numa subcategoria liberada → OK; uma prova fora das categorias → 403; um candidato que também é estudante de um curso fora das categorias → 403; sem categoria configurada → 503.
- **CA-35** Regressão: as rotas das specs 002 a 005 (Bruno) continuam iguais; moodle-cs com 0 erros e 0 avisos; OpenAPI e contrato atualizados; nota pronta para o time do CRM.
