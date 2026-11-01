<?php
declare(strict_types=1);

/*
 * Links für Teilnehmenden-Konten (AP-4b): Adresse bestätigen, neues Passwort,
 * neue Adresse. Wie bei den Einmal-Links verbraucht erst der Klick auf
 * „Weiter“ (POST) den Link, nicht das bloße Öffnen (Mail-Scanner).
 */

anm_sitzung();
$token = is_string($_REQUEST['t'] ?? null) ? $_REQUEST['t'] : '';

if (ist_post()) {
    $r = tk_link_einloesen($token);
    if ($r === null || $r['konto'] === null) {
        fertig('fehler', 'Dieser Link gilt nicht mehr: Er war schon benutzt oder ist abgelaufen.', '/deine-anmeldung');
    }
    tk_einloggen($r['konto']);
    if ($r['zweck'] !== 'email') {
        $_SESSION[SITZUNG_SCHLUESSEL_TK_PW] = true; // nach Bestätigung oder „Passwort vergessen“: neues Passwort festlegen
    }
    fertig(str_contains((string)$r['meldung'], 'gehört schon') ? 'fehler' : 'ok', (string)$r['meldung'], '/konto');
}

$f = konto_link_finden($token);
$gueltig = $f !== null && $f['konto']['rolle'] === 'teilnehmend' && in_array($f['link']['zweck'], ['bestaetigen', 'passwort', 'email'], true);
seite_kopf('Weiter', ['nav' => false]);
if (!$gueltig) {
    echo seiten_kopfzeile('Link abgelaufen', 'Konto', 'Dieser Link gilt nicht mehr: Er war schon benutzt oder ist abgelaufen.', true)
        . '<p><a class="knopf" href="/konto/passwort-vergessen">Neuen Link anfordern</a></p></div>';
    seite_ende();
}
[$titel, $text, $knopf] = ['bestaetigen' => ['Adresse bestätigen', 'Ein Klick, dann legst du dein Passwort fest.', 'Bestätigen'],
    'passwort' => ['Neues Passwort', 'Weiter zum neuen Passwort. Der Link gilt nur einmal.', 'Weiter'],
    'email' => ['Neue Adresse bestätigen', 'Bestätige, dass dein Konto künftig diese Adresse nutzt.', 'Adresse bestätigen']][$f['link']['zweck']];
echo seiten_kopfzeile($titel, 'Konto', $text, true) . aktion_knopf('/konto/link', 'einloesen', ['t' => $token], $knopf, 'knopf gruen') . '</div>';
seite_fuss();
