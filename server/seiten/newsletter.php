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
if (ist_post()) {
    $eingabe = $_POST;
    $email = mail_adresse(post_text('email'));
    $gewaehlt = array_values(array_intersect(array_map('intval', (array)($_POST['listen'] ?? [])), array_map('intval', array_column($listen, 'id'))));
    if ($email === null) {
        $fehler['email'] = 'Diese E-Mail-Adresse sieht nicht wie eine Adresse aus.';
    }
    $interessen = nl_interessen((array)($_POST['nl_interesse'] ?? []));
    if ($gewaehlt === []) {
        $fehler['listen'] = 'Bitte mindestens einen Newsletter wählen.';
    } elseif (($f = nl_wahl_fehler($gewaehlt, $interessen)) !== null) {
        $fehler['listen'] = $f;
    }
    if (!post_haken('einwilligung')) {
        $fehler['einwilligung'] = 'Ohne deine Einwilligung können wir dir keinen Newsletter schicken.';
    }
    if ($fehler === []) {
        $ip = rate_merkmal_ip();
        $ziel = rate_merkmal_kennung('newsletter:' . $email);
        $ok = spam_ok($_POST) && !spam_wegwerf($email) && rate_erlaubt('link_ip', $ip) && rate_erlaubt('link_ziel', $ziel);
        rate_misserfolg('link_ip', $ip);
        rate_misserfolg('link_ziel', $ziel);
        if ($ok) {
            nl_anfragen($email, post_text('vorname'), $gewaehlt, 'formular', null, $interessen);
        }
        rate_gleiche_dauer($beginn, 0.6);
        fertig('ok', 'Fast geschafft: Schau in dein Postfach und bestätige den Newsletter über den Link in der Mail. Ohne Bestätigung tragen wir dich nicht ein.', '/newsletter');
    }
}
seite_kopf('Newsletter', ['aktiv' => '/newsletter', 'skripte' => ['pow.js'], 'suchmaschinen' => true]);
echo seiten_kopfzeile('Newsletter', reihe_name(), 'Termine und Neuigkeiten per Mail. Kostenlos, jederzeit abbestellbar über den Link in jeder Mail.');
?>
<div class="innen">
    <?= meldung_html(flash_holen()) ?>
    <?= $fehler !== [] ? meldung_html('Bitte die markierten Angaben prüfen.', 'fehler') : '' ?>
    <form class="form zg-form" method="post" action="/newsletter" novalidate>
        <?= csrf_feld() ?>
        <fieldset class="feld nl-listen"><legend>Welche Newsletter?</legend>
            <?php // Alle Reihen-Newsletter und Allgäu Akut mit Interessen (E-71 (7)) ?>
            <?= nl_listen_html((array)($eingabe['listen'] ?? []), $eingabe === [] ? 'klinik,praeklinik' : nl_interessen((array)($eingabe['nl_interesse'] ?? []))) ?>
            <?php if (isset($fehler['listen'])): // Fehler zur ganzen Gruppe, ohne eigenes Feld ?><p class="feldfehler" id="f-listen"><?= e($fehler['listen']) ?></p><?php endif; ?>
        </fieldset>
        <div class="paar">
            <?= feld('email', 'E-Mail', ['typ' => 'email', 'id' => 'nl-email', 'wert' => wert($eingabe, [], 'email'), 'fehler' => $fehler['email'] ?? null, 'pflicht' => true, 'attr' => ['autocomplete' => 'email', 'maxlength' => 190]]) ?>
            <?= feld('vorname', 'Vorname', ['id' => 'nl-vorname', 'zusatz' => '(freiwillig, für die Anrede)', 'wert' => wert($eingabe, [], 'vorname'), 'attr' => ['autocomplete' => 'given-name', 'maxlength' => 80]]) ?>
        </div>
        <?= feld('einwilligung', '', ['typ' => 'haken', 'wert' => post_haken('einwilligung'), 'fehler' => $fehler['einwilligung'] ?? null,
            'label_html' => e(NL_EINWILLIGUNG) . ' Mehr in der <a href="/datenschutz" target="_blank" rel="noopener">Datenschutzerklärung</a>.']) ?>
        <?= spam_felder() ?>
        <div class="aktionen"><button class="knopf" type="submit">Newsletter bestellen</button></div>
        <p class="meta klein">Schon eingetragen? Über den Link „Abmelden oder Listen ändern“ in jeder Newsletter-Mail änderst du deine Listen.</p>
    </form>
</div>
<?php
seite_fuss();
