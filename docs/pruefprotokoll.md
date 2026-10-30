---
dokument: pruefprotokoll
projekt: wintersymposium
repo: gen-em/wintersymposium
stand: 2026-10-29
---

# Prüfprotokoll: Webseite Wintersymposium

Zu jedem Arbeitspaket aus `docs/konzept.md` Abschnitt 6 steht hier, was geprüft wurde und was noch wie zu prüfen ist. Prüfungen tragen eine T-Nummer (T-100 ff.; jede Nummer nur einmal – AP-3 hat T-120 bis T-129 und T-210 bis T-222, weil T-130 bis T-144 für AP-4 vergeben sind), Ergebnisse werden mit Datum, Prüfer (`admin`, `claude-dev`) und entscheidender Ausgabe (ohne Secrets, ohne Hosting-Interna – R-10) eingetragen.

## 1. Stand

| AP | Titel | Status | Geprüft | Offen |
|---|---|---|---|---|
| AP-1 | Grundgerüst und Auslieferung | auf Staging abgenommen | Staging: T-100, T-101, T-105, T-106 (Staging), T-107, T-108 (ohne Login), T-110; lokal: T-102, T-104 | Produktion per Tag (T-106); T-103 Ablauf, T-108 Login-Teil (mit AP-5); T-102/T-109 im Browser (mit AP-4); T-104 über HTTP (optional) |
| AP-2 | Design-Mockups und öffentliche Seiten | auf Staging abgenommen | T-111 bis T-114, T-116; T-115 teilweise | T-115 Pflege mit AP-5 |
| AP-3 | Team-Login, Programm und Veranstaltungs-Einstellungen | Schritt 1 bis 3 umgesetzt, Staging für Schritt 2 und 3 offen | lokal T-120, T-121, T-127 bis T-129, T-150, T-151, T-153, T-154, T-210 bis T-222 | Staging Schritt 2 und 3 (siehe unten); T-122 bis T-126 Wirkung mit AP-4 |
| AP-4 | Anmeldung, Einmal-Links, Warteliste und Nachrücken | umgesetzt, Staging offen | lokal T-130 bis T-144; Mailversand auf Staging (Admin 2026-10-29) | Staging (siehe unten) |
| AP-4b | Konten für Teilnehmende | umgesetzt, Staging offen | lokal T-145 bis T-149 | Staging (siehe unten); Newsletter-Teil mit AP-7 |
| AP-5 | Team-Bereich | umgesetzt, Staging offen | lokal T-150, T-151, T-153, T-155 bis T-159; T-154 im Code | Staging (siehe unten); T-152 Passkey mit O-14 |
| AP-6 | Mailversand und Rundmails | umgesetzt, Staging offen | lokal T-161 bis T-164, T-166 bis T-169 | Staging (siehe unten); T-160 und T-165 (Zustellbarkeit) durch den Admin |
| Rückmeldungen 30.10. | Navigation, Programm, Texte, Zwei-Faktor, Protokoll, Einstellungen, Dialog (E-51) | umgesetzt, Staging offen | lokal T-225 bis T-232 | Staging (siehe unten) |
| Rückmeldungen 30.10. (2) | Programm: Löschen, Ziehen mit dem Finger, Termine, Dozierende je Termin, Blöcke (E-53) | umgesetzt, Staging offen | lokal T-233 bis T-237 | Staging (siehe unten) |
| Editor, Konto, Navigation, Löschfrist | ein Editor, ein Konto, Personen-Menü, Team-Navigation A, Löschfrist (E-54) | umgesetzt, Staging offen | lokal T-238 bis T-242 | Staging (siehe unten) |
| Passkeys | Einloggen ohne Passwort, Passkey als zweiter Faktor (E-55) | umgesetzt, Staging offen | lokal T-243 bis T-246 | Staging mit echten Geräten (siehe unten) |
| AP-7 | Newsletter | umgesetzt, Staging offen | lokal T-170 bis T-174 | Staging (siehe unten); Datenschutzerklärung ergänzen |
| AP-8 | Kaneo-Instanz | entfallen (E-15) | – | – |
| AP-10 | Teilnahme und Zertifikate | offen | – | T-190 bis T-195 |
| AP-9 | Abschluss und Datenschutz | offen; Sicherung vorgezogen (E-47) | lokal T-201, T-223, T-224 | T-200 bis T-204 |

## 2. Geplante Prüfungen

```yaml
# AP-1 Grundgerüst und Auslieferung
- {id: T-100, ap: AP-1, was: "Installer legt DB und ersten Betreiber an, sperrt sich danach (install.lock)", wie: "Installation auf Staging über /install (docs/betrieb.md Abschnitt 2); zweiter Aufruf von /install muss 404 liefern; var/install.lock liegt im Zielordner", status: "bestanden (Staging 2026-10-29)"}
- {id: T-101, ap: AP-1, was: "HTTPS und Sicherheits-Header", wie: "curl -sI https://<domain>/ – CSP, HSTS, X-Content-Type-Options, Referrer-Policy, X-Frame-Options vorhanden", status: "bestanden (Staging 2026-10-29)"}
- {id: T-102, ap: AP-1, was: "CSRF-Schutz", wie: "POST ohne/mit falschem Token wird abgelehnt", status: "lokal bestanden; auf Staging mit AP-4 (Formulare) erneut"}
- {id: T-103, ap: AP-1, was: "Sitzungen in eigener Ablage, Ablauf 30 min", wie: "eigener Sitzungsordner im Webspace; Ablauf nach Wartezeit prüfen", status: "teilweise: Ablage var/sitzungen auf Staging vorhanden; Ablauf nach 30 min offen (mit AP-5-Login prüfen)"}
- {id: T-104, ap: AP-1, was: "Ratenschutz", wie: "11-mal /health mit falschem Token → danach auch mit richtigem Token 404 für 10 min; nach Ablauf wieder 200", status: "lokal bestanden; über HTTP auf Staging offen (optional)"}
- {id: T-105, ap: AP-1, was: "Cron-Job läuft", wie: "Lima-City-Cron ruft Job-Endpunkt auf; Zeitstempel letzter Lauf in der DB aktualisiert sich", status: "bestanden (Staging 2026-10-29, Cron per URL)"}
- {id: T-106, ap: AP-1, was: "Auslieferung per GitHub Actions (E-32)", wie: "Push auf main → Ordner staging aktualisiert, produktiv unverändert; Freigabeweg → Ordner produktiv; config.php, install.lock und Laufzeitordner bleiben in beiden Zielen unverändert (Prüfsumme vorher/nachher)", status: "Staging bestanden (2026-10-29); Produktion per Tag offen"}
- {id: T-107, ap: AP-1, was: "Health-Endpunkt", wie: "GET /health mit Kopfzeile X-Health-Token oder ?token= liefert 200 und JSON (status ok, Schema ist = soll); ohne Token 404", status: "bestanden (Staging 2026-10-29)"}
- {id: T-108, ap: AP-1, was: "Wartungsmodus", wie: "var/wartung.lock per FTP anlegen → Startseite 503 mit Hinweisseite, /health meldet wartung: true, Team-Login weiter möglich (ab AP-5); Datei löschen → wieder 200", status: "bestanden ohne Login-Teil (Staging 2026-10-29); Login-Teil nach AP-5"}
- {id: T-109, ap: AP-1, was: "Lima-City-Botschutz", wie: "Formular-POST per curl und per Browser; bei 403 beim Hoster klären (K-03)", status: "teilweise: curl-POST erreicht die App (kein Hoster-403); Browser-Formular mit AP-4"}
- {id: T-110, ap: AP-1, was: "Mehrere Hostnamen (E-27)", wie: "Aufruf über nicht erlaubten Host → Ablehnung; erlaubter Host → richtige Basis-URL in Links und Mails", status: "bestanden (Staging 2026-10-29; fremder Host vom Hoster mit 404 abgewiesen)"}

# AP-2 Design-Mockups und öffentliche Seiten
- {id: T-111, ap: AP-2, was: "Mockups vom Admin freigegeben", wie: "Freigabe im Chat/PR mit Datum festhalten", status: "bestanden (Freigabe 2026-10-29)"}
- {id: T-112, ap: AP-2, was: "Startseite auf Handy und Desktop", wie: "Browser-Prüfung 360 px, 768 px, 1280 px; kein horizontales Scrollen", status: "bestanden (lokal 390 und 1280 px; Staging vom Admin geprüft)"}
- {id: T-113, ap: AP-2, was: "Kontraste WCAG AA", wie: "Prüfwerkzeug (z. B. axe) auf Startseite und Anmeldeformular; Lila und Signalrot auf Nachtblau und Weiß", status: "bestanden (Kontraste berechnet, alle Paare AA); Prüfwerkzeug auf Staging optional"}
- {id: T-114, ap: AP-2, was: "Keine externen Ressourcen", wie: "Netzwerkanalyse im Browser: nur eigene Domain", status: "bestanden (automatischer Test lokal und im Prüf-Workflow; Netzwerkreiter auf Staging optional)"}
- {id: T-115, ap: AP-2, was: "Impressum, Datenschutz, Quelltext-Link erreichbar und pflegbar", wie: "Links in Fußzeile; Änderung durch Betreiber sichtbar", status: "teilweise: erreichbar und verlinkt; Pflege durch Betreiber mit AP-5"}
- {id: T-116, ap: AP-2, was: "Schriften und Logo korrekt ausgeliefert", wie: "Anton, Antonio, Tajawal, IBM Plex Mono laden lokal (woff2); Logo scharf auf Retina", status: "bestanden (lokal; Staging vom Admin geprüft)"}

# AP-3 Programm und Veranstaltungs-Einstellungen
- {id: T-120, ap: AP-3, was: "Zeitfenster mit freien, überlappenden Zeiten anlegbar", wie: "Zwei überlappende Zeitfenster anlegen, Darstellung prüfen", status: "lokal bestanden (Workshop-Termine und Station parallel im Block, Zeitraster und Liste in 3 Engines × 4 Breiten)"}
- {id: T-121, ap: AP-3, was: "Programm erscheint sofort auf der Startseite", wie: "Workshop-Termin ändern, Startseite neu laden", status: "lokal bestanden: Einstellungen sofort; Programm nach „Jetzt veröffentlichen“ (E-39: Speichern ≠ Veröffentlichen, vorher unverändert)"}
- {id: T-122, ap: AP-3, was: "Plätze nicht unter vergebene Plätze senkbar", wie: "Termin mit 3 Anmeldungen auf 2 Plätze setzen → Warnung, Überhang bleibt", status: "Prüfung vorhanden (Meldung am Feld, Löschen gesperrt), lokal nur mit 0 belegten Plätzen; Wirkung mit AP-4"}
- {id: T-123, ap: AP-3, was: "Anmeldezeitraum", wie: "Vor Start und nach Ende: Formular geschlossen mit Hinweis", status: "Einstellung vorhanden und geprüft (lokal); Wirkung auf das Formular mit AP-4"}
- {id: T-124, ap: AP-3, was: "Workshop-Limit und Pflicht einstellbar", wie: "Limit 1 setzen → zweiter Workshop abgelehnt; Pflicht an → Anmeldung ohne Workshop abgelehnt; beides aus → frei", status: "Einstellung vorhanden und geprüft (lokal); Wirkung mit AP-4"}
- {id: T-125, ap: AP-3, was: "Nachrück-Einstellungen wirken", wie: "Annahmefrist auf 1 h setzen → Angebot läuft nach 1 h ab; Grenze auf heute setzen → keine automatischen Angebote", status: "Einstellung vorhanden und geprüft (lokal); Wirkung mit AP-4"}
- {id: T-126, ap: AP-3, was: "Stichtage einstellbar (E-31)", wie: "Stichtage für Workshop, Essen, Storno getrennt setzen; Anzeige auf Startseite und in der Anmeldung stimmt", status: "Einstellung vorhanden und geprüft (lokal); Wirkung und Anzeige in der Anmeldung mit AP-4"}
- {id: T-127, ap: AP-3, was: "Alte Team-Konten übernommen", wie: "Migration 4 auf einem Stand mit team_konto: gleiche id, Rolle, ohne zweiten Faktor; team_konto entfernt", status: "lokal bestanden"}
- {id: T-128, ap: AP-3, was: "Einrichtungslink", wie: "Betreiber legt Konto an → Link einmal angezeigt; Passwort setzen; Link danach ungültig; nach 72 h abgelaufen; nur Hash gespeichert", status: "lokal bestanden"}
- {id: T-129, ap: AP-3, was: "Rollenwechsel nur mit eigenem Code (E-41)", wie: "falscher Code → abgelehnt; richtiger → Rolle geändert, Sitzung des Kontos beendet", status: "lokal bestanden"}
- {id: T-216, ap: AP-3, was: "Sperren und Sitzungen", wie: "gesperrtes Konto kommt nicht rein; eigenes Konto nicht sperrbar; letzter Betreiber bleibt", status: "lokal bestanden"}
- {id: T-217, ap: AP-3, was: "Ratenschutz Login", wie: "5 Fehlversuche für eine Adresse → gesperrt; unbekannte Adresse gleiche Meldung", status: "lokal bestanden"}
- {id: T-218, ap: AP-3, was: "Notweg Kommandozeile", wie: "cli/konto.php zweitfaktor/link", status: "lokal bestanden (nur wo eine Kommandozeile verfügbar ist)"}
- {id: T-220, ap: AP-3, was: "Notzugang im Browser (O-34)", wie: "falscher Schlüssel oder unbekannte Adresse → gleiche Meldung, nichts geändert; richtiger Schlüssel → zweiter Faktor zurückgesetzt, Link angezeigt, alte Sitzung beendet, Audit-Eintrag; nach 3 Fehlversuchen gesperrt", status: "lokal bestanden"}
- {id: T-221, ap: AP-3, was: "Bitwarden füllt den Code ein (auch in Firefox)", wie: "Code-Felder heißen totp; Browser-Durchlauf prüft Stichwort in id/Name auf jeder Seite; beim Admin: Firefox mit Bitwarden → Menü „Fill verification code“ am Code-Feld", status: "lokal bestanden (Durchlauf 855 ok; Gegenprobe mit altem Namen → 12 Fehler); beim Admin offen"}
- {id: T-222, ap: AP-3, was: "Veranstaltungs-Einstellungen speichern und prüfen", wie: "Startwerte gültig; ungültige Eingaben (Datum, Uhrzeit, Zahlenbereich, Auswahl, doppelte Liste, Ende vor Beginn, Stichtag oder Anmeldestart nach der Veranstaltung, Anmeldeschluss vor Start) abgelehnt und am Feld markiert, Eingabe bleibt stehen; Ortszeit ↔ UTC; Speichern ändert die Startseite sofort, Audit-Eintrag; ohne Login nicht speicherbar; im Browser alle drei Engines × 4 Breiten", status: "lokal bestanden"}
- {id: T-210, ap: AP-3, was: "Programmpunkt anlegen und prüfen", wie: "je Typ speichern; Pflichtfelder, Beginn ohne Ende, Ende vor Beginn, Termin mit 0 Plätzen abgelehnt und markiert; Felder, die der Typ nicht hat, geleert; Typwechsel blendet Felder aus; Kürzel-Vorschlag", status: "lokal bestanden"}
- {id: T-211, ap: AP-3, was: "Speichern ≠ Veröffentlichen", wie: "Speichern → Banner und Markierung, Startseite unverändert; Vorschau zeigt Arbeitsstand; Veröffentlichen → Startseite; Verwerfen stellt den Stand wieder her (auch Gelöschtes); Punkt ohne Uhrzeit blockiert Veröffentlichen", status: "lokal bestanden"}
- {id: T-212, ap: AP-3, was: "Ziehen und Termine", wie: "neuer Punkt ohne Uhrzeit oben hell-lila, auf „Fall #1“ ziehen → 19:15–19:45; Block verschieben nimmt Termine und Station mit; + Termin und Duplizieren (Anschluss, gleiche Dauer)", status: "lokal bestanden (Chromium und Firefox echtes Ziehen, WebKit mit ausgelösten Ereignissen)"}
- {id: T-213, ap: AP-3, was: "Dozierende und Fotos", wie: "anlegen, Punkten zuordnen, Foto hochladen → quadratisch 480 px, ohne Metadaten, unter /bild/ mit Cache; falscher Name → 404; Foto entfernen; verwaiste Fotos im Job aufraeumen", status: "lokal bestanden"}
- {id: T-214, ap: AP-3, was: "Programm auf der Startseite", wie: "Popups (Vortrag mit Dozierenden, Workshop mit Terminen, Bring, Zettel) öffnen, ✕ und Escape schließen; Zeitraster ab 760 px, Liste darunter; Inhalte escaped; keine fremden Adressen", status: "lokal bestanden (Bildschirmfotos der Popups angesehen)"}
- {id: T-215, ap: AP-3, was: "Test-Beispieldatensatz", wie: "server/testdaten/beispieldaten.php lädt fehlerfrei und veröffentlicht vollständig (lauf.php); Browser-Durchlauf nutzt ihn", status: "lokal bestanden"}
- {id: T-219, ap: AP-3, was: "Alle Seiten im Browser-Durchlauf (R-12)", wie: "tools/browser/durchlauf.js: Chromium, Firefox, WebKit × 390/768/1024/1440 px; öffentliche Seiten, Login mit Einrichtung, Codes, Team-Reiter, Konto anlegen mit Auswahlfeld, Einrichtungslink, Ausloggen, Login mit Wiederherstellungscode; Auswahlfelder, Aufklapp-Elemente, Popups; kein Überstand, keine Konsolenfehler", status: "lokal bestanden (750 ok, 0 Fehler)"}

# AP-4 Anmeldung, Einmal-Links, Warteliste und Nachrücken
- {id: T-130, ap: AP-4, was: "Anmeldung mit Double-Opt-In", wie: "Anmelden, Bestätigungsmail, Link klicken → Status bestätigt; unbestätigte Anmeldung verfällt nach Frist und gibt den Platz frei", status: "lokal bestanden (HTTP: Mail mit Link, Klick bestätigt; Job löscht unbestätigte nach der Frist, Plätze gehen ans Nachrücken)"}
- {id: T-131, ap: AP-4, was: "Spamschutz", wie: "Honeypot gefüllt, Formular unter 4 s, ohne Proof-of-Work → stille Ablehnung mit gleicher Antwort", status: "lokal bestanden (Honigtopf, Formular unter 4 s, ohne gelöste Rechenaufgabe: gleiche Antwort, nichts angelegt)"}
- {id: T-132, ap: AP-4, was: "Wegwerfadresse abgelehnt", wie: "Anmeldung mit Domain aus wegwerfdomains.txt", status: "lokal bestanden (mailinator.com: gleiche Antwort, nichts angelegt)"}
- {id: T-133, ap: AP-4, was: "Pflichtfelder, Berufsgruppen und EFN", wie: "Ohne Berufsgruppe/Institution → Fehler; Sonstige zeigt Freitext; EFN-Feld nur bei Ärztin/Arzt sichtbar; leer erlaubt; falsche Prüfziffer → Fehler; gültige EFN → angenommen", status: "lokal bestanden (Felder am Feld markiert; EFN- und Sonstige-Feld im Browser je Berufsgruppe; EFN-Prüfziffer nach Luhn)"}
- {id: T-134, ap: AP-4, was: "Einmal-Link anfordern", wie: "Bekannte und unbekannte Adresse → gleiche Antwort, gleiche Dauer (±); Mail nur bei bekannter", status: "lokal bestanden (gleiche Antwort, Dauer innerhalb 0,3 s; Mail nur an bekannte Adresse)"}
- {id: T-135, ap: AP-4, was: "Einmal-Link: Ablauf und Einmalverwendung", wie: "Link zweimal öffnen; Link nach Ablauf öffnen", status: "lokal bestanden (zweiter Klick und abgelaufener Link: „Link abgelaufen“; bloßes Öffnen verbraucht nicht)"}
- {id: T-136, ap: AP-4, was: "Eigene Anmeldung ändern und stornieren", wie: "Workshop und Essen ändern, EFN nachtragen, dann stornieren; Mails kommen an", status: "lokal bestanden (Essen, Workshop-Termin, neue Adresse mit Bestätigung, Storno; Mails im Datei-Modus)"}
- {id: T-137, ap: AP-4, was: "Letzter Platz bei gleichzeitigen Anmeldungen", wie: "Skript mit 20 parallelen Anmeldungen auf 1 freien Platz → genau 1 Platz, 19 Warteliste", status: "lokal bestanden (20 Prozesse gleichzeitig: genau 1 Platz, 19 Warteliste)"}
- {id: T-138, ap: AP-4, was: "Nachrück-Angebot nach Storno", wie: "Storno eines Platzes → erste Person der Warteliste erhält Angebot per Mail; Annahme über Link → Platz; Platz ist währenddessen für andere gesperrt", status: "lokal bestanden (Angebot an die erste bestätigte Person, Platz bleibt reserviert, Annehmen mit Tausch-Bestätigung)"}
- {id: T-139, ap: AP-4, was: "Angebot läuft ab oder wird abgelehnt", wie: "Frist verstreichen lassen bzw. ablehnen → Angebot geht an die nächste Person; abgelaufene Person steht am Ende der Warteliste und hat eine Mail; Mails kommen an", status: "lokal bestanden (Ablauf per Job: Person ans Listenende, Mail; Ablehnen gibt den Platz frei)"}
- {id: T-140, ap: AP-4, was: "Grenze für automatisches Nachrücken", wie: "Symposium-Datum testweise auf in 10 Tagen setzen → Storno erzeugt kein Angebot, Team sieht offenen Platz zur manuellen Vergabe", status: "lokal bestanden (keine automatischen Angebote nach der Grenze); manuelle Vergabe durch das Team mit AP-5"}
- {id: T-141, ap: AP-4, was: "Überschneidende Termine gesperrt", wie: "Zwei Termine im selben Zeitfenster wählen → Ablehnung mit Hinweis", status: "lokal bestanden (Server lehnt ab; im Browser sofort gesperrt mit Grund)"}
- {id: T-142, ap: AP-4, was: "Token nur als Hash in der DB", wie: "Datenbank: Spalte enthält sha256, nicht den Link-Wert", status: "lokal bestanden"}
- {id: T-143, ap: AP-4, was: "Stichtage sperren Teilnehmende", wie: "Stichtag Workshop auf gestern setzen → Workshop-Änderung per Einmal-Link gesperrt mit Hinweis, Essen noch änderbar; Storno-Stichtag → Storno gesperrt", status: "lokal bestanden für Workshops (Knopf gesperrt mit Hinweis, Änderung abgelehnt); Essen und Storno gleich gebaut, auf Staging nachprüfen"}
- {id: T-144, ap: AP-4, was: "Stichtage sperren das Team nicht", wie: "Nach Stichtag ändert das Team Workshop und Essen einer Anmeldung; Audit-Eintrag", status: "lokal bestanden (Team ändert Essen nach dem Stichtag, Mail „vom Team“; Workshops ohne Limit und mit Überbuchen)"}

# AP-4b Konten für Teilnehmende
- {id: T-145, ap: AP-4b, was: "Konto beim Anmelden und nachträglich", wie: "Anmeldung mit „Konto anlegen“ → Bestätigung → Login zeigt Anmeldung; Anmeldung ohne Konto → Einmal-Link → Konto anlegen → Anmeldung im Profil", status: "lokal bestanden (Häkchen → nach dem Link Konto + Passwort festlegen; „Konto anlegen“ aus „Deine Anmeldung“; Anmeldung verknüpft)"}
- {id: T-146, ap: AP-4b, was: "Konto ohne Symposium-Anmeldung", wie: "Konto nur mit Newsletter anlegen; später Anmeldung aus dem Profil heraus", status: "lokal bestanden (/konto/neu mit Spamschutz, Bestätigung, Passwort; Anmelden mit Konto ohne zweite Bestätigung); Newsletter mit AP-7"}
- {id: T-147, ap: AP-4b, was: "E-Mail-Änderung mit Bestätigung", wie: "Neue Adresse eintragen → alte bleibt gültig bis Bestätigung; nach Bestätigung gehen Mails an die neue; Newsletter zieht mit", status: "lokal bestanden (Konto und Anmeldung ziehen mit); Newsletter mit AP-7"}
- {id: T-148, ap: AP-4b, was: "Login, Passwort vergessen, Ratenschutz, kein 2FA-Zwang", wie: "Falsches Passwort n-mal → Sperre; Reset-Link einmalig; Login ohne zweiten Faktor möglich", status: "lokal bestanden (Login unter /einloggen und im Kasten, Ratenschutz wie Team, Passwort-vergessen-Link einmalig, kein zweiter Faktor; Team im Kasten → zweiter Faktor)"}
- {id: T-149, ap: AP-4b, was: "Profil übernimmt Berufsgruppe/Institution, Konto löschen", wie: "Werte aus der Anmeldung im Profil, Änderung wirkt auf die Anmeldung; Konto löschen → Login weg, Anmeldung nach Regel E-24/O-21", status: "lokal bestanden (Profil aus der Anmeldung, Änderung wirkt auf beide; Löschen mit Wahl: Anmeldung behalten oder stornieren, Admin 2026-10-29)"}

# AP-5 Team-Bereich
- {id: T-150, ap: AP-5, was: "Login ohne zweiten Faktor verweigert", wie: "Konto ohne TOTP/Passkey → nach Passwort Zwang zur Einrichtung, kein Zugriff", status: "lokal bestanden (mit AP-3 vorgezogen)"}
- {id: T-151, ap: AP-5, was: "TOTP", wie: "Einrichten per QR, Code gültig, Wiederholung desselben Codes abgelehnt", status: "lokal bestanden (mit AP-3 vorgezogen)"}
- {id: T-152, ap: AP-5, was: "Passkey", wie: "Registrieren und anmelden auf Handy und Desktop", status: offen}
- {id: T-153, ap: AP-5, was: "Wiederherstellungscodes", wie: "Login mit Code; Code danach ungültig", status: "lokal bestanden (mit AP-3 vorgezogen)"}
- {id: T-154, ap: AP-5, was: "Rollen", wie: "Admin ruft Konten-, Betreiber-Einstellungs- und Rechtstext-Seiten auf → verweigert", status: "im Code: Betrieb und Team-Konten nur Betreiber, Rechtstexte unter „Texte“ nur Betreiber, Rollen nur Betreiber; mit einem Admin-Konto auf Staging durchklicken"}
- {id: T-155, ap: AP-5, was: "Statistik-Seite stimmt", wie: "Alle Kennzahlen (Workshops, Warteliste, offene Angebote, Essen, Berufsgruppen, Institutionen, EFN-Quote, Newsletter, Konten) gegen SQL-Abfragen prüfen; Diagramme und Export je Ansicht", status: "lokal bestanden (Kennzahlen gegen SQL, Filter Berufsgruppe; Balken und Verlauf als SVG, Tabellenansicht)"}
- {id: T-156, ap: AP-5, was: "Export", wie: "CSV öffnet in Excel korrekt (UTF-8 mit BOM, Umlaute); CME-Liste enthält nur Name und EFN", status: "lokal bestanden (Semikolon, UTF-8 mit BOM, Formeln entschärft; CME-Liste nur ÄrztInnen mit Name, Berufsgruppe, EFN – Kammerformat mit AP-10); in Excel beim Admin offen"}
- {id: T-157, ap: AP-5, was: "Audit-Protokoll", wie: "Änderung durch Team erzeugt Eintrag mit Zeit, Konto, Art", status: "lokal bestanden (Team-Änderungen mit Konto, Exporte, Texte; Seite „Protokoll“ mit Filter und Suche)"}
- {id: T-158, ap: AP-5, was: "Betreiber-Einstellungen: Hostnamen und Absender", wie: "Zweiten Hostnamen und Absender anlegen → Mails tragen den gewählten Absender; Host wird akzeptiert (T-110)", status: "lokal bestanden (Host hinzufügen, abschalten, Haupt-Host; Absender aus der Einstellung vor config.php); Absender auf Staging mit Testmail prüfen"}
- {id: T-159, ap: AP-5, was: "Konten der Teilnehmenden im Team-Bereich", wie: "Konto suchen, mit Anmeldung verknüpfen, Reset auslösen, sperren → Login verweigert", status: "lokal bestanden (Personen: Filter „Mit Konto“, Passwort-Link, sperren, löschen mit Wahl, Rolle vergeben mit Code und Einrichtungslink)"}

# AP-6 Mailversand und Rundmails
- {id: T-160, ap: AP-6, was: "SMTP-Versand über Lima-City", wie: "Testmail an eigene Adresse; Verbindung innerhalb 5 s", status: "offen: Testmail und Mailing auf Staging (Mailversand an sich geht, Admin 2026-10-29)"}
- {id: T-161, ap: AP-6, was: "Prioritäten", wie: "Rundmail an 100 Adressen einreihen, danach Anmeldung → Bestätigung geht vor der Rundmail raus", status: "lokal bestanden (Rundmail über dem Limit wartet, zwei Bestätigungen gehen sofort raus)"}
- {id: T-162, ap: AP-6, was: "Tageslimit", wie: "Limit testweise auf 5 setzen, 8 Mails einreihen → 5 heute, 3 morgen", status: "lokal bestanden (Limit 5: Mailing über anmeldung@ 4 heute, Rest morgen; newsletter@ Limit 3 eigenes Kontingent)"}
- {id: T-163, ap: AP-6, was: "Wiederholung bei Fehler", wie: "SMTP-Passwort kurz falsch → Mail bleibt in der Warteschlange, Versand nach Korrektur", status: "lokal bestanden seit AP-4 (Wiederholungen 5/30/120/360 min); auf Staging nicht nötig"}
- {id: T-164, ap: AP-6, was: "Inhalt nach Versand geleert", wie: "DB-Zeile nach Versand ohne Empfänger und Text", status: "lokal bestanden (Zeilen ohne Empfänger und Text; Mailings stehen nur als Verweis in der Warteschlange)"}
- {id: T-165, ap: AP-6, was: "Zustellbarkeit", wie: "Testmails an Gmail, GMX, Web.de, Outlook; SPF/DKIM/DMARC pass in den Kopfzeilen; Spamscore (mail-tester.com)", status: "offen: der Admin auf Staging (Gmail, GMX/Web.de, Outlook; mail-tester.com); SPF/DKIM beim Hoster"}
- {id: T-166, ap: AP-6, was: "Rundmail-Filter und Testversand", wie: "Filter je Workshop-Termin und Berufsgruppe zählt richtig; Testversand nur an sich selbst", status: "lokal bestanden (Auswahl Termin, Berufsgruppe, ohne Essen zählt richtig; Testmail nur an die eigene Adresse)"}
- {id: T-167, ap: AP-6, was: "Editor, Bilder, Vorschau", wie: "Bild hochladen (verkleinert, eingebettet per Content-ID), Knopf und Platzhalter einfügen, HTML bereinigt (keine Skripte, fremden Bilder, javascript:), Vorschau ohne Skripte", status: "lokal bestanden (Bibliothek, HTTP, Browser in drei Engines)"}
- {id: T-168, ap: AP-6, was: "Planen, Abbrechen, Archiv", wie: "geplant → Job startet zum Zeitpunkt; Planung aufheben; Rest abbrechen; Empfängerliste im Archiv, nach 14 Monaten gelöscht; kopieren", status: "lokal bestanden"}
- {id: T-169, ap: AP-6, was: "Team-Mails", wie: "Einladung beim Anlegen per Mail (oder Link anzeigen); Passwort vergessen für Team-Konten mit Link über /einrichten", status: "lokal bestanden"}

# Rückmeldungen 2026-10-30 (E-51)
- {id: T-225, ap: R-30.10., was: "Navigation und Einstieg", wie: "Gast: grüner Knopf „Anmelden“; erkannt (Link, Konto, Team): „Meine Anmeldung“; vor dem Anmeldestart Hinweis statt „Link per Mail“; Team eingeloggt auf öffentlichen Seiten nicht ausgeloggt; kein Team-Link in der Fußzeile", status: "lokal bestanden"}
- {id: T-226, ap: R-30.10., was: "Programm: Block-Zuordnung und Ziehen", wie: "Workshop/Station per Feld oder Ziehen auf die Blockmitte zuordnen, Hinweis bei Uhrzeit außerhalb, lösen, verwerfen; weiße Einfügemarke, „ans Ende“; ein Label „nicht veröffentlicht“", status: "lokal bestanden (Bibliothek, HTTP, Browser mit simuliertem Ziehen); Ziehen mit der Maus auf Staging prüfen"}
- {id: T-227, ap: R-30.10., was: "Texte: CommonMark-Teilmenge, Werkzeugleiste, Bilder", wie: "Überschriften, Listen, fett/kursiv mit * und _, Backslash, Trennlinie, nur eigene Bilder; Werkzeugleiste schreibt Markdown; Bild hochladen erscheint auf der Startseite", status: "lokal bestanden"}
- {id: T-228, ap: R-30.10., was: "Zweiter Faktor per E-Mail-Code", wie: "erstes Einloggen: Wahl App/E-Mail; Code per Mail einrichten; später kommt der Code automatisch; einmal gültig, 10 min, nur Hash; Wiederherstellungscode geht; Abschalten nur mit App; Mein Konto zeigt den Abschnitt", status: "lokal bestanden; Zustellung auf Staging prüfen"}
- {id: T-229, ap: R-30.10., was: "Protokoll: Rechte, Ausblenden, Filter", wie: "Admin sieht keine Sicherungs-/Konto-Einträge anderer und keine ausgeblendeten; Betreiber blendet aus und ein (selbst protokolliert); Filter-Knöpfe mit Anzahl", status: "lokal bestanden"}
- {id: T-230, ap: R-30.10., was: "Einstellungen gesperrt", wie: "Felder zuerst gesperrt, „Entsperren“ zeigt sie, nach dem Speichern wieder gesperrt", status: "lokal bestanden"}
- {id: T-231, ap: R-30.10., was: "Einheitlicher Bestätigungsdialog (Entwurf C)", wie: "jede Nachfrage als Dialog: grün/rot/lila, mittig auf dem Desktop, auf dem Handy von unten, Escape und Abbrechen schließen, Absenden wie vorher; ohne JavaScript Aufklappen", status: "lokal bestanden (Browser: Verwerfen, Senden, Testdaten laden; alle Auslöser öffnen und schließen)"}
- {id: T-232, ap: R-30.10., was: "Kleinigkeiten", wie: "Kicker über Titel mit Umlaut ohne Überlappung; Rolle neben Name mittig; Statistik ohne Kachel „EFN angegeben“; Programmpunkt löschen neben Speichern", status: "lokal bestanden (Bildschirmfotos angesehen)"}

# Rückmeldungen 2026-10-30 (2) (E-53)
- {id: T-233, ap: R-30.10.-2, was: "Programmpunkt löschen", wie: "Dialog mittig (Desktop) bzw. von unten (Handy); „Ja, löschen“ löscht", status: "lokal bestanden (Browser in allen drei Engines)"}
- {id: T-234, ap: R-30.10.-2, was: "Ziehen mit Maus und Finger", wie: "Linie mittig zwischen zwei Einträgen, unter einem Block unter dem ganzen Block; Ziehen mit dem Finger auf Handy/Tablet; kein Feld „ans Ende“", status: "lokal bestanden (Maus in allen Engines; Finger mit Zeiger-Ereignissen vom Typ touch); auf echtem Handy auf Staging prüfen"}
- {id: T-235, ap: R-30.10.-2, was: "Termine entfernen", wie: "neue Zeile: „Entfernen“ nimmt sie sofort weg; gespeicherte: „entfernen“ anhaken und speichern (nicht bei vergebenen Plätzen)", status: "lokal bestanden"}
- {id: T-236, ap: R-30.10.-2, was: "Dozierende je Termin", wie: "nichts gewählt = wie Workshop; abweichend gewählt wird gespeichert, veröffentlicht, beim Verwerfen wiederhergestellt; Startseite „mit …“", status: "lokal bestanden"}
- {id: T-237, ap: R-30.10.-2, was: "Vorträge und Fallbesprechungen in Blöcken", wie: "Zuordnen per Feld oder Ziehen; Zeitraster und Handy-Liste zeigen sie; im Block keine Verschiebung der Hauptspur; Block nimmt sie beim Verschieben mit", status: "lokal bestanden"}

# Editor, ein Konto, Navigation, Löschfrist (E-54)
- {id: T-238, ap: E-54, was: "Ein Editor überall", wie: "Texte, Programm-Beschreibung, Kurzbio, Listen-Beschreibung und Mailings mit demselben Editor; fett/kursiv schalten an und aus; nummerierte Liste; gespeichert wird bereinigtes HTML (Skripte, fremde Bilder, http-Links entfernt)", status: "lokal bestanden"}
- {id: T-239, ap: E-54, was: "Ein Konto für alles", wie: "Team-Login gilt auch für Meine Anmeldung und Mein Konto; Team-Mitglied meldet sich mit eigenem Konto an (gleich bestätigt); Ausloggen beendet alles; Rolle angehoben → nächster Login richtet zweiten Faktor ein", status: "lokal bestanden"}
- {id: T-240, ap: E-54, was: "Personen-Menü", wie: "ausgeloggt „Einloggen“; eingeloggt Name, Meine Anmeldung, Mein Konto, Team-Bereich (nur Team), Ausloggen; auf dem Handy im Menü; eingeloggt kein Login-Feld auf Meine Anmeldung", status: "lokal bestanden"}
- {id: T-241, ap: E-54, was: "Team-Navigation Variante A", wie: "sechs Bereiche, Unterseiten angehängt; Team-Konten und Betrieb nur für Betreiber; alte Adresse /team/profil führt zu /konto", status: "lokal bestanden (Bildschirmfotos angesehen)"}
- {id: T-242, ap: E-54, was: "Löschfrist für Konten", wie: "14 Monate ohne Login: Erinnerung 30 Tage vorher (einmal), Löschung frühestens 30 Tage nach der Erinnerung, Login setzt zurück, Team-Konten ausgenommen, Anmeldung bleibt", status: "lokal bestanden (mit vorgestellter Uhr)"}

# Passkeys (E-55)
- {id: T-243, ap: E-55, was: "Passkey anlegen", wie: "Mein Konto: Name, Passwort, „Passkey hinzufügen“ → Gerät fragt Fingerabdruck/PIN → Passkey in der Liste; ohne UV, mit fremdem Ursprung, fremder rpId, zusätzlichem COSE-Feld oder Punkt außerhalb der Kurve abgelehnt", status: "lokal bestanden (Software-Authenticator; Chromium mit simuliertem Gerät)"}
- {id: T-244, ap: E-55, was: "Einloggen mit Passkey", wie: "Login-Seite „Mit Passkey einloggen“ → ohne Passwort eingeloggt; Team direkt im Team-Bereich (ersetzt Passwort und zweiten Faktor); zurückgelaufener Zähler, veränderte Signatur, fremder Ursprung abgelehnt", status: "lokal bestanden; mit echtem Handy/Laptop auf Staging prüfen"}
- {id: T-245, ap: E-55, was: "Passkey als einziger zweiter Faktor (Team)", wie: "erster Team-Login: Passkey als erste Wahl → Wiederherstellungscodes; letzter zweiter Faktor nicht entfernbar; Zurücksetzen durch Betreiber entfernt die Passkeys", status: "lokal bestanden"}
- {id: T-246, ap: E-55, was: "Passkeys verwalten", wie: "umbenennen, entfernen (Dialog), Anzeige „zuletzt benutzt“; Konto gelöscht → Passkeys weg", status: "lokal bestanden"}

# AP-7 Newsletter
- {id: T-170, ap: AP-7, was: "Newsletter Double-Opt-In", wie: "Ohne Bestätigung kein Eintrag in der Liste; mit Bestätigung Einwilligung mit Zeit und Fassung gespeichert", status: "lokal bestanden (unbestätigt kein Versand; Bestätigung mit Zeitpunkt und Fassung, Wortlaut gespeichert; Link gilt einmal)"}
- {id: T-171, ap: AP-7, was: "Abmeldung", wie: "Abmeldelink und List-Unsubscribe → sofort ausgetragen, ohne Login", status: "lokal bestanden (Abmeldelink und List-Unsubscribe mit One-Click per POST ohne Login, Person sofort gelöscht); in Gmail/Outlook auf Staging prüfen"}
- {id: T-172, ap: AP-7, was: "Mehrere Listen mit eigenem Absender", wie: "Eintrag in eine Liste, Versand an die andere → keine Mail; Absender je Liste stimmt", status: "lokal bestanden (Abgemeldete bekommen nichts; Absendername der Liste im From)"}
- {id: T-173, ap: AP-7, was: "Verzahnung mit Symposium-Anmeldung", wie: "Häkchen gesetzt → nach Bestätigung in Liste; nicht gesetzt → nicht in Liste", status: "lokal bestanden (Häkchen: nach Bestätigung der Anmeldung eigene Bestätigungsmail, vorher nichts)"}
- {id: T-174, ap: AP-7, was: "Versand über Tage", wie: "Liste größer als Tageslimit → Rest am Folgetag, Protokoll zeigt Fortschritt", status: "lokal bestanden (Mailing an Listen über die Warteschlange mit Tageslimit wie in AP-6)"}

# AP-8 Kaneo-Instanz: entfallen (E-15), T-180 bis T-184 gestrichen

# AP-10 Teilnahme und Zertifikate
- {id: T-190, ap: AP-10, was: "Anwesenheit erfassen", wie: "Liste auf dem Handy: suchen, Haken setzen, Massenaktion mit Ausnahmen; Audit-Eintrag", status: offen}
- {id: T-191, ap: AP-10, was: "Kein Zertifikat ohne Anwesenheit", wie: "Einmal-Link einer nicht anwesenden Person → kein Download; nach Erfassung → Download", status: offen}
- {id: T-192, ap: AP-10, was: "PDF-Inhalt und Darstellung", wie: "Entwurf des Admins unverändert, Name mit Umlauten und Datum an der richtigen Stelle; öffnet in Acrobat, Browser, Handy", status: offen}
- {id: T-193, ap: AP-10, was: "Freischaltung und Mail", wie: "Freischalten → Mail „Zertifikat verfügbar“ mit Einmal-Link; vor Freischaltung kein Download", status: offen}
- {id: T-194, ap: AP-10, was: "CME-Export", wie: "Export enthält nur anwesende Personen mit Name und EFN", status: offen}
- {id: T-195, ap: AP-10, was: "Zertifikat nicht fälschbar über fremden Link", wie: "Einmal-Link einer anderen Person liefert nur deren Zertifikat; manipulierte IDs → 404", status: offen}

# AP-9 Abschluss und Datenschutz
- {id: T-200, ap: AP-9, was: "Automatische Anonymisierung", wie: "Anmeldung ohne Konto, Symposium länger als 14 Monate her → vom Job anonymisiert (E-24); Konto bleibt; CME-Daten ohne Konto bleiben 10 Jahre", status: offen}
- {id: T-201, ap: AP-9, was: "Backup und Wiederherstellung", wie: "Team-Bereich → Daten: Sicherung anlegen, Daten ändern, wiederherstellen → alter Stand zurück, Stand davor gesichert; unvollständige oder neuere Sicherung abgelehnt; Herunterladen nur mit Code (E-47)", status: "lokal bestanden; auf Staging offen"}
- {id: T-223, ap: AP-9, was: "Testdaten laden (E-47)", wie: "nur Staging/Entwicklung; ersetzt Programm, Anmeldungen, Einstellungen durch den Beispieldatensatz, Team-Konten bleiben, vorher Sicherung", status: "lokal bestanden; auf Staging offen"}
- {id: T-224, ap: AP-9, was: "Mail-Umleitung auf Staging (E-47)", wie: "mail.umleiten_an gesetzt → Mail an diese Adresse, Empfänger im Betreff", status: "lokal bestanden; auf Staging offen"}
- {id: T-202, ap: AP-9, was: "Gesamttest", wie: "Ablauf Anmeldung → Konto anlegen → Änderung → Stichtag → Storno → Nachrück-Angebot → Annahme → Rundmail → Anwesenheit → Zertifikat mit zwei Testpersonen (eine mit, eine ohne Konto)", status: offen}
- {id: T-203, ap: AP-9, was: "Checkliste Anmeldestart", wie: "Konzept Abschnitt 9 vollständig abgehakt", status: offen}
- {id: T-204, ap: AP-9, was: "Repo frei von Interna (R-10)", wie: "Suche im Repo nach Kontonamen, IPs, Pfaden, Postfachnamen vor dem Umschalten auf öffentlich", status: offen}
```

## 3. Ergebnisse

Automatisch: `php tools/tests/lauf.php` (lokal und im Workflow „Prüfung“ bei jedem PR gegen MySQL 8.4). Die lokalen Ergebnisse unten stammen aus einer Arbeitsumgebung mit PHP 8.3 und MySQL 8.0 über den eingebauten PHP-Server (HTTP, kein HTTPS); sie ersetzen die Prüfung auf Staging nicht.

| T-Nr. | Datum | Prüfer | Ergebnis | Befehl / entscheidende Ausgabe | Auffälligkeiten |
|---|---|---|---|---|---|
| T-100 | 2026-10-29 | claude-dev | lokal bestanden | `lauf.php` → „Installation abgeschlossen“, „Installer danach gesperrt (404)“, „zweite Installation per POST unmöglich“; falscher Schlüssel legt kein Konto an | – |
| T-101 | 2026-10-29 | claude-dev | lokal bestanden (Teil) | CSP mit Nonce (je Anfrage neu), nosniff, Referrer-Policy, X-Frame-Options, kein X-Powered-By | HSTS und HTTPS-Umleitung nur über HTTPS prüfbar → Staging |
| T-102 | 2026-10-29 | claude-dev | lokal bestanden | POST /install ohne Token → 403, mit falschem Token → 403 | – |
| T-103 | 2026-10-29 | claude-dev | lokal bestanden (Teil) | Ablage unter var/sitzungen; Aufräumen löscht nur Dateien älter als 40 min | Ablauf nach 30 min Leerlauf auf Staging prüfen |
| T-104 | 2026-10-29 | claude-dev | lokal bestanden (Bibliothek) | 9 Fehlversuche erlaubt, 10. sperrt, nach Sperrdauer frei; Merkmal ist Hash, keine IP | über HTTP auf Staging prüfen |
| T-105 | 2026-10-29 | claude-dev | lokal bestanden | /jobs?token=… → status ok, Auslöser url; cli/jobs.php → Exitcode 0, Auslöser cli; Mindestabstand je Job eingehalten, Zeitstempel bei jedem Aufruf | Lima-City-Cron offen |
| T-107 | 2026-10-29 | claude-dev | lokal bestanden | ohne/falsches Token 404; mit Token 200, JSON, Schema ist = soll | – |
| T-108 | 2026-10-29 | claude-dev | lokal bestanden (ohne Login) | wartung.lock → 503 mit Retry-After; /health meldet wartung: true; /team nicht 503; Jobs pausieren | Team-Login erst ab AP-5 |
| T-106 | 2026-10-29 | claude-dev | nicht bestanden (erster Lauf) | Lauf „Auslieferung“ nach Merge von 0.1.0: Prüfung grün (MySQL 8.4, 88 ok), Abgleich abgebrochen mit curl 9 vor jeder Übertragung | Zielpfad falsch (FTP-Login landet schon in wintersymposium); in 0.1.1 auf /staging/ und /produktiv/ korrigiert, erneut prüfen |
| T-110 | 2026-10-29 | claude-dev | lokal bestanden | fremder Host → 421; weiterer Host aus dem Installer → 200; Groß-/Kleinschreibung und Port egal | Basis-URL in Mails erst mit AP-6 prüfbar |
| T-106 | 2026-10-29 | admin | Staging bestanden | Lauf „Auslieferung“ nach Merge von 0.1.1 grün; im Ordner staging liegen public/, lib/, seiten/, cli/, bootstrap.php, config.example.php, VERSION, Zustandsdatei | – |
| T-100 | 2026-10-29 | admin | bestanden | Installation über /install abgeschlossen; var/install.lock vorhanden; /install danach „Nicht gefunden“ (404, auch per curl bestätigt) | – |
| T-101 | 2026-10-29 | claude-dev | bestanden | `curl -sI https://test.winter-notfallsymposium.de/` → CSP mit Nonce, Strict-Transport-Security max-age=31536000, nosniff, Referrer-Policy, X-Frame-Options DENY, COOP/CORP, Permissions-Policy, kein X-Powered-By; http → https im Browser (admin) | Hoster setzt eigenes Cookie `_lcp` → in der Datenschutzerklärung (AP-2) erwähnen |
| T-107 | 2026-10-29 | admin, claude-dev | bestanden | ohne Token 404 (curl); mit Token JSON status ok, version 0.1.1, umgebung staging, schema ist 1 = soll 1 | – |
| T-105 | 2026-10-29 | admin | bestanden | Lima-City-Cron per URL alle 5 min; /health zeigt jobs.letzter_lauf aktuell, letzter_ausloeser url, mit_fehler leer | – |
| T-108 | 2026-10-29 | admin | bestanden (ohne Login) | var/wartung.lock angelegt → Startseite „Wartung“; gelöscht → wieder Startseite | Login-Teil nach AP-5 |
| T-110 | 2026-10-29 | claude-dev | bestanden | `curl -H 'Host: fremd.example' https://test.winter-notfallsymposium.de/` → 404 vom Hoster (Anfrage erreicht die App nicht); erlaubter Host 200 | – |
| T-109 | 2026-10-29 | claude-dev | teilweise | `curl -X POST https://test.winter-notfallsymposium.de/` → 200 von der App, kein 403 vom Hoster | Formular-POST im Browser mit AP-4 |
| – | 2026-10-29 | claude-dev | bestanden | interne Dateien: /config.php und /.htaccess → 403, /lib/db_lib.php und /index.php direkt → 404, /assets/basis.css → 200 | – |
| T-111 | 2026-10-29 | admin | bestanden | Mockups (Variante A) nach Kommentarrunde freigegeben: „Mockups sind freigegeben“; abgelegt in docs/mockups/ | Varianten B/C verworfen (E-36) |
| T-112 | 2026-10-29 | claude-dev | lokal bestanden | Startseite im Browser (Playwright) bei 390 px und 1280 px: kein horizontales Scrollen (scrollWidth = Breite), Menü auf dem Handy aufklappbar | 360 und 768 px auf Staging nachprüfen |
| T-113 | 2026-10-29 | claude-dev | bestanden | WCAG-Kontraste berechnet: Weiß/Nachtblau 16,8; Lila/Nachtblau 6,5; Lila/Fläche 5,6; Meta/Fläche 6,5; Nachtblau auf Lila 6,5; Nachtblau auf Grün 8,2; Rot/Nachtblau 5,5 – alle ≥ 4,5 | – |
| T-114 | 2026-10-29 | claude-dev | lokal bestanden | `lauf.php`: keine src/href auf fremde Hosts außer Quelltext-Link nach GitHub, CSS ohne externe Adressen | im Browser-Netzwerkreiter auf Staging bestätigen |
| T-115 | 2026-10-29 | claude-dev | teilweise | /impressum und /datenschutz 200, in der Fußzeile verlinkt; leer → Hinweis; gepflegter Text (Überschrift, mailto-Link) wird angezeigt | Bearbeiten durch Betreiber erst mit AP-5 |
| T-116 | 2026-10-29 | claude-dev | lokal bestanden | Anton, Antonio, Tajawal, IBM Plex Mono als WOFF2 von /assets/fonts (200), Logo als SVG (scharf in jeder Auflösung) | – |
| T-112, T-116 | 2026-10-29 | admin | bestanden | Staging nach Auslieferung von 0.2.0: Seite wird korrekt angezeigt | – |
| T-127 bis T-129, T-216 bis T-220 | 2026-10-29 | claude-dev | lokal bestanden | `lauf.php` 194 ok, 0 Fehler; Browser-Durchlauf (R-12) 750 ok, 0 Fehler in Chromium 153, Firefox 155, WebKit 26.6 × 4 Breiten; Gegenproben: absichtlich zu breites Element → 27 Fehler, Konten-Tabelle ohne Kartenansicht → Fehler auf Handy und Tablet in allen Engines | Staging-Prüfung nach dem Merge; echtes iPhone als Stichprobe |
| T-150, T-151, T-153 | 2026-10-29 | claude-dev | lokal bestanden | RFC-6238-Prüfwerte; Einrichtung per QR; derselbe Code kein zweites Mal; Wiederherstellungscode genau einmal | Passkey (T-152) mit AP-5 |
| T-243 bis T-246 | 2026-10-30 | claude-dev | lokal bestanden | `lauf.php` 668 ok, 0 Fehler (Bibliothek mit Software-Authenticator: ES256 und RS256 anlegen und einloggen, Herausforderung einmalig und je Konto, ohne UV, fremder Ursprung, fremde rpId, veränderte Signatur, Zähler zurück, COSE mit privatem Teil, Punkt außerhalb der Kurve abgelehnt, keine IP-Adresse; Team: Passkey als zweiter Faktor, letzter nicht entfernbar, Zurücksetzen entfernt Passkeys; HTTP: Login-Seite, Mein Konto, Anlegen nur mit Passwort, ohne CSRF abgelehnt, Anlegen und Einloggen über den Endpunkt, erster Team-Login mit Passkey und Codes, Team-Login nur mit Passkey); Browser-Durchlauf 5289 ok, 0 Fehler (Chromium: simuliertes Gerät mit Fingerabdruck – anlegen, ausloggen, mit Passkey einloggen; Firefox und WebKit: Knopf sichtbar; Seiten in allen Breiten) | Staging |
| T-238 bis T-242 | 2026-10-30 | claude-dev | lokal bestanden | `lauf.php` 629 ok, 0 Fehler (Bereiniger: fett/kursiv, Skripte, Attribute, Links, Listen, Klartext, Inline, Bilder, Abschnitte; Löschfrist mit vorgestellter Uhr; HTTP: Team-Navigation, Personen-Menü, Mein Konto für Team, eingeloggt ohne Login-Feld, Team-Mitglied meldet sich an, Ausloggen beendet alles, Rolle angehoben → zweiter Faktor, Programm-Beschreibung bereinigt); Browser-Durchlauf 5268 ok, 0 Fehler (Editor: Fett an und aus, nummerierte Liste, Titel mit Editor; Mailing-Editor; alle Bereiche und Unterseiten in allen Breiten; Personen-Menü; Mein Konto und Zwei-Faktor für Team) | Staging |
| T-233 bis T-237 | 2026-10-30 | claude-dev | lokal bestanden | `lauf.php` 609 ok, 0 Fehler (Fallbesprechung im Block, Vortrag ohne Zuordnung bleibt draußen, Punkt im Block schiebt die Hauptspur nicht, Block nimmt Zugeordnete mit, Verwerfen; Dozierende je Termin: laden, prüfen über Zeilen-Schlüssel, speichern, veröffentlichen, verwerfen); Browser-Durchlauf 4542 ok, 0 Fehler (Löschen-Dialog mittig und wirksam, Einfügelinie, Ziehen mit der Maus in allen Engines, Finger-Ziehen auf dem Handy, Termin-Zeile entfernen, abweichende Dozierende im Editor und im Popup, Fallbesprechung im Zeitraster und in der Handy-Liste) | Staging |
| T-170 bis T-174 | 2026-10-30 | claude-dev | lokal bestanden | `lauf.php` 599 ok, 0 Fehler (Bibliothek: Listen, Anfrage, Bestätigen, Versand an zwei Listen mit List-Unsubscribe, Abmelden, Verwalten, Aufräumen, Häkchen bei der Anmeldung, CSV; HTTP: /newsletter mit Spamschutz, Bestätigen nur per Knopf, Verwalten, One-Click-Abmelden, Team-Reiter, Einladen, Löschen, Export, Empfängerart im Editor); Browser-Durchlauf 4371 ok, 0 Fehler (Newsletter-Seiten in allen Breiten, Reiter Newsletter, Empfängerart im Editor) | Staging |
| T-225 bis T-232 | 2026-10-30 | claude-dev | lokal bestanden | `lauf.php` 562 ok, 0 Fehler (CommonMark-Teilmenge, Block-Zuordnung, E-Mail-Code, Protokoll-Sichtbarkeit; HTTP: Navigation mit Team-Login, Einstieg vor dem Start, Sperre, Text-Bild, Login mit Mail-Code, Mein Konto, Protokoll als Admin/Betreiber, Programm-Löschknopf); Browser-Durchlauf 4089 ok, 0 Fehler (Dialog mittig und von unten, Station auf Block ziehen, Werkzeugleiste Texte, Einstellungen entsperren) | Staging |
| T-161 bis T-164, T-166 bis T-169 | 2026-10-30 | claude-dev | lokal bestanden | `lauf.php` 515 ok, 0 Fehler (Mailings: bereinigen, Auswahl, Limits je Postfach, Vorrang der Bestätigungen, Inhalt beim Versand, Storno dazwischen, Planen, Job, Abbrechen, Archiv-Löschfrist, Bilder; HTTP: Seiten, Hochladen, Vorschau-Richtlinie, Empfängerzahl, Testmail, Senden, Archiv; Team-Einladung und Passwort vergessen); Browser-Durchlauf 4227 ok, 0 Fehler (Mailings-Reiter, Entwurf, Archiv in allen Breiten; Editor: Platzhalter, Knopf, Bild hochladen, HTML-Ansicht, Empfängerzahl, Speichern, Vorschau) | Staging |
| T-144, T-155 bis T-159 | 2026-10-29 | claude-dev | lokal bestanden | `lauf.php` 455 ok, 0 Fehler (Team-Bibliothek: anlegen, überbuchen, Tausch, Angebot, Entfernen mit Nachrücken, Daten, Liste, Aufgaben, CSV, Statistik, Rolle, Hosts, Absender; HTTP: alle Seiten, Person anlegen/ändern ohne und mit Mail, nach dem Stichtag, Einmal-Link, Export, Texte, Protokoll, Storno); Browser-Durchlauf 3765 ok, 0 Fehler mit allen neuen Reitern, ausgewählter Person, Termin, Person anlegen | Staging |
| – | 2026-10-29 | admin | bestanden | Mailversand auf Staging: Mails kommen an | – |
| T-212, T-201 | 2026-10-29 | claude-dev | lokal bestanden | Nachbesserung: Ziehen fügt zwischen zwei Punkte ein, nachfolgende rücken nach (Block mit Inhalt, Lücke stoppt; Bibliothekstest und Browser-Durchlauf „2 Punkte rücken“); Testmail unter Daten; Versionsanzeige; `lauf.php` 406 ok, 0 Fehler; Browser-Durchlauf 3009 ok, 0 Fehler | Staging: Ziehen mit der Maus, Testmail |
| T-145 bis T-149 | 2026-10-29 | claude-dev | lokal bestanden | `lauf.php` 398 ok, 0 Fehler (Konten ohne und mit Anmeldung, Bestätigung, Login, Verknüpfung, neue Adresse, Löschen mit Wahl, Aufräumen; HTTP: alle Wege inkl. Team im Kasten); Browser-Durchlauf 3009 ok, 0 Fehler (Konto anlegen, Passwort vergessen, Login im Kasten, Mein Konto, Profil) in 3 Engines × 4 Breiten | Staging |
| T-201, T-223, T-224 | 2026-10-29 | claude-dev | lokal bestanden | `lauf.php` 362 ok, 0 Fehler (Sicherung und Wiederherstellung, kaputte und neuere Sicherung, Mail-Umleitung, HTTP: Herunterladen mit Code, Testdaten laden und zurück); Browser-Durchlauf 2550 ok, 0 Fehler mit Seite „Daten“ in 3 Engines × 4 Breiten | Staging |
| T-130 bis T-143 | 2026-10-29 | claude-dev | lokal bestanden | `lauf.php` 342 ok, 0 Fehler (Regeln, Bestätigung, Links, Tausch, Nachrücken, Fristen, Paralleltest, HTTP-Ablauf); Browser-Durchlauf 2376 ok, 0 Fehler in Chromium, Firefox, WebKit × 4 Breiten (Formular mit Sicherheitsprüfung, Mail, Bestätigen, Deine Anmeldung, Workshops und Daten ändern, Angebot mit Tausch-Bestätigung, Ausloggen, Team-Liste); Bildschirmfotos angesehen | Staging mit echtem Mailversand |
| T-120, T-121, T-210 bis T-215 | 2026-10-29 | claude-dev | lokal bestanden | `lauf.php` 271 ok, 0 Fehler; Browser-Durchlauf 1563 ok, 0 Fehler in Chromium, Firefox, WebKit × 4 Breiten (Programm-Pflege, Dozierende, Vorschau, Startseite mit Popups, Zeitraster und Liste); Bildschirmfotos angesehen, dabei Termintabelle, Kästchen und Skala korrigiert; Datums- und Zeitfelder in Chromium und Firefox nach Korrektur der Browser-Umgebung im deutschen Format (19:30, 20.01.2027) | Staging nach dem Merge |
| T-121, T-222 | 2026-10-29 | claude-dev | lokal bestanden | `lauf.php` 221 ok, 0 Fehler; Browser-Durchlauf 978 ok, 0 Fehler (Einstellungen: Fehler markiert, Speichern, Auswahl, neuer Ort auf der Startseite, in Chromium, Firefox, WebKit) | Staging nach dem Merge |

### Noch zu prüfen für AP-1

1. T-106 Produktion: nach Merge Tag `v` + Inhalt von `VERSION` setzen (docs/betrieb.md Abschnitt 3) → Lauf nach `produktiv` grün; danach Einrichtung wie auf Staging (Datenbank, config.php mit eigenen Werten, Installer, Cron). Einmal mit falschem Tag prüfen, dass der Lauf vor der Übertragung abbricht (Tag danach löschen).
2. T-103: Ablauf der Sitzung nach 30 min Leerlauf – mit dem Team-Login aus AP-5 prüfen.
3. T-108: Team-Login während der Wartung – mit AP-5.
4. T-102 und T-109: Formular-POST im Browser – mit dem Anmeldeformular aus AP-4.
5. T-104 über HTTP (optional): elfmal `/health?token=falsch`, dann mit richtigem Token → 404; nach 10 min wieder 200.

### Noch zu prüfen für AP-2

1. T-115: Impressum und Datenschutz durch den Betreiber bearbeiten, sobald AP-5 umgesetzt ist.

### Noch zu prüfen für AP-3 Schritt 1 (nach Merge auf Staging)

1. `https://test.winter-notfallsymposium.de/einloggen` (oder Fußzeile → „Team“) mit dem Betreiber-Konto aus der Installation: Passwort eingeben → Seite zum Einrichten des zweiten Faktors mit QR-Code.
2. QR-Code mit der Authenticator-App scannen, Code eingeben → zehn Wiederherstellungscodes erscheinen. Im Passwortmanager speichern.
3. Team-Bereich öffnet sich; oben rechts stehen Name, Rolle und „Ausloggen“.
4. Ausloggen, wieder einloggen: Nach dem Passwort wird nur noch der Code gefragt. Den Code von eben gleich noch einmal eingeben → abgelehnt; den nächsten Code → drin.
5. „Team-Konten“ → zweites Konto (z. B. zweiter Betreiber) anlegen → Link kopieren, in einem privaten Fenster öffnen, Passwort setzen, zweiten Faktor einrichten.
6. Mit dem zweiten Konto „Team-Konten“ öffnen: als Admin → „Kein Zugriff“; als Betreiber → sichtbar.
7. Einmal mit einem Wiederherstellungscode einloggen → Hinweis „Übrig: 9“.
8. Notzugang (nur zum Ausprobieren, setzt den eigenen zweiten Faktor zurück): `https://test.winter-notfallsymposium.de/notzugang` → Installationsschlüssel aus der config.php und eigene Adresse → Link erscheint → Passwort neu setzen, zweiten Faktor neu einrichten. Danach den Installationsschlüssel in der config.php tauschen.

### Noch zu prüfen für 0.3.1

1. T-221: In Firefox mit Bitwarden einloggen: Nach dem Passwort erscheint am Feld „Code“ das Bitwarden-Menü, und der Code lässt sich einfügen (Eintrag mit TOTP-Schlüssel in Bitwarden vorausgesetzt).

### Noch zu prüfen für AP-3 Schritt 2 (nach Merge auf Staging)

1. Team-Bereich → „Einstellungen“: Seite ansehen (es gibt kein Mockup dafür) und sagen, ob Aufbau und Texte passen.
2. Eine Kleinigkeit ändern (z. B. Kapazität), speichern → Startseite zeigt den neuen Wert.
3. Datums- und Zeitfelder in deinem Browser: Format TT.MM.JJJJ und 24-Stunden-Uhrzeit (in der Sandbox seit 0.5.0 in Chromium, Firefox und WebKit mit deutschem Format geprüft; WebKit unter Linux zusätzlich mit Sekunden, siehe betrieb.md 6).

### Noch zu prüfen für Passkeys (nach Merge auf Staging, mit echten Geräten)

Lokal geprüft mit einem Software-Authenticator und Chromium mit simuliertem Gerät. Nicht geprüft: echte Geräte (iPhone/Safari, Android, Windows Hello, Passwortmanager) – das geht nur auf Staging.

1. Einloggen mit Passwort → Mein Konto → Passkeys: Name „Handy“, Passwort, „Passkey hinzufügen“ → das Handy fragt nach Fingerabdruck/Gesicht → Passkey steht in der Liste.
2. Ausloggen → Einloggen → „Mit Passkey einloggen“ → Fingerabdruck → du bist ohne Passwort und ohne Code im Team-Bereich.
3. Dasselbe am Laptop (Windows Hello, Touch ID oder Passwortmanager wie Bitwarden).
4. Einen Passkey umbenennen und entfernen; den letzten zweiten Faktor zu entfernen muss abgelehnt werden, solange keine App und kein E-Mail-Code an sind.
5. Ein neues Team-Konto anlegen und beim ersten Login „Passkey“ wählen → danach erscheinen die Wiederherstellungscodes.

### Noch zu prüfen für Editor, Konto, Navigation, Löschfrist (nach Merge auf Staging)

1. Einloggen: oben rechts dein Kürzel mit Menü (Meine Anmeldung, Mein Konto, Team-Bereich, Ausloggen).
2. Team-Bereich: sechs Bereiche, Unterseiten hängen darunter; jede Seite einmal anklicken.
3. Mein Konto: Kasten „Team-Konto“ → „Zwei-Faktor einstellen“ öffnet die Einstellungen.
4. Meine Anmeldung: kein Login-Feld mehr; „Jetzt anmelden“ (sobald die Anmeldung offen ist) meldet dich mit deinem Konto an.
5. Texte: im Untertitel ein Wort markieren, „B“ → fett, noch einmal „B“ → wieder normal; im Infokasten „1. Liste“ ausprobieren; speichern und Startseite ansehen.
6. Programm und Dozierende: Beschreibung bzw. Kurzbio mit dem Editor schreiben, Vorschau ansehen.
7. Ausloggen über das Menü → überall ausgeloggt.
8. Datenschutzerklärung: Löschfrist für Konten (14 Monate ohne Login, Erinnerung 30 Tage vorher) und Newsletter ergänzen.

### Noch zu prüfen für die Rückmeldungen vom 30.10. (2) (nach Merge auf Staging)

1. Programm: einen Punkt öffnen → „Programmpunkt löschen“ → Dialog in der Mitte, „Ja, löschen“ löscht (danach „Verwerfen“).
2. Auf dem Handy oder Tablet einen Punkt am Griff ⋮⋮ mit dem Finger ziehen: Linie erscheint, Loslassen setzt die Zeit.
3. „Paupanza“ (FB 01) auf die Mitte von „Maus Atemweg“ ziehen → steht im Block; Startseite-Vorschau zeigt ihn im Zeitraster.
4. Workshop öffnen: „+ Termin“, dann bei der neuen Zeile „Entfernen“; bei einem Termin unter „Dozierende“ eine andere Person wählen, speichern → Vorschau zeigt „mit …“ beim Termin.

### Noch zu prüfen für AP-7 (nach Merge auf Staging)

1. Startseite unten „Newsletter bestellen“ → beide Listen wählen, eigene Adresse, Einwilligung → Bestätigungsmail kommt; Link öffnen, „Bestätigen“.
2. Team → Newsletter: du stehst bestätigt in beiden Listen; Zahlen stimmen; CSV öffnen.
3. Mailings → Neues Mailing → Empfänger „Newsletter-Listen“, beide Listen → Testmail an mich und senden: Mail kommt einmal, Absendername der Liste, unten „Abmelden oder Listen ändern“.
4. In Gmail oder Outlook den Knopf „Abbestellen“ neben dem Absender nutzen → im Team-Bereich bist du weg.
5. Anmeldung mit Häkchen „Newsletter“ → nach der Bestätigung der Anmeldung kommt eine eigene Newsletter-Bestätigung.
6. Datenschutzerklärung (Texte) um den Newsletter ergänzen.

### Noch zu prüfen für die Rückmeldungen vom 30.10. (nach Merge auf Staging)

1. Als Gast die Startseite öffnen: grüner Knopf „Anmelden“, kein „Team“ unten. Als Team eingeloggt auf „Meine Anmeldung“ klicken: Name und „Team-Bereich“ bleiben oben.
2. Einstellungen: Seite ist gesperrt; „Entsperren“, etwas ändern, speichern → wieder gesperrt. Anmeldestart testweise in die Zukunft setzen → „Anmelden“ zeigt „Anmeldung noch nicht möglich“; danach zurückstellen.
3. Programm: einen Workshop auf die Mitte eines Workshop-Blocks ziehen (grün umrandet) → zugeordnet; einen Punkt ganz ans Ende ziehen; „Verwerfen“ zeigt den roten Dialog.
4. Texte: mit der Werkzeugleiste fett setzen, ein Bild in „Merke“ einfügen, speichern, Startseite ansehen; danach wieder entfernen.
5. Mein Konto: „Einstellungen ändern“ → „Jedes Mal einen Code per E-Mail schicken“ (Passwort). Ausloggen, einloggen → Code kommt per Mail, damit einloggen.
6. Protokoll: Filter-Knöpfe; einen Eintrag ausblenden; mit einem Admin-Konto prüfen, dass er fehlt.
7. Die Dialoge auf dem Handy ansehen (z. B. Mailing „An … senden“): von unten, Knöpfe untereinander.

### Noch zu prüfen für AP-6 (nach Merge auf Staging)

1. Optional: in der `config.php` auf dem Server im Abschnitt `mail` den Eintrag `newsletter` mit Benutzer und Passwort von newsletter@ ergänzen (Vorlage `config.example.php`). Ohne ihn gehen Mailings über anmeldung@.
2. Betrieb: Kasten „Mailversand“ zeigt beide Postfächer; Absender ansehen.
3. Testdaten laden. Mailings: „Anfahrt und Parken“ (gesendet) öffnen → Empfängerliste, Vorschau. Den Entwurf „WS 01 Atemweg: Raumänderung“ öffnen, Text ändern, Bild hochladen, „Testmail an mich“ → Mail in Gmail/GMX/Outlook ansehen (Bild, Knopf, Umlaute), Spam-Ordner prüfen.
4. Den Entwurf an „Alle Angemeldeten“ senden (Staging leitet alle Mails an die Umleitungsadresse um) → Mails kommen an, mit persönlichem Link.
5. Ein Mailing für in 5 Minuten planen → nach dem Cron-Lauf „Wird gesendet“ / „Gesendet“.
6. Team-Konten: ein Konto mit deiner zweiten Adresse anlegen → Einladung kommt. Beim Einloggen „Passwort vergessen?“ mit einer Team-Adresse → Link kommt (T-169).
7. T-165: Testmail an eine Adresse bei mail-tester.com → Ergebnis notieren.

### Noch zu prüfen für AP-5 (nach Merge auf Staging)

1. Testdaten laden (Betrieb → Testdaten). Übersicht: Kennzahlen, „Zu erledigen“, Workshop-Termine, Essen.
2. Personen: nach „paula“ suchen, auswählen → Angebot sichtbar; „Platz geben“ → Nachfrage zum Tausch → bestätigen. Eine Person mit „+ Person anlegen“ anlegen (deine Adresse) → Mail kommt.
3. Workshops: den vollen Termin WS 02 20:30 öffnen → Teilnehmende und Warteliste; „Platz geben“ fragt nach dem Überbuchen.
4. Statistik: Zahlen plausibel, Filter Berufsgruppe; jede CSV einmal in Excel öffnen (Umlaute, Spalten).
5. Texte: Sticker auf der Startseite ändern; Impressum und Datenschutz eintragen (nur als Betreiber sichtbar).
6. Mit einem Admin-Konto (nicht Betreiber): „Betrieb“ und „Team-Konten“ fehlen, unter „Texte“ fehlen Impressum und Datenschutz (T-154).
7. Betrieb: Absender ansehen, „Testmail an mich“.

### Noch zu prüfen für die Nachbesserung 0.8.1 (nach Merge auf Staging)

1. Fußzeile: steht „Version 0.8.1 · Staging“?
2. Team-Bereich → „Daten“ → Kasten „Mailversand“: Was steht bei „Eingerichtet“ und „Letzter Fehler“? Dann „Testmail an mich“ – die Meldung oben nennt entweder „angenommen“ oder den Fehler des Mailservers.
3. Programm: einen Punkt zwischen zwei andere ziehen → grüne Linie, danach rücken die folgenden Punkte nach.

### Noch zu prüfen für AP-4b (nach Merge auf Staging)

1. Testdaten laden (Team-Bereich → Daten). Auf „Deine Anmeldung“ im Kasten „Einloggen“ mit `lea@example.org` und dem Test-Passwort aus `server/testdaten/beispieldaten.php` einloggen → Leas Anmeldung, in der Navigation „Mein Konto“.
2. Ausloggen. Mit einer eigenen Adresse anmelden und „Konto anlegen“ ankreuzen → Link in der Mail → Passwort festlegen (mindestens 10 Zeichen) → Ausloggen → unter `/einloggen` mit Adresse und Passwort einloggen.
3. „Passwort vergessen?“ ausprobieren: Mail kommt, Link setzt ein neues Passwort.
4. Als Team unter „Deine Anmeldung“ im Kasten einloggen → es geht weiter zum Code aus der App.
5. „Mein Konto“ → „Konto löschen“: einmal mit „Anmeldung behalten“ (Anmeldung bleibt in der Team-Liste, Spalte Konto „nein“).

### Noch zu prüfen für Sicherung und Testdaten (nach Merge auf Staging)

1. In der `config.php` auf Staging unter `mail` den Eintrag `'umleiten_an' => '<deine Adresse>'` ergänzen (nur Staging).
2. Team-Bereich → „Daten“ → „Sicherung anlegen“: Die Sicherung erscheint in der Liste.
3. „Testdaten laden“ → Startseite zeigt das Beispielprogramm, „Anmeldungen“ zeigt Lea, Paula und Tom. Eine Test-Anmeldung über `/anmelden` schicken: Die Mail kommt an deine Adresse, im Betreff steht der eigentliche Empfänger.
4. Die Sicherung „vor dem Laden der Testdaten“ mit deinem Code wiederherstellen → alter Stand ist zurück.
5. Eine Sicherung mit Code herunterladen; die Datei verschlüsselt ablegen oder löschen (personenbezogene Daten).

### Noch zu prüfen für AP-4 (nach Merge auf Staging)

1. Mailversand einrichten: in der `config.php` auf Staging den Abschnitt `mail` aus `config.example.php` übernehmen und Host, Benutzer und Passwort des Postfachs `anmeldung@` eintragen (Werte nur dort). Team-Bereich → Übersicht: Die rote Meldung „Mailversand nicht eingerichtet“ ist weg.
2. Team-Bereich → Einstellungen: „Anmeldung ab“ auf heute setzen (für den Test), „Bestätigungsfrist“ ansehen. Startseite: grüner Knopf „Jetzt anmelden“, in der Navigation „Anmelden“.
3. Mit einer eigenen Adresse anmelden (ÄrztIn mit EFN, ein Workshop). Die Mail kommt an (auch Spam-Ordner prüfen) und der Link bestätigt die Anmeldung. Danach „Deine Anmeldung“: Essen ändern, Workshop wechseln – je eine Mail.
4. Abmelden (Ausloggen), dann „Deine Anmeldung“ → „Link schicken“ mit deiner Adresse → Link kommt; mit einer unbekannten Adresse kommt nichts.
5. Auf dem iPhone (Safari) dasselbe Formular einmal absenden (Sicherheitsprüfung rechnet im Browser).
6. Zum Schluss stornieren und „Anmeldung ab“ wieder auf den echten Start (25.11.2026 00:00) stellen.

### Noch zu prüfen für AP-3 Schritt 3 (nach Merge auf Staging)

1. Team-Bereich → „Programm“: Seite ansehen; leerer Plan mit Hinweis.
2. „Dozierende verwalten“ → eine Person mit Foto anlegen (nur mit Einverständnis, sonst ohne Foto).
3. „+ Programmpunkt“ → einen Vortrag mit Uhrzeit und der Person, einen Workshop-Block (z. B. 19:30–21:30) und einen Workshop mit zwei Terminen in dieser Zeit anlegen („Duplizieren“ ausprobieren). Startseite: noch unverändert, roter Banner im Team-Bereich.
4. Einen Punkt ohne Uhrzeit anlegen und am Griff ⋮⋮ an seine Stelle ziehen (Desktop, Maus) → Uhrzeit wird vorgeschlagen.
5. „Vorschau“ ansehen, dann „Jetzt veröffentlichen“ → Startseite zeigt das Programm. Popups antippen (Handy) und anklicken (Desktop), Zeitraster auf dem Desktop.
6. Auf einem echten iPhone oder iPad (Safari): Popup öffnen und schließen; das Ziehen ist dort nicht vorgesehen (Uhrzeit über die Felder).
7. Zum Schluss die Testeinträge löschen und veröffentlichen, oder stehen lassen, bis das echte Programm kommt.

