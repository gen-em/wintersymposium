<?php
declare(strict_types=1);

/*
 * Dateien der Teilnahme (AP-10, E-57) für das Team:
 *   /team/teilnahme/liste        Unterschriftenliste (PDF; liste=alle|aerzte, sort=…)
 *   /team/teilnahme/zertifikat   Zertifikat einer Person (a=…) oder Beispiel (beispiel=1), auch vor der Freischaltung
 *   /team/teilnahme/unterschrift Bild der Unterschrift
 */

$konto = team_tor();
$pfad = rtrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
if ($pfad === '/team/teilnahme/unterschrift') {
    if (!unterschrift_da()) {
        nicht_gefunden();
    }
    header('Content-Type: image/png');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile(unterschrift_pfad());
    exit;
}
if ($pfad === '/team/teilnahme/liste') {
    $aerzte = ($_GET['liste'] ?? '') === 'aerzte';
    $inhalt = unterschriftenliste_pdf($aerzte, (string)($_GET['sort'] ?? 'nachname'));
    audit_schreiben('export', 'Unterschriftenliste' . ($aerzte ? ' ÄrztInnen' : '') . ' als PDF', (int)$konto['id']);
    $datei = 'unterschriftenliste' . ($aerzte ? '-aerztinnen' : '') . '-' . va('veranstaltung_datum') . '.pdf';
    $art = 'attachment';
} else {
    if (!empty($_GET['beispiel'])) {
        $inhalt = zertifikat_pdf(zertifikat_beispiel());
        $datei = 'zertifikat-beispiel.pdf';
    } else {
        $a = anm_laden((int)($_GET['a'] ?? 0));
        if ($a === null) {
            nicht_gefunden();
        }
        $inhalt = zertifikat_pdf_fuer($a);
        $datei = 'zertifikat-' . zertifikat_nr((int)$a['id']) . '.pdf';
    }
    $art = 'inline';
}
header('Content-Type: application/pdf');
header('Content-Disposition: ' . $art . '; filename="' . $datei . '"');
header('Content-Length: ' . strlen($inhalt));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $inhalt;
