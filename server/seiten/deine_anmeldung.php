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
$aktion = aktion();
$modus = (string)($_GET['aendern'] ?? ''); // Ansicht: workshops, essen, daten oder Übersicht
$fehler = [];
$tausch = null;
$tauschPlatz = 0;
$eingabe = [];

if (ist_post()) {
    if ($aktion === 'ausloggen') {
        anm_ausloggen();
        fertig('ok', 'Du bist ausgeloggt.', '/deine-anmeldung');
    }
    if ($aktion === 'einloggen') {
        // Gemeinsamer Login (E-48): Teilnehmende hier, Team weiter zum zweiten Faktor
        $r = tk_login_pruefen(trim(post_text('email')), post_text('passwort'));
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
        $email = mail_adresse(post_text('email'));
        if ($email === null) {
            $fehler['email'] = 'Diese E-Mail-Adresse sieht nicht wie eine Adresse aus.';
        } else {
            $ip = rate_merkmal_ip();
            $ziel = rate_merkmal_kennung('link:' . $email);
            $ok = trim(post_text('website')) === '' && rate_erlaubt('link_ip', $ip) && rate_erlaubt('link_ziel', $ziel);
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
            fertig('ok', 'Wenn die Adresse bei uns angemeldet ist, ist der Link unterwegs. Er gilt ' . dauer_text(ANM_LINK_GUELTIG_S) . ' und einmal. Schau auch im Spam-Ordner.', '/deine-anmeldung');
        }
    } elseif ($a === null) {
        fertig('fehler', 'Deine Sitzung ist abgelaufen. Lass dir einen neuen Link schicken.', '/deine-anmeldung');
    } elseif ($nurAnsehen && $aktion !== 'konto_anlegen') {
        fertig('fehler', 'Diese Veranstaltung ist vorbei – hier lässt sich nichts mehr ändern.', '/deine-anmeldung');
    } else {
        $id = (int)$a['id'];
        switch ($aktion) {
            case 'bestaetigung_neu':
                if ($a['status'] === 'unbestaetigt') {
                    $bis = strtotime($a['bestaetigen_bis'] . ' UTC');
                    $token = anm_link_erzeugen($id, 'bestaetigen', $bis);
                    mail_vorlage_einreihen($a['email'], 'bestaetigen', ['vorname' => $a['vorname'], 'link' => anm_link_url($token), 'gueltig' => datum_zeit_text($a['bestaetigen_bis']), 'uebersicht' => anm_uebersicht_text($id)]);
                    fertig('ok', 'Die Bestätigungsmail ist noch einmal unterwegs.', '/deine-anmeldung');
                }
                umleiten('/deine-anmeldung');
            case 'workshops':
                if (!anm_frist_offen('stichtag_workshop')) {
                    fertig('fehler', 'Workshops kannst du nicht mehr selbst ändern. Schreib uns, wir helfen.', '/deine-anmeldung');
                }
                $fehler = anm_workshops_setzen($id, anm_wahl_lesen($_POST));
                if ($fehler === []) {
                    fertig('ok', 'Deine Workshops sind gespeichert. Du bekommst eine Mail mit der Übersicht.', '/deine-anmeldung');
                }
                $modus = 'workshops';
                $eingabe = $_POST;
                break;
            case 'essen':
                if (!anm_frist_offen('stichtag_essen')) {
                    fertig('fehler', 'Das Essen kannst du nicht mehr selbst ändern.', '/deine-anmeldung');
                }
                if (anm_essen_setzen($id, post_text('essen'))) {
                    fertig('ok', 'Deine Essenswahl ist gespeichert.', '/deine-anmeldung');
                }
                umleiten('/deine-anmeldung');
            case 'daten':
                $r = anm_daten_setzen($id, $_POST);
                if ($r['fehler'] === [] && $r['email_gesperrt']) {
                    fertig('fehler', 'Deine übrigen Daten sind gespeichert, die Adresse nicht: ' . ANM_ADRESSE_RATE_TEXT, '/deine-anmeldung');
                }
                if ($r['fehler'] === []) {
                    fertig('ok', $r['email_link'] ? 'Gespeichert. An die neue Adresse ist ein Link unterwegs; erst damit gilt sie. Bis dahin bleibt die bisherige.' : 'Deine Daten sind gespeichert.', '/deine-anmeldung');
                }
                $fehler = $r['fehler'];
                $eingabe = $_POST;
                $modus = 'daten';
                break;
            case 'storno':
                if (!anm_frist_offen('stichtag_storno')) {
                    fertig('fehler', 'Absagen geht nicht mehr selbst. Schreib uns bitte, wenn du nicht kommen kannst.', '/deine-anmeldung');
                }
                anm_stornieren($id);
                // Nur die Anmeldung aus der Sitzung nehmen; Konto- und Team-Login bleiben (E-63 (3), Review B-5)
                unset($_SESSION[SITZUNG_SCHLUESSEL_ANM]);
                fertig('ok', 'Deine Anmeldung ist storniert. Danke, dass du abgesagt hast – dein Platz geht an die Warteliste.', '/deine-anmeldung');
            case 'annehmen':
                $r = anm_angebot_annehmen($id, post_zahl('platz'), post_haken('tausch'));
                if (isset($r['ok'])) {
                    fertig('ok', 'Der Platz ist deiner. Du bekommst eine Mail mit der Übersicht.', '/deine-anmeldung');
                }
                if (isset($r['fehler'])) {
                    fertig('fehler', $r['fehler'], '/deine-anmeldung');
                }
                $tausch = $r['tausch'];
                $tauschPlatz = post_zahl('platz');
                break;
            case 'konto_anlegen':
                $kid = tk_anlegen_aus_anmeldung($id);
                if ($kid === null) {
                    fertig('fehler', 'Für diese Adresse lässt sich hier kein Konto anlegen.', '/deine-anmeldung');
                }
                tk_einloggen(konto_laden($kid));
                $_SESSION[SITZUNG_SCHLUESSEL_TK_PW] = true;
                fertig('ok', 'Dein Konto ist angelegt. Leg jetzt dein Passwort fest.', '/konto');
            case 'ablehnen':
                anm_angebot_ablehnen($id, post_zahl('platz'));
                fertig('ok', 'Angebot abgelehnt. Der Platz geht an die nächste Person.', anm_laden($id) === null ? '/' : '/deine-anmeldung');
        }
    }
}

$a = anm_aktuell();
$meldung = flash_holen();

/* ---- Einstieg ohne Sitzung ---- */
if ($a === null) {
    seite_kopf('Anmelden', ['aktiv' => '/deine-anmeldung']);
    $zr = anm_zeitraum();
    $kap = anm_kapazitaet(0);
    echo seiten_kopfzeile('Wie willst du rein?', $zr === 'geschlossen' ? 'Derzeit keine Veranstaltung' : va_kurz() . ' · ' . tag_text(va('veranstaltung_datum')),
        'Ein Konto brauchst du nicht. Mit Konto sparst du dir nur die Links per Mail.');
    ?>
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
            <p class="meta"><?= $zr === 'geschlossen' ? 'Derzeit gibt es keine Veranstaltung.' : 'Die Anmeldung ist geschlossen.' ?></p>
        <?php endif; ?>
    </div>
</div>
<?php
    seite_ende();
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
                <p class="meta"><?= $zr === 'geschlossen' ? 'Derzeit gibt es keine Veranstaltung.' : 'Die Anmeldung ist geschlossen.' ?></p>
            <?php endif; ?>
        </div>
        <div class="kasten zg">
            <p class="kicker">Schon angemeldet</p>
            <h2 class="zwischen">Link per Mail</h2>
            <p>Gib deine Adresse ein. Wenn wir sie kennen, schicken wir dir einen Link, mit dem du deine Anmeldung ansiehst und änderst. Er gilt <?= e(dauer_text(ANM_LINK_GUELTIG_S)) ?> und nur einmal.</p>
            <form method="post" action="/deine-anmeldung" class="zg-form" novalidate>
                <?= csrf_feld() ?><?= versteckt_html(['aktion' => 'link']) ?>
                <?php // Honigtopf von Hand: Rahmen mit aria-hidden, ohne Klasse feld ?>
                <div class="honig" aria-hidden="true"><label for="website">Website (bitte leer lassen)</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
                <?= feld('email', 'E-Mail', ['typ' => 'email', 'id' => 'zg-mail', 'pflicht' => true, 'fehler' => $fehler['email'] ?? null, 'attr' => ['autocomplete' => 'email']]) ?>
                <button class="knopf" type="submit">Link schicken</button>
            </form>
        </div>
<?php endif; ?>
        <div class="kasten zg">
            <p class="kicker">Mit Konto</p>
            <h2 class="zwischen">Einloggen</h2>
            <form method="post" action="/deine-anmeldung" class="zg-form" novalidate>
                <?= csrf_feld() ?><?= versteckt_html(['aktion' => 'einloggen']) ?>
                <?php if (isset($fehler['login'])): // Fehler zum ganzen Login, keinem Feld zugeordnet ?><p class="feldfehler" role="alert"><?= e($fehler['login']) ?></p><?php endif; ?>
                <?= feld('email', 'E-Mail', ['typ' => 'email', 'id' => 'zg-lmail', 'pflicht' => true, 'attr' => ['autocomplete' => 'username']]) ?>
                <?= feld('passwort', 'Passwort', ['typ' => 'passwort', 'id' => 'zg-pw', 'pflicht' => true, 'attr' => ['autocomplete' => 'current-password']]) ?>
                <div class="aktionen"><button class="knopf" type="submit">Einloggen</button><a class="klein-link" href="/konto/passwort-vergessen">Passwort vergessen?</a></div>
            </form>
            <p class="meta klein">Noch kein Konto? <a href="/konto/neu">Konto anlegen</a></p>
        </div>
    </div>
</div>
<?php
    seite_ende();
}

/* ---- Mit Sitzung ---- */
$id = (int)$a['id'];
$plaetze = anm_plaetze($id);
$sym = anm_symposium_status($id);
$angebote = anm_angebote($id);
$fristText = function (string $schluessel): string {
    $tag = anm_stichtag($schluessel);
    return anm_frist_offen($schluessel) ? 'möglich bis <b>' . e(tag_text($tag)) . '</b>' : 'nur noch über das Team (Stichtag war ' . e(tag_text($tag)) . ')';
};
$status = $a['status'] === 'unbestaetigt' ? 'Noch nicht bestätigt' : ($sym === 'fest' ? 'Anmeldung bestätigt' : ($sym === 'angebot' ? 'Platzangebot' : 'Warteliste'));

seite_kopf('Meine Anmeldung', ['aktiv' => '/deine-anmeldung', 'teilnehmer' => $a, 'skripte' => ['anmelden.js']]);
echo seiten_kopfzeile('Hallo ' . $a['vorname'] . '.', 'Deine Anmeldung · ' . va_kurz() . ' · ' . tag_text(va('veranstaltung_datum')), '', true);
?>
    <div class="status-band"><span class="sticker<?= $a['status'] === 'unbestaetigt' || $sym !== 'fest' ? ' hinweis' : '' ?>"><?= e($status) ?></span><span class="mono">Anmeldung Nr. <?= anm_nummer((int)$id) ?></span></div>
</div>
<div class="innen">
    <?= meldung_html($meldung) ?>
    <?php if ($nurAnsehen): ?><p class="va-archiv" role="status"><strong>Diese Veranstaltung ist vorbei.</strong> Du siehst deine Anmeldung nur noch an<?= va_aktuell_id() !== null ? '; zur aktuellen Veranstaltung geht es über die <a href="/">Startseite</a>' : '' ?>.</p><?php endif; ?>
    <?php if ($a['status'] === 'unbestaetigt'): ?>
    <?= meldung_html('Anmeldung unbestätigt', 'fehler', ['text_html' => 'Bitte bestätige deine Anmeldung über den Link in der Mail – bis ' . e(datum_zeit_text($a['bestaetigen_bis'])) . ' Uhr. Danach verfällt sie und die Plätze gehen an andere.'
        . aktion_knopf('/deine-anmeldung', 'bestaetigung_neu', [], 'Mail noch einmal schicken', 'link-knopf', ['form_klasse' => 'inline'])]) ?>
    <?php endif; ?>
</div>

<?php if ($modus === 'workshops' && $sym === 'fest' && anm_frist_offen('stichtag_workshop')):
    $wahl = $eingabe !== [] ? anm_wahl_lesen($eingabe) : anm_wahl_aus_plaetzen($plaetze);
    $bisher = array_values(array_filter($plaetze, fn($p) => (int)$p['termin_id'] !== 0)); ?>
<div class="innen abschnitt">
    <form class="form" method="post" action="/deine-anmeldung" novalidate>
        <?= csrf_feld() ?><?= versteckt_html(['aktion' => 'workshops']) ?>
        <fieldset class="feld" id="workshops"><legend>Workshops ändern</legend>
            <p class="hilfe">Gibst du einen festen Platz ab, geht er sofort an die Warteliste. Je Workshop ein fester Termin; Wartelisten für volle Termine.</p>
            <?= fehler_html($fehler) ?>
            <?= anm_workshopwahl_html($wahl, $bisher) ?>
        </fieldset>
        <div class="aktionen"><button class="knopf" type="submit">Speichern</button><a class="knopf zweit" href="/deine-anmeldung">Abbrechen</a></div>
    </form>
</div>
<?php seite_ende(); endif; ?>

<?php if ($modus === 'essen' && anm_frist_offen('stichtag_essen')): ?>
<div class="innen abschnitt">
    <form class="form" method="post" action="/deine-anmeldung">
        <?= csrf_feld() ?><?= versteckt_html(['aktion' => 'essen']) ?>
        <?= feld('essen', 'Essen ändern', ['typ' => 'radio-reihe', 'optionen' => ANM_ESSEN, 'wert' => $a['essen']]) ?>
        <div class="aktionen"><button class="knopf" type="submit">Speichern</button><a class="knopf zweit" href="/deine-anmeldung">Abbrechen</a></div>
    </form>
</div>
<?php seite_ende(); endif; ?>

<?php if ($modus === 'daten'):
    $werte = $eingabe !== [] ? $eingabe : $a;
    if (anm_hat_team_konto($a)) {
        // Adresse des Team-Kontos fest (E-63 (2))
        $profil = profil_felder_html(['email' => $a['email']] + $werte, $fehler, ['email' => 'fest', 'email_hilfe' => 'Das ist die Adresse deines Team-Kontos. ' . ANM_TEAM_ADRESSE_TEXT]);
    } else {
        $profil = profil_felder_html($werte, $fehler, ['email_hilfe' => 'Eine neue Adresse gilt erst, wenn du den Link bestätigst, den wir dorthin schicken.']);
    } ?>
<div class="innen abschnitt">
    <?= $fehler !== [] ? meldung_html(NICHT_GESPEICHERT_TEXT, 'fehler') : '' ?>
    <form class="form" method="post" action="/deine-anmeldung" novalidate>
        <?= csrf_feld() ?><?= versteckt_html(['aktion' => 'daten']) ?>
        <h2 class="zwischen">Deine Daten</h2>
        <?= $profil ?>
        <div class="aktionen"><button class="knopf" type="submit">Speichern</button><a class="knopf zweit" href="/deine-anmeldung">Abbrechen</a></div>
    </form>
</div>
<?php seite_ende(); endif; ?>

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
                    <li><?= chip('ok', 'Wird gebucht') ?> <?= e(anm_termin_text($tid)) ?></li>
                    <?php foreach ($tausch as $x): ?><li><?= chip('wl', 'Wird storniert') ?> <?= e(anm_termin_text((int)$x['termin_id'])) ?> <span class="meta">– <?= e($x['grund']) ?>, Platz geht an die Warteliste</span></li><?php endforeach; ?>
                </ul>
                <p class="meta klein">Das lässt sich nicht rückgängig machen: Die freigegebenen Plätze werden sofort weitervergeben.</p>
                <div class="aktionen">
                    <?= aktion_knopf('/deine-anmeldung', 'annehmen', ['platz' => (int)$o['id'], 'tausch' => 1], 'Ja, so tauschen', 'knopf') ?>
                    <a class="knopf zweit" href="/deine-anmeldung">Abbrechen</a>
                </div>
            </div>
            <?php else: ?>
            <div class="aktionen">
                <?= aktion_knopf('/deine-anmeldung', 'annehmen', ['platz' => (int)$o['id']], 'Platz annehmen', 'knopf') ?>
                <?= aktion_knopf('/deine-anmeldung', 'ablehnen', ['platz' => (int)$o['id']], 'Ablehnen', 'knopf zweit') ?>
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
                <div><dt>Symposium</dt><dd><?= $r !== null ? chip('wl', $r['status']) : '' ?><br><span class="meta klein">Workshops wählst du, sobald du einen Platz hast.</span></dd></div>
                <?php endif; ?>
                <?php $hatWs = false; foreach ($plaetze as $p): if ((int)$p['termin_id'] === 0) continue; $hatWs = true;
                    $t = anm_termin((int)$p['termin_id']); $r = anm_platz_zeile($p); ?>
                <div><dt class="mono"><?= e($t !== null ? $t['beginn'] . '–' . $t['ende'] : '–') ?></dt><dd><?= e($t !== null ? trim($t['workshop']['kuerzel'] . ' ' . $t['workshop']['titel']) : $r['text']) ?><br><?= chip(PLATZ_STATUS_CHIP[$p['status']] ?? 'wl', $p['status'] === 'angebot' ? 'Angebot offen, siehe oben' : $r['status']) ?></dd></div>
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
                <div><dt>Name</dt><dd><?= e(person_name($a)) ?></dd></div>
                <div><dt>E-Mail</dt><dd><?= e($a['email']) ?></dd></div>
                <div><dt>Berufsgruppe</dt><dd><?= e($a['berufsgruppe'] . ($a['beruf_sonstige'] !== '' ? ' (' . $a['beruf_sonstige'] . ')' : '')) ?></dd></div>
                <div><dt>Institution</dt><dd><?= e($a['institution']) ?></dd></div>
                <?php if ($a['berufsgruppe'] === (va_liste('berufsgruppen')[0] ?? '') && va_an('mit_cme')): ?><div><dt>EFN</dt><dd class="mono"><?= $a['efn'] !== null ? e(efn_text($a['efn'])) : '<span class="meta">– nicht angegeben</span>' ?></dd></div><?php endif; ?>
                <?php // Newsletter-Wunsch (AP-19): Reihe und Allgäu Akut mit Interessen
                $nlWunsch = array_filter([(int)$a['newsletter'] === 1 ? 'Newsletter der Reihe' : '', (string)$a['nl_interessen'] !== '' ? 'Allgäu Akut (' . nl_interessen_text((string)$a['nl_interessen']) . ')' : '']); ?>
                <div><dt>Newsletter</dt><dd><?= $nlWunsch === [] ? 'nein' : e(implode(', ', $nlWunsch)) . ' <span class="meta">– bestätigst du per Mail</span>' ?></dd></div>
            </dl>
            <div class="aktionen abstand"><div class="aktion-frist"><a class="knopf zweit" href="<?= $a['konto_id'] !== null && tk_aktuell() !== null ? '/konto?aendern=profil' : '/deine-anmeldung?aendern=daten' ?>">Daten bearbeiten</a><span class="frist">jederzeit · neue E-Mail erst nach Bestätigung</span></div></div>
        </div>
    </div>
    <div class="spalte">
        <?php $tk = tk_aktuell(); if ($tk !== null): ?>
        <div class="kasten"><strong>Mit Konto eingeloggt</strong><p class="meta klein">Profil, Adresse und Passwort änderst du unter „Mein Konto“. Bist du <?= e(dauer_text(SITZUNG_LEERLAUF_S)) ?> nicht aktiv, loggst du dich neu ein.</p><p><a class="knopf zweit" href="/konto">Mein Konto</a></p></div>
        <?php else: ?>
        <div class="kasten"><strong>Wie du hierher kommst</strong><p class="meta klein">Du hast einen Link aus deiner Mail geöffnet. Er funktioniert nur einmal. Bist du <?= e(dauer_text(SITZUNG_LEERLAUF_S)) ?> nicht aktiv, lass dir unter „Deine Anmeldung“ einen neuen schicken.</p></div>
        <?php if ($a['status'] === 'bestaetigt' && $a['konto_id'] === null && (konto_per_email($a['email'])['rolle'] ?? 'teilnehmend') === 'teilnehmend'): ?>
        <div class="kasten"><strong>Konto anlegen?</strong><p>Mit Konto brauchst du keinen Link per Mail mehr. Deine Anmeldung wird übernommen; du legst nur ein Passwort fest.</p>
            <?= aktion_knopf('/deine-anmeldung', 'konto_anlegen', [], 'Konto anlegen', 'knopf zweit') ?></div>
        <?php endif; endif; ?>
        <?php if ($a['status'] === 'bestaetigt' && zertifikat_verfuegbar($a)): ?>
        <div class="kasten akzent-kasten"><strong>Deine Teilnahmebescheinigung</strong><p>Danke, dass du dabei warst! Hier ist dein Zertifikat als PDF – mit deinen Workshops<?= va('cme_punkte') !== '' ? ' und den Fortbildungspunkten' : '' ?>.</p>
            <p><a class="knopf" href="/deine-anmeldung/zertifikat">Zertifikat herunterladen (PDF)</a></p></div>
        <?php else: ?>
        <div class="kasten"><strong>Teilnahmebescheinigung</strong><p class="meta klein">Gibt es nach dem Symposium. Sobald sie freigeschaltet ist, bekommst du eine Mail und lädst sie hier herunter.</p></div>
        <?php endif; ?>
        <div class="kasten"><strong>Doch nicht dabei?</strong><p>Sag bitte ab – dein Platz geht an jemanden von der Warteliste.</p>
            <?php if (anm_frist_offen('stichtag_storno')): ?>
            <?= bestaetigung_html('Anmeldung stornieren', 'Deine Anmeldung und alle Plätze werden gelöscht. Das lässt sich nicht rückgängig machen.', 'Ja, stornieren',
                ['aktion' => 'storno'], ['ziel' => '/deine-anmeldung', 'knopfklasse' => 'knopf gefahr']) ?>
            <?php endif; ?>
            <p class="frist"><?= $fristText('stichtag_storno') ?></p>
        </div>
    </div>
</div>
<?php
seite_fuss();
