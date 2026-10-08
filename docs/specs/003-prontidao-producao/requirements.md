# 003 — Prontidão para produção — Requisitos

## Contexto
A integração (spec 002) está pronta e validada no ambiente local. O objetivo agora é que a instalação na homologação e na produção da FAI seja só **instalar e configurar**, sem ajuste de código depois. Tudo o que depende do ambiente da FAI (IDs reais, política de senha, HTTPS, servidor web) continua como pendência externa.

## Requisitos
- **RP-01 Privacidade:** o plugin declara um provedor de privacidade (`null_provider`: ele não guarda dados pessoais), e a checagem de conformidade do Moodle passa.
- **RP-02 Configuração automática:** `local/faicrm/cli/configurar.php`, idempotente, que:
  - liga web services, o protocolo REST e o acompanhamento de conclusão;
  - cria ou atualiza o papel de sistema `integracaocrm` com as capabilities do design 002, permitindo atribuir só Estudante;
  - cria o usuário técnico `ws_crm` (senha aleatória forte) e atribui o papel no contexto de sistema;
  - autoriza o `ws_crm` no serviço `crm_vestibular_fai`;
  - opcionalmente gera o token (`--gerar-token`, com `--ip=` e `--validade-dias=`) e o imprime **uma vez**, sem gravar em arquivo.
  - Não altera a política de senha nem cria cursos.
  - A lógica fica numa classe do plugin, reutilizada pelo `setup.php` de desenvolvimento.
- **RP-03 Diagnóstico:** `local/faicrm/cli/verificar.php [--courseid=N]` lista OK/FALHA para:
  - web services e REST ligados; serviço ativo; usuário técnico ativo, autorizado e com papel; capabilities presentes; token válido existente;
  - com `--courseid`: curso existe, conclusão ligada, critério de conclusão por atividade de questionário, inscrição manual ativa, papel `student` existe;
  - cron executado recentemente; política de senha (aviso informativo).
  - Código de saída diferente de 0 se houver falha.
- **RP-04 Pacote:** `scripts/empacotar-plugin.sh` gera `dist/local_faicrm-<release>.zip`, com a pasta `faicrm` na raiz, instalável por Administração → Plugins → Instalar plugins. `dist/` fica fora do Git.
- **RP-05 Padrão de código:** o plugin passa no Moodle Code Checker (`moodlehq/moodle-cs`) sem **erros**; avisos justificados.
- **RP-06 Guia de implantação:** `docs/implantacao-producao.md`, curto, com pré-requisitos, instalação (zip ou cópia), configuração (script ou painel), preparação do curso, servidor web (header `Authorization`), testes de aceite com o Bruno, entrega do token ao CRM e plano de volta atrás (revogar token, desinstalar plugin).
- **RP-07 Documentação do plugin:** `README.md` e `CHANGES.md` dentro de `local/faicrm` (o que é, requisitos, instalação, versão).

## Critérios de aceite
- **CP-01** Moodle 4.5 limpo (sem `setup.php`): instalar o plugin **pelo zip** → `configurar.php --gerar-token --ip=... --validade-dias=...` → criar curso e prova → `verificar.php --courseid=N` todo OK → coleção Bruno (adaptada à URL e aos IDs) com o fluxo completo funcionando.
- **CP-02** `configurar.php` rodado 2 vezes não duplica nada; o token só é gerado quando pedido.
- **CP-03** `verificar.php` aponta FALHA quando algo é desligado de propósito (ex.: REST desabilitado, curso sem conclusão).
- **CP-04** Checagem de privacidade do Moodle sem pendências para `local_faicrm`.
- **CP-05** moodle-cs sem erros.
- **CP-06** O ambiente local continua subindo do zero e funcionando igual (regressão da spec 002).
