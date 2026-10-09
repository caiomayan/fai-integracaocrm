# Implantação só pelo painel do Moodle

Para quem tem acesso **só ao painel de administração** do Moodle, sem acesso ao servidor (terminal). Este roteiro foi executado do começo ao fim num Moodle 4.5.14+ limpo, **só pelo navegador**, em 09/10/2026, e a API funcionou com o token gerado no painel.

Leva cerca de 20 minutos. Você vai precisar de:
- **`local_faicrm-1.8.5.zip`**: [release v1.8.5](https://github.com/caiomayan/fai-integracaocrm/releases/tag/v1.8.5);
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
- ⚠️ Se a tela mostrar campos para esse usuário, deixe **"Válido até" desligado**. No Moodle 4.5, uma validade nesta tela **bloqueia todas as chamadas** (403). A validade vai só no token (próximo passo).

## 7. Gerar o token
**Administração do site → Servidor → Web services → Gerenciar tokens → Criar token**
- Nome: `CRM Vestibular FAI (produção)`
- Usuário: digite `ws_crm` e escolha na lista
- Serviço: **CRM Vestibular FAI**
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

## Se precisar voltar atrás
- **Parar a integração na hora:** Gerenciar tokens → excluir o token (as chamadas passam a dar 401).
- **Desligar o serviço:** Serviços externos → CRM Vestibular FAI → Editar → desmarcar "Habilitado".
- **Remover o plugin:** Administração do site → Plugins → Visão geral dos plugins → FAI CRM integration → **Desinstalar**. A pasta do plugin continua no servidor e, sem acesso a ele, o Moodle o reinstala no próximo upgrade. Para remover de vez, a TI da FAI precisa apagar `local/faicrm`.

> **Senha dos candidatos:** se a política de senha do Moodle da FAI estiver ligada (o padrão: 8 caracteres, com maiúscula, minúscula, número e símbolo), o CRM **não pode** usar o CPF como senha. A criação responde 400 "A senha não atende à política de senhas do Moodle.". O CRM precisa gerar uma senha que atenda à política.
