<?php
declare(strict_types=1);

/*
 * „Deine Anmeldung“ (AP-4, Mockups „Wie willst du rein?“ und „Deine
 * Anmeldung“; E-31, E-38, E-40, E-42).
 *
 * Ohne Sitzung: Einstieg – neu anmelden oder Link per Mail (gleiche Antwort
 * für jede Adresse, R-03). Mit Sitzung (nach einem Einmal-Link): Übersicht,
 * Nachrück-Angebote mit Tausch-Bestätigung, Workshops, Essen und Daten ändern,
 * stornieren. Stichtage sperren die jeweilige Änderung (E-31) und stehen am Knopf.
 */

anm_sitzung();
$a = anm_aktuell();
// Anmeldung einer anderen (vergangenen) Veranstaltung, z. B. über den Link der Zertifikat-Mail (E-58):
// in deren Kontext anzeigen, nur zum Ansehen
if ($a !== null && (int)$a['veranstaltung_id'] !== va_id()) {
    va_kontext((int)$a['veranstaltung_id']);
}
$nurAnsehen = $a !== null && (va_zeile()['status'] ?? 'aktuell') !== 'aktuell';
$beginn = microtime(true);
$aktion = (string)($_POST['aktion'] ?? '');
$fehler = [];
$tausch = null;
$tauschPlatz = 0;
$eingabe = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    if ($aktion === 'ausloggen') {
        anm_ausloggen();
        flash_setzen('ok', 'Du bist ausgeloggt.');
        umleiten('/deine-anmeldung');
    }
    if ($aktion === 'einloggen') {
        // Gemeinsamer Login (E-48): Teilnehmende hier, Team weiter zum zweiten Faktor
        $r = tk_login_pruefen(trim((string)($_POST['email'] ?? '')), (string)($_POST['passwort'] ?? ''));
        if (isset($r['konto']) && konto_ist_team($r['konto'])) {
            anmeldung_halb_setzen($r['konto']);
            umleiten('/einloggen');
        }
        if (isset($r['konto'])) {
            tk_einloggen($r['konto']);
            umleiten(tk_anmeldung((int)$r['konto']['id']) !== null ? '/deine-anmeldung' : '/konto');
        }
        $fehler['login'] = $r['fehler'];
    } elseif ($aktion === 'link') {
        // Gleiche Antwort und Dauer für jede Adresse (R-03)
        $email = mail_adresse(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
        if ($email === null) {
            $fehler['email'] = 'Diese E-Mail-Adresse sieht nicht wie eine Adresse aus.';
        } else {
            $ip = rate_merkmal_ip();
            $ziel = rate_merkmal_kennung('link:' . $email);
            $ok = trim((string)($_POST['website'] ?? '')) === '' && rate_erlaubt('link_ip', $ip) && rate_erlaubt('link_ziel', $ziel);
            rate_misserfolg('link_ip', $ip);
            rate_misserfolg('link_ziel', $ziel);
            $vorhanden = $ok ? anm_per_email($email) : null;
            if ($vorhanden !== null) {
                anm_zugang_senden((int)$vorhanden['id']);
            } elseif ($ok && anm_zeitraum() === 'offen' && ($frueher = anm_frueher_per_email($email)) !== null) {
                // Wiederkehrende ohne Konto (E-58): Link zur Anmeldung mit den früheren Angaben
                anm_uebernehmen_senden((int)$frueher['id']);
            }
            rate_gleiche_dauer($beginn, 0.6);
            flash_setzen('ok', 'Wenn die Adresse bei uns angemeldet ist, ist der Link unterwegs. Er gilt ' . ANM_LINK_GUELTIG_H . ' Stunden und einmal. Schau auch im Spam-Ordner.');
            umleiten('/deine-anmeldung');
        }
    } elseif ($a === null) {
        flash_setzen('fehler', 'Deine Sitzung ist abgelaufen. Lass dir einen neuen Link schicken.');
        umleiten('/deine-anmeldung');
    } elseif ($nurAnsehen && $aktion !== 'konto_anlegen') {
        flash_setzen('fehler', 'Diese Veranstaltung ist vorbei – hier lässt sich nichts mehr ändern.');
        umleiten('/deine-anmeldung');
    } else {
        $id = (int)$a['id'];
        switch ($aktion) {
            case 'bestaetigung_neu':
                if ($a['status'] === 'unbestaetigt') {
                    $bis = strtotime($a['bestaetigen_bis'] . ' UTC');
                    $token = anm_link_erzeugen($id, 'bestaetigen', $bis);
                    mail_vorlage_einreihen($a['email'], 'bestaetigen', ['vorname' => $a['vorname'], 'link' => anm_link_url($token), 'gueltig' => datum_zeit_text($a['bestaetigen_bis']), 'uebersicht' => anm_uebersicht_text($id)]);
                    flash_setzen('ok', 'Die Bestätigungsmail ist noch einmal unterwegs.');
                }
                umleiten('/deine-anmeldung');
            case 'workshops':
                if (!anm_frist_offen('stichtag_workshop')) {
                    flash_setzen('fehler', 'Workshops kannst du nicht mehr selbst ändern. Schreib uns, wir helfen.');
                    umleiten('/deine-anmeldung');
                }
                $fehler = anm_workshops_setzen($id, anm_wahl_lesen($_POST));
                if ($fehler === []) {
                    flash_setzen('ok', 'Deine Workshops sind gespeichert. Du bekommst eine Mail mit der Übersicht.');
                    umleiten('/deine-anmeldung');
                }
                $_GET['aendern'] = 'workshops';
                $eingabe = $_POST;
                break;
            case 'essen':
                if (!anm_frist_offen('stichtag_essen')) {
                    flash_setzen('fehler', 'Das Essen kannst du nicht mehr selbst ändern.');
                } elseif (anm_essen_setzen($id, (string)($_POST['essen'] ?? ''))) {
                    flash_setzen('ok', 'Deine Essenswahl ist gespeichert.');
                }
                umleiten('/deine-anmeldung');
            case 'daten':
                $r = anm_daten_setzen($id, $_POST);
                if ($r['fehler'] === []) {
                    flash_setzen('ok', $r['email_link'] ? 'Gespeichert. An die neue Adresse ist ein Link unterwegs; erst damit gilt sie. Bis dahin bleibt die bisherige.' : 'Deine Daten sind gespeichert.');
                    umleiten('/deine-anmeldung');
                }
                $fehler = $r['fehler'];
                $eingabe = $_POST;
                $_GET['aendern'] = 'daten';
                break;
            case 'storno':
                if (!anm_frist_offen('stichtag_storno')) {
                    flash_setzen('fehler', 'Absagen geht nicht mehr selbst. Schreib uns bitte, wenn du nicht kommen kannst.');
                    umleiten('/deine-anmeldung');
                }
                anm_stornieren($id);
                anm_ausloggen();
                flash_setzen('ok', 'Deine Anmeldung ist storniert. Danke, dass du abgesagt hast – dein Platz geht an die Warteliste.');
                umleiten('/deine-anmeldung');
            case 'annehmen':
                $r = anm_angebot_annehmen($id, (int)($_POST['platz'] ?? 0), !empty($_POST['tausch']));
                if (isset($r['ok'])) {
                    flash_setzen('ok', 'Der Platz ist deiner. Du bekommst eine Mail mit der Übersicht.');
                    umleiten('/deine-anmeldung');
                }
                if (isset($r['fehler'])) {
                    flash_setzen('fehler', $r['fehler']);
                    umleiten('/deine-anmeldung');
                }
                $tausch = $r['tausch'];
                $tauschPlatz = (int)($_POST['platz'] ?? 0);
                break;
            case 'konto_anlegen':
                $kid = tk_anlegen_aus_anmeldung($id);
                if ($kid === null) {
                    flash_setzen('fehler', 'Für diese Adresse lässt sich hier kein Konto anlegen.');
                    umleiten('/deine-anmeldung');
                }
                tk_einloggen(konto_laden($kid));
                $_SESSION['tk_pw'] = true;
                flash_setzen('ok', 'Dein Konto ist angelegt. Leg jetzt dein Passwort fest.');
                umleiten('/konto');
            case 'ablehnen':
                anm_angebot_ablehnen($id, (int)($_POST['platz'] ?? 0));
                flash_setzen('ok', 'Angebot abgelehnt. Der Platz geht an die nächste Person.');
                umleiten(anm_laden($id) === null ? '/' : '/deine-anmeldung');
        }
    }
}

$a = anm_aktuell();
$meldung = flash_holen();
$fehlerFeld = fn(string $k) => isset($fehler[$k]) ? '<p class="feldfehler" id="f-' . $k . '">' . e($fehler[$k]) . '</p>' : '';
$ungueltig = fn(string $k) => isset($fehler[$k]) ? ' aria-invalid="true" aria-describedby="f-' . $k . '"' : '';

/* ---- Einstieg ohne Sitzung ---- */
if ($a === null) {
    seite_kopf('Anmelden', ['aktiv' => '/deine-anmeldung']);
    $zr = anm_zeitraum();
    $kap = anm_kapazitaet(0);
    ?>
<div class="innen form-kopf">
    <p class="kicker"><?= e(va_kurz()) ?> · <?= e(tag_text(va('veranstaltung_datum'))) ?></p>
    <h1 class="titel">Wie willst du rein?</h1>
    <p class="text">Ein Konto brauchst du nicht. Mit Konto sparst du dir nur die Links per Mail.</p>
</div>
<div class="innen">
    <?= meldung_html($meldung) ?>
<?php if (($ich = tk_aktuell() ?? team_gesehen()) !== null): // Eingeloggt, aber noch nicht angemeldet (E-54): kein Login-Feld ?>
    <div class="kasten zg schmal-kasten">
        <p class="kicker">Eingeloggt als <?= e($ich['name']) ?></p>
        <h2 class="zwischen"><?= $zr === 'vorher' ? 'Anmeldung noch nicht möglich' : 'Noch nicht angemeldet' ?></h2>
        <?php if ($zr === 'vorher'): ?>
            <p>Die Anmeldung öffnet am <strong><?= e(datum_zeit_text(va('anmeldung_start'))) ?> Uhr</strong>. Dann meldest du dich hier mit deinem Konto an – Name und Adresse sind schon ausgefüllt.</p>
        <?php elseif ($zr === 'offen'): ?>
            <p>Mit deinem Konto sind Name und Adresse schon ausgefüllt, eine Bestätigung per Mail entfällt.</p>
            <a class="knopf gruen" href="/anmelden">Jetzt anmelden</a>
            <p class="meta klein"><?= $kap !== null ? 'Noch ' . anm_frei(0) . ' von ' . $kap . ' Plätzen frei · ' : '' ?>Anmeldeschluss <?= e(datum_text(anm_schluss_utc())) ?></p>
        <?php else: ?>
            <p class="meta">Die Anmeldung ist geschlossen.</p>
        <?php endif; ?>
    </div>
</div>
<?php
    seite_fuss();
    return;
endif; ?>
    <div class="zg-raster drei-spalten">
<?php if ($zr === 'vorher'): ?>
        <div class="kasten zg zg-zwei">
            <p class="kicker">Noch nicht möglich</p>
            <h2 class="zwischen">Anmeldung noch nicht möglich</h2>
            <p>Die Anmeldung öffnet am <strong><?= e(datum_zeit_text(va('anmeldung_start'))) ?> Uhr</strong>. Dann kannst du dich hier anmelden.</p>
        </div>
<?php else: ?>
        <div class="kasten zg">
            <p class="kicker">Noch nicht angemeldet</p>
            <h2 class="zwischen">Neu anmelden</h2>
            <p>Name, Berufsgruppe, Workshops, Essen – zwei Minuten.</p>
            <?php if ($zr === 'offen'): ?>
                <a class="knopf gruen" href="/anmelden">Zur Anmeldung</a>
                <p class="meta klein"><?= $kap !== null ? 'Noch ' . anm_frei(0) . ' von ' . $kap . ' Plätzen frei · ' : '' ?>Anmeldeschluss <?= e(datum_text(anm_schluss_utc())) ?></p>
            <?php else: ?>
                <p class="meta">Die Anmeldung ist geschlossen.</p>
            <?php endif; ?>
        </div>
        <div class="kasten zg">
            <p class="kicker">Schon angemeldet</p>
            <h2 class="zwischen">Link per Mail</h2>
            <p>Gib deine Adresse ein. Wenn wir sie kennen, schicken wir dir einen Link, mit dem du deine Anmeldung ansiehst und änderst. Er gilt <?= ANM_LINK_GUELTIG_H ?> Stunden und nur einmal.</p>
            <form method="post" action="/deine-anmeldung" class="zg-form" novalidate>
                <?= csrf_feld() ?><input type="hidden" name="aktion" value="link">
                <div class="honig" aria-hidden="true"><label for="website">Website (bitte leer lassen)</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
                <div class="feld"><label for="zg-mail">E-Mail</label><input id="zg-mail" name="email" type="email" autocomplete="email" required<?= $ungueltig('email') ?>><?= $fehlerFeld('email') ?></div>
                <button class="knopf" type="submit">Link schicken</button>
            </form>
        </div>
<?php endif; ?>
        <div class="kasten zg">
            <p class="kicker">Mit Konto</p>
            <h2 class="zwischen">Einloggen</h2>
            <form method="post" action="/deine-anmeldung" class="zg-form" novalidate>
                <?= csrf_feld() ?><input type="hidden" name="aktion" value="einloggen">
                <?php if (isset($fehler['login'])): ?><p class="feldfehler" role="alert"><?= e($fehler['login']) ?></p><?php endif; ?>
                <div class="feld"><label for="zg-lmail">E-Mail</label><input id="zg-lmail" name="email" type="email" autocomplete="username" required></div>
                <div class="feld"><label for="zg-pw">Passwort</label><input id="zg-pw" name="passwort" type="password" autocomplete="current-password" required></div>
                <div class="aktionen"><button class="knopf" type="submit">Einloggen</button><a class="klein-link" href="/konto/passwort-vergessen">Passwort vergessen?</a></div>
            </form>
            <p class="meta klein">Noch kein Konto? <a href="/konto/neu">Konto anlegen</a></p>
        </div>
    </div>
</div>
<?php
    seite_fuss();
    return;
}

/* ---- Mit Sitzung ---- */
$id = (int)$a['id'];
$plaetze = anm_plaetze($id);
$sym = anm_symposium_status($id);
$angebote = anm_angebote($id);
$modus = (string)($_GET['aendern'] ?? '');
$fristText = function (string $schluessel): string {
    $tag = anm_stichtag($schluessel);
    return anm_frist_offen($schluessel) ? 'möglich bis <b>' . e(tag_text($tag)) . '</b>' : 'nur noch über das Team (Stichtag war ' . e(tag_text($tag)) . ')';
};
$status = $a['status'] === 'unbestaetigt' ? 'Noch nicht bestätigt' : ($sym === 'fest' ? 'Anmeldung bestätigt' : ($sym === 'angebot' ? 'Platzangebot' : 'Warteliste'));

seite_kopf('Meine Anmeldung', ['aktiv' => '/deine-anmeldung', 'teilnehmer' => $a, 'skripte' => ['anmelden.js']]);
?>
<div class="innen form-kopf">
    <p class="kicker">Deine Anmeldung · <?= e(va_kurz()) ?> · <?= e(tag_text(va('veranstaltung_datum'))) ?></p>
    <h1 class="titel">Hallo <?= e($a['vorname']) ?>.</h1>
    <div class="status-band"><span class="sticker<?= $a['status'] === 'unbestaetigt' || $sym !== 'fest' ? ' hinweis' : '' ?>"><?= e($status) ?></span><span class="mono">Anmeldung Nr. <?= sprintf('%04d', $id) ?></span></div>
</div>
<div class="innen">
    <?= meldung_html($meldung) ?>
    <?php if ($nurAnsehen): ?><p class="va-archiv" role="status"><strong>Diese Veranstaltung ist vorbei.</strong> Du siehst deine Anmeldung nur noch an<?= va_aktuell_id() !== null ? '; zur aktuellen Veranstaltung geht es über die <a href="/">Startseite</a>' : '' ?>.</p><?php endif; ?>
    <?php if ($a['status'] === 'unbestaetigt'): ?>
    <div class="meldung fehler" role="alert">Bitte bestätige deine Anmeldung über den Link in der Mail – bis <?= e(datum_zeit_text($a['bestaetigen_bis'])) ?> Uhr. Danach verfällt sie und die Plätze gehen an andere.
        <form method="post" action="/deine-anmeldung" class="inline"><?= csrf_feld() ?><input type="hidden" name="aktion" value="bestaetigung_neu"><button type="submit" class="link-knopf">Mail noch einmal schicken</button></form></div>
    <?php endif; ?>
</div>

<?php if ($modus === 'workshops' && $sym === 'fest' && anm_frist_offen('stichtag_workshop')):
    $wahl = $eingabe !== [] ? anm_wahl_lesen($eingabe) : ['fest' => [], 'warte' => []];
    if ($eingabe === []) {
        foreach ($plaetze as $p) {
            if ((int)$p['termin_id'] === 0) {
                continue;
            }
            if ($p['status'] === 'fest') {
                $wahl['fest'][(int)$p['workshop_id']] = (int)$p['termin_id'];
            } elseif ($p['status'] === 'warte') {
                $wahl['warte'][] = (int)$p['termin_id'];
            }
        }
    }
    $bisher = array_values(array_filter($plaetze, fn($p) => (int)$p['termin_id'] !== 0)); ?>
<div class="innen abschnitt">
    <form class="form" method="post" action="/deine-anmeldung" novalidate>
        <?= csrf_feld() ?><input type="hidden" name="aktion" value="workshops">
        <fieldset class="feld" id="workshops"><legend>Workshops ändern</legend>
            <p class="hilfe">Gibst du einen festen Platz ab, geht er sofort an die Warteliste. Je Workshop ein fester Termin; Wartelisten für volle Termine.</p>
            <?php if ($fehler !== []): ?><div class="meldung fehler" role="alert"><ul><?php foreach ($fehler as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <?php require __DIR__ . '/teil_workshopwahl.php'; ?>
        </fieldset>
        <div class="aktionen"><button class="knopf" type="submit">Speichern</button><a class="knopf zweit" href="/deine-anmeldung">Abbrechen</a></div>
    </form>
</div>
<?php seite_fuss(); return; endif; ?>

<?php if ($modus === 'essen' && anm_frist_offen('stichtag_essen')): ?>
<div class="innen abschnitt">
    <form class="form" method="post" action="/deine-anmeldung">
        <?= csrf_feld() ?><input type="hidden" name="aktion" value="essen">
        <fieldset class="feld"><legend>Essen ändern</legend>
            <div class="wahl wahl-reihe"><?php foreach (ANM_ESSEN as $k => $t): ?><label><input type="radio" name="essen" value="<?= e($k) ?>"<?= $a['essen'] === $k ? ' checked' : '' ?>><span><?= e($t) ?></span></label><?php endforeach; ?></div>
        </fieldset>
        <div class="aktionen"><button class="knopf" type="submit">Speichern</button><a class="knopf zweit" href="/deine-anmeldung">Abbrechen</a></div>
    </form>
</div>
<?php seite_fuss(); return; endif; ?>

<?php if ($modus === 'daten'):
    $gruppen = va_liste('berufsgruppen');
    $w = fn(string $k) => e($eingabe !== [] ? (string)($eingabe[$k] ?? '') : (string)($a[$k] ?? '')); ?>
<div class="innen abschnitt">
    <?php if ($fehler !== []): ?><div class="meldung fehler" role="alert">Nicht gespeichert. Bitte prüf die markierten Angaben.</div><?php endif; ?>
    <form class="form" method="post" action="/deine-anmeldung" novalidate>
        <?= csrf_feld() ?><input type="hidden" name="aktion" value="daten">
        <h2 class="zwischen">Deine Daten</h2>
        <div class="paar">
            <div class="feld"><label for="vorname">Vorname</label><input id="vorname" name="vorname" type="text" autocomplete="given-name" maxlength="80" value="<?= $w('vorname') ?>"<?= $ungueltig('vorname') ?>><?= $fehlerFeld('vorname') ?></div>
            <div class="feld"><label for="nachname">Nachname</label><input id="nachname" name="nachname" type="text" autocomplete="family-name" maxlength="80" value="<?= $w('nachname') ?>"<?= $ungueltig('nachname') ?>><?= $fehlerFeld('nachname') ?></div>
        </div>
        <div class="feld"><label for="email">E-Mail</label><?php $teamAdresse = anm_hat_team_konto($a); ?><input id="email" name="email" type="email" autocomplete="email" maxlength="190" value="<?= $teamAdresse ? e($a['email']) : $w('email') ?>"<?= $teamAdresse ? ' readonly' : '' ?><?= $ungueltig('email') ?>><p class="hilfe"><?= $teamAdresse ? 'Das ist die Adresse deines Team-Kontos. ' . e(ANM_TEAM_ADRESSE_TEXT) : 'Eine neue Adresse gilt erst, wenn du den Link bestätigst, den wir dorthin schicken.' ?></p><?= $fehlerFeld('email') ?></div>
        <div class="paar">
            <div class="feld"><label for="berufsgruppe">Berufsgruppe</label>
                <select id="berufsgruppe" name="berufsgruppe" data-efn-gruppe="<?= e($gruppen[0] ?? '') ?>"<?= $ungueltig('berufsgruppe') ?>>
                    <?php $bg = $eingabe !== [] ? (string)($eingabe['berufsgruppe'] ?? '') : $a['berufsgruppe']; foreach ($gruppen as $g): ?><option value="<?= e($g) ?>"<?= $bg === $g ? ' selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?>
                </select><?= $fehlerFeld('berufsgruppe') ?></div>
            <div class="feld"><label for="institution">Klinik / Institution</label><input id="institution" name="institution" type="text" maxlength="160" value="<?= $w('institution') ?>"<?= $ungueltig('institution') ?>><?= $fehlerFeld('institution') ?></div>
        </div>
        <div class="feld" id="sonst-feld"><label for="beruf_sonstige">Sonstige – welche? <span class="meta">(freiwillig)</span></label><input id="beruf_sonstige" name="beruf_sonstige" type="text" maxlength="80" value="<?= $w('beruf_sonstige') ?>"></div>
        <div class="feld" id="efn-feld"><label for="efn">EFN <span class="meta">(freiwillig, für die Fortbildungspunkte)</span></label><input id="efn" name="efn" type="text" inputmode="numeric" autocomplete="off" maxlength="20" value="<?= $w('efn') ?>"<?= $ungueltig('efn') ?>><?= $fehlerFeld('efn') ?></div>
        <div class="aktionen"><button class="knopf" type="submit">Speichern</button><a class="knopf zweit" href="/deine-anmeldung">Abbrechen</a></div>
    </form>
</div>
<?php seite_fuss(); return; endif; ?>

<div class="innen raster abschnitt">
    <div class="spalte">
        <?php foreach ($angebote as $o):
            $tid = (int)$o['termin_id'];
            $vorschau = anm_tausch($id, $o);
            $bestaetigen = $tausch !== null && $tauschPlatz === (int)$o['id']; ?>
        <div class="angebot" id="angebot-<?= (int)$o['id'] ?>">
            <p class="kicker">Platzangebot</p>
            <h2 class="zwischen"><?= e(anm_termin_text($tid)) ?></h2>
            <p>Du standest auf der Warteliste. Der Platz ist für dich reserviert bis</p>
            <p class="uhr"><?= e(datum_zeit_text($o['angebot_bis'])) ?> Uhr</p>
            <?php if ($vorschau !== [] && !$bestaetigen): ?>
            <div class="ersetzt"><p class="kicker">Achtung · Tausch</p><p>Nimmst du an, <strong>fällt dafür weg</strong>:</p>
                <?php foreach ($vorschau as $x): ?><p class="tausch-zeile"><span class="alt mono"><?= e(anm_termin_text((int)$x['termin_id'])) ?></span><span aria-hidden="true">→</span><span class="neu mono"><?= e(anm_termin_text($tid)) ?></span></p><?php endforeach; ?>
                <p class="meta klein">Dein alter Platz geht sofort an die Warteliste.</p></div>
            <?php endif; ?>
            <p class="meta klein">Lässt du die Frist verstreichen, geht das Angebot an die nächste Person und du rückst ans Ende der Warteliste.</p>
            <?php if ($bestaetigen): ?>
            <div class="tausch-bestaetigen" role="alert">
                <p class="kicker">Bitte bestätigen</p>
                <ul class="tausch">
                    <li><span class="chip ok">Wird gebucht</span> <?= e(anm_termin_text($tid)) ?></li>
                    <?php foreach ($tausch as $x): ?><li><span class="chip wl">Wird storniert</span> <?= e(anm_termin_text((int)$x['termin_id'])) ?> <span class="meta">– <?= e($x['grund']) ?>, Platz geht an die Warteliste</span></li><?php endforeach; ?>
                </ul>
                <p class="meta klein">Das lässt sich nicht rückgängig machen: Die freigegebenen Plätze werden sofort weitervergeben.</p>
                <div class="aktionen">
                    <form method="post" action="/deine-anmeldung"><?= csrf_feld() ?><input type="hidden" name="aktion" value="annehmen"><input type="hidden" name="platz" value="<?= (int)$o['id'] ?>"><input type="hidden" name="tausch" value="1"><button class="knopf" type="submit">Ja, so tauschen</button></form>
                    <a class="knopf zweit" href="/deine-anmeldung">Abbrechen</a>
                </div>
            </div>
            <?php else: ?>
            <div class="aktionen">
                <form method="post" action="/deine-anmeldung"><?= csrf_feld() ?><input type="hidden" name="aktion" value="annehmen"><input type="hidden" name="platz" value="<?= (int)$o['id'] ?>"><button class="knopf" type="submit">Platz annehmen</button></form>
                <form method="post" action="/deine-anmeldung"><?= csrf_feld() ?><input type="hidden" name="aktion" value="ablehnen"><input type="hidden" name="platz" value="<?= (int)$o['id'] ?>"><button class="knopf zweit" type="submit">Ablehnen</button></form>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div class="kasten">
            <h2 class="zwischen klein-zwischen"><?= va_an('mit_essen') ? 'Workshops und Essen' : 'Workshops' ?></h2>
            <dl class="liste-def">
                <?php if ($sym !== 'fest'):
                    $sp = array_values(array_filter($plaetze, fn($p) => (int)$p['termin_id'] === 0))[0] ?? null;
                    $r = $sp !== null ? anm_platz_zeile($sp) : null; ?>
                <div><dt>Symposium</dt><dd><?= $r !== null ? '<span class="chip wl">' . e($r['status']) . '</span>' : '' ?><br><span class="meta klein">Workshops wählst du, sobald du einen Platz hast.</span></dd></div>
                <?php endif; ?>
                <?php $hatWs = false; foreach ($plaetze as $p): if ((int)$p['termin_id'] === 0) continue; $hatWs = true;
                    $t = anm_termin((int)$p['termin_id']); $r = anm_platz_zeile($p); ?>
                <div><dt class="mono"><?= e($t !== null ? $t['beginn'] . '–' . $t['ende'] : '–') ?></dt><dd><?= e($t !== null ? trim($t['workshop']['kuerzel'] . ' ' . $t['workshop']['titel']) : $r['text']) ?><br><span class="chip <?= $p['status'] === 'fest' ? 'ok' : 'wl' ?>"><?= e($p['status'] === 'angebot' ? 'Angebot offen, siehe oben' : $r['status']) ?></span></dd></div>
                <?php endforeach; ?>
                <?php if (!$hatWs && $sym === 'fest'): ?><div><dt>Workshops</dt><dd class="meta">Keine gewählt.</dd></div><?php endif; ?>
                <?php if (va_an('mit_essen')): ?><div><dt>Essen</dt><dd><?= e(ANM_ESSEN[$a['essen']] ?? $a['essen']) ?></dd></div><?php endif; ?>
            </dl>
            <div class="aktionen abstand">
                <?php if ($sym === 'fest' && anm_workshops() !== []): ?>
                <div class="aktion-frist"><?php if (anm_frist_offen('stichtag_workshop')): ?><a class="knopf" href="/deine-anmeldung?aendern=workshops">Workshops ändern</a><?php else: ?><span class="knopf gesperrt" aria-disabled="true">Workshops ändern</span><?php endif; ?><span class="frist"><?= $fristText('stichtag_workshop') ?></span></div>
                <?php endif; ?>
                <?php if (va_an('mit_essen')): ?><div class="aktion-frist"><?php if (anm_frist_offen('stichtag_essen')): ?><a class="knopf zweit" href="/deine-anmeldung?aendern=essen">Essen ändern</a><?php else: ?><span class="knopf zweit gesperrt" aria-disabled="true">Essen ändern</span><?php endif; ?><span class="frist"><?= $fristText('stichtag_essen') ?></span></div><?php endif; ?>
            </div>
        </div>

        <div class="kasten">
            <h2 class="zwischen klein-zwischen">Deine Daten</h2>
            <dl class="liste-def">
                <div><dt>Name</dt><dd><?= e($a['vorname'] . ' ' . $a['nachname']) ?></dd></div>
                <div><dt>E-Mail</dt><dd><?= e($a['email']) ?></dd></div>
                <div><dt>Berufsgruppe</dt><dd><?= e($a['berufsgruppe'] . ($a['beruf_sonstige'] !== '' ? ' (' . $a['beruf_sonstige'] . ')' : '')) ?></dd></div>
                <div><dt>Institution</dt><dd><?= e($a['institution']) ?></dd></div>
                <?php if ($a['berufsgruppe'] === (va_liste('berufsgruppen')[0] ?? '') && va_an('mit_cme')): ?><div><dt>EFN</dt><dd class="mono"><?= $a['efn'] !== null ? e(efn_text($a['efn'])) : '<span class="meta">– nicht angegeben</span>' ?></dd></div><?php endif; ?>
                <div><dt>Newsletter</dt><dd><?= (int)$a['newsletter'] === 1 ? 'gewünscht (Bestätigung folgt, sobald er startet)' : 'nein' ?></dd></div>
            </dl>
            <div class="aktionen abstand"><div class="aktion-frist"><a class="knopf zweit" href="<?= $a['konto_id'] !== null && tk_aktuell() !== null ? '/konto?aendern=profil' : '/deine-anmeldung?aendern=daten' ?>">Daten bearbeiten</a><span class="frist">jederzeit · neue E-Mail erst nach Bestätigung</span></div></div>
        </div>
    </div>
    <div class="spalte">
        <?php $tk = tk_aktuell(); if ($tk !== null): ?>
        <div class="kasten"><strong>Mit Konto eingeloggt</strong><p class="meta klein">Profil, Adresse und Passwort änderst du unter „Mein Konto“. Bist du 30 Minuten nicht aktiv, loggst du dich neu ein.</p><p><a class="knopf zweit" href="/konto">Mein Konto</a></p></div>
        <?php else: ?>
        <div class="kasten"><strong>Wie du hierher kommst</strong><p class="meta klein">Du hast einen Link aus deiner Mail geöffnet. Er funktioniert nur einmal. Bist du 30 Minuten nicht aktiv, lass dir unter „Deine Anmeldung“ einen neuen schicken.</p></div>
        <?php if ($a['status'] === 'bestaetigt' && $a['konto_id'] === null && (konto_per_email($a['email'])['rolle'] ?? 'teilnehmend') === 'teilnehmend'): ?>
        <div class="kasten"><strong>Konto anlegen?</strong><p>Mit Konto brauchst du keinen Link per Mail mehr. Deine Anmeldung wird übernommen; du legst nur ein Passwort fest.</p>
            <form method="post" action="/deine-anmeldung"><?= csrf_feld() ?><input type="hidden" name="aktion" value="konto_anlegen"><button class="knopf zweit" type="submit">Konto anlegen</button></form></div>
        <?php endif; endif; ?>
        <?php if ($a['status'] === 'bestaetigt' && zertifikat_verfuegbar($a)): ?>
        <div class="kasten akzent-kasten"><strong>Deine Teilnahmebescheinigung</strong><p>Danke, dass du dabei warst! Hier ist dein Zertifikat als PDF – mit deinen Workshops<?= va('cme_punkte') !== '' ? ' und den Fortbildungspunkten' : '' ?>.</p>
            <p><a class="knopf" href="/deine-anmeldung/zertifikat">Zertifikat herunterladen (PDF)</a></p></div>
        <?php else: ?>
        <div class="kasten"><strong>Teilnahmebescheinigung</strong><p class="meta klein">Gibt es nach dem Symposium. Sobald sie freigeschaltet ist, bekommst du eine Mail und lädst sie hier herunter.</p></div>
        <?php endif; ?>
        <div class="kasten"><strong>Doch nicht dabei?</strong><p>Sag bitte ab – dein Platz geht an jemanden von der Warteliste.</p>
            <?php if (anm_frist_offen('stichtag_storno')): ?>
            <details class="bestaetigen"><summary class="knopf gefahr">Anmeldung stornieren</summary>
                <form method="post" action="/deine-anmeldung"><?= csrf_feld() ?><input type="hidden" name="aktion" value="storno">
                    <p>Deine Anmeldung und alle Plätze werden gelöscht. Das lässt sich nicht rückgängig machen.</p>
                    <button type="submit" class="knopf gefahr">Ja, stornieren</button></form>
            </details>
            <?php endif; ?>
            <p class="frist"><?= $fristText('stichtag_storno') ?></p>
        </div>
    </div>
</div>
<?php
seite_fuss();
