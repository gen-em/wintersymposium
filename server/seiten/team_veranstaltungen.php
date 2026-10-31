<?php
declare(strict_types=1);

/*
 * Veranstaltungen (E-58): alle Veranstaltungen mit Status; neue anlegen
 * (leer oder als Kopie mit Einstellungen, Texten und Programm), zur aktuellen
 * machen, abschließen (danach nur lesen), löschen.
 * Immer nur eine ist aktuell: Startseite, Anmeldung und „Meine Anmeldung“.
 * Nur Betreiber (AP-12, E-60). Löschen geht bei allen außer der aktuellen,
 * mit Dialog und eingetipptem Kürzel; vorher legt die App eine Sicherung an.
 */

$konto = team_tor(['betreiber']);
$ich = (int)$konto['id'];
$fehler = [];
$eingabe = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $id = (int)($_POST['id'] ?? 0);
    switch ((string)($_POST['aktion'] ?? '')) {
        case 'anlegen':
            $eingabe = $_POST;
            $name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? '')) ?? '');
            $vorlage = (int)($_POST['vorlage'] ?? 0);
            if ($name === '' || mb_strlen($name) > 80) {
                $fehler['name'] = 'Bitte einen Namen angeben (höchstens 80 Zeichen).';
            } else {
                $r = va_anlegen((string)($_POST['kuerzel'] ?? ''), $name, $vorlage > 0 && va_zeile($vorlage) !== null ? $vorlage : null, $ich);
                if (isset($r['fehler'])) {
                    $fehler['kuerzel'] = $r['fehler'];
                } else {
                    flash_setzen('ok', 'Veranstaltung angelegt (Entwurf). Jetzt Einstellungen, Programm und Texte anpassen.');
                    umleiten('/team/einstellungen?va=' . $r['id']);
                }
            }
            break;
        case 'aktuell':
            $f = va_aktuell_machen($id, $ich);
            flash_setzen($f === null ? 'ok' : 'fehler', $f ?? 'Diese Veranstaltung ist jetzt die aktuelle: Startseite, Anmeldung und „Meine Anmeldung“ zeigen sie.');
            if ($f === null) {
                $_SESSION['team_va'] = $id;
            }
            umleiten('/team/veranstaltungen');
        case 'abschliessen':
            $f = va_abschliessen($id, $ich);
            flash_setzen($f === null ? 'ok' : 'fehler', $f ?? 'Abgeschlossen. Sie ist nur noch zum Ansehen da; Zertifikate bleiben herunterladbar.');
            umleiten('/team/veranstaltungen');
        case 'loeschen':
            $f = va_loeschen($id, $ich, (string)($_POST['kuerzel'] ?? ''));
            flash_setzen($f === null ? 'ok' : 'fehler', $f ?? 'Veranstaltung gelöscht. Der Stand davor liegt als Sicherung unter Betrieb.');
            unset($_SESSION['team_va']);
            umleiten('/team/veranstaltungen');
    }
}

$alle = va_alle();
team_seite_kopf($konto, 'Veranstaltungen', '/team/veranstaltungen');
?>
<p class="meta klein">Immer eine Veranstaltung ist <strong>aktuell</strong>: Sie steht auf der Startseite, unter „Anmelden“ und „Meine Anmeldung“. Jede ist zusätzlich unter ihrem Kürzel erreichbar (z. B. <span class="mono"><?= e(parse_url(url('/'), PHP_URL_HOST) . '/' . ($alle[0]['kuerzel'] ?? 'kuerzel')) ?></span>). Abgeschlossene sind nur noch zum Ansehen da.</p>
<?= fehler_html(array_values($fehler)) ?>
<div class="tabelle-wrap"><table class="t karten">
    <thead><tr><th>Veranstaltung</th><th>Datum</th><th>Status</th><th>Anmeldungen</th><th>Aktionen</th></tr></thead>
    <tbody>
    <?php foreach ($alle as $v): $n = (int)db_wert("SELECT COUNT(*) FROM anmeldung WHERE veranstaltung_id = ? AND status = 'bestaetigt'", [$v['id']]); ?>
        <tr<?= $v['id'] === va_id() ? ' class="gewaehlt"' : '' ?>>
            <td data-label="Veranstaltung"><strong><?= e($v['name']) ?></strong><br><a class="mono klein" href="/<?= e($v['kuerzel']) ?>"<?= $v['status'] === 'entwurf' ? '' : '' ?>>/<?= e($v['kuerzel']) ?></a></td>
            <td data-label="Datum" class="mono"><?= $v['datum'] !== '' ? e(tag_text($v['datum'])) : '–' ?></td>
            <td data-label="Status"><span class="chip <?= ['aktuell' => 'ok', 'entwurf' => 'offen', 'abgeschlossen' => 'gesperrt'][$v['status']] ?>"><?= e(VA_STATUS[$v['status']]) ?></span></td>
            <td data-label="Anmeldungen" class="mono"><?= $n ?></td>
            <td data-label="Aktionen"><span class="zellen-aktionen">
                <a class="knopf zweit klein-knopf" href="/team?va=<?= $v['id'] ?>">Öffnen</a>
                <?php if ($v['status'] === 'entwurf'): ?>
                <details class="bestaetigen inline-details" data-titel="Zur aktuellen machen?"><summary class="knopf klein-knopf">Aktuell machen</summary>
                    <form method="post" action="/team/veranstaltungen"><?= csrf_feld() ?><input type="hidden" name="aktion" value="aktuell"><input type="hidden" name="id" value="<?= $v['id'] ?>">
                        <p><?= e($v['name']) ?> erscheint dann auf der Startseite; die Anmeldung öffnet zum eingestellten Zeitpunkt. Es darf keine andere aktuelle geben.</p>
                        <button class="knopf" type="submit">Ja, aktuell machen</button></form></details>
                <?php endif; ?>
                <?php if ($v['status'] !== 'abgeschlossen'): ?>
                <details class="bestaetigen inline-details" data-titel="Veranstaltung abschließen?"><summary class="knopf zweit klein-knopf">Abschließen</summary>
                    <form method="post" action="/team/veranstaltungen"><?= csrf_feld() ?><input type="hidden" name="aktion" value="abschliessen"><input type="hidden" name="id" value="<?= $v['id'] ?>">
                        <p>Danach lässt sich an <?= e($v['name']) ?> nichts mehr ändern: keine Anmeldungen, kein Programm, keine Anwesenheit, keine Freischaltungen. Ansehen, Exporte und bereits freigeschaltete Zertifikate bleiben. Erst Anwesenheit eintragen und Zertifikate freischalten.</p>
                        <button class="knopf gefahr" type="submit">Ja, abschließen</button></form></details>
                <?php endif; ?>
                <?php if ($v['status'] !== 'aktuell'): ?>
                <details class="bestaetigen inline-details" data-titel="Veranstaltung löschen?"><summary class="klein-link">Löschen</summary>
                    <form method="post" action="/team/veranstaltungen"><?= csrf_feld() ?><input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="id" value="<?= $v['id'] ?>">
                        <p><?= e($v['name']) ?> wird mit Programm, Einstellungen, Texten, <?= $n ?> Anmeldungen, Wartelisten, Teilnahme und Mailings gelöscht. Die CME-Nachweise bleiben. Vorher legt die App eine Sicherung an.</p>
                        <div class="feld"><label for="lo-<?= $v['id'] ?>">Zur Bestätigung das Kürzel eintippen: <span class="mono"><?= e($v['kuerzel']) ?></span></label>
                            <input id="lo-<?= $v['id'] ?>" name="kuerzel" autocomplete="off" required pattern="<?= e($v['kuerzel']) ?>"></div>
                        <button class="knopf gefahr" type="submit">Endgültig löschen</button></form></details>
                <?php endif; ?>
            </span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<div class="kasten abstand-oben" id="neu">
    <h2 class="zwischen klein-zwischen">Neue Veranstaltung</h2>
    <form class="formular" method="post" action="/team/veranstaltungen"><?= csrf_feld() ?><input type="hidden" name="aktion" value="anlegen">
        <div class="feld"><label for="va-name">Name</label><input id="va-name" name="name" maxlength="80" required value="<?= e((string)($eingabe['name'] ?? '')) ?>" placeholder="z. B. Allgäuer Winter-Notfallsymposium"<?= isset($fehler['name']) ? ' aria-invalid="true"' : '' ?>></div>
        <div class="feld"><label for="va-kuerzel">Kürzel (Adresse)</label><input id="va-kuerzel" name="kuerzel" maxlength="40" required pattern="[a-z0-9][a-z0-9\-]{1,39}" value="<?= e((string)($eingabe['kuerzel'] ?? '')) ?>" placeholder="z. B. wintersymposium2028"<?= isset($fehler['kuerzel']) ? ' aria-invalid="true"' : '' ?>>
            <p class="hilfe">Kleinbuchstaben ohne Umlaute, Ziffern, Bindestrich. Später unter Einstellungen änderbar.</p></div>
        <div class="feld"><label for="va-vorlage">Vorlage</label>
            <select id="va-vorlage" name="vorlage"><option value="0">leer beginnen</option><?php foreach ($alle as $v): ?><option value="<?= $v['id'] ?>"<?= (int)($eingabe['vorlage'] ?? ($alle[0]['id'] ?? 0)) === $v['id'] ? ' selected' : '' ?>>Kopie von <?= e($v['name'] . ' (' . $v['kuerzel'] . ')') ?></option><?php endforeach; ?></select>
            <p class="hilfe">Kopiert Einstellungen, Startseiten-Texte und Programm (als Entwurf, noch nicht veröffentlicht); keine Anmeldungen.</p></div>
        <button class="knopf" type="submit">Anlegen (Entwurf)</button>
    </form>
</div>
<?php
team_seite_fuss();
