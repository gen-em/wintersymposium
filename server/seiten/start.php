<?php
declare(strict_types=1);

/*
 * Startseite (AP-2). Texte aus der Tabelle inhalt (pflegbar, AP-5).
 * Eckdaten (Datum, Zeit, Ort, CME, freie Plätze) kommen aus den
 * Veranstaltungs-Einstellungen von AP-3; bis dahin ein fester Platzhalter. Das Programm folgt mit AP-3; bis dahin ein Hinweis.
 */

$eck = [];
$datum = einstellung('veranstaltung_datum');
if ($datum !== null && $datum !== '') {
    $eck[] = ['Datum', tag_text($datum)];
    $von = (string)einstellung('veranstaltung_beginn');
    $bis = (string)einstellung('veranstaltung_ende');
    if ($von !== '') {
        $eck[] = ['Zeit', $von . ($bis !== '' ? '–' . $bis : '')];
    }
    foreach (['veranstaltung_ort' => 'Ort', 'veranstaltung_cme' => 'CME'] as $k => $label) {
        $w = (string)einstellung($k);
        if ($w !== '') {
            $eck[] = [$label, $w];
        }
    }
}
if ($eck === []) {
    // Vorübergehender Platzhalter auf Wunsch des Admins (Mockup-Kommentar
    // 2026-10-29), bis die Einstellungen aus AP-3 und die Platzzählung aus
    // AP-4 stehen; dann entfernen. Ort folgt, sobald er feststeht.
    $eck = [
        ['Datum', tag_text('2027-01-20')],
        ['Zeit', '08:30–16:30'],
        ['CME', 'Punkte beantragt'],
        ['Freie Plätze', '240 / 240'],
    ];
}

seite_kopf('Start', ['aktiv' => '/']);
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
    <h2 class="zwischen" id="programm-titel">Der Tag im Überblick</h2>
    <div class="kasten"><p class="hinweis-leer">Das Programm erscheint hier, sobald es veröffentlicht ist.</p></div>
</div></section>

<?php if (inhalt('start_cme') !== ''): ?>
<section class="abschnitt"><div class="innen">
    <div class="merke"><p class="kicker">Merke · Fortbildungspunkte</p><?= text_zu_html(inhalt('start_cme')) ?></div>
</div></section>
<?php endif; ?>
<?php
seite_fuss();
