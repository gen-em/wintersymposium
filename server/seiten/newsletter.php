<?php
declare(strict_types=1);

/*
 * Newsletter abonnieren (AP-7, E-52): Adresse, Vorname (freiwillig), Listen.
 * Spamschutz wie bei der Anmeldung; gleiche Antwort für jede Adresse (R-03).
 * Eingetragen ist man erst nach dem Link in der Bestätigungsmail.
 */

anm_sitzung();
$beginn = microtime(true);
$listen = nl_listen(true);
$fehler = [];
$eingabe = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $eingabe = $_POST;
    $email = mail_adresse(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $gewaehlt = array_values(array_intersect(array_map('intval', (array)($_POST['listen'] ?? [])), array_map('intval', array_column($listen, 'id'))));
    if ($email === null) {
        $fehler['email'] = 'Diese E-Mail-Adresse sieht nicht wie eine Adresse aus.';
    }
    if ($gewaehlt === []) {
        $fehler['listen'] = 'Bitte mindestens einen Newsletter wählen.';
    }
    if (empty($_POST['einwilligung'])) {
        $fehler['einwilligung'] = 'Ohne deine Einwilligung können wir dir keinen Newsletter schicken.';
    }
    if ($fehler === []) {
        $ip = rate_merkmal_ip();
        $ziel = rate_merkmal_kennung('newsletter:' . $email);
        $ok = spam_ok($_POST) && !spam_wegwerf($email) && rate_erlaubt('link_ip', $ip) && rate_erlaubt('link_ziel', $ziel);
        rate_misserfolg('link_ip', $ip);
        rate_misserfolg('link_ziel', $ziel);
        if ($ok) {
            nl_anfragen($email, is_string($_POST['vorname'] ?? null) ? $_POST['vorname'] : '', $gewaehlt, 'formular');
        }
        rate_gleiche_dauer($beginn, 0.6);
        flash_setzen('ok', 'Fast geschafft: Schau in dein Postfach und bestätige den Newsletter über den Link in der Mail. Ohne Bestätigung tragen wir dich nicht ein.');
        umleiten('/newsletter');
    }
}
$fehlerFeld = fn(string $k) => isset($fehler[$k]) ? '<p class="feldfehler" id="f-' . $k . '">' . e($fehler[$k]) . '</p>' : '';
$ungueltig = fn(string $k) => isset($fehler[$k]) ? ' aria-invalid="true" aria-describedby="f-' . $k . '"' : '';
seite_kopf('Newsletter', ['aktiv' => '/newsletter', 'skripte' => ['pow.js']]);
?>
<div class="innen form-kopf">
    <p class="kicker">Allgäu Akut · Wintersymposium</p>
    <h1 class="titel">Newsletter</h1>
    <p class="text">Termine und Neuigkeiten per Mail. Kostenlos, jederzeit abbestellbar über den Link in jeder Mail.</p>
</div>
<div class="innen">
    <?= meldung_html(flash_holen()) ?>
    <?php if ($fehler !== []): ?><div class="meldung fehler" role="alert">Bitte die markierten Angaben prüfen.</div><?php endif; ?>
    <form class="form zg-form" method="post" action="/newsletter" novalidate>
        <?= csrf_feld() ?>
        <fieldset class="feld nl-listen"><legend>Welche Newsletter?</legend>
            <?php foreach ($listen as $l): ?>
            <label class="haken"><input type="checkbox" name="listen[]" value="<?= (int)$l['id'] ?>"<?= in_array((string)$l['id'], (array)($eingabe['listen'] ?? []), true) ? ' checked' : '' ?>><span><strong><?= e($l['name']) ?></strong><?= $l['beschreibung'] !== '' ? '<br><span class="meta klein">' . e($l['beschreibung']) . '</span>' : '' ?></span></label>
            <?php endforeach; ?>
            <?= $fehlerFeld('listen') ?>
        </fieldset>
        <div class="paar">
            <div class="feld"><label for="nl-email">E-Mail</label><input id="nl-email" name="email" type="email" autocomplete="email" required maxlength="190" value="<?= e((string)($eingabe['email'] ?? '')) ?>"<?= $ungueltig('email') ?>><?= $fehlerFeld('email') ?></div>
            <div class="feld"><label for="nl-vorname">Vorname <span class="meta">(freiwillig, für die Anrede)</span></label><input id="nl-vorname" name="vorname" type="text" autocomplete="given-name" maxlength="80" value="<?= e((string)($eingabe['vorname'] ?? '')) ?>"></div>
        </div>
        <label class="haken"><input type="checkbox" name="einwilligung" value="1"<?= !empty($eingabe['einwilligung']) ? ' checked' : '' ?><?= $ungueltig('einwilligung') ?>><span><?= e(NL_EINWILLIGUNG) ?> Mehr in der <a href="/datenschutz" target="_blank" rel="noopener">Datenschutzerklärung</a>.</span></label>
        <?= $fehlerFeld('einwilligung') ?>
        <?= spam_felder() ?>
        <div class="aktionen"><button class="knopf" type="submit">Newsletter bestellen</button></div>
        <p class="meta klein">Schon eingetragen? Über den Link „Abmelden oder Listen ändern“ in jeder Newsletter-Mail änderst du deine Listen.</p>
    </form>
</div>
<?php
seite_fuss();
