<?php
declare(strict_types=1);

/*
 * Workshops (AP-5, Mockup „Workshops“, E-49): Termine mit Belegung; ein
 * Termin zeigt Teilnehmende und Warteliste in Reihenfolge. Hier landet das
 * Nachrücken nach der automatischen Grenze: Platz geben (Überbuchen mit
 * Bestätigung), Angebot schicken, entfernen. Dazu die Liste je Termin als CSV, Excel oder PDF.
 */

$konto = team_tor();
$tId = isset($_GET['t']) ? (int)$_GET['t'] : (isset($_POST['t']) ? (int)$_POST['t'] : -1);
$fehler = [];
$frage = null;
$ziel = '/team/workshops' . ($tId >= 0 ? '?t=' . $tId : '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $mail = empty($_POST['ohne_mail']);
    switch ((string)($_POST['aktion'] ?? '')) {
        case 'platz':
            $aId = (int)($_POST['a'] ?? 0);
            $r = team_platz_geben($aId, $tId, !empty($_POST['bestaetigt']), $mail);
            if (isset($r['ok'])) {
                flash_setzen('ok', 'Platz vergeben' . ($mail ? ', die Person bekommt eine Mail.' : ' (ohne Mail).'));
                umleiten($ziel);
            }
            if (isset($r['fehler'])) {
                $fehler[] = $r['fehler'];
            } else {
                $frage = ['a' => $aId, 'texte' => $r['frage'], 'mail' => $mail];
            }
            break;
        case 'angebot':
            $f = team_angebot((int)($_POST['platz'] ?? 0));
            flash_setzen($f === null ? 'ok' : 'fehler', $f ?? 'Angebot ist unterwegs (mit Annahmefrist).');
            umleiten($ziel);
        case 'entfernen':
            flash_setzen(team_platz_entfernen((int)($_POST['platz'] ?? 0), $mail) ? 'ok' : 'fehler', 'Eintrag entfernt' . ($mail ? '.' : ' (ohne Mail).'));
            umleiten($ziel);
    }
}

$termine = team_termine();
$auswahl = null;
foreach ($termine as $t) {
    if ($t['id'] === $tId) {
        $auswahl = $t;
    }
}
team_seite_kopf($konto, 'Workshops', '/team/workshops');
?>
<p class="meta klein">Termin anklicken: Teilnehmende und Warteliste bearbeiten. Hier landet auch das Nachrücken nach der automatischen Grenze.</p>
<?= fehler_html($fehler) ?>
<div class="personen-raster<?= $auswahl !== null ? ' mit-auswahl' : '' ?>">
    <div class="spalte">
        <div class="tabelle-wrap"><table class="t karten">
            <thead><tr><th>Termin</th><th>Belegt</th><th>Frei</th><th>Warteliste</th></tr></thead>
            <tbody>
            <?php foreach ($termine as $t): ?>
                <tr<?= $auswahl !== null && $auswahl['id'] === $t['id'] ? ' class="gewaehlt"' : '' ?>>
                    <td data-label="Termin"><a href="/team/workshops?t=<?= $t['id'] ?>"<?= $auswahl !== null && $auswahl['id'] === $t['id'] ? ' aria-current="true"' : '' ?>><?= e($t['text']) ?></a></td>
                    <td data-label="Belegt" class="mono"><?= $t['belegt'] ?><?= $t['kap'] !== null ? ' / ' . $t['kap'] : '' ?></td>
                    <td data-label="Frei" class="mono"><?= $t['frei'] === null ? '∞' : ($t['frei'] < 0 ? '<span class="chip wl">' . $t['frei'] . ' überbucht</span>' : $t['frei']) ?></td>
                    <td data-label="Warteliste" class="mono"><?= $t['warte'] ?><?= $t['angebote'] > 0 ? ' · ' . $t['angebote'] . ' Angebot' . ($t['angebote'] === 1 ? '' : 'e') : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if (count($termine) === 1): ?><p class="hinweis-leer">Noch kein veröffentlichtes Programm mit Workshops.</p><?php endif; ?>
    </div>
    <div class="spalte">
<?php if ($auswahl === null): ?>
        <p class="hinweis-leer">Wähle links einen Termin.</p>
<?php else:
    $pers = team_termin_personen($auswahl['id']);
    $tt = $auswahl['termin']; ?>
        <div class="kasten detail">
            <p class="kicker"><?= $tt !== null ? e($tt['workshop']['kuerzel']) . ' · ' . e($tt['workshop']['ort']) : 'Symposium' ?></p>
            <h2 class="zwischen"><?= e($auswahl['text']) ?></h2>
            <p class="mono"><?= $auswahl['belegt'] ?><?= $auswahl['kap'] !== null ? ' / ' . $auswahl['kap'] . ' Plätze' : ' Plätze (unbegrenzt)' ?> · <?= $auswahl['warte'] ?> auf der Warteliste</p>
            <?php if ($frage !== null): ?>
            <div class="tausch-bestaetigen" role="alert">
                <p class="kicker">Bitte bestätigen</p>
                <ul class="tausch"><?php foreach ($frage['texte'] as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul>
                <div class="aktionen">
                    <form method="post" action="<?= e($ziel) ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="platz"><input type="hidden" name="t" value="<?= $auswahl['id'] ?>"><input type="hidden" name="a" value="<?= (int)$frage['a'] ?>"><input type="hidden" name="bestaetigt" value="1"><?php if (!$frage['mail']): ?><input type="hidden" name="ohne_mail" value="1"><?php endif; ?><button class="knopf" type="submit">Ja, Platz geben</button></form>
                    <a class="knopf zweit" href="<?= e($ziel) ?>">Abbrechen</a>
                </div>
            </div>
            <?php endif; ?>
            <p class="unterkopf">Teilnehmende</p>
            <?php if ($pers['fest'] === []): ?><p class="hinweis-leer">Noch niemand.</p><?php else: ?>
            <ol class="team-liste">
                <?php foreach ($pers['fest'] as $p): ?>
                <li><a href="/team/personen?a=<?= (int)$p['anmeldung_id'] ?>"><?= e($p['vorname'] . ' ' . $p['nachname']) ?></a> <span class="meta klein"><?= e($p['berufsgruppe']) ?></span>
                    <?= $p['status'] === 'angebot' ? '<span class="chip wl">Angebot bis ' . e(datum_zeit_text($p['angebot_bis'])) . '</span>' : '' ?><?= $p['anm_status'] === 'unbestaetigt' ? ' <span class="chip offen">unbestätigt</span>' : '' ?>
                    <?php if ($auswahl['id'] !== 0): ?>
                    <details class="bestaetigen inline-details" data-titel="Platz entfernen?"><summary class="knopf zweit klein-knopf">Entfernen</summary>
                        <form method="post" action="<?= e($ziel) ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="entfernen"><input type="hidden" name="t" value="<?= $auswahl['id'] ?>"><input type="hidden" name="platz" value="<?= (int)$p['id'] ?>">
                            <p>Platz von <?= e($p['vorname'] . ' ' . $p['nachname']) ?> entfernen? Er geht ans Nachrücken.</p>
                            <label class="haken"><input type="checkbox" name="ohne_mail" value="1"><span>ohne Mail</span></label>
                            <button class="knopf gefahr" type="submit">Ja, entfernen</button></form>
                    </details>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php endif; ?>
            <p class="unterkopf">Warteliste in Reihenfolge</p>
            <?php if ($pers['warte'] === []): ?><p class="hinweis-leer">Niemand wartet.</p><?php else: ?>
            <ol class="team-liste">
                <?php foreach ($pers['warte'] as $p): ?>
                <li><a href="/team/personen?a=<?= (int)$p['anmeldung_id'] ?>"><?= e($p['vorname'] . ' ' . $p['nachname']) ?></a> <span class="meta klein">seit <?= e(datum_zeit_text($p['seit'])) ?></span><?= $p['anm_status'] === 'unbestaetigt' ? ' <span class="chip offen">unbestätigt</span>' : '' ?>
                    <span class="zellen-aktionen">
                        <form method="post" action="<?= e($ziel) ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="platz"><input type="hidden" name="t" value="<?= $auswahl['id'] ?>"><input type="hidden" name="a" value="<?= (int)$p['anmeldung_id'] ?>"><button class="knopf klein-knopf" type="submit">Platz geben</button></form>
                        <?php if ($auswahl['frei'] === null || $auswahl['frei'] > 0): ?><form method="post" action="<?= e($ziel) ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="angebot"><input type="hidden" name="t" value="<?= $auswahl['id'] ?>"><input type="hidden" name="platz" value="<?= (int)$p['id'] ?>"><button class="knopf zweit klein-knopf" type="submit">Angebot schicken</button></form><?php endif; ?>
                        <form method="post" action="<?= e($ziel) ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="entfernen"><input type="hidden" name="t" value="<?= $auswahl['id'] ?>"><input type="hidden" name="platz" value="<?= (int)$p['id'] ?>"><button class="knopf zweit klein-knopf" type="submit">Von der Liste nehmen</button></form>
                    </span>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php endif; ?>
            <p class="meta klein abstand-oben-klein">„Platz geben“ vergibt sofort, auch nach der Grenze; ist der Termin voll, fragt die Seite nach (Überbuchen). Die Person bekommt eine Mail.</p>
            <p class="aktionen"><span class="meta klein">Liste:</span> <?= export_knoepfe(['art' => 'termin', 't' => (int)$auswahl['id']]) ?></p>
        </div>
<?php endif; ?>
    </div>
</div>
<?php
team_seite_fuss();
