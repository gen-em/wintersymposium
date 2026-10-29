<?php
declare(strict_types=1);

/*
 * Links für Teilnehmenden-Konten (AP-4b): Adresse bestätigen, neues Passwort,
 * neue Adresse. Wie bei den Einmal-Links verbraucht erst der Klick auf
 * „Weiter“ (POST) den Link, nicht das bloße Öffnen (Mail-Scanner).
 */

anm_sitzung();
$token = is_string($_REQUEST['t'] ?? null) ? $_REQUEST['t'] : '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $r = tk_link_einloesen($token);
    if ($r === null || $r['konto'] === null) {
        flash_setzen('fehler', 'Dieser Link gilt nicht mehr: Er war schon benutzt oder ist abgelaufen.');
        umleiten('/deine-anmeldung');
    }
    tk_einloggen($r['konto']);
    if ($r['zweck'] !== 'email') {
        $_SESSION['tk_pw'] = true; // nach Bestätigung oder „Passwort vergessen“: neues Passwort festlegen
    }
    flash_setzen(str_contains((string)$r['meldung'], 'gehört schon') ? 'fehler' : 'ok', (string)$r['meldung']);
    umleiten('/konto');
}

$f = konto_link_finden($token);
$gueltig = $f !== null && $f['konto']['rolle'] === 'teilnehmend' && in_array($f['link']['zweck'], ['bestaetigen', 'passwort', 'email'], true);
seite_kopf('Weiter', ['nav' => false]);
?>
<div class="innen form-kopf">
    <p class="kicker">Konto</p>
<?php if (!$gueltig): ?>
    <h1 class="titel">Link abgelaufen</h1>
    <p class="text">Dieser Link gilt nicht mehr: Er war schon benutzt oder ist abgelaufen.</p>
    <p><a class="knopf" href="/konto/passwort-vergessen">Neuen Link anfordern</a></p>
<?php else:
    $text = ['bestaetigen' => ['Adresse bestätigen', 'Ein Klick, dann legst du dein Passwort fest.', 'Bestätigen'],
        'passwort' => ['Neues Passwort', 'Weiter zum neuen Passwort. Der Link gilt nur einmal.', 'Weiter'],
        'email' => ['Neue Adresse bestätigen', 'Bestätige, dass dein Konto künftig diese Adresse nutzt.', 'Adresse bestätigen']][$f['link']['zweck']]; ?>
    <h1 class="titel"><?= e($text[0]) ?></h1>
    <p class="text"><?= e($text[1]) ?></p>
    <form method="post" action="/konto/link"><?= csrf_feld() ?><input type="hidden" name="t" value="<?= e($token) ?>"><button class="knopf gruen" type="submit"><?= e($text[2]) ?></button></form>
<?php endif; ?>
</div>
<?php
seite_fuss();
