---
dokument: konzept
projekt: wintersymposium
repo: gen-em/wintersymposium
stand: 2026-10-29
phase: umsetzung   # AP-1 und AP-2 abgenommen; AP-3 Schritt 1 (Team-Login) auf Staging, Schritt 2 (Einstellungen) umgesetzt, als Nächstes Schritt 3 (Programm)
status: bestaetigt   # Zusammenfassung, Reihenfolge und Entscheidungen E-01 bis E-42 vom Admin bestätigt (2026-10-29), E-43 und E-44 aus der Umsetzung von AP-3; offene Punkte in Abschnitt 7
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
  7. **Teamkommunikation** über das bestehende Kaneo von Gen-EM (E-15); kein Bestandteil dieses Repos
- Design nach dem Erscheinungsbild **Allgäu Akut** (Repo `chodid/zina-branding`), Seitendesign über Mockups (E-23).
- Nicht Teil dieses Konzepts: Bezahlung, Tickets/QR-Codes, zentraler Login (E-12), eine eigene Kaneo-Instanz (E-15), Umstellung bestehender Gen-EM-Dienste.

## 2. Ausgangslage

```yaml
hosting_app:
  anbieter: Lima-City
  konto: Gen-EM-Konto
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
    smtp: Lima-City, Port 465 (SSL)
    limit: "500 Empfänger je 24 h je SMTP-Postfach (Lima-City-Auskunft)"
kaneo:
  bestehend: Kaneo von Gen-EM unter team.gen-em.org (Repo gen-em/server-infrastruktur); wird für das Symposium-Team mitgenutzt (E-15)
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
  auslieferung: "GitHub Actions → FTPS auf den Lima-City-Webspace (E-32, E-35): Push auf main → Staging, Tag → Produktion, getrennte Ordner; Webroot ist der Unterordner public/ (E-34); config.php und Laufzeitordner var/ bleiben unangetastet"
kaneo:
  nutzung: bestehendes Gen-EM-Kaneo (team.gen-em.org), eigener Arbeitsbereich/eigenes Projekt für das Symposium-Team; der Admin lädt ein (E-15)
  login: Kaneo-Login (E-Mail-Code), getrennt von der Webseite (E-12)
mail:
  postfaecher: [System-Postfach (Bestätigungen, Links, Nachrücken, Zertifikate), Newsletter-Postfach (Rundmails, Newsletter)]
  versand: Warteschlange mit Prioritäten und Tageslimit je Postfach
```

## 4. Entscheidungen

| ID | Thema | Entscheidung | Begründung / Quelle |
|---|---|---|---|
| E-01 | Eigenbau oder fertige Lösung | **Eigenbau**; pretix u. ä. verworfen | Kern ist die Warteliste je Workshop-Termin mit Nachrücken; pretix führt Workshops als Zusatzprodukte, für die es nach bisherigem Kenntnisstand keine Warteliste gibt (nicht abschließend geprüft); großer Unterbau mit vielen unnötigen Funktionen. Admin 2026-10-29 |
| E-02 | Technik | PHP 8.3 / MySQL 8.4 ohne Framework, Bausteine aus NAdoku (E-18) | läuft auf dem Lima-City-Webspace ohne eigenen Server; erprobte Bausteine vorhanden |
| E-03 | Hosting App | Lima-City-Webspace im **Gen-EM-Konto**; Cron vorhanden | Admin 2026-10-29 |
| E-04 | Repo und Lizenz | `gen-em/wintersymposium`, **AGPL-3.0**; Code aus NAdoku darf übernommen werden | Admin 2026-10-29 (alleiniger Urheber von NAdoku) |
| E-05 | Domain | **`winter-notfallsymposium.de`**, angelegt im Gen-EM-Konto bei Lima-City; Staging unter `test.winter-notfallsymposium.de` (E-28) | Admin 2026-10-29 (O-01 entschieden) |
| E-06 | Design-Grundlage | Erscheinungsbild Allgäu Akut (Farben, Schriften, Logo); Schriften selbst ausgeliefert, kein CDN | zina-branding |
| E-07 | Teilnehmende | **kein Konto nötig**: Verwaltung über Einmal-Links per Mail; Token nur als Hash gespeichert, mit Ablauf. Ein Konto ist optional (E-30) | Admin 2026-10-29; Muster aus NAdoku (Reset-Token, Token gegen Sitzung tauschen). Präzisiert 2026-10-29: ursprünglich „kein Konto, kein Login“, durch E-30 ergänzt |
| E-08 | Programm | frei konfigurierbar: Zeitfenster mit beliebigen, auch unterschiedlichen/überlappenden Zeiten; Workshops; Termine je Workshop; Plätze je Termin; Gesamtkapazität | Admin 2026-10-29 (Planungsstand: ca. 3 Workshops, 5 Zeitfenster) |
| E-09 | Warteliste | Warteliste **je Workshop-Termin** (und für die Gesamtkapazität, falls gesetzt) mit Nachrücken nach E-19 | Admin 2026-10-29 |
| E-10 | Essen | Snacks am Abend; Auswahl **keine speziellen Wünsche / vegetarisch / kein Essen**; keine Allergien, kein Freitext. Essens-Stichtag (E-31) und Essensliste bleiben für die Planung | Admin 2026-10-29 (Auswahl angepasst nach Umstellung auf Abendveranstaltung); keine Gesundheitsdaten (Datensparsamkeit) |
| E-11 | Rollen | **Teilnehmende** (ohne Konto per Einmal-Link oder mit optionalem Konto nach E-30, ohne zweiten Faktor), **Admin** (Programm, Einstellungen der Veranstaltung, Anmeldungen, Mailings, Newsletter, Teilnahme), **Betreiber** (zusätzlich Team-Konten, Domains/Absender, SMTP, Rechtstexte, Backups); Admin und Betreiber nur mit **Passwort + TOTP oder Passkey** | Admin 2026-10-29 |
| E-12 | Login-Architektur | **getrennte Logins** für Webseite und Kaneo; kein zentraler Anmeldedienst (Keycloak/Pocket ID geprüft und verworfen) | Admin 2026-10-29. Kaneo könnte OIDC (`CUSTOM_OAUTH_*`), ein eigener Anmeldedienst wäre aber zusätzliche Last und Abhängigkeit |
| E-13 | Mailversand | SMTP über Lima-City; Warteschlange mit **einstellbarem Tageslimit** (Vorschlag 450 je Postfach); **System-Mails vor Rundmails/Newsletter**; zwei Postfächer: `anmeldung@` (System) und `newsletter@` (Newsletter, Rundmails), dazu `admin@` als Weiterleitung an den Admin (Reply-To, Impressum); Wechsel zu einem EU-Versanddienst nur per Konfiguration | Admin 2026-10-29: kein externes Tool, Versand über mehrere Tage verteilen; Postfächer angelegt (O-02 erledigt) |
| E-14 | Newsletter | **mehrere Listen** (mindestens „Wintersymposium“ und „Allgäu Akut“); Anmeldung auch ohne Symposium-Anmeldung; Double-Opt-In; Abmeldelink und `List-Unsubscribe`; Häkchen bei der Symposium-Anmeldung freiwillig und nicht vorausgewählt | Admin 2026-10-29; UWG § 7, DSGVO |
| E-15 | Kaneo | **bestehendes Gen-EM-Kaneo** (team.gen-em.org) wird mitgenutzt: eigener Arbeitsbereich bzw. eigenes Projekt für das Symposium-Team, Einladung durch den Admin; **keine eigene Instanz**, kein eigenes Branding | Admin 2026-10-29 (ersetzt die zuvor gewünschte eigene Instanz, Entscheidung am selben Tag geändert) |
| E-16 | Verantwortlicher | Philipp Chadid (Impressum, Datenschutzerklärung) | Admin 2026-10-29 |
| E-17 | Zeitplan | AP-1 bis AP-6 bis **2026-11-25** (Anmeldestart); AP-7 (Newsletter) danach; AP-10 (Teilnahme, Zertifikate) bis zum **20.01.2027**; AP-9 zuletzt | Admin 2026-10-29 |
| E-18 | Übernahme aus NAdoku | siehe Tabelle unten | Analyse 2026-10-29 |
| E-19 | Nachrücken | Freier Platz wird der ersten Person der Warteliste **angeboten**, Annahme über Einmal-Link innerhalb einer **Annahmefrist von 48 h**; bei Ablauf oder Ablehnung geht das Angebot an die nächste Person. Automatische Angebote **bis 14 Tage vor dem Symposium** (letztes Angebot spätestens 16 Tage vorher, damit die Frist vor der Grenze endet); danach nur noch manuell durch das Team. Frist und Grenze in den Veranstaltungs-Einstellungen einstellbar. Wer die Frist verstreichen lässt, kommt **ans Ende der Warteliste** dieses Termins und wird per Mail informiert | Admin 2026-10-29 (O-17 entschieden) |
| E-20 | Workshop-Regeln | **Überschneidende Termine gesperrt**; **kein Limit** an Workshops je Person als Standard, Limit in den Veranstaltungs-Einstellungen einstellbar; Workshop-Wahl freiwillig (einstellbar); Gesamtkapazität optional (0 = unbegrenzt) | Admin 2026-10-29 |
| E-21 | Anmeldefelder | Vorname, Nachname, E-Mail, **Berufsgruppe** (Auswahl, Pflicht: ÄrztIn, NotfallsanitäterIn, RettungssanitäterIn, Pflege/MFA, StudentIn, Sonstige mit optionalem Freitext; Schreibweise nach E-37), **Klinik/Institution** (Pflicht), EFN nur bei Ärztin/Arzt (E-22). Bei Konto (E-30) werden Berufsgruppe und Institution ins Profil übernommen und sind dort änderbar | Admin 2026-10-29 (O-15 entschieden) |
| E-22 | Fortbildungspunkte (CME) | Symposium wird zertifiziert; **EFN-Feld freiwillig**, nur bei Berufsgruppe Ärztin/Arzt abgefragt und angezeigt (15-stellig, Prüfziffer geprüft); Punkte, Kammer, Veranstaltungsnummer werden in den **Veranstaltungsinfos** (Einstellungen) gepflegt; das Team **erfasst die Teilnahme** (Anwesenheit: Standard anwesend, nur Ausnahmen eintragen, E-42); danach ist ein **Teilnahmezertifikat als PDF** mit eigenem Namen über Einmal-Link oder Konto herunterladbar. **Den Zertifikat-Entwurf liefert der Admin** (fertiges Layout); die App bringt nur Name, Datum und ggf. EFN darauf (O-19) | Admin 2026-10-29. Meldeweg an die Kammer: O-16 |
| E-23 | Seitendesign | **Mockups vor der Umsetzung**, Freigabe durch den Admin (AP-2); **Lila als Hauptakzent**, bewusst frech; einzelne Seiten in **Signalrot** (nach der Markenregel passend für Workshop-Inhalte); Logo vorhanden | Admin 2026-10-29 |
| E-24 | Löschfristen | **Konten bleiben bestehen** (keine automatische Löschung; Löschung durch die Person selbst oder das Team). CME-Nachweise (Name, EFN, Anwesenheit): solange das Konto besteht, sonst **10 Jahre**, getrennt aufbewahrt. Anmeldungen **ohne Konto**: **14 Monate nach dem Symposium anonymisieren** (Name, E-Mail, Institution, EFN entfernen; Zahlen für die Statistik bleiben) – so können Wiederkehrende beim nächsten Symposium ihre Daten per Einmal-Link an die bekannte Adresse übernehmen, wer nicht wiederkommt, ist danach weg | Admin 2026-10-29 (O-18, O-21 entschieden) |
| E-25 | Repo-Sichtbarkeit | **öffentlich**, mit Trennung: keine Zugangsdaten, Kontonamen, IP-Adressen, Serverpfade oder Hosting-Interna im Repo (R-10); Betriebsangaben bleiben in `gen-em/server-infrastruktur` (privat) bzw. beim Admin; Quelltext-Link in der Fußzeile der Webseite | Admin 2026-10-29 („wenn trennbar, gerne public“). Konzept entsprechend bereinigt |
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
| E-43 | Team-Login (AP-3 Schritt 1) | **Ablauf:** Passwort, dann Code aus der Authenticator-App (TOTP, 30 s, 6 Stellen) oder ein Wiederherstellungscode; beim ersten Login Pflicht-Einrichtung mit QR-Code und Schlüssel zum Abtippen, danach zehn Wiederherstellungscodes (nur einmal angezeigt, nur als Hash gespeichert). Kein Code gilt zweimal. Passkeys später (O-14). **Schlüssel für das TOTP-Geheimnis:** per HKDF aus dem vorhandenen `geheimnis` in config.php abgeleitet, kein zusätzlicher Eintrag; wer das Geheimnis tauscht, muss den zweiten Faktor aller Team-Konten neu einrichten (Wiederherstellungscodes gelten weiter). **Neue Team-Mitglieder:** Ein Betreiber legt das Konto an und bekommt einen Einrichtungslink angezeigt (72 h gültig, einmal benutzbar), den er selbst weitergibt, solange es keinen Mailversand gibt (AP-6). Derselbe Weg dient als Passwort-Reset. **Team-Konten (nur Betreiber):** Link erzeugen, zweiten Faktor zurücksetzen, sperren, Rolle ändern (mit eigenem Code, E-41); das eigene Konto nicht, und es bleibt immer ein aktiver Betreiber. Passwortwechsel, Sperre, Rollenwechsel und Zurücksetzen beenden alle Sitzungen des Kontos. **Notzugang** (O-34): `/notzugang` im Browser mit dem Installationsschlüssel aus config.php und der Adresse eines Betreiber-Kontos setzt dessen zweiten Faktor zurück und zeigt einen Passwort-Link (3 Versuche je Stunde, Audit, nicht verlinkt); zusätzlich `php cli/konto.php` für Server mit Kommandozeile. Ein Betreiber reicht (Admin 2026-10-29). **Adressen:** `/einloggen`, `/team`, `/team/profil` („Mein Konto“), `/team/konten`; Link „Team“ in der Fußzeile | Umsetzung 2026-10-29 (AP-3 Schritt 1) |
| E-44 | Veranstaltungs-Einstellungen (AP-3 Schritt 2) | Reiter „Einstellungen“ für Admin und Betreiber, gruppiert: **Veranstaltung** (Datum, Beginn, Ende, Ort, Gesamtkapazität; 0 = unbegrenzt), **CME** (Anzeige in den Eckdaten, Punkte, Kammer, Veranstaltungsnummer), **Anmeldung** (Anmeldung ab, Anmeldeschluss = Ende des Anmeldezeitraums, leer = bis Beginn; Workshops je Person, 0 = kein Limit; Workshop-Wahl freiwillig/Pflicht), **Nachrücken** (Annahmefrist in Stunden, Grenze in Tagen vor der Veranstaltung), **Stichtage** (Workshops, Essen, Storno; jeweils bis einschließlich des Tages, leer = bis zur Veranstaltung), **Berufsgruppen** (je Zeile; die erste fragt die EFN ab). Startwerte: 20.01.2027, 18:00–22:00, Ort leer (steht noch nicht fest, Admin 2026-10-29), Kapazität 240, „Punkte beantragt“, Anmeldung ab 25.11.2026 00:00, 48 h, 14 Tage, Berufsgruppen nach E-21. Eingabe in Ortszeit, gespeichert in UTC; Prüfung auf Zusammenhänge (Ende nach Beginn, Stichtage und Anmeldestart nicht nach der Veranstaltung). Änderungen erscheinen sofort auf der Startseite und stehen im Audit-Protokoll. Die Werte zur Anmeldung wirken mit AP-4 | Umsetzung 2026-10-29 (AP-3 Schritt 2) |

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
  - {baustein: "passkey_lib.php", anpassung: "anpassen oder durch eine kleine WebAuthn-Bibliothek ersetzen – in AP-5 entscheiden (O-14)"}
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
| R-08 | Lima-City-Besonderheiten beachten (Abschnitt 2): eigene Sitzungsablage, SMTP-Zeitlimit 5 s, MySQL 8.4. |
| R-09 | Alle Texte der Oberfläche und Mails auf Deutsch; Ton nach zina-branding (`4 Regeln/Erscheinungsbild.md`). |
| R-10 | Öffentliches Repo (E-25): keine Kontonamen, IP-Adressen, Serverpfade, Postfach-Logins oder Hosting-Interna in Code, Doku, Commits, PR-Texten und Issues; Domain und öffentliche Absenderadressen dürfen genannt werden. Betriebsangaben gehören in das private Repo `gen-em/server-infrastruktur` oder zum Admin. Alles Umgebungsspezifische liegt in `config.php`. |
| R-11 | Alles, was die Veranstaltung betrifft (Fristen, Limits, Kapazitäten, Zeiträume), ist eine Einstellung in der Oberfläche, kein fester Wert im Code. |
| R-12 | Browser-Prüfung vor jedem PR (Admin 2026-10-29): jede Funktion wird in der Sandbox in **Chromium, Firefox und WebKit** (Safari-Engine) auf **Handy (390 px), Tablet (768 px), schmalem Desktop (1024 px) und breitem Desktop (1440 px)** geprüft – Darstellung (kein seitliches Scrollen, nichts ragt aus dem Fenster), Klickwege, **Auswahlfelder** (jede Option), **Aufklapp-Elemente** und **Popups**, keine Konsolenfehler. Werkzeug: `tools/browser/durchlauf.js`; neue Seiten und Abläufe werden dort ergänzt, das Ergebnis (Zahl) steht im PR und im Prüfprotokoll. Ziel: Fehler vor der Auslieferung finden, damit der Admin möglichst wenig selbst testen muss. Grenze: WebKit ist nicht Safari; ein echtes iPhone bleibt eine Stichprobe des Admins. |

## 6. Arbeitspakete

```yaml
- id: AP-1
  titel: Grundgerüst und Auslieferung
  ziel: "Leere, sichere App läuft auf dem Lima-City-Webspace unter winter-notfallsymposium.de (Staging und Produktion)"
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
    - "Platzhalter entfernen: Eckdaten der Startseite kommen seit Schritt 2 aus den Einstellungen (erledigt); freie Plätze aus der Platzzählung (AP-4); offen: das Beispielprogramm (server/seiten/teil_beispielprogramm.php) durch das echte Programm ersetzen"
    - "Ergänzt durch die Mockups: Programm-Modell, Typen, Dozierende, Workshop-Blöcke, Entwurf/Veröffentlichen, Team-Reiter „Programm“ (E-39); freie Plätze X/Y auf der Startseite (E-40)"
  abnahmekriterien:
    - Änderungen erscheinen sofort auf der Startseite
    - Plätze können nicht unter die Zahl bereits vergebener Plätze gesenkt werden (Warnung; Überhang bleibt bestehen)
    - jede Einstellung wirkt nachweislich (Prüfprotokoll)
    - "Schritt 1: ohne zweiten Faktor kein Zugriff auf den Team-Bereich; Admin sieht keine Team-Konten"
  schritte:
    - {nr: 1, titel: "Konten und Team-Login", status: "umgesetzt, auf Staging vom Admin genutzt (zweiter Faktor funktioniert)"}
    - {nr: 2, titel: "Veranstaltungs-Einstellungen", status: "umgesetzt, Staging offen"}
    - {nr: 3, titel: "Programm", status: offen}
  status: "in Arbeit (Schritt 1 und 2 umgesetzt)"
  ergebnis: "Schritt 1: Tabelle konto mit Rollen ersetzt team_konto (Migration 4, Konten mit gleicher id übernommen); Login mit Passwort und Pflicht-TOTP inkl. Einrichtung per QR-Code, Wiederherstellungscodes, Wiederholungsschutz; Einrichtungs-/Passwortlinks; Team-Bereich mit Übersicht, Mein Konto, Team-Konten (Betreiber); Rollenwechsel mit eigenem Code; Ratenschutz für Login und Codes; Notzugang mit Installationsschlüssel und Notweg auf der Kommandozeile; Login-Seiten von der Wartung ausgenommen. Details E-43. Schritt 2: Reiter „Einstellungen“ mit allen Veranstaltungs-Einstellungen nach Katalog (lib/veranstaltung_lib.php), Prüfung mit Fehleranzeige am Feld, Eckdaten der Startseite aus den Einstellungen (Platzhalter entfernt), Hinweis „Anmeldung ab …“. Details E-44"
  probleme:
    - {problem: "Kein Mailversand vor AP-6, neue Team-Mitglieder brauchen trotzdem einen Weg ins Konto", loesung: "Einrichtungslink wird dem Betreiber einmal angezeigt; er gibt ihn selbst weiter (E-43)"}
    - {problem: "NAdoku versiegelt mit eigenem Serverschlüssel in config.php; ein weiterer Eintrag hätte die Einrichtung auf Staging und Produktion erschwert", loesung: "Schlüssel per HKDF aus dem vorhandenen geheimnis abgeleitet (E-43)"}
    - {problem: "Bliebe der einzige Betreiber ohne Handy und Codes, käme niemand mehr in den Team-Bereich", loesung: "Notzugang im Browser mit dem Installationsschlüssel (O-34, E-43), dazu cli/konto.php für Server mit Kommandozeile"}
    - {problem: "Aktionen-Spalte der Konten-Tabelle sprang aus der Tabelle (Zelle als Flexbox)", loesung: "Flexbox in ein inneres Element verlegt; im Browser bei 390 und 1280 px geprüft"}
    - {problem: "Konten-Tabelle auf Tablet und Handy rechts abgeschnitten (Knöpfe halb verdeckt); der erste Browser-Durchlauf nahm scrollbare Tabellen von der Prüfung aus und sah es nicht, gefunden beim Ansehen der Bildschirmfotos", loesung: "Tabellen mit Klasse .karten unter 960 px als Karten; der Durchlauf prüft Tabellen jetzt mit (verdecktes Seitwärts-Scrollen ist ein Fehler); Gegenprobe ohne Karten meldet den Fehler in allen Engines"}
    - {problem: "Bitwarden erkannte das Code-Feld in Firefox nicht (Admin auf Staging); Firefox gibt autocomplete=one-time-code nicht als Eigenschaft weiter, Bitwarden sucht dann Stichwörter", loesung: "Feldname totp statt code (wie im Familien-Server); Browser-Durchlauf prüft das auf jeder Seite"}
    - {problem: "Mit dem Reiter „Einstellungen“ passten die Team-Reiter auf dem Handy nicht mehr in eine Zeile, „Team-Konten“ lag rechts außerhalb (Browser-Durchlauf, 36 Fehler)", loesung: "Reiter brechen in eine zweite Zeile um"}
    - {problem: "Für die Einstellungsseite gibt es kein Mockup", loesung: "aus vorhandenen Bausteinen gebaut (Kasten, Formularfelder, Team-Reiter); Freigabe beim Ansehen auf Staging"}

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
    - "Warteliste je Workshop-Termin und für die Gesamtkapazität; Nachrück-Angebot nach E-19 (Angebot per Mail mit Einmal-Link, Annahmefrist, bei Ablauf Weitergabe und Person ans Ende der Liste mit Mail, Grenze für automatische Angebote, danach manuelle Vergabe durch das Team); alles in Transaktionen (R-04)"
  nadoku_bausteine: [registrieren.php (Bremsen), pow.js, email_lib, wegwerfdomains.txt, Token-Muster, einwilligung_lib]
  abnahmekriterien:
    - Paralleltest: gleichzeitige Anmeldungen auf den letzten Platz vergeben ihn genau einmal
    - Storno löst Angebot an die nächste Person aus; Ablauf der Frist gibt das Angebot weiter und setzt die Person ans Ende; nach der Grenze keine automatischen Angebote mehr
    - abgelaufene oder benutzte Links funktionieren nicht mehr
    - EFN mit falscher Prüfziffer wird abgelehnt; EFN-Feld erscheint nur bei Ärztin/Arzt
    - nach einem Stichtag ist die jeweilige Änderung für Teilnehmende gesperrt, für das Team nicht
  status: offen
  ergebnis: null
  probleme: []

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
  status: offen
  ergebnis: null
  probleme: []

- id: AP-5
  titel: Team-Bereich
  ziel: "Team meldet sich sicher an, sieht Überblick und Statistik und verwaltet Anmeldungen"
  abhaengig_von: [AP-4, O-14]
  umfang:
    - "Team-Login und Rollen: mit AP-3 Schritt 1 vorgezogen (E-43); hier noch Passkeys (O-14) und die Anbindung an Mails (Einladung, Reset per Mail mit AP-6)"
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
  status: offen
  ergebnis: null
  probleme: []

- id: AP-6
  titel: Mailversand und Rundmails
  ziel: "Zuverlässiger Versand mit Prioritäten und Tageslimit; Rundmails an Teilnehmende"
  abhaengig_von: [AP-1, O-02]
  umfang:
    - SMTP-Client (aus NAdoku angepasst), Postfächer und Absender aus den Betreiber-Einstellungen (E-27)
    - Warteschlange mit Priorität (System vor Rundmail/Newsletter), Tageszähler je Postfach, Wiederholungen, Inhalt nach Versand leeren
    - Mail-Vorlagen: Bestätigung, Einmal-Link, Änderung, Storno, Nachrück-Angebot, Angebot abgelaufen, Platz erhalten, Wartelistenplatz, Zertifikat verfügbar
    - Rundmail an Teilnehmende mit Filter (alle, je Workshop-Termin, Warteliste, Berufsgruppe); Vorschau und Testversand an sich selbst
    - Anzeige im Team-Bereich: Warteschlange, Tageskontingent, Fehler
    - "Ergänzt durch die Mockups: Mail-Editor mit selbst gehosteten, eingebetteten Bildern, eingeschränktes HTML, Archiv gesendeter Mails (E-41); Abgrenzung Mailings/Newsletter O-29"
  nadoku_bausteine: [smtp.php, mail_lib (Muster), jobs_lib]
  abnahmekriterien:
    - Rundmail über dem Tageslimit wird auf Folgetage verteilt, Bestätigungsmails gehen trotzdem sofort raus
    - Mails kommen bei gängigen Anbietern an (Gmail, GMX/Web.de, Outlook) und landen nicht im Spam
  status: offen
  ergebnis: null
  probleme: []

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
  status: offen
  ergebnis: null
  probleme: []

- id: AP-8
  titel: Kaneo-Instanz
  status: entfallen   # E-15: bestehendes Gen-EM-Kaneo wird mitgenutzt; Einladung des Teams durch den Admin

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
  status: offen
  ergebnis: null
  probleme: []

- id: AP-9
  titel: Abschluss und Datenschutz
  ziel: "Löschkonzept umgesetzt, Gesamtprüfung bestanden"
  abhaengig_von: [AP-1, AP-2, AP-3, AP-4, AP-4b, AP-5, AP-6, AP-7, AP-10]
  umfang:
    - automatische Anonymisierung nach E-24 (Anmeldungen ohne Konto nach 14 Monaten, Mail-Warteschlange, Protokolle); Konten und CME-Nachweise nach E-24
    - Gesamttest aller Abläufe; Prüfprotokoll vollständig
    - Betriebsdoku (Backup, Wiederherstellung, Wartungsmodus) ohne Hosting-Interna (R-10)
  status: offen
  ergebnis: null
  probleme: []
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
- {id: O-14, thema: Passkey, frage: "passkey_lib aus NAdoku anpassen oder kleine WebAuthn-Bibliothek (AGPL-kompatibel) nutzen?", zustaendig: Umsetzung, benoetigt_fuer: AP-5, status: "zurückgestellt: erst nur TOTP, Passkeys später (Admin 2026-10-29)"}
- {id: O-15, thema: Berufsgruppen, status: "entschieden → E-21"}
- {id: O-16, thema: CME-Meldeweg, frage: "Meldung an die Kammer elektronisch (EIV) oder per Liste? Entscheidet, welchen Export AP-10 liefert", hinweis: "Zertifikat-Layout liefert der Admin (E-22)", zustaendig: Admin, benoetigt_fuer: AP-10, status: offen (nicht eilig)}
- {id: O-17, thema: Abgelaufenes Nachrück-Angebot, status: "entschieden → E-19 (ans Ende der Liste, mit Mail)"}
- {id: O-18, thema: Aufbewahrung CME-Nachweise, status: "entschieden → E-24 (solange das Konto besteht, sonst 10 Jahre)"}
- {id: O-19, thema: PDF-Erzeugung, frage: "Der Admin liefert den Zertifikat-Entwurf; die App setzt Name, Datum, ggf. EFN darauf. Technik je nach Entwurfsformat: PDF-Vorlage → Import und Überlagerung (z. B. FPDF + FPDI, beide MIT-/LGPL-kompatibel, ohne Composer in vendor/); SVG-/PNG-Vorlage → eigener minimaler PDF-Writer. Entwurf als PDF mit eingebetteten Schriften und freiem Feld für den Namen erbeten", zustaendig: "Umsetzung (Entwurf: Admin)", benoetigt_fuer: AP-10, status: offen}
- {id: O-20, thema: Logo-Dateien, status: "erledigt (Admin 2026-10-29): Logo liegt auf main des Branding-Repos unter 1 Logo/"}
- {id: O-21, thema: Löschfrist ohne Konto, status: "entschieden → E-24 (14 Monate, Anonymisierung)"}
- {id: O-22, thema: Lima-City-Einrichtung, frage: "Subdomain test.winter-notfallsymposium.de → Ordner staging/public, Hauptdomain → Ordner produktiv/public (Zuordnung im Lima-City-Konto, E-34); je eine MySQL-Datenbank mit eigenem Benutzer für Staging und Produktion; Cron-Aufruf des Job-Endpunkts; Datenbank-Zugangsdaten kommen nur in die config.php auf dem Webspace", zustaendig: Admin, benoetigt_fuer: AP-1, status: in Arbeit (Admin 2026-10-29)}
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
- {id: O-34, thema: "Notweg ohne Betreiber", frage: "Gibt es im Lima-City-Tarif SSH oder eine andere Möglichkeit, `php cli/konto.php` auf dem Webspace auszuführen? Wenn nicht: zweiten Betreiber Pflicht machen, oder einen Notweg über den Installationsschlüssel aus config.php im Browser bauen?", zustaendig: Admin, benoetigt_fuer: "AP-3 (vor dem Produktivstart)", status: "entschieden (Admin 2026-10-29): Notzugang im Browser mit dem Installationsschlüssel; ein Betreiber reicht, kein Zwang zu zwei"}
```

## 8. Bekannte Risiken und Einschränkungen

| ID | Risiko | Umgang |
|---|---|---|
| K-01 | Zeitplan: knapp 4 Wochen bis zum Anmeldestart | Newsletter und Zertifikate nach dem Start; Konten (AP-4b) mit Rückfall nach dem Start; Umfang von AP-1 bis AP-6 nicht ausweiten; Mockup-Freigabe (AP-2) zügig |
| K-02 | Mail-Limit 500 Empfänger/24 h je Postfach | Tageslimit, Prioritäten, zwei Postfächer; Wechsel zu EU-Versanddienst vorbereitet |
| K-03 | Lima-City-Botschutz kann automatisierte Tests und ggf. Formular-POSTs blockieren | beim Hoster für die Domain klären; echte Anmeldungen vor dem Start mit verschiedenen Browsern testen |
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
- Zentraler Login für Gen-EM (Keycloak) – bewusst nicht jetzt (E-12)

## 11. Änderungsprotokoll des Konzepts

Unter der Erstfassung steht der neueste Eintrag zuerst.

| Datum | Änderung |
|---|---|
| 2026-10-29 | Erstfassung nach Abstimmung mit dem Admin: E-01 bis E-18, R-01 bis R-09, AP-1 bis AP-9, O-01 bis O-14. Grundlagen: Analyse NAdoku (Login, Registrierung, Spamschutz, Rollen, Mail, Verschlüsselung, Betrieb), Kaneo-Doku (OIDC vorhanden, kein Theme), zina-branding, Gen-EM-Konzept (Server-Ressourcen, Kaneo-Stack). |
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
| 2026-10-29 | AP-1 auf Staging abgenommen: Status und Ergebnis ergänzt; Hinweis auf das Hoster-Cookie `_lcp` in AP-2 (Datenschutz). |
| 2026-10-29 | AP-1 Nachtrag: FTP-Pfade der Auslieferung (E-32: Zugang landet im Ordner wintersymposium, Pfade /staging und /produktiv), Problem und Lösung der ersten Auslieferung eingetragen. |
| 2026-10-29 | Umsetzung AP-1: Status, Ergebnis und Probleme eingetragen; E-34 (Webroot public/) und E-35 (Produktion per Tag) neu, E-32 angepasst, O-22 um public/ ergänzt; Widersprüche bereinigt (E-07 und E-11 an E-30 angeglichen, Logo-Stand in Abschnitt 2, O-08); noindex bis zum Start in AP-2 und Checkliste aufgenommen; YAML-Fehler in O-19 behoben (Wert mit Doppelpunkt in Anführungszeichen). |
| 2026-10-29 | E-32 (Auslieferung: Secrets, Zielordner staging/produktiv, Zuordnung der Domains), E-33 (Plan B Konten bestätigt), O-22 (Lima-City-Einrichtung) neu; O-10 Repo wird öffentlich. |
| 2026-10-29 | Domain winter-notfallsymposium.de und Postfächer eingetragen (E-05, E-13; O-01, O-02 erledigt); E-24: Anmeldungen ohne Konto nach 14 Monaten anonymisieren (O-21); Zertifikat-Entwurf kommt vom Admin (E-22, O-19); Logo auf main (O-20 erledigt); R-10 präzisiert. |
| 2026-10-29 | Konzeptanpassung Admin: E-30 (optionales Konto mit Profil), E-31 (Stichtage für Teilnehmende), AP-4b (Konten) neu, AP-5 um Statistik-Seite erweitert; Antworten eingearbeitet: E-21 Berufsgruppen (O-15), E-22 EFN freiwillig und nur Ärztin/Arzt (O-16 verengt), E-19 abgelaufenes Angebot ans Listenende (O-17), E-24 Konten bleiben, CME 10 Jahre (O-18), O-21 Löschfrist ohne Konto neu; O-20 Logo-Branch benannt. |
| 2026-10-29 | Kaneo: E-15 geändert auf Mitnutzung des bestehenden Gen-EM-Kaneo; AP-8 entfallen, E-29 und O-09 entfallen, K-05 gestrichen. |
| 2026-10-29 | Antworten des Admins eingearbeitet: E-19 bis E-29 (Nachrücken mit Annahmefrist, Workshop-Regeln, Anmeldefelder, CME mit Zertifikat, Mockups und Lila/Rot, Löschfristen, Repo öffentlich, PR, mehrere Domains/Absender, Staging, Kaneo-Subdomain); R-10 und R-11 neu; AP-2 mit Mockup-Halt, AP-3 um Veranstaltungs-Einstellungen, AP-4 um Nachrück-Angebote und EFN, AP-5 um Betreiber-Einstellungen erweitert; AP-10 (Teilnahme und Zertifikate) neu; O-03 bis O-08 und O-10 bis O-13 entschieden, O-15 bis O-20 neu; K-07, K-08 neu; Hosting-Interna nach R-10 entfernt. |
