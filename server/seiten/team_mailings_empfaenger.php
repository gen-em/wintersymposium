<?php
declare(strict_types=1);

/* Empfängerzahl einer Auswahl für den Editor der Mailings (JSON, nur lesen). */

team_tor();
$auswahl = (string)($_GET['auswahl'] ?? '');
$bg = (string)($_GET['bg'] ?? '');
if (!array_key_exists($auswahl, rm_auswahl_optionen()) || ($bg !== '' && !in_array($bg, va_liste('berufsgruppen'), true))) {
    json_antwort(400, ['fehler' => 'Unbekannte Auswahl.']);
}
$e = rm_empfaenger($auswahl, $bg);
json_antwort(200, ['zahl' => count($e), 'namen' => array_map(fn($x) => $x['vorname'] . ' ' . $x['nachname'], $e)]);
