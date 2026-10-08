# local_faicrm — Integração CRM ↔ Moodle (Vestibular FAI)

Plugin local do Moodle que permite ao CRM da FAI cadastrar candidatos, matriculá-los no curso do Vestibular e consultar os resultados da prova (nota, conclusão e datas) por uma API JSON.

Tudo passa pelas APIs padrão do Moodle (autenticação por token, permissões e validação são as nativas). O único dado próprio é uma preferência do candidato por prova (`local_faicrm_novatentativa_<quizid>`) que registra a nova tentativa liberada pelo CRM, para o cancelamento restaurar a exceção como estava; ela é declarada no provedor de privacidade e apagada com o usuário (e na desinstalação do plugin).

## O que contém

- **Adaptador JSON** `rest_json.php`: recebe `POST` com corpo JSON e `Authorization: Bearer <token>`, e executa o servidor REST nativo do Moodle. Erros saem como `{"message": "..."}` em português, com o status HTTP adequado.
- **Função `local_faicrm_get_resultados_vestibular`** (somente leitura): candidatos do curso, 100 por página, com nota, conclusão, `datamatricula`, `dataprova`, `dataconclusao` e filtro por data da prova.
- **Serviço externo** `crm_vestibular_fai` ("CRM Vestibular FAI"), com as funções nativas necessárias (criar usuário, matricular, consultar).
- **Scripts de linha de comando** `cli/configurar.php` e `cli/verificar.php`.

## Requisitos

- Moodle **4.5** ou superior.
- PHP **8.1** ou superior.
- Cron do Moodle em execução (a conclusão do curso depende dele).
- HTTPS no servidor web (o token trafega no header `Authorization`).

## Instalação

1. Gere o pacote com `scripts/empacotar-plugin.sh` (arquivo `dist/local_faicrm-<versão>.zip`, com a pasta `faicrm` na raiz).
2. Em **Administração do site → Plugins → Instalar plugins**, envie o zip, ou copie a pasta `faicrm` para `<moodle>/local/faicrm`.
3. Conclua o upgrade em **Administração do site → Notificações** (ou `php admin/cli/upgrade.php`).

## Configuração

Na raiz do Moodle, como o usuário do servidor web (ex.: `www-data`):

```bash
# Liga web services/REST/conclusão, cria o papel integracaocrm e o usuário ws_crm, autoriza o serviço.
php local/faicrm/cli/configurar.php

# Idem, e gera um token (impresso uma única vez, não é gravado em arquivo).
php local/faicrm/cli/configurar.php --gerar-token --ip=200.10.20.30 --validade-dias=365

# Diagnóstico (sai com código 1 se houver FALHA). Com o curso do Vestibular:
php local/faicrm/cli/verificar.php --courseid=<id do curso>
```

O `configurar.php` é idempotente e não altera a política de senha nem cria cursos. O curso, a prova e o critério de conclusão (conclusão do questionário) são preparados à parte, pela equipe da FAI. Veja `docs/implantacao-producao.md` no repositório do projeto.

## Endpoints

Todas as chamadas são `POST`, com `Content-Type: application/json` e `Authorization: Bearer <token>`:

```
POST {baseUrl}/local/faicrm/rest_json.php/<função>
```

(a forma `.../rest_json.php?wsfunction=<função>` também vale). Funções principais: `core_user_get_users_by_field`, `core_user_create_users`, `enrol_manual_enrol_users` (204), `enrol_manual_unenrol_users` (204) e `local_faicrm_get_resultados_vestibular`. O contrato completo, com exemplos e erros, está em `docs/openapi.yaml` do repositório.

## Versão

Veja `CHANGES.md`. Licença: GNU GPL v3 ou posterior.
