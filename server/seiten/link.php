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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $r = anm_link_einloesen($token);
    if ($r === null) {
        flash_setzen('fehler', 'Dieser Link gilt nicht mehr: Er war schon benutzt, ist abgelaufen oder die Anmeldung gibt es nicht mehr. Hier kannst du dir einen neuen schicken lassen.');
        umleiten('/deine-anmeldung');
    }
    anm_einloggen($r['anm']);
    if (!empty($r['konto_neu'])) {
        // Häkchen „Konto anlegen“ beim Anmelden: jetzt Passwort festlegen (E-48)
        tk_einloggen(konto_laden((int)$r['konto_neu']));
        $_SESSION['tk_pw'] = true;
        flash_setzen('ok', 'Deine Anmeldung ist bestätigt und dein Konto angelegt. Leg jetzt dein Passwort fest.');
        umleiten('/konto');
    }
    if ($r['meldung'] !== null) {
        flash_setzen($r['zweck'] === 'email' && str_contains($r['meldung'], 'anderen') ? 'fehler' : 'ok', $r['meldung']);
    }
    umleiten('/deine-anmeldung');
}

$link = anm_link_finden($token);
seite_kopf('Weiter', ['nav' => false]);
?>
<div class="innen form-kopf">
    <p class="kicker">Wintersymposium</p>
<?php if ($link === null): ?>
    <h1 class="titel">Link abgelaufen</h1>
    <p class="text">Dieser Link gilt nicht mehr: Er war schon benutzt oder ist abgelaufen.</p>
    <p><a class="knopf" href="/deine-anmeldung">Neuen Link anfordern</a></p>
<?php else:
    $text = ['bestaetigen' => ['Anmeldung bestätigen', 'Ein Klick, und deine Anmeldung gilt.', 'Anmeldung bestätigen'],
        'zugang' => ['Deine Anmeldung', 'Weiter zu deiner Anmeldung. Der Link gilt nur einmal.', 'Weiter'],
        'email' => ['Neue Adresse bestätigen', 'Bestätige, dass deine Anmeldung künftig an diese Adresse gehen soll.', 'Adresse bestätigen']][$link['zweck']]; ?>
    <h1 class="titel"><?= e($text[0]) ?></h1>
    <p class="text"><?= e($text[1]) ?></p>
    <form method="post" action="/link"><?= csrf_feld() ?><input type="hidden" name="t" value="<?= e($token) ?>"><button class="knopf gruen" type="submit"><?= e($text[2]) ?></button></form>
<?php endif; ?>
</div>
<?php
seite_fuss();
