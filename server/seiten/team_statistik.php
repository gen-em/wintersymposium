<?php
declare(strict_types=1);

/*
 * Statistik (AP-5, Mockup „Statistik“, E-49): Kennzahlen, Verlauf,
 * Workshop-Termine, Berufsgruppen, Institutionen, Essen; Filter nach
 * Berufsgruppe; Export als CSV, Excel oder PDF (berücksichtigt den Filter).
 */

$konto = team_tor();
$bg = (string)($_GET['bg'] ?? '');
if ($bg !== '' && !in_array($bg, va_liste('berufsgruppen'), true)) {
    $bg = '';
}
$d = stat_daten($bg);
team_seite_kopf($konto, 'Statistik', '/team/statistik');
?>
<form class="filterzeile" method="get" action="/team/statistik">
    <label for="bg" class="sr">Berufsgruppe</label>
    <select id="bg" name="bg">
        <option value="">Alle Berufsgruppen</option>
        <?php foreach (va_liste('berufsgruppen') as $g): ?><option value="<?= e($g) ?>"<?= $bg === $g ? ' selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?>
    </select>
    <button class="knopf zweit klein-knopf" type="submit">Filtern</button>
    <a class="klein-link" href="#export">Zum Export</a>
</form>
<p class="meta klein">Stand <?= e(datum_zeit_text(db_jetzt())) ?><?= $bg !== '' ? ' · nur ' . e($bg) : '' ?> · gezählt werden bestätigte Anmeldungen.</p>

<div class="kacheln">
    <div class="kachel"><div class="lbl">Angemeldet</div><div class="zahl"><?= $d['angemeldet'] ?><?= $d['kapazitaet'] !== null && $bg === '' ? ' / ' . $d['kapazitaet'] : '' ?></div><p class="meta klein"><?= $d['kapazitaet'] !== null && $bg === '' ? stat_prozent($d['angemeldet'], $d['kapazitaet']) . ' der Kapazität · ' : '' ?>+ <?= $d['unbestaetigt'] ?> Mail nicht bestätigt<br>EFN: <?= $d['mit_efn'] ?> von <?= $d['aerzte'] ?> <?= e(va_liste('berufsgruppen')[0] ?? '') ?></p></div>
    <div class="kachel"><div class="lbl">Warteliste</div><div class="zahl"><?= $d['wl_eintraege'] ?></div><p class="meta klein">Einträge · <?= $d['wl_personen'] ?> Personen · <?= $d['warteliste'] ?> fürs Symposium</p></div>
    <div class="kachel"><div class="lbl">Konten</div><div class="zahl"><?= $d['konten'] ?></div><p class="meta klein"><?= stat_prozent($d['konten'], $d['bestaetigt']) ?> der Angemeldeten · Newsletter-Wunsch <?= $d['newsletter'] ?></p></div>
</div>

<div class="team-raster abstand-oben">
    <div class="kasten">
        <h2 class="zwischen klein-zwischen">Anmeldungen seit Start</h2>
        <p class="meta klein">bestätigte Anmeldungen, kumuliert</p>
        <?= stat_verlauf($d['verlauf']) ?>
        <?php if ($d['verlauf'] !== []): ?>
        <details class="tabelle-details"><summary>Tabelle</summary>
            <table class="t"><thead><tr><th>Tag</th><th>Neu</th><th>Gesamt</th></tr></thead><tbody>
            <?php foreach ($d['verlauf'] as $v): ?><tr><td class="mono"><?= e(tag_text($v['tag'])) ?></td><td class="mono"><?= $v['neu'] ?></td><td class="mono"><?= $v['summe'] ?></td></tr><?php endforeach; ?>
            </tbody></table></details>
        <?php endif; ?>
    </div>
    <div class="kasten">
        <h2 class="zwischen klein-zwischen">Workshop-Termine</h2>
        <p class="meta klein">belegt (Balken) von Plätzen; Warteliste in Klammern</p>
        <?php if ($d['termine'] === []): ?><p class="hinweis-leer">Noch kein veröffentlichtes Programm mit Workshops.</p><?php else: ?>
        <div class="balken-liste" role="list" aria-label="Workshop-Termine">
            <?php foreach ($d['termine'] as $t): ?>
            <div class="balken-zeile" role="listitem"><span class="balken-label"><?= e($t['text']) ?></span>
                <svg class="balken-svg" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true"><title><?= e($t['text'] . ': ' . $t['belegt'] . ' von ' . $t['kap']) ?></title><rect x="0" y="1" width="100" height="8" rx="1" class="balken-grund"/><rect x="0" y="1" width="<?= $t['kap'] > 0 ? min(100, round(100 * $t['belegt'] / $t['kap'], 2)) : 0 ?>" height="8" rx="1" class="balken-wert"/></svg>
                <span class="balken-zahl mono"><?= $t['belegt'] ?>/<?= $t['kap'] ?><?= $t['warte'] > 0 ? ' (' . $t['warte'] . ')' : '' ?></span></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="kasten">
        <h2 class="zwischen klein-zwischen">Berufsgruppen</h2>
        <?= stat_balken($d['berufsgruppen'], 'Berufsgruppen') ?>
    </div>
    <div class="kasten">
        <h2 class="zwischen klein-zwischen">Institutionen</h2>
        <?= $d['institutionen'] === [] ? '<p class="hinweis-leer">Noch keine.</p>' : stat_balken($d['institutionen'], 'Institutionen (höchstens 15)') ?>
    </div>
    <div class="kasten">
        <h2 class="zwischen klein-zwischen">Essen</h2>
        <?= stat_balken(array_combine(array_map(fn($k) => ANM_ESSEN[$k], array_keys($d['essen'])), array_values($d['essen'])), 'Essen') ?>
        <p class="meta klein"><?= $d['essen']['egal'] + $d['essen']['vegetarisch'] ?> Essen zu bestellen · Essens-Stichtag <?= e(tag_text(anm_stichtag('stichtag_essen'))) ?></p>
    </div>
    <?php if ($konto['rolle'] === 'betreiber'): // Newsletter gilt für alle Veranstaltungen: nur Betreiber (AP-12, E-60) ?>
    <div class="kasten">
        <h2 class="zwischen klein-zwischen">Newsletter</h2>
        <?php $nlz = nl_zahlen(); ?>
        <?= stat_balken(array_combine(array_map(fn($z) => $z['liste']['name'], $nlz), array_map(fn($z) => $z['bestaetigt'], $nlz)) ?: [], 'Bestätigte Abonnierende je Liste') ?>
        <p class="meta klein">nur bestätigte Einträge (Double-Opt-In) · <?= array_sum(array_column($nlz, 'offen')) ?> warten auf Bestätigung · <?= array_sum(array_column($nlz, 'abgemeldet')) ?> abgemeldet · <a href="/team/newsletter">Newsletter</a></p>
    </div>
    <?php endif; ?>
    <div class="kasten">
        <h2 class="zwischen klein-zwischen">Anwesenheit</h2>
        <?php $tz = tn_zahlen(); ?>
        <div class="zahl"><?= $tz['anwesend'] ?> / <?= $tz['angemeldet'] ?></div>
        <p class="meta klein">anwesend / mit Platz · <?= $tz['nicht_erschienen'] ?> nicht erschienen · <?= $tz['freigeschaltet'] ?> Zertifikate freigeschaltet. Erfassen unter <a href="/team/teilnahme">Teilnahme</a>; Standard ist „anwesend“.</p>
    </div>
</div>

<h2 class="zwischen" id="export">Export</h2>
<p class="meta klein">CSV für andere Programme, Excel mit fertiger Kopfzeile und Filter, PDF zum Ausdrucken (A4) · berücksichtigt den Filter oben<?= $bg !== '' ? ' (' . e($bg) . ')' : '' ?>.</p>
<div class="tabelle-wrap"><table class="t karten">
    <tbody>
    <?php foreach (EXPORT_ARTEN as $art => [$t, $b]): ?>
        <tr><td data-label="Liste"><strong><?= e($t) ?></strong><br><span class="meta klein"><?= e($b) ?></span></td>
            <td data-label="Datei"><?= export_knoepfe(['art' => $art, 'bg' => $bg]) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php
team_seite_fuss();
