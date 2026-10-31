---
dokument: konzept
projekt: wintersymposium
repo: gen-em/wintersymposium
stand: 2026-10-29
phase: umsetzung   # AP-1 und AP-2 abgenommen; AP-3 bis AP-7 umgesetzt, Abnahme auf Staging offen; Editor, ein Konto, Navigation, Löschfrist (E-54) und Passkeys (E-55), Export xlsx/PDF (E-56) AP-10 Teilnahme und Zertifikate (E-57) , AP-11 Mehrere Veranstaltungen (E-58), AP-9 Abschluss und Datenschutz (E-59) und AP-12 Team-Navigation und Rechte (E-60) umgesetzt, Abnahme auf Staging offen; vor dem Anmeldestart Checkliste Abschnitt 9
status: bestaetigt   # Zusammenfassung, Reihenfolge und Entscheidungen E-01 bis E-42 vom Admin bestätigt (2026-10-29), E-43 bis E-45 aus der Umsetzung von AP-3, E-46 aus AP-4, E-47 (Sicherung, Testdaten), E-48 aus AP-4b, E-49 aus AP-5, E-50 aus AP-6, E-51 aus den Rückmeldungen vom 30.10., E-52 aus AP-7; offene Punkte in Abschnitt 7
version: null   # Konzept ohne Version; die Repo-Version legt die Umsetzung fest (Datei VERSION)
verantwortlich: Philipp Chadid
termine:
  symposium: 2027-01-20
  anmeldung_offen_ab: 2026-11-25
---

# Konzept: Webseite Wintersymposium (Allgäu Akut)

## 0. Arbeitsweise für die umsetzende Instanz

- Arbeitspakete (AP) in der Reihenfolge ihrer IDs abarbeiten, soweit `abhaengig_von` nichts anderes zulässt. AP-1 bis AP-6 müssen bis zum 2026-11-25 fertig sein, AP-10 bis zum Symposium (E-17). AP-8 ist entfallen (E-15).
- Vor Beginn eines AP: Umfang kurz zusammenfassen und vom Admin bestätigen lassen. Offene Punkte (Abschnitt 7), die das AP betreffen, vorher klären.
- Nach Abschluss jedes AP:
  - im AP-Block dieses Dokuments `status`, `ergebnis` und `probleme` (Problem und Lösung) eintragen,
  - `docs/pruefprotokoll.md` aktualisieren: was geprüft ist, was noch wie geprüft werden muss,
  - Version (`VERSION`) hochstufen, `CHANGELOG.md` und betroffene Doku aktualisieren (neue und entfernte Funktionen) und auf Konsistenz prüfen,
  - Commit auf einen Arbeits-Branch und PR; der Admin mergt (E-26). Keine ZIP-Ausgabe.
- Keine eigenen Annahmen bei Unklarheiten: nachfragen. Neue Entscheidungen als E-xx in Abschnitt 4 und im Änderungsprotokoll (Abschnitt 11) festhalten.
- Keine Versionsnummer im Konzept festlegen.
- Das Repo wird öffentlich (E-25): keine Zugangsdaten, Kontonamen, IP-Adressen, Serverpfade oder Hosting-Interna in Code, Doku, Commits oder PR-Texten (R-10).

## 1. Ziel und Rahmen

- Webseite für das **Wintersymposium** der Reihe **ALLGÄU AKUT – Fortbildung Notfallmedizin** am **20.01.2027**, als **Abendveranstaltung 18:00–22:00** (erst Vorträge, dann Workshops, offene Stationen, Snacks und Getränke).
- Teilnahme **kostenlos**, keine Bezahlfunktion.
- Bestandteile:
  1. **Startseite** mit Infos und Programm (aus der Programm-Konfiguration erzeugt)
  2. **Anmeldung** mit Workshop-Wahl, Essenswahl und Warteliste; Verwaltung der eigenen Anmeldung über Einmal-Links per Mail oder über ein **optionales Konto** (E-30); Änderungen bis zu einstellbaren Stichtagen (E-31)
  3. **Team-Bereich**: Überblick, **Statistik-Seite** mit allen Auswertungen, Verwaltung der Anmeldungen und des Programms, Export
  4. **Mailings**: Rundmails an Teilnehmende
  5. **Newsletter** mit mehreren Listen, auch unabhängig von einer Symposium-Anmeldung
  6. **Teilnahme und Zertifikate**: Anwesenheit erfassen, CME-Teilnahmebescheinigung als PDF zum Herunterladen (E-22)
  7. **Teamkommunikation** über ein bestehendes Kaneo (E-15); kein Bestandteil dieses Repos
- Design nach dem Erscheinungsbild **Allgäu Akut** (Repo `chodid/zina-branding`), Seitendesign über Mockups (E-23).
- Nicht Teil dieses Konzepts: Bezahlung, Tickets/QR-Codes, zentraler Login (E-12), eine eigene Kaneo-Instanz (E-15), Umstellung bestehender Dienste.

## 2. Ausgangslage

```yaml
hosting_app:
  anbieter: Webspace-Hoster (Name nicht im Repo, R-10)
  art: Webspace mit PHP und MySQL
  cron: vorhanden (Auskunft Admin 2026-10-29)
  bekannt_aus_nadoku_staging:   # docs/Technik.md 6.3a im Repo gen-em/einsatzdoku-luftrettung
    php: "8.3 (FPM, fastcgi_finish_request nutzbar)"
    datenbank: "MySQL 8.4 (nicht MariaDB)"
    max_execution_time: 300
    memory_limit: 512M
    default_socket_timeout: "5 s – betrifft SMTP-Verbindungen"
    sitzungen: "session.save_path geteilt, gc_maxlifetime 1440 s → eigene Ablage und eigenes Aufräumen nötig"
    opcache: "nur Dateicache, opcache_get_status gesperrt, opcache_invalidate wirkt"
    botschutz: "blockierte automatisierte Formular-POSTs (HTTP 403); für Testläufe beim Hoster abschalten lassen"
    ftps: "Deploy-Konto auf sein Verzeichnis beschränkt; Deploy-Action brauchte vorab angelegte Zustandsdatei (ECONNRESET)"
  mail:
    smtp: Hoster, Port 465 (SSL)
    limit: "500 Empfänger je 24 h je SMTP-Postfach (Auskunft des Hosters)"
kaneo:
  bestehend: vorhandenes Kaneo des Admins; wird für das Symposium-Team mitgenutzt (E-15)
domain: winter-notfallsymposium.de (angelegt, Admin 2026-10-29; E-05)
mail_adressen:
  system: anmeldung@winter-notfallsymposium.de (Postfach)
  newsletter: newsletter@winter-notfallsymposium.de (Postfach)
  admin: admin@winter-notfallsymposium.de (Weiterleitung an den Admin)
branding:
  quelle: chodid/zina-branding (4 Regeln/Erscheinungsbild.md, 9 Technik/Gestaltungswerte, 9 Technik/farben-und-schriften.json)
  farben: {nachtblau: "#001E3A", markenblau: "#003C71", lila: "#B48CFF", signalrot: "#FF5A5F", kva_gruen: "#B5BD00"}
  schriften: {titel: Anton, zwischenueberschrift: Antonio, text: Tajawal, zahlen: IBM Plex Mono}
  logo: "vorhanden: Bildzeichen und Logo (gestapelt, Lila/Rot/Nachtblau/Weiß, SVG und PNG) auf main des Branding-Repos unter `1 Logo/` (O-20 erledigt)"
  regel: "ein Akzent je Medium: Lila für Vortrag/Journal Club, Signalrot für Workshop/Fallbesprechung"
vorlage_code:
  repo: gen-em/einsatzdoku-luftrettung (NAdoku, PHP/MySQL, AGPL-3.0)
  urheber: Philipp Chadid (alleiniger Urheber, Auskunft Admin 2026-10-29)
```

## 3. Architektur

```yaml
app:
  sprache: PHP 8.3, ohne Framework, ohne Composer zur Laufzeit (wie NAdoku)
  datenbank: MySQL 8.4 über PDO (Prepared Statements, keine emulierten Prepares), Zeiten in UTC
  oberflaeche: serverseitig gerendertes HTML, wenig JavaScript, mobil zuerst, eigenes CSS mit Design-Tokens aus zina-branding
  mandanten: "eine Installation, mehrere Hostnamen und Absenderadressen konfigurierbar (E-27); vorerst eine Domain"
  bereiche:
    oeffentlich: [Startseite/Programm, Anmeldung, Newsletter-Anmeldung, Impressum, Datenschutz]
    teilnehmende: "Einmal-Link oder Konto-Login (E-30) → eigene Anmeldung ansehen, ändern, stornieren; Nachrück-Angebot annehmen; Zertifikat herunterladen; Newsletter verwalten; mit Konto zusätzlich Profil (E-Mail, Passwort, Berufsgruppe, Institution, EFN); Änderungen nur bis zu den Stichtagen (E-31)"
    team: "Login (Passwort + TOTP oder Passkey) → Übersicht, Statistik-Seite, Anmeldungen, Konten der Teilnehmenden, Programm, Veranstaltungs-Einstellungen, Mailings, Newsletter, Teilnahme"
    betreiber: "zusätzlich Team-Konten, Domains/Absender, SMTP, Rechtstexte, Backups"
  einstellungen:
    veranstaltung: "Anmeldezeitraum, Gesamtkapazität, Workshop-Limit je Person, Workshop-Wahl Pflicht/freiwillig, Nachrück-Annahmefrist, Grenze für automatisches Nachrücken (E-19, E-20); Stichtage für Workshop-Änderung, Essens-Änderung und Storno (E-31); Veranstaltungsinfos inkl. CME-Angaben (E-22)"
    betreiber: "Hostnamen, Absenderadressen je Zweck/Liste, SMTP-Postfächer, Tageslimit je Postfach (E-13, E-27)"
  hintergrund: "Cron ruft einen Job-Endpunkt (CLI oder Token-URL) auf; Mail-Warteschlange, Nachrück-Angebote und -Fristen, Aufräumen, Ablauf von Links, Löschfristen"
  auslieferung: "GitHub Actions → FTPS auf den Webspace (E-32, E-35): Push auf main → Staging, Tag → Produktion, getrennte Ordner; Webroot ist der Unterordner public/ (E-34); config.php und Laufzeitordner var/ bleiben unangetastet"
kaneo:
  nutzung: bestehendes Kaneo, eigener Arbeitsbereich/eigenes Projekt für das Symposium-Team; der Admin lädt ein (E-15)
  login: Kaneo-Login (E-Mail-Code), getrennt von der Webseite (E-12)
mail:
  postfaecher: [System-Postfach (Bestätigungen, Links, Nachrücken, Zertifikate), Newsletter-Postfach (Rundmails, Newsletter)]
  versand: Warteschlange mit Prioritäten und Tageslimit je Postfach
```

## 4. Entscheidungen

| ID | Thema | Entscheidung | Begründung / Quelle |
|---|---|---|---|
| E-01 | Eigenbau oder fertige Lösung | **Eigenbau**; pretix u. ä. verworfen | Kern ist die Warteliste je Workshop-Termin mit Nachrücken; pretix führt Workshops als Zusatzprodukte, für die es nach bisherigem Kenntnisstand keine Warteliste gibt (nicht abschließend geprüft); großer Unterbau mit vielen unnötigen Funktionen. Admin 2026-10-29 |
| E-02 | Technik | PHP 8.3 / MySQL 8.4 ohne Framework, Bausteine aus NAdoku (E-18) | läuft auf einem Webspace ohne eigenen Server; erprobte Bausteine vorhanden |
| E-03 | Hosting App | Webspace mit PHP, MySQL und Cron beim bestehenden Hoster | Admin 2026-10-29 |
| E-04 | Repo und Lizenz | `gen-em/wintersymposium`, **AGPL-3.0**; Code aus NAdoku darf übernommen werden | Admin 2026-10-29 (alleiniger Urheber von NAdoku) |
| E-05 | Domain | **`winter-notfallsymposium.de`**, angelegt beim Hoster; Staging unter `test.winter-notfallsymposium.de` (E-28) | Admin 2026-10-29 (O-01 entschieden) |
| E-06 | Design-Grundlage | Erscheinungsbild Allgäu Akut (Farben, Schriften, Logo); Schriften selbst ausgeliefert, kein CDN | zina-branding |
| E-07 | Teilnehmende | **kein Konto nötig**: Verwaltung über Einmal-Links per Mail; Token nur als Hash gespeichert, mit Ablauf. Ein Konto ist optional (E-30) | Admin 2026-10-29; Muster aus NAdoku (Reset-Token, Token gegen Sitzung tauschen). Präzisiert 2026-10-29: ursprünglich „kein Konto, kein Login“, durch E-30 ergänzt |
| E-08 | Programm | frei konfigurierbar: Zeitfenster mit beliebigen, auch unterschiedlichen/überlappenden Zeiten; Workshops; Termine je Workshop; Plätze je Termin; Gesamtkapazität | Admin 2026-10-29 (Planungsstand: ca. 3 Workshops, 5 Zeitfenster) |
| E-09 | Warteliste | Warteliste **je Workshop-Termin** (und für die Gesamtkapazität, falls gesetzt) mit Nachrücken nach E-19 | Admin 2026-10-29 |
| E-10 | Essen | Snacks am Abend; Auswahl **keine speziellen Wünsche / vegetarisch / kein Essen**; keine Allergien, kein Freitext. Essens-Stichtag (E-31) und Essensliste bleiben für die Planung | Admin 2026-10-29 (Auswahl angepasst nach Umstellung auf Abendveranstaltung); keine Gesundheitsdaten (Datensparsamkeit) |
| E-11 | Rollen | **Teilnehmende** (ohne Konto per Einmal-Link oder mit optionalem Konto nach E-30, ohne zweiten Faktor), **Admin** (Programm, Einstellungen der Veranstaltung, Anmeldungen, Mailings, Newsletter, Teilnahme), **Betreiber** (zusätzlich Team-Konten, Domains/Absender, SMTP, Rechtstexte, Backups); Admin und Betreiber nur mit **Passwort + TOTP oder Passkey** | Admin 2026-10-29 |
| E-12 | Login-Architektur | **getrennte Logins** für Webseite und Kaneo; kein zentraler Anmeldedienst (Keycloak/Pocket ID geprüft und verworfen) | Admin 2026-10-29. Kaneo könnte OIDC (`CUSTOM_OAUTH_*`), ein eigener Anmeldedienst wäre aber zusätzliche Last und Abhängigkeit |
| E-13 | Mailversand | SMTP über den Hoster; Warteschlange mit **einstellbarem Tageslimit** (Vorschlag 450 je Postfach); **System-Mails vor Rundmails/Newsletter**; zwei Postfächer: `anmeldung@` (System) und `newsletter@` (Newsletter, Rundmails), dazu `admin@` als Weiterleitung an den Admin (Reply-To, Impressum); Wechsel zu einem EU-Versanddienst nur per Konfiguration | Admin 2026-10-29: kein externes Tool, Versand über mehrere Tage verteilen; Postfächer angelegt (O-02 erledigt) |
| E-14 | Newsletter | **mehrere Listen** (mindestens „Wintersymposium“ und „Allgäu Akut“); Anmeldung auch ohne Symposium-Anmeldung; Double-Opt-In; Abmeldelink und `List-Unsubscribe`; Häkchen bei der Symposium-Anmeldung freiwillig und nicht vorausgewählt | Admin 2026-10-29; UWG § 7, DSGVO |
| E-15 | Kaneo | **bestehendes Kaneo** wird mitgenutzt: eigener Arbeitsbereich bzw. eigenes Projekt für das Symposium-Team, Einladung durch den Admin; **keine eigene Instanz**, kein eigenes Branding | Admin 2026-10-29 (ersetzt die zuvor gewünschte eigene Instanz, Entscheidung am selben Tag geändert) |
| E-16 | Verantwortlicher | Philipp Chadid (Impressum, Datenschutzerklärung) | Admin 2026-10-29 |
| E-17 | Zeitplan | AP-1 bis AP-6 bis **2026-11-25** (Anmeldestart); AP-7 (Newsletter) danach; AP-10 (Teilnahme, Zertifikate) bis zum **20.01.2027**; AP-9 zuletzt | Admin 2026-10-29 |
| E-18 | Übernahme aus NAdoku | siehe Tabelle unten | Analyse 2026-10-29 |
| E-19 | Nachrücken | Freier Platz wird der ersten Person der Warteliste **angeboten**, Annahme über Einmal-Link innerhalb einer **Annahmefrist von 48 h**; bei Ablauf oder Ablehnung geht das Angebot an die nächste Person. Automatische Angebote **bis 14 Tage vor dem Symposium** (letztes Angebot spätestens 16 Tage vorher, damit die Frist vor der Grenze endet); danach nur noch manuell durch das Team. Frist und Grenze in den Veranstaltungs-Einstellungen einstellbar. Wer die Frist verstreichen lässt, kommt **ans Ende der Warteliste** dieses Termins und wird per Mail informiert | Admin 2026-10-29 (O-17 entschieden) |
| E-20 | Workshop-Regeln | **Überschneidende Termine gesperrt**; **kein Limit** an Workshops je Person als Standard, Limit in den Veranstaltungs-Einstellungen einstellbar; Workshop-Wahl freiwillig (einstellbar); Gesamtkapazität optional (0 = unbegrenzt) | Admin 2026-10-29 |
| E-21 | Anmeldefelder | Vorname, Nachname, E-Mail, **Berufsgruppe** (Auswahl, Pflicht: ÄrztIn, NotfallsanitäterIn, RettungssanitäterIn, Pflege/MFA, StudentIn, Sonstige mit optionalem Freitext; Schreibweise nach E-37), **Klinik/Institution** (Pflicht), EFN nur bei Ärztin/Arzt (E-22). Bei Konto (E-30) werden Berufsgruppe und Institution ins Profil übernommen und sind dort änderbar | Admin 2026-10-29 (O-15 entschieden) |
| E-22 | Fortbildungspunkte (CME) | Symposium wird zertifiziert; **EFN-Feld freiwillig**, nur bei Berufsgruppe Ärztin/Arzt abgefragt und angezeigt (15-stellig, Prüfziffer geprüft); Punkte, Kammer, Veranstaltungsnummer werden in den **Veranstaltungsinfos** (Einstellungen) gepflegt; das Team **erfasst die Teilnahme** (Anwesenheit: Standard anwesend, nur Ausnahmen eintragen, E-42); danach ist ein **Teilnahmezertifikat als PDF** mit eigenem Namen über Einmal-Link oder Konto herunterladbar. **Den Zertifikat-Entwurf liefert der Admin** (fertiges Layout); die App bringt nur Name, Datum und ggf. EFN darauf (O-19) | Admin 2026-10-29. Meldeweg an die Kammer: O-16 |
| E-23 | Seitendesign | **Mockups vor der Umsetzung**, Freigabe durch den Admin (AP-2); **Lila als Hauptakzent**, bewusst frech; einzelne Seiten in **Signalrot** (nach der Markenregel passend für Workshop-Inhalte); Logo vorhanden | Admin 2026-10-29 |
| E-24 | Löschfristen | **Konten:** Teilnehmende nach **14 Monaten ohne Login** gelöscht, Erinnerung 30 Tage vorher, Login setzt die Frist neu; Team-Konten bleiben bestehen (geändert 2026-10-30, E-54; vorher: keine automatische Löschung). Löschung jederzeit durch die Person selbst oder das Team. CME-Nachweise (Name, EFN, Anwesenheit): solange das Konto besteht, sonst **10 Jahre**, getrennt aufbewahrt. Anmeldungen **ohne Konto**: **14 Monate nach dem Symposium anonymisieren** (Name, E-Mail, Institution, EFN entfernen; Zahlen für die Statistik bleiben) – so können Wiederkehrende beim nächsten Symposium ihre Daten per Einmal-Link an die bekannte Adresse übernehmen, wer nicht wiederkommt, ist danach weg | Admin 2026-10-29 (O-18, O-21 entschieden) |
| E-25 | Repo-Sichtbarkeit | **öffentlich**, mit Trennung: keine Zugangsdaten, Kontonamen, IP-Adressen, Serverpfade oder Hosting-Interna im Repo (R-10); Betriebsangaben bleiben beim Admin (privat); Quelltext-Link in der Fußzeile der Webseite | Admin 2026-10-29 („wenn trennbar, gerne public“). Konzept entsprechend bereinigt |
| E-26 | Ausgabe der Arbeit | Commit auf Arbeits-Branch und **PR**, der Admin mergt; keine ZIP-Dateien | Admin 2026-10-29 |
| E-27 | Domains und Absender | **vorerst eine Domain**; in der Betreiberoberfläche **mehrere Hostnamen und Absenderadressen** konfigurierbar, Absender je Zweck (System, Newsletter) und je Newsletter-Liste wählbar | Admin 2026-10-29 |
| E-28 | Staging | Testumgebung unter `test.<domain>` mit eigener Datenbank und eigenem Webspace-Ordner | Admin 2026-10-29 |
| E-29 | Kaneo-Subdomain | entfallen (E-15: bestehendes Kaneo, keine eigene Subdomain) | Admin 2026-10-29 |
| E-30 | Optionales Konto für Teilnehmende | Registrierung **möglich, nicht nötig**: Konto mit E-Mail und Passwort (kein zweiter Faktor, der bleibt Admin/Betreiber), Passwort-Zurücksetzen per Mail. Profil: E-Mail (neue Adresse erst nach Bestätigung gültig), Passwort, Berufsgruppe, Institution, EFN, Anmeldung, Workshops, Essen, Newsletter. Konto kann **beim Anmelden, nachträglich über den Einmal-Link oder ohne Symposium-Anmeldung** (z. B. nur Newsletter) angelegt werden. Ohne Konto bleibt alles über Einmal-Links erreichbar | Admin 2026-10-29 |
| E-32 | Auslieferung (FTP) | GitHub-Secrets `FTP_SERVER`, `FTP_USER`, `FTP_PASSWORD` sind im Repo angelegt (Werte nur dort). Zielordner auf dem Webspace: `/wintersymposium/staging` (erreichbar als `test.winter-notfallsymposium.de`) und `/wintersymposium/produktiv` (`winter-notfallsymposium.de`). Push auf `main` → Staging automatisch; Produktion über ein Tag (E-35). Ein FTP-Zugang für beide Ziele; er landet im Ordner `wintersymposium`, die Pfade vom FTP-Login aus sind daher `/staging` und `/produktiv`. Laufzeitordner und `config.php` je Ziel auf der Ausnahmeliste | Admin 2026-10-29 (Secrets und Ordner angelegt; FTP-Sicht nachgetragen nach der ersten Auslieferung) |
| E-33 | Plan B Konten | Reicht die Zeit für AP-4b nicht bis zum 25.11., startet die Anmeldung ohne Konten (nur Einmal-Links); Konten folgen direkt danach und werden nachträglich über den Einmal-Link angelegt | Admin 2026-10-29 (bestätigt) |
| E-31 | Stichtage für Teilnehmende | In den Veranstaltungs-Einstellungen getrennte Stichtage, ab denen Teilnehmende (mit Konto und per Einmal-Link) nichts mehr ändern können: **Anmeldeschluss**, **Workshop-Änderungen**, **Essens-Änderungen**, **Storno**. Das Team kann danach weiterhin alles ändern; die Oberfläche zeigt den Stichtag an | Admin 2026-10-29 |
| E-34 | Webroot | Die Domains zeigen auf den Unterordner **`public/`** des jeweiligen Zielordners (`/wintersymposium/staging/public`, `/wintersymposium/produktiv/public`). Bibliotheken, `config.php` und der Laufzeitordner `var/` liegen außerhalb des Webroots; zusätzlich sperrt eine `.htaccess` den Zielordner, falls die Zuordnung doch auf ihn zeigt | Admin 2026-10-29 |
| E-35 | Freigabe Produktion | Produktion wird über ein **Tag `vX.Y.Z`** ausgeliefert (Admin ist Tags gewohnt). Der Lauf bricht ab, wenn das Tag nicht zur Datei `VERSION` passt oder nicht auf `main` liegt. Environment „produktiv“ in GitHub; ein Pflicht-Prüfer dort ist optional | Admin 2026-10-29 (ersetzt den Vorschlag „manueller Lauf“ aus E-32) |
| E-36 | Gestaltung | **Variante A „Plakat“** aus den Mockups: dunkler Modus, Lila als Hauptakzent, Workshop-Inhalte in Signalrot, Sticker/Zettel/Klebeband nach Erscheinungsbild; Varianten B und C verworfen. Mockups liegen in `docs/mockups/` und sind Vorlage für alle Seiten (öffentlich, Teilnehmende, Team) | Admin 2026-10-29 (Mockups freigegeben) |
| E-37 | Sprache | **Du-Ansprache überall** (Seiten, Formulare, Mails); Rechtstexte neutral. Gendern mit **Binnen-I** („ÄrztInnen“, „NotfallsanitäterIn“) nach Erscheinungsbild | Admin 2026-10-29 |
| E-38 | Workshop-Termine und Warteliste | Workshops haben **eigene Termine mit freier Dauer** (keine festen Runden; z. B. 90, 45, 60 min). Je Workshop **ein fester Termin**; für **weitere Termine desselben Workshops** darf man auf die Warteliste. Feste Termine dürfen sich nicht überschneiden (E-20). Nimmt jemand ein Nachrück-Angebot an und hat schon einen anderen Termin desselben Workshops, zeigt die App den **Tausch** (alter Termin wird storniert, neuer gebucht) und führt ihn erst nach **aktiver Bestätigung** aus; der frei gewordene Platz geht sofort ans Nachrücken. Ohne anderen Termin entfällt der Tausch-Hinweis. Anzeige für Teilnehmende: Status je Termin (Platz bestätigt / Warteliste mit „Platz X von Y“). Überschneidung des Angebots mit einem anderen festen Workshop: O-23 | Admin 2026-10-29 (Mockup-Kommentare) |
| E-39 | Programm-Modell und Pflege | Typen: **Vortrag (VT)**, **Fallbesprechung (FB)**, **Workshop (WS**, mit Terminen, Plätzen je Termin, Leitung optional, Notiz vom Team optional, „Bring“ optional), **Offene Station** (parallel, ohne Anmeldung), **Workshop-Block** (nur Zeitfenster; sammelt automatisch alle Termine und offenen Stationen, die in seiner Zeit liegen), **Pause/Info**. **Dozierende** als eigene Liste (Name, Funktion, Kurzbio, Foto optional), mehreren Punkten zuordenbar. Pflege im Team-Reiter „Programm“: Tagesplan nach Uhrzeit mit Zieh-Griffen; neue Punkte erscheinen oben hell-lila und werden an ihre Stelle gezogen (Uhrzeit wird vorgeschlagen). **Speichern ≠ Veröffentlichen**: Status je Punkt „veröffentlicht“, „gespeichert, nicht veröffentlicht“ (im Plan markiert), „neu“; roter Banner, solange Unveröffentlichtes besteht; Prüfungen beim Speichern (Plätze nicht unter vergebene, Raum doppelt belegt) | Admin 2026-10-29 (Mockup-Kommentare) |
| E-40 | Öffentliche Seiten und Einstieg | Eckdaten-Band mit Datum, Zeit, Ort, CME und **freien Plätzen „X / Y“** (Gesamtkapazität aus den Einstellungen). Programm mit **Popups** für Workshops (Termin, Plätze, Beschreibung, Anmelden) und Vorträge (Dozierende); Workshop-Blöcke als Zeitraster auf dem Desktop, als Liste auf dem Handy. **Einstiegsseite** „Deine Anmeldung“: neu anmelden / Einmal-Link per Mail / Login mit Konto (Team nutzt denselben Login + zweiten Faktor). Nach dem Erkennen zeigt die Navigation Name (und Rolle) und „Ausloggen“ statt „Einloggen“ (E-42). Auf „Deine Anmeldung“: Fristen direkt an den jeweiligen Knöpfen, eigener Kasten „Deine Daten“ zum Bearbeiten | Admin 2026-10-29 (Mockup-Kommentare) |
| E-41 | Team-Bereich | Reiter: Übersicht, Statistik, Personen, Workshops, Programm, Mailings, Newsletter, Teilnahme, Einstellungen, Betrieb (nur Betreiber). **Personen**: Suche/Filter, Anmeldung bearbeiten (auch nach Stichtagen), Einmal-Link/Passwort-Link senden, sperren, **Rollenwechsel nur nach Bestätigung mit dem eigenen zweiten Faktor**, Gefahrenzone (Storno, Konto löschen). **Workshops**: je Termin Teilnehmende und Warteliste bearbeiten (entfernen, auf Warteliste, verschieben, Platz vergeben); Personen nur aus vorhandenen Anmeldungen wählbar; **Überbuchen nur mit aktiver Bestätigung** (auch über „Personen“). **Statistik**: Kennzahlen, Verlauf, Termine, Berufsgruppen, Institutionen, Essen, Newsletter; Exporte zentral in einer Export-Kachel. **Mailings**: Editor mit Bildern (**selbst gehostet, in die Mail eingebettet**), eingeschränktes HTML (Liste erlaubter Elemente), Vorschau, Testmail, Planen; Archiv gesendeter Mails (Text, Auswahl und Empfängerliste, E-42). **Teilnahme**: PDF-Listen – Unterschriftenliste (EFN-Spalte mit Barcode bzw. Feld für Etikett nur bei der Liste „Nur ÄrztInnen“), Teilnehmenden- und Wartelisten je Termin, Sortierung wählbar | Admin 2026-10-29 (Mockup-Kommentare) |
| E-42 | Klärungen aus der Mockup-Runde | **Nachrücken bei Überschneidung** (O-23): Auf die Warteliste eines Termins darf man auch, wenn er sich mit einem eigenen festen Termin überschneidet; beim Annehmen des Angebots zeigt die App, welcher Termin dafür storniert wird, und führt das erst nach aktiver Bestätigung aus (wie im Mockup); der frei gewordene Platz geht sofort ans Nachrücken. **Statusfarben** (O-24): Grün = Platz bestätigt, Rot = Warteliste – bewusste Ausnahme von der Markenregel „keine Ampelfarben“, immer mit Text. **Anmelde-Knopf** (O-25, O-26): „Jetzt anmelden“ und der Knopf „Anmelden“ in der Navigation sind grün (Ausnahme von „Grün nur als Erkennungszeichen“); „Anmelden“ heißt nur die Anmeldung zum Symposium, das Einloggen ins Konto heißt durchgehend „Einloggen“ (Navigation nach dem Erkennen: „Abmelden“ → „Ausloggen“). **Daten nach dem Symposium** (O-27): Name und EFN bleiben änderbar. **Konten** (O-28): eine gemeinsame Konten-Tabelle mit Rollen (Teilnehmende, Admin, Betreiber) statt getrennter Team-Konten; Umbau mit AP-4b/AP-5. **Mailings und Newsletter** (O-29): ein gemeinsamer Editor unter „Mailings“ mit Wahl der Empfänger (Angemeldete, Warteliste, Workshop-Termin, Newsletter-Liste); Reiter „Newsletter“ verwaltet nur Listen und Abonnierende (Double-Opt-In). **EFN-Barcode** (O-30): Code 128, Inhalt `*EFN*` (Sternchen davor und dahinter, wie in der bewährten Tabellen-Vorlage des Admins), Klartext darunter; die App erzeugt den Barcode selbst, ohne externen Dienst (E-06). **Anwesenheit** (O-31): Standard „anwesend“ für alle mit festem Platz; das Team trägt nur Ausnahmen „nicht erschienen“ ein; Nachweis bleibt die Unterschriftenliste. **Empfängerliste** (O-32): Zu jeder gesendeten Rundmail wird die Liste der einzelnen EmpfängerInnen gespeichert (Nachweis bei Rückfragen); Löschfrist wie die übrigen Anmeldedaten nach E-24 (O-33) | Admin 2026-10-29 |
| E-43 | Team-Login (AP-3 Schritt 1) | **Ablauf:** Passwort, dann Code aus der Authenticator-App (TOTP, 30 s, 6 Stellen) oder ein Wiederherstellungscode; beim ersten Login Pflicht-Einrichtung mit QR-Code und Schlüssel zum Abtippen, danach zehn Wiederherstellungscodes (nur einmal angezeigt, nur als Hash gespeichert). Kein Code gilt zweimal. Passkeys seit E-55 (ersetzen Passwort und Code). **Schlüssel für das TOTP-Geheimnis:** per HKDF aus dem vorhandenen `geheimnis` in config.php abgeleitet, kein zusätzlicher Eintrag; wer das Geheimnis tauscht, muss den zweiten Faktor aller Team-Konten neu einrichten (Wiederherstellungscodes gelten weiter). **Neue Team-Mitglieder:** Ein Betreiber legt das Konto an und bekommt einen Einrichtungslink angezeigt (72 h gültig, einmal benutzbar), den er selbst weitergibt, solange es keinen Mailversand gibt (AP-6). Derselbe Weg dient als Passwort-Reset. **Team-Konten (nur Betreiber):** Link erzeugen, zweiten Faktor zurücksetzen, sperren, Rolle ändern (mit eigenem Code, E-41); das eigene Konto nicht, und es bleibt immer ein aktiver Betreiber. Passwortwechsel, Sperre, Rollenwechsel und Zurücksetzen beenden alle Sitzungen des Kontos. **Notzugang** (O-34): `/notzugang` im Browser mit dem Installationsschlüssel aus config.php und der Adresse eines Betreiber-Kontos setzt dessen zweiten Faktor zurück und zeigt einen Passwort-Link (3 Versuche je Stunde, Audit, nicht verlinkt); zusätzlich `php cli/konto.php` für Server mit Kommandozeile. Ein Betreiber reicht (Admin 2026-10-29). **Adressen:** `/einloggen`, `/team`, `/team/profil` („Mein Konto“), `/team/konten`; Link „Team“ in der Fußzeile | Umsetzung 2026-10-29 (AP-3 Schritt 1) |
| E-44 | Veranstaltungs-Einstellungen (AP-3 Schritt 2) | Reiter „Einstellungen“ für Admin und Betreiber, gruppiert: **Veranstaltung** (Datum, Beginn, Ende, Ort, Gesamtkapazität; 0 = unbegrenzt), **CME** (Anzeige in den Eckdaten, Punkte, Kammer, Veranstaltungsnummer), **Anmeldung** (Anmeldung ab, Anmeldeschluss = Ende des Anmeldezeitraums, leer = bis Beginn; Workshops je Person, 0 = kein Limit; Workshop-Wahl freiwillig/Pflicht), **Nachrücken** (Annahmefrist in Stunden, Grenze in Tagen vor der Veranstaltung), **Stichtage** (Workshops, Essen, Storno; jeweils bis einschließlich des Tages, leer = bis zur Veranstaltung), **Berufsgruppen** (je Zeile; die erste fragt die EFN ab). Startwerte: 20.01.2027, 18:00–22:00, Ort leer (steht noch nicht fest, Admin 2026-10-29), Kapazität 240, „Punkte beantragt“, Anmeldung ab 25.11.2026 00:00, 48 h, 14 Tage, Berufsgruppen nach E-21. Eingabe in Ortszeit, gespeichert in UTC; Prüfung auf Zusammenhänge (Ende nach Beginn, Stichtage und Anmeldestart nicht nach der Veranstaltung). Änderungen erscheinen sofort auf der Startseite und stehen im Audit-Protokoll. Die Werte zur Anmeldung wirken mit AP-4 | Umsetzung 2026-10-29 (AP-3 Schritt 2) |
| E-45 | Programm-Pflege und -Anzeige (AP-3 Schritt 3) | Umsetzung von E-39/E-40. **Datenmodell:** Arbeitsstand in Tabellen (programmpunkt, workshop_termin, dozent, punkt_dozent), veröffentlichter Stand als Schnappschuss (programm_stand); Status je Punkt durch Vergleich mit dem Schnappschuss („veröffentlicht“, „gespeichert · nicht veröffentlicht“, „neu“, gelöscht). **Verwerfen** stellt den Schnappschuss mit denselben IDs wieder her. **Uhrzeit** optional beim Speichern (neue Punkte stehen oben und werden gezogen), Pflicht zum Veröffentlichen. **Ziehen** (geändert, Admin 2026-10-29): Einfügen zwischen zwei Punkte mit grüner Linie; Beginn = Ende des Punkts darüber (direkt unter einem Block: dessen Beginn); Dauer bleibt (ohne Dauer 30 min); nachfolgende Punkte der Hauptspur (Vortrag, Fall, Pause, Block) rücken nach hinten, bis eine Lücke reicht; ein Block nimmt die Termine und Stationen in seiner Zeit mit; ohne Skript über die Felder. **Block** sammelt Termine und Stationen, die ganz in seiner Zeit liegen. **Hinweise** statt Sperre bei doppelt belegtem Raum. **Dozierenden-Fotos** optional, mit Einverständnis; auf 480 px quadratisch verkleinert, ohne Metadaten, unter zufälligem Namen in var/bilder, ausgeliefert über /bild/. **Startseite:** Popups als `<dialog>`; Zeitraster ab 760 px, darunter Liste; ohne Skript immer die Liste. Das Beispielprogramm aus AP-2 ist entfernt; ohne veröffentlichtes Programm erscheint ein Hinweis. Belegte Plätze zählen ab AP-4 (bis dahin 0) | Umsetzung 2026-10-29 (AP-3 Schritt 3) |
| E-46 | Anmeldung, Warteliste und Nachrücken (AP-4) | Antworten des Admins 2026-10-29: **(1) Mailversand** – die Grundlage aus AP-6 (SMTP-Client, Warteschlange mit Priorität und Tageslimit, Wiederholungen, Vorlagen der Anmelde-Mails) kommt mit AP-4; Zugangsdaten vorerst in `config.php` (Abschnitt `mail`), Betreiber-Oberfläche mit AP-5; Rundmails, Editor und Archiv bleiben AP-6. Mails gehen nach der Antwort an den Browser raus (gleiche Antwortzeit, R-03) und spätestens beim nächsten Job-Lauf; nach dem Versand werden Empfänger, Betreff und Text geleert (R-02). **(2) Bestätigung** – eine neue Anmeldung hält ihre Plätze, bis die Person den Link in der Mail anklickt; Frist als Einstellung „Bestätigungsfrist“ (Start 48 h – Link 48 h gültig und Platz so lange reserviert, Admin 2026-10-29), danach verfällt sie und die Plätze gehen ans Nachrücken. Unbestätigte stehen auf Wartelisten, bekommen aber erst nach der Bestätigung Angebote. **(3) Einmal-Links** gelten 48 h und einmal; erst der Klick auf „Weiter“ verbraucht sie (Mail-Scanner öffnen Links vorab); danach eine Sitzung mit 30 min Leerlauf. **(4) Team** – in AP-4 nur eine Liste zum Lesen (Reiter „Anmeldungen“); Verwaltung mit AP-5. **(5) Warteliste des Symposiums** – wer dort steht, bucht noch keine Workshops; nach dem Annehmen wählt die Person aus den freien Plätzen. **(6) Gleiche Adresse** – keine zweite Anmeldung, gleiche Antwort; an die Adresse geht der Link zur bestehenden. **Aus der Umsetzung:** eine Anmeldung je Adresse; Plätze beziehen sich auf den veröffentlichten Stand des Programms (keine Bindung an die Tabellenzeilen, weil „Verwerfen“ sie neu anlegt); Veröffentlichen lehnt ab, wenn ein Termin mit vergebenen Plätzen fehlt oder zu klein wird, und räumt Wartelisten entfallener Termine auf; alle Platzvergaben unter einer Sperre in einer Transaktion (R-04); Warteliste nur für volle Termine; das Workshop-Limit zählt feste Plätze und Wartelisten; Storno und verfallene Bestätigung löschen die Anmeldung (R-02); wer ein Angebot für das Symposium ablehnt, ist abgemeldet; EFN mit Prüfziffer nach Luhn, angezeigt in Fünfergruppen; Newsletter-Häkchen speichert nur den Wunsch (Bestätigung mit AP-7); Spamschutz wie NAdoku (Honigtopf, Zeitstempel ≥ 4 s, Rechenaufgabe 16 Bit, Ratenschutz, Wegwerfadressen) – das Absenden braucht JavaScript; Navigation mit grünem „Anmelden“, „Deine Anmeldung“ und nach dem Link Name und „Ausloggen“. **Nicht umgesetzt:** die Zeitleiste „Dein Tag“ unter der Workshop-Wahl aus dem Mockup (die Sperre bei Überschneidung zeigt den Grund direkt am Termin); „Konto anlegen“ kommt mit AP-4b | Admin 2026-10-29; Umsetzung 2026-10-29 (AP-4) |
| E-47 | Sicherung, Wiederherstellung und Testdaten | Team-Bereich → **Daten** (nur Betreiber). **Sicherung:** alle Tabellen (Aufbau und Inhalt, ohne Ratenschutz und Mail-Warteschlange) und die Fotos der Dozierenden als gzip-Datei in var/sicherungen/, nicht über das Web erreichbar; Liste mit Datum, Anlass, Größe. **Herunterladen und Wiederherstellen** nur mit dem eigenen Code aus der App (wie E-41). **Wiederherstellen** ersetzt alles, auch die Team-Konten, durch den Stand der Sicherung; vorher automatische Sicherung des jetzigen Stands; unvollständige Dateien und Sicherungen aus einem neueren Stand werden abgelehnt, ältere mit den Migrationen nachgezogen. Auch auf Produktion nutzbar (Teil von AP-9, T-201). **Testdaten laden** nur auf Staging und in der Entwicklung, nie auf Produktion: ersetzt Programm, Dozierende, Anmeldungen und Veranstaltungs-Einstellungen durch den Beispieldatensatz (server/testdaten/, wird mit ausgeliefert); Team-Konten bleiben; vorher automatische Sicherung. **Mail-Umleitung:** `mail.umleiten_an` in config.php schickt alle Mails an eine Adresse, der eigentliche Empfänger steht im Betreff (für Staging). **Mail-Stand und Testmail** (Admin 2026-10-29, nach „es kommt keine Mail an“): Kasten „Mailversand“ unter Daten mit Einrichtung, Absender, Warteschlange, letztem Fehler und „Testmail an mich“ (sofort, zeigt die Antwort des Mailservers). **Version** außerhalb der Produktion in der Fußzeile | Admin 2026-10-29 (Fragen 1–4: Sicherung auch auf Produktion, Mail-Umleitung, Wiederherstellen ersetzt alles, eigener PR) |
| E-48 | Konten für Teilnehmende (AP-4b) | Antworten des Admins 2026-10-29: **(1) Ein Login für alle** unter `/einloggen` und im Kasten „Einloggen“ auf „Deine Anmeldung“; Team-Konten danach mit zweitem Faktor, Teilnehmende direkt in ihr Konto. **(2)** Beim Anmelden nur das Häkchen „Konto anlegen“; das Passwort folgt nach dem Bestätigungslink. **(3) Passwörter Teilnehmender mindestens 10 Zeichen** (Team bleibt bei 12; Annahme der Umsetzung, auf Wunsch angleichbar). **(4) Konto löschen** mit Wahl: Anmeldung behalten (weiter per Link erreichbar) oder stornieren (nur bis zum Storno-Stichtag); mit Passwort bestätigt. **(5)** Bestätigtes Konto und bestätigte Anmeldung mit gleicher Adresse werden automatisch verknüpft. **(6)** Team-Seite nur Spalte „Konto ja/nein“; Verwaltung mit AP-5. **(7)** Sitzung endet nach 30 min ohne Aktivität. **Aus der Umsetzung:** eine Konten-Tabelle mit Rolle „teilnehmend“ (E-42); Profil (Berufsgruppe, Institution, EFN) am Konto, mit Anmeldung gilt die Anmeldung und das Konto zieht mit; Konto ohne Anmeldung über `/konto/neu` (Spamschutz wie die Anmeldung, Bestätigung per Mail, unbestätigte nach 48 h gelöscht); eingeloggt anmelden: Angaben vorausgefüllt, keine zweite Bestätigung; neue Adresse gilt nach Bestätigung für Konto und Anmeldung; „Passwort vergessen“ per Mail nur für Teilnehmende (Team weiter über den Betreiber); Links 48 h, erst per Klick verbraucht; Testdaten: Lea mit Konto und erfundenem Test-Passwort | Admin 2026-10-29 |
| E-49 | Team-Bereich (AP-5) | Antworten des Admins 2026-10-29: **(1)** alles in einem PR; **(2)** Änderungen durch das Team schicken der Person eine Mail („das Team hat deine Anmeldung geändert/aktualisiert“), Häkchen „ohne Mail“ für stille Korrekturen; **(3)** Platz von Hand: „Platz geben“ (sofort fest, mit Mail) und „Angebot schicken“ (wie beim Nachrücken, mit Frist), beides auch nach der Grenze; **(4)** Admin und Betreiber: Personen, Workshops, Statistik, Export, Startseiten-Texte, Protokoll lesen; nur Betreiber: Rechtstexte, Hostnamen und Absender, Rollen, Team-Konten, Sicherung; **(5)** CSV mit Semikolon und UTF-8-BOM; **(6)** CME-Liste einfach (Name, Berufsgruppe, EFN), Format für die Kammer mit AP-10. **Aus der Umsetzung:** Reiter Übersicht, Personen, Workshops, Programm, Statistik, Einstellungen, Texte, Protokoll, Mein Konto (+ Team-Konten, Betrieb für Betreiber; „Daten“ heißt jetzt „Betrieb“, „Anmeldungen“ leitet auf „Personen“); das Team ändert auch nach den Stichtagen, ohne Workshop-Limit; volle Termine und ein volles Symposium nur mit „Überbuchen bestätigen“; Tausch (gleicher Workshop, Überschneidung) nach Nachfrage; eine vom Team geänderte Adresse gilt sofort; Person anlegen gilt sofort als bestätigt (ohne Bestätigungsmail); Platz entfernen lässt nachrücken; „Zu erledigen“: freie Plätze mit Warteliste (nach der Grenze manuell), Mail-Fehler, Angebote, die in 24 h ablaufen, unveröffentlichtes Programm; Statistik mit SVG-Balken in einer Farbe, Zahlen als Text, Tabellenansicht für den Verlauf; Exporte stehen im Protokoll; Rolle vergeben holt Teilnehmende ins Team (Mail, ohne Passwort mit Einrichtungslink); Absender in der Oberfläche vor config.php. Nicht in AP-5: Passkeys (O-14), Team-Mails Einladung und „Passwort vergessen“ (AP-6, Admin 2026-10-29) | Admin 2026-10-29 |
| E-50 | Mailings und Team-Mails (AP-6) | Antworten des Admins 2026-10-30: **(1)** newsletter@ als zweites Postfach in config.php (Abschnitt `mail` → `newsletter`), eigenes Tageslimit; **(2)** Mailings an Teilnehmende ohne Abmeldelink (organisatorisch, Teil der Anmeldung), nur Impressum und Datenschutz; Abmeldelink mit den Newsletter-Listen (AP-7); **(3)** Empfängerliste im Archiv so lange wie die Anmeldungen: 14 Monate nach dem Symposium (E-24); **(4)** Planen mit Datum und Uhrzeit, aufhebbar, solange nichts versandt ist; **(5)** eine Auswahl plus optional Berufsgruppe; **(6)** Texte der System-Mails bleiben fest im Code. **Aus der Umsetzung:** Reiter „Mailings“ nach „Programm“; Auswahl: alle Angemeldeten, Warteliste Symposium, Warteliste (alle), ohne Essen, Teilnehmende oder Warteliste je Workshop-Termin – nur bestätigte Anmeldungen; Platzhalter {vorname}, {nachname}, {workshops}, {link} (persönlicher Einmal-Link, beim Versand erzeugt, 48 h); Editor mit Werkzeugleiste (fett, kursiv, Überschrift, Liste, Link, Knopf, Bild, Platzhalter, HTML), ohne JavaScript ein HTML-Feld; der Server lässt nur einfache Elemente zu; Bilder JPG/PNG, auf höchstens 1200 px verkleinert, neu geschrieben, als Datei in var/bilder (mit gesichert), in der Mail per Content-ID eingebettet; Vorschau im iframe ohne Skripte mit den Daten der ersten Person, Handy/Desktop; Testmail an die eigene Adresse sofort; die Warteschlange enthält bei Mailings nur Verweise, der Inhalt entsteht beim Versand (aktueller Name, Storno dazwischen → keine Mail); Mailings über anmeldung@ nur bis 80 % des Tageslimits (Rest für Bestätigungen), über newsletter@ bis 100 %; Rest am nächsten Tag; Versand abbrechbar; „Als neue Mail kopieren“; Senden, Planen, Abbrechen im Protokoll. **Team-Mails:** Einladung beim Anlegen per Mail (wahlweise Link nur anzeigen); „Passwort vergessen“ gilt auch für Team-Konten (Link über /einrichten, zweiter Faktor bleibt). Absender je Postfach unter „Betrieb“ | Admin 2026-10-30 |
| E-51 | Rückmeldungen zu Staging (2026-10-30) | Antworten des Admins zur ToDo-Liste: **Navigation:** ohne Erkennung grüner Knopf „Anmelden“ zur Einstiegsseite (neu anmelden, Link per Mail, Einloggen), erkannt „Meine Anmeldung“; vor dem Anmeldestart statt der ersten beiden Kacheln „Anmeldung noch nicht möglich – öffnet am …“; Link „Team“ in der Fußzeile entfällt, Login-Text kürzer; Team-Login wird auf öffentlichen Seiten erkannt (Fehler: „Deine Anmeldung“ wirkte wie ausgeloggt). **Programm:** Workshops und Stationen ausdrücklich einem Block zuordnen (Feld oder Ziehen auf die Blockmitte, Hinweis bei Uhrzeit außerhalb); Einfügemarke weiß, ausgewählt lila, Block grün (ohne dicken Balken); unter den letzten Block ziehen („ans Ende“, unteres Viertel eines Blocks = danach); ein Label „nicht veröffentlicht“ ohne Legende; Hinweisbalken hellrot, abgerundet; „Programmpunkt löschen“ neben Speichern. **Texte:** Auszeichnung als CommonMark-Teilmenge (Standard statt Eigenbau, Zeilenumbruch bleibt wie in GitHub-Kommentaren, Links nur https/mailto, kein HTML), Werkzeugleiste wie bei den Mailings, Bilder hochladen (öffentlich unter /bild/text-…), Hilfe als Kasten. **Zwei-Faktor-Authentifizierung:** Code per E-Mail für alle frei wählbar (beim ersten Login oder unter „Mein Konto“, mit Passwort bestätigt; 10 min, einmal, nur als Hash), App und Wiederherstellungscodes gelten weiter; Abschnitt unter „Mein Konto“ neu; Passkeys als eigenes Paket danach (O-14). **Protokoll:** Admins sehen Anmeldungen, Nachrücken, Programm, Einstellungen, Texte, Exporte, Mailings und Eigenes; Betreiber alles und können ausblenden (markiert, wieder einblendbar, selbst protokolliert), Löschen geht nicht; Filter als Knöpfe; Team-Logins als Art „Login“. **Einstellungen:** ganze Seite gesperrt bis „Entsperren“. **Bestätigungsdialog:** einheitlich nach Mockup-Entwurf C (Rahmen grün/rot/lila, Handy von unten), aus jeder Nachfrage gebaut, ohne JavaScript bleibt das Aufklappen. **Statistik:** Kachel „EFN angegeben“ entfällt (Angabe in „Angemeldet“), Verlauf bleibt. **Titel:** Abstand zwischen Kicker und Titel mit Umlaut. **Nach AP-7:** Export als xlsx und PDF im Branding-Stil **Geändert durch E-54:** statt der CommonMark-Teilmenge der Editor der Mailings (bereinigtes HTML). | Admin 2026-10-30 |
| E-52 | Newsletter (AP-7) | Antworten des Admins 2026-10-30 („alles ja“): **(1)** Listen „Wintersymposium“ und „Allgäu Akut“, das Team legt weitere an, benennt um, blendet aus; **(2)** öffentliche Seite /newsletter (Link in der Fußzeile, Hinweis auf der Startseite) mit E-Mail, Vorname (freiwillig), Listen, Spamschutz wie bei der Anmeldung; **(3)** Häkchen bei der Symposium-Anmeldung: nach deren Bestätigung eine eigene Newsletter-Bestätigungsmail; **(4)** Listen ändern über „Abmelden oder Listen ändern“ in jeder Newsletter-Mail, mit Konto auch unter „Mein Konto“; **(5)** Versand im Mailings-Editor mit Empfängerart „Newsletter-Listen“, auch mehrere zugleich, jede Person einmal, Abmeldelink und List-Unsubscribe (One-Click, RFC 8058) automatisch, ohne Vorname „Hallo,“; **(6)** Absender newsletter@ mit Anzeigenamen je Liste; **(7)** unbestätigte Einträge nach 7 Tagen gelöscht, Abgemeldete sofort gelöscht (je Liste bleibt eine Zahl), Nachweis: Zeitpunkt und Fassung des Einwilligungstexts (Wortlaut gespeichert), keine IP; **(8)** Team: suchen, löschen, einladen nur per Bestätigungsmail; **(9)** Empfängerliste im Archiv 14 Monate ab Versand. **Aus der Umsetzung:** Bestätigen erst mit Klick auf „Bestätigen“ (Mail-Scanner); über den Verwalten-Link (beweist die Adresse) und im Konto gelten neu gewählte Listen gleich als bestätigt; Links nur als Hash (Bestätigen 7 Tage, Verwalten 400 Tage); die bei der Anmeldung angebotene Liste ist eine Einstellung der Liste; Statistik und CSV je Liste; Protokoll-Art „Newsletter“ für Admins sichtbar. **Datenschutzerklärung:** muss den Newsletter beschreiben (Betreiber, Texte) | Admin 2026-10-30 |
| E-53 | Rückmeldungen 30.10. (2) | ToDo-Liste des Admins nach AP-7. **Umgesetzt:** **(1)** „Programmpunkt löschen“: Dialog mittig, „Ja, löschen“ wirkt (Fehler im Dialog-Skript: ein Feld `name="id"` überdeckte die Formular-Kennung; betraf jeden Dialog mit so einem Feld). **(2)** Ziehen im Plan auch mit dem Finger (Handy, Tablet) über Zeiger-Ereignisse; die weiße Linie sitzt mittig zwischen zwei Einträgen, unter einem Block unter dem ganzen Block; das Feld „ans Ende“ entfällt (Admin: mit der Linie überflüssig). **(3)** Neue Termin-Zeilen lassen sich entfernen; gespeicherte wie bisher mit „entfernen“ beim Speichern. **(4)** **Dozierende je Termin**, wenn ein Termin abweicht (z. B. Pause einer Dozentin): nichts gewählt = wie beim Workshop; die Startseite zeigt „mit …“ beim Termin. **(5)** **Vorträge, Fallbesprechungen und Stationen in Workshop-Blöcken** (Admin: „kann es in Blöcken ja auch geben“): zuordnen per Feld oder Ziehen; Vorträge und Fälle nur ausdrücklich, nicht über die Uhrzeit (Annahme der Umsetzung, damit bestehende Programme nicht umsortiert werden); im Block schieben sie die Hauptspur nicht; ein verschobener Block nimmt sie mit. **Geklärt, folgt als eigenes Paket:** Editor (Admin: Variante b, „Nur ein Editor überall“, der Mailings-Editor 1:1, O-38); **ein Konto für alles** mit Personen-Menü oben rechts (O-37, O-39); Team-Reiter neu aufteilen, Mockup mit drei Varianten (O-37); Konten nach 14 Monaten ohne Login löschen, Erinnerung 30 Tage vorher (O-36). „Workshop in den Block ziehen“ ging bereits – im Beispiel des Admins war „Paupanza“ eine Fallbesprechung, das ist jetzt mit (5) möglich | Admin 2026-10-30 |
| E-54 | Ein Editor, ein Konto, Navigation, Löschfrist | Antworten des Admins 2026-10-30 (O-36 bis O-39, Mockup „Team-Navigation und Personen-Menü“): **(1) Ein Editor überall** – der Mailings-Editor 1:1 für Texte, Programm-Beschreibungen, Kurzbios und Listen-Beschreibungen; Knöpfe schalten an und aus und zeigen den Zustand; neu „nummerierte Liste“ (auch in Mailings); gespeichert als bereinigtes HTML, die CommonMark-Teilmenge aus E-51 entfällt; vorhandene Start-Texte werden einmal umgewandelt (Admin: „es gibt noch keine Texte – einfach überschreiben“). **(2) Ein Konto für alles** – eine Sitzung für Team, Konto und Einmal-Link; Team-Konten fragen den zweiten Faktor direkt beim Login ab; wird ein Konto zum Team-Konto, wird der zweite Faktor beim nächsten Login eingerichtet; Team-Mitglieder melden sich mit dem eigenen Konto zum Symposium an; „Mein Konto“ eine Seite für alle (Team zusätzlich Zwei-Faktor). **(3) Personen-Menü** oben rechts (ausgeloggt „Einloggen“; eingeloggt Meine Anmeldung, Mein Konto, Team-Bereich, Ausloggen); eingeloggt kein Login-Feld auf „Meine Anmeldung“. **(4) Team-Navigation Variante A** – sechs Bereiche (Übersicht, Anmeldungen, Programm, Kommunikation, Statistik, Verwaltung), Unterseiten angehängt darunter (Kommentar des Admins im Mockup: „klarer der oberen Leiste zugehörig“). **(5) Löschfrist** – Konten Teilnehmender nach 14 Monaten ohne Login löschen, Erinnerung 30 Tage vorher, Login setzt die Frist neu, Team-Konten ausgenommen; die Anmeldung bleibt ohne Konto (wie E-48 „Anmeldung behalten“, Admin bestätigt); Erinnerung über den Systemabsender (Admin bestätigt). Ändert E-24 (dort blieben Konten bestehen). Umsetzung in einem PR (Admin: „Fasse alle 3 PRs in einen zusammen“) | Admin 2026-10-30 |
| E-55 | Passkeys (O-14) | Antworten des Admins 2026-10-30 („Empfehlungen ok“): **(1)** Ein Passkey **ersetzt Passwort und zweiten Faktor** (Gerät als Besitz plus Fingerabdruck, Gesicht oder Geräte-PIN; die Nutzerprüfung UV wird bei Anlage und Anmeldung verlangt). **(2)** Für **alle Konten**; Teilnehmende freiwillig. **(3)** Ein Team-Konto darf **nur einen Passkey** als zweiten Faktor haben; der letzte zweite Faktor lässt sich nicht entfernen; die Wiederherstellungscodes bleiben der Notweg. **(4)** Beim ersten Team-Login steht „Passkey“ als erste Wahl neben E-Mail-Code und App. **Aus der Umsetzung:** NAdoku `passkey_lib.php` übernommen (strenger CBOR-Leser, Prüfung von clientDataJSON, authData, Zähler, Grenzen für Schlüssel), Signatur über die PHP-Erweiterung **OpenSSL** statt phpseclib (keine neue Abhängigkeit); ES256 und RS256; Attestation „none“ (keine Herstellerdaten); gespeichert werden nur öffentlicher Schlüssel (PEM), Zähler, Name, Zeitpunkte; Nutzerkennung beim Gerät ist ein HMAC, nicht die Kontonummer; rp.id = Domain der Anfrage (nur eingetragene Hosts, HTTPS; ein Passkey gilt nur unter dem Namen, unter dem er angelegt wurde); höchstens 10 je Konto; Anlegen in „Mein Konto“ mit dem Passwort bestätigt; Zurücksetzen des zweiten Faktors durch einen Betreiber entfernt auch die Passkeys; ein zurückgelaufener Zähler (kopierter Passkey) wird abgelehnt und protokolliert | Admin 2026-10-30 |
| E-56 | Export als xlsx und PDF (O-35) | Antworten des Admins 2026-10-30: **(1)** PDF mit einer bestehenden Bibliothek: **tFPDF** (Variante B; LGPL-2.1, unverändert in `server/lib/vendor/tfpdf/` mit Lizenz und Herkunft, keine Composer-Abhängigkeit; Unicode-Schriften für Umlaute und Branding). **(2)** Die Liste je Termin bekommt **keine Unterschriftenspalte**; sie ist Info und Kontrollliste für die Dozierenden. **(3)** CME-Liste bleibt einfach bis AP-10. **(4)** xlsx selbst geschrieben (ZipArchive); fehlt die PHP-Erweiterung zip, ist der Excel-Knopf ausgeblendet. **Umfang:** xlsx für alle sieben Exporte (Kopfzeile Lila, fixiert, mit Filter, Spaltenbreiten, Datum als echte Datumszelle, Texte nie als Formel); PDF (A4) für Teilnehmende (quer, ohne E-Mail und Bestätigungszeit), Listen je Termin, Wartelisten, Essen, CME und Statistik, nicht für Newsletter; je Termin bzw. Essen eine eigene Seite mit Ort, Leitung und Belegung (Liste je Termin ohne E-Mail). Branding: Logo, Kicker in Antonio, Titel in Anton, Text in Tajawal, Zahlen in IBM Plex Mono, Nachtblau und Lila 600; Kopf mit Stand und Filter, Fuß „Vertraulich – nur für die Organisation“ und Seitenzahlen. CSV bleibt. Knöpfe CSV · Excel · PDF unter Statistik, beim einzelnen Termin unter Workshops und je Newsletter-Liste (dort ohne PDF). Schriftmaße speichert tFPDF im Laufzeitordner (`pdf/unifont/`). **Status:** umgesetzt 2026-10-30, Staging offen (Prüfprotokoll T-247 bis T-250). **Probleme und Lösungen:** (a) tFPDF schreibt den Zwischenspeicher der Schriftmaße neben die Schriften – das wäre im ausgelieferten Code; Lösung: `FPDF_FONTPATH` zeigt auf den Laufzeitordner, die Schriften liest tFPDF über `_SYSTEM_TTFONTS` aus `server/pdf/`. (b) In der Beratung zuerst als FPDF-Lizenz bezeichnet; tFPDF steht unter LGPL-2.1 – mit AGPL vereinbar, Lizenztext liegt bei. (c) Excel zeigt ein Datum ohne Uhrzeit nur dann sauber, wenn die Zelle eine ganze Zahl ist; Datum und Datum mit Uhrzeit bekommen je ein eigenes Zahlenformat. (d) LibreOffice und openpyxl vermissten den Standard-Zellstil; `cellStyles` ergänzt | Admin 2026-10-30 |
| E-57 | Teilnahme und Zertifikate (AP-10, O-19) | Antworten des Admins 2026-10-30: **Zertifikat nach Entwurf C** („Off-Label“, Mockups im Canvas „Zertifikat-Entwürfe“): heller Papiergrund (druckbar zu Hause), oben links nur die Bildmarke (ohne Wortmarke ALLGÄU AKUT, Admin 2026-10-30) und daneben groß über die ganze Breite der Name der Veranstaltung („Allgäuer Winter-Notfallsymposium“, kein ALLGÄU AKUT im Text), darunter rechts der Badge „3. Auflage · 2027“, Kicker „Teilnahmebescheinigung · Datum“, Titel „Nicht nur dabei gewesen.“, Zeit und Ort in eigener Zeile, Zettel mit Klebeband (über dem Zettel) mit Name, EFN und abgehakten Programmpunkten, Stempel „Teilnahme bestätigt“, Punkte mit Fußnote, Unterschrift rechts; unterste Zeile links „Teilnahme vom Team erfasst · Nr.“, rechts die Webadresse, beides grün (Admin 2026-10-31; vorher Adresse zentriert in eigener Zeile), ein langer Kammer-Hinweis steht darüber. **(1)** Angaben (Name, Auflage, Punkte, Kategorie, Kammer, VNR, Name und Rolle unter der Unterschrift) sind Einstellungen (R-11). **(2)** Die Unterschrift kommt als **PNG** aufs Zertifikat (Upload unter Team → Teilnahme). **(3)** CME-Export bleibt Excel/PDF/CSV mit den **Anwesenden**; eine EIV-Datei erst, wenn das Format der Kammer bekannt ist (O-16). **(4)** Zertifikat für **alle Anwesenden** aller Berufsgruppen; EFN nur, wenn angegeben; **keine Berufsgruppe** auf dem Zertifikat. **(5)** Jeder fest gebuchte Workshop gilt als besucht; das Team kann einzelne Workshops (wie das Symposium, E-42) als „nicht erschienen“ austragen. **(6)** Alles in einem PR. **Aus der Umsetzung:** Reiter **Anmeldungen → Teilnahme**: Kacheln, Liste mit Suche und Filter, „nicht erschienen“/„doch da“ je Symposium und Workshop (Handy-tauglich, alles im Protokoll, Art „Teilnahme“), Unterschriftenliste als PDF (alle mit Berufsgruppe oder nur ÄrztInnen mit EFN als Barcode Code 128 `*EFN*` bzw. Feld fürs Etikett; sortiert nach Nachname, Vorname oder Berufsgruppe), CME-Liste, Vorschau mit Beispiel und je Person, Freischalten einzeln oder alle (erst ab dem Veranstaltungstag, wahlweise ohne Mail), zurücknehmen. Freischalten schickt die Mail „Deine Teilnahmebescheinigung ist da“ mit einem Link zu „Meine Anmeldung“ (30 Tage, einmal); dort und mit Konto lädt die Person das PDF herunter. Ohne Anwesenheit kein Download – auch wenn nach der Freischaltung „nicht erschienen“ eingetragen wird. Vorträge und Fallbesprechungen im Plenum (nicht einem Workshop-Block zugeordnet) stehen gesammelt auf dem Zertifikat; Nummer „WS27-0042“ aus Jahr und Anmeldungsnummer. Die Unterschrift liegt im Laufzeitordner (`zertifikat/unterschrift.png`) und ist Teil der Sicherung. Migration 14: `platz.nicht_erschienen`, `anmeldung.zertifikat_am`. **Status:** umgesetzt 2026-10-30, Staging offen (T-190 bis T-195, T-251 bis T-253). **Probleme und Lösungen:** (a) Nach gedrehten Elementen (Badge, Zettel, Stempel) erschien Text in der Hintergrundfarbe: Das PDF stellt nach einer Drehung Farben und Schrift zurück, tFPDF wusste davon nichts; jetzt werden sie danach neu gesetzt. (b) Beim Testen fiel ein **Fehler im Mailversand** auf (seit AP-6): Das Tageslimit für Rundmails wurde in der Datenbank als Text verglichen („4“ < „360“ ist als Text falsch) – Rundmails blieben liegen, sobald am Tag 4–9 (bzw. 40–99) Mails verschickt waren; jetzt vergleicht PHP die Zahlen, mit Test. (c) Ein neues Pflichtfeld „Name der Veranstaltung“ hätte ältere Formulare und Testdaten gebrochen; leer gilt „Wintersymposium“. (d) Barcode mit einem unabhängigen Leser geprüft (zxing: Code 128, `*802760012345673*`) | Admin 2026-10-30 |
| E-58 | Mehrere Veranstaltungen (AP-11) | Antworten des Admins 2026-10-31: Es kommen weitere Veranstaltungen dazu – auch die Abende der Reihe; **ein Grundmuster** für alle (Anmeldung, Programm, Warteliste, Teilnahme, Zertifikat), manchmal **ohne Essen**, manchmal **ohne Workshops**. **(1)** Immer nur **eine aktuelle** Veranstaltung: Startseite, `/anmelden`, „Meine Anmeldung“. **(2)** Jede Veranstaltung hat ein **Kürzel** (in den Einstellungen änderbar, z. B. `wintersymposium2027`) und ist unter `/<kürzel>` erreichbar; **abgeschlossene** sind dort nur noch zum Ansehen (Eckdaten, Programm), werden mit dem Abschluss festgeschrieben, bleiben aber in derselben Struktur gespeichert. **(3)** Gemeinsam: Konten, Team, Newsletter, Impressum, Datenschutz, Dozierende, Absender, Hostnamen; je Veranstaltung: Einstellungen, Programm, Startseiten-Texte, Anmeldungen, Wartelisten, Teilnahme, Zertifikat-Angaben und Unterschrift, Exporte, Statistik, Mailings an Angemeldete. **(4)** „Mein Konto“ zeigt die besuchten Veranstaltungen mit Zertifikat, solange das Konto besteht. **(5)** Wiederkehrende: mit Konto vorausgefüllt; ohne Konto per Einmal-Link an die bekannte Adresse („Daten übernehmen“), solange die Daten nicht geleert sind. **(6)** Team-Bereich: Auswahl der Veranstaltung im Kopf, Seite „Veranstaltungen“ (anlegen leer oder als Kopie mit Einstellungen, Texten und Programm; zur aktuellen machen; abschließen; Entwurf löschen). **(7)** Zwei PRs: dieser Umbau, danach AP-9. **Aus der Umsetzung:** Tabelle `veranstaltung` (Kürzel, Status Entwurf → aktuell → abgeschlossen), `va_einstellung` (Einstellungen je Veranstaltung), Spalte `veranstaltung_id` an `inhalt` (0 = gemeinsam), `programmpunkt`, `anmeldung`, `platz`, `rundmail`; `programm_stand` je Veranstaltung (id = Veranstaltung); Migration 15 macht alles Bisherige zur Veranstaltung 1 „wintersymposium2027“ (aktuell). Jede Anfrage arbeitet im **Kontext** einer Veranstaltung (öffentlich die aktuelle bzw. die zum Kürzel, im Team die gewählte, Jobs je nicht abgeschlossener). Neue Einstellungen: **Kurzname** (Mails, Kopfzeilen, Seitentitel), **Art** (Symposium, Vortragsabend, Journal Club, Workshop-Abend, Fallbesprechung, Sonstige), **Bausteine** Essenswahl, Fortbildungspunkte (EFN, CME-Liste) und Teilnahmebescheinigung (je ja/nein); Workshops gibt es, sobald das Programm welche enthält. Zur aktuellen machen geht nur, wenn keine andere aktuell ist; Abschließen sperrt alle Änderungen (Speichern wird abgewiesen), Lesen, Exporte und Zertifikate bleiben; „Zu erledigen“ erinnert nach dem Veranstaltungstag ans Abschließen. Ein Einmal-Link oder die Zertifikat-Mail einer vergangenen Veranstaltung zeigt deren Anmeldung nur zum Ansehen. **Status:** umgesetzt 2026-10-31, Staging offen (T-254 bis T-259). **Probleme und Lösungen:** (a) Der Platz „Symposium“ hat in allen Veranstaltungen die Termin-Nummer 0; ohne Filter hätten sich Belegung und Wartelisten vermischt – `platz` bekam die Veranstaltung als eigene Spalte. (b) „Verwerfen“ im Programm löschte alle Programmpunkte – jetzt nur die der Veranstaltung; ebenso Veröffentlichen, Nachrücken und das Aufräumen von Wartelisten. (c) Viele Stellen lesen Einstellungen über `einstellung()`; statt sie alle umzuschreiben, leitet `einstellung()` die Schlüssel der Veranstaltung an `va_einstellung` im Kontext weiter (Tests und Code bleiben gleich). (d) Feste Texte „Wintersymposium“ in Mails und Kopfzeilen kommen jetzt aus dem Kurznamen. (e) Die Unterschrift liegt je Veranstaltung (`zertifikat/<id>/`); die bisherige wird beim ersten Zugriff verschoben. (f) WebKit (Safari) zählte den Text einer langen gewählten Option eines Auswahlfelds zur Seitenbreite – auf dem Handy ließ sich die Seite seitlich schieben; `contain: paint` für alle Auswahlfelder, der Browser-Durchlauf nennt bei seitlichem Scrollen jetzt die überstehenden Elemente (`WS_ENGINES` für einzelne Engines) | Admin 2026-10-31 |
| E-59 | Abschluss und Datenschutz (AP-9, Antworten) | Antworten des Admins 2026-10-31: **(1)** Anmeldungen ohne Konto 14 Monate nach der Veranstaltung leeren (E-24), Statistik-Zahlen bleiben. **(2)** CME-Nachweise nur der anwesenden ÄrztInnen (Name, EFN, Datum, VNR, Punkte) getrennt aufbewahren, 10 Jahre ab der Veranstaltung, nur für Betreiber, mit Export. **(3)** Protokoll 14 Monate, Login-Einträge 90 Tage. **(4)** Automatische Sicherung **8× täglich** (alle 3 Stunden); behalten: alle der letzten 3 Tage, bis 7 Tage eine je Tag, bis 14 Tage eine je 3 Tage, bis 28 Tage eine je Woche, danach keine. **(5)** Textvorschlag für die Datenschutzerklärung (Prüfung durch den Admin, keine Rechtsberatung). **(6)** Gesamttest und Repo-Prüfung (T-202 bis T-204). **(7)** Sicherungen von Hand nach **90 Tagen** löschen (Admin: „Ja“). **(8)** Zusätzliche Sicherungen des Hosters spielen keine Rolle – wir verlassen uns nicht darauf (Admin: „verlassen wir uns nicht darauf“). **Aus der Umsetzung:** Job `datenschutz` stündlich (Leeren, CME-Nachweise ablegen und nach 10 Jahren löschen, Protokoll kürzen), Job `sicherung` alle 3 Stunden (anlegen, dann nach Plan aufräumen; automatische ohne Protokolleintrag, sonst stünden 8 Einträge am Tag im Protokoll); Migration 16 (`cme_nachweis`, `anmeldung.anonymisiert_am`). Geleert werden Name, Adresse (ersetzt durch eine ungültige Platzhalter-Adresse, damit die Eindeutigkeit je Veranstaltung hält), Institution, Freitext, EFN, Einmal-Links und Namen in Mailing-Empfängerlisten; Berufsgruppe, Essen, Plätze und Anwesenheit bleiben für die Statistik. CME-Nachweise nur bei Veranstaltungen mit dem Baustein Fortbildungspunkte, nur bestätigte, anwesende Personen der ersten Berufsgruppe (ÄrztInnen); Kasten und Export unter Betrieb nur für Betreiber. Die Fristen sind feste Regeln des Löschkonzepts, keine Einstellungen (R-11 gilt für Veranstaltungswerte). Textvorschlag Datenschutzerklärung: `docs/datenschutz-vorschlag.md`. **Status:** umgesetzt 2026-10-31, Staging offen (T-200 bis T-202, T-260 bis T-263); T-204 bestanden: Name des Hosters und Bezüge auf die frühere Organisation aus dem Repo entfernt, Ordnerpfade bleiben (Admin 2026-10-31) | Admin 2026-10-31 |
| E-66 | Mails im Erscheinungsbild, eigene Plätze im Programm | Admin 2026-10-31 nach Mockups (O-40, O-41): **(1) Mails Variante A „Plakat“** für alle automatischen Mails, Mailings und Newsletter: dunkler Kopf mit eingebettetem Logo (PNG, kein Nachladen, keine fremden Server), Kicker und Überschrift, grüner Streifen, Übersicht der Plätze als Tabelle mit Schildern (grün bestätigt, lila Platzangebot, gestrichelt Warteliste mit Position), lila Knopf mit Ersatzadresse darunter, dunkler Fuß mit Impressum und Datenschutz. Die Textfassung wird weiter mitgeschickt; im HTML ersetzt der Knopf den Satz mit der Adresse (nicht doppelt). **(2) Programm Variante B:** Wer erkannt ist (eingeloggt oder per Link), sieht am eigenen Termin einen weißen Rahmen und ein Schild (grün „Dein Platz“, lila „Platzangebot“, gestrichelt „Warteliste N“), die Workshop-Zeile ist lila markiert mit „Du: …“; darunter ein Hinweis, nur für die Person sichtbar. **(3) Begriff „Platzangebot“** statt „Platz frei für dich“ überall (Mail, Meine Anmeldung, Team-Bereich). | Admin 2026-10-31 |
| E-65 | Status-Seite unter Betrieb (AP-15) | Wunsch und Antworten des Admins 2026-10-31: **(1)** **Eigene Seite** Betrieb → Status (nur BetreiberInnen, erste Seite im Reiter) mit Ampel-Zeilen für Cron, Jobs, Mailversand, Datenbank, Sicherung, Fehlerprotokoll und Umgebung; `/health` liefert dieselben Daten als JSON aus einer gemeinsamen Funktion. **(2)** **Cron-Anleitung auf der Seite** mit Kommando zum Kopieren (Shell-Cronjob, E-64) und als Ausweichweg der URL mit Token; **der Token wird erst nach Klick angezeigt** und das Anzeigen protokolliert. **(3)** Fehlerprotokoll (`php-fehler.log`) auf der Seite einsehbar und leerbar, weil es auf dem Webspace sonst keinen Zugang dazu gibt. **(4)** Rote Zeilen erscheinen als Aufgabe auf der Team-Übersicht. **Status:** AP-15 geplant | Admin 2026-10-31 |
| E-64 | Review-Folgen: Design-Wahrheit, Cron, technische Grenzen | Antworten des Admins 2026-10-31 zu O-44 bis O-46: **(1)** **site.css ist die alleinige Design-Wahrheit.** Aus den echten Klassen entsteht ein Komponentenblatt (jeder Knopf, Kasten, Chip, jede Meldung einmal), das Mockup `docs/mockups/` wird Archiv und ist nicht mehr Vorlage (ändert E-36 und CLAUDE.md, Umsetzung im Review-AP CSS). **(2)** **Cron als Shell-Cronjob:** der Hoster kann Befehle starten; Standard ist `php <Zielordner>/cli/jobs.php` ohne Token in einer URL. Der URL-Aufruf `/jobs?token=` bleibt als Ausweichweg (betrieb.md angepasst). **(3)** **Technische Grenzen bleiben Konstanten:** Link-Gültigkeiten, Ratenschutz, Höchstwerte im Programm-Editor (12 Termine je Workshop, 500 Plätze je Termin) sind Tippfehler-Bremsen, keine Veranstaltungseinstellungen; R-11 ergänzt. | Admin 2026-10-31 |
| E-63 | Code-Review und Behebung (AP-14 ff.) | Code-Review vom 2026-10-31 mit drei Zielstellungen (Bugs, Sicherheit, Vereinheitlichung) in `docs/review-2026-10-31.md`; Antworten des Admins 2026-10-31: **(1)** Die Behebung läuft in Review-Arbeitspaketen; das erste (AP-R1 im Review) ist **AP-14**, weitere folgen als AP-15 ff. nach Bestätigung des Umfangs. **(2)** **Die Login-Adresse eines Team-Kontos ist nicht über eine Anmeldung änderbar** – weder durch das Team unter „Personen“ noch durch die Person selbst unter „Meine Anmeldung → Daten“; sie ändert nur eine BetreiberIn unter Betrieb → Team-Konten (O-42). Adressen verknüpfter Teilnehmenden-Konten darf das Team weiter ändern, die alte Adresse bekommt eine Hinweis-Mail. **(3)** **Ein Storno auf „Meine Anmeldung“ beendet die Sitzung nicht mehr**; Konto- und Team-Login bleiben (O-43; ändert E-46/E-48, wo „ausgeloggt“ steht). **(4)** **Ein PR erst, wenn das ganze Paket da ist.** Offen: O-44 bis O-46. **Status:** AP-14 umgesetzt (Hinweistext zu (2) angepasst, Funktion offen als O-48), AP-16 geplant | Admin 2026-10-31 |
| E-62 | Browser-Prüfung gestuft | Admin 2026-10-31: der volle Browser-Lauf (30–40 Min.) bei jeder kleinen Änderung ist zu viel. **(1)** Stufung nach dem, was sich ändert, nicht nach festem Takt: Stufe 1 `lauf.php` immer; Stufe 2 gezielt (betroffene Seiten, Chromium, Handy und breiter Desktop, `WS_STUFE=2 WS_SEITEN=…`, ca. 1 Min.); Stufe 3 voll bei Arbeitspaket-Ende, gemeinsamen Teilen, vor Produktion und spätestens nach 5 PRs ohne vollen Lauf. **(2)** Kein nächtlicher Lauf in GitHub Actions. **(3)** Eigener PR | Admin 2026-10-31 |
| E-61 | Team-Konten und Meldungen (AP-13) | Rückmeldung des Admins 2026-10-31 (Entwürfe 8–10): **(1)** Team-Konten nach **Entwurf B**: je Zeile ein Knopf „Bearbeiten“ mit Menü, jede Aktion im Dialog (Sperren und Zurücksetzen mit Nachfrage). **(2)** Meldungen nach **Entwurf B**: volle Farbe (Fehler rot, Hinweis lila, Erfolg grün), dunkle Schrift, Icon aus Phosphor; Fehlermeldung außerhalb des Bildschirms wird angescrollt. **(3)** Ein PR. **Status:** umgesetzt 2026-10-31, Staging offen (T-273, T-274) | Admin 2026-10-31 |
| E-60 | Team-Navigation und Rechte je Veranstaltung (AP-12) | Antworten und Freigaben des Admins 2026-10-31 (Entwürfe 1–7 im Canvas „Team-Kopf und Personen-Menü“): **(1)** Statt der Leiste ein **Wechsler** rechts neben dem Seitentitel; aufgeklappt die Veranstaltungen mit Status, für BetreiberInnen „Neue Veranstaltung“ und „Alle verwalten“. **(2)** **Neue Veranstaltungen legen nur BetreiberInnen an**; bearbeiten dürfen dafür freigeschaltete Team-Mitglieder. **(3)** **Team-Konten auf Veranstaltungen begrenzbar:** je Admin „Alle, auch künftige“ oder „Nur ausgewählte“; BetreiberInnen sehen alles. Admins sehen auch bei Personen und Konten nur Anmeldungen ihrer Veranstaltungen. **(4)** **Protokoll** nach Veranstaltung filterbar; Admins sehen nur, was ihre Veranstaltungen betrifft und ihre Rolle erlaubt; Einträge ohne Veranstaltung (Konten, Newsletter, Logins) nur BetreiberInnen. **(5)** Neuer Reiter **Betrieb** (nur BetreiberInnen, alles Veranstaltungsübergreifende): Veranstaltungen, Team-Konten, Newsletter, Texte (Impressum, Datenschutz), Betrieb; „Kommunikation“ entfällt, oben steht **Mailings**; **Mailings an Newsletter-Listen nur BetreiberInnen**; die **Startseiten-Texte** bleiben bei der Veranstaltung (Verwaltung → Startseite). **(6)** **Veranstaltungen löschen** (nur BetreiberInnen, auch abgeschlossene, nicht die aktuelle): Dialog und eingetipptes Kürzel, **vorher automatische Sicherung**; CME-Nachweise bleiben. **(7)** **Personen-Menü** nach Entwurf A: ohne Team-Bereich (steht in der Leiste), Knopf mit Fläche, Ring beim Hover und Pfeil, Kopf zum Lesen abgesetzt, Punkte mit Icons aus einem fertigen Set – **Phosphor** (nach Vergleich mit Lucide und Tabler). **(8)** **Knopf-Standard** zuerst Variante B (getönte Fläche), **geändert auf Variante A** (Ring 2 px innen, Hover leicht gefüllt; Admin 2026-10-31, nach AP-13), nur ganze Pixel, Haupt/Zweit/Anmelden/Gefahr, 44 bzw. 36 px. **(9)** **Mein Konto** nach Entwurf 7: Profil zuerst (bei Team-Konten Rolle mit den Veranstaltungen untereinander), Deine Veranstaltungen (Anmeldung und Rückblick zusammen), Newsletter; „Einloggen und Sicherheit“ in einer Kachel mit verständlichen Bezeichnungen („Zweiter Schritt beim Einloggen“, „Notfall-Codes“, „Einloggen ohne Passwort“) und kräftigem Hinweis für Team-Konten. **(10)** **Kopfleiste bleibt stehen**; auf dem Handy beim Scrollen nach unten nur ein kleiner „Menü“-Knopf. **(11)** **Team-Bereich grün** in der Leiste. **(12)** Die Rolle heißt überall **BetreiberIn**. **(13)** Es gibt noch keine Admins; keine Übergangsregel nötig. **Status:** umgesetzt 2026-10-31, Staging offen (T-264 bis T-272) | Admin 2026-10-31 |

### E-18: Bausteine aus NAdoku (`gen-em/einsatzdoku-luftrettung`, Ordner `server/`)

```yaml
direkt_uebernehmen:
  - {baustein: "email_lib.php + wegwerfdomains.txt (8 883 Domains, CC0)", zweck: "Adresse normalisieren/prüfen, Wegwerfadressen ablehnen"}
  - {baustein: "konfig_lib.php", zweck: "Konfiguration lesen"}
  - {baustein: "transaktion_lib.php", zweck: "verschachtelungsfeste DB-Transaktionen (wichtig fürs Nachrücken)"}
  - {baustein: "format_lib.php", zweck: "deutsche Datums-/Zahlenformate"}
  - {baustein: "session_lib.php: csrf_token/csrf_field/csrf_ok, flash_setzen/holen", zweck: "CSRF-Schutz, Post/Redirect/Get"}
uebernehmen_mit_anpassung:
  - {baustein: "sitzung_lib.php", anpassung: "auf zwei Sitzungsarten kürzen (Team, Einmal-Link); eigene Ablage im Webspace, eigenes Aufräumen"}
  - {baustein: "registrieren.php: Honeypot, HMAC-Zeitstempel (4 s–2 h), Proof-of-Work (assets/pow.js, pow-worker.js)", anpassung: "für Anmelde- und Newsletter-Formular; eine Antwort für alle Fälle"}
  - {baustein: "ratelimit_lib.php", anpassung: "nur Kern (rate_erlaubt, rate_misserfolg, rate_gleiche_dauer, Tabelle), ohne Sperrleiter und Mail-Alarm"}
  - {baustein: "Token-Muster (konto_lib.php reset_token_ausstellen)", anpassung: "für Einmal-Links, Nachrück-Angebote und Newsletter-Bestätigung; sha256 in der DB, Ablauf, Einmalverwendung"}
  - {baustein: "totp_lib.php (RFC 6238, Wiederholungsschutz, Wiederherstellungscodes)", anpassung: "Kern ohne Demo/Gerät-merken; Geheimnis versiegelt (sk_versiegeln)"}
  - {baustein: "passkey_lib.php", anpassung: "übernommen, Signatur über OpenSSL statt phpseclib (E-55)"}
  - {baustein: "serverkrypto_lib.php: sk_versiegeln/sk_oeffnen (AES-256-GCM)", anpassung: "nur für TOTP-Geheimnisse und SMTP-Passwörter"}
  - {baustein: "smtp.php", anpassung: "Message-ID, Reply-To, List-Unsubscribe, optional HTML, mehrere Mails je Verbindung"}
  - {baustein: "jobs_lib.php (Rahmen jobs_lauf/jobs_einen_lauf)", anpassung: "nur Rahmen; Auslöser Cron (CLI/Token-URL) und huckepack"}
  - {baustein: "kopfzeilen_lib.php", anpassung: "CSP mit Nonce, Sicherheits-Header; ohne Geocoder/Karten"}
  - {baustein: "rechtstexte_lib.php, rechtstext_seite.php", anpassung: "nur Impressum und Datenschutz; Editor vereinfacht"}
  - {baustein: "einwilligung_lib.php", anpassung: "Einwilligung je Anmeldung/Newsletter statt je Konto"}
  - {baustein: "validate_lib.php (pruef_text/zahl/flag/kalendertag)", anpassung: "nur generische Prüfungen; EFN-Prüfung neu"}
  - {baustein: "config.example.php, .github/workflows/auslieferung.yml", anpassung: "stark gekürzt: FTPS-Sync mit Ausnahmeliste, Staging und Produktion"}
nur_als_muster:
  - "mail_lib.php (Katalog, Warteschlange, Wiederholungen, Inhalt nach Versand leeren) – neu schreiben mit Prioritäten und Tageszähler"
  - "migration_lib.php, install.php – kleiner Migrationskatalog und Installer"
  - "protokoll_lib.php – einfache Tabelle audit(zeit, wer, art, text)"
  - "wartung_lib.php, api/health.php"
  - "ui.php – Token-Struktur, Bedienhöhe 44 px; Design neu in Allgäu Akut"
nicht_uebernehmen:
  - "Ende-zu-Ende-Verschlüsselung (Team muss lesen können)"
  - "Komplett-Backup, Sicherungsziele, statistik_lib, Login/Konto-Logik von NAdoku (an die Verschlüsselung gebunden)"
```

## 5. Verbindliche Regeln

| ID | Regel |
|---|---|
| R-01 | Keine Secrets im Repo, im Chat oder in Ausgaben. `config.php` liegt nur auf dem Webspace; Zugangsdaten für die Auslieferung als GitHub-Secrets. |
| R-02 | Datensparsamkeit: nur nötige Felder; Tokens nur als Hash; Mail-Inhalte und Empfänger in der Warteschlange nach dem Versand leeren (Ausnahme: Empfängerliste je Rundmail im Archiv, E-42); Löschfristen nach E-24. |
| R-03 | Sicherheit: Prepared Statements, CSRF-Schutz für alle POSTs, CSP mit Nonce, Ratenschutz, Spamschutz an jedem öffentlichen Formular, gleiche Antwort auf Anmelde-/Link-Anfragen unabhängig davon, ob die Adresse bekannt ist. |
| R-04 | Platzvergabe, Nachrück-Angebote und Annahme nur in Transaktionen mit Zeilensperre; kein Platz darf doppelt vergeben werden. |
| R-05 | Jede Änderung: Version hochstufen, `CHANGELOG.md`, Doku, Prüfprotokoll und Konzept aktualisieren; per PR. |
| R-06 | Mobil zuerst, barrierearm (Kontraste nach WCAG AA, Bedienelemente mind. 44 px, Formulare mit Labels). |
| R-07 | Zeiten in der DB in UTC, Anzeige in Europe/Berlin. |
| R-08 | Besonderheiten des Hosters beachten (Abschnitt 2): eigene Sitzungsablage, SMTP-Zeitlimit 5 s, MySQL 8.4. |
| R-09 | Alle Texte der Oberfläche und Mails auf Deutsch; Ton nach zina-branding (`4 Regeln/Erscheinungsbild.md`). |
| R-10 | Öffentliches Repo (E-25): keine Kontonamen, IP-Adressen, Serverpfade, Postfach-Logins oder Hosting-Interna in Code, Doku, Commits, PR-Texten und Issues; Domain und öffentliche Absenderadressen dürfen genannt werden. Betriebsangaben bleiben beim Admin (privat). Alles Umgebungsspezifische liegt in `config.php`. |
| R-11 | Alles, was die Veranstaltung betrifft (Fristen, Limits, Kapazitäten, Zeiträume), ist eine Einstellung in der Oberfläche, kein fester Wert im Code. Technische Grenzen sind davon ausgenommen (Sicherheitsfristen wie Sitzungs- und Link-Ablauf, Ratenschutz, Plausibilitätsgrenzen gegen Tippfehler wie Höchstwerte im Programm-Editor); sie bleiben Konstanten im Code (E-64 (3)). |
| R-12 | Browser-Prüfung vor jedem PR (Admin 2026-10-29; gestuft seit 2026-10-31, E-62): jede Funktion wird in der Sandbox in **Chromium, Firefox und WebKit** (Safari-Engine) auf **Handy (390 px), Tablet (768 px), schmalem Desktop (1024 px) und breitem Desktop (1440 px)** geprüft – Darstellung (kein seitliches Scrollen, nichts ragt aus dem Fenster), Klickwege, **Auswahlfelder** (jede Option), **Aufklapp-Elemente** und **Popups**, keine Konsolenfehler. Werkzeug: `tools/browser/durchlauf.js`; neue Seiten und Abläufe werden dort ergänzt, das Ergebnis (Zahl) steht im PR und im Prüfprotokoll. Ziel: Fehler vor der Auslieferung finden, damit der Admin möglichst wenig selbst testen muss. Grenze: WebKit ist nicht Safari; ein echtes iPhone bleibt eine Stichprobe des Admins. **Gestuft (Admin 2026-10-31, E-62):** Stufe 1 bei jeder Änderung `lauf.php`; Stufe 2 bei Oberflächen-Änderungen an einzelnen Seiten ein gezielter Lauf `WS_STUFE=2 WS_SEITEN=<seiten>` (Chromium, Handy und breiter Desktop, ca. 1 Min.); Stufe 3, der volle Lauf (3 Engines × 4 Breiten), bei Ende eines Arbeitspakets (zweite Stelle der Version steigt), bei gemeinsamen Teilen (Kopfleiste, Navigation, Knöpfe, Meldungen, Formulare, Grundlagen im CSS, JavaScript-Dateien, Login/Sitzung), vor einem Release auf Produktion und spätestens nach 5 PRs ohne vollen Lauf. Welche Stufe lief und warum, steht im PR und im Prüfprotokoll. |

## 6. Arbeitspakete

```yaml
- id: AP-1
  titel: Grundgerüst und Auslieferung
  ziel: "Leere, sichere App läuft auf dem Webspace unter winter-notfallsymposium.de (Staging und Produktion)"
  abhaengig_von: []
  umfang:
    - Repo-Struktur (server/, docs/, tools/), README, VERSION, CHANGELOG, docs/pruefprotokoll.md
    - config.example.php, Konfigurations- und DB-Schicht, Transaktionen, Migrationskatalog, Installer
    - Sitzungen, CSRF, Sicherheits-Header/CSP, Ratenschutz-Kern, Audit-Tabelle, Wartungsmodus, Health-Endpunkt
    - Job-Rahmen mit Cron-Auslöser
    - Grundlage für mehrere Hostnamen (E-27): erlaubte Hosts in der DB, Basis-URL je Anfrage
    - "Auslieferung per GitHub Actions (FTPS) nach E-32: Secrets FTP_SERVER/FTP_USER/FTP_PASSWORD, Ziele /wintersymposium/staging und /wintersymposium/produktiv, Ausnahmeliste (config.php, install.lock, Laufzeitordner); Freigabe Produktion per Tag (E-35)"
  nadoku_bausteine: [konfig_lib, transaktion_lib, format_lib, session_lib (CSRF/Flash), sitzung_lib, kopfzeilen_lib, ratelimit_lib (Kern), jobs_lib (Rahmen), migration_lib/install (Muster)]
  abnahmekriterien:
    - Installer legt Datenbank und ersten Betreiber an und sperrt sich danach
    - HTTPS mit gültigem Zertifikat; Sicherheits-Header gesetzt (CSP, HSTS, nosniff, Referrer-Policy)
    - Cron-Job läuft nachweislich
    - Auslieferung aus GitHub überschreibt config.php nicht; Staging und Produktion getrennt
  status: abgenommen_staging   # 2026-10-29: Staging eingerichtet und geprüft; offen: Produktion per Tag einrichten, Restprüfungen mit AP-4/AP-5 (Prüfprotokoll)
  ergebnis:
    - "Struktur: server/ (Webroot server/public/ mit einzigem Einstieg index.php, lib/, seiten/, cli/), tools/tests/, docs/betrieb.md (Einrichtung, Auslieferung, Wartung, Tests)"
    - "config.php (Vorlage config.example.php): DB-Zugang, geheimnis, installations_schluessel, jobs_token, health_token – alle geheimen Werte nur dort (R-01); WS_CONFIG nur für Tests auf der Kommandozeile"
    - "DB-Schicht (PDO, keine emulierten Prepares, Sitzungszeitzone UTC, strenger sql_mode), db_transaktion verschachtelungsfest, Migrationskatalog mit MySQL-Sperre; Migrationen laufen beim Installieren und automatisch bei der ersten Anfrage bzw. dem nächsten Cron-Lauf nach einer Auslieferung"
    - "Tabellen: einstellung, host, team_konto (ohne zweiten Faktor; kommt mit AP-5), audit, rate_limit, job, schema_migration"
    - "Installer /install: verlangt den Installationsschlüssel aus config.php, legt Tabellen, Haupt-Host (= aufgerufener Host), weitere Hosts und den ersten Betreiber an, schreibt var/install.lock; danach 404. Gibt es schon ein Team-Konto, wird nur die Sperre wiederhergestellt"
    - "Sitzungen in var/sitzungen, PHP-eigenes Aufräumen aus, 30 min Leerlauf-Ablauf, Aufräumen durch den Job; CSRF für alle POSTs"
    - "Kopfzeilen: CSP mit Nonce (nur eigene Quellen), HSTS ein Jahr ohne includeSubDomains (nur über HTTPS), nosniff, Referrer-Policy, X-Frame-Options, COOP/CORP, Permissions-Policy; HTTPS-Zwang in .htaccess und PHP (POST über HTTP abgelehnt)"
    - "Hosts (E-27): erlaubt sind nur Einträge der Tabelle host; fremder Host → 421; Basis-URL je Anfrage, auf der Kommandozeile der Haupt-Host"
    - "Ratenschutz-Kern: Fehlversuche je Topf (installation, health, jobs) und HMAC-Hash der IP, Sperre nach Grenze, rate_gleiche_dauer; Grenzen sind technische Sicherheitswerte im Code (keine Veranstaltungseinstellung, R-11 nicht betroffen)"
    - "Wartung: var/wartung.lock → 503 mit Retry-After für öffentliche Seiten; /team, /health, /install ausgenommen; Jobs pausieren"
    - "Health /health mit Token (Kopfzeile oder Parameter), sonst 404; JSON mit Version, Umgebung, Schema-Stand, Wartung, letztem Job-Lauf"
    - "Jobs: Katalog mit Mindestabstand je Job, MySQL-Sperre gegen parallele Läufe, Zeitstempel jobs_letzter_lauf bei jedem Aufruf; Auslöser cli/jobs.php oder /jobs?token=…; erster Job aufraeumen (Sitzungsdateien, Ratenschutz)"
    - "Auslieferung (E-32, E-35): Workflow Auslieferung – Prüfung, dann FTPS-Abgleich von server/; main → staging, Tag vX.Y.Z → produktiv (Tag = VERSION und auf main, sonst Abbruch vor jeder Übertragung); Ausnahmeliste config.php und var/; Zustandsdatei wird vorab angelegt, falls sie fehlt"
    - "Workflow Prüfung (bei jedem PR und vor jeder Auslieferung): php -l aller Dateien und tools/tests/lauf.php gegen MySQL 8.4"
    - "Staging (2026-10-29): ausgeliefert, installiert, Cron per URL alle 5 min läuft, Health ok, Kopfzeilen, gesperrter Installer, Wartungsmodus und Schutz interner Dateien geprüft (Prüfprotokoll T-100 bis T-110)"
  probleme:
    - {problem: "Bei NAdoku brach die FTP-Deploy-Action gegen diesen Hoster mit ECONNRESET ab, solange die Zustandsdatei auf dem Server fehlte (die Action deutet den Abbruch als Erstauslieferung und arbeitet mit toter Verbindung weiter)", loesung: "Vor dem Abgleich prüft ein curl-Schritt per SIZE auf dem Steuerkanal, ob die Datei da ist, und legt nur dann eine leere an; eine vorhandene wird nie überschrieben"}
    - {problem: "Ein Team-Login gibt es erst mit AP-5; T-108 verlangt „Team-Login weiter möglich“", loesung: "Der Wartungsmodus nimmt /team bereits aus (getestet); der Login-Teil von T-108 wird nach AP-5 geprüft"}
    - {problem: "Eine Regel „Punktdateien sperren“ hätte auch /.well-known/ gesperrt, über das Zertifikate ausgestellt werden können", loesung: ".well-known ist in der .htaccess ausgenommen"}
    - {problem: "In der Arbeitsumgebung gibt es MySQL nur als 8.0, auf dem Webspace läuft 8.4", loesung: "Lokal gegen 8.0 getestet; der Prüf-Workflow testet gegen MySQL 8.4"}
    - {problem: "Erste Auslieferung nach dem Merge brach ab (curl 9, Verzeichniswechsel verweigert): der FTP-Zugang landet bereits im Ordner wintersymposium, der Workflow verlangte aber /wintersymposium/staging/. Zusätzlich lief das Prüfskript mit bash -e und stieg ohne verständliche Meldung aus", loesung: "Zielpfade auf /staging/ und /produktiv/ geändert (Auskunft Admin); Skript wertet curl-Codes selbst aus und meldet Zielordner nicht erreichbar, Anmeldung abgelehnt oder anderen Code – ohne Server-Meldungen und Ordnerlisten, weil die Logs öffentlich sind (R-10)"}
    - {problem: "Bei der Vorab-Prüfung wurde die Reihenfolge im Änderungsprotokoll (Abschnitt 11) für einen Widerspruch gehalten", loesung: "War keiner: unter der Erstfassung steht der neueste Eintrag zuerst; Hinweis über der Tabelle ergänzt"}

- id: AP-2
  titel: Design-Mockups und öffentliche Seiten
  ziel: "Freigegebenes Seitendesign; Startseite mit Infos und Programm, Impressum, Datenschutz im Allgäu-Akut-Design"
  abhaengig_von: [AP-1]
  umfang:
    - "Schritt 1 – Mockups (E-23): Startseite, Programm, Anmeldeformular, Einmal-Link-Seite, Team-Übersicht als statische HTML-Mockups (Handy und Desktop); Lila als Hauptakzent, Beispielseite in Signalrot; HALT: Freigabe durch den Admin vor Schritt 2"
    - "Schritt 2 – Design-Tokens (Farben, Schriften, Abstände) aus zina-branding/9 Technik; Schriften selbst ausgeliefert; Logo eingebunden"
    - Seitenrahmen mit Logo, Navigation, Fußzeile mit Impressum, Datenschutz und Quelltext-Link (E-25)
    - Startseite mit frei pflegbaren Infotexten (Ort, Anfahrt, Ablauf, CME-Hinweis) und Programm aus der Programm-Konfiguration (AP-3)
    - Rechtstexte Impressum und Datenschutz (pflegbar durch Betreiber); der Hoster setzt ein eigenes Cookie `_lcp` (gesehen auf Staging) – in der Datenschutzerklärung berücksichtigen
    - "Suchmaschinen: bis zum Anmeldestart tragen alle Seiten noindex (seit AP-1); für Produktion zum Start aufheben, Staging behält noindex"
  nadoku_bausteine: [rechtstexte_lib, rechtstext_seite]
  abnahmekriterien:
    - Mockups vom Admin freigegeben
    - Darstellung auf Handy und Desktop geprüft; Kontraste WCAG AA
    - keine externen Ressourcen zur Laufzeit
  status: abgeschlossen   # Schritt 1 freigegeben 2026-10-29 (Variante A, E-36); Schritt 2 im Code, auf Staging vom Admin abgenommen (2026-10-29); Pflege der Texte im Betreiber-Bereich folgt mit AP-5, Programmteil mit AP-3
  ergebnis:
    - "Mockups: drei Varianten erstellt, nach rund 60 Kommentaren des Admins zu Variante A ausgearbeitet und freigegeben; Seiten: Start, Workshop, Anmeldung, Einstieg/Login, Deine Anmeldung, Team (Übersicht, Personen, Statistik, Workshops, Programm, Mailings, Teilnahme); abgelegt in docs/mockups/ (mit Schriften und Logos)"
    - "Design im Code: server/public/assets/site.css mit Tokens aus zina-branding (Nachtblau, Lila, Signalrot, Grün, Blau-Stufen), Schriften Anton, Antonio, Tajawal, IBM Plex Mono als WOFF2 selbst ausgeliefert (OFL-Lizenzen beigelegt), Logo quer negativ lila/rot als SVG, Favicon und App-Symbol"
    - "Seitenrahmen (html_lib): Logo, Navigation (Desktop als Liste, Handy als aufklappbares Menü ohne JavaScript), Sprunglink zum Inhalt, Fußzeile mit Impressum, Datenschutz und Quelltext-Link; body.akzent-rot für Workshop-Seiten"
    - "Pflegbare Texte (Tabelle inhalt, Migration 2, inhalt_lib): kleine Auszeichnung (Absätze, ##-Überschriften, Listen, **fett**, *Akzent*, Links nur https/mailto), HTML wird immer maskiert; Startwerte aus den Mockups; Bearbeitung im Betreiber-Bereich mit AP-5"
    - "Startseite: Kicker, Titel, Untertitel, Sticker, Eckdaten-Band (erscheint, sobald die Veranstaltungs-Einstellungen aus AP-3 gesetzt sind), Infokästen, Programmabschnitt mit Hinweis bis AP-3, CME-Hinweis"
    - "Impressum und Datenschutz unter /impressum und /datenschutz; solange leer, Hinweis „wird gerade erstellt“; die Texte liefert der Admin (vor dem Anmeldestart, Checkliste Abschnitt 9)"
    - "Installer und Meldungsseiten im neuen Rahmen; noindex bleibt bis zum Anmeldestart"
  probleme:
    - {problem: "Desktop-Navigation unsichtbar: Inhalt eines geschlossenen <details> wird vom Browser nicht gerendert, auch nicht per CSS", loesung: "Liste zweimal ausgeben – als Liste daneben (Desktop) und im aufklappbaren Menü (Handy)"}
    - {problem: "Lila und rotes Logo erschienen gleichzeitig (Spezifität der CSS-Regeln)", loesung: "Logo-Regeln an .kopf gebunden; Screenshot-Prüfung Desktop und Handy"}
    - {problem: "Überschrift direkt über einer Textzeile (ohne Leerzeile) wurde im Textparser nicht erkannt", loesung: "Überschriften am Blockanfang werden abgetrennt; Test ergänzt"}
    - {problem: "Mehrere Mockup-Wünsche berühren spätere Arbeitspakete und Regeln (Ampelfarben, Grün als Fläche, Anwesenheit, Empfängerlisten)", loesung: "Als offene Punkte O-23 bis O-32 festgehalten; Klärung vor dem jeweiligen AP"}

- id: AP-3
  titel: Team-Login, Programm und Veranstaltungs-Einstellungen
  ziel: "Admins legen Zeitfenster, Workshops, Termine und Plätze frei an und setzen die Regeln der Veranstaltung"
  abhaengig_von: [AP-1]
  umfang:
    - Datenmodell Veranstaltung, Zeitfenster (Beginn/Ende frei, auch überlappend), Workshop, Workshop-Termin (Zeitfenster, Raum, Plätze), Programmpunkte ohne Anmeldung (Vorträge, Pausen)
    - "Veranstaltungs-Einstellungen (R-11): Anmeldezeitraum (Start 2026-11-25, Ende einstellbar), Gesamtkapazität (0 = unbegrenzt), Workshop-Limit je Person (0 = keins), Workshop-Wahl Pflicht/freiwillig, Nachrück-Annahmefrist (48 h), Grenze für automatisches Nachrücken (14 Tage vor dem Symposium), Stichtage für Workshop-Änderung, Essens-Änderung und Storno (E-31), Berufsgruppen-Liste (E-21), Veranstaltungsinfos mit CME-Angaben (E-22)"
    - Regel: überschneidende Termine gesperrt (E-20)
    - "Team-Login aus AP-5 vorgezogen (Admin 2026-10-29), damit das Team Programm und Einstellungen selbst pflegen kann: gemeinsame Konten-Tabelle mit Rollen (O-28, E-42) statt team_konto; Login mit Passwort und Pflicht-Zweitfaktor (E-11); Rollen Admin/Betreiber; Team-Bereich mit Reitern Programm und Einstellungen"
    - "Platzhalter entfernen: Eckdaten der Startseite kommen seit Schritt 2 aus den Einstellungen (erledigt); freie Plätze aus der Platzzählung (AP-4); Beispielprogramm durch das echte Programm ersetzt (Schritt 3, erledigt)"
    - "Ergänzt durch die Mockups: Programm-Modell, Typen, Dozierende, Workshop-Blöcke, Entwurf/Veröffentlichen, Team-Reiter „Programm“ (E-39); freie Plätze X/Y auf der Startseite (E-40)"
  abnahmekriterien:
    - Änderungen erscheinen sofort auf der Startseite
    - Plätze können nicht unter die Zahl bereits vergebener Plätze gesenkt werden (Warnung; Überhang bleibt bestehen)
    - jede Einstellung wirkt nachweislich (Prüfprotokoll)
    - "Schritt 1: ohne zweiten Faktor kein Zugriff auf den Team-Bereich; Admin sieht keine Team-Konten"
  schritte:
    - {nr: 1, titel: "Konten und Team-Login", status: "umgesetzt, auf Staging vom Admin genutzt (zweiter Faktor funktioniert)"}
    - {nr: 2, titel: "Veranstaltungs-Einstellungen", status: "umgesetzt, Staging offen"}
    - {nr: 3, titel: "Programm", status: "umgesetzt, Staging offen"}
  status: "umgesetzt (Schritt 1 bis 3), Abnahme auf Staging offen"
  ergebnis: "Schritt 1: Tabelle konto mit Rollen ersetzt team_konto (Migration 4, Konten mit gleicher id übernommen); Login mit Passwort und Pflicht-TOTP inkl. Einrichtung per QR-Code, Wiederherstellungscodes, Wiederholungsschutz; Einrichtungs-/Passwortlinks; Team-Bereich mit Übersicht, Mein Konto, Team-Konten (Betreiber); Rollenwechsel mit eigenem Code; Ratenschutz für Login und Codes; Notzugang mit Installationsschlüssel und Notweg auf der Kommandozeile; Login-Seiten von der Wartung ausgenommen. Details E-43. Schritt 2: Reiter „Einstellungen“ mit allen Veranstaltungs-Einstellungen nach Katalog (lib/veranstaltung_lib.php), Prüfung mit Fehleranzeige am Feld, Eckdaten der Startseite aus den Einstellungen (Platzhalter entfernt), Hinweis „Anmeldung ab …“. Details E-44. Schritt 3: Reiter „Programm“ mit Tagesplan, Zieh-Griffen, Formular je Typ, Terminen (+ Termin, Duplizieren), Dozierenden mit Foto, Vorschau, Verwerfen und Veröffentlichen; Startseite mit Popups, Zeitraster (Desktop) und Liste (Handy); Beispielprogramm entfernt; Test-Beispieldatensatz in tools/testdaten. Details E-45. Überschneidende Termine (E-20) und belegte Plätze wirken mit der Anmeldung (AP-4)"
  probleme:
    - {problem: "Kein Mailversand vor AP-6, neue Team-Mitglieder brauchen trotzdem einen Weg ins Konto", loesung: "Einrichtungslink wird dem Betreiber einmal angezeigt; er gibt ihn selbst weiter (E-43)"}
    - {problem: "NAdoku versiegelt mit eigenem Serverschlüssel in config.php; ein weiterer Eintrag hätte die Einrichtung auf Staging und Produktion erschwert", loesung: "Schlüssel per HKDF aus dem vorhandenen geheimnis abgeleitet (E-43)"}
    - {problem: "Bliebe der einzige Betreiber ohne Handy und Codes, käme niemand mehr in den Team-Bereich", loesung: "Notzugang im Browser mit dem Installationsschlüssel (O-34, E-43), dazu cli/konto.php für Server mit Kommandozeile"}
    - {problem: "Aktionen-Spalte der Konten-Tabelle sprang aus der Tabelle (Zelle als Flexbox)", loesung: "Flexbox in ein inneres Element verlegt; im Browser bei 390 und 1280 px geprüft"}
    - {problem: "Konten-Tabelle auf Tablet und Handy rechts abgeschnitten (Knöpfe halb verdeckt); der erste Browser-Durchlauf nahm scrollbare Tabellen von der Prüfung aus und sah es nicht, gefunden beim Ansehen der Bildschirmfotos", loesung: "Tabellen mit Klasse .karten unter 960 px als Karten; der Durchlauf prüft Tabellen jetzt mit (verdecktes Seitwärts-Scrollen ist ein Fehler); Gegenprobe ohne Karten meldet den Fehler in allen Engines"}
    - {problem: "Bitwarden erkannte das Code-Feld in Firefox nicht (Admin auf Staging); Firefox gibt autocomplete=one-time-code nicht als Eigenschaft weiter, Bitwarden sucht dann Stichwörter", loesung: "Feldname totp statt code (wie im Familien-Server); Browser-Durchlauf prüft das auf jeder Seite"}
    - {problem: "Mit dem Reiter „Einstellungen“ passten die Team-Reiter auf dem Handy nicht mehr in eine Zeile, „Team-Konten“ lag rechts außerhalb (Browser-Durchlauf, 36 Fehler)", loesung: "Reiter brechen in eine zweite Zeile um"}
    - {problem: "Für die Einstellungsseite gibt es kein Mockup", loesung: "aus vorhandenen Bausteinen gebaut (Kasten, Formularfelder, Team-Reiter); Freigabe beim Ansehen auf Staging"}
    - {problem: "Meldung nach dem Ziehen nannte nur das Ende („19:45“ statt „19:15–19:45“): in PHP gehört ein direkt folgendes „–“ (UTF-8) zum Variablennamen, \"$beginn–$ende\" las eine unbekannte Variable", loesung: "{$beginn}–{$ende}; HTTP-Test prüft den Meldungstext; Suche im ganzen Code nach Variablen mit direkt folgendem Nicht-ASCII-Zeichen (keine weiteren)"}
    - {problem: "Zeitraster ragte auf Tabletbreite rechts hinaus (Mindestbreite 640 px in einer gut 560 px breiten Spalte) und die letzte Skalen-Beschriftung stand halb über dem Rand", loesung: "Mindestbreite entfernt, Namensspalte schrumpft mit; Randbeschriftungen bündig"}
    - {problem: "Termintabelle im Formular rechts abgeschnitten (Duplizieren, entfernen, auf dem Handy die Plätze); ich hatte sie als scroll-erlaubt markiert und damit selbst von der Prüfung ausgenommen, gefunden beim Ansehen der Bildschirmfotos", loesung: "Termine als umbrechende Zeilen mit Beschriftung je Feld; die Prüfung auf Abgeschnittenes gilt jetzt für jeden Rahmen mit overflow (fand danach sofort die Skalen-Beschriftung)"}
    - {problem: "Kästchen (Dozierende, entfernen) bekamen Polsterung und Breite der Textfelder, weil die allgemeine Regel .feld input später im CSS steht", loesung: "eigene Regel für Kästchen in Formularfeldern"}
    - {problem: "Testbrowser zeigten Zeitfelder als 07:30 PM (Admin: wir brauchen das deutsche Format); Chromium und Firefox richten die Felder nach der Umgebung des Browserprozesses, nicht nach der Spracheinstellung", loesung: "Durchlauf startet die Browser mit LANG=de_DE.UTF-8 (Firefox mit Übernahme der Systemeinstellung); an einer Testseite in allen Engines verglichen, Bildschirmfotos zeigen 19:30 und 20.01.2027"}
    - {problem: "Playwright kann in WebKit unter Linux kein natives Ziehen auslösen", loesung: "dort löst der Durchlauf dieselben Drag-Ereignisse im Browser aus; Chromium und Firefox ziehen echt"}
    - {problem: "Test zum Wiederholungsschutz der Einmalcodes schlug zufällig fehl, wenn eine 30-s-Grenze zwischen zwei Aufrufen lag", loesung: "Code einmal erzeugen und wiederverwenden"}

- id: AP-4
  titel: Anmeldung, Einmal-Links, Warteliste und Nachrücken
  ziel: "Anmeldung ohne Konto, Verwaltung per Einmal-Link, Warteliste je Workshop-Termin mit Nachrück-Angeboten, Stichtage"
  abhaengig_von: [AP-3]
  umfang:
    - "Anmeldeformular: Felder nach E-21 (EFN nur bei Ärztin/Arzt, freiwillig, mit Prüfziffer, E-22), Essenswahl (E-10), Workshop-Wahl mit Regeln aus den Einstellungen, freiwilliges Newsletter-Häkchen (E-14), Einwilligung Datenschutz; Option „Konto anlegen“ (AP-4b)"
    - Spamschutz (Honeypot, Zeitstempel, Proof-of-Work, Ratenschutz, Wegwerfadressen)
    - Bestätigung per Mail (Double-Opt-In), danach gilt die Anmeldung; unbestätigte Anmeldungen verfallen nach einstellbarer Frist
    - Einmal-Link-Anforderung über Mailadresse (gleiche Antwort für alle Fälle); Link öffnet die eigene Anmeldung: Workshops und Essen ändern, stornieren, EFN nachtragen
    - "Stichtage (E-31): nach dem jeweiligen Stichtag sind Workshop-Änderung, Essens-Änderung bzw. Storno für Teilnehmende gesperrt, mit Hinweis auf den Stichtag; Nachrück-Angebote enden mit der Grenze aus E-19"
    - "Ergänzt durch die Mockups: Termine mit freier Dauer, ein fester Termin je Workshop, Warteliste für weitere Termine, Tausch mit Bestätigung (E-38); Einstiegsseite und Popups (E-40)"
    - "Mailversand-Grundlage aus AP-6 vorgezogen (E-46): SMTP, Warteschlange, Vorlagen der Anmelde-Mails"
    - "Warteliste je Workshop-Termin und für die Gesamtkapazität; Nachrück-Angebot nach E-19 (Angebot per Mail mit Einmal-Link, Annahmefrist, bei Ablauf Weitergabe und Person ans Ende der Liste mit Mail, Grenze für automatische Angebote, danach manuelle Vergabe durch das Team); alles in Transaktionen (R-04)"
  nadoku_bausteine: [registrieren.php (Bremsen), pow.js, email_lib, wegwerfdomains.txt, Token-Muster, einwilligung_lib]
  abnahmekriterien:
    - Paralleltest: gleichzeitige Anmeldungen auf den letzten Platz vergeben ihn genau einmal
    - Storno löst Angebot an die nächste Person aus; Ablauf der Frist gibt das Angebot weiter und setzt die Person ans Ende; nach der Grenze keine automatischen Angebote mehr
    - abgelaufene oder benutzte Links funktionieren nicht mehr
    - EFN mit falscher Prüfziffer wird abgelehnt; EFN-Feld erscheint nur bei Ärztin/Arzt
    - nach einem Stichtag ist die jeweilige Änderung für Teilnehmende gesperrt, für das Team nicht
  status: "umgesetzt, Abnahme auf Staging offen"
  ergebnis: "Anmeldeformular /anmelden (Felder nach E-21, EFN mit Prüfziffer, Essen, Workshop-Wahl mit fester Platz je Workshop und Wartelisten, Sperre bei Überschneidung, Newsletter-Wunsch, Einwilligung) mit Spamschutz; Bestätigung per Mail mit Frist (Einstellung); Einmal-Links (48 h, einmal, erst per Klick verbraucht); „Deine Anmeldung“ mit Einstieg (Link per Mail), Übersicht, Workshops/Essen/Daten ändern (neue Adresse erst nach Bestätigung), Storno, Nachrück-Angebote mit Tausch-Bestätigung; Stichtage am Knopf; Wartelisten je Termin und für das Symposium; Nachrücken mit Annahmefrist, Weitergabe ans Listenende und Grenze; Job „anmeldung“ (Fristen, Angebote, Nachrücken) und „mail“ (Warteschlange); Mailversand per SMTP mit Warteschlange (Grundlage aus AP-6); freie Plätze X / Y auf der Startseite und im Programm; Team-Reiter „Anmeldungen“ zum Lesen; Test-Beispieldatensatz um Anmeldungen erweitert. Details E-46"
  probleme:
    - {problem: "AP-4 braucht Mails (Bestätigung, Links, Angebote), der Versand stand erst in AP-6", loesung: "Grundlage vorgezogen (Admin 2026-10-29, E-46); Zugangsdaten vorerst in config.php"}
    - {problem: "„Verwerfen“ im Programm löscht alle Programmpunkte und legt sie neu an; ein Fremdschlüssel von Buchungen auf Termine hätte das blockiert oder Buchungen mitgelöscht", loesung: "Plätze beziehen sich auf den veröffentlichten Stand (ohne Fremdschlüssel); Veröffentlichen prüft unter der Platzsperre, dass belegte Termine bleiben"}
    - {problem: "Mail-Scanner öffnen Links vorab und hätten Einmal-Links entwertet", loesung: "Link-Seite verbraucht erst beim Klick auf „Weiter“ (POST)"}
    - {problem: "Gleichzeitige Anmeldungen auf den letzten Platz", loesung: "eine MySQL-Sperre für alle Platzvergaben, darin die Transaktion; Paralleltest mit 20 Prozessen: genau 1 Platz, 19 Warteliste (T-137)"}
    - {problem: "Ohne eingetragenen Absender ging „From: <>“ hinaus", loesung: "Rückfall anmeldung@ der Haupt-Domain (E-13)"}
    - {problem: "Browser-Durchlauf: Honigtopf außerhalb des Bildes galt als abgeschnitten; langer Wartelisten-Chip ragte auf dem Handy aus der Übersicht", loesung: "Honigtopf ganz ausgeblendet (display: none); Chips in der Übersicht umbrechen"}

- id: AP-4b
  titel: Konten für Teilnehmende
  ziel: "Optionales Konto mit Profil (E-30); Einmal-Links bleiben der Weg ohne Konto"
  abhaengig_von: [AP-4]
  termin: "Ziel 2026-11-25; Plan B nach E-33: unmittelbar nach dem Anmeldestart"
  umfang:
    - Registrierung mit E-Mail und Passwort (password_hash), Bestätigung per Mail; Spamschutz wie beim Anmeldeformular
    - Konto anlegen beim Anmelden, nachträglich über den Einmal-Link oder ohne Symposium-Anmeldung
    - Login, Passwort vergessen (Token-Muster), Logout; Ratenschutz; kein zweiter Faktor
    - "Profil: E-Mail ändern (neue Adresse erst nach Bestätigung gültig, Anmeldung und Newsletter ziehen mit), Passwort ändern, Berufsgruppe, Institution, EFN; Anmeldung, Workshops, Essen und Newsletter-Listen verwalten (gleiche Regeln und Stichtage wie per Einmal-Link); Konto löschen"
    - Team-Bereich: Konten sehen, mit Anmeldung verknüpfen, Passwort-Reset auslösen, sperren, löschen
  nadoku_bausteine: [Token-Muster, ratelimit_lib, Login-Muster (klassisch, ohne E2E)]
  abnahmekriterien:
    - Anmeldung ohne Konto funktioniert unverändert
    - Konto nachträglich über Einmal-Link angelegt → Anmeldung ist im Profil sichtbar
    - E-Mail-Änderung ohne Bestätigung bleibt wirkungslos
    - Stichtage gelten im Profil genauso wie per Einmal-Link
  status: "umgesetzt, Abnahme auf Staging offen"
  ergebnis: "Konten für Teilnehmende: anlegen beim Anmelden (Häkchen), aus „Deine Anmeldung“ oder ohne Anmeldung (/konto/neu); Bestätigung per Mail; Passwort mindestens 10 Zeichen; gemeinsamer Login (/einloggen und Kasten auf „Deine Anmeldung“, Team weiter zum zweiten Faktor); „Mein Konto“ mit Profil, neuer Adresse, Passwort ändern, Konto löschen mit Wahl; „Passwort vergessen“; automatische Verknüpfung mit der Anmeldung; eingeloggt anmelden ohne zweite Bestätigung; Team-Liste mit Spalte Konto; Migration 7. Details E-48"
  probleme:
    - {problem: "Der Login für alle soll unter /einloggen und im Kasten auf „Deine Anmeldung“ gehen, Team und Teilnehmende haben aber getrennte Sitzungen (Cookies) und CSRF-Token", loesung: "Login-Prüfung als gemeinsame Funktion; nach dem Passwort wechselt die Seite in die passende Sitzung (sitzung_wechseln), das CSRF-Token der aufrufenden Seite ist da schon geprüft"}
    - {problem: "Bestehender Test zählte alle Konten; mit Leas Teilnehmenden-Konto stimmte die Zahl nicht mehr", loesung: "Test zählt nur Team-Konten"}
    - {problem: "Testadresse alt@example.org war in den Tests schon für ein Team-Konto vergeben, das Aufräumen unbestätigter Konten schien zu versagen", loesung: "eigene Testadresse; Aufräumen löscht nachweislich nur unbestätigte Teilnehmenden-Konten"}

- id: AP-5
  titel: Team-Bereich
  ziel: "Team meldet sich sicher an, sieht Überblick und Statistik und verwaltet Anmeldungen"
  abhaengig_von: [AP-4, O-14]
  umfang:
    - "Team-Login und Rollen: mit AP-3 Schritt 1 vorgezogen (E-43); Passkeys (O-14) und Team-Mails (Einladung, Passwort vergessen) später mit AP-6 (Admin 2026-10-29)"
    - Übersicht (Startseite des Team-Bereichs): Kennzahlen, offene Aufgaben (manuelle Vergabe nach der Nachrück-Grenze, Mail-Fehler)
    - "Statistik-Seite (Admin 2026-10-29): alles einsehbar – Anmeldungen gesamt und Verlauf, je Workshop-Termin (belegt/frei/Warteliste/offene Angebote), Essenszahlen, Berufsgruppen, Institutionen, EFN-Quote, Newsletter-Listen, Konten, Anwesenheit (nach AP-10); Diagramme, Filter nach Status, Export jeder Ansicht"
    - Anmeldungen suchen, ansehen, ändern, stornieren, manuell anlegen, Plätze von der Warteliste manuell vergeben – auch nach den Stichtagen (E-31)
    - Export (CSV): Teilnehmerliste, Listen je Workshop-Termin, Essensliste, CME-Liste (Name, EFN)
    - Betreiber-Einstellungen: Team-Konten, Hostnamen und Absenderadressen (E-27), SMTP-Postfächer, Rechtstexte
    - Audit-Protokoll aller Änderungen durch das Team
    - "Ergänzt durch die Mockups: Reiter-Gliederung, Personen, Workshops (Überbuchen mit Bestätigung), Statistik mit Export-Kachel, Rollenwechsel mit zweitem Faktor (E-41); Bearbeitung der pflegbaren Texte und Rechtstexte (Tabelle inhalt, AP-2)"
  nadoku_bausteine: [totp_lib (Kern), passkey_lib (O-14), serverkrypto_lib (sk_versiegeln), Rollen- und Listenmuster aus admin_users.php]
  abnahmekriterien:
    - ohne zweiten Faktor kein Zugriff auf den Team-Bereich
    - Admin kann keine Team-Konten, Betreiber-Einstellungen oder Rechtstexte ändern
    - Zahlen auf der Statistik-Seite stimmen mit der Datenbank überein
    - Team kann nach einem Stichtag ändern, Teilnehmende nicht
  status: "umgesetzt, Abnahme auf Staging offen"
  ergebnis: "Übersicht mit Kennzahlen und „Zu erledigen“; Personen (Suche, Filter, Bearbeiten auch nach Stichtagen, Überbuchen und Tausch mit Bestätigung, Person anlegen, Einmal-Link, Konto-Aktionen, Rolle); Workshops (Termine, Teilnehmende, Warteliste, Platz geben, Angebot, Entfernen); Statistik mit Filter und SVG-Balken; CSV-Exporte; Texte (Startseite, Rechtstexte für Betreiber); Protokoll; Betrieb mit Hostnamen und Absender. Details E-49"
  probleme:
    - {problem: "Tabellen seit AP-4 nutzten data-titel statt data-label; in der Kartenansicht auf schmalen Bildschirmen fehlten deshalb die Spaltenbeschriftungen", loesung: "auf data-label umgestellt (gefunden beim Ansehen der Bildschirmfotos)"}
    - {problem: "Tabellen in der schmalen Listenspalte von Personen und Workshops waren auf dem Desktop zu breit", loesung: "dort immer Kartenansicht; ausgewählte Person bzw. Termin steht auf schmalen Bildschirmen über der Liste"}
    - {problem: "Die Prüfung auf Abgeschnittenes meldete nur für Screenreader sichtbare Beschriftungen (.sr) und Eingabefelder mit langem Text", loesung: "beide aus dieser Prüfung genommen – sie sind absichtlich so"}
    - {problem: "Diagramme brauchen Breiten, die Sicherheitsrichtlinie verbietet Inline-Stile", loesung: "Balken als SVG mit width-Attribut"}

- id: AP-6
  titel: Mailversand und Rundmails
  ziel: "Zuverlässiger Versand mit Prioritäten und Tageslimit; Rundmails an Teilnehmende"
  abhaengig_von: [AP-1, O-02]
  umfang:
    - "SMTP-Client, Warteschlange mit Priorität und Tageslimit, Wiederholungen und die Vorlagen der Anmelde-Mails sind mit AP-4 vorgezogen (E-46); Absender je Postfach in der Oberfläche, Zugangsdaten bleiben in config.php (Admin 2026-10-29/30, E-50)"
    - "Team-Mails aus AP-5 übernommen: Einladung und „Passwort vergessen“ für Team-Konten"
    - Warteschlange mit Priorität (System vor Rundmail/Newsletter), Tageszähler je Postfach, Wiederholungen, Inhalt nach Versand leeren
    - Mail-Vorlagen: Bestätigung, Einmal-Link, Änderung, Storno, Nachrück-Angebot, Angebot abgelaufen, Platz erhalten, Wartelistenplatz, Zertifikat verfügbar
    - Rundmail an Teilnehmende mit Filter (alle, je Workshop-Termin, Warteliste, Berufsgruppe); Vorschau und Testversand an sich selbst
    - Anzeige im Team-Bereich: Warteschlange, Tageskontingent, Fehler
    - "Ergänzt durch die Mockups: Mail-Editor mit selbst gehosteten, eingebetteten Bildern, eingeschränktes HTML, Archiv gesendeter Mails (E-41); Abgrenzung Mailings/Newsletter O-29"
  nadoku_bausteine: [smtp.php, mail_lib (Muster), jobs_lib]
  abnahmekriterien:
    - Rundmail über dem Tageslimit wird auf Folgetage verteilt, Bestätigungsmails gehen trotzdem sofort raus
    - Mails kommen bei gängigen Anbietern an (Gmail, GMX/Web.de, Outlook) und landen nicht im Spam
  status: "umgesetzt, Abnahme auf Staging offen"
  ergebnis: "Reiter „Mailings“: Editor mit Bildern und Platzhaltern, Empfängerauswahl, Vorschau, Testmail, Planen, Senden, Archiv mit Empfängerliste; zweites Postfach newsletter@ mit eigenem Tageslimit; Rundmails verteilen sich auf mehrere Tage, Bestätigungen gehen vor; Einladung und „Passwort vergessen“ für Team-Konten per Mail; Absender je Postfach unter „Betrieb“. Details E-50. Zustellbarkeit (T-165) prüft der Admin auf Staging"
  probleme:
    - {problem: "Sicherungen sind JSON-Zeilen; Bilder als Binärdaten in der Datenbank wären darin beschädigt worden", loesung: "Bilder als Dateien neben den Fotos der Dozierenden, in der Sicherung wie diese base64-kodiert; Prüfung der Sicherung kennt die neuen Dateinamen"}
    - {problem: "Ein Einmal-Link für {link} wäre bei Mailings über mehrere Tage schon abgelaufen", loesung: "Mail erst beim Versand zusammensetzen; die Warteschlange enthält nur Verweis auf Mailing und Anmeldung"}
    - {problem: "WebKit auf dem Handy: lange Einträge in Auswahlfeldern machten die Seite breiter als den Bildschirm", loesung: "Formularfelder mit begrenzter Spalte (minmax), Termine als Gruppen mit kurzen Einträgen, Absender-Adressen als Hinweis unter dem Feld"}
    - {problem: "Die Vorschau braucht Stile im HTML, die Sicherheitsrichtlinie der Seiten verbietet sie", loesung: "eigene Seite für die Vorschau mit eigener Richtlinie (Stile ja, Skripte nein), im iframe mit sandbox"}

- id: AP-7
  titel: Newsletter
  ziel: "Mehrere Newsletter-Listen mit Double-Opt-In, unabhängig von der Symposium-Anmeldung"
  abhaengig_von: [AP-6]
  umfang:
    - Listen anlegen (mind. „Wintersymposium“, „Allgäu Akut“) mit Absender je Liste (E-27); öffentliches Anmeldeformular mit Listenauswahl und Spamschutz
    - Double-Opt-In, Abmeldelink je Mail und List-Unsubscribe, Verwaltung der eigenen Listen per Einmal-Link
    - Verzahnung: Häkchen bei der Symposium-Anmeldung trägt nach Bestätigung in die Liste ein
    - Versand an eine oder mehrere Listen über die Warteschlange (E-13); Nachweis der Einwilligung (Zeitpunkt, Fassung)
  abnahmekriterien:
    - ohne Bestätigung kein Newsletter-Versand
    - Abmeldung wirkt sofort und ohne Login
  status: "umgesetzt, Abnahme auf Staging offen"
  ergebnis: "Listen mit Double-Opt-In und Nachweis der Einwilligung; Seite /newsletter, Bestätigen, Verwalten, Abmelden mit einem Klick (auch List-Unsubscribe One-Click); Häkchen bei der Anmeldung mit eigener Bestätigungsmail; Mein Konto; Reiter „Newsletter“ (Listen, Abonnierende, Einladen); Versand über Mailings an eine oder mehrere Listen; Statistik und CSV. Details E-52"
  probleme:
    - {problem: "Die Empfänger-Felder im Mailings-Editor hießen data-art – derselbe Name, den der einheitliche Dialog für seine Farbe nutzt", loesung: "umbenannt in data-empfaenger, damit sich die Skripte nicht in die Quere kommen"}
    - {problem: "Ein Abmelde-Link braucht kein Login, darf aber nicht von Dritten erraten werden", loesung: "zufällige Tokens (48 Hex-Zeichen), nur als Hash gespeichert, je Mail neu; One-Click per POST ohne Sitzung"}

- id: AP-8
  titel: Kaneo-Instanz
  status: entfallen   # E-15: bestehendes Kaneo wird mitgenutzt; Einladung des Teams durch den Admin

- id: AP-10
  titel: Teilnahme und Zertifikate
  ziel: "Team erfasst die Anwesenheit; Teilnehmende laden danach ihr CME-Teilnahmezertifikat als PDF herunter"
  abhaengig_von: [AP-5, AP-6, O-16, O-19]
  termin: Anwesenheitserfassung einsatzbereit zum 2027-01-20
  umfang:
    - Anwesenheit je Anmeldung erfassen (Liste mit Suche, auch auf dem Handy bedienbar; Massenaktion „alle anwesend“ mit Ausnahmen)
    - "Zertifikat: Entwurf vom Admin (E-22); die App setzt Name, Datum, ggf. EFN darauf; Erzeugung in PHP ohne Composer (O-19)"
    - Freischaltung durch das Team (einzeln oder alle); Mail „Zertifikat verfügbar“ mit Einmal-Link; Download über die Einmal-Link-Seite
    - CME-Export für die Meldung an die Ärztekammer (Name, EFN, Anwesenheit)
    - "Ergänzt durch die Mockups: PDF-Listen (Unterschriftenliste mit EFN-Barcode, Workshop- und Wartelisten, Sortierung) (E-41); Art der Anwesenheitserfassung O-31, Barcode-Format O-30"
  abnahmekriterien:
    - ohne erfasste Anwesenheit kein Zertifikat
    - PDF öffnet in gängigen Viewern, Name und Umlaute korrekt
  status: "umgesetzt 2026-10-30, Abnahme auf Staging offen (E-57)"
  ergebnis: "Reiter Teilnahme (Anwesenheit je Symposium und Workshop, nur Ausnahmen), Unterschriftenliste mit EFN-Barcode, CME-Liste nur Anwesende, Zertifikat nach Entwurf C mit Unterschrift (PNG), Freischaltung einzeln oder alle mit Mail, Download unter „Meine Anmeldung“; Migration 14; Prüfungen T-190 bis T-195, T-251 bis T-253"
  probleme:
    - {problem: "Text nach gedrehten Elementen im PDF in falscher Farbe (unsichtbar)", loesung: "Nach jeder Drehung Farben, Linienstärke und Schrift neu ausgeben (export_lib)"}
    - {problem: "Rundmails blieben liegen, sobald am Tag 4–9 Mails verschickt waren: Tageslimit wurde in SQL als Text verglichen (Fehler seit AP-6)", loesung: "Vergleich in PHP, Regressionstest in lauf.php"}
    - {problem: "Neues Pflichtfeld „Name“ hätte gespeicherte Einstellungen und Testdaten ungültig gemacht", loesung: "Feld optional, leer gilt „Wintersymposium“"}
    - {problem: "Ein Entwurf als PDF-Vorlage (O-19) lag nicht vor", loesung: "Zertifikat nach dem gewählten Entwurf C direkt mit tFPDF gezeichnet; keine Vorlage, kein FPDI nötig"}

- id: AP-11
  titel: Mehrere Veranstaltungen
  ziel: "Weitere Veranstaltungen (Symposien und Abende) mit demselben Grundmuster; immer eine aktuelle, vergangene als Rückblick; Profil zeigt besuchte Veranstaltungen"
  abhaengig_von: [AP-4, AP-4b, AP-5, AP-10]
  umfang:
    - "Veranstaltung als Rahmen mit Kürzel und Status; Einstellungen, Texte, Programm, Anmeldungen, Plätze, Mailings, Teilnahme je Veranstaltung (E-58)"
    - "Team: Auswahl im Kopf, Seite Veranstaltungen (anlegen, kopieren, aktuell machen, abschließen), Schreibschutz für abgeschlossene"
    - "Öffentlich: /<kürzel>, Rückblick für abgeschlossene; Mein Konto mit besuchten Veranstaltungen und Zertifikaten; Daten übernehmen für Wiederkehrende"
    - "Bausteine je Veranstaltung: Essen, Fortbildungspunkte, Teilnahmebescheinigung"
  status: "umgesetzt 2026-10-31, Abnahme auf Staging offen (E-58)"
  ergebnis: "Migration 15 (bestehende Daten = Veranstaltung 1 „wintersymposium2027“); Kontext je Anfrage; Seite Veranstaltungen; Archiv unter dem Kürzel; Rückblick im Konto; Daten übernehmen per Link; Prüfungen T-254 bis T-259"
  probleme:
    - {problem: "Termin 0 (Platz beim Symposium) gibt es in jeder Veranstaltung – Belegung und Wartelisten hätten sich vermischt", loesung: "platz.veranstaltung_id und Filter in allen Abfragen"}
    - {problem: "Verwerfen, Veröffentlichen und Nachrücken wirkten auf das ganze Programm", loesung: "auf die Veranstaltung des Kontexts beschränkt; Eingriffe in fremde Programmpunkte abgewiesen"}
    - {problem: "Einstellungen werden an sehr vielen Stellen gelesen", loesung: "einstellung() leitet Veranstaltungs-Schlüssel an va_einstellung weiter"}
    - {problem: "Feste Texte „Wintersymposium“ in Mails und Kopfzeilen", loesung: "Kurzname je Veranstaltung"}

- id: AP-16
  titel: "Review-Behebung 2: restliche Bugs und kleine Sicherheitspunkte (Review AP-R2)"
  ziel: "Alle übrigen bestätigten Funde des Reviews beheben, die kein eigenes Paket brauchen; jeder Fund zuerst mit Test belegt (rot), dann behoben (grün)"
  quelle: "docs/review-2026-10-31.md (B-4, B-6, B-7, B-8, B-10, B-12, B-14, S-3, S-4, S-5, S-6, S-9, S-11, S-12; dazu V-4 als Helfer); Entscheidungen E-63"
  abhaengig_von: [AP-14]
  nicht_enthalten:
    - "S-7 (Aussperren eines Kontos durch 5 falsche Passwörter): Zielkonflikt, Entscheidung O-47"
    - "S-8 (Cron-Token in der URL): durch E-64 (2) und AP-15 erledigt"
    - "S-10 (Open Redirect in der .htaccess vor der Host-Prüfung): bleibt; wirkt nur bei Klartext-HTTP mit gefälschtem Host, HSTS ist gesetzt; ein fester Hostname in der .htaccess ginge wegen mehrerer Hosts (E-27) nicht"
  reihenfolge: "Unterpunkte 1 bis 9 nacheinander; ein PR"
  umfang:
    - nr: 1
      fund: B-4
      titel: "Sitzungseintrag der Anmeldung an die Veranstaltung binden"
      loesung:
        - "$_SESSION['anm'] bekommt 'va' (Veranstaltungs-ID); anm_aktuell() verwirft den Eintrag, wenn 'va' nicht zu va_id() passt, und leitet dann neu ab; die automatische Ableitung aus Konto oder Team-Adresse läuft nur, wenn va_id() === va_aktuell_id() (öffentliche Seiten der aktuellen Veranstaltung), nie auf Team-Seiten oder Archivseiten /<kürzel>"
        - "anm_einloggen() (Einmal-Link) setzt 'va' aus der Anmeldung; Archivseiten zeigen eine fremde Anmeldung nur über tk_veranstaltungen (Konto), nicht über die Sitzung"
      test: "lauf.php: Konto mit Anmeldung in VA 1 (abgeschlossen) und ohne in VA 2 (aktuell): im Kontext VA 2 liefert anm_aktuell() null; Eintrag mit va=1 im Kontext 2 wird verworfen; http_tests: Team-Mitglied wechselt im Team-Bereich auf die alte VA, öffnet /deine-anmeldung → zeigt die aktuelle VA"
    - nr: 2
      fund: B-6
      titel: "Newsletter speichern lässt ausgeblendete Listen unberührt"
      loesung:
        - "nl_listen_setzen(): $weg nur aus den sichtbaren Listen (nl_listen(true)) bilden; Abos ausgeblendeter Listen bleiben"
        - "konto.php Aktion newsletter: ein leeres $ids meldet nur von sichtbaren Listen ab (nl_listen_setzen mit []), nicht nl_abmelden() für alle; newsletter_verwalten.php dieselbe Funktion"
      test: "lauf.php: Person mit Abo auf sichtbarer Liste A und ausgeblendeter Liste B speichert nur A → B bleibt; speichert nichts → A weg, B bleibt"
    - nr: 3
      fund: "B-7, S-4, V-4"
      titel: "Bestätigung des zweiten Faktors als ein Helfer, für alle Faktoren und mit Ratenschutz"
      loesung:
        - "totp_lib: zweitfaktor_bestaetigen(int $kontoId, string $code): ?string prüft TOTP, Mail-Code (wenn gerade einer gilt) und Wiederherstellungscode, mit Ratenschutz Topf zweitfaktor je Konto und den bisherigen Fehltexten aus einer Stelle (rate_text); bei Erfolg $_SESSION['zf_bestaetigt'] = ['konto' => id, 'bis' => jetzt + 300]"
        - "zweitfaktor_bestaetigt(int $kontoId): bool liest das Sitzungsfeld (einmal gültig, wird beim Lesen gelöscht); die Seiten team_daten (Sicherung, Wiederherstellen), team_personen (Rolle vergeben), team_konten (Rolle, Zurücksetzen, Sperren) und konto_zweitfaktor nutzen nur noch diese beiden Funktionen"
        - "Auf diesen Seiten gibt es neben dem Code-Feld „Code per E-Mail schicken“ (POST, mailcode_senden, höchstens einer je Minute je Konto) und, wenn Passkeys vorhanden, „Mit Passkey bestätigen“: neue Aktion bestaetigen in passkey.php (Challenge an das Konto gebunden), die bei Erfolg dasselbe Sitzungsfeld setzt und die Seite neu lädt; die Texte sagen „Code aus der App, aus der Mail oder ein Wiederherstellungscode“"
        - "konto.php Passwort ändern und Konto löschen: Passwortprüfung mit Ratenschutz Topf login je Konto (wie konto_zweitfaktor) über einen Helfer passwort_bestaetigen(array $k, string $pw): ?string in anmeldung_lib"
        - "Doku betrieb.md: „Code aus der App“ durch „zweiter Faktor“ ersetzen"
      test: "lauf.php: Konto nur mit Mail-Code: zweitfaktor_bestaetigen mit gültigem Mail-Code → null, Feld gesetzt; 5 falsche Codes → gesperrt; Konto nur mit Passkey: Aktion bestaetigen setzt das Feld; Passwort ändern mit 5 falschen alten Passwörtern → gesperrt; http_tests: Sicherung anlegen mit Mail-Code"
    - nr: 4
      fund: "S-3 (B-11 ist schon in 0.21.4 behoben)"
      titel: "Team-Mitglieder bekommen nur eigene, bestätigte Anmeldungen"
      loesung:
        - "anm_aktuell(): für Team-Konten nur die verknüpfte Anmeldung (konto_id) oder eine bestätigte mit gleicher Adresse übernehmen; unbestätigte Anmeldungen mit der Team-Adresse werden nicht übernommen und auf „Meine Anmeldung“ nicht als eigene gezeigt"
      test: "lauf.php: unbestätigte Anmeldung mit Team-Adresse → anm_aktuell() null für das Team-Konto; bestätigte → übernommen"
    - nr: 5
      fund: "B-8, B-12, B-14, S-11"
      titel: "Kleine Korrekturen in Programm, Mailings, Konto, Protokoll"
      loesung:
        - "B-8 foto_aufraeumen(): als genutzt gelten dozent.foto und die Fotos aller veröffentlichten Stände (programm_stand aller Veranstaltungen, inkl. termine[].dozenten)"
        - "B-12 rm_abbrechen(): die Empfänger der gelöschten mail_ausgang-Einträge aus rundmail_empfaenger entfernen, empfaenger_zahl danach aus der Tabelle zählen"
        - "B-14 tk_profil_setzen() gibt Fehler ohne Feldbezug unter dem Schlüssel '_' zurück; konto.php zeigt solche Fehler als Meldung; rm_speichern() prüft rm_laden() auf null (Fehlertext statt Warnung)"
        - "S-11 tn_setzen(): Protokolltext ohne Namen („Anmeldung N: anwesend gesetzt“), wie die übrigen Einträge (R-02)"
      test: "lauf.php: Foto in abgeschlossener VA bleibt nach foto_aufraeumen(); Abbrechen eines Mailings mit 3 offenen von 5 → Empfängerliste 2; tk_profil_setzen auf gelöschte Anmeldung → Fehler sichtbar; Protokolltext ohne Namen"
    - nr: 6
      fund: B-10
      titel: "Kein Rückfall auf Veranstaltung 1"
      loesung:
        - "va_id() ohne Kontext und ohne aktuelle Veranstaltung wirft keine Zahl mehr, sondern die öffentlichen Seiten zeigen „Derzeit keine Veranstaltung“ (Startseite: Eckdaten und Anmeldung ausgeblendet; /anmelden und /deine-anmeldung mit Hinweis); anm_zeitraum() liefert dann 'geschlossen'"
        - "va_loeschen(): die letzte verbliebene Veranstaltung lässt sich nicht löschen (Fehlertext)"
      test: "lauf.php: alle Veranstaltungen abgeschlossen → Startseite 200 mit Hinweis, anm_anlegen() liefert Fehler; Löschen der letzten → Fehler"
    - nr: 7
      fund: "S-5, S-9"
      titel: "Mailing-Bilder nur für Berechtigte; Mail-Codes je Konto begrenzen"
      loesung:
        - "S-5 team_mailings_bild.php: Bild nur ausliefern, wenn es zu einem Mailing gehört, das rm_sichtbar() (aus AP-14 Unterpunkt 2) erlaubt, oder erstellt_von das eigene Konto ist; sonst 404"
        - "S-9 mailcode_senden(): höchstens ein Versand je Minute und 10 je Stunde je Konto, gezählt in der Tabelle rate_limit (Topf mailcode, Merkmal konto:id); einloggen.php und AP-16 Unterpunkt 3 nutzen nur diese Funktion, die Minuten-Sperre aus der Sitzung entfällt"
      test: "http_tests: Admin mit eingeschränkter VA ruft Bild eines Newsletter-Mailings → 404; lauf.php: 11 Mail-Codes in einer Stunde → der 11. wird nicht verschickt"
    - nr: 8
      fund: "S-6, S-12"
      titel: "Prüfungen für Proxy und Sitzungs-ID"
      loesung:
        - "S-6 betrieb_status() (AP-15) Zeile Umgebung: Warnung, wenn REMOTE_ADDR im privaten Bereich liegt (10/8, 172.16/12, 192.168/16, 127/8) oder X-Forwarded-For gesetzt ist, während https_ueber_proxy aus ist – Text: „Anfragen kommen über einen Proxy; der Ratenschutz zählt dann alle Besucher zusammen. Beim Hoster klären.“ Falls AP-15 noch nicht umgesetzt ist, steht die Prüfung in health.php"
        - "S-12 Test: Keks mit erfundener, gültig geformter Sitzungs-ID → nach sitzung_starten() hat die Sitzung eine andere ID (use_strict_mode wirkt); kein Codeumbau nötig, wenn der Test besteht; sonst session_id() nicht mehr von Hand setzen"
      test: "lauf.php: beide Prüfungen wie beschrieben; Staging: Status-Seite zeigt in Zeile Umgebung keine Proxy-Warnung (T-296 durch den Admin)"
    - nr: 9
      titel: "Abschluss"
      loesung:
        - "Version, CHANGELOG (Behoben mit den Nummern aus dem Review), betrieb.md (zweiter Faktor bei Bestätigungen, keine Veranstaltung), Prüfprotokoll T-288 bis T-296, Konzept AP-16 status/ergebnis/probleme; Review-Dokument Abschnitt 5: behoben am …"
        - "Beispieldatensatz: eine unbestätigte Anmeldung mit Team-Adresse und ein Konto mit Abo auf einer ausgeblendeten Liste ergänzen"
        - "Browser-Durchlauf R-12: Bestätigung mit Mail-Code und Passkey, Startseite ohne Veranstaltung"
      test: "php tools/tests/lauf.php und node tools/browser/durchlauf.js ohne Fehler; Zahlen in PR und Prüfprotokoll"
  status: "geplant 2026-10-31 (Konzept); Umsetzung in eigener Chat-Instanz nach AP-14"
  ergebnis: null
  probleme: []

- id: AP-15
  titel: "Status-Seite unter Betrieb"
  ziel: "BetreiberInnen sehen auf einer Seite, ob der Betrieb läuft (Cron, Jobs, Mail, Datenbank, Sicherung, Fehlerprotokoll, Umgebung) und wie der Cron einzurichten ist; dieselben Daten liefert /health als JSON"
  quelle: "Wunsch des Admins 2026-10-31; Review S-8 (Cron-Token), V-14 (DB-Abfragen in Seiten); Entscheidungen E-65"
  abhaengig_von: [AP-13]
  reihenfolge: "Unterpunkte 1 bis 6 nacheinander; ein PR; unabhängig von AP-14"
  umfang:
    - nr: 1
      titel: "Datenquelle betrieb_status() (neue lib betrieb_lib.php)"
      loesung:
        - "Eine Funktion betrieb_status(?int $jetzt = null): array liefert alle Zeilen als Liste von ['id', 'titel', 'ampel' => ok|warnung|fehler, 'text', 'details' => array, 'link' => ?string]; Grenzwerte als Konstanten der Lib (technische Werte, R-11 Ausnahme): Cron gelb ab 15 min, rot ab 60 min ohne Lauf; Sicherung gelb ab 6 h; Fehlerprotokoll gelb, sobald es Einträge seit dem letzten Leeren gibt"
        - "Zeilen: cron (letzter Lauf, Auslöser cli/url, Alter), jobs (je Job letzter Lauf, Dauer, letzter_fehler), mail (eingerichtet, offen, fehler, heute gesendet je Postfach gegen Limit – aus mail_stand()), datenbank (migration_ist/soll, Wartungsmodus), sicherung (Alter der letzten automatischen und der letzten von Hand), fehlerlog (Größe, Zeilen seit dem letzten Leeren, letzte 30 Zeilen nur auf Abruf), umgebung (Version, Umgebung, PHP-Version, Schreibrechte der Laufzeitordner sitzungen, sicherungen, fotos, zertifikat, logs)"
        - "health.php nutzt betrieb_status() und gibt die Zeilen als JSON (ohne Logzeilen und ohne Token) aus; eigene Abfragen dort entfallen; status = fehler, sobald eine Zeile rot ist (HTTP 200 bleibt, damit Monitoring den Text liest)"
      test: "lauf.php: Zeilen mit künstlichem jetzt: Cron vor 20 min → warnung, vor 2 h → fehler; Job mit letzter_fehler → fehler; Sicherung 7 h alt → warnung; Schema ist < soll → fehler; health liefert dieselben Ampeln"
    - nr: 2
      titel: "Seite /team/status (Betrieb → Status, nur BetreiberInnen, erste Seite im Reiter)"
      loesung:
        - "team_status.php mit team_tor(['betreiber']); Ampel-Liste als .liste-def-Zeilen mit Chip (ok grün, warnung lila, fehler rot) und Text; Details je Zeile aufklappbar (details/summary); Eintrag in ROUTEN und TEAM_BEREICHE vor „Veranstaltungen“"
        - "Rote und gelbe Zeilen erscheinen oben zusammengefasst („2 Punkte brauchen Aufmerksamkeit“), grüne darunter; alles grün: eine Zeile „Alles in Ordnung, Stand …“"
        - "Knopf „Jetzt prüfen“ lädt die Seite neu (kein Zwischenspeicher, Cache-Control no-store ist Standard)"
      test: "http_tests: Admin → 403; Betreiber → 200 mit allen sieben Zeilen; Browser-Durchlauf: Seite in 3 Engines × 4 Breiten, Aufklappen einer Zeile"
    - nr: 3
      titel: "Cron-Anleitung mit Kopieren (E-65 (2))"
      loesung:
        - "In der Cron-Zeile aufklappbar: Shell-Cronjob (Standard): Zeitplan */5 * * * * und Kommando php <Zielordner>/cli/jobs.php, Zielordner aus laufzeit_pfad-Basis (dirname des Installationsordners) vorbelegt; Knopf „Kopieren“ (assets/status.js, navigator.clipboard mit Rückmeldung „Kopiert“, ohne Clipboard-API: Feld markieren)"
        - "Ausweichweg URL-Cronjob: URL https://<Haupt-Host>/jobs?token=… zunächst mit Platzhalter; Knopf „URL mit Token anzeigen“ (POST mit CSRF) blendet die vollständige URL ein und schreibt einen Protokolleintrag „Cron-URL mit Token angezeigt“ (Art betrieb); Hinweis, dass der Token in Zugriffslogs landet"
        - "Der Serverpfad erscheint nur auf der Seite, nie in Doku, Protokoll oder Tests (R-10)"
      test: "http_tests: GET zeigt Kommando und URL mit Platzhalter, kein Token im HTML; POST anzeigen → Token im HTML, Audit-Eintrag vorhanden; Browser-Durchlauf: Kopieren-Knopf meldet „Kopiert“ (Clipboard in Playwright erlaubt)"
    - nr: 4
      titel: "Fehlerprotokoll einsehen und leeren"
      loesung:
        - "Zeile fehlerlog: Größe und Zahl der Zeilen seit dem letzten Leeren (Zeitstempel in Einstellung betrieb_fehlerlog_geleert); Knopf „Letzte 30 Zeilen anzeigen“ (POST, nur Betreiber) zeigt sie in einem <pre> mit e(); Knopf „Leeren“ (Dialog) kürzt die Datei auf 0 Byte und schreibt den Zeitstempel und einen Protokolleintrag"
        - "Zeilen über 500 Zeichen werden gekürzt; Pfade in den Logzeilen bleiben (nur Betreiber sehen sie)"
      test: "lauf.php: Logdatei mit 40 Zeilen → letzte 30 geliefert, Größe stimmt; Leeren → 0 Byte, Einstellung gesetzt, Audit-Eintrag"
    - nr: 5
      titel: "Seite Betrieb entlasten, Übersicht warnt"
      loesung:
        - "team_daten.php: Block „Mailversand“ auf Testmail und Umleitung verkleinern, Zahlen (offen, Fehler, Tagesstand) mit Verweis „siehe Status“; Hostnamen, Absender, Sicherung, Testdaten, CME bleiben"
        - "team_aufgaben(): je roter Status-Zeile eine Aufgabe „Betrieb: …“ mit Link /team/status (nur für BetreiberInnen sichtbar; Admins sehen weiter nur die Mail-Aufgabe wie bisher)"
      test: "lauf.php: Cron 2 h alt → team_aufgaben() enthält Aufgabe Betrieb für Betreiber, nicht für Admin"
    - nr: 6
      titel: "Abschluss"
      loesung:
        - "Version, CHANGELOG (Neu: Status-Seite; Geändert: Betrieb, health), betrieb.md (Abschnitt Cron verweist auf die Seite; Abschnitt Health beschreibt die Zeilen), Prüfprotokoll T-282 bis T-287, Konzept AP-15 status/ergebnis/probleme; Beispieldatensatz unverändert"
        - "Browser-Durchlauf R-12 mit der neuen Seite"
      test: "php tools/tests/lauf.php und node tools/browser/durchlauf.js ohne Fehler; Zahlen in PR und Prüfprotokoll"
  status: "umgesetzt 2026-10-31 (0.22.0), Unterpunkte 1 bis 6; Staging offen"
  ergebnis:
    - "Unterpunkt 1: server/lib/betrieb_lib.php mit betrieb_status(?int $jetzt) – sieben Zeilen (cron, jobs, mail, datenbank, sicherung, fehlerlog, umgebung) mit ampel, text, details (Beschriftung, Wert, intern) und link; Grenzwerte als Konstanten BETRIEB_*; jede Zeile einzeln abgesichert (Ausnahme → Zeile rot „Konnte nicht geprüft werden“). health.php nutzt betrieb_status() und betrieb_status_json() (ohne interne Details: letzter Mailserver-Fehler; kein Token, kein Pfad); status fehler bei roter Zeile, zusätzlich ampel (schlechteste Ampel); Felder datenbank und jobs entfallen"
    - "Unterpunkt 2: server/seiten/team_status.php (team_tor(['betreiber'])), Route /team/status, erste Seite unter Betrieb (der Reiter Betrieb führt dorthin); Kasten „N Punkte brauchen Aufmerksamkeit“ (rot vor gelb), Kasten „In Ordnung“, sonst Meldung „Alles in Ordnung, Stand …“; Details je Zeile in details.tabelle-details; „Jetzt prüfen“ als Link"
    - "Unterpunkt 3: Cron-Zeile „Details und Einrichtung“: Zeitplan und Befehl (php <Installationsordner>/cli/jobs.php aus dem Ort der App) in schreibgeschützten Feldern, Knopf „Befehl kopieren“ (assets/status.js; ohne Zwischenablage Feld markieren); URL mit Platzhalter, „URL mit Token anzeigen“ (POST mit CSRF) zeigt sie und schreibt „Cron-URL mit Token angezeigt“ (neue Protokoll-Art betrieb, nur BetreiberInnen sichtbar)"
    - "Unterpunkt 4: betrieb_fehlerlog_stand/_ende/_leeren; Zeilen zählen blockweise, Ende aus den letzten 512 KB, je Zeile höchstens 500 Zeichen; Leeren kürzt auf 0 Byte, setzt Einstellung betrieb_fehlerlog_geleert, Protokolleintrag „Fehlerprotokoll geleert“; Leeren mit dem Bestätigungsdialog"
    - "Unterpunkt 5: team_daten.php Kasten Mailversand nur noch Hinweis auf Status, Umleitung und Testmail; team_aufgaben($jetzt, $rolle) hängt für BetreiberInnen je rote Zeile eine Aufgabe „Betrieb: …“ mit Link /team/status vorne an (Chip rot); BetreiberInnen sehen die Mail-Aufgaben dafür nicht mehr doppelt, Admins wie bisher"
    - "Unterpunkt 6: Version 0.22.0, CHANGELOG, betrieb.md (Schritt 6/7 der Ersteinrichtung, Abschnitt 5 Health, 13 Team-Bereich, neu 16b Status, 17 Fehlerprotokoll), Prüfprotokoll T-282 bis T-287; lauf.php und Browser-Durchlauf (Stufe 3) siehe Prüfprotokoll"
  probleme:
    - "Konzept nannte den Laufzeitordner „fotos“; im Code heißt er bilder (Fotos der Dozierenden, Bilder der Texte). Geprüft wird bilder, angezeigt als „Fotos“."
    - "site.css hatte keinen lila Chip für „Achtung“ und keinen Stil für Logzeilen: zwei neue Klassen .chip.warnung (Hintergrund --lila) und .status-log (pre mit Rahmen, Umbruch, max. 360 px hoch). Für das Komponentenblatt (Review-AP CSS, E-64 (1)) vormerken."
    - "/health meldete bisher immer status ok. Mit den Ampeln steht status nach einer Neuinstallation auf fehler, bis Cron und erste automatische Sicherung gelaufen sind; betrieb.md Schritt 7 und Abschnitt 5 entsprechend angepasst. Ein Monitoring, das auf jobs.letzter_lauf schaut, muss auf zeilen.cron umgestellt werden."
    - "BetreiberInnen hätten „Mailversand nicht eingerichtet“ doppelt gesehen (Mail-Aufgabe und Status-Zeile): Für sie entfallen die Mail-Aufgaben, die Status-Zeile ersetzt sie."
    - "Seite und Tests: Zeitplan- und Befehlsfeld klebten auf den Bildschirmfotos aneinander → in .form gefasst (vorhandene Klasse, Abstand 20 px). Bestehende Tests erwarteten den Reiter Betrieb auf /team/veranstaltungen und status ok im Health-Test → auf /team/status bzw. die neuen Ampeln angepasst."
    - "Sandbox: tools/browser/einrichten.sh startet MySQL nur, installiert es aber nicht; in dieser Sandbox fehlte es. Das Skript installiert mysql-server jetzt, wenn es fehlt."

- id: AP-14
  titel: "Review-Behebung 1: Sicherheit und schwere Bugs (Review AP-R1)"
  ziel: "Die im Code-Review vom 2026-10-31 bestätigten schweren Funde beheben; jeder Fund zuerst mit einem Test belegt (rot), dann behoben (grün)"
  quelle: "docs/review-2026-10-31.md (Funde S-1, S-2, B-1, B-2, B-3, B-5, B-13); Entscheidungen E-63"
  abhaengig_von: [AP-13]
  reihenfolge: "Unterpunkte 1 bis 7 nacheinander, nach jedem Unterpunkt lauf.php; ein PR für das ganze Paket (Admin 2026-10-31: PR erst, wenn alles da ist)"
  umfang:
    - nr: 1
      fund: "S-1 (und B-9)"
      titel: "Login-Adresse eines Team-Kontos nicht über eine Anmeldung änderbar (E-63 (2))"
      loesung:
        - "team_anm_daten (team_lib): ist die Anmeldung mit einem Konto der Rolle admin oder betreiber verknüpft, wird eine abweichende Adresse abgelehnt (Fehler am Feld: „Die Adresse eines Team-Kontos ändert eine BetreiberIn unter Betrieb → Team-Konten.“); alle übrigen Felder bleiben änderbar. Das Konto zieht nur mit, wenn rolle = teilnehmend"
        - "team_personen: E-Mail-Feld bei verknüpftem Team-Konto schreibgeschützt mit demselben Hinweis statt „Vom Team geändert gilt die neue Adresse sofort“"
        - "anm_daten_setzen (buchung_lib, Pfad „Meine Anmeldung → Daten“): bei verknüpftem Team-Konto abweichende Adresse ablehnen (kein Link, keine Mail) mit Hinweis auf die BetreiberIn; anm_link_einloesen Zweck email schreibt bei verknüpftem Team-Konto nie ins Konto (zweite Sperre)"
        - "Adresse eines verknüpften Teilnehmenden-Kontos durch das Team bleibt möglich (Umfang AP-5), neu: Hinweis-Mail an die alte Adresse („Das Team hat die Adresse deiner Anmeldung und deines Kontos geändert“) und Protokolleintrag „Konto N: Adresse vom Team geändert“ (ohne Adressen, R-02)"
      test: "lauf.php: (a) Admin ändert Adresse einer Anmeldung mit verknüpftem Betreiber-Konto → Fehler, Konto und Anmeldung unverändert; (b) Team-Mitglied ändert eigene Adresse über Meine Anmeldung → Fehler, kein Link in anmeldung_link; (c) Teilnehmenden-Konto → Adresse in Anmeldung und Konto geändert, Mail an die alte Adresse in mail_ausgang; http_tests: POST speichern mit fremder Adresse auf Team-Anmeldung → Fehlermeldung, Konto-Adresse gleich"
    - nr: 2
      fund: B-1
      titel: "Newsletter-Mails überleben den Versand nach einer Admin-Aktion"
      loesung:
        - "Rechteprüfung aus rm_laden entfernen; rm_laden lädt jedes Mailing. Die Rollen- und Veranstaltungsprüfung wandert in eine Funktion rm_sichtbar(array $rm): bool, die team_mailings, team_mailings_vorschau, team_mailings_empfaenger und team_mailings_bild vor jeder Anzeige oder Änderung aufrufen (404 bzw. 403 wie bisher)"
        - "rm_mail_fuer und rm_mail_fuer_nl nutzen rm_laden ohne Rechte; mail_senden_faellige löscht einen Eintrag nur noch, wenn die Anmeldung storniert oder die Person abgemeldet ist – nicht, wenn das Mailing nicht geladen werden kann (dann Fehler mit Wiederholung, wie bei Zustellfehlern)"
      test: "lauf.php: Newsletter-Mailing mit 3 Empfängern einreihen, Team-Rolle admin setzen (ws_team_rolle), mail_senden_faellige(5) → alle 3 gesendet, keine gelöscht; Mailing einer fremden Veranstaltung als Admin laden → rm_sichtbar false, rm_laden liefert die Zeile"
    - nr: 3
      fund: "B-2 und B-3"
      titel: "Wartelisten-Einträge überleben „Speichern“ (Team und Teilnehmende)"
      loesung:
        - "teil_workshopwahl: bestehende Warte-Einträge immer anzeigen und als vorangekreuzte Kästchen wl[] mitsenden – auch im Team-Modus und auch, wenn der Termin inzwischen freie Plätze hat (Text: „Du stehst auf der Warteliste · N frei – fest wählen oder auf der Warteliste bleiben“)"
        - "anm_workshops_setzen: ein bestehender Warte-Eintrag wird nur gelöscht, wenn er im gesendeten Formular abgewählt wurde (wl[] fehlt) oder der Termin fest gewählt wurde; die Warte-Position (seit) bleibt beim Behalten unverändert"
        - "anm_wahl_pruefen: Warteliste für einen Termin mit freien Plätzen bleibt für Neuwahl verboten, ein bestehender Eintrag darf aber behalten werden"
      test: "lauf.php: Anmeldung mit Warte-Eintrag; (a) Team speichert nur Name → Warte-Eintrag mit altem seit bleibt; (b) Termin bekommt freie Plätze, Teilnehmende speichert Workshops ohne Änderung → Eintrag bleibt; (c) Kästchen abgewählt → Eintrag weg; Browser-Durchlauf: Kästchen im Team-Modus sichtbar"
    - nr: 4
      fund: S-2
      titel: "Ratenschutz für Adressänderungen (Mail an fremde Adressen)"
      loesung:
        - "anm_daten_setzen und tk_email_aendern: vor dem Versand rate_erlaubt('link_ip', IP) und rate_erlaubt('link_ziel', Zieladresse) wie bei „Link per Mail“; jeder Versand zählt als Misserfolg in beiden Töpfen; bei Sperre Fehlertext „Zu viele Adressänderungen. Bitte warte eine Stunde.“, Daten ohne Adresse werden trotzdem gespeichert"
        - "Vor dem Anlegen eines neuen email-Links alle älteren email-Links derselben Anmeldung bzw. desselben Kontos löschen"
        - "Vorlage email_bestaetigen: Anrede ohne Vornamen („Hallo,“), weil der Empfänger die Person nicht kennt"
      test: "lauf.php: 11 Adressänderungen auf 11 Zieladressen von einer IP → die 11. gesperrt (link_ip 10/h; im Plan stand „4 auf 4“, korrigiert mit dem Admin 2026-10-31); 4 Änderungen auf dieselbe Zieladresse → ab der 4. gesperrt (link_ziel 3/h); nur ein offener email-Link je Anmeldung"
    - nr: 5
      fund: B-5
      titel: "Storno lässt die Person eingeloggt (E-63 (3))"
      loesung:
        - "deine_anmeldung Aktion storno: statt anm_ausloggen() nur unset($_SESSION['anm']); Konto- und Team-Login bleiben; danach Umleitung auf /deine-anmeldung, die ohne Anmeldung den Einstieg („Jetzt anmelden“ bzw. „Link schicken“) zeigt – mit Konto den Hinweis „Du bist eingeloggt als … – neu anmelden?“"
        - "Doku: betrieb.md und konzept E-46 anpassen, wo „nach dem Storno ausgeloggt“ steht"
      test: "http_tests: Konto-Login, Anmeldung stornieren → Antwort 303 auf /deine-anmeldung, Sitzung hat tk weiter, Seite zeigt den Namen im Personen-Menü; Team-Login dasselbe, /team weiter erreichbar"
    - nr: 6
      fund: B-13
      titel: "Vorlage „Daten übernehmen“ nach Gebrauch löschen"
      loesung:
        - "anmelden.php: nach erfolgreichem POST und beim Ausloggen unset($_SESSION['anm_vorlage']); zusätzlich Ablauf 30 Minuten nach dem Setzen (Zeitstempel im Sitzungseintrag)"
      test: "lauf.php: Vorlage setzen, Anmeldung absenden → anm_vorlage() null; Vorlage mit altem Zeitstempel → null"
    - nr: 7
      titel: "Abschluss des Pakets"
      loesung:
        - "Version hochstufen, CHANGELOG (Behoben mit den Nummern aus dem Review), betrieb.md (Adresse von Team-Konten, Storno), Prüfprotokoll (T-275 bis T-281, ein Eintrag je Unterpunkt), Konzept (AP-14 status/ergebnis/probleme; Review-Dokument Abschnitt 5 Prüfstand ergänzen: behoben am …)"
        - "Browser-Durchlauf R-12 mit den neuen Kästchen im Team-Modus und dem Storno-Ablauf; Beispieldatensatz um eine Anmeldung mit Warte-Eintrag erweitern (beispieldaten.php)"
      test: "php tools/tests/lauf.php und node tools/browser/durchlauf.js ohne Fehler; Zahlen in PR und Prüfprotokoll"
  status: "umgesetzt 2026-10-31, Staging offen"
  ergebnis: "Alle sieben Unterpunkte umgesetzt, je Fund zuerst ein Test (rot, nachgewiesen durch Lauf ohne die Behebung), dann die Behebung (grün); Prüfungen T-275 bis T-281. (1) team_anm_daten, anm_daten_setzen und das Einlösen eines E-Mail-Links lehnen eine neue Adresse bei verknüpftem Team-Konto ab; E-Mail-Feld unter Personen und Meine Anmeldung → Daten schreibgeschützt mit Hinweis (neue Regel .feld input[readonly] in site.css); Teilnehmenden-Konto zieht mit, alte Adresse bekommt die neue Mail-Vorlage adresse_vom_team, Protokoll „Konto N: Adresse vom Team geändert“. (2) rm_laden ohne Rechte, neue Funktion rm_sichtbar() in team_mailings und team_mailings_vorschau; im Versand ist ein fehlendes Mailing ein Fehler mit Wiederholung. (3) teil_workshopwahl zeigt bestehende Warte-Einträge immer als vorangekreuztes Kästchen (anm_workshops_setzen und anm_wahl_pruefen erfüllten die Regeln schon und blieben unverändert). (4) Funktion adresse_aendern_erlaubt() (link_ip, link_ziel) in anm_daten_setzen und tk_email_aendern, ältere E-Mail-Links werden gelöscht, Vorlage email_bestaetigen mit „Hallo,“. (5) Storno nimmt nur die Anmeldung aus der Sitzung. (6) Vorlage mit Zeitstempel, 30 Minuten (ANM_VORLAGE_MIN), nach dem Absenden gelöscht; Ausloggen leerte die Sitzung schon vorher ganz. (7) Beispieldatensatz: Funktion beispieldaten_warteliste() (Wanda auf der Warteliste eines freien Termins); Browser-Durchlauf um Warte-Kästchen (Team und Teilnehmende) und Storno mit Konto erweitert."
  probleme:
    - {problem: "Der Hinweis „Die Adresse eines Team-Kontos ändert eine BetreiberIn unter Betrieb → Team-Konten“ verwies auf eine Funktion, die es nicht gibt (Team-Konten können die Adresse eines bestehenden Kontos nicht ändern)", loesung: "Admin 2026-10-31: Text lautet „Die Adresse eines Team-Kontos lässt sich hier nicht ändern. Bitte wende dich an eine BetreiberIn.“; die Funktion ist als O-48 offen"}
    - {problem: "Test zu Unterpunkt 4 im Plan rechnerisch unmöglich („4 Änderungen … ab der 11. gesperrt“)", loesung: "mit dem Admin korrigiert: 11 Änderungen auf 11 Adressen, die 11. gesperrt"}
    - {problem: "Wanda (Warteliste eines freien Termins) in beispieldaten_laden() änderte die AP-4-Prüfungen: Vor der Nachrück-Grenze bietet jedes Nachrücken auf dem Termin ihr den Platz an", loesung: "beispieldaten_warteliste() wird nicht in beispieldaten_laden() aufgerufen, sondern im Browser-Durchlauf (vorbereiten.php) und in lauf.php (AP-14); der Durchlauf setzt sie vor ihren Prüfschritten wieder auf die Warteliste (Stand nach der Grenze)"}
    - {problem: "Konzept nannte team_mailings_empfaenger und team_mailings_bild für rm_sichtbar", loesung: "team_mailings_empfaenger lädt kein Mailing (prüft die Auswahl schon mit rm_auswahl_gueltig); team_mailings_bild ist AP-16 Unterpunkt S-5 und nutzt rm_sichtbar dort"}
    - {problem: "E-63 (3) nennt E-46/E-48, „wo ausgeloggt steht“", loesung: "beide Einträge enthalten keinen solchen Satz; geändert wurde betrieb.md (Meine Anmeldung)"}

- id: AP-13
  titel: Team-Konten aufräumen, Meldungen auffälliger
  ziel: "Aktionen je Team-Konto übersichtlich, Fehlermeldungen nicht zu übersehen"
  abhaengig_von: [AP-12]
  umfang:
    - "Team-Konten: Menü „Bearbeiten“ je Zeile, Aktionen als Dialog (Entwurf 9)"
    - "Meldungen kräftig gefüllt mit Icon (Entwurf 10 B), Fehler werden angescrollt"
  status: "umgesetzt 2026-10-31, Abnahme auf Staging offen"
  ergebnis: "team_konten.php mit Zeilen-Menü und Dialogen (dialog.js data-ausloeser), Meldungs-CSS mit Icons über mask-image (assets/icons), kopf.js scrollt zur Fehlermeldung; Prüfungen T-273, T-274"
  probleme:
    - {problem: "Ein offenes Menü in einer Tabelle mit eigenem Scrollbereich wäre abgeschnitten worden", loesung: "Tabellenrahmen wird sichtbar, solange ein Zeilen-Menü offen ist"}
    - {problem: "Meldungen werden an vielen Stellen direkt als HTML geschrieben", loesung: "Icon per CSS (mask-image), keine Änderung an den Seiten nötig"}

- id: AP-12
  titel: Team-Navigation und Rechte je Veranstaltung
  ziel: "Team-Bereich klar gegliedert, Admins nur für ihre Veranstaltungen, einheitliche Knöpfe und Kopfleiste"
  abhaengig_von: [AP-11, AP-9]
  umfang:
    - "Wechsler statt Leiste, Reiter Mailings und Betrieb (nur BetreiberInnen), Startseiten-Texte unter Verwaltung"
    - "Rechte je Veranstaltung: Admins für alle oder ausgewählte; Filter in Personen, Konten, Protokoll, Mailings, Exporten; fremde Nummern abgewiesen"
    - "Veranstaltung löschen mit Kürzel und Sicherung vorher"
    - "Personen-Menü (Entwurf A, Phosphor-Icons), Knopf-Standard B, Mein Konto (Entwurf 7), stehende Kopfleiste, Team-Bereich grün, Rolle BetreiberIn"
  status: "umgesetzt 2026-10-31, Abnahme auf Staging offen"
  ergebnis: "Migration 17 (konto.alle_veranstaltungen, konto_veranstaltung, audit.veranstaltung_id); Rechte-Funktionen in veranstaltung_lib.php; Wechsler, Reiter, Personen-Menü, Mein Konto, kopf.js; Beispieldaten mit Ada (Admin nur Fortbildungsabend); Prüfungen T-264 bis T-272"
  probleme:
    - {problem: "Startseite erkannte auf Staging eingeloggte Team-Konten nicht (Personen-Menü fehlte); lokal nicht nachstellbar", loesung: "Die Startseite las die Sitzung nur (read_and_close, eigene Einstellungen); jetzt öffnet sie sie wie alle anderen Seiten (derselbe Keks); Test dafür ergänzt"}
    - {problem: "Knöpfe und Kästen wirkten unrund", loesung: "Ränder von 1,5 px (halbe Pixel je nach Zoom ungleich gezeichnet) durch ganze Pixel ersetzt; Knöpfe ohne Rand als getönte Fläche"}
    - {problem: "Admins hätten fremde Anmeldungen über ihre Nummer öffnen können (seit AP-11 auch zwischen Veranstaltungen)", loesung: "Team-Tor prüft Anmeldung, Platz und Konto der Anfrage gegen die gewählte Veranstaltung und die Rechte (404)"}
    - {problem: "Protokoll kannte keine Veranstaltung", loesung: "Spalte veranstaltung_id, Einträge der Veranstaltungs-Arten bekommen den Kontext; alte Einträge der ersten Veranstaltung zugeordnet; Mailings an Newsletter-Listen ohne Veranstaltung"}
    - {problem: "Klasse va-liste gab es schon (Rückblick im Konto)", loesung: "Wechsler-Liste heißt va-menue"}

- id: AP-9
  titel: Abschluss und Datenschutz
  ziel: "Löschkonzept umgesetzt, Gesamtprüfung bestanden"
  abhaengig_von: [AP-1, AP-2, AP-3, AP-4, AP-4b, AP-5, AP-6, AP-7, AP-10]
  umfang:
    - automatische Anonymisierung nach E-24 (Anmeldungen ohne Konto nach 14 Monaten, Mail-Warteschlange, Protokolle); Konten und CME-Nachweise nach E-24
    - Gesamttest aller Abläufe; Prüfprotokoll vollständig
    - Betriebsdoku (Backup, Wiederherstellung, Wartungsmodus) ohne Hosting-Interna (R-10)
    - "Sicherung und Wiederherstellung im Team-Bereich vorgezogen (E-47); hier noch: regelmäßige automatische Sicherung und Aufbewahrungsfrist klären"
    - "Antworten des Admins in E-59 (Fristen, Sicherung 8× täglich mit Staffelung, CME-Nachweise, Textvorschlag Datenschutz)"
  status: "umgesetzt 2026-10-31, Abnahme auf Staging offen; T-203 (Checkliste Anmeldestart) vor dem 25.11."
  ergebnis: "Jobs datenschutz (stündlich) und sicherung (alle 3 Stunden, Staffelung); Migration 16; CME-Nachweise unter Betrieb; Gesamtablauf mit zwei Personen als Test; Textvorschlag docs/datenschutz-vorschlag.md; Repo-Prüfung T-204; Prüfungen T-200 bis T-202, T-260 bis T-263"
  probleme:
    - {problem: "Anmeldungen sind je Veranstaltung über die Adresse eindeutig – mehrere geleerte mit leerer Adresse hätten sich gestoßen", loesung: "Platzhalter-Adresse geleert-<id>@anonym.invalid (nie zustellbar, .invalid ist reserviert)"}
    - {problem: "Acht automatische Sicherungen am Tag hätten das Protokoll gefüllt", loesung: "automatische Sicherungen und ihr Aufräumen ohne Protokolleintrag; von Hand und vor dem Wiederherstellen weiter mit"}
    - {problem: "Test des Sicherungsplans erwartete feste Anzahlen je Stufe; je nach Uhrzeit fällt eine Sicherung an einer Kalendertag-Grenze in den Topf davor oder danach", loesung: "Test prüft Bereiche je Stufe und, dass ein zweiter Lauf nichts mehr löscht"}
    - {problem: "Repo-Prüfung T-204: Name des Hosters, zwei Ordnerpfade (betrieb.md) und ein Kontoname (Konzept) stehen im öffentlichen Repo", loesung: "dem Admin vorgelegt; auf seine Antwort Hoster-Name durch „Hoster“ ersetzt und alle Bezüge auf die frühere Organisation entfernt (Konto, Kaneo-Adresse, privates Repo); Ordnerpfade bleiben; Repo-Pfade auf GitHub (eigenes Repo, NAdoku als Herkunft des Codes) bleiben"}
```

## 7. Offene Punkte

```yaml
- {id: O-01, thema: Domain, status: "entschieden → E-05 (winter-notfallsymposium.de angelegt)"}
- {id: O-02, thema: Postfächer, status: "erledigt → E-13 (anmeldung@, newsletter@ als Postfächer, admin@ als Weiterleitung)"}
- {id: O-03, thema: Nachrücken, status: "entschieden → E-19"}
- {id: O-04, thema: Workshop-Regeln, status: "entschieden → E-20"}
- {id: O-05, thema: Anmeldefelder, status: "entschieden → E-21"}
- {id: O-06, thema: Fortbildungspunkte, status: "entschieden → E-22; Details O-16"}
- {id: O-07, thema: Akzentfarbe und Logo, status: "entschieden → E-23; Logo-Dateien O-20"}
- {id: O-08, thema: Löschfristen, status: "entschieden → E-24 (Frist ohne Konto über O-21 entschieden)"}
- {id: O-09, thema: Kaneo, status: "entfallen → E-15 (bestehendes Kaneo)"}
- {id: O-10, thema: Repo-Sichtbarkeit, status: "entschieden → E-25; Admin schaltet nach dem Merge des Konzepts auf öffentlich (2026-10-29)"}
- {id: O-11, thema: Ausgabe der Arbeit, status: "entschieden → E-26"}
- {id: O-12, thema: Newsletter-Absender, status: "entschieden → E-27"}
- {id: O-13, thema: Staging, status: "entschieden → E-28"}
- {id: O-14, thema: Passkey, frage: "passkey_lib aus NAdoku anpassen oder kleine WebAuthn-Bibliothek (AGPL-kompatibel) nutzen?", zustaendig: Umsetzung, benoetigt_fuer: AP-5, status: "entschieden → E-55 (NAdoku-Bibliothek mit OpenSSL; Passkey ersetzt Passwort und zweiten Faktor)"}
- {id: O-15, thema: Berufsgruppen, status: "entschieden → E-21"}
- {id: O-16, thema: CME-Meldeweg, frage: "Meldung an die Kammer elektronisch (EIV) oder per Liste? Entscheidet, welchen Export AP-10 liefert", hinweis: "Zertifikat-Layout liefert der Admin (E-22)", zustaendig: Admin, benoetigt_fuer: AP-10, status: "teilweise (Admin 2026-10-30, E-57): CME-Liste der Anwesenden als Excel/PDF/CSV; EIV-Datei offen, bis das Format der Kammer bekannt ist"}
- {id: O-17, thema: Abgelaufenes Nachrück-Angebot, status: "entschieden → E-19 (ans Ende der Liste, mit Mail)"}
- {id: O-18, thema: Aufbewahrung CME-Nachweise, status: "entschieden → E-24 (solange das Konto besteht, sonst 10 Jahre)"}
- {id: O-19, thema: PDF-Erzeugung, frage: "Der Admin liefert den Zertifikat-Entwurf; die App setzt Name, Datum, ggf. EFN darauf. Technik je nach Entwurfsformat: PDF-Vorlage → Import und Überlagerung (z. B. FPDF + FPDI, beide MIT-/LGPL-kompatibel, ohne Composer in vendor/; tFPDF liegt seit E-56 im Repo); SVG-/PNG-Vorlage → eigener minimaler PDF-Writer. Entwurf als PDF mit eingebetteten Schriften und freiem Feld für den Namen erbeten", zustaendig: "Umsetzung (Entwurf: Admin)", benoetigt_fuer: AP-10, status: "entschieden → E-57 (Entwurf C, direkt mit tFPDF gezeichnet; keine PDF-Vorlage)"}
- {id: O-20, thema: Logo-Dateien, status: "erledigt (Admin 2026-10-29): Logo liegt auf main des Branding-Repos unter 1 Logo/"}
- {id: O-21, thema: Löschfrist ohne Konto, status: "entschieden → E-24 (14 Monate, Anonymisierung)"}
- {id: O-22, thema: Einrichtung beim Hoster, frage: "Subdomain test.winter-notfallsymposium.de → Ordner staging/public, Hauptdomain → Ordner produktiv/public (Zuordnung in der Verwaltung des Hosters, E-34); je eine MySQL-Datenbank mit eigenem Benutzer für Staging und Produktion; Cron-Aufruf des Job-Endpunkts; Datenbank-Zugangsdaten kommen nur in die config.php auf dem Webspace", zustaendig: Admin, benoetigt_fuer: AP-1, status: in Arbeit (Admin 2026-10-29)}
- {id: O-23, thema: "Nachrück-Angebot überschneidet sich mit anderem festen Workshop", frage: "(a) Beim Annehmen den überschneidenden Termin mit Bestätigung automatisch stornieren (so im Mockup) oder (b) das Angebot gar nicht an diese Person geben, sondern direkt an die nächste? Dazu: Darf man auf die Warteliste eines Termins, der sich mit einem eigenen festen Termin überschneidet?", zustaendig: Admin, benoetigt_fuer: AP-4, status: "entschieden (Admin 2026-10-29, E-42)"}
- {id: O-24, thema: "Statusfarben Grün/Rot", frage: "Grün für „Platz bestätigt“, Rot für „Warteliste“ widerspricht der Markenregel „keine Ampelfarben“. Beibehalten oder markentreu (bestätigt grün, Warteliste gestrichelt ohne Rot)?", zustaendig: Admin, benoetigt_fuer: AP-4, status: "entschieden (Admin 2026-10-29, E-42)"}
- {id: O-25, thema: "Grüner Haupt-Button", frage: "„Jetzt anmelden“ als grüne Fläche (Marke: Grün nur Erkennungszeichen) beibehalten oder lila Button?", zustaendig: Admin, benoetigt_fuer: AP-4, status: "entschieden (Admin 2026-10-29, E-42)"}
- {id: O-26, thema: "Button-Beschriftung", frage: "„Anmelden“ ist doppeldeutig (Symposium vs. Login). Umbenennen in „Teilnehmen“ oder „Platz sichern“?", zustaendig: Admin, benoetigt_fuer: AP-4, status: "entschieden (Admin 2026-10-29, E-42)"}
- {id: O-27, thema: "Daten nach dem Symposium", frage: "Sollen Name und EFN nach dem Symposium noch änderbar sein (Teilnahmebescheinigung) oder ab einem Stichtag gesperrt?", zustaendig: Admin, benoetigt_fuer: AP-4b/AP-10, status: "entschieden (Admin 2026-10-29, E-42)"}
- {id: O-28, thema: "Eine Konten-Tabelle mit Rollen", frage: "Mit „zum Admin machen“ (E-41) wird ein Teilnehmenden-Konto zum Team-Konto. Künftig eine gemeinsame Konten-Tabelle mit Rollen (Teilnehmende, Admin, Betreiber) statt getrennter Team-Konten (AP-1: team_konto)?", zustaendig: Admin, benoetigt_fuer: AP-4b/AP-5, status: "entschieden (Admin 2026-10-29, E-42)"}
- {id: O-29, thema: "Mailings und Newsletter", frage: "Mailings = organisatorische Rundmails an Angemeldete; Newsletter = Listen mit Double-Opt-In. Ein gemeinsamer Editor unter „Mailings“ mit Wahl der Empfängerart, Reiter „Newsletter“ nur für Listen und Abonnierende?", zustaendig: Admin, benoetigt_fuer: AP-6/AP-7, status: "entschieden (Admin 2026-10-29, E-42)"}
- {id: O-30, thema: "EFN-Barcode", frage: "Welches Barcode-Format nutzen die EFN-Etiketten bzw. der Scanner der Kammer? (Foto eines Etiketts genügt)", zustaendig: Admin, benoetigt_fuer: AP-10, status: "entschieden (Admin 2026-10-29, E-42)"}
- {id: O-31, thema: "Anwesenheit", frage: "Admin: Anwesenheit eigentlich nicht erfassen („wer angemeldet ist, war da“). Vorschlag: Standard anwesend, das Team trägt nur Ausnahmen „nicht erschienen“ ein, Nachweis bleibt die Unterschriftenliste. Annehmen (E-22 anpassen) oder ganz ohne Ausnahmen?", zustaendig: Admin, benoetigt_fuer: AP-10, status: "entschieden (Admin 2026-10-29, E-42)"}
- {id: O-32, thema: "Empfängerliste von Rundmails", frage: "Gesendete Mails werden ohne Empfängerliste archiviert (R-02). Wird die Liste für Rückfragen gebraucht? Dann R-02 anpassen und Löschfrist festlegen", zustaendig: Admin, benoetigt_fuer: AP-6, status: "entschieden (Admin 2026-10-29, E-42)"}
- {id: O-33, thema: "Löschfrist der Empfängerlisten", frage: "Vorschlag: Empfängerliste einer Rundmail zusammen mit den Anmeldedaten nach E-24 behandeln (bei Anmeldungen ohne Konto nach 14 Monaten entfernen, sonst mit dem Konto); Anzahl und Auswahl bleiben im Archiv. Einverstanden oder andere Frist?", zustaendig: Admin, benoetigt_fuer: AP-6, status: "entschieden (Admin 2026-10-29): wie vorgeschlagen"}
- {id: O-34, thema: "Notweg ohne Betreiber", frage: "Gibt es im Tarif des Hosters SSH oder eine andere Möglichkeit, `php cli/konto.php` auf dem Webspace auszuführen? Wenn nicht: zweiten Betreiber Pflicht machen, oder einen Notweg über den Installationsschlüssel aus config.php im Browser bauen?", zustaendig: Admin, benoetigt_fuer: "AP-3 (vor dem Produktivstart)", status: "entschieden (Admin 2026-10-29): Notzugang im Browser mit dem Installationsschlüssel; ein Betreiber reicht, kein Zwang zu zwei"}
- {id: O-35, thema: "Export als xlsx und PDF", frage: "Exporte zusätzlich als Excel-Datei (xlsx) und PDF im Branding-Stil?", zustaendig: Admin, benoetigt_fuer: "nach AP-7", status: "entschieden → E-56 (tFPDF, xlsx selbst geschrieben; umgesetzt 2026-10-30, Ergebnis und Probleme in E-56 und im Prüfprotokoll T-247 bis T-250)"}
- {id: O-36, thema: "Löschfrist für Konten", frage: "Admin 2026-10-30: Konten nach 14 Monaten löschen, Erinnerung 30 Tage vorher, Login verhindert die Löschung. Offen: zählen die 14 Monate ab dem letzten Login, und gilt das auch für Team-Konten? (Ändert E-24: dort bleiben Konten bisher bestehen)", zustaendig: Admin, benoetigt_fuer: "Paket Konten", status: "entschieden → E-54 (14 Monate ab letztem Login, Team-Konten ausgenommen)"}
- {id: O-37, thema: "Team-Navigation und Personen-Menü", frage: "Mockup mit Varianten A (sechs Bereiche mit Unterreitern), B (Seitenleiste), C (Hauptreiter und „Mehr“) und Personen-Menü oben rechts (ausgeloggt, Teilnehmende, Team): welche Variante?", zustaendig: Admin, benoetigt_fuer: "Paket Konten/Navigation", status: "entschieden → E-54 (Variante A)"}
- {id: O-38, thema: "Ein Editor überall", frage: "Admin: Mailings-Editor 1:1 überall. Vorschlag: Texte, Programm-Beschreibungen, Kurzbios und Listen-Beschreibungen speichern dann bereinigtes HTML statt der CommonMark-Teilmenge (E-51); bestehende Texte werden einmal umgewandelt. Einverstanden?", zustaendig: Admin, benoetigt_fuer: "Paket Editor", status: "entschieden → E-54 (einverstanden, Texte überschreiben)"}
- {id: O-40, thema: "Mails im Branding", frage: "Admin 2026-10-31: Die Mails (z. B. Anmeldebestätigung) sind noch reiner Text und sollen im Erscheinungsbild Allgäu Akut kommen (Logo, Farben, Schriften, HTML mit Textfassung). Umfang und Gestaltung vor der Umsetzung klären", zustaendig: Admin, benoetigt_fuer: "eigenes Paket", status: "entschieden → E-66 (Variante A, umgesetzt)"}
- {id: O-41, thema: "Eigene Workshops im Programm markieren", frage: "Admin 2026-10-31: Im Programm auf der Startseite zeigen, in welchen Workshops man angemeldet ist bzw. auf der Warteliste steht. 2–3 Ideen als Mockup vorlegen", zustaendig: Admin, benoetigt_fuer: "eigenes Paket", status: "entschieden → E-66 (Variante B, umgesetzt)"}
- {id: O-39, thema: "Ein Konto für alles", frage: "Admin: ein Konto für Team und Teilnahme. Vorschlag: Team-Konten fragen den zweiten Faktor direkt beim Login ab (nicht erst beim Betreten des Team-Bereichs). Einverstanden?", zustaendig: Admin, benoetigt_fuer: "Paket Konten", status: "entschieden → E-54 (ja; Rolle angehoben: zweiter Faktor beim nächsten Login)"}
- {id: O-42, thema: "Login-Adresse von Team-Konten über eine Anmeldung", frage: "Review S-1: Darf die Adresse eines Team-Kontos über eine verknüpfte Anmeldung geändert werden (Team unter „Personen“, Person unter „Meine Anmeldung“)?", zustaendig: Admin, benoetigt_fuer: AP-14, status: "entschieden → E-63 (2): nein, nur BetreiberInnen unter Team-Konten (Admin 2026-10-31)"}
- {id: O-43, thema: "Storno und Sitzung", frage: "Review B-5: Soll ein Storno auf „Meine Anmeldung“ weiter die ganze Sitzung beenden oder die Person eingeloggt lassen?", zustaendig: Admin, benoetigt_fuer: AP-14, status: "entschieden → E-63 (3): eingeloggt bleiben (Admin 2026-10-31)"}
- {id: O-44, thema: "Mockup oder site.css als Design-Wahrheit", frage: "Review V-40: docs/mockups/index.html hat ein eigenes Stylesheet mit ~120 Klassen, die es in site.css nicht gibt, und abweichende Knopf-Stile. Mockup an site.css anbinden oder site.css zur alleinigen Vorlage machen und ein Komponentenblatt aus den echten Klassen erzeugen?", zustaendig: Admin, benoetigt_fuer: "Review-AP CSS (AP-R6)", status: "entschieden → E-64 (1): site.css ist die Wahrheit, Komponentenblatt aus den echten Klassen; Mockup wird Archiv (Admin 2026-10-31)"}
- {id: O-45, thema: "Cron-Token als Kopfzeile", frage: "Review S-8: /jobs?token= landet in Zugriffslogs. Kann der Cron des Hosters eine Kopfzeile oder einen POST schicken? Sonst bleibt es bei der URL.", zustaendig: Admin, benoetigt_fuer: "Review-AP Rest-Bugs (AP-R2)", status: "entschieden → E-64 (2): der Hoster bietet Shell-Cronjobs; Standard ist `php <Zielordner>/cli/jobs.php`, der URL-Aufruf bleibt Ausweichweg (Admin 2026-10-31)"}
- {id: O-46, thema: "Konstanten als Einstellungen (R-11)", frage: "Review V-9: Gehören Einmal-Link-Gültigkeit (48 h), Zertifikat-Link (30 Tage), Programm-Grenzen (500 Plätze, 12 Termine) in die Veranstaltungs-Einstellungen, oder bleiben sie technische Werte?", zustaendig: Admin, benoetigt_fuer: "Review-AP Konventionen (AP-R3)", status: "entschieden → E-64 (3): bleiben technische Werte; R-11 ergänzt (Admin 2026-10-31)"}
- {id: O-47, thema: "Aussperren eines Kontos vom Passwort-Login", frage: "Review S-7: 5 falsche Passwörter je Adresse sperren den Passwort-Login dieses Kontos 15 Minuten – jeder kann so ein bekanntes Betreiber-Konto gezielt aussperren (Passkey geht weiter). Lassen (einfach, bremst Angreifer sicher) oder Sperre nur je Adresse und IP zusammen (weniger Aussperrung, Angreifer mit vielen Adressen kommen weiter)?", zustaendig: Admin, benoetigt_fuer: "nach AP-16", status: offen}
- {id: O-48, thema: "Adresse eines Team-Kontos ändern", frage: "Unter Betrieb → Team-Konten lässt sich die Adresse eines bestehenden Team-Kontos nicht ändern (bei AP-14 aufgefallen). Soll es dort „Adresse ändern …“ geben (nur BetreiberIn, Code aus der App, Hinweis-Mail an die alte Adresse, Protokoll)? Bis dahin: neues Team-Konto mit der neuen Adresse anlegen und das alte sperren.", zustaendig: Admin, benoetigt_fuer: "späteres AP", status: offen}
```

## 8. Bekannte Risiken und Einschränkungen

| ID | Risiko | Umgang |
|---|---|---|
| K-01 | Zeitplan: knapp 4 Wochen bis zum Anmeldestart | Newsletter und Zertifikate nach dem Start; Konten (AP-4b) mit Rückfall nach dem Start; Umfang von AP-1 bis AP-6 nicht ausweiten; Mockup-Freigabe (AP-2) zügig |
| K-02 | Mail-Limit 500 Empfänger/24 h je Postfach | Tageslimit, Prioritäten, zwei Postfächer; Wechsel zu EU-Versanddienst vorbereitet |
| K-03 | Der Botschutz des Hosters kann automatisierte Tests und ggf. Formular-POSTs blockieren | beim Hoster für die Domain klären; echte Anmeldungen vor dem Start mit verschiedenen Browsern testen |
| K-04 | Zustellbarkeit (SPF/DKIM/DMARC der neuen Domain) | DNS-Einträge mit der Domain einrichten und prüfen (AP-6) |
| K-06 | Ein Team-Mitglied verliert den zweiten Faktor | Wiederherstellungscodes; Betreiber kann den Faktor zurücksetzen; Notzugang mit dem Installationsschlüssel (O-34) |
| K-07 | Nachrück-Kette mit 48-h-Fristen ist langsam: bei n Absagen dauert die Vergabe bis zu n × 48 h | Team kann jederzeit manuell vergeben; Frist einstellbar; Angebot wird bei Ablehnung sofort weitergegeben |
| K-08 | Öffentliches Repo: versehentlich eingecheckte Interna | R-10; Prüfung vor jedem PR; Secrets nur in config.php und GitHub-Secrets |

## 9. Checkliste Anmeldestart (2026-11-25)

- [ ] winter-notfallsymposium.de mit HTTPS, Mail-DNS (SPF, DKIM, DMARC) geprüft
- [ ] Impressum und Datenschutz online
- [ ] Programm vollständig eingetragen, Veranstaltungs-Einstellungen gesetzt
- [ ] Testanmeldungen inkl. Warteliste, Nachrück-Angebot, Storno, Einmal-Links und Stichtagen bestanden (Konten, falls AP-4b fertig)
- [ ] Team-Konten mit zweitem Faktor eingerichtet
- [ ] Backup der Datenbank eingerichtet und Wiederherstellung einmal geprüft
- [ ] Testdaten gelöscht
- [ ] Suchmaschinen-Sperre (noindex) auf Produktion aufgehoben (AP-2)

## 10. Spätere Ausbaustufen

- Check-in vor Ort per QR-Code (Anwesenheit schneller erfassen als über die Liste aus AP-10)
- Wiederverwendung für weitere Allgäu-Akut-Veranstaltungen (mehrere Veranstaltungen in einer Installation; die Mehr-Domain-Grundlage aus E-27 ist dafür vorbereitet)
- Zentraler Login (Keycloak) – bewusst nicht jetzt (E-12)

## 11. Änderungsprotokoll des Konzepts

Unter der Erstfassung steht der neueste Eintrag zuerst.

| Datum | Änderung |
|---|---|
| 2026-10-29 | Erstfassung nach Abstimmung mit dem Admin: E-01 bis E-18, R-01 bis R-09, AP-1 bis AP-9, O-01 bis O-14. Grundlagen: Analyse NAdoku (Login, Registrierung, Spamschutz, Rollen, Mail, Verschlüsselung, Betrieb), Kaneo-Doku (OIDC vorhanden, kein Theme), zina-branding, bestehendes Server-Konzept (Ressourcen, Kaneo-Stack). |
| 2026-10-29 | AP-2 umgesetzt: Mockups (Variante A) freigegeben und in docs/mockups/ abgelegt; E-36 bis E-41 (Gestaltung, Sprache, Workshop-Termine und Warteliste, Programm-Modell, öffentliche Seiten und Einstieg, Team-Bereich) aus den Mockup-Kommentaren; E-21 auf Binnen-I; Hinweise in AP-3 bis AP-10; offene Punkte O-23 bis O-32. |
| 2026-10-29 | AP-2 auf Staging abgenommen; offene Punkte O-23 bis O-32 entschieden und in E-42 zusammengefasst; E-22 (Anwesenheit), E-40 (Einloggen/Ausloggen), E-41 und R-02 (Empfängerliste im Mail-Archiv) angepasst; O-33 (Löschfrist der Empfängerlisten) neu; Eckdaten-Platzhalter auf der Startseite bis AP-3 (Hinweis in AP-3); Überschrift der Infokästen „Für alle, die da sind, wenn andere frei haben“. |
| 2026-10-29 | Team-Login aus AP-5 in AP-3 vorgezogen; O-33 entschieden; Symposium als Abendveranstaltung 18:00–22:00 (Abschnitt 1); Infotexte der Startseite überarbeitet. |
| 2026-10-29 | E-10: Essenswahl „keine speziellen Wünsche / vegetarisch / kein Essen“ (Snacks statt Mittagessen); E-42: Anmelde-Knopf in der Navigation grün. |
| 2026-10-29 | Beispielprogramm auf der Startseite bis AP-3 (Wunsch des Admins); Hinweis zum Entfernen in AP-3. |
| 2026-10-29 | AP-3 Schritt 1 umgesetzt: E-43 (Team-Login) neu; AP-3 mit Schritten und Ergebnis; O-14 zurückgestellt (erst TOTP); O-34 (Notweg) neu. |
| 2026-10-29 | O-34 entschieden: Notzugang im Browser mit dem Installationsschlüssel, ein Betreiber reicht (E-43 ergänzt). |
| 2026-10-29 | R-12 (Browser-Prüfung in drei Engines und vier Breiten vor jedem PR) neu. |
| 2026-10-29 | AP-3 Schritt 1: Code-Felder für Bitwarden in Firefox umbenannt (Problem und Lösung eingetragen). |
| 2026-10-29 | AP-3 Schritt 2 umgesetzt: E-44 (Veranstaltungs-Einstellungen) neu, Status und Ergebnis AP-3. |
| 2026-10-30 | Passkeys umgesetzt: E-55 neu; O-14 entschieden; E-43 und NAdoku-Baustein passkey_lib angepasst. |
| 2026-10-30 | Export als xlsx und PDF umgesetzt: E-56 neu (Antworten zu O-35, Umfang, Ergebnis, Probleme); O-35 entschieden; Hinweis in O-19. |
| 2026-10-30 | AP-10 umgesetzt: E-57 neu (Zertifikat-Entwurf C und Antworten des Admins, Umsetzung, Probleme); AP-10 Status, Ergebnis, Probleme; O-19 entschieden, O-16 teilweise. |
| 2026-10-31 | AP-11 Mehrere Veranstaltungen neu und umgesetzt (E-58); Antworten zu AP-9 festgehalten (E-59). |
| 2026-10-31 | E-66 neu: Mails im Erscheinungsbild (Variante A), eigene Plätze im Programm (Variante B), Begriff „Platzangebot“; O-40 und O-41 entschieden und umgesetzt. |
| 2026-10-31 | Editor überall mit derselben Leiste (zentral festgelegt), Bilder und Knöpfe auch im Programm und in Kurzbios; obere Navigationsleiste bleibt wie sie ist (Admin). |
| 2026-10-31 | „Meine Anmeldung“ zeigt dem Team-Konto den Team-Bereich in der Leiste. |
| 2026-10-31 | Startseite erkennt Login auch ohne Ausgabepuffer (Sitzung vor der ersten Ausgabe öffnen, Testserver ohne Puffer); O-40 (Mails im Branding) und O-41 (eigene Workshops im Programm markieren) auf Halde; Impressum und Datenschutz mit derselben Werkzeugleiste wie die Mailings (Knopf, HTML), Platzhalter unter dem Mail-Editor erklärt. |
| 2026-10-31 | AP-14 umgesetzt (Review S-1, S-2, B-1, B-2, B-3, B-5, B-13): status, ergebnis, probleme eingetragen; Test zu Unterpunkt 4 korrigiert; Hinweistext zur Adresse von Team-Konten angepasst (Admin), O-48 neu. |
| 2026-10-31 | AP-15 umgesetzt: Status-Seite, Cron-Anleitung, Fehlerprotokoll, health aus derselben Quelle; vor der Umsetzung geklärte Punkte in E-65 (5) bis (8); status, ergebnis, probleme eingetragen. |
| 2026-10-31 | AP-16 (Review AP-R2: B-4, B-6, B-7, B-8, B-10 bis B-12, B-14, S-3 bis S-6, S-9, S-11, S-12, Helfer V-4) geplant mit neun Unterpunkten; O-47 (Aussperren je Adresse) neu; S-8 und S-10 begründet nicht enthalten. |
| 2026-10-31 | AP-15 Status-Seite unter Betrieb geplant (E-65): Ampel-Zeilen, Cron-Anleitung mit Kopieren und Token nach Klick, Fehlerprotokoll, health aus derselben Quelle. |
| 2026-10-31 | E-64 neu (site.css als Design-Wahrheit mit Komponentenblatt, Shell-Cronjob als Standard, technische Grenzen bleiben Konstanten); O-44 bis O-46 entschieden; R-11 um technische Grenzen ergänzt. |
| 2026-10-31 | Code-Review (docs/review-2026-10-31.md): E-63 neu (Review-APs, Adresse von Team-Konten nur über Team-Konten, Storno ohne Ausloggen, ein PR je Paket); AP-14 (Review AP-R1: S-1, B-1, B-2/B-3, S-2, B-5, B-13) geplant mit sieben Unterpunkten; O-42, O-43 entschieden, O-44 bis O-46 neu. |
| 2026-10-31 | E-62 neu, R-12 gestuft (Browser-Prüfung Stufe 1/2/3). |
| 2026-10-31 | E-60 (8): Knopf-Standard auf Variante A (Ring 2 px) geändert; aktiver Unterpunkt der Team-Navigation als lila Fläche; EFN-Zeile aus der Kachel „Angemeldet“ entfernt (Admin 2026-10-31). |
| 2026-10-31 | AP-13 neu und umgesetzt (E-61): Team-Konten mit Menü je Zeile, Meldungen kräftig gefüllt. |
| 2026-10-31 | AP-12 Team-Navigation und Rechte je Veranstaltung neu und umgesetzt (E-60): Wechsler, Reiter Betrieb, Rechte je Veranstaltung, Löschen mit Sicherung, Personen-Menü, Knopf-Standard, Mein Konto, Kopfleiste, BetreiberIn. |
| 2026-10-31 | E-57: Fußzeile der Teilnahmebescheinigung in einer Zeile, Adresse rechtsbündig in Grün; Beispieldatensatz mit drei Veranstaltungen (2× Wintersymposium, 1× Fortbildungsabend, Admin 2026-10-31). |
| 2026-10-31 | AP-9 umgesetzt: E-59 ergänzt (Sicherungen von Hand 90 Tage, Hoster-Sicherung ohne Belang, Umsetzung, Status); AP-9 Status, Ergebnis, Probleme; Repo-Prüfung T-204: Hoster-Name und Bezüge auf die frühere Organisation entfernt (E-03, E-05, E-13, E-15, E-25, R-08, R-10, O-22, O-34, K-03). |
| 2026-10-30 | Editor, Konto, Navigation, Löschfrist umgesetzt: E-54 neu; O-36 bis O-39 entschieden; E-24 (Löschfrist für Konten) und E-51 (Editor statt CommonMark) geändert. |
| 2026-10-30 | Rückmeldungen 30.10. (2) umgesetzt: E-53 (Programm: Dialog, Ziehen mit dem Finger, Termine entfernen, Dozierende je Termin, Vorträge/Fälle in Blöcken) neu; O-36 bis O-39 (Löschfrist, Navigation, Editor, ein Konto) neu. |
| 2026-10-30 | AP-7 umgesetzt: E-52 (Newsletter; Antworten des Admins 1–9) neu; AP-7 Status, Ergebnis, Probleme. |
| 2026-10-30 | Rückmeldungen des Admins (ToDo-Liste) umgesetzt: E-51 neu (Navigation, Programm-Zuordnung, CommonMark, E-Mail-Code, Protokoll-Rechte, Sperre, Bestätigungsdialog C); O-14 ergänzt; Export xlsx/PDF nach AP-7. |
| 2026-10-30 | AP-6 umgesetzt: E-50 (Mailings und Team-Mails; Antworten des Admins zu zweitem Postfach, Abmeldelink, Archiv, Planen, Auswahl, System-Texten) neu; AP-6 Status, Ergebnis, Probleme. |
| 2026-10-29 | AP-5 umgesetzt: E-49 (Team-Bereich; Antworten des Admins zu Mail bei Team-Änderungen, Platz von Hand, Rechten, CSV, CME-Liste) neu; AP-5 Status, Ergebnis, Probleme; Mailversand auf Staging bestätigt (Admin). |
| 2026-10-29 | Rückmeldungen des Admins: Ziehen im Programm fügt zwischen zwei Punkte ein und lässt nachfolgende Punkte nachrücken (E-45 ergänzt); Versionsanzeige außerhalb der Produktion; Testmail und Mail-Stand unter „Daten“ (E-47 ergänzt); Abstände der Zwischentitel; Farben bleiben wie im Branding (Admin). |
| 2026-10-29 | AP-4b umgesetzt: E-48 (Konten für Teilnehmende; Antworten des Admins zu Login, Passwort, Löschen) neu; AP-4b Status, Ergebnis, Probleme. |
| 2026-10-29 | E-47 (Sicherung, Wiederherstellung, Testdaten auf Staging, Mail-Umleitung) neu, Hinweis in AP-9; Beispieldatensatz nach server/testdaten/ verschoben. |
| 2026-10-29 | AP-4 umgesetzt: E-46 (Anmeldung, Warteliste, Nachrücken; Antworten des Admins zu Mailversand, Bestätigung, Einmal-Link 48 h, Team-Liste, Warteliste des Symposiums, gleicher Adresse) neu; AP-4 Status, Ergebnis, Probleme; AP-6 um die vorgezogene Mail-Grundlage gekürzt. |
| 2026-10-29 | Prüfprotokoll: doppelt vergebene Nummern der AP-3-Prüfungen (T-130 bis T-136) in T-216 bis T-222 umbenannt (Admin); AP-4 behält T-130 bis T-144. |
| 2026-10-29 | AP-3 Schritt 3 umgesetzt: E-45 (Programm-Pflege und -Anzeige) neu; AP-3 Status, Ergebnis und Probleme; Beispielprogramm entfernt; Test-Beispieldatensatz (Wunsch des Admins). |
| 2026-10-29 | AP-1 auf Staging abgenommen: Status und Ergebnis ergänzt; Hinweis auf das Hoster-Cookie `_lcp` in AP-2 (Datenschutz). |
| 2026-10-29 | AP-1 Nachtrag: FTP-Pfade der Auslieferung (E-32: Zugang landet im Ordner wintersymposium, Pfade /staging und /produktiv), Problem und Lösung der ersten Auslieferung eingetragen. |
| 2026-10-29 | Umsetzung AP-1: Status, Ergebnis und Probleme eingetragen; E-34 (Webroot public/) und E-35 (Produktion per Tag) neu, E-32 angepasst, O-22 um public/ ergänzt; Widersprüche bereinigt (E-07 und E-11 an E-30 angeglichen, Logo-Stand in Abschnitt 2, O-08); noindex bis zum Start in AP-2 und Checkliste aufgenommen; YAML-Fehler in O-19 behoben (Wert mit Doppelpunkt in Anführungszeichen). |
| 2026-10-29 | E-32 (Auslieferung: Secrets, Zielordner staging/produktiv, Zuordnung der Domains), E-33 (Plan B Konten bestätigt), O-22 (Einrichtung beim Hoster) neu; O-10 Repo wird öffentlich. |
| 2026-10-29 | Domain winter-notfallsymposium.de und Postfächer eingetragen (E-05, E-13; O-01, O-02 erledigt); E-24: Anmeldungen ohne Konto nach 14 Monaten anonymisieren (O-21); Zertifikat-Entwurf kommt vom Admin (E-22, O-19); Logo auf main (O-20 erledigt); R-10 präzisiert. |
| 2026-10-29 | Konzeptanpassung Admin: E-30 (optionales Konto mit Profil), E-31 (Stichtage für Teilnehmende), AP-4b (Konten) neu, AP-5 um Statistik-Seite erweitert; Antworten eingearbeitet: E-21 Berufsgruppen (O-15), E-22 EFN freiwillig und nur Ärztin/Arzt (O-16 verengt), E-19 abgelaufenes Angebot ans Listenende (O-17), E-24 Konten bleiben, CME 10 Jahre (O-18), O-21 Löschfrist ohne Konto neu; O-20 Logo-Branch benannt. |
| 2026-10-29 | Kaneo: E-15 geändert auf Mitnutzung des bestehenden Kaneo; AP-8 entfallen, E-29 und O-09 entfallen, K-05 gestrichen. |
| 2026-10-29 | Antworten des Admins eingearbeitet: E-19 bis E-29 (Nachrücken mit Annahmefrist, Workshop-Regeln, Anmeldefelder, CME mit Zertifikat, Mockups und Lila/Rot, Löschfristen, Repo öffentlich, PR, mehrere Domains/Absender, Staging, Kaneo-Subdomain); R-10 und R-11 neu; AP-2 mit Mockup-Halt, AP-3 um Veranstaltungs-Einstellungen, AP-4 um Nachrück-Angebote und EFN, AP-5 um Betreiber-Einstellungen erweitert; AP-10 (Teilnahme und Zertifikate) neu; O-03 bis O-08 und O-10 bis O-13 entschieden, O-15 bis O-20 neu; K-07, K-08 neu; Hosting-Interna nach R-10 entfernt. |
