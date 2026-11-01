<?php
declare(strict_types=1);

/* Passwort vergessen (AP-4b): gleiche Antwort und Dauer für jede Adresse (R-03). */

anm_sitzung();
$beginn = microtime(true);
$fehler = null;

if (ist_post()) {
    $email = mail_adresse(post_text('email'));
    if ($email === null) {
        $fehler = 'Diese E-Mail-Adresse sieht nicht wie eine Adresse aus.';
    } else {
        $ip = rate_merkmal_ip();
        $ziel = rate_merkmal_kennung('link:' . $email);
        $ok = trim(post_text('website')) === '' && rate_erlaubt('link_ip', $ip) && rate_erlaubt('link_ziel', $ziel);
        rate_misserfolg('link_ip', $ip);
        rate_misserfolg('link_ziel', $ziel);
        if ($ok) {
            tk_passwort_vergessen($email);
        }
        rate_gleiche_dauer($beginn, 0.6);
        fertig('ok', 'Wenn es zu der Adresse ein Konto gibt, ist ein Link unterwegs. Er gilt einmal und ' . dauer_text(TK_LINK_GUELTIG_S) . ' (Team-Konten: ' . dauer_text(KONTO_LINK_GUELTIG_S) . '). Schau auch im Spam-Ordner.', '/konto/passwort-vergessen');
    }
}
seite_kopf('Passwort vergessen', ['aktiv' => '/deine-anmeldung']);
?>
<?= seiten_kopfzeile('Passwort vergessen', 'Konto', 'Gib die Adresse deines Kontos ein. Wir schicken dir einen Link, mit dem du ein neues Passwort festlegst. Das gilt auch für Team-Konten; der zweite Faktor bleibt dabei bestehen.') ?>
<div class="innen">
    <?= meldung_html(flash_holen()) ?>
    <form class="form zg-form" method="post" action="/konto/passwort-vergessen" novalidate>
        <?= csrf_feld() ?>
        <div class="honig" aria-hidden="true"><?= feld('website', 'Website (bitte leer lassen)', ['ohne_rahmen' => true, 'attr' => ['tabindex' => '-1', 'autocomplete' => 'off']]) ?></div>
        <?= feld('email', 'E-Mail', ['typ' => 'email', 'pflicht' => true, 'fehler' => $fehler, 'attr' => ['autocomplete' => 'email']]) ?>
        <div><button class="knopf" type="submit">Link schicken</button></div>
    </form>
</div>
<?php
seite_fuss();
