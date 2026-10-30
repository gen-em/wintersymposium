<?php
declare(strict_types=1);

/*
 * Ausloggen (nur per POST mit CSRF-Token, damit kein fremder Link ausloggt).
 * Eine Sitzung für alles (E-54): beendet Team-Login, Konto und Einmal-Link.
 * Team danach zum Login, alle anderen zu „Meine Anmeldung“.
 */

team_sitzung();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    umleiten(anmeldung_konto() !== null ? '/team' : '/einloggen');
}
csrf_pruefen();
$warTeam = anmeldung_konto() !== null;
anmeldung_beenden();
team_sitzung();
flash_setzen('ok', 'Du bist ausgeloggt.');
umleiten($warTeam ? '/einloggen' : '/deine-anmeldung');
