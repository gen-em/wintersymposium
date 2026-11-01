<?php
declare(strict_types=1);

/*
 * Newsletter bestätigen (Double-Opt-In, AP-7). Erst der Klick auf
 * „Bestätigen“ (POST) trägt ein – Mailprogramme und Virenscanner öffnen Links
 * vorab und würden sonst ohne Zutun der Person bestätigen.
 */

anm_sitzung();
$token = is_string($_REQUEST['t'] ?? null) ? $_REQUEST['t'] : '';
if (ist_post()) {
    $p = nl_bestaetigen($token);
    if ($p === null) {
        fertig('fehler', 'Dieser Link gilt nicht mehr (schon benutzt oder älter als ' . NL_BESTAETIGEN_TAGE . ' Tage). Trag dich einfach noch einmal ein.', '/newsletter');
    }
    seite_kopf('Newsletter bestätigt', ['nav' => false]);
    echo seiten_kopfzeile('Danke!', 'Newsletter', 'Du bist eingetragen. Abmelden oder Listen ändern kannst du jederzeit über den Link in jeder Newsletter-Mail.', true)
        . '<p><a class="knopf" href="/">Zur Startseite</a></p></div>';
    seite_ende();
}
$f = nl_link_finden($token, 'bestaetigen');
seite_kopf('Newsletter bestätigen', ['nav' => false]);
if ($f === null) {
    echo seiten_kopfzeile('Link abgelaufen', 'Newsletter', 'Dieser Link gilt nicht mehr: Er war schon benutzt oder ist älter als ' . NL_BESTAETIGEN_TAGE . ' Tage.', true)
        . '<p><a class="knopf" href="/newsletter">Noch einmal eintragen</a></p></div>';
    seite_ende();
}
$offen = array_keys(array_filter(nl_abos((int)$f['person']['id']), fn($s) => $s === 'offen'));
$namen = array_column(array_filter(nl_listen(), fn($l) => in_array((int)$l['id'], $offen, true)), 'name');
echo seiten_kopfzeile('Newsletter bestätigen', 'Newsletter', 'Für ' . $f['person']['email'] . ': ' . ($namen !== [] ? implode(', ', $namen) : 'die gewählten Newsletter') . '.', true)
    . '<p class="meta">' . e(NL_EINWILLIGUNG) . '</p>'
    . aktion_knopf('/newsletter/bestaetigen', 'bestaetigen', ['t' => $token], 'Bestätigen', 'knopf gruen')
    . '</div>';
seite_fuss();
