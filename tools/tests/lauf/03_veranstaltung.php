<?php
declare(strict_types=1);

/* Testbereich: Einstellungen einer Veranstaltung (Startwerte, Prüfung, Speichern) und mehrere Veranstaltungen nebeneinander (E-58). */

function test_veranstaltung(): void
{
    abschnitt('Veranstaltungs-Einstellungen (AP-3 Schritt 2)');
    test_frisch(['beispiel' => false]); // Startwerte: nichts gespeichert
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

    abschnitt('Mehrere Veranstaltungen (E-58)');
    // Beispieldaten: aktuelle Veranstaltung mit Lea, Paula (bestätigt), Tom (unbestätigt); Lea mit Konto
    $an = test_frisch()['bd']['anmeldungen'];
    $v1 = va_id();
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
    // Gleiche Adresse in beiden Veranstaltungen: Lea meldet sich auch für 2028 an (Anlegen wird geprüft)
    $e1 = anm_laden($an['lea']);
    $daten = ['vorname' => $e1['vorname'], 'nachname' => $e1['nachname'], 'email' => $e1['email'], 'berufsgruppe' => $e1['berufsgruppe'], 'institution' => $e1['institution'] ?: 'X', 'essen' => 'egal', 'efn' => '', 'beruf_sonstige' => ''];
    $neuV2 = va_mit($v2, fn() => anm_anlegen(anm_daten_pruefen($daten)['werte'], ['fest' => [], 'warte' => []], false));
    pruefe(($neuV2['art'] ?? '') === 'neu' && (int)anm_laden($neuV2['id'])['veranstaltung_id'] === $v2 && anm_per_email($e1['email'])['id'] == $e1['id'], 'Eine Anmeldung je Veranstaltung und Adresse');
    pruefe(va_mit($v2, fn() => rm_empfaenger('angemeldet')) === [] && count(rm_empfaenger('angemeldet')) === 2, 'Mailing-Empfänger aus der eigenen Veranstaltung (2028: Lea noch unbestätigt; aktuell: Lea und Paula)');
    // Wiederkehrende ohne Konto: Daten übernehmen
    $mailVor = (int)db_wert('SELECT COUNT(*) FROM mail_ausgang');
    $fr = va_mit($v2, fn() => anm_frueher_per_email('wer@example.org'));
    $fr2 = va_mit($v2, fn() => anm_frueher_per_email(' Paula@example.org'));
    pruefe($fr === null && $fr2 !== null && (int)$fr2['id'] === $an['paula'], 'Frühere Anmeldung einer anderen Veranstaltung gefunden');
    va_mit($v2, fn() => anm_uebernehmen_senden((int)$fr2['id']));
    $tok = '';
    if (preg_match('#/link\?t=([A-Za-z0-9_-]{43})#', (string)(test_mail_an('paula@example.org')['text'] ?? ''), $m) === 1) {
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
    // Profil: Veranstaltungen eines Kontos (Lea: aktuell, Abend, Vorjahr und jetzt 2028)
    $kid = $an['lea_konto'];
    db_ausfuehren("UPDATE anmeldung SET konto_id = ?, status = 'bestaetigt' WHERE id = ?", [$kid, $neuV2['id']]);
    $liste = tk_veranstaltungen($kid);
    pruefe(count($liste) === 4 && in_array('Winter 2028', array_column($liste, 'name'), true), 'Mein Konto: alle Veranstaltungen der Person');
    pruefe(tk_anmeldung($kid)['id'] == $an['lea'] && va_mit($v2, fn() => tk_anmeldung($kid))['id'] == $neuV2['id'], 'Anmeldung des Kontos je Veranstaltung');
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
    $alle = va_alle();
    pruefe(count($alle) === 4 && array_column(array_filter($alle, fn($v) => $v['status'] === 'aktuell'), 'id') === [$v2], 'Liste aller Veranstaltungen: vier, nur 2028 aktuell');
    // Zertifikat einer vergangenen Veranstaltung im eigenen Kontext (Lea, Zertifikat freigeschaltet)
    $pdfAlt = zertifikat_pdf_fuer(anm_laden($an['lea']));
    pruefe(str_starts_with($pdfAlt, '%PDF-'), 'Zertifikat einer abgeschlossenen Veranstaltung');
}
