#!/usr/bin/env bash
# Respaldo del portal de proveedores: base de datos MySQL + documentos subidos.
#
# Uso:   scripts/respaldo.sh [carpeta_destino]
# Lee DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME y DB_PASSWORD del entorno o de api/.env.
# Variables opcionales: RETENCION_DIAS (por defecto 14), CARPETA_DOCUMENTOS (por defecto api/storage/app).
#
# Para programarlo a diario (cron):  30 2 * * *  /ruta/al/proyecto/scripts/respaldo.sh /var/respaldos/portal
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DESTINO="${1:-$RAIZ/respaldos}"
RETENCION_DIAS="${RETENCION_DIAS:-14}"
CARPETA_DOCUMENTOS="${CARPETA_DOCUMENTOS:-$RAIZ/api/storage/app}"

# Lee una variable de api/.env sin ejecutar el archivo.
leer_env() {
  [ -f "$RAIZ/api/.env" ] || return 0
  grep -E "^$1=" "$RAIZ/api/.env" | head -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

DB_HOST="${DB_HOST:-$(leer_env DB_HOST)}"
DB_PORT="${DB_PORT:-$(leer_env DB_PORT)}"
DB_DATABASE="${DB_DATABASE:-$(leer_env DB_DATABASE)}"
DB_USERNAME="${DB_USERNAME:-$(leer_env DB_USERNAME)}"
DB_PASSWORD="${DB_PASSWORD:-$(leer_env DB_PASSWORD)}"

: "${DB_DATABASE:?Falta DB_DATABASE}"
: "${DB_USERNAME:?Falta DB_USERNAME}"

SELLO="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$DESTINO"
SQL="$DESTINO/portal_${SELLO}.sql.gz"

# La clave se entrega por archivo temporal para que no aparezca en la lista de procesos.
CNF="$(mktemp)"
trap 'rm -f "$CNF"' EXIT
chmod 600 "$CNF"
printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
  "$DB_USERNAME" "$DB_PASSWORD" "${DB_HOST:-127.0.0.1}" "${DB_PORT:-3306}" > "$CNF"

echo "Respaldando base de datos $DB_DATABASE..."
mysqldump --defaults-extra-file="$CNF" --single-transaction --routines --triggers \
  --no-tablespaces "$DB_DATABASE" | gzip > "$SQL"

# Verificación de integridad: el archivo debe descomprimir y terminar con la marca de fin de volcado.
gzip -t "$SQL"
if ! gunzip -c "$SQL" | tail -n 5 | grep -q "Dump completed"; then
  echo "ERROR: el respaldo no está completo: $SQL" >&2
  exit 1
fi
echo "Base de datos OK: $SQL ($(du -h "$SQL" | cut -f1))"

if [ -d "$CARPETA_DOCUMENTOS" ]; then
  ZIP="$DESTINO/documentos_${SELLO}.tar.gz"
  tar -czf "$ZIP" -C "$CARPETA_DOCUMENTOS" .
  tar -tzf "$ZIP" > /dev/null
  echo "Documentos OK: $ZIP ($(du -h "$ZIP" | cut -f1))"
else
  echo "Aviso: no existe la carpeta de documentos $CARPETA_DOCUMENTOS; se omite."
fi

# Retención: borra respaldos más antiguos que RETENCION_DIAS.
find "$DESTINO" -maxdepth 1 \( -name 'portal_*.sql.gz' -o -name 'documentos_*.tar.gz' \) -mtime +"$RETENCION_DIAS" -delete
echo "Respaldo terminado."
