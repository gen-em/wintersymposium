<?php
declare(strict_types=1);

/*
 * Einmal-Links aus Mails (AP-4): Anmeldung bestätigen, Zugang zu „Deine
 * Anmeldung“, neue Adresse bestätigen.
 *
 * Erst der Klick auf „Weiter“ (POST) verbraucht den Link. Ein bloßes Öffnen
 * (GET) tut das nicht – manche Mailprogramme und Virenscanner rufen Links
 * vorab auf und würden ihn sonst entwerten, bevor die Person klickt.
 */

anm_sitzung();
$token = is_string($_REQUEST['t'] ?? null) ? $_REQUEST['t'] : '';

if (ist_post()) {
    $r = anm_link_einloesen($token);
    if ($r === null) {
        fertig('fehler', 'Dieser Link gilt nicht mehr: Er war schon benutzt, ist abgelaufen oder die Anmeldung gibt es nicht mehr. Hier kannst du dir einen neuen schicken lassen.', '/deine-anmeldung');
    }
    if ($r['zweck'] === 'uebernehmen') {
        // Daten einer früheren Anmeldung ins Formular der aktuellen Veranstaltung (E-58)
        $_SESSION[SITZUNG_SCHLUESSEL_ANM_VORLAGE] = ['id' => (int)$r['anm'], 'zeit' => time()]; // gilt 30 Minuten (Review B-13)
        fertig('ok', 'Deine Angaben vom letzten Mal sind eingetragen. Prüf sie, wähl Workshops und schick die Anmeldung ab.', '/anmelden');
    }
    anm_einloggen($r['anm']);
    if (!empty($r['konto_neu'])) {
        // Häkchen „Konto anlegen“ beim Anmelden: jetzt Passwort festlegen (E-48)
        tk_einloggen(konto_laden((int)$r['konto_neu']));
        $_SESSION[SITZUNG_SCHLUESSEL_TK_PW] = true;
        fertig('ok', 'Deine Anmeldung ist bestätigt und dein Konto angelegt. Leg jetzt dein Passwort fest.', '/konto');
    }
    if ($r['meldung'] !== null) {
        fertig($r['zweck'] === 'email' && str_contains($r['meldung'], 'anderen') ? 'fehler' : 'ok', $r['meldung'], '/deine-anmeldung');
    }
    umleiten('/deine-anmeldung');
}

$link = anm_link_finden($token);
seite_kopf('Weiter', ['nav' => false]);
if ($link === null) {
    echo seiten_kopfzeile('Link abgelaufen', 'Wintersymposium', 'Dieser Link gilt nicht mehr: Er war schon benutzt oder ist abgelaufen.', true)
        . '<p><a class="knopf" href="/deine-anmeldung">Neuen Link anfordern</a></p></div>';
    seite_ende();
}
[$titel, $text, $knopf] = ['bestaetigen' => ['Anmeldung bestätigen', 'Ein Klick, und deine Anmeldung gilt.', 'Anmeldung bestätigen'],
    'zugang' => ['Deine Anmeldung', 'Weiter zu deiner Anmeldung. Der Link gilt nur einmal.', 'Weiter'],
    'email' => ['Neue Adresse bestätigen', 'Bestätige, dass deine Anmeldung künftig an diese Adresse gehen soll.', 'Adresse bestätigen'],
    'uebernehmen' => ['Wieder dabei', 'Weiter zur Anmeldung – deine Angaben vom letzten Mal sind schon eingetragen.', 'Zur Anmeldung']][$link['zweck']];
echo seiten_kopfzeile($titel, 'Wintersymposium', $text, true) . aktion_knopf('/link', 'einloesen', ['t' => $token], $knopf, 'knopf gruen') . '</div>';
seite_fuss();
