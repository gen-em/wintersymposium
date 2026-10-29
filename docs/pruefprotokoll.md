---
dokument: pruefprotokoll
projekt: wintersymposium
repo: gen-em/wintersymposium
stand: 2026-10-29
---

# Prüfprotokoll: Webseite Wintersymposium

Zu jedem Arbeitspaket aus `docs/konzept.md` Abschnitt 6 steht hier, was geprüft wurde und was noch wie zu prüfen ist. Prüfungen tragen eine T-Nummer (T-100 ff.), Ergebnisse werden mit Datum, Prüfer (`admin`, `claude-dev`) und entscheidender Ausgabe (ohne Secrets, ohne Hosting-Interna – R-10) eingetragen.

## 1. Stand

| AP | Titel | Status | Geprüft | Offen |
|---|---|---|---|---|
| AP-1 | Grundgerüst und Auslieferung | auf Staging abgenommen | Staging: T-100, T-101, T-105, T-106 (Staging), T-107, T-108 (ohne Login), T-110; lokal: T-102, T-104 | Produktion per Tag (T-106); T-103 Ablauf, T-108 Login-Teil (mit AP-5); T-102/T-109 im Browser (mit AP-4); T-104 über HTTP (optional) |
| AP-2 | Design-Mockups und öffentliche Seiten | offen | – | T-111 bis T-116 |
| AP-3 | Programm und Veranstaltungs-Einstellungen | offen | – | T-120 bis T-126 |
| AP-4 | Anmeldung, Einmal-Links, Warteliste und Nachrücken | offen | – | T-130 bis T-144 |
| AP-4b | Konten für Teilnehmende | offen | – | T-145 bis T-149 |
| AP-5 | Team-Bereich | offen | – | T-150 bis T-159 |
| AP-6 | Mailversand und Rundmails | offen | – | T-160 bis T-166 |
| AP-7 | Newsletter | offen | – | T-170 bis T-174 |
| AP-8 | Kaneo-Instanz | entfallen (E-15) | – | – |
| AP-10 | Teilnahme und Zertifikate | offen | – | T-190 bis T-195 |
| AP-9 | Abschluss und Datenschutz | offen | – | T-200 bis T-204 |

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
- {id: T-111, ap: AP-2, was: "Mockups vom Admin freigegeben", wie: "Freigabe im Chat/PR mit Datum festhalten", status: offen}
- {id: T-112, ap: AP-2, was: "Startseite auf Handy und Desktop", wie: "Browser-Prüfung 360 px, 768 px, 1280 px; kein horizontales Scrollen", status: offen}
- {id: T-113, ap: AP-2, was: "Kontraste WCAG AA", wie: "Prüfwerkzeug (z. B. axe) auf Startseite und Anmeldeformular; Lila und Signalrot auf Nachtblau und Weiß", status: offen}
- {id: T-114, ap: AP-2, was: "Keine externen Ressourcen", wie: "Netzwerkanalyse im Browser: nur eigene Domain", status: offen}
- {id: T-115, ap: AP-2, was: "Impressum, Datenschutz, Quelltext-Link erreichbar und pflegbar", wie: "Links in Fußzeile; Änderung durch Betreiber sichtbar", status: offen}
- {id: T-116, ap: AP-2, was: "Schriften und Logo korrekt ausgeliefert", wie: "Anton, Antonio, Tajawal, IBM Plex Mono laden lokal (woff2); Logo scharf auf Retina", status: offen}

# AP-3 Programm und Veranstaltungs-Einstellungen
- {id: T-120, ap: AP-3, was: "Zeitfenster mit freien, überlappenden Zeiten anlegbar", wie: "Zwei überlappende Zeitfenster anlegen, Darstellung prüfen", status: offen}
- {id: T-121, ap: AP-3, was: "Programm erscheint sofort auf der Startseite", wie: "Workshop-Termin ändern, Startseite neu laden", status: offen}
- {id: T-122, ap: AP-3, was: "Plätze nicht unter vergebene Plätze senkbar", wie: "Termin mit 3 Anmeldungen auf 2 Plätze setzen → Warnung, Überhang bleibt", status: offen}
- {id: T-123, ap: AP-3, was: "Anmeldezeitraum", wie: "Vor Start und nach Ende: Formular geschlossen mit Hinweis", status: offen}
- {id: T-124, ap: AP-3, was: "Workshop-Limit und Pflicht einstellbar", wie: "Limit 1 setzen → zweiter Workshop abgelehnt; Pflicht an → Anmeldung ohne Workshop abgelehnt; beides aus → frei", status: offen}
- {id: T-125, ap: AP-3, was: "Nachrück-Einstellungen wirken", wie: "Annahmefrist auf 1 h setzen → Angebot läuft nach 1 h ab; Grenze auf heute setzen → keine automatischen Angebote", status: offen}
- {id: T-126, ap: AP-3, was: "Stichtage einstellbar (E-31)", wie: "Stichtage für Workshop, Essen, Storno getrennt setzen; Anzeige auf Startseite und in der Anmeldung stimmt", status: offen}

# AP-4 Anmeldung, Einmal-Links, Warteliste und Nachrücken
- {id: T-130, ap: AP-4, was: "Anmeldung mit Double-Opt-In", wie: "Anmelden, Bestätigungsmail, Link klicken → Status bestätigt; unbestätigte Anmeldung verfällt nach Frist und gibt den Platz frei", status: offen}
- {id: T-131, ap: AP-4, was: "Spamschutz", wie: "Honeypot gefüllt, Formular unter 4 s, ohne Proof-of-Work → stille Ablehnung mit gleicher Antwort", status: offen}
- {id: T-132, ap: AP-4, was: "Wegwerfadresse abgelehnt", wie: "Anmeldung mit Domain aus wegwerfdomains.txt", status: offen}
- {id: T-133, ap: AP-4, was: "Pflichtfelder, Berufsgruppen und EFN", wie: "Ohne Berufsgruppe/Institution → Fehler; Sonstige zeigt Freitext; EFN-Feld nur bei Ärztin/Arzt sichtbar; leer erlaubt; falsche Prüfziffer → Fehler; gültige EFN → angenommen", status: offen}
- {id: T-134, ap: AP-4, was: "Einmal-Link anfordern", wie: "Bekannte und unbekannte Adresse → gleiche Antwort, gleiche Dauer (±); Mail nur bei bekannter", status: offen}
- {id: T-135, ap: AP-4, was: "Einmal-Link: Ablauf und Einmalverwendung", wie: "Link zweimal öffnen; Link nach Ablauf öffnen", status: offen}
- {id: T-136, ap: AP-4, was: "Eigene Anmeldung ändern und stornieren", wie: "Workshop und Essen ändern, EFN nachtragen, dann stornieren; Mails kommen an", status: offen}
- {id: T-137, ap: AP-4, was: "Letzter Platz bei gleichzeitigen Anmeldungen", wie: "Skript mit 20 parallelen Anmeldungen auf 1 freien Platz → genau 1 Platz, 19 Warteliste", status: offen}
- {id: T-138, ap: AP-4, was: "Nachrück-Angebot nach Storno", wie: "Storno eines Platzes → erste Person der Warteliste erhält Angebot per Mail; Annahme über Link → Platz; Platz ist währenddessen für andere gesperrt", status: offen}
- {id: T-139, ap: AP-4, was: "Angebot läuft ab oder wird abgelehnt", wie: "Frist verstreichen lassen bzw. ablehnen → Angebot geht an die nächste Person; abgelaufene Person steht am Ende der Warteliste und hat eine Mail; Mails kommen an", status: offen}
- {id: T-140, ap: AP-4, was: "Grenze für automatisches Nachrücken", wie: "Symposium-Datum testweise auf in 10 Tagen setzen → Storno erzeugt kein Angebot, Team sieht offenen Platz zur manuellen Vergabe", status: offen}
- {id: T-141, ap: AP-4, was: "Überschneidende Termine gesperrt", wie: "Zwei Termine im selben Zeitfenster wählen → Ablehnung mit Hinweis", status: offen}
- {id: T-142, ap: AP-4, was: "Token nur als Hash in der DB", wie: "Datenbank: Spalte enthält sha256, nicht den Link-Wert", status: offen}
- {id: T-143, ap: AP-4, was: "Stichtage sperren Teilnehmende", wie: "Stichtag Workshop auf gestern setzen → Workshop-Änderung per Einmal-Link gesperrt mit Hinweis, Essen noch änderbar; Storno-Stichtag → Storno gesperrt", status: offen}
- {id: T-144, ap: AP-4, was: "Stichtage sperren das Team nicht", wie: "Nach Stichtag ändert das Team Workshop und Essen einer Anmeldung; Audit-Eintrag", status: offen}

# AP-4b Konten für Teilnehmende
- {id: T-145, ap: AP-4b, was: "Konto beim Anmelden und nachträglich", wie: "Anmeldung mit „Konto anlegen“ → Bestätigung → Login zeigt Anmeldung; Anmeldung ohne Konto → Einmal-Link → Konto anlegen → Anmeldung im Profil", status: offen}
- {id: T-146, ap: AP-4b, was: "Konto ohne Symposium-Anmeldung", wie: "Konto nur mit Newsletter anlegen; später Anmeldung aus dem Profil heraus", status: offen}
- {id: T-147, ap: AP-4b, was: "E-Mail-Änderung mit Bestätigung", wie: "Neue Adresse eintragen → alte bleibt gültig bis Bestätigung; nach Bestätigung gehen Mails an die neue; Newsletter zieht mit", status: offen}
- {id: T-148, ap: AP-4b, was: "Login, Passwort vergessen, Ratenschutz, kein 2FA-Zwang", wie: "Falsches Passwort n-mal → Sperre; Reset-Link einmalig; Login ohne zweiten Faktor möglich", status: offen}
- {id: T-149, ap: AP-4b, was: "Profil übernimmt Berufsgruppe/Institution, Konto löschen", wie: "Werte aus der Anmeldung im Profil, Änderung wirkt auf die Anmeldung; Konto löschen → Login weg, Anmeldung nach Regel E-24/O-21", status: offen}

# AP-5 Team-Bereich
- {id: T-150, ap: AP-5, was: "Login ohne zweiten Faktor verweigert", wie: "Konto ohne TOTP/Passkey → nach Passwort Zwang zur Einrichtung, kein Zugriff", status: offen}
- {id: T-151, ap: AP-5, was: "TOTP", wie: "Einrichten per QR, Code gültig, Wiederholung desselben Codes abgelehnt", status: offen}
- {id: T-152, ap: AP-5, was: "Passkey", wie: "Registrieren und anmelden auf Handy und Desktop", status: offen}
- {id: T-153, ap: AP-5, was: "Wiederherstellungscodes", wie: "Login mit Code; Code danach ungültig", status: offen}
- {id: T-154, ap: AP-5, was: "Rollen", wie: "Admin ruft Konten-, Betreiber-Einstellungs- und Rechtstext-Seiten auf → verweigert", status: offen}
- {id: T-155, ap: AP-5, was: "Statistik-Seite stimmt", wie: "Alle Kennzahlen (Workshops, Warteliste, offene Angebote, Essen, Berufsgruppen, Institutionen, EFN-Quote, Newsletter, Konten) gegen SQL-Abfragen prüfen; Diagramme und Export je Ansicht", status: offen}
- {id: T-156, ap: AP-5, was: "Export", wie: "CSV öffnet in Excel korrekt (UTF-8 mit BOM, Umlaute); CME-Liste enthält nur Name und EFN", status: offen}
- {id: T-157, ap: AP-5, was: "Audit-Protokoll", wie: "Änderung durch Team erzeugt Eintrag mit Zeit, Konto, Art", status: offen}
- {id: T-158, ap: AP-5, was: "Betreiber-Einstellungen: Hostnamen und Absender", wie: "Zweiten Hostnamen und Absender anlegen → Mails tragen den gewählten Absender; Host wird akzeptiert (T-110)", status: offen}
- {id: T-159, ap: AP-5, was: "Konten der Teilnehmenden im Team-Bereich", wie: "Konto suchen, mit Anmeldung verknüpfen, Reset auslösen, sperren → Login verweigert", status: offen}

# AP-6 Mailversand und Rundmails
- {id: T-160, ap: AP-6, was: "SMTP-Versand über Lima-City", wie: "Testmail an eigene Adresse; Verbindung innerhalb 5 s", status: offen}
- {id: T-161, ap: AP-6, was: "Prioritäten", wie: "Rundmail an 100 Adressen einreihen, danach Anmeldung → Bestätigung geht vor der Rundmail raus", status: offen}
- {id: T-162, ap: AP-6, was: "Tageslimit", wie: "Limit testweise auf 5 setzen, 8 Mails einreihen → 5 heute, 3 morgen", status: offen}
- {id: T-163, ap: AP-6, was: "Wiederholung bei Fehler", wie: "SMTP-Passwort kurz falsch → Mail bleibt in der Warteschlange, Versand nach Korrektur", status: offen}
- {id: T-164, ap: AP-6, was: "Inhalt nach Versand geleert", wie: "DB-Zeile nach Versand ohne Empfänger und Text", status: offen}
- {id: T-165, ap: AP-6, was: "Zustellbarkeit", wie: "Testmails an Gmail, GMX, Web.de, Outlook; SPF/DKIM/DMARC pass in den Kopfzeilen; Spamscore (mail-tester.com)", status: offen}
- {id: T-166, ap: AP-6, was: "Rundmail-Filter und Testversand", wie: "Filter je Workshop-Termin und Berufsgruppe zählt richtig; Testversand nur an sich selbst", status: offen}

# AP-7 Newsletter
- {id: T-170, ap: AP-7, was: "Newsletter Double-Opt-In", wie: "Ohne Bestätigung kein Eintrag in der Liste; mit Bestätigung Einwilligung mit Zeit und Fassung gespeichert", status: offen}
- {id: T-171, ap: AP-7, was: "Abmeldung", wie: "Abmeldelink und List-Unsubscribe → sofort ausgetragen, ohne Login", status: offen}
- {id: T-172, ap: AP-7, was: "Mehrere Listen mit eigenem Absender", wie: "Eintrag in eine Liste, Versand an die andere → keine Mail; Absender je Liste stimmt", status: offen}
- {id: T-173, ap: AP-7, was: "Verzahnung mit Symposium-Anmeldung", wie: "Häkchen gesetzt → nach Bestätigung in Liste; nicht gesetzt → nicht in Liste", status: offen}
- {id: T-174, ap: AP-7, was: "Versand über Tage", wie: "Liste größer als Tageslimit → Rest am Folgetag, Protokoll zeigt Fortschritt", status: offen}

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
- {id: T-201, ap: AP-9, was: "Backup und Wiederherstellung", wie: "DB-Dump auf Staging einspielen, Anmeldung aufrufbar", status: offen}
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

### Noch zu prüfen für AP-1

1. T-106 Produktion: nach Merge Tag `v` + Inhalt von `VERSION` setzen (docs/betrieb.md Abschnitt 3) → Lauf nach `produktiv` grün; danach Einrichtung wie auf Staging (Datenbank, config.php mit eigenen Werten, Installer, Cron). Einmal mit falschem Tag prüfen, dass der Lauf vor der Übertragung abbricht (Tag danach löschen).
2. T-103: Ablauf der Sitzung nach 30 min Leerlauf – mit dem Team-Login aus AP-5 prüfen.
3. T-108: Team-Login während der Wartung – mit AP-5.
4. T-102 und T-109: Formular-POST im Browser – mit dem Anmeldeformular aus AP-4.
5. T-104 über HTTP (optional): elfmal `/health?token=falsch`, dann mit richtigem Token → 404; nach 10 min wieder 200.
