# Moodle FAI — ambiente de desenvolvimento (Docker)

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

**API JSON (formato principal):** `POST http://localhost:8080/local/faicrm/rest_json.php?wsfunction=<função>` com `Authorization: Bearer <token>` e `Content-Type: application/json`. O corpo é o JSON do cliente (ex.: `{"users":[{"username":"…","password":"…","firstname":"…","lastname":"…","email":"…","auth":"manual","idnumber":"…"}]}` ou `{"enrolments":[{"userid":10,"courseid":2}]}`; `roleid` é opcional e o padrão é o papel `student`). O adaptador (plugin `local_faicrm`) delega ao servidor REST nativo. Erros respondem com status HTTP coerente (400, 401, 403, 404, 405, 409, 413, 500, 503) e corpo só `{"message": "…"}` em português; o detalhe técnico fica no log do Moodle, localizável pelo header `X-Request-Id`. Matricular e desmatricular respondem **204** sem corpo. O endpoint nativo `/webservice/rest/server.php` (form-urlencoded) continua disponível como alternativa.

**Bruno (simula o CRM, com corpo JSON):** abra a pasta `bruno/` em *Open Collection*, escolha o ambiente `local` e preencha a variável `token` com o conteúdo de `output/token.txt` (e `courseid` com o de `ids.json`, se for diferente de 2).
O script do 02/01 grava `userid` no ambiente (o Bruno salva em `environments/local.bru`); não versione esse valor.
Cada candidato precisa de `username` **e** `email` únicos — para um novo teste, troque os dois.

**Ordem de teste:** `01` localizar → `02` criar → `03` matricular (sem `roleid`; `03b` envia o `roleid`) → `04` resultados (`nota: null`, `concluido: false`). Depois simule a prova (4 acertos de 5 = nota 800) e rode o `04` de novo:
```powershell
docker compose exec -u www-data moodle php /opt/fai/simular_prova.php --username=12345678900 --acertos=4
```
A conclusão depende do cron (roda a cada minuto). `05`/`06` são as funções nativas de conclusão e notas, `07` desmatricula (03, 03b e 07 devolvem 204), `95` a `99` mostram erros: campo obrigatório (400), curso inexistente (404), candidato duplicado (409), JSON inválido (400) e token inválido (401).

Guia completo (fluxo, parâmetros, erros, implantação na FAI): [docs/integracao-crm.md](docs/integracao-crm.md).

## Próxima fase
Implantar o plugin `local_faicrm` e o serviço no Moodle da FAI (checklist em [docs/integracao-crm.md](docs/integracao-crm.md)), validar a política de senha e o `courseid` reais, e integrar o backend do CRM usando o token.
