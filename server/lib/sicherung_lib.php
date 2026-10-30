<?php
declare(strict_types=1);

/*
 * Sicherung und Wiederherstellung der Datenbank, Testdaten auf Staging
 * (Admin 2026-10-29, E-47; Teil von AP-9, T-201).
 *
 * Eine Sicherung ist eine Datei in var/sicherungen/ (nie über das Web
 * erreichbar), gzip-komprimiert, je Zeile ein JSON-Objekt:
 *   {"art":"kopf", format, version, migration, erstellt, anlass}
 *   {"art":"tabelle", name, ddl, spalten}       – Aufbau (SHOW CREATE TABLE)
 *   {"art":"zeilen", name, daten: [[…], …]}      – bis zu 500 Zeilen je Block
 *   {"art":"datei", ordner, name, inhalt}        – Fotos der Dozierenden und Bilder der Mailings (base64)
 *   {"art":"ende", tabellen, zeilen}             – fehlt sie, ist die Datei unvollständig
 * Ohne Inhalt gesichert: rate_limit und mail_ausgang (kein erneuter Versand).
 *
 * Wiederherstellen ersetzt alle Tabellen – auch die Team-Konten – durch den
 * Stand der Sicherung; vorher legt es selbst eine Sicherung an. Eine Sicherung
 * aus einem älteren Stand wird danach mit den Migrationen nachgezogen; eine
 * aus einem neueren Stand wird abgelehnt.
 */

const SICHERUNG_FORMAT = 1;
const SICHERUNG_OHNE_INHALT = ['rate_limit', 'mail_ausgang'];
const SICHERUNG_BLOCK = 500;
const SICHERUNG_NAME = '/^sicherung-\d{8}-\d{6}-[0-9a-f]{6}\.jsonl(\.gz)?$/';

function sicherung_ordner(): string
{
    return laufzeit_pfad('sicherungen');
}

function sicherung_gz(): bool
{
    return function_exists('gzopen');
}

/** Pfad einer Sicherung oder null (nur gültige Namen im eigenen Ordner). */
function sicherung_pfad(string $name): ?string
{
    if (preg_match(SICHERUNG_NAME, $name) !== 1) {
        return null;
    }
    $p = sicherung_ordner() . '/' . $name;
    return is_file($p) ? $p : null;
}

/**
 * Datei öffnen; komprimiert über den zlib-Stream (gz = true).
 * @return resource
 */
function sicherung_oeffnen(string $pfad, string $modus, ?bool $gz = null)
{
    $gz ??= str_ends_with($pfad, '.gz');
    $h = @fopen(($gz ? 'compress.zlib://' : '') . $pfad, $modus);
    if ($h === false) {
        throw new RuntimeException('Sicherung lässt sich nicht öffnen.');
    }
    return $h;
}

function sicherung_zeile($h): ?array
{
    $z = fgets($h);
    if ($z === false || $z === '') {
        return null;
    }
    $d = json_decode($z, true);
    return is_array($d) ? $d : ['art' => 'kaputt'];
}

/** Sicherung anlegen. Gibt den Dateinamen zurück. */
function sicherung_anlegen(string $anlass, ?int $kontoId = null): string
{
    $name = 'sicherung-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.jsonl' . (sicherung_gz() ? '.gz' : '');
    $pfad = sicherung_ordner() . '/' . $name;
    $tmp = $pfad . '.teil';
    $h = sicherung_oeffnen($tmp, 'w', sicherung_gz());
    $schreib = function (array $d) use ($h): void {
        fwrite($h, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    };
    $schreib(['art' => 'kopf', 'format' => SICHERUNG_FORMAT, 'version' => app_version(), 'migration' => migration_ist(), 'erstellt' => db_jetzt(), 'anlass' => mb_substr($anlass, 0, 120)]);
    $tabellen = 0;
    $zeilen = 0;
    foreach (db_zeilen('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\' ORDER BY table_name') as $t) {
        $tab = (string)$t['t'];
        $ddl = (string)(db_zeile('SHOW CREATE TABLE `' . str_replace('`', '', $tab) . '`')['Create Table'] ?? '');
        $spalten = array_column(db_zeilen('SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$tab]), 'c');
        $schreib(['art' => 'tabelle', 'name' => $tab, 'ddl' => $ddl, 'spalten' => $spalten]);
        $tabellen++;
        if (in_array($tab, SICHERUNG_OHNE_INHALT, true)) {
            continue;
        }
        $st = db()->query('SELECT * FROM `' . str_replace('`', '', $tab) . '`', PDO::FETCH_NUM);
        $block = [];
        foreach ($st as $r) {
            $block[] = $r;
            $zeilen++;
            if (count($block) >= SICHERUNG_BLOCK) {
                $schreib(['art' => 'zeilen', 'name' => $tab, 'daten' => $block]);
                $block = [];
            }
        }
        if ($block !== []) {
            $schreib(['art' => 'zeilen', 'name' => $tab, 'daten' => $block]);
        }
    }
    foreach (array_merge(glob(foto_ordner() . '/dozent-*.jpg') ?: [], glob(foto_ordner() . '/mail-*.jpg') ?: [], glob(foto_ordner() . '/mail-*.png') ?: [], glob(foto_ordner() . '/text-*.jpg') ?: [], glob(foto_ordner() . '/text-*.png') ?: []) as $f) {
        $schreib(['art' => 'datei', 'ordner' => 'bilder', 'name' => basename($f), 'inhalt' => base64_encode((string)file_get_contents($f))]);
    }
    $schreib(['art' => 'ende', 'tabellen' => $tabellen, 'zeilen' => $zeilen]);
    fclose($h);
    rename($tmp, $pfad);
    audit_schreiben('sicherung', 'Sicherung angelegt: ' . $name . ' (' . $anlass . ')', $kontoId);
    return $name;
}

/** Kopf einer Sicherung lesen (oder null). */
function sicherung_kopf(string $name): ?array
{
    $p = sicherung_pfad($name);
    if ($p === null) {
        return null;
    }
    $h = sicherung_oeffnen($p, 'r');
    $k = sicherung_zeile($h);
    fclose($h);
    return ($k['art'] ?? '') === 'kopf' ? $k : null;
}

/** Alle Sicherungen, neueste zuerst: [['name','groesse','kopf']]. */
function sicherung_liste(): array
{
    $liste = [];
    foreach (glob(sicherung_ordner() . '/sicherung-*') ?: [] as $p) {
        $name = basename($p);
        if (preg_match(SICHERUNG_NAME, $name) !== 1) {
            continue;
        }
        $liste[] = ['name' => $name, 'groesse' => (int)filesize($p), 'kopf' => sicherung_kopf($name)];
    }
    usort($liste, fn($a, $b) => strcmp($b['name'], $a['name']));
    return $liste;
}

function sicherung_loeschen(string $name, ?int $kontoId): bool
{
    $p = sicherung_pfad($name);
    if ($p === null || !@unlink($p)) {
        return false;
    }
    audit_schreiben('sicherung', 'Sicherung gelöscht: ' . $name, $kontoId);
    return true;
}

/** Datei vollständig lesen und prüfen, ohne etwas zu ändern. Gibt eine Fehlermeldung oder null zurück. */
function sicherung_pruefen(string $name): ?string
{
    $p = sicherung_pfad($name);
    if ($p === null) {
        return 'Diese Sicherung gibt es nicht.';
    }
    $h = sicherung_oeffnen($p, 'r');
    $kopf = sicherung_zeile($h);
    $ende = false;
    $tabellen = [];
    while (($d = sicherung_zeile($h)) !== null) {
        $art = $d['art'] ?? '';
        if ($art === 'kaputt') {
            break;
        }
        if ($art === 'tabelle') {
            if (!is_string($d['name'] ?? null) || preg_match('/^[a-z0-9_]+$/', $d['name']) !== 1 || !str_starts_with((string)($d['ddl'] ?? ''), 'CREATE TABLE `' . $d['name'] . '`')) {
                break;
            }
            $tabellen[$d['name']] = true;
        } elseif ($art === 'zeilen' && !isset($tabellen[$d['name'] ?? ''])) {
            break;
        } elseif ($art === 'datei' && (($d['ordner'] ?? '') !== 'bilder' || preg_match('/^(dozent-\d+-[0-9a-f]{12}\.jpg|(mail|text)-[0-9a-f]{16}\.(jpg|png))$/', (string)($d['name'] ?? '')) !== 1)) {
            break;
        } elseif ($art === 'ende') {
            $ende = true;
            break;
        }
    }
    fclose($h);
    if (($kopf['art'] ?? '') !== 'kopf' || (int)($kopf['format'] ?? 0) !== SICHERUNG_FORMAT) {
        return 'Die Datei ist keine Sicherung dieser Anwendung.';
    }
    if (!$ende) {
        return 'Die Sicherung ist unvollständig oder beschädigt.';
    }
    if ((int)$kopf['migration'] > migration_soll()) {
        return 'Die Sicherung stammt aus einem neueren Stand der Anwendung (Datenbank-Stand ' . (int)$kopf['migration'] . ', hier ' . migration_soll() . ').';
    }
    if (!isset($tabellen['konto'], $tabellen['einstellung'])) {
        return 'In der Sicherung fehlen wichtige Tabellen.';
    }
    return null;
}

/**
 * Sicherung wiederherstellen. Legt vorher eine Sicherung des jetzigen Stands
 * an. Gibt null bei Erfolg zurück, sonst eine Fehlermeldung.
 */
function sicherung_wiederherstellen(string $name, ?int $kontoId): ?string
{
    $fehler = sicherung_pruefen($name);
    if ($fehler !== null) {
        return $fehler;
    }
    $vorher = sicherung_anlegen('vor dem Wiederherstellen von ' . $name, $kontoId);
    $p = (string)sicherung_pfad($name);
    $pdo = db();
    foreach (['ws_jobs', 'ws_platz', 'ws_migration'] as $sperre) {
        if ((int)db_wert('SELECT GET_LOCK(?, 20)', [$sperre]) !== 1) {
            return 'Die Datenbank ist gerade beschäftigt. Bitte gleich noch einmal versuchen.';
        }
    }
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (db_zeilen('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $t) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string)$t['t']) . '`');
        }
        $h = sicherung_oeffnen($p, 'r');
        $spalten = [];
        while (($d = sicherung_zeile($h)) !== null) {
            switch ($d['art'] ?? '') {
                case 'tabelle':
                    $pdo->exec((string)$d['ddl']);
                    $spalten[$d['name']] = $d['spalten'];
                    break;
                case 'zeilen':
                    $tab = (string)$d['name'];
                    $sp = $spalten[$tab];
                    $platz = '(' . implode(',', array_fill(0, count($sp), '?')) . ')';
                    $sql = 'INSERT INTO `' . $tab . '` (`' . implode('`,`', $sp) . '`) VALUES ';
                    $pdo->beginTransaction();
                    foreach (array_chunk($d['daten'], 100) as $teil) {
                        $st = $pdo->prepare($sql . implode(',', array_fill(0, count($teil), $platz)));
                        $st->execute(array_merge(...$teil));
                    }
                    $pdo->commit();
                    break;
                case 'datei':
                    @file_put_contents(foto_ordner() . '/' . basename((string)$d['name']), base64_decode((string)$d['inhalt'], true) ?: '');
                    break;
            }
        }
        fclose($h);
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        foreach (['ws_migration', 'ws_platz', 'ws_jobs'] as $sperre) {
            db_wert('SELECT RELEASE_LOCK(?)', [$sperre]);
        }
    }
    migration_bei_bedarf();
    if (function_exists('anm_workshops')) {
        anm_workshops(true);
    }
    audit_schreiben('sicherung', 'Wiederhergestellt: ' . $name . ' (Stand davor gesichert als ' . $vorher . ')', $kontoId);
    return null;
}

/* ---- Testdaten (nur Staging und Entwicklung) ----------------------------- */

function testdaten_moeglich(): bool
{
    return in_array(umgebung(), ['staging', 'entwicklung'], true);
}

/**
 * Programm, Dozierende, Anmeldungen und Veranstaltungs-Einstellungen durch den
 * Test-Beispieldatensatz ersetzen. Team-Konten bleiben. Vorher eine Sicherung.
 * Gibt den Namen der Sicherung zurück.
 */
function testdaten_laden(?int $kontoId): string
{
    if (!testdaten_moeglich()) {
        throw new RuntimeException('Testdaten nur auf Staging.');
    }
    require_once dirname(__DIR__) . '/testdaten/beispieldaten.php';
    $sicherung = sicherung_anlegen('vor dem Laden der Testdaten', $kontoId);
    db_transaktion(function (): void {
        db_ausfuehren('DELETE FROM anmeldung');
        db_ausfuehren('DELETE FROM programmpunkt');
        db_ausfuehren('DELETE FROM dozent');
        db_ausfuehren('DELETE FROM programm_stand');
        db_ausfuehren('DELETE FROM mail_ausgang');
        db_ausfuehren('DELETE FROM rundmail');
        db_ausfuehren('DELETE FROM einstellung WHERE schluessel IN (' . implode(',', array_fill(0, count(VA_KATALOG), '?')) . ')', array_keys(VA_KATALOG));
    });
    anm_workshops(true);
    beispieldaten_laden();
    audit_schreiben('sicherung', 'Testdaten geladen (Sicherung davor: ' . $sicherung . ')', $kontoId);
    return $sicherung;
}
