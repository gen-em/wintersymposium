<?php
declare(strict_types=1);

/* Testbereich: Startseite mit eigenen Feldern, Titel mit Lila und Trennstelle, Größenstufen, ohne aktuelle Veranstaltung, Einstellungen je Kachel (AP-18, E-70). */

function test_startseite(): void
{
    abschnitt('Startseite: Felder je Veranstaltung (AP-18 Unterpunkt 1, T-309)');
    $f = test_frisch();
    $html = start_html(start_werte());
    pruefe(str_contains($html, '<p class="kicker">Allgäu Akut · Wintersymposium</p>') && str_contains($html, '<span class="sticker hinweis frei">Kostenlos</span>'), 'Zeile leer: Dachmarke · Kurzname; Sticker aus dem Feld');
    pruefe(substr_count($html, '<div class="kasten"><strong>') === 2 && str_contains($html, '<strong>Wie</strong>') && str_contains($html, '<p class="kicker">Worum es geht</p>'), 'leere dritte Kachel erscheint nicht (Beispieldatensatz)');
    pruefe(str_contains($html, '<p class="kicker">Programm</p><h2 class="zwischen" id="programm-titel">Der Abend im Überblick</h2>'), 'Programm-Abschnitt mit Zeile und Überschrift aus den Feldern');
    $w = start_werte();
    $leer = ['start_unter' => '', 'start_sticker' => '', 'start_cme' => '', 'start_programm_kicker' => '', 'start_programm_titel' => '',
        'start_kachel_1_titel' => '', 'start_kachel_1_text' => '', 'start_kachel_2_titel' => '', 'start_kachel_2_text' => ''];
    $h2 = start_html(array_merge($w, $leer));
    pruefe(!str_contains($h2, 'class="unter"') && !str_contains($h2, 'class="sticker') && !str_contains($h2, 'class="merke"') && !str_contains($h2, 'Worum es geht') && !str_contains($h2, 'infos-titel'), 'leere Felder und leerer Abschnitt „Worum es geht“ erscheinen nicht');
    pruefe(str_contains($h2, '<section class="abschnitt" id="programm"><div class="innen"><ol class="programm">') && !str_contains($h2, 'programm-titel') && str_contains($h2, 'class="eckdaten"') && str_contains($h2, 'class="nl-hinweis"'), 'Programm ohne Überschrift; Eckdaten und Newsletter-Hinweis bleiben fest');
    pruefe(start_werte(['start_sticker' => "  Gratis   jetzt ", 'start_unter' => '<b>Fett</b><script>x()</script>'])['start_sticker'] === 'Gratis jetzt'
        && start_werte(['start_unter' => '<b>Fett</b><script>x()</script>'])['start_unter'] === '<strong>Fett</strong>', 'Werte aus dem Formular bereinigt wie beim Speichern (Vorschau)');
    [$g, $fe] = start_texte_speichern(['start_sticker' => str_repeat('x', START_TEXT_MAX + 1), 'start_unter' => 'Neu'], null);
    pruefe($g === [] && isset($fe['start_sticker']) && inhalt('start_unter') !== 'Neu', 'zu langer Text: nichts gespeichert, Fehler am Feld');
    // Migration 22: Zeile und Titel leer, Abschnitte mit den bisher festen Texten, leere Überschrift der Kacheln wie bisher
    inhalt_setzen('start_titel', 'Alter Titel');
    inhalt_setzen('start_kicker', 'Alte Zeile');
    inhalt_setzen('start_infos_titel', '');
    db_ausfuehren("DELETE FROM inhalt WHERE schluessel IN ('start_programm_kicker', 'start_programm_titel', 'start_infos_kicker')");
    foreach (migration_katalog()[22]['sql'] as $sql) {
        db()->exec($sql);
    }
    ctx_weg('inhalt_cache');
    pruefe(inhalt('start_titel') === '' && inhalt('start_kicker') === '' && inhalt('start_programm_kicker') === 'Programm' && inhalt('start_programm_titel') === 'Der Abend im Überblick'
        && inhalt('start_infos_kicker') === 'Worum es geht' && inhalt('start_infos_titel') === 'Worum es geht', 'Migration 22: Startwerte (Zeile, Titel leer; feste Texte als Werte)');
    $entwurf = beispieldaten_entwurf();
    pruefe(va_mit($entwurf, fn() => inhalt('start_programm_titel') === 'Der Abend im Überblick' && inhalt('start_infos_kicker') === 'Worum es geht' && inhalt('start_unter') === ''), 'neue Veranstaltung ohne Vorlage: feste Texte als Startwerte, sonst leer');

    abschnitt('Titel: Lila und Trennstelle (AP-18 Unterpunkt 2, T-310)');
    $b = fn(string $s) => html_bereinigen($s, 'titel');
    pruefe($b('Allgäuer <b>Winter</b> <a href="https://x.example.org">Notfall</a><script>x()</script><h2>Sym</h2>') === 'Allgäuer Winter Notfall Sym', 'Bereinigung: fett, Link, Skript, Überschrift entfernt');
    pruefe($b('Allgäuer <i>Winter</i>') === 'Allgäuer <em>Winter</em>' && $b('A <em><strong>B</strong></em>') === 'A <em>B</em>', 'Lila (Kursiv aus dem Editor) wird em, ohne Verschachtelung');
    pruefe($b('Notfall<span class="trennstelle" contenteditable="false"></span>symposium') === "Notfall\u{AD}symposium" && $b('Notfall&shy;symposium') === "Notfall\u{AD}symposium", 'Trennstelle aus dem Editor und als &shy; ohne Skript');
    pruefe($b("Not\u{AD}\u{AD}fall \u{AD}Wort Ende\u{AD}") === "Not\u{AD}fall Wort Ende", 'Trennstellen nur mitten im Wort, nie doppelt');
    pruefe(start_titel_html('') === 'Allgäuer <em>Winter-Notfallsymposium</em>' && start_titel_html('Eins zwei drei') === 'Eins <em>zwei drei</em>' && start_titel_html('Solo') === '<em>Solo</em>', 'ohne Lila: erstes Wort weiß, Rest lila (ein Wort ganz lila); leer: Name der Veranstaltung');
    pruefe(start_titel_html('Ganz <em>eigen</em> hier') === 'Ganz <em>eigen</em> hier', 'mit Lila-Markierung: genau wie markiert');

    abschnitt('Titel: Größenstufen (AP-18 Unterpunkt 3, T-311)');
    $st = fn(string $t) => start_titel_stufe(start_titel_html($t));
    pruefe($st('Kurz <em>Titel</em>')['stufe'] === 'XL' && $st('Allgäuer Winter-Notfallsymposium')['stufe'] === 'L', 'kurz: XL; „Notfallsymposium“ (16 Zeichen, nach dem Bindestrich teilbar): L');
    $mit = $st("Allgäuer Winter-Notfall\u{AD}symposium");
    pruefe($mit['stufe'] === 'XL' && $mit['laengster'] === 'symposium' && $mit['trennstelle'] && !$mit['notfall'], 'mit Trennstelle: längster Teil „symposium“, Titel bleibt XL');
    pruefe($st('Wintersymposiumsabend')['stufe'] === 'M' && !$st('Wintersymposiumsabend')['notfall'] && $st('Präklinische Notfallversorgungsstrategie')['stufe'] === 'S', 'lang: M (bis 21 Zeichen), länger: S');
    $nf = $st('Donaudampfschifffahrtsgesellschaft');
    pruefe($nf['notfall'] && str_contains($nf['klasse'], 'titel-notfall'), 'passt auch in der Mindestgröße nicht: titel-notfall (Browser darf trennen)');
    beispieldaten_startseite(); // Titel war oben für Migration 22 geleert
    pruefe(str_contains(start_html(start_werte()), '<h1 class="titel titel-xl">Allgäuer <em>Winter-Notfall' . "\u{AD}" . 'symposium</em></h1>'), 'Startseite: Klasse der Stufe am Titel');

    abschnitt('Ohne aktuelle Veranstaltung (AP-18 Unterpunkt 4, T-312)');
    db_ausfuehren("UPDATE veranstaltung SET status = 'abgeschlossen' WHERE status = 'aktuell'");
    va_zwischenspeicher_leeren();
    ctx_weg('va_id');
    $keine = start_html(start_werte());
    pruefe(va_keine() && str_contains($keine, '<h1 class="titel titel-xl">Allgäu <em>Akut</em></h1>') && !str_contains($keine, 'class="kicker">Allgäu Akut ·') && !str_contains($keine, 'id="programm"'), 'Titel = Dachmarke, keine Zeile, kein Programm');
    einstellung_setzen('reihe_name', 'Testmarke');
    pruefe(str_contains(start_html(start_werte()), '>Testmarke</em></h1>'), 'Dachmarke aus der Einstellung (ein Wort: ganz lila)');

    abschnitt('Einstellungen je Kachel (AP-18 Unterpunkt 7, T-315)');
    $f = test_frisch();
    $gruppe = fn(string $g) => array_map('va_formularwert', array_combine($k = array_keys(array_filter(VA_KATALOG, fn($d) => $d['gruppe'] === $g)), $k));
    $r = va_gruppe_pruefen(['veranstaltung_ort' => 'Neuer Ort', 'nachrueck_frist_h' => '5'] + $gruppe('veranstaltung'), 'veranstaltung');
    pruefe($r['fehler'] === [] && array_keys($r['werte']) === array_keys(array_filter(VA_KATALOG, fn($d) => $d['gruppe'] === 'veranstaltung')) && $r['werte']['veranstaltung_ort'] === 'Neuer Ort', 'Kachel: nur die Felder der Gruppe, fremde Felder ignoriert');
    $vorher = va('nachrueck_frist_h');
    va_speichern($r['werte'], null);
    pruefe(va('veranstaltung_ort') === 'Neuer Ort' && va('nachrueck_frist_h') === $vorher && va('veranstaltung_name') === 'Allgäuer Winter-Notfallsymposium', 'gespeichert: nur die Gruppe, übrige unverändert');
    $tag = va('veranstaltung_datum');
    $r = va_gruppe_pruefen(['stichtag_essen' => (new DateTimeImmutable($tag))->modify('+1 day')->format('Y-m-d')] + $gruppe('stichtage'), 'stichtage');
    pruefe(isset($r['fehler']['stichtag_essen']) && $r['eingabe']['veranstaltung_datum'] === $tag, 'Prüfung über Gruppen hinweg (Stichtag nach dem gespeicherten Datum)');
    $z = va_zusammenfassung('veranstaltung');
    pruefe($z[0] === ['Name', 'Allgäuer Winter-Notfallsymposium'] && $z[3] === ['Wo', 'Neuer Ort'] && va_zusammenfassung('bausteine')[0] === ['Essen', 'an'] && str_contains(va_zusammenfassung('listen')[0][1], 'ÄrztIn · NotfallsanitäterIn'), 'Zusammenfassung je Kachel');

    abschnitt('Beispieldatensatz Startseite (AP-18 Unterpunkt 8, T-316)');
    pruefe(str_contains(inhalt('start_titel'), "\u{AD}") && inhalt('start_kachel_3_titel') === '' && inhalt('start_kachel_3_text') === '' && start_hinweise(start_werte())['leer'] === [3], 'Beispieldatensatz: Trennstelle im Titel, dritte Kachel leer (Hinweis „leer“)');
}
