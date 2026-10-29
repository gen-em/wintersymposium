<?php
declare(strict_types=1);

/*
 * Team-Konten (nur Betreiber, E-11): anlegen, Einrichtungs-/Passwortlink,
 * zweiten Faktor zurücksetzen, sperren, Rolle ändern. Ein Rollenwechsel
 * verlangt den eigenen zweiten Faktor (E-41). Das eigene Konto lässt sich
 * hier nicht sperren oder herabstufen, und es bleibt immer mindestens ein
 * aktiver Betreiber.
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
                $fehler = in_array($werte['rolle'], TEAM_ROLLEN, true) ? konto_eingabe_fehler($werte['name'], $werte['email'], $werte['rolle']) : ['Bitte Admin oder Betreiber wählen.'];
                if ($fehler === []) {
                    $id = konto_anlegen($werte['name'], $werte['email'], $werte['rolle'], null, $ich);
                    $_SESSION['neuer_link'] = ['name' => trim($werte['name']), 'url' => konto_link_url(konto_link_erzeugen($id, 'einrichten', $ich))];
                    umleiten('/team/konten');
                }
                break;
            case 'link':
                $zweck = $ziel['passwort_hash'] === null ? 'einrichten' : 'passwort';
                $_SESSION['neuer_link'] = ['name' => $ziel['name'], 'url' => konto_link_url(konto_link_erzeugen((int)$ziel['id'], $zweck, $ich))];
                umleiten('/team/konten');
            case 'zweitfaktor':
                totp_zuruecksetzen((int)$ziel['id'], 'durch Betreiber', $ich);
                flash_setzen('ok', 'Zweiter Faktor von ' . $ziel['name'] . ' zurückgesetzt. Beim nächsten Einloggen richtet ' . $ziel['name'] . ' ihn neu ein.');
                umleiten('/team/konten');
            case 'sperren':
                if ($ziel['rolle'] === 'betreiber' && (int)$ziel['aktiv'] === 1 && konto_aktive_betreiber() < 2) {
                    $fehler[] = 'Es muss mindestens ein aktiver Betreiber bleiben.';
                    break;
                }
                konto_aktiv_setzen((int)$ziel['id'], (int)$ziel['aktiv'] !== 1, $ich);
                flash_setzen('ok', $ziel['name'] . ((int)$ziel['aktiv'] === 1 ? ' gesperrt.' : ' entsperrt.'));
                umleiten('/team/konten');
            case 'rolle':
                $rolle = (string)($_POST['rolle'] ?? '');
                $kennung = rate_merkmal('konto:' . $ich);
                if (!in_array($rolle, TEAM_ROLLEN, true) || $rolle === $ziel['rolle']) {
                    $fehler[] = 'Bitte eine andere Rolle wählen.';
                } elseif ($ziel['rolle'] === 'betreiber' && (int)$ziel['aktiv'] === 1 && konto_aktive_betreiber() < 2) {
                    $fehler[] = 'Es muss mindestens ein aktiver Betreiber bleiben.';
                } elseif (!rate_erlaubt('zweitfaktor', $kennung)) {
                    $fehler[] = 'Zu viele falsche Codes. Bitte warte 15 Minuten.';
                } elseif (!totp_anmeldung_pruefen($ich, (string)($_POST['code'] ?? ''))['ok']) {
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
<div class="tabelle-wrap"><table class="t">
    <thead><tr><th>Name</th><th>Rolle</th><th>Status</th><th>Letzte Anmeldung</th><th>Aktionen</th></tr></thead>
    <tbody>
    <?php foreach (konto_team_liste() as $k):
        $eigen = (int)$k['id'] === $ich;
        $status = (int)$k['aktiv'] !== 1 ? 'gesperrt' : ($k['hat_passwort'] ? ($k['totp_seit'] !== null ? 'aktiv' : 'zweiter Faktor fehlt') : 'Einrichtung offen'); ?>
        <tr>
            <td><strong><?= e($k['name']) ?></strong><?= $eigen ? ' <span class="meta">(du)</span>' : '' ?><br><span class="meta klein"><?= e($k['email']) ?></span></td>
            <td><?= e(ROLLEN[$k['rolle']]) ?></td>
            <td><span class="chip <?= (int)$k['aktiv'] !== 1 ? 'gesperrt' : ($status === 'aktiv' ? 'ok' : 'offen') ?>"><?= e($status) ?></span></td>
            <td class="mono klein"><?= e($k['letzte_anmeldung'] ? datum_zeit_text($k['letzte_anmeldung']) : '–') ?></td>
            <td><div class="aktionen-zelle">
            <?php if (!$eigen): ?>
                <form method="post" action="/team/konten"><?= csrf_feld() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="aktion" value="link"><button class="knopf zweit klein" type="submit"><?= $k['hat_passwort'] ? 'Passwort-Link' : 'Neuer Einrichtungslink' ?></button></form>
                <?php if ($k['totp_seit'] !== null): ?>
                <form method="post" action="/team/konten"><?= csrf_feld() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="aktion" value="zweitfaktor"><button class="knopf zweit klein" type="submit">2. Faktor zurücksetzen</button></form>
                <?php endif; ?>
                <form method="post" action="/team/konten"><?= csrf_feld() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="aktion" value="sperren"><button class="knopf zweit klein" type="submit"><?= (int)$k['aktiv'] === 1 ? 'Sperren' : 'Entsperren' ?></button></form>
                <details class="rolle-wechsel"><summary>Rolle ändern</summary>
                    <form method="post" action="/team/konten" class="formular">
                        <?= csrf_feld() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="aktion" value="rolle">
                        <label for="rolle-<?= (int)$k['id'] ?>">Neue Rolle</label>
                        <select id="rolle-<?= (int)$k['id'] ?>" name="rolle"><?php foreach (TEAM_ROLLEN as $r): if ($r !== $k['rolle']): ?><option value="<?= e($r) ?>"><?= e(ROLLEN[$r]) ?></option><?php endif; endforeach; ?></select>
                        <label for="code-<?= (int)$k['id'] ?>">Bestätigen mit dem Code aus deiner App</label>
                        <input id="code-<?= (int)$k['id'] ?>" name="code" type="text" required inputmode="numeric" autocomplete="one-time-code" maxlength="8">
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
            <option value="betreiber"<?= $werte['rolle'] === 'betreiber' ? ' selected' : '' ?>>Betreiber – zusätzlich Team-Konten und Technik</option>
        </select>
        <button type="submit" class="knopf">Konto anlegen und Link erzeugen</button>
    </form>
    <p class="meta klein">Mails verschickt die Webseite noch nicht. Du bekommst einen Einrichtungslink angezeigt und gibst ihn weiter.</p>
</section>
<?php
team_seite_fuss();
