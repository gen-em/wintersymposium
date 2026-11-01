<?php
declare(strict_types=1);

/*
 * Vorschau der Startseite (AP-18, E-70 (6)): zeichnet die echte Startseite
 * mit den Werten aus dem Formular von Verwaltung → Startseite, ohne zu
 * speichern. Nur per POST mit CSRF und für das Team; die Antwort ist nur in
 * Seiten derselben Herkunft einbettbar (iframe der Seite „Startseite“) und
 * wird nicht zwischengespeichert. Navigation wie für Besucherinnen und
 * Besucher, Links ohne Wirkung (CSS .vorschau-seite). Hinweise (Größenstufe,
 * Trennung, leere Kacheln) stehen als data-hinweise am body; startseite.js
 * liest sie aus.
 */

team_tor();
if (!ist_post()) {
    umleiten('/team/startseite');
}
$werte = start_werte($_POST);
seite_kopf('Vorschau Startseite', ['aktiv' => '/', 'vorschau' => start_hinweise($werte)]);
echo start_html($werte);
seite_fuss();
