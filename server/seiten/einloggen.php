<?php
declare(strict_types=1);

/*
 * Einloggen für alle Konten (E-48): Team (AP-3 Schritt 1) mit Passwort, dann
 * Code aus der Authenticator-App, aus einer Mail (frei wählbar, Admin
 * 2026-10-30) oder ein Wiederherstellungscode; beim ersten Login wird der
 * zweite Faktor eingerichtet (Pflicht, E-11). Teilnehmende
 * (AP-4b) nur mit Passwort. Eine Sitzung für alles (E-54): Team-Konten gelten
 * nach dem zweiten Faktor auch als Konto für die eigene Anmeldung.
 * Passkeys folgen später (O-14).
 */

team_sitzung();
// Passkey als zweiter Faktor beim ersten Login eingerichtet (E-55): Wiederherstellungscodes einmal zeigen
if (isset($_GET['codes']) && is_array($_SESSION[SITZUNG_SCHLUESSEL_PK_CODES] ?? null) && anmeldung_konto() !== null) {
    $pkCodes = $_SESSION[SITZUNG_SCHLUESSEL_PK_CODES];
    unset($_SESSION[SITZUNG_SCHLUESSEL_PK_CODES]);
    zweitfaktor_codes_seite($pkCodes);
}
if (anmeldung_konto() !== null) {
    umleiten('/team');
}
// Ein Konto für alles (E-54): schon eingeloggt → zum Konto
if (($eingeloggt = tk_aktuell()) !== null && !konto_ist_team($eingeloggt)) {
    umleiten('/konto');
}

$fehler = [];
$email = '';

if (ist_post()) {
    $aktion = aktion();

    if ($aktion === 'passwort') {
        $email = trim(post_text('email'));
        $r = tk_login_pruefen($email, post_text('passwort'));
        if (isset($r['konto']) && konto_ist_team($r['konto'])) {
            anmeldung_halb_setzen($r['konto']);
            umleiten('/einloggen');
        }
        if (isset($r['konto'])) {
            // Teilnehmende: direkt eingeloggt (eine Sitzung für alles, E-54)
            tk_einloggen($r['konto']);
            umleiten(tk_anmeldung((int)$r['konto']['id']) !== null ? '/deine-anmeldung' : '/konto');
        }
        $fehler[] = $r['fehler'];
    } elseif ($aktion === 'abbrechen') {
        anmeldung_halb_verwerfen();
        unset($_SESSION[SITZUNG_SCHLUESSEL_ZF_MAIL]);
        umleiten('/einloggen');
    } elseif ($aktion === 'mail_waehlen' || $aktion === 'mailcode') {
        // E-Mail-Code wählen (erstes Einloggen) bzw. neuen Code anfordern – Grenze je Konto in mailcode_senden (Review S-9)
        $k = anmeldung_halb();
        if ($k === null) {
            umleiten('/einloggen');
        }
        $f = mailcode_senden($k);
        if ($aktion === 'mail_waehlen') {
            $_SESSION[SITZUNG_SCHLUESSEL_ZF_MAIL] = (int)$k['id'];
        }
        fertig($f === null ? 'ok' : 'hinweis', $f ?? 'Code ist unterwegs an ' . $k['email'] . '. Er gilt ' . dauer_text(MAILCODE_GUELTIG_S) . '.', '/einloggen');
    } elseif ($aktion === 'app_waehlen') {
        unset($_SESSION[SITZUNG_SCHLUESSEL_ZF_MAIL]);
        umleiten('/einloggen');
    } elseif ($aktion === 'code' || $aktion === 'einrichten' || $aktion === 'mail_einrichten') {
        $k = anmeldung_halb();
        if ($k === null) {
            fertig('hinweis', 'Die Anmeldung hat zu lange gedauert. Bitte gib dein Passwort noch einmal ein.', '/einloggen');
        }
        $kennung = rate_merkmal('konto:' . $k['id']);
        $code = post_text('totp');
        if (!rate_erlaubt('zweitfaktor', $kennung)) {
            $fehler[] = rate_text('zweitfaktor', 'falsche Codes');
        } elseif ($aktion === 'mail_einrichten') {
            if (!totp_zustand((int)$k['id'])['eingerichtet'] && mailcode_pruefen((int)$k['id'], $code)) {
                rate_erfolg('zweitfaktor', $kennung);
                $codes = mailcode_einschalten((int)$k['id'], true);
                unset($_SESSION[SITZUNG_SCHLUESSEL_ZF_MAIL]);
                anmeldung_voll(konto_laden((int)$k['id']));
                zweitfaktor_codes_seite($codes ?? []);
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
                zweitfaktor_codes_seite($codes);
            }
            rate_misserfolg('zweitfaktor', $kennung);
            $fehler[] = 'Der Code stimmt nicht. Prüfe, ob die Uhrzeit auf deinem Handy stimmt, und nimm den aktuellen Code.';
        }
    }
}

$halb = anmeldung_halb();
$skripte = [];
$einrichtung = null;
$zs = $halb !== null ? totp_zustand((int)$halb['id']) : null;
$mailWahl = $halb !== null && !$zs['eingerichtet'] && ($_SESSION[SITZUNG_SCHLUESSEL_ZF_MAIL] ?? 0) === (int)$halb['id'];
// E-Mail-Code eingeschaltet: beim ersten Aufruf nach dem Passwort einen Code schicken
if ($halb !== null && $zs['mail'] && ($_SESSION[SITZUNG_SCHLUESSEL_ZF_MAIL_HALB] ?? '') !== ($_SESSION[SITZUNG_SCHLUESSEL_HALB]['zeit'] ?? '')) {
    // An der Grenze (Review S-9) kein neuer Code; ein Code aus den letzten 10 Minuten gilt weiter
    if (($f = mailcode_senden($halb)) !== null) {
        flash_setzen('hinweis', $f);
    }
    $_SESSION[SITZUNG_SCHLUESSEL_ZF_MAIL_HALB] = $_SESSION[SITZUNG_SCHLUESSEL_HALB]['zeit'] ?? '';
}
if ($halb !== null && !$zs['eingerichtet'] && !$mailWahl) {
    $roh = totp_zustand((int)$halb['id'])['angefangen'] ? totp_geheimnis((int)$halb['id']) : null;
    $roh ??= totp_einrichtung_beginnen((int)$halb['id']);
    $aussteller = 'Wintersymposium' . (umgebung() === 'produktion' ? '' : ' (' . umgebung() . ')');
    $einrichtung = ['otpauth' => totp_otpauth($roh, (string)$halb['email'], $aussteller), 'text' => totp_base32_gruppen($roh)];
    $skripte = ['vendor/qrcode.js', 'qr.js'];
}

$skripte[] = 'passkey.js'; // Passkeys (E-55)
seite_kopf('Einloggen', ['nav' => false, 'skripte' => $skripte]);
echo seiten_kopfzeile('Einloggen', 'Konto') . '<div class="innen schmal">';
echo meldung_html(flash_holen()) . fehler_html($fehler);

if ($halb === null): ?>
<form method="post" action="/einloggen" class="formular">
    <?= csrf_feld() ?>
    <input type="hidden" name="aktion" value="passwort">
    <?= feld('email', 'E-Mail-Adresse', ['typ' => 'email', 'pflicht' => true, 'wert' => $email, 'ohne_rahmen' => true, 'attr' => ['maxlength' => 254, 'autocomplete' => 'username']]) ?>
    <?= feld('passwort', 'Passwort', ['typ' => 'passwort', 'pflicht' => true, 'ohne_rahmen' => true, 'attr' => ['autocomplete' => 'current-password']]) ?>
    <button type="submit" class="knopf">Weiter</button>
</form>
<div class="pk-login" data-passkey-login hidden>
    <p class="trenner-text"><span>oder</span></p>
    <button type="button" class="knopf zweit pk-knopf" data-passkey-los><?= icon('key', 18) ?>Mit Passkey einloggen</button>
    <p class="meta klein">Ohne Passwort, mit Fingerabdruck, Gesicht oder Geräte-PIN – wenn du unter „Mein Konto“ einen Passkey angelegt hast.</p>
    <p class="feldfehler" data-passkey-meldung role="alert" hidden></p>
</div>
<p class="meta klein"><a href="/konto/passwort-vergessen">Passwort vergessen?</a> · Noch kein Konto? <a href="/konto/neu">Konto anlegen</a></p>
<?php elseif ($mailWahl): ?>
<form method="post" action="/einloggen" class="formular">
    <?= csrf_feld() ?>
    <input type="hidden" name="aktion" value="mail_einrichten">
    <p>Hallo <?= e($halb['name']) ?>. Wir haben dir einen sechsstelligen Code an <strong><?= e($halb['email']) ?></strong> geschickt. Gib ihn hier ein – damit ist der Code per E-Mail eingerichtet, und du bekommst ihn ab jetzt bei jedem Einloggen.</p>
    <?= feld('totp', 'Code aus der Mail', ['pflicht' => true, 'ohne_rahmen' => true, 'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'maxlength' => 8, 'autofocus' => true]]) ?>
    <button type="submit" class="knopf">Einrichten</button>
</form>
<div class="aktionen abstand-oben-klein">
    <?= aktion_knopf('/einloggen', 'mailcode', [], 'Neuen Code schicken', 'knopf zweit') ?>
    <?= aktion_knopf('/einloggen', 'app_waehlen', [], 'Doch lieber mit App', 'knopf zweit') ?>
</div>
<?php elseif ($einrichtung === null): ?>
<form method="post" action="/einloggen" class="formular">
    <?= csrf_feld() ?>
    <input type="hidden" name="aktion" value="code">
    <?php if ($zs['mail']): ?>
    <p>Hallo <?= e($halb['name']) ?>. Wir haben dir einen sechsstelligen Code an <strong><?= e($halb['email']) ?></strong> geschickt (gilt <?= e(dauer_text(MAILCODE_GUELTIG_S)) ?>). <?= $zs['an'] ? 'Der Code aus deiner Authenticator-App geht auch, ebenso' : 'Es geht auch' ?> ein Wiederherstellungscode.</p>
    <?php elseif (!$zs['an']): ?>
    <p>Hallo <?= e($halb['name']) ?>. Bestätige mit deinem Passkey – oder gib einen deiner Wiederherstellungscodes ein.</p>
    <?php else: ?>
    <p>Hallo <?= e($halb['name']) ?>. Gib den sechsstelligen Code aus deiner Authenticator-App ein – oder einen deiner Wiederherstellungscodes.</p>
    <?php endif; ?>

    <?= feld('totp', 'Code', ['pflicht' => true, 'ohne_rahmen' => true, 'attr' => ['inputmode' => 'text', 'autocomplete' => 'one-time-code', 'maxlength' => 12, 'autofocus' => true]]) ?>
    <button type="submit" class="knopf">Einloggen</button>
</form>
<?php if ($zs['mail']): ?>
<?= aktion_knopf('/einloggen', 'mailcode', [], 'Neuen Code schicken', 'knopf zweit', ['form_klasse' => 'abstand-oben-klein']) ?>
<?php endif; ?>
<?php if ($zs['passkeys'] > 0): ?>
<div class="pk-login" data-passkey-login hidden>
    <p class="trenner-text"><span>oder</span></p>
    <button type="button" class="knopf pk-knopf" data-passkey-los>Mit Passkey bestätigen</button>
    <p class="feldfehler" data-passkey-meldung role="alert" hidden></p>
</div>
<?php endif; ?>
<?php else: ?>
<div class="text">
    <p>Hallo <?= e($halb['name']) ?>. Für den Team-Bereich brauchst du einen zweiten Nachweis nach dem Passwort (Zwei-Faktor-Authentifizierung). Du hast die Wahl:</p>
</div>
<div class="kasten zf-wahl" data-passkey-anlage data-einrichtung hidden>
    <p class="kicker">Möglichkeit 1 (am einfachsten und sichersten)</p>
    <h2 class="zwischen">Passkey</h2>
    <p>Dein Handy oder Computer wird zum Schlüssel: Fingerabdruck, Gesicht oder Geräte-PIN genügt. Danach loggst du dich damit ganz ohne Passwort ein.</p>
    <form class="formular" data-passkey-anlegen>
        <?= feld('bezeichnung', 'Name für den Passkey (freiwillig)', ['id' => 'pk-bez', 'ohne_rahmen' => true, 'attr' => ['maxlength' => 40, 'placeholder' => 'z. B. Handy', 'autocomplete' => 'off']]) ?>
        <button type="submit" class="knopf">Passkey anlegen</button>
        <p class="feldfehler" data-passkey-meldung role="alert" hidden></p>
    </form>
</div>
<div class="kasten zf-wahl">
    <p class="kicker">Möglichkeit 2</p>
    <h2 class="zwischen">Code per E-Mail</h2>
    <p>Bei jedem Einloggen schicken wir dir einen Code an <?= e($halb['email']) ?>. Einfach, aber nur so sicher wie dein Postfach.</p>
    <?= aktion_knopf('/einloggen', 'mail_waehlen', [], 'Code per E-Mail nutzen', 'knopf zweit') ?>
</div>
<div class="text abstand-oben-klein">
    <p class="kicker">Möglichkeit 3</p>
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
    <?= feld('totp', 'Code aus der App', ['pflicht' => true, 'ohne_rahmen' => true, 'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'maxlength' => 8, 'pattern' => '[0-9 ]{6,8}']]) ?>
    <button type="submit" class="knopf">Einrichten</button>
</form>
<?php endif;
if ($halb !== null): ?>
<?= aktion_knopf('/einloggen', 'abbrechen', [], 'Abbrechen', 'knopf zweit', ['form_klasse' => 'abbrechen']) ?>
<?php endif;
echo '</div>';
seite_fuss();
