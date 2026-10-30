# Wintersymposium – Allgäu Akut

Webseite für das Wintersymposium der Fortbildungsreihe **ALLGÄU AKUT – Fortbildung Notfallmedizin**: Infos und Programm, Anmeldung mit Warteliste (Verwaltung über Einmal-Links oder ein optionales Konto), Team-Bereich mit Statistik und Verwaltung, Rundmails, Newsletter, Teilnahmezertifikate. Teamkommunikation läuft über das bestehende Kaneo von Gen-EM.

**Stand:** Grundgerüst (AP-1), Erscheinungsbild mit öffentlichen Seiten (AP-2) Team-Login mit zweitem Faktor, Veranstaltungs-Einstellungen und Programm-Pflege (AP-3) sowie Anmeldung mit Warteliste und Nachrücken (AP-4) Konten für Teilnehmende (AP-4b), der Team-Bereich (AP-5) und Mailings (AP-6) umgesetzt; Newsletter folgt. Gestaltungsvorlage: [`docs/mockups/`](docs/mockups/index.html). Version in [`VERSION`](VERSION), Änderungen in [`CHANGELOG.md`](CHANGELOG.md).

- Konzept (Entscheidungen E-xx, Regeln R-xx, Arbeitspakete AP-x, offene Punkte O-xx): [`docs/konzept.md`](docs/konzept.md)
- Prüfprotokoll (geplante und durchgeführte Prüfungen T-xxx): [`docs/pruefprotokoll.md`](docs/pruefprotokoll.md)
- Betrieb (Einrichtung, Auslieferung, Wartung, Tests): [`docs/betrieb.md`](docs/betrieb.md)
- Hinweise für Claude: [`CLAUDE.md`](CLAUDE.md)

Technik: PHP 8.3 / MySQL 8.4 ohne Framework auf einem Lima-City-Webspace; Bausteine aus [Gen-EM NAdoku](https://github.com/gen-em/einsatzdoku-luftrettung). Webroot ist `server/public/`. Auslieferung per GitHub Actions (FTPS): `main` → Staging, Tag `vX.Y.Z` → Produktion. Erscheinungsbild aus dem Branding-Repo Allgäu Akut.

Tests lokal: `php tools/tests/lauf.php` (mit Testdatenbank siehe [`docs/betrieb.md`](docs/betrieb.md#6-tests-lokal-und-im-prüf-workflow)).

Lizenz: [AGPL-3.0](LICENSE).
