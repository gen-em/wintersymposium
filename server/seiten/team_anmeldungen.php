<?php
declare(strict_types=1);

/*
 * Anmeldungen im Team-Bereich (AP-4, nur lesen; Admin 2026-10-29). Suchen,
 * ändern, manuell vergeben und exportieren kommt mit AP-5.
 */

$konto = team_tor();
$liste = anm_liste();
$z = anm_zahlen();
$ms = mail_stand();
$filter = (string)($_GET['f'] ?? 'alle');
$zeigen = array_values(array_filter($liste, function (array $a) use ($filter): bool {
    $sym = '';
    foreach ($a['plaetze'] as $p) {
        if ((int)$p['termin_id'] === 0) {
            $sym = $p['status'];
        }
    }
    return match ($filter) {
        'unbestaetigt' => $a['status'] === 'unbestaetigt',
        'warteliste' => $sym !== 'fest',
        default => true,
    };
}));

team_seite_kopf($konto, 'Anmeldungen', '/team/anmeldungen');
?>
<div class="kacheln">
    <div class="kachel"><div class="lbl">Bestätigt</div><div class="zahl"><?= $z['bestaetigt'] ?></div></div>
    <div class="kachel"><div class="lbl">Noch nicht bestätigt</div><div class="zahl"><?= $z['unbestaetigt'] ?></div></div>
    <div class="kachel"><div class="lbl">Plätze vergeben</div><div class="zahl"><?= $z['symposium_belegt'] ?><?= $z['kapazitaet'] !== null ? ' / ' . $z['kapazitaet'] : '' ?></div></div>
    <div class="kachel"><div class="lbl">Warteliste Symposium</div><div class="zahl"><?= $z['symposium_warte'] ?></div><p class="meta klein">Offene Angebote (alle Listen): <?= $z['angebote'] ?></p></div>
</div>
<?php if (!$ms['eingerichtet']): ?><div class="meldung fehler" role="alert">Der Mailversand ist nicht eingerichtet (config.php, Abschnitt mail). <?= $ms['offen'] ?> Mails warten.</div><?php endif; ?>
<?php if ($ms['fehler'] > 0): ?><div class="meldung fehler" role="alert"><?= $ms['fehler'] ?> Mails ließen sich nicht zustellen (nach mehreren Versuchen). Einzelheiten im Fehlerprotokoll.</div><?php endif; ?>

<h2 class="zwischen">Workshop-Termine</h2>
<div class="tabelle-wrap"><table class="t karten">
    <thead><tr><th>Workshop</th><th>Termin</th><th>Plätze</th><th>Vergeben</th><th>Warteliste</th></tr></thead>
    <tbody>
    <?php foreach (anm_workshops() as $w): foreach ($w['termine'] as $t): ?>
        <tr><td data-titel="Workshop"><?= e(trim($w['kuerzel'] . ' ' . $w['titel'])) ?></td><td data-titel="Termin" class="mono"><?= e($t['beginn'] . '–' . $t['ende']) ?></td>
            <td data-titel="Plätze"><?= (int)$t['plaetze'] ?></td><td data-titel="Vergeben"><?= anm_belegt((int)$t['id']) ?></td><td data-titel="Warteliste"><?= anm_wartend((int)$t['id']) ?></td></tr>
    <?php endforeach; endforeach; ?>
    <?php if (anm_workshops() === []): ?><tr><td colspan="5" class="meta">Noch kein veröffentlichtes Programm mit Workshops.</td></tr><?php endif; ?>
    </tbody>
</table></div>

<h2 class="zwischen">Personen</h2>
<div class="filterzeile" role="group" aria-label="Filter">
    <?php foreach (['alle' => 'Alle', 'unbestaetigt' => 'Noch nicht bestätigt', 'warteliste' => 'Warteliste Symposium'] as $k => $t): ?>
    <a class="knopf <?= $filter === $k ? '' : 'zweit' ?> klein-knopf" href="/team/anmeldungen?f=<?= e($k) ?>"<?= $filter === $k ? ' aria-current="true"' : '' ?>><?= e($t) ?></a>
    <?php endforeach; ?>
</div>
<?php if ($zeigen === []): ?>
<p class="hinweis-leer">Keine Anmeldungen<?= $filter !== 'alle' ? ' in dieser Auswahl' : '' ?>.</p>
<?php else: ?>
<div class="tabelle-wrap"><table class="t karten">
    <thead><tr><th>Nr.</th><th>Name</th><th>Konto</th><th>Berufsgruppe · Institution</th><th>Status</th><th>Workshops</th><th>Essen</th><th>Angemeldet</th></tr></thead>
    <tbody>
    <?php foreach ($zeigen as $a):
        $ws = [];
        $symText = '';
        foreach ($a['plaetze'] as $p) {
            $r = anm_platz_zeile($p);
            if ((int)$p['termin_id'] === 0) {
                $symText = $p['status'] === 'fest' ? '' : $r['status'];
            } else {
                $t = anm_termin((int)$p['termin_id']);
                $ws[] = ($t !== null ? $t['workshop']['kuerzel'] . ' ' . $t['beginn'] : 'Termin ?') . ($p['status'] === 'fest' ? '' : ' (' . ($p['status'] === 'angebot' ? 'Angebot' : 'WL ' . anm_position($p)[0]) . ')');
            }
        } ?>
        <tr>
            <td data-titel="Nr." class="mono"><?= sprintf('%04d', $a['id']) ?></td>
            <td data-titel="Name"><?= e($a['vorname'] . ' ' . $a['nachname']) ?><br><span class="meta klein"><?= e($a['email']) ?></span></td>
            <td data-titel="Konto"><?= $a['konto_id'] !== null ? '<span class="chip ok">ja</span>' : '<span class="meta">nein</span>' ?></td>
            <td data-titel="Beruf"><?= e($a['berufsgruppe']) ?><?= $a['efn'] !== null ? ' <span class="chip offen">EFN</span>' : '' ?><br><span class="meta klein"><?= e($a['institution']) ?></span></td>
            <td data-titel="Status"><?= $a['status'] === 'unbestaetigt' ? '<span class="chip offen">unbestätigt</span>' : '<span class="chip ok">bestätigt</span>' ?><?= $symText !== '' ? '<br><span class="chip wl">' . e($symText) . '</span>' : '' ?></td>
            <td data-titel="Workshops" class="mono klein"><?= e(implode(', ', $ws) ?: '–') ?></td>
            <td data-titel="Essen"><?= e(ANM_ESSEN[$a['essen']] ?? '') ?></td>
            <td data-titel="Angemeldet" class="mono klein"><?= e(datum_zeit_text($a['erstellt_am'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
<p class="meta klein">Ändern, manuell vergeben und Export folgen mit dem Team-Bereich (AP-5). Mails heute: <?= $ms['heute'] ?> von <?= $ms['limit'] ?> (Rundmails); System-Mails gehen immer raus.</p>
<?php
team_seite_fuss();
