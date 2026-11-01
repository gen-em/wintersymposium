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
team_schreibschutz('/team/programm'); // abgeschlossene Veranstaltung: nur lesen (E-58)
$ich = (int)$konto['id'];
$fehler = [];
$eingabe = null;
$pubFehler = [];

if (ist_post()) {
    $aktion = aktion();
    $id = post_zahl('id') ?: null;
    switch ($aktion) {
        case 'speichern':
            if ($id !== null && !programm_punkt_im_kontext($id)) {
                seite_meldung(404, 'Nicht gefunden', 'Diesen Programmpunkt gibt es nicht mehr.');
            }
            $r = programm_punkt_pruefen($_POST, $id);
            if ($r['fehler'] === []) {
                $id = programm_punkt_speichern($id, $r, $ich);
                fertig('ok', 'Gespeichert – noch nicht veröffentlicht. Die Startseite ändert sich erst mit „Jetzt veröffentlichen“.', '/team/programm?id=' . $id);
            }
            $fehler = $r['fehler'];
            $eingabe = $_POST;
            break;
        case 'loeschen':
            $ok = $id !== null && programm_punkt_loeschen($id, $ich);
            fertig($ok ? 'ok' : 'fehler', $ok ? 'Gelöscht – auf der Startseite steht der Punkt, bis du veröffentlichst.' : 'Nicht gelöscht: Der Punkt hat Termine mit Anmeldungen.', '/team/programm');
        case 'verschieben':
            $art = post_text('art') === 'termin' ? 'termin' : 'punkt';
            $pid = $art === 'termin' ? programm_punkt_von_termin(post_zahl('id')) : post_zahl('id');
            // In einen Block gezogen bzw. aus ihm heraus (Rückmeldung 2026-10-30): zuerst zuordnen,
            // damit ein Punkt im Block die Hauptspur nicht nach hinten schiebt
            if (post_text('block') !== '' && in_array(programm_typ($pid), PROGRAMM_IM_BLOCK, true)) {
                programm_zuordnen($pid, post_zahl('block') ?: null, $ich);
            }
            $neu = programm_verschieben($art, post_zahl('id'), post_text('beginn'), $ich, post_haken('nachruecken'), $nr);
            fertig($neu === null ? 'fehler' : 'hinweis', $neu === null ? 'Verschieben ging nicht.' : "Neue Uhrzeit vorgeschlagen: $neu – bitte prüfen." . ($nr > 0 ? ' ' . ($nr === 1 ? 'Ein Punkt rückt' : "$nr Punkte rücken") . ' dahinter nach hinten.' : '') . ' Gespeichert, noch nicht veröffentlicht.',
                '/team/programm' . ($pid > 0 ? '?id=' . $pid : ''));
        case 'zuordnen':
            $ok = programm_zuordnen(post_zahl('id'), post_zahl('block') ?: null, $ich);
            fertig($ok ? 'ok' : 'fehler', $ok ? 'Zugeordnet – gespeichert, noch nicht veröffentlicht. Liegt ein Termin außerhalb der Blockzeit, steht unten ein Hinweis.' : 'Zuordnen ging nicht (nur Workshops und Stationen, nur zu einem Workshop-Block).', '/team/programm?id=' . post_zahl('id'));
        case 'veroeffentlichen':
            $pubFehler = programm_veroeffentlichen($ich);
            if ($pubFehler === []) {
                fertig('ok', 'Veröffentlicht. Die Startseite zeigt jetzt das neue Programm.', '/team/programm');
            }
            break;
        case 'verwerfen':
            programm_verwerfen($ich);
            fertig('ok', 'Verworfen. Der Arbeitsstand entspricht wieder dem veröffentlichten Programm.', '/team/programm');
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
$w = fn(string $k) => wert($eingabe ?? [], $bearbeiten ?? [], $k);
$typ = $w('typ') ?: ($neuTyp ?? 'vortrag');
$termineForm = [];
if ($eingabe !== null) {
    foreach ((array)($eingabe['t_beginn'] ?? []) as $i => $b) {
        $schl = (string)(($eingabe['t_key'] ?? [])[$i] ?? '');
        $termineForm[] = ['id' => (int)(($eingabe['t_id'] ?? [])[$i] ?? 0), 'beginn' => (string)$b, 'ende' => (string)(($eingabe['t_ende'] ?? [])[$i] ?? ''), 'plaetze' => (string)(($eingabe['t_plaetze'] ?? [])[$i] ?? ''),
            'doz' => array_map('intval', (array)(($eingabe['t_doz'] ?? [])[$schl] ?? []))];
    }
    $termineForm = array_values(array_filter($termineForm, fn($t) => $t['beginn'] !== '' || $t['ende'] !== '' || $t['plaetze'] !== ''));
} elseif ($bearbeiten !== null) {
    $termineForm = array_map(fn($t) => $t + ['doz' => array_column($t['dozenten'] ?? [], 'id')], $bearbeiten['termine']);
}
$gewaehlteDoz = $eingabe !== null ? array_map('intval', (array)($eingabe['dozenten'] ?? [])) : array_column($bearbeiten['dozenten'] ?? [], 'id');
$dozenten = dozent_liste();
$vorschlaege = [];
foreach (PROGRAMM_TYPEN as $t => $_) {
    $vorschlaege[$t] = kuerzel_vorschlag($t);
}
// Ein Label für alles noch nicht Veröffentlichte (Rückmeldung 2026-10-30: gespeichert ist immer alles)
$statusText = ['veroeffentlicht' => ['pub', '✓ veröffentlicht'], 'geaendert' => ['ges', 'nicht veröffentlicht'], 'neu' => ['ges', 'nicht veröffentlicht']];
$fuer = fn(array $typen) => ' data-fuer="' . implode(' ', $typen) . '"';
$feldOpt = fn(string $k, string $id, array $opt = []): array => $opt + ['id' => $id, 'wert' => $w($k), 'fehler' => $fehler[$k] ?? null];

team_seite_kopf($konto, 'Programm', '/team/programm', ['skripte' => ['programm-team.js']]);

echo meldung_html($pubFehler, 'fehler', ['text_html' => '<strong>Nicht veröffentlicht.</strong> Zuerst ergänzen:<ul>' . implode('', array_map(fn($pf) => '<li>' . e($pf) . '</li>', $pubFehler)) . '</ul>']);
if ($st['offen']):
    $geaendert = array_filter($arbeit, fn($p) => $st['status'][$p['id']] !== 'veroeffentlicht');
    $namen = array_map(fn($p) => trim($p['kuerzel'] . ' ' . $p['titel']), $geaendert);
    $weg = array_map(fn($p) => trim($p['kuerzel'] . ' ' . $p['titel']) . ' (gelöscht)', $st['geloescht']); ?>
<div class="entwurf-banner" role="status">
    <span><strong>Gespeichert, aber nicht veröffentlicht.</strong> <?= count($namen) + count($weg) ?> <?= count($namen) + count($weg) === 1 ? 'Programmpunkt ist' : 'Programmpunkte sind' ?> geändert und noch nicht auf der Startseite: <?= e(implode(', ', array_merge($namen, $weg))) ?>.</span>
    <span class="eb-knoepfe">
        <a class="knopf zweit" href="/team/programm/vorschau">Vorschau</a>
        <?php if ($pub !== null): ?>
        <?= bestaetigung_html('Verwerfen', 'Alle Änderungen seit dem letzten Veröffentlichen gehen verloren.', 'Ja, verwerfen', ['aktion' => 'verwerfen'],
            ['ziel' => '/team/programm', 'titel' => 'Änderungen verwerfen?', 'knopfklasse' => 'knopf zweit']) ?>
        <?php endif; ?>
        <?= aktion_knopf('/team/programm', 'veroeffentlichen', [], 'Jetzt veröffentlichen', 'knopf gruen') ?>
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
        <p class="meta klein">Sortiert nach Uhrzeit. Am Griff ⋮⋮ zwischen zwei Punkte ziehen – die weiße Linie zeigt, wo: Der Punkt beginnt, wo der darüber endet, nachfolgende Punkte rücken bis zur nächsten Lücke nach hinten. Workshops, Stationen, Vorträge und Fallbesprechungen auf die Mitte eines Workshop-Blocks ziehen ordnet sie ihm zu (grün umrandet), ebenso Ziehen zwischen zwei Punkte im Block; Workshops und Stationen ohne Zuordnung hängen unter dem Block, in dessen Zeit sie liegen. Punkte ohne Uhrzeit stehen oben hell-lila.</p>
        <?= $gliederung === [] ? leer_hinweis('Noch keine Programmpunkte. Lege mit „+ Programmpunkt“ den ersten an.') : '' ?>
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
                . (($p['typ'] !== 'workshop' || $art === 'termin' || $p['termine'] === []) ? '<span class="griff" title="Ziehen zum Verschieben" aria-hidden="true">⋮⋮</span>' : '')
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
            <li><?= $zeile($p, 'punkt', $p['id'], $p['beginn'], $p['ende'], '<strong>' . e($p['titel']) . '</strong> <span class="meta klein">Workshop-Block · ' . count($g['kinder']) . ' Einträge</span>') ?>
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
        </ol>
        <form method="post" action="/team/programm" id="verschieben-form" hidden>
            <?= csrf_feld() . versteckt_html(['aktion' => 'verschieben', 'art' => '', 'id' => '', 'beginn' => '', 'nachruecken' => '1', 'block' => '']) ?>
        </form>
        <form method="post" action="/team/programm" id="zuordnen-form" hidden>
            <?= csrf_feld() . versteckt_html(['aktion' => 'zuordnen', 'id' => '', 'block' => '']) ?>
        </form>
        <?php foreach ($hinweise as $h): ?><p class="pruef"><?= chip('offen', 'Hinweis') ?> <?= e($h) ?></p><?php endforeach; ?>
    </div>

    <div class="kasten pg-form">
    <?php if (!$zeigeForm): ?>
        <?= leer_hinweis('Wähle links einen Programmpunkt zum Bearbeiten oder lege mit „+ Programmpunkt“ einen neuen an.') ?>
    <?php else:
        $s = $bearbeiten !== null ? ($st['status'][$bearbeiten['id']] ?? 'veroeffentlicht') : 'neu'; ?>
        <div class="stat-kopf"><h2 class="zwischen"><?= $bearbeiten !== null ? e(trim($bearbeiten['kuerzel'] . ' ' . $bearbeiten['titel'])) . ' bearbeiten' : 'Neuer Programmpunkt' ?></h2><span class="st <?= $statusText[$s][0] ?>"><?= $statusText[$s][1] ?></span></div>
        <?= $fehler !== [] ? meldung_html(NICHT_GESPEICHERT_TEXT, 'fehler') : '' ?>
        <form method="post" action="/team/programm" class="pg-formular" id="pg-formular" data-vorschlaege="<?= e(json_encode($vorschlaege, JSON_UNESCAPED_UNICODE)) ?>" novalidate>
            <?= csrf_feld() . versteckt_html(['aktion' => 'speichern', 'id' => $bearbeiten['id'] ?? null]) ?>
            <div class="paar">
                <?php // von Hand: Auswahl mit Gruppen (optgroup) kann feld() nicht ?>
                <div class="feld"><label for="pg-typ">Typ</label>
                    <select id="pg-typ" name="typ"<?= isset($fehler['typ']) ? ' aria-invalid="true" aria-describedby="f-typ"' : '' ?>>
                        <optgroup label="Inhalte">
                        <?php foreach (['vortrag' => 'Vortrag (VT)', 'fall' => 'Fallbesprechung (FB)', 'workshop' => 'Workshop (WS, mit Terminen und Plätzen)', 'station' => 'Offene Station (ohne Anmeldung)'] as $tw => $tt): ?><option value="<?= $tw ?>"<?= $typ === $tw ? ' selected' : '' ?>><?= e($tt) ?></option><?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Rahmen">
                        <?php foreach (['block' => 'Workshop-Block (Zeitfenster)', 'pause' => 'Pause / Info'] as $tw => $tt): ?><option value="<?= $tw ?>"<?= $typ === $tw ? ' selected' : '' ?>><?= e($tt) ?></option><?php endforeach; ?>
                        </optgroup>
                    </select>
                    <p class="hilfe">Ein Workshop ist der Inhalt mit eigenen Terminen. Ein Workshop-Block ist nur ein Zeitfenster – er sammelt alle Termine und Stationen, die in seiner Zeit liegen.</p><?= isset($fehler['typ']) ? '<p class="feldfehler" id="f-typ">' . e($fehler['typ']) . '</p>' : '' ?>
                </div>
                <?= feld('kuerzel', 'Kürzel', ['rahmen_attr' => ['data-fuer' => implode(' ', ['vortrag', 'fall', 'workshop'])]] + $feldOpt('kuerzel', 'pg-kuerzel', [
                    'wert' => $eingabe === null && $bearbeiten === null ? $vorschlaege[$typ] : $w('kuerzel'), 'hilfe' => 'Vorschlag automatisch, änderbar', 'attr' => ['maxlength' => 12]])) ?>
            </div>
            <?php $bloeckeWahl = array_filter($arbeit, fn($b) => $b['typ'] === 'block');
            $blockJetzt = (string)($eingabe['block_id'] ?? ($bearbeiten['block_id'] ?? '')); ?>
            <?= feld('block_id', 'Gehört zu Workshop-Block', ['rahmen_attr' => ['data-fuer' => implode(' ', PROGRAMM_IM_BLOCK)]] + ['typ' => 'auswahl', 'id' => 'pg-block', 'wert' => $blockJetzt,
                'leer' => in_array($typ, ['vortrag', 'fall'], true) ? 'keinem (Hauptprogramm)' : 'automatisch (nach Uhrzeit)',
                'optionen' => array_combine(array_map(fn($b) => (string)$b['id'], $bloeckeWahl), array_map(fn($b) => $b['titel'] . ($b['beginn'] !== '' ? ' · ' . $b['beginn'] . '–' . $b['ende'] : ''), $bloeckeWahl)),
                'hilfe' => 'Auch im Plan: den Punkt auf die Mitte eines Blocks ziehen.']) ?>
            <?= feld('titel', 'Titel', $feldOpt('titel', 'pg-titel', ['pflicht' => true, 'attr' => ['maxlength' => 160]])) ?>
            <?= feld('untertitel', 'Untertitel', ['rahmen_attr' => ['data-fuer' => implode(' ', ['vortrag', 'fall', 'workshop'])]] + $feldOpt('untertitel', 'pg-untertitel', ['attr' => ['maxlength' => 200]])) ?>
            <?= feld('beschreibung', 'Beschreibung', ['rahmen_attr' => ['data-fuer' => implode(' ', ['vortrag', 'fall', 'workshop', 'station', 'pause'])]] + $feldOpt('beschreibung', 'pg-beschreibung', ['typ' => 'textarea', 'hilfe' => 'Erscheint im Popup.',
                'attr' => ['rows' => 4] + editor_attr()])) ?>
            <div class="paar"<?= $fuer(['vortrag', 'fall', 'station', 'block', 'pause']) ?>>
                <?= feld('beginn', 'Beginn', $feldOpt('beginn', 'pg-beginn', ['typ' => 'time', 'attr' => ['step' => 60]])) ?>
                <?= feld('ende', 'Ende', $feldOpt('ende', 'pg-ende', ['typ' => 'time', 'attr' => ['step' => 60]])) ?>
            </div>
            <div class="paar">
                <?= feld('ort', 'Ort', ['rahmen_attr' => ['data-fuer' => implode(' ', ['vortrag', 'fall', 'workshop', 'station', 'pause'])]] + $feldOpt('ort', 'pg-ort', ['attr' => ['maxlength' => 120]])) ?>
                <?= feld('bring', 'Bring (optional)', ['rahmen_attr' => ['data-fuer' => implode(' ', ['workshop'])]] + $feldOpt('bring', 'pg-bring', ['attr' => ['maxlength' => 160]])) ?>
            </div>
            <fieldset class="feld"<?= $fuer(['vortrag', 'fall', 'workshop', 'station']) ?>><legend>Leitung / Dozierende (optional)</legend>
                <?php if ($dozenten === []): ?><p class="hilfe">Noch keine Dozierenden – unter <a href="/team/dozierende">Dozierende verwalten</a> anlegen.</p>
                <?php else: ?><div class="tags"><?php foreach ($dozenten as $d): ?><label class="tag-wahl"><input type="checkbox" name="dozenten[]" value="<?= (int)$d['id'] ?>"<?= in_array((int)$d['id'], $gewaehlteDoz, true) ? ' checked' : '' ?>><span><?= e($d['name']) ?></span></label><?php endforeach; ?></div>
                <p class="hilfe">Aus der Liste der Dozierenden – Name, Funktion, Kurzbio und Foto pflegst du dort einmal.</p><?php endif; ?>
            </fieldset>
            <?= feld('notiz', 'Notiz vom Team (optional, erscheint als Zettel)', ['rahmen_attr' => ['data-fuer' => implode(' ', ['workshop'])]] + $feldOpt('notiz', 'pg-notiz', ['attr' => ['maxlength' => 300]])) ?>
            <div class="feld"<?= $fuer(['workshop']) ?>>
                <p class="unterkopf">Termine</p>
                <table class="pg-termine">
                    <thead><tr><th>Beginn</th><th>Ende</th><th>Plätze</th><th>Dozierende</th><th>Belegt</th><th></th></tr></thead>
                    <tbody id="pg-termine">
                    <?php foreach (array_merge($termineForm, [['id' => 0, 'beginn' => '', 'ende' => '', 'plaetze' => '', 'doz' => []]]) as $i => $t): $leer = (int)$t['id'] === 0 && $t['beginn'] === '';
                        $schl = 'z' . $i; $tDoz = $t['doz'] ?? []; ?>
                        <tr<?= $leer ? ' class="vorlage"' : '' ?>>
                            <td data-titel="Beginn"><input type="hidden" name="t_id[]" value="<?= (int)$t['id'] ?>"><input type="hidden" name="t_key[]" value="<?= $schl ?>"><input type="time" step="60" name="t_beginn[]" value="<?= e((string)$t['beginn']) ?>" aria-label="Beginn"></td>
                            <td data-titel="Ende"><input type="time" step="60" name="t_ende[]" value="<?= e((string)$t['ende']) ?>" aria-label="Ende"></td>
                            <td data-titel="Plätze"><input type="number" min="1" max="<?= PROGRAMM_MAX_PLAETZE ?>" name="t_plaetze[]" value="<?= e((string)$t['plaetze']) ?>" aria-label="Plätze"></td>
                            <td data-titel="Dozierende"><?php if ($dozenten === []): ?><span class="meta klein">wie Workshop</span><?php else: ?>
                                <details class="t-doz"><summary><?= $tDoz === [] ? 'wie Workshop' : e(implode(', ', array_column(array_filter($dozenten, fn($d) => in_array((int)$d['id'], $tDoz, true)), 'name'))) ?></summary>
                                    <div class="t-doz-wahl"><p class="hilfe">Nur wenn dieser Termin abweicht (z. B. Pause einer Dozentin). Nichts gewählt = wie beim Workshop.</p>
                                    <?php foreach ($dozenten as $d): ?><label class="tag-wahl"><input type="checkbox" name="t_doz[<?= $schl ?>][]" value="<?= (int)$d['id'] ?>"<?= in_array((int)$d['id'], $tDoz, true) ? ' checked' : '' ?>><span><?= e($d['name']) ?></span></label><?php endforeach; ?></div>
                                </details><?php endif; ?></td>
                            <td class="n" data-titel="Belegt"><?= (int)$t['id'] > 0 ? termin_belegt((int)$t['id']) : '–' ?></td>
                            <td class="t-aktion"><button type="button" class="mini" data-duplizieren>Duplizieren</button><?php if ((int)$t['id'] > 0): ?><label class="mini-haken"><input type="checkbox" name="t_weg[<?= $i ?>]" value="1"> entfernen</label><?php else: ?><button type="button" class="mini" data-entfernen hidden>Entfernen</button><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?= isset($fehler['termine']) ? '<p class="feldfehler" id="f-termine">' . e($fehler['termine']) . '</p>' : '' ?>
                <p class="hilfe">Die leere Zeile unten ist für einen neuen Termin. „Duplizieren“ legt den nächsten Termin direkt im Anschluss an, mit gleicher Dauer, Plätzen und Dozierenden. Neue Zeilen nimmt „Entfernen“ sofort weg; gespeicherte Termine mit „entfernen“ anhaken, sie verschwinden beim Speichern.</p>
                <button type="button" class="mini" id="pg-termin-neu">+ Termin</button>
            </div>
            <div class="aktionen">
                <button type="submit" class="knopf">Speichern</button>
                <a class="knopf zweit" href="/team/programm">Abbrechen</a>
                <?php if ($bearbeiten !== null): ?><button type="button" class="knopf gefahr rechts" id="pg-loeschen" hidden>Programmpunkt löschen</button><?php endif; ?>
            </div>
        </form>
        <?php if ($bearbeiten !== null): ?>
        <?= bestaetigung_html('Programmpunkt löschen', '„' . trim($bearbeiten['kuerzel'] . ' ' . $bearbeiten['titel']) . '“ wird gelöscht. Auf der Startseite bleibt er, bis du veröffentlichst.', 'Ja, löschen',
            ['aktion' => 'loeschen', 'id' => $bearbeiten['id']], ['ziel' => '/team/programm', 'ausloeser' => 'pg-loeschen', 'klasse' => 'gefahrzone', 'knopfklasse' => 'knopf gefahr']) ?>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</div>
<?php
team_seite_fuss();
