# 001 — Ambiente de desenvolvimento

**Status:** ✅ concluída (2026-10-06)

## Objetivo
Moodle limpo local, mesma linha da FAI (4.5.14+ / PHP 8.1), para explorar e desenvolver a integração.

## Entregue
- `docker-compose.yml`: `db` (MariaDB 10.11), `moodle` (php:8.1-apache + Moodle `MOODLE_405_STABLE`), `cron` (cron.php a cada 60s).
- `docker/moodle/entrypoint.sh`: gera `config.php` a cada subida; 1ª subida instala via `install_database.php` (pt_br, America/Sao_Paulo, noreplyaddress); depois `upgrade.php`.
- Acesso: http://localhost:8080 — `admin` / `Admin@12345` (dev). Variáveis em `.env` (modelo `.env.example`).

## Critérios de aceite (verificados)
- Login admin funciona; Administração → Servidor → Web services acessível; console sem erros.
- `$release = 4.5.14+ (Build: 20261002)`, PHP 8.1.34.
