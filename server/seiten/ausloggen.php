<?php
declare(strict_types=1);

/* Ausloggen (nur per POST mit CSRF-Token, damit kein fremder Link ausloggt). */

team_sitzung();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    umleiten(anmeldung_konto() !== null ? '/team' : '/einloggen');
}
csrf_pruefen();
anmeldung_beenden();
team_sitzung();
flash_setzen('ok', 'Du bist ausgeloggt.');
umleiten('/einloggen');
