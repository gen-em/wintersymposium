<?php
declare(strict_types=1);

/*
 * Bilder der Mailings (AP-6, E-41): GET liefert ein Bild an das Team aus
 * (Editor und Vorschau), POST lädt ein neues hoch (Editor, JSON-Antwort).
 * In der Mail selbst stecken die Bilder als Anhang mit Content-ID.
 * Ausgeliefert wird nur, was das Konto sehen darf: selbst hochgeladen oder in
 * einem sichtbaren Mailing (Review S-5); sonst 404.
 */

$konto = team_tor();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        json_antwort(403, ['fehler' => 'Sitzung abgelaufen. Bitte die Seite neu laden.']);
    }
    try {
        $b = rm_bild_speichern($_FILES['bild'] ?? [], (int)$konto['id']);
        json_antwort(200, ['id' => (int)$b['id'], 'url' => '/team/mailings/bild?id=' . (int)$b['id'], 'breite' => (int)$b['breite'], 'hoehe' => (int)$b['hoehe']]);
    } catch (RuntimeException $ex) {
        json_antwort(400, ['fehler' => $ex->getMessage()]);
    }
}
$b = rm_bild((int)($_GET['id'] ?? 0));
$pfad = $b !== null ? foto_ordner() . '/' . basename($b['datei']) : '';
if ($b === null || !is_file($pfad) || !rm_bild_sichtbar($b, (int)$konto['id'])) {
    nicht_gefunden();
}
kopf_gemeinsam();
header('Content-Type: image/' . $b['typ']);
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . filesize($pfad));
readfile($pfad);
