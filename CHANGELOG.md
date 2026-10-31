# Changelog

Alle nennenswerten Änderungen. Versionen nach [Semantic Versioning](https://semver.org/lang/de/); die aktuelle Version steht in `VERSION`.

## [0.19.0] – 2026-10-31

Abschluss und Datenschutz (AP-9, E-59).

### Neu

- **Fristen automatisch** (Job `datenschutz`, stündlich): Anmeldungen ohne Konto werden 14 Monate nach der Veranstaltung geleert (Zahlen für die Statistik bleiben); Protokoll nach 14 Monaten, Logins nach 90 Tagen gelöscht.
- **CME-Nachweise:** vor dem Leeren werden Name, EFN und Punkte der anwesenden ÄrztInnen getrennt abgelegt und 10 Jahre aufbewahrt; Kasten mit Export unter Betrieb (nur Betreiber).
- **Automatische Sicherung** alle 3 Stunden mit Staffelung (alle der letzten 3 Tage, bis 7 Tage je Tag, bis 14 Tage je 3 Tage, bis 28 Tage je Woche); von Hand angelegte werden nach 90 Tagen gelöscht.
- **Textvorschlag Datenschutzerklärung** in `docs/datenschutz-vorschlag.md` (kein Rechtsrat).
- Tests: Gesamtablauf mit zwei Personen (T-202), Fristen, Sicherungsplan.

### Geändert

- Migration 16: Tabelle `cme_nachweis`, Spalte `anmeldung.anonymisiert_am`.
- Geleerte Anmeldungen erscheinen im Team-Bereich als „(Daten geleert)“.
- Automatische Sicherungen stehen nicht im Protokoll.
- Repo-Prüfung (T-204): Name des Hosters und Bezüge auf Gen-EM aus README, Konzept, Betriebsdoku, Prüfprotokoll und Code-Kommentaren entfernt.

## [0.18.0] – 2026-10-31

Mehrere Veranstaltungen (AP-11, E-58).

### Neu

- **Veranstaltungen:** Symposien und Abende mit demselben Grundmuster; immer eine aktuelle auf der Startseite, jede unter ihrem Kürzel (`/wintersymposium2027`) erreichbar.
- **Verwaltung → Veranstaltungen:** anlegen (leer oder als Kopie mit Einstellungen, Texten und Programm), aktuell machen, abschließen, Entwürfe löschen.
- **Auswahl im Team-Bereich:** Leiste unter der Navigation; alle Seiten gelten für die gewählte Veranstaltung.
- **Abgeschlossene Veranstaltungen** sind nur noch zum Ansehen da (Rückblick unter dem Kürzel, Team-Bereich ohne Speichern); Exporte und Zertifikate bleiben.
- **Mein Konto → Deine Veranstaltungen:** alle besuchten Veranstaltungen mit Zertifikat.
- **Daten übernehmen:** Wiederkehrende ohne Konto bekommen einen Link, mit dem das Anmeldeformular vorausgefüllt ist.
- Neue Einstellungen: Kürzel, Kurzname, Art, Bausteine (Essenswahl, Fortbildungspunkte, Teilnahmebescheinigung).
- „Zu erledigen“ erinnert nach dem Veranstaltungstag ans Abschließen.

### Geändert

- Migration 15: alle bisherigen Daten gehören zur Veranstaltung „wintersymposium2027“ (aktuell).
- Mails, Kopfzeilen und Seitentitel nennen den Kurznamen der Veranstaltung statt fest „Wintersymposium“.
- Die Unterschrift des Zertifikats liegt je Veranstaltung.

## [0.17.0] – 2026-10-30

Teilnahme und Zertifikate (AP-10, E-57).

### Neu

- **Team → Anmeldungen → Teilnahme:** Alle mit Platz gelten als anwesend; „nicht erschienen“ (und „doch da“) beim Symposium und je Workshop eintragen, auch auf dem Handy; Suche und Filter; alles im Protokoll.
- **Unterschriftenliste als PDF:** alle Teilnehmenden oder nur ÄrztInnen mit der EFN als Barcode (Code 128, `*EFN*`; ohne EFN ein Feld fürs Etikett), sortiert nach Nachname, Vorname oder Berufsgruppe.
- **Teilnahmebescheinigung** nach Entwurf C: Name, EFN, Vorträge, besuchte Workshops, Punkte, Kammer, VNR, Unterschrift als Bild, Nummer; hell und zu Hause druckbar.
- **Freischalten** einzeln oder für alle Anwesenden (ab dem Veranstaltungstag) mit Mail „Deine Teilnahmebescheinigung ist da“; Download unter „Meine Anmeldung“. Ohne Anwesenheit kein Zertifikat.
- Neue Einstellungen: Name und Auflage der Veranstaltung, CME-Kategorie, Name und Rolle unter der Unterschrift.
- Statistik: Kachel „Anwesenheit“ mit Zahlen.

### Geändert

- CME-Liste enthält nur noch anwesende ÄrztInnen.
- Die Sicherung enthält auch die Unterschrift des Zertifikats.

### Behoben

- **Rundmails blieben liegen**, sobald am Tag 4–9 Mails verschickt waren: Das Tageslimit wurde in der Datenbank als Text verglichen (Fehler seit 0.6.0).

## [0.16.0] – 2026-10-30

Export als Excel und PDF (E-56, O-35).

### Neu

- **Excel (xlsx)** für alle Exporte: Kopfzeile in Lila, beim Scrollen fest, mit Filter; Datum als echtes Datum; Texte werden nie als Formel ausgeführt.
- **PDF (A4) im Branding** zum Ausdrucken: Teilnehmende (quer, ohne E-Mail), Listen je Termin, Wartelisten, Essen, CME, Statistik – mit Logo, Stand, Filter und Seitenzahlen.
- **Liste je Termin für Dozierende:** je Termin eine Seite mit Ort, Leitung und Belegung, ohne E-Mail; auch einzeln unter „Workshops“.
- Knöpfe **CSV · Excel · PDF** unter Statistik, beim Termin und je Newsletter-Liste (dort ohne PDF).
- Fremdbestandteil tFPDF 1.34 (LGPL-2.1) in `server/lib/vendor/tfpdf/`, Schriften und Logo für die PDFs in `server/pdf/`.

### Geändert

- Das Protokoll nennt beim Export das Format.

## [0.15.0] – 2026-10-30

Passkeys (E-55, O-14).

### Neu

- **Mit Passkey einloggen:** ohne Passwort, mit Fingerabdruck, Gesicht oder Geräte-PIN; für Team-Konten ersetzt der Passkey Passwort und zweiten Faktor.
- **Mein Konto → Passkeys:** anlegen (mit Passwort bestätigt), umbenennen, entfernen; bis zu 10 je Konto.
- Erster Team-Login: „Passkey“ als erste Wahl für den zweiten Faktor, danach Wiederherstellungscodes.
- Prüfung nach WebAuthn (übernommen aus NAdoku, Signatur über OpenSSL): Nutzerprüfung verlangt, Zähler gegen kopierte Passkeys, keine Gerätedaten.

### Geändert

- Code per E-Mail lässt sich ausschalten, wenn App oder Passkey eingerichtet sind.
- Links, die eine Nachfrage öffnen („Entfernen“, „Entwurf löschen“), sehen wieder wie Links aus.

## [0.14.0] – 2026-10-30

Ein Editor, ein Konto, Navigation, Löschfrist (E-54).

### Neu

- **Ein Editor überall:** derselbe Editor wie bei den Mailings für Texte, Programm-Beschreibungen, Kurzbios und Listen-Beschreibungen; Knöpfe zeigen den Zustand und schalten an und aus; **nummerierte Liste**.
- **Ein Konto für alles:** eine Sitzung für Team und Teilnehmende; Team-Mitglieder melden sich mit dem eigenen Konto zum Symposium an.
- **Personen-Menü** oben rechts: Meine Anmeldung, Mein Konto, Team-Bereich, Ausloggen.
- **Team-Navigation** in sechs Bereichen mit angehängten Unterseiten.
- **Löschfrist:** Konten Teilnehmender nach 14 Monaten ohne Login gelöscht, Erinnerung 30 Tage vorher; Login setzt die Frist neu.

### Geändert

- Team-Konten fragen den zweiten Faktor direkt beim Login ab; „Mein Konto“ ist eine Seite für alle, der zweite Faktor unter `/konto/zweitfaktor`.
- Eingeloggt zeigt „Meine Anmeldung“ kein Login-Feld mehr.
- Texte werden als bereinigtes HTML gespeichert; die Markdown-Auszeichnung entfällt (Start-Texte einmal umgewandelt, Migration 12).

### Entfernt

- Werkzeugleiste für Markdown (`texte.js`) und der Reiter „Mein Konto“ im Team-Bereich (`/team/profil` leitet zu `/konto`).

## [0.13.0] – 2026-10-30

Rückmeldungen des Admins vom 30.10. (2): Programm (E-53).

### Neu

- **Ziehen mit dem Finger** auf Handy und Tablet; die Zeile läuft mit, am Rand rollt die Seite.
- **Dozierende je Termin**, wenn ein Termin vom Workshop abweicht; Startseite zeigt „mit …“.
- **Vorträge, Fallbesprechungen und Stationen in Workshop-Blöcken** (Feld oder Ziehen), im Zeitraster und in der Handy-Liste.
- Neue Termin-Zeilen mit „Entfernen“.

### Geändert

- Einfügelinie mittig zwischen zwei Einträgen, unter einem Block unter dem ganzen Block; Feld „Hierher ziehen: ans Ende“ entfällt.

### Behoben

- „Programmpunkt löschen“: Dialog erschien unten, „Ja, löschen“ wirkte nicht (betraf jeden Dialog, dessen Formular ein Feld „id“ hat).

## [0.12.0] – 2026-10-30

AP-7: Newsletter (E-52).

### Neu

- **Newsletter-Listen** „Wintersymposium“ und „Allgäu Akut“ mit Double-Opt-In; Seite `/newsletter` (Link in der Fußzeile, Hinweis auf der Startseite).
- Bestätigen, Listen ändern und **Abmelden mit einem Klick** (Link in jeder Mail und List-Unsubscribe); Abgemeldete werden gelöscht.
- Häkchen bei der Symposium-Anmeldung: nach der Bestätigung eine eigene Newsletter-Bestätigung.
- **Team → Newsletter:** Listen, Abonnierende, Einladen, CSV; Statistik je Liste.
- Mailings an eine oder mehrere **Newsletter-Listen** (jede Person einmal, Absendername der Liste).
- „Mein Konto“ (Teilnehmende): Newsletter an- und abwählen.

## [0.11.0] – 2026-10-30

Rückmeldungen des Admins zu Staging (E-51).

### Neu

- **Einheitlicher Bestätigungsdialog** (Entwurf C): jede Nachfrage als Fenster über der Seite, grün bestätigen, rot löschen/verwerfen, lila Info; auf dem Handy von unten.
- **Zwei-Faktor-Authentifizierung per E-Mail-Code**, für alle frei wählbar; neuer Abschnitt unter „Mein Konto“ (App einrichten, Code per E-Mail, Wiederherstellungscodes).
- **Programm:** Workshops und Stationen ausdrücklich einem Block zuordnen (Feld oder auf den Block ziehen); „ans Ende“ ziehen.
- **Texte:** Auszeichnung nach CommonMark (Teilmenge), Werkzeugleiste und Bilder.
- **Protokoll:** Admins sehen nur ihre Bereiche; Betreiber können Einträge ausblenden; Filter als Knöpfe.
- **Einstellungen** sind gesperrt, bis man „Entsperren“ wählt.

### Geändert

- Navigation: grüner Knopf „Anmelden“ bzw. „Meine Anmeldung“; vor dem Anmeldestart Hinweis statt „Link per Mail“; kein „Team“-Link in der Fußzeile; kürzere Login-Texte.
- Programm: weiße Einfügemarke, ein Label „nicht veröffentlicht“, hellroter Hinweisbalken, „Programmpunkt löschen“ neben Speichern.
- Statistik ohne Kachel „EFN angegeben“; Abstand Kicker–Titel bei Umlauten; Rolle neben dem Namen mittig.

### Behoben

- Als Team-Mitglied wirkte „Deine Anmeldung“ wie ausgeloggt.

## [0.10.0] – 2026-10-30

AP-6: Mailings und Team-Mails (E-50).

### Neu

- **Mailings** (neuer Reiter): Rundmails an Teilnehmende mit Empfängerauswahl (alle Angemeldeten, Wartelisten, ohne Essen, je Workshop-Termin, optional Berufsgruppe), Editor mit Fett, Kursiv, Überschrift, Liste, Link, Knopf, Bild und Platzhaltern ({vorname}, {nachname}, {workshops}, {link}), HTML-Ansicht, Vorschau für Handy und Desktop, Testmail an mich, Planen, Senden mit Nachfrage, Abbrechen, Archiv mit Empfängerliste, „Als neue Mail kopieren“.
- Bilder werden hochgeladen, verkleinert und in die Mail eingebettet (keine fremden Server).
- Zweites Postfach **newsletter@** mit eigenem Tageslimit (config.php); Mailings verteilen sich auf mehrere Tage, Bestätigungen gehen vor.
- **Team-Mails:** Einladung neuer Team-Mitglieder per Mail; „Passwort vergessen?“ auch für Team-Konten.
- Betrieb: Stand und Absender je Postfach.

### Geändert

- Formularfelder bleiben auf dem Handy in der Breite des Bildschirms (WebKit).
- Testdaten enthalten zwei Mailings (gesendet und Entwurf mit Bild).

## [0.9.0] – 2026-10-29

AP-5: Team-Bereich (E-49).

### Neu

- **Übersicht** wie im Mockup: Kennzahlen, „Zu erledigen“ (freie Plätze mit Warteliste, Mail-Fehler, ablaufende Angebote, unveröffentlichtes Programm), Workshop-Termine, Essen.
- **Personen:** Suche, Filter mit Zahlen, Person auswählen und daneben bearbeiten – auch nach den Stichtagen. Überbuchen und Tausch nur nach Bestätigung. Platz geben, Angebot schicken, von der Warteliste nehmen, stornieren, Person anlegen, Einmal-Link senden; Konten: Passwort-Link, sperren, löschen, Rolle vergeben (Betreiber, mit Code). Mail an die Person, außer „ohne Mail“.
- **Workshops:** Termine mit Belegung, Teilnehmende und Warteliste je Termin, Aktionen und Liste als CSV.
- **Statistik** mit Filter nach Berufsgruppe (Kennzahlen, Verlauf, Termine, Berufsgruppen, Institutionen, Essen) und **Export** als CSV für Excel (Teilnehmende, Termine, Wartelisten, Essen, CME, Statistik).
- **Texte:** Startseite bearbeiten; Impressum und Datenschutz für Betreiber.
- **Protokoll:** Audit-Einträge mit Filter und Suche.
- **Betrieb** (vorher „Daten“): Hostnamen und Absender der System-Mails.

### Geändert

- „Anmeldungen“ heißt jetzt „Personen“ (alte Adresse leitet weiter).
- Tabellen zeigen in der Kartenansicht wieder ihre Spaltenbeschriftungen.
- Browser-Durchlauf: Prüfung auf Abgeschnittenes ohne Screenreader-Beschriftungen und Eingabefelder.

## [0.8.1] – 2026-10-29

Rückmeldungen des Admins.

### Geändert

- Programm: Ziehen fügt jetzt **zwischen** zwei Punkte ein (grüne Linie zeigt davor oder dahinter). Der Punkt beginnt, wo der darüber endet; nachfolgende Vorträge, Fälle, Pausen und Workshop-Blöcke (samt Terminen und Stationen) rücken nach hinten, bis eine Lücke reicht.
- Abstand der Zwischentitel auf den Team-Seiten (z. B. „Workshop-Termine“, „Personen“).

### Neu

- Team-Bereich → „Daten“: Kasten „Mailversand“ mit Stand (eingerichtet, Absender, Warteschlange, letzter Fehler) und „Testmail an mich“, die sofort versendet und die Antwort des Mailservers zeigt.
- Versionsanzeige in der Fußzeile außerhalb der Produktion und auf der Team-Übersicht.

## [0.8.0] – 2026-10-29

AP-4b: Konten für Teilnehmende (E-48).

### Neu

- Konto anlegen auf drei Wegen: Häkchen beim Anmelden (Passwort nach dem Bestätigungslink), „Konto anlegen?“ auf „Deine Anmeldung“, oder ohne Anmeldung unter `/konto/neu` mit Bestätigung per Mail (Spamschutz wie die Anmeldung).
- Ein Login für alle: `/einloggen` und Kasten „Einloggen“ auf „Deine Anmeldung“ (wie im Mockup). Teilnehmende ohne zweiten Faktor, Team-Konten weiter zum Code.
- „Mein Konto“ (`/konto`): Passwort festlegen und ändern (mindestens 10 Zeichen), Profil, neue Adresse (gilt nach Bestätigung, Anmeldung zieht mit), Konto löschen mit Wahl, ob die Anmeldung bleibt.
- „Passwort vergessen“ per Mail-Link (48 h, einmal).
- Bestätigte Konten und Anmeldungen mit gleicher Adresse werden verknüpft; eingeloggt anmelden ohne zweite Bestätigung, Angaben vorausgefüllt.
- Navigation mit „Mein Konto“; Team-Liste „Anmeldungen“ mit Spalte Konto.
- Job räumt unbestätigte Konten nach 48 h auf.
- Testdaten: Lea hat ein Konto (erfundenes Test-Passwort).

### Geändert

- `/einloggen` ist der Login für alle Konten (vorher nur Team).

## [0.7.0] – 2026-10-29

Sicherung, Wiederherstellung und Testdaten (E-47).

### Neu

- Team-Bereich → „Daten“ (nur Betreiber): Sicherung aller Daten (Tabellen und Fotos) als gzip-Datei auf dem Server, Liste mit Datum, Anlass und Größe; Herunterladen und Wiederherstellen nur mit dem eigenen Code aus der App; Wiederherstellen sichert vorher den jetzigen Stand und lehnt unvollständige oder neuere Sicherungen ab.
- „Testdaten laden“ (nur Staging und Entwicklung): ersetzt Programm, Dozierende, Anmeldungen und Einstellungen durch den Beispieldatensatz; Team-Konten bleiben; vorher automatische Sicherung.
- Mail-Umleitung für Staging: `mail.umleiten_an` in der `config.php` schickt alle Mails an eine Adresse, der Empfänger steht im Betreff.

### Geändert

- Der Test-Beispieldatensatz liegt jetzt in `server/testdaten/` (statt `tools/testdaten/`) und wird mit ausgeliefert, damit Staging ihn laden kann. Außerhalb des Webroots, nicht über das Web erreichbar.
- Ein Testfall zur Mail-Warteschlange wartet jetzt kurz auf den Vermerk in der Datenbank (lief vereinzelt zu früh).

## [0.6.0] – 2026-10-29

AP-4: Anmeldung, Einmal-Links, Warteliste und Nachrücken.

### Neu

- Anmeldung unter `/anmelden`: Vorname, Nachname, E-Mail, Berufsgruppe, Klinik/Institution, EFN (nur bei ÄrztInnen, freiwillig, mit Prüfziffer), Essen, Workshop-Wahl, Newsletter-Wunsch, Einwilligung. Workshop-Wahl wie im Mockup: je Workshop ein fester Termin, überschneidende Termine sofort gesperrt (mit Grund), volle Termine auf die Warteliste, Limit und Pflicht aus den Einstellungen. Ist das Symposium voll, geht die Anmeldung auf dessen Warteliste.
- Bestätigung per Mail: Erst der Klick auf den Link macht die Anmeldung gültig. Bis dahin hält sie ihre Plätze; neue Einstellung „Bestätigungsfrist“ (Start 48 h, Admin), danach verfällt sie.
- „Deine Anmeldung“ (`/deine-anmeldung`): Einstieg mit „Link per Mail“ (gleiche Antwort für jede Adresse), Übersicht mit Status und Wartelisten-Platz, Workshops, Essen und Daten ändern (neue Adresse erst nach Bestätigung), stornieren. Stichtage stehen am jeweiligen Knopf und sperren nach Ablauf.
- Einmal-Links: 48 Stunden gültig, einmal benutzbar, nur als Hash gespeichert; erst der Klick auf „Weiter“ verbraucht sie (Mail-Scanner entwerten sie nicht).
- Wartelisten je Workshop-Termin und für das Symposium; Nachrücken mit Angebot per Mail, Annahmefrist, Weitergabe ans Listenende bei Ablauf und Grenze vor der Veranstaltung. Überschneidet sich ein Angebot mit einem festen Termin oder betrifft es denselben Workshop, zeigt die Seite den Tausch und führt ihn erst nach Bestätigung aus.
- Gleiche Adresse zweimal: keine zweite Anmeldung; die Adresse bekommt den Link zur bestehenden.
- Spamschutz an den öffentlichen Formularen: Honigtopf, Mindestdauer, Rechenaufgabe im Browser (aus NAdoku), Ratenschutz, Liste von Wegwerfadressen.
- Mailversand (Grundlage aus AP-6 vorgezogen): SMTP mit TLS oder STARTTLS, Warteschlange mit Priorität, Tageslimit und Wiederholungen, Vorlagen für alle Anmelde-Mails; Versand direkt nach der Antwort und per Job; Inhalt und Empfänger nach dem Versand gelöscht. Konfiguration in `config.php` (Abschnitt `mail`).
- Jobs `anmeldung` (Fristen, Angebote, Nachrücken) und `mail` (Warteschlange).
- Startseite: „Jetzt anmelden“ und „Anmeldung ändern“, freie Plätze „X / Y“ aus den Anmeldungen; Navigation mit grünem „Anmelden“, „Deine Anmeldung“ und nach dem Link Name und „Ausloggen“. Workshop-Popups mit freien Plätzen, Wartenden und Knopf zur Anmeldung.
- Team-Reiter „Anmeldungen“ (nur lesen): Zahlen, Workshop-Termine mit Belegung und Warteliste, alle Anmeldungen mit Filter; Hinweis auf der Übersicht, wenn der Mailversand nicht eingerichtet ist.
- Test-Beispieldatensatz um Einstellungen (relativ zu heute) und Anmeldungen erweitert; Tests für Regeln, Links, Tausch, Nachrücken, Fristen, Paralleltest (20 gleichzeitige Anmeldungen auf 1 Platz) und den Ablauf über HTTP; Browser-Durchlauf mit Anmeldung, Mail, Bestätigung, Ändern, Angebot und Tausch.

### Geändert

- Programm: Belegte Plätze kommen aus den Anmeldungen. Veröffentlichen lehnt ab, wenn ein Termin mit vergebenen Plätzen fehlt oder zu klein ist, und entfernt Wartelisten entfallener Termine.
- Beispielprogramm im Testdatensatz: Termin WS 02 20:30 hat 2 Plätze (für Warteliste und Nachrücken).

## [0.5.1] – 2026-10-29

### Geändert

- Prüfprotokoll: Die AP-3-Prüfungen T-130 bis T-136 hatten dieselben Nummern wie die geplanten AP-4-Prüfungen. Sie heißen jetzt T-216 bis T-222 (T-130→T-216 Sperren und Sitzungen, T-131→T-217 Ratenschutz Login, T-132→T-218 Notweg Kommandozeile, T-133→T-219 Browser-Durchlauf, T-134→T-220 Notzugang im Browser, T-135→T-221 Bitwarden, T-136→T-222 Veranstaltungs-Einstellungen). Ältere Einträge in diesem Changelog nennen noch die alten Nummern.

## [0.5.0] – 2026-10-29

AP-3 Schritt 3: Programm.

### Neu

- Reiter „Programm“ im Team-Bereich: Tagesplan nach Uhrzeit mit Programmpunkten der Typen Vortrag, Fallbesprechung, Workshop (mit Terminen und Plätzen je Termin, „Bring“, Notiz vom Team), offene Station, Workshop-Block und Pause/Info. Ein Workshop-Block sammelt automatisch alle Workshop-Termine und Stationen in seiner Zeit.
- Speichern ist nicht Veröffentlichen: Änderungen sind im Plan markiert („neu“, „gespeichert · nicht veröffentlicht“), ein roter Banner zeigt den offenen Stand mit Vorschau, Verwerfen und „Jetzt veröffentlichen“. Die Startseite zeigt nur den veröffentlichten Stand. Ohne Uhrzeit lässt sich nicht veröffentlichen.
- Ziehen am Griff ⋮⋮: Ablegen auf einer Zeile schlägt deren Ende als neuen Beginn vor (auf einem Block dessen Beginn); die Dauer bleibt, ein Block nimmt seine Inhalte mit. Neue Punkte ohne Uhrzeit stehen oben hell-lila.
- Termine: „+ Termin“ und „Duplizieren“ (nächster Termin im Anschluss, gleiche Dauer und Plätze); Hinweis, wenn ein Raum doppelt belegt ist.
- Dozierende als eigene Liste (Name, Funktion, Kurzbio, Foto optional), mehreren Punkten zuordenbar. Fotos werden quadratisch auf 480 px verkleinert, ohne Metadaten gespeichert und unter `/bild/` ausgeliefert; verwaiste Fotos räumt der Job „aufraeumen“ weg.
- Startseite: Programm mit Popups für Vorträge, Fallbesprechungen, Workshops und Stationen (Dozierende, Termine mit freien Plätzen, Notiz-Zettel); Workshop-Block auf dem Desktop als Zeitraster, auf dem Handy als Liste.
- Test-Beispieldatensatz `tools/testdaten/beispieldaten.php`: erfundenes Programm für den Browser-Durchlauf; wächst mit jedem Arbeitspaket.
- Browser-Durchlauf: Programm-Pflege (Formular, Typwechsel, Termine, Ziehen, Verwerfen), Dozierende, Vorschau, Zeitraster und Liste, Bildschirmfotos geöffneter Popups. Die Prüfung auf Abgeschnittenes gilt jetzt für jeden Rahmen mit overflow, nicht nur für Tabellen.

### Behoben

- Test zum Wiederholungsschutz der Einmalcodes schlug zufällig fehl, wenn zwischen zwei Aufrufen eine 30-Sekunden-Grenze lag (Fehler im Test, nicht in der Anwendung).
- Kästchen in Formularfeldern bekamen die Maße der Textfelder.
- Browser-Durchlauf: Chromium und Firefox zeigten Datums- und Zeitfelder im US-Format (AM/PM), weil nur die Sprache, nicht die Umgebung des Browserprozesses deutsch war. Jetzt mit `LANG=de_DE.UTF-8`; die Bildschirmfotos zeigen 24 h und TT.MM.JJJJ.

### Entfernt

- Beispielprogramm auf der Startseite (`server/seiten/teil_beispielprogramm.php`, aus 0.2.2); ohne veröffentlichtes Programm steht dort ein Hinweis.

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
