<?php
declare(strict_types=1);

/*
 * Gemeinsamer Einstieg für Web und Kommandozeile: Bibliotheken laden,
 * Grundeinstellungen setzen, Fehlerbehandlung.
 */

require __DIR__ . '/lib/konfig_lib.php';
require __DIR__ . '/lib/db_lib.php';
require __DIR__ . '/lib/transaktion_lib.php';
require __DIR__ . '/lib/format_lib.php';
require __DIR__ . '/lib/html_lib.php';
require __DIR__ . '/lib/kopfzeilen_lib.php';
require __DIR__ . '/lib/sitzung_lib.php';
require __DIR__ . '/lib/host_lib.php';
require __DIR__ . '/lib/audit_lib.php';
require __DIR__ . '/lib/ratelimit_lib.php';
require __DIR__ . '/lib/migration_lib.php';
require __DIR__ . '/lib/wartung_lib.php';
require __DIR__ . '/lib/jobs_lib.php';
require __DIR__ . '/lib/install_lib.php';

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
ini_set('display_errors', entwicklung() ? '1' : '0');
ini_set('log_errors', '1');
if (konfig_vorhanden()) {
    ini_set('error_log', laufzeit_pfad('logs') . '/php-fehler.log');
}

set_exception_handler(function (Throwable $ex): void {
    error_log('Unbehandelt: ' . get_class($ex) . ': ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Fehler: ' . $ex->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        kopfzeilen_seite();
    }
    $text = entwicklung() ? $ex->getMessage() : 'Bitte später noch einmal versuchen.';
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Fehler</title></head><body>'
        . '<h1>Es ist ein Fehler aufgetreten</h1><p>' . e($text) . '</p></body></html>';
});
