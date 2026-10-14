# 007 — Retorno ao CRM depois da prova

## Contexto
Sugestão do gestor, maturada com o Caio num grilling em 14/10. O CRM leva o candidato **logado pelo SSO** à prova (spec 006). Ao terminar, o Moodle deve **devolvê-lo** ao CRM, ou a outra URL configurada no plugin, em vez de deixá-lo no Moodle.

O Moodle 4.5 não tem isso nativamente. Depois de "Enviar tudo e terminar", ele sempre vai para a tela de revisão da tentativa.

## Decisões (grilling com o Caio, 14/10)
- **D-01 Para quem:** só **quem entrou pelo SSO** (`entrar.php` → login por chave do `auth_userkey`). Quem entra pelo login normal segue o fluxo do Moodle como hoje.
- **D-02 Qual prova:** só a **prova do link**, o `cmid` com que o candidato entrou. Outras provas na mesma sessão seguem o fluxo normal.
- **D-03 Quando:** ao **enviar a tentativa com o candidato online**, seja pelo botão "Enviar tudo e terminar", seja porque o tempo acabou com o navegador aberto. Uma tentativa enviada **pelo cron** (navegador fechado) **não** gera retorno.
- **D-04 Tela de confirmação:** no lugar da tela de revisão, uma tela nossa com:
  - "Prova enviada com sucesso! Você será levado de volta em 5 segundos.";
  - a **mensagem opcional** configurada;
  - o botão **"Voltar agora"**.

  São **5 segundos fixos**. O candidato **não vê** a nota nem as respostas.
- **D-05 Sessão:** nessa tela o Moodle **encerra a sessão** do candidato, antes do redirecionamento.
- **D-06 Destino:** **uma URL só**, na configuração do plugin. **Só `https://`**, a menos que o próprio Moodle esteja em `http://` (ambiente local).
- **D-07 Parâmetros:** o plugin acrescenta `status=concluida&cmid=<cmid>` e mantém os parâmetros que a URL configurada já tiver. **Nunca** acrescenta nota, CPF ou outro dado pessoal.
- **D-08 Desligado por padrão:** com a URL vazia, nada muda.
- **D-09 Desacoplado:** o retorno faz parte do SSO (`classes/sso/`), com configuração própria na página do SSO, e não afeta as outras rotas nem quem não usa SSO.

## Requisitos
- **RF-29 Marcar a sessão do SSO:** depois do login por chave, o candidato passa por uma página nossa (`iniciar.php?cmid=…`).
  - Ela só grava que a prova `cmid` é a **prova do retorno** se o endereço tiver a **assinatura** gerada pela 4.10 para esse usuário e essa prova, ainda na validade (design T-01).
  - Em seguida, redireciona para a prova.
  - Sem a assinatura certa, só redireciona, sem marcar nada.
- **RF-30 Detectar o envio:** quando uma tentativa é enviada (`\mod_quiz\event\attempt_submitted`), o plugin grava na sessão um **retorno pendente** se:
  - o candidato estava online (`studentisonline`);
  - o usuário da sessão é o dono da tentativa;
  - a prova é a do retorno;
  - há URL configurada.
- **RF-31 Desviar para a tela de confirmação:** com retorno pendente, a próxima página da prova (revisão ou página inicial da prova) é desviada para `concluido.php`, antes de qualquer conteúdo.
- **RF-32 Tela de confirmação (`concluido.php`):**
  - consome o retorno pendente, que vale uma vez só;
  - encerra a sessão;
  - mostra a confirmação (D-04);
  - redireciona em 5 s, ou no clique de "Voltar agora", para a URL configurada com `status=concluida&cmid=<cmid>`.

  Sem retorno pendente, a tela leva à página inicial do Moodle, sem redirecionar para fora.
- **RF-33 Configuração:** na página "FAI CRM: acesso automático à prova":
  - **"URL de retorno após a prova"** (`local_faicrm/retorno_url`): vazia = desligado; só `https://`, ou `http://` se o Moodle for http;
  - **"Mensagem ao concluir"** (`local_faicrm/retorno_mensagem`), opcional, em texto simples.

  O diagnóstico mostra se o retorno está ligado e para qual domínio.
- **RF-34 Versão:** **1.10.0** (`version` 2026101401).

## Critérios de aceite
- **CA-38** PHPUnit:
  - a URL final (parâmetros acrescentados, os existentes mantidos, nada pessoal);
  - a validação da configuração (https, http só com o Moodle em http, vazia);
  - a marcação só com login por chave;
  - o retorno pendente só nas condições do RF-30 (prova certa, online, mesmo usuário, URL configurada);
  - a decisão de desvio;
  - o consumo único.
- **CA-39** No navegador:
  - SSO → prova → "Enviar tudo e terminar" → tela de confirmação → 5 s → URL com `status=concluida&cmid=…`, e a sessão do Moodle encerrada;
  - o mesmo quando o **tempo acaba**;
  - o botão "Voltar agora" funciona.
- **CA-40** Sem efeito em quem não deve:
  - login normal → revisão normal;
  - SSO numa prova diferente da do link → fluxo normal;
  - URL vazia → fluxo normal;
  - tentativa enviada pelo cron → nada;
  - abrir `iniciar.php?cmid=…` sem a assinatura, ou com a de outra prova → não marca;
  - "acessar como" o candidato, ou um professor revisando a tentativa → nunca desvia;
  - tentativa não finalizada → não desvia;
  - abrir `concluido.php` direto → página inicial, sem redirecionar para fora.
- **CA-41** Segurança:
  - o destino nunca vem da requisição (sem redirecionamento aberto);
  - nenhum dado pessoal na URL;
  - a sessão é encerrada antes de sair.
- **CA-42** Regressão das specs 002–006, moodle-cs 0/0, roteiro do painel, contrato do CRM completo (o que o CRM precisa ter e fazer) e OpenAPI.
