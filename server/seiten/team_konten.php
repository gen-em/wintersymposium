<?php
declare(strict_types=1);

/*
 * Team-Konten (nur Betreiber, E-11): anlegen, Einrichtungs-/Passwortlink
 * (per Mail oder angezeigt, AP-6),
 * zweiten Faktor zurücksetzen, sperren, Rolle und Adresse ändern (O-48). Rolle
 * und Adresse ändern, zweiten Faktor zurücksetzen und Sperren verlangen den
 * eigenen zweiten Faktor (E-41,
 * Review B-7: Code aus App oder Mail, Wiederherstellungscode oder Passkey). Das eigene Konto lässt sich
 * hier nicht sperren oder herabstufen, und es bleibt immer mindestens ein
 * aktiver Betreiber. Admins sind für alle Veranstaltungen (auch künftige) oder
 * nur für ausgewählte freigeschaltet (AP-12, E-60).
 */

$konto = team_tor(['betreiber']);
$ich = (int)$konto['id'];
$fehler = [];
$werte = ['name' => '', 'email' => '', 'rolle' => 'admin'];

if (ist_post()) {
    zweitfaktor_mailcode_aktion($konto, '/team/konten');
    $aktion = aktion();
    $ziel = isset($_POST['id']) ? konto_laden(post_zahl('id')) : null;
    if ($aktion !== 'anlegen' && (!konto_ist_team($ziel))) {
        $fehler[] = 'Dieses Konto gibt es nicht.';
    } elseif ($aktion !== 'anlegen' && (int)$ziel['id'] === $ich) {
        $fehler[] = 'Dein eigenes Konto änderst du unter „Mein Konto“.';
    } else {
        switch ($aktion) {
            case 'anlegen':
                $werte = ['name' => post_text('name'), 'email' => post_text('email'), 'rolle' => post_text('rolle')];
                $fehler = in_array($werte['rolle'], TEAM_ROLLEN, true) ? konto_eingabe_fehler($werte['name'], $werte['email'], $werte['rolle']) : ['Bitte Admin oder BetreiberIn wählen.'];
                if ($fehler === []) {
                    $id = konto_anlegen($werte['name'], $werte['email'], $werte['rolle'], null, $ich);
                    team_link_weitergeben(konto_laden($id), 'einrichten', !post_haken('nur_anzeigen'), $ich);
                    umleiten('/team/konten');
                }
                break;
            case 'link':
                team_link_weitergeben($ziel, $ziel['passwort_hash'] === null ? 'einrichten' : 'passwort', !post_haken('nur_anzeigen'), $ich);
                umleiten('/team/konten');
            case 'zweitfaktor':
                if (($f = zweitfaktor_aus_formular($ich)) !== null) {
                    $fehler[] = $f . ' Nichts zurückgesetzt.';
                    break;
                }
                totp_zuruecksetzen((int)$ziel['id'], 'durch BetreiberIn', $ich);
                fertig('ok', 'Zweiter Faktor von ' . $ziel['name'] . ' zurückgesetzt. Beim nächsten Einloggen richtet ' . $ziel['name'] . ' ihn neu ein.', '/team/konten');
            case 'sperren':
                if ($ziel['rolle'] === 'betreiber' && (int)$ziel['aktiv'] === 1 && konto_aktive_betreiber() < 2) {
                    $fehler[] = 'Es muss mindestens eine aktive BetreiberIn bleiben.';
                    break;
                }
                if (($f = zweitfaktor_aus_formular($ich)) !== null) {
                    $fehler[] = $f . ' Nichts geändert.';
                    break;
                }
                konto_aktiv_setzen((int)$ziel['id'], (int)$ziel['aktiv'] !== 1, $ich);
                fertig('ok', $ziel['name'] . ((int)$ziel['aktiv'] === 1 ? ' gesperrt.' : ' entsperrt.'), '/team/konten');
            case 'adresse':
                // O-48 (Admin 2026-11-01): nur BetreiberIn, mit eigenem Code, Hinweis an die alte Adresse
                if (($f = zweitfaktor_aus_formular($ich)) !== null) {
                    $fehler[] = $f . ' Die Adresse wurde nicht geändert.';
                } elseif (($f = konto_adresse_aendern((int)$ziel['id'], post_text('email'), $ich)) !== null) {
                    $fehler[] = $f;
                } else {
                    fertig('ok', 'Adresse von ' . $ziel['name'] . ' geändert. An die alte Adresse ging ein Hinweis.', '/team/konten');
                }
                break;
            case 'veranstaltungen':
                if ($ziel['rolle'] !== 'admin') {
                    $fehler[] = 'BetreiberInnen sehen immer alle Veranstaltungen.';
                    break;
                }
                $alle = post_text('umfang') !== 'auswahl';
                va_rechte_setzen((int)$ziel['id'], $alle, (array)($_POST['va'] ?? []), $ich, (array)($_POST['reihe'] ?? []));
                fertig('ok', 'Veranstaltungen von ' . $ziel['name'] . ': ' . va_rechte_text(konto_laden((int)$ziel['id'])) . '.', '/team/konten');
            case 'rolle':
                $rolle = post_text('rolle');
                if (!in_array($rolle, TEAM_ROLLEN, true) || $rolle === $ziel['rolle']) {
                    $fehler[] = 'Bitte eine andere Rolle wählen.';
                } elseif ($ziel['rolle'] === 'betreiber' && (int)$ziel['aktiv'] === 1 && konto_aktive_betreiber() < 2) {
                    $fehler[] = 'Es muss mindestens eine aktive BetreiberIn bleiben.';
                } elseif (($f = zweitfaktor_aus_formular($ich)) !== null) {
                    $fehler[] = $f . ' Die Rolle wurde nicht geändert.';
                } else {
                    konto_rolle_setzen((int)$ziel['id'], $rolle, $ich);
                    fertig('ok', $ziel['name'] . ' ist jetzt ' . ROLLEN[$rolle] . '.', '/team/konten');
                }
                break;
            default:
                $fehler[] = 'Unbekannte Aktion.';
        }
    }
}

$link = $_SESSION[SITZUNG_SCHLUESSEL_NEUER_LINK] ?? null;
unset($_SESSION[SITZUNG_SCHLUESSEL_NEUER_LINK]);

team_seite_kopf($konto, 'Team-Konten', '/team/konten', ['skripte' => ['zweitfaktor.js']]);
echo fehler_html($fehler);
if (is_array($link)): ?>
<div class="kasten">
    <p><strong>Link für <?= e($link['name']) ?></strong> – gib ihn persönlich oder über einen sicheren Weg weiter. Er gilt <?= e(dauer_text(KONTO_LINK_GUELTIG_S)) ?> und nur einmal und wird nur jetzt angezeigt.</p>
    <p class="mono umbruch"><?= e($link['url']) ?></p>
</div>
<?php endif; ?>
<div class="tabelle-wrap"><table class="t karten">
    <thead><tr><th>Name</th><th>Rolle</th><th>Veranstaltungen</th><th>Status</th><th>Letzte Anmeldung</th><th>Aktionen</th></tr></thead>
    <tbody>
    <?php foreach (konto_team_liste() as $k):
        $eigen = (int)$k['id'] === $ich;
        $status = (int)$k['aktiv'] !== 1 ? 'gesperrt' : ($k['hat_passwort'] ? (zweitfaktor_da($k) ? 'aktiv' : 'zweiter Faktor fehlt') : 'Einrichtung offen'); ?>
        <tr>
            <td data-label="Name"><strong><?= e($k['name']) ?></strong><?= $eigen ? ' <span class="meta">(du)</span>' : '' ?><br><span class="meta klein"><?= e($k['email']) ?></span></td>
            <td data-label="Rolle"><?= e(ROLLEN[$k['rolle']]) ?></td>
            <td data-label="Veranstaltungen" class="klein"><?= e(va_rechte_text($k)) ?></td>
            <td data-label="Status"><?= chip((int)$k['aktiv'] !== 1 ? 'gesperrt' : ($status === 'aktiv' ? 'ok' : 'offen'), $status) ?></td>
            <td data-label="Letzte Anmeldung" class="mono klein"><?= e($k['letzte_anmeldung'] ? datum_zeit_text($k['letzte_anmeldung']) : '–') ?></td>
            <td<?= $eigen ? '' : ' data-label="Aktionen"' ?>><div class="aktionen-zelle">
            <?php if (!$eigen): $kid = (int)$k['id']; $erlaubt = va_erlaubte_ids($k); $erlaubtR = va_erlaubte_reihen($k) ?? [];
                $direkt = $erlaubt === null ? [] : va_rechte_direkt($kid); ?>
                <?php /* Ein Knopf je Zeile (AP-13, Entwurf 9): Menü, jede Aktion als Dialog */ ?>
                <details class="zeilen-menue"><summary class="knopf zweit klein">Bearbeiten <?= icon('caret-down', 14) ?></summary>
                    <div class="menue-karte zeilen-liste" role="menu">
                        <?php if ($k['rolle'] === 'admin'): ?><button type="button" hidden class="menue-punkt" role="menuitem" id="m-va-<?= $kid ?>">Veranstaltungen …</button><?php endif; ?>
                        <button type="button" hidden class="menue-punkt" role="menuitem" id="m-rolle-<?= $kid ?>">Rolle ändern …</button>
                        <button type="button" hidden class="menue-punkt" role="menuitem" id="m-adresse-<?= $kid ?>">Adresse ändern …</button>
                        <hr>
                        <form method="post" action="/team/konten"><?= csrf_feld() ?><input type="hidden" name="id" value="<?= $kid ?>"><input type="hidden" name="aktion" value="link"><button class="menue-punkt" role="menuitem" type="submit"><?= $k['hat_passwort'] ? 'Passwort-Link mailen' : 'Einladung neu mailen' ?></button></form>
                        <?php if (zweitfaktor_da($k)): ?><button type="button" hidden class="menue-punkt" role="menuitem" id="m-zf-<?= $kid ?>">Zweiten Schritt zurücksetzen …</button><?php endif; ?>
                        <hr>
                        <button type="button" hidden class="menue-punkt<?= (int)$k['aktiv'] === 1 ? ' rot' : '' ?>" role="menuitem" id="m-sperren-<?= $kid ?>"><?= (int)$k['aktiv'] === 1 ? 'Konto sperren …' : 'Konto entsperren …' ?></button>
                    </div>
                </details>
                <?php if ($k['rolle'] === 'admin'): ?>
                <details class="bestaetigen" data-art="lila" data-ausloeser="m-va-<?= $kid ?>" data-titel="<?= e($k['name']) ?> · Veranstaltungen"><summary class="klein-link">Veranstaltungen</summary>
                    <form method="post" action="/team/konten" class="formular">
                        <?= csrf_feld() ?><input type="hidden" name="id" value="<?= $kid ?>"><input type="hidden" name="aktion" value="veranstaltungen">
                        <fieldset class="feld"><legend>Darf diese Veranstaltungen bearbeiten</legend>
                            <label class="haken"><input type="radio" name="umfang" value="alle"<?= $erlaubt === null ? ' checked' : '' ?>><span><strong>Alle</strong>, auch künftige</span></label>
                            <label class="haken"><input type="radio" name="umfang" value="auswahl"<?= $erlaubt !== null ? ' checked' : '' ?>><span><strong>Nur ausgewählte</strong> Reihen und Veranstaltungen (kombinierbar):</span></label>
                            <?php foreach (va_reihen() as $r): ?><label class="haken einrueck"><input type="checkbox" name="reihe[]" value="<?= $r['id'] ?>"<?= in_array($r['id'], $erlaubtR, true) ? ' checked' : '' ?>><span>Reihe <strong><?= e($r['name']) ?></strong> <span class="meta klein">· alle ihre Veranstaltungen, auch künftige, und ihr Newsletter</span></span></label><?php endforeach; ?>
                            <?php foreach (va_alle() as $v): ?><label class="haken einrueck"><input type="checkbox" name="va[]" value="<?= $v['id'] ?>"<?= in_array($v['id'], $direkt, true) ? ' checked' : '' ?>><span><?= e($v['name']) ?> <span class="meta klein">· <?= e($v['kuerzel']) ?> · <?= e(VA_STATUS[$v['status']]) ?></span></span></label><?php endforeach; ?>
                        </fieldset>
                        <p class="hilfe">Sieht dann im Wechsler, in allen Listen und im Protokoll nur diese Veranstaltungen. Mit einer Reihe verschickt die Person auch deren Newsletter; „Allgäu Akut“ bleibt bei den BetreiberInnen.</p>
                        <button class="knopf" type="submit">Speichern</button>
                    </form>
                </details>
                <?php endif; ?>
                <details class="bestaetigen" data-art="lila" data-ausloeser="m-rolle-<?= $kid ?>" data-titel="<?= e($k['name']) ?> · Rolle ändern"><summary class="klein-link">Rolle ändern</summary>
                    <form method="post" action="/team/konten" class="formular">
                        <?= csrf_feld() ?><input type="hidden" name="id" value="<?= $kid ?>"><input type="hidden" name="aktion" value="rolle">
                        <label for="rolle-<?= $kid ?>">Neue Rolle</label>
                        <select id="rolle-<?= $kid ?>" name="rolle"><?php foreach (TEAM_ROLLEN as $r): if ($r !== $k['rolle']): ?><option value="<?= e($r) ?>"><?= e(ROLLEN[$r]) ?></option><?php endif; endforeach; ?></select>
                        <?= zweitfaktor_feld_html($konto, 'totp-' . $kid) ?>
                        <button class="knopf" type="submit">Rolle ändern</button>
                    </form>
                </details>
                <details class="bestaetigen" data-art="lila" data-ausloeser="m-adresse-<?= $kid ?>" data-titel="<?= e($k['name']) ?> · Adresse ändern"><summary class="klein-link">Adresse ändern</summary>
                    <form method="post" action="/team/konten" class="formular">
                        <?= csrf_feld() ?><input type="hidden" name="id" value="<?= $kid ?>"><input type="hidden" name="aktion" value="adresse">
                        <?= feld('email', 'Neue E-Mail-Adresse', ['typ' => 'email', 'id' => 'adresse-' . $kid, 'pflicht' => true, 'attr' => ['maxlength' => 254, 'autocomplete' => 'off'], 'ohne_rahmen' => true,
                            'hilfe' => 'Gilt sofort für Login und Anmeldungen. Die alte Adresse ' . $k['email'] . ' bekommt einen Hinweis; offene Einladungs- und Passwort-Links verfallen.']) ?>
                        <?= zweitfaktor_feld_html($konto, 'totp-ad-' . $kid) ?>
                        <button class="knopf" type="submit">Adresse ändern</button>
                    </form>
                </details>
                <?php if (zweitfaktor_da($k)): ?>
                <?= bestaetigung_html('Zweiten Schritt zurücksetzen', $k['name'] . ' richtet App, Code per E-Mail oder Passkey beim nächsten Einloggen neu ein. Passkeys werden entfernt.', 'Ja, zurücksetzen', ['id' => $kid, 'aktion' => 'zweitfaktor'],
                    ['ziel' => '/team/konten', 'ausloeser' => 'm-zf-' . $kid, 'titel' => 'Zweiten Schritt zurücksetzen?', 'knopfklasse' => 'klein-link', 'inhalt_html' => zweitfaktor_feld_html($konto, 'totp-zf-' . $kid)]) ?>
                <?php endif; ?>
                <?php $aktiv = (int)$k['aktiv'] === 1; ?>
                <?= bestaetigung_html($aktiv ? 'Sperren' : 'Entsperren', $aktiv ? $k['name'] . ' kann sich danach nicht mehr einloggen; eine laufende Sitzung endet.' : $k['name'] . ' kann sich wieder einloggen.', $aktiv ? 'Ja, sperren' : 'Ja, entsperren', ['id' => $kid, 'aktion' => 'sperren'],
                    ['ziel' => '/team/konten', 'art' => $aktiv ? 'rot' : 'gruen', 'ausloeser' => 'm-sperren-' . $kid, 'titel' => $aktiv ? 'Konto sperren?' : 'Konto entsperren?', 'knopfklasse' => 'klein-link', 'inhalt_html' => zweitfaktor_feld_html($konto, 'totp-sp-' . $kid)]) ?>
            <?php endif; ?>
            </div></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<section class="abschnitt">
    <h2 class="zwischen">Neues Team-Konto</h2>
    <form method="post" action="/team/konten" class="formular">
        <?= csrf_feld() ?>
        <input type="hidden" name="aktion" value="anlegen">
        <?= feld('name', 'Name', ['wert' => $werte['name'], 'pflicht' => true, 'attr' => ['maxlength' => 120], 'ohne_rahmen' => true]) ?>
        <?= feld('email', 'E-Mail-Adresse', ['typ' => 'email', 'wert' => $werte['email'], 'pflicht' => true, 'attr' => ['maxlength' => 254], 'ohne_rahmen' => true]) ?>
        <?= feld('rolle', 'Rolle', ['typ' => 'auswahl', 'wert' => $werte['rolle'], 'ohne_rahmen' => true, 'optionen' => [
            'admin' => 'Admin – Programm, Einstellungen, Anmeldungen',
            'betreiber' => 'BetreiberIn – zusätzlich Team-Konten und Technik',
        ]]) ?>
        <?= feld('nur_anzeigen', 'Keine Mail – Link nur hier anzeigen (ich gebe ihn selbst weiter)', ['typ' => 'haken']) ?>
        <button type="submit" class="knopf">Konto anlegen und einladen</button>
    </form>
    <p class="meta klein">Die Einladung kommt per Mail mit einem Link zum Festlegen des Passworts (<?= e(dauer_text(KONTO_LINK_GUELTIG_S)) ?>, einmal). Beim ersten Einloggen richtet die Person den zweiten Faktor ein.</p>
</section>
<?php
team_seite_fuss();
