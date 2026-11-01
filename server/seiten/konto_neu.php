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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $eingabe = $_POST;
    $vorname = trim(preg_replace('/\s+/u', ' ', (string)($_POST['vorname'] ?? '')) ?? '');
    $nachname = trim(preg_replace('/\s+/u', ' ', (string)($_POST['nachname'] ?? '')) ?? '');
    $email = mail_adresse((string)($_POST['email'] ?? ''));
    if ($vorname === '' || mb_strlen($vorname) > 80) {
        $fehler['vorname'] = 'Bitte gib deinen Vornamen an.';
    }
    if ($nachname === '' || mb_strlen($nachname) > 80) {
        $fehler['nachname'] = 'Bitte gib deinen Nachnamen an.';
    }
    if ($email === null) {
        $fehler['email'] = 'Diese E-Mail-Adresse sieht nicht wie eine Adresse aus.';
    }
    if (empty($_POST['einwilligung'])) {
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
$v = fn(string $f) => e(is_string($eingabe[$f] ?? null) ? $eingabe[$f] : '');
$fehlerFeld = fn(string $f) => isset($fehler[$f]) ? '<p class="feldfehler" id="f-' . $f . '">' . e($fehler[$f]) . '</p>' : '';
$ungueltig = fn(string $f) => isset($fehler[$f]) ? ' aria-invalid="true" aria-describedby="f-' . $f . '"' : '';

seite_kopf('Konto anlegen', ['aktiv' => '/deine-anmeldung', 'skripte' => ['pow.js']]);
?>
<div class="innen form-kopf">
    <p class="kicker">Konto</p>
    <h1 class="titel">Konto anlegen</h1>
<?php if ($danke): ?>
    <div class="kasten danke" role="status">
        <p class="kicker">Fast geschafft</p>
        <h2 class="zwischen">Schau in dein Postfach.</h2>
        <p>Wir haben dir eine Mail mit einem Link geschickt. Damit bestätigst du deine Adresse und legst dein Passwort fest. Der Link gilt <?= e(dauer_text(TK_LINK_GUELTIG_S)) ?>.</p>
        <p class="meta">Gibt es für die Adresse schon ein Konto, bekommst du stattdessen einen Hinweis zum Einloggen.</p>
    </div>
</div>
<?php seite_fuss(); return; endif; ?>
    <p class="text">Ein Konto brauchst du nicht – anmelden geht auch ohne. Mit Konto loggst du dich mit Passwort ein, statt dir Links schicken zu lassen.</p>
</div>
<div class="innen">
    <?php if ($fehler !== []): ?><div class="meldung fehler" role="alert">Noch kein Konto angelegt. Bitte prüf die markierten Angaben.</div><?php endif; ?>
    <form class="form" method="post" action="/konto/neu" novalidate data-pow data-pow-bits="<?= SPAM_POW_BITS ?>" data-pow-worker="/assets/pow-worker.js">
        <?= csrf_feld() ?>
        <div class="paar">
            <div class="feld"><label for="vorname">Vorname</label><input id="vorname" name="vorname" type="text" autocomplete="given-name" maxlength="80" value="<?= $v('vorname') ?>"<?= $ungueltig('vorname') ?>><?= $fehlerFeld('vorname') ?></div>
            <div class="feld"><label for="nachname">Nachname</label><input id="nachname" name="nachname" type="text" autocomplete="family-name" maxlength="80" value="<?= $v('nachname') ?>"<?= $ungueltig('nachname') ?>><?= $fehlerFeld('nachname') ?></div>
        </div>
        <div class="feld"><label for="email">E-Mail</label><input id="email" name="email" type="email" autocomplete="email" maxlength="190" value="<?= $v('email') ?>"<?= $ungueltig('email') ?>><?= $fehlerFeld('email') ?></div>
        <div class="feld haken-liste">
            <label class="haken"><input type="checkbox" name="einwilligung" value="1"<?= !empty($eingabe['einwilligung']) ? ' checked' : '' ?><?= $ungueltig('einwilligung') ?>><span>Ich habe die <a href="/datenschutz" target="_blank" rel="noopener">Datenschutzerklärung</a> gelesen und bin einverstanden, dass meine Angaben für mein Konto gespeichert werden.</span></label>
            <?= $fehlerFeld('einwilligung') ?>
        </div>
        <?= spam_felder() ?>
        <div class="absenden"><button class="knopf" type="submit" data-pow-knopf>Konto anlegen</button>
            <p class="meta klein zustand" data-pow-zustand>Ohne JavaScript lässt sich das Formular nicht absenden (Sicherheitsprüfung gegen Spam).</p></div>
    </form>
    <p class="meta klein">Schon ein Konto? <a href="/einloggen">Einloggen</a></p>
</div>
<?php
seite_fuss();
