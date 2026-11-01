<?php
declare(strict_types=1);

/* Testbereich: Veranstaltungsreihen und Newsletter (AP-19, E-71) – Dachmarke, Reihen mit Liste, Migration, Rechte je Reihe, Allgäu Akut mit Interessen, Zielgruppe, Beispieldaten. */

function test_reihen(): void
{
    abschnitt('Dachmarke (AP-19 Unterpunkt 1, T-317)');
    $f = test_frisch();
    pruefe(reihe_name() === 'Allgäu Akut' && mail_kicker('newsletter') === 'Newsletter · Allgäu Akut', 'Dachmarke unverändert in Mails (Wert der Einstellung reihe_name)');

    abschnitt('Reihen als eigenes Objekt (AP-19 Unterpunkt 2, T-318)');
    $reihen = array_column(va_reihen(), null, 'name');
    $winter = $reihen['Wintersymposium'] ?? null;
    $abend = $reihen['Fortbildungsabend'] ?? null;
    $vAkt = (int)va_aktuell_id();
    $vVor = (int)$f['bd']['vorjahr']['id'];
    $vAbend = (int)$f['bd']['abend']['id'];
    pruefe($winter !== null && $abend !== null && $winter['veranstaltungen'] === 2 && $abend['veranstaltungen'] === 1
        && va_reihe_von($vAkt)['id'] === $winter['id'] && va_reihe_von($vVor)['id'] === $winter['id'] && va_reihe_von($vAbend)['id'] === $abend['id'], 'Beispieldaten: Reihe Wintersymposium (2026, 2027) und Fortbildungsabend');
    // Migration 23 (O-53): je Kurzname eine Reihe – auf dem Stand vor der Zuordnung nachgestellt
    db_ausfuehren('UPDATE veranstaltung SET reihe_id = NULL');
    db_ausfuehren('DELETE FROM reihe');
    $sql = migration_katalog()[23]['sql'];
    db()->exec($sql[2]);
    db()->exec($sql[3]);
    ctx_weg('va_reihen_da');
    $m = array_column(va_reihen(), null, 'kurz');
    pruefe(array_keys($m) === ['Fortbildungsabend', 'Wintersymposium'] && $m['Wintersymposium']['veranstaltungen'] === 2 && $m['Fortbildungsabend']['veranstaltungen'] === 1
        && (int)va_reihe_von($vVor)['id'] === $m['Wintersymposium']['id'], 'Migration: je Kurzname eine Reihe, zwei Wintersymposien und ein Abend zugeordnet');
    $f = test_frisch();
    $winter = array_column(va_reihen(), null, 'name')['Wintersymposium'];
    $neu = va_anlegen('wintersymposium2028', 'Allgäuer Winter-Notfallsymposium', $vAkt = (int)va_aktuell_id(), null);
    pruefe(isset($neu['id']) && va_reihe_von($neu['id'])['id'] === $winter['id'], 'Kopie behält die Reihe');
    $einzeln = va_anlegen('einzelabend', 'Einzelner Abend', $vAkt, null, 0);
    pruefe(isset($einzeln['id']) && va_reihe_von($einzeln['id']) === null, 'Kopie mit „einzeln“: keine Reihe');
    $rid = va_reihe_speichern(null, va_reihe_pruefen(['name' => 'Journal Club', 'kurz' => 'Journal Club'])['werte'], null);
    $jc = va_anlegen('journalclub1', 'Journal Club Januar', null, null, $rid);
    pruefe(isset($jc['id']) && va_reihe_von($jc['id'])['id'] === $rid && va_mit($jc['id'], fn() => va_kurz()) === 'Journal Club', 'neue Veranstaltung in einer Reihe: Kurzname der Reihe vorbelegt');
    pruefe(va_reihe_pruefen(['name' => 'journal club', 'kurz' => 'x'])['fehler'] !== [] && va_reihe_pruefen(['name' => '', 'kurz' => ''])['fehler'] !== [], 'Reihe: Name Pflicht und eindeutig, Kurzname Pflicht');
    pruefe(va_reihe_loeschen($rid, null) !== null && va_reihe($rid) !== null, 'Löschen mit Veranstaltungen abgelehnt (O-51)');
    $leer = va_reihe_speichern(null, va_reihe_pruefen(['name' => 'Sommerakademie', 'kurz' => 'Sommerakademie'])['werte'], null);
    $leerListe = va_reihe($leer)['liste_id'];
    $sp = nl_person_anlegen('sam@example.org', 'Sam');
    nl_listen_setzen($sp, [$leerListe], 'formular');
    $sicher = count(sicherung_liste());
    pruefe(va_reihe_loeschen($leer, null) === null && va_reihe($leer) === null && nl_liste($leerListe) === null && nl_person($sp) === null && count(sicherung_liste()) === $sicher + 1,
        'Löschen ohne Veranstaltungen: Liste und Abonnierende mit, vorher Sicherung (O-51)');
    $vk = va_aktuell_id();
    va_reihe_setzen((int)$vk, null, null);
    pruefe(va_reihe_von((int)$vk) === null && va_zusammenfassung('reihe')[0] === ['Reihe', 'einzeln'], 'Reihe einer Veranstaltung setzen (einzeln), Zusammenfassung der Kachel');
    va_reihe_setzen((int)$vk, $winter['id'], null);

    abschnitt('Newsletter je Reihe (AP-19 Unterpunkt 3, T-319)');
    $wl = nl_liste($winter['liste_id']);
    pruefe($wl !== null && $wl['name'] === 'Wintersymposium' && $wl['art'] === 'reihe' && (int)$wl['id'] === (int)array_column(nl_listen(), 'id', 'name')['Wintersymposium'], 'bestehende Liste „Wintersymposium“ gehört der Reihe');
    $jl = nl_liste(va_reihe($rid)['liste_id']);
    pruefe($jl !== null && $jl['name'] === 'Journal Club' && $jl['art'] === 'reihe' && (int)$jl['sichtbar'] === 1, 'neue Reihe → neue öffentliche Liste mit ihrem Namen');
    va_reihe_speichern($rid, va_reihe_pruefen(['name' => 'Journal Club Notfallmedizin', 'kurz' => 'Journal Club', 'beschreibung' => 'Ein Paper, ein Abend.'], $rid)['werte'], null);
    $jl = nl_liste((int)$jl['id']);
    pruefe($jl['name'] === 'Journal Club Notfallmedizin' && $jl['beschreibung'] === 'Ein Paper, ein Abend.', 'Umbenennen zieht Name und Beschreibung der Liste mit');
    nl_liste_speichern((int)$jl['id'], ['name' => 'Anders', 'beschreibung' => 'x', 'absender_name' => 'JC', 'sichtbar' => 1], null);
    pruefe(nl_liste((int)$jl['id'])['name'] === 'Journal Club Notfallmedizin' && nl_liste((int)$jl['id'])['absender_name'] === 'JC', 'Liste einer Reihe: Name bleibt bei der Reihe, Absender änderbar');
    pruefe(nl_liste_dachmarke()['name'] === 'Allgäu Akut' && array_count_values(array_column(nl_listen(), 'art'))['dachmarke'] === 1, 'genau eine Dachmarken-Liste');

    abschnitt('Admin-Rechte je Reihe (AP-19 Unterpunkt 4, T-320)');
    $anna = konto_laden($f['admin']);
    va_rechte_setzen((int)$anna['id'], false, [], null, [$rid]);
    $anna = konto_laden($f['admin']);
    pruefe(va_erlaubte_ids($anna) === [$jc['id']] && va_erlaubte_reihen($anna) === [$rid], 'Admin mit Reihe sieht deren Veranstaltung');
    $jc2 = va_anlegen('journalclub2', 'Journal Club Februar', $jc['id'], null);
    pruefe(va_darf($anna, $jc2['id']) && !va_darf($anna, (int)$vk), 'neue Veranstaltung der Reihe ohne neue Freigabe, fremde nicht');
    va_rechte_setzen((int)$anna['id'], false, [(int)$vk], null, [$rid]);
    pruefe(va_darf(konto_laden($f['admin']), (int)$vk) && str_contains(va_rechte_text(konto_laden($f['admin'])), 'Reihe Journal Club Notfallmedizin'), 'Reihen und Veranstaltungen kombinierbar, Text in der Liste');
    ctx_setzen('team_rolle', 'admin');
    ctx_setzen('team_konto_zeile', konto_laden($f['admin']));
    $dm = (int)nl_liste_dachmarke()['id'];
    pruefe(rm_nl_erlaubt_ids() === [(int)$jl['id']] && rm_auswahl_gueltig('nl:' . $jl['id']) && !rm_auswahl_gueltig('nl:' . $dm) && !rm_auswahl_gueltig('nl:' . $wl['id']), 'Admin verschickt nur den Newsletter seiner Reihe, nicht Allgäu Akut (O-52)');
    ctx_setzen('team_rolle', 'betreiber');
    pruefe(rm_nl_erlaubt_ids() === null && rm_auswahl_gueltig('nl:' . $dm), 'BetreiberIn: alle Listen');
    ctx_weg('team_rolle', 'team_konto_zeile');

    abschnitt('Allgäu Akut mit Interessen (AP-19 Unterpunkt 5, T-321)');
    pruefe(nl_wahl_fehler([$dm], '') !== null && nl_wahl_fehler([$dm], 'klinik') === null && nl_wahl_fehler([(int)$wl['id']], '') === null, 'Dachmarke ohne Interesse abgelehnt, andere Listen ohne');
    pruefe(nl_interessen(['praeklinik', 'klinik', 'x']) === 'klinik,praeklinik' && nl_interessen_text('klinik,praeklinik') === 'Klinik, Präklinik', 'Interessen: Speicherform und Text');
    $nina = (int)nl_person_per_email('nina@example.org')['id'];
    pruefe(nl_interessen_von($nina) === 'klinik', 'Beispieldaten: Nina Klinik');
    nl_listen_setzen($nina, [(int)$wl['id'], $dm], 'verwalten', 'klinik,praeklinik');
    pruefe(nl_interessen_von($nina) === 'klinik,praeklinik' && nl_abos($nina)[$dm] === 'bestaetigt', 'Interessen ändern (Verwalten)');
    nl_listen_setzen($nina, [(int)$wl['id'], $dm], 'verwalten', 'klinik');
    $ex = export_erzeugen('newsletter', '', $dm);
    pruefe($ex !== null && str_contains($ex['text'], 'Interessen') && str_contains($ex['text'], '"nina@example.org";"Nina";"Klinik"') && str_contains($ex['text'], 'Präklinik'), 'Export mit Interessen');
    pruefe(str_contains(NL_EINWILLIGUNG, 'Interessen (Klinik, Präklinik)') && db_wert('SELECT text FROM newsletter_fassung WHERE fassung = ?', [nl_fassung()]) === NL_EINWILLIGUNG, 'Einwilligungstext mit Interessen, neue Fassung');

    abschnitt('Zielgruppe und Versand (AP-19 Unterpunkt 6, T-322)');
    $e = fn(string $z) => array_column(nl_empfaenger([$dm], $z), 'email');
    pruefe($e('klinik') === ['nina@example.org'] && $e('praeklinik') === ['otto@example.org'] && $e('beide') === ['nina@example.org', 'otto@example.org'], 'Empfänger je Zielgruppe (Klinik, Präklinik, alle)');
    nl_listen_setzen((int)nl_person_per_email('otto@example.org')['id'], [$dm], 'verwalten', 'klinik,praeklinik');
    pruefe($e('klinik') === ['nina@example.org', 'otto@example.org'], '„beides“ bekommt Klinik-Mails');
    pruefe(array_column(nl_empfaenger([$dm, (int)$wl['id']], 'praeklinik'), 'email') === ['nina@example.org', 'otto@example.org'], 'andere Listen unabhängig von der Zielgruppe (Nina über Wintersymposium)');
    va_mit((int)$vAbend, fn() => einstellung_setzen('zielgruppe', 'praeklinik'));
    $w = rm_pruefen(['art' => 'newsletter', 'listen' => [$dm], 'nl_ziel' => 'va:' . $vAbend, 'betreff' => 'x', 'html' => '<p>x</p>', 'postfach' => 'newsletter'])['werte'];
    pruefe($w['auswahl'] === 'nl:' . $dm . ';ziel:va:' . $vAbend && rm_nl_ziel_wirksam($w['auswahl']) === 'praeklinik' && rm_auswahl_text($w['auswahl'], '') === 'Newsletter: Allgäu Akut · Allgäu Akut nur Präklinik', 'Bezug auf eine Veranstaltung: deren Zielgruppe');
    $w2 = rm_pruefen(['art' => 'newsletter', 'listen' => [(int)$wl['id']], 'nl_ziel' => 'klinik', 'betreff' => 'x', 'html' => '<p>x</p>', 'postfach' => 'newsletter'])['werte'];
    pruefe($w2['auswahl'] === 'nl:' . $wl['id'], 'ohne Allgäu Akut keine Zielgruppe');
    pruefe(va_interesse('NotfallsanitäterIn') === 'praeklinik' && va_interesse('Pflege/MFA') === 'klinik' && va_interesse('ÄrztIn') === 'beides', 'Vorauswahl je Berufsgruppe nach O-50');
    $r = va_gruppe_pruefen([VA_BG_INTERESSE => ['ÄrztIn' => 'klinik', 'StudentIn' => 'praeklinik']], 'listen');
    pruefe(isset($r['werte'][VA_BG_INTERESSE]) && $r['fehler'] === [], 'Vorauswahl als Einstellung (Zuordnung) prüfbar');
    va_speichern($r['werte'], null);
    pruefe(va_interesse('ÄrztIn') === 'klinik' && va_interesse('NotfallsanitäterIn') === 'praeklinik', 'gespeicherte Vorauswahl gilt, sonst die Regel');
    pruefe(va_gruppe_pruefen([VA_BG_INTERESSE => ['ÄrztIn' => 'irgendwas']], 'listen')['fehler'] !== [], 'ungültige Zuordnung abgelehnt');

    abschnitt('Beispieldaten (AP-19 Unterpunkt 8, T-324)');
    $rita = beispieldaten_admin_reihe();
    $rk = konto_laden($rita);
    pruefe(va_erlaubte_reihen($rk) === [beispieldaten_reihe_abend()] && va_darf($rk, $vAbend) && !va_darf($rk, (int)$vk), 'Rita Reihe: Admin für die Reihe Fortbildungsabend');
}
