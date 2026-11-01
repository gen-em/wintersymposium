<?php
declare(strict_types=1);

/*
 * Startseite (AP-2). Texte aus der Tabelle inhalt (pflegbar, AP-5).
 * Eckdaten (Datum, Zeit, Ort, CME, freie Plätze) kommen aus den
 * Veranstaltungs-Einstellungen (AP-3 Schritt 2, lib/veranstaltung_lib.php).
 * Das Programm zeigt den veröffentlichten Stand (AP-3 Schritt 3, teil_programm.php).
 */

// Keine aktuelle Veranstaltung (Review B-10): Hinweis statt Eckdaten, Anmeldung und Programm
$keine = va_keine();
$eck = $keine ? [] : va_eckdaten();
$anmeldungAb = va('anmeldung_start');
$anmeldungHinweis = (!$keine && $anmeldungAb !== '' && strtotime($anmeldungAb . ' UTC') > time()) ? 'Anmeldung ab ' . datum_text($anmeldungAb) : '';

seite_kopf('Start', ['aktiv' => '/', 'skripte' => ['programm.js']]);
$archiv = va_abgeschlossen();
?>
<?php if ($archiv): $akt = va_aktuell_id(); ?>
<div class="innen"><p class="va-archiv" role="status"><strong>Diese Veranstaltung ist vorbei.</strong> Die Seite bleibt als Rückblick stehen<?= $akt !== null ? ' – die aktuelle findest du auf der <a href="/">Startseite</a>' : '' ?>.</p></div>
<?php endif; ?>
<?php if ($keine): ?>
<div class="innen"><p class="va-archiv" role="status"><strong>Derzeit keine Veranstaltung.</strong> Die nächste kündigen wir hier und im <a href="/newsletter">Newsletter</a> an.</p></div>
<?php endif; ?>
<div class="innen held">
    <div>
        <?php // Zeile und Titel aus der Veranstaltung (Admin 2026-10-31): Reihe · Kurzname; Name mit erstem Wort weiß, Rest lila ?>
        <p class="kicker"><?= e(reihe_name() . ' · ' . va_kurz()) ?></p>
        <h1 class="titel"><?= va_titel_html() ?></h1>
        <?php if (inhalt('start_unter') !== ''): ?><p class="unter"><?= editor_html(inhalt('start_unter'), 'inline') ?></p><?php endif; ?>
    </div>
    <?php if (inhalt('start_sticker') !== ''): ?><span class="sticker hinweis frei"><?= e(inhalt('start_sticker')) ?></span><?php endif; ?>
    <?php if ($eck !== []): ?>
    <dl class="eckdaten">
        <?php foreach ($eck as [$dt, $dd]): ?><div><dt><?= e($dt) ?></dt><dd><?= e($dd) ?></dd></div><?php endforeach; ?>
    </dl>
    <?php endif; ?>
    <?php if ($anmeldungHinweis !== '' && !$archiv): ?><p class="mono meta klein anmeldung-ab"><?= e($anmeldungHinweis) ?></p><?php endif; ?>
    <?php if (anm_zeitraum() === 'offen'): ?><div class="held-knoepfe"><a class="knopf gruen" href="/anmelden">Jetzt anmelden</a><a class="knopf zweit" href="/deine-anmeldung">Anmeldung ändern</a></div><?php endif; ?>
</div>

<?php $infos = array_filter(start_kacheln(), fn($k) => $k['titel'] !== '' || $k['text'] !== ''); if ($infos !== []): ?>
<section class="abschnitt" aria-labelledby="infos-titel"><div class="innen">
    <p class="kicker">Worum es geht</p>
    <h2 class="zwischen" id="infos-titel"><?= editor_html(inhalt('start_infos_titel') ?: 'Worum es geht', 'inline') ?></h2>
    <div class="drei">
        <?php foreach ($infos as $i): ?>
        <div class="kasten">
            <?php if ($i['titel'] !== ''): ?><strong><?= e($i['titel']) ?></strong><?php endif; ?>
            <?= editor_html($i['text']) ?>
        </div>
        <?php endforeach; ?>
    </div>
</div></section>
<?php endif; ?>

<?php if (!$keine): ?>
<section class="abschnitt" id="programm" aria-labelledby="programm-titel"><div class="innen">
    <p class="kicker">Programm</p>
    <h2 class="zwischen" id="programm-titel">Der Abend im Überblick</h2>
    <?php $programm_punkte = programm_veroeffentlicht()['punkte'] ?? []; require __DIR__ . '/teil_programm.php'; ?>
</div></section>
<?php endif; ?>

<?php if (inhalt('start_cme') !== ''): ?>
<section class="abschnitt"><div class="innen">
    <div class="merke"><p class="kicker">Merke · Fortbildungspunkte</p><?= editor_html(inhalt('start_cme')) ?></div>
</div></section>
<?php endif; ?>
<section class="abschnitt"><div class="innen">
    <p class="nl-hinweis"><span class="kicker">Newsletter</span> Termine und Neuigkeiten per Mail – kostenlos, jederzeit abbestellbar. <a href="/newsletter">Newsletter bestellen</a></p>
</div></section>
<?php
seite_fuss();
