<?php
declare(strict_types=1);

/*
 * Einloggen für alle Konten (E-48): Team (AP-3 Schritt 1) mit Passwort, dann
 * Code aus der Authenticator-App, aus einer Mail (frei wählbar, Admin
 * 2026-10-30) oder ein Wiederherstellungscode; beim ersten Login wird der
 * zweite Faktor eingerichtet (Pflicht, E-11). Teilnehmende
 * (AP-4b) nur mit Passwort, danach weiter zu ihrem Konto (Sitzung 'link').
 * Passkeys folgen später (O-14).
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
        $r = tk_login_pruefen($email, (string)($_POST['passwort'] ?? ''));
        if (isset($r['konto']) && konto_ist_team($r['konto'])) {
            anmeldung_halb_setzen($r['konto']);
            umleiten('/einloggen');
        }
        if (isset($r['konto'])) {
            // Teilnehmende: in die Sitzung der Teilnehmenden wechseln
            sitzung_wechseln('link');
            tk_einloggen($r['konto']);
            umleiten(tk_anmeldung((int)$r['konto']['id']) !== null ? '/deine-anmeldung' : '/konto');
        }
        $fehler[] = $r['fehler'];
    } elseif ($aktion === 'abbrechen') {
        anmeldung_halb_verwerfen();
        unset($_SESSION['zf_mail']);
        umleiten('/einloggen');
    } elseif ($aktion === 'mail_waehlen' || $aktion === 'mailcode') {
        // E-Mail-Code wählen (erstes Einloggen) bzw. neuen Code anfordern – höchstens einer je Minute
        $k = anmeldung_halb();
        if ($k === null) {
            umleiten('/einloggen');
        }
        if (time() - (int)($_SESSION['zf_mail_zeit'] ?? 0) >= 60) {
            mailcode_senden($k);
            $_SESSION['zf_mail_zeit'] = time();
            flash_setzen('ok', 'Code ist unterwegs an ' . $k['email'] . '. Er gilt 10 Minuten.');
        } else {
            flash_setzen('hinweis', 'Ein Code ist gerade erst unterwegs. Warte eine Minute, bevor du einen neuen anforderst.');
        }
        if ($aktion === 'mail_waehlen') {
            $_SESSION['zf_mail'] = (int)$k['id'];
        }
        umleiten('/einloggen');
    } elseif ($aktion === 'app_waehlen') {
        unset($_SESSION['zf_mail']);
        umleiten('/einloggen');
    } elseif ($aktion === 'code' || $aktion === 'einrichten' || $aktion === 'mail_einrichten') {
        $k = anmeldung_halb();
        if ($k === null) {
            flash_setzen('hinweis', 'Die Anmeldung hat zu lange gedauert. Bitte gib dein Passwort noch einmal ein.');
            umleiten('/einloggen');
        }
        $kennung = rate_merkmal('konto:' . $k['id']);
        $code = (string)($_POST['totp'] ?? '');
        if (!rate_erlaubt('zweitfaktor', $kennung)) {
            $fehler[] = 'Zu viele falsche Codes. Bitte warte 15 Minuten und versuche es dann noch einmal.';
        } elseif ($aktion === 'mail_einrichten') {
            if (!totp_zustand((int)$k['id'])['eingerichtet'] && mailcode_pruefen((int)$k['id'], $code)) {
                rate_erfolg('zweitfaktor', $kennung);
                $codes = mailcode_einschalten((int)$k['id'], true);
                unset($_SESSION['zf_mail']);
                anmeldung_voll(konto_laden((int)$k['id']));
                codes_seite($codes ?? []);
            }
            rate_misserfolg('zweitfaktor', $kennung);
            $fehler[] = 'Der Code stimmt nicht oder ist abgelaufen. Fordere einen neuen an.';
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
                codes_seite($codes);
            }
            rate_misserfolg('zweitfaktor', $kennung);
            $fehler[] = 'Der Code stimmt nicht. Prüfe, ob die Uhrzeit auf deinem Handy stimmt, und nimm den aktuellen Code.';
        }
    }
}

/** Wiederherstellungscodes einmal anzeigen und beenden. */
function codes_seite(array $codes): never
{
    seite_kopf('Wiederherstellungscodes', ['nav' => false]);
    echo '<div class="innen seitenkopf"><p class="kicker">Zwei-Faktor-Authentifizierung eingerichtet</p><h1 class="titel">Deine Wiederherstellungs&shy;codes</h1></div>'
        . '<div class="innen text">'
        . '<p>Mit einem dieser Codes kommst du rein, wenn du keinen anderen Code bekommst (Handy weg, Postfach nicht erreichbar). Jeder Code gilt einmal. '
        . '<strong>Sie werden nur jetzt angezeigt</strong>: Speichere sie in deinem Passwortmanager oder drucke sie aus.</p>'
        . '<ul class="codes">' . implode('', array_map(fn($c) => '<li class="mono">' . e(totp_code_anzeige($c)) . '</li>', $codes)) . '</ul>'
        . '<p><a class="knopf" href="/team">Codes gesichert – weiter zum Team-Bereich</a></p></div>';
    seite_fuss();
    exit;
}

$halb = anmeldung_halb();
$skripte = [];
$einrichtung = null;
$zs = $halb !== null ? totp_zustand((int)$halb['id']) : null;
$mailWahl = $halb !== null && !$zs['eingerichtet'] && ($_SESSION['zf_mail'] ?? 0) === (int)$halb['id'];
// E-Mail-Code eingeschaltet: beim ersten Aufruf nach dem Passwort einen Code schicken
if ($halb !== null && $zs['mail'] && ($_SESSION['zf_mail_halb'] ?? '') !== ($_SESSION['halb']['zeit'] ?? '')) {
    mailcode_senden($halb);
    $_SESSION['zf_mail_halb'] = $_SESSION['halb']['zeit'] ?? '';
    $_SESSION['zf_mail_zeit'] = time();
}
if ($halb !== null && !$zs['eingerichtet'] && !$mailWahl) {
    $roh = totp_zustand((int)$halb['id'])['angefangen'] ? totp_geheimnis((int)$halb['id']) : null;
    $roh ??= totp_einrichtung_beginnen((int)$halb['id']);
    $aussteller = 'Wintersymposium' . (umgebung() === 'produktion' ? '' : ' (' . umgebung() . ')');
    $einrichtung = ['otpauth' => totp_otpauth($roh, (string)$halb['email'], $aussteller), 'text' => totp_base32_gruppen($roh)];
    $skripte = ['vendor/qrcode.js', 'qr.js'];
}

seite_kopf('Einloggen', ['nav' => false, 'skripte' => $skripte]);
echo '<div class="innen seitenkopf"><p class="kicker">Konto</p><h1 class="titel">Einloggen</h1></div><div class="innen schmal">';
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
<p class="meta klein"><a href="/konto/passwort-vergessen">Passwort vergessen?</a> · Noch kein Konto? <a href="/konto/neu">Konto anlegen</a></p>
<?php elseif ($mailWahl): ?>
<form method="post" action="/einloggen" class="formular">
    <?= csrf_feld() ?>
    <input type="hidden" name="aktion" value="mail_einrichten">
    <p>Hallo <?= e($halb['name']) ?>. Wir haben dir einen sechsstelligen Code an <strong><?= e($halb['email']) ?></strong> geschickt. Gib ihn hier ein – damit ist der Code per E-Mail eingerichtet, und du bekommst ihn ab jetzt bei jedem Einloggen.</p>
    <label for="totp">Code aus der Mail</label>
    <input id="totp" name="totp" type="text" required inputmode="numeric" autocomplete="one-time-code" maxlength="8" autofocus>
    <button type="submit" class="knopf">Einrichten</button>
</form>
<div class="aktionen abstand-oben-klein">
    <form method="post" action="/einloggen"><?= csrf_feld() ?><input type="hidden" name="aktion" value="mailcode"><button type="submit" class="knopf zweit">Neuen Code schicken</button></form>
    <form method="post" action="/einloggen"><?= csrf_feld() ?><input type="hidden" name="aktion" value="app_waehlen"><button type="submit" class="knopf zweit">Doch lieber mit App</button></form>
</div>
<?php elseif ($einrichtung === null): ?>
<form method="post" action="/einloggen" class="formular">
    <?= csrf_feld() ?>
    <input type="hidden" name="aktion" value="code">
    <?php if ($zs['mail']): ?>
    <p>Hallo <?= e($halb['name']) ?>. Wir haben dir einen sechsstelligen Code an <strong><?= e($halb['email']) ?></strong> geschickt (gilt 10 Minuten). <?= $zs['an'] ? 'Der Code aus deiner Authenticator-App geht auch, ebenso' : 'Es geht auch' ?> ein Wiederherstellungscode.</p>
    <?php else: ?>
    <p>Hallo <?= e($halb['name']) ?>. Gib den sechsstelligen Code aus deiner Authenticator-App ein – oder einen deiner Wiederherstellungscodes.</p>
    <?php endif; ?>
    <label for="totp">Code</label>
    <input id="totp" name="totp" type="text" required inputmode="text" autocomplete="one-time-code" maxlength="12" autofocus>
    <button type="submit" class="knopf">Einloggen</button>
</form>
<?php if ($zs['mail']): ?>
<form method="post" action="/einloggen" class="abstand-oben-klein"><?= csrf_feld() ?><input type="hidden" name="aktion" value="mailcode"><button type="submit" class="knopf zweit">Neuen Code schicken</button></form>
<?php endif; ?>
<?php else: ?>
<div class="text">
    <p>Hallo <?= e($halb['name']) ?>. Für den Team-Bereich brauchst du einen zweiten Nachweis nach dem Passwort (Zwei-Faktor-Authentifizierung). Du hast die Wahl:</p>
</div>
<div class="kasten zf-wahl">
    <p class="kicker">Möglichkeit 1</p>
    <h2 class="zwischen">Code per E-Mail</h2>
    <p>Bei jedem Einloggen schicken wir dir einen Code an <?= e($halb['email']) ?>. Einfach, aber nur so sicher wie dein Postfach.</p>
    <form method="post" action="/einloggen"><?= csrf_feld() ?><input type="hidden" name="aktion" value="mail_waehlen"><button type="submit" class="knopf zweit">Code per E-Mail nutzen</button></form>
</div>
<div class="text abstand-oben-klein">
    <p class="kicker">Möglichkeit 2 (sicherer)</p>
    <p><strong>Authenticator-App</strong> – einmalig einrichten:</p>
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
    <label for="totp">Code aus der App</label>
    <input id="totp" name="totp" type="text" required inputmode="numeric" autocomplete="one-time-code" maxlength="8" pattern="[0-9 ]{6,8}">
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
