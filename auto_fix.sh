#!/bin/bash
cd /var/www/FortKnox-PreDB

echo "==> 1. Füge { credentials: 'include' } fehlerfrei in public/admin.html ein..."
python3 -c '
path = "public/admin.html"
with open(path, "r", encoding="utf-8") as f:
    content = f.read()

import re
# Ersetzt fetch("/api/...") so, dass das Cookie-Credential-Flag sauber übergeben wird
fixed = re.sub(r"fetch\((['\"`])(/api/.*?)\1\)", r"fetch(\1\2\1, { credentials: '\''include'\'' })", content)

with open(path, "w", encoding="utf-8") as f:
    f.write(fixed)
print("[OK] admin.html aktualisiert.")
'

echo "==> 2. Bereinige alte PHP-Sessions..."
rm -rf /var/lib/php/sessions/* 2>/dev/null
rm -rf /tmp/sess_* 2>/dev/null

echo "==> 3. Korrigiere Dateirechte..."
chown -R www-data:www-data /var/www/FortKnox-PreDB
find /var/www/FortKnox-PreDB -type d -exec chmod 755 {} \;
find /var/www/FortKnox-PreDB -type f -exec chmod 644 {} \;

echo "==> 4. Prüfe PHP Syntax von AuthController.php..."
php -l src/Web/Controller/AuthController.php

echo "==> 5. Starte Apache neu..."
killall -9 apache2 passenger-memory-stats PassengerAgent 2>/dev/null
systemctl start apache2

echo "==> Fertig! Apache Status:"
systemctl status apache2 --no-pager
