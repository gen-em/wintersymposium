<?php
declare(strict_types=1);

/*
 * Teilnahme (AP-10, E-57): Anwesenheit (Standard „anwesend“, nur Ausnahmen
 * eintragen, E-42) beim Symposium und je Workshop, Unterschriftenliste als
 * PDF, CME-Export, Zertifikate freischalten (einzeln oder alle), Vorschau,
 * Unterschrift als Bild.
 */

$konto = team_tor();
team_schreibschutz('/team/teilnahme'); // abgeschlossene Veranstaltung: nur lesen (E-58)
$kid = (int)$konto['id'];
$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$filter = (string)($_GET['f'] ?? $_POST['f'] ?? 'alle');
$filter = in_array($filter, ['alle', 'fehlt', 'offen'], true) ? $filter : 'alle';
$zurueck = '/team/teilnahme' . (($qs = http_build_query(array_filter(['q' => $q, 'f' => $filter === 'alle' ? '' : $filter]))) !== '' ? '?' . $qs : '');

if (ist_post()) {
    $anker = '';
    $mail = !post_haken('ohne_mail');
    switch (aktion()) {
        case 'erschienen':
            $platz = post_zahl('platz');
            $anker = '#a' . anm_id_von_platz($platz);
            if (!tn_setzen($platz, post_text('wert') === '1', $kid)) {
                flash_setzen('fehler', 'Diesen Platz gibt es nicht mehr.');
            }
            break;
        case 'freischalten':
            $aId = post_zahl('a');
            if (!zertifikat_frei_ab_heute()) {
                fertig('fehler', 'Zertifikate gibt es erst ab dem Veranstaltungstag.', $zurueck . '#a' . $aId);
            }
            $n = zertifikat_freischalten($aId, $kid, $mail);
            fertig($n === 1 ? 'ok' : 'fehler', $n === 1 ? 'Zertifikat freigeschaltet' . ($mail ? ', die Person bekommt eine Mail.' : ' (ohne Mail).') : 'Nicht freigeschaltet: Die Person ist als „nicht erschienen“ markiert oder schon freigeschaltet.', $zurueck . '#a' . $aId);
        case 'alle_freischalten':
            if (!zertifikat_frei_ab_heute()) {
                fertig('fehler', 'Zertifikate gibt es erst ab dem Veranstaltungstag.', $zurueck);
            }
            $n = zertifikat_freischalten(null, $kid, $mail);
            fertig('ok', $n === 0 ? 'Es war niemand mehr offen.' : $n . ' ' . ($n === 1 ? 'Zertifikat' : 'Zertifikate') . ' freigeschaltet' . ($mail ? '; die Mails sind unterwegs.' : ' (ohne Mail).'), $zurueck);
        case 'sperren':
            $aId = post_zahl('a');
            fertig(zertifikat_sperren($aId, $kid) ? 'ok' : 'fehler', 'Freischaltung zurückgenommen. Die Person kann das Zertifikat nicht mehr herunterladen.', $zurueck . '#a' . $aId);
        case 'unterschrift':
            try {
                unterschrift_speichern($_FILES['bild'] ?? [], $kid);
                flash_setzen('ok', 'Unterschrift gespeichert. Prüf sie in der Vorschau.');
            } catch (RuntimeException $f) {
                flash_setzen('fehler', $f->getMessage());
            }
            $anker = '#zertifikat';
            break;
        case 'unterschrift_weg':
            unterschrift_entfernen($kid);
            fertig('ok', 'Unterschrift entfernt.', $zurueck . '#zertifikat');
    }
    umleiten($zurueck . $anker);
}

$alle = tn_liste();
$z = tn_zahlen();
$liste = array_values(array_filter($alle, function (array $e) use ($q, $filter): bool {
    if ($filter === 'fehlt' && $e['anwesend'] && array_filter($e['workshops'], fn($w) => (int)$w['nicht_erschienen'] === 1) === []) {
        return false;
    }
    if ($filter === 'offen' && (!$e['anwesend'] || $e['anm']['zertifikat_am'] !== null)) {
        return false;
    }
    return $q === '' || str_contains(mb_strtolower($e['anm']['vorname'] . ' ' . $e['anm']['nachname'] . ' ' . $e['anm']['email'] . ' ' . $e['anm']['berufsgruppe']), mb_strtolower($q));
}));
$abHeute = zertifikat_frei_ab_heute();
team_seite_kopf($konto, 'Teilnahme', '/team/teilnahme');
?>
<p class="meta klein">Alle mit Platz gelten als anwesend. Trag nur ein, wer nicht erschienen ist – beim Symposium oder in einem Workshop. Nachweis bleibt die Unterschriftenliste.</p>

<div class="kacheln">
    <?= kachel('Mit Platz', $z['angemeldet'], 'bestätigte Anmeldungen mit Platz beim Symposium') ?>
    <?= kachel('Anwesend', $z['anwesend'], $z['nicht_erschienen'] . ' nicht erschienen') ?>
    <?= kachel('Zertifikate', $z['freigeschaltet'], 'freigeschaltet · ' . $z['offen'] . ' noch offen') ?>
</div>

<div class="team-raster abstand-oben">
    <div class="kasten">
        <h2 class="zwischen klein-zwischen">Listen zum Ausdrucken</h2>
        <form class="formular" method="get" action="/team/teilnahme/liste">
            <?= feld('liste', 'Unterschriftenliste', ['typ' => 'auswahl', 'optionen' => ['alle' => 'Alle Teilnehmenden (mit Berufsgruppe)', 'aerzte' => 'Nur ' . (va_liste('berufsgruppen')[0] ?? 'ÄrztInnen') . ' (mit EFN-Barcode)']]) ?>
            <?= feld('sort', 'Sortiert nach', ['typ' => 'auswahl', 'optionen' => TN_SORTIERUNG]) ?>
            <button class="knopf" type="submit">PDF herunterladen</button>
        </form>
        <p class="meta klein abstand-oben-klein">Ohne EFN steht ein Feld fürs Etikett da. Listen je Workshop-Termin gibt es unter <a href="/team/workshops">Workshops</a>.</p>
        <p class="unterkopf">CME-Liste für die Kammer</p>
        <p class="meta klein">Name und EFN der anwesenden <?= e(va_liste('berufsgruppen')[0] ?? 'ÄrztInnen') ?>.</p>
        <p class="aktionen"><?= export_knoepfe(['art' => 'cme']) ?></p>
    </div>
    <div class="kasten" id="zertifikat">
        <h2 class="zwischen klein-zwischen">Zertifikate</h2>
        <p class="meta klein">Freigeschaltet bekommen Anwesende eine Mail mit Link und laden das PDF unter „Meine Anmeldung“ herunter. Wer als „nicht erschienen“ markiert ist, bekommt keins.</p>
        <p class="aktionen"><a class="knopf zweit klein-knopf" href="/team/teilnahme/zertifikat?beispiel=1" target="_blank" rel="noopener">Vorschau mit Beispiel</a> <a class="klein-link" href="/team/einstellungen#zertifikat">Angaben auf dem Zertifikat</a></p>
        <?php if (!$abHeute): ?>
        <?= leer_hinweis('Freischalten geht ab dem Veranstaltungstag (' . tag_text(va('veranstaltung_datum')) . ').') ?>
        <?php elseif ($z['offen'] > 0): ?>
        <?= bestaetigung_html('Alle ' . $z['offen'] . ' freischalten', $z['offen'] . ' Anwesende bekommen ihr Zertifikat und eine Mail mit Link. Erst „nicht erschienen“ eintragen, dann freischalten.', 'Ja, freischalten',
            ['aktion' => 'alle_freischalten'], ['ziel' => $zurueck, 'titel' => 'Zertifikate freischalten?', 'knopfklasse' => 'knopf', 'ja_klasse' => 'knopf', 'inhalt_html' => feld('ohne_mail', 'ohne Mail', ['typ' => 'haken'])]) ?>
        <?php else: ?>
        <p class="meta">Alle Anwesenden sind freigeschaltet.</p>
        <?php endif; ?>
        <p class="unterkopf">Unterschrift</p>
        <?php if (unterschrift_da()): ?>
        <p><img class="unterschrift-bild" src="/team/teilnahme/unterschrift?v=<?= (int)filemtime(unterschrift_pfad()) ?>" alt="Unterschrift auf dem Zertifikat"></p>
        <?= aktion_knopf($zurueck, 'unterschrift_weg', [], 'Unterschrift entfernen', 'klein-link') ?>
        <?php else: ?>
        <p class="meta klein">Noch keine – auf dem Zertifikat steht dann nur die Linie mit Name.</p>
        <?php endif; ?>
        <?php if (foto_moeglich()): ?>
        <form class="formular" method="post" action="<?= e($zurueck) ?>" enctype="multipart/form-data"><?= csrf_feld() . versteckt_html(['aktion' => 'unterschrift']) ?>
            <?= feld('bild', unterschrift_da() ? 'Neues Bild' : 'Bild hochladen', ['typ' => 'file', 'pflicht' => true, 'attr' => ['accept' => 'image/png,image/jpeg'],
                'hilfe' => 'PNG mit durchsichtigem Hintergrund (oder JPG auf Weiß), höchstens ' . bytes_text(UNTERSCHRIFT_MAX_BYTES) . '.']) ?>
            <button class="knopf zweit" type="submit">Hochladen</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<h2 class="zwischen" id="anwesenheit">Anwesenheit</h2>
<?= filter_leiste(['ziel' => '/team/teilnahme', 'knopf' => 'Anzeigen', 'felder' =>
    feld('q', 'Suche', ['typ' => 'search', 'sr' => true, 'ohne_rahmen' => true, 'wert' => $q, 'attr' => ['placeholder' => 'Name, Adresse, Berufsgruppe']])
    . feld('f', 'Filter', ['typ' => 'auswahl', 'sr' => true, 'ohne_rahmen' => true, 'wert' => $filter, 'optionen' => ['alle' => 'Alle', 'fehlt' => 'Mit „nicht erschienen“', 'offen' => 'Zertifikat noch offen']])]) ?>
<?php if ($liste === []): ?>
<?= leer_hinweis($alle === [] ? 'Noch niemand mit Platz beim Symposium.' : 'Niemand passt zur Suche.') ?>
<?php else: ?>
<div class="tabelle-wrap"><table class="t karten teilnahme">
    <thead><tr><th>Person</th><th>Symposium</th><th>Workshops</th><th>Zertifikat</th></tr></thead>
    <tbody>
    <?php foreach ($liste as $e): $a = $e['anm']; $aid = (int)$a['id']; ?>
        <tr id="a<?= $aid ?>"<?= $e['anwesend'] ? '' : ' class="fehlt"' ?>>
            <td data-label="Person"><a href="/team/personen?a=<?= $aid ?>"><?= e($a['nachname'] . ', ' . $a['vorname']) ?></a><br><span class="meta klein"><?= e($a['berufsgruppe']) ?><?= $e['aerztin'] && $a['efn'] !== null ? ' · EFN' : '' ?></span></td>
            <td data-label="Symposium">
                <?= $e['anwesend']
                    ? aktion_knopf($zurueck, 'erschienen', ['platz' => (int)$e['platz']['id'], 'wert' => '0'], 'nicht erschienen', 'klein-link', ['vor_html' => chip('ok', 'anwesend') . ' '])
                    : aktion_knopf($zurueck, 'erschienen', ['platz' => (int)$e['platz']['id'], 'wert' => '1'], 'doch da', 'klein-link', ['vor_html' => chip('wl', 'nicht erschienen') . ' ']) ?>
            </td>
            <td data-label="Workshops">
                <?php if ($e['workshops'] === []): ?><span class="meta klein">keine</span><?php endif; ?>
                <?php foreach ($e['workshops'] as $w): $da = (int)$w['nicht_erschienen'] === 0; ?>
                <?php // von Hand: aktion_knopf() kennt keinen gesperrten Knopf (disabled) ?>
                <form class="ws-anwesend" method="post" action="<?= e($zurueck) ?>"><?= csrf_feld() . versteckt_html(['aktion' => 'erschienen', 'platz' => (int)$w['id'], 'wert' => $da ? '0' : '1']) ?>
                    <span class="<?= $da ? '' : 'durch' ?>"><?= e($w['text']) ?></span><?= $da ? '' : ' ' . chip('wl', 'nicht erschienen') ?>
                    <button class="klein-link" type="submit"<?= $e['anwesend'] ? '' : ' disabled' ?>><?= $da ? 'nicht erschienen' : 'doch da' ?></button></form>
                <?php endforeach; ?>
            </td>
            <td data-label="Zertifikat">
                <?php if (!$e['anwesend']): ?>
                <span class="meta klein">keins (nicht erschienen)</span>
                <?php elseif ($a['zertifikat_am'] !== null): ?>
                <?= chip('ok', 'freigeschaltet') ?> <span class="meta klein"><?= e(datum_zeit_text($a['zertifikat_am'])) ?></span>
                <span class="zellen-aktionen"><a class="klein-link" href="/team/teilnahme/zertifikat?a=<?= $aid ?>" target="_blank" rel="noopener">PDF</a>
                    <?= aktion_knopf($zurueck, 'sperren', ['a' => $aid], 'zurücknehmen', 'klein-link') ?></span>
                <?php else: ?>
                <?= chip('offen', 'offen') ?>
                <span class="zellen-aktionen"><a class="klein-link" href="/team/teilnahme/zertifikat?a=<?= $aid ?>" target="_blank" rel="noopener">Vorschau</a>
                    <?= $abHeute ? aktion_knopf($zurueck, 'freischalten', ['a' => $aid], 'Freischalten') : '' ?></span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<p class="meta klein"><?= count($liste) ?> von <?= count($alle) ?> angezeigt.</p>
<?php endif;
team_seite_fuss();
