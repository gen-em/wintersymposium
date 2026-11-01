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
$pwNoetig = $k['passwort_hash'] === null || !empty($_SESSION[SITZUNG_SCHLUESSEL_TK_PW]);
$modus = (string)($_GET['aendern'] ?? '');

if (ist_post()) {
    switch (aktion()) {
        case 'newsletter':
            // AP-7: Adresse ist durch das bestätigte Konto bewiesen – Listen gelten gleich
            $ids = array_map('intval', (array)($_POST['listen'] ?? []));
            $np = nl_person_per_email((string)$k['email']);
            if ($ids === [] && $np !== null) {
                nl_listen_setzen((int)$np['id'], [], 'konto'); // nur sichtbare Listen (Review B-6)
            } elseif ($ids !== []) {
                nl_listen_setzen(nl_person_anlegen((string)$k['email'], tk_namen($k)['vorname']), $ids, 'konto');
            }
            fertig('ok', 'Newsletter gespeichert.', '/konto');
        case 'passwort_setzen':
            if (!$pwNoetig) {
                umleiten('/konto');
            }
            $f = passwort_regel_fehler(post_text('passwort'), post_text('passwort2'), $pwMin);
            if ($f === null) {
                konto_passwort_setzen($id, post_text('passwort'));
                unset($_SESSION[SITZUNG_SCHLUESSEL_TK_PW]);
                tk_einloggen(konto_laden($id));
                fertig('ok', 'Dein Passwort ist gespeichert. Ab jetzt kannst du dich mit Adresse und Passwort einloggen.', '/konto');
            }
            $fehler['passwort'] = $f;
            break;
        case 'passwort_aendern':
            if (($f = passwort_bestaetigen($k, post_text('alt'), 'Das bisherige Passwort stimmt nicht.')) !== null) {
                $fehler['alt'] = $f; // mit Ratenschutz (Review S-4)
            } elseif (($f = passwort_regel_fehler(post_text('neu'), post_text('neu2'), $pwMin)) !== null) {
                $fehler['neu'] = $f;
            } else {
                konto_passwort_setzen($id, post_text('neu'));
                // Andere Sitzungen enden (neue sitzung_version), diese bleibt – beim Team samt zweitem Faktor
                if ($team) {
                    $_SESSION[SITZUNG_SCHLUESSEL_KONTO] = ['id' => $id, 'version' => (int)konto_laden($id)['sitzung_version']];
                }
                tk_einloggen(konto_laden($id));
                fertig('ok', 'Dein Passwort ist geändert. Andere Sitzungen sind beendet.', '/konto');
            }
            break;
        case 'profil':
            $fehler = tk_profil_setzen($id, $_POST);
            if ($fehler === []) {
                fertig('ok', 'Dein Profil ist gespeichert.', '/konto');
            }
            $eingabe = $_POST;
            $modus = 'profil';
            break;
        case 'email':
            if ($team) {
                umleiten('/konto');
            }
            $f = tk_email_aendern($id, post_text('email'));
            if ($f === null) {
                fertig('ok', 'An die neue Adresse ist ein Link unterwegs. Erst damit gilt sie; bis dahin bleibt die bisherige.', '/konto');
            }
            $fehler['email'] = $f;
            break;
        case 'pk_entfernen':
            // Passkey entfernen (E-55); Team-Konten behalten immer einen zweiten Faktor
            fertig_oder_fehler(pk_entfernen($id, post_zahl('pk')), 'Passkey entfernt.', '/konto#passkeys');
        case 'pk_umbenennen':
            pk_umbenennen($id, post_zahl('pk'), post_text('bezeichnung'));
            fertig('ok', 'Passkey umbenannt.', '/konto#passkeys');
        case 'loeschen':
            if ($team) {
                umleiten('/konto'); // Team-Konten löscht ein Betreiber
            }
            $mitAnmeldung = post_text('anmeldung') === 'stornieren';
            if (($f = passwort_bestaetigen($k, post_text('passwort'), 'Das Passwort stimmt nicht. Das Konto wurde nicht gelöscht.')) !== null) {
                $fehler['loeschen'] = $f; // mit Ratenschutz (Review S-4)
            } elseif (($f = tk_loeschen($id, $mitAnmeldung)) !== null) {
                $fehler['loeschen'] = $f;
            } else {
                anm_ausloggen();
                fertig('ok', $mitAnmeldung ? 'Dein Konto und deine Anmeldung sind gelöscht.' : 'Dein Konto ist gelöscht. Deine Anmeldung bleibt; du erreichst sie über „Link per Mail“.', '/deine-anmeldung');
            }
            break;
    }
}

$k = konto_laden($id);
$a = tk_anmeldung($id);
$meldung = flash_holen();
$gruppen = va_liste('berufsgruppen');
$namen = $a !== null ? ['vorname' => $a['vorname'], 'nachname' => $a['nachname']] : tk_namen($k);
$profil = $a ?? $k;
$benutzer = '<input type="email" name="email" value="' . e($k['email']) . '" autocomplete="username" hidden>';

seite_kopf('Mein Konto', ['aktiv' => '/konto', 'skripte' => ['anmelden.js', 'passkey.js']]);
?>
<?= seiten_kopfzeile('Hallo ' . $namen['vorname'] . '.', 'Mein Konto') ?>
<div class="innen">
<?= meldung_html($meldung) ?>
<?php if ($pwNoetig): ?>
    <div class="kasten schmal-kasten">
        <h2 class="zwischen klein-zwischen">Passwort festlegen</h2>
        <p>Mindestens <?= $pwMin ?> Zeichen. Am besten eins aus deinem Passwortmanager.</p>
        <form class="form" method="post" action="/konto" novalidate>
            <?= csrf_feld() ?><input type="hidden" name="aktion" value="passwort_setzen">
            <?= $benutzer ?>
            <?= feld('passwort', 'Neues Passwort', ['typ' => 'passwort', 'pflicht' => true, 'fehler' => $fehler['passwort'] ?? null, 'attr' => ['autocomplete' => 'new-password', 'minlength' => $pwMin]]) ?>
            <?= feld('passwort2', 'Noch einmal', ['typ' => 'passwort', 'pflicht' => true, 'attr' => ['autocomplete' => 'new-password']]) ?>
            <div><button class="knopf" type="submit">Passwort speichern</button></div>
        </form>
    </div>
</div>
<?php seite_ende(); endif; ?>
</div>

<?php if ($modus === 'profil'): ?>
<div class="innen abschnitt">
    <?php if ($fehler !== []): ?><?= meldung_html(isset($fehler['_']) ? 'Nicht gespeichert. ' . $fehler['_'] : NICHT_GESPEICHERT_TEXT, 'fehler') ?><?php endif; ?>
    <form class="form" method="post" action="/konto" novalidate>
        <?= csrf_feld() ?><input type="hidden" name="aktion" value="profil">
        <h2 class="zwischen">Dein Profil</h2>
        <?= profil_felder_html($eingabe !== [] ? $eingabe : $namen + $profil, $fehler, ['email' => 'ohne']) ?>
        <div class="aktionen"><button class="knopf" type="submit">Speichern</button><a class="knopf zweit" href="/konto">Abbrechen</a></div>
    </form>
</div>
<?php seite_ende(); endif; ?>

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
                <?php if ($team): ?><div><dt>Rolle</dt><dd class="konto-rolle"><span class="chip rolle-leise"><?= e(ROLLEN[$k['rolle']] ?? '') ?></span>
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
                    <?= feld('email', 'Neue Adresse', ['typ' => 'email', 'id' => 'neue-email', 'fehler' => $fehler['email'] ?? null, 'attr' => ['autocomplete' => 'email', 'maxlength' => 190],
                        'hilfe' => 'Gilt erst, wenn du den Link bestätigst, den wir dorthin schicken' . ($a !== null ? '; deine Anmeldung zieht mit' : '') . '.']) ?>
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
                    <?= $a !== null ? chip(ANM_STATUS_CHIP[$a['status']] ?? 'offen', $a['status'] === 'bestaetigt' ? 'Angemeldet' : 'Noch nicht bestätigt') : chip('offen', 'Nicht angemeldet') ?></p>
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
                    <?= chip($f['teilgenommen'] ? 'ok' : 'offen', $f['stand']) ?>
                    <?php if ($f['zertifikat']): ?><a class="klein-link" href="/konto/zertifikat?a=<?= (int)$f['id'] ?>">Bescheinigung (PDF)</a><?php endif; ?>
                    <?php if ($f['status'] !== 'abgeschlossen'): ?><a class="klein-link" href="/<?= e($f['kuerzel']) ?>">Zur Veranstaltung</a><?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
            <p class="meta klein">Bleibt hier, solange es dein Konto gibt.</p>
            <?php elseif ($vaAktuell === null || $vaAktuell['status'] === 'abgeschlossen'): ?>
            <?= leer_hinweis('Noch keine Veranstaltung.') ?>
            <?php endif; ?>
        </section>

        <?php $np = nl_person_per_email((string)$k['email']); $abos = $np !== null ? nl_abos((int)$np['id']) : []; ?>
        <section class="kasten" aria-labelledby="h-nl">
            <h3 class="zwischen klein-zwischen" id="h-nl">Newsletter</h3>
            <form class="form" method="post" action="/konto"><?= csrf_feld() ?><input type="hidden" name="aktion" value="newsletter">
                <fieldset class="feld nl-listen"><legend class="sr">Newsletter</legend>
                <?php foreach (nl_listen(true) as $l): ?><?= feld('listen[]', $l['name'], ['typ' => 'haken', 'wert_haken' => (int)$l['id'], 'wert' => ($abos[(int)$l['id']] ?? '') === 'bestaetigt',
                    'label_html' => '<strong>' . e($l['name']) . '</strong>' . ($l['beschreibung'] !== '' ? '<br><span class="meta klein">' . editor_html($l['beschreibung'], 'inline') . '</span>' : '')]) ?><?php endforeach; ?>
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
                        <?= $benutzer ?>
                        <?= feld('alt', 'Bisheriges Passwort', ['typ' => 'passwort', 'fehler' => $fehler['alt'] ?? null, 'attr' => ['autocomplete' => 'current-password']]) ?>
                        <?= feld('neu', 'Neues Passwort', ['typ' => 'passwort', 'fehler' => $fehler['neu'] ?? null, 'hilfe' => 'mindestens ' . $pwMin . ' Zeichen', 'attr' => ['autocomplete' => 'new-password', 'minlength' => $pwMin]]) ?>
                        <?= feld('neu2', 'Neues Passwort wiederholen', ['typ' => 'passwort', 'attr' => ['autocomplete' => 'new-password']]) ?>
                        <div><button class="knopf" type="submit">Speichern</button></div>
                    </form>
                </details>
            </div>

            <?php if ($team): $zf = totp_zustand($id); ?>
            <div class="sicherheit-teil">
                <h3 class="zwischen klein-zwischen">Zweiter Schritt beim Einloggen</h3>
                <p class="meta klein">Nach dem Passwort fragen wir einen Code ab – aus einer App auf deinem Handy oder per E-Mail. So kommt niemand nur mit deinem Passwort hinein.</p>
                <dl class="liste-def zustand-liste">
                    <div><dt>Code aus der App</dt><dd><?= $zf['an'] ? chip('ok', 'eingerichtet') : chip('offen', 'nicht eingerichtet') ?></dd></div>
                    <div><dt>Code per E-Mail</dt><dd><?= $zf['mail'] ? chip('ok', 'an') : chip('offen', 'aus') ?></dd></div>
                    <div><dt>Notfall-Codes <span class="meta klein">(falls das Handy weg ist)</span></dt><dd class="mono"><?= $zf['codes_offen'] ?> von <?= TOTP_CODES ?></dd></div>
                </dl>
                <div><a class="knopf zweit klein" href="/konto/zweitfaktor">Einstellen</a></div>
            </div>
            <?php endif; ?>

            <?php $pks = pk_liste($id); $pkHier = pk_ursprung(); ?>
            <div class="sicherheit-teil" id="passkeys">
                <h3 class="zwischen klein-zwischen">Einloggen ohne Passwort</h3>
                <p class="meta klein">Mit einem <strong>Passkey</strong> wird dein Handy oder Computer zum Schlüssel – entsperrt mit Fingerabdruck, Gesicht oder PIN.<?= $team ? ' Zählt auch als zweiter Schritt.' : ' Freiwillig.' ?></p>
                <?php if ($pks === []): ?><?= leer_hinweis('Noch kein Passkey.') ?><?php else: ?>
                <ul class="pk-liste">
                <?php foreach ($pks as $p): $name = $p['bezeichnung'] !== '' ? $p['bezeichnung'] : 'Passkey vom ' . datum_text(substr((string)$p['erstellt_am'], 0, 10)); ?>
                    <li><div><strong><?= e($name) ?></strong><span class="meta klein">angelegt <?= e(datum_zeit_text($p['erstellt_am'])) ?> · <?= $p['zuletzt_am'] !== null ? 'zuletzt benutzt ' . e(datum_zeit_text($p['zuletzt_am'])) : 'noch nicht benutzt' ?><?= $pkHier !== null && $p['rp_id'] !== $pkHier['rp_id'] ? ' · gilt nur unter ' . e($p['rp_id']) : '' ?></span></div>
                        <div class="pk-aktionen">
                            <?= bestaetigung_html('Umbenennen', '', 'Speichern', ['aktion' => 'pk_umbenennen', 'pk' => (int)$p['id']], ['ziel' => '/konto', 'art' => 'lila', 'titel' => 'Passkey umbenennen', 'knopfklasse' => 'klein-link',
                                'inhalt_html' => feld('bezeichnung', 'Neuer Name', ['id' => 'pk-name-' . (int)$p['id'], 'wert' => $p['bezeichnung'], 'ohne_rahmen' => true, 'attr' => ['maxlength' => 40]])]) ?>
                            <?= bestaetigung_html('Entfernen', '„' . $name . '“ gilt danach nicht mehr. Lösch ihn auch im Passwortmanager bzw. auf dem Gerät.', 'Ja, entfernen', ['aktion' => 'pk_entfernen', 'pk' => (int)$p['id']],
                                ['ziel' => '/konto', 'titel' => 'Passkey entfernen?', 'knopfklasse' => 'klein-link']) ?>
                        </div></li>
                <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <?php if ($pkHier !== null): ?>
                <details class="aufklapp" data-passkey-anlage hidden><summary class="knopf zweit klein">Passkey hinzufügen</summary>
                    <form class="form" data-passkey-anlegen>
                        <input type="email" name="email" value="<?= e($k['email']) ?>" autocomplete="username webauthn" hidden>
                        <?= feld('bezeichnung', 'Name für dieses Gerät', ['id' => 'pk-bez', 'zusatz' => '(z. B. „Handy“)', 'attr' => ['maxlength' => 40, 'autocomplete' => 'off']]) ?>
                        <?= feld('pw', 'Dein Passwort zur Bestätigung', ['typ' => 'passwort', 'id' => 'pk-pw', 'pflicht' => true, 'attr' => ['autocomplete' => 'current-password']]) ?>
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
            <?= isset($fehler['loeschen']) ? '<p class="feldfehler" id="f-loeschen">' . e($fehler['loeschen']) . '</p>' : '' ?>
            <?php
            $wahl = '';
            if ($a !== null) {
                // Von Hand: nur die zweite Wahl ist nach dem Stichtag gesperrt (feld() kennt kein disabled je Option)
                $storno = anm_frist_offen('stichtag_storno');
                $wahl = '<fieldset class="feld"><legend>Und deine Anmeldung?</legend><div class="wahl">'
                    . '<label><input type="radio" name="anmeldung" value="behalten" checked><span>Anmeldung behalten – du erreichst sie weiter über „Link per Mail“</span></label>'
                    . '<label><input type="radio" name="anmeldung" value="stornieren"' . ($storno ? '' : ' disabled') . '><span>Anmeldung auch stornieren – deine Plätze gehen an die Warteliste' . ($storno ? '' : ' (nicht mehr möglich, Stichtag vorbei)') . '</span></label>'
                    . '</div></fieldset>';
            }
            ?>
            <?= bestaetigung_html('Konto löschen …', 'Das Konto wird endgültig gelöscht.', 'Ja, löschen', ['aktion' => 'loeschen'], ['ziel' => '/konto', 'knopfklasse' => 'knopf gefahr klein',
                'offen' => isset($fehler['loeschen']), 'vor_frage' => true,
                'inhalt_html' => $wahl . $benutzer . feld('passwort', 'Passwort zur Bestätigung', ['typ' => 'passwort', 'id' => 'loeschen-pw', 'attr' => ['autocomplete' => 'current-password']])]) ?>
        </section>
        <?php endif; ?>
    </div>
</div>
<?php
seite_fuss();
