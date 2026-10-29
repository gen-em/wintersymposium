<?php
declare(strict_types=1);

/*
 * Workshop-Wahl (Mockup „Anmelden“, E-20, E-38): je Workshop ein fester Termin
 * (Radio ws[<workshop>]), volle Termine zusätzlich auf die Warteliste
 * (Checkbox wl[]). Überschneidungen sperrt assets/anmelden.js sofort; der
 * Server prüft alles noch einmal (anm_wahl_pruefen).
 *
 * Erwartet: $wahl (['fest' => [wid => tid], 'warte' => [tid]]),
 *           $bisher (eigene Plätze bei Änderungen, sonst []).
 */

$eigen = [];
foreach ($bisher as $p) {
    $eigen[(int)$p['termin_id']] = $p['status'];
}
$dauerMin = fn(array $t) => minuten($t['ende']) - minuten($t['beginn']);
foreach (anm_workshops() as $w):
    $wid = (int)$w['id'];
    $festeZeilen = '';
    $warteZeilen = '';
    foreach ($w['termine'] as $t) {
        $tid = (int)$t['id'];
        $st = $eigen[$tid] ?? null;
        if ($st === 'angebot') {
            $warteZeilen .= '<p class="meta klein">' . e($t['beginn'] . '–' . $t['ende']) . ': Platz frei für dich – annehmen oder ablehnen oben unter „Platz frei geworden“.</p>';
            continue;
        }
        $frei = anm_frei($tid);
        $zeit = '<span class="zeit-chip">' . e($t['beginn'] . '–' . $t['ende']) . '</span> <span class="meta">· ' . $dauerMin($t) . ' min</span>';
        $titel = e(trim($w['kuerzel'] . ' ' . $t['beginn'] . '–' . $t['ende']));
        if ($frei > 0 || $st === 'fest') {
            $an = ($wahl['fest'][$wid] ?? 0) === $tid;
            $rechts = $st === 'fest' ? 'dein Platz' : $frei . ' frei';
            $festeZeilen .= '<label><input type="radio" name="ws[' . $wid . ']" value="' . $tid . '" data-von="' . minuten($t['beginn']) . '" data-bis="' . minuten($t['ende']) . '" data-titel="' . $titel . '"' . ($an ? ' checked' : '') . '>'
                . '<span>' . $zeit . '<span class="grund" data-grund></span></span><span class="rechts status">' . e($rechts) . '</span></label>';
        } else {
            $an = in_array($tid, $wahl['warte'], true);
            $wartend = anm_wartend($tid) - ($st === 'warte' ? 1 : 0);
            $grund = $st === 'warte' ? 'Voll · du stehst auf der Warteliste' : 'Voll' . ($wartend === 1 ? ' · 1 Person wartet' : ($wartend > 1 ? " · $wartend Personen warten" : ''));
            $warteZeilen .= '<label class="wl-zeile' . ($an ? ' an' : '') . '"><input class="sr" type="checkbox" name="wl[]" value="' . $tid . '"' . ($an ? ' checked' : '') . '>'
                . '<span>' . $zeit . '<span class="grund">' . e($grund) . '</span></span><span class="rechts wl-toggle" data-an="✓ Auf der Warteliste" data-aus="+ Warteliste">' . ($an ? '✓ Auf der Warteliste' : '+ Warteliste') . '</span></label>';
        }
    }
    $keiner = !isset($wahl['fest'][$wid]);
    $festeZeilen .= '<label><input type="radio" name="ws[' . $wid . ']" value=""' . ($keiner ? ' checked' : '') . '><span>Keinen festen Platz</span></label>';
?>
<div class="ws-gruppe">
    <div class="kopfzeile"><span class="format rot"><?= e($w['kuerzel']) ?></span><strong><?= e($w['titel']) ?></strong><?php if ($w['ort'] !== ''): ?><span class="meta klein"><?= e($w['ort']) ?></span><?php endif; ?></div>
    <p class="unterkopf">Fester Platz <span class="meta">– wähle einen</span></p>
    <div class="wahl"><?= $festeZeilen ?></div>
    <?php if ($warteZeilen !== ''): ?>
    <p class="unterkopf">Warteliste für volle Termine <span class="meta">– du kannst auch mehrere markieren</span></p>
    <div class="wahl"><?= $warteZeilen ?></div>
    <?php endif; ?>
</div>
<?php endforeach;
