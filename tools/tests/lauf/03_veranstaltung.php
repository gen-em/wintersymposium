<?php
declare(strict_types=1);

/* Testbereich: Veranstaltungen und ihre Einstellungen (mechanisch aus lauf.php ausgelagert, noch nicht auf test_frisch umgestellt). */

function test_veranstaltung(): void
{
    abschnitt('Veranstaltungs-Einstellungen (AP-3 Schritt 2)');
    $gut = [];
    foreach (VA_KATALOG as $k => $_) {
        $gut[$k] = va_formularwert($k);
    }
    $r = va_pruefen($gut);
    pruefe($r['fehler'] === [], 'Startwerte bestehen die Prüfung');
    pruefe($r['werte']['anmeldung_start'] === '2026-11-24 23:00:00', 'Anmeldung ab 25.11.2026 00:00 Ortszeit = 24.11. 23:00 UTC');
    pruefe(va_formularwert('anmeldung_start') === '2026-11-25T00:00', 'Formular zeigt Ortszeit');
    $f = fn(array $x) => va_pruefen($x + $gut)['fehler'];
    pruefe(isset($f(['veranstaltung_datum' => '2027-02-30'])['veranstaltung_datum']), 'ungültiges Datum abgelehnt');
    pruefe(isset($f(['veranstaltung_datum' => ''])['veranstaltung_datum']), 'Pflichtfeld Datum');
    pruefe(isset($f(['veranstaltung_ende' => '17:00'])['veranstaltung_ende']), 'Ende vor Beginn abgelehnt');
    pruefe(isset($f(['veranstaltung_beginn' => '25:00'])['veranstaltung_beginn']), 'ungültige Uhrzeit abgelehnt');
    pruefe(isset($f(['veranstaltung_kapazitaet' => '-5'])['veranstaltung_kapazitaet']) && isset($f(['veranstaltung_kapazitaet' => '12a'])['veranstaltung_kapazitaet']), 'Kapazität nur ganze Zahlen im Bereich');
    pruefe(isset($f(['nachrueck_frist_h' => '0'])['nachrueck_frist_h']), 'Annahmefrist mindestens 1 h');
    pruefe(isset($f(['workshop_pflicht' => 'vielleicht'])['workshop_pflicht']), 'Auswahl nur aus der Liste');
    pruefe(isset($f(['stichtag_storno' => '2027-01-21'])['stichtag_storno']), 'Stichtag nach der Veranstaltung abgelehnt');
    pruefe(isset($f(['anmeldung_ende' => '2026-11-20T10:00'])['anmeldung_ende']), 'Anmeldeschluss vor Anmeldestart abgelehnt');
    pruefe(isset($f(['anmeldung_start' => '2027-02-01T10:00'])['anmeldung_start']), 'Anmeldestart nach der Veranstaltung abgelehnt');
    pruefe(isset($f(['berufsgruppen' => "ÄrztIn\närztin"])['berufsgruppen']), 'doppelte Berufsgruppe abgelehnt');
    $r = va_pruefen(['berufsgruppen' => "  ÄrztIn \n\n Pflege/MFA  \n", 'veranstaltung_ort' => "  Klinikum   Kempten "] + $gut);
    pruefe($r['werte']['berufsgruppen'] === "ÄrztIn\nPflege/MFA" && $r['werte']['veranstaltung_ort'] === 'Klinikum Kempten', 'Leerzeilen und Leerzeichen bereinigt');
    pruefe(va('veranstaltung_kapazitaet') === '240' && va_liste('berufsgruppen')[0] === 'ÄrztIn', 'ohne gespeicherten Wert gilt der Startwert');
    $geaendert = va_speichern(va_pruefen(['veranstaltung_kapazitaet' => '100'] + $gut)['werte'], null);
    pruefe($geaendert === ['veranstaltung_kapazitaet'] && va_zahl('veranstaltung_kapazitaet') === 100, 'Speichern meldet nur Geändertes');
    pruefe(in_array(['Freie Plätze', '100 / 100'], va_eckdaten(), true), 'Eckdaten mit Kapazität');
    va_speichern(va_pruefen(['veranstaltung_kapazitaet' => '0', 'cme_anzeige' => ''] + $gut)['werte'], null);
    $eckTitel = array_column(va_eckdaten(), 0);
    pruefe(!in_array('Freie Plätze', $eckTitel, true) && !in_array('CME', $eckTitel, true), 'Kapazität 0 und leere CME-Anzeige: nicht in den Eckdaten');
    db_ausfuehren("DELETE FROM va_einstellung WHERE schluessel IN ('" . implode("','", array_keys(VA_KATALOG)) . "')");

    abschnitt('Mehrere Veranstaltungen (E-58)');
    $v1 = va_id();
    $anmV1 = count(anm_liste());
    pruefe(va_zeile($v1)['status'] === 'aktuell' && $anmV1 > 0, 'Ausgangslage: eine aktuelle Veranstaltung mit Anmeldungen');
    pruefe(va_kuerzel_fehler('Team 2028') !== null && va_kuerzel_fehler('team') !== null && va_kuerzel_fehler(va_zeile($v1)['kuerzel']) !== null && va_kuerzel_fehler('winter-2028') === null, 'Kürzel: Format, belegte Adressen und doppelte abgelehnt');
    $r = va_anlegen('winter-2028', 'Winter 2028', $v1, null);
    $v2 = (int)($r['id'] ?? 0);
    pruefe($v2 > 0 && va_zeile($v2)['status'] === 'entwurf' && va_von($v2, 'veranstaltung_ort') === va('veranstaltung_ort') && va_von($v2, 'veranstaltung_name') === 'Winter 2028', 'Neue Veranstaltung als Kopie: Entwurf mit Einstellungen und eigenem Namen');
    $pv1 = count(programm_arbeitsstand());
    pruefe(va_mit($v2, fn() => count(programm_arbeitsstand())) === $pv1 && va_mit($v2, fn() => programm_veroeffentlicht()) === null, 'Programm kopiert (Arbeitsstand, nicht veröffentlicht)');
    pruefe(va_mit($v2, fn() => anm_liste()) === [] && va_mit($v2, fn() => anm_belegt(0)) === 0 && anm_belegt(0) > 0, 'Anmeldungen und Plätze getrennt');
    va_mit($v2, fn() => einstellung_setzen('veranstaltung_ort', 'Neuer Ort'));
    va_mit($v2, fn() => inhalt_setzen('start_titel', 'Titel 2028'));
    pruefe(va('veranstaltung_ort') !== 'Neuer Ort' && va_von($v2, 'veranstaltung_ort') === 'Neuer Ort' && inhalt('start_titel') !== 'Titel 2028' && va_mit($v2, fn() => inhalt('start_titel')) === 'Titel 2028', 'Einstellungen und Startseiten-Texte je Veranstaltung');
    pruefe(va_mit($v2, fn() => anm_zeitraum()) === 'vorher', 'Entwurf nimmt keine Anmeldungen an');
    pruefe(va_aktuell_machen($v2, null) !== null, 'Zweite aktuelle nur nach Abschluss der ersten');
    // Gleiche Adresse in beiden Veranstaltungen
    $e1 = anm_liste()[0];
    $daten = ['vorname' => $e1['vorname'], 'nachname' => $e1['nachname'], 'email' => $e1['email'], 'berufsgruppe' => $e1['berufsgruppe'], 'institution' => $e1['institution'] ?: 'X', 'essen' => 'egal', 'efn' => '', 'beruf_sonstige' => ''];
    $neuV2 = va_mit($v2, fn() => anm_anlegen(anm_daten_pruefen($daten)['werte'], ['fest' => [], 'warte' => []], false));
    pruefe(($neuV2['art'] ?? '') === 'neu' && (int)anm_laden($neuV2['id'])['veranstaltung_id'] === $v2 && anm_per_email($e1['email'])['id'] == $e1['id'], 'Eine Anmeldung je Veranstaltung und Adresse');
    pruefe(va_mit($v2, fn() => count(rm_empfaenger('angemeldet'))) <= 1 && count(rm_empfaenger('angemeldet')) > 1, 'Mailing-Empfänger aus der eigenen Veranstaltung');
    // Wiederkehrende ohne Konto: Daten übernehmen
    $mailVor = (int)db_wert('SELECT COUNT(*) FROM mail_ausgang');
    $fr = va_mit($v2, fn() => anm_frueher_per_email('wer@example.org'));
    $mailFrueher = (string)db_wert("SELECT email FROM anmeldung WHERE veranstaltung_id = ? AND status = 'bestaetigt' ORDER BY id LIMIT 1", [$v1]);
    $fr2 = va_mit($v2, fn() => anm_frueher_per_email($mailFrueher));
    pruefe($fr === null && $fr2 !== null && (int)$fr2['veranstaltung_id'] === $v1, 'Frühere Anmeldung einer anderen Veranstaltung gefunden');
    va_mit($v2, fn() => anm_uebernehmen_senden((int)$fr2['id']));
    $tok = '';
    if (preg_match('#/link\?t=([A-Za-z0-9_-]{43})#', (string)db_wert('SELECT text FROM mail_ausgang ORDER BY id DESC LIMIT 1'), $m) === 1) {
        $tok = $m[1];
    }
    $ein = anm_link_einloesen($tok);
    pruefe((int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === $mailVor + 1 && ($ein['zweck'] ?? '') === 'uebernehmen' && $ein['anm'] === (int)$fr2['id'], 'Link „Daten übernehmen“ per Mail, einlösbar');
    $_SESSION[SITZUNG_SCHLUESSEL_ANM_VORLAGE] = ['id' => (int)$fr2['id'], 'zeit' => time()];
    pruefe((anm_vorlage()['email'] ?? '') === $fr2['email'] && (anm_vorlage()['institution'] ?? '') === $fr2['institution'], 'Vorlage fürs Formular aus der früheren Anmeldung');
    unset($_SESSION[SITZUNG_SCHLUESSEL_ANM_VORLAGE]);
    // Bausteine: ohne Essen und ohne Fortbildungspunkte
    va_mit($v2, fn() => einstellung_setzen('mit_essen', 'nein'));
    va_mit($v2, fn() => einstellung_setzen('mit_cme', 'nein'));
    $ohne = va_mit($v2, fn() => anm_daten_pruefen(['essen' => '', 'efn' => '802760012345673', 'berufsgruppe' => va_liste('berufsgruppen')[0]] + $daten));
    pruefe(!isset($ohne['fehler']['essen']) && $ohne['werte']['essen'] === 'kein' && $ohne['werte']['efn'] === '' && va_an('mit_essen'), 'Ohne Essen und Punkte: keine Essenswahl, keine EFN (nur in dieser Veranstaltung)');
    // Profil: Veranstaltungen eines Kontos
    tk_registrieren('Vera Viel', 'vera.viel@example.org');
    $kid = (int)konto_per_email('vera.viel@example.org')['id'];
    db_ausfuehren('UPDATE konto SET email_bestaetigt_am = ? WHERE id = ?', [db_jetzt(), $kid]);
    db_ausfuehren("UPDATE anmeldung SET konto_id = ?, status = 'bestaetigt' WHERE id = ?", [$kid, $neuV2['id']]);
    $va1Anm = (int)db_wert("SELECT id FROM anmeldung WHERE veranstaltung_id = ? AND konto_id IS NULL AND status = 'bestaetigt' ORDER BY id LIMIT 1", [$v1]);
    db_ausfuehren('UPDATE anmeldung SET konto_id = ? WHERE id = ?', [$kid, $va1Anm]);
    $liste = tk_veranstaltungen($kid);
    pruefe(count($liste) === 2 && in_array('Winter 2028', array_column($liste, 'name'), true), 'Mein Konto: alle Veranstaltungen der Person');
    pruefe(tk_anmeldung($kid)['id'] == $va1Anm && va_mit($v2, fn() => tk_anmeldung($kid))['id'] == $neuV2['id'], 'Anmeldung des Kontos je Veranstaltung');
    // Abschließen und neue aktuelle
    // Ohne aktuelle Veranstaltung kein Rückfall auf Nr. 1 (Review B-10): öffentlich „geschlossen“, im Kontext der alten „vorbei“
    pruefe(va_abschliessen($v1, null) === null && va_abgeschlossen($v1) && anm_zeitraum() === 'geschlossen' && va_mit($v1, fn() => anm_zeitraum()) === 'vorbei' && va_abschliessen($v1, null) !== null, 'Abschließen: nur noch lesen, keine Anmeldung');
    pruefe(va_aktuell_machen($v2, null) === null && va_aktuell_id() === $v2 && va_id() === $v2, 'Danach wird die neue Veranstaltung die aktuelle');
    pruefe(va_kuerzel_setzen($v2, 'Ungültig!', null) !== null && va_kuerzel_setzen($v2, 'winter2028', null) === null && va_per_kuerzel('winter2028')['id'] == $v2, 'Kürzel ändern');
    pruefe(va_loeschen($v2, null) !== null, 'Aktuelle mit Anmeldungen lässt sich nicht löschen');
    $v3 = (int)va_anlegen('leer-2029', 'Leer', null, null)['id'];
    pruefe(va_mit($v3, fn() => programm_arbeitsstand()) === [] && va_loeschen($v3, null) !== null && va_zeile($v3) !== null, 'Löschen ohne eingetipptes Kürzel abgewiesen');
    $sichVor = count(sicherung_liste());
    pruefe(va_loeschen($v3, null, 'leer-2029') === null && va_zeile($v3) === null && count(sicherung_liste()) === $sichVor + 1, 'Leerer Entwurf mit Kürzel gelöscht, vorher gesichert');
    pruefe(va_alle()[0]['id'] !== 0 && count(va_alle()) === 1 + (int)db_wert("SELECT COUNT(*) FROM veranstaltung WHERE status = 'abgeschlossen'"), 'Liste aller Veranstaltungen');
    // Zertifikat einer vergangenen Veranstaltung im eigenen Kontext
    $pdfAlt = zertifikat_pdf_fuer(anm_laden($va1Anm));
    pruefe(str_starts_with($pdfAlt, '%PDF-'), 'Zertifikat einer abgeschlossenen Veranstaltung');
    // Aufräumen: wieder nur Veranstaltung 1 als aktuelle
    db_ausfuehren('DELETE FROM anmeldung WHERE veranstaltung_id = ?', [$v2]);
    db_ausfuehren('DELETE FROM programmpunkt WHERE veranstaltung_id = ?', [$v2]);
    db_ausfuehren('DELETE FROM veranstaltung WHERE id = ?', [$v2]);
    db_ausfuehren("UPDATE veranstaltung SET status = 'aktuell', abgeschlossen_am = NULL WHERE id = ?", [$v1]);
    db_ausfuehren('DELETE FROM konto WHERE id = ?', [$kid]);
    va_zwischenspeicher_leeren();
    pruefe(va_id() === $v1 && count(anm_liste()) === $anmV1, 'Zurück auf Veranstaltung 1');
}
