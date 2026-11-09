# The-Lounge-Benutzerverwaltung (Admin-Panel)

Deploy-Artefakte für den Reiter **„💬 The Lounge“** im FortKnox-Admin-Panel.
Damit werden Benutzer der FiSH-Monitor-Instanz
(`THELOUNGE_HOME=/var/lib/fortknox-fish-lounge-test`, Port `9001`) verwaltet.

## Dateien

| Datei | Zweck |
|---|---|
| `fortknox-lounge-userctl` | Schmaler Helfer, läuft als Systembenutzer `thelounge`. Unterstützt ausschließlich `list`, `add`, `reset` und `remove`. Passwörter werden über **stdin** gelesen und als bcrypt-Hash (11 Runden, wie The Lounge selbst) gespeichert. |
| `fortknox-lounge-users.sudoers` | Erlaubt ausschließlich `www-data`, genau diesen Helfer als `thelounge` auszuführen. |

## Installation

Die Dateien müssen außerhalb des Projektverzeichnisses liegen, weil der
Webserver-Benutzer `www-data` das Projektverzeichnis `bin/` schreiben kann.
Ein dort liegender Helfer wäre durch eine kompromittierte Web-App austauschbar.

```bash
sudo install -o root -g root -m 0755 \
  deploy/lounge-user-management/fortknox-lounge-userctl \
  /usr/local/bin/fortknox-lounge-userctl

sudo install -o root -g root -m 0440 \
  deploy/lounge-user-management/fortknox-lounge-users.sudoers \
  /etc/sudoers.d/fortknox-lounge-users

sudo visudo -cf /etc/sudoers.d/fortknox-lounge-users
```

Test als Webserver-Benutzer:

```bash
sudo -u www-data sudo -n -u thelounge /usr/local/bin/fortknox-lounge-userctl list
```

## Verwendung im Admin-Panel

`https://<host>/admin.html` → Reiter **💬 The Lounge**:

- Benutzer anlegen (Name, Passwort, optional Chatverläufe speichern)
- Benutzer auflisten (Netzwerke, Channels, Sessions, letzte Aktivität)
- Passwort zurücksetzen (beendet alle Lounge-Sessions des Benutzers)
- Benutzer löschen

Die Änderungen greifen live über den User-Watcher von The Lounge; ein
Dienst-Neustart ist nicht erforderlich.

## API

Alle Routen sind admin-authentifiziert:

```text
GET  /api/admin/lounge/users
POST /api/admin/lounge/users
POST /api/admin/lounge/users/{name}/reset
POST /api/admin/lounge/users/{name}/delete
```

## Sicherheit / Betrieb

- PHP (`www-data`) liest oder schreibt die Lounge-Userdateien **nicht direkt**.
- Der Helfer gibt nur Metadaten zurück – keine Passwort-Hashes, Session-Tokens
  oder FiSH-Schlüssel.
- Benutzernamen: 2–32 Zeichen, `A-Z a-z 0-9 . _ -`
- Passwörter: 8–200 Zeichen, keine NUL-Bytes
- Die im Helfer hinterlegten Pfade sind bewusst fest verdrahtet:
  - Lounge-User: `/var/lib/fortknox-fish-lounge-test/users`
  - bcryptjs: `/opt/fortknox-fish-lounge/staging/thelounge/node_modules/bcryptjs`

Bei einem Umzug der Lounge-Instanz müssen diese Pfade im Helfer angepasst und
die Datei erneut nach `/usr/local/bin` installiert werden.
