<?php
declare(strict_types=1);

/*
 * Export (AP-5, E-49; xlsx und PDF O-35, E-56): Datei herunterladen; jeder
 * Export steht im Protokoll. ?format=csv (Standard) | xlsx | pdf.
 */

$konto = team_tor();
$art = (string)($_GET['art'] ?? '');
$bg = (string)($_GET['bg'] ?? '');
if ($bg !== '' && !in_array($bg, va_liste('berufsgruppen'), true)) {
    $bg = '';
}
$format = (string)($_GET['format'] ?? 'csv');
if ($art === 'cme_nachweise' && $konto['rolle'] !== 'betreiber') {
    nicht_gefunden(); // CME-Nachweise nur für Betreiber (AP-9)
}
$x = export_erzeugen($art, $bg, (int)($_GET['t'] ?? -1));
$datei = $x !== null && isset(EXPORT_FORMATE[$format]) ? export_datei($x, $format) : null;
if ($datei === null) {
    nicht_gefunden();
}
audit_schreiben('export', 'Export ' . $art . ' als ' . EXPORT_FORMATE[$format] . ($bg !== '' ? ' (' . $bg . ')' : ''), (int)$konto['id']);
header('Content-Type: ' . $datei['typ']);
header('Content-Disposition: attachment; filename="' . $datei['datei'] . '"');
header('Content-Length: ' . strlen($datei['inhalt']));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $datei['inhalt'];
