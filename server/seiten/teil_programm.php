<?php
declare(strict_types=1);

/*
 * Programm-Anzeige (AP-3 Schritt 3, E-39, E-40) nach den Mockups: Liste nach
 * Uhrzeit; Workshop-Blöcke als Zeitraster auf dem Desktop (assets/programm.js
 * setzt die Positionen) und als Liste auf dem Handy; Popups (<dialog>) für
 * Vorträge, Fallbesprechungen, Workshops und Stationen.
 *
 * Erwartet $programm_punkte (veröffentlichter Stand oder, in der Vorschau,
 * der Arbeitsstand).
 */

$punkte = $programm_punkte ?? [];
$gliederung = programm_gliederung($punkte);
$anmeldungAb = va('anmeldung_start');
$anmeldungHinweis = ($anmeldungAb !== '' && strtotime($anmeldungAb . ' UTC') > time()) ? 'Anmelden kannst du dich ab ' . datum_text($anmeldungAb) . '.' : '';

$dauer = fn(string $von, string $bis) => minuten($bis) - minuten($von);
$frei = fn(array $t) => max(0, $t['plaetze'] - termin_belegt((int)$t['id']));
$chipFrei = function (array $t) use ($frei): string {
    if (va_abgeschlossen()) {
        return ''; // Rückblick (E-58): keine Plätze mehr
    }
    $f = $frei($t);
    if ($f > 0) {
        return '<span class="chip ok">' . $f . ' frei</span>';
    }
    $w = anm_wartend((int)$t['id']);
    return '<span class="chip wl">voll · ' . ($w > 0 ? $w . ' warten' : 'Warteliste') . '</span>';
};
$format = function (array $p): string {
    $typ = PROGRAMM_TYPEN[$p['typ']];
    if ($p['typ'] === 'station') {
        return '<span class="format offen">OFFEN</span>';
    }
    if ($p['kuerzel'] === '' || $typ['kurz'] === '') {
        return '';
    }
    return '<span class="format' . ($typ['farbe'] === 'rot' ? ' rot' : '') . '">' . e($p['kuerzel']) . '</span>';
};
$hatPopup = fn(array $p) => in_array($p['typ'], ['vortrag', 'fall', 'workshop'], true) || ($p['typ'] === 'station' && $p['beschreibung'] !== '');
$ausloeser = fn(array $p, string $inhalt, string $klasse = 'pp-link') => $hatPopup($p)
    ? '<button type="button" class="' . $klasse . '" aria-haspopup="dialog" data-dialog="pp-' . $p['id'] . '">' . $inhalt . '</button>'
    : $inhalt;
$namen = fn(array $p) => implode(', ', array_column($p['dozenten'], 'name'));
// Abweichende Dozierende eines Termins (Rückmeldung 2026-10-30), sonst die des Workshops
$terminNamen = fn(array $p, array $t) => isset($t['dozenten']) ? implode(', ', array_column($t['dozenten'], 'name')) : $namen($p);

if ($gliederung === []): ?>
<div class="kasten"><p class="hinweis-leer">Das Programm erscheint hier, sobald es veröffentlicht ist.</p></div>
<?php return; endif; ?>
<ol class="programm">
<?php foreach ($gliederung as $g):
    $p = $g['punkt'];
    if ($g['art'] === 'block'): ?>
    <li class="pp wsblock">
        <div class="zeit"><?= e($p['beginn']) ?><small><?= e($p['ende']) ?></small></div>
        <div>
            <div class="titelzeile"><h3><?= e($p['titel']) ?></h3></div>
            <?php if ($g['kinder'] === []): ?><p class="meta klein">Termine folgen.</p><?php else: ?>
            <ul class="ws-mobil">
            <?php foreach ($g['kinder'] as $k):
                $kp = $k['punkt'];
                if ($k['art'] === 'punkt' && $kp['typ'] !== 'station'): ?>
                <li class="im-block <?= $kp['typ'] === 'fall' ? 'fb' : 'vt' ?>"><span class="mono"><?= e($kp['beginn'] . '–' . $kp['ende']) ?></span><span><?= $format($kp) ?> <?= $ausloeser($kp, e($kp['titel']), 'ws-link') ?><?php if ($namen($kp) !== '' || $kp['ort'] !== ''): ?><br><span class="meta klein"><?= e(implode(' · ', array_filter([$namen($kp), $kp['ort']]))) ?></span><?php endif; ?></span></li>
                <?php elseif ($k['art'] === 'punkt'): ?>
                <li class="offen"><span class="mono"><?= e($kp['beginn'] . '–' . $kp['ende']) ?></span><span><?= $format($kp) ?> <?= $ausloeser($kp, e($kp['titel']), 'ws-link') ?><br><span class="meta klein">ohne Anmeldung<?= $kp['ort'] !== '' ? ' · ' . e($kp['ort']) : '' ?></span></span></li>
                <?php else: $t = $k['termin']; ?>
                <li><button type="button" class="ws-zeile" aria-haspopup="dialog" data-dialog="pp-<?= $kp['id'] ?>"><span class="mono"><?= e($t['beginn'] . '–' . $t['ende']) ?><br><small><?= $dauer($t['beginn'], $t['ende']) ?> min</small></span><span><?= $format($kp) ?> <?= e($kp['titel']) ?><br><?= $chipFrei($t) ?></span><span class="pfeil" aria-hidden="true">›</span></button></li>
                <?php endif;
            endforeach; ?>
            </ul>
            <?php
            // Zeitraster: eine Zeile je Workshop bzw. Station
            $zeilen = [];
            foreach ($g['kinder'] as $k) {
                $zeilen[$k['punkt']['id']]['punkt'] = $k['punkt'];
                $zeilen[$k['punkt']['id']]['balken'][] = $k;
            }
            ?>
            <div class="gantt-wrap" data-von="<?= minuten($p['beginn']) ?>" data-bis="<?= minuten($p['ende']) ?>" hidden>
                <div class="gantt">
                    <div class="gz kopf"><span></span><div class="gs skala"></div></div>
                    <?php foreach ($zeilen as $z): $zp = $z['punkt']; ?>
                    <div class="gz">
                        <?= $ausloeser($zp, $format($zp) . ' ' . e($zp['titel']), 'gl') ?>
                        <div class="gs">
                        <?php foreach ($z['balken'] as $k):
                            if ($k['art'] === 'punkt' && $zp['typ'] !== 'station'): ?>
                            <button type="button" class="gb <?= $zp['typ'] === 'fall' ? 'fb' : 'vt' ?>" aria-haspopup="dialog" data-dialog="pp-<?= $zp['id'] ?>" data-von="<?= minuten($zp['beginn']) ?>" data-bis="<?= minuten($zp['ende']) ?>" title="<?= e(trim($zp['kuerzel'] . ' ' . $zp['beginn'] . '–' . $zp['ende'])) ?>"><b><?= e($zp['beginn']) ?></b> <?= e($namen($zp) !== '' ? $namen($zp) : PROGRAMM_TYPEN[$zp['typ']]['name']) ?></button>
                            <?php elseif ($k['art'] === 'punkt'): ?>
                            <span class="gb offen" data-von="<?= minuten($zp['beginn']) ?>" data-bis="<?= minuten($zp['ende']) ?>"><b><?= e($zp['beginn'] . '–' . $zp['ende']) ?></b> ohne Anmeldung<?= $zp['ort'] !== '' ? ' · ' . e($zp['ort']) : '' ?></span>
                            <?php else: $t = $k['termin']; $f = $frei($t); ?>
                            <button type="button" class="gb <?= $f > 0 ? 'frei' : 'voll' ?>" aria-haspopup="dialog" data-dialog="pp-<?= $zp['id'] ?>" data-von="<?= minuten($t['beginn']) ?>" data-bis="<?= minuten($t['ende']) ?>" title="<?= e($zp['kuerzel'] . ' ' . $t['beginn'] . '–' . $t['ende']) ?>"><b><?= e($t['beginn']) ?></b> <?= va_abgeschlossen() ? '' : ($f > 0 ? $f . ' frei' : 'voll') ?></button>
                            <?php endif;
                        endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </li>
    <?php elseif ($g['art'] === 'termin'): $t = $g['termin']; ?>
    <li class="pp">
        <div class="zeit"><?= e($t['beginn']) ?><small><?= e($t['ende']) ?></small></div>
        <div>
            <div class="titelzeile"><?= $format($p) ?><h3><?= $ausloeser($p, e($p['titel']) . ' <span class="pp-mehr">Infos</span>') ?></h3></div>
            <?php if ($p['untertitel'] !== ''): ?><p><?= e($p['untertitel']) ?></p><?php endif; ?>
            <p class="meta klein"><?= e(implode(' · ', array_filter([$terminNamen($p, $t), $p['ort']]))) ?> <?= $chipFrei($t) ?></p>
        </div>
    </li>
    <?php elseif ($p['typ'] !== 'workshop'):
        $klasse = $p['typ'] === 'pause' ? ' pause' : ''; ?>
    <li class="pp<?= $klasse ?>">
        <div class="zeit"><?= e($p['beginn']) ?><small><?= e($p['ende']) ?></small></div>
        <div>
            <div class="titelzeile"><?= $format($p) ?><h3><?= $hatPopup($p) ? $ausloeser($p, e($p['titel']) . ' <span class="pp-mehr">Infos</span>') : e($p['titel']) ?></h3></div>
            <?php if ($p['untertitel'] !== ''): ?><p><?= e($p['untertitel']) ?></p><?php endif; ?>
            <?php $meta = array_filter([$namen($p), $p['typ'] === 'station' ? 'ohne Anmeldung' : '', $p['ort']]); if ($meta !== []): ?><p class="meta klein"><?= e(implode(' · ', $meta)) ?></p><?php endif; ?>
        </div>
    </li>
    <?php endif;
endforeach; ?>
</ol>

<?php foreach (programm_sortieren($punkte) as $p): if (!$hatPopup($p)) continue;
    $rot = PROGRAMM_TYPEN[$p['typ']]['farbe'] === 'rot'; ?>
<dialog class="popup" id="pp-<?= $p['id'] ?>" aria-labelledby="pp-<?= $p['id'] ?>-titel">
    <div class="popup-innen<?= $rot ? ' rot' : '' ?>">
        <button type="button" class="popup-zu" aria-label="Schließen" data-schliessen>✕</button>
        <p class="kicker"><?= $format($p) ?> &nbsp;<?= e(PROGRAMM_TYPEN[$p['typ']]['name']) ?><?= $p['ort'] !== '' ? ' · ' . e($p['ort']) : '' ?></p>
        <h2 class="titel" id="pp-<?= $p['id'] ?>-titel"><?= e($p['titel']) ?></h2>
        <?php if ($p['typ'] !== 'workshop' && $p['beginn'] !== ''): ?><p class="mono"><?= e($p['beginn'] . '–' . $p['ende']) ?> · <?= $dauer($p['beginn'], $p['ende']) ?> min</p><?php endif; ?>
        <?php if ($p['untertitel'] !== ''): ?><p class="unter-fett"><?= e($p['untertitel']) ?></p><?php endif; ?>
        <?= editor_html($p['beschreibung']) ?>
        <?php if ($p['typ'] === 'workshop'): ?>
            <?php if ($p['bring'] !== ''): ?><p><strong>Bring:</strong> <?= e($p['bring']) ?></p><?php endif; ?>
            <?php if ($p['notiz'] !== ''): ?><div class="zettel"><p class="zettel-kopf">Notiz vom Team</p><p><?= e($p['notiz']) ?></p></div><?php endif; ?>
            <p class="unterkopf">Termine</p>
            <div class="pt-liste">
            <?php foreach ($p['termine'] as $t): ?>
                <div class="pt-zeile"><span class="mono"><?= e($t['beginn'] . '–' . $t['ende']) ?> · <?= $dauer($t['beginn'], $t['ende']) ?> min</span> <?= $chipFrei($t) ?> <span class="meta klein"><?= (int)$t['plaetze'] ?> Plätze<?= isset($t['dozenten']) ? ' · mit ' . e($terminNamen($p, $t)) : '' ?></span></div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($p['dozenten'] !== []): ?>
            <div><p class="unterkopf"><?= $p['typ'] === 'workshop' ? 'Leitung' : 'Dozierende' ?></p>
            <?php foreach ($p['dozenten'] as $d): ?>
                <div class="doz">
                    <?php if (!empty($d['foto'])): ?><img class="foto" src="/bild/<?= e($d['foto']) ?>" alt="" width="56" height="56" loading="lazy"><?php else: ?><span class="foto" aria-hidden="true"><?= e(mb_substr($d['name'], 0, 1)) ?></span><?php endif; ?>
                    <div><p><strong><?= e($d['name']) ?></strong><?= $d['funktion'] !== '' ? '<br><span class="meta klein">' . e($d['funktion']) . '</span>' : '' ?></p><?php if ($d['kurzbio'] !== ''): ?><div class="klein"><?= editor_html($d['kurzbio']) ?></div><?php endif; ?></div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (in_array($p['typ'], ['vortrag', 'fall'], true)): ?><p class="meta klein">Vorträge und Fallbesprechungen brauchen keine eigene Anmeldung – sie gehören zur Anmeldung zum Symposium.</p><?php endif; ?>
        <?php if ($p['typ'] === 'station'): ?><p class="meta klein">Offene Station: ohne Anmeldung, komm einfach vorbei.</p><?php endif; ?>
        <?php if ($p['typ'] === 'workshop' && $anmeldungHinweis !== ''): ?><p class="meta klein"><?= e($anmeldungHinweis) ?></p><?php endif; ?>
        <?php if ($p['typ'] === 'workshop' && anm_zeitraum() === 'offen'): ?><div class="aktionen"><a class="knopf gruen" href="/anmelden#workshops">Zur Anmeldung</a><a class="knopf zweit" href="/deine-anmeldung">Schon angemeldet?</a></div><?php endif; ?>
    </div>
</dialog>
<?php endforeach;
