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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $aktion = (string)($_POST['aktion'] ?? '');
    $kennung = rate_merkmal('konto:' . $id);
    if (in_array($aktion, ['codes', 'mail_an', 'mail_aus', 'app_start'], true)) {
        // Änderungen am zweiten Faktor: mit dem Passwort bestätigen
        $zs = totp_zustand($id);
        if (!rate_erlaubt('login', $kennung)) {
            $fehler[] = 'Zu viele Versuche. Bitte warte 15 Minuten.';
        } elseif (!anmeldung_passwort_ok($konto, (string)($_POST['pw'] ?? ''))) {
            rate_misserfolg('login', $kennung);
            $fehler[] = 'Das Passwort stimmt nicht. Nichts geändert.';
        } elseif ($aktion === 'codes') {
            $neueCodes = totp_codes_erneuern($id);
        } elseif ($aktion === 'mail_an') {
            mailcode_einschalten($id, true);
            flash_setzen('ok', 'Code per E-Mail ist eingeschaltet. Beim nächsten Einloggen schicken wir dir einen Code.');
            umleiten('/konto/zweitfaktor');
        } elseif ($aktion === 'mail_aus') {
            if (!$zs['an'] && $zs['passkeys'] === 0) {
                $fehler[] = 'Richte zuerst die Authenticator-App oder einen Passkey ein – ohne zweiten Faktor geht es nicht.';
            } else {
                mailcode_einschalten($id, false);
                flash_setzen('ok', 'Code per E-Mail ist ausgeschaltet. Du loggst dich mit der Authenticator-App ein.');
                umleiten('/konto/zweitfaktor');
            }
        } elseif (!$zs['an']) {
            $_SESSION['app_einrichten'] = true;
            umleiten('/konto/zweitfaktor#app');
        }
    } elseif ($aktion === 'app_fertig' && !empty($_SESSION['app_einrichten'])) {
        $codes = totp_einrichtung_abschliessen($id, (string)($_POST['totp'] ?? ''));
        if ($codes === null) {
            $fehler[] = 'Der Code aus der App stimmt nicht. Prüfe die Uhrzeit auf dem Handy und nimm den aktuellen Code.';
        } else {
            unset($_SESSION['app_einrichten']);
            $neueCodes = $codes;
        }
    }
}

$zf = totp_zustand($id);
if (!empty($_SESSION['app_einrichten']) && !$zf['an']) {
    $roh = $zf['angefangen'] ? totp_geheimnis($id) : null;
    $roh ??= totp_einrichtung_beginnen($id);
    $aussteller = 'Wintersymposium' . (umgebung() === 'produktion' ? '' : ' (' . umgebung() . ')');
    $appEinrichtung = ['otpauth' => totp_otpauth($roh, (string)$konto['email'], $aussteller), 'text' => totp_base32_gruppen($roh)];
}
$pwFeld = fn(string $id) => '<label for="pw-' . $id . '">Bestätigen mit deinem Passwort</label><input id="pw-' . $id . '" name="pw" type="password" required autocomplete="current-password">';
seite_kopf('Zwei-Faktor', ['aktiv' => '/konto', 'konto' => $konto] + ($appEinrichtung !== null ? ['skripte' => ['vendor/qrcode.js', 'qr.js']] : []));
echo '<div class="innen form-kopf"><p class="kicker"><a href="/konto">Mein Konto</a> ›</p><h1 class="titel">Zwei-Faktor</h1></div><div class="innen">' . meldung_html(flash_holen());
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
            <div><dt>Authenticator-App</dt><dd><?= $zf['an'] ? '<span class="chip ok">eingerichtet</span> seit ' . e(datum_zeit_text($konto['totp_seit'])) : '<span class="chip offen">nicht eingerichtet</span>' ?></dd></div>
            <div><dt>Code per E-Mail</dt><dd><?= $zf['mail'] ? '<span class="chip ok">an</span> bei jedem Einloggen an ' . e($konto['email']) : '<span class="chip offen">aus</span>' ?></dd></div>
            <div><dt>Passkeys</dt><dd><?= $zf['passkeys'] > 0 ? '<span class="chip ok">' . $zf['passkeys'] . '</span>' : '<span class="chip offen">keiner</span>' ?> <a href="/konto#passkeys">verwalten</a></dd></div>
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
                <label for="app-code">Code aus der App</label><input id="app-code" name="totp" type="text" required inputmode="numeric" autocomplete="one-time-code" maxlength="8">
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
        <p class="meta klein">Handy gewechselt und kein Code mehr? Logg dich mit einem Wiederherstellungscode ein und richte die App hier neu ein – oder bitte einen Betreiber, deinen zweiten Faktor zurückzusetzen.</p>
    </section>
</div>
<?php
echo '</div>';
seite_fuss();
