# Implantação na homologação e na produção

Guia curto para instalar e configurar o plugin `local_faicrm` no Moodle da FAI. Faça **primeiro na homologação**; só depois repita na produção, com os mesmos passos.

## 1. Pré-requisitos

- Moodle **4.5 ou superior**, PHP **8.1 ou superior**.
- **HTTPS** no site (o token trafega no header `Authorization`).
- **Cron do Moodle** rodando (a conclusão do curso depende dele; o `verificar.php` confere).
- Acesso de administrador ao Moodle e, para os scripts, ao terminal do servidor com o usuário do servidor web (ex.: `www-data`).
- Combinar com a FAI: URL do Moodle, `courseid` do curso do Vestibular, IP de saída do backend do CRM (para restringir o token).

## 2. Backup

Antes de instalar, faça backup do banco de dados e do `moodledata`/código (ou um snapshot da máquina). O plugin só cria registros próprios (papel, usuário técnico, serviço, token), mas o upgrade altera o banco.

## 3. Instalar o plugin

Gere o pacote na máquina de desenvolvimento: `scripts/empacotar-plugin.sh` (cria `dist/local_faicrm-<versão>.zip`, com a pasta `faicrm` na raiz).

- **Pelo painel:** Administração do site → Plugins → Instalar plugins → envie o zip → siga o assistente de upgrade. Exige que o servidor web tenha permissão de escrita na pasta `<moodle>/local/`; se não tiver (comum em produção), use a cópia.
- **Por cópia:** descompacte o zip **dentro de `<moodle>/local/`** (o zip já traz a pasta `faicrm`; o resultado deve ser `<moodle>/local/faicrm/version.php`, e não `local/faicrm/faicrm/...`). Deixe os arquivos legíveis pelo usuário do servidor web e abra Administração do site → Notificações (ou rode `php admin/cli/upgrade.php --non-interactive`). Exemplo:

  ```bash
  cd <moodle>
  sudo -u www-data unzip -q /caminho/local_faicrm-<versão>.zip -d local/
  sudo -u www-data php admin/cli/upgrade.php --non-interactive
  ```

## 4. Configurar

Na raiz do Moodle, como o usuário do servidor web:

```bash
sudo -u www-data php local/faicrm/cli/configurar.php --gerar-token --ip=<IP do backend do CRM> --validade-dias=365
```

O script é idempotente (pode rodar de novo sem duplicar nada). Ele liga web services, o protocolo REST e a conclusão de curso; cria o papel `integracaocrm` (só atribui Estudante; inclui a capability `mod/quiz:manageoverrides`, usada para liberar nova tentativa só de um candidato) e o usuário técnico `ws_crm` (senha aleatória, nunca exibida); autoriza o `ws_crm` no serviço `crm_vestibular_fai`; e, com `--gerar-token`, **imprime o token uma única vez** (não grava em arquivo: copie e guarde em local seguro). Ele **não** altera a política de senha nem cria cursos. Rodar de novo **sem** `--gerar-token` não cria token.

Sobre o `--ip`:

- Ele restringe o token gerado **e também a autorização do `ws_crm` no serviço** (vale para todos os tokens dele). Rodar de novo com outro `--ip` substitui o valor; rodar sem `--ip` mantém o que já está gravado. Para tirar a restrição, edite o usuário autorizado em Serviços externos → "CRM Vestibular FAI" → Usuários autorizados.
- Informe o IP **como o Moodle o enxerga**. Se houver proxy reverso ou balanceador na frente do Moodle, configure `$CFG->reverseproxy`/`getremoteaddrconf` no `config.php`, senão o Moodle vê o IP do proxy e bloqueia o CRM.
- Chamada de um IP fora da lista → `403 Operação não permitida para esta integração.` (no log do servidor: `IP:<ip> is not supported`).
- `--ip=0.0.0.0/0` libera qualquer origem (o script avisa): use só em teste.

**Caminho manual (sem o script), resumido:** Funcionalidades avançadas → ligar *Serviços web* e *Conclusão de curso*; Servidor → Serviços web → Gerenciar protocolos → ativar *REST*; criar o usuário `ws_crm` e um papel de sistema com as capabilities de `classes/setup/configurador.php` (lista `CAPABILITIES`), permitindo atribuir Estudante; Serviços externos → "CRM Vestibular FAI" → ativar e autorizar o `ws_crm`; Gerenciar tokens → criar o token do `ws_crm` nesse serviço (com IP e validade). Na tela de usuários autorizados do serviço, **deixe "Válido até" vazio**: no Moodle 4.5 uma validade ali bloqueia todas as chamadas (403); a validade fica só no token.

## 5. Preparar o curso

O curso do Vestibular é criado e mantido pela equipe da FAI (o script não cria curso). Confira:

- Conclusão de curso ligada **no curso** (Configurações do curso → Acompanhamento de conclusão).
- A prova é um **Questionário** com conclusão de atividade automática ao receber nota. A `nota` devolvida ao CRM é o total do curso no livro de notas, na escala configurada (no projeto, nota máxima do questionário = **1000**, 200 por questão); combine a escala com o CRM.
- Conclusão do curso → critério **Conclusão de atividade**: o questionário da prova.
- **Inscrição manual** ativa no curso.
- A política de senha do site é a da FAI; a senha enviada pelo CRM ao criar o candidato precisa atendê-la.

## 6. Servidor web: header `Authorization`

O CRM envia `Authorization: Bearer <token>`. Alguns servidores não repassam esse header ao PHP. Se a chamada de teste devolver `401 Token de acesso inválido ou ausente.` **com um token correto**, ajuste:

- **Apache + mod_php:** `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`
- **Apache + PHP-FPM/CGI (2.4.13+):** `CGIPassAuth On`
- **Nginx + PHP-FPM:** `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`

Recarregue o servidor depois da mudança.

O CRM deve chamar **exatamente a URL do site** (`$CFG->wwwroot`: mesmo esquema, host e porta). Com outro host (ex.: nome interno ou IP do servidor), o Moodle responde `303` com uma página HTML de redirecionamento, e não JSON.

## 7. Verificar

```bash
sudo -u www-data php local/faicrm/cli/verificar.php --courseid=<id do curso>
```

Lista `OK`, `FALHA` ou `AVISO` de cada item (web services/REST, serviço, usuário técnico, papel e capabilities, token, cron, política de senha e, com `--courseid`, o curso). O código de saída é diferente de 0 se houver `FALHA`: resolva todas antes de seguir. Avisos são informativos (por exemplo, token sem restrição de IP). Logo depois de instalar, o item do **cron** pode aparecer como `FALHA` até o cron rodar pela primeira vez: aguarde alguns minutos (ou rode `sudo -u www-data php admin/cli/cron.php`) e verifique de novo.

## 8. Teste de aceite com o Bruno

A coleção tem os valores **fixos** no corpo de cada requisição (não há ambiente nem variáveis) e não tem testes automáticos: confira o status de cada resposta com o esperado na aba *Docs*.

1. Abra a coleção `bruno/` e, em **Collection → Auth → Bearer**, cole o token gerado.
2. Crie antes, na homologação, os candidatos de teste usados pela coleção (ou use candidatos de teste da FAI) e anote os ids: a **Maria da Silva** (`12345678900`, usada em 01, 02b, 03, 03b, 05, 06, 07, 07b e 97) e o **João Santos** (`98765432100`, usado em 08 e 09). O `02` cria a **Ana** (`11122233344`).
3. Substitua em todas as requisições (no Bruno: abrir cada uma, ou editar os arquivos `.bru` num editor com "substituir em todos os arquivos"):
   - a URL `http://localhost:8080` pela do Moodle;
   - `"courseid": 2` pelo id do curso do Vestibular (o `96` usa `99999` de propósito: mantenha);
   - `"userid": 72` pelo id da Maria e `"userid": 73` pelo id do João;
   - as **senhas** de `02` e `97` (hoje iguais ao CPF): com a política de senha ligada, troque por senhas que a atendam, senão a resposta é `400 A senha não atende à política de senhas do Moodle.` em vez de `200`/`409`;
   - as **datas** de `04c` (`2026-10-07`) e `04d` (`2026-09-08` a `2026-10-07`) pelo dia da prova de teste e por um período que o inclua.
4. Faça a prova de um candidato de teste (a Maria) no navegador antes do `04`.
5. Rode a coleção de cima para baixo e compare cada status com a aba *Docs* (200, 204, 400, 401, 403, 404, 409). Na primeira rodada o `02` dá `200`; nas seguintes, `409` (a Ana já existe), o que também é esperado.

## 9. Entregar o token ao CRM

Envie o token ao responsável do CRM por **canal seguro** (gerenciador de segredos ou mensagem cifrada; nunca por e-mail aberto ou chat). Ele fica só no backend do CRM. Informe também a URL base e o contrato (`docs/openapi.yaml`). Confirme o recebimento e **não** guarde o token em planilhas ou repositórios.

## 10. Plano de volta atrás

Em ordem, do menos ao mais drástico:

1. **Revogar o token:** Administração do site → Servidor → Serviços web → Gerenciar tokens → excluir. A integração para na hora (o CRM passa a receber `401`).
2. **Desabilitar o serviço:** Serviços externos → "CRM Vestibular FAI" → desabilitar (o CRM recebe `503`) ou suspender o usuário `ws_crm` (o CRM recebe `403`).
3. **Desinstalar o plugin:** Administração do site → Plugins → Visão geral → `local_faicrm` → Desinstalar (ou `sudo -u www-data php admin/cli/uninstall_plugins.php --plugins=local_faicrm --run`). Isso remove o serviço, a autorização e **todos os tokens**. **Remova a pasta `local/faicrm` logo em seguida**: se ela ficar, o próximo acesso a Notificações ou o próximo `upgrade.php` **reinstala** o plugin (com o serviço sem restrição de IP e sem token). O papel `integracaocrm` e o usuário `ws_crm` continuam no site; remova-os à mão, se desejado. Para voltar a usar depois, reinstale e rode `configurar.php --gerar-token --ip=...` de novo.
4. Em último caso, restaurar o backup do passo 2.
