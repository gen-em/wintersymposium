<?php
declare(strict_types=1);

/*
 * Teilnahmebescheinigung herunterladen (AP-10, E-57): nur mit Sitzung
 * („Meine Anmeldung“ über Einmal-Link oder Konto), nur wenn anwesend und
 * freigeschaltet.
 */

anm_sitzung();
$a = anm_aktuell();
if ($a === null) {
    flash_setzen('fehler', 'Bitte öffne zuerst deine Anmeldung – über den Link aus der Mail oder mit deinem Konto.');
    umleiten('/deine-anmeldung');
}
if (!zertifikat_verfuegbar($a)) {
    flash_setzen('fehler', 'Deine Teilnahmebescheinigung ist noch nicht freigeschaltet.');
    umleiten('/deine-anmeldung');
}
$inhalt = zertifikat_pdf(zertifikat_daten($a));
$name = preg_replace('/[^A-Za-z0-9-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $a['nachname']) ?: 'teilnahme');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Teilnahmebescheinigung-' . trim((string)$name, '-') . '-' . substr(va('veranstaltung_datum'), 0, 4) . '.pdf"');
header('Content-Length: ' . strlen($inhalt));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $inhalt;
