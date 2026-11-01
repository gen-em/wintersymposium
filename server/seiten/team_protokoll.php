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
if (ist_post()) {
    if (!$betreiber) {
        seite_meldung(403, 'Nicht erlaubt', 'Einträge blendet nur eine BetreiberIn aus.');
    }
    $aus = aktion() === 'ausblenden';
    fertig('ok', audit_ausblenden(post_zahl('id'), $aus, (int)$konto['id']) ? ($aus ? 'Eintrag ausgeblendet – nur BetreiberInnen sehen ihn noch.' : 'Eintrag wieder eingeblendet.') : 'Nichts geändert.',
        '/team/protokoll?' . http_build_query(array_filter(['art' => post_text('art'), 'q' => post_text('q'), 's' => post_text('s'), 'v' => post_text('v')])));
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
<?= filter_chips(['' => 'Gewählte Veranstaltung', 'alle' => $betreiber ? 'Alle Einträge' : 'Alle meine Veranstaltungen'] + ($betreiber ? ['ohne' => 'Ohne Veranstaltung'] : []),
    $vf, fn(string $k) => $link(['v' => $k !== '' ? $k : null, 's' => null]), 'Veranstaltung') ?>
<?= filter_leiste(['ziel' => '/team/protokoll', 'suche' => true, 'versteckt' => ['art' => $art, 'v' => $vf],
    'felder' => feld('q', 'Suchen', ['typ' => 'search', 'wert' => $q, 'attr' => ['placeholder' => 'z. B. Anmeldung 12'], 'sr' => true, 'ohne_rahmen' => true])]) ?>
<?php
$artFilter = ['' => ['Alle', array_sum($zahlen)]];
foreach (array_keys($zahlen + ($art !== '' ? [$art => 0] : [])) as $a) {
    $artFilter[$a] = [$titel((string)$a), $zahlen[$a] ?? 0];
}
?>
<?= filter_chips($artFilter, $art, fn(string $k) => $link(['art' => $k !== '' ? $k : null]), 'Art') ?>
<p class="meta klein"><?= $gesamt ?> Einträge<?= $gesamt > $proSeite ? ' · Seite ' . $seite . ' von ' . (int)ceil($gesamt / $proSeite) : '' ?></p>
<?php if ($zeilen === []): ?><?= leer_hinweis('Einträge') ?><?php else: ?>
<div class="tabelle-wrap"><table class="t karten">
    <thead><tr><th>Zeit</th><th>Art</th><th>Veranstaltung</th><th>Was</th><th>Wer</th><?= $betreiber ? '<th>Sichtbar</th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($zeilen as $z): $weg = $z['ausgeblendet_am'] !== null; ?>
        <tr<?= $weg ? ' class="ausgeblendet"' : '' ?>><td data-label="Zeit" class="mono klein"><?= e(datum_zeit_text($z['zeit'])) ?></td><td data-label="Art"><?= chip('offen', $titel($z['art'])) ?></td><td data-label="Veranstaltung" class="klein"><?= isset($z['veranstaltung_id']) ? e($vaNamen[(int)$z['veranstaltung_id']] ?? 'gelöscht') : '<span class="meta">–</span>' ?></td><td data-label="Was"><?= e($z['text']) ?></td><td data-label="Wer"><?= e($z['name'] ?? ($z['konto_id'] !== null ? 'Konto ' . $z['konto_id'] : 'System / Teilnehmende')) ?></td>
        <?php if ($betreiber): ?><td data-label="Sichtbar"><?php if ($z['art'] !== 'protokoll'): ?>
            <?= aktion_knopf('/team/protokoll', $weg ? 'einblenden' : 'ausblenden', ['id' => (int)$z['id'], 'art' => $art, 'q' => $q, 's' => $seite > 1 ? $seite : '', 'v' => $vf],
                $weg ? 'Einblenden' : 'Ausblenden', 'knopf zweit klein-knopf', ['form_klasse' => 'inline-form', 'vor_html' => $weg ? chip('gesperrt', 'nur BetreiberInnen') . ' ' : '']) ?>
            <?php else: ?><span class="meta klein">nur BetreiberInnen</span><?php endif; ?></td><?php endif; ?></tr>
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
