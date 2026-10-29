# Changelog

Alle nennenswerten Änderungen. Versionen nach [Semantic Versioning](https://semver.org/lang/de/); die aktuelle Version steht in `VERSION`.

## [0.4.0] – 2026-10-29

AP-3 Schritt 2: Veranstaltungs-Einstellungen.

### Neu

- Reiter „Einstellungen“ im Team-Bereich (Admin und Betreiber): Veranstaltung (Datum, Beginn, Ende, Ort, Gesamtkapazität), Fortbildungspunkte (Anzeige, Punkte, Kammer, Veranstaltungsnummer), Anmeldung (Zeitraum, Workshops je Person, Workshop-Wahl), Nachrücken (Annahmefrist, Grenze), Stichtage für Workshops, Essen und Storno, Berufsgruppen. Ein Katalog in `lib/veranstaltung_lib.php` beschreibt Typ, Startwert und Grenzen jeder Einstellung.
- Prüfung beim Speichern mit Meldung am Feld (auch Zusammenhänge: Ende nach Beginn, Stichtage nicht nach der Veranstaltung); Zeiten in Ortszeit, gespeichert in UTC; Änderungen im Audit-Protokoll.
- Startseite: Eckdaten kommen aus den Einstellungen; Hinweis „Anmeldung ab …“, solange die Anmeldung noch nicht offen ist.
- Browser-Durchlauf: Einstellungsseite mit Fehlerfall und Speichern; Browser mit deutscher Sprache und Zeitzone.

### Geändert

- Team-Reiter brechen auf schmalen Bildschirmen in eine zweite Zeile um, statt rechts aus dem Bild zu laufen.

### Entfernt

- Fester Eckdaten-Platzhalter auf der Startseite (aus 0.2.1).

## [0.3.1] – 2026-10-29

### Behoben

- Bitwarden füllte den Code aus der Authenticator-App in Firefox nicht ein: Die Code-Felder heißen jetzt `totp` statt `code`. Firefox gibt `autocomplete="one-time-code"` nicht an Erweiterungen weiter, Bitwarden sucht dann nach Stichwörtern wie `totp` (Lösung aus dem Familien-Server, dort `docs/erkenntnis-bitwarden-einmalcode.md`).

### Neu

- Browser-Durchlauf prüft auf jeder Seite, dass Code-Felder ein solches Stichwort tragen und kein Passwortfeld sind.

## [0.3.0] – 2026-10-29

AP-3 Schritt 1: Konten und Team-Login.

### Neu

- Team-Login unter `/einloggen` (Link „Team“ in der Fußzeile): Passwort, dann Pflicht-Zweitfaktor mit einer Authenticator-App (TOTP nach RFC 6238). Beim ersten Login Einrichtung mit QR-Code oder Schlüssel zum Abtippen, danach zehn Wiederherstellungscodes (einmal angezeigt, nur als Hash gespeichert). Kein Code gilt zweimal. Kern übernommen aus NAdoku, Passkeys folgen später.
- Team-Bereich `/team` mit den Reitern Übersicht, Mein Konto (Passwort ändern, neue Wiederherstellungscodes) und Team-Konten (nur Betreiber). Name, Rolle und „Ausloggen“ in der Navigation.
- Team-Konten: Konto anlegen mit Einrichtungslink (72 h, einmal benutzbar, wird dem Betreiber angezeigt, solange es keinen Mailversand gibt), Passwort-Link, zweiten Faktor zurücksetzen, sperren, Rolle ändern nur mit eigenem Code. Das eigene Konto ist dort gesperrt, und es bleibt immer ein aktiver Betreiber.
- Ratenschutz für Login, Codes und Einrichtungslinks; gleiche Meldung und Laufzeit für bekannte und unbekannte Adressen.
- Notzugang für Betreiber unter `/notzugang` (O-34): mit dem Installationsschlüssel aus der config.php wird der zweite Faktor eines Betreiber-Kontos zurückgesetzt und ein Passwort-Link angezeigt; 3 Versuche je Stunde, Audit-Eintrag, nicht verlinkt.
- Notweg auf der Kommandozeile: `cli/konto.php` (Liste, Link erzeugen, zweiten Faktor zurücksetzen).
- Browser-Durchlauf (R-12): `tools/browser/einrichten.sh` richtet Chromium, Firefox und WebKit in der Sandbox ein, `tools/browser/durchlauf.js` prüft alle Seiten und Abläufe in den drei Engines auf Handy-, Tablet-, schmaler und breiter Desktopbreite, mit Auswahlfeldern, Aufklapp-Elementen, Popups, Überstand und Konsolenfehlern.
- QR-Code lokal erzeugt: `assets/vendor/qrcode.js` (qrcode-generator 2.0.4, MIT) und `assets/qr.js`, übernommen aus NAdoku.

### Geändert

- Datenbank (Migration 4): eine Tabelle `konto` mit Rollen (Teilnehmend, Admin, Betreiber) ersetzt `team_konto`; vorhandene Konten werden mit gleicher id übernommen und richten beim ersten Login den zweiten Faktor ein. Neue Tabellen `konto_code` (Wiederherstellungscodes) und `konto_link` (Einrichtungs- und Passwortlinks, nur Hash).
- Installer legt den ersten Betreiber in `konto` an und verweist danach auf das Einloggen.
- Login-Seiten sind wie der Team-Bereich von der Wartung ausgenommen.
- Tabellen im Team-Bereich (Klasse `.karten`) werden unter 960 px als Karten dargestellt statt rechts abgeschnitten.
- Aufräum-Job löscht abgelaufene Einrichtungslinks.

- Favicon besser erkennbar (Variante B): Auf hellen Oberflächen füllt das lila Bildzeichen die nachtblaue Kachel fast ganz aus statt nur zu drei Vierteln; bei 16 px war es vorher kaum zu erkennen. Auf dunklen Oberflächen unverändert. `favicon.ico` neu erzeugt.

## [0.2.1] – 2026-10-29

### Geändert

- Konzept: offene Punkte O-23 bis O-32 aus der Mockup-Runde entschieden und als E-42 eingetragen (Nachrücken bei Überschneidung, Statusfarben, grüner Anmelde-Knopf, „Anmelden“/„Einloggen“, Konten mit Rollen, Mailings und Newsletter, EFN-Barcode Code 128, Anwesenheit nur mit Ausnahmen, Empfängerliste im Mail-Archiv); R-02, E-22, E-40 und E-41 angepasst; O-33 (Löschfrist der Empfängerlisten) neu.
- Prüfprotokoll: AP-2 auf Staging abgenommen.
- Konzept: Team-Login (Konten-Tabelle mit Rollen, Passwort, zweiter Faktor) aus AP-5 in AP-3 vorgezogen; O-33 entschieden; Symposium als Abendveranstaltung 18:00–22:00 eingetragen.
- Startseite: Überschrift der Infokästen „Für alle, die da sind, wenn andere frei haben“ statt „… den Piepser tragen“ (passt für Notaufnahme und Rettungsdienst, Nächte und Wochenenden); Migration 3 ändert den Text, solange er noch nicht bearbeitet wurde. Mockup angepasst.
- Startseite: Infokasten „Für wen“ mit neuer Aufzählung der Zielgruppen (Pflegende, NotfallsanitäterInnen, MFA, ÄrztInnen, RettungssanitäterInnen, Studierende und alle Interessierten); ebenfalls über Migration 3.
- Startseite: Infokasten „Wie“ beschreibt die Abendveranstaltung (erst Vorträge, dann Bier, Snacks, Workshops und offene Stationen); ebenfalls über Migration 3.
- Startseite: Infokasten „Was es kostet“: nichts, dazu Snacks, Bier und Alkoholfreies (statt Mittagessen); ebenfalls über Migration 3.
- Startseite: Untertitel „Ein Abend für Rettungsdienst, Notaufnahme und Krankenhaus …“ statt „Ein Tag für …“; ebenfalls über Migration 3.
- Konzept (E-10): Essenswahl „keine speziellen Wünsche / vegetarisch / kein Essen“.
- Mockup und Konzept (E-42): Knopf „Anmelden“ in der Navigation ebenfalls grün.
- Favicon passt sich hellen und dunklen Oberflächen an (wie bei GitHub): hell nachtblaue Kachel mit lila Bildzeichen, dunkel lila Bildzeichen allein; `favicon.ico` als Kachel; SVG wird vor der .ico bevorzugt.
- Startseite: Beispielprogramm als Platzhalter bis AP-3 (ausgedacht, deutlich gekennzeichnet; Figuren aus der Sendung mit der Maus und die Tigerente als Dozierende), mit Programm-Darstellung nach Mockup in `site.css`.
- Startseite: Eckdaten als vorübergehender Platzhalter (Mi 20.01.2027, 18:00–22:00, CME beantragt, freie Plätze 240 / 240; Ort folgt), bis Einstellungen (AP-3) und Platzzählung (AP-4) stehen.

## [0.2.0] – 2026-10-29

AP-2 Design-Mockups und öffentliche Seiten.

### Neu

- Freigegebene Mockups (Variante A „Plakat“) in `docs/mockups/` – Vorlage für alle Seiten.
- Erscheinungsbild Allgäu Akut im Code: `assets/site.css` mit Design-Tokens, Schriften Anton, Antonio, Tajawal und IBM Plex Mono als WOFF2 (selbst ausgeliefert, Lizenzen beigelegt), Logo als SVG, Favicon und App-Symbol.
- Seitenrahmen mit Logo, Navigation (Handy: aufklappbares Menü ohne JavaScript), Sprunglink und Fußzeile mit Impressum, Datenschutz und Quelltext-Link.
- Pflegbare Texte (Tabelle `inhalt`, Migration 2) mit einfacher Auszeichnung; Startwerte aus den Mockups. Bearbeitung folgt im Betreiber-Bereich (AP-5).
- Startseite mit Titel, Sticker, Eckdaten (sobald die Veranstaltungs-Einstellungen aus AP-3 gesetzt sind), Infokästen, Programmabschnitt und CME-Hinweis.
- Seiten `/impressum` und `/datenschutz`.

### Geändert

- Installer und Meldungsseiten im neuen Rahmen.

### Entfernt

- `assets/basis.css` (Übergangs-Stylesheet aus AP-1).

## [0.1.2] – 2026-10-29

### Doku

- Prüfprotokoll: Ergebnisse der Abnahme von AP-1 auf Staging (T-100, T-101, T-105 bis T-110) und verbleibende Prüfungen.
- Konzept: AP-1 auf Staging abgenommen; Hinweis auf das Hoster-Cookie für die Datenschutzerklärung (AP-2).

## [0.1.1] – 2026-10-29

### Behoben

- Auslieferung: Zielpfade vom FTP-Login aus sind `/staging/` und `/produktiv/` (der Zugang landet bereits im Ordner `wintersymposium`); der erste Lauf war mit curl 9 abgebrochen, ohne etwas zu übertragen.
- Auslieferung: Der Schritt „Zustandsdatei sicherstellen“ wertet curl-Fehler jetzt selbst aus (lief vorher mit `bash -e` und stieg ohne Erklärung aus) und meldet verständlich: Zielordner nicht erreichbar, Anmeldung abgelehnt oder anderer Code. Keine Server-Meldungen im öffentlichen Log.

## [0.1.0] – 2026-10-29

AP-1 Grundgerüst und Auslieferung.

### Neu

- Repo-Struktur `server/` (Webroot `server/public/`, Bibliotheken, Seiten, CLI), `tools/tests/`, `docs/betrieb.md`.
- Konfiguration über `config.php` (Vorlage `config.example.php`), DB-Schicht mit PDO ohne emulierte Prepares, Zeiten in UTC, verschachtelungsfeste Transaktionen.
- Migrationskatalog (Schritt 1: Einstellungen, Hosts, Team-Konten, Audit, Ratenschutz, Jobs); Migrationen laufen beim Installieren und automatisch nach einer Auslieferung.
- Installer `/install` mit Installationsschlüssel: legt Tabellen, Haupt-Host und ersten Betreiber an und sperrt sich danach (`var/install.lock`).
- Sitzungen in eigener Ablage (`var/sitzungen`) mit 30 min Leerlauf-Ablauf, CSRF-Schutz, Flash-Meldungen.
- Sicherheits-Kopfzeilen: CSP mit Nonce, HSTS (nur über HTTPS), nosniff, Referrer-Policy, X-Frame-Options, Permissions-Policy; HTTPS-Zwang in `.htaccess` und PHP.
- Mehrere Hostnamen (E-27): erlaubte Hosts in der Tabelle `host`, Basis-URL je Anfrage, fremde Hosts → 421.
- Ratenschutz-Kern (Fehlversuche je Topf und Herkunfts-Hash, Sperre, gleiche Antwortdauer).
- Audit-Protokoll (Tabelle `audit`).
- Wartungsmodus über `var/wartung.lock` (503 für öffentliche Seiten, Team/Health/Installer ausgenommen, Jobs pausieren).
- Health-Endpunkt `/health` mit Token (ohne Token 404).
- Job-Rahmen mit Cron-Auslöser per Kommandozeile (`cli/jobs.php`) oder URL mit Token (`/jobs?token=…`); erster Job „aufraeumen“ (Sitzungsdateien, Ratenschutz).
- GitHub Actions: „Prüfung“ (Syntax, Testlauf gegen MySQL 8.4) bei jedem PR; „Auslieferung“ per FTPS – Push auf `main` → Staging, Tag `vX.Y.Z` → Produktion (Tag muss zu `VERSION` passen und auf `main` liegen).
- Testlauf `tools/tests/lauf.php` (Formate, Hosts, Kopfzeilen, Migrationen, Transaktionen, Ratenschutz, Jobs, Sitzungsablage, HTTP-Durchlauf von Installer bis Wartung).
