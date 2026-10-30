<?php
declare(strict_types=1);

/*
 * Startseite (AP-2). Texte aus der Tabelle inhalt (pflegbar, AP-5).
 * Eckdaten (Datum, Zeit, Ort, CME, freie Plätze) kommen aus den
 * Veranstaltungs-Einstellungen (AP-3 Schritt 2, lib/veranstaltung_lib.php).
 * Das Programm zeigt den veröffentlichten Stand (AP-3 Schritt 3, teil_programm.php).
 */

$eck = va_eckdaten();
$anmeldungAb = va('anmeldung_start');
$anmeldungHinweis = ($anmeldungAb !== '' && strtotime($anmeldungAb . ' UTC') > time()) ? 'Anmeldung ab ' . datum_text($anmeldungAb) : '';

seite_kopf('Start', ['aktiv' => '/', 'skripte' => ['programm.js']]);
?>
<div class="innen held">
    <div>
        <?php if (inhalt('start_kicker') !== ''): ?><p class="kicker"><?= text_inline(inhalt('start_kicker')) ?></p><?php endif; ?>
        <h1 class="titel"><?= text_inline(inhalt('start_titel') ?: 'Wintersymposium') ?></h1>
        <?php if (inhalt('start_unter') !== ''): ?><p class="unter"><?= text_inline(inhalt('start_unter')) ?></p><?php endif; ?>
    </div>
    <?php if (inhalt('start_sticker') !== ''): ?><span class="sticker hinweis frei"><?= e(inhalt('start_sticker')) ?></span><?php endif; ?>
    <?php if ($eck !== []): ?>
    <dl class="eckdaten">
        <?php foreach ($eck as [$dt, $dd]): ?><div><dt><?= e($dt) ?></dt><dd><?= e($dd) ?></dd></div><?php endforeach; ?>
    </dl>
    <?php endif; ?>
    <?php if ($anmeldungHinweis !== ''): ?><p class="mono meta klein anmeldung-ab"><?= e($anmeldungHinweis) ?></p><?php endif; ?>
    <?php if (anm_zeitraum() === 'offen'): ?><div class="held-knoepfe"><a class="knopf gruen" href="/anmelden">Jetzt anmelden</a><a class="knopf zweit" href="/deine-anmeldung">Anmeldung ändern</a></div><?php endif; ?>
</div>

<?php $infos = text_abschnitte(inhalt('start_infos')); if ($infos !== []): ?>
<section class="abschnitt" aria-labelledby="infos-titel"><div class="innen">
    <p class="kicker">Worum es geht</p>
    <h2 class="zwischen" id="infos-titel"><?= text_inline(inhalt('start_infos_titel') ?: 'Worum es geht') ?></h2>
    <div class="drei">
        <?php foreach ($infos as $i): ?>
        <div class="kasten">
            <?php if ($i['titel'] !== ''): ?><strong><?= text_inline($i['titel']) ?></strong><?php endif; ?>
            <?= text_zu_html($i['text']) ?>
        </div>
        <?php endforeach; ?>
    </div>
</div></section>
<?php endif; ?>

<section class="abschnitt" id="programm" aria-labelledby="programm-titel"><div class="innen">
    <p class="kicker">Programm</p>
    <h2 class="zwischen" id="programm-titel">Der Abend im Überblick</h2>
    <?php $programm_punkte = programm_veroeffentlicht()['punkte'] ?? []; require __DIR__ . '/teil_programm.php'; ?>
</div></section>

<?php if (inhalt('start_cme') !== ''): ?>
<section class="abschnitt"><div class="innen">
    <div class="merke"><p class="kicker">Merke · Fortbildungspunkte</p><?= text_zu_html(inhalt('start_cme')) ?></div>
</div></section>
<?php endif; ?>
<section class="abschnitt"><div class="innen">
    <p class="nl-hinweis"><span class="kicker">Newsletter</span> Termine und Neuigkeiten per Mail – kostenlos, jederzeit abbestellbar. <a href="/newsletter">Newsletter bestellen</a></p>
</div></section>
<?php
seite_fuss();
