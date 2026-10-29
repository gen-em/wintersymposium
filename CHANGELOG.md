# Changelog

Alle nennenswerten Änderungen. Versionen nach [Semantic Versioning](https://semver.org/lang/de/); die aktuelle Version steht in `VERSION`.

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
