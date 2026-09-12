#!/bin/bash

set -u

PROJECT_DIR="/root/FortKnox-PreDB"
BACKUP_DIR="$PROJECT_DIR/storage/backups"
TIMESTAMP="$(date '+%Y-%m-%d_%H-%M-%S')"
BACKUP_PATH="$BACKUP_DIR/$TIMESTAMP"

cd "$PROJECT_DIR" || exit 1

fail() {
    echo
    echo "[FEHLER] $1"
    echo "Update wurde abgebrochen."
    echo
    exit 1
}

ok() {
    echo "[OK] $1"
}

echo
echo "=============================================="
echo "       FortKnox PreDB Update System"
echo "=============================================="
echo

echo "[1/9] Projekt pr√ºfen..."

[ -f "composer.json" ] || fail "composer.json fehlt."
[ -f ".env" ] || fail ".env fehlt."
[ -d "src" ] || fail "src fehlt."
[ -d "public" ] || fail "public fehlt."
[ -d "database/migrations" ] || fail "Migration-Verzeichnis fehlt."

ok "Projekt gefunden."


echo
echo "[2/9] Backup erstellen..."

mkdir -p "$BACKUP_PATH" \
    || fail "Backup-Verzeichnis konnte nicht erstellt werden."

tar \
    --exclude='./storage/backups' \
    --exclude='./vendor' \
    -czf "$BACKUP_PATH/fortknox-project.tar.gz" \
    . \
    || fail "Projekt-Backup fehlgeschlagen."

ok "Backup erstellt:"
echo "     $BACKUP_PATH/fortknox-project.tar.gz"


echo
echo "[3/9] Git-Status pr√ºfen..."

git status --short

ok "Git-Status gepr√ºft."


echo
echo "[4/9] Composer pr√ºfen..."

command -v composer >/dev/null 2>&1 \
    || fail "Composer wurde nicht gefunden."

composer validate --no-check-publish \
    || fail "Composer-Konfiguration ist ung√ºltig."

ok "Composer-Konfiguration OK."


echo
echo "[5/9] Datenbank-Migrationen ausf√ºhren..."

if [ ! -x "bin/fortknox-migrate" ]; then
    chmod +x bin/fortknox-migrate \
        || fail "fortknox-migrate konnte nicht ausf√ºhrbar gemacht werden."
fi

if ! bin/fortknox-migrate; then
    fail "Datenbank-Migration fehlgeschlagen."
fi

ok "Migrationen gepr√ºft."


echo
echo "[6/9] Composer Autoloader aktualisieren..."

composer dump-autoload --no-interaction \
    || fail "Composer Autoloader konnte nicht aktualisiert werden."

ok "Autoloader OK."


echo
echo "[7/9] PHP-Syntax pr√ºfen..."

PHP_ERRORS=0

while IFS= read -r file; do
    if ! php -l "$file" >/dev/null 2>&1; then
        echo "[FEHLER] PHP-Syntax: $file"
        php -l "$file"
        PHP_ERRORS=$((PHP_ERRORS + 1))
    fi
done < <(
    find src public bin \
        -type f \
        -name "*.php" \
        ! -path "*/vendor/*" \
        2>/dev/null
)

if [ "$PHP_ERRORS" -gt 0 ]; then
    fail "$PHP_ERRORS PHP-Datei(en) enthalten Syntaxfehler."
fi

ok "PHP-Syntax vollst√§ndig OK."


echo
echo "[8/9] Dashboard API testen..."

if ! curl -fsS \
    --max-time 10 \
    http://127.0.0.1:8080/api/dashboard \
    >/tmp/fortknox-dashboard-test.json
then
    fail "Dashboard API nicht erreichbar."
fi

if ! grep -q '"success"[[:space:]]*:[[:space:]]*true' \
    /tmp/fortknox-dashboard-test.json
then
    cat /tmp/fortknox-dashboard-test.json
    fail "Dashboard API liefert kein success=true."
fi

ok "Dashboard API OK."


echo
echo "[9/9] Release API testen..."

if ! curl -fsS \
    --max-time 10 \
    http://127.0.0.1:8080/api/releases/5 \
    >/tmp/fortknox-release-test.json
then
    fail "Release API nicht erreichbar."
fi

if ! grep -q '"success"[[:space:]]*:[[:space:]]*true' \
    /tmp/fortknox-release-test.json
then
    cat /tmp/fortknox-release-test.json
    fail "Release API liefert kein success=true."
fi

ok "Release API OK."


echo
echo "=============================================="
echo "        FORTKNOX UPDATE ERFOLGREICH"
echo "=============================================="
echo
echo "Backup:"
echo "  $BACKUP_PATH/fortknox-project.tar.gz"
echo
echo "Migrationen:"
echo "  automatisch gepr√ºft"
echo
echo "PHP:"
echo "  Syntax OK"
echo
echo "API:"
echo "  Dashboard OK"
echo "  Release API OK"
echo
echo "FortKnox ist bereit. Ì†ΩÌ∫Ä"
echo