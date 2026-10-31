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
                          qr.js und vendor/qrcode.js (QR-Code für den zweiten Faktor, MIT),
                          anmelden.js, pow.js und pow-worker.js (Anmeldung, Spamschutz)
  lib/                  → Bibliotheken (über HTTP nicht erreichbar)
  seiten/               → Seiten (über HTTP nicht erreichbar)
  pdf/                  → Schriften (TTF) und Logo für die PDF-Exporte (E-56)
  daten/                → wegwerfdomains.txt (Spamschutz, Quelle: disposable-email-domains)
  testdaten/            → Test-Beispieldatensatz (erfundene Daten, wächst je AP; auf Staging
                          über Team-Bereich → Daten ladbar)
  cli/jobs.php          → Job-Einstieg für den Cron auf der Kommandozeile
  cli/konto.php         → Notweg für Team-Konten (Link erzeugen, zweiten Faktor zurücksetzen)
  config.example.php    → Vorlage; die echte config.php liegt nur auf dem Webspace
  var/                  → Laufzeitordner, legt die App selbst an (Sitzungen,
                          install.lock, wartung.lock, Logs, bilder/ mit den
                          Fotos der Dozierenden, sicherungen/ mit den Sicherungen,
                          mails/ nur im Test-Modus 'datei');
                          wird nie ausgeliefert
tools/tests/            → Testlauf (lokal und im Prüf-Workflow)
tools/browser/          → Browser-Durchlauf (R-12)
```

Zielordner auf dem Webspace (E-32):

| Umgebung | Ordner im Webspace | Pfad vom FTP-Login aus | Domain zeigt auf |
|---|---|---|---|
| Staging | `/wintersymposium/staging` | `/staging` | `/wintersymposium/staging/public` (`test.winter-notfallsymposium.de`) |
| Produktion | `/wintersymposium/produktiv` | `/produktiv` | `/wintersymposium/produktiv/public` (`winter-notfallsymposium.de`) |

Der FTP-Zugang der Auslieferung landet direkt im Ordner `wintersymposium` und sieht dort `staging` und `produktiv`; der Workflow verwendet deshalb `/staging/` und `/produktiv/`.

## 2. Ersteinrichtung je Umgebung

Erst Staging einrichten und prüfen, dann Produktion genauso.

1. **Domain-Zuordnung:** in der Verwaltung des Hosters die Subdomain `test.winter-notfallsymposium.de` auf den Ordner `/wintersymposium/staging/public` legen (Produktion: Hauptdomain auf `/wintersymposium/produktiv/public`). HTTPS-Zertifikat für die Domain aktivieren.
2. **Datenbank:** eine MySQL-Datenbank mit eigenem Benutzer anlegen (je Umgebung eine eigene).
3. **Erste Auslieferung:** Sobald der PR in `main` gemergt ist, überträgt GitHub Actions den Code automatisch nach Staging (Abschnitt 3). Für Produktion: Tag setzen (Abschnitt 3).
4. **config.php anlegen:** `config.example.php` lokal kopieren, als `config.php` ausfüllen und per FTP in den Zielordner legen (neben `config.example.php`, **nicht** in `public/`):
   - `umgebung`: `staging` bzw. `produktion`
   - `db`: Zugangsdaten aus Schritt 2
   - `geheimnis`, `installations_schluessel`, `jobs_token`, `health_token`: je eine zufällige Zeichenkette mit mindestens 32 Zeichen (Passwortmanager, nur Buchstaben und Ziffern), je Umgebung verschieden
   - `mail`: SMTP-Server, Port und Verschlüsselung des Hosters, Benutzer und Passwort des Postfachs `anmeldung@` (Abschnitt 12). Ohne diesen Eintrag bleiben Mails in der Warteschlange, und der Team-Bereich zeigt eine rote Meldung.
5. **Installer:** `https://<domain>/install` öffnen. Er zeigt an, falls in der `config.php` etwas fehlt. Installationsschlüssel, Name, E-Mail und Passwort (mind. 12 Zeichen) des ersten Betreibers eingeben; optional weitere Hostnamen (z. B. `www.…`). Der aufgerufene Host wird Haupt-Host. Danach ist der Installer gesperrt (`var/install.lock`).
6. **Cron einrichten:** in der Verwaltung des Hosters einen Shell-Cronjob alle 5 Minuten anlegen mit dem Kommando `php <Zielordner>/cli/jobs.php` (E-64). Nur wenn der Cron keine Befehle starten kann: URL-Cronjob auf `https://<domain>/jobs?token=<jobs_token>` – der Token steht dann in Zugriffslogs, deshalb nur als Ausweichweg.
7. **Prüfen:** `https://<domain>/health?token=<health_token>` zeigt JSON mit `"status": "ok"` und nach dem ersten Cron-Lauf eine Zeit unter `jobs.letzter_lauf`.
8. **Einloggen:** `https://<domain>/einloggen` mit dem BetreiberIn aus Schritt 5; beim ersten Mal den zweiten Faktor mit einer Authenticator-App einrichten und die Wiederherstellungscodes sichern (Abschnitt 8).

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

**Version:** Außerhalb der Produktion steht die laufende Version in der Fußzeile jeder Seite (z. B. „Version 0.8.1 · Staging“), im Team-Bereich immer auf der Übersicht.

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

**Stufen** (R-12, E-62):

| Stufe | Wann | Was |
|---|---|---|
| 1 | jede Änderung | `php tools/tests/lauf.php` |
| 2 | Oberfläche einzelner Seiten | `WS_STUFE=2 WS_SEITEN=team-konten,team-statistik node tools/browser/durchlauf.js <ordner>` – nur Chromium, Handy und breiter Desktop; volle Prüfung und Fotos nur für Seiten, deren Name (wie in `bericht.txt`) mit einem Eintrag beginnt; ca. 1 Minute |
| 3 | Arbeitspaket fertig, gemeinsame Teile (Kopfleiste, Navigation, Knöpfe, Meldungen, Formulare, CSS-Grundlagen, JavaScript, Login/Sitzung), vor Produktion, spätestens nach 5 PRs ohne vollen Lauf | voller Durchlauf, 30–40 Minuten |

Einzeln überschreibbar: `WS_ENGINES=webkit`, `WS_BREITEN=handy,tablet`.

Beide Testserver (`lauf.php` und Browser-Durchlauf) laufen mit `output_buffering=0` wie beim Hoster: Eine Seite, die nach der ersten Ausgabe noch eine Sitzung öffnen will, fällt so schon im Test auf.

**Browser-Durchlauf (R-12)** in Chromium, Firefox und WebKit, je auf Handy (390 px), Tablet (768 px), schmalem (1024 px) und breitem Desktop (1440 px): alle Seiten, Klickwege (Menü, Team-Reiter, Login mit zweitem Faktor, Konto anlegen, Einrichtungslink, Ausloggen), jedes Auswahlfeld, jedes Aufklapp-Element, jedes Popup; dazu seitliches Scrollen, Überstand und Konsolenfehler.

```bash
bash tools/browser/einrichten.sh
```

(einmal je Sandbox: MySQL, Bibliotheken für WebKit, Firefox und WebKit in `/opt/ws-browsers`, Chromium dorthin verlinkt; zum Schluss starten alle drei Engines zum Nachweis)

```bash
node tools/browser/durchlauf.js <Ausgabeordner>
```

(mit denselben `WS_TEST_DB_*`-Variablen; die Testdatenbank wird geleert. Im Ausgabeordner liegen `bericht.txt` und je Engine, Breite und Seite ein Bildschirmfoto.) Popups erkennt der Durchlauf an Auslösern mit `aria-haspopup="dialog"` oder `data-dialog` und einem `<dialog>`; neue Popups so auszeichnen. Die Browser laufen mit deutscher Sprache, deutscher Umgebung (`LANG=de_DE.UTF-8` für den Browserprozess, bei Firefox mit Übernahme der Systemeinstellung) und Zeitzone Europe/Berlin; nur so zeigen Datums- und Zeitfelder TT.MM.JJJJ und 24 h – die Spracheinstellung allein ergibt in Chromium und Firefox das US-Format mit AM/PM. Auf den Bildschirmfotos das Format mit ansehen. **Grenzen:** WebKit unter Linux zeigt bei Uhrzeiten Sekunden (19:30:00), auch mit `step="60"`; Safari auf iPhone und Mac hat eigene Zeitfelder. Das betrifft nur die Darstellung im Testbrowser, nicht die gespeicherten Werte. Natives Ziehen (Drag and Drop) kann Playwright in WebKit unter Linux nicht auslösen; dort löst der Durchlauf dieselben Ereignisse im Browser aus.

**Test-Beispieldatensatz:** `server/testdaten/beispieldaten.php` legt erfundene Daten an: Einstellungen relativ zu heute (Veranstaltung in 60 Tagen, Anmeldung offen), zwei Dozierende, ein veröffentlichtes Programm mit Vortrag, Fallbesprechung, Workshop-Block mit zwei Workshops (ein Termin mit nur 2 Plätzen, einer mit anderer Leitung), einer Station und einer zugeordneten Fallbesprechung, Pausen, dazu Anmeldungen: drei bestätigte mit Workshops, eine unbestätigte, ein voller Termin mit Warteliste und ein offenes Nachrück-Angebot mit Tausch. Der Browser-Durchlauf lädt ihn nach der Installation, `lauf.php` prüft, dass er sich fehlerfrei laden lässt. Neue Bereiche (Anmeldungen ab AP-4 usw.) dort als eigene Funktion ergänzen und in `beispieldaten_laden()` aufrufen, damit spätere Prüfungen auf realistischen Daten laufen. Nur erfundene Namen und `example.org`-Adressen.

## 7. Pflegbare Texte und der Editor

Texte der Startseite, Impressum und Datenschutz stehen in der Tabelle `inhalt` (Startwerte kommen mit der Migration). Bearbeitet werden sie im Team-Bereich: die Startseite je Veranstaltung unter Verwaltung → „Startseite“, Impressum und Datenschutz (für alle Veranstaltungen) unter Betrieb → „Texte“ (nur BetreiberInnen, AP-12).

**Ein Editor überall** (Admin 2026-10-30, E-54): Texte, Programm-Beschreibungen, Kurzbios der Dozierenden, Beschreibungen der Newsletter-Listen und Mailings nutzen denselben Editor (`assets/editor.js`). Man sieht den Text gleich formatiert. Knöpfe: fett, kursiv, Zwischenüberschrift (H), Liste, nummerierte Liste, Link, in Texten und Mailings auch Bild (in Mailings zusätzlich Knopf, Platzhalter, HTML). Die Knöpfe zeigen, was an der Schreibmarke gilt, und schalten es an und aus. Einzeilige Felder (Titel, Untertitel, Zeile über dem Titel, Listen-Beschreibung) kennen nur fett, kursiv und Link; kursiv erscheint im Titel der Startseite in Lila. Infokästen der Startseite: ein Kasten je Zwischenüberschrift.

Gespeichert wird bereinigtes HTML aus wenigen Elementen (Absatz, Zwischenüberschrift, Listen, fett, kursiv, Link nur https und mailto, Knopf, eigene Bilder). Alles andere entfernt der Server beim Speichern und noch einmal beim Anzeigen. Bilder (JPG/PNG) werden auf höchstens 1200 px verkleinert und in `var/bilder/` abgelegt, mit gesichert; unbenutzte löscht der Job nach einem Tag. Klick aufs Bild im Editor: halbe oder volle Breite. Alle mehrzeiligen Felder haben dieselbe Werkzeugleiste wie die Mailings, mit Knopf (Link als Schaltfläche) und HTML-Quelltext; festgelegt an einer Stelle (`EDITOR_LEISTE` in `inhalt_lib.php`, Admin 2026-10-31). Platzhalter gibt es nur in Mails, einzeilige Felder haben nur fett, kursiv und Link. Bilder sind auch in Programm-Beschreibungen und Kurzbios erlaubt; der Aufräum-Job sucht sie in Texten, Programm, veröffentlichtem Stand und Kurzbios. Ohne JavaScript bleibt ein Textfeld; Klartext wird dann als Absätze übernommen.

## 8. Team-Login und Konten

**Ein Konto für alles** (Admin 2026-10-30, E-54): Team und Teilnehmende loggen sich gleich ein und haben eine gemeinsame Sitzung (Keks `ws_sitzung`). Team-Konten fragen direkt beim Login den zweiten Faktor ab und gelten danach auch als Konto für die eigene Anmeldung (Team-Mitglieder melden sich mit ihrem Konto selbst an, ohne Bestätigungsmail). Wird ein Konto später zum Team-Konto, endet seine Sitzung; beim nächsten Login wird der zweite Faktor eingerichtet. Ausloggen beendet alles.

**Kopfleiste** (AP-12, E-60): bleibt beim Scrollen oben stehen (mit Schatten, gleiche Höhe); auf dem Handy verschwindet sie beim Scrollen nach unten, rechts oben bleibt ein runder „Menü“-Knopf, beim Scrollen nach oben kommt sie zurück (`assets/kopf.js`; ohne JavaScript bleibt sie stehen). Der **Team-Bereich** steht in Grün in der Leiste (ausgewählt unterstrichen). **Meldungen** (AP-13) stehen als volle Farbfläche mit Icon oben auf der Seite: Fehler rot, Hinweise lila, Erfolg grün; liegt eine Fehlermeldung außerhalb des Bildschirms, scrollt die Seite dorthin. **Knöpfe**: Haupt (lila gefüllt), Zweit (lila Ring, 2 px), Anmelden (grün), Gefahr (roter Ring), 44 bzw. 36 px hoch.

**Personen-Menü oben rechts** (Entwurf A, Icons aus Phosphor, `lib/vendor/phosphor/`): ausgeloggt „Einloggen“, sonst Kürzel, Vorname und Pfeil; beim Hover ein lila Ring. Aufgeklappt: oben ein Kopf zum Lesen (Kürzel, Name, Adresse, Rolle), darunter Meine Anmeldung, Mein Konto und Ausloggen mit Icons. Der Team-Bereich steht nicht im Menü, sondern in der Leiste. Wer eingeloggt ist, sieht auf „Meine Anmeldung“ kein Login-Feld, sondern die eigene Anmeldung oder „Jetzt anmelden“; auch die Startseite erkennt den Login (bis 0.19 dort nicht, Rückmeldung 2026-10-31).

**Mein Konto** (Entwurf 7): links Profil (bei Team-Konten mit Rolle und den Veranstaltungen, für die sie gilt), Deine Veranstaltungen (oben „Als Nächstes“ mit Anmelden bzw. „Workshops, Essen, absagen“, darunter vergangene mit Bescheinigung) und Newsletter; rechts eine Kachel „Einloggen und Sicherheit“ mit Passwort (aufklappbar), „Zweiter Schritt beim Einloggen“ (Code aus der App, Code per E-Mail, Notfall-Codes) und „Einloggen ohne Passwort“ (Passkeys); bei Team-Konten darüber der lila Hinweis, dass der zweite Schritt Pflicht ist. Teilnehmende ändern dort ihre Adresse und löschen ihr Konto.


- **Einloggen:** über „Anmelden“ in der Navigation (Kasten „Einloggen“) oder direkt `/einloggen`; der Link „Team“ in der Fußzeile ist weg (Rückmeldung 2026-10-30). Passwort, dann der zweite Faktor (Zwei-Faktor-Authentifizierung): Code aus einer Authenticator-App (TOTP), Code per E-Mail (frei wählbar, gilt 10 Minuten und einmal) oder ein Wiederherstellungscode. Beim ersten Login wählt man App (QR-Code oder Schlüssel zum Abtippen) oder Code per E-Mail; danach erscheinen zehn Wiederherstellungscodes **einmal**. Der E-Mail-Code ist nur so sicher wie das Postfach.
- **Mein Konto** (`/konto`, für Team und Teilnehmende) → „Zwei-Faktor einstellen“ (`/konto/zweitfaktor`): App einrichten, Code per E-Mail ein- oder ausschalten (aus nur, wenn App oder Passkey eingerichtet sind), neue Wiederherstellungscodes – jeweils mit dem Passwort bestätigt.
- **Team-Login auf öffentlichen Seiten:** dieselbe Sitzung; Personen-Menü und „Team-Bereich“ erscheinen überall, eine eigene Anmeldung (verknüpft oder mit derselben Adresse) unter „Meine Anmeldung“.
- **Passkeys** (E-55): Unter „Mein Konto“ → „Passkeys“ legt jede Person mit Konto einen Passkey an (Name, mit Passwort bestätigt), umbenennen und entfernen geht dort auch. Danach „Mit Passkey einloggen“ auf der Login-Seite: ohne Passwort, mit Fingerabdruck, Gesicht oder Geräte-PIN; bei Team-Konten ersetzt der Passkey Passwort und zweiten Faktor. Für das Team zählt ein Passkey als zweiter Faktor (beim ersten Login als erste Wahl); der letzte zweite Faktor lässt sich nicht entfernen, die Wiederherstellungscodes bleiben der Notweg. Ein Passkey gilt nur unter der Domain, unter der er angelegt wurde (Staging und Produktion getrennt), und nur mit HTTPS. Gespeichert werden öffentlicher Schlüssel, Zähler, Name und Zeitpunkte, keine Gerätedaten. Eine BetreiberIn, der den zweiten Faktor zurücksetzt, entfernt auch die Passkeys.
- **Neues Team-Mitglied:** BetreiberIn → Team-Bereich → „Team-Konten“ → Name, E-Mail, Rolle → die Einladung kommt per Mail (AP-6); mit „Keine Mail“ wird der Link stattdessen einmal angezeigt. Er gilt 72 Stunden und einmal. Dort setzt die Person ihr Passwort und richtet den zweiten Faktor ein.
- **Passwort vergessen:** „Passwort vergessen?“ beim Einloggen (Link per Mail, 72 Stunden; der zweite Faktor bleibt) oder eine BetreiberIn mailt unter „Team-Konten“ einen Passwort-Link.
- **Handy weg:** mit einem Wiederherstellungscode einloggen und unter „Mein Konto“ → „Zwei-Faktor einstellen“ neue Codes erzeugen; oder eine BetreiberIn setzt den zweiten Faktor zurück, beim nächsten Login wird er neu eingerichtet.
- **Rollen:** Admin (Programm, Einstellungen, Anmeldungen …), BetreiberIn (zusätzlich Team-Konten und Technik). Rolle ändern verlangt den eigenen Code; das eigene Konto lässt sich dort nicht ändern, und es bleibt immer eine aktive BetreiberIn.
- **Notzugang** (BetreiberIn hat Handy und Wiederherstellungscodes verloren): `https://<domain>/notzugang` öffnen (nicht verlinkt), den `installations_schluessel` aus der config.php und die E-Mail-Adresse des BetreiberIn-Kontos eingeben. Der zweite Faktor wird zurückgesetzt und ein Passwort-Link angezeigt: öffnen, Passwort setzen, zweiten Faktor neu einrichten. 3 Versuche je Stunde; jede Nutzung steht im Audit-Protokoll. **Danach den Installationsschlüssel in der config.php durch einen neuen ersetzen.** Der Schlüssel ist damit so wichtig wie das Betreiber-Passwort: nur im Passwortmanager aufbewahren.
- **Notweg auf der Kommandozeile** (nur wo der Webspace eine Kommandozeile bietet):
  - `php <Zielordner>/cli/konto.php liste`
  - `php <Zielordner>/cli/konto.php zweitfaktor <email>` (zweiten Faktor zurücksetzen)
  - `php <Zielordner>/cli/konto.php link <email>` (neuer Passwort-Link, wird ausgegeben)
- **Achtung beim Tausch von `geheimnis` in config.php:** Das Geheimnis des zweiten Faktors ist damit versiegelt. Nach einem Tausch lassen sich die Codes aus der App nicht mehr prüfen; Wiederherstellungscodes gelten weiter, danach den zweiten Faktor neu einrichten.
- Sitzungen enden nach 30 Minuten ohne Aktivität sowie bei Passwortwechsel, Sperre, Rollenwechsel und Zurücksetzen des zweiten Faktors.
- Fremdbestandteil: `assets/vendor/qrcode.js` (qrcode-generator 2.0.4, Kazuhiko Arase, MIT; Lizenz in `assets/vendor/LIZENZ-qrcode-generator.txt`), übernommen aus NAdoku.
- Fremdbestandteil: `lib/vendor/tfpdf/` (tFPDF 1.34, LGPL-2.1, unverändert; Herkunft, Prüfsummen und Aktualisierung in `HERKUNFT.md`) für die PDF-Exporte (E-56). Schriften und Logo der PDFs liegen in `server/pdf/` (SIL OFL 1.1, Lizenzen daneben); tFPDF speichert Schriftmaße im Laufzeitordner unter `pdf/unifont/` (wird neu erzeugt, wenn er fehlt).

## 9a. Mehrere Veranstaltungen (E-58)

- **Eine aktuelle:** Startseite, `/anmelden` und „Meine Anmeldung“ zeigen immer die aktuelle Veranstaltung. Jede Veranstaltung ist außerdem unter `https://<domain>/<kürzel>` erreichbar; das Kürzel steht unter Einstellungen (Kleinbuchstaben, Ziffern, Bindestrich; Adressen der Seite wie `team` sind gesperrt).
- **Team-Bereich:** Der Wechsler rechts neben dem Seitentitel (AP-12, E-60; vorher eine Leiste unter der Navigation) zeigt die gewählte Veranstaltung mit Status; aufgeklappt die Veranstaltungen, für die man freigeschaltet ist, für BetreiberInnen dazu „Neue Veranstaltung“ und „Alle Veranstaltungen verwalten“. Alle Seiten (Personen, Workshops, Teilnahme, Programm, Texte der Startseite, Einstellungen, Statistik, Exporte, Mailings an Angemeldete) gelten für diese. Gemeinsam bleiben Konten, Team, Newsletter, Impressum, Datenschutz, Dozierende, Absender und Hostnamen.
- **Betrieb → Veranstaltungen** (nur BetreiberInnen, AP-12): neue anlegen – leer oder als Kopie (Einstellungen, Startseiten-Texte und Programm als unveröffentlichter Entwurf, keine Anmeldungen); **Aktuell machen** (nur, wenn keine andere aktuell ist); **Abschließen** (danach nur noch ansehen: Speichern wird abgewiesen, keine Anmeldung, die Seite unter dem Kürzel zeigt einen Rückblick; Exporte und freigeschaltete Zertifikate bleiben); **Löschen** jede außer der aktuellen: Dialog, dann das Kürzel eintippen; vorher legt die App eine Sicherung an („vor dem Löschen von …“). Gelöscht werden Programm, Einstellungen, Texte, Anmeldungen, Plätze, Mailings und die Unterschrift; die CME-Nachweise und das Protokoll bleiben. Versehentlich gelöscht: die Sicherung wiederherstellen – sie setzt aber alles auf ihren Stand zurück. Nach dem Veranstaltungstag erinnert „Zu erledigen“ ans Abschließen.
- **Ablauf für die nächste Auflage:** Anwesenheit eintragen und Zertifikate freischalten → neue Veranstaltung als Kopie anlegen und vorbereiten → alte abschließen → neue aktuell machen.
- **Bausteine** (Einstellungen): Essenswahl, Fortbildungspunkte (EFN, CME-Liste) und Teilnahmebescheinigung je ja/nein; Workshops gibt es, sobald das Programm welche enthält. **Kurzname** und **Art** bestimmen Texte in Mails, Kopfzeilen und Seitentiteln.
- **Teilnehmende:** „Mein Konto“ zeigt alle Veranstaltungen mit Stand und Zertifikat. Wer ohne Konto wiederkommt und unter „Meine Anmeldung“ einen Link anfordert, bekommt – wenn es für die aktuelle Veranstaltung noch keine Anmeldung gibt – einen Link „Daten übernehmen“; das Formular ist dann vorausgefüllt.

## 9. Veranstaltungs-Einstellungen

Team-Bereich → „Einstellungen“ (Admin und BetreiberIn), je Veranstaltung (E-58). Alles, was die Veranstaltung betrifft (R-11): Kürzel, Kurzname, Art, Bausteine, Name, Auflage, Datum, Zeit, Ort, Kapazität, CME-Angaben (Punkte, Kategorie, Kammer, VNR), Angaben unter der Unterschrift des Zertifikats, Anmeldezeitraum, Workshop-Limit und -Pflicht, Nachrücken, Stichtage, Berufsgruppen. Zeiten werden in Ortszeit eingegeben. Stichtage gelten bis einschließlich des genannten Tages; leere Stichtage gelten bis zur Veranstaltung. Bis jemand speichert, gelten die Startwerte aus `server/lib/veranstaltung_lib.php`. Änderungen stehen im Audit-Protokoll und erscheinen sofort auf der Startseite. Die Werte zur Anmeldung wirken ab AP-4. Die Seite ist zuerst **gesperrt** (Balken oben, Felder grau); „Entsperren“ schaltet die Felder frei, nach dem Speichern ist sie wieder gesperrt (Rückmeldung 2026-10-30).

## 10. Programm

Team-Bereich → „Programm“ (Admin und BetreiberIn).

- **Typen:** Vortrag (VT), Fallbesprechung (FB), Workshop (WS, mit Terminen und Plätzen je Termin), offene Station (ohne Anmeldung), Workshop-Block (nur Zeitfenster), Pause/Info. Das Kürzel wird beim Anlegen vorgeschlagen (z. B. „VT 02“) und ist änderbar.
- **Workshop-Block:** sammelt im Plan und auf der Startseite alle Workshop-Termine und Stationen, die ganz in seiner Zeit liegen. Workshops, Stationen, **Vorträge und Fallbesprechungen** lassen sich auch **ausdrücklich zuordnen** (Feld „Gehört zu Workshop-Block“ oder im Plan auf die Mitte des Blocks bzw. zwischen zwei Punkte im Block ziehen); dann stehen sie unabhängig von der Uhrzeit darunter, und ein Hinweis meldet Zeiten außerhalb der Blockzeit. Vorträge und Fälle kommen nur so in einen Block, nie über die Uhrzeit; im Block laufen sie parallel und schieben die Hauptspur nicht. Wird ein Block verschoben, wandern alle zugeordneten Punkte mit. Termine ohne Block stehen einzeln im Plan.
- **Speichern ≠ Veröffentlichen:** Gespeichertes steht sofort im Plan (Label „nicht veröffentlicht“), auf der Startseite aber erst nach „Jetzt veröffentlichen“ im hellroten Hinweisbalken. „Vorschau“ zeigt die Startseite mit dem Arbeitsstand, „Verwerfen“ stellt den veröffentlichten Stand wieder her (auch Gelöschtes). Punkte ohne Uhrzeit verhindern das Veröffentlichen.
- **Ziehen:** am Griff ⋮⋮ eine Zeile zwischen zwei andere ziehen, mit Maus oder Finger (Handy, Tablet); die weiße Linie zeigt, ob davor oder dahinter eingefügt wird, unter einem Block unter dem ganzen Block (ausgewählt = lila, Block = grün). Am Fensterrand rollt die Seite mit, Escape bricht ab. Neuer Beginn = Ende des Punkts darüber (als erster Punkt in einem Workshop-Block: dessen Beginn; unter dem letzten Punkt: dessen Ende), die Dauer bleibt. Punkte der Hauptspur (Vorträge, Fälle, Pausen, Workshop-Blöcke samt Inhalt), die sich danach überschneiden würden, rücken nach hinten, bis eine Lücke reicht (Admin 2026-10-29). Alles ist gespeichert, aber nicht veröffentlicht – Uhrzeiten prüfen. Ohne Ziehen geht alles über die Felder Beginn und Ende.
- **Termine:** „+ Termin“ fügt eine leere Zeile an, „Duplizieren“ den nächsten Termin im Anschluss (mit Plätzen und Dozierenden), „Entfernen“ nimmt eine neue Zeile weg; gespeicherte Termine „entfernen“ anhaken und speichern. Höchstens 12 Termine je Workshop. **Dozierende je Termin:** nur wenn ein Termin abweicht (z. B. Pause einer Dozentin) – nichts gewählt heißt „wie beim Workshop“; die Startseite zeigt dann „mit …“ beim Termin. Plätze lassen sich nicht unter die vergebenen senken (wirkt mit AP-4).
- **Dozierende:** „Dozierende verwalten“; Foto optional (JPG, PNG oder WebP, höchstens 8 MB), wird quadratisch auf 480 px verkleinert und ohne Metadaten in `var/bilder/` gespeichert. Nur mit Einverständnis der Person. Fotos ohne Zuordnung räumt der Job „aufraeumen“ weg. Ohne die PHP-Erweiterung GD ist der Upload ausgeblendet.

## 11. Anmeldung (AP-4)

- **Ablauf:** `/anmelden` → Mail mit Bestätigungslink → erst der Klick macht die Anmeldung gültig. Bis dahin hält sie ihre Plätze (Einstellung „Bestätigungsfrist“, Start 48 h, so lange wie der Link gilt); danach löscht der Job sie und die Plätze gehen ans Nachrücken.
- **„Anmelden“ / „Meine Anmeldung“** (`/deine-anmeldung`, Rückmeldung 2026-10-30): Wer nicht erkannt ist, sieht in der Navigation den grünen Knopf „Anmelden“; die Seite dahinter bietet „Neu anmelden“, „Link per Mail“ und „Einloggen“. Vor dem Anmeldestart steht statt der ersten beiden Kacheln „Anmeldung noch nicht möglich – öffnet am …“. Erkannte Personen (Link, Konto, Team) sehen „Meine Anmeldung“. Einstieg mit „Link per Mail“ (gleiche Antwort für jede Adresse); nach dem Link Übersicht, Workshops, Essen und Daten ändern, stornieren, Nachrück-Angebote annehmen oder ablehnen; nach dem Symposium die Teilnahmebescheinigung herunterladen, sobald das Team sie freigeschaltet hat (AP-10). Einmal-Links gelten 48 Stunden und einmal; erst der Klick auf „Weiter“ auf der Link-Seite verbraucht sie. Danach endet die Sitzung nach 30 min ohne Aktivität.
- **Stichtage** (Einstellungen): nach dem Tag sind Workshops, Essen bzw. Storno für Teilnehmende gesperrt; die Seite zeigt den Stichtag am Knopf.
- **Plätze:** je Workshop ein fester Termin, keine Überschneidungen, Warteliste für volle Termine (auch überschneidend). Ist das Symposium voll, kommt die Person auf dessen Warteliste und wählt Workshops erst nach dem Nachrücken. Plätze beziehen sich auf das veröffentlichte Programm; ein Termin mit vergebenen Plätzen lässt sich nicht löschen oder verkleinern.
- **Nachrücken:** Wird ein Platz frei, bekommt die erste bestätigte Person der Warteliste ein Angebot per Mail (Annahmefrist aus den Einstellungen). Lässt sie es verstreichen, kommt sie ans Ende der Liste und das Angebot geht weiter. Ab der Grenze (Tage vor der Veranstaltung) keine automatischen Angebote mehr; dann vergibt das Team (AP-5).
- **Jobs** (Cron alle 5 Minuten): `anmeldung` (Fristen, abgelaufene Angebote, Nachrücken, alte Links) und `mail` (Warteschlange) jede Minute fällig, `aufraeumen` stündlich.
- **Team:** Reiter „Anmeldungen“ zeigt Zahlen, Workshop-Termine und alle Anmeldungen (nur lesen; Bearbeiten mit AP-5).
- **Spamschutz:** Honigtopf, Formular mindestens 4 s offen, Rechenaufgabe im Browser (JavaScript nötig), Ratenschutz je Herkunft, Wegwerfadressen. Greift eine Bremse, sieht die Antwort aus wie bei Erfolg.
- **Datenschutz:** Die Datenschutzerklärung (pflegbarer Text, Abschnitt 7) muss die Anmeldung und die Konten beschreiben: Felder, Zweck, Mails, Löschung (Storno und verfallene Bestätigung löschen die Anmeldung sofort; Konten: Löschfrist nach E-54; weitere Fristen in Abschnitt 16a). Textvorschlag: `docs/datenschutz-vorschlag.md`.

## 12. Mailversand

Konfiguration in `config.php`, Abschnitt `mail` (Vorlage in `config.example.php`):

| Schlüssel | Bedeutung |
|---|---|
| `modus` | `smtp`; `datei` nur für Tests (Mails als Datei in `var/mails/`) |
| `host`, `port`, `verschluesselung` | SMTP-Server des Hosters; `ssl` (meist Port 465) oder `starttls` (meist 587) |
| `benutzer`, `passwort` | Zugang des Postfachs `anmeldung@` – nur hier, nie im Repo |
| `absender`, `absender_name` | Absender; leer: `anmeldung@<Haupt-Domain>` |
| `antwort_an` | optional, Antwortadresse (z. B. `admin@…`) |
| `tageslimit` | Mails je Tag (Start 450); System-Mails gehen immer raus, Mailings über dieses Postfach nur bis 80 %, der Rest am nächsten Tag |
| `newsletter` | optional, zweites Postfach für Mailings (AP-6): `benutzer`, `passwort`, `absender`, `absender_name`, `tageslimit`; Server wie oben. Fehlt der Eintrag, gehen Mailings über anmeldung@ |
| `umleiten_an` | nur Staging: alle Mails an diese Adresse, Empfänger im Betreff (Abschnitt 16) |

**Prüfen:** Team-Bereich → „Betrieb“ → Kasten „Mailversand“ zeigt, ob der Versand eingerichtet ist, Absender, Warteschlange und den letzten Fehler; „Testmail an mich“ versendet sofort und zeigt die Antwort des Mailservers (ohne Passwort).

Jede Mail geht zuerst in die Warteschlange und wird direkt nach der Antwort an den Browser versendet, spätestens beim nächsten Cron-Lauf. Fehlschläge werden nach 5, 30, 120 und 360 Minuten wiederholt, danach als Fehler gezählt (Team-Bereich, Fehlerprotokoll ohne Empfängeradresse). Nach dem Versand werden Empfänger, Betreff und Text gelöscht (R-02); Einträge verschwinden nach 30 Tagen. Zeitbudget je Versand 5 s.

**Mailings (AP-6, E-50):** Team-Bereich → „Mailings“. Empfänger wählen (alle Angemeldeten, Wartelisten, ohne Essen, je Workshop-Termin; optional nur eine Berufsgruppe; nur bestätigte Anmeldungen), Absender (anmeldung@ oder newsletter@), Betreff und Text mit Platzhaltern {vorname}, {nachname}, {workshops}, {link} (persönlicher Einmal-Link, 48 h). Bilder (JPG/PNG) werden verkleinert und in die Mail eingebettet. Vorschau neben dem Editor, „Testmail an mich“, „Planen …“ (Datum und Uhrzeit, aufhebbar), „An N senden“ (mit Nachfrage). Mailings gehen über das Tageslimit ihres Postfachs hinaus am nächsten Tag weiter; der Rest lässt sich abbrechen. Gesendete Mailings stehen mit Empfängerliste im Archiv; die Liste wird 14 Monate nach dem Symposium gelöscht (E-24). Kein Abmeldelink (organisatorische Mails); Impressum und Datenschutz hängt die App an.

**Team-Mails:** Neue Team-Konten bekommen die Einladung per Mail (oder der Link wird angezeigt, „Keine Mail“). „Passwort vergessen?“ beim Einloggen gilt auch für Team-Konten; der zweite Faktor bleibt.

## 13. Team-Bereich (AP-5)

**Nachfragen** (löschen, verwerfen, senden …) erscheinen überall als derselbe Dialog über der Seite (Rückmeldung 2026-10-30, Entwurf C): Rahmen grün = bestätigen, rot = löschen/verwerfen, lila = Info; auf dem Handy von unten mit großen Knöpfen. Ohne JavaScript bleibt die Nachfrage zum Aufklappen.

Bereiche (Admin 2026-10-30, Variante A; neu geordnet mit AP-12, E-60), darunter angehängt die Unterseiten des aktiven Bereichs: **Übersicht** · **Anmeldungen** (Personen, Workshops, Teilnahme) · **Programm** (Programm, Dozierende) · **Mailings** (Abschnitt 12) · **Statistik** · **Verwaltung** (Einstellungen, Startseite, Protokoll) · **Betrieb**, nur für BetreiberInnen, alles, was für alle Veranstaltungen gilt (Veranstaltungen – Abschnitt 9a, Team-Konten, Newsletter – Abschnitt 14, Texte, Betrieb).

**Rechte je Veranstaltung** (AP-12, E-60): BetreiberInnen sehen und dürfen alles. Admins sind unter Betrieb → Team-Konten → „Bearbeiten“ → „Veranstaltungen …“ (AP-13: je Zeile ein Menü, jede Aktion im Dialog) für **alle, auch künftige** oder **nur ausgewählte** Veranstaltungen freigeschaltet. Sie sehen dann im Wechsler, in Personen, Workshops, Teilnahme, Programm, Mailings, Statistik, Exporten und im Protokoll nur diese; bei Konten nur Personen, die in einer dieser Veranstaltungen angemeldet sind. Eine fremde Veranstaltung per Adresse (`?va=`) wird abgewiesen, Nummern fremder Anmeldungen oder Plätze ergeben 404. Ohne Freischaltung sehen sie einen Hinweis statt des Team-Bereichs. Mailings an Newsletter-Listen, der Newsletter und sein Export sind nur für BetreiberInnen. „Mein Konto“ steckt im Personen-Menü oben rechts.

- **Übersicht:** Kennzahlen, „Zu erledigen“ (freie Plätze mit Warteliste – nach der Grenze manuell vergeben –, Mail-Fehler, Angebote, die in 24 h ablaufen, unveröffentlichtes Programm), Workshop-Termine, Essen.
- **Personen:** Suche und Filter (Angemeldet, Warteliste, Mail nicht bestätigt, Mit Konto, Team). Eine Person auswählen: Daten, Essen und Workshops ändern – auch nach den Stichtagen und ohne Workshop-Limit. Volle Termine nur mit „Überbuchen bestätigen“. Plätze, Wartelisten und Angebote: „Platz geben“ (fragt bei Überbuchen oder Tausch nach), „Angebot schicken“, „Entfernen“. „Einmal-Link senden“, „+ Person anlegen“ (gilt sofort als bestätigt). Konto: Passwort-Link, sperren, löschen (mit Wahl), Rolle vergeben (nur BetreiberInnen, mit Code). Jede Änderung schickt der Person eine Mail, außer „ohne Mail“ ist angekreuzt; alles steht im Protokoll.
- **Workshops:** Symposium und alle Termine mit Belegung; ein Termin zeigt Teilnehmende und Warteliste in Reihenfolge, mit denselben Aktionen und der Liste als CSV, Excel oder PDF (eine Seite mit Ort, Leitung und Belegung, ohne E-Mail – zum Weitergeben an die Dozierenden).
- **Teilnahme** (AP-10, E-57): Alle mit Platz gelten als anwesend; eingetragen wird nur „nicht erschienen“ – beim Symposium oder je Workshop („doch da“ nimmt es zurück). Suche und Filter, auf dem Handy bedienbar, alles im Protokoll (Art „Teilnahme“). **Listen:** Unterschriftenliste als PDF – alle (mit Berufsgruppe) oder nur ÄrztInnen mit der EFN als Barcode (Code 128, Inhalt `*EFN*`; ohne EFN ein Feld fürs Etikett), sortiert nach Nachname, Vorname oder Berufsgruppe; CME-Liste (nur Anwesende). **Zertifikate:** Vorschau mit Beispiel und je Person; „Freischalten“ einzeln oder „Alle freischalten“ – erst ab dem Veranstaltungstag, wahlweise ohne Mail; die Person bekommt „Deine Teilnahmebescheinigung ist da“ mit einem Link (30 Tage, einmal) und lädt das PDF unter „Meine Anmeldung“ herunter (mit Konto jederzeit). Wer als „nicht erschienen“ markiert ist, bekommt keins – auch nicht nach der Freischaltung. „Zurücknehmen“ sperrt den Download wieder. **Unterschrift:** PNG mit durchsichtigem Hintergrund (oder JPG auf Weiß), höchstens 4 MB; liegt im Laufzeitordner unter `zertifikat/` und ist Teil der Sicherung. Was sonst auf dem Zertifikat steht (Name der Veranstaltung, Auflage, Punkte, Kategorie, Kammer, VNR, Name und Rolle unter der Unterschrift), steht unter Einstellungen.
- **Statistik:** Kennzahlen, Verlauf, Termine, Berufsgruppen, Institutionen, Essen; Filter nach Berufsgruppe. **Export** je Liste in drei Formaten (E-56): **CSV** (Semikolon, UTF-8 mit BOM, für andere Programme), **Excel** (xlsx: Kopfzeile fixiert und mit Filter, Datum als Datum; braucht die PHP-Erweiterung zip, sonst fehlt der Knopf) und **PDF** (A4 im Branding zum Ausdrucken; Teilnehmende quer und ohne E-Mail, je Termin bzw. Essen eine Seite; nicht für Newsletter): Teilnehmende, Listen und Wartelisten je Termin, Essen, CME (Name, Berufsgruppe, EFN der anwesenden ÄrztInnen), Statistik, Newsletter. Die Kachel „Anwesenheit“ zeigt anwesend / mit Platz und die freigeschalteten Zertifikate. Exporte stehen mit Format im Protokoll.
- **Startseite** (Verwaltung): Texte der Startseite der gewählten Veranstaltung. **Texte** (Betrieb, nur BetreiberInnen): Impressum und Datenschutz.
- **Protokoll:** Filter-Knöpfe nach Art mit Anzahl, Suche (z. B. „Anmeldung 12“). Filter nach Veranstaltung: gewählte, alle (Admins: alle ihre), für BetreiberInnen auch „ohne Veranstaltung“ (Konten, Newsletter, Logins, Betrieb). Admins sehen Anmeldungen, Nachrücken, Programm, Einstellungen, Texte, Exporte, Mailings und Teilnahme ihrer Veranstaltungen, keine Einträge ohne Veranstaltung (AP-12); BetreiberInnen sehen alles und können Einträge **ausblenden** (dann nur noch für BetreiberInnen sichtbar, markiert, wieder einblendbar). Löschen geht nicht von Hand; nach 14 Monaten (Logins nach 90 Tagen) löscht der Job `datenschutz` (Abschnitt 16a).
- **Betrieb** (BetreiberIn): Mailversand mit Testmail, Hostnamen (hinzufügen, Haupt-Host, abschalten), Absender je Postfach (System und Newsletter, vor config.php), Sicherung, Testdaten (nur Staging).

## 14. Newsletter (AP-7)

Listen mit Double-Opt-In, unabhängig von der Anmeldung (E-52).

- **Bestellen:** `/newsletter` (Link in der Fußzeile, Hinweis auf der Startseite): Listen, E-Mail, Vorname (freiwillig), Einwilligung; Spamschutz wie bei der Anmeldung, gleiche Antwort für jede Adresse. Eingetragen ist man erst nach „Bestätigen“ über den Link in der Mail (7 Tage gültig); unbestätigte Anfragen löscht der Job nach 7 Tagen.
- **Häkchen bei der Anmeldung:** bietet die Liste an, die unter Team → Newsletter „Bei der Symposium-Anmeldung anbieten“ hat; nach der Bestätigung der Anmeldung kommt eine eigene Newsletter-Bestätigung.
- **Abmelden und ändern:** in jeder Newsletter-Mail „Abmelden oder Listen ändern“; Mailprogramme zeigen dank List-Unsubscribe einen Knopf „Abbestellen“ (One-Click). Abmelden wirkt sofort und löscht die Adresse; je Liste bleibt nur die Zahl der Abmeldungen. Mit Konto auch unter „Mein Konto“.
- **Team → Newsletter:** Zahlen je Liste, Listen anlegen und ändern (Name, Beschreibung, Absendername, öffentlich wählbar, bei der Anmeldung anbieten), Abonnierende suchen und löschen, einladen (nur per Bestätigungsmail), CSV oder Excel je Liste (mit Zeitpunkt und Fassung der Einwilligung).
- **Versand:** Mailings → Empfänger „Newsletter-Listen“ (eine oder mehrere; jede Person einmal), am besten über newsletter@. Abmeldelink und List-Unsubscribe hängt die App an; ohne Vorname lautet die Anrede „Hallo,“. Archiv der Empfängerliste 14 Monate ab Versand.
- **Datenschutz:** gespeichert werden Adresse, Vorname (falls angegeben), Listen, Zeitpunkt und Fassung der Einwilligung (Wortlaut in der Datenbank), keine IP-Adresse. Die Datenschutzerklärung muss das beschreiben.

## 15. Konten für Teilnehmende (AP-4b)

- **Freiwillig:** Anmelden und Ändern geht weiter ohne Konto über Einmal-Links.
- **Anlegen:** Häkchen „Konto anlegen“ beim Anmelden (Passwort nach dem Bestätigungslink), Kasten „Konto anlegen?“ auf „Meine Anmeldung“ oder ohne Anmeldung unter `/konto/neu` (Bestätigung per Mail; unbestätigte Konten löscht der Job nach 48 h).
- **Einloggen:** ein Login für alle unter `/einloggen` oder im Kasten „Einloggen“ auf der Seite „Anmelden“. Teilnehmende brauchen keinen zweiten Faktor; Team-Konten werden danach nach dem Code gefragt. Passwort mindestens 10 Zeichen (Team 12).
- **Mein Konto** (`/konto`): Profil (Name, Berufsgruppe, Institution, EFN), Newsletter, neue Adresse (gilt nach Bestätigung, die Anmeldung zieht mit), Passwort ändern, Konto löschen – wahlweise mit oder ohne Anmeldung, mit Passwort bestätigt. Team-Konten sehen statt Adresse und Löschen den Kasten „Team-Konto“ mit dem zweiten Faktor; beides erledigt eine BetreiberIn.
- **Löschfrist** (Admin 2026-10-30, E-54): Konten Teilnehmender werden nach **14 Monaten ohne Login** gelöscht; **30 Tage vorher** kommt eine Erinnerung per Mail (Systemabsender). Jeder Login setzt die Frist neu. Gelöscht wird frühestens 30 Tage nach der Erinnerung (Job „anmeldung“). Die Anmeldung bleibt, ohne Konto, per Link erreichbar. Team-Konten sind ausgenommen.
- **Passwort vergessen** (`/konto/passwort-vergessen`): Link per Mail, 48 h, einmal. Nur für Teilnehmende; Team-Passwörter setzt weiter eine BetreiberIn zurück.
- Bestätigtes Konto und Anmeldung mit derselben Adresse werden verknüpft. Eingeloggt anmelden: Angaben vorausgefüllt, keine zweite Bestätigung.
- **Testdaten:** Lea (`lea@example.org`) hat ein Konto; das erfundene Test-Passwort steht in `server/testdaten/beispieldaten.php` (`BEISPIEL_PASSWORT`). Nur auf Staging.

## 16. Betrieb: Sicherung, Wiederherstellung, Testdaten

Team-Bereich → „Betrieb“ (nur BetreiberInnen, E-47; bis 0.8 „Daten“).

- **Sicherung anlegen:** alle Tabellen, die Fotos der Dozierenden, die Bilder der Mailings und Texte und die Unterschrift des Zertifikats als gzip-Datei in `var/sicherungen/` (nie über das Web erreichbar). Ohne Inhalt bleiben Ratenschutz und Mail-Warteschlange.
- **Herunterladen** und **Wiederherstellen** verlangen den eigenen Code aus der App. Heruntergeladene Sicherungen enthalten personenbezogene Daten und Passwort-Hashes: verschlüsselt aufbewahren.
- **Wiederherstellen** ersetzt alles, auch die Team-Konten. Vorher wird der jetzige Stand gesichert. Unvollständige Dateien und Sicherungen aus einem neueren Stand der Anwendung werden abgelehnt; ältere werden nach dem Einspielen per Migration nachgezogen.
- **Testdaten laden** gibt es nur, wenn `umgebung` `staging` oder `entwicklung` ist: ersetzt Programm, Dozierende, Anmeldungen und Veranstaltungs-Einstellungen durch den Beispieldatensatz (`server/testdaten/beispieldaten.php`), Team-Konten bleiben, vorher automatische Sicherung. Danach ist die Anmeldung offen (Veranstaltung in 60 Tagen). Drei Veranstaltungen: Wintersymposium 2027 (aktuell), „Fortbildungsabend klinische Notfallmedizin“ (`/fortbildungsabend-notfallmedizin`, Vortragsabend vor 100 Tagen ohne Essen und Workshops, abgeschlossen) und Wintersymposium 2026 (`/wintersymposium2026`, abgeschlossen); Lea hat alle drei mit Zertifikat im Konto.
- **Mail-Umleitung (Staging):** `mail.umleiten_an` in der `config.php` schickt jede Mail an diese Adresse; der eigentliche Empfänger steht im Betreff. Auf Produktion leer lassen.
- **Automatisch** (AP-9, E-59): Der Job `sicherung` legt alle 3 Stunden (8× am Tag, über den Cron) eine Sicherung mit Anlass „automatisch“ an und räumt danach auf: behalten werden alle der letzten 3 Tage, bis 7 Tage die neueste je Tag, bis 14 Tage die neueste je 3 Tage, bis 28 Tage die neueste je Woche; ältere automatische werden gelöscht. Von Hand angelegte (auch „vor dem Wiederherstellen“, „vor dem Laden der Testdaten“) werden nach 90 Tagen gelöscht. Automatische Sicherungen und ihr Aufräumen stehen nicht im Protokoll. Für länger: herunterladen und verschlüsselt aufbewahren. Auf zusätzliche Sicherungen des Hosters verlassen wir uns nicht. Im Ordner liegen so etwa 30 automatische Sicherungen gleichzeitig.
- Einzelne Sicherungen lassen sich jederzeit über „Löschen“ entfernen.

## 16a. Fristen und Datenschutz (AP-9, E-24, E-59)

Der Job `datenschutz` läuft stündlich (über den Cron) und wendet diese Fristen an:

| Was | Frist | Wie |
|---|---|---|
| Anmeldungen ohne Konto | 14 Monate nach der Veranstaltung | Name, Adresse, Institution, EFN, Freitext geleert, Links gelöscht, Name in den Mailing-Empfängerlisten entfernt; Berufsgruppe, Essen und Plätze bleiben für die Statistik. Im Team-Bereich „(Daten geleert)“. Protokoll-Art „Datenschutz“. |
| Anmeldungen mit Konto | solange das Konto besteht | danach wie ohne Konto |
| Konten Teilnehmender | 14 Monate ohne Login, Erinnerung 30 Tage vorher | E-54, Abschnitt 15 |
| CME-Nachweise | 10 Jahre nach der Veranstaltung | beim Leeren abgelegt: nur anwesende, bestätigte ÄrztInnen einer Veranstaltung mit Fortbildungspunkten (Name, EFN, Veranstaltung, Datum, VNR, Punkte, Kammer). Betrieb → Kasten „CME-Nachweise“ mit Export (nur BetreiberInnen). |
| Protokoll | 14 Monate, Logins 90 Tage | gelöscht |
| Mailing-Empfängerlisten | 14 Monate nach Veranstaltung bzw. Versand | E-50 |
| Mail-Warteschlange | sofort nach dem Versand | Empfänger und Inhalt geleert |
| Sicherungen | automatisch höchstens 28 Tage, von Hand 90 Tage | Abschnitt 16 |

Die Fristen sind feste Regeln aus dem Löschkonzept (E-24, E-59), keine Einstellungen. Ein **Textvorschlag für die Datenschutzerklärung**, abgestimmt auf diese Fristen, liegt in `docs/datenschutz-vorschlag.md` (kein Rechtsrat; Platzhalter füllen, prüfen lassen, unter Texte → Datenschutz einfügen).

## 17. Fehlerprotokoll

PHP-Fehler landen in `var/logs/php-fehler.log` im Zielordner (per FTP lesbar, über HTTP nicht erreichbar).
