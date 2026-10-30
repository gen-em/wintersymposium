<?php
declare(strict_types=1);

/* Bild für einen Text der Webseite hochladen (Seite „Texte“, JSON). Rückmeldung 2026-10-30. */

$konto = team_tor();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    nicht_gefunden();
}
if (!csrf_ok()) {
    json_antwort(403, ['fehler' => 'Sitzung abgelaufen. Bitte die Seite neu laden.']);
}
try {
    json_antwort(200, ['url' => text_bild_speichern($_FILES['bild'] ?? [], (int)$konto['id'])]);
} catch (RuntimeException $ex) {
    json_antwort(400, ['fehler' => $ex->getMessage()]);
}
