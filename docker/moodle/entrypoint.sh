#!/bin/sh
set -e

MOODLE_DIR=/var/www/html
DATAROOT=/var/www/moodledata
asweb() { su -s /bin/sh www-data -c "$*"; }

# config.php é gerado a cada subida a partir das variáveis de ambiente
cat > "$MOODLE_DIR/config.php" <<PHP
<?php
unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();
\$CFG->dbtype    = 'mariadb';
\$CFG->dblibrary = 'native';
\$CFG->dbhost    = '${DB_HOST}';
\$CFG->dbname    = '${DB_NAME}';
\$CFG->dbuser    = '${DB_USER}';
\$CFG->dbpass    = '${DB_PASS}';
\$CFG->prefix    = 'mdl_';
\$CFG->dboptions = ['dbpersist' => 0, 'dbport' => '', 'dbsocket' => '', 'dbcollation' => 'utf8mb4_unicode_ci'];
\$CFG->wwwroot   = '${MOODLE_URL}';
\$CFG->dataroot  = '${DATAROOT}';
\$CFG->admin     = 'admin';
\$CFG->directorypermissions = 02777;
\$CFG->noemailever = true; // dev: não há servidor de e-mail nos containers
require_once(__DIR__ . '/lib/setup.php');
PHP
chown www-data:www-data "$MOODLE_DIR/config.php"

mkdir -p "$DATAROOT/lang"
chown -R www-data:www-data "$DATAROOT"
if [ -n "$MOODLE_LANG" ] && [ ! -d "$DATAROOT/lang/$MOODLE_LANG" ] && [ -f "/opt/langpacks/$MOODLE_LANG.zip" ]; then
    asweb "unzip -q /opt/langpacks/$MOODLE_LANG.zip -d $DATAROOT/lang"
fi

is_installed() {
    php -r '$m = @new mysqli(getenv("DB_HOST"), getenv("DB_USER"), getenv("DB_PASS"), getenv("DB_NAME"));
            if ($m->connect_errno) exit(2);
            $r = $m->query("SHOW TABLES LIKE \"mdl_config\"");
            exit($r && $r->num_rows ? 0 : 1);'
}

wait_db() {
    i=0
    while :; do
        is_installed && return 0 || rc=$?
        [ "$rc" = 1 ] && return 0
        i=$((i+1)); [ $i -gt 60 ] && { echo "Banco indisponível"; exit 1; }
        sleep 2
    done
}

case "$1" in
  web)
    wait_db
    if is_installed; then
        echo ">> Moodle já instalado; aplicando upgrade se necessário..."
        asweb "php admin/cli/upgrade.php --non-interactive" || true
    else
        echo ">> Instalando Moodle (pode levar alguns minutos)..."
        asweb "php admin/cli/install_database.php --agree-license \
            --lang='${MOODLE_LANG:-en}' \
            --fullname='${MOODLE_SITE_NAME}' --shortname='MoodleFAI' \
            --adminuser='${MOODLE_ADMIN_USER}' --adminpass='${MOODLE_ADMIN_PASS}' \
            --adminemail='${MOODLE_ADMIN_EMAIL}' --supportemail='${MOODLE_ADMIN_EMAIL}'"
        asweb "php admin/cli/cfg.php --name=timezone --set=America/Sao_Paulo"
        asweb "php admin/cli/cfg.php --name=country --set=BR"
        asweb "php admin/cli/cfg.php --name=noreplyaddress --set=noreply@localhost.local"
        echo ">> Instalação concluída."
    fi
    echo ">> Configurando integração CRM (setup.php)..."
    mkdir -p /opt/fai/output
    chown www-data:www-data /opt/fai/output 2>/dev/null || chmod 0777 /opt/fai/output || true
    asweb "php /opt/fai/setup.php" || echo "!! setup.php falhou (veja a mensagem acima); o Moodle seguirá no ar sem a configuração da integração."
    asweb "touch $DATAROOT/.installed"
    echo ">> Moodle disponível em ${MOODLE_URL} (usuário: ${MOODLE_ADMIN_USER})"
    exec apache2-foreground
    ;;
  cron)
    echo ">> Aguardando instalação do Moodle para iniciar o cron..."
    until [ -f "$DATAROOT/.installed" ]; do sleep 10; done
    while :; do
        asweb "php admin/cli/cron.php" >/dev/null 2>&1 || echo "cron: falhou (veja logs do Moodle)"
        sleep 60
    done
    ;;
  *)
    exec "$@"
    ;;
esac
