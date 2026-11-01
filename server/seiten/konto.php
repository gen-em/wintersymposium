<?php
declare(strict_types=1);

/*
 * „Mein Konto“ für alle (AP-4b, E-30, E-48; ein Konto für alles, E-54):
 * Passwort festlegen oder ändern, Profil, Newsletter, neue Adresse (gilt nach
 * Bestätigung, Anmeldung zieht mit), Konto löschen – wahlweise mit oder ohne
 * Anmeldung. Team-Konten: zusätzlich der zweite Faktor (/konto/zweitfaktor),
 * Passwort mit 12 Zeichen; Adresse ändern und Löschen über einen Betreiber.
 * Workshops, Essen und Storno bleiben auf „Meine Anmeldung“.
 */

anm_sitzung();
$k = tk_aktuell();
if ($k === null) {
    flash_setzen('hinweis', 'Bitte logge dich ein.');
    umleiten('/deine-anmeldung');
}
$id = (int)$k['id'];
$team = konto_ist_team($k);
$pwMin = $team ? PASSWORT_MIN_LAENGE : PASSWORT_MIN_TN;
$fehler = [];
$eingabe = [];
$pwNoetig = $k['passwort_hash'] === null || !empty($_SESSION['tk_pw']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    switch ((string)($_POST['aktion'] ?? '')) {
        case 'newsletter':
            // AP-7: Adresse ist durch das bestätigte Konto bewiesen – Listen gelten gleich
            $ids = array_map('intval', (array)($_POST['listen'] ?? []));
            $np = nl_person_per_email((string)$k['email']);
            if ($ids === [] && $np !== null) {
                nl_listen_setzen((int)$np['id'], [], 'konto'); // nur sichtbare Listen (Review B-6)
            } elseif ($ids !== []) {
                nl_listen_setzen(nl_person_anlegen((string)$k['email'], tk_namen($k)['vorname']), $ids, 'konto');
            }
            flash_setzen('ok', 'Newsletter gespeichert.');
            umleiten('/konto');
        case 'passwort_setzen':
            if (!$pwNoetig) {
                umleiten('/konto');
            }
            $f = passwort_regel_fehler((string)($_POST['passwort'] ?? ''), (string)($_POST['passwort2'] ?? ''), $pwMin);
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
            if (($f = passwort_bestaetigen($k, (string)($_POST['alt'] ?? ''), 'Das bisherige Passwort stimmt nicht.')) !== null) {
                $fehler['alt'] = $f; // mit Ratenschutz (Review S-4)
            } elseif (($f = passwort_regel_fehler((string)($_POST['neu'] ?? ''), (string)($_POST['neu2'] ?? ''), $pwMin)) !== null) {
                $fehler['neu'] = $f;
            } else {
                konto_passwort_setzen($id, (string)$_POST['neu']);
                // Andere Sitzungen enden (neue sitzung_version), diese bleibt – beim Team samt zweitem Faktor
                if ($team) {
                    $_SESSION['konto'] = ['id' => $id, 'version' => (int)konto_laden($id)['sitzung_version']];
                }
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
            if ($team) {
                umleiten('/konto');
            }
            $f = tk_email_aendern($id, (string)($_POST['email'] ?? ''));
            if ($f === null) {
                flash_setzen('ok', 'An die neue Adresse ist ein Link unterwegs. Erst damit gilt sie; bis dahin bleibt die bisherige.');
                umleiten('/konto');
            }
            $fehler['email'] = $f;
            break;
        case 'pk_entfernen':
            // Passkey entfernen (E-55); Team-Konten behalten immer einen zweiten Faktor
            $f = pk_entfernen($id, (int)($_POST['pk'] ?? 0));
            flash_setzen($f === null ? 'ok' : 'fehler', $f ?? 'Passkey entfernt.');
            umleiten('/konto#passkeys');
        case 'pk_umbenennen':
            pk_umbenennen($id, (int)($_POST['pk'] ?? 0), (string)($_POST['bezeichnung'] ?? ''));
            flash_setzen('ok', 'Passkey umbenannt.');
            umleiten('/konto#passkeys');
        case 'loeschen':
            if ($team) {
                umleiten('/konto'); // Team-Konten löscht ein Betreiber
            }
            if (($f = passwort_bestaetigen($k, (string)($_POST['passwort'] ?? ''), 'Das Passwort stimmt nicht. Das Konto wurde nicht gelöscht.')) !== null) {
                $fehler['loeschen'] = $f; // mit Ratenschutz (Review S-4)
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

seite_kopf('Mein Konto', ['aktiv' => '/konto', 'skripte' => ['anmelden.js', 'passkey.js']]);
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
        <p>Mindestens <?= $pwMin ?> Zeichen. Am besten eins aus deinem Passwortmanager.</p>
        <form class="form" method="post" action="/konto" novalidate>
            <?= csrf_feld() ?><input type="hidden" name="aktion" value="passwort_setzen">
            <input type="email" name="email" value="<?= e($k['email']) ?>" autocomplete="username" hidden>
            <div class="feld"><label for="passwort">Neues Passwort</label><input id="passwort" name="passwort" type="password" autocomplete="new-password" minlength="<?= $pwMin ?>" required<?= $ungueltig('passwort') ?>><?= $fehlerFeld('passwort') ?></div>
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
    <?php if ($fehler !== []): ?><div class="meldung fehler" role="alert">Nicht gespeichert. <?= isset($fehler['_']) ? e($fehler['_']) : 'Bitte prüf die markierten Angaben.' ?></div><?php endif; ?>
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

<?php
// Aufbau nach Entwurf 7 (AP-12, E-60): links Profil, Veranstaltungen, Newsletter;
// rechts „Einloggen und Sicherheit“ in einer Kachel; Konto löschen ganz unten.
$vaAktuell = va_zeile();
$frueher = array_values(array_filter(tk_veranstaltungen((int)$k['id']), fn($f) => $a === null || $f['id'] !== (int)$a['id']));
$pwOffen = isset($fehler['alt']) || isset($fehler['neu']);
$emailOffen = isset($fehler['email']);
?>
<div class="innen raster abschnitt konto-raster">
    <div class="spalte">
        <h2 class="gruppen-titel">Profil und Veranstaltungen</h2>
        <section class="kasten" aria-labelledby="h-profil">
            <h3 class="zwischen klein-zwischen" id="h-profil">Profil</h3>
            <dl class="liste-def">
                <div><dt>Name</dt><dd><?= e(person_name($namen)) ?></dd></div>
                <div><dt>E-Mail</dt><dd><?= e($k['email']) ?></dd></div>
                <div><dt>Berufsgruppe</dt><dd><?= $profil['berufsgruppe'] !== '' ? e($profil['berufsgruppe'] . ($profil['beruf_sonstige'] !== '' ? ' (' . $profil['beruf_sonstige'] . ')' : '')) : '<span class="meta">–</span>' ?></dd></div>
                <div><dt>Institution</dt><dd><?= $profil['institution'] !== '' ? e($profil['institution']) : '<span class="meta">–</span>' ?></dd></div>
                <?php if ($profil['berufsgruppe'] === ($gruppen[0] ?? '')): ?><div><dt>EFN</dt><dd class="mono"><?= $profil['efn'] !== null ? e(efn_text($profil['efn'])) : '<span class="meta">– nicht angegeben</span>' ?></dd></div><?php endif; ?>
                <?php if ($team): ?><div><dt>Rolle</dt><dd class="konto-rolle"><span class="rolle-etikett"><?= e(ROLLEN[$k['rolle']] ?? '') ?></span>
                    <span class="meta klein"><?= $k['rolle'] === 'admin' ? 'Admin für:' : 'Zuständig für:' ?></span>
                    <ul class="rechte-liste"><?php foreach (va_rechte_liste($k) as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul></dd></div><?php endif; ?>
            </dl>
            <div class="aktionen abstand"><a class="knopf zweit" href="/konto?aendern=profil">Profil bearbeiten</a></div>
            <?php if ($team): ?>
            <p class="meta klein">Die Adresse eines Team-Kontos ändert eine BetreiberIn unter Team-Konten.</p>
            <?php else: ?>
            <details class="aufklapp"<?= $emailOffen ? ' open' : '' ?>><summary class="knopf zweit klein">E-Mail-Adresse ändern</summary>
                <form class="form" method="post" action="/konto" novalidate>
                    <?= csrf_feld() ?><input type="hidden" name="aktion" value="email">
                    <div class="feld"><label for="neue-email">Neue Adresse</label><input id="neue-email" name="email" type="email" autocomplete="email" maxlength="190"<?= $ungueltig('email') ?>><p class="hilfe">Gilt erst, wenn du den Link bestätigst, den wir dorthin schicken<?= $a !== null ? '; deine Anmeldung zieht mit' : '' ?>.</p><?= $fehlerFeld('email') ?></div>
                    <div><button class="knopf" type="submit">Link schicken</button></div>
                </form>
            </details>
            <?php endif; ?>
        </section>

        <section class="kasten" id="veranstaltungen" aria-labelledby="h-va">
            <h3 class="zwischen klein-zwischen" id="h-va">Deine Veranstaltungen</h3>
            <?php if ($vaAktuell !== null && $vaAktuell['status'] !== 'abgeschlossen'): ?>
            <div class="als-naechstes">
                <p class="va-label">Als Nächstes</p>
                <p class="naechstes-zeile"><span><strong><?= e(va('veranstaltung_name') ?: va_kurz()) ?></strong><?= va('veranstaltung_datum') !== '' ? '<br><span class="mono meta klein">' . e(tag_text(va('veranstaltung_datum'))) . '</span>' : '' ?></span>
                    <?php if ($a !== null): ?><span class="chip <?= $a['status'] === 'bestaetigt' ? 'ok' : 'offen' ?>"><?= $a['status'] === 'bestaetigt' ? 'Angemeldet' : 'Noch nicht bestätigt' ?></span>
                    <?php else: ?><span class="chip offen">Nicht angemeldet</span><?php endif; ?></p>
                <?php if ($a !== null): ?>
                    <p class="meta klein">Anmeldung Nr. <?= anm_nummer((int)$a['id']) ?></p>
                    <div class="aktionen"><a class="knopf" href="/deine-anmeldung">Workshops, Essen, absagen</a><?php if (zertifikat_verfuegbar($a)): ?><a class="knopf zweit" href="/konto/zertifikat?a=<?= (int)$a['id'] ?>">Bescheinigung (PDF)</a><?php endif; ?></div>
                <?php elseif (anm_zeitraum() === 'offen'): ?>
                    <p class="meta klein">Mit deinem Konto sind Name und Adresse schon ausgefüllt, eine Bestätigung per Mail entfällt.</p>
                    <div><a class="knopf gruen" href="/anmelden">Jetzt anmelden</a></div>
                <?php else: ?>
                    <p class="meta klein"><?= anm_zeitraum() === 'vorher' ? 'Die Anmeldung öffnet am ' . e(datum_zeit_text(va('anmeldung_start'))) . ' Uhr – mit Konto sind deine Angaben schon ausgefüllt.' : 'Die Anmeldung ist geschlossen.' ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($frueher !== []): ?>
            <p class="va-label abstand-oben-klein">Vergangene</p>
            <ul class="va-liste">
            <?php foreach ($frueher as $f): ?>
                <li><span class="va-rueck-name"><strong><?= e($f['name']) ?></strong><?= $f['datum'] !== '' ? '<br><span class="mono meta klein">' . e(tag_text($f['datum'])) . '</span>' : '' ?></span>
                    <span class="chip <?= $f['teilgenommen'] ? 'ok' : 'offen' ?>"><?= e($f['stand']) ?></span>
                    <?php if ($f['zertifikat']): ?><a class="klein-link" href="/konto/zertifikat?a=<?= (int)$f['id'] ?>">Bescheinigung (PDF)</a><?php endif; ?>
                    <?php if ($f['status'] !== 'abgeschlossen'): ?><a class="klein-link" href="/<?= e($f['kuerzel']) ?>">Zur Veranstaltung</a><?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
            <p class="meta klein">Bleibt hier, solange es dein Konto gibt.</p>
            <?php elseif ($vaAktuell === null || $vaAktuell['status'] === 'abgeschlossen'): ?>
            <p class="meta">Noch keine Veranstaltung.</p>
            <?php endif; ?>
        </section>

        <?php $np = nl_person_per_email((string)$k['email']); $abos = $np !== null ? nl_abos((int)$np['id']) : []; ?>
        <section class="kasten" aria-labelledby="h-nl">
            <h3 class="zwischen klein-zwischen" id="h-nl">Newsletter</h3>
            <form class="form" method="post" action="/konto"><?= csrf_feld() ?><input type="hidden" name="aktion" value="newsletter">
                <fieldset class="feld nl-listen"><legend class="sr">Newsletter</legend>
                <?php foreach (nl_listen(true) as $l): ?><label class="haken"><input type="checkbox" name="listen[]" value="<?= (int)$l['id'] ?>"<?= ($abos[(int)$l['id']] ?? '') === 'bestaetigt' ? ' checked' : '' ?>><span><strong><?= e($l['name']) ?></strong><?= $l['beschreibung'] !== '' ? '<br><span class="meta klein">' . editor_html($l['beschreibung'], 'inline') . '</span>' : '' ?></span></label><?php endforeach; ?>
                </fieldset>
                <p class="meta klein"><?= e(NL_EINWILLIGUNG) ?></p>
                <div><button class="knopf zweit" type="submit">Newsletter speichern</button></div>
            </form>
        </section>
    </div>

    <div class="spalte">
        <h2 class="gruppen-titel">Einloggen und Sicherheit</h2>
        <section class="kasten sicherheit" aria-label="Einloggen und Sicherheit">
            <?php if ($team): ?>
            <div class="pflicht-balken" role="note"><?= icon('shield-check', 26) ?><span><strong>Pflicht für Team-Konten:</strong> der zweite Schritt beim Einloggen. Richte mindestens einen Weg ein und bewahre die Notfall-Codes gut auf.</span></div>
            <?php endif; ?>

            <div class="sicherheit-teil">
                <h3 class="zwischen klein-zwischen">Passwort</h3>
                <details class="aufklapp"<?= $pwOffen ? ' open' : '' ?>><summary class="knopf zweit klein">Passwort ändern</summary>
                    <form class="form" method="post" action="/konto" novalidate>
                        <?= csrf_feld() ?><input type="hidden" name="aktion" value="passwort_aendern">
                        <input type="email" name="email" value="<?= e($k['email']) ?>" autocomplete="username" hidden>
                        <div class="feld"><label for="alt">Bisheriges Passwort</label><input id="alt" name="alt" type="password" autocomplete="current-password"<?= $ungueltig('alt') ?>><?= $fehlerFeld('alt') ?></div>
                        <div class="feld"><label for="neu">Neues Passwort</label><input id="neu" name="neu" type="password" autocomplete="new-password" minlength="<?= $pwMin ?>"<?= $ungueltig('neu') ?>><p class="hilfe">mindestens <?= $pwMin ?> Zeichen</p><?= $fehlerFeld('neu') ?></div>
                        <div class="feld"><label for="neu2">Neues Passwort wiederholen</label><input id="neu2" name="neu2" type="password" autocomplete="new-password"></div>
                        <div><button class="knopf" type="submit">Speichern</button></div>
                    </form>
                </details>
            </div>

            <?php if ($team): $zf = totp_zustand($id); ?>
            <div class="sicherheit-teil">
                <h3 class="zwischen klein-zwischen">Zweiter Schritt beim Einloggen</h3>
                <p class="meta klein">Nach dem Passwort fragen wir einen Code ab – aus einer App auf deinem Handy oder per E-Mail. So kommt niemand nur mit deinem Passwort hinein.</p>
                <dl class="liste-def zustand-liste">
                    <div><dt>Code aus der App</dt><dd><?= $zf['an'] ? '<span class="chip ok">eingerichtet</span>' : '<span class="chip offen">nicht eingerichtet</span>' ?></dd></div>
                    <div><dt>Code per E-Mail</dt><dd><?= $zf['mail'] ? '<span class="chip ok">an</span>' : '<span class="chip offen">aus</span>' ?></dd></div>
                    <div><dt>Notfall-Codes <span class="meta klein">(falls das Handy weg ist)</span></dt><dd class="mono"><?= $zf['codes_offen'] ?> von <?= TOTP_CODES ?></dd></div>
                </dl>
                <div><a class="knopf zweit klein" href="/konto/zweitfaktor">Einstellen</a></div>
            </div>
            <?php endif; ?>

            <?php $pks = pk_liste($id); $pkHier = pk_ursprung(); ?>
            <div class="sicherheit-teil" id="passkeys">
                <h3 class="zwischen klein-zwischen">Einloggen ohne Passwort</h3>
                <p class="meta klein">Mit einem <strong>Passkey</strong> wird dein Handy oder Computer zum Schlüssel – entsperrt mit Fingerabdruck, Gesicht oder PIN.<?= $team ? ' Zählt auch als zweiter Schritt.' : ' Freiwillig.' ?></p>
                <?php if ($pks === []): ?><p>Noch kein Passkey.</p><?php else: ?>
                <ul class="pk-liste">
                <?php foreach ($pks as $p): $name = $p['bezeichnung'] !== '' ? $p['bezeichnung'] : 'Passkey vom ' . datum_text(substr((string)$p['erstellt_am'], 0, 10)); ?>
                    <li><div><strong><?= e($name) ?></strong><span class="meta klein">angelegt <?= e(datum_zeit_text($p['erstellt_am'])) ?> · <?= $p['zuletzt_am'] !== null ? 'zuletzt benutzt ' . e(datum_zeit_text($p['zuletzt_am'])) : 'noch nicht benutzt' ?><?= $pkHier !== null && $p['rp_id'] !== $pkHier['rp_id'] ? ' · gilt nur unter ' . e($p['rp_id']) : '' ?></span></div>
                        <div class="pk-aktionen">
                            <details class="bestaetigen" data-art="lila" data-titel="Passkey umbenennen"><summary class="klein-link">Umbenennen</summary>
                                <form method="post" action="/konto"><?= csrf_feld() ?><input type="hidden" name="aktion" value="pk_umbenennen"><input type="hidden" name="pk" value="<?= (int)$p['id'] ?>">
                                    <label for="pk-name-<?= (int)$p['id'] ?>">Neuer Name</label><input id="pk-name-<?= (int)$p['id'] ?>" name="bezeichnung" type="text" maxlength="40" value="<?= e($p['bezeichnung']) ?>">
                                    <button type="submit" class="knopf">Speichern</button></form>
                            </details>
                            <details class="bestaetigen" data-titel="Passkey entfernen?"><summary class="klein-link">Entfernen</summary>
                                <form method="post" action="/konto"><?= csrf_feld() ?><input type="hidden" name="aktion" value="pk_entfernen"><input type="hidden" name="pk" value="<?= (int)$p['id'] ?>">
                                    <p>„<?= e($name) ?>“ gilt danach nicht mehr. Lösch ihn auch im Passwortmanager bzw. auf dem Gerät.</p>
                                    <button type="submit" class="knopf gefahr">Ja, entfernen</button></form>
                            </details>
                        </div></li>
                <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <?php if ($pkHier !== null): ?>
                <details class="aufklapp" data-passkey-anlage hidden><summary class="knopf zweit klein">Passkey hinzufügen</summary>
                    <form class="form" data-passkey-anlegen>
                        <input type="email" name="email" value="<?= e($k['email']) ?>" autocomplete="username webauthn" hidden>
                        <div class="feld"><label for="pk-bez">Name für dieses Gerät <span class="meta">(z. B. „Handy“)</span></label><input id="pk-bez" name="bezeichnung" type="text" maxlength="40" autocomplete="off"></div>
                        <div class="feld"><label for="pk-pw">Dein Passwort zur Bestätigung</label><input id="pk-pw" name="pw" type="password" autocomplete="current-password" required></div>
                        <div><button class="knopf" type="submit">Weiter</button></div>
                        <p class="feldfehler" data-passkey-meldung role="alert" hidden></p>
                    </form>
                </details>
                <noscript><p class="meta klein">Passkeys brauchen JavaScript.</p></noscript>
                <?php else: ?><p class="meta klein">Unter dieser Adresse gehen keine Passkeys (nur mit HTTPS und Domain).</p><?php endif; ?>
            </div>
        </section>

        <?php if (!$team): ?>
        <section class="kasten gefahr-kasten" aria-labelledby="h-loeschen">
            <h3 class="zwischen klein-zwischen" id="h-loeschen">Konto löschen</h3>
            <p class="meta klein">Ohne Login löschen wir dein Konto nach <?= KONTO_LOESCHEN_MONATE ?> Monaten von selbst; <?= KONTO_ERINNERN_TAGE ?> Tage vorher schicken wir dir eine Erinnerung. Einloggen genügt, damit es bleibt.</p>
            <?= $fehlerFeld('loeschen') ?>
            <details class="bestaetigen"<?= isset($fehler['loeschen']) ? ' open' : '' ?>><summary class="knopf gefahr klein">Konto löschen …</summary>
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
        </section>
        <?php endif; ?>
    </div>
</div>
<?php
seite_fuss();
