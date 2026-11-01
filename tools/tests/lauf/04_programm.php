<?php
declare(strict_types=1);

/* Testbereich: Programm – Prüfung der Punkte, Gliederung, Status, Raumhinweise und Titel der Startseite (ohne Beispieldaten). */

function test_programm(): void
{
    abschnitt('Programm (AP-3 Schritt 3)');
    test_frisch(['beispiel' => false]);
    $r = programm_punkt_pruefen(['typ' => 'workshop', 'titel' => 'WS', 't_beginn' => ['20:00', '', '21:00'], 't_ende' => ['20:45', '', '20:30'], 't_plaetze' => ['10', '', '5']]);
    pruefe(isset($r['fehler']['termine']) && str_contains($r['fehler']['termine'], 'Termin 2'), 'Termin mit Ende vor Beginn abgelehnt (leere Zeile übersprungen)');
    $r = programm_punkt_pruefen(['typ' => 'workshop', 'titel' => 'WS', 't_beginn' => ['20:00'], 't_ende' => ['20:45'], 't_plaetze' => ['0']]);
    pruefe(isset($r['fehler']['termine']), 'Termin mit 0 Plätzen abgelehnt');
    $r = programm_punkt_pruefen(['typ' => 'vortrag', 'titel' => 'X', 'beginn' => '18:00']);
    pruefe(isset($r['fehler']['ende']), 'Beginn ohne Ende abgelehnt');
    einstellung_setzen('veranstaltung_name', 'Abend');
    $t1 = va_titel_html();
    einstellung_setzen('veranstaltung_name', 'Fortbildungsabend klinische Notfallmedizin');
    $t3 = va_titel_html();
    pruefe($t1 === '<em>Abend</em>' && $t3 === 'Fortbildungsabend <em>klinische Notfallmedizin</em>', 'Titel der Startseite: ein Wort lila, sonst erstes Wort weiß und Rest lila');
    $r = programm_punkt_pruefen(['typ' => 'pause', 'titel' => 'P']);
    pruefe($r['fehler'] === [], 'Punkt ohne Uhrzeit speicherbar (wird im Plan gezogen)');
    $r = programm_punkt_pruefen(['typ' => 'vortrag', 'titel' => 'Bild', 'beschreibung' => '<p>Text <img src="/bild/text-0123456789abcdef.jpg" alt="x"></p><p><a class="knopf" href="https://example.org">Mehr</a></p>']);
    pruefe(str_contains($r['werte']['beschreibung'], '<img src="/bild/text-0123456789abcdef.jpg"') && str_contains($r['werte']['beschreibung'], 'class="knopf"'), 'Programm-Beschreibung: Bild und Knopf wie in den Texten (Admin 2026-10-31)');
    $d = dozent_pruefen(['name' => 'Bild Test', 'kurzbio' => '<p><img src="/bild/text-0123456789abcdef.png" alt=""></p>']);
    pruefe(str_contains($d['werte']['kurzbio'] ?? '', '<img src="/bild/text-0123456789abcdef.png"'), 'Kurzbio: Bild erlaubt');
    $r = programm_punkt_pruefen(['typ' => 'fremd', 'titel' => '']);
    pruefe(isset($r['fehler']['typ'], $r['fehler']['titel']), 'Typ und Titel Pflicht');
    $r = programm_punkt_pruefen(['typ' => 'block', 'titel' => 'B', 'beginn' => '19:00', 'ende' => '21:00', 'ort' => 'X', 'bring' => 'Y', 'kuerzel' => 'Z']);
    pruefe($r['werte']['ort'] === '' && $r['werte']['bring'] === '' && $r['werte']['kuerzel'] === '', 'Felder, die der Typ nicht hat, werden geleert');
    $mk = fn(int $id, string $typ, string $b, string $e, array $t = []) => ['id' => $id, 'typ' => $typ, 'kuerzel' => '', 'titel' => "T$id", 'untertitel' => '', 'beschreibung' => '', 'ort' => '', 'beginn' => $b, 'ende' => $e, 'bring' => '', 'notiz' => '', 'termine' => $t, 'dozenten' => []];
    $g = programm_gliederung([
        $mk(1, 'block', '19:00', '21:00'),
        $mk(2, 'workshop', '', '', [['id' => 7, 'beginn' => '19:00', 'ende' => '19:45', 'plaetze' => 5], ['id' => 8, 'beginn' => '21:30', 'ende' => '22:00', 'plaetze' => 5]]),
        $mk(3, 'vortrag', '18:00', '18:30'),
        $mk(4, 'station', '19:00', '21:00'),
        $mk(5, 'pause', '', ''),
    ]);
    pruefe(array_map(fn($x) => $x['art'] . ($x['termin']['id'] ?? $x['punkt']['id']), $g) === ['punkt5', 'punkt3', 'block1', 'termin8'], 'Gliederung: ohne Zeit oben, dann nach Uhrzeit; Termin außerhalb des Blocks einzeln');
    pruefe(count($g[2]['kinder']) === 2 && $g[2]['kinder'][0]['art'] === 'termin' && $g[2]['kinder'][1]['art'] === 'punkt', 'Block sammelt Termin und Station');
    $st = programm_status([$mk(1, 'vortrag', '18:00', '18:30'), $mk(2, 'pause', '19:00', '19:15')], ['punkte' => [$mk(1, 'vortrag', '18:00', '18:30'), $mk(3, 'pause', '20:00', '20:15')], 'am' => '']);
    pruefe($st['status'] === [1 => 'veroeffentlicht', 2 => 'neu'] && count($st['geloescht']) === 1 && $st['offen'], 'Status: veröffentlicht, neu, gelöscht');
    pruefe(programm_hinweise([array_merge($mk(1, 'vortrag', '18:00', '18:30'), ['ort' => 'A']), array_merge($mk(2, 'station', '18:15', '19:00'), ['ort' => 'a'])]) === ['A ist 18:15–18:30 doppelt belegt: T1 und T2.'], 'Raum doppelt belegt (Groß-/Kleinschreibung egal)');
    pruefe(hm_aus_minuten(minuten('23:30') + 90) === '23:59', 'Uhrzeit bleibt am selben Tag');
}
