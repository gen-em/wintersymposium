<?php
declare(strict_types=1);

/* Testbereich: Gesamtablauf mit zwei Personen (mechanisch aus lauf.php ausgelagert, noch nicht auf test_frisch umgestellt). */

function test_gesamtablauf(): void
{
    abschnitt('Gesamtablauf mit zwei Personen (T-202)');
    $vG = (int)va_anlegen('gesamttest', 'Gesamttest', null, null)['id'];
    $gesamt = va_mit($vG, function (): array {
        $e = [];
        foreach (['veranstaltung_datum' => gmdate('Y-m-d', time() + 20 * 86400), 'veranstaltung_kapazitaet' => '10', 'berufsgruppen' => VA_KATALOG['berufsgruppen']['start'],
            'anmeldung_bestaetigung_h' => '48', 'nachrueck_frist_h' => '48', 'nachrueck_grenze_tage' => '1', 'cme_punkte' => '3'] as $k => $w) {
            einstellung_setzen($k, $w);
        }
        programm_punkt_speichern(null, programm_punkt_pruefen(['typ' => 'vortrag', 'kuerzel' => 'VT 01', 'titel' => 'Eröffnung', 'beginn' => '18:00', 'ende' => '18:30']), null);
        programm_punkt_speichern(null, programm_punkt_pruefen(['typ' => 'workshop', 'kuerzel' => 'WS 01', 'titel' => 'Engpass', 't_beginn' => ['19:00'], 't_ende' => ['20:00'], 't_plaetze' => ['1']]), null);
        $e['pub'] = programm_veroeffentlichen(null);
        $ws = anm_workshops(true)[0];
        $tid = (int)$ws['termine'][0]['id'];
        $daten = fn(string $v, string $m) => anm_daten_pruefen(['vorname' => $v, 'nachname' => 'Gesamt', 'email' => $m, 'berufsgruppe' => 'ÄrztIn', 'institution' => 'Klinik', 'efn' => '802760012345673', 'beruf_sonstige' => '', 'essen' => 'egal'])['werte'];
        $mailVon = fn(string $an) => (string)db_wert('SELECT text FROM mail_ausgang WHERE an = ? ORDER BY id DESC LIMIT 1', [$an]);
        $token = fn(string $text) => preg_match('#/link\?t=([A-Za-z0-9_-]{43})#', $text, $m) === 1 ? $m[1] : '';
        // 1. Ohne Konto: anmelden mit dem einzigen Workshop-Platz, über den Link bestätigen
        $p1 = anm_anlegen($daten('Ohne', 'ohne.konto@example.org'), ['fest' => [$ws['id'] => $tid], 'warte' => []], false);
        $e['p1_bestaetigt'] = anm_link_einloesen($token($mailVon('ohne.konto@example.org')))['zweck'] ?? '';
        // 2. Mit Konto-Wunsch: Warteliste für den vollen Workshop, Bestätigung legt das Konto an
        $p2 = anm_anlegen($daten('Mit', 'mit.konto@example.org'), ['fest' => [], 'warte' => [$tid]], false, null, true);
        $e['p2_konto'] = anm_link_einloesen($token($mailVon('mit.konto@example.org')))['konto_neu'] ?? null;
        $e['p2_warte'] = db_wert('SELECT status FROM platz WHERE anmeldung_id = ? AND termin_id = ?', [$p2['id'], $tid]);
        // 3. Änderung: Person 1 gibt den Workshop ab → Nachrück-Angebot an Person 2 → Annahme
        $e['aendern'] = anm_workshops_setzen($p1['id'], ['fest' => [], 'warte' => []]);
        $angebot = db_zeile("SELECT * FROM platz WHERE anmeldung_id = ? AND termin_id = ? AND status = 'angebot'", [$p2['id'], $tid]);
        $e['angebot'] = $angebot !== null;
        $e['annahme'] = $angebot !== null ? anm_angebot_annehmen($p2['id'], (int)$angebot['id'], true) : ['x'];
        $e['p2_fest'] = db_wert('SELECT status FROM platz WHERE anmeldung_id = ? AND termin_id = ?', [$p2['id'], $tid]);
        // 4. Stichtag vorbei: Teilnehmende ändern nicht mehr selbst
        einstellung_setzen('stichtag_workshop', gmdate('Y-m-d', time() - 86400));
        $e['stichtag'] = anm_frist_offen('stichtag_workshop');
        // 5. Eine dritte Person storniert
        $p3 = anm_anlegen($daten('Weg', 'storno@example.org'), ['fest' => [], 'warte' => []], false);
        anm_link_einloesen($token($mailVon('storno@example.org')));
        $e['storno'] = anm_stornieren($p3['id']) && anm_laden($p3['id']) === null;
        // 6. Rundmail an alle Angemeldeten
        $rw = rm_pruefen(['betreff' => 'Bis bald, {vorname}', 'html' => '<p>Hallo {vorname}</p>', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system']);
        $e['rundmail'] = rm_einreihen((int)rm_speichern(null, $rw['werte'], null), null);
        // 7. Veranstaltungstag vorbei: Anwesenheit (Person 1 ist nicht da), Zertifikate
        einstellung_setzen('veranstaltung_datum', gmdate('Y-m-d', time() - 86400));
        tn_setzen((int)db_wert('SELECT id FROM platz WHERE anmeldung_id = ? AND termin_id = 0', [$p1['id']]), false, null);
        $e['frei'] = zertifikat_freischalten(null, null);
        $e['z1'] = zertifikat_verfuegbar(anm_laden($p1['id']));
        $e['z2'] = zertifikat_verfuegbar(anm_laden($p2['id']));
        $e['pdf'] = str_starts_with(zertifikat_pdf_fuer(anm_laden($p2['id'])), '%PDF-');
        $e['konto_rueckblick'] = $e['p2_konto'] !== null ? (tk_veranstaltungen((int)$e['p2_konto'])[0]['zertifikat'] ?? false) : false;
        $e['ids'] = [$p1['id'], $p2['id']];
        return $e;
    });
    pruefe($gesamt['pub'] === [] && $gesamt['p1_bestaetigt'] === 'bestaetigen' && $gesamt['p2_konto'] !== null && $gesamt['p2_warte'] === 'warte', 'Anmelden: ohne Konto bestätigt, mit Konto angelegt, volle Workshops auf die Warteliste');
    pruefe($gesamt['aendern'] === [] && $gesamt['angebot'] && ($gesamt['annahme'] === [] || isset($gesamt['annahme']['ok'])) && $gesamt['p2_fest'] === 'fest', 'Änderung macht den Platz frei, Angebot, Annahme');
    pruefe($gesamt['stichtag'] === false && $gesamt['storno'] === true, 'Stichtag sperrt Änderungen; Storno löscht die Anmeldung');
    pruefe($gesamt['rundmail'] === 2, 'Rundmail an die beiden Angemeldeten');
    pruefe($gesamt['frei'] === 1 && !$gesamt['z1'] && $gesamt['z2'] && $gesamt['pdf'] && $gesamt['konto_rueckblick'], 'Anwesenheit und Zertifikat: nur die anwesende Person, auch im Konto-Rückblick');
    db_ausfuehren('DELETE FROM konto WHERE id = ?', [(int)$gesamt['p2_konto']]);
    db_ausfuehren('DELETE FROM anmeldung WHERE veranstaltung_id = ?', [$vG]);
    db_ausfuehren('DELETE FROM rundmail WHERE veranstaltung_id = ?', [$vG]);
    db_ausfuehren('DELETE FROM programmpunkt WHERE veranstaltung_id = ?', [$vG]);
    db_ausfuehren('DELETE FROM veranstaltung WHERE id = ?', [$vG]);
    db_ausfuehren('DELETE FROM mail_ausgang');
}
