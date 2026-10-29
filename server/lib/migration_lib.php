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
