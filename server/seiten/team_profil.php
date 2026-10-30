<?php
declare(strict_types=1);

/*
 * Mein Konto: Passwort ändern; Zwei-Faktor-Authentifizierung (Rückmeldung
 * 2026-10-30): Authenticator-App einrichten, Code per E-Mail ein/aus,
 * Wiederherstellungscodes erneuern. Änderungen am zweiten Faktor bestätigt
 * man mit dem Passwort. Passkeys folgen später (O-14).
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
    if ($aktion === 'passwort') {
        if (!rate_erlaubt('login', $kennung)) {
            $fehler[] = 'Zu viele Versuche. Bitte warte 15 Minuten.';
        } elseif (!anmeldung_passwort_ok($konto, (string)($_POST['alt'] ?? ''))) {
            rate_misserfolg('login', $kennung);
            $fehler[] = 'Das bisherige Passwort stimmt nicht.';
        } elseif (($f = passwort_regel_fehler((string)($_POST['neu'] ?? ''), (string)($_POST['neu2'] ?? ''))) !== null) {
            $fehler[] = $f;
        } else {
            konto_passwort_setzen($id, (string)$_POST['neu']);
            // Andere Sitzungen enden (neue sitzung_version), diese bleibt.
            sitzung_erneuern();
            $_SESSION['konto']['version'] = (int)konto_laden($id)['sitzung_version'];
            flash_setzen('ok', 'Passwort geändert. Andere Sitzungen mit diesem Konto sind beendet.');
            umleiten('/team/profil');
        }
    } elseif (in_array($aktion, ['codes', 'mail_an', 'mail_aus', 'app_start'], true)) {
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
            umleiten('/team/profil');
        } elseif ($aktion === 'mail_aus') {
            if (!$zs['an']) {
                $fehler[] = 'Richte zuerst die Authenticator-App ein – ohne zweiten Faktor geht es nicht.';
            } else {
                mailcode_einschalten($id, false);
                flash_setzen('ok', 'Code per E-Mail ist ausgeschaltet. Du loggst dich mit der Authenticator-App ein.');
                umleiten('/team/profil');
            }
        } elseif (!$zs['an']) {
            $_SESSION['app_einrichten'] = true;
            umleiten('/team/profil#app');
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
team_seite_kopf($konto, 'Mein Konto', '/team/profil', $appEinrichtung !== null ? ['skripte' => ['vendor/qrcode.js', 'qr.js']] : []);
echo fehler_html($fehler);
if ($neueCodes !== null): ?>
<div class="kasten">
    <p><strong>Deine neuen Wiederherstellungscodes.</strong> Die alten gelten nicht mehr. Sie werden nur jetzt angezeigt: Speichere sie im Passwortmanager oder drucke sie aus.</p>
    <ul class="codes"><?php foreach ($neueCodes as $c): ?><li class="mono"><?= e(totp_code_anzeige($c)) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
<div class="team-raster">
    <section>
        <h2 class="zwischen">Passwort ändern</h2>
        <form method="post" action="/team/profil" class="formular">
            <?= csrf_feld() ?>
            <input type="hidden" name="aktion" value="passwort">
            <input type="text" name="email" value="<?= e($konto['email']) ?>" autocomplete="username" hidden>
            <label for="alt">Bisheriges Passwort</label>
            <input id="alt" name="alt" type="password" required autocomplete="current-password">
            <label for="neu">Neues Passwort (mindestens <?= PASSWORT_MIN_LAENGE ?> Zeichen)</label>
            <input id="neu" name="neu" type="password" required minlength="<?= PASSWORT_MIN_LAENGE ?>" autocomplete="new-password">
            <label for="neu2">Neues Passwort wiederholen</label>
            <input id="neu2" name="neu2" type="password" required minlength="<?= PASSWORT_MIN_LAENGE ?>" autocomplete="new-password">
            <button type="submit" class="knopf">Passwort ändern</button>
        </form>
    </section>
    <section class="zf">
        <h2 class="zwischen">Zwei-Faktor-Authentifizierung</h2>
        <p class="meta">Nach dem Passwort fragt der Login einen zweiten Nachweis ab. So kommt niemand nur mit deinem Passwort in den Team-Bereich. Änderungen hier bestätigst du mit deinem Passwort.</p>
        <dl class="liste-def">
            <div><dt>Authenticator-App</dt><dd><?= $zf['an'] ? '<span class="chip ok">eingerichtet</span> seit ' . e(datum_zeit_text($konto['totp_seit'])) : '<span class="chip offen">nicht eingerichtet</span>' ?></dd></div>
            <div><dt>Code per E-Mail</dt><dd><?= $zf['mail'] ? '<span class="chip ok">an</span> bei jedem Einloggen an ' . e($konto['email']) : '<span class="chip offen">aus</span>' ?></dd></div>
            <div><dt>Passkey</dt><dd class="meta">folgt später</dd></div>
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
            <form method="post" action="/team/profil" class="formular"><?= csrf_feld() ?><input type="hidden" name="aktion" value="app_fertig">
                <label for="app-code">Code aus der App</label><input id="app-code" name="totp" type="text" required inputmode="numeric" autocomplete="one-time-code" maxlength="8">
                <button type="submit" class="knopf">App einrichten</button></form>
        </div>
        <?php endif; ?>
        <details class="tabelle-details"><summary>Einstellungen ändern</summary>
            <div class="zf-aktionen">
            <?php if (!$zf['an'] && $appEinrichtung === null): ?>
            <form method="post" action="/team/profil" class="formular"><?= csrf_feld() ?><input type="hidden" name="aktion" value="app_start"><?= $pwFeld('app') ?><button type="submit" class="knopf zweit">Authenticator-App einrichten</button></form>
            <?php endif; ?>
            <form method="post" action="/team/profil" class="formular"><?= csrf_feld() ?><input type="hidden" name="aktion" value="<?= $zf['mail'] ? 'mail_aus' : 'mail_an' ?>"><?= $pwFeld('mail') ?><button type="submit" class="knopf zweit"><?= $zf['mail'] ? 'Code per E-Mail ausschalten' : 'Jedes Mal einen Code per E-Mail schicken' ?></button>
                <?php if ($zf['mail'] && !$zf['an']): ?><p class="hilfe">Ausschalten geht erst, wenn die Authenticator-App eingerichtet ist.</p><?php endif; ?></form>
            <form method="post" action="/team/profil" class="formular"><?= csrf_feld() ?><input type="hidden" name="aktion" value="codes"><?= $pwFeld('codes') ?><button type="submit" class="knopf zweit">Neue Wiederherstellungscodes erzeugen</button></form>
            </div>
        </details>
        <p class="meta klein">Handy gewechselt und kein Code mehr? Logg dich mit einem Wiederherstellungscode ein und richte die App hier neu ein – oder bitte einen Betreiber, deinen zweiten Faktor zurückzusetzen.</p>
    </section>
</div>
<?php
team_seite_fuss();
