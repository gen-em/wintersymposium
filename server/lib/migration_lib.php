<?php
declare(strict_types=1);

/*
 * Migrationskatalog (Muster aus NAdoku migration_lib.php, stark verkleinert).
 *
 * Jeder Schritt hat eine fortlaufende Nummer und wird genau einmal
 * angewendet; die Tabelle schema_migration merkt sich, welche gelaufen sind.
 * Schritte werden nie nachträglich geändert, nur neue angehängt.
 *
 * Ausführung: beim Installieren und automatisch bei der ersten Anfrage nach
 * einer Auslieferung (migration_bei_bedarf), geschützt durch eine
 * MySQL-Sperre, damit zwei Anfragen nicht gleichzeitig migrieren.
 */

function migration_katalog(): array
{
    return [
        1 => [
            'beschreibung' => 'Grundgerüst: Einstellungen, Hosts, Team-Konten, Audit, Ratenschutz, Jobs',
            'sql' => [
                "CREATE TABLE einstellung (
                    schluessel   VARCHAR(64)  NOT NULL PRIMARY KEY,
                    wert         TEXT         NULL,
                    geaendert_am DATETIME     NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",

                "CREATE TABLE host (
                    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name        VARCHAR(253) NOT NULL,
                    haupt       TINYINT(1)   NOT NULL DEFAULT 0,
                    aktiv       TINYINT(1)   NOT NULL DEFAULT 1,
                    erstellt_am DATETIME     NOT NULL,
                    UNIQUE KEY uq_host_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",

                "CREATE TABLE team_konto (
                    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    email         VARCHAR(254) NOT NULL,
                    name          VARCHAR(120) NOT NULL,
                    passwort_hash VARCHAR(255) NOT NULL,
                    rolle         ENUM('admin','betreiber') NOT NULL,
                    aktiv         TINYINT(1)   NOT NULL DEFAULT 1,
                    erstellt_am   DATETIME     NOT NULL,
                    geaendert_am  DATETIME     NOT NULL,
                    UNIQUE KEY uq_team_konto_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",

                "CREATE TABLE audit (
                    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    zeit     DATETIME     NOT NULL,
                    konto_id INT UNSIGNED NULL,
                    art      VARCHAR(64)  NOT NULL,
                    text     TEXT         NOT NULL,
                    KEY ix_audit_zeit (zeit),
                    KEY ix_audit_art (art)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",

                "CREATE TABLE rate_limit (
                    topf          VARCHAR(32)  NOT NULL,
                    merkmal       CHAR(64)     NOT NULL,
                    fenster_start DATETIME     NOT NULL,
                    fehlversuche  INT UNSIGNED NOT NULL DEFAULT 0,
                    gesperrt_bis  DATETIME     NULL,
                    PRIMARY KEY (topf, merkmal),
                    KEY ix_rate_limit_fenster (fenster_start)
                ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin",

                "CREATE TABLE job (
                    name            VARCHAR(64)  NOT NULL PRIMARY KEY,
                    letzter_lauf    DATETIME     NULL,
                    letzter_erfolg  DATETIME     NULL,
                    letzte_dauer_ms INT UNSIGNED NULL,
                    letzter_fehler  TEXT         NULL,
                    laeufe          INT UNSIGNED NOT NULL DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            ],
        ],
        2 => [
            'beschreibung' => 'Pflegbare Texte der öffentlichen Seiten (Startseite, Impressum, Datenschutz)',
            'sql' => [
                "CREATE TABLE inhalt (
                    schluessel    VARCHAR(64)  NOT NULL PRIMARY KEY,
                    text          MEDIUMTEXT   NOT NULL,
                    geaendert_am  DATETIME     NOT NULL,
                    geaendert_von INT UNSIGNED NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                // Startwerte aus den freigegebenen Mockups; im Betreiber-Bereich änderbar (AP-5).
                "INSERT INTO inhalt (schluessel, text, geaendert_am) VALUES
                    ('start_kicker', 'Allgäu Akut · Wintersymposium', UTC_TIMESTAMP()),
                    ('start_titel', 'Wintersymposium *Notfallmedizin*', UTC_TIMESTAMP()),
                    ('start_unter', 'Ein Tag für Notaufnahme und Rettungsdienst. Vorträge, Workshops, ein Fall. Keine Ausreden.', UTC_TIMESTAMP()),
                    ('start_sticker', 'Kostenlos', UTC_TIMESTAMP()),
                    ('start_infos_titel', 'Für alle, die nachts den Piepser tragen', UTC_TIMESTAMP()),
                    ('start_infos', '## Für wen\nNotärztInnen, NotfallsanitäterInnen, RettungssanitäterInnen, Pflege und MFA aus der Notaufnahme, StudentInnen. Alle Bereiche, alle Berufsgruppen.\n## Wie\nVormittags Vorträge, dazwischen und nachmittags Workshops in kleinen Gruppen und offene Stationen ohne Anmeldung.\n## Was es kostet\nNichts. Mittagessen inklusive (Fleisch oder vegetarisch). Kaffee gibt''s. Ausreden nicht.', UTC_TIMESTAMP()),
                    ('start_cme', 'Die Veranstaltung ist zur Zertifizierung eingereicht. Trag bei der Anmeldung deine EFN ein, dann melden wir deine Teilnahme. Die Teilnahmebescheinigung lädst du nach dem Symposium hier herunter.', UTC_TIMESTAMP()),
                    ('impressum', '', UTC_TIMESTAMP()),
                    ('datenschutz', '', UTC_TIMESTAMP())",
            ],
        ],
        3 => [
            'beschreibung' => 'Startseite: Untertitel und Infokästen für die Abendveranstaltung überarbeitet',
            'sql' => [
                // Nur ändern, solange der Startwert noch unverändert ist.
                "UPDATE inhalt SET text = 'Für alle, die da sind, wenn andere frei haben', geaendert_am = UTC_TIMESTAMP()
                 WHERE schluessel = 'start_infos_titel' AND text = 'Für alle, die nachts den Piepser tragen'",
                "UPDATE inhalt SET text = 'Ein Abend für Rettungsdienst, Notaufnahme und Krankenhaus. Vorträge, Workshops, ein Fall. Keine Ausreden.', geaendert_am = UTC_TIMESTAMP()
                 WHERE schluessel = 'start_unter' AND text = 'Ein Tag für Notaufnahme und Rettungsdienst. Vorträge, Workshops, ein Fall. Keine Ausreden.'",
                "UPDATE inhalt SET text = REPLACE(text, 'NotärztInnen, NotfallsanitäterInnen, RettungssanitäterInnen, Pflege und MFA aus der Notaufnahme, StudentInnen. Alle Bereiche, alle Berufsgruppen.', 'Pflegende, NotfallsanitäterInnen, MFA, ÄrztInnen, RettungssanitäterInnen, Studierende und alle, die sonst interessiert sind. Alle Bereiche, alle Berufsgruppen.'), geaendert_am = UTC_TIMESTAMP()
                 WHERE schluessel = 'start_infos' AND text LIKE '%NotärztInnen, NotfallsanitäterInnen, RettungssanitäterInnen, Pflege und MFA aus der Notaufnahme, StudentInnen. Alle Bereiche, alle Berufsgruppen.%'",
                "UPDATE inhalt SET text = REPLACE(text, 'Vormittags Vorträge, dazwischen und nachmittags Workshops in kleinen Gruppen und offene Stationen ohne Anmeldung.', 'Erst Input, dann Hände dran: Nach den Vorträgen gibt''s Bier, Snacks und Workshops in kleinen Gruppen. Dazwischen übst du ganz entspannt an offenen Stationen.'), geaendert_am = UTC_TIMESTAMP()
                 WHERE schluessel = 'start_infos' AND text LIKE '%Vormittags Vorträge, dazwischen und nachmittags Workshops in kleinen Gruppen und offene Stationen ohne Anmeldung.%'",
                "UPDATE inhalt SET text = REPLACE(text, 'Nichts. Mittagessen inklusive (Fleisch oder vegetarisch). Kaffee gibt''s. Ausreden nicht.', 'Natürlich nichts. Dazu gibt''s Snacks, Bier und Alkoholfreies. Nur Ausreden gibt''s nicht.'), geaendert_am = UTC_TIMESTAMP()
                 WHERE schluessel = 'start_infos' AND text LIKE '%Nichts. Mittagessen inklusive (Fleisch oder vegetarisch).%'",
            ],
        ],
        4 => [
            'beschreibung' => 'Eine Konten-Tabelle mit Rollen (E-42/O-28), zweiter Faktor, Einrichtungslinks',
            'sql' => [
                // Teilnehmende (AP-4b), Admin und Betreiber in einer Tabelle.
                // Zweiter Faktor: Geheimnis versiegelt (krypto_lib), eingeschaltet erst mit totp_seit.
                // sitzung_version steigt bei Passwortwechsel, Sperre und Rollenwechsel: alte Sitzungen enden.
                "CREATE TABLE konto (
                    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    email            VARCHAR(254) NOT NULL,
                    name             VARCHAR(120) NOT NULL,
                    passwort_hash    VARCHAR(255) NULL,
                    rolle            ENUM('teilnehmend','admin','betreiber') NOT NULL DEFAULT 'teilnehmend',
                    aktiv            TINYINT(1)   NOT NULL DEFAULT 1,
                    totp_geheimnis   VARCHAR(255) NULL,
                    totp_seit        DATETIME     NULL,
                    totp_schritt     BIGINT       NULL,
                    sitzung_version  INT UNSIGNED NOT NULL DEFAULT 1,
                    letzte_anmeldung DATETIME     NULL,
                    erstellt_am      DATETIME     NOT NULL,
                    geaendert_am     DATETIME     NOT NULL,
                    UNIQUE KEY uq_konto_email (email),
                    KEY ix_konto_rolle (rolle)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                // Bisherige Team-Konten mit gleicher id übernehmen (audit.konto_id bleibt gültig).
                "INSERT INTO konto (id, email, name, passwort_hash, rolle, aktiv, erstellt_am, geaendert_am)
                 SELECT id, email, name, passwort_hash, rolle, aktiv, erstellt_am, geaendert_am FROM team_konto",
                "DROP TABLE team_konto",
                "CREATE TABLE konto_code (
                    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    konto_id    INT UNSIGNED NOT NULL,
                    hash        VARCHAR(255) NOT NULL,
                    benutzt_am  DATETIME     NULL,
                    KEY ix_konto_code_konto (konto_id),
                    CONSTRAINT fk_konto_code_konto FOREIGN KEY (konto_id) REFERENCES konto (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                // Einrichtungs- bzw. Passwort-Links: nur der Hash des Tokens (R-02).
                "CREATE TABLE konto_link (
                    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    konto_id    INT UNSIGNED NOT NULL,
                    token_hash  CHAR(64)     NOT NULL,
                    zweck       VARCHAR(16)  NOT NULL,
                    gueltig_bis DATETIME     NOT NULL,
                    erstellt_am DATETIME     NOT NULL,
                    UNIQUE KEY uq_konto_link_token (token_hash),
                    KEY ix_konto_link_konto (konto_id),
                    CONSTRAINT fk_konto_link_konto FOREIGN KEY (konto_id) REFERENCES konto (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            ],
        ],
        5 => [
            'beschreibung' => 'Programm: Programmpunkte, Workshop-Termine, Dozierende, veröffentlichter Stand (E-39)',
            'sql' => [
                // Arbeitsstand des Programms. Die Startseite zeigt nur den veröffentlichten Stand (programm_stand).
                "CREATE TABLE programmpunkt (
                    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    typ          ENUM('vortrag','fall','workshop','station','block','pause') NOT NULL,
                    kuerzel      VARCHAR(12)  NOT NULL DEFAULT '',
                    titel        VARCHAR(160) NOT NULL,
                    untertitel   VARCHAR(200) NOT NULL DEFAULT '',
                    beschreibung TEXT         NOT NULL,
                    ort          VARCHAR(120) NOT NULL DEFAULT '',
                    beginn       TIME         NULL,
                    ende         TIME         NULL,
                    bring        VARCHAR(160) NOT NULL DEFAULT '',
                    notiz        VARCHAR(300) NOT NULL DEFAULT '',
                    erstellt_am  DATETIME     NOT NULL,
                    geaendert_am DATETIME     NOT NULL,
                    KEY ix_programmpunkt_beginn (beginn)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                "CREATE TABLE workshop_termin (
                    id       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    punkt_id INT UNSIGNED NOT NULL,
                    beginn   TIME         NOT NULL,
                    ende     TIME         NOT NULL,
                    plaetze  INT UNSIGNED NOT NULL,
                    KEY ix_workshop_termin_punkt (punkt_id),
                    CONSTRAINT fk_workshop_termin_punkt FOREIGN KEY (punkt_id) REFERENCES programmpunkt (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                "CREATE TABLE dozent (
                    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name         VARCHAR(120) NOT NULL,
                    funktion     VARCHAR(160) NOT NULL DEFAULT '',
                    kurzbio      TEXT         NOT NULL,
                    foto         VARCHAR(64)  NULL,
                    erstellt_am  DATETIME     NOT NULL,
                    geaendert_am DATETIME     NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                "CREATE TABLE punkt_dozent (
                    punkt_id  INT UNSIGNED NOT NULL,
                    dozent_id INT UNSIGNED NOT NULL,
                    pos       SMALLINT     NOT NULL DEFAULT 0,
                    PRIMARY KEY (punkt_id, dozent_id),
                    CONSTRAINT fk_punkt_dozent_punkt FOREIGN KEY (punkt_id) REFERENCES programmpunkt (id) ON DELETE CASCADE,
                    CONSTRAINT fk_punkt_dozent_dozent FOREIGN KEY (dozent_id) REFERENCES dozent (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                // Veröffentlichter Stand als JSON (eine Zeile); die Startseite liest nur hier.
                "CREATE TABLE programm_stand (
                    id                INT UNSIGNED NOT NULL PRIMARY KEY,
                    daten             MEDIUMTEXT   NOT NULL,
                    veroeffentlicht_am DATETIME    NOT NULL,
                    veroeffentlicht_von INT UNSIGNED NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            ],
        ],
        6 => [
            'beschreibung' => 'Anmeldung: Anmeldungen, Plätze und Wartelisten, Einmal-Links, Mail-Warteschlange (AP-4)',
            'sql' => [
                // Eine Anmeldung je Adresse. Storno und verfallene Bestätigung löschen die Zeile (R-02).
                "CREATE TABLE anmeldung (
                    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    vorname         VARCHAR(80)  NOT NULL,
                    nachname        VARCHAR(80)  NOT NULL,
                    email           VARCHAR(190) NOT NULL,
                    berufsgruppe    VARCHAR(60)  NOT NULL,
                    beruf_sonstige  VARCHAR(80)  NOT NULL DEFAULT '',
                    institution     VARCHAR(160) NOT NULL,
                    efn             CHAR(15)     NULL,
                    essen           ENUM('egal','vegetarisch','kein') NOT NULL,
                    newsletter      TINYINT(1)   NOT NULL DEFAULT 0,
                    status          ENUM('unbestaetigt','bestaetigt') NOT NULL,
                    bestaetigen_bis DATETIME     NULL,
                    einwilligung_am DATETIME     NOT NULL,
                    sitzung_version INT UNSIGNED NOT NULL DEFAULT 1,
                    erstellt_am     DATETIME     NOT NULL,
                    bestaetigt_am   DATETIME     NULL,
                    geaendert_am    DATETIME     NOT NULL,
                    UNIQUE KEY uq_anmeldung_email (email),
                    KEY ix_anmeldung_status (status, bestaetigen_bis)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                // Plätze: termin_id 0 = Platz beim Symposium, sonst ein Workshop-Termin aus dem
                // veröffentlichten Programm (ohne Fremdschlüssel, weil „Verwerfen“ die Termine neu anlegt).
                // fest = Platz, warte = Warteliste (Reihenfolge nach seit, id), angebot = Nachrück-Angebot (E-19).
                "CREATE TABLE platz (
                    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    anmeldung_id INT UNSIGNED NOT NULL,
                    termin_id    INT UNSIGNED NOT NULL DEFAULT 0,
                    workshop_id  INT UNSIGNED NOT NULL DEFAULT 0,
                    status       ENUM('fest','warte','angebot') NOT NULL,
                    seit         DATETIME     NOT NULL,
                    angebot_bis  DATETIME     NULL,
                    UNIQUE KEY uq_platz_anmeldung_termin (anmeldung_id, termin_id),
                    KEY ix_platz_termin (termin_id, status, seit, id),
                    KEY ix_platz_angebot (status, angebot_bis),
                    CONSTRAINT fk_platz_anmeldung FOREIGN KEY (anmeldung_id) REFERENCES anmeldung (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                // Einmal-Links: nur der Hash (R-02); beim Benutzen gelöscht.
                "CREATE TABLE anmeldung_link (
                    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    anmeldung_id INT UNSIGNED NOT NULL,
                    token_hash   CHAR(64)     NOT NULL,
                    zweck        ENUM('bestaetigen','zugang','email') NOT NULL,
                    neue_email   VARCHAR(190) NULL,
                    gueltig_bis  DATETIME     NOT NULL,
                    erstellt_am  DATETIME     NOT NULL,
                    UNIQUE KEY uq_anmeldung_link_token (token_hash),
                    KEY ix_anmeldung_link_anmeldung (anmeldung_id),
                    KEY ix_anmeldung_link_gueltig (gueltig_bis),
                    CONSTRAINT fk_anmeldung_link_anmeldung FOREIGN KEY (anmeldung_id) REFERENCES anmeldung (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                // Warteschlange: nach dem Versand werden an, betreff, text geleert (R-02).
                "CREATE TABLE mail_ausgang (
                    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    an                VARCHAR(190) NOT NULL,
                    betreff           VARCHAR(250) NOT NULL,
                    text              MEDIUMTEXT   NOT NULL,
                    prio              TINYINT UNSIGNED NOT NULL,
                    status            ENUM('offen','sendet','gesendet','fehler') NOT NULL,
                    versuche          TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    naechster_versuch DATETIME     NOT NULL,
                    erstellt_am       DATETIME     NOT NULL,
                    gesendet_am       DATETIME     NULL,
                    fehler            VARCHAR(250) NULL,
                    KEY ix_mail_ausgang_faellig (status, prio, naechster_versuch),
                    KEY ix_mail_ausgang_gesendet (gesendet_am)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            ],
        ],
        7 => [
            'beschreibung' => 'Konten für Teilnehmende: Bestätigung, Profil, Verknüpfung mit der Anmeldung (AP-4b, E-30)',
            'sql' => [
                // Profil der Teilnehmenden; Team-Konten gelten als bestätigt (vom Betreiber angelegt).
                "ALTER TABLE konto
                    ADD COLUMN email_bestaetigt_am DATETIME NULL AFTER email,
                    ADD COLUMN berufsgruppe   VARCHAR(60)  NOT NULL DEFAULT '' AFTER rolle,
                    ADD COLUMN beruf_sonstige VARCHAR(80)  NOT NULL DEFAULT '' AFTER berufsgruppe,
                    ADD COLUMN institution    VARCHAR(160) NOT NULL DEFAULT '' AFTER beruf_sonstige,
                    ADD COLUMN efn            CHAR(15)     NULL AFTER institution",
                "UPDATE konto SET email_bestaetigt_am = erstellt_am",
                // Links für Teilnehmenden-Konten: Adresse bestätigen, Passwort, neue Adresse
                "ALTER TABLE konto_link ADD COLUMN neue_email VARCHAR(254) NULL AFTER zweck",
                // Anmeldung ↔ Konto (Konto löschen: Verknüpfung fällt weg, Anmeldung bleibt)
                "ALTER TABLE anmeldung
                    ADD COLUMN konto_id    INT UNSIGNED NULL AFTER id,
                    ADD COLUMN konto_wunsch TINYINT(1)  NOT NULL DEFAULT 0 AFTER newsletter,
                    ADD UNIQUE KEY uq_anmeldung_konto (konto_id),
                    ADD CONSTRAINT fk_anmeldung_konto FOREIGN KEY (konto_id) REFERENCES konto (id) ON DELETE SET NULL",
            ],
        ],
        8 => [
            'beschreibung' => 'Mailings: Postfächer, HTML-Mails, Rundmails mit Bildern, Archiv (AP-6, E-50)',
            'sql' => [
                // Postfach je Mail (eigenes Tageslimit); Rundmails werden erst beim Versand aus
                // rundmail + anmeldung zusammengesetzt (aktueller Name, frischer Einmal-Link).
                "ALTER TABLE mail_ausgang
                    ADD COLUMN postfach     ENUM('system','newsletter') NOT NULL DEFAULT 'system' AFTER prio,
                    ADD COLUMN rundmail_id  INT UNSIGNED NULL AFTER postfach,
                    ADD COLUMN anmeldung_id INT UNSIGNED NULL AFTER rundmail_id,
                    ADD KEY ix_mail_ausgang_rundmail (rundmail_id, status)",
                // Mailings: Entwurf → geplant → versand → gesendet (oder abgebrochen)
                "CREATE TABLE rundmail (
                    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    betreff         VARCHAR(200) NOT NULL DEFAULT '',
                    html            MEDIUMTEXT   NOT NULL,
                    auswahl         VARCHAR(40)  NOT NULL DEFAULT 'angemeldet',
                    berufsgruppe    VARCHAR(60)  NOT NULL DEFAULT '',
                    postfach        ENUM('system','newsletter') NOT NULL DEFAULT 'system',
                    status          ENUM('entwurf','geplant','versand','gesendet','abgebrochen') NOT NULL DEFAULT 'entwurf',
                    geplant_fuer    DATETIME     NULL,
                    auswahl_text    VARCHAR(200) NOT NULL DEFAULT '',
                    empfaenger_zahl INT UNSIGNED NOT NULL DEFAULT 0,
                    loeschen_ab     DATE         NULL,
                    erstellt_von    INT UNSIGNED NULL,
                    gesendet_von    INT UNSIGNED NULL,
                    erstellt_am     DATETIME     NOT NULL,
                    geaendert_am    DATETIME     NOT NULL,
                    gesendet_am     DATETIME     NULL,
                    KEY ix_rundmail_status (status, geplant_fuer)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                // Archiv: Empfängerliste je Rundmail (R-02-Ausnahme, E-42); gelöscht ab loeschen_ab (E-24)
                "CREATE TABLE rundmail_empfaenger (
                    rundmail_id  INT UNSIGNED NOT NULL,
                    anmeldung_id INT UNSIGNED NULL,
                    name         VARCHAR(170) NOT NULL,
                    email        VARCHAR(190) NOT NULL,
                    PRIMARY KEY (rundmail_id, email),
                    CONSTRAINT fk_rundmail_empfaenger FOREIGN KEY (rundmail_id) REFERENCES rundmail (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                // Bilder für Mailings: selbst gehostet, verkleinert, als Datei in var/bilder/ (mit gesichert)
                "CREATE TABLE rundmail_bild (
                    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    datei       VARCHAR(80)  NOT NULL,
                    typ         ENUM('jpeg','png') NOT NULL,
                    breite      SMALLINT UNSIGNED NOT NULL,
                    hoehe       SMALLINT UNSIGNED NOT NULL,
                    erstellt_von INT UNSIGNED NULL,
                    erstellt_am DATETIME     NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            ],
        ],
        9 => [
            'beschreibung' => 'Rückmeldung 2026-10-30: Block-Zuordnung im Programm, Protokoll ausblenden, zweiter Faktor per E-Mail-Code',
            'sql' => [
                "ALTER TABLE programmpunkt ADD COLUMN block_id INT UNSIGNED NULL AFTER typ",
                "ALTER TABLE audit ADD COLUMN ausgeblendet_von INT UNSIGNED NULL, ADD COLUMN ausgeblendet_am DATETIME NULL",
                // Zweiter Faktor per E-Mail-Code, frei wählbar (Admin 2026-10-30); Code nur als Hash, 10 min, einmal
                "ALTER TABLE konto ADD COLUMN zweitfaktor_mail TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN mailcode_hash VARCHAR(255) NULL, ADD COLUMN mailcode_bis DATETIME NULL",
            ],
        ],
        10 => [
            'beschreibung' => 'Newsletter: Listen, Abonnierende mit Double-Opt-In, Links, Versand an Listen (AP-7, E-52)',
            'sql' => [
                "CREATE TABLE newsletter_liste (
                    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name            VARCHAR(80)  NOT NULL,
                    beschreibung    VARCHAR(300) NOT NULL DEFAULT '',
                    absender_name   VARCHAR(80)  NOT NULL DEFAULT '',
                    sichtbar        TINYINT(1)   NOT NULL DEFAULT 1,
                    bei_anmeldung   TINYINT(1)   NOT NULL DEFAULT 0,
                    pos             INT          NOT NULL DEFAULT 0,
                    abmeldungen     INT UNSIGNED NOT NULL DEFAULT 0,
                    erstellt_am     DATETIME     NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                "INSERT INTO newsletter_liste (name, beschreibung, absender_name, sichtbar, bei_anmeldung, pos, erstellt_am) VALUES
                    ('Wintersymposium', 'Neuigkeiten zum Wintersymposium: Termin, Programm, Anmeldestart.', 'Wintersymposium Notfallmedizin', 1, 0, 1, UTC_TIMESTAMP()),
                    ('Allgäu Akut', 'Termine der nächsten Fortbildungen von Allgäu Akut.', 'Allgäu Akut', 1, 1, 2, UTC_TIMESTAMP())",
                // Eine Person je Adresse; Vorname freiwillig (Anrede)
                "CREATE TABLE newsletter_person (
                    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    email       VARCHAR(190) NOT NULL,
                    vorname     VARCHAR(80)  NOT NULL DEFAULT '',
                    erstellt_am DATETIME     NOT NULL,
                    UNIQUE KEY uq_newsletter_person_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                // Abo je Liste: offen (wartet auf Bestätigung) oder bestätigt; Nachweis: Zeitpunkt und Fassung des Einwilligungstexts
                "CREATE TABLE newsletter_abo (
                    person_id     INT UNSIGNED NOT NULL,
                    liste_id      INT UNSIGNED NOT NULL,
                    status        ENUM('offen','bestaetigt') NOT NULL,
                    quelle        ENUM('formular','anmeldung','konto','team','verwalten') NOT NULL,
                    angefragt_am  DATETIME     NOT NULL,
                    bestaetigt_am DATETIME     NULL,
                    fassung       CHAR(12)     NULL,
                    PRIMARY KEY (person_id, liste_id),
                    KEY ix_newsletter_abo_liste (liste_id, status),
                    CONSTRAINT fk_newsletter_abo_person FOREIGN KEY (person_id) REFERENCES newsletter_person (id) ON DELETE CASCADE,
                    CONSTRAINT fk_newsletter_abo_liste FOREIGN KEY (liste_id) REFERENCES newsletter_liste (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                // Wortlaut der Einwilligung je Fassung (Nachweis, was zugestimmt wurde)
                "CREATE TABLE newsletter_fassung (
                    fassung     CHAR(12)     NOT NULL PRIMARY KEY,
                    text        TEXT         NOT NULL,
                    erstellt_am DATETIME     NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                // Links: bestaetigen (7 Tage) und verwalten/abmelden (aus jeder Newsletter-Mail, 400 Tage); nur Hash
                "CREATE TABLE newsletter_link (
                    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    person_id   INT UNSIGNED NOT NULL,
                    token_hash  CHAR(64)     NOT NULL,
                    zweck       ENUM('bestaetigen','verwalten') NOT NULL,
                    gueltig_bis DATETIME     NOT NULL,
                    UNIQUE KEY uq_newsletter_link_token (token_hash),
                    KEY ix_newsletter_link_gueltig (gueltig_bis),
                    CONSTRAINT fk_newsletter_link_person FOREIGN KEY (person_id) REFERENCES newsletter_person (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                // Mailings an Newsletter-Listen: Empfänger ist eine newsletter_person statt einer Anmeldung
                "ALTER TABLE mail_ausgang ADD COLUMN nl_person_id INT UNSIGNED NULL AFTER anmeldung_id",
                "ALTER TABLE rundmail_empfaenger ADD COLUMN nl_person_id INT UNSIGNED NULL AFTER anmeldung_id",
            ],
        ],
    ];
}

function migration_tabelle_anlegen(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS schema_migration (
            nr            INT UNSIGNED NOT NULL PRIMARY KEY,
            beschreibung  VARCHAR(255) NOT NULL,
            angewendet_am DATETIME     NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
    );
}

/** Höchste Nummer im Katalog. */
function migration_soll(): int
{
    return max(array_keys(migration_katalog()));
}

/** Höchste angewendete Nummer (0 ohne Tabelle). */
function migration_ist(): int
{
    try {
        return (int)(db_wert('SELECT MAX(nr) FROM schema_migration') ?? 0);
    } catch (PDOException $ex) {
        if ($ex->getCode() === '42S02') { // Tabelle fehlt: noch nichts angewendet
            return 0;
        }
        throw $ex;
    }
}

/**
 * Alle ausstehenden Schritte anwenden. Gibt die Nummern der angewendeten
 * Schritte zurück. DDL schreibt in MySQL sofort fest; ein Fehler mitten in
 * einem Schritt bricht ab, der Schritt gilt dann als nicht angewendet.
 */
function migration_anwenden(): array
{
    $pdo = db();
    if ((int)db_wert("SELECT GET_LOCK('ws_migration', 30)") !== 1) {
        throw new RuntimeException('Migrationssperre nicht erhalten.');
    }
    try {
        migration_tabelle_anlegen();
        $erledigt = array_map('intval', array_column(db_zeilen('SELECT nr FROM schema_migration'), 'nr'));
        $neu = [];
        foreach (migration_katalog() as $nr => $schritt) {
            if (in_array($nr, $erledigt, true)) {
                continue;
            }
            foreach ($schritt['sql'] as $sql) {
                $pdo->exec($sql);
            }
            db_ausfuehren(
                'INSERT INTO schema_migration (nr, beschreibung, angewendet_am) VALUES (?, ?, ?)',
                [$nr, $schritt['beschreibung'], db_jetzt()]
            );
            $neu[] = $nr;
        }
        return $neu;
    } finally {
        db_wert("SELECT RELEASE_LOCK('ws_migration')");
    }
}

/** Nach einer Auslieferung: fehlende Schritte nachziehen (nur wenn installiert). */
function migration_bei_bedarf(): void
{
    if (migration_ist() < migration_soll()) {
        $neu = migration_anwenden();
        if ($neu !== []) {
            audit_schreiben('migration', 'Migrationen angewendet: ' . implode(', ', $neu));
        }
    }
}
