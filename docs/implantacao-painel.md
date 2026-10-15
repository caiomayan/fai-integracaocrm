# Implantação só pelo painel do Moodle

> **Já tem a integração e o SSO funcionando (1.9.0 ou 1.9.1)? Para ir à 1.9.2, só atualize o plugin.**
> 1. **Administração do site → Plugins → Instalar plugins** → envie o **`local_faicrm-1.9.2.zip`** → **Instalar plugin do arquivo ZIP** → **Continuar** → **Atualizar base de dados do Moodle agora** → **Continuar**.
> 2. Pronto. **Não refaça nenhum passo** deste roteiro: os papéis, os usuários `ws_crm` e `ws_sso`, os tokens, os serviços, o auth_userkey e as configurações do SSO continuam como estão. A 1.9.2 não tem configuração nova.
> 3. **Vindo da 1.9.0**, a atualização também traz "Cursos liberados" (S2b), da 1.9.1. A liberação por categoria que você já tem continua valendo.
> 4. Para conferir: em **Plugins → Visão geral dos plugins**, "FAI CRM integration" deve mostrar **1.9.2 (2026101302)**. Na página do SSO, o diagnóstico continua todo OK. A operação 4.5 passa a trazer `nota_redacao` e `nota_objetivas`.
>
> Os passos abaixo são para uma instalação **nova** (1 a 11) ou para ligar o SSO **pela primeira vez** (S1 a S9).

Para quem tem acesso **só ao painel de administração** do Moodle, sem acesso ao servidor (terminal). Este roteiro foi executado do começo ao fim num Moodle 4.5.14+ limpo, **só pelo navegador**, em 09/10/2026, e a API funcionou com o token gerado no painel.

Leva cerca de 20 minutos. Você vai precisar de:
- **`local_faicrm-1.9.2.zip`**: [release v1.9.2](https://github.com/caiomayan/fai-integracaocrm/releases/tag/v1.9.2);
- **`papel-integracaocrm.xml`**: o papel pronto, com as 20 permissões ([`implantacao/papel-integracaocrm.xml`](../implantacao/papel-integracaocrm.xml), também anexado à release);
- o **IP do servidor do CRM** (recomendado), para restringir o token.

> Faça um **backup** antes (ou peça à equipe de TI da FAI) e, se possível, rode primeiro numa homologação.

---

## 1. Instalar o plugin
**Administração do site → Plugins → Instalar plugins**
1. Em "Pacote ZIP": **Escolha um arquivo… → Enviar um arquivo → Anexo** → selecione o zip → **Enviar este arquivo**.
2. **Instalar plugin do arquivo ZIP**. Deve aparecer "Validando local_faicrm ... OK".
3. **Continuar** → **Continuar** (verificações do servidor) → **Atualizar base de dados do Moodle agora** → "local_faicrm: Sucesso" → **Continuar**.

> **Se a tela "Instalar plugins" não existir** ou der erro de permissão de escrita, o Moodle da FAI bloqueia a instalação pelo painel. Nesse caso, quem tem acesso ao servidor precisa copiar a pasta (veja `implantacao-producao.md`, passo 3). Não há alternativa só pelo painel.

## 2. Ligar os web services
1. **Administração do site → Geral → Recursos avançados**: marque **"Habilitar serviços web (web services)"** e confira **"Ativar acompanhamento de conclusão"** → **Salvar mudanças**.
2. **Administração do site → Servidor → Web services → Gerenciar protocolos**: na linha **Protocolo REST**, clique no ícone do olho para ativar.

## 3. Criar o papel "Integração CRM" (importando o XML)
**Administração do site → Usuários → Permissões → Definir papéis → Adicionar um novo papel**
1. Em **"Usar papel predefinido"**: **Escolha um arquivo… → Enviar um arquivo → Anexo** → `papel-integracaocrm.xml` → **Enviar este arquivo** → **Continuar**.
2. Confira que a tela já veio preenchida:
   - nome curto `integracaocrm`;
   - contexto **Sistema** marcado;
   - em "Permitir atribuições de papel", só **Estudante**;
   - **20 permissões** marcadas como Permitir.
3. **Criar este papel**.

## 4. Criar o usuário técnico
**Administração do site → Usuários → Contas → Adicionar um usuário**
- Identificação de usuário: `ws_crm`
- Nova senha: uma senha **longa e aleatória**. Ninguém vai usá-la para entrar; não precisa guardar.
- Nome: `Integração` · Sobrenome: `CRM` · E-mail: um endereço técnico da FAI (ex.: `ws_crm@fai.edu.br`)
- **Criar usuário**.

## 5. Dar o papel ao usuário técnico
**Administração do site → Usuários → Permissões → Atribuir papéis globais → Integração CRM**
- Na lista da direita, selecione **Integração CRM (ws_crm…)** → **◄ Adicionar**.

## 6. Autorizar o usuário no serviço
**Administração do site → Servidor → Web services → Serviços externos**
- Na linha **"CRM Vestibular FAI"** (criado pela instalação do plugin), clique em **Usuários autorizados** → selecione o `ws_crm` → **◄ Adicionar**.
- A tela mostra **"Faltando capacidades: 2"** (`moodle/course:useremail` e `moodle/user:update`). Isso é **esperado**: o papel dá ao `ws_crm` só o mínimo, de propósito, e a integração funciona sem essas duas permissões.
- ⚠️ Se a tela mostrar campos para esse usuário, deixe **"Válido até" desligado**. No Moodle 4.5, uma validade nesta tela **bloqueia todas as chamadas** (403). A validade vai só no token (próximo passo).

## 7. Gerar o token
**Administração do site → Servidor → Web services → Gerenciar tokens → Criar token**
- Nome: `CRM Vestibular FAI (produção)`
- Usuário: digite `ws_crm` e escolha na lista
- Serviço: **CRM Vestibular FAI**. ⚠️ A lista pode já vir com **"CRM SSO Vestibular FAI"** selecionado, que é o serviço do acesso automático. Confira que escolheu o **CRM Vestibular FAI**, sem "SSO".
- Restrição de IP: o **IP do servidor do CRM** (recomendado; vazio = de qualquer lugar)
- ⚠️ **Válido até:** o Moodle já vem com essa opção **ligada e só 30 dias**. Ajuste para **1 ano** (ou o prazo combinado com a FAI); senão a integração para em um mês.
- **Salvar mudanças**. O token aparece **uma única vez** ("Copie o token agora"): copie e guarde num lugar seguro (cofre de senhas).

## 8. Conferir o curso do Vestibular
No curso e na prova reais da FAI:
- **Curso → Configurações → Rastreamento de conclusão:** habilitado.
- **Curso → Conclusão do curso:** critério "Conclusão de atividade" marcando a **prova**.
- **Prova → Configurações:**
  - "Tentativas permitidas": um número (ex.: **1**). Com "Ilimitado", a recaptação não faz sentido.
  - Conclusão da atividade: "receber nota".
- **Curso → Participantes → Métodos de inscrição:** **Inscrições manuais** ativo.
- Anote o **id do curso** (na URL, `course/view.php?id=…`) e o **id da prova** (rota "Provas do curso" da API, ou na URL `mod/quiz/view.php?id=…`, que mostra o cmid; o id da prova vem na API).

## 9. Conferir pelo painel (no lugar do `verificar.php`)
- **Administração do site → Notificações:** sem alerta de **cron** (a integração precisa do cron para marcar a conclusão).
- **Serviços externos → CRM Vestibular FAI → Funções:** 15 funções listadas.
- **Definir papéis → Integração CRM:** 20 permissões, só contexto Sistema.

## 10. Teste de aceite (Bruno)
Na coleção `bruno/`, troque `http://localhost:8080` pela URL do Moodle da FAI e os ids (curso, prova, candidato) pelos reais, e coloque o token em Collection → Auth. Rode com **um candidato de teste**: listar cursos (10) → provas (11) → criar (02) → matricular (03) → resultados (04) → desmatricular (14). Depois apague o candidato de teste em Usuários → Contas.

- **Todas as chamadas dão 401 com o token certo?** O servidor da FAI não está repassando o header `Authorization` ao PHP. Isso só a TI da FAI resolve (uma linha na configuração do Apache; veja `implantacao-producao.md`, passo 6).
- **403?** A chamada saiu de um IP fora da restrição do token, ou o `ws_crm` não está autorizado no serviço (passo 6).

## 11. Entregar ao time do CRM
Por canal seguro: a URL do Moodle, o **token**, o **id do curso** e o **id da prova**, e o [contrato da API](contrato-api-crm.md).

---

# Acesso automático à prova (SSO) — a partir da 1.9.1

Com o SSO, o candidato clica em **"Realizar minha prova"** no CRM e cai na prova **já logado**, sem uma segunda senha ([spec 006](specs/006-acesso-automatico-prova/requirements.md)). Ele usa o plugin da comunidade **User key authentication** (`auth_userkey` **2022081902**), com um **token separado** do token da integração.

> Por que não usar a função do próprio `auth_userkey`? Ela gera link de login para **qualquer** usuário, **inclusive o admin**. A função do `local_faicrm` só gera link para **candidato matriculado** na prova. Por isso o serviço do `auth_userkey` fica **desligado**, e o CRM usa só o nosso.

Você vai precisar de: o **`local_faicrm-1.9.2.zip`**, o **`papel-ssocrm.xml`** ([`implantacao/papel-ssocrm.xml`](../implantacao/papel-ssocrm.xml), também anexado à release) e o zip do **`auth_userkey` 2022081902** ([Moodle Plugins directory](https://moodle.org/plugins/auth_userkey/2022081902/37365)), se ele ainda não estiver instalado.

## S1. Instalar ou atualizar os plugins
**Administração do site → Plugins → Instalar plugins**, como no passo 1:
- **`local_faicrm-1.9.2.zip`**: atualiza a integração. Nada muda nas rotas que o CRM já usa.
- **`auth_userkey` 2022081902**: só se ainda não estiver instalado. Confira em **Plugins → Visão geral dos plugins**, procurando "userkey".

Logo depois do "Sucesso", o Moodle abre a tela **"Novas configurações"** com os campos dos dois plugins. O auth_userkey vem com "Mapping field" = e-mail e validade 60. Você pode preencher ali com os valores do S2 e do S2b, ou clicar em **Salvar mudanças** sem mudar nada e configurar nos passos S2 e S2b. O resultado é o mesmo.

## S2. Configurar o User key authentication
**Administração do site → Plugins → Autenticação → User key authentication**:

Alguns campos aparecem em português e outros em inglês (o pacote de idioma traduz só parte deles):

| Campo na tela | Valor |
|---|---|
| Mapping field | **Identificação de usuário** (Username) |
| Tempo de vida da chave do usuário (User key life time) | **120** (segundos) |
| Restrição de IP (IP restriction) | **Não**. O CRM não envia o IP do candidato, e os links do `local_faicrm` não usam IP mesmo com "Sim". Deixe "Não" para não confundir. |
| Faixas de IP permitidos (Whitelist IP ranges) | vazio |
| Logout redirect URL | vazio |
| URL of SSO host | **vazio** ⚠️ Preenchido, a tela de login normal do Moodle passa a mandar todo mundo para esse endereço. |
| Criar usuário? (Create user?) | **Não** |
| Update user? | **Não** |

**Salvar mudanças.**

## S2b. Liberar os cursos (ou as categorias) dos vestibulares
**Administração do site → Plugins → Plugins locais → FAI CRM: acesso automático à prova**. Há dois campos, e eles valem juntos:
- **"Cursos liberados"**: marque o curso do vestibular, por exemplo **PROVA ONLINE FAI 2027.1 (POF2027.1, id 10)**. Use este campo quando o curso do vestibular estiver numa categoria **misturada** com outros cursos, como na FAI hoje. Não é preciso mover nada.
- **"Categorias liberadas"**: use só se existir uma categoria **apenas de vestibulares**. Tudo o que estiver nela e nas subcategorias fica liberado. **Não marque uma categoria que tenha cursos de aula ou de material**, porque isso liberaria esses alunos também.

Para marcar mais de um item, segure Ctrl. Depois clique em **Salvar mudanças**.

O que fica liberado:
- só as provas dos cursos liberados geram link, contando os cursos marcados mais os das categorias marcadas;
- quem tiver papel em qualquer outro curso fica de fora, por exemplo um aluno de um curso de aula, mesmo que também esteja inscrito no vestibular;
- sem nada marcado, o acesso automático fica **desligado**.

> **Vestibular novo:** depois de criar o curso, volte a esta página e marque-o também em "Cursos liberados". Se ele ficar de fora, o CRM recebe 403 "Esta prova não está liberada para o acesso automático.".

## S3. Habilitar o método de autenticação
**Administração do site → Plugins → Autenticação → Gerenciar autenticação**: na linha **User key authentication**, clique no **olho** para habilitar. O login normal (usuário e senha) continua igual para todos.

## S4. Desligar o serviço do próprio auth_userkey
O auth_userkey **vem com o serviço dele habilitado**, e a função desse serviço gera link para qualquer usuário. Por isso ele precisa ser desligado.

**Administração do site → Servidor → Web services → Serviços externos**:
- Na linha **"User key authentication web service"**, clique em **Editar**, desmarque **"Habilitado"** e clique em **Salvar mudanças**.
- Em **Usuários autorizados** desse serviço, a lista deve ficar **vazia**.

## S5. Criar o papel "SSO CRM" (importando o XML)
Como no passo 3, com o **`papel-ssocrm.xml`**. Confira que a tela veio preenchida:
- nome curto `ssocrm`;
- contexto **Sistema**;
- **nenhum** papel em "Permitir atribuições de papel";
- **2 permissões** como Permitir: *Gerar o link de acesso automático à prova* (`local/faicrm:gerarlinkprova`) e *Usar o protocolo REST* (`webservice/rest:use`).

Depois clique em **Criar este papel**.

## S6. Criar o usuário técnico `ws_sso`
Como no passo 4:
- identificação de usuário: `ws_sso`;
- senha **longa e aleatória**;
- nome: `SSO`, sobrenome: `CRM`;
- um e-mail técnico da FAI.

Use uma conta **separada** do `ws_crm`.

## S7. Dar o papel e autorizar no serviço
- **Usuários → Permissões → Atribuir papéis globais → SSO CRM**: adicione o `ws_sso`.
- **Servidor → Web services → Serviços externos → "CRM SSO Vestibular FAI" → Usuários autorizados**: adicione o `ws_sso`. Deixe **"Válido até" desligado**, pelo mesmo motivo do passo 6.

## S8. Gerar o token do SSO
**Servidor → Web services → Gerenciar tokens → Criar token**:
- nome: `CRM SSO Vestibular FAI (produção)`;
- usuário: `ws_sso`;
- serviço: **CRM SSO Vestibular FAI**;
- restrição de IP: **vazia**, porque o CRM não tem IP fixo. Se um dia tiver, preencha;
- **Válido até:** **1 ano**, porque o padrão é 30 dias.

Copie o token (ele aparece **uma única vez**). No CRM, ele vira o segredo **`MOODLE_SSO_TOKEN`**. O `MOODLE_API_TOKEN` atual continua o mesmo.

## S9. Teste de aceite
Primeiro, abra a página do S2b (**Plugins locais → FAI CRM: acesso automático à prova**). No topo, o **diagnóstico** confere sozinho os passos S2 a S8, por exemplo:
- o serviço do auth_userkey continua desligado e sem usuários;
- a "URL of SSO host" está vazia;
- há cursos ou categorias liberados;
- o `ws_sso` está autorizado e tem token.

Todos os itens devem estar **OK**. Um **AVISO** ou uma **FALHA** diz o que corrigir.

Com um **candidato de teste** matriculado no curso da prova, chame a rota `local_faicrm_gerar_link_prova` com o token do SSO. Na coleção `bruno/`, use `17-gerar-link-prova` e coloque o token do SSO na aba Auth dessa requisição:
```http
POST /local/faicrm/rest_json.php/local_faicrm_gerar_link_prova
Authorization: Bearer <token do SSO>
Content-Type: application/json

{ "username": "<CPF do candidato de teste>", "cmid": 22 }
```
A resposta é `200` com `url` e `expiraem`. Agora:
- **Abra a `url` numa janela anônima em até 2 minutos.** O candidato entra logado, direto na prova.
- **Abra a mesma `url` de novo.** Tem que dar erro, porque o link é de uso único.
- **Peça um link para `admin`.** Tem que dar `403`.
- **Peça um link para uma prova de um curso não liberado.** Tem que dar `403`.

O link gerado também fica registrado: em **Relatórios → Logs**, escolha o **curso da prova**. Aparece o evento **"Link de acesso à prova gerado"**, com o candidato.

Depois apague o candidato de teste.

## Voltar atrás (só o SSO)
- **Parar na hora:** Gerenciar tokens → excluir o token do SSO. A integração do CRM (`ws_crm`) continua funcionando.
- **Desligar o login por link:** Gerenciar autenticação → desabilitar **User key authentication**. Os links passam a dar erro, e o login normal continua.
- **Desligar só a geração de links:** na página do S2b, desmarque todos os cursos e todas as categorias.

---

## Se precisar voltar atrás
- **Parar a integração na hora:** Gerenciar tokens → excluir o token (as chamadas passam a dar 401).
- **Desligar o serviço:** Serviços externos → CRM Vestibular FAI → Editar → desmarcar "Habilitado".
- **Remover o plugin:** Administração do site → Plugins → Visão geral dos plugins → FAI CRM integration → **Desinstalar**. A pasta do plugin continua no servidor e, sem acesso a ele, o Moodle o reinstala no próximo upgrade. Para remover de vez, a TI da FAI precisa apagar `local/faicrm`.

> **Senha dos candidatos:** se a política de senha do Moodle da FAI estiver ligada (o padrão: 8 caracteres, com maiúscula, minúscula, número e símbolo), o CRM **não pode** usar o CPF como senha. A criação responde 400 "A senha não atende à política de senhas do Moodle.". O CRM precisa gerar uma senha que atenda à política.
