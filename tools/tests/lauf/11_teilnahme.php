<?php
declare(strict_types=1);

/* Testbereich: Anwesenheit, Freischalten der Zertifikate, Zertifikat und Unterschriftenliste als PDF, Code 128 und Unterschrift (AP-10, E-57). */

function test_teilnahme(): void
{
    abschnitt('Teilnahme und Zertifikate (AP-10, E-57)');
    $an = test_frisch()['bd']['anmeldungen'];
    $lea = (int)$an['lea'];
    $paula = (int)$an['paula'];
    // Beispieldaten: bestätigt sind Lea (WS 01 19:30, WS 02 20:30, Zertifikat frei) und Paula (WS 01 20:30, dort nicht erschienen); Tom ist unbestätigt
    $tl = tn_liste();
    pruefe(array_map(fn($e) => (int)$e['anm']['id'], $tl) === [$lea, $paula] && count(array_filter($tl, fn($e) => $e['anwesend'])) === 2, 'Teilnahme: nur bestätigte mit festem Platz (Lea, Paula), Standard anwesend');
    $e = $tl[0];
    $symPlatz = (int)$e['platz']['id'];
    $wsPlatz = (int)$e['workshops'][0]['id'];
    pruefe(zertifikat_verfuegbar(anm_laden($lea)) && zertifikat_sperren($lea, null) && !zertifikat_verfuegbar(anm_laden($lea)) && !zertifikat_sperren($lea, null), 'Freischaltung zurücknehmen (einmal)');
    pruefe(array_column(zertifikat_daten($e['anm'])['workshops'], 'kuerzel') === ['WS 01', 'WS 02'], 'Zertifikat: alle festen Workshops als besucht');
    $audit0 = (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'teilnahme' AND objekt_art = 'anmeldung' AND objekt_id = ?", [$lea]);
    pruefe(tn_setzen($wsPlatz, false, null) && array_column(zertifikat_daten(anm_laden($lea))['workshops'], 'kuerzel') === ['WS 02'] && tn_anwesend($lea), 'Workshop „nicht erschienen“: fehlt auf dem Zertifikat, Symposium bleibt');
    $eintrag = (string)db_wert("SELECT text FROM audit WHERE art = 'teilnahme' AND objekt_art = 'anmeldung' AND objekt_id = ? ORDER BY id DESC LIMIT 1", [$lea]);
    pruefe((int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'teilnahme' AND objekt_art = 'anmeldung' AND objekt_id = ?", [$lea]) === $audit0 + 1
        && str_contains($eintrag, 'nicht erschienen') && !str_contains($eintrag, 'Beispiel'), 'Anwesenheit im Protokoll, ohne Namen');
    tn_setzen($wsPlatz, true, null);
    pruefe(!tn_setzen(999999, false, null), 'unbekannter Platz: nichts');
    // Ohne Anwesenheit kein Zertifikat (T-191); die Veranstaltung liegt im Datensatz in der Zukunft
    tn_setzen($symPlatz, false, null);
    pruefe(!zertifikat_frei_ab_heute(), 'Freischalten erst ab dem Veranstaltungstag');
    einstellung_setzen('veranstaltung_datum', gmdate('Y-m-d', time() - 86400));
    pruefe(zertifikat_frei_ab_heute() && !tn_anwesend($lea) && zertifikat_freischalten($lea, null) === 0, 'Nicht erschienen: kein Zertifikat');
    $offen = tn_zahlen()['offen'];
    $n = zertifikat_freischalten(null, null);
    pruefe($offen === 1 && $n === 1 && (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE betreff LIKE 'Deine Teilnahmebescheinigung%'") === 1
        && test_mail_an('paula@example.org') !== null && anm_laden($paula)['zertifikat_am'] !== null, 'Alle Anwesenden freigeschaltet (Paula), je eine Mail');
    pruefe(!zertifikat_verfuegbar(anm_laden($lea)) && anm_laden($lea)['zertifikat_am'] === null, 'Nicht erschienen: nicht freigeschaltet');
    $mailText = (string)(test_mail_an('paula@example.org')['text'] ?? '');
    pruefe(str_contains($mailText, '/link?t=') && str_contains($mailText, 'Allgäuer Winter-Notfallsymposium'), 'Mail mit Link und Name der Veranstaltung');
    tn_setzen($symPlatz, true, null);
    pruefe(zertifikat_freischalten($lea, null, false) === 1 && zertifikat_verfuegbar(anm_laden($lea)) && zertifikat_freischalten($lea, null) === 0 && test_mail_an('lea@example.org') === null, 'Einzeln freischalten (ohne Mail), nicht doppelt');
    tn_setzen($symPlatz, false, null);
    pruefe(!zertifikat_verfuegbar(anm_laden($lea)), 'Nach der Freischaltung als nicht erschienen markiert: kein Download');
    // CME-Liste: nur anwesende ÄrztInnen (Lea ist Ärztin, Paula Pflege)
    $cmeOhne = export_erzeugen('cme')['text'];
    tn_setzen($symPlatz, true, null);
    $cmeMit = export_erzeugen('cme')['text'];
    pruefe(!str_contains($cmeOhne, '"Beispiel"'), 'CME-Liste ohne „nicht erschienen“');
    pruefe(str_contains($cmeMit, '"Beispiel"') && str_contains($cmeMit, '802760012345673') && !str_contains($cmeMit, '"Probe"'), 'CME-Liste mit anwesenden ÄrztInnen, ohne andere Berufsgruppen');
    // Barcode Code 128 B
    pruefe(count(array_filter(array_slice(CODE128_MUSTER, 0, 106), fn($m) => array_sum(str_split($m)) !== 11)) === 0 && array_sum(str_split(CODE128_MUSTER[106])) === 13 && count(array_unique(CODE128_MUSTER)) === 107, 'Code 128: 106 Zeichen mit 11 Modulen, Stopp mit 13');
    $bc = code128_module('*802760012345673*');
    pruefe($bc !== null && array_sum($bc) === 11 * 19 + 13 && code128_module("ä") === null, 'Code 128: *EFN* hat 222 Module; Umlaut abgelehnt');
    pruefe(array_slice(code128_module('A') ?? [], 6, 6) === [1, 1, 1, 3, 2, 3] && array_slice(code128_module('A') ?? [], 12, 6) === array_map('intval', str_split(CODE128_MUSTER[(104 + 33) % 103])), 'Code 128: Zeichen und Prüfzeichen');
    // PDFs (Einstellungen für Punkte, Kammer, Leitung und Auflage aus den Beispieldaten)
    $a = anm_laden($lea);
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
        pruefe(str_contains($zt, 'LEA BEISPIEL') && str_contains($zt, 'NICHT NUR') && str_contains($zt, 'Atemweg. Plan B.') && str_contains($zt, 'Kleine PatientInnen') && str_contains($zt, 'Nr. ' . zertifikat_nr($lea)), 'Zertifikat: Name, Titel, Workshops, Nummer');
        pruefe(str_contains($zt, '4 Punkte') && str_contains($zt, 'Kategorie A') && str_contains($zt, 'Beispiel-Landesärztekammer') && str_contains($zt, 'Dr. Erika Erfunden') && !str_contains($zt, 'ÄrztIn'), 'Zertifikat: Punkte, Kammer, Leitung, keine Berufsgruppe');
        pruefe(str_contains($zt, 'ALLGÄUER') && str_contains($zt, '3. AUFLAGE'), 'Zertifikat: Name der Veranstaltung und Auflage aus den Einstellungen');
        $u1 = unterschriftenliste_pdf(false, 'vorname');
        $u2 = unterschriftenliste_pdf(true, 'nachname');
        if ($ausgabe !== '') {
            file_put_contents($ausgabe . '/unterschriften.pdf', $u1);
            file_put_contents($ausgabe . '/unterschriften-aerzte.pdf', $u2);
        }
        $ut = $txt($u1);
        pruefe(str_starts_with($u1, '%PDF-') && str_contains($ut, 'UNTERSCHRIFTENLISTE') && str_contains($ut, 'Beispiel') && str_contains($ut, 'Probe') && str_contains($ut, 'sortiert nach Vorname'), 'Unterschriftenliste: alle, sortiert');
        $ut2 = $txt($u2);
        pruefe(str_contains($ut2, 'EFN') && str_contains($ut2, 'Beispiel') && !str_contains($ut2, 'Probe'), 'Unterschriftenliste ÄrztInnen: mit EFN, ohne andere Berufsgruppen');
    }
    // Unterschrift als Bild (die Beispieldaten bringen schon eine mit)
    unterschrift_entfernen(null);
    pruefe(!unterschrift_da(), 'Unterschrift entfernen');
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
    pruefe($abgelehnt && unterschrift_da(), 'Unterschrift: nur PNG oder JPG, die vorhandene bleibt');
    pruefe(mail_vorlage('zertifikat', ['vorname' => 'X', 'link' => 'L', 'gueltig' => 'G', 'name' => 'N'])[0] === 'Deine Teilnahmebescheinigung ist da', 'Mail-Vorlage Zertifikat');
}
