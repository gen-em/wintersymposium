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

if ($zeitraum === 'offen' && ist_post()) {
    $eingabe = $_POST;
    $r = anm_daten_pruefen($tk !== null ? ['email' => $tk['email']] + $_POST : $_POST);
    $fehler = $r['fehler'];
    $wahl = anm_wahl_lesen($_POST);
    if (!post_haken('einwilligung')) {
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
            // Häkchen 2 (E-71 (6)): Allgäu Akut mit Interessen; ohne gewähltes Interesse die Vorauswahl der Berufsgruppe (O-50)
            $r['werte']['nl_interessen'] = post_haken('nl_dachmarke') ? (nl_interessen((array)($_POST['nl_interesse'] ?? [])) ?: nl_interessen_fuer($r['werte']['berufsgruppe'])) : '';
            $erg = anm_anlegen($r['werte'], $wahl, post_haken('newsletter'), null, post_haken('konto'), $tk);
            if ($erg['art'] !== 'fehler') {
                unset($_SESSION[SITZUNG_SCHLUESSEL_ANM_VORLAGE]); // Vorlage „Daten übernehmen“ ist verbraucht (Review B-13)
            }
            if ($erg['art'] === 'fehler') {
                $wsFehler = $erg['fehler'];
            } elseif ($erg['art'] === 'neu' && !empty($erg['bestaetigt'])) {
                anm_einloggen($erg['id']);
                fertig('ok', 'Du bist angemeldet. Die Übersicht kommt auch per Mail.', '/deine-anmeldung');
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

// Profilfelder; mit Konto ist die Adresse fest (die des Kontos)
$profilHtml = function (array $eingabe, array $fehler, ?array $tk): string {
    $opt = ['pflicht' => true, 'cme' => va_an('mit_cme')];
    if ($tk === null) {
        return profil_felder_html($eingabe, $fehler, $opt + ['email_hilfe' => 'An diese Adresse schicken wir die Bestätigung und deine Links zum Ändern.']);
    }
    return profil_felder_html(['email' => $tk['email']] + $eingabe, $fehler, $opt + ['email' => 'fest',
        'email_hilfe' => 'Die Adresse deines Kontos. Du bist eingeloggt, deshalb ist keine Bestätigung per Mail nötig.']);
};

seite_kopf('Anmelden', ['aktiv' => '/anmelden', 'skripte' => ['anmelden.js', 'pow.js']]);
$kicker = va_kurz() . ' · ' . tag_text(va('veranstaltung_datum'));
if ($danke) {
    echo seiten_kopfzeile('Anmelden', $kicker, '', true); ?>
    <div class="kasten danke" role="status">
        <p class="kicker">Fast geschafft</p>
        <h2 class="zwischen">Schau in dein Postfach.</h2>
        <p>Wir haben dir eine Mail mit einem Bestätigungslink geschickt. <strong>Erst mit dem Klick darauf gilt deine Anmeldung.</strong> Der Link gilt <?= va_zahl('anmeldung_bestaetigung_h') ?> Stunden; so lange halten wir deine Plätze frei.</p>
        <p class="meta">Keine Mail da? Schau auch im Spam-Ordner. Hast du dich mit dieser Adresse schon einmal angemeldet, bekommst du stattdessen einen Link zu deiner bestehenden Anmeldung.</p>
    </div>
</div>
<?php
    seite_ende();
}
if ($zeitraum !== 'offen') {
    echo seiten_kopfzeile('Anmelden', $kicker, $zeitraum === 'vorher'
        ? 'Die Anmeldung öffnet am ' . datum_zeit_text(va('anmeldung_start')) . ' Uhr.'
        : ($zeitraum === 'geschlossen' ? 'Derzeit gibt es keine Veranstaltung, zu der du dich anmelden kannst.' : 'Die Anmeldung ist geschlossen.'), true)
        . '<p><a href="/deine-anmeldung">Schon angemeldet? Zu deiner Anmeldung</a></p></div>';
    seite_ende();
}
echo seiten_kopfzeile('Anmelden', $kicker, 'Dauert zwei Minuten. Danach bekommst du eine Mail mit einem Bestätigungslink – erst damit gilt deine Anmeldung.', true);
?>
    <div class="stichtage">
        <span>Anmeldeschluss <?= e(datum_text(anm_schluss_utc())) ?></span>
        <span>Workshops ändern bis <?= e(tag_text(anm_stichtag('stichtag_workshop'))) ?></span>
        <?php if (va_an('mit_essen')): ?><span>Essen ändern bis <?= e(tag_text(anm_stichtag('stichtag_essen'))) ?></span><?php endif; ?>
        <span>Absagen bis <?= e(tag_text(anm_stichtag('stichtag_storno'))) ?></span>
    </div>
</div>
<div class="innen">
    <?= $tn !== null ? meldung_html('Du bist schon angemeldet.', 'hinweis', ['text_html' => 'Du bist schon angemeldet. <a href="/deine-anmeldung">Zu deiner Anmeldung</a>']) : '' ?>
    <?php if ($voll): ?><div class="merke warteliste-hinweis"><p class="kicker">Gerade voll</p><p>Alle Plätze sind vergeben. Melde dich trotzdem an: Du kommst auf die Warteliste, und wird ein Platz frei, bieten wir ihn dir per Mail an. Workshops wählst du, sobald du einen Platz hast.</p></div><?php endif; ?>
    <?= $fehler !== [] || $wsFehler !== [] ? meldung_html('Noch nicht angemeldet. Bitte prüf die markierten Angaben' . ($wsFehler !== [] ? ' und deine Workshop-Wahl' : '') . '.', 'fehler') : '' ?>
    <form class="form" method="post" action="/anmelden" novalidate data-pow data-pow-bits="<?= SPAM_POW_BITS ?>" data-pow-worker="/assets/pow-worker.js">
        <?= csrf_feld() ?>
        <?= $profilHtml($eingabe, $fehler, $tk) ?>

        <?php if (!$voll && anm_workshops() !== []):
            $limit = va_zahl('workshop_limit'); $pflicht = va('workshop_pflicht') === 'pflicht'; ?>
        <fieldset class="feld" id="workshops"><legend>Workshops <span class="meta"><?= $pflicht ? '(mindestens einer)' : '(freiwillig)' ?></span></legend>
            <p class="hilfe">Jeder Workshop hat eigene Termine und Längen. Je Workshop ein fester Termin; Termine, die sich mit deiner Wahl überschneiden, sind gesperrt. Volle Termine kannst du zusätzlich auf die Warteliste setzen.<?= $limit > 0 ? " Höchstens $limit " . ($limit === 1 ? 'Workshop' : 'Workshops') . ' (feste Plätze und Wartelisten zusammen).' : '' ?></p>
            <?= $wsFehler !== [] ? fehler_html($wsFehler) : '' ?>
            <?= anm_workshopwahl_html($wahl, []) ?>
        </fieldset>
        <?php endif; ?>

        <?php if (va_an('mit_essen')): ?>
        <?= feld('essen', 'Essen', ['typ' => 'radio-reihe', 'optionen' => ANM_ESSEN, 'wert' => wert($eingabe, [], 'essen'), 'fehler' => $fehler['essen'] ?? null]) ?>
        <?php endif; ?>

        <div class="feld haken-liste">
            <?php if ($tk === null): ?><?= feld('konto', '', ['typ' => 'haken', 'wert' => !empty($eingabe['konto']),
                'label_html' => 'Konto anlegen – dann siehst und änderst du deine Anmeldung jederzeit mit Passwort, ohne Link per Mail. Das Passwort legst du nach der Bestätigung fest. Geht auch später.']) ?><?php endif; ?>
            <?php // Zwei Häkchen (E-71 (6)): Newsletter der Reihe, Allgäu Akut mit Interessen; eine gemeinsame Bestätigungsmail (O-54)
            $nl = nl_angebot_anmeldung();
            $nlHinweis = ' Freiwillig, Abmeldung jederzeit.';
            $nlMail = ' Nach der Bestätigung deiner Anmeldung schicken wir dir eine Mail, mit der du deine Newsletter bestätigst (eine für beide).'; ?>
            <?php if ($nl['reihe'] !== null): ?><?= feld('newsletter', '', ['typ' => 'haken', 'wert' => !empty($eingabe['newsletter']),
                'label_html' => 'Newsletter „' . e($nl['reihe']['name']) . '“: Infos zum nächsten ' . e($nl['reihe_kurz']) . '.' . $nlHinweis . ($nl['dachmarke'] === null ? $nlMail : '')]) ?><?php endif; ?>
            <?php if ($nl['dachmarke'] !== null):
                $bgWahl = [];
                foreach (va_liste('berufsgruppen') as $bg) {
                    $bgWahl[$bg] = va_interesse($bg);
                }
                $iWahl = isset($eingabe['nl_interesse']) ? array_map('strval', (array)$eingabe['nl_interesse']) : explode(',', nl_interessen_fuer((string)($eingabe['berufsgruppe'] ?? ''))); ?>
            <?= feld('nl_dachmarke', '', ['typ' => 'haken', 'wert' => !empty($eingabe['nl_dachmarke']),
                'label_html' => 'Newsletter „' . e($nl['dachmarke']['name']) . '“' . ($nl['dachmarke']['beschreibung'] !== '' ? ': ' . editor_html($nl['dachmarke']['beschreibung'], 'inline') : '') . $nlHinweis . $nlMail]) ?>
            <fieldset class="feld nl-interessen" data-vorauswahl="<?= e(json_encode($bgWahl, JSON_UNESCAPED_UNICODE)) ?>"><legend>Interessiert an <span class="meta">(vorausgewählt nach Berufsgruppe)</span></legend>
                <div class="wahl wahl-reihe"><?php foreach (NL_INTERESSEN as $ik => $it): ?><label><input type="checkbox" name="nl_interesse[]" value="<?= e($ik) ?>"<?= in_array($ik, $iWahl, true) ? ' checked' : '' ?>><span><?= e($it) ?></span></label><?php endforeach; ?></div>
            </fieldset>
            <?php endif; ?>
            <?= feld('einwilligung', '', ['typ' => 'haken', 'wert' => !empty($eingabe['einwilligung']), 'fehler' => $fehler['einwilligung'] ?? null,
                'label_html' => 'Ich habe die <a href="/datenschutz" target="_blank" rel="noopener">Datenschutzerklärung</a> gelesen und bin einverstanden, dass meine Angaben für die Organisation des Symposiums verarbeitet werden.']) ?>
        </div>
        <?= spam_felder() ?>
        <div class="absenden"><button class="knopf gruen" type="submit" data-pow-knopf>Anmelden</button>
            <p class="meta klein zustand" data-pow-zustand>Ohne JavaScript lässt sich die Anmeldung nicht absenden (Sicherheitsprüfung gegen Spam).</p></div>
    </form>
</div>
<?php
seite_fuss();
