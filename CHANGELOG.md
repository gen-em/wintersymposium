# Changelog

Alle nennenswerten Änderungen. Versionen nach [Semantic Versioning](https://semver.org/lang/de/); die aktuelle Version steht in `VERSION`.

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
