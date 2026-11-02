<?php
declare(strict_types=1);

/*
 * Startseite (AP-2; Felder je Veranstaltung seit AP-18, E-70). Texte aus der
 * Tabelle inhalt, gepflegt unter Verwaltung → Startseite; Eckdaten (Datum,
 * Zeit, Ort, CME, freie Plätze) aus den Veranstaltungs-Einstellungen; das
 * Programm im veröffentlichten Stand. Aufbau in start_html() (inhalt_lib),
 * dieselbe Funktion zeichnet die Vorschau (team_startseite_vorschau.php).
 */

seite_kopf('Start', ['aktiv' => '/', 'skripte' => ['programm.js'], 'suchmaschinen' => true]);
echo start_html(start_werte());
seite_fuss();
