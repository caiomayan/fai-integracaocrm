# Desenvolvimento — ambiente local e testes

_Guia técnico para quem mantém o projeto. Visão geral para o cliente: [README](../README.md)._

Moodle **4.5.14+** com **PHP 8.1** e **MariaDB 10.11**, a mesma linha de versão do Moodle da FAI. É uma instalação limpa, só para explorar a ferramenta e, depois, desenvolver a integração com o CRM.

> Não é preciso instalar PHP no Windows: ele roda dentro do container.

## Acesso
| O quê | Valor |
|---|---|
| Moodle | http://localhost:8080 |
| Admin | `admin` / `Admin@12345` |
| Banco (DBeaver/HeidiSQL) | `localhost:3307`, banco `moodle`, usuário `moodle` / `moodle` (root: `root`) |

As senhas ficam no `.env` (copiado de `.env.example`, não versionado) e servem só para desenvolvimento.

## Comandos
```powershell
cp .env.example .env              # 1ª vez: cria o .env local
docker compose up -d --build      # sobe tudo (a 1ª vez instala o Moodle, ~2-3 min)
docker compose logs -f moodle     # acompanha a instalação / erros PHP
docker compose ps                 # status dos containers
docker compose stop               # para (mantém os dados)
docker compose start              # volta a subir
docker compose down -v            # APAGA tudo (banco + moodledata) e recomeça do zero
docker compose exec -u www-data moodle php admin/cli/cron.php   # roda o cron na hora
docker compose exec -u www-data moodle php admin/cli/purge_caches.php
```

## Containers
- **db**: MariaDB 10.11 (volume `dbdata`).
- **moodle**: Apache + PHP 8.1 com o código do Moodle (`/var/www/html`); arquivos enviados ficam em `/var/www/moodledata` (volume `moodledata`).
- **cron**: roda `admin/cli/cron.php` a cada minuto. O Moodle depende disso para tarefas como calcular a conclusão de curso.

`docker/moodle/entrypoint.sh` gera o `config.php` a cada subida e instala o Moodle na primeira vez (`admin/cli/install_database.php`).

## Por onde começar a explorar
- **Administração do site → Cursos → Gerenciar cursos e categorias**: crie um curso de teste (ex. "Vestibular 2027.1") e veja o ID na URL (`course/view.php?id=…`).
- No curso: **Modo de edição** → adicionar atividade **Questionário** (a prova).
- **Participantes**: matrícula manual de usuários (papel *Estudante* = `roleid 5`).
- **Notas → Relatório de notas**: mostra o questionário e o **Total do curso**.
- **Administração → Servidor → Web services**: aqui será configurado o serviço "CRM Vestibular FAI" (fase 2). Em **API Documentation** aparecem as funções disponíveis (`core_user_create_users`, `enrol_manual_enrol_users`…).

## Integração CRM (fase 2)
Ao subir, o `setup.php` configura web services, o usuário técnico `ws_crm`, o serviço "CRM Vestibular FAI", o curso **Vestibular 2027.1** e a prova de teste. Saídas em `output/`:
- `output/token.txt`: token do serviço (use no Bruno e no CRM);
- `output/ids.json`: `courseid`, `quizid`, `cmid`, `serviceid`.

**API JSON (formato principal):** `POST http://localhost:8080/local/faicrm/rest_json.php/<função>` (a forma `?wsfunction=<função>` também vale; contrato completo em [`docs/openapi.yaml`](openapi.yaml)) com `Authorization: Bearer <token>` e `Content-Type: application/json`. O corpo é o JSON do cliente (ex.: `{"users":[{"username":"…","password":"…","firstname":"…","lastname":"…","email":"…","auth":"manual","idnumber":"…"}]}` ou `{"enrolments":[{"userid":10,"courseid":2}]}`; `roleid` é opcional e o padrão é o papel `student`). O adaptador (plugin `local_faicrm`) delega ao servidor REST nativo. Erros respondem com status HTTP coerente (400, 401, 403, 404, 405, 409, 413, 500, 503) e corpo só `{"message": "…"}` em português; o detalhe técnico fica no log do Moodle, localizável pelo header `X-Request-Id`. Matricular e desmatricular respondem **204** sem corpo. O endpoint nativo `/webservice/rest/server.php` (form-urlencoded) continua disponível como alternativa.

**Bruno (simula o CRM, com corpo JSON):** abra a pasta `bruno/` em *Open Collection* e escolha o ambiente `local`.

*Token (segredo, nunca vai para o Git):* o ambiente `local` declara `token` como **segredo** (`vars:secret`), então o arquivo não guarda o valor. No app: **Environments → local → token →** cole o conteúdo de `output/token.txt` (o Bruno guarda segredos só na sua máquina). Na CLI: `npx @usebruno/cli run --env local --env-var "token=$(cat output/token.txt)"` (na CLI do Bruno o token secreto do ambiente sai vazio, então passe sempre `--env-var token=$(cat output/token.txt)`). Se existir um `bruno/collection.bru` local (está no `.gitignore`), não coloque `token` nem `baseUrl` nele: pela precedência de variáveis do Bruno (execução > requisição > pasta > **ambiente** > coleção > global) o valor do ambiente vale mais que o da coleção, e um valor vazio no ambiente anularia o da coleção.

*Candidato único a cada execução:* o script do `01` gera um candidato novo (username de 11 dígitos começando com 9, senha igual ao username, e-mail `candidato<username>@email.com`, nome "Candidato Teste") e o `02` o cria; o `userid` devolvido fica numa variável de execução usada do `03` ao `07`. Nada é gravado no ambiente. Por isso é só rodar `01` a `07` (ou a coleção toda) quantas vezes quiser, sem trocar nada. Se rodar o `02` sozinho, ele gera o candidato. Para limpar os candidatos de teste depois:
```powershell
docker compose exec -u www-data moodle php -r 'define("CLI_SCRIPT",1);require("/var/www/html/config.php");require_once($CFG->dirroot."/user/lib.php");foreach($DB->get_records_select("user","deleted=0 AND firstname=? AND lastname=?",["Candidato","Teste"]) as $u){delete_user($u);}'
```

**Ordem de teste (a coleção já vem na ordem certa; é só rodar de cima para baixo):**
`01` localizar (candidato novo, `[]`) → `02` criar → `02b` papel não permitido (403, antes de matricular) → `03` matricular sem `roleid` (204) → `03d` matricular de novo (409) → `04` resultados (`nota: null`, `concluido: false`) → `04b` página inválida (400) → `04c` provas de hoje → `04d` data inválida (400) → `04e` últimos 30 dias → `04f` data sem provas (`total: 0`) → `04g` `porpagina` não aceito (400) → `05`/`06` conclusão e notas nativas → `07` desmatricular (204) → `07b` desmatricular de novo (404) → `08` matricular com `roleid` 5 (204, depois da desmatrícula) → `95` a `99` erros: campo obrigatório (400), curso inexistente (404), candidato duplicado (409), JSON inválido (400) e token inválido (401).

As datas dos itens `04c`, `04e` e `04f` são calculadas por script (nada para digitar). Para o `04c` ("provas de hoje") e o `04e` (últimos 30 dias) trazerem alguém, o candidato precisa ter feito a prova: depois do `02`, simule a prova com o username que o `01` gerou (veja a aba Vars ou o corpo do `02`) e rode o `04c` de novo:
```powershell
docker compose exec -u www-data moodle php /opt/fai/simular_prova.php --username=<username gerado> --acertos=4
```
A conclusão depende do cron (roda a cada minuto).

Guia completo (fluxo, parâmetros, erros, implantação na FAI): [docs/integracao-crm.md](integracao-crm.md).

## Próxima fase
Implantar o plugin `local_faicrm` e o serviço no Moodle da FAI (checklist em [docs/integracao-crm.md](integracao-crm.md)), validar a política de senha e o `courseid` reais, e integrar o backend do CRM usando o token.
