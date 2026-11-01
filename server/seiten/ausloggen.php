<?php
declare(strict_types=1);

/*
 * Ausloggen (nur per POST mit CSRF-Token, damit kein fremder Link ausloggt).
 * Eine Sitzung für alles (E-54): beendet Team-Login, Konto und Einmal-Link.
 * Team danach zum Login, alle anderen zu „Meine Anmeldung“.
 */

team_sitzung();
if (!ist_post()) {
    umleiten(anmeldung_konto() !== null ? '/team' : '/einloggen');
}
$warTeam = anmeldung_konto() !== null;
anmeldung_beenden();
team_sitzung();
fertig('ok', 'Du bist ausgeloggt.', $warTeam ? '/einloggen' : '/deine-anmeldung');
