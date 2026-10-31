<?php
declare(strict_types=1);

/*
 * Health-Endpunkt (T-107). Nur mit Token aus config.php (Kopfzeile
 * X-Health-Token oder ?token=); ohne oder mit falschem Token 404, damit der
 * Endpunkt nach außen nicht auffällt. Fehlversuche zählen im Ratenschutz.
 *
 * Die Zeilen kommen aus betrieb_status() wie auf der Seite Betrieb → Status
 * (AP-15, E-65), ohne Logzeilen, Token und Pfade. status ist „fehler“, sobald
 * eine Zeile rot ist; HTTP bleibt 200, damit das Monitoring den Text liest.
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

$zeilen = betrieb_status();
json_antwort(200, [
    'status' => betrieb_gesamt($zeilen) === 'fehler' ? 'fehler' : 'ok',
    'ampel' => betrieb_gesamt($zeilen),
    'version' => app_version(),
    'umgebung' => umgebung(),
    'zeit' => iso_utc(db_jetzt()),
    'schema' => ['ist' => migration_ist(), 'soll' => migration_soll()],
    'wartung' => wartung_aktiv(),
    'zeilen' => betrieb_status_json($zeilen),
]);
