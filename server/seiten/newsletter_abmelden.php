<?php
declare(strict_types=1);

/*
 * Abmelden mit einem Klick (AP-7): Ziel von List-Unsubscribe. Mailprogramme
 * schicken nach RFC 8058 ein POST mit „List-Unsubscribe=One-Click“ – das
 * meldet sofort von den Listen ab, ohne Sitzung und ohne CSRF-Token (der Link
 * selbst ist der Nachweis). Ein Aufruf im Browser (GET) zeigt einen Knopf.
 */

$token = is_string($_REQUEST['t'] ?? null) ? $_REQUEST['t'] : '';
$f = nl_link_finden($token, 'verwalten');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') { // ohne CSRF: Mailprogramme schicken One-Click ohne Sitzung (RFC 8058)
    if ($f !== null) {
        nl_abmelden((int)$f['person']['id']);
    }
    if (post_text('List-Unsubscribe') === 'One-Click') {
        kopf_gemeinsam();
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Abgemeldet.';
        exit;
    }
    seite_kopf('Abgemeldet', ['nav' => false]);
    echo seiten_kopfzeile('Abgemeldet', 'Newsletter', 'Du bekommst keine Newsletter mehr. Deine Adresse ist gelöscht.', true)
        . '<p><a class="knopf zweit" href="/">Zur Startseite</a></p></div>';
    seite_ende();
}
seite_kopf('Abmelden', ['nav' => false]);
if ($f === null) {
    echo seiten_kopfzeile('Abmelden', 'Newsletter', 'Dieser Link gilt nicht mehr – oder du bist schon abgemeldet.');
    seite_ende();
}
// Formular von Hand: ohne Sitzung und CSRF-Token (aktion_knopf setzt beides voraus)
echo seiten_kopfzeile('Abmelden', 'Newsletter', $f['person']['email'] . ' von allen Newslettern abmelden?', true)
    . '<form method="post" action="/newsletter/abmelden">' . versteckt_html(['t' => $token]) . '<button class="knopf gefahr" type="submit">Ja, abmelden</button></form>'
    . '<p class="meta klein"><a href="/newsletter/verwalten?t=' . e($token) . '">Nur einzelne Listen ändern</a></p></div>';
seite_fuss();
