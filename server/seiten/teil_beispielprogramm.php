<?php
declare(strict_types=1);

/*
 * Vorübergehendes Beispielprogramm für die Startseite (Wunsch des Admins
 * 2026-10-29), damit die Seite bis zur Programmfunktion (AP-3) etwas zeigt.
 * Ausdrücklich als Beispiel gekennzeichnet. Mit AP-3 entfernen.
 * Aufbau nach den Mockups (docs/mockups/, Abschnitt Programm).
 */

$beispiel_programm = [
    ['18:00', '18:15', 'info', '', 'Ankommen, Snacks, Getränke', '', 'Foyer', '', ''],
    ['18:15', '18:20', 'info', '', 'Begrüßung', '', 'Hörsaal', '', ''],
    ['18:20', '18:50', 'vt', 'VT 01', 'Wie kommt die Ente in den Schockraum?',
        'Eine Sachgeschichte über die Übergabe vom Rettungsdienst an die Notaufnahme', 'Hörsaal',
        'Die Maus', 'Klingt komisch, ist aber so: Eine gute Übergabe dauert keine Minute. Die Maus zeigt Schritt für Schritt, wer wann redet und was dabei verloren geht.'],
    ['18:50', '19:20', 'vt', 'VT 02', 'Warum piepst der Monitor?',
        'Alarme verstehen, bevor sie nerven', 'Hörsaal',
        'Der Elefant · Die kleine Ente', 'Der Elefant hört jeden Alarm, die kleine Ente fragt nach: Welcher ist wichtig, welcher kann warten – und wie stellt man ihn richtig ein?'],
    ['19:20', '19:45', 'fb', 'FB 01', 'Fall #1: Die Tigerente rollt nicht mehr',
        'Was hättest du gemacht?', 'Hörsaal',
        'Tigerente', 'Ein Fall zum Mitentscheiden: Die Tigerente wird bewusstlos im Garten gefunden. Wir gehen ihn gemeinsam durch – Fehlerkultur statt Heldengeschichte.'],
    ['19:45', '20:00', 'pause', '', 'Pause mit Bier, Snacks und Alkoholfreiem', '', '', '', ''],
    ['20:00', '21:45', 'ws', '', 'Workshops und offene Stationen', '', '', '', ''],
    ['21:45', '22:00', 'info', '', 'Abschluss und Ausklang', '', 'Foyer', '', ''],
];

$beispiel_workshops = [
    ['WS 01', 'Atemweg mit Rüssel', 'Der Elefant', ['20:00–20:45', '21:00–21:45']],
    ['WS 02', 'Kleine PatientInnen, große Ruhe', 'Die kleine Ente', ['20:00–20:30', '20:30–21:00', '21:00–21:30']],
    ['WS 03', 'Ultraschall: Was steckt in der Tigerente?', 'Tigerente', ['20:15–21:15']],
];
$beispiel_offen = [['Reanimation im Maus-Tempo (110 pro Minute)', '20:00–21:45', 'Foyer']];
?>
<div class="meldung beispiel"><strong>Beispielprogramm.</strong> Alles hier ist ausgedacht, damit du siehst, wie das Programm aussehen wird. Das echte Programm erscheint, sobald es veröffentlicht ist.</div>
<ol class="programm">
<?php foreach ($beispiel_programm as [$von, $bis, $art, $nr, $titel, $unter, $ort, $doz, $text]): ?>
    <li class="pp<?= $art === 'pause' ? ' pause' : '' ?><?= $art === 'info' ? ' ablauf' : '' ?>">
        <div class="zeit"><?= e($von) ?><small><?= e($bis) ?></small></div>
        <div>
            <div class="titelzeile">
                <?php if ($nr !== ''): ?><span class="format<?= $art === 'fb' ? ' rot' : '' ?>"><?= e($nr) ?></span><?php endif; ?>
                <h3><?= e($titel) ?></h3>
            </div>
            <?php if ($unter !== ''): ?><p><?= e($unter) ?></p><?php endif; ?>
            <?php if ($doz !== '' || $ort !== ''): ?><p class="meta klein"><?= e(implode(' · ', array_filter([$doz, $ort]))) ?></p><?php endif; ?>
            <?php if ($text !== ''): ?><details class="pp-infos"><summary>Infos</summary><p><?= e($text) ?></p></details><?php endif; ?>
            <?php if ($art === 'ws'): ?>
            <ul class="ws-liste">
                <?php foreach ($beispiel_workshops as [$wnr, $wtitel, $leitung, $termine]): ?>
                <li><span class="format rot"><?= e($wnr) ?></span> <strong><?= e($wtitel) ?></strong>
                    <span class="meta klein"><?= e($leitung) ?><?php foreach ($termine as $t): ?> · <span class="nowrap"><?= e($t) ?></span><?php endforeach; ?></span></li>
                <?php endforeach; ?>
                <?php foreach ($beispiel_offen as [$otitel, $ozeit, $oort]): ?>
                <li class="offen"><span class="format offen">OFFEN</span> <strong><?= e($otitel) ?></strong>
                    <span class="meta klein"><?= e($ozeit) ?> · <?= e($oort) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </li>
<?php endforeach; ?>
</ol>
