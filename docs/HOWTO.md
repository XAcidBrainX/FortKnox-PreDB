# FortKnox PreDB – Überblick & How-to

**Stand / letzte Aktualisierung:** 01.11.2026  
**Aktiver Server:** X4All – `v35837.1blu.de`  
**Aktives Projektverzeichnis:** `/var/www/FortKnox-PreDB`  
**Git-Branch:** `main`  
**Erfasster Commit:** `f83ddb4` (27.09.2026)

> Dieses Dokument beschreibt den produktiven Stand und die am 01.11.2026 vorgenommenen Reparaturen/Erweiterungen. Änderungen wurden auf dem X4All-Server im aktiven Checkout ausgeführt. Der separate Mirror und der alte Checkout unter `/opt/FortKnox/FortKnox-PreDB` wurden nicht verändert.

---

## 1. Was ist FortKnox PreDB?

FortKnox PreDB ist eine PHP-Anwendung zur Verarbeitung von Release-Ankündigungen aus IRC. Erkannte Releases werden geparst, in MariaDB gespeichert und über eine Weboberfläche/API bereitgestellt. Zusätzlich gibt es IRC-Bot-Verwaltung, Nuke-/Dupe-Funktionen, externe Quellen, Webhooks und Backup-Funktionen.

### Komponenten

| Bereich | Aufgabe | Zentrale Dateien |
|---|---|---|
| IRC | Verbindung, Anmeldung, Channels, Nachrichtenverarbeitung | `src/IRC/`, `bin/fortknox-irc` |
| PreDB-Parser | Erkennung von Titel, Gruppe, Jahr, Staffel/Episode, Auflösung, Quelle, Sprache und Codec | `src/PreDB/ReleaseParser.php`, `src/PreDB/Parser/` |
| Import | Duplikatprüfung, Release-/Gruppen-/Event-Speicherung, Outbox und Webhook | `src/PreDB/ImportService.php` |
| Datenbank | PDO-Verbindung und SQL-Migrationen | `src/Database/`, `database/migrations/` |
| Web/API | Dashboard, Releases, Authentifizierung, Bot-Verwaltung, Quellen und Backups | `public/index.php`, `src/Web/Controller/` |
| Crawler | Externe PreDB-/Quellen-Daten | `bin/fortknox-predbnet-crawler`, `bin/fortknox-predborg-crawler`, `bin/fortknox-sources-crawler` |
| Betrieb | Backup, Wartung, Updates und Bot-Prüfung | `bin/` |

### Grober Datenfluss

1. Ein IRC-Bot verbindet sich mit einem konfigurierten IRC-Netzwerk.
2. Nachrichten aus erlaubten Channels werden durch den IRC-Parser gelesen.
3. Der Announcement-Handler filtert und übergibt passende Release-Namen an den Import-Service.
4. Der Release-Parser ermittelt Metadaten.
5. Das Repository speichert Release, Gruppe und Event in MariaDB.
6. Ausgehende IRC-Ankündigungen landen in `irc_outbox`; optionale Webhooks werden versendet.
7. Das Webpanel liest Daten über die API und stellt Verwaltungsfunktionen bereit.

---

## 2. Welches Verzeichnis ist das richtige?

**Ausschließlich dieses Verzeichnis ist die aktive Installation:**

```bash
cd /var/www/FortKnox-PreDB
```

Die beiden laufenden systemd-Prozesse verwenden genau diesen Pfad.

Es existiert außerdem `/opt/FortKnox/FortKnox-PreDB`. Das ist ein separater, deutlich älterer Git-Stand und **nicht** das produktiv laufende Projekt. Nicht versehentlich dort Änderungen durchführen.

---

## 3. Laufende Dienste prüfen

Es laufen zwei IRC-Bot-Instanzen. Beide waren bei der Prüfung aktiv und aktiviert:

| systemd-Service | Datenbank-Netzwerk-ID | Status bei Prüfung |
|---|---:|---|
| `fortknox-irc@1.service` | 1 | active (running) |
| `fortknox-irc@4.service` | 4 | active (running) |

### Status

```bash
systemctl status fortknox-irc@1.service --no-pager -l
systemctl status fortknox-irc@4.service --no-pager -l
systemctl is-active mariadb
systemctl is-active apache2
```

### Logs live verfolgen

```bash
# Aktuelle IRC-Protokolle (ab Aktivierung der Datei-Logs)
tail -f storage/logs/irc-1.log
tail -f storage/logs/irc-4.log

# Historische Journal-Einträge vor der Umstellung
journalctl -u fortknox-irc@1.service -n 200 --no-pager
journalctl -u fortknox-irc@4.service -n 200 --no-pager
```

Letzte 200 Zeilen:

```bash
journalctl -u fortknox-irc@1.service -n 200 --no-pager
journalctl -u fortknox-irc@4.service -n 200 --no-pager
```

### IRC-Bot starten / stoppen / neu starten

Nur wenn wirklich erforderlich – ein Neustart unterbricht die IRC-Verbindung:

```bash
systemctl restart fortknox-irc@1.service
systemctl restart fortknox-irc@4.service
```

Einzelnen Bot stoppen/starten:

```bash
systemctl stop fortknox-irc@1.service
systemctl start fortknox-irc@1.service
```

Die systemd-Vorlage liegt auf dem Server unter `/etc/systemd/system/fortknox-irc@.service`. Sie startet `/usr/bin/php /var/www/FortKnox-PreDB/bin/fortknox-irc --network-id=%i` mit WorkingDirectory `/var/www/FortKnox-PreDB`.

Seit 01.11.2026 werden stdout/stderr der IRC-Bots zusätzlich in `storage/logs/irc-<NETZWERK-ID>.log` geschrieben. Verzeichnisrechte: `root:www-data` / `0750`; Logdateien: `root:www-data` / `0640`. Rotation: `/etc/logrotate.d/fortknox-irc` (täglich, 14 Rotationen, komprimiert). Das Adminpanel kann diese Dateien lesend anzeigen. Die neuen Dateien enthalten nur Einträge ab der Umstellung; ältere Einträge liegen ggf. noch im systemd-Journal.

---

## 4. Konfiguration

Die produktive Konfiguration liegt in:

```
/var/www/FortKnox-PreDB/.env
```

Die Variablennamen und ein Beispiel stehen in `.env.example`. Die Datei enthält unter anderem:

- `APP_ENV`, `APP_DEBUG`, `APP_URL`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- IRC-Fallback-Einstellungen (`IRC_HOST`, `IRC_PORT`, `IRC_TLS`, Nick, Channels und Passwörter)
- Webhook-Schalter und Webhook-URL
- Admin-Konfiguration

**Wichtig:** Zugangsdaten niemals in Logs, Screenshots, Git-Commits oder Support-Nachrichten kopieren. Die IRC-Netzwerke werden bevorzugt aus der Tabelle `irc_networks` gelesen; die IRC-Werte in `.env` dienen als Fallback, falls die angeforderte Netzwerk-ID nicht in der Datenbank vorhanden ist.

---

## 5. Nützliche Kommandos

Alle folgenden Befehle im aktiven Projektverzeichnis ausführen.

### Release-Namen parsen – ohne Datenbankänderung

```bash
php bin/fortknox-parse "Show.Name.S03E07.1080p.WEB-DL.x264-GRP"
```

Das zeigt die erkannten Metadaten an und ist der beste erste Funktionstest für den Parser.

### IRC-Protokollparser testen

```bash
php bin/fortknox-irc-parser-test
```

Dieser Test verarbeitet eine feste Beispiel-IRC-Nachricht und benötigt keine Datenbank.

### Release-Filter testen

```bash
php bin/fortknox-irc-filter-test
```

Dieser Test prüft einige feste Beispiele für Release-Namen und normale Chat-Nachrichten.

### PHP-Syntax prüfen

```bash
find src public bin -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

### Composer

```bash
composer validate --no-check-publish
composer dump-autoload
```

### Datenbankmigrationen

```bash
php bin/fortknox-migrate
```

Migrationen können das produktive Schema verändern. Vorher ein geprüftes Datenbank-Backup erstellen. Nicht auf Verdacht ausführen.

### Backup

Das Skript `bin/fortknox-backup` legt unter `storage/backups/` einen komprimierten MariaDB-Dump und eine Kopie der `.env` ab. Die ENV-Kopie wird mit Modus 0600 versehen. Das Skript rotiert Dateien älter als sieben Tage.

```bash
php bin/fortknox-backup
```

Vor dem Einsatz sicherstellen, dass genügend Speicherplatz vorhanden ist und das Ergebnis tatsächlich erzeugt wurde. Ein erfolgreich beendetes Skript ersetzt keinen Restore-Test.

---

## 6. Weboberfläche und API

Apache hat virtuelle Hosts für folgende Namen konfiguriert:

- `fk-predb.com` (mit `www.fk-predb.com`)
- `predb.x4all.de`
- `fortknox.cloud` (mit `www.fortknox.cloud`)

Die Anwendung liegt im Verzeichnis `public/`; `.htaccess` leitet nicht direkt vorhandene Pfade an `public/index.php` weiter.

In `public/index.php` registrierte API-Bereiche:

| API-Bereich | Zweck |
|---|---|
| `/api/releases` | Release-Liste, Suche, Live-Abfrage, Details und Events |
| `/api/dashboard` | Dashboard-Daten |
| `/api/auth/*` | Login, Logout und Status |
| `/api/admin/bots` | IRC-Netzwerke/Bots verwalten und Services steuern |
| `/api/admin/sources` | Externe Quellen verwalten |
| `/api/admin/backups` | Backups auflisten, erstellen, herunterladen und löschen (keine Restore-Funktion im Panel) |
| `/api/admin/users` | Admin-Benutzer verwalten |
| `/api/admin/monitor/stats` | Geschützte Betriebs- und Datenbankstatistiken |
| `/api/admin/monitor/logs` | Geschützte IRC-Logzeilen und letzte Release-Events |
| `/api/releases/{id}/nuke` | Release moderieren (Nuke/UnNuke/Dupe) |

Admin-Routen sind im Frontcontroller durch eine Authentifizierungsprüfung geschützt. Vor Änderungen an Auth- oder Admin-Funktionen die Controller in `src/Web/Controller/` und die zugehörigen Frontend-Dateien gemeinsam prüfen.

---

## 7. Datenbank und wichtige Tabellen

Die Anwendung verwendet MariaDB/MySQL über PDO. Die Migrationen liegen in `database/migrations/`; der MigrationRunner protokolliert angewendete Migrationen in der Tabelle `migrations`.

Wichtige Tabellen, die bei der Bestandsaufnahme vorhanden waren:

| Tabelle | Zweck / Beobachtung |
|---|---|
| `releases` | Release-Datensätze |
| `release_groups` | Release-Gruppen |
| `release_events` | Historie/Ereignisse; ca. 102.543 Datensätze |
| `irc_networks` | IRC-Netzwerke; 2 Datensätze |
| `irc_outbox` | Ausgehende IRC-Nachrichten; ca. 101.663 Datensätze |
| `external_sources` | Externe Quellen; 6 Datensätze |
| `admin_users` | Admin-Konten; 2 Datensätze |
| `nuke_history` | Nuke-Historie; ca. 82.829 Datensätze |
| `migrations` | 4 angewendete Migrationen |

Die Zahlen sind Momentaufnahmen und ändern sich im laufenden Betrieb.

---

## 8. Bekannte Baustellen aus dem Audit

Diese Punkte wurden beim Audit gefunden. Der jeweilige Status steht direkt am Punkt; verbleibende Risiken sind ausdrücklich markiert.

### Kritisch / zuerst angehen

1. **GitHub-Zugriffstoken in der Remote-URL:** Im Git-Remote des aktiven Repositories ist ein Zugriffstoken eingebettet. Token zeitnah bei GitHub widerrufen/rotieren. Anschließend Remote auf eine sichere Authentifizierung umstellen. Den alten Token nicht erneut ausgeben oder in Befehle/Logs kopieren.
2. **Dateirechte der `.env`:** Die Datei hatte Modus `0644` und war damit für lokale Benutzer lesbar. Da Eigentümer und Gruppe `www-data` sind, sollte nach Prüfung der Zugriffsanforderungen mindestens auf `0640` eingeschränkt werden.
3. **IRC-Outbox-Rückstand:** Der alte Rückstand wird bewusst nicht automatisch nachgesendet. Die Outbox-Verarbeitung wurde am 01.11.2026 so begrenzt, dass nur neue `pending`-Einträge der jeweiligen Netzwerk-ID aus den letzten 10 Minuten verarbeitet werden. Alte Einträge bleiben zur manuellen Prüfung erhalten. Bei der letzten Messung waren weiterhin 4.279 alte Einträge `pending`; der aktuelle Zähler ist im Admin-Reiter „Logs & Statistiken“ sichtbar.
4. **Netzwerk-ID 4 – Channel-Trennzeichen:** Behoben am 01.11.2026. `bin/fortknox-irc` trennt Channels nun an Kommas und Zeilenumbrüchen. Nach dem Neustart wurden `#pre`, `#pre.search` und `#Pre.Spam` korrekt gejoint.

### Fehlerhafte Hilfsprogramme / Code-Konsistenz

5. **`bin/fortknox-db-test`:** Ruft `Connection::ping()` auf, diese Methode existiert in `src/Database/Connection.php` nicht. Der Test bricht deshalb mit einem Fatal Error ab. Die direkte PDO-Verbindung zur Datenbank war beim Audit hingegen erfolgreich.
6. **`bin/fortknox-import`:** Übergibt die Argumente an den Konstruktor von `ImportService` in falscher Reihenfolge bzw. mit falschen Typen. Vor einer Verwendung korrigieren und separat testen.
7. **`bin/fortknox-irc-test`:** Verwendet ebenfalls einen nicht passenden `ImportService`-Konstruktoraufruf. Nicht als verlässlichen Integrationstest betrachten.
8. **`bin/fortknox-irc`:** Behoben am 01.11.2026. `enable_announce` wird jetzt aus dem tatsächlich geladenen `$network`-Datensatz gelesen.
9. **Wartungs-/Update-Skripte:** `bin/fortknox-maintenance.sh` und `bin/fortknox-update.sh` referenzieren `/root/FortKnox-PreDB` statt des aktiven Pfads. Das Update-Skript erwartet zusätzlich eine `APP_VERSION`-Datei, die im aktiven Projektverzeichnis nicht vorhanden war. Diese Skripte nicht unverändert starten.
10. **Backup-Restore:** Die Backup-Erstellung wurde am 01.11.2026 mit Konfigurations- und vollständigen Dumps geprüft (gzip-Integrität erfolgreich). Ein Restore-Test in einer separaten Testdatenbank steht weiterhin aus.

### Repository-Aufräumen

Im Git-Index liegen auffällige Dateien wie `[DB_CHARSET]`, `f:`, `ponse_code(401);` und ein Python-Bytecode unter `__pycache__/`. Das wirkt wie versehentlich eingecheckte Artefakte. Vor dem Entfernen erst Herkunft und Verwendung prüfen.

---

## 9. Testergebnisse der Bestandsaufnahme

| Prüfung | Ergebnis |
|---|---|
| PHP-Syntax in `src/`, `public/` und PHP-CLI-Dateien | bestanden |
| IRC-Protokollparser-Test | bestanden |
| IRC-Filter-Beispieltest | bestanden |
| MonitorController-Statistikabfrage als `www-data` | bestanden |
| MonitorController-Logzugriff als `www-data` | bestanden; beide IRC-Logdateien lesbar |
| Monitor-API ohne Anmeldung | HTTP 401 (erwartet) |
| Adminseite nach Erweiterung | HTTP 200 |
| systemd-IRC-Services nach Logumstellung | beide aktiv |
| MariaDB-Verbindung über direkten PDO-Aufruf | erfolgreich |
| `bin/fortknox-db-test` | fehlgeschlagen: Methode `ping()` fehlt |
| IRC systemd-Service 1 | aktiv |
| IRC systemd-Service 4 | aktiv |
| Apache | aktiv |
| MariaDB | aktiv |

Es wurde kein produktiver Import-Test ausgeführt, da dieser die Datenbank verändern würde. Auch ein vollständiger Browser-/API-Funktionstest und ein Backup-Restore-Test sind noch offen.

---

## 10. Empfohlene Reihenfolge für die nächsten Arbeiten

1. GitHub-Token widerrufen/ersetzen und Remote-Zugang absichern.
2. `.env`-Dateirechte korrigieren.
3. Alte IRC-Outbox-Einträge (4.279 `pending` beim letzten Stand) fachlich prüfen und entscheiden, ob archivieren/markieren; nicht blind erneut senden.
5. Falsche Konstruktoraufrufe in Import-CLI und IRC-Test korrigieren.
6. `fortknox-db-test` reparieren.
7. Update-/Maintenance-Skripte auf den aktiven Pfad umstellen und gegen den echten Serverablauf testen.
8. Backup-Restore in einer separaten Testdatenbank verifizieren.

---

## 11. Adminbereich: Logs & Statistiken

Der Adminbereich enthält seit 01.11.2026 den zusätzlichen Reiter **„Logs & Statistiken“**.

### Statistiken

Die geschützte API `GET /api/admin/monitor/stats` liefert:

- Releases gesamt, heute und in den letzten sieben Tagen
- Datenbankgröße
- Outbox-Zähler nach `pending`, `sent` und `failed`
- aktive/gesamte externe Quellen
- Events der letzten 24 Stunden
- Status aller konfigurierten IRC-Netzwerk-Services
- tägliche Release-Anzahl für die letzten sieben Tage

Die Werte werden live aus MariaDB bzw. über `systemctl is-active` gelesen. Sie sind Momentaufnahmen, keine historische Metrik-Datenbank.

### IRC-Logs

Die geschützte API `GET /api/admin/monitor/logs` liest die Logdateien für die in `irc_networks` vorhandenen Netzwerk-IDs. Unterstützte Parameter:

- `network_id=all` oder eine existierende numerische Netzwerk-ID
- `lines=50|100|200|500` (serverseitig auf maximal 500 begrenzt)

Die Dateien befinden sich unter `storage/logs/irc-<ID>.log`. Neue Einträge werden ab dem Neustart nach der Umstellung vom 01.11.2026 gesammelt; historische Journal-Einträge werden nicht rückwirkend importiert. Die Ausgabe maskiert NickServ-`IDENTIFY`-/`RECOVER`- und IRC-`PASS`-Payloads zusätzlich serverseitig. Trotzdem Logs nur im authentifizierten Adminbereich anzeigen und keine vollständigen Logdateien öffentlich teilen.

Logrotation ist über `/etc/logrotate.d/fortknox-irc` eingerichtet: täglich, 14 Rotationen, komprimierte ältere Dateien, `copytruncate`.

### Manuelle Diagnose

```bash
cd /var/www/FortKnox-PreDB
systemctl is-active fortknox-irc@1.service fortknox-irc@4.service
tail -n 100 storage/logs/irc-1.log
tail -n 100 storage/logs/irc-4.log
logrotate -d /etc/logrotate.d/fortknox-irc
```

Die Logdateien werden vom Bot-Prozess als root geschrieben, sind aber durch Gruppe `www-data` für den PHP-Admin-Viewer lesbar. Verzeichnis-/Dateirechte nicht lockern.

---

## 12. Änderungsprotokoll – 01.11.2026

Alle nachfolgend aufgeführten Änderungen wurden im produktiven Checkout `/var/www/FortKnox-PreDB` auf dem X4All-Server vorgenommen. Der Mirror wurde nicht angefasst. Änderungen sind derzeit **nicht committed und nicht gepusht**.

### IRC-Bots und Outbox

- `src/IRC/IrcSession.php`: IRC-Registrierung wird nach numerischer 001 als abgeschlossen markiert; Outbox-Verarbeitung wartet bis dahin. Verarbeitung ist auf das eigene Netzwerk und höchstens zehn Minuten alte `pending`-Einträge begrenzt. PDO-Fehler werden ins Fehlerprotokoll geschrieben statt verschluckt.
- `bin/fortknox-irc`: Channel-Aufteilung akzeptiert Kommas und Zeilenumbrüche; `enable_announce` wird korrekt aus `$network` gelesen.
- `src/IRC/IrcClient.php`: ausgehende NickServ-`IDENTIFY`- und `RECOVER`-Kommandos sowie `PASS` werden in der Protokollausgabe maskiert.
- Beide Services `fortknox-irc@1.service` und `fortknox-irc@4.service` wurden neu gestartet und anschließend als `active` geprüft. Netzwerk 4 trat wieder allen drei konfigurierten Channels bei.
- Alte Outbox-Einträge werden nicht gelöscht. `sent` bedeutet weiterhin, dass die Nachricht an den Socket geschrieben wurde, nicht dass der IRC-Server die Zustellung bestätigt hat.

### Adminbereich, Anmeldung und Backups

- `public/admin.html`: fehlerhafte Tab-/Panel-Verschachtelung korrigiert; IRC-Bot-Panel enthält wieder Bot-Tabelle und Live-Monitor. Benutzer-, Backup- und weitere Panels werden unter `#adminView` normalisiert. Backup-Aktionen und Meldungen ergänzt; Backup-Beschriftungen an den tatsächlichen Funktionsumfang angepasst.
- `public/index.php`: Backup-Routen an die realen Controller-Methoden angepasst; Benutzerverwaltungsrouten ergänzt; Monitor-Routen geschützt registriert.
- `src/Web/Controller/AuthController.php`: Login unterstützt aktive Einträge aus `admin_users`, aktualisiert `last_login`; Dashboard findet Backups unter `storage/backups`.
- `src/Web/Controller/BackupController.php`: DB-Konfiguration aus den tatsächlichen `.env`-Schlüsseln normalisiert; temporäre Client-Credentials mit Modus 0600 statt Passwort in der Prozessargumentliste; Dumps werden geprüft und atomar finalisiert; Dateitypen und Dateioperationen validiert.
- Test-Backups wurden erstellt und per `gzip -t` geprüft. Die Backup-UI bietet weiterhin keine Restore-Funktion.

### Logs & Statistiken

- Neu: `src/Web/Controller/MonitorController.php` mit authentifizierten, read-only Endpunkten für Betriebsstatistiken, IRC-Logzeilen und letzte Release-Events.
- Neu: Admin-Tab „Logs & Statistiken“ in `public/admin.html` mit Bot-Status, Release-Zählern, Datenbankgröße, Outbox-Status, Quellen, Release-Aktivität, Logfilter und Eventliste.
- `/etc/systemd/system/fortknox-irc@.service`: stdout/stderr werden zusätzlich in `storage/logs/irc-%i.log` geschrieben; bestehende Journal-Historie wurde nicht gelöscht.
- Neu: `/etc/logrotate.d/fortknox-irc` mit täglicher Rotation und 14 Generationen.
- Logverzeichnis und Dateien sind nur für root und die notwendige Lesegruppe `www-data` zugänglich (0750/0640). Keine Zugangsdaten in Logs ausgeben; serverseitige Maskierung ist aktiv.

### Dokumentation und Backups

- Dieses `docs/HOWTO.md` wurde mit Betriebsanleitung, API-Übersicht, Logzugriff, Sicherheitsregeln, Testergebnissen und Änderungsprotokoll ergänzt.
- Sicherungskopien der bearbeiteten Dateien liegen unter `storage/backups/` in den datierten Verzeichnissen `irc-repair-20261101`, `admin-repair-20261101-034239`, `admin-tabs-fix-20261101-*` und `monitor-feature-20261101-*`. Die systemd-Vorlage wurde zusätzlich unter `/etc/systemd/system/fortknox-irc@.service.bak-20261101-monitor` gesichert.
- Vor einem Commit zuerst `git status`, `git diff --check` und alle Tests erneut prüfen. Keine Zugangsdaten oder `.env`-Dateien committen.

---

## 13. Sicherheits- und Betriebsregeln

- Immer in `/var/www/FortKnox-PreDB` arbeiten – nicht im alten `/opt`-Checkout.
- Vor Datenbankmigrationen, Importen oder größeren Änderungen ein frisches Backup erstellen.
- Keine Zugangsdaten aus `.env` ausgeben oder in Git einchecken.
- IRC-Bots nicht unnötig neu starten; Status und Logs zuerst prüfen.
- Keine alten Update-/Maintenance-Skripte starten, bevor deren Pfade korrigiert wurden.
- Änderungen einzeln durchführen und danach Syntax, Logs und betroffene Funktion prüfen.

### FortKnox Control Center – Dark Neon Theme

- `public/admin.html`: neues, zentral gekapseltes CSS-Theme `fortknox-neon-theme` ergänzt.
- Designrichtung: Dark Neon mit Anthrazit-/Navy-Flächen, Türkis- und Cyan-Akzenten, dezenten Glow-Effekten sowie klaren Statusfarben.
- Balanced Layout: größere, gut lesbare Statuskarten, flexibles Dashboard-Raster, horizontale scrollbarere Tab-Leiste und kompaktere Tabellen.
- Responsive Anpassungen für schmale Displays; Formulare, Login, Buttons, Tabellen und Logausgabe an das Theme angepasst.
- Tastaturfokus (`:focus-visible`) und `prefers-reduced-motion` berücksichtigt.
- Keine API-, Authentifizierungs-, Datenbank-, IRC- oder Backup-Logik geändert.
- Vorherige Version gesichert unter `storage/backups/control-center-neon-20261101/admin.html.before` und `HOWTO.md.before`.
- Theme-Änderung wurde im produktiven X4All-Checkout vorgenommen; noch nicht committed oder gepusht.


## FortKnox Control Center – Markenauftritt

- Seit 2026-11-01 trägt die Admin-Oberfläche den Seitentitel `FortKnox Control Center`.
- Der Kopfbereich verwendet ein inline eingebettetes, skalierbares SVG-Schutzschild mit Festungszinnen und Schlüsselloch sowie den zweifarbigen FortKnox-Schriftzug (Silber/Cyan und Neon-Grün).
- Die Gestaltung greift den Dark-Neon-Stil des Control Centers auf und bleibt ohne externe Bild- oder Font-Abhängigkeiten funktionsfähig.
- Der Markenblock enthält den Untertitel `CONTROL CENTER · MONITOR · MANAGE · SECURE` und den Link zum Live-Dashboard.
- Ergänzende CSS-Regeln sind in `public/admin.html` unter `#fortknox-brand-lockup` gekapselt; API-, Authentifizierungs- und Datenbanklogik wurden nicht verändert.
- Vor der Änderung wurde eine Sicherung unter `storage/backups/control-center-branding-20261101-045459/admin.html.before` erstellt.
- Validierung: 7 Inline-JavaScript-Blöcke mit `node --check` erfolgreich, HTML-Kopf-/Body-Struktur geprüft, `git diff --check` erfolgreich, Admin-Seite auf `fk-predb.com` mit HTTP 200 erreichbar.


## FortKnox Control Center – Originalgrafik eingebunden (2026-11-01)

- Die vom Betreiber bereitgestellte Originalgrafik liegt unverändert unter `public/assets/fortknox-original.png` (PNG, 1672 × 941 Pixel).
- `public/admin.html` verwendet die Originalgrafik sowohl im Login-Bereich als auch im Kopfbereich der angemeldeten Admin-Oberfläche.
- Die Grafik wird proportional skaliert (`height:auto`, `object-fit:contain`) und über responsive CSS-Breiten für Desktop und schmale Displays angepasst. Es werden keine Schriftarten, Effekte oder Bildbestandteile nachgebaut oder vereinfacht.
- Die bisherige SVG-Ersatzmarke und der HTML-Schriftzug wurden aus dem Markenblock entfernt.
- Bilddatei: `public/assets/fortknox-original.png`; Einbindung relativ zur Webroot unter `/assets/fortknox-original.png`.
- Vor der HTML-Änderung gesicherte Datei: `storage/backups/control-center-branding-20261101-original-logo/admin.html.before`.
- Keine API-, Authentifizierungs-, Datenbank-, IRC- oder Backup-Logik geändert. Kein Commit, Push oder Deployment ausgeführt.


### Logo-Integration optisch abgestimmt (2026-11-01)

- Nach Sichtprüfung wurde die Einbindung der Originalgrafik verfeinert: kleinere, zur Header-Hierarchie passende Maximalbreite, proportionale Darstellung und eine dezente radiale Maskierung an den Außenkanten.
- Die Maskierung blendet nur den dunklen rechteckigen Bildrand weich in das Dark-Neon-Layout ein; das Originalbild selbst wird weder beschnitten noch neu gerendert oder vereinfacht.
- Login- und Admin-Header verwenden abgestimmte responsive Breiten; der Dashboard-Link sitzt enger am Markenblock.
- Sicherung vor dieser Anpassung: `storage/backups/control-center-logo-blend-20261101-140955/admin.html.before`.



## FortKnox Release Dashboard – Metallic Neon Design (2026-11-01)

- `public/dashboard.html` erhält eine eigenständige Metallic-Neon-Gestaltung passend zur Markenrichtung „Release Intelligence & Control Center“.
- Visuelle Elemente: tiefdunkle Navy-/Graphitflächen, dezente technische Rasterstruktur, metallisch wirkende Überschriften, Cyan-/Türkislinien und sparsame Neongrün-Akzente.
- Statistik-Karten, Release-Suche, Tabellen, Kategorien, Live-Feed, Statusanzeigen und Admin-Link wurden optisch vereinheitlicht.
- Die sichtbare Positionierung lautet jetzt „FortKnox – Release Intelligence & Control Center“. FTP-Themen werden nicht dargestellt.
- Ausschließlich CSS und der statische Marken-/Untertiteltext wurden geändert. API-Aufrufe, JavaScript, Release-Suche, Filter, Pagination, Live-Polling und Datenbankzugriffe bleiben unverändert.
- Vorherige Dateien: `storage/backups/release-dashboard-theme-20261101-153137/dashboard.html.before` und `HOWTO.md.before`.
- Änderungen sind lokal im produktiven Checkout vorbereitet, nicht committed, nicht gepusht und nicht separat ausgerollt.



### Ergänzende Release-Seiten im Metallic-Neon-Design (2026-11-01)

- `public/release.html` (Release-Detailansicht) und `public/presite.html` (Public Release Feed) wurden an dieselbe dunkle Metallic-/Neon-Designsprache angepasst.
- Gemeinsame Farb- und Oberflächenregeln für Header, Karten, Metadatenfelder, Event-Historie, Tabellen, Suchfelder und Navigation.
- Markenbezeichnung und Seitentitel auf „FortKnox – Release Intelligence“ bzw. „Release Intelligence & Control Center“ vereinheitlicht; FTP wird nicht erwähnt.
- Die Navigationsbeschriftung im Public Feed lautet „Live Dashboard“.
- Nur CSS und sichtbare statische Beschriftungen geändert. API-Endpunkte, Release-Laden, Suche, Pagination und Nuke-/UnNuke-Aktionen bleiben unverändert.
- Vorherige Dateien: `storage/backups/release-pages-theme-20261101-1533/` (`release.html.before` und `presite.html.before`).


### Veröffentlichungszeit in der Releases-Tabelle (2026-11-01)

- `public/dashboard.html` ergänzt die Tabelle „Releases“ um die Spalte „Veröffentlicht“ direkt neben dem Release-Namen.
- Angezeigt wird `created_at` mit derselben `formatReleaseTime()`-Formatierung wie im Livefeed. Damit ist der Zeitpunkt des Eingangs/der Veröffentlichung auf FortKnox in beiden Bereichen identisch.
- Leere- und Fehlerzustände der Tabelle verwenden passend zur zusätzlichen Spalte `colspan="7".
- API, Datenbank, Sortierung, Filter und Pagination wurden nicht verändert.
- Backup vor der Änderung: `storage/backups/releases-published-column-20261101-154417/dashboard.html.before` und `HOWTO.md.before`.
- Kein Commit, Push oder separates Deployment ausgeführt.


### Tagesstatistik eindeutiger Besucher (2026-11-01)

- Öffentliche Seiten `dashboard.html`, `release.html` und `presite.html` melden beim Laden einen anonymisierten Besuch an `POST /api/visits/track`.
- Der Endpunkt speichert je Kalendertag und Besucherkennung höchstens einen Datensatz. Die Kennung ist ein HMAC aus Remote-IP und User-Agent mit einem täglich abgeleiteten Schlüssel. Die Klartext-IP und der User-Agent werden nicht gespeichert; es gibt kein Tracking-Cookie.
- Der geheime HMAC-Schlüssel liegt außerhalb des Webroots in `storage/visitor-hmac.key` und wird bei der ersten Erfassung mit 0600 angelegt. Die Kennungen werden täglich gewechselt und Datensätze älter als 35 Tage bei Gelegenheit gelöscht.
- Tabelle: `daily_visitors` (Migration `005_create_daily_visitors.sql`). Die Admin-Statistik zeigt „Besucher heute“ und Tageswerte der letzten 30 Tage. Es handelt sich um eine datensparsame Näherung eindeutiger Browser/Netzwerk-Kombinationen, nicht um verifizierte natürliche Personen.
- Vor Änderungen angelegte Dateisicherungen: `storage/backups/daily-visitors-20261101-155048/`. Datenbank-Backup wurde vor der Migration mit `bin/fortknox-backup` erstellt.
- Datenschutzhinweis und Rechtsgrundlage für die pseudonyme Verarbeitung müssen vor öffentlicher Nutzung in der Datenschutzerklärung ergänzt bzw. geprüft werden.

### IRC-Outbox: automatische PDO-Wiederverbindung (2026-11-01)

- Ursache der wiederholten `SQLSTATE[HY000] 2006`-Meldungen: Der langlebige IRC-Prozess hielt in `IrcSession::processOutbox()` eine statische PDO-Verbindung. Nach einem MariaDB-Neustart blieb dieses Handle ungültig.
- Bei einem Outbox-Datenbankfehler wird das PDO-Handle jetzt verworfen (`$pdo = null`). Beim nächsten Outbox-Poll wird automatisch eine neue Verbindung aufgebaut.
- Wiederholte Fehlermeldungen werden während eines Ausfalls auf maximal eine Meldung je 30 Sekunden begrenzt. IRC-Verbindung und Outbox-Datensätze werden nicht pauschal zurückgesetzt oder gelöscht.
- Sicherung vor Änderung: `storage/backups/irc-db-reconnect-20261101-162329/IrcSession.php.before` und `HOWTO.md.before`.
- PHP-Syntaxprüfung für `src/IRC/IrcSession.php` erfolgreich. Der IRC-Dienst konnte über die verfügbare Remote-Steuerung nicht neu gestartet werden; die Änderung wird erst nach einem Dienstneustart aktiv.
- MariaDB wurde laut systemd heute um 03:08 und 11:34 Uhr jeweils durch Signal 9 beendet. Im abgefragten Kernel-Journal fanden sich keine passenden OOM-Killer-Einträge. Der InnoDB-Buffer-Pool ist mit 5 GiB konfiguriert; der MariaDB-Prozess belegte bei der Nachmessung rund 5,4 GiB RSS. Die externe/harte Beendigung ist damit noch nicht erklärt.

### Release-Suche: FULLTEXT statt Tabellen-Vollscan (2026-11-01)

- Ausgangsbefund: `releases` enthält rund 25,6 Mio. Datensätze. Die bisherige Freitextsuche verwendete sechs `LIKE '%...%'`-Bedingungen mit `OR`; `EXPLAIN` zeigte dafür `type=ALL` und einen Scan über rund 25,6 Mio. Zeilen trotz vorhandener Indizes.
- In der produktiven Tabelle existiert bereits `ft_releases_search` auf `name,title`. Die Suche nutzt diesen FULLTEXT-Index für Begriffe ab drei Zeichen. Kurze Begriffe verwenden Präfixsuche (`term%`) auf Name/Titel. Exakte Kategorie-, Sprach- und Codec-Treffer werden über separate `UNION`-Zweige und deren Indizes ermittelt.
- Die Such-ID-Menge wird vor dem Join auf `releases` gebildet (`STRAIGHT_JOIN`), damit MariaDB die FULLTEXT-/B-Tree-Zweige zuerst auswertet. `EXPLAIN` bestätigt `type=fulltext` für Namen/Titel sowie `ref`-Zugriffe für Kategorie, Sprache und Codec, statt des ursprünglichen Vollscans.
- Hinweis: Die Freitextsuche über das bislang nicht indexierte Feld `source` ist in dieser ersten Optimierungsstufe nicht mehr enthalten. Ein zusätzlicher Index auf 25,6 Mio. Datensätzen wurde bewusst nicht im laufenden Betrieb aufgebaut.
- Messung mit dem sehr breiten Suchbegriff `1080p`: 1.172.538 Treffer. Die API antwortete mit HTTP 200 und 25 Ergebnissen in rund 15,4 Sekunden. Das ist ein klarer technischer Fortschritt gegenüber dem ursprünglichen Vollscan-Plan, aber für einen häufigen Begriff noch zu langsam; weitere Optimierung von Trefferzählung und Sortierung bleibt erforderlich.
- Backup vor Änderung: `storage/backups/search-performance-20261101-1645/ReleaseController.php.before` und `HOWTO.md.before`.
- PHP-Lint und `git diff --check` erfolgreich. Keine Datenbankstruktur geändert; kein Dienstneustart erforderlich, da die Webanwendung die PHP-Datei bei Requests lädt.

### IRC FiSH 10 / Blowfish (2026-11-01)

- FiSH 10-kompatible Blowfish-Verschlüsselung ist in `src/IRC/FiSH.php` implementiert: klassischer ECB-Modus mit FiSH-Base64-Alphabet (`+OK `) sowie Mircryption-kompatibler CBC-Modus (`+OK *`) mit zufälligem 8-Byte-IV, vorangestellt im Klartextstrom. CBC-Schlüssel werden wie in der Referenz auf maximal 56 Byte begrenzt; ECB-Schlüssel über 56 Byte werden abgewiesen.
- Blowfish wird über `phpseclib/phpseclib` bereitgestellt, da PHPs OpenSSL-3-Bindung auf diesem Host die Legacy-Blowfish-Cipher nicht anbietet. Die Abbildung der ECB-Blöcke wurde gegen `BF_encrypt` aus OpenSSL geprüft; ECB/CBC-Roundtrip-Tests laufen unter `tests/IRC/fish-test.php`.
- Channel-Schlüssel liegen getrennt nach Netzwerk und Channel in `irc_channel_keys` (Migration `006_create_irc_channel_keys.sql`). Der Datenbankwert ist AES-256-GCM-verschlüsselt; der 32-Byte-Masterschlüssel liegt separat in `storage/irc-fish.key` (0600). Ohne gespeicherten Schlüssel bleibt der jeweilige Channel unverändert unverschlüsselt.
- Schlüssel verwalten: `bin/fortknox-irc-key --network-id=1 --channel=#predb` (Eingabe verdeckt), `--list` zum Anzeigen vorhandener Channel-Einträge, `--remove` zum Löschen. Der Klartextschlüssel wird nicht in der Datenbank gespeichert oder über die Admin-API ausgegeben.
- Der IRC-Prozess lädt Schlüssel beim Start. Nach einer Schlüsseländerung ist deshalb ein Neustart des betroffenen `fortknox-irc@<network-id>.service` erforderlich. Ein solcher Neustart wurde im Rahmen dieser Implementierung nicht ausgeführt.
- Vor Migration 006 wurde ein vollständiges Datenbank-Backup erstellt: `storage/backups/db_fortknox_2026-11-01_20-37-30.sql.gz` (ca. 1,06 GB komprimiert). Die Migration wurde anschließend erfolgreich angewendet.
- Live-Interoperabilität mit einem mIRC-FiSH-10-Client muss noch im freigegebenen Channel `#predb` geprüft werden. Bis zu diesem Test wurde kein Channel-Schlüssel produktiv hinterlegt und keine laufende IRC-Instanz verändert.


### FiSH 10 DH1080 CBC Key Exchange (2026-11-01)

- src/IRC/FiSHDh1080.php implements the exact FiSH 10 DH1080 prime, 135-byte public/private key representation, DH shared-secret calculation, SHA-256 hashing and FiSH Base64 encoding. The prime and derivation were checked against the official FiSH 10 implementation.
- IRC NOTICE parsing now recognizes DH1080_INIT / DH1080_FINISH message transport without passing NOTICE payloads into the PreDB release parser.
- For network ID 1, an approved mIRC peer can initiate NOTICE Pre-DB :DH1080_INIT <public-key> CBC. FortKnox replies with DH1080_FINISH <public-key> CBC, stores the derived cbc: key encrypted through the existing AES-256-GCM key vault, and activates it in the running session for #predb.
- To prevent arbitrary IRC users from replacing the channel key, the sender must exactly match IRC_FISH_PEER_NICK in .env. Until this is configured, DH1080 requests are deliberately ignored. The setting is a nickname, not a password or encryption key.
- The key is stored for network 1 / #predb only. The key is not written to logs. Other networks and channels are not affected.
- Tests: tests/IRC/dh1080-test.php verifies two independently generated peers derive the same shared secret; bin/fortknox-irc-parser-test checks NOTICE parsing; tests/IRC/fish-test.php checks local ECB/CBC round trips. PHP lint and git diff --check passed after the implementation.
- Live-Diagnose am 01.11.2026: mIRC sendete DH1080_INIT-Public-Keys mit 181 Base64-Zeichen. Die Handler-Regex in IrcSession.php akzeptierte fälschlich nur 180 Zeichen; dadurch wurde jede Anfrage still verworfen und kein DH1080_FINISH gesendet. Die FiSH-DH1080-Kodierung hängt bei 135-Byte-Werten ohne Base64-Padding ein abschließendes `A` an; der Decoder entfernt diesen Marker vor dem Dekodieren.
- Korrektur am 01.11.2026: Der Handler akzeptiert nun exakt 180 Base64-Zeichen plus abschließendes `A`. Der DH1080-Test prüft zusätzlich Länge und Format des erzeugten Public Keys. Vorherige Dateien liegen unter `storage/backups/fish10-dh1080-regex-20261101/`.
- Nach der Korrektur sind PHP-Lint, DH1080-Shared-Secret-Test, FiSH-Tests, IRC-Parser-Test und `git diff --check` auszuführen. Ein Live-Austausch ist damit noch nicht bestätigt; dafür ist ein kontrollierter Test in #predb und ein genehmigter Neustart von fortknox-irc@1.service erforderlich.

### Adminpanel: FiSH-10-Status (2026-11-01)

- Im Adminbereich „Logs & Statistiken“ zeigt ein eigener FiSH-10/DH1080-Block die hinterlegten Channel-Schlüssel pro IRC-Netzwerk, CBC-Modus, Aktualisierungszeitpunkt sowie den letzten im Netzwerk-1-Log gefundenen DH1080-Austausch und FiSH-Fehler.
- Die Admin-API liest ausschließlich `network_id`, `channel` und `updated_at` aus `irc_channel_keys`. Das Feld `encrypted_key`, der Vault-Masterschlüssel und Klartextschlüssel werden weder ausgewählt noch an den Browser ausgegeben.
- Die Anzeige „Schlüssel hinterlegt“ bestätigt nur den Datenbankeintrag; sie ist kein Live-Nachweis für eine aktuell funktionierende Peer-Verbindung. Die Betriebs- und IRC-Logs bleiben maßgeblich für die Laufzeitdiagnose.
- Änderungen am Adminpanel benötigen keinen IRC-Dienstneustart. Es wurden keine Dienste neu gestartet und keine Deploy-/Git-Aktionen ausgeführt.
- Backup vor Änderung: `storage/backups/admin-fish-panel-20261101-232010/`.


## Adminpanel: FiSH-Channel-Schlüssel verwalten (2026-11-01)

Im Bereich „Logs & Statistiken“ kann für Netzwerk 1 (FortKnox Main) ein FiSH-Schlüssel manuell gesetzt oder rotiert werden. Der Admin-Endpunkt `POST /api/admin/monitor/fish-key` ist durch die bestehende Admin-Session geschützt. Er validiert Netzwerk, Channel, Modus und Schlüssellänge (1–56 Bytes), speichert den Schlüssel über `FiSHKeyStore` und `FiSHKeyVault` AES-256-GCM-verschlüsselt in `irc_channel_keys`. Der Klartextschlüssel wird weder zurückgegeben noch in Statusdaten ausgegeben; das Eingabefeld wird nach erfolgreichem Speichern geleert.

CBC ist Standard; ECB steht nur für Legacy-Kompatibilität zur Verfügung. Manuelle Änderungen werden vom laufenden IRC-Prozess nicht live eingelesen: Netzwerk 1 muss nach dem Speichern separat neu gestartet werden. Ein DH1080-Austausch speichert weiterhin automatisch über den bestehenden FiSHKeyStore und aktualisiert den laufenden Prozess.

## FiSH-Schlüssel im IRC-Bot-Formular (2026-11-02)

Die manuelle FiSH-Schlüsselverwaltung befindet sich im Formular „IRC Bots & Netzwerke → Bot konfigurieren“. Der Admin-Endpunkt akzeptiert eine vorhandene Netzwerk-ID, speichert den Schlüssel im FiSH-Vault und ergänzt den angegebenen Channel automatisch in `irc_networks.channels`, falls er dort noch nicht vorkommt. Damit werden Schlüsselzuweisung und Bot-JOIN-Konfiguration gemeinsam vorbereitet. Nach dem Speichern muss der jeweilige `fortknox-irc@<network-id>.service` separat neu gestartet werden; dies geschieht nicht automatisch. DH1080 bleibt aktuell auf Netzwerk 1 beschränkt.

### FiSH-The-Lounge-Test: Schlüsselauflösung zur Laufzeit (2026-11-02)

In der isolierten FiSH-Testinstanz werden persistierte Netzwerk-Schlüssel jetzt zusätzlich direkt beim Empfang und Versand aufgelöst, falls ein Channel-Objekt noch keinen `blowfishKey`-Wert besitzt. Die Auflösung verwendet `resolveBlowKeyFor(channel)` und `resolveBlowModeFor(channel)`; der eingehende PRIVMSG-Pfad entschlüsselt damit anhand des gespeicherten Channel-Schlüssels. Die ausgehenden PRIVMSG-, NOTICE- und ACTION-Pfade verwenden denselben Fallback. DH1080-Nick-Schlüssel bleiben getrennte Einträge und werden nicht automatisch als Channel-Schlüssel missbraucht. Änderungen betreffen ausschließlich die parallele Testinstanz unter `/opt/fortknox-fish-lounge/staging/thelounge`; Produktions-The-Lounge auf Port 9000 wurde nicht verändert. Vor Inbetriebnahme ist ein kontrollierter CBC-Interoperabilitätstest erforderlich.
