<?php
declare(strict_types=1);

/* CSV-Export (AP-5, E-49): Datei herunterladen; jeder Export steht im Protokoll. */

$konto = team_tor();
$art = (string)($_GET['art'] ?? '');
$bg = (string)($_GET['bg'] ?? '');
if ($bg !== '' && !in_array($bg, va_liste('berufsgruppen'), true)) {
    $bg = '';
}
$x = export_erzeugen($art, $bg, (int)($_GET['t'] ?? -1));
if ($x === null) {
    nicht_gefunden();
}
audit_schreiben('export', 'Export ' . $art . ($bg !== '' ? ' (' . $bg . ')' : ''), (int)$konto['id']);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $x['datei'] . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $x['text'];
