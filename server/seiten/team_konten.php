<?php
declare(strict_types=1);

/*
 * Team-Konten (nur Betreiber, E-11): anlegen, Einrichtungs-/Passwortlink
 * (per Mail oder angezeigt, AP-6),
 * zweiten Faktor zurücksetzen, sperren, Rolle ändern. Ein Rollenwechsel
 * verlangt den eigenen zweiten Faktor (E-41). Das eigene Konto lässt sich
 * hier nicht sperren oder herabstufen, und es bleibt immer mindestens ein
 * aktiver Betreiber. Admins sind für alle Veranstaltungen (auch künftige) oder
 * nur für ausgewählte freigeschaltet (AP-12, E-60).
 */

$konto = team_tor(['betreiber']);
$ich = (int)$konto['id'];
$fehler = [];
$werte = ['name' => '', 'email' => '', 'rolle' => 'admin'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $aktion = (string)($_POST['aktion'] ?? '');
    $ziel = isset($_POST['id']) ? konto_laden((int)$_POST['id']) : null;
    if ($aktion !== 'anlegen' && (!konto_ist_team($ziel))) {
        $fehler[] = 'Dieses Konto gibt es nicht.';
    } elseif ($aktion !== 'anlegen' && (int)$ziel['id'] === $ich) {
        $fehler[] = 'Dein eigenes Konto änderst du unter „Mein Konto“.';
    } else {
        switch ($aktion) {
            case 'anlegen':
                $werte = ['name' => (string)($_POST['name'] ?? ''), 'email' => (string)($_POST['email'] ?? ''), 'rolle' => (string)($_POST['rolle'] ?? '')];
                $fehler = in_array($werte['rolle'], TEAM_ROLLEN, true) ? konto_eingabe_fehler($werte['name'], $werte['email'], $werte['rolle']) : ['Bitte Admin oder BetreiberIn wählen.'];
                if ($fehler === []) {
                    $id = konto_anlegen($werte['name'], $werte['email'], $werte['rolle'], null, $ich);
                    team_link_weitergeben(konto_laden($id), 'einrichten', empty($_POST['nur_anzeigen']), $ich);
                    umleiten('/team/konten');
                }
                break;
            case 'link':
                team_link_weitergeben($ziel, $ziel['passwort_hash'] === null ? 'einrichten' : 'passwort', empty($_POST['nur_anzeigen']), $ich);
                umleiten('/team/konten');
            case 'zweitfaktor':
                totp_zuruecksetzen((int)$ziel['id'], 'durch BetreiberIn', $ich);
                flash_setzen('ok', 'Zweiter Faktor von ' . $ziel['name'] . ' zurückgesetzt. Beim nächsten Einloggen richtet ' . $ziel['name'] . ' ihn neu ein.');
                umleiten('/team/konten');
            case 'sperren':
                if ($ziel['rolle'] === 'betreiber' && (int)$ziel['aktiv'] === 1 && konto_aktive_betreiber() < 2) {
                    $fehler[] = 'Es muss mindestens eine aktive BetreiberIn bleiben.';
                    break;
                }
                konto_aktiv_setzen((int)$ziel['id'], (int)$ziel['aktiv'] !== 1, $ich);
                flash_setzen('ok', $ziel['name'] . ((int)$ziel['aktiv'] === 1 ? ' gesperrt.' : ' entsperrt.'));
                umleiten('/team/konten');
            case 'veranstaltungen':
                if ($ziel['rolle'] !== 'admin') {
                    $fehler[] = 'BetreiberInnen sehen immer alle Veranstaltungen.';
                    break;
                }
                $alle = ($_POST['umfang'] ?? '') !== 'auswahl';
                va_rechte_setzen((int)$ziel['id'], $alle, (array)($_POST['va'] ?? []), $ich);
                flash_setzen('ok', 'Veranstaltungen von ' . $ziel['name'] . ': ' . va_rechte_text(konto_laden((int)$ziel['id'])) . '.');
                umleiten('/team/konten');
            case 'rolle':
                $rolle = (string)($_POST['rolle'] ?? '');
                $kennung = rate_merkmal('konto:' . $ich);
                if (!in_array($rolle, TEAM_ROLLEN, true) || $rolle === $ziel['rolle']) {
                    $fehler[] = 'Bitte eine andere Rolle wählen.';
                } elseif ($ziel['rolle'] === 'betreiber' && (int)$ziel['aktiv'] === 1 && konto_aktive_betreiber() < 2) {
                    $fehler[] = 'Es muss mindestens eine aktive BetreiberIn bleiben.';
                } elseif (!rate_erlaubt('zweitfaktor', $kennung)) {
                    $fehler[] = 'Zu viele falsche Codes. Bitte warte 15 Minuten.';
                } elseif (!totp_anmeldung_pruefen($ich, (string)($_POST['totp'] ?? ''))['ok']) {
                    rate_misserfolg('zweitfaktor', $kennung);
                    $fehler[] = 'Der Code aus deiner App stimmt nicht. Die Rolle wurde nicht geändert.';
                } else {
                    rate_erfolg('zweitfaktor', $kennung);
                    konto_rolle_setzen((int)$ziel['id'], $rolle, $ich);
                    flash_setzen('ok', $ziel['name'] . ' ist jetzt ' . ROLLEN[$rolle] . '.');
                    umleiten('/team/konten');
                }
                break;
            default:
                $fehler[] = 'Unbekannte Aktion.';
        }
    }
}

/** Link erzeugen und per Mail schicken oder (nur_anzeigen) einmal anzeigen. */
function team_link_weitergeben(array $k, string $zweck, bool $perMail, int $ich): void
{
    $url = konto_link_url(konto_link_erzeugen((int)$k['id'], $zweck, $ich));
    if ($perMail && mail_eingerichtet()) {
        mail_vorlage_einreihen($k['email'], $zweck === 'einrichten' ? 'team_einladung' : 'team_passwort', ['vorname' => tk_namen($k)['vorname'], 'link' => $url, 'rolle' => ROLLEN[$k['rolle']] ?? $k['rolle']]);
        flash_setzen('ok', ($zweck === 'einrichten' ? 'Einladung' : 'Passwort-Link') . ' an ' . $k['email'] . ' geschickt (gilt 72 Stunden).');
        return;
    }
    $_SESSION['neuer_link'] = ['name' => $k['name'], 'url' => $url];
}

$link = $_SESSION['neuer_link'] ?? null;
unset($_SESSION['neuer_link']);

team_seite_kopf($konto, 'Team-Konten', '/team/konten');
echo fehler_html($fehler);
if (is_array($link)): ?>
<div class="kasten link-kasten">
    <p><strong>Link für <?= e($link['name']) ?></strong> – gib ihn persönlich oder über einen sicheren Weg weiter. Er gilt 72 Stunden und nur einmal und wird nur jetzt angezeigt.</p>
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
            <td data-label="Status"><span class="chip <?= (int)$k['aktiv'] !== 1 ? 'gesperrt' : ($status === 'aktiv' ? 'ok' : 'offen') ?>"><?= e($status) ?></span></td>
            <td data-label="Letzte Anmeldung" class="mono klein"><?= e($k['letzte_anmeldung'] ? datum_zeit_text($k['letzte_anmeldung']) : '–') ?></td>
            <td<?= $eigen ? '' : ' data-label="Aktionen"' ?>><div class="aktionen-zelle">
            <?php if (!$eigen): ?>
                <form method="post" action="/team/konten"><?= csrf_feld() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="aktion" value="link"><button class="knopf zweit klein" type="submit"><?= $k['hat_passwort'] ? 'Passwort-Link mailen' : 'Einladung neu mailen' ?></button></form>
                <?php if (zweitfaktor_da($k)): ?>
                <form method="post" action="/team/konten"><?= csrf_feld() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="aktion" value="zweitfaktor"><button class="knopf zweit klein" type="submit">2. Faktor zurücksetzen</button></form>
                <?php endif; ?>
                <form method="post" action="/team/konten"><?= csrf_feld() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="aktion" value="sperren"><button class="knopf zweit klein" type="submit"><?= (int)$k['aktiv'] === 1 ? 'Sperren' : 'Entsperren' ?></button></form>
                <?php if ($k['rolle'] === 'admin'): $erlaubt = va_erlaubte_ids($k); ?>
                <details class="rolle-wechsel"><summary>Veranstaltungen</summary>
                    <form method="post" action="/team/konten" class="formular">
                        <?= csrf_feld() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="aktion" value="veranstaltungen">
                        <fieldset class="feld"><legend>Darf diese Veranstaltungen bearbeiten</legend>
                            <label class="haken"><input type="radio" name="umfang" value="alle"<?= $erlaubt === null ? ' checked' : '' ?>><span><strong>Alle</strong>, auch künftige</span></label>
                            <label class="haken"><input type="radio" name="umfang" value="auswahl"<?= $erlaubt !== null ? ' checked' : '' ?>><span><strong>Nur ausgewählte:</strong></span></label>
                            <?php foreach (va_alle() as $v): ?><label class="haken einrueck"><input type="checkbox" name="va[]" value="<?= $v['id'] ?>"<?= $erlaubt !== null && in_array($v['id'], $erlaubt, true) ? ' checked' : '' ?>><span><?= e($v['name']) ?> <span class="meta klein">· <?= e($v['kuerzel']) ?> · <?= e(VA_STATUS[$v['status']]) ?></span></span></label><?php endforeach; ?>
                        </fieldset>
                        <p class="hilfe">Sieht dann im Wechsler, in allen Listen und im Protokoll nur diese Veranstaltungen. Neue Veranstaltungen legen nur BetreiberInnen an.</p>
                        <button class="knopf klein" type="submit">Speichern</button>
                    </form>
                </details>
                <?php endif; ?>
                <details class="rolle-wechsel"><summary>Rolle ändern</summary>
                    <form method="post" action="/team/konten" class="formular">
                        <?= csrf_feld() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="aktion" value="rolle">
                        <label for="rolle-<?= (int)$k['id'] ?>">Neue Rolle</label>
                        <select id="rolle-<?= (int)$k['id'] ?>" name="rolle"><?php foreach (TEAM_ROLLEN as $r): if ($r !== $k['rolle']): ?><option value="<?= e($r) ?>"><?= e(ROLLEN[$r]) ?></option><?php endif; endforeach; ?></select>
                        <label for="totp-<?= (int)$k['id'] ?>">Bestätigen mit dem Code aus deiner App</label>
                        <input id="totp-<?= (int)$k['id'] ?>" name="totp" type="text" required inputmode="numeric" autocomplete="one-time-code" maxlength="8">
                        <button class="knopf klein" type="submit">Rolle ändern</button>
                    </form>
                </details>
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
        <label for="name">Name</label>
        <input id="name" name="name" type="text" required maxlength="120" value="<?= e($werte['name']) ?>">
        <label for="email">E-Mail-Adresse</label>
        <input id="email" name="email" type="email" required maxlength="254" value="<?= e($werte['email']) ?>">
        <label for="rolle">Rolle</label>
        <select id="rolle" name="rolle">
            <option value="admin"<?= $werte['rolle'] === 'admin' ? ' selected' : '' ?>>Admin – Programm, Einstellungen, Anmeldungen</option>
            <option value="betreiber"<?= $werte['rolle'] === 'betreiber' ? ' selected' : '' ?>>BetreiberIn – zusätzlich Team-Konten und Technik</option>
        </select>
        <label class="haken"><input type="checkbox" name="nur_anzeigen" value="1"><span>Keine Mail – Link nur hier anzeigen (ich gebe ihn selbst weiter)</span></label>
        <button type="submit" class="knopf">Konto anlegen und einladen</button>
    </form>
    <p class="meta klein">Die Einladung kommt per Mail mit einem Link zum Festlegen des Passworts (72 Stunden, einmal). Beim ersten Einloggen richtet die Person den zweiten Faktor ein.</p>
</section>
<?php
team_seite_fuss();
