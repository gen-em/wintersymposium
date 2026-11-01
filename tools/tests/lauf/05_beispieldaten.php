<?php
declare(strict_types=1);

/* Testbereich: Test-Beispieldatensatz (mechanisch aus lauf.php ausgelagert, noch nicht auf test_frisch umgestellt). */

function test_beispieldaten(): void
{
    abschnitt('Test-Beispieldatensatz (server/testdaten)');
    $bd = test_frisch()['bd'];
    $vj = tk_veranstaltungen($bd['anmeldungen']['lea_konto']);
    pruefe(va_zeile($bd['vorjahr']['id'])['status'] === 'abgeschlossen' && count($vj) === 3 && $vj[1]['zertifikat'] && $vj[1]['stand'] === 'teilgenommen' && $vj[2]['zertifikat'], 'Beispieldaten: abgeschlossenes Vorjahr, Lea mit drei Veranstaltungen und Zertifikat im Rückblick');
    $ab = $bd['abend'];
    pruefe(va_zeile($ab['id'])['status'] === 'abgeschlossen' && !va_mit($ab['id'], fn() => va_an('mit_essen')) && va_von($ab['id'], 'veranstaltung_art') === 'vortrag'
        && tn_anwesend($ab['kai']) && !tn_anwesend($ab['nora']) && $vj[1]['kuerzel'] === 'fortbildungsabend-notfallmedizin', 'Beispieldaten: Fortbildungsabend ohne Essen, Kai da, Nora nicht erschienen');
    $ada = konto_laden($bd['admin']);
    pruefe($ada['rolle'] === 'admin' && va_erlaubte_ids($ada) === [(int)$ab['id']] && array_column(va_alle_fuer($ada), 'id') === [(int)$ab['id']] && va_darf($ada, (int)$ab['id']) && !va_darf($ada, (int)va_aktuell_id()), 'Beispieldaten: Ada (Admin) nur für den Fortbildungsabend (AP-12)');
    $r2 = $bd['review2'];
    $leaNl = nl_person_per_email('lea@example.org');
    pruefe(anm_laden($r2['fremd_ada'])['status'] === 'unbestaetigt' && anm_laden($r2['fremd_ada'])['email'] === 'ada.abend@example.org' && anm_eigene_team($ada) === null
        && (int)nl_liste($r2['liste_intern'])['sichtbar'] === 0 && $leaNl !== null && (nl_abos((int)$leaNl['id'])[$r2['liste_intern']] ?? '') === 'bestaetigt', 'Beispieldaten (AP-16): unbestätigte Anmeldung mit Adas Adresse, Lea auf ausgeblendeter Liste');
    $personenAda = va_mit((int)$ab['id'], fn() => team_personen($ada));
    $kontenAda = array_filter($personenAda, fn($p) => $p['art'] === 'konto' && $p['konto']['rolle'] === 'teilnehmend');
    pruefe(count(array_filter($personenAda, fn($p) => $p['art'] === 'anm')) === 3 && $kontenAda === [], 'Ada sieht im Abend dessen drei Anmeldungen, keine fremden Konten');
    pruefe(va_rechte_liste($ada) === [va_von((int)$ab['id'], 'veranstaltung_name') . ' (' . tag_text(va_von((int)$ab['id'], 'veranstaltung_datum')) . ')'] && va_rechte_liste(['id' => 0, 'rolle' => 'betreiber']) === ['alle Veranstaltungen', 'Betrieb (Veranstaltungen, Team-Konten, Newsletter, Texte)'], 'Mein Konto: Liste „zuständig für“');
    $pv = programm_veroeffentlicht()['punkte'] ?? [];
    pruefe(count($pv) === count($bd['programm']['punkte']) && !programm_status(programm_arbeitsstand(), programm_veroeffentlicht())['offen'], 'Programm geladen und vollständig veröffentlicht');
    $bl = array_values(array_filter(programm_gliederung($pv), fn($x) => $x['art'] === 'block'));
    pruefe(count($bl) === 1 && count($bl[0]['kinder']) === 7, 'Workshop-Block mit 5 Terminen, 1 Station und 1 Fallbesprechung');
    // Einfügen mit Nachrücken (Admin 2026-10-29): 30-min-Pause nach Fall #1 (endet 19:15)
    $r = programm_punkt_pruefen(['typ' => 'pause', 'titel' => 'Eingefügt']);
    $neuId = programm_punkt_speichern(null, $r, null);
    $zeit = fn(string $titel) => db_zeile('SELECT TIME_FORMAT(beginn, "%H:%i") AS b, TIME_FORMAT(ende, "%H:%i") AS e FROM programmpunkt WHERE titel = ?', [$titel]);
    $neu = programm_verschieben('punkt', $neuId, '19:15', null, true, $nachNr);
    pruefe($neu === '19:15–19:45' && $nachNr === 2 && $zeit('Workshops und offene Stationen') == ['b' => '19:45', 'e' => '21:45'] && $zeit('Abschluss') == ['b' => '21:45', 'e' => '22:15'], 'Einfügen: Block und Abschluss rücken nach hinten');
    pruefe(db_wert("SELECT MIN(t.beginn) FROM workshop_termin t JOIN programmpunkt p ON p.id = t.punkt_id WHERE p.veranstaltung_id = ?", [va_id()]) === '19:45:00' && db_wert("SELECT beginn FROM programmpunkt WHERE typ = 'station' AND veranstaltung_id = ?", [va_id()]) === '19:45:00', 'Block nimmt beim Nachrücken Termine und Station mit');
    pruefe($zeit('Fall #1') == ['b' => '18:45', 'e' => '19:15'], 'Punkte davor bleiben');
    $r = programm_punkt_pruefen(['typ' => 'pause', 'titel' => 'Ohne Folgen']);
    $id2 = programm_punkt_speichern(null, $r, null);
    programm_verschieben('punkt', $id2, '23:00', null, true, $nachNr);
    pruefe($nachNr === 0, 'Lücke danach: nichts rückt');
    programm_verwerfen(null);
    pruefe($zeit('Abschluss') == ['b' => '21:30', 'e' => '22:00'] && $zeit('Eingefügt') === null, 'Verwerfen stellt den Plan wieder her');
}
