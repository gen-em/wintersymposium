<?php
declare(strict_types=1);

/*
 * Protokoll (AP-5, E-49): Audit-Einträge lesen, filtern und suchen
 * (Admin und Betreiber). Personenbezogene Inhalte stehen nicht im Protokoll,
 * nur Nummern von Anmeldungen und Konten.
 */

$konto = team_tor();
$art = (string)($_GET['art'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$seite = max(1, (int)($_GET['s'] ?? 1));
$proSeite = 50;
$wo = [];
$par = [];
if ($art !== '') {
    $wo[] = 'a.art = ?';
    $par[] = $art;
}
if ($q !== '') {
    $wo[] = 'a.text LIKE ?';
    $par[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
}
$sqlWo = $wo === [] ? '' : ' WHERE ' . implode(' AND ', $wo);
$gesamt = (int)db_wert('SELECT COUNT(*) FROM audit a' . $sqlWo, $par);
$zeilen = db_zeilen('SELECT a.*, k.name FROM audit a LEFT JOIN konto k ON k.id = a.konto_id' . $sqlWo . ' ORDER BY a.id DESC LIMIT ' . $proSeite . ' OFFSET ' . (($seite - 1) * $proSeite), $par);
$arten = array_column(db_zeilen('SELECT DISTINCT art FROM audit ORDER BY art'), 'art');
$link = fn(int $s) => '/team/protokoll?' . http_build_query(array_filter(['art' => $art ?: null, 'q' => $q ?: null, 's' => $s > 1 ? $s : null]));
team_seite_kopf($konto, 'Protokoll', '/team/protokoll');
?>
<form class="filterzeile" method="get" action="/team/protokoll" role="search">
    <label class="sr" for="art">Art</label>
    <select id="art" name="art"><option value="">Alle Arten</option><?php foreach ($arten as $a): ?><option value="<?= e($a) ?>"<?= $art === $a ? ' selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select>
    <label class="sr" for="q">Suchen</label>
    <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="z. B. Anmeldung 12">
    <button class="knopf zweit klein-knopf" type="submit">Filtern</button>
</form>
<p class="meta klein"><?= $gesamt ?> Einträge<?= $gesamt > $proSeite ? ' · Seite ' . $seite . ' von ' . (int)ceil($gesamt / $proSeite) : '' ?></p>
<?php if ($zeilen === []): ?><p class="hinweis-leer">Keine Einträge.</p><?php else: ?>
<div class="tabelle-wrap"><table class="t karten">
    <thead><tr><th>Zeit</th><th>Art</th><th>Was</th><th>Wer</th></tr></thead>
    <tbody>
    <?php foreach ($zeilen as $z): ?>
        <tr><td data-label="Zeit" class="mono klein"><?= e(datum_zeit_text($z['zeit'])) ?></td><td data-label="Art"><span class="chip offen"><?= e($z['art']) ?></span></td><td data-label="Was"><?= e($z['text']) ?></td><td data-label="Wer"><?= e($z['name'] ?? ($z['konto_id'] !== null ? 'Konto ' . $z['konto_id'] : 'System / Teilnehmende')) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<div class="aktionen abstand-oben-klein">
    <?php if ($seite > 1): ?><a class="knopf zweit klein-knopf" href="<?= e($link($seite - 1)) ?>">Neuere</a><?php endif; ?>
    <?php if ($seite * $proSeite < $gesamt): ?><a class="knopf zweit klein-knopf" href="<?= e($link($seite + 1)) ?>">Ältere</a><?php endif; ?>
</div>
<?php endif; ?>
<?php
team_seite_fuss();
