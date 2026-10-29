<?php
declare(strict_types=1);

/*
 * Einziger Einstieg für alle Seiten (die .htaccess leitet alles, was keine
 * Datei ist, hierher). Reihenfolge der Prüfungen:
 *   1. Konfiguration vorhanden? Installiert? Sonst nur /install.
 *   2. Host erlaubt (E-27), HTTPS erzwingen.
 *   3. Ausstehende Migrationen nachziehen.
 *   4. Wartungsmodus.
 *   5. Seite ausführen.
 */

if (PHP_SAPI === 'cli-server') {
    // Eingebauter PHP-Server (nur Tests/Entwicklung): vorhandene Dateien direkt ausliefern.
    $datei = __DIR__ . parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($datei !== __FILE__ && is_file($datei) && !str_ends_with($datei, '.php')) {
        return false;
    }
}

require dirname(__DIR__) . '/bootstrap.php';

const ROUTEN = [
    '/' => 'start',
    '/install' => 'install',
    '/jobs' => 'jobs',
    '/health' => 'health',
    '/impressum' => 'impressum',
    '/datenschutz' => 'datenschutz',
];

$pfad = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$pfad = $pfad === '/' ? '/' : rtrim($pfad, '/');
$seite = ROUTEN[$pfad] ?? null;

// 1. Noch nicht eingerichtet: nur der Installer antwortet.
if (konfig_maengel() !== [] || !installiert()) {
    if ($seite !== 'install') {
        kopf_gemeinsam();
        seite_meldung(503, 'Noch nicht eingerichtet', 'Diese Webseite wird gerade eingerichtet.');
    }
    $host = host_angefragt() ?? 'localhost';
    https_tor($host);
    $GLOBALS['ws_host'] = $host;
    require dirname(__DIR__) . '/seiten/install.php';
    exit;
}

// 2. Host und HTTPS
$host = host_tor();
https_tor($host);

// 3. Migrationen nach einer Auslieferung
migration_bei_bedarf();

// 4. Wartung
wartung_tor($pfad);

// 5. Seite
if ($seite === null || $seite === 'install') {
    nicht_gefunden();
}
require dirname(__DIR__) . '/seiten/' . $seite . '.php';
