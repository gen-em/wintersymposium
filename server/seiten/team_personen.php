<?php
declare(strict_types=1);

/*
 * Personen (AP-5, Mockup „Personen“, E-49): suchen, filtern, eine Person
 * auswählen und daneben bearbeiten – auch nach den Stichtagen. Anmeldung
 * ändern, Platz geben (Überbuchen und Tausch mit Bestätigung), Angebot
 * schicken, von der Warteliste nehmen, stornieren, Person anlegen,
 * Einmal-Link senden; bei Konten Passwort-Link, sperren, löschen, Rolle
 * (nur Betreiber, mit Code, E-41). Mail an die Person außer „ohne Mail“.
 */

$konto = team_tor();
team_schreibschutz('/team/personen'); // abgeschlossene Veranstaltung: nur lesen (E-58)
$ich = (int)$konto['id'];
$betreiber = $konto['rolle'] === 'betreiber';
$filter = isset(TEAM_FILTER[$_GET['f'] ?? '']) ? (string)$_GET['f'] : 'alle';
$q = trim((string)($_GET['q'] ?? ''));
$aId = (int)($_GET['a'] ?? $_POST['a'] ?? 0);
$kId = (int)($_GET['k'] ?? $_POST['k'] ?? 0);
$fehler = [];
$eingabe = [];
$frage = null;
$modus = ($_GET['neu'] ?? '') === '1' ? 'neu' : ''; // 'neu' = Formular „Person anlegen“
$ziel = fn(): string => '/team/personen?' . http_build_query(array_filter(['f' => $filter !== 'alle' ? $filter : null, 'q' => $q !== '' ? $q : null, 'a' => $aId ?: null, 'k' => $kId ?: null]));

if (ist_post()) {
    zweitfaktor_mailcode_aktion($konto, $ziel());
    $mail = !post_haken('ohne_mail');
    switch (aktion()) {
        case 'speichern':
            $a = anm_laden($aId);
            if ($a === null) {
                umleiten('/team/personen');
            }
            $fehler = team_anm_daten($aId, $_POST, false);
            if ($fehler === []) {
                if (post_text('essen') !== $a['essen']) {
                    anm_essen_setzen($aId, post_text('essen'), true, false);
                }
                if (anm_symposium_status($aId) === 'fest' && isset($_POST['ws_gesendet'])) {
                    $fehler = anm_workshops_setzen($aId, anm_wahl_lesen($_POST), null, true, post_haken('ueberbuchen'), false);
                }
            }
            if ($fehler === []) {
                if ($mail) {
                    $neu = anm_laden($aId);
                    mail_vorlage_einreihen($neu['email'], 'geaendert', ['vorname' => $neu['vorname'], 'was' => 'das Team hat deine Anmeldung aktualisiert.', 'uebersicht' => anm_uebersicht_text($aId)]);
                }
                fertig('ok', 'Gespeichert' . ($mail ? ', die Person bekommt eine Mail mit der Übersicht.' : ' (ohne Mail).'), $ziel());
            }
            $eingabe = $_POST;
            break;
        case 'link':
            if (anm_laden($aId) !== null) {
                anm_zugang_senden($aId);
                audit_schreiben('anmeldung', 'Team: Einmal-Link an Anmeldung ' . $aId . ' geschickt', $ich);
                fertig('ok', 'Einmal-Link ist unterwegs.', $ziel());
            }
            umleiten($ziel());
        case 'platz':
            $r = team_platz_geben($aId, isset($_POST['t']) ? post_zahl('t') : -1, post_haken('bestaetigt'), $mail);
            if (isset($r['ok'])) {
                fertig('ok', 'Platz vergeben' . ($mail ? ', die Person bekommt eine Mail.' : ' (ohne Mail).'), $ziel());
            }
            if (isset($r['fehler'])) {
                $fehler[] = $r['fehler'];
            } else {
                $frage = ['t' => post_zahl('t'), 'texte' => $r['frage'], 'mail' => $mail];
            }
            break;
        case 'angebot':
            fertig_oder_fehler(team_angebot(post_zahl('platz')), 'Angebot ist unterwegs (mit Annahmefrist).', $ziel());
        case 'entfernen':
            fertig(team_platz_entfernen(post_zahl('platz'), $mail) ? 'ok' : 'fehler', 'Eintrag entfernt' . ($mail ? '.' : ' (ohne Mail).'), $ziel());
        case 'storno':
            anm_stornieren($aId, null, $mail);
            fertig('ok', 'Anmeldung storniert. Freie Plätze gehen an die Warteliste.', '/team/personen?' . http_build_query(array_filter(['f' => $filter !== 'alle' ? $filter : null, 'q' => $q ?: null])));
        case 'anlegen':
            $r = team_anlegen($_POST, post_haken('ueberbuchen'), $mail);
            if (isset($r['id'])) {
                fertig('ok', 'Person angelegt' . ($r['warteliste'] ? ' – das Symposium ist voll, sie steht auf der Warteliste.' : '.') . ' Workshops wählst du jetzt hier.', '/team/personen?a=' . $r['id']);
            }
            $fehler = $r['fehler'];
            $eingabe = $_POST;
            $modus = 'neu';
            break;
        case 'passwort_link':
            fertig(team_passwort_link($kId) ? 'ok' : 'fehler', 'Passwort-Link ist unterwegs.', $ziel());
        case 'sperren':
            $k = konto_laden($kId);
            if ($k !== null && $k['rolle'] === 'teilnehmend') {
                konto_aktiv_setzen($kId, (int)$k['aktiv'] !== 1, $ich);
                fertig('ok', (int)$k['aktiv'] === 1 ? 'Konto gesperrt.' : 'Konto entsperrt.', $ziel());
            }
            umleiten($ziel());
        case 'konto_loeschen':
            $k = konto_laden($kId);
            if ($k !== null && $k['rolle'] === 'teilnehmend') {
                fertig_oder_fehler(tk_loeschen($kId, post_text('anmeldung') === 'stornieren'), 'Konto gelöscht.', '/team/personen');
            }
            umleiten('/team/personen');
        case 'rolle':
            if (!$betreiber) {
                seite_meldung(403, 'Kein Zugriff', 'Rollen vergibt nur eine BetreiberIn.');
            }
            if (($f = zweitfaktor_aus_formular($ich)) !== null) {
                $fehler[] = $f . ' Die Rolle wurde nicht geändert.';
            } else {
                $rolle = post_text('rolle');
                fertig(team_rolle_vergeben($kId, $rolle) ? 'ok' : 'fehler', 'Rolle vergeben: ' . (ROLLEN[$rolle] ?? '') . '. Die Person bekommt eine Mail und richtet beim nächsten Login den zweiten Faktor ein.', $ziel());
            }
            break;
    }
}

$alle = team_personen($konto);
$zahlen = [];
foreach (TEAM_FILTER as $f => $_) {
    $zahlen[$f] = count(array_filter($alle, fn($p) => team_filter_passt($p, $f)));
}
$zeigen = array_values(array_filter($alle, fn($p) => team_filter_passt($p, $filter) && team_suche_passt($p, $q)));
$auswahl = null;
foreach ($alle as $p) {
    if (($aId > 0 && $p['art'] === 'anm' && $p['id'] === $aId) || ($kId > 0 && $p['art'] === 'konto' && $p['id'] === $kId)) {
        $auswahl = $p;
    }
}
if ($auswahl !== null && $auswahl['konto'] !== null) {
    $kId = (int)$auswahl['konto']['id'];
}
$statusText = ['angemeldet' => ['Angemeldet', 'ok'], 'warteliste' => ['Warteliste', 'wl'], 'unbestaetigt' => ['Mail nicht bestätigt', 'offen'], 'konto' => ['Konto ohne Anmeldung', 'offen'], 'team' => ['Team', 'offen']];
$wsKurz = function (array $a): string {
    $fest = [];
    $wl = 0;
    foreach ($a['plaetze'] as $p) {
        if ((int)$p['termin_id'] === 0) {
            continue;
        }
        if ($p['status'] === 'fest') {
            $fest[] = anm_termin((int)$p['termin_id'])['workshop']['kuerzel'] ?? '?';
        } else {
            $wl++;
        }
    }
    return ($fest !== [] ? implode(', ', $fest) : '–') . ($wl > 0 ? " +$wl WL" : '');
};

team_seite_kopf($konto, 'Personen', '/team/personen', ['skripte' => ['anmelden.js', 'zweitfaktor.js']]);
?>
<?= in_array(aktion(), ['platz', 'rolle'], true) ? fehler_html($fehler) : '' ?>
<p class="legende meta klein"><strong>Angemeldet</strong> = Mail bestätigt, Platz beim Symposium · <strong>Mail nicht bestätigt</strong> = Link noch nicht geklickt, Platz nur vorgemerkt · <strong>Warteliste</strong> = bestätigt, wartet auf einen Platz beim Symposium</p>
<?= filter_leiste(['ziel' => '/team/personen', 'suche' => true, 'versteckt' => ['f' => $filter !== 'alle' ? $filter : null],
    'felder' => feld('q', 'Suchen', ['typ' => 'search', 'id' => 'suche', 'sr' => true, 'ohne_rahmen' => true, 'wert' => $q, 'attr' => ['placeholder' => 'Name, Adresse, Institution']]),
    'nach_html' => '<a class="knopf klein-knopf" href="/team/personen?neu=1">+ Person anlegen</a>']) ?>
<?= filter_chips(array_map(fn($f) => [TEAM_FILTER[$f], $zahlen[$f]], array_combine(array_keys(TEAM_FILTER), array_keys(TEAM_FILTER))), $filter,
    fn(string $f) => '/team/personen?' . http_build_query(array_filter(['f' => $f !== 'alle' ? $f : null, 'q' => $q ?: null]))) ?>

<div class="personen-raster<?= $auswahl !== null || $modus === 'neu' ? ' mit-auswahl' : '' ?>">
    <div class="spalte">
    <?php if ($zeigen === []): ?>
        <?= leer_hinweis('Personen', $q !== '' || $filter !== 'alle') ?>
    <?php else: ?>
        <div class="tabelle-wrap"><table class="t karten personen">
            <thead><tr><th>Name</th><th>Status</th><th>Konto / Rolle</th><th>Workshops</th><th>Essen</th></tr></thead>
            <tbody>
            <?php foreach ($zeigen as $p):
                $href = '/team/personen?' . http_build_query(array_filter(['f' => $filter !== 'alle' ? $filter : null, 'q' => $q ?: null, $p['art'] === 'anm' ? 'a' : 'k' => $p['id']]));
                $gewaehlt = $auswahl !== null && $auswahl['art'] === $p['art'] && $auswahl['id'] === $p['id'];
                [$st, $farbe] = $statusText[$p['status']]; ?>
                <tr<?= $gewaehlt ? ' class="gewaehlt"' : '' ?>>
                    <td data-label="Name"><a href="<?= e($href) ?>"<?= $gewaehlt ? ' aria-current="true"' : '' ?>><?= e($p['name']) ?></a><br><span class="meta klein"><?= e($p['email']) ?></span></td>
                    <td data-label="Status"><?= chip($farbe, $st) ?></td>
                    <td data-label="Konto"><?= $p['konto'] === null ? '<span class="meta">ohne Konto</span>' : ($p['konto']['rolle'] === 'teilnehmend' ? ((int)$p['konto']['aktiv'] === 1 ? 'Konto' : chip('gesperrt', 'Konto gesperrt')) : '<span class="rolle-tag">' . e(ROLLEN[$p['konto']['rolle']]) . '</span>') ?></td>
                    <td data-label="Workshops" class="mono klein"><?= $p['anmeldung'] !== null ? e($wsKurz($p['anmeldung'])) : '–' ?></td>
                    <td data-label="Essen"><?= $p['essen'] !== null ? e(ANM_ESSEN[$p['essen']] ?? '') : '–' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    </div>

    <div class="spalte">
<?php if ($modus === 'neu'): ?>
        <div class="kasten detail">
            <h2 class="zwischen klein-zwischen">Person anlegen</h2>
            <p class="meta klein">Gilt sofort als bestätigt (keine Bestätigungsmail). Workshops wählst du danach.</p>
            <form class="form" method="post" action="/team/personen" novalidate>
                <?= csrf_feld() . versteckt_html(['aktion' => 'anlegen']) ?>
                <?= profil_felder_html($eingabe, $fehler, ['hinweise' => false, 'autocomplete' => false]) ?>
                <?= feld('essen', 'Essen', ['typ' => 'radio-reihe', 'optionen' => ANM_ESSEN, 'wert' => wert($eingabe, [], 'essen') ?: 'egal', 'fehler' => $fehler['essen'] ?? null]) ?>
                <?= feld('ueberbuchen', 'Ist das Symposium voll: überbuchen statt Warteliste', ['typ' => 'haken']) ?>
                <?= feld('ohne_mail', 'ohne Mail an die Person', ['typ' => 'haken']) ?>
                <div class="aktionen"><button class="knopf" type="submit">Anlegen</button><a class="knopf zweit" href="/team/personen">Abbrechen</a></div>
            </form>
        </div>
<?php elseif ($auswahl === null): ?>
        <?= leer_hinweis('Wähle links eine Person oder lege mit „+ Person anlegen“ eine neue an.') ?>
<?php else:
    $a = $auswahl['anmeldung'];
    $k = $auswahl['konto'];
    [$st, $farbe] = $statusText[$auswahl['status']]; ?>
        <div class="kasten detail">
            <p class="kicker">Ausgewählt</p>
            <h2 class="zwischen"><?= e($auswahl['name']) ?></h2>
            <p class="meta klein"><?= e(implode(' · ', array_filter([$a['berufsgruppe'] ?? $k['berufsgruppe'] ?? '', $a['institution'] ?? $k['institution'] ?? '', ($a['efn'] ?? $k['efn'] ?? null) !== null ? 'EFN ' . efn_text($a['efn'] ?? $k['efn']) : '']))) ?> <?= chip($farbe, $st) ?></p>

<?php if ($frage !== null): ?>
            <div class="tausch-bestaetigen" role="alert">
                <p class="kicker">Bitte bestätigen</p>
                <ul class="tausch"><?php foreach ($frage['texte'] as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul>
                <div class="aktionen">
                    <?= aktion_knopf($ziel(), 'platz', ['a' => $aId, 't' => (int)$frage['t'], 'bestaetigt' => 1, 'ohne_mail' => $frage['mail'] ? null : 1], 'Ja, Platz geben', 'knopf') ?>
                    <a class="knopf zweit" href="<?= e($ziel()) ?>">Abbrechen</a>
                </div>
            </div>
<?php endif; ?>

<?php if ($a !== null):
    $plaetze = anm_plaetze((int)$a['id']);
    $sym = anm_symposium_status((int)$a['id']);
    $teamAdresse = anm_hat_team_konto($a);
    // Adresse eines Team-Kontos bleibt die gespeicherte, auch nach einem Fehler
    $werte = ($teamAdresse ? ['email' => $a['email']] : []) + ($eingabe !== [] ? $eingabe : $a); ?>
            <p class="unterkopf">Anmeldung bearbeiten – auch nach den Stichtagen</p>
            <?= fehler_html($fehler) ?>
            <form class="form" method="post" action="<?= e($ziel()) ?>" novalidate>
                <?= csrf_feld() . versteckt_html(['aktion' => 'speichern', 'a' => (int)$a['id']]) ?>
                <?= profil_felder_html($werte, $fehler, ['hinweise' => false, 'autocomplete' => false, 'email' => $teamAdresse ? 'fest' : 'frei',
                    'email_hilfe' => $teamAdresse ? ANM_TEAM_ADRESSE_TEXT : 'Vom Team geändert gilt die neue Adresse sofort' . ($a['konto_id'] !== null ? '; die bisherige Adresse bekommt einen Hinweis.' : '.')]) ?>
                <?= feld('essen', 'Essen', ['typ' => 'radio-reihe', 'optionen' => ANM_ESSEN, 'wert' => wert($eingabe, $a, 'essen')]) ?>
                <?php if ($sym === 'fest' && anm_workshops() !== []):
                    $wahl = $eingabe !== [] ? anm_wahl_lesen($eingabe) : anm_wahl_aus_plaetzen($plaetze);
                    $bisher = array_values(array_filter($plaetze, fn($p) => (int)$p['termin_id'] !== 0)); ?>
                <fieldset class="feld" id="workshops"><legend>Workshops</legend>
                    <input type="hidden" name="ws_gesendet" value="1">
                    <?= anm_workshopwahl_html($wahl, $bisher, true) ?>
                    <?= feld('ueberbuchen', 'Überbuchen bestätigen (volle Termine trotzdem vergeben)', ['typ' => 'haken']) ?>
                </fieldset>
                <?php endif; ?>
                <?= feld('ohne_mail', 'ohne Mail an die Person', ['typ' => 'haken']) ?>
                <div class="aktionen"><button class="knopf" type="submit">Speichern</button></div>
            </form>
            <?= aktion_knopf($ziel(), 'link', ['a' => (int)$a['id']], 'Einmal-Link senden', 'knopf zweit klein-knopf', ['form_klasse' => 'abstand-oben-klein']) ?>

            <p class="unterkopf">Plätze, Wartelisten und Angebote</p>
            <dl class="liste-def">
            <?php foreach ($plaetze as $p): $r = anm_platz_zeile($p); $tid = (int)$p['termin_id']; ?>
                <div><dt class="mono klein"><?= e($tid === 0 ? 'Symposium' : (anm_termin($tid)['beginn'] ?? '') . '–' . (anm_termin($tid)['ende'] ?? '')) ?></dt><dd><?= e($tid === 0 ? '' : trim((anm_termin($tid)['workshop']['kuerzel'] ?? '') . ' ' . (anm_termin($tid)['workshop']['titel'] ?? ''))) ?> <?= chip($p['status'] === 'fest' ? 'ok' : 'wl', $r['status']) ?>
                    <span class="zellen-aktionen">
                    <?php if ($p['status'] !== 'fest'): ?>
                        <?= aktion_knopf($ziel(), 'platz', ['a' => (int)$a['id'], 't' => $tid], 'Platz geben', 'knopf klein-knopf') ?>
                        <?= $p['status'] === 'warte' && anm_frei($tid) > 0 ? aktion_knopf($ziel(), 'angebot', ['a' => (int)$a['id'], 'platz' => (int)$p['id']], 'Angebot schicken') : '' ?>
                    <?php endif; ?>
                    <?= $tid !== 0 ? aktion_knopf($ziel(), 'entfernen', ['a' => (int)$a['id'], 'platz' => (int)$p['id']], 'Entfernen') : '' ?>
                    </span></dd></div>
            <?php endforeach; ?>
            </dl>
<?php endif; ?>

<?php if ($k !== null): ?>
            <p class="unterkopf">Konto</p>
            <?php if ($k['rolle'] !== 'teilnehmend'): ?>
                <p><span class="rolle-tag"><?= e(ROLLEN[$k['rolle']]) ?></span> Team-Konto – verwalten unter <?= $betreiber ? '<a href="/team/konten">Team-Konten</a>' : '„Team-Konten“ (BetreiberIn)' ?>.</p>
            <?php else: ?>
                <p><?= (int)$k['aktiv'] === 1 ? 'Konto aktiv' : chip('gesperrt', 'Konto gesperrt') ?> · <?= $k['email_bestaetigt_am'] !== null ? 'Adresse bestätigt' : 'Adresse noch nicht bestätigt' ?> · <?= $k['passwort_hash'] !== null ? 'Passwort gesetzt' : 'noch ohne Passwort' ?></p>
                <div class="zellen-aktionen">
                    <?= aktion_knopf($ziel(), 'passwort_link', ['k' => (int)$k['id']], 'Passwort-Link senden') ?>
                    <?= aktion_knopf($ziel(), 'sperren', ['k' => (int)$k['id']], (int)$k['aktiv'] === 1 ? 'Konto sperren' : 'Konto entsperren') ?>
                </div>
                <?php if ($betreiber): ?>
                <?= bestaetigung_html('Rolle ändern', '', 'Rolle vergeben', ['aktion' => 'rolle', 'k' => (int)$k['id']], ['ziel' => $ziel(), 'art' => 'gruen',
                    'inhalt_html' => feld('rolle', 'Neue Rolle', ['typ' => 'auswahl', 'optionen' => ['admin' => 'Admin (Team)', 'betreiber' => 'BetreiberIn']])
                        . '<p>' . e($auswahl['name']) . ' sieht dann alle Anmeldungen mit E-Mail, Institution und EFN. Beim nächsten Login wird ein zweiter Faktor eingerichtet; die Person bekommt eine Mail. Die Änderung steht im Protokoll.</p>'
                        . zweitfaktor_feld_html($konto, 'totp-rolle')]) ?>
                <?php endif; ?>
            <?php endif; ?>
<?php endif; ?>

            <div class="gefahrenzone">
                <p class="unterkopf">Gefahrenzone</p>
                <p class="meta klein">Lässt sich nicht rückgängig machen. Freie Plätze gehen sofort an die Warteliste.</p>
                <div class="zellen-aktionen">
                <?php if ($a !== null): ?>
                    <?= bestaetigung_html('Anmeldung stornieren', 'Die Anmeldung und alle Plätze von ' . $auswahl['name'] . ' werden gelöscht.', 'Ja, stornieren', ['aktion' => 'storno', 'a' => (int)$a['id']],
                        ['ziel' => $ziel(), 'knopfklasse' => 'knopf gefahr klein-knopf', 'inhalt_html' => feld('ohne_mail', 'ohne Mail', ['typ' => 'haken'])]) ?>
                <?php endif; ?>
                <?php if ($k !== null && $k['rolle'] === 'teilnehmend'): ?>
                    <?php // Auswahl ohne Rahmen und Legende (feld() radio bringt ein fieldset mit) bleibt von Hand ?>
                    <?= bestaetigung_html('Konto löschen', 'Das Konto wird gelöscht.', 'Ja, löschen', ['aktion' => 'konto_loeschen', 'k' => (int)$k['id']], ['ziel' => $ziel(), 'knopfklasse' => 'knopf gefahr klein-knopf', 'vor_frage' => true,
                        'inhalt_html' => $a !== null ? '<div class="wahl"><label><input type="radio" name="anmeldung" value="behalten" checked><span>Anmeldung behalten</span></label><label><input type="radio" name="anmeldung" value="stornieren"><span>Anmeldung auch stornieren</span></label></div>' : '']) ?>
                <?php endif; ?>
                </div>
            </div>
            <?php $letzte = $a !== null ? audit_letzte_anmeldung((int)$a['id']) : null;
            if ($letzte !== null): ?>
            <p class="meta klein">Letzte Änderung <?= e(datum_zeit_text($letzte['zeit'])) ?> durch <?= e($letzte['name'] ?? 'die Person selbst oder das System') ?> (<?= e($letzte['text']) ?>) · <a href="/team/protokoll?q=<?= e(rawurlencode('Anmeldung ' . $a['id'])) ?>">Protokoll ansehen</a></p>
            <?php endif; ?>
        </div>
<?php endif; ?>
    </div>
</div>
<?php
team_seite_fuss();
