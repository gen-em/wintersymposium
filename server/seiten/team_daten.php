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

if (ist_post()) {
    zweitfaktor_mailcode_aktion($konto, '/team/daten');
    $name = post_text('name');
    switch (aktion()) {
        case 'host_neu':
            try {
                host_anlegen(post_text('host'), false, $ich);
            } catch (InvalidArgumentException) {
                fertig('fehler', 'Das ist kein gültiger Hostname (z. B. www.winter-notfallsymposium.de).', '/team/daten');
            }
            fertig('ok', 'Hostname hinzugefügt. Die Domain muss beim Hoster auf diesen Ordner zeigen.', '/team/daten');
        case 'host_haupt':
        case 'host_aus':
            fertig(host_aendern(post_text('host'), aktion() === 'host_haupt' ? 'haupt' : 'aus', $ich) ? 'ok' : 'fehler', 'Gespeichert.', '/team/daten');
        case 'absender':
            $f = mail_absender_setzen(post_text('postfach'), post_text('mail_absender'), post_text('mail_absender_name'), post_text('reihe_name'), $ich);
            if ($f !== null) {
                fertig('fehler', $f, '/team/daten');
            }
            fertig('ok', 'Absender gespeichert. Prüf ihn mit „Testmail an mich“.', '/team/daten');
        case 'testmail':
            $grund = mail_testen((string)$konto['email'], $ich);
            fertig($grund === null ? 'ok' : 'fehler', $grund === null
                ? 'Testmail an ' . $konto['email'] . ' ist beim Mailserver angenommen. Kommt sie nicht an, liegt es beim Empfang (Spam-Ordner, Absender-Einträge der Domain).'
                : 'Testmail ging nicht raus: ' . $grund, '/team/daten');
        case 'anlegen':
            $n = sicherung_anlegen('von Hand', $ich);
            fertig('ok', 'Sicherung angelegt: ' . $n, '/team/daten');
        case 'herunterladen':
            $p = sicherung_pfad($name);
            if ($p === null) {
                $fehler[] = 'Diese Sicherung gibt es nicht.';
            } elseif (($f = zweitfaktor_aus_formular($ich)) !== null) {
                $fehler[] = $f;
            } else {
                sicherung_herunterladen_protokollieren($name, $ich);
                bild_senden($p, str_ends_with($name, '.gz') ? 'application/gzip' : 'application/x-ndjson', 'no-store', $name);
            }
            break;
        case 'wiederherstellen':
            if (($f = zweitfaktor_aus_formular($ich)) !== null) {
                $fehler[] = $f;
            } elseif (($f = sicherung_wiederherstellen($name, $ich)) !== null) {
                $fehler[] = 'Nicht wiederhergestellt: ' . $f;
            } else {
                fertig('ok', 'Wiederhergestellt: ' . $name . '. Der Stand davor ist als eigene Sicherung abgelegt.', '/team/daten');
            }
            break;
        case 'loeschen':
            $ok = sicherung_loeschen($name, $ich);
            fertig($ok ? 'ok' : 'fehler', $ok ? 'Sicherung gelöscht.' : 'Löschen ging nicht.', '/team/daten');
        case 'testdaten':
            if (!testdaten_moeglich()) {
                $fehler[] = 'Testdaten gibt es nur auf Staging.';
            } else {
                $s = testdaten_laden($ich);
                fertig('ok', 'Testdaten geladen. Der Stand davor ist gesichert als ' . $s . '.', '/team/daten');
            }
            break;
    }
}

$liste = sicherung_liste();
$ms = mail_stand();
$codeFeld = fn(string $id) => zweitfaktor_feld_html($konto, $id);
team_seite_kopf($konto, 'Betrieb', '/team/daten', ['skripte' => ['zweitfaktor.js']]);
?>
<?= fehler_html($fehler) ?>
<div class="team-raster">
    <div class="kasten">
        <h2 class="zwischen">Sicherung</h2>
        <p>Eine Sicherung enthält alle Daten: Einstellungen, Programm, Fotos, Anmeldungen und Team-Konten. Sie liegt auf dem Server außerhalb des Webs. Herunterladen und Wiederherstellen verlangen deinen Code.</p>
        <p class="meta klein"><strong>Automatisch</strong> alle <?= e(dauer_text(DS_SICHERUNG_ABSTAND_S)) ?> (über den Cron). Es bleiben alle der letzten 3 Tage, bis 7 Tage eine je Tag, bis 14 Tage eine je 3 Tage, bis 28 Tage eine je Woche. Von Hand angelegte werden nach <?= DS_SICHERUNG_HAND_TAGE ?> Tagen gelöscht (E-59). Für länger: herunterladen und verschlüsselt aufbewahren.</p>
        <?= aktion_knopf('/team/daten', 'anlegen', [], 'Sicherung anlegen', 'knopf') ?>
    </div>
    <?php if (testdaten_moeglich()): ?>
    <div class="kasten gefahr-kasten">
        <h2 class="zwischen">Testdaten <?= chip('offen', umgebung()) ?></h2>
        <p>Ersetzt Programm, Dozierende, Anmeldungen und Veranstaltungs-Einstellungen durch den Beispieldatensatz: Veranstaltung in 60 Tagen, Anmeldung offen, Workshops mit vollem Termin, Warteliste und offenem Angebot. Team-Konten bleiben. Vorher wird automatisch gesichert.</p>
        <p class="meta klein">Die Testpersonen haben Adressen bei example.org. Damit keine Mails dorthin gehen, in der config.php unter mail den Eintrag umleiten_an setzen.</p>
        <?= bestaetigung_html('Testdaten laden', 'Programm, Anmeldungen und Einstellungen werden ersetzt. Zurück geht es über die Sicherung, die dabei entsteht.', 'Ja, Testdaten laden', ['aktion' => 'testdaten'], ['ziel' => '/team/daten', 'knopfklasse' => 'knopf gefahr']) ?>
    </div>
    <?php endif; ?>
</div>

<div class="kasten abstand-oben">
    <h2 class="zwischen">Mailversand</h2>
    <p>Eingerichtet, Warteschlange, Tagesstand je Postfach und letzter Fehler stehen unter <a href="/team/status#zeile-mail">Status</a>.</p>
    <?php if ($ms['umleiten'] !== ''): ?><p>Umleitung: alle Mails gehen an <span class="mono"><?= e($ms['umleiten']) ?></span>.</p><?php endif; ?>
    <?= aktion_knopf('/team/daten', 'testmail', [], 'Testmail an mich', 'knopf zweit', ['form_klasse' => 'abstand-oben-klein']) ?>
    <p class="meta klein">Versendet sofort an <?= e($konto['email']) ?> und zeigt den Fehler des Mailservers, falls er die Mail ablehnt. Das Passwort wird nie angezeigt.</p>
</div>

<div class="team-raster abstand-oben">
    <div class="kasten">
        <h2 class="zwischen">Hostnamen</h2>
        <p class="meta klein">Unter diesen Namen antwortet die Webseite. Links in Mails nutzen den Haupt-Host (E-27).</p>
        <ul class="team-liste">
        <?php foreach (host_liste(true) as $hz): ?>
            <li><span class="mono"><?= e($hz['name']) ?></span> <?= (int)$hz['haupt'] === 1 ? chip('ok', 'Haupt-Host') : '' ?>
                <?php if ((int)$hz['haupt'] !== 1): ?><span class="zellen-aktionen">
                    <?= aktion_knopf('/team/daten', 'host_haupt', ['host' => $hz['name']], 'Zum Haupt-Host') ?>
                    <?= aktion_knopf('/team/daten', 'host_aus', ['host' => $hz['name']], 'Abschalten') ?>
                </span><?php endif; ?></li>
        <?php endforeach; ?>
        </ul>
        <form method="post" action="/team/daten" class="form abstand-oben-klein"><?= csrf_feld() ?><input type="hidden" name="aktion" value="host_neu">
            <?= feld('host', 'Weiterer Hostname', ['attr' => ['placeholder' => 'www.winter-notfallsymposium.de', 'autocomplete' => 'off']]) ?>
            <div><button class="knopf zweit" type="submit">Hinzufügen</button></div></form>
    </div>
    <div class="kasten">
        <h2 class="zwischen">Absender</h2>
        <p class="meta klein">Leer: aus der config.php. Die Adresse muss zum Postfach passen, über das versendet wird – sonst lehnt der Mailserver ab.</p>
        <?php foreach (['system' => ['', 'System-Mails (Anmeldung, Links, Angebote)'], 'newsletter' => ['_newsletter', 'Mailings über newsletter@']] as $pf => [$suf, $titel]): ?>
        <form method="post" action="/team/daten" class="form abstand-oben-klein"><?= csrf_feld() ?><input type="hidden" name="aktion" value="absender"><input type="hidden" name="postfach" value="<?= e($pf) ?>">
            <p class="unterkopf"><?= e($titel) ?></p>
            <?= feld('mail_absender', 'Absenderadresse', ['typ' => 'email', 'id' => 'mail_absender' . $suf, 'wert' => (string)(einstellung('mail_absender' . $suf) ?? ''), 'attr' => ['placeholder' => mail_absender(mail_konfig($pf))]]) ?>
            <?php if ($pf === 'system'): ?>
            <?= feld('reihe_name', 'Dachmarke', ['wert' => (string)(einstellung('reihe_name') ?? ''), 'attr' => ['maxlength' => 60, 'placeholder' => 'Allgäu Akut'],
                'hilfe' => 'Die Dachmarke über allen Reihen und Veranstaltungen (E-71). Absender, Betreff und Gruß der Mails, die für alle Veranstaltungen gelten (Team, Konto, Newsletter). Mails zu einer Anmeldung tragen den Namen der Veranstaltung (E-67).']) ?>
            <?php else: ?>
            <?= feld('mail_absender_name', 'Absendername', ['id' => 'mail_absender_name' . $suf, 'wert' => (string)(einstellung('mail_absender_name' . $suf) ?? ''), 'attr' => ['maxlength' => 80, 'placeholder' => reihe_name()]]) ?>
            <?php endif; ?>
            <div><button class="knopf zweit" type="submit">Speichern</button></div></form>
        <?php endforeach; ?>
    </div>
</div>

<?php $cme = ds_cme_liste(); ?>
<div class="kasten abstand-oben" id="cme-nachweise">
    <h2 class="zwischen">CME-Nachweise</h2>
    <p class="meta klein">Name, EFN und Punkte der anwesenden ÄrztInnen, abgelegt, wenn die Anmeldung <?= DS_LEEREN_MONATE ?> Monate nach der Veranstaltung geleert wird; gelöscht 10 Jahre nach der Veranstaltung (E-24, E-59). Nur für BetreiberInnen.</p>
    <p><?= count($cme) ?> <?= count($cme) === 1 ? 'Nachweis' : 'Nachweise' ?><?= $cme !== [] ? ' · älteste Veranstaltung ' . e(tag_text((string)end($cme)['datum'])) : '' ?></p>
    <?php if ($cme !== []): ?><p class="aktionen"><?= export_knoepfe(['art' => 'cme_nachweise']) ?></p><?php endif; ?>
</div>

<h2 class="zwischen abstand-oben">Vorhandene Sicherungen</h2>
<?php if ($liste === []): ?>
<?= leer_hinweis('Noch keine Sicherung.') ?>
<?php else: ?>
<div class="tabelle-wrap"><table class="t karten">
    <thead><tr><th>Angelegt</th><th>Anlass</th><th>Größe</th><th>Aktionen</th></tr></thead>
    <tbody>
    <?php foreach ($liste as $i => $s): $k = $s['kopf']; ?>
        <tr>
            <td data-label="Angelegt" class="mono"><?= $k !== null ? e(datum_zeit_text($k['erstellt'])) : '–' ?><br><span class="meta klein"><?= e($s['name']) ?></span></td>
            <td data-label="Anlass"><?= $k !== null ? ($k['anlass'] === DS_SICHERUNG_AUTOMATISCH ? chip('offen', 'automatisch') : e($k['anlass'])) . '<br><span class="meta klein">Version ' . e($k['version']) . '</span>' : chip('wl', 'nicht lesbar') ?></td>
            <td data-label="Größe" class="mono"><?= e(bytes_text((int)$s['groesse'])) ?></td>
            <td data-label="Aktionen"><div class="zellen-aktionen">
                <?= bestaetigung_html('Herunterladen', 'Die Datei enthält personenbezogene Daten. Nur verschlüsselt aufbewahren.', 'Herunterladen', ['aktion' => 'herunterladen', 'name' => $s['name']],
                    ['ziel' => '/team/daten', 'art' => 'gruen', 'titel' => 'Sicherung herunterladen?', 'inhalt_html' => $codeFeld('totp-dl-' . $i)]) ?>
                <?= bestaetigung_html('Wiederherstellen', 'Alle Daten werden durch diesen Stand ersetzt, auch die Team-Konten. Der jetzige Stand wird vorher gesichert.', 'Ja, wiederherstellen', ['aktion' => 'wiederherstellen', 'name' => $s['name']],
                    ['ziel' => '/team/daten', 'titel' => 'Sicherung wiederherstellen?', 'knopfklasse' => 'knopf gefahr klein', 'inhalt_html' => $codeFeld('totp-wh-' . $i)]) ?>
                <?= bestaetigung_html('Löschen', 'Diese Sicherung wird endgültig gelöscht.', 'Ja, löschen', ['aktion' => 'loeschen', 'name' => $s['name']], ['ziel' => '/team/daten', 'titel' => 'Sicherung löschen?']) ?>
            </div></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
<?php
team_seite_fuss();
