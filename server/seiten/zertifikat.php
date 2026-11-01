<?php
declare(strict_types=1);

/*
 * Teilnahmebescheinigung herunterladen (AP-10, E-57): nur mit Sitzung
 * („Meine Anmeldung“ über Einmal-Link oder Konto), nur wenn anwesend und
 * freigeschaltet.
 */

anm_sitzung();
if (rtrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/') === '/konto/zertifikat') {
    // Aus „Mein Konto“ (E-58): Bescheinigung einer eigenen Anmeldung, auch aus vergangenen Veranstaltungen
    $tk = tk_aktuell() ?? team_gesehen();
    $a = $tk !== null ? tk_eigene_anmeldung((int)$tk['id'], (int)($_GET['a'] ?? 0)) : null;
    if ($a === null) {
        nicht_gefunden();
    }
} else {
    $a = anm_aktuell();
}
if ($a === null) {
    flash_setzen('fehler', 'Bitte öffne zuerst deine Anmeldung – über den Link aus der Mail oder mit deinem Konto.');
    umleiten('/deine-anmeldung');
}
if (!zertifikat_verfuegbar($a)) {
    flash_setzen('fehler', 'Deine Teilnahmebescheinigung ist noch nicht freigeschaltet.');
    umleiten('/deine-anmeldung');
}
va_kontext((int)$a['veranstaltung_id']);
$inhalt = zertifikat_pdf_fuer($a);
$name = preg_replace('/[^A-Za-z0-9-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $a['nachname']) ?: 'teilnahme');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Teilnahmebescheinigung-' . trim((string)$name, '-') . '-' . substr(va('veranstaltung_datum'), 0, 4) . '.pdf"');
header('Content-Length: ' . strlen($inhalt));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $inhalt;
