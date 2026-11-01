<?php
declare(strict_types=1);

/* Testbereich: Mailings (Bereinigen, Empfänger, Tageslimits, Versand, Planen, Archiv, Bilder, Mail-Vorlagen) sowie Block-Zuordnung, Mail-Code und Protokoll (Rückmeldung 2026-10-30). */

function test_mailings(): void
{
    abschnitt('Mailings (AP-6)');
    $f = test_frisch();
    beispieldaten_angemeldete();
    // HTML bereinigen
    $bildTest = rm_bild_aus_daten((function (): string { $g = imagecreatetruecolor(1600, 400); ob_start(); imagejpeg($g); imagedestroy($g); return (string)ob_get_clean(); })(), null);
    pruefe($bildTest['breite'] === 1200 && $bildTest['hoehe'] === 300 && is_file(foto_ordner() . '/' . $bildTest['datei']), 'Bild auf 1200 px verkleinert und gespeichert');
    $sauber = rm_html_bereinigen('<h1 onclick="x()">Titel</h1><div>Zeile <b>fett</b> <i>k</i> <span style="color:red">rot</span></div><script>alert(1)</script>'
        . '<p><a href="javascript:alert(1)">böse</a> <a href="https://example.org/x?a=1&b=2">gut</a> <a class="knopf extra" href="{link}">Knopf</a></p>'
        . '<p><img src="https://fremd.example/x.png"><img src="/team/mailings/bild?id=' . $bildTest['id'] . '" alt="Plan" data-breite="50" onerror="x()"></p><iframe src="x"></iframe>');
    pruefe($sauber === '<h2>Titel</h2><p>Zeile <strong>fett</strong> <em>k</em> rot</p><p>böse <a href="https://example.org/x?a=1&amp;b=2">gut</a> <a href="{link}" class="knopf">Knopf</a></p><p><img src="/team/mailings/bild?id=' . $bildTest['id'] . '" alt="Plan" data-breite="50"></p>', 'HTML bereinigt: nur erlaubte Elemente, keine Skripte, fremden Bilder oder javascript:');
    pruefe(rm_html_bereinigen('<p>a &lt;b&gt; &amp; "c"</p>') === '<p>a &lt;b&gt; &amp; &quot;c&quot;</p>', 'Text bleibt maskiert');
    // Empfänger
    // Angemeldet: Lea, Paula und die acht aus beispieldaten_angemeldete(); nicht Tom (unbestätigt), Max (storniert), Ada (unbestätigt)
    $angem = 10;
    $alle = array_column(rm_empfaenger('angemeldet'), 'email');
    pruefe(count($alle) === $angem && in_array('lea@example.org', $alle, true) && !in_array('tom@example.org', $alle, true) && !in_array('max@example.org', $alle, true) && !in_array('ada.abend@example.org', $alle, true), 'Auswahl „Alle Angemeldeten“: nur bestätigte mit Platz');
    $studi = array_column(rm_empfaenger('angemeldet', 'StudentIn'), 'email');
    sort($studi);
    pruefe($studi === ['sam.studi@example.org', 'stella.studi@example.org'], 'zusätzlich nach Berufsgruppe');
    $wsT = test_termin('WS 01', '19:30');
    pruefe(array_column(rm_empfaenger('termin:' . $wsT), 'id') === [$f['bd']['anmeldungen']['lea']], 'Auswahl Workshop-Termin');
    pruefe(array_column(rm_empfaenger('essen_kein'), 'email') === ['paula@example.org'], 'Auswahl „Ohne Essen“');
    pruefe(rm_empfaenger('unbekannt') === [] && !array_key_exists('termin:999999', rm_auswahl_optionen()), 'unbekannte Auswahl: niemand');
    // Tageslimit je Postfach: config kurz auf ein Limit von 5 setzen (Rundmails über anmeldung@ bis 4)
    $cfgDatei = konfig_datei();
    $cfgAlt = (string)file_get_contents($cfgDatei);
    $cfg = require $cfgDatei;
    $cfg['mail'] = ['modus' => 'datei', 'tageslimit' => 5, 'newsletter' => ['tageslimit' => 3]];
    file_put_contents($cfgDatei, '<?php return ' . var_export($cfg, true) . ";\n");
    konfig_verwerfen();
    db_ausfuehren("DELETE FROM mail_ausgang");
    pruefe(mail_limit('system') === ['limit' => 5, 'rundmail' => 4] && mail_limit('newsletter') === ['limit' => 3, 'rundmail' => 3] && mail_postfach_da('newsletter'), 'Limits je Postfach, Reserve für System-Mails');
    pruefe(mail_absender(mail_konfig('newsletter')) === 'newsletter@' . (host_haupt() ?? 'localhost'), 'Absender newsletter@ der Haupt-Domain');
    $w = rm_pruefen(['betreff' => "Info\nfür {vorname}", 'html' => '<p>Hallo {vorname} {nachname},</p><p>{workshops}</p><p><a class="knopf" href="{link}">Ansehen</a></p><p><img src="/team/mailings/bild?id=' . $bildTest['id'] . '" alt="Plan"></p>', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system']);
    pruefe($w['fehler'] === [] && $w['werte']['betreff'] === 'Info für {vorname}', 'Eingaben geprüft, Betreff einzeilig');
    $rmId = rm_speichern(null, $w['werte'], null);
    $n = rm_einreihen($rmId, null);
    pruefe($n === $angem && (int)db_wert('SELECT COUNT(*) FROM rundmail_empfaenger WHERE rundmail_id = ?', [$rmId]) === $angem && rm_laden($rmId)['status'] === 'versand', 'Senden: Empfängerliste im Archiv, alle in der Warteschlange');
    pruefe(db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE rundmail_id = ? AND (betreff <> '' OR text <> '')", [$rmId]) == 0, 'Warteschlange enthält nur Verweise, keinen Inhalt');
    pruefe(rm_einreihen($rmId, null) === null, 'zweites Senden nicht möglich');
    pruefe(rm_laden($rmId)['loeschen_ab'] === (new DateTimeImmutable(va('veranstaltung_datum')))->modify('+14 months')->format('Y-m-d'), 'Archiv bis 14 Monate nach dem Symposium (E-24)');
    // eine Person storniert inzwischen: ihre Mail entfällt
    $weg = (int)db_wert('SELECT anmeldung_id FROM mail_ausgang WHERE rundmail_id = ? ORDER BY id DESC LIMIT 1', [$rmId]);
    anm_stornieren($weg);
    db_ausfuehren("DELETE FROM mail_ausgang WHERE rundmail_id IS NULL");
    $e1 = mail_senden_faellige(50, 20.0);
    pruefe($e1['gesendet'] === 4 && rm_fortschritt($rmId)['gesendet'] === 4, 'Rundmail über anmeldung@: 4 von 5 (Reserve bleibt frei)');
    mail_einreihen('system@example.org', 'System', 'Text');
    $e2 = mail_senden_faellige(50, 20.0);
    pruefe($e2['gesendet'] === 1 && db_wert("SELECT status FROM mail_ausgang WHERE rundmail_id IS NULL ORDER BY id DESC LIMIT 1") === 'gesendet', 'Bestätigungen gehen trotzdem sofort raus (Abnahme AP-6)');
    mail_einreihen('system2@example.org', 'System', 'Text');
    pruefe(mail_senden_faellige(50, 20.0)['gesendet'] === 1, 'auch über dem Tageslimit');
    pruefe(rm_fortschritt($rmId)['wartet'] === $angem - 4, 'Rest der Rundmail wartet auf den nächsten Tag');
    $morgen = time() + 86400;
    $e3 = mail_senden_faellige(50, 20.0, $morgen);
    pruefe($e3['gesendet'] === 4 && rm_fortschritt($rmId)['wartet'] === 2, 'am nächsten Tag geht es weiter');
    // Inhalt der gesendeten Mail
    $dateien = glob(laufzeit_pfad('mails') . '/*.eml') ?: [];
    sort($dateien);
    $roh = '';
    foreach ($dateien as $d) {
        // Seit O-40 haben alle HTML-Mails das Logo eingebettet: das Mailing am eigenen Bild erkennen
        if (str_contains((string)file_get_contents($d), 'Content-ID: <bild' . $bildTest['id'] . '@')) {
            $roh = (string)file_get_contents($d);
            break;
        }
    }
    $dek = quoted_printable_decode($roh);
    pruefe(str_contains($roh, 'Content-Type: multipart/related') && str_contains($roh, 'multipart/alternative') && preg_match('/Content-ID: <bild' . $bildTest['id'] . '@[^>]+>/', $roh) === 1 && str_contains($dek, 'src="cid:bild' . $bildTest['id'] . '@'), 'HTML-Mail mit Textfassung und eingebettetem Bild (Content-ID)');
    // Mails im Erscheinungsbild (O-40, Variante A): HTML aus dem Text, Knopf, Übersicht, Logo eingebettet
    $ueb = "Deine Anmeldung (Nr. 0009):\n\n- WS 02 Kleine PatientInnen · 19:30–20:00: Platz bestätigt\n- WS 01 Atemweg · 20:30–21:15: Warteliste · Platz 3 von 5\n- Essen: egal";
    [$bt, $tx] = mail_vorlage('bestaetigt', ['vorname' => 'Lea', 'uebersicht' => $ueb]);
    $hb = mail_html_aus_text('bestaetigt', $bt, $tx);
    pruefe(str_contains($hb, 'Anmeldung bestätigt') && str_contains($hb, '>Bestätigt</span>') && str_contains($hb, 'Warteliste · 3</span>') && str_contains($hb, '>WORKSHOP · 19:30–20:00</span>WS 02') && str_contains($hb, 'border-left:4px solid #FF5A5F') && str_contains($hb, '>ESSEN</span>egal')
        && str_contains($hb, '>Anmeldung ansehen oder ändern</a>') && !str_contains($hb, 'Ändern oder absagen') && str_contains($tx, 'Ändern oder absagen') && str_contains($hb, MAIL_LOGO_PLATZHALTER) && str_contains($hb, 'Impressum</a>') && !str_contains($tx, '<'), 'Mail-HTML: Übersicht als Tabelle mit Schildern, Knopf, Logo, Fuß; Text bleibt Text');
    [$ab, $at] = mail_vorlage('angebot', ['vorname' => 'Lea', 'was' => 'WS 01 · 20:30–21:15', 'frist' => 'Do 18:00', 'link' => 'https://example.org/link?t=abc']);
    $ha = mail_html_aus_text('angebot', $ab, $at);
    pruefe(str_starts_with($ab, 'Platzangebot:') && str_contains($ha, 'href="https://example.org/link?t=abc"') && str_contains($ha, '>Platz annehmen oder ablehnen</a>') && str_contains($ha, 'Knopf geht nicht? https://example.org/link?t=abc'), 'Mail-HTML: Link allein im Absatz wird Knopf mit Ersatzadresse');
    $rohM = mail_nachricht(mail_konfig(), 'lea@example.org', $ab, $at, ['html' => $ha]);
    pruefe(preg_match('/Content-ID: <logo@[^>]+>/', $rohM) === 1 && str_contains(quoted_printable_decode($rohM), 'src="cid:logo@') && !str_contains($rohM, MAIL_LOGO_PLATZHALTER) && is_file(mail_logo()[1]), 'Mail: Logo als eingebettetes PNG (Content-ID)');
    // Namen (E-67): allgemeine Mails im Namen der Reihe, Mails zu einer Anmeldung im Namen der Veranstaltung
    [$pb, $pt] = mail_vorlage('team_passwort', ['vorname' => 'Bea', 'link' => 'https://example.org/x']);
    pruefe(reihe_name() === 'Allgäu Akut' && $pb === 'Neues Passwort für den Team-Bereich von Allgäu Akut' && str_contains($pt, 'Dein Team von Allgäu Akut') && !str_contains($pb . $pt, 'Wintersymposium')
        && str_contains(mail_html_aus_text('team_passwort', $pb, $pt), 'Team-Bereich · Allgäu Akut') && str_contains($tx, 'Dein Team vom ' . va_kurz()), 'Mail-Namen: Team-Mail nennt die Reihe, Anmeldungs-Mail die Veranstaltung');
    einstellung_setzen('reihe_name', 'Testreihe');
    pruefe(mail_vorlage('konto_vorhanden', ['vorname' => 'X'])[0] === 'Du hast schon ein Konto bei Testreihe' && mail_absender_name(mail_konfig()) === 'Testreihe', 'Name der Reihe ist eine Einstellung');
    einstellung_setzen('reihe_name', null);
    [, $ct] = mail_vorlage('zweitfaktor_code', ['vorname' => 'Bea', 'code' => '123456']);
    pruefe(str_contains(mail_html_aus_text('zweitfaktor_code', 'Code', $ct), 'letter-spacing:6px;color:#001E3A;">123456</p>'), 'Mail-HTML: Code groß und gut lesbar');
    pruefe(preg_match('#Ansehen: http\S+/link\?t=[A-Za-z0-9_-]{43}#', $dek) === 1 && str_contains($dek, 'Hallo ') && !str_contains($dek, '{vorname}') && !str_contains($dek, '{link}') && str_contains($dek, '/impressum'), 'Platzhalter ersetzt, persönlicher Einmal-Link, Impressum');
    $betr = preg_match('/^Subject: (.*(?:\r?\n[ \t].*)*)/m', $roh, $sm) === 1 ? iconv_mime_decode(trim($sm[1]), 0, 'UTF-8') : '';
    $vornamen = array_column(db_zeilen('SELECT vorname FROM anmeldung WHERE veranstaltung_id = ?', [va_id()]), 'vorname');
    pruefe(!str_contains($roh, 'Auto-Submitted') && str_starts_with($betr, 'Info für ') && in_array(substr($betr, strlen('Info für ')), $vornamen, true), "Rundmail ohne Auto-Submitted, Betreff mit Vorname ($betr)");
    // dritter Tag: eine Mail geht noch raus, die der stornierten Person entfällt
    $e4 = mail_senden_faellige(50, 20.0, $morgen + 86400);
    rm_job(time());
    pruefe($e4['gesendet'] === 1 && db_wert('SELECT COUNT(*) FROM mail_ausgang WHERE anmeldung_id = ?', [$weg]) == 0 && rm_fortschritt($rmId) === ['gesendet' => $angem - 1, 'wartet' => 0, 'fehler' => 0] && rm_laden($rmId)['status'] === 'gesendet', 'Storniert: Mail entfällt, Rundmail fertig gesendet');
    // Newsletter-Postfach eigenes Limit
    $w2 = rm_pruefen(['betreff' => 'NL', 'html' => '<p>Hallo</p>', 'auswahl' => 'angemeldet', 'postfach' => 'newsletter']);
    $nl = rm_speichern(null, $w2['werte'], null);
    rm_einreihen($nl, null);
    pruefe(mail_senden_faellige(50, 20.0, $morgen + 86400)['gesendet'] === 3 && db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE postfach = 'newsletter' AND status = 'gesendet'") == 3, 'newsletter@ mit eigenem Tageslimit');
    pruefe(str_contains((string)rm_abbrechen($nl, 0), 'abgebrochen') && rm_fortschritt($nl)['wartet'] === 0 && rm_laden($nl)['status'] === 'abgebrochen', 'Restlichen Versand abbrechen');
    // Planen und Job
    $pl = rm_speichern(null, $w['werte'], null);
    pruefe(rm_planen($pl, gmdate('Y-m-d H:i:s', time() + 3600), 0) && rm_laden($pl)['status'] === 'geplant', 'Mailing geplant');
    rm_job(time());
    pruefe(rm_laden($pl)['status'] === 'geplant', 'vor dem Zeitpunkt passiert nichts');
    pruefe(str_contains((string)rm_abbrechen($pl, 0), 'Entwurf') && rm_laden($pl)['status'] === 'entwurf', 'Planung aufheben → wieder Entwurf');
    rm_planen($pl, gmdate('Y-m-d H:i:s', time() + 3600), 0);
    $j = rm_job(time() + 3700);
    pruefe($j['gestartet'] === 1 && rm_laden($pl)['status'] === 'versand', 'Job startet das geplante Mailing');
    db_ausfuehren("UPDATE mail_ausgang SET status = 'gesendet', gesendet_am = ? WHERE rundmail_id = ?", [db_jetzt(), $pl]);
    pruefe(rm_job(time() + 3800)['fertig'] === 1 && rm_laden($pl)['status'] === 'gesendet', 'Job schließt fertige Mailings ab');
    pruefe(rm_job(strtotime(rm_laden($pl)['loeschen_ab'] . ' 12:00:00 UTC'))['archiv'] > 0 && (int)db_wert('SELECT COUNT(*) FROM rundmail_empfaenger WHERE rundmail_id = ?', [$pl]) === 0, 'Empfängerliste nach der Löschfrist entfernt');
    $leer = rm_speichern(null, ['betreff' => 'Leer', 'html' => '<p>x</p>', 'auswahl' => 'termin:' . $wsT, 'berufsgruppe' => 'Sonstige', 'postfach' => 'system'], null);
    pruefe(rm_sendefehler(rm_laden($leer)) !== [] && rm_einreihen($leer, null) === null, 'ohne Empfänger nicht sendbar');
    $kopie = rm_kopieren($rmId, 0);
    pruefe(rm_laden($kopie)['status'] === 'entwurf' && rm_laden($kopie)['html'] === rm_laden($rmId)['html'], 'Als neue Mail kopieren');
    pruefe(rm_loeschen($kopie, 0) && !rm_loeschen($rmId, 0), 'nur Entwürfe lassen sich löschen');
    // Bilder aufräumen: unbenutzt und älter als ein Tag
    $alt = rm_bild_aus_daten((function (): string { $g = imagecreatetruecolor(10, 10); ob_start(); imagepng($g); imagedestroy($g); return (string)ob_get_clean(); })(), null);
    pruefe($alt['typ'] === 'png' && rm_bilder_aufraeumen(time() + 2 * 86400) === 1 && rm_bild($alt['id']) === null && !is_file(foto_ordner() . '/' . $alt['datei']) && rm_bild($bildTest['id']) !== null, 'unbenutzte Bilder entfernt, benutzte bleiben');
    // Text-Bilder im Programm und bei Dozierenden bleiben beim Aufräumen stehen (Admin 2026-10-31)
    $tb = foto_ordner() . '/text-aaaaaaaaaaaaaaa1.jpg';
    $tbWeg = foto_ordner() . '/text-aaaaaaaaaaaaaaa2.jpg';
    file_put_contents($tb, 'x'); file_put_contents($tbWeg, 'x');
    touch($tb, time() - 3 * 86400); touch($tbWeg, time() - 3 * 86400);
    $dz = dozent_speichern(null, dozent_pruefen(['name' => 'Bild Aufräumen', 'kurzbio' => '<p><img src="/bild/text-aaaaaaaaaaaaaaa1.jpg" alt=""></p>'])['werte'], null);
    text_bilder_aufraeumen(time());
    pruefe(is_file($tb) && !is_file($tbWeg), 'Aufräumen: Bild aus einer Kurzbio bleibt, unbenutztes Text-Bild geht');
    dozent_loeschen($dz, null); @unlink($tb);
    // Team: Passwort vergessen per Mail (AP-6)
    tk_passwort_vergessen(TEST_ADMIN);
    pruefe(str_contains((string)test_mail_an(TEST_ADMIN)['text'], '/einrichten?t='), 'Team: Passwort vergessen schickt einen Link');
    file_put_contents($cfgDatei, $cfgAlt);
    konfig_verwerfen();
    // Tageslimit als Zahl vergleichen (Fehler gefunden mit AP-10): 4 gesendet, Grenze 360 → Rundmail geht raus
    db_ausfuehren('DELETE FROM mail_ausgang');
    for ($i = 0; $i < 4; $i++) {
        db_ausfuehren("INSERT INTO mail_ausgang (an, betreff, text, prio, status, versuche, naechster_versuch, erstellt_am, gesendet_am) VALUES ('', '', '', 1, 'gesendet', 0, ?, ?, ?)", [db_jetzt(), db_jetzt(), db_jetzt()]);
    }
    db_ausfuehren("INSERT INTO mail_ausgang (an, betreff, text, prio, status, versuche, naechster_versuch, erstellt_am) VALUES ('limit@example.org', 'Limit', 'Text', ?, 'offen', 0, ?, ?)", [MAIL_PRIO_RUNDMAIL, db_jetzt(), db_jetzt()]);
    pruefe(mail_limit('system')['rundmail'] >= 10 && mail_senden_faellige(5, 10.0)['gesendet'] === 1, 'Tageslimit als Zahl verglichen (4 gesendet, Grenze dreistellig)');
    db_ausfuehren('DELETE FROM mail_ausgang');

    abschnitt('Rückmeldung 2026-10-30: Block-Zuordnung, Mail-Code, Protokoll');
    // Block-Zuordnung
    // Kürzel wie VT 01 gibt es auch im Vorjahr und im Abend: nur in der aktuellen Veranstaltung suchen
    $punkt = fn(string $wo, array $p = []) => (int)db_wert("SELECT id FROM programmpunkt WHERE veranstaltung_id = ? AND $wo ORDER BY id LIMIT 1", array_merge([va_id()], $p));
    $blockId = $punkt("typ = 'block'");
    $wsId = $punkt("kuerzel = 'WS 01'");
    $r = programm_punkt_pruefen(['typ' => 'workshop', 'titel' => 'Ohne Termin', 'kuerzel' => 'WS 99', 'block_id' => (string)$blockId]);
    $neuWs = programm_punkt_speichern(null, $r, null);
    $kind = function (int $punkt) use ($blockId): int {
        foreach (programm_gliederung(programm_arbeitsstand()) as $g) {
            if ($g['art'] === 'block' && $g['punkt']['id'] === $blockId) {
                return count(array_filter($g['kinder'], fn($k) => $k['punkt']['id'] === $punkt));
            }
        }
        return 0;
    };
    $termineWs = (int)db_wert('SELECT COUNT(*) FROM workshop_termin WHERE punkt_id = ?', [$wsId]);
    pruefe($kind($neuWs) === 1, 'Workshop ohne Termin über das Formular einem Block zugeordnet');
    $pauseId = $punkt("typ = 'pause'");
    pruefe(!programm_zuordnen($blockId, $blockId, null) && !programm_zuordnen($neuWs, $wsId, null) && !programm_zuordnen($pauseId, $blockId, null), 'nur Inhalte (keine Pause, kein Block), nur zu einem Block');
    // Vorträge und Fallbesprechungen im Block (Rückmeldung 2026-10-30)
    $fb2 = $punkt("kuerzel = 'FB 02'");
    $vt1 = $punkt("kuerzel = 'VT 01'");
    $haupt = fn() => array_map(fn($g) => $g['punkt']['id'], array_filter(programm_gliederung(programm_arbeitsstand()), fn($g) => $g['art'] !== 'termin'));
    pruefe($kind($fb2) === 1 && !in_array($fb2, $haupt(), true), 'Fallbesprechung (Beispieldaten) steht im Block, nicht im Hauptprogramm');
    pruefe($kind($vt1) === 0, 'Vortrag ohne Zuordnung bleibt im Hauptprogramm, auch wenn ein Block läuft');
    $fb1 = $punkt("kuerzel = 'FB 01'");
    $faelle = db_wert('SELECT beginn FROM programmpunkt WHERE id = ?', [$fb1]);
    pruefe(programm_verschieben('punkt', $fb2, '19:40', null, true, $nachNr) === '19:40–20:10' && db_wert('SELECT beginn FROM programmpunkt WHERE id = ?', [$fb1]) === $faelle && $nachNr === 0, 'Punkt im Block verschieben schiebt die Hauptspur nicht');
    programm_verschieben('punkt', $blockId, '19:40', null);
    pruefe(hm((string)db_wert('SELECT beginn FROM programmpunkt WHERE id = ?', [$fb2])) === '19:50', 'Block verschieben nimmt die zugeordnete Fallbesprechung mit');
    programm_verwerfen(null);
    pruefe($kind($fb2) === 1 && hm((string)db_wert('SELECT beginn FROM programmpunkt WHERE id = ?', [$fb2])) === '20:00', 'Verwerfen: Fallbesprechung wieder im Block um 20:00');
    // Dozierende je Termin
    $ws1 = $wsId;
    $ws1p = array_values(array_filter(programm_arbeitsstand(), fn($p) => $p['id'] === $ws1))[0];
    pruefe(!isset($ws1p['termine'][0]['dozenten']) && array_column($ws1p['termine'][1]['dozenten'] ?? [], 'name') === ['Dr. Erika Muster'], 'Dozierende je Termin: erster wie Workshop, zweiter abweichend');
    $pub = programm_veroeffentlicht()['punkte'];
    $ws1v = array_values(array_filter($pub, fn($p) => $p['id'] === $ws1))[0];
    pruefe(isset($ws1v['termine'][1]['dozenten']), 'abweichende Dozierende sind veröffentlicht');
    $tids = array_column($ws1p['termine'], 'id');
    $r = programm_punkt_pruefen(['typ' => 'workshop', 'titel' => $ws1p['titel'], 'kuerzel' => 'WS 01', 'dozenten' => array_column($ws1p['dozenten'], 'id'),
        't_id' => [$tids[0], $tids[1], 0], 't_key' => ['z0', 'z1', 'n1'], 't_beginn' => ['19:30', '20:30', '21:15'], 't_ende' => ['20:15', '21:15', '21:30'], 't_plaetze' => ['12', '12', '4'],
        't_doz' => ['z0' => [(string)$ws1p['dozenten'][0]['id'], '999999'], 'n1' => []]], $ws1);
    pruefe($r['fehler'] === [] && $r['termine'][0]['dozenten'] === [(int)$ws1p['dozenten'][0]['id']] && $r['termine'][1]['dozenten'] === [] && $r['termine'][2]['dozenten'] === [], 'Dozierende je Termin über den Zeilen-Schlüssel, unbekannte verworfen');
    programm_punkt_speichern($ws1, $r, null);
    pruefe((int)db_wert('SELECT COUNT(*) FROM termin_dozent td JOIN workshop_termin t ON t.id = td.termin_id WHERE t.punkt_id = ?', [$ws1]) === 1, 'gespeichert: nur der erste Termin weicht ab, der zweite wieder wie Workshop');
    programm_verwerfen(null);
    pruefe((int)db_wert('SELECT COUNT(*) FROM termin_dozent td JOIN workshop_termin t ON t.id = td.termin_id WHERE t.punkt_id = ? AND t.id = ?', [$ws1, $tids[1]]) === 1, 'Verwerfen stellt die abweichenden Dozierenden wieder her');
    db_ausfuehren("UPDATE workshop_termin SET beginn = '23:00:00', ende = '23:30:00' WHERE punkt_id = ? ORDER BY id LIMIT 1", [$wsId]);
    programm_zuordnen($wsId, $blockId, null);
    pruefe($kind($wsId) === $termineWs && array_filter(programm_hinweise(programm_arbeitsstand()), fn($x) => str_contains($x, 'liegt aber 23:00')) !== [], 'zugeordnet trotz Uhrzeit außerhalb, mit Hinweis');
    programm_zuordnen($wsId, null, null);
    pruefe($kind($wsId) === $termineWs - 1, 'Zuordnung lösen: wieder nach Uhrzeit (Termin um 23:00 nicht mehr im Block)');
    programm_verwerfen(null);
    pruefe(db_wert('SELECT COUNT(*) FROM programmpunkt WHERE id = ?', [$neuWs]) == 0 && $kind($wsId) === $termineWs, 'Verwerfen stellt den veröffentlichten Stand her');
    // Zweiter Faktor per E-Mail-Code
    $zk = $f['admin'];
    pruefe(!zweitfaktor_da(konto_laden($zk)), 'ohne zweiten Faktor nicht fertig');
    $codes = mailcode_einschalten($zk, true);
    pruefe(is_array($codes) && count($codes) === TOTP_CODES && zweitfaktor_da(konto_laden($zk)) && totp_zustand($zk)['mail'] && !totp_zustand($zk)['an'], 'Code per E-Mail eingeschaltet, Wiederherstellungscodes erzeugt');
    mailcode_senden(konto_laden($zk));
    $mcode = preg_match('/\b(\d{6})\b/', (string)test_mail_an(TEST_ADMIN)['text'], $m) === 1 ? $m[1] : '';
    pruefe($mcode !== '' && db_wert('SELECT mailcode_hash FROM konto WHERE id = ?', [$zk]) !== $mcode, 'Code per Mail, gespeichert nur als Hash');
    pruefe(!totp_anmeldung_pruefen($zk, $mcode === '000000' ? '111111' : '000000')['ok'], 'falscher Code abgelehnt');
    pruefe(totp_anmeldung_pruefen($zk, $mcode)['art'] === 'mail' && !totp_anmeldung_pruefen($zk, $mcode)['ok'], 'Code gilt einmal');
    db_ausfuehren('DELETE FROM rate_limit'); // höchstens ein Code je Minute (Review S-9)
    mailcode_senden(konto_laden($zk));
    db_ausfuehren('UPDATE konto SET mailcode_bis = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 1), $zk]);
    $alt2 = preg_match('/\b(\d{6})\b/', (string)test_mail_an(TEST_ADMIN)['text'], $m) === 1 ? $m[1] : '';
    pruefe(!mailcode_pruefen($zk, $alt2), 'abgelaufener Code abgelehnt');
    pruefe(totp_anmeldung_pruefen($zk, $codes[0])['art'] === 'code', 'Wiederherstellungscode geht auch ohne App');
    totp_zuruecksetzen($zk, 'Test');
    pruefe(!zweitfaktor_da(konto_laden($zk)), 'Zurücksetzen schaltet auch den Mail-Code ab');
    // Protokoll: Sichtbarkeit und Ausblenden
    audit_schreiben('sicherung', 'Test Sicherung', null);
    $sid = (int)db()->lastInsertId();
    audit_schreiben('programm', 'Test Programm', null);
    $pidA = (int)db()->lastInsertId();
    audit_schreiben('konto', 'Eigene Aktion', $zk);
    $eigen = (int)db()->lastInsertId();
    $sieht = function (array $k, int $id): bool { [$w, $p] = audit_sichtbar($k); return (int)db_wert("SELECT COUNT(*) FROM audit a WHERE $w AND a.id = ?", array_merge($p, [$id])) === 1; };
    $admin = konto_laden($zk);
    $betr = ['id' => 0, 'rolle' => 'betreiber'];
    pruefe(!$sieht($admin, $sid) && $sieht($admin, $pidA) && !$sieht($admin, $eigen) && $sieht($betr, $sid) && $sieht($betr, $eigen), 'Admin sieht Programm seiner Veranstaltung, nicht Sicherung und Konten (auch eigene, AP-12); Betreiber alles');
    pruefe(audit_ausblenden($pidA, true, 1) && !$sieht($admin, $pidA) && $sieht($betr, $pidA), 'ausgeblendet: nur noch Betreiber');
    pruefe(db_wert("SELECT art FROM audit ORDER BY id DESC LIMIT 1") === 'protokoll' && audit_ausblenden($pidA, false, 1) && $sieht($admin, $pidA), 'Ausblenden steht im Protokoll, Einblenden geht');
    pruefe(!audit_ausblenden((int)db_wert("SELECT id FROM audit WHERE art = 'protokoll' ORDER BY id DESC LIMIT 1"), true, 1), 'Protokoll-Einträge zum Ausblenden lassen sich nicht ausblenden');
}
