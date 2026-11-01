<?php
declare(strict_types=1);

/*
 * Anmeldung zum Symposium (AP-4, Mockup „Anmelden“; E-10, E-14, E-20, E-21,
 * E-22, E-38, E-46).
 *
 * Sichtbar gemeldet werden nur Eingabefehler (Vertipper). Greift eine Bremse
 * des Spamschutzes oder ist die Adresse schon angemeldet, kommt dieselbe
 * Antwort wie bei Erfolg (R-03); im zweiten Fall bekommt die Adresse statt
 * einer zweiten Anmeldung den Link zur bestehenden (E-46).
 */

anm_sitzung();
$beginn = microtime(true);
$zeitraum = anm_zeitraum();
$tn = anm_aktuell();
$tk = tk_aktuell(); // mit Konto eingeloggt (AP-4b): Angaben vorausgefüllt, keine Bestätigungsmail
$fehler = [];
$wsFehler = [];
$eingabe = [];
if ($tk !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $eingabe = tk_namen($tk) + ['berufsgruppe' => $tk['berufsgruppe'], 'beruf_sonstige' => $tk['beruf_sonstige'], 'institution' => $tk['institution'], 'efn' => (string)$tk['efn']];
} elseif (($vorlage = anm_vorlage()) !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    // Wiederkehrende ohne Konto (E-58): Daten der früheren Anmeldung über den Link aus der Mail
    $eingabe = $vorlage;
}
$wahl = ['fest' => [], 'warte' => []];
$danke = false;
$voll = anm_frei(0) <= 0;
$gruppen = va_liste('berufsgruppen');

if ($zeitraum === 'offen' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $eingabe = $_POST;
    $r = anm_daten_pruefen($tk !== null ? ['email' => $tk['email']] + $_POST : $_POST);
    $fehler = $r['fehler'];
    $wahl = anm_wahl_lesen($_POST);
    if (empty($_POST['einwilligung'])) {
        $fehler['einwilligung'] = 'Ohne dein Einverständnis zur Verarbeitung können wir dich nicht anmelden.';
    }
    if (!$voll) {
        $wsFehler = anm_wahl_pruefen($wahl);
    }
    if ($fehler === [] && $wsFehler === []) {
        // Ab hier keine Auskunft mehr: jede Absendung zählt, Bremsen antworten wie Erfolg.
        $ip = rate_merkmal_ip();
        $mensch = spam_ok($_POST) && rate_erlaubt('anmelden', $ip) && !spam_wegwerf($r['werte']['email']);
        rate_misserfolg('anmelden', $ip);
        if ($mensch) {
            $erg = anm_anlegen($r['werte'], $wahl, !empty($_POST['newsletter']), null, !empty($_POST['konto']), $tk);
            if ($erg['art'] !== 'fehler') {
                unset($_SESSION['anm_vorlage']); // Vorlage „Daten übernehmen“ ist verbraucht (Review B-13)
            }
            if ($erg['art'] === 'fehler') {
                $wsFehler = $erg['fehler'];
            } elseif ($erg['art'] === 'neu' && !empty($erg['bestaetigt'])) {
                anm_einloggen($erg['id']);
                flash_setzen('ok', 'Du bist angemeldet. Die Übersicht kommt auch per Mail.');
                umleiten('/deine-anmeldung');
            } else {
                if ($erg['art'] === 'vorhanden') {
                    // Gleiche Grenze je Adresse wie bei „Link per Mail“ (keine Mailflut an Fremde)
                    $ziel = rate_merkmal_kennung('link:' . $r['werte']['email']);
                    if (rate_erlaubt('link_ziel', $ziel)) {
                        anm_zugang_senden($erg['id'], 'schon_angemeldet');
                    }
                    rate_misserfolg('link_ziel', $ziel);
                }
                $danke = true;
            }
        } else {
            $danke = true;
        }
        if ($danke) {
            rate_gleiche_dauer($beginn, 0.6);
        }
    }
}

$v = fn(string $k) => e(is_string($eingabe[$k] ?? null) ? $eingabe[$k] : '');
$fehlerFeld = fn(string $k) => isset($fehler[$k]) ? '<p class="feldfehler" id="f-' . $k . '">' . e($fehler[$k]) . '</p>' : '';
$ungueltig = fn(string $k) => isset($fehler[$k]) ? ' aria-invalid="true" aria-describedby="f-' . $k . '"' : '';
$tagKurz = fn(string $tag) => tag_text($tag);

seite_kopf('Anmelden', ['aktiv' => '/anmelden', 'skripte' => ['anmelden.js', 'pow.js']]);
?>
<div class="innen form-kopf">
    <p class="kicker"><?= e(va_kurz()) ?> · <?= e(tag_text(va('veranstaltung_datum'))) ?></p>
    <h1 class="titel">Anmelden</h1>
<?php if ($danke): ?>
    <div class="kasten danke" role="status">
        <p class="kicker">Fast geschafft</p>
        <h2 class="zwischen">Schau in dein Postfach.</h2>
        <p>Wir haben dir eine Mail mit einem Bestätigungslink geschickt. <strong>Erst mit dem Klick darauf gilt deine Anmeldung.</strong> Der Link gilt <?= va_zahl('anmeldung_bestaetigung_h') ?> Stunden; so lange halten wir deine Plätze frei.</p>
        <p class="meta">Keine Mail da? Schau auch im Spam-Ordner. Hast du dich mit dieser Adresse schon einmal angemeldet, bekommst du stattdessen einen Link zu deiner bestehenden Anmeldung.</p>
    </div>
</div>
<?php seite_fuss(); return; endif; ?>
<?php if ($zeitraum !== 'offen'): ?>
    <p class="text"><?= $zeitraum === 'vorher'
        ? 'Die Anmeldung öffnet am ' . e(datum_zeit_text(va('anmeldung_start'))) . ' Uhr.'
        : ($zeitraum === 'geschlossen' ? 'Derzeit gibt es keine Veranstaltung, zu der du dich anmelden kannst.' : 'Die Anmeldung ist geschlossen.') ?></p>
    <p><a href="/deine-anmeldung">Schon angemeldet? Zu deiner Anmeldung</a></p>
</div>
<?php seite_fuss(); return; endif; ?>
    <p class="text">Dauert zwei Minuten. Danach bekommst du eine Mail mit einem Bestätigungslink – erst damit gilt deine Anmeldung.</p>
    <div class="stichtage">
        <span>Anmeldeschluss <?= e(datum_text(anm_schluss_utc())) ?></span>
        <span>Workshops ändern bis <?= e($tagKurz(anm_stichtag('stichtag_workshop'))) ?></span>
        <?php if (va_an('mit_essen')): ?><span>Essen ändern bis <?= e($tagKurz(anm_stichtag('stichtag_essen'))) ?></span><?php endif; ?>
        <span>Absagen bis <?= e($tagKurz(anm_stichtag('stichtag_storno'))) ?></span>
    </div>
</div>
<div class="innen">
    <?php if ($tn !== null): ?><div class="meldung hinweis">Du bist schon angemeldet. <a href="/deine-anmeldung">Zu deiner Anmeldung</a></div><?php endif; ?>
    <?php if ($voll): ?><div class="merke warteliste-hinweis"><p class="kicker">Gerade voll</p><p>Alle Plätze sind vergeben. Melde dich trotzdem an: Du kommst auf die Warteliste, und wird ein Platz frei, bieten wir ihn dir per Mail an. Workshops wählst du, sobald du einen Platz hast.</p></div><?php endif; ?>
    <?php if ($fehler !== [] || $wsFehler !== []): ?><div class="meldung fehler" role="alert">Noch nicht angemeldet. Bitte prüf die markierten Angaben<?= $wsFehler !== [] ? ' und deine Workshop-Wahl' : '' ?>.</div><?php endif; ?>
    <form class="form" method="post" action="/anmelden" novalidate data-pow data-pow-bits="<?= SPAM_POW_BITS ?>" data-pow-worker="/assets/pow-worker.js">
        <?= csrf_feld() ?>
        <div class="paar">
            <div class="feld"><label for="vorname">Vorname</label><input id="vorname" name="vorname" type="text" autocomplete="given-name" maxlength="80" required value="<?= $v('vorname') ?>"<?= $ungueltig('vorname') ?>><?= $fehlerFeld('vorname') ?></div>
            <div class="feld"><label for="nachname">Nachname</label><input id="nachname" name="nachname" type="text" autocomplete="family-name" maxlength="80" required value="<?= $v('nachname') ?>"<?= $ungueltig('nachname') ?>><?= $fehlerFeld('nachname') ?></div>
        </div>
        <?php if ($tk !== null): ?>
        <div class="feld"><label for="email">E-Mail</label><input id="email" name="email" type="email" value="<?= e($tk['email']) ?>" readonly><p class="hilfe">Die Adresse deines Kontos. Du bist eingeloggt, deshalb ist keine Bestätigung per Mail nötig.</p></div>
        <?php else: ?>
        <div class="feld"><label for="email">E-Mail</label><input id="email" name="email" type="email" autocomplete="email" maxlength="190" required value="<?= $v('email') ?>"<?= $ungueltig('email') ?>><p class="hilfe">An diese Adresse schicken wir die Bestätigung und deine Links zum Ändern.</p><?= $fehlerFeld('email') ?></div>
        <?php endif; ?>
        <div class="paar">
            <div class="feld"><label for="berufsgruppe">Berufsgruppe</label>
                <select id="berufsgruppe" name="berufsgruppe" required data-efn-gruppe="<?= e($gruppen[0] ?? '') ?>"<?= $ungueltig('berufsgruppe') ?>>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($gruppen as $g): ?><option value="<?= e($g) ?>"<?= ($eingabe['berufsgruppe'] ?? '') === $g ? ' selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?>
                </select><?= $fehlerFeld('berufsgruppe') ?></div>
            <div class="feld"><label for="institution">Klinik / Institution</label><input id="institution" name="institution" type="text" autocomplete="organization" maxlength="160" required value="<?= $v('institution') ?>"<?= $ungueltig('institution') ?>><?= $fehlerFeld('institution') ?></div>
        </div>
        <div class="feld" id="sonst-feld"><label for="beruf_sonstige">Sonstige – welche? <span class="meta">(freiwillig)</span></label><input id="beruf_sonstige" name="beruf_sonstige" type="text" maxlength="80" value="<?= $v('beruf_sonstige') ?>"<?= $ungueltig('beruf_sonstige') ?>><?= $fehlerFeld('beruf_sonstige') ?></div>
        <?php if (va_an('mit_cme')): ?><div class="feld" id="efn-feld"><label for="efn">EFN <span class="meta">(nur für <?= e($gruppen[0] ?? 'ÄrztInnen') ?>, freiwillig, für die Fortbildungspunkte)</span></label><input id="efn" name="efn" type="text" inputmode="numeric" autocomplete="off" maxlength="20" value="<?= $v('efn') ?>"<?= $ungueltig('efn') ?>><p class="hilfe">Die einheitliche Fortbildungsnummer (15 Ziffern) steht auf deinem Fortbildungsausweis. Kannst du auch später nachtragen.</p><?= $fehlerFeld('efn') ?></div><?php endif; ?>

        <?php if (!$voll && anm_workshops() !== []):
            $limit = va_zahl('workshop_limit'); $pflicht = va('workshop_pflicht') === 'pflicht'; ?>
        <fieldset class="feld" id="workshops"><legend>Workshops <span class="meta"><?= $pflicht ? '(mindestens einer)' : '(freiwillig)' ?></span></legend>
            <p class="hilfe">Jeder Workshop hat eigene Termine und Längen. Je Workshop ein fester Termin; Termine, die sich mit deiner Wahl überschneiden, sind gesperrt. Volle Termine kannst du zusätzlich auf die Warteliste setzen.<?= $limit > 0 ? " Höchstens $limit " . ($limit === 1 ? 'Workshop' : 'Workshops') . ' (feste Plätze und Wartelisten zusammen).' : '' ?></p>
            <?php if ($wsFehler !== []): ?><div class="meldung fehler" role="alert"><ul><?php foreach ($wsFehler as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <?php $bisher = []; require __DIR__ . '/teil_workshopwahl.php'; ?>
        </fieldset>
        <?php endif; ?>

        <?php if (va_an('mit_essen')): ?>
        <fieldset class="feld"><legend>Essen</legend>
            <div class="wahl wahl-reihe">
                <?php foreach (ANM_ESSEN as $k => $t): ?><label><input type="radio" name="essen" value="<?= e($k) ?>"<?= ($eingabe['essen'] ?? '') === $k ? ' checked' : '' ?>><span><?= e($t) ?></span></label><?php endforeach; ?>
            </div><?= $fehlerFeld('essen') ?>
        </fieldset>
        <?php endif; ?>

        <div class="feld haken-liste">
            <?php if ($tk === null): ?><label class="haken"><input type="checkbox" name="konto" value="1"<?= !empty($eingabe['konto']) ? ' checked' : '' ?>><span>Konto anlegen – dann siehst und änderst du deine Anmeldung jederzeit mit Passwort, ohne Link per Mail. Das Passwort legst du nach der Bestätigung fest. Geht auch später.</span></label><?php endif; ?>
            <?php if (($nlListe = nl_liste_anmeldung()) !== null): ?><label class="haken"><input type="checkbox" name="newsletter" value="1"<?= !empty($eingabe['newsletter']) ? ' checked' : '' ?>><span>Newsletter „<?= e($nlListe['name']) ?>“<?= $nlListe['beschreibung'] !== '' ? ': ' . editor_html($nlListe['beschreibung'], 'inline') : '' ?> Freiwillig, Abmeldung jederzeit. Nach der Bestätigung deiner Anmeldung schicken wir dir eine eigene Mail, mit der du den Newsletter bestätigst.</span></label><?php endif; ?>
            <label class="haken"><input type="checkbox" name="einwilligung" value="1"<?= !empty($eingabe['einwilligung']) ? ' checked' : '' ?><?= $ungueltig('einwilligung') ?>><span>Ich habe die <a href="/datenschutz" target="_blank" rel="noopener">Datenschutzerklärung</a> gelesen und bin einverstanden, dass meine Angaben für die Organisation des Symposiums verarbeitet werden.</span></label>
            <?= $fehlerFeld('einwilligung') ?>
        </div>
        <?= spam_felder() ?>
        <div class="absenden"><button class="knopf gruen" type="submit" data-pow-knopf>Anmelden</button>
            <p class="meta klein zustand" data-pow-zustand>Ohne JavaScript lässt sich die Anmeldung nicht absenden (Sicherheitsprüfung gegen Spam).</p></div>
    </form>
</div>
<?php
seite_fuss();
