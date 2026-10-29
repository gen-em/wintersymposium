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
    assets/             → site.css (Erscheinungsbild), fonts/ (WOFF2 + Lizenzen), logo/,
                          qr.js und vendor/qrcode.js (QR-Code für den zweiten Faktor, MIT)
  lib/                  → Bibliotheken (über HTTP nicht erreichbar)
  seiten/               → Seiten (über HTTP nicht erreichbar)
  cli/jobs.php          → Job-Einstieg für den Cron auf der Kommandozeile
  cli/konto.php         → Notweg für Team-Konten (Link erzeugen, zweiten Faktor zurücksetzen)
  config.example.php    → Vorlage; die echte config.php liegt nur auf dem Webspace
  var/                  → Laufzeitordner, legt die App selbst an (Sitzungen,
                          install.lock, wartung.lock, Logs, bilder/ mit den
                          Fotos der Dozierenden); wird nie ausgeliefert
tools/tests/            → Testlauf (lokal und im Prüf-Workflow)
tools/browser/          → Browser-Durchlauf (R-12)
tools/testdaten/        → Test-Beispieldatensatz (erfundene Daten, wächst je AP)
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
8. **Einloggen:** `https://<domain>/einloggen` mit dem Betreiber aus Schritt 5; beim ersten Mal den zweiten Faktor mit einer Authenticator-App einrichten und die Wiederherstellungscodes sichern (Abschnitt 8).

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

**Browser-Durchlauf (R-12)** in Chromium, Firefox und WebKit, je auf Handy (390 px), Tablet (768 px), schmalem (1024 px) und breitem Desktop (1440 px): alle Seiten, Klickwege (Menü, Team-Reiter, Login mit zweitem Faktor, Konto anlegen, Einrichtungslink, Ausloggen), jedes Auswahlfeld, jedes Aufklapp-Element, jedes Popup; dazu seitliches Scrollen, Überstand und Konsolenfehler.

```bash
bash tools/browser/einrichten.sh
```

(einmal je Sandbox: MySQL, Bibliotheken für WebKit, Firefox und WebKit in `/opt/ws-browsers`, Chromium dorthin verlinkt; zum Schluss starten alle drei Engines zum Nachweis)

```bash
node tools/browser/durchlauf.js <Ausgabeordner>
```

(mit denselben `WS_TEST_DB_*`-Variablen; die Testdatenbank wird geleert. Im Ausgabeordner liegen `bericht.txt` und je Engine, Breite und Seite ein Bildschirmfoto.) Popups erkennt der Durchlauf an Auslösern mit `aria-haspopup="dialog"` oder `data-dialog` und einem `<dialog>`; neue Popups so auszeichnen. Die Browser laufen mit deutscher Sprache, deutscher Umgebung (`LANG=de_DE.UTF-8` für den Browserprozess, bei Firefox mit Übernahme der Systemeinstellung) und Zeitzone Europe/Berlin; nur so zeigen Datums- und Zeitfelder TT.MM.JJJJ und 24 h – die Spracheinstellung allein ergibt in Chromium und Firefox das US-Format mit AM/PM. Auf den Bildschirmfotos das Format mit ansehen. **Grenzen:** WebKit unter Linux zeigt bei Uhrzeiten Sekunden (19:30:00), auch mit `step="60"`; Safari auf iPhone und Mac hat eigene Zeitfelder. Das betrifft nur die Darstellung im Testbrowser, nicht die gespeicherten Werte. Natives Ziehen (Drag and Drop) kann Playwright in WebKit unter Linux nicht auslösen; dort löst der Durchlauf dieselben Ereignisse im Browser aus.

**Test-Beispieldatensatz:** `tools/testdaten/beispieldaten.php` legt erfundene Daten an (bisher: zwei Dozierende und ein veröffentlichtes Programm mit Vortrag, Fallbesprechung, Workshop-Block mit zwei Workshops und einer Station, Pausen). Der Browser-Durchlauf lädt ihn nach der Installation, `lauf.php` prüft, dass er sich fehlerfrei laden lässt. Neue Bereiche (Anmeldungen ab AP-4 usw.) dort als eigene Funktion ergänzen und in `beispieldaten_laden()` aufrufen, damit spätere Prüfungen auf realistischen Daten laufen. Nur erfundene Namen und `example.org`-Adressen.

## 7. Pflegbare Texte

Texte der Startseite, Impressum und Datenschutz stehen in der Tabelle `inhalt` (Startwerte kommen mit der Migration). Bearbeitet werden sie im Betreiber-Bereich (ab AP-5). Erlaubte Auszeichnung:

| Schreibweise | Ergebnis |
|---|---|
| Leerzeile | neuer Absatz |
| `## Überschrift` / `### Überschrift` | Zwischenüberschrift |
| `- Punkt` | Aufzählung |
| `**fett**` | fett |
| `*Akzent*` | hervorgehoben (im Titel der Startseite in Lila) |
| `[Text](https://…)`, `[Text](mailto:…)` | Link (nur https und mailto) |

HTML wird nicht übernommen, sondern als Text angezeigt. Die Infokästen der Startseite (`start_infos`) werden an `##`-Überschriften getrennt: je Überschrift ein Kasten.

## 8. Team-Login und Konten

- **Einloggen:** `/einloggen` (Link „Team“ in der Fußzeile). Passwort, dann Code aus einer Authenticator-App (TOTP) oder ein Wiederherstellungscode. Beim ersten Login wird der zweite Faktor eingerichtet (QR-Code oder Schlüssel zum Abtippen); danach erscheinen zehn Wiederherstellungscodes **einmal**.
- **Neues Team-Mitglied:** Betreiber → Team-Bereich → „Team-Konten“ → Name, E-Mail, Rolle → der Einrichtungslink wird einmal angezeigt. Weitergeben (persönlich oder über einen sicheren Weg); er gilt 72 Stunden und einmal. Dort setzt die Person ihr Passwort und richtet den zweiten Faktor ein.
- **Passwort vergessen:** Betreiber erzeugt unter „Team-Konten“ einen Passwort-Link (gleicher Weg).
- **Handy weg:** mit einem Wiederherstellungscode einloggen und unter „Mein Konto“ neue Codes erzeugen; oder ein Betreiber setzt den zweiten Faktor zurück, beim nächsten Login wird er neu eingerichtet.
- **Rollen:** Admin (Programm, Einstellungen, Anmeldungen …), Betreiber (zusätzlich Team-Konten und Technik). Rolle ändern verlangt den eigenen Code; das eigene Konto lässt sich dort nicht ändern, und es bleibt immer ein aktiver Betreiber.
- **Notzugang** (Betreiber hat Handy und Wiederherstellungscodes verloren): `https://<domain>/notzugang` öffnen (nicht verlinkt), den `installations_schluessel` aus der config.php und die E-Mail-Adresse des Betreiber-Kontos eingeben. Der zweite Faktor wird zurückgesetzt und ein Passwort-Link angezeigt: öffnen, Passwort setzen, zweiten Faktor neu einrichten. 3 Versuche je Stunde; jede Nutzung steht im Audit-Protokoll. **Danach den Installationsschlüssel in der config.php durch einen neuen ersetzen.** Der Schlüssel ist damit so wichtig wie das Betreiber-Passwort: nur im Passwortmanager aufbewahren.
- **Notweg auf der Kommandozeile** (nur wo der Webspace eine Kommandozeile bietet):
  - `php <Zielordner>/cli/konto.php liste`
  - `php <Zielordner>/cli/konto.php zweitfaktor <email>` (zweiten Faktor zurücksetzen)
  - `php <Zielordner>/cli/konto.php link <email>` (neuer Passwort-Link, wird ausgegeben)
- **Achtung beim Tausch von `geheimnis` in config.php:** Das Geheimnis des zweiten Faktors ist damit versiegelt. Nach einem Tausch lassen sich die Codes aus der App nicht mehr prüfen; Wiederherstellungscodes gelten weiter, danach den zweiten Faktor neu einrichten.
- Sitzungen enden nach 30 Minuten ohne Aktivität sowie bei Passwortwechsel, Sperre, Rollenwechsel und Zurücksetzen des zweiten Faktors.
- Fremdbestandteil: `assets/vendor/qrcode.js` (qrcode-generator 2.0.4, Kazuhiko Arase, MIT; Lizenz in `assets/vendor/LIZENZ-qrcode-generator.txt`), übernommen aus NAdoku.

## 9. Veranstaltungs-Einstellungen

Team-Bereich → „Einstellungen“ (Admin und Betreiber). Alles, was die Veranstaltung betrifft (R-11): Datum, Zeit, Ort, Kapazität, CME-Angaben, Anmeldezeitraum, Workshop-Limit und -Pflicht, Nachrücken, Stichtage, Berufsgruppen. Zeiten werden in Ortszeit eingegeben. Stichtage gelten bis einschließlich des genannten Tages; leere Stichtage gelten bis zur Veranstaltung. Bis jemand speichert, gelten die Startwerte aus `server/lib/veranstaltung_lib.php`. Änderungen stehen im Audit-Protokoll und erscheinen sofort auf der Startseite. Die Werte zur Anmeldung wirken ab AP-4.

## 10. Programm

Team-Bereich → „Programm“ (Admin und Betreiber).

- **Typen:** Vortrag (VT), Fallbesprechung (FB), Workshop (WS, mit Terminen und Plätzen je Termin), offene Station (ohne Anmeldung), Workshop-Block (nur Zeitfenster), Pause/Info. Das Kürzel wird beim Anlegen vorgeschlagen (z. B. „VT 02“) und ist änderbar.
- **Workshop-Block:** sammelt im Plan und auf der Startseite alle Workshop-Termine und Stationen, die ganz in seiner Zeit liegen. Termine außerhalb eines Blocks stehen einzeln im Plan.
- **Speichern ≠ Veröffentlichen:** Gespeichertes steht sofort im Plan, auf der Startseite aber erst nach „Jetzt veröffentlichen“ im roten Banner. „Vorschau“ zeigt die Startseite mit dem Arbeitsstand, „Verwerfen“ stellt den veröffentlichten Stand wieder her (auch Gelöschtes). Punkte ohne Uhrzeit verhindern das Veröffentlichen.
- **Ziehen:** am Griff ⋮⋮ eine Zeile auf eine andere ziehen → neuer Beginn = Ende der Zielzeile (auf einem Block: dessen Beginn), die Dauer bleibt; ein Block nimmt Termine und Stationen mit. Der Vorschlag ist gespeichert, aber nicht veröffentlicht – Uhrzeit prüfen. Ohne Ziehen geht alles über die Felder Beginn und Ende.
- **Termine:** „+ Termin“ fügt eine leere Zeile an, „Duplizieren“ den nächsten Termin im Anschluss. Höchstens 12 Termine je Workshop. Plätze lassen sich nicht unter die vergebenen senken (wirkt mit AP-4).
- **Dozierende:** „Dozierende verwalten“; Foto optional (JPG, PNG oder WebP, höchstens 8 MB), wird quadratisch auf 480 px verkleinert und ohne Metadaten in `var/bilder/` gespeichert. Nur mit Einverständnis der Person. Fotos ohne Zuordnung räumt der Job „aufraeumen“ weg. Ohne die PHP-Erweiterung GD ist der Upload ausgeblendet.

## 11. Fehlerprotokoll

PHP-Fehler landen in `var/logs/php-fehler.log` im Zielordner (per FTP lesbar, über HTTP nicht erreichbar).
