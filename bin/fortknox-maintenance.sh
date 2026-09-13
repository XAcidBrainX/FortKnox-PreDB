#!/usr/bin/env bash
set -euo pipefail

ENV_FILE="/root/FortKnox-PreDB/.env"
BACKUP_DIR="/root/FortKnox-PreDB/backups"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/fortknox_${TIMESTAMP}.sql.gz"

if [ ! -f "${ENV_FILE}" ]; then
    echo "[ERROR] .env file not found at ${ENV_FILE}" >&2
    exit 1
fi

mkdir -p "${BACKUP_DIR}"

# Zugangsdaten aus .env extrahieren
get_env() {
    grep -E "^${1}=" "${ENV_FILE}" | cut -d '=' -f2- | tr -d '"' | tr -d "'" | tr -d '\r'
}

DB_HOST=$(get_env "DB_HOST")
DB_PORT=$(get_env "DB_PORT")
DB_NAME=$(get_env "DB_DATABASE")
DB_USER=$(get_env "DB_USERNAME")
DB_PASS=$(get_env "DB_PASSWORD")

# Passendes Dump-Tool ermitteln
DUMP_BIN=$(command -v mariadb-dump || command -v mysqldump || true)
if [ -z "${DUMP_BIN}" ]; then
    echo "[ERROR] Neither mariadb-dump nor mysqldump found." >&2
    exit 1
fi

MYSQL_BIN=$(command -v mariadb || command -v mysql || true)

# 1. Non-blocking Online-Backup
MYSQL_PWD="${DB_PASS}" "${DUMP_BIN}" \
    -h "${DB_HOST}" \
    -P "${DB_PORT}" \
    -u "${DB_USER}" \
    --single-transaction \
    --quick \
    "${DB_NAME}" | gzip -9 > "${BACKUP_FILE}"

# 2. Tabellen optimieren & Indizes defragmentieren
if [ -n "${MYSQL_BIN}" ]; then
    MYSQL_PWD="${DB_PASS}" "${MYSQL_BIN}" \
        -h "${DB_HOST}" \
        -P "${DB_PORT}" \
        -u "${DB_USER}" \
        -e "OPTIMIZE TABLE releases;" "${DB_NAME}" > /dev/null 2>&1 || true
fi

# 3. Backups rotieren (älter als 7 Tage entfernen)
find "${BACKUP_DIR}" -type f -name "fortknox_*.sql.gz" -mtime +7 -delete

echo "[$(date '+%Y-%m-%d %H:%M:%S')] MariaDB maintenance & backup completed: ${BACKUP_FILE}"
