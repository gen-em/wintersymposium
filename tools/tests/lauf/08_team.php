<?php
declare(strict_types=1);

/* Testbereich: Team-Bereich – Personen anlegen, Plätze von Hand, Warteliste, Daten ändern, Personenliste, Aufgaben, Exporte (CSV, xlsx, PDF), Statistik, Rollen, Hosts (AP-5). */

function test_team(): void
{
    abschnitt('Team-Bereich (AP-5)');
    $f = test_frisch();
    $an = $f['bd']['anmeldungen'];
    $wv = beispieldaten_warteliste_voll();
    $nkId = beispieldaten_nur_konto();
    $voll = $wv['termin'];
    // Person anlegen durch das Team (geprüft wird team_anlegen selbst)
    $dt = ['vorname' => 'Theo', 'nachname' => 'Team', 'email' => 'theo@example.org', 'berufsgruppe' => 'StudentIn', 'institution' => 'Uni', 'efn' => '', 'essen' => 'egal'];
    $t1 = team_anlegen($dt, false, true);
    pruefe(isset($t1['id']) && anm_laden($t1['id'])['status'] === 'bestaetigt' && anm_symposium_status($t1['id']) === 'fest' && test_mail_an('theo@example.org') !== null && (int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === 1, 'Person anlegen: sofort bestätigt, Platz, Mail');
    pruefe(isset(team_anlegen(['email' => 'lea@example.org'] + $dt, false, true)['fehler']['email']), 'Adresse einer vorhandenen Anmeldung: abgelehnt');
    einstellung_setzen('veranstaltung_kapazitaet', (string)anm_belegt(0));
    $t2 = team_anlegen(['vorname' => 'Vroni', 'email' => 'vroni@example.org'] + $dt, false, false);
    $t3 = team_anlegen(['vorname' => 'Uwe', 'email' => 'uwe@example.org'] + $dt, true, false);
    pruefe($t2['warteliste'] === true && anm_symposium_status($t2['id']) === 'warte' && $t3['warteliste'] === false && anm_symposium_status($t3['id']) === 'fest' && anm_belegt(0) === (int)va('veranstaltung_kapazitaet') + 1, 'Symposium voll: Warteliste, mit Bestätigung überbucht');
    pruefe((int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === 1, '„ohne Mail“: keine Mail');
    einstellung_setzen('veranstaltung_kapazitaet', '240');
    // Platz von Hand: WS 02 20:30 ist in den Beispieldaten voll (Lea fest, Paulas Angebot)
    $r = team_platz_geben($t1['id'], $voll, false, false);
    pruefe(isset($r['frage']) && count($r['frage']) === 1 && str_contains($r['frage'][0], 'überbucht'), 'Platz geben in vollem Termin: fragt nach');
    $r = team_platz_geben($t1['id'], $voll, true, false);
    pruefe(isset($r['ok']) && anm_frei($voll) === 0 && anm_belegt($voll) === anm_kapazitaet($voll) + 1, 'mit Bestätigung überbucht');
    $r = team_platz_geben($t1['id'], test_termin('WS 02', '19:30'), false, false);
    pruefe(isset($r['frage']) && str_contains(implode(' ', $r['frage']), 'storniert'), 'gleicher Workshop: Tausch wird angekündigt');
    team_platz_geben($t1['id'], test_termin('WS 02', '19:30'), true, false);
    $pl = array_column(anm_plaetze($t1['id']), 'status', 'termin_id');
    pruefe(($pl[test_termin('WS 02', '19:30')] ?? '') === 'fest' && !isset($pl[$voll]), 'nach Bestätigung: Tausch ausgeführt');
    pruefe(isset(team_platz_geben($t2['id'], $voll, true, false)['fehler']), 'Workshop nur mit Platz beim Symposium');
    einstellung_setzen('workshop_limit', '1');
    $f3 = anm_workshops_setzen($t3['id'], ['fest' => [test_workshop('WS 01') => test_termin('WS 01', '19:30'), test_workshop('WS 02') => $voll], 'warte' => []], null, true, true, false);
    $pl3 = array_column(anm_plaetze($t3['id']), 'status', 'termin_id');
    pruefe($f3 === [] && ($pl3[test_termin('WS 01', '19:30')] ?? '') === 'fest' && ($pl3[$voll] ?? '') === 'fest', 'Team: ohne Workshop-Limit, mit Überbuchen');
    einstellung_setzen('workshop_limit', '0');
    // Warteliste (Wilma, Beispieldaten): Angebot von Hand, Entfernen lässt nachrücken
    pruefe(str_contains((string)team_angebot($wv['platz']), 'Kein freier Platz') && db_wert('SELECT status FROM platz WHERE id = ?', [$wv['platz']]) === 'warte', 'Angebot ohne freien Platz abgelehnt');
    foreach (db_zeilen("SELECT id FROM platz WHERE termin_id = ? AND status = 'fest' ORDER BY id", [$voll]) as $p) {
        team_platz_entfernen((int)$p['id'], false);
    }
    pruefe(db_wert('SELECT status FROM platz WHERE id = ?', [$wv['platz']]) === 'angebot', 'Platz entfernen: Nachrücken bietet ihn an');
    // Daten durch das Team: neue Adresse gilt sofort, doppelte abgelehnt
    $pa = anm_laden($an['paula']);
    $dp = ['vorname' => $pa['vorname'], 'nachname' => $pa['nachname'], 'berufsgruppe' => $pa['berufsgruppe'], 'beruf_sonstige' => '', 'institution' => $pa['institution'], 'efn' => '', 'essen' => $pa['essen']];
    pruefe(team_anm_daten($an['paula'], ['email' => 'paula.neu@example.org'] + $dp, false) === [] && anm_laden($an['paula'])['email'] === 'paula.neu@example.org', 'Team ändert Adresse sofort');
    pruefe(isset(team_anm_daten($an['paula'], ['email' => 'lea@example.org'] + $dp, false)['email']) && anm_laden($an['paula'])['email'] === 'paula.neu@example.org', 'Adresse einer anderen Anmeldung abgelehnt');
    // Personenliste und Filter (Nils: Konto ohne Anmeldung, Beispieldaten)
    $ps = team_personen();
    $nk = array_values(array_filter($ps, fn($p) => $p['email'] === 'nils.nurkonto@example.org'));
    pruefe(count($nk) === 1 && $nk[0]['art'] === 'konto' && team_filter_passt($nk[0], 'unbestaetigt') && !team_filter_passt($nk[0], 'angemeldet'), 'Personen: Konto ohne Anmeldung in der Liste');
    $wl = array_column(array_filter($ps, fn($p) => team_filter_passt($p, 'warteliste')), 'email');
    pruefe($wl === ['vroni@example.org'] && team_suche_passt($nk[0], 'NURKONTO') && !team_suche_passt($nk[0], 'zzz'), 'Filter Warteliste, Suche ohne Groß-/Kleinschreibung');
    // Aufgaben nach der Grenze
    $datum = va('veranstaltung_datum');
    einstellung_setzen('veranstaltung_datum', gmdate('Y-m-d', time() + 5 * 86400));
    einstellung_setzen('veranstaltung_kapazitaet', (string)(anm_belegt(0) + 1));
    $auf = team_aufgaben();
    pruefe(count(array_filter($auf, fn($a) => $a['art'] === 'Manuell' && $a['link'] === '/team/workshops?t=0' && str_contains($a['text'], 'vorbei'))) === 1, 'Zu erledigen: freier Platz nach der Grenze');
    einstellung_setzen('veranstaltung_datum', $datum);
    einstellung_setzen('veranstaltung_kapazitaet', '240');
    // CSV und Export
    $csv = csv_text(['A', 'B'], [['=SUMME(1)', 'Müller; "x"']]);
    pruefe(str_starts_with($csv, "\u{FEFF}\"A\";\"B\"\r\n") && str_contains($csv, "\"'=SUMME(1)\";\"Müller; \"\"x\"\"\""), 'CSV: BOM, Semikolon, Formeln entschärft');
    $ex = export_erzeugen('teilnehmende');
    pruefe($ex !== null && str_contains($ex['text'], 'Theo') && str_contains($ex['text'], 'Lea') && !str_contains($ex['text'], 'Tom') && str_ends_with($ex['datei'], '.csv'), 'Export Teilnehmende: Bestätigte, nicht Unbestätigte');
    $ex = export_erzeugen('cme');
    pruefe($ex !== null && str_contains($ex['text'], 'Lea') && !str_contains($ex['text'], 'Theo') && !str_contains($ex['text'], 'Paula'), 'CME-Liste nur ÄrztInnen');
    pruefe(export_erzeugen('unbekannt') === null && export_erzeugen('termin', '', 999999) === null, 'unbekannter Export: nichts');
    // xlsx und PDF (O-35, E-56)
    pruefe(xlsx_spalte(0) === 'A' && xlsx_spalte(25) === 'Z' && xlsx_spalte(26) === 'AA' && xlsx_spalte(701) === 'ZZ', 'xlsx: Spaltenbuchstaben');
    pruefe(xlsx_datum('20.01.2027') === ['wert' => 46407, 'stil' => 2] && abs(xlsx_datum('20.01.2027, 12:00')['wert'] - 46407.5) < 1e-9 && xlsx_datum('31.02.2027') === null && xlsx_datum('Müller') === null, 'xlsx: Datum als Excel-Zahl');
    $xl = export_datei(['datei' => 'a.csv', 'basis' => 'a', 'titel' => 'Liste: [x]', 'kopf' => ['Name', 'Datum', 'Zahl'], 'zeilen' => [['=HYPERLINK("x")', '20.01.2027, 14:30', 3], ["Ä\x01<b>", '', 2.5]], 'text' => '', 'bg' => '', 'pdf' => null], 'xlsx');
    $xlTmp = tempnam(sys_get_temp_dir(), 'wst');
    file_put_contents($xlTmp, $xl['inhalt']);
    $zip = new ZipArchive();
    $zipOk = $zip->open($xlTmp) === true;
    $blatt = $zipOk ? (string)$zip->getFromName('xl/worksheets/sheet1.xml') : '';
    $mappe = $zipOk ? (string)$zip->getFromName('xl/workbook.xml') : '';
    pruefe($zipOk && $xl['datei'] === 'a.xlsx' && str_contains($xl['typ'], 'spreadsheetml') && $zip->getFromName('xl/styles.xml') !== false && $zip->getFromName('[Content_Types].xml') !== false, 'xlsx: Zip mit Mappe, Blatt und Stilen');
    pruefe(str_contains($blatt, 'state="frozen"') && str_contains($blatt, '<autoFilter ref="A1:C3"/>') && str_contains($blatt, 's="1" t="inlineStr"'), 'xlsx: Kopfzeile fixiert, mit Filter und Stil');
    pruefe(!str_contains($blatt, '<f>') && str_contains($blatt, '=HYPERLINK(&quot;x&quot;)') && str_contains($blatt, 'Ä&lt;b&gt;') && !str_contains($blatt, "\x01") && str_contains($blatt, '<v>3</v>') && str_contains($blatt, 's="3"><v>46407.6041'), 'xlsx: Text nie Formel, Zeichen maskiert, Zahl und Datum als Zahl');
    pruefe(preg_match('/<sheet name="([^"]*)"/', $mappe, $bn) === 1 && str_starts_with($bn[1], 'Liste') && str_contains($bn[1], 'x') && strpbrk($bn[1], ':[]*?/\\') === false, 'xlsx: Blattname ohne verbotene Zeichen');
    $zip->close();
    @unlink($xlTmp);
    $pdfText = function (array $d) : string {
        $f = tempnam(sys_get_temp_dir(), 'wsp');
        file_put_contents($f, $d['inhalt']);
        $t = (string)shell_exec('pdftotext -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
        @unlink($f);
        return $t;
    };
    $ex = export_erzeugen('teilnehmende');
    $pd = export_datei($ex, 'pdf');
    pruefe($pd !== null && str_starts_with($pd['inhalt'], '%PDF-') && $pd['datei'] === preg_replace('/\.csv$/', '.pdf', $ex['datei']), 'PDF Teilnehmende: Datei');
    if (trim((string)shell_exec('command -v pdftotext')) !== '') {
        $pt = $pdfText($pd);
        pruefe(str_contains($pt, 'Theo') && str_contains($pt, 'Seite 1 von') && str_contains($pt, 'TEILNEHMENDE') && !str_contains($pt, '@example.org'), 'PDF Teilnehmende: Namen, Seitenzahl, ohne E-Mail');
        $exT = export_erzeugen('termine');
        $ptT = $pdfText(export_datei($exT, 'pdf'));
        $seiten = substr_count($ptT, "\f");
        pruefe($seiten >= count(array_unique(array_column($exT['zeilen'], 0))) && str_contains($ptT, 'Plätzen belegt') && !str_contains($ptT, '@example.org'), 'PDF je Termin: eine Seite je Termin, Belegung, ohne E-Mail');
        $leer = export_datei(['datei' => 'l.csv', 'basis' => 'l', 'titel' => 'Leer', 'kopf' => ['A'], 'zeilen' => [], 'text' => '', 'bg' => '', 'pdf' => ['gruppe' => null, 'ohne' => [], 'quer' => false, 'info' => []]], 'pdf');
        pruefe(str_contains($pdfText($leer), 'Niemand eingetragen'), 'PDF ohne Zeilen: Hinweis statt leerer Tabelle');
        $lang = export_datei(['datei' => 'g.csv', 'basis' => 'g', 'titel' => 'Lang', 'kopf' => ['Name', 'Text'], 'zeilen' => array_fill(0, 120, ['Zora Zett', str_repeat('Workshop Atemweg ', 6)]), 'text' => '', 'bg' => 'ÄrztIn', 'pdf' => ['gruppe' => null, 'ohne' => [], 'quer' => false, 'info' => []]], 'pdf');
        $ptL = $pdfText($lang);
        pruefe(substr_count($ptL, 'Zora Zett') === 120 && substr_count($ptL, "\f") >= 3 && str_contains($ptL, 'Berufsgruppe: ÄrztIn'), 'PDF: lange Liste über mehrere Seiten, nichts verloren');
    }
    pruefe(export_datei(export_erzeugen('newsletter'), 'pdf') === null && export_datei($ex, 'doc') === null, 'Newsletter ohne PDF, unbekanntes Format nichts');
    pruefe(str_contains(export_knoepfe(['art' => 'cme']), 'format=pdf') && str_contains(export_knoepfe(['art' => 'cme']), 'format=xlsx') && !str_contains(export_knoepfe(['art' => 'newsletter']), 'format=pdf'), 'Knöpfe CSV, Excel, PDF (Newsletter ohne PDF)');
    // bestätigt: Lea, Paula, Wilma, Theo, Uwe, Vroni (Warteliste); StudentIn davon Theo, Uwe, Vroni
    $sd = stat_daten();
    $ss = stat_daten('StudentIn');
    pruefe($sd['bestaetigt'] === 6 && $sd['angemeldet'] === 5 && $sd['warteliste'] === 1 && $sd['unbestaetigt'] === 2 && array_sum($sd['berufsgruppen']) === 6 && $sd['berufsgruppen']['StudentIn'] === 3
        && $ss['bestaetigt'] === 3 && $ss['angemeldet'] === 2, 'Statistik: Zahlen stimmen, auch mit Filter Berufsgruppe');
    pruefe(str_contains(stat_balken(['A' => 2, 'B' => 4], 'T'), 'width="50"') && str_contains(stat_balken(['A' => 2, 'B' => 4], 'T'), 'width="100"'), 'Balken als SVG-Attribut (keine Inline-Stile)');
    // Rolle vergeben (Teilnehmende → Team): Nils aus den Beispieldaten
    pruefe(team_rolle_vergeben($nkId, 'admin') && konto_laden($nkId)['rolle'] === 'admin' && str_contains((string)test_mail_an('nils.nurkonto@example.org')['text'], '/einrichten?t='), 'Rolle vergeben: Admin, Mail mit Einrichtungslink (ohne Passwort)');
    pruefe(!team_rolle_vergeben($nkId, 'betreiber') && konto_laden($nkId)['rolle'] === 'admin', 'nur Teilnehmende lassen sich ins Team holen');
    // Hosts und Absender
    host_anlegen('www.beispiel.test');
    pruefe(host_aendern('www.beispiel.test', 'aus', null) && !host_erlaubt('www.beispiel.test'), 'weiteren Host abschalten');
    einstellung_setzen('mail_absender', 'team@example.org');
    pruefe(mail_absender(mail_konfig()) === 'team@example.org', 'Absender aus der Einstellung vor config.php');
}
