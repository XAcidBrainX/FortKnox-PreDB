# The Lounge – FortKnox Neon Theme

Das laufende FiSH-IRC-Monitor-Theme liegt bewusst **außerhalb** dieses Repos
(The Lounge wird aus `/opt/fortknox-fish-lounge` ausgeliefert). Damit die
Anpassung bei einem Update nicht verloren geht, liegt hier eine versionierte Kopie.

## Installation / Wiederherstellung

1. Theme in das The-Lounge-Paket kopieren:

   ```bash
   cp deploy/thelounge/fortknox.css \
      /opt/fortknox-fish-lounge/staging/thelounge/public/themes/fortknox.css
   chown root:thelounge /opt/fortknox-fish-lounge/staging/thelounge/public/themes/fortknox.css
   chmod 644 /opt/fortknox-fish-lounge/staging/thelounge/public/themes/fortknox.css
   ```

2. Standard-Theme in der Instanz-Config setzen:

   ```js
   // /var/lib/fortknox-fish-lounge-test/config.js
   theme: "fortknox",
   ```

3. Bestehende Benutzer auf das Theme umstellen (optional, pro Benutzer):

   ```bash
   python3 - <<'PY'
   import json, glob
   for p in glob.glob('/var/lib/fortknox-fish-lounge-test/users/*.json'):
       with open(p, encoding='utf-8') as f:
           d = json.load(f)
       d['theme'] = 'fortknox'
       with open(p, 'w', encoding='utf-8') as f:
           json.dump(d, f, indent='\t', ensure_ascii=False)
   PY
   ```

4. Dienst neu starten:

   ```bash
   systemctl restart fortknox-fish-lounge-test.service
   ```

Die Farben/Fonts sind an `public/assets/fk-neon.css` angelehnt (Neon-Grün,
Cyan, metallische Wortmarke). Die Wortmarke auf Login-/Loading-Screen wird
per CSS aus dem Standard-Logo ersetzt.
