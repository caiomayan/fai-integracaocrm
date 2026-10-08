#!/usr/bin/env bash
# Gera dist/local_faicrm-<release>.zip com a pasta "faicrm" na raiz, instalável em
# Administração do site > Plugins > Instalar plugins. O release vem do version.php.
#
# Uso:  scripts/empacotar-plugin.sh
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN="$RAIZ/moodle-plugin/local/faicrm"
VERSION_PHP="$PLUGIN/version.php"
[ -f "$VERSION_PHP" ] || { echo "ERRO: $VERSION_PHP não encontrado" >&2; exit 1; }

RELEASE="$(grep -E "^[$]plugin->release" "$VERSION_PHP" | head -n1 | cut -d"'" -f2)"
[ -n "$RELEASE" ] || { echo "ERRO: não foi possível ler o release do version.php" >&2; exit 1; }

# Segurança: o pacote vai para a produção da FAI, então nada de arquivo de desenvolvimento
# (token, .env, logs, saídas, links simbólicos). Só tipos de arquivo de plugin Moodle entram;
# qualquer outra coisa na pasta do plugin interrompe o empacotamento.
PROIBIDOS="$(cd "$PLUGIN" && find . -mindepth 1 \
    \( -type l \
    -o \( -name '.*' ! -name '.DS_Store' \) \
    -o -iname '*token*' -o -iname '*secret*' -o -iname '*.env' -o -iname '*.log' \
    -o -iname '*.zip' -o -iname '*.bak' -o -iname '*.swp' -o -name '*~' \
    -o -name output -o -name dist -o -name node_modules -o -name vendor \) -print)"
FORA_DO_PADRAO="$(cd "$PLUGIN" && find . -type f ! -name '.DS_Store' ! \( -name '*.php' -o -name '*.md' \
    -o -name '*.txt' -o -name '*.xml' -o -name '*.js' -o -name '*.json' -o -name '*.mustache' -o -name '*.css' \
    -o -name '*.scss' -o -name '*.svg' -o -name '*.png' -o -name '*.gif' -o -name '*.jpg' -o -name '*.feature' \) -print)"
if [ -n "$PROIBIDOS$FORA_DO_PADRAO" ]; then
    echo "ERRO: arquivos que não podem ir no pacote (remova-os de moodle-plugin/local/faicrm):" >&2
    printf '%s\n%s\n' "$PROIBIDOS" "$FORA_DO_PADRAO" | sed '/^$/d' | sort -u | sed 's/^/  /' >&2
    exit 1
fi

DESTINO="$RAIZ/dist/local_faicrm-$RELEASE.zip"
mkdir -p "$RAIZ/dist"
rm -f "$DESTINO"

if command -v zip >/dev/null 2>&1; then
    # Copia para uma pasta temporária chamada "faicrm" para garantir o nome na raiz do zip.
    TMP="$(mktemp -d)"
    trap 'rm -rf "$TMP"' EXIT
    cp -R "$PLUGIN" "$TMP/faicrm"
    (cd "$TMP" && zip -qr "$DESTINO" faicrm -x '*.DS_Store' -x '*/.git/*')
else
    PY="$(command -v python3 || command -v python || true)"
    [ -n "$PY" ] || { echo "ERRO: instale 'zip' ou Python 3 para gerar o pacote" >&2; exit 1; }
    "$PY" - "$PLUGIN" "$DESTINO" <<'PYEOF'
import os, sys, zipfile
plugin, destino = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(destino, 'w', zipfile.ZIP_DEFLATED) as z:
    for pasta, dirs, arquivos in os.walk(plugin):
        dirs[:] = sorted(d for d in dirs if d != '.git')
        for nome in sorted(arquivos):
            if nome == '.DS_Store':
                continue
            caminho = os.path.join(pasta, nome)
            z.write(caminho, 'faicrm/' + os.path.relpath(caminho, plugin).replace(os.sep, '/'))
PYEOF
fi

echo "Pacote gerado: dist/local_faicrm-$RELEASE.zip"
