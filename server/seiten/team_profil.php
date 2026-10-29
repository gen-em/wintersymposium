<?php
declare(strict_types=1);

/* Mein Konto: Passwort ändern, Wiederherstellungscodes erneuern. */

$konto = team_tor();
$id = (int)$konto['id'];
$fehler = [];
$neueCodes = null;

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
    } elseif ($aktion === 'codes') {
        if (!rate_erlaubt('zweitfaktor', $kennung)) {
            $fehler[] = 'Zu viele falsche Codes. Bitte warte 15 Minuten.';
        } elseif (!totp_anmeldung_pruefen($id, (string)($_POST['totp'] ?? ''))['ok']) {
            rate_misserfolg('zweitfaktor', $kennung);
            $fehler[] = 'Der Code stimmt nicht.';
        } else {
            rate_erfolg('zweitfaktor', $kennung);
            $neueCodes = totp_codes_erneuern($id);
        }
    }
}

$zf = totp_zustand($id);
team_seite_kopf($konto, 'Mein Konto', '/team/profil');
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
    <section>
        <h2 class="zwischen">Zweiter Faktor</h2>
        <dl class="angaben">
            <div><dt>Eingerichtet</dt><dd><?= e(datum_zeit_text($konto['totp_seit'])) ?></dd></div>
            <div><dt>Wiederherstellungscodes übrig</dt><dd><?= $zf['codes_offen'] ?> von <?= TOTP_CODES ?></dd></div>
            <div><dt>Letzte Anmeldung</dt><dd><?= e(datum_zeit_text($konto['letzte_anmeldung'])) ?></dd></div>
        </dl>
        <form method="post" action="/team/profil" class="formular">
            <?= csrf_feld() ?>
            <input type="hidden" name="aktion" value="codes">
            <label for="totp">Neue Wiederherstellungscodes: Code aus der App</label>
            <input id="totp" name="totp" type="text" required inputmode="numeric" autocomplete="one-time-code" maxlength="8">
            <button type="submit" class="knopf zweit">Neue Codes erzeugen</button>
        </form>
        <p class="meta klein">Handy gewechselt? Ein Betreiber setzt deinen zweiten Faktor zurück, beim nächsten Einloggen richtest du ihn neu ein.</p>
    </section>
</div>
<?php
team_seite_fuss();
