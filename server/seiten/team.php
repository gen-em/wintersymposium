<?php
declare(strict_types=1);

/*
 * Team-Bereich, Übersicht (AP-3 Schritt 1). Reiter für Programm und
 * Einstellungen kommen mit Schritt 2 und 3, die übrigen mit AP-5 (E-41).
 */

$konto = team_tor();
$zf = totp_zustand((int)$konto['id']);

team_seite_kopf($konto, 'Übersicht', '/team');
if ($zf['codes_offen'] <= 2) {
    echo '<div class="meldung fehler" role="alert">Du hast nur noch ' . $zf['codes_offen'] . ' Wiederherstellungscodes. '
        . 'Erzeuge unter <a href="/team/profil">Mein Konto</a> neue.</div>';
}
?>
<p>Hallo <?= e($konto['name']) ?>. Hier entsteht der Team-Bereich.</p>
<div class="kacheln">
    <div class="kachel"><div class="lbl">Programm</div><p>Programm, Workshops und Dozierende pflegen.</p><p><a href="/team/programm">Öffnen</a></p></div>
    <div class="kachel"><div class="lbl">Einstellungen</div><p>Datum, Ort, Kapazität, Anmeldezeitraum, Fristen.</p><p><a href="/team/einstellungen">Öffnen</a></p></div>
    <div class="kachel"><div class="lbl">Mein Konto</div><p>Passwort und Wiederherstellungscodes.</p><p><a href="/team/profil">Öffnen</a></p></div>
    <?php if ($konto['rolle'] === 'betreiber'): ?>
    <div class="kachel"><div class="lbl">Team-Konten</div><div class="zahl"><?= count(array_filter(konto_team_liste(), fn($k) => (int)$k['aktiv'] === 1)) ?></div><p><a href="/team/konten">Verwalten</a></p></div>
    <?php endif; ?>
</div>
<?php
team_seite_fuss();
