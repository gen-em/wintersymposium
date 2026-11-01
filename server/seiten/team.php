<?php
declare(strict_types=1);

/*
 * Team-Bereich, Übersicht (Mockup „Übersicht“, AP-5, E-49): Kennzahlen,
 * Workshop-Termine, Essen und „Zu erledigen“ (Plätze nach der Grenze,
 * Mail-Fehler, ablaufende Angebote, unveröffentlichtes Programm; für
 * BetreiberInnen rote Zeilen der Status-Seite, AP-15).
 */

$konto = team_tor();
$zf = totp_zustand((int)$konto['id']);
$z = anm_zahlen();
$seitGestern = team_bestaetigt_seit(time() - 86400);
$termine = team_termine();
$wl = array_filter($termine, fn($t) => $t['id'] !== 0 && $t['warte'] > 0);
$ms = mail_stand();
$essen = team_essen_zahlen();
$aufgaben = team_aufgaben();

team_seite_kopf($konto, 'Übersicht', '/team');
if ($zf['codes_offen'] <= 2) {
    $text = 'Du hast nur noch ' . $zf['codes_offen'] . ' Wiederherstellungscodes. ';
    echo meldung_html($text, 'fehler', ['text_html' => e($text) . 'Erzeuge unter <a href="/konto/zweitfaktor">Mein Konto › Zwei-Faktor</a> neue.']);
}
?>
<p>Hallo <?= e($konto['name']) ?>. <span class="meta klein mono">Stand <?= e(jetzt_text()) ?> · Version <?= e(app_version()) ?> · <?= e(ucfirst(umgebung())) ?></span></p>
<div class="kacheln">
    <?= kachel('Anmeldungen', $z['symposium_belegt'] . ($z['kapazitaet'] !== null ? ' / ' . $z['kapazitaet'] : ''), $z['bestaetigt'] . ' bestätigt · +' . $seitGestern . ' seit gestern · ' . $z['unbestaetigt'] . ' noch nicht bestätigt', ['/team/personen', 'Personen']) ?>
    <?= kachel('Warteliste', array_sum(array_column($termine, 'warte')), $z['symposium_warte'] . ' fürs Symposium · über ' . count($wl) . ' Termine') ?>
    <?= kachel('Offene Angebote', $z['angebote'], 'Frist läuft') ?>
    <?= kachel('Mails heute', $ms['heute'] . ' / ' . $ms['limit'], ($ms['eingerichtet'] ? 'System-Postfach' : 'nicht eingerichtet') . ($ms['offen'] > 0 ? ' · ' . $ms['offen'] . ' warten' : '')) ?>
</div>

<div class="team-raster abstand-oben">
    <div class="kasten">
        <h2 class="zwischen">Zu erledigen</h2>
        <?php if ($aufgaben === []): ?><?= leer_hinweis('Nichts offen.') ?><?php else: ?>
        <ul class="aufgaben">
            <?php foreach ($aufgaben as $af): ?>
            <li><?= chip(in_array($af['art'], ['Manuell', 'Betrieb'], true) ? 'wl' : 'offen', $af['art']) ?> <span><?= e($af['text']) ?></span> <a class="knopf zweit klein" href="<?= e($af['link']) ?>"><?= e($af['knopf']) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <div class="kasten">
        <h2 class="zwischen">Essen</h2>
        <dl class="liste-def">
            <?php foreach (ANM_ESSEN as $k => $t): ?><div><dt><?= e($t) ?></dt><dd class="mono"><?= $essen[$k] ?? 0 ?></dd></div><?php endforeach; ?>
        </dl>
        <p class="meta klein">Bestätigte Anmeldungen · Essens-Stichtag <?= e(tag_text(anm_stichtag('stichtag_essen'))) ?></p>
    </div>
</div>

<h2 class="zwischen">Workshop-Termine</h2>
<div class="tabelle-wrap"><table class="t karten">
    <thead><tr><th>Termin</th><th>Belegt</th><th>Frei</th><th>Warteliste</th></tr></thead>
    <tbody>
    <?php foreach ($termine as $t): if ($t['id'] === 0) continue; ?>
        <tr><td data-label="Termin"><a href="/team/workshops?t=<?= $t['id'] ?>"><?= e($t['text']) ?></a></td><td data-label="Belegt" class="mono"><?= $t['belegt'] ?> / <?= $t['kap'] ?></td><td data-label="Frei" class="mono"><?= $t['frei'] ?></td><td data-label="Warteliste" class="mono"><?= $t['warte'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (count($termine) === 1): ?><tr><td colspan="4" class="meta">Noch kein veröffentlichtes Programm mit Workshops.</td></tr><?php endif; ?>
    </tbody>
</table></div>
<?php
team_seite_fuss();
