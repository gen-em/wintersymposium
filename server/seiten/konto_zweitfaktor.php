<?php
declare(strict_types=1);

/*
 * Zwei-Faktor-Authentifizierung für Team-Konten (Rückmeldung 2026-10-30),
 * erreichbar aus „Mein Konto“ (ein Konto für alles, E-54): Authenticator-App
 * einrichten, Code per E-Mail ein/aus, Wiederherstellungscodes erneuern.
 * Änderungen bestätigt man mit dem Passwort. Das Passwort selbst ändert man
 * unter „Mein Konto“. Passkeys verwaltet man unter „Mein Konto“ (E-55).
 */

$konto = team_tor();
$id = (int)$konto['id'];
$fehler = [];
$neueCodes = null;
$appEinrichtung = null;

if (ist_post()) {
    $aktion = aktion();
    if (in_array($aktion, ['codes', 'mail_an', 'mail_aus', 'app_start'], true)) {
        // Änderungen am zweiten Faktor: mit dem Passwort bestätigen (Ratenschutz im Helfer, V-4)
        $zs = totp_zustand($id);
        if (($f = passwort_bestaetigen($konto, post_text('pw'), 'Das Passwort stimmt nicht. Nichts geändert.')) !== null) {
            $fehler[] = $f;
        } elseif ($aktion === 'codes') {
            $neueCodes = totp_codes_erneuern($id);
        } elseif ($aktion === 'mail_an') {
            mailcode_einschalten($id, true);
            fertig('ok', 'Code per E-Mail ist eingeschaltet. Beim nächsten Einloggen schicken wir dir einen Code.', '/konto/zweitfaktor');
        } elseif ($aktion === 'mail_aus') {
            if (!$zs['an'] && $zs['passkeys'] === 0) {
                $fehler[] = 'Richte zuerst die Authenticator-App oder einen Passkey ein – ohne zweiten Faktor geht es nicht.';
            } else {
                mailcode_einschalten($id, false);
                fertig('ok', 'Code per E-Mail ist ausgeschaltet. Du loggst dich mit der Authenticator-App ein.', '/konto/zweitfaktor');
            }
        } elseif (!$zs['an']) {
            $_SESSION[SITZUNG_SCHLUESSEL_APP_EINRICHTEN] = true;
            umleiten('/konto/zweitfaktor#app');
        }
    } elseif ($aktion === 'app_fertig' && !empty($_SESSION[SITZUNG_SCHLUESSEL_APP_EINRICHTEN])) {
        $codes = totp_einrichtung_abschliessen($id, post_text('totp'));
        if ($codes === null) {
            $fehler[] = 'Der Code aus der App stimmt nicht. Prüfe die Uhrzeit auf dem Handy und nimm den aktuellen Code.';
        } else {
            unset($_SESSION[SITZUNG_SCHLUESSEL_APP_EINRICHTEN]);
            $neueCodes = $codes;
        }
    }
}

$zf = totp_zustand($id);
if (!empty($_SESSION[SITZUNG_SCHLUESSEL_APP_EINRICHTEN]) && !$zf['an']) {
    $roh = $zf['angefangen'] ? totp_geheimnis($id) : null;
    $roh ??= totp_einrichtung_beginnen($id);
    $aussteller = 'Wintersymposium' . (umgebung() === 'produktion' ? '' : ' (' . umgebung() . ')');
    $appEinrichtung = ['otpauth' => totp_otpauth($roh, (string)$konto['email'], $aussteller), 'text' => totp_base32_gruppen($roh)];
}
$pwFeld = fn(string $id) => feld('pw', 'Bestätigen mit deinem Passwort', ['typ' => 'passwort', 'id' => 'pw-' . $id, 'pflicht' => true, 'ohne_rahmen' => true, 'attr' => ['autocomplete' => 'current-password']]);
seite_kopf('Zwei-Faktor', ['aktiv' => '/konto', 'konto' => $konto] + ($appEinrichtung !== null ? ['skripte' => ['vendor/qrcode.js', 'qr.js']] : []));
// Kopf von Hand: der Kicker ist ein Link zurück (seiten_kopfzeile() escaped den Kicker)
echo '<div class="innen seitenkopf"><p class="kicker"><a href="/konto">Mein Konto</a> ›</p><h1 class="titel">Zwei-Faktor</h1></div><div class="innen">' . meldung_html(flash_holen());
echo fehler_html($fehler);
if ($neueCodes !== null): ?>
<div class="kasten">
    <p><strong>Deine neuen Wiederherstellungscodes.</strong> Die alten gelten nicht mehr. Sie werden nur jetzt angezeigt: Speichere sie im Passwortmanager oder drucke sie aus.</p>
    <ul class="codes"><?php foreach ($neueCodes as $c): ?><li class="mono"><?= e(totp_code_anzeige($c)) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
<div class="team-raster">
    <section class="zf">
        <h2 class="zwischen">Zwei-Faktor-Authentifizierung</h2>
        <p class="meta">Nach dem Passwort fragt der Login einen zweiten Nachweis ab. So kommt niemand nur mit deinem Passwort in den Team-Bereich. Änderungen hier bestätigst du mit deinem Passwort.</p>
        <dl class="liste-def">
            <div><dt>Authenticator-App</dt><dd><?= $zf['an'] ? chip('ok', 'eingerichtet') . ' seit ' . e(datum_zeit_text($konto['totp_seit'])) : chip('offen', 'nicht eingerichtet') ?></dd></div>
            <div><dt>Code per E-Mail</dt><dd><?= $zf['mail'] ? chip('ok', 'an') . ' bei jedem Einloggen an ' . e($konto['email']) : chip('offen', 'aus') ?></dd></div>
            <div><dt>Passkeys</dt><dd><?= $zf['passkeys'] > 0 ? chip('ok', (string)$zf['passkeys']) : chip('offen', 'keiner') ?> <a href="/konto#passkeys">verwalten</a></dd></div>
            <div><dt>Wiederherstellungs&shy;codes</dt><dd><?= $zf['codes_offen'] ?> von <?= TOTP_CODES ?> übrig – für den Notfall (Handy weg, Postfach nicht erreichbar)</dd></div>
            <div><dt>Letzte Anmeldung</dt><dd><?= e(datum_zeit_text($konto['letzte_anmeldung'])) ?></dd></div>
        </dl>
        <?php if ($appEinrichtung !== null): ?>
        <div class="kasten" id="app">
            <p><strong>Authenticator-App einrichten:</strong> QR-Code scannen oder Schlüssel abtippen, dann den Code aus der App eingeben.</p>
            <div class="einrichtung">
                <svg class="qr" xmlns="http://www.w3.org/2000/svg" data-qr="<?= e($appEinrichtung['otpauth']) ?>" role="img" aria-label="QR-Code für die Authenticator-App" hidden></svg>
                <div><p class="meta klein">Schlüssel zum Abtippen</p><p class="mono schluessel"><?= e($appEinrichtung['text']) ?></p></div>
            </div>
            <form method="post" action="/konto/zweitfaktor" class="formular"><?= csrf_feld() ?><input type="hidden" name="aktion" value="app_fertig">
                <?= feld('totp', 'Code aus der App', ['id' => 'app-code', 'pflicht' => true, 'ohne_rahmen' => true, 'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'maxlength' => 8]]) ?>
                <button type="submit" class="knopf">App einrichten</button></form>
        </div>
        <?php endif; ?>
        <details class="tabelle-details"><summary>Einstellungen ändern</summary>
            <div class="zf-aktionen">
            <?php if (!$zf['an'] && $appEinrichtung === null): ?>
            <form method="post" action="/konto/zweitfaktor" class="formular"><?= csrf_feld() ?><input type="hidden" name="aktion" value="app_start"><?= $pwFeld('app') ?><button type="submit" class="knopf zweit">Authenticator-App einrichten</button></form>
            <?php endif; ?>
            <form method="post" action="/konto/zweitfaktor" class="formular"><?= csrf_feld() ?><input type="hidden" name="aktion" value="<?= $zf['mail'] ? 'mail_aus' : 'mail_an' ?>"><?= $pwFeld('mail') ?><button type="submit" class="knopf zweit"><?= $zf['mail'] ? 'Code per E-Mail ausschalten' : 'Jedes Mal einen Code per E-Mail schicken' ?></button>
                <?php if ($zf['mail'] && !$zf['an'] && $zf['passkeys'] === 0): ?><p class="hilfe">Ausschalten geht erst, wenn die Authenticator-App oder ein Passkey eingerichtet ist.</p><?php endif; ?></form>
            <form method="post" action="/konto/zweitfaktor" class="formular"><?= csrf_feld() ?><input type="hidden" name="aktion" value="codes"><?= $pwFeld('codes') ?><button type="submit" class="knopf zweit">Neue Wiederherstellungscodes erzeugen</button></form>
            </div>
        </details>
        <p class="meta klein">Handy gewechselt und kein Code mehr? Logg dich mit einem Wiederherstellungscode ein und richte die App hier neu ein – oder bitte eine BetreiberIn, deinen zweiten Faktor zurückzusetzen.</p>
    </section>
</div>
<?php
echo '</div>';
seite_fuss();
