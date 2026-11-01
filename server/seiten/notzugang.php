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

if (ist_post()) {
    $ip = rate_merkmal_ip();
    if (!rate_erlaubt('notzugang', $ip)) {
        $fehler[] = rate_text('notzugang');
    } else {
        $beginn = microtime(true);
        $schluesselOk = hash_equals((string)konfig('installations_schluessel'), post_text('schluessel'));
        $k = konto_per_email(post_text('email'));
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
echo seiten_kopfzeile('Notzugang', 'Team-Bereich') . '<div class="innen schmal">';
echo fehler_html($fehler);
if ($link !== null): ?>
<div class="kasten">
    <p><strong>Zweiter Faktor zurückgesetzt.</strong> Öffne diesen Link, setze ein neues Passwort und richte den zweiten Faktor neu ein. Er gilt <?= e(dauer_text(KONTO_LINK_GUELTIG_S)) ?> und einmal und wird nur jetzt angezeigt.</p>
    <p class="mono umbruch"><a href="<?= e($link) ?>"><?= e($link) ?></a></p>
    <p class="meta klein">Danach empfohlen: den Installationsschlüssel in der config.php durch einen neuen ersetzen.</p>
</div>
<?php else: ?>
<p>Nur für BetreiberInnen, die Handy und Wiederherstellungscodes verloren haben. Du brauchst den Installationsschlüssel aus der <span class="mono">config.php</span> auf dem Webspace.</p>
<form method="post" action="/notzugang" class="formular">
    <?= csrf_feld() ?>
    <?= feld('schluessel', 'Installationsschlüssel', ['typ' => 'passwort', 'pflicht' => true, 'ohne_rahmen' => true, 'attr' => ['autocomplete' => 'off']]) ?>
    <?= feld('email', 'E-Mail-Adresse des BetreiberIn-Kontos', ['typ' => 'email', 'pflicht' => true, 'ohne_rahmen' => true, 'attr' => ['maxlength' => 254, 'autocomplete' => 'username']]) ?>
    <button type="submit" class="knopf">Zweiten Faktor zurücksetzen</button>
</form>
<?php endif;
echo '</div>';
seite_fuss();
