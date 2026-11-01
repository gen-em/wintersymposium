<?php
declare(strict_types=1);

/*
 * Einrichtungs- bzw. Passwortlink einlösen: Passwort setzen. Danach geht es
 * weiter zum Einloggen; beim ersten Mal mit Einrichtung des zweiten Faktors.
 * Den Link erzeugt ein Betreiber unter „Team-Konten“ (gültig 72 h, einmal).
 */

team_sitzung();
$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$ip = rate_merkmal_ip();
if (!rate_erlaubt('einrichten', $ip)) {
    seite_meldung(429, 'Zu viele Versuche', rate_warten_text('einrichten'));
}
$fund = konto_link_finden($token);
if ($fund === null || !konto_ist_team($fund['konto'])) {
    rate_misserfolg('einrichten', $ip);
    seite_meldung(404, 'Link ungültig', 'Dieser Link ist abgelaufen, schon benutzt oder falsch. Einen neuen bekommst du unter „Passwort vergessen?“ beim Einloggen oder von einer BetreiberIn.');
}
$k = $fund['konto'];
$neu = $fund['link']['zweck'] === 'einrichten';
$fehler = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $pw = (string)($_POST['passwort'] ?? '');
    $f = passwort_regel_fehler($pw, (string)($_POST['passwort2'] ?? ''));
    if ($f !== null) {
        $fehler[] = $f;
    } elseif (konto_link_verbrauchen((int)$fund['link']['id'])) {
        konto_passwort_setzen((int)$k['id'], $pw);
        rate_erfolg('einrichten', $ip);
        anmeldung_halb_setzen(konto_laden((int)$k['id']));
        umleiten('/einloggen');
    } else {
        seite_meldung(404, 'Link ungültig', 'Dieser Link wurde gerade schon benutzt.');
    }
}

seite_kopf($neu ? 'Konto einrichten' : 'Neues Passwort', ['nav' => false]);
echo '<div class="innen seitenkopf"><p class="kicker">Team-Bereich</p><h1 class="titel">' . ($neu ? 'Konto einrichten' : 'Neues Passwort') . '</h1></div><div class="innen schmal">';
echo fehler_html($fehler);
?>
<p>Hallo <?= e($k['name']) ?> (<?= e($k['email']) ?>). Lege dein Passwort fest<?= $neu ? '; danach richtest du den zweiten Faktor ein' : '' ?>.</p>
<form method="post" action="/einrichten" class="formular">
    <?= csrf_feld() ?>
    <input type="hidden" name="t" value="<?= e($token) ?>">
    <input type="text" name="email" value="<?= e($k['email']) ?>" autocomplete="username" hidden>
    <label for="passwort">Passwort (mindestens <?= PASSWORT_MIN_LAENGE ?> Zeichen)</label>
    <input id="passwort" name="passwort" type="password" required minlength="<?= PASSWORT_MIN_LAENGE ?>" autocomplete="new-password">
    <label for="passwort2">Passwort wiederholen</label>
    <input id="passwort2" name="passwort2" type="password" required minlength="<?= PASSWORT_MIN_LAENGE ?>" autocomplete="new-password">
    <button type="submit" class="knopf">Passwort speichern</button>
</form>
<?php
echo '</div>';
seite_fuss();
