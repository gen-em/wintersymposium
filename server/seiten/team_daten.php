<?php
declare(strict_types=1);

/*
 * Daten: Sicherungen und Testdaten (nur Betreiber; E-47).
 * Herunterladen und Wiederherstellen verlangen den eigenen Code aus der
 * Authenticator-App (wie der Rollenwechsel, E-41). Testdaten laden gibt es nur
 * auf Staging und in der Entwicklung, nie auf Produktion.
 */

$konto = team_tor(['betreiber']);
$ich = (int)$konto['id'];
$fehler = [];

/** Eigenen Code prüfen, mit Ratenschutz. Gibt eine Fehlermeldung oder null zurück. */
$codePruefen = function () use ($ich): ?string {
    $kennung = rate_merkmal('konto:' . $ich);
    if (!rate_erlaubt('zweitfaktor', $kennung)) {
        return 'Zu viele falsche Codes. Bitte warte 15 Minuten.';
    }
    if (!totp_anmeldung_pruefen($ich, (string)($_POST['totp'] ?? ''))['ok']) {
        rate_misserfolg('zweitfaktor', $kennung);
        return 'Der Code aus deiner App stimmt nicht.';
    }
    rate_erfolg('zweitfaktor', $kennung);
    return null;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $name = (string)($_POST['name'] ?? '');
    switch ((string)($_POST['aktion'] ?? '')) {
        case 'testmail':
            $grund = mail_testen((string)$konto['email']);
            audit_schreiben('mail', 'Testmail ' . ($grund === null ? 'versendet' : 'fehlgeschlagen: ' . $grund), $ich);
            flash_setzen($grund === null ? 'ok' : 'fehler', $grund === null
                ? 'Testmail an ' . $konto['email'] . ' ist beim Mailserver angenommen. Kommt sie nicht an, liegt es beim Empfang (Spam-Ordner, Absender-Einträge der Domain).'
                : 'Testmail ging nicht raus: ' . $grund);
            umleiten('/team/daten');
        case 'anlegen':
            $n = sicherung_anlegen('von Hand', $ich);
            flash_setzen('ok', 'Sicherung angelegt: ' . $n);
            umleiten('/team/daten');
        case 'herunterladen':
            $p = sicherung_pfad($name);
            if ($p === null) {
                $fehler[] = 'Diese Sicherung gibt es nicht.';
            } elseif (($f = $codePruefen()) !== null) {
                $fehler[] = $f;
            } else {
                audit_schreiben('sicherung', 'Sicherung heruntergeladen: ' . $name, $ich);
                header('Content-Type: ' . (str_ends_with($name, '.gz') ? 'application/gzip' : 'application/x-ndjson'));
                header('Content-Disposition: attachment; filename="' . $name . '"');
                header('Content-Length: ' . filesize($p));
                header('Cache-Control: no-store');
                readfile($p);
                exit;
            }
            break;
        case 'wiederherstellen':
            if (($f = $codePruefen()) !== null) {
                $fehler[] = $f;
            } elseif (($f = sicherung_wiederherstellen($name, $ich)) !== null) {
                $fehler[] = 'Nicht wiederhergestellt: ' . $f;
            } else {
                flash_setzen('ok', 'Wiederhergestellt: ' . $name . '. Der Stand davor ist als eigene Sicherung abgelegt.');
                umleiten('/team/daten');
            }
            break;
        case 'loeschen':
            $ok = sicherung_loeschen($name, $ich);
            flash_setzen($ok ? 'ok' : 'fehler', $ok ? 'Sicherung gelöscht.' : 'Löschen ging nicht.');
            umleiten('/team/daten');
        case 'testdaten':
            if (!testdaten_moeglich()) {
                $fehler[] = 'Testdaten gibt es nur auf Staging.';
            } else {
                $s = testdaten_laden($ich);
                flash_setzen('ok', 'Testdaten geladen. Der Stand davor ist gesichert als ' . $s . '.');
                umleiten('/team/daten');
            }
            break;
    }
}

$liste = sicherung_liste();
$ms = mail_stand();
$codeFeld = fn(string $id) => '<div class="feld"><label for="' . $id . '">Code aus deiner App</label><input id="' . $id . '" name="totp" type="text" required inputmode="numeric" autocomplete="one-time-code" maxlength="8"></div>';
$groesse = fn(int $b) => $b >= 1048576 ? zahl_text($b / 1048576, 1) . ' MB' : zahl_text(max(1, (int)round($b / 1024))) . ' KB';
team_seite_kopf($konto, 'Daten', '/team/daten');
?>
<?= fehler_html($fehler) ?>
<div class="team-raster">
    <div class="kasten">
        <h2 class="zwischen">Sicherung</h2>
        <p>Eine Sicherung enthält alle Daten: Einstellungen, Programm, Fotos, Anmeldungen und Team-Konten. Sie liegt auf dem Server außerhalb des Webs. Herunterladen und Wiederherstellen verlangen deinen Code.</p>
        <form method="post" action="/team/daten"><?= csrf_feld() ?><input type="hidden" name="aktion" value="anlegen"><button class="knopf" type="submit">Sicherung anlegen</button></form>
    </div>
    <?php if (testdaten_moeglich()): ?>
    <div class="kasten gefahrzone-kasten">
        <h2 class="zwischen">Testdaten <span class="chip offen"><?= e(umgebung()) ?></span></h2>
        <p>Ersetzt Programm, Dozierende, Anmeldungen und Veranstaltungs-Einstellungen durch den Beispieldatensatz: Veranstaltung in 60 Tagen, Anmeldung offen, Workshops mit vollem Termin, Warteliste und offenem Angebot. Team-Konten bleiben. Vorher wird automatisch gesichert.</p>
        <p class="meta klein">Die Testpersonen haben Adressen bei example.org. Damit keine Mails dorthin gehen, in der config.php unter mail den Eintrag umleiten_an setzen.</p>
        <details class="bestaetigen"><summary class="knopf gefahr">Testdaten laden</summary>
            <form method="post" action="/team/daten"><?= csrf_feld() ?><input type="hidden" name="aktion" value="testdaten">
                <p>Programm, Anmeldungen und Einstellungen werden ersetzt. Zurück geht es über die Sicherung, die dabei entsteht.</p>
                <button type="submit" class="knopf gefahr">Ja, Testdaten laden</button></form>
        </details>
    </div>
    <?php endif; ?>
</div>

<div class="kasten abstand-oben">
    <h2 class="zwischen">Mailversand</h2>
    <dl class="liste-def">
        <div><dt>Eingerichtet</dt><dd><?= $ms['eingerichtet'] ? '<span class="chip ok">ja</span> Modus ' . e($ms['modus']) : '<span class="chip wl">nein</span> In der config.php fehlt im Abschnitt mail der Eintrag host.' ?></dd></div>
        <div><dt>Absender</dt><dd class="mono"><?= e($ms['absender']) ?></dd></div>
        <?php if ($ms['umleiten'] !== ''): ?><div><dt>Umleitung</dt><dd>Alle Mails gehen an <span class="mono"><?= e($ms['umleiten']) ?></span></dd></div><?php endif; ?>
        <div><dt>Warteschlange</dt><dd><?= $ms['offen'] ?> offen<?= $ms['aelteste_offen'] !== null ? ' (älteste seit ' . e(datum_zeit_text($ms['aelteste_offen'])) . ')' : '' ?> · <?= $ms['fehler'] ?> endgültig fehlgeschlagen · heute gesendet <?= $ms['heute'] ?></dd></div>
        <?php if ($ms['letzter_fehler'] !== null): ?><div><dt>Letzter Fehler</dt><dd><span class="mono"><?= e($ms['letzter_fehler']['fehler']) ?></span><br><span class="meta klein">Mail vom <?= e(datum_zeit_text($ms['letzter_fehler']['erstellt_am'])) ?>, <?= (int)$ms['letzter_fehler']['versuche'] ?> Versuche</span></dd></div><?php endif; ?>
    </dl>
    <form method="post" action="/team/daten" class="abstand-oben-klein"><?= csrf_feld() ?><input type="hidden" name="aktion" value="testmail"><button class="knopf zweit" type="submit">Testmail an mich</button></form>
    <p class="meta klein">Versendet sofort an <?= e($konto['email']) ?> und zeigt den Fehler des Mailservers, falls er die Mail ablehnt. Das Passwort wird nie angezeigt.</p>
</div>

<h2 class="zwischen abstand-oben">Vorhandene Sicherungen</h2>
<?php if ($liste === []): ?>
<p class="hinweis-leer">Noch keine Sicherung.</p>
<?php else: ?>
<div class="tabelle-wrap"><table class="t karten">
    <thead><tr><th>Angelegt</th><th>Anlass</th><th>Größe</th><th>Aktionen</th></tr></thead>
    <tbody>
    <?php foreach ($liste as $i => $s): $k = $s['kopf']; ?>
        <tr>
            <td data-titel="Angelegt" class="mono"><?= $k !== null ? e(datum_zeit_text($k['erstellt'])) : '–' ?><br><span class="meta klein"><?= e($s['name']) ?></span></td>
            <td data-titel="Anlass"><?= $k !== null ? e($k['anlass']) . '<br><span class="meta klein">Version ' . e($k['version']) . '</span>' : '<span class="chip wl">nicht lesbar</span>' ?></td>
            <td data-titel="Größe" class="mono"><?= e($groesse($s['groesse'])) ?></td>
            <td data-titel="Aktionen"><div class="zellen-aktionen">
                <details class="bestaetigen"><summary class="knopf zweit klein-knopf">Herunterladen</summary>
                    <form method="post" action="/team/daten"><?= csrf_feld() ?><input type="hidden" name="aktion" value="herunterladen"><input type="hidden" name="name" value="<?= e($s['name']) ?>">
                        <p>Die Datei enthält personenbezogene Daten. Nur verschlüsselt aufbewahren.</p>
                        <?= $codeFeld('totp-dl-' . $i) ?><button type="submit" class="knopf">Herunterladen</button></form>
                </details>
                <details class="bestaetigen"><summary class="knopf gefahr klein-knopf">Wiederherstellen</summary>
                    <form method="post" action="/team/daten"><?= csrf_feld() ?><input type="hidden" name="aktion" value="wiederherstellen"><input type="hidden" name="name" value="<?= e($s['name']) ?>">
                        <p>Alle Daten werden durch diesen Stand ersetzt, auch die Team-Konten. Der jetzige Stand wird vorher gesichert.</p>
                        <?= $codeFeld('totp-wh-' . $i) ?><button type="submit" class="knopf gefahr">Ja, wiederherstellen</button></form>
                </details>
                <details class="bestaetigen"><summary class="knopf zweit klein-knopf">Löschen</summary>
                    <form method="post" action="/team/daten"><?= csrf_feld() ?><input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="name" value="<?= e($s['name']) ?>">
                        <p>Diese Sicherung wird endgültig gelöscht.</p>
                        <button type="submit" class="knopf gefahr">Ja, löschen</button></form>
                </details>
            </div></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
<?php
team_seite_fuss();
