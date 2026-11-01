<?php
declare(strict_types=1);

/*
 * Protokoll (AP-5, E-49; Rückmeldung 2026-10-30): Audit-Einträge lesen,
 * filtern und suchen. Admins sehen die Arten, die sie selbst ausführen
 * dürfen, aus ihren Veranstaltungen (AP-12, E-60); Filter nach Veranstaltung
 * (gewählte, alle meine; Betreiber auch „ohne Veranstaltung“). Betreiber sehen alles und können
 * Einträge ausblenden (dann nur für Betreiber, markiert) – löschen geht nicht.
 * Personenbezogene Inhalte stehen nicht im Protokoll, nur Nummern.
 */

$konto = team_tor();
$betreiber = $konto['rolle'] === 'betreiber';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    if (!$betreiber) {
        seite_meldung(403, 'Nicht erlaubt', 'Einträge blendet nur eine BetreiberIn aus.');
    }
    $aus = ($_POST['aktion'] ?? '') === 'ausblenden';
    flash_setzen('ok', audit_ausblenden((int)($_POST['id'] ?? 0), $aus, (int)$konto['id']) ? ($aus ? 'Eintrag ausgeblendet – nur BetreiberInnen sehen ihn noch.' : 'Eintrag wieder eingeblendet.') : 'Nichts geändert.');
    umleiten('/team/protokoll?' . http_build_query(array_filter(['art' => $_POST['art'] ?? null, 'q' => $_POST['q'] ?? null, 's' => $_POST['s'] ?? null, 'v' => $_POST['v'] ?? null])));
}
$art = (string)($_GET['art'] ?? '');
// Veranstaltung: '' = die gewählte, 'alle' = alle sichtbaren, 'ohne' = ohne Veranstaltung (nur Betreiber)
$vf = in_array($_GET['v'] ?? '', $betreiber ? ['alle', 'ohne'] : ['alle'], true) ? (string)$_GET['v'] : '';
$q = trim((string)($_GET['q'] ?? ''));
$seite = max(1, (int)($_GET['s'] ?? 1));
$proSeite = 50;
['zahlen' => $zahlen, 'gesamt' => $gesamt, 'zeilen' => $zeilen] = audit_suchen($konto, $vf, $q, $art, $seite, $proSeite);
$link = fn(array $neu) => '/team/protokoll?' . http_build_query(array_filter(array_merge(['art' => $art ?: null, 'q' => $q ?: null, 'v' => $vf ?: null], $neu)));
$vaNamen = array_column(va_alle(), 'kuerzel', 'id');
$titel = fn(string $a) => AUDIT_ARTEN[$a] ?? $a;
team_seite_kopf($konto, 'Protokoll', '/team/protokoll');
?>
<p class="meta klein"><?= $betreiber ? 'Du siehst alle Einträge. Ausgeblendete sehen nur BetreiberInnen.' : 'Du siehst die Einträge zu Anmeldungen, Programm, Einstellungen, Texten, Exporten, Mailings und Teilnahme aus deinen Veranstaltungen.' ?></p>
<div class="filter" role="group" aria-label="Veranstaltung">
    <a class="knopf klein-knopf<?= $vf === '' ? '' : ' zweit' ?>" href="<?= e($link(['v' => null, 's' => null])) ?>"<?= $vf === '' ? ' aria-current="true"' : '' ?>>Gewählte Veranstaltung</a>
    <a class="knopf klein-knopf<?= $vf === 'alle' ? '' : ' zweit' ?>" href="<?= e($link(['v' => 'alle', 's' => null])) ?>"<?= $vf === 'alle' ? ' aria-current="true"' : '' ?>><?= $betreiber ? 'Alle Einträge' : 'Alle meine Veranstaltungen' ?></a>
    <?php if ($betreiber): ?><a class="knopf klein-knopf<?= $vf === 'ohne' ? '' : ' zweit' ?>" href="<?= e($link(['v' => 'ohne', 's' => null])) ?>"<?= $vf === 'ohne' ? ' aria-current="true"' : '' ?>>Ohne Veranstaltung</a><?php endif; ?>
</div>
<form class="filterzeile" method="get" action="/team/protokoll" role="search">
    <?php if ($art !== ''): ?><input type="hidden" name="art" value="<?= e($art) ?>"><?php endif; ?>
    <?php if ($vf !== ''): ?><input type="hidden" name="v" value="<?= e($vf) ?>"><?php endif; ?>
    <label class="sr" for="q">Suchen</label>
    <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="z. B. Anmeldung 12">
    <button class="knopf zweit klein-knopf" type="submit">Suchen</button>
</form>
<div class="filter" role="group" aria-label="Art">
    <a class="knopf klein-knopf<?= $art === '' ? '' : ' zweit' ?>" href="<?= e($link(['art' => null])) ?>"<?= $art === '' ? ' aria-current="true"' : '' ?>>Alle <span class="mono"><?= array_sum($zahlen) ?></span></a>
    <?php foreach (array_keys($zahlen + ($art !== '' ? [$art => 0] : [])) as $a): ?>
    <a class="knopf klein-knopf<?= $art === $a ? '' : ' zweit' ?>" href="<?= e($link(['art' => $a])) ?>"<?= $art === $a ? ' aria-current="true"' : '' ?>><?= e($titel($a)) ?> <span class="mono"><?= $zahlen[$a] ?? 0 ?></span></a>
    <?php endforeach; ?>
</div>
<p class="meta klein"><?= $gesamt ?> Einträge<?= $gesamt > $proSeite ? ' · Seite ' . $seite . ' von ' . (int)ceil($gesamt / $proSeite) : '' ?></p>
<?php if ($zeilen === []): ?><p class="hinweis-leer">Keine Einträge.</p><?php else: ?>
<div class="tabelle-wrap"><table class="t karten">
    <thead><tr><th>Zeit</th><th>Art</th><th>Veranstaltung</th><th>Was</th><th>Wer</th><?= $betreiber ? '<th>Sichtbar</th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($zeilen as $z): $weg = $z['ausgeblendet_am'] !== null; ?>
        <tr<?= $weg ? ' class="ausgeblendet"' : '' ?>><td data-label="Zeit" class="mono klein"><?= e(datum_zeit_text($z['zeit'])) ?></td><td data-label="Art"><span class="chip offen"><?= e($titel($z['art'])) ?></span></td><td data-label="Veranstaltung" class="klein"><?= isset($z['veranstaltung_id']) ? e($vaNamen[(int)$z['veranstaltung_id']] ?? 'gelöscht') : '<span class="meta">–</span>' ?></td><td data-label="Was"><?= e($z['text']) ?></td><td data-label="Wer"><?= e($z['name'] ?? ($z['konto_id'] !== null ? 'Konto ' . $z['konto_id'] : 'System / Teilnehmende')) ?></td>
        <?php if ($betreiber): ?><td data-label="Sichtbar"><?php if ($z['art'] !== 'protokoll'): ?>
            <form method="post" action="/team/protokoll" class="inline-form"><?= csrf_feld() ?><input type="hidden" name="id" value="<?= (int)$z['id'] ?>"><input type="hidden" name="art" value="<?= e($art) ?>"><input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="s" value="<?= $seite > 1 ? $seite : '' ?>"><input type="hidden" name="v" value="<?= e($vf) ?>">
                <?php if ($weg): ?><span class="chip gesperrt">nur BetreiberInnen</span> <button class="knopf zweit klein-knopf" type="submit" name="aktion" value="einblenden">Einblenden</button>
                <?php else: ?><button class="knopf zweit klein-knopf" type="submit" name="aktion" value="ausblenden">Ausblenden</button><?php endif; ?>
            </form><?php else: ?><span class="meta klein">nur BetreiberInnen</span><?php endif; ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<div class="aktionen abstand-oben-klein">
    <?php if ($seite > 1): ?><a class="knopf zweit klein-knopf" href="<?= e($link(['s' => $seite - 1 > 1 ? $seite - 1 : null])) ?>">Neuere</a><?php endif; ?>
    <?php if ($seite * $proSeite < $gesamt): ?><a class="knopf zweit klein-knopf" href="<?= e($link(['s' => $seite + 1])) ?>">Ältere</a><?php endif; ?>
</div>
<?php endif; ?>
<?php
team_seite_fuss();
