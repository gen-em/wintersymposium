# Changelog

Alle nennenswerten Änderungen. Versionen nach [Semantic Versioning](https://semver.org/lang/de/); die aktuelle Version steht in `VERSION`.

## [0.30.5] – 2026-11-01

Befunde aus dem Durchklicken von Staging (E-74 (3)).

### Neu

- **Status → Umgebung, Details:** drei Merkmale zur Proxy-Warnung, ohne Adressen anzuzeigen – Absenderadresse intern (ja/nein), Kopfzeile X-Forwarded-For (fehlt/vorhanden), Absender = letzter Eintrag darin (ja/nein). Damit lässt sich klären, ob der Ratenschutz die echten Adressen sieht (die Warnung kam auf Staging auch im Browser des Admins).

### Behoben

- **Einstellungen: „geändert“ ohne Änderung.** Wer eine Kachel öffnete und unverändert speicherte, bekam bei Art, Bausteinen, Zielgruppe und der Vorauswahl je Berufsgruppe „… Einstellungen geändert“ samt Protokolleintrag. Ursache: „Testdaten laden“ speicherte alle nicht gesetzten Einstellungen leer; die Auswahl zeigte den Startwert. Jetzt gilt eine leer gespeicherte Auswahl als ihr Startwert, eine leere Zuordnung als die Regel, die das Formular zeigt; eine leere Auswahl wird als Startwert gespeichert, und die Testdaten speichern nur, was sie setzen.
- Team-Testkonten auf Staging bekommen Wiederherstellungscodes; der rote Hinweis „0 Wiederherstellungscodes“ stand sonst auf jeder Team-Seite. Angezeigt werden die Codes nicht.

### Entfernt

- Diagnosezeile „Einstellungen: Reihe nicht übernommen …“ im Fehlerprotokoll (0.30.2): Die Ursache ist gefunden (0.30.3); die Zeile stand sonst bei jedem Admin, der die Kachel „Reihe & Zielgruppe“ speichert.

### Doku

- Konzept E-74 (3) mit Ergebnis des Durchklickens, Prüfprotokoll T-339 bis T-341, betrieb.md (Testkonten, Status → Umgebung).

## [0.30.4] – 2026-11-01

Passkey direkt abfragen (E-75).

### Neu

- **Passkey im Adressfeld:** Auf der Login-Seite bietet der Browser beim Tippen ins Feld „E-Mail-Adresse“ die Passkeys der Seite an (WebAuthn Conditional UI); ein Tipp plus Fingerabdruck, Gesicht oder PIN loggt ein. Für Team-Konten und Teilnehmende.
- **Das Gerät merkt sich den Passkey:** Nach einem Login mit Passkey geht die Passkey-Abfrage beim nächsten Öffnen der Login-Seite von selbst auf. Gespeichert wird im Browser nur „zuletzt mit Passkey“, ohne Name oder Adresse. Lässt der Browser das ohne Tipp nicht zu (Safari), steht der Knopf „Mit Passkey einloggen“ hervorgehoben oben. Abbrechen löscht den Merker, ebenso das Entfernen des letzten eigenen Passkeys unter „Mein Konto“; Ausloggen nicht.

### Geändert

- Je Sitzung gelten bis zu 3 offene Passkey-Herausforderungen je Zweck (vorher eine): Adressfeld, Knopf und ein zweiter Tab machen sich nicht mehr gegenseitig ungültig.
- Der Knopf „Mit Passkey einloggen“ bricht die Abfrage im Adressfeld vorher ab und holt eine frische Herausforderung.

### Doku

- betrieb.md (Passkeys), Konzept (E-75), Prüfprotokoll T-334 bis T-338; Browser-Durchlauf mit Merker, automatischer Abfrage, Abbrechen und Blockade.

## [0.30.3] – 2026-11-01

Ursache des Reihe-Fehlers auf Staging (E-72 (11)) gefunden und gründlich behoben; Staging lässt sich zurücksetzen (E-74).

### Neu

- **Staging zurücksetzen** (Betrieb → Betrieb, nur Staging und Entwicklung, mit Code): Stand wie nach der Installation, ohne Beispieldaten und Testkonten. Es bleiben die eigenen Team-Konten, Betriebs-Einstellungen, Hostnamen, Impressum, Datenschutz und die Reihe Wintersymposium mit einer leeren Veranstaltung; vorher automatische Sicherung. Wahlweise legt es danach die Testkonten aus der `config.php` neu an.
- `feld()`: Option `liste` für Auswahlfelder, deren Werte zugleich der Text sind (Berufsgruppen).

### Geändert

- `feld()` vermutet nicht mehr, ob `optionen` eine Textliste ist: die Schlüssel sind immer die Werte, auch bei 0, 1, 2 …; Berufsgruppen (Anmeldung, Statistik, Mailings) über `liste`. Ein Test in `konventionen.php` verhindert das Raten.

### Behoben

- **Reihe sprang beim Speichern auf „einzeln“ zurück** (Staging): Lagen die Nummern der Reihen alphabetisch hintereinander (1, 2, 3), hielt `feld()` die Auswahl samt „einzeln“ (0) für eine Textliste und schrieb den Namen statt der Nummer in die Optionen; der Server las den Namen als 0. Nicht browserabhängig. Ein Wert, der keine Nummer ist, wird jetzt mit „Unbekannte Reihe.“ abgewiesen.

### Doku

- betrieb.md (Staging zurücksetzen), Konzept (E-72 (11) Ursache, E-74), Prüfprotokoll T-332, T-333.

## [0.30.2] – 2026-11-01

Rückmeldungen vom Admin nach AP-18 und AP-19 (Staging).

### Neu

- **Startseite bearbeiten:**
  - „Verwerfen · Speichern“ schwebt am unteren Fensterrand; nach dem Speichern bleibt die Seite an der Stelle.
  - Vorschau mit Zoom (Einpassen, 50 %, 75 %, 100 %) und „Groß ansehen“ (füllt das Fenster, Esc schließt).
- **Überschrift „Merke · Fortbildungspunkte“** als Feld der Startseite (Migration 24 setzt den bisherigen Text; leer: Kasten ohne Überschrift).
- **Dozierende:** Suche nach Name und Funktion.
- **Newsletter-Mailing:** Postfach newsletter@ vorausgewählt, wenn eingerichtet, sonst Hinweis.
- **Feste Testkonten auf Staging:** BetreiberIn, Admin, Teilnehmende mit Passwort und zweitem Faktor aus der `config.php` (`testkonten`, nie im Repo); „Testdaten laden“ legt sie mit an, „Testkonten anlegen“ setzt sie einzeln zurück.
- **Browser-Durchlauf: Abbruch und Teilwiederholung (E-73):** Bricht eine Engine ab, zählt das als Fehler mit Seite und Zeile, und die übrigen Engines laufen weiter. `ergebnis.json` hält je Engine Status, Zahlen, Version und Code-Stand fest. `WS_WIEDERHOLEN=<ordner>` fährt nur die roten oder abgebrochenen Engines neu und übernimmt die grünen samt Fotos in einen gemeinsamen Bericht; geänderte Dateien seit dem alten Stand stehen im Bericht.
- **Diagnose:** Speichern der Kachel „Reihe & Zielgruppe“ nennt die gespeicherte Reihe; wird die Reihe nicht übernommen, steht der Grund im Fehlerprotokoll (Fehlerprotokoll-Zeile in 0.30.5 wieder entfernt).

### Geändert

- Über der Vorschau stehen nur noch die Warnung „Wort ohne Trennstelle getrennt“ und der Hinweis auf leere Kacheln; Größenstufe und gewollte Trennung entfallen.
- „Allgäu Akut“ steht in allen Newsletter-Listen zuerst und ist hervorgehoben (Bestellen, Verwalten, Mein Konto, Mailings, Team → Newsletter).
- Das Komponentenblatt ist nicht mehr in der Navigation (Betrieb); es bleibt unter `/team/komponenten` für BetreiberInnen.

### Behoben

- Vorschau der Startseite in Firefox: Kamen zwei Aktualisierungen kurz nacheinander, ging eine verloren, und die Vorschau blieb leer. Jetzt wartet die zweite, bis die erste geladen ist.
- „Abbrechen“ in den Einstellungen schloss die Kachel nicht, wenn die Adresse schon auf die Kachel zeigte (`#anmeldung`): jetzt ein Knopf, der neu lädt. Enter speichert weiter.

### Doku

- betrieb.md (Startseite, Einstellungen, Newsletter, Dozierende, Testkonten, Komponentenblatt, Teilwiederholung des Browser-Durchlaufs), CLAUDE.md (Komponentenblatt-Adresse, Teilwiederholung), Konzept (E-72, E-73), Prüfprotokoll T-326 bis T-331.

## [0.30.1] – 2026-11-01

### Doku

- Konzept E-71 (12), (13) und Prüfprotokoll AP-19: zwei Punkte aus der Umsetzung vom Admin bestätigt (Reihe ändern nur BetreiberInnen; ohne gewähltes Interesse gilt die Vorauswahl der Berufsgruppe). Keine Code-Änderung.

## [0.30.0] – 2026-11-01

AP-19 Veranstaltungsreihen und Newsletter (E-71, O-50 bis O-54).

### Neu

- **Reihen:** Dachmarke (Allgäu Akut) → Reihe (z. B. Wintersymposium über alle Jahre) → Veranstaltung.
  - Unter Betrieb → Veranstaltungen anlegen, bearbeiten und löschen. Löschen geht nur ohne Veranstaltungen; die Liste samt Abonnierenden geht mit, vorher eine Sicherung (O-51).
  - Eine neue Veranstaltung wählt ihre Reihe („wie die Vorlage“, „einzeln“, Reihe). Eine Kopie behält die Reihe; eine gewählte Reihe belegt den Kurznamen vor.
  - Einstellungen: Kachel „Reihe & Zielgruppe“.
- **Newsletter je Reihe:** Die Liste entsteht mit der Reihe und heißt wie sie. Team → Newsletter vermerkt „gehört zur Reihe …“.
- **Allgäu Akut mit Interessen** Klinik, Präklinik oder beides (mindestens eins) auf „Newsletter bestellen“, „Verwalten“ und „Mein Konto“; Export mit Interessen; Einwilligungstext ergänzt (neue Fassung).
- **Zielgruppe je Veranstaltung** (Klinik, Präklinik, beide). Ein Mailing an Allgäu Akut geht an alle, nur Klinik, nur Präklinik oder „wie die Zielgruppe einer Veranstaltung“; die Empfängerzahl zeigt der Editor.
- **Admin-Rechte je Reihe:** kombinierbar mit einzelnen Veranstaltungen; gilt auch für künftige Veranstaltungen der Reihe. Admins verschicken den Newsletter ihrer Reihen (O-52).
- **Anmeldung mit zwei Häkchen:**
  - Newsletter der Reihe („Infos zum nächsten …“).
  - Allgäu Akut mit Interessen, vorausgewählt nach Berufsgruppe (O-50, je Berufsgruppe einstellbar in der Kachel Berufsgruppen).
  - Eine Bestätigungsmail für beide (O-54).
- **Migration 23:**
  - Je Kurzname eine Reihe (O-53); die Liste „Wintersymposium“ gehört der Reihe Wintersymposium.
  - Die Liste „Allgäu Akut“ wird Dachmarke; bestehende Abos gelten für beides.
  - Tabellen `reihe` und `konto_reihe`; Spalten `veranstaltung.reihe_id`, `newsletter_liste.art`, `newsletter_abo.klinik`/`praeklinik`, `anmeldung.nl_interessen`; `rundmail.auswahl` auf 120 Zeichen.
- **Beispieldaten:** Reihen Wintersymposium (2026, 2027) und Fortbildungsabend; Nina (Klinik) und Otto (Präklinik) bei Allgäu Akut; `beispieldaten_admin_reihe()` (Rita, Admin für die Reihe Fortbildungsabend).
- **Tests:** T-317 bis T-325 (`tools/tests/lauf/20_reihen.php`, HTTP-Bereich `ap19`, Browser: Vorauswahl der Interessen, Zielgruppe im Mailing-Editor).

### Geändert

- „Name der Reihe“ heißt **Dachmarke** (Betrieb → Absender); Wert und Wirkung bleiben (E-67).
- Seite „Newsletter“: Kopfzeile mit der Dachmarke.
- Statistik „Newsletter-Wunsch“ zählt beide Häkchen.
- S-5-Prüfung angepasst: Ein Admin für alle Veranstaltungen sieht seit O-52 auch Bilder der Reihen-Newsletter.

### Entfernt

- Listen-Option „Bei der Symposium-Anmeldung anbieten“ (Spalte `newsletter_liste.bei_anmeldung`, `nl_liste_anmeldung()`), ersetzt durch die zwei Häkchen.

### Doku

- betrieb.md: Reihen (Abschnitt 9), Einstellungen (10), Rechte je Reihe (14), Newsletter (15).
- Datenschutz-Vorschlag: Interessen und Newsletter der Reihen.
- Konzept: AP-19 mit status, fortschritt, ergebnis, probleme; E-71 ergänzt. Prüfprotokoll: T-317 bis T-325.

## [0.29.0] – 2026-11-01

AP-18 Startseite mit Vorschau, Einstellungen als Übersicht (E-70, Mockup Variante B).

### Neu

- **Seite „Startseite“** unter Verwaltung (wieder eine eigene Seite, E-70 (1)), mit den Abschnitten der Startseite:
  - **Kopf:** Zeile über dem Titel, **Titel**, Untertitel, Sticker.
  - **Worum es geht:** Zeile, Überschrift, drei Kacheln.
  - **Programm:** Zeile und Überschrift (bisher fest).
  - **Merke · Fortbildungspunkte.**
  - Leere Felder und Abschnitte erscheinen nicht. Eckdaten und Newsletter-Hinweis bleiben fest. Abgeschlossene Veranstaltung: nur ansehen.
- **Vorschau** der Startseite mit den noch nicht gespeicherten Werten:
  - Gerätewahl Desktop · Handy · Tablet (1440 / 390 / 768 px). Desktop ist vorausgewählt, in der schmalen Ansicht Handy (O-49).
  - Breit rechts und mitlaufend, schmal darunter und aufklappbar.
  - Hinweise zu Größenstufe, Trennung im Titel (Info oder Warnung) und leeren Kacheln.
  - Neuer Endpunkt `/team/startseite/vorschau` (POST mit CSRF, speichert nichts, nur in derselben Herkunft einbettbar); `assets/startseite.js`.
- **Titel der Startseite:**
  - Editor im Titel-Modus mit den Knöpfen **„Lila“** und **„Trennstelle“**; Bereinigungsprofil `titel` (nur `em` und weiches Trennzeichen).
  - Größenstufen XL/L/M/S nach dem längsten unteilbaren Teil, Mindestgröße 2rem. Getrennt wird nur an Trennstellen; der Browser trennt nur im Notfall.
- **Einstellungen als Übersicht:**
  - Je Gruppe eine Kachel mit Zusammenfassung (Grunddaten, Bausteine, Anmeldung, Warteliste, Stichtage, Fortbildungspunkte, Bescheinigung, Berufsgruppen).
  - „Bearbeiten“ öffnet eine Kachel zur Zeit, gespeichert wird je Kachel. Fehler öffnen ihre Kachel.
  - Kachel „Startseite“ mit Mini-Vorschau und Link.
- **Migration 22:** Zeile und Titel der Startseite leer (= automatisch); Zeilen und Überschriften der Abschnitte mit den bisher festen Texten.
- **Komponentenblatt:** neuer Abschnitt „Startseite“ (Titel-Stufen, Titel-Editor, Gerätewahl, Vorschau-Hinweise, Einstellungs-Kacheln).
- **Tests:** T-309 bis T-316 (`tools/tests/lauf/19_startseite.php`, HTTP-Bereich `ap18`, Browser-Durchlauf: Seite „Startseite“, Lila/Trennstelle, Vorschau, Kacheln der Einstellungen). Beispieldatensatz mit Trennstelle im Titel und leerer dritter Kachel.

### Geändert

- **Ohne aktuelle Veranstaltung:** Die Startseite zeigt als Titel die Dachmarke, und „Programm“ fehlt in der Navigation (E-70 (7)).
- **Zeile über dem Titel und Titel** sind wieder Felder; leer gelten Dachmarke · Kurzname bzw. der Name der Veranstaltung (ändert E-68 (1)).
- **Kopie einer Veranstaltung mit anderem Namen:** Der eigene Titel der Startseite wird geleert, damit der neue Name erscheint.
- **Neue Veranstaltung ohne Vorlage:** bekommt die Zeilen und Überschriften der Abschnitte als Startwerte.
- **Navigation:** Verwaltung hat die Unterseiten Einstellungen, Startseite, Protokoll.
- **Startseite** zeichnet sich aus `start_html()` (dieselbe Funktion wie die Vorschau); `editor_attr()` liefert die Editor-Attribute als Liste für `feld()`.

### Entfernt

- **Sperre der Einstellungen** („Entsperren“/„Wieder sperren“, Klassen `sperre*`): ersetzt durch „Bearbeiten“ je Kachel (Admin 2026-11-01).
- Startseiten-Felder in den Einstellungen, die Weiterleitung `/team/startseite` → Einstellungen und der Startseiten-Modus in `team_texte.php`.
- `va_titel_html()` (ersetzt durch `start_titel_html()`).

### Doku

- betrieb.md: Startseite mit Titel-Regeln und Vorschau (Abschnitt 7), keine aktuelle Veranstaltung (9), Einstellungen als Übersicht (10), Team-Bereich (14), Aufbau, Beispieldatensatz.
- Konzept: AP-18 status, fortschritt, ergebnis, probleme; E-70 und E-68 mit Stand. Prüfprotokoll: AP-18 mit T-309 bis T-316.

## [0.28.1] – 2026-11-01

### Behoben

- **Auslieferung nach 0.28.0 brach vor dem Abgleich ab:** Der neue Schritt im Prüf-Workflow installierte `poppler-utils` ohne aktuellen Paketindex, der Runner bekam 404 auf einen älteren Paketstand. Jetzt läuft vorher `apt-get update`. Übertragen wurde nichts; Staging blieb auf 0.27.0.

### Doku

- Prüfprotokoll und Konzept (AP-17, Probleme) zur abgebrochenen Auslieferung.

## [0.28.0] – 2026-11-01

AP-17 Vereinheitlichung, zweiter Teil und Abschluss (Unterpunkte 7 bis 12, E-69): reiner Umbau; Seiten und Abläufe bleiben gleich. Sichtbar anders sind nur die Protokolltexte (einheitliches Muster).

### Neu

- **JS-Basis `assets/basis.js`** (V-42 bis V-45), auf jeder Seite zuerst geladen: `window.ws` mit `csrf`, `post`, `get` (eine Antwortform `{ok, fehler, …}`), `melden`, `dialog`, `oeffnen`, Uhrzeiten und Base64url; enthält die Bestätigungsdialoge und die stehende Kopfleiste.
- **Link-Lib `server/lib/link_lib.php`** (V-1): ein System für alle Einmal-Links (Konto, Anmeldung, Newsletter) in einer Tabelle `link` (Migration 20, Altdaten übernommen). Token 32 Byte Base64url, ältere Links aus verschickten Mails gelten weiter.
- **Protokoll mit Objektbezug** (V-8, Migration 21): `audit.objekt_art`/`objekt_id`, Altdaten per Muster zugeordnet. Der Filter `?o=anmeldung-12` und „Letzte Änderung“ unter Personen arbeiten über den Bezug.
- **Neue Libs:** `kontext_lib` (`ctx()` statt `$GLOBALS`), `bild_lib` (ein Upload- und Auslieferungsweg für Fotos, Bilder und Dateien), `zweitfaktor_lib`, `zertifikat_lib`; `server/routen.php` mit den Routen.
- **Tests (V-50 bis V-53):**
  - Ein Bereich je Datei in `tools/tests/lauf/`, jeder beginnt mit `test_frisch()` (`tools/tests/basis.php`: frische DB, Installation, Beispieldatensatz). Auswahl mit `WS_TESTS=…`, im HTTP-Teil mit `WS_HTTP=…`.
  - Der HTTP-Teil hat 17 Bereiche mit frischem Stand.
  - Neue Fabrik-Funktionen im Beispieldatensatz (u. a. Warteliste, Fristen, Team-Anmeldung, Entwurf, CME-Nachweis, Sicherung).
  - Grep-Tests: Tests legen nur an, wo das Anlegen geprüft wird (Ausnahmen mit Grund); Ausnahmelisten ohne veraltete Einträge.
  - `tools/tests/totp_code.php` und `tools/tests/seiten.php` (Seitenliste aus den Routen) für HTTP-Tests und Browser-Durchlauf; `einloggenAls()` und eine Abdeckungsprüfung im Browser-Durchlauf.

### Geändert

- **Libs nach Präfix** (V-6, V-12, Konzept 5a K-h, maschinell geprüft):
  - `anmeldung_lib` heißt `login_lib`, `buchung_lib` heißt `anmeldung_lib`.
  - `efn_*` liegen in `format_lib`, die Export-Funktionen in `export_lib`.
  - Seiten-Logik in Libs: `team_link_weitergeben`, `zweitfaktor_codes_seite` und weitere.
  - `ich()` cacht das eingeloggte Konto je Anfrage; die Sitzungsschlüssel sind Konstanten `SITZUNG_SCHLUESSEL_*`.
- **Ein HTML-Bereiniger** `html_bereinigen($html, $profil)` mit den Profilen inline, block und mail (V-13).
- **Alle Skripte** nutzen `window.ws` und haben einen Codestil.
- **Protokolltexte** nach einem Muster („Anmeldung 12: storniert“), ohne Namen, Adressen oder Passkey-Bezeichnungen (R-02); `audit_schreiben` nur noch in Libs.
- **Tests:**
  - Viele Prüfungen sind schärfer: genaue Werte statt `>= 1` oder `!== []`, Schlupflöcher mit `||` entfernt, Zähler relativ zum Stand davor.
  - `lauf.php` hat 1047 Prüfungen (vorher 1038) und braucht etwa 2,5 Minuten.
- **Prüf-Workflow** installiert `poppler-utils`. Fehlt `pdftotext`, schlägt eine Prüfung fehl, statt die PDF-Prüfungen still zu überspringen.
- **Auslieferung:** Die FTP-Zielordner kommen aus den Repository-Variablen `FTP_ZIEL_STAGING` und `FTP_ZIEL_PRODUKTION` statt aus dem Workflow (R-10).

### Behoben

- **Der Klassen-Test aus 0.27.0 erkannte `class="…"` nicht** (Escape-Fehler im Muster) und prüfte nur 30 statt 329 Klassen; jetzt alle, ohne Funde.
- **Testprüfungen, die nichts bewiesen:**
  - „ohne Pfad“ in den Health-Daten konnte nie anschlagen (`json_encode` maskiert `/`).
  - Ein Zweig hing vom Zufall ab, daher lief eine Prüfung nie.
  - Drei Platzhalter `pruefe(false, …)` standen hinter `function_exists`-Wächtern.
  - Mehrere `||`-Bedingungen waren immer wahr.
  - Programm-Abfragen fanden das richtige Kürzel nur über die Reihenfolge der IDs.

### Entfernt

- Tabellen `konto_link`, `anmeldung_link` und `newsletter_link` (in `link` übernommen, E-69 (7)).
- `assets/dialog.js` und `assets/kopf.js` (in `basis.js`).
- Alle `$GLOBALS['ws_*']` und `$GLOBALS['programm_nachgerueckt']`.

### Doku

- Prüfprotokoll mit einem Abschnitt je Arbeitspaket (geplant, Ergebnisse, Noch zu prüfen); T-303 bis T-308.
- betrieb.md:
  - durchnummeriert (1 bis 20);
  - Aufbau mit `routen.php`, `basis.js` und den neuen Libs;
  - Aufbau der Tests;
  - Zielordner als Platzhalter (R-10, Admin 2026-11-01).
- CHANGELOG nur mit den Abschnitten Neu, Geändert, Behoben, Entfernt und Doku; E-18 nennt die Ziel-Dateien der übernommenen Bausteine.
- CLAUDE.md zu JavaScript, `ctx()` und dem Testaufbau.
- Konzept: AP-17 abgeschlossen (Ergebnis, Probleme); Review-Dokument mit den Zahlen nach AP-17.

## [0.27.0] – 2026-11-01

AP-17 Vereinheitlichung, erster Teil (Unterpunkte 1 bis 6, E-69 (5)): reiner Umbau; Seiten und Abläufe bleiben für Teilnehmende und Team gleich. Ausnahmen stehen unter „Geändert (sichtbar)“.

### Neu

- **Komponentenblatt** unter Betrieb → Komponenten (`/team/komponenten`, nur BetreiberInnen, E-64 (1)). Es zeigt jede Komponente mit Varianten und darunter den Aufruf: Typografie, Knöpfe, Felder, Kästen, Chips, Meldungen, Kacheln, Filter, Tabellen, Dialoge, Menüs. Die Seite ist Vorlage für neue Seiten und Regressionstest für das CSS im Browser-Durchlauf. Die Beispiel-Knöpfe ändern nichts.
- **`server/lib/formular_lib.php`** (V-15 bis V-25) bündelt die Bausteine für Formulare und Seiten:
  - Felder: `feld()` für alle Feldtypen, ein Markup mit `aria-describedby` auf Hilfe und Fehler; `wert()`; `profil_felder_html()` ersetzt fünf Kopien des Profilformulars.
  - Aktionen und Dialoge: `aktion_knopf()`, `bestaetigung_html()`.
  - Bausteine: `kachel()`, `chip()` mit den Status-Maps, `filter_leiste()`, `filter_chips()`, `leer_hinweis()`, `seiten_kopfzeile()`.
  - Seitenablauf: `ist_post()`, `aktion()`, `post_text()`, `post_zahl()`, `post_haken()`, `fertig()`, `fertig_oder_fehler()`.
- **Kleine Helfer** (V-2, V-3, V-9, V-11, V-48, V-49):
  - `db_lib`: `db_zeit`, `db_ts`, `db_tag`, `db_zahl`, `db_gibt_es`.
  - `format_lib`: `dauer_text`, `bytes_text`, `person_name`, `jetzt_text`, `jetzt_iso`; `datum_zeit_text(…, uhr: true)`.
  - Weitere: `rate_text` und `rate_warten_text`, `anm_nummer`, `konto_profil_setzen`, `anm_profil_setzen`.
  - `html_lib`: `meldung_html()` nimmt Text, Flash oder Liste; neu sind `NICHT_GESPEICHERT_TEXT`, die Option `seite_kopf(…, ['meldung' => true])` und `seite_ende()`.
- **Code-Konventionen** stehen im Konzept, Abschnitt 5a (K-a bis K-h, Präfix-Tabelle). Grep-Tests dazu liegen in `tools/tests/konventionen.php`: Typografie, Wortliste, `gmdate`, keine `db_*` in Seiten, keine Feld-Closures, keine Einknopf-Formulare von Hand, CSS-Klassen in beiden Richtungen.
- Phosphor-Icons `key`, `lock` und `lock-open` (V-41).

### Geändert

- **Alle Seiten nutzen die Helfer** (V-14 bis V-27):
  - Datenbankzugriffe aus den Seiten sind in Lib-Funktionen gewandert. Die Zählung auf Betrieb → Veranstaltungen braucht jetzt eine Abfrage statt einer je Zeile.
  - `teil_workshopwahl.php` und `teil_programm.php` sind nur noch Vorlagen von `anm_workshopwahl_html()` und `programm_html()`, mit festen Parametern.
  - Statt `$_GET` als Zustand gibt es `$modus`; statt `seite_fuss(); return;` gibt es `seite_ende()`.
  - Selbstgezeichnete SVGs sind durch `icon()` ersetzt.
- **site.css als ein System** (V-29 bis V-39):
  - Gegliedert nach Komponenten, mit Inhaltsverzeichnis.
  - Tokens für Scheine, Backdrop, Papier, Maße und Radien; keine `rgba`- und Hex-Werte mehr außerhalb von `:root`.
  - Etikett-Typografie als eine Regel (`.etikett`, `.etikett-mono`).
  - Eine Menü-Karte (`.menue-karte`) für Zeilen-Menü, Personen-Menü und Wechsler.
  - Aufklapper ohne Dreieck stehen in einer Regel (`details.schlicht`).
  - Kartenmodus der Tabellen als `.karten-immer`.
  - `.knopf.klein` statt `.klein-knopf`; `.gefahr-kasten` statt `.gefahrzone-kasten`; `.seitenkopf` statt `.form-kopf`.
  - Rollen als Chip (`.chip.rolle`, `.chip.rolle-leise` statt `.rolle-tag` und `.rolle-etikett`).
  - Die Töne der Meldungen stehen an einer Stelle.
- Feste Zahlen in Texten (72 Stunden, 15 Minuten, 4 MB …) kommen aus den Konstanten (V-9).

Sichtbar (gewollt):

- **Umbruchpunkte** (E-69 (6)): 760 und 1000 px statt sieben verschiedener. Folgen bei Zwischenbreiten:
  - 600 bis 759 px: Formularpaare stehen untereinander, der Bestätigungsdialog kommt von unten.
  - 900 bis 999 px: zweispaltige Raster (Team-Übersicht, Meine Anmeldung) sind einspaltig.
  - 960 bis 999 px: Tabellen zeigen Karten.
  - 1000 bis 1099 px: Die Namensspalte im Workshop-Raster ist 220 px breit.
- **Profilformulare:**
  - Überall „Bitte wählen“ als erste Berufsgruppe.
  - Auf öffentlichen Seiten „(freiwillig)“ an „Sonstige“ und der EFN-Hinweis wie auf /anmelden.
- **Texte:**
  - Wortliste (Konzept 5a K-f): „Ja, löschen“ statt „Endgültig löschen“ und „Ja, Konto löschen“; „Entfernen“ statt „Von der Liste nehmen“.
  - Sperr-Meldungen enden einheitlich auf „… und versuche es dann noch einmal.“
  - Einheitlicher Text „Nicht gespeichert. Bitte prüf die markierten Angaben.“
- **Darstellung:**
  - **Text in Knöpfen, Chips und Feldern sitzt mittig** (Rückmeldung 2026-11-01). Die Schrift Tajawal hat zwei unpassende Sätze vertikaler Metriken; macOS und Chromium setzten Großbuchstaben deshalb 2–4 px zu hoch. Jetzt gelten in allen Engines einheitliche Metriken über `ascent-override`, `descent-override` und `line-gap-override`. Die Zeilenhöhen bleiben gleich, Tajawal-Text sitzt in seiner Zeile optisch mittig.
  - Leer-Hinweise „Noch kein Passkey.“ und „Noch keine Veranstaltung.“ sind grau-kursiv wie alle anderen.
  - Das Rollen-Etikett unter „Mein Konto“ ist ein Chip (wenige Pixel höher).
  - Das Schloss in den Einstellungen und der Passkey-Knopf beim Einloggen zeigen Phosphor-Icons.

### Entfernt

- CSS-Regeln ohne Verwendung: `ablauf`, `angaben`, `cta`, `fussnote`, `hilfe-kasten`, `hilfe-liste`, `nav-name`, `nav-person`, `nowrap`, `rolle-wechsel`, `form-kopf`, `klein-knopf`, `gefahrzone-kasten`, `rolle-tag`, `rolle-etikett`. Dazu die Klassen ohne CSS im Markup: `eb-text`, `link-kasten`, `person-vorname`, `pg-block`, `status`.
- `betrieb_groesse_text()` (jetzt `bytes_text()`); `hm()` und `minuten()` liegen jetzt in `format_lib` statt in `programm_lib`.

### Doku

- Konzept 5a (Code-Konventionen) und AP-17 Fortschritt; E-69 (5) bis (8).
- betrieb.md zu Seiten, Bausteinen, Gestaltung und Grep-Tests; CLAUDE.md zum Komponentenblatt und zur CSS-Regel.
- `docs/mockups/README.md` (Archiv, E-64).
- Prüfprotokoll T-297 bis T-302.

## [0.26.3] – 2026-11-01

### Doku

- **Konzept:** E-70 (Startseite mit Vorschau für Desktop, Handy und Tablet, Titel mit „Lila“ und „Trennstelle“, Größe vor Trennung; Einstellungen als Übersicht mit Kacheln) und E-71 (Veranstaltungsreihen, Newsletter je Reihe, Allgäu Akut mit Klinik/Präklinik, Zielgruppe je Veranstaltung, Admin-Rechte je Reihe); AP-18 und AP-19 geplant (nach AP-17); Punkte O-49 bis O-54 (wie vorgeschlagen entschieden). Prüfprotokoll: T-309 bis T-325 reserviert.

## [0.26.2] – 2026-11-01

### Doku

- **AP-17 Vereinheitlichung geplant** (E-69): die Review-Pakete AP-R3 bis AP-R9 als ein Paket mit zwölf Unterpunkten (Konventionen, Helfer, formular_lib, Seiten umstellen, CSS-Grundreinigung, Komponentenblatt, JS-Basis, Link-Lib, Protokoll mit Objektbezug, Lib-Aufteilung, Tests auf Beispieldaten, Abschluss); kommt vor dem Umbau von Einstellungen, Startseite und Newsletter; Prüfprotokoll reserviert T-297 bis T-308.

## [0.26.1] – 2026-11-01

### Doku

- **Prüfprotokoll:** voller Browser-Lauf für 0.25.0 nachgetragen (8016 ok, 0 Fehler).

## [0.26.0] – 2026-11-01

### Geändert

- Neues Skript `assets/zweitfaktor.js` (Knöpfe im Bestätigungsdialog), neue Aktion `bestaetigen` in `/passkey`; Ratenschutz-Töpfe `mailcode` und `mailcode_minute`.
- Test-Beispieldatensatz: `beispieldaten_review2()` (unbestätigte Anmeldung mit Adas Adresse, Lea auf der ausgeblendeten Liste „Referierende (intern)“); Browser-Durchlauf prüft Bestätigung mit Mail-Code und Passkey, Adas „Meine Anmeldung“ und Startseite ohne Veranstaltung.

### Behoben

AP-16, Review-Behebung 2:

- **B-4:** „Meine Anmeldung“ zeigt immer die aktuelle Veranstaltung. Vorher legte eine Team-Seite oder Archivseite einer alten Veranstaltung deren Anmeldung in die Sitzung. Der Sitzungseintrag kennt jetzt seine Veranstaltung; abgeleitet wird nur auf öffentlichen Seiten der aktuellen. Ein Einmal-Link (z. B. aus der Zertifikat-Mail) öffnet weiter die Anmeldung einer vergangenen Veranstaltung (E-58).
- **B-6:** Newsletter speichern („Mein Konto“, Verwalten-Link) meldet nicht mehr still von ausgeblendeten Listen ab; nichts angekreuzt in „Mein Konto“ meldet nur von den sichtbaren ab.
- **B-7 / S-4 / V-4:** Heikle Aktionen (Rolle ändern, zweiten Faktor zurücksetzen, Sperren, Sicherung herunterladen und wiederherstellen) gehen mit jedem zweiten Faktor: Code aus der App, Code aus der Mail („Code per E-Mail schicken“ direkt im Dialog) oder Wiederherstellungscode, mit Passkey über „Mit Passkey bestätigen“. Ein Helfer (`zweitfaktor_bestaetigen`, `zweitfaktor_bestaetigt`) statt Eigencode auf vier Seiten. Passwort ändern, Konto löschen, zweiten Faktor ändern und Passkey anlegen prüfen das Passwort mit Ratenschutz (`passwort_bestaetigen`, 5 Versuche je 15 Minuten).
- **S-3:** Team-Konten übernehmen nur die verknüpfte oder eine bestätigte Anmeldung mit ihrer Adresse; eine fremde, unbestätigte gilt nicht als eigene.
- **B-8:** Der Aufräum-Job löscht keine Fotos mehr, die vergangene Veranstaltungen (Archivseiten) oder Workshop-Termine noch zeigen.
- **B-12:** Nach „Restlichen Versand abbrechen“ stehen in der Empfängerliste nur noch die, die das Mailing bekommen haben.
- **B-14:** Fehler ohne Feldbezug in „Mein Konto → Profil“ erscheinen als Meldung; Speichern eines gelöschten Mailings ohne PHP-Warnung.
- **S-11:** Protokolltext zur Anwesenheit ohne Namen.
- **B-10:** Ohne aktuelle Veranstaltung kein Rückfall auf Veranstaltung 1: Startseite „Derzeit keine Veranstaltung“, keine Anmeldung möglich. Die letzte verbliebene Veranstaltung lässt sich nicht löschen.
- **S-5:** Mailing-Bilder sieht im Team-Bereich nur, wer sie hochgeladen hat oder das Mailing sehen darf.
- **S-9:** Codes per E-Mail höchstens einer je Minute und 10 je Stunde je Konto (statt je Sitzung).
- **S-6:** Status → Umgebung warnt, wenn Anfragen über einen Proxy kommen und `https_ueber_proxy` aus ist.
- **S-12:** geprüft, kein Fehler: eine erfundene Sitzungs-ID im Keks wird nicht übernommen (`use_strict_mode` wirkt); dazu ein Test.

### Doku

- Konzept: AP-16 umgesetzt (status, ergebnis, probleme); betrieb.md (zweiter Faktor beim Bestätigen, Mail-Code-Grenze, keine aktuelle Veranstaltung, Proxy-Warnung, Newsletter, Fotos); Prüfprotokoll T-288 bis T-296; Review-Dokument Abschnitt 5.

## [0.25.0] – 2026-10-31

### Geändert

- **Startseite aus der Veranstaltung** (E-68): Die Zeile über dem Titel ist „Name der Reihe · Kurzname“, der Titel der Name der Veranstaltung – ein Wort ganz lila, sonst das erste Wort weiß und der Rest lila. Die Textfelder „Zeile über dem Titel“ und „Titel“ entfallen.
- **Alles einer Veranstaltung auf einer Seite:** Untertitel, Sticker, Überschrift der Kacheln, **drei Kacheln** mit je Titel und Text und der Hinweis Fortbildungspunkte stehen bei Verwaltung → Einstellungen (Abschnitt „Startseite“, mit derselben Sperre). Die Seite „Startseite“ und ihr Reiter entfallen; alte Links führen zu den Einstellungen. Das frühere Feld „Infokästen“ wird beim ersten Speichern in die drei Kacheln übernommen.
- Lange Wörter im Titel der Startseite werden mit Trennstrich umbrochen (vorher lief „Fortbildungsabend“ auf breiten Bildschirmen über den Rand).

## [0.24.0] – 2026-10-31

### Geändert

- Neue Mail-Vorlage „Adresse geändert“ (an die alte Adresse); neue CSS-Regel für schreibgeschützte Felder (`.feld input[readonly]`).
- Test-Beispieldatensatz: `beispieldaten_warteliste()`; Browser-Durchlauf prüft Warte-Kästchen (Team und Teilnehmende) und Storno mit Konto.

### Behoben

AP-14, Review-Behebung 1:

- **S-1 / B-9:** Die Login-Adresse eines Team-Kontos lässt sich nicht mehr über eine Anmeldung ändern – weder vom Team unter „Personen“ noch von der Person unter „Meine Anmeldung → Daten“ oder über einen alten Bestätigungslink. Das Feld ist dort schreibgeschützt mit Hinweis. Ändert das Team die Adresse einer Anmeldung mit Teilnehmenden-Konto, bekommt die alte Adresse eine Hinweis-Mail; das Protokoll vermerkt „Konto N: Adresse vom Team geändert“.
- **B-1:** Newsletter-Mails in der Warteschlange gingen verloren, wenn eine Admin-Aktion den Versand anstieß. Mailings laden jetzt ohne Rechteprüfung (die Team-Seiten prüfen mit `rm_sichtbar()`); fehlt ein Mailing, ist das ein Fehler mit Wiederholung statt Löschen.
- **B-2 / B-3:** Wartelisten-Einträge gingen beim „Speichern“ verloren (Team-Modus; Termin inzwischen frei). Bestehende Einträge stehen jetzt immer als angekreuztes Kästchen im Formular.
- **S-2:** Adressänderungen (Meine Anmeldung, Mein Konto) haben einen Ratenschutz wie „Link per Mail“ (10 je Stunde und Herkunft, 3 je Zieladresse); nur ein offener Bestätigungslink; die Bestätigungsmail beginnt mit „Hallo,“ ohne Vornamen.
- **B-5:** Ein Storno auf „Meine Anmeldung“ loggt nicht mehr aus; Konto- und Team-Login bleiben (E-63).
- **B-13:** Die Vorlage „Daten übernehmen“ gilt 30 Minuten und ist nach dem Absenden der Anmeldung weg.

### Doku

- Konzept: AP-14 umgesetzt (status, ergebnis, probleme), Test zu Unterpunkt 4 korrigiert, O-48 (Adresse eines Team-Kontos ändern) neu; betrieb.md, Prüfprotokoll T-275 bis T-281, Review-Dokument Abschnitt 5.

## [0.23.3] – 2026-10-31

### Doku

- **AP-15 auf Staging abgenommen** (Admin 2026-10-31): Shell-Cronjob beim Hoster eingerichtet, alle Ampeln grün, Token-Anzeige, Fehlerprotokoll und `/health` geprüft; Prüfprotokoll T-282 bis T-287 und Konzept nachgetragen.
- **Konzept E-65:** die beim Zusammenführen mit 0.23.x verlorenen Punkte (5) bis (8) (vor der Umsetzung geklärt) wiederhergestellt.

## [0.23.2] – 2026-10-31

### Doku

- **Prüfprotokoll:** voller Browser-Lauf für 0.23.1 (7851 ok, 9 Fehler am Personen-Menü – behoben, Nachtest 590 ok, 0 Fehler) und Prüfweg für den Admin nachgetragen.

## [0.23.1] – 2026-10-31

### Geändert

- **Namen in Mails** (E-67): Mails zu einer Anmeldung tragen den Namen der Veranstaltung (Absender, Kicker, Gruß); Mails für alle Veranstaltungen (Team, Konto, Passwort, Code, Newsletter, Testmail) den Namen der Reihe „Allgäu Akut“ statt „Wintersymposium“ – auch im Betreff. Der Name der Reihe ist eine Einstellung unter Betrieb → Absender und ersetzt dort das Feld „Absendername“ des System-Postfachs. Migration 19 (Absendername je Mail in der Warteschlange).
- **Personen-Menü:** Team-Konten sehen oben den Eintrag „Team-Bereich“, grün mit Symbol (Rückmeldung 2026-10-31; ersetzt „ohne Team-Bereich“ aus E-60).
- **Übersicht in der Mail nach Variante B** (Admin 2026-10-31): jede Zeile mit farbigem Streifen links und Kennung über dem Titel – „ABEND · 18:00–22:00“ lila, „WORKSHOP · 20:00–20:30“ rot wie im Programm, „ESSEN“ grau; Schilder rechts senkrecht mittig; keine leere Zeitspalte mehr.

## [0.23.0] – 2026-10-31

### Neu

- **Mails im Erscheinungsbild Allgäu Akut** (O-40, E-66, Variante A): Alle automatischen Mails, Mailings und Newsletter kommen als HTML mit Textfassung – dunkler Kopf mit eingebettetem Logo, Überschrift, grüner Streifen, Übersicht der Plätze als Tabelle mit Schildern (bestätigt, Platzangebot, Warteliste mit Position), lila Knopf mit Ersatzadresse, Fuß mit Impressum und Datenschutz. Neue Datei `mail_html_lib.php`, Logo `assets/logo/mail-logo.png`, Migration 18 (HTML-Fassung in der Warteschlange).
- **Eigene Plätze im Programm** (O-41, E-66, Variante B): Wer eingeloggt ist oder über den Link kommt, sieht auf der Startseite am eigenen Termin einen weißen Rahmen und ein Schild („Dein Platz“, „Platzangebot“, „Warteliste N“), die Zeile des Workshops ist markiert („Du: …“); auch in der Handy-Liste.

### Geändert

- Begriff **„Platzangebot“** statt „Platz frei für dich“ in Mails, „Meine Anmeldung“ und Team-Bereich; Betreff der Angebotsmail „Platzangebot: …“.
- Knöpfe in Mailings sind lila wie auf der Webseite.

## [0.22.0] – 2026-10-31

### Neu

- **Status-Seite unter Betrieb** (AP-15, E-65; nur BetreiberInnen, erste Seite im Reiter Betrieb): Ampel für Cron, Jobs, Mailversand, Datenbank, Sicherung, Fehlerprotokoll und Umgebung; was Aufmerksamkeit braucht, steht oben. Details je Zeile zum Aufklappen, Knopf „Jetzt prüfen“.
- **Cron-Anleitung auf der Seite:** Zeitplan und Befehl für den Shell-Cronjob mit dem richtigen Zielordner und Knopf „Kopieren“; die URL mit Token für den Ausweichweg erst nach Klick, das Anzeigen steht im Protokoll (neue Art „Betrieb“).
- **Fehlerprotokoll im Team-Bereich:** Größe und Einträge seit dem letzten Leeren, letzte 30 Zeilen auf Abruf, Leeren mit Nachfrage und Protokolleintrag.
- **Übersicht:** Rote Status-Zeilen erscheinen BetreiberInnen unter „Zu erledigen“.

### Geändert

- **`/health`** liefert dieselben Ampeln wie die Status-Seite (`zeilen`, `ampel`); `status` ist `fehler`, sobald eine Zeile rot ist (HTTP bleibt 200). Die Felder `datenbank` und `jobs` sind entfallen. Nach einer Neuinstallation steht `status` deshalb auf `fehler`, bis Cron und erste automatische Sicherung gelaufen sind.
- **Seite Betrieb:** Der Kasten Mailversand zeigt nur noch Testmail und Umleitung; die Zahlen stehen unter Status.
- BetreiberInnen sehen die Mail-Aufgaben auf der Übersicht als Status-Zeile statt doppelt.

## [0.21.6] – 2026-10-31

### Doku

- **Code-Review** mit drei Zielstellungen (Bugs, Sicherheit, Vereinheitlichung) in `docs/review-2026-10-31.md`: 14 Bugs, 12 Sicherheitspunkte, 53 Vereinheitlichungspunkte, Vorschlag für Arbeitspakete. Noch keine Behebung.
- **Konzept:** E-63 (Review-Behebung: Adresse von Team-Konten nur über Team-Konten, Storno ohne Ausloggen, ein PR je Paket), E-64 (site.css als alleinige Design-Vorlage, Mockups Archiv, CLAUDE.md angepasst; Shell-Cronjob als Standard, betrieb.md Schritt 6; technische Grenzen bleiben Konstanten, R-11 ergänzt), E-65 (Status-Seite unter Betrieb). **AP-14** Review-Behebung 1, **AP-15** Status-Seite, **AP-16** Review-Behebung 2 geplant mit Unterpunkten und Tests; O-42 bis O-46 entschieden, O-47 offen; Prüfprotokoll reserviert T-275 bis T-296.

## [0.21.5] – 2026-10-31

### Geändert

- **Editor überall mit derselben Leiste** (Admin 2026-10-31): Alle mehrzeiligen Felder (Texte der Startseite, Impressum, Datenschutz, Programm-Beschreibung, Kurzbio, Mailings) haben B, I, H · Liste, 1. Liste, Link, Bild, Knopf · HTML. Die Leiste ist an einer Stelle festgelegt (`EDITOR_LEISTE`, `editor_attribute()` in `inhalt_lib.php`). Ausnahmen: Platzhalter nur in Mails, einzeilige Felder (Titel, Untertitel, Beschreibung einer Newsletter-Liste) nur fett, kursiv, Link.
- **Bilder und Knöpfe im Programm-Popup und in Kurzbios**; der Aufräum-Job sucht Text-Bilder jetzt auch im Programm, im veröffentlichten Stand und bei den Dozierenden.

## [0.21.4] – 2026-10-31

### Behoben

- **„Meine Anmeldung“ zeigte dem Team-Konto keinen Team-Bereich** in der Leiste (Rückmeldung 2026-10-31): Seiten, die die Anmeldung selbst übergeben, haben das Team-Login nicht geprüft. Der Seitenkopf prüft es jetzt immer.

## [0.21.3] – 2026-10-31

### Geändert

- Testserver (`lauf.php` und Browser-Durchlauf) laufen wie der Hoster ohne Ausgabepuffer (`output_buffering=0`), damit solche Fehler im Test auffallen. Der Browser-Durchlauf prüft die Startseite nach dem Team-Login direkt, über das Logo und nach Zurück/Vor.

- **Impressum und Datenschutz** haben dieselbe Werkzeugleiste wie die Mailings, mit Knopf (Link als Schaltfläche) und HTML-Quelltext (Admin 2026-10-31). Knöpfe bleiben auf der Webseite Knöpfe.
- **Mailings:** Der Hilfetext unter dem Editor ist kürzer und erklärt die Platzhalter `{vorname}`, `{nachname}`, `{workshops}` und `{link}`.

### Behoben

- **Startseite zeigte eingeloggt den ausgeloggten Kopf** (Rückmeldung 2026-10-31, Staging, alle Browser): Der Seitenkopf schickte den Anfang der Seite, bevor er die Sitzung öffnete. Beim Hoster ist der Ausgabepuffer aus, danach lässt sich keine Sitzung mehr starten. Die Erkennung von Login, Konto und Einmal-Link läuft jetzt vor der ersten Ausgabe.

### Doku

Offene Punkte im Konzept:

- O-40: Mails im Erscheinungsbild Allgäu Akut.
- O-41: Eigene Workshops (angemeldet, Warteliste) im Programm der Startseite markieren – Mockup mit 2–3 Varianten.

## [0.21.2] – 2026-10-31

### Geändert

- **Browser-Prüfung gestuft** (R-12, E-62): Stufe 1 `lauf.php` immer, Stufe 2 gezielt (`WS_STUFE=2 WS_SEITEN=…`, Chromium, Handy und breiter Desktop, ca. 1 Minute), Stufe 3 der volle Lauf bei Arbeitspaket-Ende, gemeinsamen Teilen, vor Produktion und spätestens nach 5 PRs. Der Durchlauf kennt dafür `WS_STUFE`, `WS_SEITEN` und `WS_BREITEN`.

## [0.21.1] – 2026-10-31

### Geändert

- **Knopf-Standard Variante A** (Entwurf 6): Zweit- und Gefahr-Knöpfe mit 2-px-Ring statt getönter Fläche, beim Hover leicht gefüllt (Admin 2026-10-31).
- Team-Bereich: der aktive Unterpunkt (z. B. Betrieb → Texte) ist eine lila Fläche statt eines dünnen Strichs.
- Statistik: die EFN-Zeile in der Kachel „Angemeldet“ entfällt.

## [0.21.0] – 2026-10-31

Team-Konten aufgeräumt, Meldungen auffälliger (AP-13, E-61).

### Geändert

- **Team-Konten:** je Zeile nur noch ein Knopf „Bearbeiten“ mit Menü (Veranstaltungen, Rolle ändern, Passwort-Link, zweiten Schritt zurücksetzen, Konto sperren); jede Aktion läuft im Dialog. Zurücksetzen und Sperren fragen jetzt nach.
- **Meldungen** (Fehler, Hinweis, Erfolg): volle Farbe mit dunkler Schrift und Icon statt eines schmalen Strichs; liegt eine Fehlermeldung außerhalb des Bildschirms, scrollt die Seite dorthin.

## [0.20.0] – 2026-10-31

Team-Navigation und Rechte je Veranstaltung (AP-12, E-60).

### Neu

- **Rechte je Veranstaltung:** Admins sind für alle (auch künftige) oder nur ausgewählte Veranstaltungen freigeschaltet (Betrieb → Team-Konten) und sehen überall nur diese – auch bei Personen, Konten und im Protokoll. BetreiberInnen sehen alles.
- **Wechsler** rechts neben dem Seitentitel statt der Leiste; BetreiberInnen mit „Neue Veranstaltung“.
- **Reiter Betrieb** (nur BetreiberInnen): Veranstaltungen, Team-Konten, Newsletter, Texte (Impressum, Datenschutz), Betrieb. Oben steht „Mailings“ statt „Kommunikation“; Startseiten-Texte unter Verwaltung → Startseite.
- **Veranstaltungen löschen** (nur BetreiberInnen, nicht die aktuelle): Kürzel eintippen, vorher automatische Sicherung; CME-Nachweise bleiben.
- **Protokoll** mit Veranstaltung und Filter (gewählte, alle, ohne Veranstaltung).
- **Personen-Menü** neu: Kopf zum Lesen, Punkte mit Icons (Phosphor, MIT), Hover-Ring; ohne Team-Bereich.
- **Mein Konto** neu sortiert: Profil (mit Rolle und Veranstaltungen), Deine Veranstaltungen, Newsletter; „Einloggen und Sicherheit“ in einer Kachel mit verständlichen Bezeichnungen.
- **Kopfleiste bleibt stehen**; auf dem Handy beim Scrollen nach unten nur ein „Menü“-Knopf.
- Beispieldaten: Ada (Admin nur für den Fortbildungsabend).

### Geändert

- **Knopf-Standard:** getönte Fläche statt Rand; alle Ränder in ganzen Pixeln (vorher 1,5 px, wirkte unrund).
- Team-Bereich grün in der Kopfleiste.
- Die Rolle heißt überall „BetreiberIn“.
- Mailings an Newsletter-Listen, Newsletter und sein Export nur für BetreiberInnen.
- Migration 17: `konto.alle_veranstaltungen`, `konto_veranstaltung`, `audit.veranstaltung_id`.

### Behoben

- Die Startseite erkannte eingeloggte Team-Konten nicht (Personen-Menü fehlte, Staging).
- Anmeldungen und Plätze anderer Veranstaltungen ließen sich im Team-Bereich über ihre Nummer öffnen.

## [0.19.1] – 2026-10-31

### Geändert

- Beispieldatensatz mit drei Veranstaltungen: neu ein abgeschlossener „Fortbildungsabend klinische Notfallmedizin“ (Vortragsabend ohne Essen und Workshops, mit Punkten; Lea und Kai da, Nora nicht erschienen); Browser-Durchlauf prüft seinen Rückblick.
- Teilnahmebescheinigung: Fußzeile in einer Zeile – links „Teilnahme vom Team erfasst · Nr.“, rechts die Webadresse, beides grün; ein langer Kammer-Hinweis steht darüber.

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
- Repo-Prüfung (T-204): Name des Hosters und Bezüge auf die frühere Organisation aus README, Konzept, Betriebsdoku, Prüfprotokoll und Code-Kommentaren entfernt.

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

### Neu

- Team-Bereich → „Daten“: Kasten „Mailversand“ mit Stand (eingerichtet, Absender, Warteschlange, letzter Fehler) und „Testmail an mich“, die sofort versendet und die Antwort des Mailservers zeigt.
- Versionsanzeige in der Fußzeile außerhalb der Produktion und auf der Team-Übersicht.

### Geändert

- Programm: Ziehen fügt jetzt **zwischen** zwei Punkte ein (grüne Linie zeigt davor oder dahinter). Der Punkt beginnt, wo der darüber endet; nachfolgende Vorträge, Fälle, Pausen und Workshop-Blöcke (samt Terminen und Stationen) rücken nach hinten, bis eine Lücke reicht.
- Abstand der Zwischentitel auf den Team-Seiten (z. B. „Workshop-Termine“, „Personen“).

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

### Neu

- Browser-Durchlauf prüft auf jeder Seite, dass Code-Felder ein solches Stichwort tragen und kein Passwortfeld sind.

### Behoben

- Bitwarden füllte den Code aus der Authenticator-App in Firefox nicht ein: Die Code-Felder heißen jetzt `totp` statt `code`. Firefox gibt `autocomplete="one-time-code"` nicht an Erweiterungen weiter, Bitwarden sucht dann nach Stichwörtern wie `totp` (Lösung aus dem Familien-Server, dort `docs/erkenntnis-bitwarden-einmalcode.md`).

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

- Auslieferung: Zielpfade gelten vom FTP-Login aus (der Zugang landet schon eine Ebene tiefer); der erste Lauf war mit curl 9 abgebrochen, ohne etwas zu übertragen.
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
