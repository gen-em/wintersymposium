<?php
declare(strict_types=1);

/*
 * Fotos der Dozierenden aus dem Laufzeitordner ausliefern (außerhalb des
 * Webroots). Nur Dateinamen, die foto_speichern() erzeugt; lange zwischenspeicherbar,
 * weil jeder neue Upload einen neuen Namen bekommt.
 */

$name = substr((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), strlen('/bild/'));
// Fotos der Dozierenden und Bilder der Texte (Rückmeldung 2026-10-30); Bilder der Mailings nur über /team/mailings/bild
if (preg_match('/^(dozent-\d+-[0-9a-f]{12}\.jpg|text-[0-9a-f]{16}\.(jpg|png))$/', $name) !== 1 || !is_file(foto_ordner() . '/' . $name)) {
    nicht_gefunden();
}
kopf_gemeinsam();
header('Content-Type: ' . (str_ends_with($name, '.png') ? 'image/png' : 'image/jpeg'));
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . filesize(foto_ordner() . '/' . $name));
readfile(foto_ordner() . '/' . $name);
