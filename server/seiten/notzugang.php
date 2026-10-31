<?php
declare(strict_types=1);

/*
 * Notzugang für Betreiber (O-34): Wer Handy und Wiederherstellungscodes
 * verloren hat, gibt den Installationsschlüssel aus config.php und die
 * E-Mail-Adresse eines Betreiber-Kontos ein. Dann wird der zweite Faktor
 * zurückgesetzt und ein Passwort-Link angezeigt.
 *
 * Wer den Schlüssel kennt, hat ohnehin Zugriff auf die Dateien des Webspace.
 * Trotzdem: strenger Ratenschutz, gleiche Meldung bei jedem Fehler, Eintrag
 * im Audit-Protokoll. Bewusst nicht verlinkt; beschrieben in docs/betrieb.md.
 */

team_sitzung();
$fehler = [];
$link = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $ip = rate_merkmal_ip();
    if (!rate_erlaubt('notzugang', $ip)) {
        $fehler[] = 'Zu viele Versuche. Bitte warte eine Stunde und versuche es dann noch einmal.';
    } else {
        $beginn = microtime(true);
        $schluesselOk = hash_equals((string)konfig('installations_schluessel'), (string)($_POST['schluessel'] ?? ''));
        $k = konto_per_email((string)($_POST['email'] ?? ''));
        rate_gleiche_dauer($beginn);
        if ($schluesselOk && $k !== null && $k['rolle'] === 'betreiber') {
            rate_erfolg('notzugang', $ip);
            $id = (int)$k['id'];
            totp_zuruecksetzen($id, 'Notzugang mit Installationsschlüssel');
            if ((int)$k['aktiv'] !== 1) {
                konto_aktiv_setzen($id, true, null);
            }
            $link = konto_link_url(konto_link_erzeugen($id, $k['passwort_hash'] === null ? 'einrichten' : 'passwort'));
            audit_schreiben('notzugang', 'Notzugang benutzt für Konto ' . $id);
        } else {
            rate_misserfolg('notzugang', $ip);
            $fehler[] = 'Installationsschlüssel oder E-Mail-Adresse stimmt nicht.';
        }
    }
}

seite_kopf('Notzugang', ['nav' => false]);
echo '<div class="innen seitenkopf"><p class="kicker">Team-Bereich</p><h1 class="titel">Notzugang</h1></div><div class="innen schmal">';
echo fehler_html($fehler);
if ($link !== null): ?>
<div class="kasten link-kasten">
    <p><strong>Zweiter Faktor zurückgesetzt.</strong> Öffne diesen Link, setze ein neues Passwort und richte den zweiten Faktor neu ein. Er gilt 72 Stunden und einmal und wird nur jetzt angezeigt.</p>
    <p class="mono umbruch"><a href="<?= e($link) ?>"><?= e($link) ?></a></p>
    <p class="meta klein">Danach empfohlen: den Installationsschlüssel in der config.php durch einen neuen ersetzen.</p>
</div>
<?php else: ?>
<p>Nur für BetreiberInnen, die Handy und Wiederherstellungscodes verloren haben. Du brauchst den Installationsschlüssel aus der <span class="mono">config.php</span> auf dem Webspace.</p>
<form method="post" action="/notzugang" class="formular">
    <?= csrf_feld() ?>
    <label for="schluessel">Installationsschlüssel</label>
    <input id="schluessel" name="schluessel" type="password" required autocomplete="off">
    <label for="email">E-Mail-Adresse des BetreiberIn-Kontos</label>
    <input id="email" name="email" type="email" required maxlength="254" autocomplete="username">
    <button type="submit" class="knopf">Zweiten Faktor zurücksetzen</button>
</form>
<?php endif;
echo '</div>';
seite_fuss();
