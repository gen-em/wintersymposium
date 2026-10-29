<?php
declare(strict_types=1);

/*
 * Job-Auslöser auf der Kommandozeile für den Cron (T-105):
 *   php <Installationsordner>/cli/jobs.php
 * Gibt eine JSON-Übersicht aus; Exitcode 0 bei Erfolg.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

if (konfig_maengel() !== [] || !installiert()) {
    fwrite(STDERR, "Nicht installiert oder Konfiguration unvollständig.\n");
    exit(2);
}

$ergebnis = jobs_lauf('cli');
echo json_encode($ergebnis, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
$fehler = array_filter($ergebnis['jobs'], fn($j) => ($j['status'] ?? '') === 'fehler');
exit($fehler === [] ? 0 : 1);
