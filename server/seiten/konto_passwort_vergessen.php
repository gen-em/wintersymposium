<?php
declare(strict_types=1);

/* Passwort vergessen (AP-4b): gleiche Antwort und Dauer für jede Adresse (R-03). */

anm_sitzung();
$beginn = microtime(true);
$fehler = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $email = mail_adresse((string)($_POST['email'] ?? ''));
    if ($email === null) {
        $fehler = 'Diese E-Mail-Adresse sieht nicht wie eine Adresse aus.';
    } else {
        $ip = rate_merkmal_ip();
        $ziel = rate_merkmal_kennung('link:' . $email);
        $ok = trim((string)($_POST['website'] ?? '')) === '' && rate_erlaubt('link_ip', $ip) && rate_erlaubt('link_ziel', $ziel);
        rate_misserfolg('link_ip', $ip);
        rate_misserfolg('link_ziel', $ziel);
        if ($ok) {
            tk_passwort_vergessen($email);
        }
        rate_gleiche_dauer($beginn, 0.6);
        flash_setzen('ok', 'Wenn es zu der Adresse ein Konto gibt, ist ein Link unterwegs. Er gilt einmal und 48 Stunden (Team-Konten: 72 Stunden). Schau auch im Spam-Ordner.');
        umleiten('/konto/passwort-vergessen');
    }
}
seite_kopf('Passwort vergessen', ['aktiv' => '/deine-anmeldung']);
?>
<div class="innen form-kopf">
    <p class="kicker">Konto</p>
    <h1 class="titel">Passwort vergessen</h1>
    <p class="text">Gib die Adresse deines Kontos ein. Wir schicken dir einen Link, mit dem du ein neues Passwort festlegst. Das gilt auch für Team-Konten; der zweite Faktor bleibt dabei bestehen.</p>
</div>
<div class="innen">
    <?= meldung_html(flash_holen()) ?>
    <form class="form zg-form" method="post" action="/konto/passwort-vergessen" novalidate>
        <?= csrf_feld() ?>
        <div class="honig" aria-hidden="true"><label for="website">Website (bitte leer lassen)</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
        <div class="feld"><label for="email">E-Mail</label><input id="email" name="email" type="email" autocomplete="email" required<?= $fehler !== null ? ' aria-invalid="true" aria-describedby="f-email"' : '' ?>><?= $fehler !== null ? '<p class="feldfehler" id="f-email">' . e($fehler) . '</p>' : '' ?></div>
        <div><button class="knopf" type="submit">Link schicken</button></div>
    </form>
</div>
<?php
seite_fuss();
