<?php
declare(strict_types=1);

/*
 * Vorbereitung für den Browser-Durchlauf (tools/browser/durchlauf.js):
 * leere Testdatenbank, Installation mit festen Testkonten, Konfiguration in
 * einem eigenen Ordner, dazu der Test-Beispieldatensatz
 * (tools/testdaten/beispieldaten.php). Gibt JSON aus. Nur für die Testdatenbank – sie wird
 * geleert! (Umgebungsvariablen WS_TEST_DB_* wie bei lauf.php.)
 *
 *   php tools/browser/vorbereiten.php <ordner>
 */

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') === '') {
    fwrite(STDERR, "Aufruf: php tools/browser/vorbereiten.php <ordner>\n");
    exit(2);
}
$ordner = rtrim($argv[1], '/');
@mkdir($ordner . '/var', 0700, true);
if ((getenv('WS_TEST_DB_NAME') ?: '') === '') {
    fwrite(STDERR, "WS_TEST_DB_NAME fehlt (eigene Testdatenbank, wird geleert).\n");
    exit(2);
}
$konfig = [
    'umgebung' => 'entwicklung',
    'db' => [
        'host' => getenv('WS_TEST_DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('WS_TEST_DB_PORT') ?: 3306),
        'name' => getenv('WS_TEST_DB_NAME'),
        'user' => getenv('WS_TEST_DB_USER') ?: '',
        'pass' => getenv('WS_TEST_DB_PASS') ?: '',
    ],
    'geheimnis' => bin2hex(random_bytes(24)),
    'installations_schluessel' => bin2hex(random_bytes(24)),
    'jobs_token' => bin2hex(random_bytes(24)),
    'health_token' => bin2hex(random_bytes(24)),
    'laufzeit_ordner' => $ordner . '/var',
];
file_put_contents($ordner . '/config.php', '<?php return ' . var_export($konfig, true) . ";\n");
putenv('WS_CONFIG=' . $ordner . '/config.php');

require dirname(__DIR__, 2) . '/server/bootstrap.php';
restore_exception_handler();

db()->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (db_zeilen('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $z) {
    db()->exec('DROP TABLE `' . $z['t'] . '`');
}
db()->exec('SET FOREIGN_KEY_CHECKS = 1');

$pw = 'browser-test-passwort';
install_ausfuehren('127.0.0.1', ['name' => 'Bea Betreiberin', 'email' => 'betrieb@example.org', 'passwort' => $pw, 'weitere_hosts' => 'localhost']);
$admin = konto_anlegen('Anna Admin', 'anna@example.org', 'admin');

// Test-Beispieldatensatz (wächst mit jedem Arbeitspaket)
require dirname(__DIR__) . '/testdaten/beispieldaten.php';
$bd = beispieldaten_laden();
// Je Engine ein Zugangslink für Paula (offenes Nachrück-Angebot mit Tausch)
$paula = [];
for ($i = 0; $i < 3; $i++) {
    $paula[] = '/link?t=' . anm_link_erzeugen($bd['anmeldungen']['paula'], 'zugang', time() + 86400);
}

echo json_encode([
    'config' => $ordner . '/config.php',
    'passwort' => $pw,
    'betreiber' => 'betrieb@example.org',
    'admin_id' => $admin,
    'installations_schluessel' => $konfig['installations_schluessel'],
    'paula_links' => $paula,
    'mails' => $ordner . '/var/mails',
], JSON_UNESCAPED_SLASHES), "\n";
