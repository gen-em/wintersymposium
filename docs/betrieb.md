---
dokument: betrieb
projekt: wintersymposium
stand: 2026-10-29
---

# Betrieb: Einrichtung, Auslieferung, Wartung

Diese Anleitung enthält keine Zugangsdaten, Kontonamen oder Hosting-Interna (R-10). Die konkreten Werte stehen in der `config.php` auf dem Webspace und in den GitHub-Secrets.

## 1. Aufbau

```text
server/                 → wird in den Zielordner der Umgebung übertragen
  public/               → Webroot (die Domain zeigt hierher, E-34)
    index.php           → einziger Einstieg, leitet alle Seiten
    assets/             → CSS, später Schriften, Bilder, Skripte
  lib/                  → Bibliotheken (über HTTP nicht erreichbar)
  seiten/               → Seiten (über HTTP nicht erreichbar)
  cli/jobs.php          → Job-Einstieg für den Cron auf der Kommandozeile
  config.example.php    → Vorlage; die echte config.php liegt nur auf dem Webspace
  var/                  → Laufzeitordner, legt die App selbst an (Sitzungen,
                          install.lock, wartung.lock, Logs); wird nie ausgeliefert
tools/tests/            → Testlauf (lokal und im Prüf-Workflow)
```

Zielordner auf dem Webspace (E-32):

| Umgebung | Ordner im Webspace | Pfad vom FTP-Login aus | Domain zeigt auf |
|---|---|---|---|
| Staging | `/wintersymposium/staging` | `/staging` | `/wintersymposium/staging/public` (`test.winter-notfallsymposium.de`) |
| Produktion | `/wintersymposium/produktiv` | `/produktiv` | `/wintersymposium/produktiv/public` (`winter-notfallsymposium.de`) |

Der FTP-Zugang der Auslieferung landet direkt im Ordner `wintersymposium` und sieht dort `staging` und `produktiv`; der Workflow verwendet deshalb `/staging/` und `/produktiv/`.

## 2. Ersteinrichtung je Umgebung

Erst Staging einrichten und prüfen, dann Produktion genauso.

1. **Domain-Zuordnung:** im Lima-City-Konto die Subdomain `test.winter-notfallsymposium.de` auf den Ordner `/wintersymposium/staging/public` legen (Produktion: Hauptdomain auf `/wintersymposium/produktiv/public`). HTTPS-Zertifikat für die Domain aktivieren.
2. **Datenbank:** eine MySQL-Datenbank mit eigenem Benutzer anlegen (je Umgebung eine eigene).
3. **Erste Auslieferung:** Sobald der PR in `main` gemergt ist, überträgt GitHub Actions den Code automatisch nach Staging (Abschnitt 3). Für Produktion: Tag setzen (Abschnitt 3).
4. **config.php anlegen:** `config.example.php` lokal kopieren, als `config.php` ausfüllen und per FTP in den Zielordner legen (neben `config.example.php`, **nicht** in `public/`):
   - `umgebung`: `staging` bzw. `produktion`
   - `db`: Zugangsdaten aus Schritt 2
   - `geheimnis`, `installations_schluessel`, `jobs_token`, `health_token`: je eine zufällige Zeichenkette mit mindestens 32 Zeichen (Passwortmanager, nur Buchstaben und Ziffern), je Umgebung verschieden
5. **Installer:** `https://<domain>/install` öffnen. Er zeigt an, falls in der `config.php` etwas fehlt. Installationsschlüssel, Name, E-Mail und Passwort (mind. 12 Zeichen) des ersten Betreibers eingeben; optional weitere Hostnamen (z. B. `www.…`). Der aufgerufene Host wird Haupt-Host. Danach ist der Installer gesperrt (`var/install.lock`).
6. **Cron einrichten:** im Lima-City-Konto einen Cronjob alle 5 Minuten anlegen, entweder
   - als URL-Aufruf: `https://<domain>/jobs?token=<jobs_token>`, oder
   - auf der Kommandozeile: `php <Zielordner>/cli/jobs.php`
7. **Prüfen:** `https://<domain>/health?token=<health_token>` zeigt JSON mit `"status": "ok"` und nach dem ersten Cron-Lauf eine Zeit unter `jobs.letzter_lauf`.

## 3. Auslieferung (GitHub Actions)

- **Staging:** jeder Push auf `main` (also jeder gemergte PR) → Prüfung → Abgleich nach `/wintersymposium/staging` (FTP-Pfad `/staging/`).
- **Produktion:** ein Tag `vX.Y.Z` → Prüfung → Abgleich nach `/wintersymposium/produktiv` (FTP-Pfad `/produktiv/`). Der Tag muss genau der Datei `VERSION` entsprechen (`v` + Inhalt) und auf einem Commit von `main` liegen, sonst bricht der Lauf ab, bevor etwas übertragen wird.
  - Mit GitHub Desktop: Branch `main` auswählen und aktualisieren („Fetch/Pull origin“) → Reiter *History* → Rechtsklick auf den obersten Commit → *Create Tag…* → Name `v` + Version (z. B. `v0.1.0`) → *Push origin* (überträgt den Tag).
  - Optional: In GitHub unter *Settings → Environments → produktiv* einen Pflicht-Prüfer eintragen; dann wartet der Lauf vor dem Abgleich auf die Freigabe. Das Environment legt GitHub beim ersten Tag-Lauf selbst an.
- **Nie übertragen und nie gelöscht:** `config.php`, `var/` (Sitzungen, Sperrdateien, Logs).
- Die Secrets `FTP_SERVER`, `FTP_USER`, `FTP_PASSWORD` sind im Repo hinterlegt.
- Die Zustandsdatei `.ftp-deploy-sync-state.json` im Zielordner (außerhalb von `public/`) merkt sich den übertragenen Stand. **Nicht löschen**, sonst überträgt der nächste Lauf alles neu. Fehlt sie, legt der Lauf vorher eine leere an.
- Bricht der Schritt „Zustandsdatei auf dem Server sicherstellen“ ab, nennt die Meldung den Grund: Zielordner vom FTP-Login aus nicht erreichbar (curl 9), Anmeldung abgelehnt (curl 67) oder ein anderer curl-Code. In jedem Fall wurde noch nichts übertragen.
- **Datenbank-Änderungen** (Migrationen) laufen automatisch bei der ersten Anfrage nach einer Auslieferung bzw. beim nächsten Cron-Lauf.

## 4. Wartungsmodus

- Einschalten: per FTP eine (leere) Datei `var/wartung.lock` im Zielordner anlegen. Öffentliche Seiten zeigen dann eine Wartungsseite (HTTP 503); Team-Bereich, Health-Endpunkt und Installer bleiben erreichbar; Hintergrundjobs pausieren.
- Ausschalten: die Datei löschen.

## 5. Health-Endpunkt

`GET https://<domain>/health` mit Kopfzeile `X-Health-Token: <health_token>` oder `?token=<health_token>`. Ohne oder mit falschem Token: 404 (nach 10 Fehlversuchen in 10 Minuten ist die Herkunft für 10 Minuten gesperrt). Antwort:

```json
{"status": "ok", "version": "…", "umgebung": "staging", "zeit": "…", "datenbank": "ok",
 "schema": {"ist": 1, "soll": 1}, "wartung": false,
 "jobs": {"letzter_lauf": "…", "letzter_ausloeser": "url", "mit_fehler": []}}
```

## 6. Tests lokal und im Prüf-Workflow

```bash
php tools/tests/lauf.php
```

Ohne Datenbank laufen nur die Teile ohne DB. Mit einer **eigenen, leeren** Testdatenbank (wird geleert!) die Umgebungsvariablen `WS_TEST_DB_HOST`, `WS_TEST_DB_PORT`, `WS_TEST_DB_NAME`, `WS_TEST_DB_USER`, `WS_TEST_DB_PASS` setzen. Der Workflow „Prüfung“ macht das bei jedem PR mit MySQL 8.4.

## 7. Fehlerprotokoll

PHP-Fehler landen in `var/logs/php-fehler.log` im Zielordner (per FTP lesbar, über HTTP nicht erreichbar).
