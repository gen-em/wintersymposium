<?php
declare(strict_types=1);

/*
 * Workshops (AP-5, Mockup „Workshops“, E-49): Termine mit Belegung; ein
 * Termin zeigt Teilnehmende und Warteliste in Reihenfolge. Hier landet das
 * Nachrücken nach der automatischen Grenze: Platz geben (Überbuchen mit
 * Bestätigung), Angebot schicken, entfernen. Dazu die Liste je Termin als CSV, Excel oder PDF.
 */

$konto = team_tor();
team_schreibschutz('/team/workshops'); // abgeschlossene Veranstaltung: nur lesen (E-58)
$tId = isset($_GET['t']) ? (int)$_GET['t'] : (isset($_POST['t']) ? (int)$_POST['t'] : -1);
$fehler = [];
$frage = null;
$ziel = '/team/workshops' . ($tId >= 0 ? '?t=' . $tId : '');

if (ist_post()) {
    $mail = !post_haken('ohne_mail');
    switch (aktion()) {
        case 'platz':
            $aId = post_zahl('a');
            $r = team_platz_geben($aId, $tId, post_haken('bestaetigt'), $mail);
            if (isset($r['ok'])) {
                fertig('ok', 'Platz vergeben' . ($mail ? ', die Person bekommt eine Mail.' : ' (ohne Mail).'), $ziel);
            }
            if (isset($r['fehler'])) {
                $fehler[] = $r['fehler'];
            } else {
                $frage = ['a' => $aId, 'texte' => $r['frage'], 'mail' => $mail];
            }
            break;
        case 'angebot':
            fertig_oder_fehler(team_angebot(post_zahl('platz')), 'Angebot ist unterwegs (mit Annahmefrist).', $ziel);
        case 'entfernen':
            fertig(team_platz_entfernen(post_zahl('platz'), $mail) ? 'ok' : 'fehler', 'Eintrag entfernt' . ($mail ? '.' : ' (ohne Mail).'), $ziel);
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
                    <td data-label="Frei" class="mono"><?= $t['frei'] === null ? '∞' : ($t['frei'] < 0 ? chip('wl', $t['frei'] . ' überbucht') : $t['frei']) ?></td>
                    <td data-label="Warteliste" class="mono"><?= $t['warte'] ?><?= $t['angebote'] > 0 ? ' · ' . $t['angebote'] . ' Angebot' . ($t['angebote'] === 1 ? '' : 'e') : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= count($termine) === 1 ? leer_hinweis('Noch kein veröffentlichtes Programm mit Workshops.') : '' ?>
    </div>
    <div class="spalte">
<?php if ($auswahl === null): ?>
        <?= leer_hinweis('Wähle links einen Termin.') ?>
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
                    <?= aktion_knopf($ziel, 'platz', ['t' => $auswahl['id'], 'a' => (int)$frage['a'], 'bestaetigt' => 1, 'ohne_mail' => $frage['mail'] ? null : 1], 'Ja, Platz geben', 'knopf') ?>
                    <a class="knopf zweit" href="<?= e($ziel) ?>">Abbrechen</a>
                </div>
            </div>
            <?php endif; ?>
            <p class="unterkopf">Teilnehmende</p>
            <?php if ($pers['fest'] === []): ?><?= leer_hinweis('Noch niemand.') ?><?php else: ?>
            <ol class="team-liste">
                <?php foreach ($pers['fest'] as $p): ?>
                <li><a href="/team/personen?a=<?= (int)$p['anmeldung_id'] ?>"><?= e(person_name($p)) ?></a> <span class="meta klein"><?= e($p['berufsgruppe']) ?></span>
                    <?= $p['status'] === 'angebot' ? chip('wl', 'Angebot bis ' . datum_zeit_text($p['angebot_bis'])) : '' ?><?= $p['anm_status'] === 'unbestaetigt' ? ' ' . chip('offen', 'unbestätigt') : '' ?>
                    <?php if ($auswahl['id'] !== 0): ?>
                    <?= bestaetigung_html('Entfernen', 'Platz von ' . person_name($p) . ' entfernen? Er geht ans Nachrücken.', 'Ja, entfernen',
                        ['aktion' => 'entfernen', 't' => $auswahl['id'], 'platz' => (int)$p['id']],
                        ['ziel' => $ziel, 'titel' => 'Platz entfernen?', 'klasse' => 'inline-details', 'inhalt_html' => feld('ohne_mail', 'ohne Mail', ['typ' => 'haken'])]) ?>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php endif; ?>
            <p class="unterkopf">Warteliste in Reihenfolge</p>
            <?php if ($pers['warte'] === []): ?><?= leer_hinweis('Niemand wartet.') ?><?php else: ?>
            <ol class="team-liste">
                <?php foreach ($pers['warte'] as $p): ?>
                <li><a href="/team/personen?a=<?= (int)$p['anmeldung_id'] ?>"><?= e(person_name($p)) ?></a> <span class="meta klein">seit <?= e(datum_zeit_text($p['seit'])) ?></span><?= $p['anm_status'] === 'unbestaetigt' ? ' ' . chip('offen', 'unbestätigt') : '' ?>
                    <span class="zellen-aktionen">
                        <?= aktion_knopf($ziel, 'platz', ['t' => $auswahl['id'], 'a' => (int)$p['anmeldung_id']], 'Platz geben', 'knopf klein-knopf') ?>
                        <?= $auswahl['frei'] === null || $auswahl['frei'] > 0 ? aktion_knopf($ziel, 'angebot', ['t' => $auswahl['id'], 'platz' => (int)$p['id']], 'Angebot schicken') : '' ?>
                        <?= aktion_knopf($ziel, 'entfernen', ['t' => $auswahl['id'], 'platz' => (int)$p['id']], 'Entfernen') ?>
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
