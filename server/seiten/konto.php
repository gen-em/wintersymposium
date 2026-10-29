<?php
declare(strict_types=1);

/*
 * „Mein Konto“ für Teilnehmende (AP-4b, E-30, E-48): Passwort festlegen oder
 * ändern, Profil, neue Adresse (gilt nach Bestätigung, Anmeldung zieht mit),
 * Konto löschen – wahlweise mit oder ohne Anmeldung. Workshops, Essen und
 * Storno bleiben auf „Deine Anmeldung“ (gleiche Regeln und Stichtage).
 */

anm_sitzung();
$k = tk_aktuell();
if ($k === null) {
    flash_setzen('hinweis', 'Bitte logge dich ein.');
    umleiten('/deine-anmeldung');
}
$id = (int)$k['id'];
$fehler = [];
$eingabe = [];
$pwNoetig = $k['passwort_hash'] === null || !empty($_SESSION['tk_pw']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    switch ((string)($_POST['aktion'] ?? '')) {
        case 'passwort_setzen':
            if (!$pwNoetig) {
                umleiten('/konto');
            }
            $f = passwort_regel_fehler((string)($_POST['passwort'] ?? ''), (string)($_POST['passwort2'] ?? ''), PASSWORT_MIN_TN);
            if ($f === null) {
                konto_passwort_setzen($id, (string)$_POST['passwort']);
                unset($_SESSION['tk_pw']);
                tk_einloggen(konto_laden($id));
                flash_setzen('ok', 'Dein Passwort ist gespeichert. Ab jetzt kannst du dich mit Adresse und Passwort einloggen.');
                umleiten('/konto');
            }
            $fehler['passwort'] = $f;
            break;
        case 'passwort_aendern':
            if (!anmeldung_passwort_ok($k, (string)($_POST['alt'] ?? ''))) {
                $fehler['alt'] = 'Das bisherige Passwort stimmt nicht.';
            } elseif (($f = passwort_regel_fehler((string)($_POST['neu'] ?? ''), (string)($_POST['neu2'] ?? ''), PASSWORT_MIN_TN)) !== null) {
                $fehler['neu'] = $f;
            } else {
                konto_passwort_setzen($id, (string)$_POST['neu']);
                tk_einloggen(konto_laden($id));
                flash_setzen('ok', 'Dein Passwort ist geändert. Andere Sitzungen sind beendet.');
                umleiten('/konto');
            }
            break;
        case 'profil':
            $fehler = tk_profil_setzen($id, $_POST);
            if ($fehler === []) {
                flash_setzen('ok', 'Dein Profil ist gespeichert.');
                umleiten('/konto');
            }
            $eingabe = $_POST;
            $_GET['aendern'] = 'profil';
            break;
        case 'email':
            $f = tk_email_aendern($id, (string)($_POST['email'] ?? ''));
            if ($f === null) {
                flash_setzen('ok', 'An die neue Adresse ist ein Link unterwegs. Erst damit gilt sie; bis dahin bleibt die bisherige.');
                umleiten('/konto');
            }
            $fehler['email'] = $f;
            break;
        case 'loeschen':
            if (!anmeldung_passwort_ok($k, (string)($_POST['passwort'] ?? ''))) {
                $fehler['loeschen'] = 'Das Passwort stimmt nicht. Das Konto wurde nicht gelöscht.';
            } elseif (($f = tk_loeschen($id, ($_POST['anmeldung'] ?? '') === 'stornieren')) !== null) {
                $fehler['loeschen'] = $f;
            } else {
                $mitAnmeldung = ($_POST['anmeldung'] ?? '') === 'stornieren';
                anm_ausloggen();
                flash_setzen('ok', $mitAnmeldung ? 'Dein Konto und deine Anmeldung sind gelöscht.' : 'Dein Konto ist gelöscht. Deine Anmeldung bleibt; du erreichst sie über „Link per Mail“.');
                umleiten('/deine-anmeldung');
            }
            break;
    }
}

$k = konto_laden($id);
$a = tk_anmeldung($id);
$meldung = flash_holen();
$fehlerFeld = fn(string $f) => isset($fehler[$f]) ? '<p class="feldfehler" id="f-' . $f . '">' . e($fehler[$f]) . '</p>' : '';
$ungueltig = fn(string $f) => isset($fehler[$f]) ? ' aria-invalid="true" aria-describedby="f-' . $f . '"' : '';
$gruppen = va_liste('berufsgruppen');
$namen = $a !== null ? ['vorname' => $a['vorname'], 'nachname' => $a['nachname']] : tk_namen($k);
$profil = $a ?? $k;

seite_kopf('Mein Konto', ['aktiv' => '/konto', 'skripte' => ['anmelden.js']]);
?>
<div class="innen form-kopf">
    <p class="kicker">Mein Konto</p>
    <h1 class="titel">Hallo <?= e($namen['vorname']) ?>.</h1>
</div>
<div class="innen">
<?= meldung_html($meldung) ?>
<?php if ($pwNoetig): ?>
    <div class="kasten schmal-kasten">
        <h2 class="zwischen klein-zwischen">Passwort festlegen</h2>
        <p>Mindestens <?= PASSWORT_MIN_TN ?> Zeichen. Am besten eins aus deinem Passwortmanager.</p>
        <form class="form" method="post" action="/konto" novalidate>
            <?= csrf_feld() ?><input type="hidden" name="aktion" value="passwort_setzen">
            <input type="email" name="email" value="<?= e($k['email']) ?>" autocomplete="username" hidden>
            <div class="feld"><label for="passwort">Neues Passwort</label><input id="passwort" name="passwort" type="password" autocomplete="new-password" minlength="<?= PASSWORT_MIN_TN ?>" required<?= $ungueltig('passwort') ?>><?= $fehlerFeld('passwort') ?></div>
            <div class="feld"><label for="passwort2">Noch einmal</label><input id="passwort2" name="passwort2" type="password" autocomplete="new-password" required></div>
            <div><button class="knopf" type="submit">Passwort speichern</button></div>
        </form>
    </div>
</div>
<?php seite_fuss(); return; endif; ?>
</div>

<?php if (($_GET['aendern'] ?? '') === 'profil'):
    $w = fn(string $f) => e($eingabe !== [] ? (string)($eingabe[$f] ?? '') : (string)($f === 'vorname' || $f === 'nachname' ? $namen[$f] : ($profil[$f] ?? ''))); ?>
<div class="innen abschnitt">
    <?php if ($fehler !== []): ?><div class="meldung fehler" role="alert">Nicht gespeichert. Bitte prüf die markierten Angaben.</div><?php endif; ?>
    <form class="form" method="post" action="/konto" novalidate>
        <?= csrf_feld() ?><input type="hidden" name="aktion" value="profil">
        <h2 class="zwischen">Dein Profil</h2>
        <div class="paar">
            <div class="feld"><label for="vorname">Vorname</label><input id="vorname" name="vorname" type="text" autocomplete="given-name" maxlength="80" value="<?= $w('vorname') ?>"<?= $ungueltig('vorname') ?>><?= $fehlerFeld('vorname') ?></div>
            <div class="feld"><label for="nachname">Nachname</label><input id="nachname" name="nachname" type="text" autocomplete="family-name" maxlength="80" value="<?= $w('nachname') ?>"<?= $ungueltig('nachname') ?>><?= $fehlerFeld('nachname') ?></div>
        </div>
        <div class="paar">
            <div class="feld"><label for="berufsgruppe">Berufsgruppe</label>
                <select id="berufsgruppe" name="berufsgruppe" data-efn-gruppe="<?= e($gruppen[0] ?? '') ?>"<?= $ungueltig('berufsgruppe') ?>>
                    <option value="">Bitte wählen</option>
                    <?php $bg = $eingabe !== [] ? (string)($eingabe['berufsgruppe'] ?? '') : (string)$profil['berufsgruppe']; foreach ($gruppen as $g): ?><option value="<?= e($g) ?>"<?= $bg === $g ? ' selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?>
                </select><?= $fehlerFeld('berufsgruppe') ?></div>
            <div class="feld"><label for="institution">Klinik / Institution</label><input id="institution" name="institution" type="text" maxlength="160" value="<?= $w('institution') ?>"<?= $ungueltig('institution') ?>><?= $fehlerFeld('institution') ?></div>
        </div>
        <div class="feld" id="sonst-feld"><label for="beruf_sonstige">Sonstige – welche? <span class="meta">(freiwillig)</span></label><input id="beruf_sonstige" name="beruf_sonstige" type="text" maxlength="80" value="<?= $w('beruf_sonstige') ?>"></div>
        <div class="feld" id="efn-feld"><label for="efn">EFN <span class="meta">(freiwillig, für die Fortbildungspunkte)</span></label><input id="efn" name="efn" type="text" inputmode="numeric" autocomplete="off" maxlength="20" value="<?= $w('efn') ?>"<?= $ungueltig('efn') ?>><?= $fehlerFeld('efn') ?></div>
        <div class="aktionen"><button class="knopf" type="submit">Speichern</button><a class="knopf zweit" href="/konto">Abbrechen</a></div>
    </form>
</div>
<?php seite_fuss(); return; endif; ?>

<div class="innen raster abschnitt">
    <div class="spalte">
        <div class="kasten">
            <h2 class="zwischen klein-zwischen">Deine Anmeldung</h2>
            <?php if ($a !== null): ?>
                <p>Anmeldung Nr. <?= sprintf('%04d', $a['id']) ?> · <?= $a['status'] === 'bestaetigt' ? 'bestätigt' : 'noch nicht bestätigt' ?></p>
                <div class="aktionen abstand"><a class="knopf" href="/deine-anmeldung">Workshops, Essen, Absage</a></div>
            <?php elseif (anm_zeitraum() === 'offen'): ?>
                <p>Du bist noch nicht zum Symposium angemeldet. Mit deinem Konto sind Name und Adresse schon ausgefüllt, und eine Bestätigung per Mail entfällt.</p>
                <div class="aktionen abstand"><a class="knopf gruen" href="/anmelden">Jetzt anmelden</a></div>
            <?php else: ?>
                <p class="meta">Du bist nicht zum Symposium angemeldet. <?= anm_zeitraum() === 'vorher' ? 'Die Anmeldung öffnet am ' . e(datum_zeit_text(va('anmeldung_start'))) . ' Uhr.' : 'Die Anmeldung ist geschlossen.' ?></p>
            <?php endif; ?>
        </div>
        <div class="kasten">
            <h2 class="zwischen klein-zwischen">Dein Profil</h2>
            <dl class="liste-def">
                <div><dt>Name</dt><dd><?= e(trim($namen['vorname'] . ' ' . $namen['nachname'])) ?></dd></div>
                <div><dt>E-Mail</dt><dd><?= e($k['email']) ?></dd></div>
                <div><dt>Berufsgruppe</dt><dd><?= $profil['berufsgruppe'] !== '' ? e($profil['berufsgruppe'] . ($profil['beruf_sonstige'] !== '' ? ' (' . $profil['beruf_sonstige'] . ')' : '')) : '<span class="meta">–</span>' ?></dd></div>
                <div><dt>Institution</dt><dd><?= $profil['institution'] !== '' ? e($profil['institution']) : '<span class="meta">–</span>' ?></dd></div>
                <?php if ($profil['berufsgruppe'] === ($gruppen[0] ?? '')): ?><div><dt>EFN</dt><dd class="mono"><?= $profil['efn'] !== null ? e(efn_text($profil['efn'])) : '<span class="meta">– nicht angegeben</span>' ?></dd></div><?php endif; ?>
            </dl>
            <div class="aktionen abstand"><a class="knopf zweit" href="/konto?aendern=profil">Profil bearbeiten</a></div>
        </div>
    </div>
    <div class="spalte">
        <div class="kasten">
            <strong>E-Mail-Adresse ändern</strong>
            <form class="form" method="post" action="/konto" novalidate>
                <?= csrf_feld() ?><input type="hidden" name="aktion" value="email">
                <div class="feld"><label for="neue-email">Neue Adresse</label><input id="neue-email" name="email" type="email" autocomplete="email" maxlength="190"<?= $ungueltig('email') ?>><p class="hilfe">Gilt erst, wenn du den Link bestätigst, den wir dorthin schicken<?= $a !== null ? '; deine Anmeldung zieht mit' : '' ?>.</p><?= $fehlerFeld('email') ?></div>
                <div><button class="knopf zweit" type="submit">Link schicken</button></div>
            </form>
        </div>
        <div class="kasten">
            <strong>Passwort ändern</strong>
            <form class="form" method="post" action="/konto" novalidate>
                <?= csrf_feld() ?><input type="hidden" name="aktion" value="passwort_aendern">
                <input type="email" name="email" value="<?= e($k['email']) ?>" autocomplete="username" hidden>
                <div class="feld"><label for="alt">Bisheriges Passwort</label><input id="alt" name="alt" type="password" autocomplete="current-password"<?= $ungueltig('alt') ?>><?= $fehlerFeld('alt') ?></div>
                <div class="feld"><label for="neu">Neues Passwort</label><input id="neu" name="neu" type="password" autocomplete="new-password" minlength="<?= PASSWORT_MIN_TN ?>"<?= $ungueltig('neu') ?>><?= $fehlerFeld('neu') ?></div>
                <div class="feld"><label for="neu2">Noch einmal</label><input id="neu2" name="neu2" type="password" autocomplete="new-password"></div>
                <div><button class="knopf zweit" type="submit">Passwort ändern</button></div>
            </form>
        </div>
        <div class="kasten">
            <strong>Konto löschen</strong>
            <?= $fehlerFeld('loeschen') ?>
            <details class="bestaetigen"<?= isset($fehler['loeschen']) ? ' open' : '' ?>><summary class="knopf gefahr">Konto löschen</summary>
                <form method="post" action="/konto"><?= csrf_feld() ?><input type="hidden" name="aktion" value="loeschen">
                    <?php if ($a !== null): ?>
                    <fieldset class="feld"><legend>Und deine Anmeldung?</legend>
                        <div class="wahl">
                            <label><input type="radio" name="anmeldung" value="behalten" checked><span>Anmeldung behalten – du erreichst sie weiter über „Link per Mail“</span></label>
                            <label><input type="radio" name="anmeldung" value="stornieren"<?= anm_frist_offen('stichtag_storno') ? '' : ' disabled' ?>><span>Anmeldung auch stornieren – deine Plätze gehen an die Warteliste<?= anm_frist_offen('stichtag_storno') ? '' : ' (nicht mehr möglich, Stichtag vorbei)' ?></span></label>
                        </div>
                    </fieldset>
                    <?php endif; ?>
                    <input type="email" name="email" value="<?= e($k['email']) ?>" autocomplete="username" hidden>
                    <div class="feld"><label for="loeschen-pw">Passwort zur Bestätigung</label><input id="loeschen-pw" name="passwort" type="password" autocomplete="current-password"></div>
                    <p>Das Konto wird endgültig gelöscht.</p>
                    <button type="submit" class="knopf gefahr">Ja, Konto löschen</button></form>
            </details>
        </div>
    </div>
</div>
<?php
seite_fuss();
