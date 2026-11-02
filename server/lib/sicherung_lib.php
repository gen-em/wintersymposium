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
 *   {"art":"datei", ordner, name, inhalt}        – Fotos der Dozierenden, Bilder der Mailings und Texte, Unterschrift des Zertifikats (base64)
 *   {"art":"ende", tabellen, zeilen}             – fehlt sie, ist die Datei unvollständig
 * Ohne Inhalt gesichert: rate_limit, login_bekannt (Ratenschutz) und mail_ausgang (kein erneuter Versand).
 *
 * Wiederherstellen ersetzt alle Tabellen – auch die Team-Konten – durch den
 * Stand der Sicherung; vorher legt es selbst eine Sicherung an. Eine Sicherung
 * aus einem älteren Stand wird danach mit den Migrationen nachgezogen; eine
 * aus einem neueren Stand wird abgelehnt.
 */

const SICHERUNG_FORMAT = 1;
const SICHERUNG_OHNE_INHALT = ['rate_limit', 'login_bekannt', 'mail_ausgang'];
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
    // Unterschrift auf dem Zertifikat (AP-10)
    foreach (glob(laufzeit_pfad('zertifikat') . '/*/unterschrift.png') ?: [] as $f) {
        $schreib(['art' => 'datei', 'ordner' => 'zertifikat', 'name' => basename(dirname($f)) . '/unterschrift.png', 'inhalt' => base64_encode((string)file_get_contents($f))]);
    }
    $schreib(['art' => 'ende', 'tabellen' => $tabellen, 'zeilen' => $zeilen]);
    fclose($h);
    rename($tmp, $pfad);
    if ($anlass !== DS_SICHERUNG_AUTOMATISCH) {
        // Die automatischen (8× täglich) stehen nicht einzeln im Protokoll
        audit_schreiben('sicherung', 'Sicherung angelegt: ' . $name . ' (' . $anlass . ')', $kontoId);
    }
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

function sicherung_loeschen(string $name, ?int $kontoId, bool $protokoll = true): bool
{
    $p = sicherung_pfad($name);
    if ($p === null || !@unlink($p)) {
        return false;
    }
    if ($protokoll) {
        audit_schreiben('sicherung', 'Sicherung gelöscht: ' . $name, $kontoId);
    }
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
        } elseif ($art === 'datei' && !((($d['ordner'] ?? '') === 'bilder' && preg_match('/^(dozent-\d+-[0-9a-f]{12}\.jpg|(mail|text)-[0-9a-f]{16}\.(jpg|png))$/', (string)($d['name'] ?? '')) === 1)
            || (($d['ordner'] ?? '') === 'zertifikat' && preg_match('#^\d{1,9}/unterschrift\.png$#', (string)($d['name'] ?? '')) === 1))) {
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
        if (db_zahl('SELECT GET_LOCK(?, 20)', [$sperre]) !== 1) {
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
                    $ziel = ($d['ordner'] ?? '') === 'zertifikat'
                        ? laufzeit_pfad('zertifikat/' . (int)dirname((string)$d['name'])) . '/unterschrift.png'
                        : foto_ordner() . '/' . basename((string)$d['name']);
                    @file_put_contents($ziel, base64_decode((string)$d['inhalt'], true) ?: '');
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
        db_ausfuehren('DELETE FROM newsletter_person');
        // Alle Veranstaltungen auf die erste zurücksetzen (E-58); die Beispieldaten legen weitere an
        db_ausfuehren('DELETE FROM va_einstellung');
        db_ausfuehren('DELETE FROM inhalt WHERE veranstaltung_id NOT IN (0, 1)');
        db_ausfuehren('DELETE FROM veranstaltung WHERE id <> 1');
        db_ausfuehren("UPDATE veranstaltung SET status = 'aktuell', abgeschlossen_am = NULL WHERE id = 1");
    });
    va_zwischenspeicher_leeren();
    ctx_weg('va_id');
    anm_workshops(true);
    beispieldaten_laden();
    testdaten_konten($kontoId);
    audit_schreiben('sicherung', 'Testdaten geladen (Sicherung davor: ' . $sicherung . ')', $kontoId);
    return $sicherung;
}

/**
 * Staging auf den Ausgangsstand zurücksetzen (Admin 2026-11-01): wie nach der
 * Installation, ohne Beispieldaten und ohne Testkonten. Es bleiben die
 * Team-Konten, die nicht zu den Testdaten gehören (Adresse nicht bei
 * example.org und nicht unter testkonten in der config.php), samt zweitem
 * Faktor, Wiederherstellungscodes und Passkeys; dazu die Betriebs-Einstellungen
 * (Tabelle einstellung), die Hostnamen sowie Impressum und Datenschutz
 * (gemeinsame Texte mit ihren Bildern). Alles andere wird neu aufgebaut: alle
 * Tabellen weg, Migrationen von vorn – also eine Veranstaltung, die Reihe
 * Wintersymposium und die Newsletter-Listen wie nach der Installation.
 * Rechte der Admins bleiben für die erste Veranstaltung und für Reihen
 * gleichen Namens. Vorher eine Sicherung. Mit $testkonten legt es danach die
 * festen Testkonten aus der config.php neu an. Gibt den Namen der Sicherung
 * zurück.
 */
function testdaten_zuruecksetzen(?int $kontoId, bool $testkonten = false): string
{
    if (!testdaten_moeglich()) {
        throw new RuntimeException('Zurücksetzen nur auf Staging.');
    }
    $sicherung = sicherung_anlegen('vor dem Zurücksetzen von Staging', $kontoId);
    $test = array_filter(array_map(fn($d) => mail_adresse((string)(((array)$d)['email'] ?? '')), (array)konfig('testkonten', [])));
    $konten = array_values(array_filter(
        db_zeilen("SELECT * FROM konto WHERE rolle IN ('admin', 'betreiber')"),
        fn($k) => !str_ends_with(mb_strtolower((string)$k['email']), '@example.org') && !in_array(mail_adresse((string)$k['email']), $test, true)
    ));
    $ids = array_map(fn($k) => (int)$k['id'], $konten);
    // Nummern gelöschter Konten nicht neu vergeben (eine offene Sitzung zeigte sonst auf ein anderes Konto)
    $naechste = db_zahl('SELECT COALESCE(MAX(id), 0) FROM konto') + 1;
    $mit = fn(string $tabelle) => $ids === [] ? [] : db_zeilen('SELECT * FROM ' . $tabelle . ' WHERE konto_id IN (' . implode(',', $ids) . ')');
    $behalten = [
        'konto' => $konten,
        'konto_code' => $mit('konto_code'),
        'passkey' => $mit('passkey'),
        'konto_veranstaltung' => array_values(array_filter($mit('konto_veranstaltung'), fn($r) => (int)$r['veranstaltung_id'] === 1)),
        'einstellung' => db_zeilen('SELECT * FROM einstellung'),
        'host' => db_zeilen('SELECT * FROM host'),
        'inhalt' => db_zeilen('SELECT * FROM inhalt WHERE veranstaltung_id = 0'),
    ];
    $reihenRechte = va_reihen_da() && $ids !== []
        ? db_zeilen('SELECT kr.konto_id, r.name FROM konto_reihe kr JOIN reihe r ON r.id = kr.reihe_id WHERE kr.konto_id IN (' . implode(',', $ids) . ')') : [];

    $pdo = db();
    foreach (['ws_jobs', 'ws_platz', 'ws_migration'] as $sperre) {
        if (db_zahl('SELECT GET_LOCK(?, 20)', [$sperre]) !== 1) {
            throw new RuntimeException('Die Datenbank ist gerade beschäftigt. Bitte gleich noch einmal versuchen.');
        }
    }
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (db_zeilen('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $t) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string)$t['t']) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        db_wert('SELECT RELEASE_LOCK(?)', ['ws_migration']);
        migration_anwenden();
        // Was die Migrationen vorbelegen (Texte, Hosts), weicht dem behaltenen Stand
        db_ausfuehren('DELETE FROM inhalt WHERE veranstaltung_id = 0');
        db_ausfuehren('DELETE FROM host');
        foreach ($behalten as $tabelle => $zeilen) {
            foreach ($zeilen as $z) {
                db_ausfuehren('REPLACE INTO `' . $tabelle . '` (`' . implode('`,`', array_keys($z)) . '`) VALUES (' . implode(',', array_fill(0, count($z), '?')) . ')', array_values($z));
            }
        }
        db_ausfuehren('ALTER TABLE konto AUTO_INCREMENT = ' . $naechste);
        $reihen = array_column(va_reihen_da() ? db_zeilen('SELECT id, name FROM reihe') : [], 'id', 'name');
        foreach ($reihenRechte as $rr) {
            if (isset($reihen[$rr['name']])) {
                db_ausfuehren('INSERT IGNORE INTO konto_reihe (konto_id, reihe_id) VALUES (?, ?)', [(int)$rr['konto_id'], (int)$reihen[$rr['name']]]);
            }
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        foreach (['ws_migration', 'ws_platz', 'ws_jobs'] as $sperre) {
            db_wert('SELECT RELEASE_LOCK(?)', [$sperre]);
        }
    }
    // Dateien: Fotos, Bilder der Mailings und Unterschriften weg; Bilder in Impressum und Datenschutz bleiben
    $texte = implode(' ', array_column($behalten['inhalt'], 'text'));
    foreach (array_merge(glob(foto_ordner() . '/dozent-*.jpg') ?: [], glob(foto_ordner() . '/mail-*') ?: [], glob(foto_ordner() . '/text-*') ?: []) as $f) {
        if (!str_contains($texte, basename($f))) {
            @unlink($f);
        }
    }
    foreach (glob(laufzeit_pfad('zertifikat') . '/*/unterschrift.png') ?: [] as $f) {
        @unlink($f);
    }
    va_zwischenspeicher_leeren();
    ctx_weg('va_id');
    if (function_exists('anm_workshops')) {
        anm_workshops(true);
    }
    if ($testkonten) {
        testdaten_konten($kontoId);
    }
    audit_schreiben('sicherung', 'Staging zurückgesetzt (Sicherung davor: ' . $sicherung . ')' . ($testkonten ? ', Testkonten neu angelegt' : ''), $kontoId);
    return $sicherung;
}

/** Rollen der Testkonten aus der config.php (Schlüssel testkonten). */
const TESTDATEN_KONTO_ROLLEN = ['betreiber' => 'Test BetreiberIn', 'admin' => 'Test Admin', 'teilnehmend' => 'Test Teilnehmerin'];

/**
 * Feste Testkonten auf Staging (Rückmeldung nach AP-19): aus config.php
 * (testkonten => [rolle => [email, passwort, totp]]) anlegen oder Passwort,
 * Rolle und zweiten Faktor zurücksetzen. Zugangsdaten stehen nur in der
 * config.php (R-10). Gibt die Zahl der Konten oder den Fehler je Rolle zurück.
 */
function testdaten_konten(?int $durch): array
{
    $erg = ['konten' => 0, 'fehler' => []];
    if (!testdaten_moeglich()) {
        return $erg;
    }
    foreach ((array)konfig('testkonten', []) as $rolle => $d) {
        $d = (array)$d;
        $email = mail_adresse((string)($d['email'] ?? ''));
        $pw = (string)($d['passwort'] ?? '');
        $roh = (string)($d['totp'] ?? '') !== '' ? totp_base32_dekodieren(strtoupper(str_replace(' ', '', (string)$d['totp']))) : '';
        if (!isset(TESTDATEN_KONTO_ROLLEN[$rolle]) || $email === null || mb_strlen($pw) < ($rolle === 'teilnehmend' ? PASSWORT_MIN_TN : PASSWORT_MIN_LAENGE)) {
            $erg['fehler'][] = $rolle . ': Rolle, Adresse oder Passwort (Mindestlänge) ungültig';
            continue;
        }
        $k = konto_per_email($email);
        $id = $k !== null ? (int)$k['id'] : konto_anlegen(TESTDATEN_KONTO_ROLLEN[$rolle], $email, $rolle, $pw, $durch);
        if ($k !== null) {
            konto_passwort_setzen($id, $pw);
            db_ausfuehren('UPDATE konto SET rolle = ?, aktiv = 1 WHERE id = ?', [$rolle, $id]);
        }
        db_ausfuehren('UPDATE konto SET email_bestaetigt_am = COALESCE(email_bestaetigt_am, ?) WHERE id = ?', [db_jetzt(), $id]);
        if ($rolle !== 'teilnehmend' && strlen($roh) >= 10) {
            totp_fest_setzen($id, $roh);
            // Wiederherstellungscodes, damit der rote Hinweis „0 Codes“ nicht auf jeder Seite steht; angezeigt
            // werden sie nicht – der Notweg für Testkonten ist „Testkonten anlegen“
            if (db_zahl('SELECT COUNT(*) FROM konto_code WHERE konto_id = ? AND benutzt_am IS NULL', [$id]) === 0) {
                totp_codes_schreiben($id, totp_codes_erzeugen());
            }
        }
        if ($rolle === 'admin') {
            va_rechte_setzen($id, true, [], $durch);
        }
        $erg['konten']++;
    }
    if ($erg['konten'] > 0) {
        audit_schreiben('sicherung', 'Testkonten aus der Konfiguration gesetzt: ' . $erg['konten'], $durch);
    }
    return $erg;
}

/** Herunterladen einer Sicherung protokollieren (vor der Auslieferung). */
function sicherung_herunterladen_protokollieren(string $name, ?int $durch): void
{
    audit_schreiben('sicherung', 'Sicherung heruntergeladen: ' . $name, $durch);
}
