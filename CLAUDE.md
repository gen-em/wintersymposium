# Wintersymposium – Hinweise für Claude

Verbindliche Quelle ist [`docs/konzept.md`](docs/konzept.md) (Entscheidungen E-xx, Regeln R-xx, offene Punkte O-xx, Arbeitspakete AP-x). Prüfungen stehen in [`docs/pruefprotokoll.md`](docs/pruefprotokoll.md).

## Zusammenarbeit mit dem Admin

- Deutsch, klar und direkt; Probleme und Missverständnisse ansprechen. Bei Unklarheiten nachfragen statt annehmen.
- Vor jedem Arbeitspaket den Umfang zusammenfassen und bestätigen lassen; offene Punkte des AP vorher klären.
- Anleitungen in nummerierten Schritten; nach einem Schritt, dessen Ergebnis die nächsten bestimmt, anhalten und auf die Rückmeldung warten.
- Der Admin arbeitet mit GitHub Desktop (kein git auf der Kommandozeile).

## Technik

- Aufbau, Einrichtung, Auslieferung und Tests: [`docs/betrieb.md`](docs/betrieb.md). Webroot ist `server/public/`.
- Gestaltung: freigegebene Mockups in [`docs/mockups/`](docs/mockups/index.html) (Variante A, E-36) sind Vorlage für jede neue Seite; CSS in `server/public/assets/site.css`.
- Vor jedem PR: `php tools/tests/lauf.php` mit Testdatenbank (Umgebungsvariablen `WS_TEST_DB_*`); neue Funktionen bekommen Tests dort.

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
