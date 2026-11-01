<?php
declare(strict_types=1);

/* Testbereich: Teilnahme und Zertifikate (mechanisch aus lauf.php ausgelagert, noch nicht auf test_frisch umgestellt). */

function test_teilnahme(): void
{
    abschnitt('Teilnahme und Zertifikate (AP-10, E-57)');
    $tl = tn_liste();
    $mitWs = array_values(array_filter($tl, fn($e) => $e['workshops'] !== [] && $e['anwesend']));
    pruefe($tl !== [] && $mitWs !== [] && count(array_filter($tl, fn($e) => $e['anm']['status'] !== 'bestaetigt' || $e['platz']['status'] !== 'fest')) === 0, 'Teilnahme: alle bestätigten mit festem Platz, Standard anwesend');
    $e = $mitWs[0];
    $aid = (int)$e['anm']['id'];
    $wsPlatz = (int)$e['workshops'][0]['id'];
    $wsTitel = anm_termin((int)$e['workshops'][0]['termin_id'])['workshop']['titel'];
    pruefe(count(zertifikat_daten($e['anm'])['workshops']) === count($e['workshops']), 'Zertifikat: alle festen Workshops als besucht');
    pruefe(tn_setzen($wsPlatz, false, null) && count(zertifikat_daten(anm_laden($aid))['workshops']) === count($e['workshops']) - 1 && tn_anwesend($aid), 'Workshop „nicht erschienen“: fehlt auf dem Zertifikat, Symposium bleibt');
    pruefe(db_wert("SELECT COUNT(*) FROM audit WHERE art = 'teilnahme' AND text LIKE '%nicht erschienen%'") >= 1, 'Anwesenheit im Protokoll');
    tn_setzen($wsPlatz, true, null);
    pruefe(!tn_setzen(999999, false, null), 'unbekannter Platz: nichts');
    // Ohne Anwesenheit kein Zertifikat (T-191)
    tn_setzen((int)$e['platz']['id'], false, null);
    $datumAlt = va('veranstaltung_datum');
    einstellung_setzen('veranstaltung_datum', gmdate('Y-m-d', time() + 30 * 86400));
    pruefe(!zertifikat_frei_ab_heute(), 'Freischalten erst ab dem Veranstaltungstag');
    einstellung_setzen('veranstaltung_datum', gmdate('Y-m-d', time() - 86400));
    pruefe(zertifikat_frei_ab_heute() && !tn_anwesend($aid) && zertifikat_freischalten($aid, null) === 0, 'Nicht erschienen: kein Zertifikat');
    db_ausfuehren('DELETE FROM mail_ausgang');
    $anwesend = tn_zahlen()['offen'];
    $n = zertifikat_freischalten(null, null);
    pruefe($n === $anwesend && $n > 0 && (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE betreff LIKE 'Deine Teilnahmebescheinigung%'") === $n, 'Alle Anwesenden freigeschaltet, je eine Mail');
    pruefe(!zertifikat_verfuegbar(anm_laden($aid)) && anm_laden($aid)['zertifikat_am'] === null, 'Nicht erschienen: nicht freigeschaltet');
    $mailText = (string)db_wert("SELECT text FROM mail_ausgang ORDER BY id LIMIT 1");
    pruefe(str_contains($mailText, '/link?t=') && str_contains($mailText, tn_name()), 'Mail mit Link und Name der Veranstaltung');
    tn_setzen((int)$e['platz']['id'], true, null);
    pruefe(zertifikat_freischalten($aid, null, false) === 1 && zertifikat_verfuegbar(anm_laden($aid)) && zertifikat_freischalten($aid, null) === 0, 'Einzeln freischalten (ohne Mail), nicht doppelt');
    tn_setzen((int)$e['platz']['id'], false, null);
    pruefe(!zertifikat_verfuegbar(anm_laden($aid)), 'Nach der Freischaltung als nicht erschienen markiert: kein Download');
    tn_setzen((int)$e['platz']['id'], true, null);
    pruefe(zertifikat_sperren($aid, null) && !zertifikat_verfuegbar(anm_laden($aid)) && zertifikat_freischalten($aid, null, false) === 1, 'Freischaltung zurücknehmen und wieder freischalten');
    // CME-Liste: nur anwesende ÄrztInnen
    $arzt = array_values(array_filter(tn_liste(), fn($x) => $x['aerztin']));
    if ($arzt !== []) {
        tn_setzen((int)$arzt[0]['platz']['id'], false, null);
        pruefe(!str_contains(export_erzeugen('cme')['text'], '"' . $arzt[0]['anm']['nachname'] . '"'), 'CME-Liste ohne „nicht erschienen“');
        tn_setzen((int)$arzt[0]['platz']['id'], true, null);
        pruefe(str_contains(export_erzeugen('cme')['text'], '"' . $arzt[0]['anm']['nachname'] . '"'), 'CME-Liste mit Anwesenden');
    }
    // Barcode Code 128 B
    pruefe(count(array_filter(array_slice(CODE128_MUSTER, 0, 106), fn($m) => array_sum(str_split($m)) !== 11)) === 0 && array_sum(str_split(CODE128_MUSTER[106])) === 13 && count(array_unique(CODE128_MUSTER)) === 107, 'Code 128: 106 Zeichen mit 11 Modulen, Stopp mit 13');
    $bc = code128_module('*802760012345673*');
    pruefe($bc !== null && array_sum($bc) === 11 * 19 + 13 && code128_module("ä") === null, 'Code 128: *EFN* hat 222 Module; Umlaut abgelehnt');
    pruefe(array_slice(code128_module('A') ?? [], 6, 6) === [1, 1, 1, 3, 2, 3] && array_slice(code128_module('A') ?? [], 12, 6) === array_map('intval', str_split(CODE128_MUSTER[(104 + 33) % 103])), 'Code 128: Zeichen und Prüfzeichen');
    // PDFs
    einstellung_setzen('cme_punkte', '4');
    einstellung_setzen('cme_kategorie', 'A');
    einstellung_setzen('cme_kammer', 'Beispielkammer');
    einstellung_setzen('cme_veranstaltungsnummer', '2761234567890123');
    einstellung_setzen('zertifikat_leitung', 'Dr. Erika Erfunden');
    einstellung_setzen('zertifikat_leitung_rolle', 'Wissenschaftliche Leitung');
    einstellung_setzen('veranstaltung_name', 'Allgäuer Winter-Notfallsymposium');
    einstellung_setzen('veranstaltung_auflage', '3. Auflage');
    $a = anm_laden($aid);
    $z = zertifikat_pdf(zertifikat_daten($a));
    pruefe(str_starts_with($z, '%PDF-'), 'Zertifikat: PDF');
    $ausgabe = getenv('WS_TEST_AUSGABE') ?: '';
    if ($ausgabe !== '') {
        file_put_contents($ausgabe . '/zertifikat.pdf', $z);
    }
    if (trim((string)shell_exec('command -v pdftotext')) !== '') {
        $txt = function (string $pdf): string {
            $f = tempnam(sys_get_temp_dir(), 'wsz');
            file_put_contents($f, $pdf);
            $t = (string)shell_exec('pdftotext -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
            @unlink($f);
            return $t;
        };
        $zt = $txt($z);
        pruefe(str_contains($zt, mb_strtoupper($a['vorname'] . ' ' . $a['nachname'])) && str_contains($zt, 'NICHT NUR') && str_contains($zt, $wsTitel) && str_contains($zt, 'Nr. ' . zertifikat_nr($aid)), 'Zertifikat: Name, Titel, Workshop, Nummer');
        pruefe(str_contains($zt, '4 Punkte') && str_contains($zt, 'Kategorie A') && str_contains($zt, 'Beispielkammer') && str_contains($zt, 'Dr. Erika Erfunden') && !str_contains($zt, $a['berufsgruppe']), 'Zertifikat: Punkte, Kammer, Leitung, keine Berufsgruppe');
        pruefe(str_contains($zt, 'ALLGÄUER') && str_contains($zt, '3. AUFLAGE'), 'Zertifikat: Name der Veranstaltung und Auflage aus den Einstellungen');
        $u1 = unterschriftenliste_pdf(false, 'vorname');
        $u2 = unterschriftenliste_pdf(true, 'nachname');
        if ($ausgabe !== '') {
            file_put_contents($ausgabe . '/unterschriften.pdf', $u1);
            file_put_contents($ausgabe . '/unterschriften-aerzte.pdf', $u2);
        }
        $ut = $txt($u1);
        pruefe(str_starts_with($u1, '%PDF-') && str_contains($ut, 'UNTERSCHRIFTENLISTE') && str_contains($ut, $a['nachname']) && str_contains($ut, 'sortiert nach Vorname'), 'Unterschriftenliste: alle, sortiert');
        $ut2 = $txt($u2);
        pruefe(count(array_filter(tn_liste(), fn($x) => $x['aerztin'])) === 0 || (str_contains($ut2, 'EFN') && !str_contains($ut2, 'StudentIn')), 'Unterschriftenliste ÄrztInnen: mit EFN, ohne andere Berufsgruppen');
    }
    // Unterschrift als Bild
    $b = imagecreatetruecolor(300, 80);
    imagefill($b, 0, 0, imagecolorallocate($b, 255, 255, 255));
    imageline($b, 10, 60, 290, 20, imagecolorallocate($b, 0, 0, 0));
    ob_start();
    imagepng($b);
    unterschrift_schreiben((string)ob_get_clean());
    pruefe(unterschrift_da() && str_starts_with(zertifikat_pdf(zertifikat_beispiel()), '%PDF-'), 'Unterschrift gespeichert, Zertifikat mit Bild');
    $abgelehnt = false;
    try {
        unterschrift_schreiben('<svg></svg>');
    } catch (RuntimeException $f) {
        $abgelehnt = true;
    }
    pruefe($abgelehnt, 'Unterschrift: nur PNG oder JPG');
    unterschrift_entfernen(null);
    pruefe(!unterschrift_da(), 'Unterschrift entfernen');
    pruefe(mail_vorlage('zertifikat', ['vorname' => 'X', 'link' => 'L', 'gueltig' => 'G', 'name' => 'N'])[0] === 'Deine Teilnahmebescheinigung ist da', 'Mail-Vorlage Zertifikat');
    einstellung_setzen('veranstaltung_datum', $datumAlt);
    db_ausfuehren('DELETE FROM mail_ausgang');
}
