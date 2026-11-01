<?php
declare(strict_types=1);

/*
 * Workshop-Wahl (Mockup „Anmelden“, E-20, E-38): je Workshop ein fester Termin
 * (Radio ws[<workshop>]), volle Termine zusätzlich auf die Warteliste
 * (Checkbox wl[]); bestehende Warte-Einträge immer als vorangekreuztes
 * Kästchen, auch im Team-Modus (AP-14). Überschneidungen sperrt assets/anmelden.js sofort; der
 * Server prüft alles noch einmal (anm_wahl_pruefen).
 *
 * Vorlage von anm_workshopwahl_html() (V-27): sieht nur deren Parameter
 * $wahl (['fest' => [wid => tid], 'warte' => [tid]]), $bisher (eigene Plätze
 * bei Änderungen, sonst []) und $team (Team-Bereich: volle Termine wählbar).
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
            $warteZeilen .= '<p class="meta klein">' . e($t['beginn'] . '–' . $t['ende']) . ': Platzangebot für dich – annehmen oder ablehnen oben unter „Platzangebot“.</p>';
            continue;
        }
        $frei = anm_frei($tid);
        $zeit = '<span class="zeit-chip">' . e($t['beginn'] . '–' . $t['ende']) . '</span> <span class="meta">· ' . $dauerMin($t) . ' min</span>';
        $titel = e(trim($w['kuerzel'] . ' ' . $t['beginn'] . '–' . $t['ende']));
        $festWaehlbar = $frei > 0 || $st === 'fest' || $team;
        if ($festWaehlbar) {
            $an = ($wahl['fest'][$wid] ?? 0) === $tid;
            // Team (AP-5): volle Termine wählbar, Überbuchen wird beim Speichern bestätigt
            $rechts = $st === 'fest' ? ($team ? 'fest' : 'dein Platz') : ($frei > 0 ? $frei . ' frei' : 'voll · überbuchen');
            $festeZeilen .= '<label><input type="radio" name="ws[' . $wid . ']" value="' . $tid . '" data-von="' . minuten($t['beginn']) . '" data-bis="' . minuten($t['ende']) . '" data-titel="' . $titel . '"' . ($an ? ' checked' : '') . '>'
                . '<span>' . $zeit . '<span class="grund" data-grund></span></span><span class="rechts">' . e($rechts) . '</span></label>';
        }
        // Ein bestehender Warte-Eintrag steht immer da und wird mitgesendet – auch im
        // Team-Modus und wenn der Termin inzwischen frei ist (Review B-2, B-3)
        if (!$festWaehlbar || $st === 'warte') {
            $an = in_array($tid, $wahl['warte'], true);
            $wartend = anm_wartend($tid) - ($st === 'warte' ? 1 : 0);
            if ($st === 'warte' && $team) {
                $grund = 'Auf der Warteliste' . ($frei > 0 ? ' · ' . $frei . ' frei' : '');
            } elseif ($st === 'warte' && $frei > 0) {
                $grund = 'Du stehst auf der Warteliste · ' . $frei . ' frei – fest wählen oder auf der Warteliste bleiben';
            } elseif ($st === 'warte') {
                $grund = 'Voll · du stehst auf der Warteliste';
            } else {
                $grund = 'Voll' . ($wartend === 1 ? ' · 1 Person wartet' : ($wartend > 1 ? " · $wartend Personen warten" : ''));
            }
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
    <p class="unterkopf"><?= $team ? 'Warteliste <span class="meta">– abwählen nimmt die Person von der Warteliste</span>' : 'Warteliste für volle Termine <span class="meta">– du kannst auch mehrere markieren</span>' ?></p>
    <div class="wahl"><?= $warteZeilen ?></div>
    <?php endif; ?>
</div>
<?php endforeach;
