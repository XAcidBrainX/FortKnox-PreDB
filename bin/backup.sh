#!/bin/bash
BACKUP_DIR="/var/backups/fortknox"
DATE=$(date +"%Y-%m-%d_%H-%M")
ENV_FILE="/var/www/FortKnox-PreDB/.env"

echo "Starte FortKnox Backup: $DATE"

# Datenbank-Zugangsdaten aus der .env-Datei auslesen
eval $(grep -E '^(DB_USERNAME|DB_PASSWORD|DB_DATABASE)=' $ENV_FILE)

# 1. Datenbank sichern (mit Komprimierung)
echo "Sichere Datenbank..."
# --single-transaction verhindert das Sperren der Tabellen, die Bots laufen ungestört weiter
mysqldump --single-transaction --quick -h 127.0.0.1 -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" | gzip > "$BACKUP_DIR/db_$DATE.sql.gz"

# 2. Web-Dateien sichern
echo "Sichere Projektdateien..."
tar -czf "$BACKUP_DIR/files_$DATE.tar.gz" -C /var/www FortKnox-PreDB

# 3. Alte Backups löschen (älter als 7 Tage)
echo "Räume Backups auf, die älter als 7 Tage sind..."
find "$BACKUP_DIR" -type f -mtime +7 -delete

echo "Backup erfolgreich beendet."
