<?php
declare(strict_types=1);

/*
 * Health-Endpunkt (T-107). Nur mit Token aus config.php (Kopfzeile
 * X-Health-Token oder ?token=); ohne oder mit falschem Token 404, damit der
 * Endpunkt nach außen nicht auffällt. Fehlversuche zählen im Ratenschutz.
 */

$token = (string)($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? ($_GET['token'] ?? ''));
$merkmal = rate_merkmal_ip();
if (!rate_erlaubt('health', $merkmal)) {
    nicht_gefunden();
}
if ($token === '' || !hash_equals((string)konfig('health_token'), $token)) {
    rate_misserfolg('health', $merkmal);
    nicht_gefunden();
}
rate_erfolg('health', $merkmal);

$letzterLauf = einstellung('jobs_letzter_lauf');
$fehlerJobs = db_zeilen('SELECT name FROM job WHERE letzter_fehler IS NOT NULL');
json_antwort(200, [
    'status' => 'ok',
    'version' => app_version(),
    'umgebung' => umgebung(),
    'zeit' => iso_utc(db_jetzt()),
    'datenbank' => 'ok',
    'schema' => ['ist' => migration_ist(), 'soll' => migration_soll()],
    'wartung' => wartung_aktiv(),
    'jobs' => [
        'letzter_lauf' => iso_utc($letzterLauf),
        'letzter_ausloeser' => einstellung('jobs_letzter_ausloeser'),
        'mit_fehler' => array_column($fehlerJobs, 'name'),
    ],
]);
