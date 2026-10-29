<?php
declare(strict_types=1);

/*
 * Datenbank: eine PDO-Verbindung je Anfrage, echte Prepared Statements,
 * Sitzungszeitzone UTC (R-07).
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
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

/** Gibt es die Tabelle? */
function db_tabelle_da(string $tabelle): bool
{
    return (int)db_wert(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$tabelle]
    ) > 0;
}

/** Wert aus der Tabelle einstellung (Schlüssel/Wert), null wenn nicht gesetzt. */
function einstellung(string $schluessel): ?string
{
    $w = db_wert('SELECT wert FROM einstellung WHERE schluessel = ?', [$schluessel]);
    return $w === null ? null : (string)$w;
}

function einstellung_setzen(string $schluessel, ?string $wert): void
{
    db_ausfuehren(
        'INSERT INTO einstellung (schluessel, wert, geaendert_am) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE wert = VALUES(wert), geaendert_am = VALUES(geaendert_am)',
        [$schluessel, $wert, db_jetzt()]
    );
}
