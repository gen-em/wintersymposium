# Wintersymposium – Hinweise für Claude

Verbindliche Quelle ist [`docs/konzept.md`](docs/konzept.md) (Entscheidungen E-xx, Regeln R-xx, offene Punkte O-xx, Arbeitspakete AP-x). Prüfungen stehen in [`docs/pruefprotokoll.md`](docs/pruefprotokoll.md).

## Zusammenarbeit mit dem Admin

- Deutsch, klar und direkt; Probleme und Missverständnisse ansprechen. Bei Unklarheiten nachfragen statt annehmen.
- Vor jedem Arbeitspaket den Umfang zusammenfassen und bestätigen lassen; offene Punkte des AP vorher klären.
- Anleitungen in nummerierten Schritten; nach einem Schritt, dessen Ergebnis die nächsten bestimmt, anhalten und auf die Rückmeldung warten.
- Der Admin arbeitet mit GitHub Desktop (kein git auf der Kommandozeile).

## Technik

- Aufbau, Einrichtung, Auslieferung und Tests: [`docs/betrieb.md`](docs/betrieb.md). Webroot ist `server/public/`.
- **Code-Konventionen:** Konzept Abschnitt 5a (K-a bis K-h: Rückgaben, `$durch`, Zeit, keine `db_*` in Seiten, Zustand, Wortliste, Typografie, Präfix-Tabelle). Grep-Tests dazu in `tools/tests/konventionen.php` (läuft in `lauf.php`); Ausnahmen nur mit Begründung.
- Gestaltung: `server/public/assets/site.css` ist die alleinige Vorlage (E-64); die Vorlage zum Ansehen ist das **Komponentenblatt** unter Betrieb → Komponenten (`/team/komponenten`, nur BetreiberInnen): jede Komponente mit Varianten und dem Aufruf darunter. Neue Seiten bauen sich aus den Helfern in `server/lib/formular_lib.php` (`feld`, `profil_felder_html`, `aktion_knopf`, `bestaetigung_html`, `kachel`, `chip`, `filter_leiste`, `filter_chips`, `leer_hinweis`, `seiten_kopfzeile`, `ist_post`/`post_*`/`fertig`) und vorhandenen Klassen; eine neue Klasse nur mit Eintrag im Komponentenblatt. site.css ist nach Komponenten gegliedert (Inhaltsverzeichnis oben): neue Regeln an die passende Stelle, nie ans Ende; Farben und Maße nur über die Tokens in `:root`; Umbruchpunkte 760 und 1000 px. Der Test in `tools/tests/konventionen.php` prüft, dass jede Klasse in CSS und Markup zusammenpasst. Die Mockups in [`docs/mockups/`](docs/mockups/index.html) (E-36) sind Archiv.
- Vor jedem PR: `php tools/tests/lauf.php` mit Testdatenbank (Umgebungsvariablen `WS_TEST_DB_*`); neue Funktionen bekommen Tests dort.
- **Test-Beispieldatensatz** `server/testdaten/beispieldaten.php` (Admin 2026-10-29): Daten, die für eine Prüfung angelegt werden (Programm, später Anmeldungen, Warteliste …), dort als eigene Funktion ablegen statt nur im Test, damit der Datensatz mit jedem AP wächst. Nur erfundene Namen und `example.org`-Adressen.
- **Browser-Prüfung vor jedem PR (R-12):** Funktionen in Chromium, Firefox und WebKit (Safari-Engine) auf Handy (390 px), Tablet (768 px), schmalem (1024 px) und breitem Desktop (1440 px) prüfen – Darstellung, Klickwege, Auswahlfelder, Aufklapp-Elemente, Popups, Konsolenfehler. **Gestuft (Admin 2026-10-31, E-62):** Stufe 1 bei jeder Änderung `lauf.php`; Stufe 2 bei Oberflächen-Änderungen an einzelnen Seiten ein gezielter Lauf `WS_STUFE=2 WS_SEITEN=<seiten>` (Chromium, Handy und breiter Desktop, ca. 1 Min.); Stufe 3, der volle Lauf (3 Engines × 4 Breiten), bei Ende eines Arbeitspakets (zweite Stelle der Version steigt), bei gemeinsamen Teilen (Kopfleiste, Navigation, Knöpfe, Meldungen, Formulare, Grundlagen im CSS, JavaScript-Dateien, Login/Sitzung), vor einem Release auf Produktion und spätestens nach 5 PRs ohne vollen Lauf. Welche Stufe lief und warum, steht im PR und im Prüfprotokoll. Einrichtung je Sandbox `bash tools/browser/einrichten.sh`, Lauf `node tools/browser/durchlauf.js` (Testdatenbank wie oben). Neue Seiten und Abläufe dort ergänzen; Ergebnis mit Zahl in PR und Prüfprotokoll. Bildschirmfotos ansehen, nicht nur die Zahl. Der Admin soll möglichst wenig selbst testen müssen.

## Regeln

- **Öffentliches Repo (E-25, R-10):** keine Zugangsdaten, Kontonamen, IP-Adressen, Serverpfade oder Hosting-Interna in Code, Doku, Commits, PR-Texten oder Issues. Domain und öffentliche Absenderadressen (anmeldung@, newsletter@) dürfen genannt werden, Passwörter und Postfach-Logins nie. Umgebungsspezifisches nur in `config.php` (nie im Repo) und in GitHub-Secrets.
- Jede Änderung am Repo: Version (`VERSION`) hochstufen, `CHANGELOG.md`, betroffene Doku, Prüfprotokoll und Konzept aktualisieren (neue und entfernte Funktionen); Commit auf einen Arbeits-Branch und PR, der Admin mergt (E-26). Keine ZIP-Dateien.
- Im Konzept keine Versionsnummer festlegen; die legt die Umsetzung fest.
- Nach jedem Arbeitspaket: im Konzept `status`, `ergebnis` und `probleme` (Problem und Lösung) eintragen; im Prüfprotokoll, was geprüft wurde und was noch wie zu prüfen ist.
- Alles Veranstaltungsspezifische (Fristen, Limits, Kapazitäten) ist eine Einstellung in der Oberfläche, kein fester Wert im Code (R-11).
- Code aus `gen-em/einsatzdoku-luftrettung` (NAdoku) darf übernommen werden (gleicher Urheber, beide AGPL-3.0); welche Bausteine, steht in E-18.
- Erscheinungsbild: Farben, Schriften, Logo und Ton aus dem Branding-Repo Allgäu Akut (`chodid/zina-branding`); Lila als Hauptakzent, einzelne Seiten in Signalrot (E-23).
- Meldungen an fremde Projekte in Commit-Nachrichten, PR-Texten und Kommentaren nur als Text („Kaneo-Issue 123“), nie als `owner/repo#nummer` oder Link.
- **PRs verlinken:** Jeden erstellten oder geänderten PR im Chat mit vollständigem Link nennen (`https://github.com/gen-em/wintersymposium/pull/<nr>`), damit der Admin nicht suchen muss – auch nach jedem Nachschieben von Commits.
- **PRs überwachen:** jeden selbst erstellten PR bis zum Merge beobachten (`subscribe_pr_activity`); bei Konflikt mit `main` den Branch selbst aktualisieren (`main` hineinmergen, kein Rebase, kein Force-Push), Version und CHANGELOG einordnen, danach pushen.
