<?php
declare(strict_types=1);

/*
 * Team-Bereich, Übersicht (AP-3 Schritt 1). Weitere Reiter kommen mit AP-5 (E-41).
 */

$konto = team_tor();
$zf = totp_zustand((int)$konto['id']);

team_seite_kopf($konto, 'Übersicht', '/team');
if ($zf['codes_offen'] <= 2) {
    echo '<div class="meldung fehler" role="alert">Du hast nur noch ' . $zf['codes_offen'] . ' Wiederherstellungscodes. '
        . 'Erzeuge unter <a href="/team/profil">Mein Konto</a> neue.</div>';
}
?>
<p>Hallo <?= e($konto['name']) ?>. <span class="meta klein mono">Version <?= e(app_version()) ?> · <?= e(ucfirst(umgebung())) ?></span></p>
<?php $z = anm_zahlen(); $ms = mail_stand(); if (!$ms['eingerichtet']): ?><div class="meldung fehler" role="alert">Der Mailversand ist nicht eingerichtet (config.php, Abschnitt mail). Bestätigungen und Links gehen erst raus, wenn er eingerichtet ist.</div><?php endif; ?>
<div class="kacheln">
    <div class="kachel"><div class="lbl">Anmeldungen</div><div class="zahl"><?= $z['bestaetigt'] ?></div><p class="meta klein">bestätigt · <?= $z['unbestaetigt'] ?> offen · <?= $z['symposium_warte'] ?> auf der Warteliste</p><p><a href="/team/anmeldungen">Ansehen</a></p></div>
    <div class="kachel"><div class="lbl">Programm</div><p>Programm, Workshops und Dozierende pflegen.</p><p><a href="/team/programm">Öffnen</a></p></div>
    <div class="kachel"><div class="lbl">Einstellungen</div><p>Datum, Ort, Kapazität, Anmeldezeitraum, Fristen.</p><p><a href="/team/einstellungen">Öffnen</a></p></div>
    <div class="kachel"><div class="lbl">Mein Konto</div><p>Passwort und Wiederherstellungscodes.</p><p><a href="/team/profil">Öffnen</a></p></div>
    <?php if ($konto['rolle'] === 'betreiber'): ?>
    <div class="kachel"><div class="lbl">Team-Konten</div><div class="zahl"><?= count(array_filter(konto_team_liste(), fn($k) => (int)$k['aktiv'] === 1)) ?></div><p><a href="/team/konten">Verwalten</a></p></div>
    <?php endif; ?>
</div>
<?php
team_seite_fuss();
