<?php
declare(strict_types=1);

/*
 * Einloggen für das Team (AP-3 Schritt 1): Passwort, dann Code aus der
 * Authenticator-App oder ein Wiederherstellungscode. Beim ersten Login wird
 * der zweite Faktor eingerichtet (Pflicht, E-11). Passkeys folgen später (O-14).
 */

team_sitzung();
if (anmeldung_konto() !== null) {
    umleiten('/team');
}

$fehler = [];
$email = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $aktion = (string)($_POST['aktion'] ?? '');

    if ($aktion === 'passwort') {
        $email = trim((string)($_POST['email'] ?? ''));
        $ip = rate_merkmal_ip();
        $kennung = rate_merkmal_kennung($email);
        if (!rate_erlaubt('login_ip', $ip) || !rate_erlaubt('login', $kennung)) {
            $fehler[] = 'Zu viele Versuche. Bitte warte 15 Minuten und versuche es dann noch einmal.';
        } else {
            $beginn = microtime(true);
            $k = konto_per_email($email);
            $ok = anmeldung_passwort_ok($k, (string)($_POST['passwort'] ?? '')) && (int)$k['aktiv'] === 1 && konto_ist_team($k);
            rate_gleiche_dauer($beginn);
            if ($ok) {
                rate_erfolg('login', $kennung);
                anmeldung_halb_setzen($k);
                umleiten('/einloggen');
            }
            rate_misserfolg('login_ip', $ip);
            rate_misserfolg('login', $kennung);
            $fehler[] = 'E-Mail-Adresse oder Passwort stimmt nicht.';
        }
    } elseif ($aktion === 'abbrechen') {
        anmeldung_halb_verwerfen();
        umleiten('/einloggen');
    } elseif ($aktion === 'code' || $aktion === 'einrichten') {
        $k = anmeldung_halb();
        if ($k === null) {
            flash_setzen('hinweis', 'Die Anmeldung hat zu lange gedauert. Bitte gib dein Passwort noch einmal ein.');
            umleiten('/einloggen');
        }
        $kennung = rate_merkmal('konto:' . $k['id']);
        $code = (string)($_POST['code'] ?? '');
        if (!rate_erlaubt('zweitfaktor', $kennung)) {
            $fehler[] = 'Zu viele falsche Codes. Bitte warte 15 Minuten und versuche es dann noch einmal.';
        } elseif ($aktion === 'code') {
            $r = totp_anmeldung_pruefen((int)$k['id'], $code);
            if ($r['ok']) {
                rate_erfolg('zweitfaktor', $kennung);
                anmeldung_voll($k);
                if ($r['art'] === 'code') {
                    flash_setzen('hinweis', 'Du hast einen Wiederherstellungscode benutzt. Übrig: ' . $r['codes_offen']
                        . '. Unter „Mein Konto“ kannst du neue Codes erzeugen.');
                }
                umleiten('/team');
            }
            rate_misserfolg('zweitfaktor', $kennung);
            $fehler[] = 'Der Code stimmt nicht. Nimm den aktuellen Code aus der App oder einen unbenutzten Wiederherstellungscode.';
        } else {
            $codes = totp_einrichtung_abschliessen((int)$k['id'], $code);
            if ($codes !== null) {
                rate_erfolg('zweitfaktor', $kennung);
                anmeldung_voll(konto_laden((int)$k['id']));
                seite_kopf('Wiederherstellungscodes', ['nav' => false]);
                echo '<div class="innen seitenkopf"><p class="kicker">Zweiter Faktor eingerichtet</p><h1 class="titel">Deine Wiederherstellungs&shy;codes</h1></div>'
                    . '<div class="innen text">'
                    . '<p>Mit einem dieser Codes kommst du rein, wenn dein Handy weg ist. Jeder Code gilt einmal. '
                    . '<strong>Sie werden nur jetzt angezeigt</strong>: Speichere sie in deinem Passwortmanager oder drucke sie aus.</p>'
                    . '<ul class="codes">' . implode('', array_map(fn($c) => '<li class="mono">' . e(totp_code_anzeige($c)) . '</li>', $codes)) . '</ul>'
                    . '<p><a class="knopf" href="/team">Codes gesichert – weiter zum Team-Bereich</a></p></div>';
                seite_fuss();
                exit;
            }
            rate_misserfolg('zweitfaktor', $kennung);
            $fehler[] = 'Der Code stimmt nicht. Prüfe, ob die Uhrzeit auf deinem Handy stimmt, und nimm den aktuellen Code.';
        }
    }
}

$halb = anmeldung_halb();
$skripte = [];
$einrichtung = null;
if ($halb !== null && !totp_an((int)$halb['id'])) {
    $roh = totp_zustand((int)$halb['id'])['angefangen'] ? totp_geheimnis((int)$halb['id']) : null;
    $roh ??= totp_einrichtung_beginnen((int)$halb['id']);
    $aussteller = 'Wintersymposium' . (umgebung() === 'produktion' ? '' : ' (' . umgebung() . ')');
    $einrichtung = ['otpauth' => totp_otpauth($roh, (string)$halb['email'], $aussteller), 'text' => totp_base32_gruppen($roh)];
    $skripte = ['vendor/qrcode.js', 'qr.js'];
}

seite_kopf('Einloggen', ['nav' => false, 'skripte' => $skripte]);
echo '<div class="innen seitenkopf"><p class="kicker">Team-Bereich</p><h1 class="titel">Einloggen</h1></div><div class="innen schmal">';
echo meldung_html(flash_holen()) . fehler_html($fehler);

if ($halb === null): ?>
<form method="post" action="/einloggen" class="formular">
    <?= csrf_feld() ?>
    <input type="hidden" name="aktion" value="passwort">
    <label for="email">E-Mail-Adresse</label>
    <input id="email" name="email" type="email" required maxlength="254" autocomplete="username" value="<?= e($email) ?>">
    <label for="passwort">Passwort</label>
    <input id="passwort" name="passwort" type="password" required autocomplete="current-password">
    <button type="submit" class="knopf">Weiter</button>
</form>
<p class="meta klein">Nur für das Team. Passwort vergessen? Ein Betreiber schickt dir einen neuen Link.</p>
<?php elseif ($einrichtung === null): ?>
<form method="post" action="/einloggen" class="formular">
    <?= csrf_feld() ?>
    <input type="hidden" name="aktion" value="code">
    <p>Hallo <?= e($halb['name']) ?>. Gib den sechsstelligen Code aus deiner Authenticator-App ein – oder einen deiner Wiederherstellungscodes.</p>
    <label for="code">Code</label>
    <input id="code" name="code" type="text" required inputmode="text" autocomplete="one-time-code" maxlength="12" autofocus>
    <button type="submit" class="knopf">Einloggen</button>
</form>
<?php else: ?>
<div class="text">
    <p>Hallo <?= e($halb['name']) ?>. Für den Team-Bereich brauchst du einen zweiten Faktor. Das geht einmalig so:</p>
    <ol>
        <li>Öffne eine Authenticator-App auf deinem Handy (z. B. Aegis, 2FAS, Google Authenticator, Microsoft Authenticator oder den Passwortmanager).</li>
        <li>Scanne den QR-Code oder tippe den Schlüssel ab.</li>
        <li>Gib den Code ein, den die App anzeigt.</li>
    </ol>
</div>
<div class="einrichtung">
    <svg class="qr" xmlns="http://www.w3.org/2000/svg" data-qr="<?= e($einrichtung['otpauth']) ?>" role="img" aria-label="QR-Code für die Authenticator-App" hidden></svg>
    <div>
        <p class="meta klein">Schlüssel zum Abtippen</p>
        <p class="mono schluessel"><?= e($einrichtung['text']) ?></p>
        <p class="klein"><a href="<?= e($einrichtung['otpauth']) ?>">Auf diesem Handy direkt in der App öffnen</a></p>
    </div>
</div>
<form method="post" action="/einloggen" class="formular">
    <?= csrf_feld() ?>
    <input type="hidden" name="aktion" value="einrichten">
    <label for="code">Code aus der App</label>
    <input id="code" name="code" type="text" required inputmode="numeric" autocomplete="one-time-code" maxlength="8" pattern="[0-9 ]{6,8}">
    <button type="submit" class="knopf">Einrichten</button>
</form>
<?php endif;
if ($halb !== null): ?>
<form method="post" action="/einloggen" class="abbrechen">
    <?= csrf_feld() ?>
    <input type="hidden" name="aktion" value="abbrechen">
    <button type="submit" class="knopf zweit">Abbrechen</button>
</form>
<?php endif;
echo '</div>';
seite_fuss();
