#!/bin/sh
# PHPUnit do Moodle dentro do container (o banco de testes usa o prefixo phpu_ no mesmo MariaDB).
#   scripts/phpunit.sh init                                    inicializa/atualiza o ambiente de testes
#   scripts/phpunit.sh                                         roda todos os testes do local_faicrm
#   scripts/phpunit.sh local/faicrm/tests/x_test.php [--filter nome]   roda um arquivo
set -e
export MSYS_NO_PATHCONV=1  # Git Bash no Windows não pode converter /var/... em caminho do Windows
cd "$(dirname "$0")/.."

dc() { docker compose exec -T -u www-data moodle "$@"; }

case "$1" in
  init)
    dc php admin/tool/phpunit/cli/init.php
    ;;
  *)
    # O init é incremental: só reinstala o que mudou (e regenera o phpunit.xml com os testes do plugin).
    dc test -f phpunit.xml || dc php admin/tool/phpunit/cli/init.php
    alvo="${1:-local_faicrm}"
    [ $# -gt 0 ] && shift
    case "$alvo" in
      *.php|*/*) dc vendor/bin/phpunit "$@" "$alvo" ;;
      *)         dc vendor/bin/phpunit --testsuite "${alvo}_testsuite" "$@" ;;
    esac
    ;;
esac
