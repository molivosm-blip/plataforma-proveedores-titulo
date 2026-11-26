#!/usr/bin/env bash
# Restaura un respaldo de la base de datos.
# Uso:   scripts/restaurar.sh archivo.sql.gz [base_destino]
# Por seguridad restaura en una base DISTINTA a la de producción si se indica base_destino;
# sin ese parámetro usa DB_DATABASE (sobrescribe).
set -euo pipefail

ARCHIVO="${1:?Indique el archivo .sql.gz a restaurar}"
RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

leer_env() {
  [ -f "$RAIZ/api/.env" ] || return 0
  grep -E "^$1=" "$RAIZ/api/.env" | head -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

DB_HOST="${DB_HOST:-$(leer_env DB_HOST)}"
DB_PORT="${DB_PORT:-$(leer_env DB_PORT)}"
DB_USERNAME="${DB_USERNAME:-$(leer_env DB_USERNAME)}"
DB_PASSWORD="${DB_PASSWORD:-$(leer_env DB_PASSWORD)}"
BASE="${2:-${DB_DATABASE:-$(leer_env DB_DATABASE)}}"

gzip -t "$ARCHIVO"
CNF="$(mktemp)"; trap 'rm -f "$CNF"' EXIT; chmod 600 "$CNF"
printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
  "$DB_USERNAME" "$DB_PASSWORD" "${DB_HOST:-127.0.0.1}" "${DB_PORT:-3306}" > "$CNF"

echo "Restaurando $ARCHIVO en la base $BASE..."
gunzip -c "$ARCHIVO" | mysql --defaults-extra-file="$CNF" "$BASE"
echo "Restauración terminada."
