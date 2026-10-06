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

## Próxima fase
Configurar o serviço web, o usuário técnico e o token, criar a função de resultados (nota + concluído) e montar a coleção do Bruno que simula o CRM.
