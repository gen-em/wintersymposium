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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $anker = '';
    switch ((string)($_POST['aktion'] ?? '')) {
        case 'erschienen':
            $platz = (int)($_POST['platz'] ?? 0);
            $anker = '#a' . (int)db_wert('SELECT anmeldung_id FROM platz WHERE id = ?', [$platz]);
            if (!tn_setzen($platz, ($_POST['wert'] ?? '') === '1', $kid)) {
                flash_setzen('fehler', 'Diesen Platz gibt es nicht mehr.');
            }
            break;
        case 'freischalten':
            $aId = (int)($_POST['a'] ?? 0);
            $anker = '#a' . $aId;
            if (!zertifikat_frei_ab_heute()) {
                flash_setzen('fehler', 'Zertifikate gibt es erst ab dem Veranstaltungstag.');
            } else {
                $n = zertifikat_freischalten($aId, $kid, empty($_POST['ohne_mail']));
                flash_setzen($n === 1 ? 'ok' : 'fehler', $n === 1 ? 'Zertifikat freigeschaltet' . (empty($_POST['ohne_mail']) ? ', die Person bekommt eine Mail.' : ' (ohne Mail).') : 'Nicht freigeschaltet: Die Person ist als „nicht erschienen“ markiert oder schon freigeschaltet.');
            }
            break;
        case 'alle_freischalten':
            if (!zertifikat_frei_ab_heute()) {
                flash_setzen('fehler', 'Zertifikate gibt es erst ab dem Veranstaltungstag.');
            } else {
                $n = zertifikat_freischalten(null, $kid, empty($_POST['ohne_mail']));
                flash_setzen('ok', $n === 0 ? 'Es war niemand mehr offen.' : $n . ' ' . ($n === 1 ? 'Zertifikat' : 'Zertifikate') . ' freigeschaltet' . (empty($_POST['ohne_mail']) ? '; die Mails sind unterwegs.' : ' (ohne Mail).'));
            }
            break;
        case 'sperren':
            $aId = (int)($_POST['a'] ?? 0);
            $anker = '#a' . $aId;
            flash_setzen(zertifikat_sperren($aId, $kid) ? 'ok' : 'fehler', 'Freischaltung zurückgenommen. Die Person kann das Zertifikat nicht mehr herunterladen.');
            break;
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
            flash_setzen('ok', 'Unterschrift entfernt.');
            $anker = '#zertifikat';
            break;
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
$ziel = e($zurueck);
team_seite_kopf($konto, 'Teilnahme', '/team/teilnahme');
?>
<p class="meta klein">Alle mit Platz gelten als anwesend. Trag nur ein, wer nicht erschienen ist – beim Symposium oder in einem Workshop. Nachweis bleibt die Unterschriftenliste.</p>

<div class="kacheln">
    <div class="kachel"><div class="lbl">Mit Platz</div><div class="zahl"><?= $z['angemeldet'] ?></div><p class="meta klein">bestätigte Anmeldungen mit Platz beim Symposium</p></div>
    <div class="kachel"><div class="lbl">Anwesend</div><div class="zahl"><?= $z['anwesend'] ?></div><p class="meta klein"><?= $z['nicht_erschienen'] ?> nicht erschienen</p></div>
    <div class="kachel"><div class="lbl">Zertifikate</div><div class="zahl"><?= $z['freigeschaltet'] ?></div><p class="meta klein">freigeschaltet · <?= $z['offen'] ?> noch offen</p></div>
</div>

<div class="team-raster abstand-oben">
    <div class="kasten">
        <h2 class="zwischen klein-zwischen">Listen zum Ausdrucken</h2>
        <form class="formular" method="get" action="/team/teilnahme/liste">
            <div class="feld"><label for="liste">Unterschriftenliste</label>
                <select id="liste" name="liste"><option value="alle">Alle Teilnehmenden (mit Berufsgruppe)</option><option value="aerzte">Nur <?= e(va_liste('berufsgruppen')[0] ?? 'ÄrztInnen') ?> (mit EFN-Barcode)</option></select></div>
            <div class="feld"><label for="sort">Sortiert nach</label>
                <select id="sort" name="sort"><?php foreach (TN_SORTIERUNG as $k => $t): ?><option value="<?= $k ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
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
        <p class="hinweis-leer">Freischalten geht ab dem Veranstaltungstag (<?= e(tag_text(va('veranstaltung_datum'))) ?>).</p>
        <?php elseif ($z['offen'] > 0): ?>
        <details class="bestaetigen" data-titel="Zertifikate freischalten?"><summary class="knopf">Alle <?= $z['offen'] ?> freischalten</summary>
            <form method="post" action="<?= $ziel ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="alle_freischalten">
                <p><?= $z['offen'] ?> Anwesende bekommen ihr Zertifikat und eine Mail mit Link. Erst „nicht erschienen“ eintragen, dann freischalten.</p>
                <label class="haken"><input type="checkbox" name="ohne_mail" value="1"><span>ohne Mail</span></label>
                <button class="knopf" type="submit">Ja, freischalten</button></form>
        </details>
        <?php else: ?>
        <p class="meta">Alle Anwesenden sind freigeschaltet.</p>
        <?php endif; ?>
        <p class="unterkopf">Unterschrift</p>
        <?php if (unterschrift_da()): ?>
        <p><img class="unterschrift-bild" src="/team/teilnahme/unterschrift?v=<?= (int)filemtime(unterschrift_pfad()) ?>" alt="Unterschrift auf dem Zertifikat"></p>
        <form method="post" action="<?= $ziel ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="unterschrift_weg"><button class="klein-link" type="submit">Unterschrift entfernen</button></form>
        <?php else: ?>
        <p class="meta klein">Noch keine – auf dem Zertifikat steht dann nur die Linie mit Name.</p>
        <?php endif; ?>
        <?php if (foto_moeglich()): ?>
        <form class="formular" method="post" action="<?= $ziel ?>" enctype="multipart/form-data"><?= csrf_feld() ?><input type="hidden" name="aktion" value="unterschrift">
            <div class="feld"><label for="bild"><?= unterschrift_da() ? 'Neues Bild' : 'Bild hochladen' ?></label><input id="bild" type="file" name="bild" accept="image/png,image/jpeg" required>
                <p class="hilfe">PNG mit durchsichtigem Hintergrund (oder JPG auf Weiß), höchstens 4 MB.</p></div>
            <button class="knopf zweit" type="submit">Hochladen</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<h2 class="zwischen" id="anwesenheit">Anwesenheit</h2>
<form class="filterzeile" method="get" action="/team/teilnahme">
    <label for="q" class="sr">Suche</label><input id="q" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, Adresse, Berufsgruppe">
    <label for="f" class="sr">Filter</label>
    <select id="f" name="f">
        <option value="alle"<?= $filter === 'alle' ? ' selected' : '' ?>>Alle</option>
        <option value="fehlt"<?= $filter === 'fehlt' ? ' selected' : '' ?>>Mit „nicht erschienen“</option>
        <option value="offen"<?= $filter === 'offen' ? ' selected' : '' ?>>Zertifikat noch offen</option>
    </select>
    <button class="knopf zweit klein-knopf" type="submit">Anzeigen</button>
</form>
<?php if ($liste === []): ?>
<p class="hinweis-leer"><?= $alle === [] ? 'Noch niemand mit Platz beim Symposium.' : 'Niemand passt zur Suche.' ?></p>
<?php else: ?>
<div class="tabelle-wrap"><table class="t karten teilnahme">
    <thead><tr><th>Person</th><th>Symposium</th><th>Workshops</th><th>Zertifikat</th></tr></thead>
    <tbody>
    <?php foreach ($liste as $e): $a = $e['anm']; $aid = (int)$a['id']; ?>
        <tr id="a<?= $aid ?>"<?= $e['anwesend'] ? '' : ' class="fehlt"' ?>>
            <td data-label="Person"><a href="/team/personen?a=<?= $aid ?>"><?= e($a['nachname'] . ', ' . $a['vorname']) ?></a><br><span class="meta klein"><?= e($a['berufsgruppe']) ?><?= $e['aerztin'] && $a['efn'] !== null ? ' · EFN' : '' ?></span></td>
            <td data-label="Symposium">
                <form method="post" action="<?= $ziel ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="erschienen"><input type="hidden" name="platz" value="<?= (int)$e['platz']['id'] ?>">
                <?php if ($e['anwesend']): ?>
                    <span class="chip ok">anwesend</span> <input type="hidden" name="wert" value="0"><button class="klein-link" type="submit">nicht erschienen</button>
                <?php else: ?>
                    <span class="chip wl">nicht erschienen</span> <input type="hidden" name="wert" value="1"><button class="klein-link" type="submit">doch da</button>
                <?php endif; ?>
                </form>
            </td>
            <td data-label="Workshops">
                <?php if ($e['workshops'] === []): ?><span class="meta klein">keine</span><?php endif; ?>
                <?php foreach ($e['workshops'] as $w): $da = (int)$w['nicht_erschienen'] === 0; ?>
                <form class="ws-anwesend" method="post" action="<?= $ziel ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="erschienen"><input type="hidden" name="platz" value="<?= (int)$w['id'] ?>"><input type="hidden" name="wert" value="<?= $da ? '0' : '1' ?>">
                    <span class="<?= $da ? '' : 'durch' ?>"><?= e($w['text']) ?></span><?= $da ? '' : ' <span class="chip wl">nicht erschienen</span>' ?>
                    <button class="klein-link" type="submit"<?= $e['anwesend'] ? '' : ' disabled' ?>><?= $da ? 'nicht erschienen' : 'doch da' ?></button></form>
                <?php endforeach; ?>
            </td>
            <td data-label="Zertifikat">
                <?php if (!$e['anwesend']): ?>
                <span class="meta klein">keins (nicht erschienen)</span>
                <?php elseif ($a['zertifikat_am'] !== null): ?>
                <span class="chip ok">freigeschaltet</span> <span class="meta klein"><?= e(datum_zeit_text($a['zertifikat_am'])) ?></span>
                <span class="zellen-aktionen"><a class="klein-link" href="/team/teilnahme/zertifikat?a=<?= $aid ?>" target="_blank" rel="noopener">PDF</a>
                    <form method="post" action="<?= $ziel ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="sperren"><input type="hidden" name="a" value="<?= $aid ?>"><button class="klein-link" type="submit">zurücknehmen</button></form></span>
                <?php else: ?>
                <span class="chip offen">offen</span>
                <span class="zellen-aktionen"><a class="klein-link" href="/team/teilnahme/zertifikat?a=<?= $aid ?>" target="_blank" rel="noopener">Vorschau</a>
                    <?php if ($abHeute): ?><form method="post" action="<?= $ziel ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="freischalten"><input type="hidden" name="a" value="<?= $aid ?>"><button class="knopf zweit klein-knopf" type="submit">Freischalten</button></form><?php endif; ?></span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<p class="meta klein"><?= count($liste) ?> von <?= count($alle) ?> angezeigt.</p>
<?php endif;
team_seite_fuss();
