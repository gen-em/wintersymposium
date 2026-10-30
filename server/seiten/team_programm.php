<?php
declare(strict_types=1);

/*
 * Programm pflegen (AP-3 Schritt 3, E-39) nach dem Mockup „Team: Programm“:
 * links der Tagesplan nach Uhrzeit mit Zieh-Griffen, rechts das Formular.
 * Speichern ändert nur den Arbeitsstand; der rote Banner zeigt, was noch nicht
 * veröffentlicht ist. Ziehen schlägt eine Uhrzeit vor (assets/programm-team.js);
 * ohne Maus geht dasselbe über Beginn und Ende im Formular.
 */

$konto = team_tor();
$ich = (int)$konto['id'];
$fehler = [];
$eingabe = null;
$pubFehler = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $aktion = (string)($_POST['aktion'] ?? '');
    $id = (int)($_POST['id'] ?? 0) ?: null;
    switch ($aktion) {
        case 'speichern':
            if ($id !== null && db_wert('SELECT COUNT(*) FROM programmpunkt WHERE id = ?', [$id]) != 1) {
                seite_meldung(404, 'Nicht gefunden', 'Diesen Programmpunkt gibt es nicht mehr.');
            }
            $r = programm_punkt_pruefen($_POST, $id);
            if ($r['fehler'] === []) {
                $id = programm_punkt_speichern($id, $r, $ich);
                flash_setzen('ok', 'Gespeichert – noch nicht veröffentlicht. Die Startseite ändert sich erst mit „Jetzt veröffentlichen“.');
                umleiten('/team/programm?id=' . $id);
            }
            $fehler = $r['fehler'];
            $eingabe = $_POST;
            break;
        case 'loeschen':
            if ($id !== null && programm_punkt_loeschen($id, $ich)) {
                flash_setzen('ok', 'Gelöscht – auf der Startseite steht der Punkt, bis du veröffentlichst.');
            } else {
                flash_setzen('fehler', 'Nicht gelöscht: Der Punkt hat Termine mit Anmeldungen.');
            }
            umleiten('/team/programm');
        case 'verschieben':
            $art = (string)($_POST['art'] ?? '') === 'termin' ? 'termin' : 'punkt';
            $neu = programm_verschieben($art, (int)($_POST['id'] ?? 0), (string)($_POST['beginn'] ?? ''), $ich, !empty($_POST['nachruecken']));
            $nr = (int)($GLOBALS['programm_nachgerueckt'] ?? 0);
            $pid = $art === 'termin' ? (int)db_wert('SELECT punkt_id FROM workshop_termin WHERE id = ?', [(int)($_POST['id'] ?? 0)]) : (int)($_POST['id'] ?? 0);
            // In einen Block gezogen bzw. aus ihm heraus (Rückmeldung 2026-10-30): nur Workshops und Stationen
            if ($neu !== null && ($_POST['block'] ?? '') !== '' && in_array(db_wert('SELECT typ FROM programmpunkt WHERE id = ?', [$pid]), ['workshop', 'station'], true)) {
                programm_zuordnen($pid, (int)$_POST['block'] ?: null, $ich);
            }
            flash_setzen($neu === null ? 'fehler' : 'hinweis', $neu === null ? 'Verschieben ging nicht.' : "Neue Uhrzeit vorgeschlagen: $neu – bitte prüfen." . ($nr > 0 ? ' ' . ($nr === 1 ? 'Ein Punkt rückt' : "$nr Punkte rücken") . ' dahinter nach hinten.' : '') . ' Gespeichert, noch nicht veröffentlicht.');
            umleiten('/team/programm' . ($pid > 0 ? '?id=' . $pid : ''));
        case 'zuordnen':
            $ok = programm_zuordnen((int)($_POST['id'] ?? 0), (int)($_POST['block'] ?? 0) ?: null, $ich);
            flash_setzen($ok ? 'ok' : 'fehler', $ok ? 'Zugeordnet – gespeichert, noch nicht veröffentlicht. Liegt ein Termin außerhalb der Blockzeit, steht unten ein Hinweis.' : 'Zuordnen ging nicht (nur Workshops und Stationen, nur zu einem Workshop-Block).');
            umleiten('/team/programm?id=' . (int)($_POST['id'] ?? 0));
        case 'veroeffentlichen':
            $pubFehler = programm_veroeffentlichen($ich);
            if ($pubFehler === []) {
                flash_setzen('ok', 'Veröffentlicht. Die Startseite zeigt jetzt das neue Programm.');
                umleiten('/team/programm');
            }
            break;
        case 'verwerfen':
            programm_verwerfen($ich);
            flash_setzen('ok', 'Verworfen. Der Arbeitsstand entspricht wieder dem veröffentlichten Programm.');
            umleiten('/team/programm');
        default:
            $fehler['typ'] = 'Unbekannte Aktion.';
    }
}

$arbeit = programm_arbeitsstand();
$pub = programm_veroeffentlicht();
$st = programm_status($arbeit, $pub);
$nachId = [];
foreach ($arbeit as $p) {
    $nachId[$p['id']] = $p;
}
$gliederung = programm_gliederung($arbeit);
$hinweise = programm_hinweise($arbeit);

// Formular: bestehender Punkt (?id), neuer Punkt (?neu) oder keiner
$bearbeiten = null;
$neuTyp = null;
if ($eingabe !== null) {
    $bearbeiten = ($id ?? null) !== null ? ($nachId[$id] ?? null) : null;
    $neuTyp = $bearbeiten === null ? (string)($eingabe['typ'] ?? 'vortrag') : null;
} elseif (isset($_GET['id'])) {
    $bearbeiten = $nachId[(int)$_GET['id']] ?? null;
} elseif (isset($_GET['neu'])) {
    $neuTyp = isset(PROGRAMM_TYPEN[$_GET['neu']]) ? (string)$_GET['neu'] : 'vortrag';
}
$zeigeForm = $bearbeiten !== null || $neuTyp !== null;
$v = function (string $k, string $vorgabe = '') use ($eingabe, $bearbeiten): string {
    if ($eingabe !== null) {
        return is_string($eingabe[$k] ?? null) ? $eingabe[$k] : $vorgabe;
    }
    return $bearbeiten !== null ? (string)($bearbeiten[$k] ?? $vorgabe) : $vorgabe;
};
$typ = $v('typ', $neuTyp ?? 'vortrag');
$termineForm = [];
if ($eingabe !== null) {
    foreach ((array)($eingabe['t_beginn'] ?? []) as $i => $b) {
        $termineForm[] = ['id' => (int)(($eingabe['t_id'] ?? [])[$i] ?? 0), 'beginn' => (string)$b, 'ende' => (string)(($eingabe['t_ende'] ?? [])[$i] ?? ''), 'plaetze' => (string)(($eingabe['t_plaetze'] ?? [])[$i] ?? '')];
    }
    $termineForm = array_values(array_filter($termineForm, fn($t) => $t['beginn'] !== '' || $t['ende'] !== '' || $t['plaetze'] !== ''));
} elseif ($bearbeiten !== null) {
    $termineForm = $bearbeiten['termine'];
}
$gewaehlteDoz = $eingabe !== null ? array_map('intval', (array)($eingabe['dozenten'] ?? [])) : array_column($bearbeiten['dozenten'] ?? [], 'id');
$dozenten = dozent_liste();
$vorschlaege = [];
foreach (PROGRAMM_TYPEN as $t => $_) {
    $vorschlaege[$t] = kuerzel_vorschlag($t);
}
// Ein Label für alles noch nicht Veröffentlichte (Rückmeldung 2026-10-30: gespeichert ist immer alles)
$statusText = ['veroeffentlicht' => ['pub', '✓ veröffentlicht'], 'geaendert' => ['ges', 'nicht veröffentlicht'], 'neu' => ['ges', 'nicht veröffentlicht']];
$fehlerFeld = fn(string $k) => isset($fehler[$k]) ? '<p class="feldfehler" id="f-' . $k . '">' . e($fehler[$k]) . '</p>' : '';
$ungueltig = fn(string $k) => isset($fehler[$k]) ? ' aria-invalid="true" aria-describedby="f-' . $k . '"' : '';
$fuer = fn(array $typen) => ' data-fuer="' . implode(' ', $typen) . '"';

team_seite_kopf($konto, 'Programm', '/team/programm', ['skripte' => ['programm-team.js']]);

if ($pubFehler !== []): ?>
<div class="meldung fehler" role="alert"><strong>Nicht veröffentlicht.</strong> Zuerst ergänzen:<ul><?php foreach ($pubFehler as $pf): ?><li><?= e($pf) ?></li><?php endforeach; ?></ul></div>
<?php endif;
if ($st['offen']):
    $geaendert = array_filter($arbeit, fn($p) => $st['status'][$p['id']] !== 'veroeffentlicht');
    $namen = array_map(fn($p) => trim($p['kuerzel'] . ' ' . $p['titel']), $geaendert);
    $weg = array_map(fn($p) => trim($p['kuerzel'] . ' ' . $p['titel']) . ' (gelöscht)', $st['geloescht']); ?>
<div class="entwurf-banner" role="status">
    <span class="eb-text"><strong>Gespeichert, aber nicht veröffentlicht.</strong> <?= count($namen) + count($weg) ?> <?= count($namen) + count($weg) === 1 ? 'Programmpunkt ist' : 'Programmpunkte sind' ?> geändert und noch nicht auf der Startseite: <?= e(implode(', ', array_merge($namen, $weg))) ?>.</span>
    <span class="eb-knoepfe">
        <a class="knopf zweit" href="/team/programm/vorschau">Vorschau</a>
        <?php if ($pub !== null): ?>
        <details class="bestaetigen" data-titel="Änderungen verwerfen?"><summary class="knopf zweit">Verwerfen</summary>
            <form method="post" action="/team/programm"><?= csrf_feld() ?><input type="hidden" name="aktion" value="verwerfen"><p>Alle Änderungen seit dem letzten Veröffentlichen gehen verloren.</p><button type="submit" class="knopf gefahr">Ja, verwerfen</button></form>
        </details>
        <?php endif; ?>
        <form method="post" action="/team/programm"><?= csrf_feld() ?><input type="hidden" name="aktion" value="veroeffentlichen"><button type="submit" class="knopf gruen">Jetzt veröffentlichen</button></form>
    </span>
</div>
<?php elseif ($pub !== null): ?>
<p class="meta klein">Alles veröffentlicht (zuletzt <?= e(datum_zeit_text($pub['am'])) ?>).</p>
<?php endif; ?>

<div class="filterzeile">
    <a class="knopf" href="/team/programm?neu=vortrag">+ Programmpunkt</a>
    <a class="knopf zweit" href="/team/dozierende">Dozierende verwalten</a>
</div>

<div class="pg-raster">
    <div class="kasten pg-plan">
        <p class="unterkopf"><?= e(tag_text(va('veranstaltung_datum'))) ?> · Tagesplan</p>
        <p class="meta klein">Sortiert nach Uhrzeit. Am Griff ⋮⋮ zwischen zwei Punkte ziehen – die weiße Linie zeigt, wo: Der Punkt beginnt, wo der darüber endet, nachfolgende Punkte rücken bis zur nächsten Lücke nach hinten. Workshops und Stationen auf die Mitte eines Workshop-Blocks ziehen ordnet sie ihm zu (grün umrandet); sonst hängen sie unter dem Block, in dessen Zeit sie liegen. Punkte ohne Uhrzeit stehen oben hell-lila.</p>
        <?php if ($gliederung === []): ?><p class="hinweis-leer">Noch keine Programmpunkte. Lege mit „+ Programmpunkt“ den ersten an.</p><?php endif; ?>
        <ol class="pg-liste" id="pg-liste">
        <?php
        $zeile = function (array $p, string $art, int $zid, string $von, string $bis, string $inhalt) use ($st, $bearbeiten): string {
            $s = $st['status'][$p['id']] ?? 'veroeffentlicht';
            $klasse = 'pg-zeile' . ($p['typ'] === 'pause' ? ' pause' : '') . ($p['typ'] === 'block' ? ' block' : '')
                . ($von === '' ? ' pg-neu-punkt' : '') . ($s === 'geaendert' ? ' geaendert' : '') . ($s === 'neu' && $von !== '' ? ' neu' : '')
                . (($bearbeiten['id'] ?? 0) === $p['id'] ? ' aktiv' : '');
            return '<div class="' . $klasse . '" data-art="' . $art . '" data-id="' . $zid . '" data-punkt="' . (int)$p['id'] . '" data-typ="' . e($p['typ']) . '" data-beginn="' . e($von) . '" data-ende="' . e($bis) . '">'
                . '<a class="pg-link" href="/team/programm?id=' . $p['id'] . '"><span class="mono">' . ($von !== '' ? e($von . '–' . $bis) : '--:--') . '</span><span>' . $inhalt
                . ($s !== 'veroeffentlicht' ? ' <span class="st ges">nicht veröffentlicht</span>' : '') . '</span></a>'
                . (($p['typ'] !== 'workshop' || $art === 'termin' || $p['termine'] === []) ? '<span class="griff" draggable="true" title="Ziehen zum Verschieben" aria-hidden="true">⋮⋮</span>' : '')
                . '</div>';
        };
        $chip = function (array $p): string {
            $t = PROGRAMM_TYPEN[$p['typ']];
            if ($p['typ'] === 'station') {
                return '<span class="format offen">OFFEN</span> ';
            }
            return $p['kuerzel'] !== '' ? '<span class="format' . ($t['farbe'] === 'rot' ? ' rot' : '') . '">' . e($p['kuerzel']) . '</span> ' : '';
        };
        foreach ($gliederung as $g):
            $p = $g['punkt'];
            if ($g['art'] === 'block'): ?>
            <li class="pg-block"><?= $zeile($p, 'punkt', $p['id'], $p['beginn'], $p['ende'], '<strong>' . e($p['titel']) . '</strong> <span class="meta klein">Workshop-Block · ' . count($g['kinder']) . ' Termine</span>') ?>
                <ol class="pg-kinder">
                <?php foreach ($g['kinder'] as $k):
                    $kp = $k['punkt'];
                    if ($k['art'] === 'termin'): ?>
                    <li><?= $zeile($kp, 'termin', $k['termin']['id'], $k['termin']['beginn'], $k['termin']['ende'], $chip($kp) . e($kp['titel']) . ' <span class="meta klein">' . (int)$k['termin']['plaetze'] . ' Plätze</span>') ?></li>
                    <?php else: ?>
                    <li><?= $zeile($kp, 'punkt', $kp['id'], $kp['beginn'], $kp['ende'], $chip($kp) . e($kp['titel'])) ?></li>
                    <?php endif;
                endforeach; ?>
                </ol>
            </li>
            <?php elseif ($g['art'] === 'termin'): ?>
            <li><?= $zeile($p, 'termin', $g['termin']['id'], $g['termin']['beginn'], $g['termin']['ende'], $chip($p) . e($p['titel']) . ' <span class="meta klein">' . (int)$g['termin']['plaetze'] . ' Plätze</span>') ?></li>
            <?php else: ?>
            <li><?= $zeile($p, 'punkt', $p['id'], $p['beginn'], $p['ende'], $chip($p) . e($p['titel']) . ($p['typ'] === 'workshop' ? ' <span class="meta klein">noch ohne Termin</span>' : '')) ?></li>
            <?php endif;
        endforeach; ?>
        <?php if ($gliederung !== []): ?><li class="pg-ende" id="pg-ende" hidden>Hierher ziehen: ans Ende</li><?php endif; ?>
        </ol>
        <form method="post" action="/team/programm" id="verschieben-form" hidden>
            <?= csrf_feld() ?><input type="hidden" name="aktion" value="verschieben"><input type="hidden" name="art" value=""><input type="hidden" name="id" value=""><input type="hidden" name="beginn" value=""><input type="hidden" name="nachruecken" value="1"><input type="hidden" name="block" value="">
        </form>
        <form method="post" action="/team/programm" id="zuordnen-form" hidden>
            <?= csrf_feld() ?><input type="hidden" name="aktion" value="zuordnen"><input type="hidden" name="id" value=""><input type="hidden" name="block" value="">
        </form>
        <?php foreach ($hinweise as $h): ?><p class="pruef"><span class="chip offen">Hinweis</span> <?= e($h) ?></p><?php endforeach; ?>
    </div>

    <div class="kasten pg-form">
    <?php if (!$zeigeForm): ?>
        <p class="hinweis-leer">Wähle links einen Programmpunkt zum Bearbeiten oder lege mit „+ Programmpunkt“ einen neuen an.</p>
    <?php else:
        $s = $bearbeiten !== null ? ($st['status'][$bearbeiten['id']] ?? 'veroeffentlicht') : 'neu'; ?>
        <div class="stat-kopf"><h2 class="zwischen"><?= $bearbeiten !== null ? e(trim($bearbeiten['kuerzel'] . ' ' . $bearbeiten['titel'])) . ' bearbeiten' : 'Neuer Programmpunkt' ?></h2><span class="st <?= $statusText[$s][0] ?>"><?= $statusText[$s][1] ?></span></div>
        <?php if ($fehler !== []): ?><div class="meldung fehler" role="alert">Nicht gespeichert. Bitte die markierten Felder prüfen.</div><?php endif; ?>
        <form method="post" action="/team/programm" class="pg-formular" id="pg-formular" data-vorschlaege="<?= e(json_encode($vorschlaege, JSON_UNESCAPED_UNICODE)) ?>" novalidate>
            <?= csrf_feld() ?>
            <input type="hidden" name="aktion" value="speichern">
            <?php if ($bearbeiten !== null): ?><input type="hidden" name="id" value="<?= $bearbeiten['id'] ?>"><?php endif; ?>
            <div class="paar">
                <div class="feld"><label for="pg-typ">Typ</label>
                    <select id="pg-typ" name="typ"<?= $ungueltig('typ') ?>>
                        <optgroup label="Inhalte">
                        <?php foreach (['vortrag' => 'Vortrag (VT)', 'fall' => 'Fallbesprechung (FB)', 'workshop' => 'Workshop (WS, mit Terminen und Plätzen)', 'station' => 'Offene Station (ohne Anmeldung)'] as $tw => $tt): ?><option value="<?= $tw ?>"<?= $typ === $tw ? ' selected' : '' ?>><?= e($tt) ?></option><?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Rahmen">
                        <?php foreach (['block' => 'Workshop-Block (Zeitfenster)', 'pause' => 'Pause / Info'] as $tw => $tt): ?><option value="<?= $tw ?>"<?= $typ === $tw ? ' selected' : '' ?>><?= e($tt) ?></option><?php endforeach; ?>
                        </optgroup>
                    </select>
                    <p class="hilfe">Ein Workshop ist der Inhalt mit eigenen Terminen. Ein Workshop-Block ist nur ein Zeitfenster – er sammelt alle Termine und Stationen, die in seiner Zeit liegen.</p><?= $fehlerFeld('typ') ?>
                </div>
                <div class="feld"<?= $fuer(['vortrag', 'fall', 'workshop']) ?>><label for="pg-kuerzel">Kürzel</label>
                    <input id="pg-kuerzel" name="kuerzel" type="text" maxlength="12" value="<?= e($v('kuerzel', $bearbeiten === null ? $vorschlaege[$typ] : '')) ?>"<?= $ungueltig('kuerzel') ?>><p class="hilfe">Vorschlag automatisch, änderbar</p><?= $fehlerFeld('kuerzel') ?>
                </div>
            </div>
            <?php $bloeckeWahl = array_filter($arbeit, fn($b) => $b['typ'] === 'block');
            $blockJetzt = (string)($eingabe['block_id'] ?? ($bearbeiten['block_id'] ?? '')); ?>
            <div class="feld"<?= $fuer(['workshop', 'station']) ?>><label for="pg-block">Gehört zu Workshop-Block</label>
                <select id="pg-block" name="block_id"><option value="">automatisch (nach Uhrzeit)</option><?php foreach ($bloeckeWahl as $b): ?><option value="<?= $b['id'] ?>"<?= $blockJetzt === (string)$b['id'] ? ' selected' : '' ?>><?= e($b['titel'] . ($b['beginn'] !== '' ? ' · ' . $b['beginn'] . '–' . $b['ende'] : '')) ?></option><?php endforeach; ?></select>
                <p class="hilfe">Auch im Plan: den Punkt auf die Mitte eines Blocks ziehen.</p></div>
            <div class="feld"><label for="pg-titel">Titel</label><input id="pg-titel" name="titel" type="text" maxlength="160" required value="<?= e($v('titel')) ?>"<?= $ungueltig('titel') ?>><?= $fehlerFeld('titel') ?></div>
            <div class="feld"<?= $fuer(['vortrag', 'fall', 'workshop']) ?>><label for="pg-untertitel">Untertitel</label><input id="pg-untertitel" name="untertitel" type="text" maxlength="200" value="<?= e($v('untertitel')) ?>"<?= $ungueltig('untertitel') ?>><?= $fehlerFeld('untertitel') ?></div>
            <div class="feld"<?= $fuer(['vortrag', 'fall', 'workshop', 'station', 'pause']) ?>><label for="pg-beschreibung">Beschreibung</label><textarea id="pg-beschreibung" name="beschreibung" rows="4"<?= $ungueltig('beschreibung') ?>><?= e($v('beschreibung')) ?></textarea><p class="hilfe">Erscheint im Popup. Leerzeile = neuer Absatz.</p><?= $fehlerFeld('beschreibung') ?></div>
            <div class="paar"<?= $fuer(['vortrag', 'fall', 'station', 'block', 'pause']) ?>>
                <div class="feld"><label for="pg-beginn">Beginn</label><input id="pg-beginn" name="beginn" type="time" step="60" value="<?= e($v('beginn')) ?>"<?= $ungueltig('beginn') ?>><?= $fehlerFeld('beginn') ?></div>
                <div class="feld"><label for="pg-ende">Ende</label><input id="pg-ende" name="ende" type="time" step="60" value="<?= e($v('ende')) ?>"<?= $ungueltig('ende') ?>><?= $fehlerFeld('ende') ?></div>
            </div>
            <div class="paar">
                <div class="feld"<?= $fuer(['vortrag', 'fall', 'workshop', 'station', 'pause']) ?>><label for="pg-ort">Ort</label><input id="pg-ort" name="ort" type="text" maxlength="120" value="<?= e($v('ort')) ?>"<?= $ungueltig('ort') ?>><?= $fehlerFeld('ort') ?></div>
                <div class="feld"<?= $fuer(['workshop']) ?>><label for="pg-bring">Bring (optional)</label><input id="pg-bring" name="bring" type="text" maxlength="160" value="<?= e($v('bring')) ?>"<?= $ungueltig('bring') ?>><?= $fehlerFeld('bring') ?></div>
            </div>
            <fieldset class="feld"<?= $fuer(['vortrag', 'fall', 'workshop', 'station']) ?>><legend>Leitung / Dozierende (optional)</legend>
                <?php if ($dozenten === []): ?><p class="hilfe">Noch keine Dozierenden – unter <a href="/team/dozierende">Dozierende verwalten</a> anlegen.</p>
                <?php else: ?><div class="tags"><?php foreach ($dozenten as $d): ?><label class="tag-wahl"><input type="checkbox" name="dozenten[]" value="<?= (int)$d['id'] ?>"<?= in_array((int)$d['id'], $gewaehlteDoz, true) ? ' checked' : '' ?>><span><?= e($d['name']) ?></span></label><?php endforeach; ?></div>
                <p class="hilfe">Aus der Liste der Dozierenden – Name, Funktion, Kurzbio und Foto pflegst du dort einmal.</p><?php endif; ?>
            </fieldset>
            <div class="feld"<?= $fuer(['workshop']) ?>><label for="pg-notiz">Notiz vom Team (optional, erscheint als Zettel)</label><input id="pg-notiz" name="notiz" type="text" maxlength="300" value="<?= e($v('notiz')) ?>"<?= $ungueltig('notiz') ?>><?= $fehlerFeld('notiz') ?></div>
            <div class="feld"<?= $fuer(['workshop']) ?>>
                <p class="unterkopf">Termine</p>
                <table class="pg-termine">
                    <thead><tr><th>Beginn</th><th>Ende</th><th>Plätze</th><th>Belegt</th><th></th></tr></thead>
                    <tbody id="pg-termine">
                    <?php foreach (array_merge($termineForm, [['id' => 0, 'beginn' => '', 'ende' => '', 'plaetze' => '']]) as $i => $t): $leer = (int)$t['id'] === 0 && $t['beginn'] === ''; ?>
                        <tr<?= $leer ? ' class="vorlage"' : '' ?>>
                            <td data-titel="Beginn"><input type="hidden" name="t_id[]" value="<?= (int)$t['id'] ?>"><input type="time" step="60" name="t_beginn[]" value="<?= e((string)$t['beginn']) ?>" aria-label="Beginn"></td>
                            <td data-titel="Ende"><input type="time" step="60" name="t_ende[]" value="<?= e((string)$t['ende']) ?>" aria-label="Ende"></td>
                            <td data-titel="Plätze"><input type="number" min="1" max="<?= PROGRAMM_MAX_PLAETZE ?>" name="t_plaetze[]" value="<?= e((string)$t['plaetze']) ?>" aria-label="Plätze"></td>
                            <td class="n" data-titel="Belegt"><?= (int)$t['id'] > 0 ? termin_belegt((int)$t['id']) : '–' ?></td>
                            <td class="t-aktion"><button type="button" class="mini" data-duplizieren>Duplizieren</button><?php if ((int)$t['id'] > 0): ?><label class="mini-haken"><input type="checkbox" name="t_weg[<?= $i ?>]" value="1"> entfernen</label><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?= $fehlerFeld('termine') ?>
                <p class="hilfe">Die leere Zeile unten ist für einen neuen Termin. „Duplizieren“ legt den nächsten Termin direkt im Anschluss an, mit gleicher Dauer und gleichen Plätzen.</p>
                <button type="button" class="mini" id="pg-termin-neu">+ Termin</button>
            </div>
            <div class="aktionen">
                <button type="submit" class="knopf">Speichern</button>
                <a class="knopf zweit" href="/team/programm">Abbrechen</a>
                <?php if ($bearbeiten !== null): ?><button type="button" class="knopf gefahr rechts" id="pg-loeschen" hidden>Programmpunkt löschen</button><?php endif; ?>
            </div>
        </form>
        <?php if ($bearbeiten !== null): ?>
        <details class="bestaetigen gefahrzone" data-ausloeser="pg-loeschen"><summary class="knopf gefahr">Programmpunkt löschen</summary>
            <form method="post" action="/team/programm"><?= csrf_feld() ?><input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="id" value="<?= $bearbeiten['id'] ?>">
                <p>„<?= e(trim($bearbeiten['kuerzel'] . ' ' . $bearbeiten['titel'])) ?>“ wird gelöscht. Auf der Startseite bleibt er, bis du veröffentlichst.</p>
                <button type="submit" class="knopf gefahr">Ja, löschen</button>
            </form>
        </details>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</div>
<?php
team_seite_fuss();
