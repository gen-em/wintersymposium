<?php
declare(strict_types=1);

/*
 * Testlauf auf der Kommandozeile (lokal und im Prüf-Workflow):
 *
 *   php tools/tests/lauf.php
 *
 * Ohne Datenbank laufen nur die Teile ohne DB. Mit Datenbank die
 * Umgebungsvariablen setzen (eigene, leere Testdatenbank – sie wird geleert!):
 *
 *   WS_TEST_DB_HOST, WS_TEST_DB_PORT, WS_TEST_DB_NAME, WS_TEST_DB_USER, WS_TEST_DB_PASS
 *
 * Der HTTP-Teil startet den eingebauten PHP-Server auf 127.0.0.1.
 */

$wurzel = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ws-test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/var', 0700, true);

$mitDb = getenv('WS_TEST_DB_NAME') !== false && getenv('WS_TEST_DB_NAME') !== '';
$konfig = [
    'umgebung' => 'entwicklung',
    'db' => [
        'host' => getenv('WS_TEST_DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('WS_TEST_DB_PORT') ?: 3306),
        'name' => getenv('WS_TEST_DB_NAME') ?: '',
        'user' => getenv('WS_TEST_DB_USER') ?: '',
        'pass' => getenv('WS_TEST_DB_PASS') ?: '',
    ],
    'geheimnis' => str_repeat('g', 40),
    'installations_schluessel' => str_repeat('i', 40),
    'jobs_token' => str_repeat('j', 40),
    'health_token' => str_repeat('h', 40),
    'laufzeit_ordner' => $tmp . '/var',
];
file_put_contents($tmp . '/config.php', '<?php return ' . var_export($konfig, true) . ";\n");
putenv('WS_CONFIG=' . $tmp . '/config.php');

require $wurzel . '/server/bootstrap.php';
restore_exception_handler();

/* ---- Mini-Rahmen ---------------------------------------------------------- */

$GLOBALS['t_ok'] = 0;
$GLOBALS['t_fehler'] = [];

function pruefe(bool $bedingung, string $was): void
{
    if ($bedingung) {
        $GLOBALS['t_ok']++;
        echo "  ok    $was\n";
    } else {
        $GLOBALS['t_fehler'][] = $was;
        echo "  FEHLER $was\n";
    }
}

function abschnitt(string $titel): void
{
    echo "\n== $titel\n";
}

function aufraeumen_tmp(string $pfad): void
{
    if (!is_dir($pfad)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pfad, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $d) {
        $d->isDir() ? @rmdir($d->getPathname()) : @unlink($d->getPathname());
    }
    @rmdir($pfad);
}

/* ---- Ohne Datenbank -------------------------------------------------------- */

abschnitt('Formate');
pruefe(datum_text('2027-01-20 08:00:00') === '20.01.2027', 'datum_text');
pruefe(datum_zeit_text('2027-01-20 08:00:00') === '20.01.2027, 09:00', 'UTC → Europe/Berlin (Winterzeit)');
pruefe(datum_zeit_text('2026-07-01 08:00:00') === '01.07.2026, 10:00', 'UTC → Europe/Berlin (Sommerzeit)');
pruefe(lokal_nach_utc('2027-01-20 09:00') === '2027-01-20 08:00:00', 'Ortszeit → UTC');
pruefe(zahl_text(1234.5, 1) === '1.234,5', 'zahl_text');
pruefe(iso_utc('2026-10-29 12:00:00') === '2026-10-29T12:00:00Z', 'iso_utc');

abschnitt('Hostnamen (E-27)');
pruefe(host_normalisieren('Winter-Notfallsymposium.DE') === 'winter-notfallsymposium.de', 'Kleinschreibung');
pruefe(host_normalisieren('test.winter-notfallsymposium.de:443') === 'test.winter-notfallsymposium.de', 'Port entfernt');
pruefe(host_normalisieren('beispiel.de.') === 'beispiel.de', 'Punkt am Ende entfernt');
pruefe(host_normalisieren('evil.de/x') === null, 'Schrägstrich abgelehnt');
pruefe(host_normalisieren('-x.de') === null, 'Bindestrich am Anfang abgelehnt');
pruefe(host_normalisieren('') === null, 'leer abgelehnt');
pruefe(host_normalisieren('a b.de') === null, 'Leerzeichen abgelehnt');

abschnitt('Kopfzeilen');
$csp = kopf_csp();
pruefe(str_contains($csp, "'nonce-" . kopf_nonce() . "'"), 'CSP enthält Nonce');
pruefe(str_contains($csp, "default-src 'none'") && str_contains($csp, "frame-ancestors 'none'"), 'CSP streng');
pruefe(!str_contains($csp, 'unsafe-inline') && !str_contains($csp, 'http'), 'CSP ohne unsafe-inline und fremde Quellen');

abschnitt('Konfiguration');
pruefe(konfig_maengel() === [] || !$mitDb, 'Test-Konfiguration vollständig');
pruefe(umgebung() === 'entwicklung', 'Umgebung gelesen');
pruefe(preg_match('/^\d+\.\d+\.\d+$/', app_version()) === 1, 'VERSION lesbar (' . app_version() . ')');

abschnitt('Installer-Eingaben');
$gut = ['name' => 'Test', 'email' => 'a@b.de', 'passwort' => str_repeat('x', 12), 'passwort2' => str_repeat('x', 12), 'weitere_hosts' => "www.beispiel.de\n"];
pruefe(install_pruefen($gut) === [], 'gültige Eingabe');
pruefe(install_pruefen(['passwort2' => 'y'] + $gut) !== [], 'Passwörter verschieden');
pruefe(install_pruefen(['passwort' => 'kurz', 'passwort2' => 'kurz'] + $gut) !== [], 'Passwort zu kurz');
pruefe(install_pruefen(['email' => 'kein-at'] + $gut) !== [], 'E-Mail ungültig');
pruefe(install_pruefen(['weitere_hosts' => 'böse/host'] + $gut) !== [], 'Host ungültig');

/* ---- Mit Datenbank --------------------------------------------------------- */

if (!$mitDb) {
    echo "\n(Ohne WS_TEST_DB_NAME: Datenbank- und HTTP-Tests übersprungen.)\n";
} else {
    abschnitt('Datenbank leeren');
    db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (db_zeilen('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $z) {
        db()->exec('DROP TABLE `' . $z['t'] . '`');
    }
    db()->exec('SET FOREIGN_KEY_CHECKS = 1');
    pruefe(migration_ist() === 0, 'leere Datenbank');

    abschnitt('Migrationen');
    $neu = migration_anwenden();
    pruefe($neu === array_keys(migration_katalog()), 'alle Schritte angewendet');
    pruefe(migration_anwenden() === [], 'zweiter Lauf wendet nichts an');
    pruefe(migration_ist() === migration_soll(), 'Stand = Soll');
    $zeit = (string)db_wert('SELECT @@session.time_zone');
    pruefe($zeit === '+00:00', 'DB-Sitzung in UTC');

    abschnitt('Transaktionen (R-04)');
    try {
        db_transaktion(function (): void {
            einstellung_setzen('t_aussen', '1');
            db_transaktion(function (): void {
                einstellung_setzen('t_innen', '1');
            });
            throw new RuntimeException('Abbruch');
        });
    } catch (RuntimeException) {
    }
    pruefe(einstellung('t_aussen') === null && einstellung('t_innen') === null, 'verschachtelt: alles zurückgerollt');
    db_transaktion(fn() => einstellung_setzen('t_ok', 'ja'));
    pruefe(einstellung('t_ok') === 'ja', 'festgeschrieben');

    abschnitt('Ratenschutz (T-104)');
    $m = rate_merkmal('test');
    $t0 = 1_900_000_000;
    [$max, $fenster, $sperre] = RATE_TOEPFE['health'];
    for ($i = 1; $i < $max; $i++) {
        rate_misserfolg('health', $m, $t0);
    }
    pruefe(rate_erlaubt('health', $m, $t0), ($max - 1) . ' Fehlversuche: noch erlaubt');
    pruefe(rate_misserfolg('health', $m, $t0) === true, "$max. Fehlversuch sperrt");
    pruefe(!rate_erlaubt('health', $m, $t0 + 1), 'gesperrt');
    pruefe(rate_erlaubt('health', $m, $t0 + $sperre + 1), 'nach Sperrdauer wieder frei');
    rate_erfolg('health', $m);
    pruefe(db_wert('SELECT COUNT(*) FROM rate_limit WHERE merkmal = ?', [$m]) == 0, 'Erfolg setzt zurück');
    rate_misserfolg('health', $m, $t0);
    pruefe(rate_aufraeumen($t0 + 100000) >= 1, 'Aufräumen entfernt alte Einträge');
    pruefe(strlen(rate_merkmal_ip()) === 64 && !str_contains(rate_merkmal_ip(), '.'), 'Merkmal ist Hash, keine IP');

    abschnitt('Jobs (T-105)');
    $r = jobs_lauf('test', 1_900_000_000);
    pruefe($r['status'] === 'ok' && $r['jobs']['aufraeumen']['status'] === 'ok', 'erster Lauf führt Job aus');
    pruefe(einstellung('jobs_letzter_lauf') === gmdate('Y-m-d H:i:s', 1_900_000_000), 'letzter Lauf gespeichert');
    $r = jobs_lauf('test', 1_900_000_060);
    pruefe($r['jobs']['aufraeumen']['status'] === 'nicht_faellig', 'Mindestabstand eingehalten');
    pruefe(einstellung('jobs_letzter_lauf') === gmdate('Y-m-d H:i:s', 1_900_000_060), 'Zeitstempel trotzdem aktualisiert');
    wartung_einschalten('test');
    pruefe(jobs_lauf('test')['status'] === 'wartung', 'Jobs pausieren bei Wartung');
    wartung_ausschalten();

    abschnitt('Sitzungsablage (T-103)');
    $alt = sitzung_ablage_pfad() . '/sess_alt';
    $frisch = sitzung_ablage_pfad() . '/sess_frisch';
    file_put_contents($alt, 'x');
    touch($alt, time() - 7200);
    file_put_contents($frisch, 'x');
    pruefe(sitzung_aufraeumen() === 1 && !is_file($alt) && is_file($frisch), 'nur abgelaufene Sitzungsdateien gelöscht');
    pruefe(str_starts_with(sitzung_ablage_pfad(), laufzeit_ordner()), 'Ablage im eigenen Laufzeitordner');

    // Für den HTTP-Teil wieder leer starten.
    db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (db_zeilen('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $z) {
        db()->exec('DROP TABLE `' . $z['t'] . '`');
    }
    db()->exec('SET FOREIGN_KEY_CHECKS = 1');

    require __DIR__ . '/http_tests.php';
    http_tests($wurzel, $tmp);
}

aufraeumen_tmp($tmp);

$n = count($GLOBALS['t_fehler']);
echo "\n" . $GLOBALS['t_ok'] . " ok, $n Fehler\n";
foreach ($GLOBALS['t_fehler'] as $f) {
    echo "  - $f\n";
}
exit($n === 0 ? 0 : 1);
