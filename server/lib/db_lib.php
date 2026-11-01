<?php
declare(strict_types=1);

/*
 * Datenbank: eine PDO-Verbindung je Anfrage, echte Prepared Statements,
 * Sitzungszeitzone UTC (R-07).
 */

function db(bool $neu = false): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO && !$neu) {
        return $pdo;
    }
    // $neu: eigene Verbindung, z. B. in einem abgespaltenen Testprozess (Paralleltest)
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string)konfig('db.host', 'localhost'),
        (int)konfig('db.port', 3306),
        (string)konfig('db.name', '')
    );
    $pdo = new PDO($dsn, (string)konfig('db.user', ''), (string)konfig('db.pass', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,ONLY_FULL_GROUP_BY'");
    return $pdo;
}

/** Eine Abfrage mit Parametern ausführen. */
function db_ausfuehren(string $sql, array $param = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($param);
    return $st;
}

/** Erste Zeile oder null. */
function db_zeile(string $sql, array $param = []): ?array
{
    $z = db_ausfuehren($sql, $param)->fetch();
    return $z === false ? null : $z;
}

/** Alle Zeilen. */
function db_zeilen(string $sql, array $param = []): array
{
    return db_ausfuehren($sql, $param)->fetchAll();
}

/** Erster Wert der ersten Zeile oder null. */
function db_wert(string $sql, array $param = []): mixed
{
    $w = db_ausfuehren($sql, $param)->fetchColumn();
    return $w === false ? null : $w;
}

/** Aktuelle Zeit in UTC im DATETIME-Format der Datenbank. */
function db_jetzt(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Unix-Zeit als UTC-Text für die Datenbank (Gegenstück zu db_ts). */
function db_zeit(int $ts): string
{
    return gmdate('Y-m-d H:i:s', $ts);
}

/** UTC-Tag „Y-m-d“ einer Unix-Zeit (für DATE-Spalten und Vergleiche mit Tagen). */
function db_tag(int $ts): string
{
    return gmdate('Y-m-d', $ts);
}

/** UTC-Text aus der Datenbank als Unix-Zeit (Gegenstück zu db_zeit); nicht lesbar ergibt 0. */
function db_ts(string $utc): int
{
    return (int)strtotime($utc . ' UTC');
}

/** Erste Spalte der ersten Zeile als Zahl (z. B. SELECT COUNT(*)); keine Zeile ergibt 0. */
function db_zahl(string $sql, array $param = []): int
{
    return (int)db_wert($sql, $param);
}

/** Ob die Abfrage eine Zahl größer 0 liefert (SELECT COUNT(*) … oder SELECT 1 … LIMIT 1). */
function db_gibt_es(string $sql, array $param = []): bool
{
    return db_zahl($sql, $param) > 0;
}

/** Gibt es die Tabelle? */
function db_tabelle_da(string $tabelle): bool
{
    return db_gibt_es(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$tabelle]
    );
}

/**
 * Wert aus der Tabelle einstellung (Schlüssel/Wert), null wenn nicht gesetzt.
 * Einstellungen der Veranstaltung (VA_KATALOG) liegen seit E-58 je
 * Veranstaltung in va_einstellung und gelten für die Veranstaltung des
 * Kontexts (va_id()).
 */
function einstellung(string $schluessel): ?string
{
    if (einstellung_je_veranstaltung($schluessel)) {
        $w = db_wert('SELECT wert FROM va_einstellung WHERE veranstaltung_id = ? AND schluessel = ?', [va_id(), $schluessel]);
    } else {
        $w = db_wert('SELECT wert FROM einstellung WHERE schluessel = ?', [$schluessel]);
    }
    return $w === null ? null : (string)$w;
}

function einstellung_setzen(string $schluessel, ?string $wert): void
{
    if (einstellung_je_veranstaltung($schluessel)) {
        db_ausfuehren(
            'INSERT INTO va_einstellung (veranstaltung_id, schluessel, wert, geaendert_am) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE wert = VALUES(wert), geaendert_am = VALUES(geaendert_am)',
            [va_id(), $schluessel, $wert, db_jetzt()]
        );
        return;
    }
    db_ausfuehren(
        'INSERT INTO einstellung (schluessel, wert, geaendert_am) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE wert = VALUES(wert), geaendert_am = VALUES(geaendert_am)',
        [$schluessel, $wert, db_jetzt()]
    );
}

/** Gehört die Einstellung zur Veranstaltung (und gibt es die Tabelle dafür schon)? */
function einstellung_je_veranstaltung(string $schluessel): bool
{
    return defined('VA_KATALOG') && isset(VA_KATALOG[$schluessel]) && va_tabellen_da();
}
