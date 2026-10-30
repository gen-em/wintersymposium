<?php
declare(strict_types=1);

/*
 * Newsletter bestätigen (Double-Opt-In, AP-7). Erst der Klick auf
 * „Bestätigen“ (POST) trägt ein – Mailprogramme und Virenscanner öffnen Links
 * vorab und würden sonst ohne Zutun der Person bestätigen.
 */

anm_sitzung();
$token = is_string($_REQUEST['t'] ?? null) ? $_REQUEST['t'] : '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $p = nl_bestaetigen($token);
    if ($p === null) {
        flash_setzen('fehler', 'Dieser Link gilt nicht mehr (schon benutzt oder älter als ' . NL_BESTAETIGEN_TAGE . ' Tage). Trag dich einfach noch einmal ein.');
        umleiten('/newsletter');
    }
    seite_kopf('Newsletter bestätigt', ['nav' => false]);
    echo '<div class="innen form-kopf"><p class="kicker">Newsletter</p><h1 class="titel">Danke!</h1>'
        . '<p class="text">Du bist eingetragen. Abmelden oder Listen ändern kannst du jederzeit über den Link in jeder Newsletter-Mail.</p>'
        . '<p><a class="knopf" href="/">Zur Startseite</a></p></div>';
    seite_fuss();
    exit;
}
$f = nl_link_finden($token, 'bestaetigen');
seite_kopf('Newsletter bestätigen', ['nav' => false]);
?>
<div class="innen form-kopf">
    <p class="kicker">Newsletter</p>
<?php if ($f === null): ?>
    <h1 class="titel">Link abgelaufen</h1>
    <p class="text">Dieser Link gilt nicht mehr: Er war schon benutzt oder ist älter als <?= NL_BESTAETIGEN_TAGE ?> Tage.</p>
    <p><a class="knopf" href="/newsletter">Noch einmal eintragen</a></p>
<?php else:
    $offen = array_keys(array_filter(nl_abos((int)$f['person']['id']), fn($s) => $s === 'offen'));
    $namen = array_column(array_filter(nl_listen(), fn($l) => in_array((int)$l['id'], $offen, true)), 'name'); ?>
    <h1 class="titel">Newsletter bestätigen</h1>
    <p class="text">Für <?= e($f['person']['email']) ?>: <?= e($namen !== [] ? implode(', ', $namen) : 'die gewählten Newsletter') ?>.</p>
    <p class="meta"><?= e(NL_EINWILLIGUNG) ?></p>
    <form method="post" action="/newsletter/bestaetigen"><?= csrf_feld() ?><input type="hidden" name="t" value="<?= e($token) ?>"><button class="knopf gruen" type="submit">Bestätigen</button></form>
<?php endif; ?>
</div>
<?php
seite_fuss();
