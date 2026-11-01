<?php
declare(strict_types=1);

/*
 * Daten: Sicherungen und Testdaten (nur Betreiber; E-47). Zahlen zum
 * Mailversand stehen seit AP-15 auf der Status-Seite (E-65).
 * Herunterladen und Wiederherstellen verlangen den eigenen zweiten Faktor
 * (Code aus App oder Mail, Wiederherstellungscode oder Passkey; wie der
 * Rollenwechsel, E-41, Review B-7). Testdaten laden gibt es nur
 * auf Staging und in der Entwicklung, nie auf Produktion.
 */

$konto = team_tor(['betreiber']);
$ich = (int)$konto['id'];
$fehler = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    zweitfaktor_mailcode_aktion($konto, '/team/daten');
    $name = (string)($_POST['name'] ?? '');
    switch ((string)($_POST['aktion'] ?? '')) {
        case 'host_neu':
            try {
                host_anlegen((string)($_POST['host'] ?? ''));
                audit_schreiben('host', 'Host ' . (string)$_POST['host'] . ' hinzugefügt', $ich);
                flash_setzen('ok', 'Hostname hinzugefügt. Die Domain muss beim Hoster auf diesen Ordner zeigen.');
            } catch (InvalidArgumentException) {
                flash_setzen('fehler', 'Das ist kein gültiger Hostname (z. B. www.winter-notfallsymposium.de).');
            }
            umleiten('/team/daten');
        case 'host_haupt':
        case 'host_aus':
            flash_setzen(host_aendern((string)($_POST['host'] ?? ''), $_POST['aktion'] === 'host_haupt' ? 'haupt' : 'aus', $ich) ? 'ok' : 'fehler', 'Gespeichert.');
            umleiten('/team/daten');
        case 'absender':
            // Je Postfach (E-27, E-50): System (anmeldung@) oder Newsletter (newsletter@)
            $nl = ($_POST['postfach'] ?? '') === 'newsletter' ? '_newsletter' : '';
            $adr = trim((string)($_POST['mail_absender'] ?? ''));
            $nam = trim(preg_replace('/\s+/u', ' ', (string)($_POST['mail_absender_name'] ?? '')) ?? '');
            if ($adr !== '' && mail_adresse($adr) === null) {
                flash_setzen('fehler', 'Die Absenderadresse ist keine gültige Adresse.');
            } else {
                einstellung_setzen('mail_absender' . $nl, $adr === '' ? null : mail_adresse($adr));
                if ($nl !== '') {
                    einstellung_setzen('mail_absender_name' . $nl, $nam === '' ? null : mb_substr($nam, 0, 80));
                } else {
                    // System-Postfach: Name der Reihe (E-67); der Absender der Mails zu einer Anmeldung ist die Veranstaltung
                    $reihe = trim(preg_replace('/\s+/u', ' ', (string)($_POST['reihe_name'] ?? '')) ?? '');
                    einstellung_setzen('reihe_name', $reihe === '' ? null : mb_substr($reihe, 0, 60));
                    einstellung_setzen('mail_absender_name', null);
                }
                audit_schreiben('mail', 'Absender ' . ($nl !== '' ? 'Newsletter' : 'System') . ' geändert: ' . ($adr ?: 'aus config.php'), $ich);
                flash_setzen('ok', 'Absender gespeichert. Prüf ihn mit „Testmail an mich“.');
            }
            umleiten('/team/daten');
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
            } elseif (($f = zweitfaktor_aus_formular($ich)) !== null) {
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
            if (($f = zweitfaktor_aus_formular($ich)) !== null) {
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
$codeFeld = fn(string $id) => zweitfaktor_feld_html($konto, $id);
$groesse = fn(int $b) => $b >= 1048576 ? zahl_text($b / 1048576, 1) . ' MB' : zahl_text(max(1, (int)round($b / 1024))) . ' KB';
team_seite_kopf($konto, 'Betrieb', '/team/daten', ['skripte' => ['zweitfaktor.js']]);
?>
<?= fehler_html($fehler) ?>
<div class="team-raster">
    <div class="kasten">
        <h2 class="zwischen">Sicherung</h2>
        <p>Eine Sicherung enthält alle Daten: Einstellungen, Programm, Fotos, Anmeldungen und Team-Konten. Sie liegt auf dem Server außerhalb des Webs. Herunterladen und Wiederherstellen verlangen deinen Code.</p>
        <p class="meta klein"><strong>Automatisch</strong> alle 3 Stunden (über den Cron). Es bleiben alle der letzten 3 Tage, bis 7 Tage eine je Tag, bis 14 Tage eine je 3 Tage, bis 28 Tage eine je Woche. Von Hand angelegte werden nach 90 Tagen gelöscht (E-59). Für länger: herunterladen und verschlüsselt aufbewahren.</p>
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
    <p>Eingerichtet, Warteschlange, Tagesstand je Postfach und letzter Fehler stehen unter <a href="/team/status#zeile-mail">Status</a>.</p>
    <?php if ($ms['umleiten'] !== ''): ?><p>Umleitung: alle Mails gehen an <span class="mono"><?= e($ms['umleiten']) ?></span>.</p><?php endif; ?>
    <form method="post" action="/team/daten" class="abstand-oben-klein"><?= csrf_feld() ?><input type="hidden" name="aktion" value="testmail"><button class="knopf zweit" type="submit">Testmail an mich</button></form>
    <p class="meta klein">Versendet sofort an <?= e($konto['email']) ?> und zeigt den Fehler des Mailservers, falls er die Mail ablehnt. Das Passwort wird nie angezeigt.</p>
</div>

<div class="team-raster abstand-oben">
    <div class="kasten">
        <h2 class="zwischen">Hostnamen</h2>
        <p class="meta klein">Unter diesen Namen antwortet die Webseite. Links in Mails nutzen den Haupt-Host (E-27).</p>
        <ul class="team-liste">
        <?php foreach (host_liste(true) as $hz): ?>
            <li><span class="mono"><?= e($hz['name']) ?></span> <?= (int)$hz['haupt'] === 1 ? '<span class="chip ok">Haupt-Host</span>' : '' ?>
                <?php if ((int)$hz['haupt'] !== 1): ?><span class="zellen-aktionen">
                    <form method="post" action="/team/daten"><?= csrf_feld() ?><input type="hidden" name="aktion" value="host_haupt"><input type="hidden" name="host" value="<?= e($hz['name']) ?>"><button class="knopf zweit klein-knopf" type="submit">Zum Haupt-Host</button></form>
                    <form method="post" action="/team/daten"><?= csrf_feld() ?><input type="hidden" name="aktion" value="host_aus"><input type="hidden" name="host" value="<?= e($hz['name']) ?>"><button class="knopf zweit klein-knopf" type="submit">Abschalten</button></form>
                </span><?php endif; ?></li>
        <?php endforeach; ?>
        </ul>
        <form method="post" action="/team/daten" class="form abstand-oben-klein"><?= csrf_feld() ?><input type="hidden" name="aktion" value="host_neu">
            <div class="feld"><label for="host">Weiterer Hostname</label><input id="host" name="host" type="text" placeholder="www.winter-notfallsymposium.de" autocomplete="off"></div>
            <div><button class="knopf zweit" type="submit">Hinzufügen</button></div></form>
    </div>
    <div class="kasten">
        <h2 class="zwischen">Absender</h2>
        <p class="meta klein">Leer: aus der config.php. Die Adresse muss zum Postfach passen, über das versendet wird – sonst lehnt der Mailserver ab.</p>
        <?php foreach (['system' => ['', 'System-Mails (Anmeldung, Links, Angebote)'], 'newsletter' => ['_newsletter', 'Mailings über newsletter@']] as $pf => [$suf, $titel]): ?>
        <form method="post" action="/team/daten" class="form abstand-oben-klein"><?= csrf_feld() ?><input type="hidden" name="aktion" value="absender"><input type="hidden" name="postfach" value="<?= e($pf) ?>">
            <p class="unterkopf"><?= e($titel) ?></p>
            <div class="feld"><label for="mail_absender<?= $suf ?>">Absenderadresse</label><input id="mail_absender<?= $suf ?>" name="mail_absender" type="email" value="<?= e((string)(einstellung('mail_absender' . $suf) ?? '')) ?>" placeholder="<?= e(mail_absender(mail_konfig($pf))) ?>"></div>
            <?php if ($pf === 'system'): ?>
            <div class="feld"><label for="reihe_name">Name der Reihe</label><input id="reihe_name" name="reihe_name" type="text" maxlength="60" value="<?= e((string)(einstellung('reihe_name') ?? '')) ?>" placeholder="Allgäu Akut">
                <p class="hilfe">Absender, Betreff und Gruß der Mails, die für alle Veranstaltungen gelten (Team, Konto, Newsletter). Mails zu einer Anmeldung tragen den Namen der Veranstaltung (E-67).</p></div>
            <?php else: ?>
            <div class="feld"><label for="mail_absender_name<?= $suf ?>">Absendername</label><input id="mail_absender_name<?= $suf ?>" name="mail_absender_name" type="text" maxlength="80" value="<?= e((string)(einstellung('mail_absender_name' . $suf) ?? '')) ?>" placeholder="<?= e(reihe_name()) ?>"></div>
            <?php endif; ?>
            <div><button class="knopf zweit" type="submit">Speichern</button></div></form>
        <?php endforeach; ?>
    </div>
</div>

<?php $cme = ds_cme_liste(); ?>
<div class="kasten abstand-oben" id="cme-nachweise">
    <h2 class="zwischen">CME-Nachweise</h2>
    <p class="meta klein">Name, EFN und Punkte der anwesenden ÄrztInnen, abgelegt, wenn die Anmeldung 14 Monate nach der Veranstaltung geleert wird; gelöscht 10 Jahre nach der Veranstaltung (E-24, E-59). Nur für BetreiberInnen.</p>
    <p><?= count($cme) ?> <?= count($cme) === 1 ? 'Nachweis' : 'Nachweise' ?><?= $cme !== [] ? ' · älteste Veranstaltung ' . e(tag_text((string)end($cme)['datum'])) : '' ?></p>
    <?php if ($cme !== []): ?><p class="aktionen"><?= export_knoepfe(['art' => 'cme_nachweise']) ?></p><?php endif; ?>
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
            <td data-label="Angelegt" class="mono"><?= $k !== null ? e(datum_zeit_text($k['erstellt'])) : '–' ?><br><span class="meta klein"><?= e($s['name']) ?></span></td>
            <td data-label="Anlass"><?= $k !== null ? ($k['anlass'] === DS_SICHERUNG_AUTOMATISCH ? '<span class="chip offen">automatisch</span>' : e($k['anlass'])) . '<br><span class="meta klein">Version ' . e($k['version']) . '</span>' : '<span class="chip wl">nicht lesbar</span>' ?></td>
            <td data-label="Größe" class="mono"><?= e($groesse($s['groesse'])) ?></td>
            <td data-label="Aktionen"><div class="zellen-aktionen">
                <details class="bestaetigen" data-art="gruen" data-titel="Sicherung herunterladen?"><summary class="knopf zweit klein-knopf">Herunterladen</summary>
                    <form method="post" action="/team/daten"><?= csrf_feld() ?><input type="hidden" name="aktion" value="herunterladen"><input type="hidden" name="name" value="<?= e($s['name']) ?>">
                        <p>Die Datei enthält personenbezogene Daten. Nur verschlüsselt aufbewahren.</p>
                        <?= $codeFeld('totp-dl-' . $i) ?><button type="submit" class="knopf">Herunterladen</button></form>
                </details>
                <details class="bestaetigen" data-titel="Sicherung wiederherstellen?"><summary class="knopf gefahr klein-knopf">Wiederherstellen</summary>
                    <form method="post" action="/team/daten"><?= csrf_feld() ?><input type="hidden" name="aktion" value="wiederherstellen"><input type="hidden" name="name" value="<?= e($s['name']) ?>">
                        <p>Alle Daten werden durch diesen Stand ersetzt, auch die Team-Konten. Der jetzige Stand wird vorher gesichert.</p>
                        <?= $codeFeld('totp-wh-' . $i) ?><button type="submit" class="knopf gefahr">Ja, wiederherstellen</button></form>
                </details>
                <details class="bestaetigen" data-titel="Sicherung löschen?"><summary class="knopf zweit klein-knopf">Löschen</summary>
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
