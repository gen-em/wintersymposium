<?php
declare(strict_types=1);

/*
 * Verwaltung → Startseite (AP-18, E-70 (1)): die Texte der Startseite einer
 * Veranstaltung mit Vorschau. Abschnitte wie auf der Startseite (Kopf, Worum
 * es geht, Programm, Merke); leere Felder erscheinen dort nicht. Eckdaten
 * und Newsletter-Hinweis sind fest. Rechte wie die Einstellungen; eine
 * abgeschlossene Veranstaltung nur zum Ansehen (E-58).
 *
 * Die Vorschau (startseite.js) schickt das Formular an
 * /team/startseite/vorschau und zeigt die Antwort im iframe; ohne
 * JavaScript fehlt sie mit einem Hinweis, das Formular funktioniert.
 */

$konto = team_tor();
team_schreibschutz('/team/startseite');
$fehler = [];
$eingabe = null;

if (ist_post()) {
    [$geaendert, $fehler] = start_texte_speichern($_POST, (int)$konto['id']);
    if ($fehler === []) {
        if ($geaendert !== []) {
            inhalt_protokollieren('Startseite geändert', $geaendert, (int)$konto['id'], true);
        }
        fertig('ok', $geaendert === [] ? 'Nichts geändert.' : 'Gespeichert. Die Startseite zeigt die neuen Texte sofort.', '/team/startseite');
    }
    $eingabe = $_POST;
}

$nurLesen = va_abgeschlossen();
$werte = $eingabe !== null ? array_map(fn($k) => (string)($eingabe[$k] ?? ''), array_combine(array_keys(START_TEXTE), array_keys(START_TEXTE))) : start_werte();

/** Ein Feld der Startseite: einfaches Textfeld oder Editor (Art aus START_TEXTE). */
$startFeld = function (string $k, array $opt = []) use ($werte, $fehler, $nurLesen): string {
    [$titel, $art, $hilfe] = START_TEXTE[$k];
    $o = $opt + ['wert' => $werte[$k], 'fehler' => $fehler[$k] ?? null, 'hilfe' => isset($fehler[$k]) ? '' : $hilfe];
    if ($art === 'text') {
        return feld($k, $opt['label'] ?? $titel, $o + ['attr' => ['maxlength' => START_TEXT_MAX]]);
    }
    $zeilen = ['titel' => 2, 'inline' => 2, 'block' => 4][$art];
    return feld($k, $opt['label'] ?? $titel, $o + ['typ' => 'textarea', 'attr' => ['rows' => $zeilen] + ($nurLesen ? [] : editor_attr($art))]);
};

team_seite_kopf($konto, 'Startseite', '/team/startseite', ['skripte' => ['startseite.js']]);
?>
<?= $fehler !== [] ? meldung_html(NICHT_GESPEICHERT_TEXT, 'fehler') : '' ?>
<form method="post" action="/team/startseite" class="st-raster" id="st-form" data-vorschau="/team/startseite/vorschau" novalidate>
    <?= csrf_feld() ?>
    <div class="st-felder stapel">
        <section class="kasten stapel">
            <h2 class="zwischen klein-titel">Kopf</h2>
            <fieldset class="st-gruppe stapel"<?= $nurLesen ? ' disabled' : '' ?>>
            <?= $startFeld('start_kicker', ['attr' => ['maxlength' => START_TEXT_MAX, 'placeholder' => reihe_name() . ' · ' . va_kurz()]]) ?>
            <?= $startFeld('start_titel') ?>
            <?= $startFeld('start_unter') ?>
            <?= $startFeld('start_sticker') ?>
        </fieldset></section>
        <section class="kasten stapel">
            <h2 class="zwischen klein-titel">Worum es geht</h2>
            <fieldset class="st-gruppe stapel"<?= $nurLesen ? ' disabled' : '' ?>>
            <div class="paar"><?= $startFeld('start_infos_kicker') . $startFeld('start_infos_titel') ?></div>
            <?php for ($i = 1; $i <= 3; $i++): ?>
            <div class="stapel">
                <?= $startFeld("start_kachel_{$i}_titel", ['label' => "Kachel $i"]) ?>
                <?= $startFeld("start_kachel_{$i}_text", ['label' => "Text Kachel $i"]) ?>
            </div>
            <?php endfor; ?>
            <p class="hilfe">Leere Kacheln erscheinen nicht; ohne Kachel fehlt der ganze Abschnitt.</p>
        </fieldset></section>
        <section class="kasten stapel">
            <h2 class="zwischen klein-titel">Programm</h2>
            <fieldset class="st-gruppe stapel"<?= $nurLesen ? ' disabled' : '' ?>>
            <div class="paar"><?= $startFeld('start_programm_kicker') . $startFeld('start_programm_titel') ?></div>
            <p class="hilfe">Darunter das veröffentlichte Programm.</p>
        </fieldset></section>
        <section class="kasten stapel">
            <h2 class="zwischen klein-titel">Merke · Fortbildungspunkte</h2>
            <fieldset class="st-gruppe stapel"<?= $nurLesen ? ' disabled' : '' ?>>
            <?= $startFeld('start_cme') ?>
        </fieldset></section>
        <p class="meta klein st-fest">Fest, nicht hier zu ändern: <strong>Eckdaten</strong> (aus den <a href="/team/einstellungen">Einstellungen</a>) und <strong>Newsletter-Hinweis</strong>.</p>
    </div>

    <details class="st-vorschau" id="st-vorschau" open hidden>
        <summary>Vorschau</summary>
        <div class="stapel">
            <p class="kicker">Vorschau · noch nicht gespeichert <span class="mono meta" id="st-breite"></span></p>
            <div class="geraet-wahl" role="group" aria-label="Gerät der Vorschau">
                <button type="button" data-geraet="desktop" data-breite="1440" aria-pressed="false">Desktop</button>
                <button type="button" data-geraet="handy" data-breite="390" aria-pressed="false">Handy</button>
                <button type="button" data-geraet="tablet" data-breite="768" aria-pressed="false">Tablet</button>
            </div>
            <div class="vorschau-hinweise" id="st-hinweise" aria-live="polite"></div>
            <div class="vorschau-rahmen"><iframe name="st-vorschau-frame" class="vorschau-frame" title="Vorschau der Startseite" src="about:blank" tabindex="-1"></iframe></div>
        </div>
    </details>
    <p class="meta klein st-ohne-js" id="st-ohne-js">Die Vorschau braucht JavaScript. Speichern geht auch ohne; danach zeigt die <a href="/<?= e(va_zeile()['kuerzel'] ?? '') ?>" target="_blank" rel="noopener">Startseite</a> den neuen Stand.</p>

    <?php if (!$nurLesen): ?>
    <div class="aktionen st-knoepfe">
        <a class="knopf zweit" href="/team/startseite">Verwerfen</a>
        <button class="knopf" type="submit">Speichern</button>
    </div>
    <?php endif; ?>
</form>
<?php
team_seite_fuss();
