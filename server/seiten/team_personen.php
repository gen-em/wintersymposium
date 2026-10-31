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
$ziel = fn(): string => '/team/personen?' . http_build_query(array_filter(['f' => $filter !== 'alle' ? $filter : null, 'q' => $q !== '' ? $q : null, 'a' => $aId ?: null, 'k' => $kId ?: null]));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $mail = empty($_POST['ohne_mail']);
    switch ((string)($_POST['aktion'] ?? '')) {
        case 'speichern':
            $a = anm_laden($aId);
            if ($a === null) {
                umleiten('/team/personen');
            }
            $fehler = team_anm_daten($aId, $_POST, false);
            if ($fehler === []) {
                if (($_POST['essen'] ?? '') !== $a['essen']) {
                    anm_essen_setzen($aId, (string)($_POST['essen'] ?? ''), true, false);
                }
                if (anm_symposium_status($aId) === 'fest' && isset($_POST['ws_gesendet'])) {
                    $fehler = anm_workshops_setzen($aId, anm_wahl_lesen($_POST), null, true, !empty($_POST['ueberbuchen']), false);
                }
            }
            if ($fehler === []) {
                if ($mail) {
                    $neu = anm_laden($aId);
                    mail_vorlage_einreihen($neu['email'], 'geaendert', ['vorname' => $neu['vorname'], 'was' => 'das Team hat deine Anmeldung aktualisiert.', 'uebersicht' => anm_uebersicht_text($aId)]);
                }
                flash_setzen('ok', 'Gespeichert' . ($mail ? ', die Person bekommt eine Mail mit der Übersicht.' : ' (ohne Mail).'));
                umleiten($ziel());
            }
            $eingabe = $_POST;
            break;
        case 'link':
            if (anm_laden($aId) !== null) {
                anm_zugang_senden($aId);
                audit_schreiben('anmeldung', 'Team: Einmal-Link an Anmeldung ' . $aId . ' geschickt', $ich);
                flash_setzen('ok', 'Einmal-Link ist unterwegs.');
            }
            umleiten($ziel());
        case 'platz':
            $r = team_platz_geben($aId, (int)($_POST['t'] ?? -1), !empty($_POST['bestaetigt']), $mail);
            if (isset($r['ok'])) {
                flash_setzen('ok', 'Platz vergeben' . ($mail ? ', die Person bekommt eine Mail.' : ' (ohne Mail).'));
                umleiten($ziel());
            }
            if (isset($r['fehler'])) {
                $fehler[] = $r['fehler'];
            } else {
                $frage = ['t' => (int)$_POST['t'], 'texte' => $r['frage'], 'mail' => $mail];
            }
            break;
        case 'angebot':
            $f = team_angebot((int)($_POST['platz'] ?? 0));
            flash_setzen($f === null ? 'ok' : 'fehler', $f ?? 'Angebot ist unterwegs (mit Annahmefrist).');
            umleiten($ziel());
        case 'entfernen':
            flash_setzen(team_platz_entfernen((int)($_POST['platz'] ?? 0), $mail) ? 'ok' : 'fehler', 'Eintrag entfernt' . ($mail ? '.' : ' (ohne Mail).'));
            umleiten($ziel());
        case 'storno':
            anm_stornieren($aId, null, $mail);
            flash_setzen('ok', 'Anmeldung storniert. Freie Plätze gehen an die Warteliste.');
            umleiten('/team/personen?' . http_build_query(array_filter(['f' => $filter !== 'alle' ? $filter : null, 'q' => $q ?: null])));
        case 'anlegen':
            $r = team_anlegen($_POST, !empty($_POST['ueberbuchen']), $mail);
            if (isset($r['id'])) {
                flash_setzen('ok', 'Person angelegt' . ($r['warteliste'] ? ' – das Symposium ist voll, sie steht auf der Warteliste.' : '.') . ' Workshops wählst du jetzt hier.');
                umleiten('/team/personen?a=' . $r['id']);
            }
            $fehler = $r['fehler'];
            $eingabe = $_POST;
            $_GET['neu'] = '1';
            break;
        case 'passwort_link':
            flash_setzen(team_passwort_link($kId) ? 'ok' : 'fehler', 'Passwort-Link ist unterwegs.');
            umleiten($ziel());
        case 'sperren':
            $k = konto_laden($kId);
            if ($k !== null && $k['rolle'] === 'teilnehmend') {
                konto_aktiv_setzen($kId, (int)$k['aktiv'] !== 1, $ich);
                flash_setzen('ok', (int)$k['aktiv'] === 1 ? 'Konto gesperrt.' : 'Konto entsperrt.');
            }
            umleiten($ziel());
        case 'konto_loeschen':
            $k = konto_laden($kId);
            if ($k !== null && $k['rolle'] === 'teilnehmend') {
                $f = tk_loeschen($kId, ($_POST['anmeldung'] ?? '') === 'stornieren');
                flash_setzen($f === null ? 'ok' : 'fehler', $f ?? 'Konto gelöscht.');
            }
            umleiten('/team/personen');
        case 'rolle':
            if (!$betreiber) {
                seite_meldung(403, 'Kein Zugriff', 'Rollen vergibt nur eine BetreiberIn.');
            }
            $kennung = rate_merkmal('konto:' . $ich);
            if (!rate_erlaubt('zweitfaktor', $kennung)) {
                $fehler[] = 'Zu viele falsche Codes. Bitte warte 15 Minuten.';
            } elseif (!totp_anmeldung_pruefen($ich, (string)($_POST['totp'] ?? ''))['ok']) {
                rate_misserfolg('zweitfaktor', $kennung);
                $fehler[] = 'Der Code aus deiner App stimmt nicht. Die Rolle wurde nicht geändert.';
            } else {
                rate_erfolg('zweitfaktor', $kennung);
                $rolle = (string)($_POST['rolle'] ?? '');
                flash_setzen(team_rolle_vergeben($kId, $rolle) ? 'ok' : 'fehler', 'Rolle vergeben: ' . (ROLLEN[$rolle] ?? '') . '. Die Person bekommt eine Mail und richtet beim nächsten Login den zweiten Faktor ein.');
                umleiten($ziel());
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
$gruppen = va_liste('berufsgruppen');
$fehlerFeld = fn(string $f) => isset($fehler[$f]) ? '<p class="feldfehler" id="f-' . $f . '">' . e($fehler[$f]) . '</p>' : '';
$ungueltig = fn(string $f) => isset($fehler[$f]) ? ' aria-invalid="true" aria-describedby="f-' . $f . '"' : '';
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

team_seite_kopf($konto, 'Personen', '/team/personen', ['skripte' => ['anmelden.js']]);
?>
<?= in_array((string)($_POST['aktion'] ?? ''), ['platz', 'rolle'], true) ? fehler_html(array_values($fehler)) : '' ?>
<p class="legende meta klein"><strong>Angemeldet</strong> = Mail bestätigt, Platz beim Symposium · <strong>Mail nicht bestätigt</strong> = Link noch nicht geklickt, Platz nur vorgemerkt · <strong>Warteliste</strong> = bestätigt, wartet auf einen Platz beim Symposium</p>
<form class="filterzeile" method="get" action="/team/personen" role="search">
    <?php if ($filter !== 'alle'): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif; ?>
    <label class="sr" for="suche">Suchen</label>
    <input id="suche" name="q" type="search" value="<?= e($q) ?>" placeholder="Name, Adresse, Institution">
    <button class="knopf zweit klein-knopf" type="submit">Suchen</button>
    <a class="knopf klein-knopf" href="/team/personen?neu=1">+ Person anlegen</a>
</form>
<div class="filter" role="group" aria-label="Filter">
    <?php foreach (TEAM_FILTER as $f => $t): ?>
    <a class="knopf klein-knopf <?= $filter === $f ? '' : 'zweit' ?>" href="/team/personen?<?= e(http_build_query(array_filter(['f' => $f !== 'alle' ? $f : null, 'q' => $q ?: null]))) ?>"<?= $filter === $f ? ' aria-current="true"' : '' ?>><?= e($t) ?> <span class="mono"><?= $zahlen[$f] ?></span></a>
    <?php endforeach; ?>
</div>

<div class="personen-raster<?= $auswahl !== null || ($_GET['neu'] ?? '') === '1' ? ' mit-auswahl' : '' ?>">
    <div class="spalte">
    <?php if ($zeigen === []): ?>
        <p class="hinweis-leer">Keine Personen<?= $q !== '' || $filter !== 'alle' ? ' in dieser Auswahl' : '' ?>.</p>
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
                    <td data-label="Status"><span class="chip <?= $farbe ?>"><?= e($st) ?></span></td>
                    <td data-label="Konto"><?= $p['konto'] === null ? '<span class="meta">ohne Konto</span>' : ($p['konto']['rolle'] === 'teilnehmend' ? ((int)$p['konto']['aktiv'] === 1 ? 'Konto' : '<span class="chip gesperrt">Konto gesperrt</span>') : '<span class="rolle-tag">' . e(ROLLEN[$p['konto']['rolle']]) . '</span>') ?></td>
                    <td data-label="Workshops" class="mono klein"><?= $p['anmeldung'] !== null ? e($wsKurz($p['anmeldung'])) : '–' ?></td>
                    <td data-label="Essen"><?= $p['essen'] !== null ? e(ANM_ESSEN[$p['essen']] ?? '') : '–' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    </div>

    <div class="spalte">
<?php if (($_GET['neu'] ?? '') === '1'):
    $v = fn(string $f) => e((string)($eingabe[$f] ?? '')); ?>
        <div class="kasten detail">
            <h2 class="zwischen klein-zwischen">Person anlegen</h2>
            <p class="meta klein">Gilt sofort als bestätigt (keine Bestätigungsmail). Workshops wählst du danach.</p>
            <form class="form" method="post" action="/team/personen" novalidate>
                <?= csrf_feld() ?><input type="hidden" name="aktion" value="anlegen">
                <div class="paar">
                    <div class="feld"><label for="vorname">Vorname</label><input id="vorname" name="vorname" type="text" maxlength="80" value="<?= $v('vorname') ?>"<?= $ungueltig('vorname') ?>><?= $fehlerFeld('vorname') ?></div>
                    <div class="feld"><label for="nachname">Nachname</label><input id="nachname" name="nachname" type="text" maxlength="80" value="<?= $v('nachname') ?>"<?= $ungueltig('nachname') ?>><?= $fehlerFeld('nachname') ?></div>
                </div>
                <div class="feld"><label for="email">E-Mail</label><input id="email" name="email" type="email" maxlength="190" value="<?= $v('email') ?>"<?= $ungueltig('email') ?>><?= $fehlerFeld('email') ?></div>
                <div class="paar">
                    <div class="feld"><label for="berufsgruppe">Berufsgruppe</label><select id="berufsgruppe" name="berufsgruppe" data-efn-gruppe="<?= e($gruppen[0] ?? '') ?>"<?= $ungueltig('berufsgruppe') ?>><option value="">Bitte wählen</option><?php foreach ($gruppen as $g): ?><option value="<?= e($g) ?>"<?= ($eingabe['berufsgruppe'] ?? '') === $g ? ' selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select><?= $fehlerFeld('berufsgruppe') ?></div>
                    <div class="feld"><label for="institution">Klinik / Institution</label><input id="institution" name="institution" type="text" maxlength="160" value="<?= $v('institution') ?>"<?= $ungueltig('institution') ?>><?= $fehlerFeld('institution') ?></div>
                </div>
                <div class="feld" id="sonst-feld"><label for="beruf_sonstige">Sonstige – welche?</label><input id="beruf_sonstige" name="beruf_sonstige" type="text" maxlength="80" value="<?= $v('beruf_sonstige') ?>"></div>
                <div class="feld" id="efn-feld"><label for="efn">EFN</label><input id="efn" name="efn" type="text" inputmode="numeric" maxlength="20" value="<?= $v('efn') ?>"<?= $ungueltig('efn') ?>><?= $fehlerFeld('efn') ?></div>
                <fieldset class="feld"><legend>Essen</legend><div class="wahl wahl-reihe"><?php foreach (ANM_ESSEN as $k => $t): ?><label><input type="radio" name="essen" value="<?= e($k) ?>"<?= ($eingabe['essen'] ?? 'egal') === $k ? ' checked' : '' ?>><span><?= e($t) ?></span></label><?php endforeach; ?></div><?= $fehlerFeld('essen') ?></fieldset>
                <label class="haken"><input type="checkbox" name="ueberbuchen" value="1"><span>Ist das Symposium voll: überbuchen statt Warteliste</span></label>
                <label class="haken"><input type="checkbox" name="ohne_mail" value="1"><span>ohne Mail an die Person</span></label>
                <div class="aktionen"><button class="knopf" type="submit">Anlegen</button><a class="knopf zweit" href="/team/personen">Abbrechen</a></div>
            </form>
        </div>
<?php elseif ($auswahl === null): ?>
        <p class="hinweis-leer">Wähle links eine Person oder lege mit „+ Person anlegen“ eine neue an.</p>
<?php else:
    $a = $auswahl['anmeldung'];
    $k = $auswahl['konto'];
    [$st, $farbe] = $statusText[$auswahl['status']]; ?>
        <div class="kasten detail">
            <p class="kicker">Ausgewählt</p>
            <h2 class="zwischen"><?= e($auswahl['name']) ?></h2>
            <p class="meta klein"><?= e(implode(' · ', array_filter([$a['berufsgruppe'] ?? $k['berufsgruppe'] ?? '', $a['institution'] ?? $k['institution'] ?? '', ($a['efn'] ?? $k['efn'] ?? null) !== null ? 'EFN ' . efn_text($a['efn'] ?? $k['efn']) : '']))) ?> <span class="chip <?= $farbe ?>"><?= e($st) ?></span></p>

<?php if ($frage !== null): ?>
            <div class="tausch-bestaetigen" role="alert">
                <p class="kicker">Bitte bestätigen</p>
                <ul class="tausch"><?php foreach ($frage['texte'] as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul>
                <div class="aktionen">
                    <form method="post" action="<?= e($ziel()) ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="platz"><input type="hidden" name="a" value="<?= $aId ?>"><input type="hidden" name="t" value="<?= (int)$frage['t'] ?>"><input type="hidden" name="bestaetigt" value="1"><?php if (!$frage['mail']): ?><input type="hidden" name="ohne_mail" value="1"><?php endif; ?><button class="knopf" type="submit">Ja, Platz geben</button></form>
                    <a class="knopf zweit" href="<?= e($ziel()) ?>">Abbrechen</a>
                </div>
            </div>
<?php endif; ?>

<?php if ($a !== null):
    $plaetze = anm_plaetze((int)$a['id']);
    $sym = anm_symposium_status((int)$a['id']);
    $w = fn(string $f) => e($eingabe !== [] ? (string)($eingabe[$f] ?? '') : (string)($a[$f] ?? '')); ?>
            <p class="unterkopf">Anmeldung bearbeiten – auch nach den Stichtagen</p>
            <?php if ($fehler !== []): ?><div class="meldung fehler" role="alert"><ul><?php foreach ($fehler as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <form class="form" method="post" action="<?= e($ziel()) ?>" novalidate>
                <?= csrf_feld() ?><input type="hidden" name="aktion" value="speichern"><input type="hidden" name="a" value="<?= (int)$a['id'] ?>">
                <div class="paar">
                    <div class="feld"><label for="vorname">Vorname</label><input id="vorname" name="vorname" type="text" maxlength="80" value="<?= $w('vorname') ?>"<?= $ungueltig('vorname') ?>><?= $fehlerFeld('vorname') ?></div>
                    <div class="feld"><label for="nachname">Nachname</label><input id="nachname" name="nachname" type="text" maxlength="80" value="<?= $w('nachname') ?>"<?= $ungueltig('nachname') ?>><?= $fehlerFeld('nachname') ?></div>
                </div>
                <?php $teamAdresse = anm_hat_team_konto($a); ?>
                <div class="feld"><label for="email">E-Mail</label><input id="email" name="email" type="email" maxlength="190" value="<?= $teamAdresse ? e($a['email']) : $w('email') ?>"<?= $teamAdresse ? ' readonly' : '' ?><?= $ungueltig('email') ?>><p class="hilfe"><?= $teamAdresse ? e(ANM_TEAM_ADRESSE_TEXT) : 'Vom Team geändert gilt die neue Adresse sofort' . ($a['konto_id'] !== null ? '; die bisherige Adresse bekommt einen Hinweis.' : '.') ?></p><?= $fehlerFeld('email') ?></div>
                <div class="paar">
                    <div class="feld"><label for="berufsgruppe">Berufsgruppe</label><select id="berufsgruppe" name="berufsgruppe" data-efn-gruppe="<?= e($gruppen[0] ?? '') ?>"<?= $ungueltig('berufsgruppe') ?>><?php $bg = $eingabe !== [] ? (string)($eingabe['berufsgruppe'] ?? '') : $a['berufsgruppe']; foreach ($gruppen as $g): ?><option value="<?= e($g) ?>"<?= $bg === $g ? ' selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select><?= $fehlerFeld('berufsgruppe') ?></div>
                    <div class="feld"><label for="institution">Klinik / Institution</label><input id="institution" name="institution" type="text" maxlength="160" value="<?= $w('institution') ?>"<?= $ungueltig('institution') ?>><?= $fehlerFeld('institution') ?></div>
                </div>
                <div class="feld" id="sonst-feld"><label for="beruf_sonstige">Sonstige – welche?</label><input id="beruf_sonstige" name="beruf_sonstige" type="text" maxlength="80" value="<?= $w('beruf_sonstige') ?>"></div>
                <div class="feld" id="efn-feld"><label for="efn">EFN</label><input id="efn" name="efn" type="text" inputmode="numeric" maxlength="20" value="<?= $w('efn') ?>"<?= $ungueltig('efn') ?>><?= $fehlerFeld('efn') ?></div>
                <fieldset class="feld"><legend>Essen</legend><div class="wahl wahl-reihe"><?php $es = $eingabe !== [] ? (string)($eingabe['essen'] ?? '') : $a['essen']; foreach (ANM_ESSEN as $ek => $et): ?><label><input type="radio" name="essen" value="<?= e($ek) ?>"<?= $es === $ek ? ' checked' : '' ?>><span><?= e($et) ?></span></label><?php endforeach; ?></div></fieldset>
                <?php if ($sym === 'fest' && anm_workshops() !== []):
                    $wahl = $eingabe !== [] ? anm_wahl_lesen($eingabe) : ['fest' => [], 'warte' => []];
                    if ($eingabe === []) {
                        foreach ($plaetze as $p) {
                            if ((int)$p['termin_id'] !== 0 && $p['status'] === 'fest') {
                                $wahl['fest'][(int)$p['workshop_id']] = (int)$p['termin_id'];
                            } elseif ((int)$p['termin_id'] !== 0 && $p['status'] === 'warte') {
                                $wahl['warte'][] = (int)$p['termin_id'];
                            }
                        }
                    }
                    $bisher = array_values(array_filter($plaetze, fn($p) => (int)$p['termin_id'] !== 0));
                    $teamModus = true; ?>
                <fieldset class="feld" id="workshops"><legend>Workshops</legend>
                    <input type="hidden" name="ws_gesendet" value="1">
                    <?php require __DIR__ . '/teil_workshopwahl.php'; ?>
                    <label class="haken"><input type="checkbox" name="ueberbuchen" value="1"><span>Überbuchen bestätigen (volle Termine trotzdem vergeben)</span></label>
                </fieldset>
                <?php endif; ?>
                <label class="haken"><input type="checkbox" name="ohne_mail" value="1"><span>ohne Mail an die Person</span></label>
                <div class="aktionen"><button class="knopf" type="submit">Speichern</button></div>
            </form>
            <form method="post" action="<?= e($ziel()) ?>" class="abstand-oben-klein"><?= csrf_feld() ?><input type="hidden" name="aktion" value="link"><input type="hidden" name="a" value="<?= (int)$a['id'] ?>"><button class="knopf zweit klein-knopf" type="submit">Einmal-Link senden</button></form>

            <p class="unterkopf">Plätze, Wartelisten und Angebote</p>
            <dl class="liste-def">
            <?php foreach ($plaetze as $p): $r = anm_platz_zeile($p); $tid = (int)$p['termin_id']; ?>
                <div><dt class="mono klein"><?= e($tid === 0 ? 'Symposium' : (anm_termin($tid)['beginn'] ?? '') . '–' . (anm_termin($tid)['ende'] ?? '')) ?></dt><dd><?= e($tid === 0 ? '' : trim((anm_termin($tid)['workshop']['kuerzel'] ?? '') . ' ' . (anm_termin($tid)['workshop']['titel'] ?? ''))) ?> <span class="chip <?= $p['status'] === 'fest' ? 'ok' : 'wl' ?>"><?= e($r['status']) ?></span>
                    <span class="zellen-aktionen">
                    <?php if ($p['status'] !== 'fest'): ?>
                        <form method="post" action="<?= e($ziel()) ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="platz"><input type="hidden" name="a" value="<?= (int)$a['id'] ?>"><input type="hidden" name="t" value="<?= $tid ?>"><button class="knopf klein-knopf" type="submit">Platz geben</button></form>
                        <?php if ($p['status'] === 'warte' && anm_frei($tid) > 0): ?><form method="post" action="<?= e($ziel()) ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="angebot"><input type="hidden" name="a" value="<?= (int)$a['id'] ?>"><input type="hidden" name="platz" value="<?= (int)$p['id'] ?>"><button class="knopf zweit klein-knopf" type="submit">Angebot schicken</button></form><?php endif; ?>
                    <?php endif; ?>
                    <?php if ($tid !== 0): ?><form method="post" action="<?= e($ziel()) ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="entfernen"><input type="hidden" name="a" value="<?= (int)$a['id'] ?>"><input type="hidden" name="platz" value="<?= (int)$p['id'] ?>"><button class="knopf zweit klein-knopf" type="submit"><?= $p['status'] === 'warte' ? 'Von der Liste nehmen' : 'Entfernen' ?></button></form><?php endif; ?>
                    </span></dd></div>
            <?php endforeach; ?>
            </dl>
<?php endif; ?>

<?php if ($k !== null): ?>
            <p class="unterkopf">Konto</p>
            <?php if ($k['rolle'] !== 'teilnehmend'): ?>
                <p><span class="rolle-tag"><?= e(ROLLEN[$k['rolle']]) ?></span> Team-Konto – verwalten unter <?= $betreiber ? '<a href="/team/konten">Team-Konten</a>' : '„Team-Konten“ (BetreiberIn)' ?>.</p>
            <?php else: ?>
                <p><?= (int)$k['aktiv'] === 1 ? 'Konto aktiv' : '<span class="chip gesperrt">Konto gesperrt</span>' ?> · <?= $k['email_bestaetigt_am'] !== null ? 'Adresse bestätigt' : 'Adresse noch nicht bestätigt' ?> · <?= $k['passwort_hash'] !== null ? 'Passwort gesetzt' : 'noch ohne Passwort' ?></p>
                <div class="zellen-aktionen">
                    <form method="post" action="<?= e($ziel()) ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="passwort_link"><input type="hidden" name="k" value="<?= (int)$k['id'] ?>"><button class="knopf zweit klein-knopf" type="submit">Passwort-Link senden</button></form>
                    <form method="post" action="<?= e($ziel()) ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="sperren"><input type="hidden" name="k" value="<?= (int)$k['id'] ?>"><button class="knopf zweit klein-knopf" type="submit"><?= (int)$k['aktiv'] === 1 ? 'Konto sperren' : 'Konto entsperren' ?></button></form>
                </div>
                <?php if ($betreiber): ?>
                <details class="bestaetigen" data-art="gruen"><summary class="knopf zweit klein-knopf">Rolle ändern</summary>
                    <form method="post" action="<?= e($ziel()) ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="rolle"><input type="hidden" name="k" value="<?= (int)$k['id'] ?>">
                        <div class="feld"><label for="rolle">Neue Rolle</label><select id="rolle" name="rolle"><option value="admin">Admin (Team)</option><option value="betreiber">BetreiberIn</option></select></div>
                        <p><?= e($auswahl['name']) ?> sieht dann alle Anmeldungen mit E-Mail, Institution und EFN. Beim nächsten Login wird ein zweiter Faktor eingerichtet; die Person bekommt eine Mail. Die Änderung steht im Protokoll.</p>
                        <div class="feld"><label for="totp-rolle">Dein Code aus der App</label><input id="totp-rolle" name="totp" type="text" required inputmode="numeric" autocomplete="one-time-code" maxlength="8"></div>
                        <button type="submit" class="knopf">Rolle vergeben</button></form>
                </details>
                <?php endif; ?>
            <?php endif; ?>
<?php endif; ?>

            <div class="gefahrenzone">
                <p class="unterkopf">Gefahrenzone</p>
                <p class="meta klein">Lässt sich nicht rückgängig machen. Freie Plätze gehen sofort an die Warteliste.</p>
                <div class="zellen-aktionen">
                <?php if ($a !== null): ?>
                    <details class="bestaetigen"><summary class="knopf gefahr klein-knopf">Anmeldung stornieren</summary>
                        <form method="post" action="<?= e($ziel()) ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="storno"><input type="hidden" name="a" value="<?= (int)$a['id'] ?>">
                            <p>Die Anmeldung und alle Plätze von <?= e($auswahl['name']) ?> werden gelöscht.</p>
                            <label class="haken"><input type="checkbox" name="ohne_mail" value="1"><span>ohne Mail</span></label>
                            <button type="submit" class="knopf gefahr">Ja, stornieren</button></form>
                    </details>
                <?php endif; ?>
                <?php if ($k !== null && $k['rolle'] === 'teilnehmend'): ?>
                    <details class="bestaetigen"><summary class="knopf gefahr klein-knopf">Konto löschen</summary>
                        <form method="post" action="<?= e($ziel()) ?>"><?= csrf_feld() ?><input type="hidden" name="aktion" value="konto_loeschen"><input type="hidden" name="k" value="<?= (int)$k['id'] ?>">
                            <?php if ($a !== null): ?><div class="wahl"><label><input type="radio" name="anmeldung" value="behalten" checked><span>Anmeldung behalten</span></label><label><input type="radio" name="anmeldung" value="stornieren"><span>Anmeldung auch stornieren</span></label></div><?php endif; ?>
                            <p>Das Konto wird gelöscht.</p>
                            <button type="submit" class="knopf gefahr">Ja, Konto löschen</button></form>
                    </details>
                <?php endif; ?>
                </div>
            </div>
            <?php $letzte = $a !== null ? db_zeile("SELECT a.zeit, a.text, k.name FROM audit a LEFT JOIN konto k ON k.id = a.konto_id WHERE a.text LIKE ? OR a.text LIKE ? ORDER BY a.id DESC LIMIT 1", ['Anmeldung ' . $a['id'] . ' %', '%Anmeldung ' . $a['id']]) : null;
            if ($letzte !== null): ?>
            <p class="meta klein">Letzte Änderung <?= e(datum_zeit_text($letzte['zeit'])) ?> durch <?= e($letzte['name'] ?? 'die Person selbst oder das System') ?> (<?= e($letzte['text']) ?>) · <a href="/team/protokoll?q=<?= e(rawurlencode('Anmeldung ' . $a['id'])) ?>">Protokoll ansehen</a></p>
            <?php endif; ?>
        </div>
<?php endif; ?>
    </div>
</div>
<?php
team_seite_fuss();
