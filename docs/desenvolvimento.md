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

**Bruno (simula o CRM, com corpo JSON):** abra a pasta `bruno/` em *Open Collection*. Cada requisição já vem pronta: URL completa (`http://localhost:8080/local/faicrm/rest_json.php/<função>`), corpo JSON escrito direto, sem ambiente, variáveis, scripts nem testes. O que ela faz e a resposta esperada estão na aba *Docs* de cada uma.

*Token (uma vez só):* em **Collection → Auth → Bearer**, cole o conteúdo de `output/token.txt`. Todas as requisições usam essa autenticação (`auth: inherit`); só a `99` leva um token inválido de propósito. O token fica no `bruno/collection.bru`, que está no `.gitignore` e não vai para o Git. Na CLI não há o que configurar além do arquivo local: `cd bruno && npx @usebruno/cli run`.

*Dados fixos (já existem no Moodle local):* **Maria da Silva** (userid 72, username `12345678900`, `maria@email.com`) e **João Santos** (userid 73, `98765432100`, `joao@email.com`), curso 2. A **Ana Souza** (`11122233344`) não existe: é criada pelo `02`. Se recriar o ambiente do zero, crie a Maria e o João de novo e ajuste os `userid` nos corpos.

**Ordem (já na sequência certa, de cima para baixo):**
`01` localizar a Maria (200) → `02` criar a Ana (200 na 1ª vez, 409 depois) → `02b` papel não permitido (403) → `03` matricular a Maria (204) → `03b` matricular de novo (409) → `04` resultados → `04b` página inválida (400) → `04c` provas em 07/10/2026 → `04d` provas no período → `04e` data sem provas (`total: 0`) → `04f` data inválida (400) → `04g` `porpagina` não aceito (400) → `05` conclusão da Maria → `06` notas da Maria → `07` desmatricular a Maria (204) → `07b` desmatricular de novo (404) → `08` matricular o João com `roleid` 5 (204) → `09` desmatricular o João (204, limpeza) → `95` campo obrigatório (400) → `96` curso inexistente (404) → `97` candidato duplicado (409) → `98` JSON inválido (400) → `99` token inválido (401).

Como as datas dos filtros (`04c`, `04d`) estão escritas no corpo, ajuste-as se quiser outro período. Para o `04c` trazer alguém, é preciso que alguém tenha feito a prova nesse dia (`simular_prova.php` ou a prova no navegador):
```powershell
docker compose exec -u www-data moodle php /opt/fai/simular_prova.php --username=12345678900 --acertos=4
```
A conclusão depende do cron (roda a cada minuto). Para repetir o `02` com 200, apague a Ana:
```powershell
docker compose exec -u www-data moodle php -r 'define("CLI_SCRIPT",1);require("/var/www/html/config.php");require_once($CFG->dirroot."/user/lib.php");$a=$DB->get_record("user",["username"=>"11122233344","deleted"=>0]);if($a){delete_user($a);}'
```

Guia completo (fluxo, parâmetros, erros, implantação na FAI): [docs/integracao-crm.md](integracao-crm.md).

## Próxima fase
Implantar o plugin `local_faicrm` e o serviço no Moodle da FAI (checklist em [docs/integracao-crm.md](integracao-crm.md)), validar a política de senha e o `courseid` reais, e integrar o backend do CRM usando o token.
