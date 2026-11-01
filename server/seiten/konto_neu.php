<?php
declare(strict_types=1);

/*
 * Konto anlegen ohne Anmeldung zum Symposium (AP-4b, E-30), z. B. für den
 * Newsletter (AP-7). Gleiche Antwort für jede Adresse (R-03), Spamschutz wie
 * beim Anmeldeformular. Das Passwort folgt nach der Bestätigung per Mail.
 */

anm_sitzung();
if (tk_aktuell() !== null) {
    umleiten('/konto');
}
$beginn = microtime(true);
$fehler = [];
$eingabe = [];
$danke = false;

if (ist_post()) {
    $eingabe = $_POST;
    $vorname = trim(preg_replace('/\s+/u', ' ', post_text('vorname')) ?? '');
    $nachname = trim(preg_replace('/\s+/u', ' ', post_text('nachname')) ?? '');
    $email = mail_adresse(post_text('email'));
    if ($vorname === '' || mb_strlen($vorname) > 80) {
        $fehler['vorname'] = 'Bitte gib deinen Vornamen an.';
    }
    if ($nachname === '' || mb_strlen($nachname) > 80) {
        $fehler['nachname'] = 'Bitte gib deinen Nachnamen an.';
    }
    if ($email === null) {
        $fehler['email'] = 'Diese E-Mail-Adresse sieht nicht wie eine Adresse aus.';
    }
    if (!post_haken('einwilligung')) {
        $fehler['einwilligung'] = 'Ohne dein Einverständnis können wir kein Konto anlegen.';
    }
    if ($fehler === []) {
        $ip = rate_merkmal_ip();
        $ziel = rate_merkmal_kennung('link:' . $email);
        $mensch = spam_ok($_POST) && rate_erlaubt('anmelden', $ip) && rate_erlaubt('link_ziel', $ziel) && !spam_wegwerf($email);
        rate_misserfolg('anmelden', $ip);
        rate_misserfolg('link_ziel', $ziel);
        if ($mensch) {
            tk_registrieren($vorname . ' ' . $nachname, $email);
        }
        rate_gleiche_dauer($beginn, 0.6);
        $danke = true;
    }
}

seite_kopf('Konto anlegen', ['aktiv' => '/deine-anmeldung', 'skripte' => ['pow.js']]);
if ($danke) {
    echo seiten_kopfzeile('Konto anlegen', 'Konto', '', true); ?>
    <div class="kasten danke" role="status">
        <p class="kicker">Fast geschafft</p>
        <h2 class="zwischen">Schau in dein Postfach.</h2>
        <p>Wir haben dir eine Mail mit einem Link geschickt. Damit bestätigst du deine Adresse und legst dein Passwort fest. Der Link gilt <?= e(dauer_text(TK_LINK_GUELTIG_S)) ?>.</p>
        <p class="meta">Gibt es für die Adresse schon ein Konto, bekommst du stattdessen einen Hinweis zum Einloggen.</p>
    </div>
</div>
<?php
    seite_ende();
}
echo seiten_kopfzeile('Konto anlegen', 'Konto', 'Ein Konto brauchst du nicht – anmelden geht auch ohne. Mit Konto loggst du dich mit Passwort ein, statt dir Links schicken zu lassen.');
?>
<div class="innen">
    <?= $fehler !== [] ? meldung_html('Noch kein Konto angelegt. Bitte prüf die markierten Angaben.', 'fehler') : '' ?>
    <form class="form" method="post" action="/konto/neu" novalidate data-pow data-pow-bits="<?= SPAM_POW_BITS ?>" data-pow-worker="/assets/pow-worker.js">
        <?= csrf_feld() ?>
        <div class="paar">
            <?= feld('vorname', 'Vorname', ['wert' => wert($eingabe, [], 'vorname'), 'fehler' => $fehler['vorname'] ?? null, 'attr' => ['autocomplete' => 'given-name', 'maxlength' => 80]]) ?>
            <?= feld('nachname', 'Nachname', ['wert' => wert($eingabe, [], 'nachname'), 'fehler' => $fehler['nachname'] ?? null, 'attr' => ['autocomplete' => 'family-name', 'maxlength' => 80]]) ?>
        </div>
        <?= feld('email', 'E-Mail', ['typ' => 'email', 'wert' => wert($eingabe, [], 'email'), 'fehler' => $fehler['email'] ?? null, 'attr' => ['autocomplete' => 'email', 'maxlength' => 190]]) ?>
        <div class="feld haken-liste">
            <?= feld('einwilligung', '', ['typ' => 'haken', 'wert' => post_haken('einwilligung'), 'fehler' => $fehler['einwilligung'] ?? null,
                'label_html' => 'Ich habe die <a href="/datenschutz" target="_blank" rel="noopener">Datenschutzerklärung</a> gelesen und bin einverstanden, dass meine Angaben für mein Konto gespeichert werden.']) ?>
        </div>
        <?= spam_felder() ?>
        <div class="absenden"><button class="knopf" type="submit" data-pow-knopf>Konto anlegen</button>
            <p class="meta klein zustand" data-pow-zustand>Ohne JavaScript lässt sich das Formular nicht absenden (Sicherheitsprüfung gegen Spam).</p></div>
    </form>
    <p class="meta klein">Schon ein Konto? <a href="/einloggen">Einloggen</a></p>
</div>
<?php
seite_fuss();
