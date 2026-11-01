<?php
declare(strict_types=1);

/* Testbereich: Review-Behebung 1 (mechanisch aus lauf.php ausgelagert, noch nicht auf test_frisch umgestellt). */

function test_review1(): void
{
    abschnitt('Review-Behebung 1 (AP-14, T-275 bis T-281)');
    ctx_setzen('team_konto', null);
    db_ausfuehren('DELETE FROM mail_ausgang');
    $rd = ['nachname' => 'Review', 'berufsgruppe' => 'StudentIn', 'institution' => 'Uni', 'efn' => '', 'essen' => 'egal'];
    // Unterpunkt 1 (S-1, T-275): Adresse eines Team-Kontos nicht über eine Anmeldung
    $bId = konto_anlegen('Bea Betrieb', 'bea.betrieb@example.org', 'betreiber');
    $bAnm = team_anlegen(['vorname' => 'Bea', 'email' => 'bea.betrieb@example.org'] + $rd, true, false)['id'];
    db_ausfuehren('UPDATE anmeldung SET konto_id = ? WHERE id = ?', [$bId, $bAnm]);
    $f = team_anm_daten($bAnm, ['vorname' => 'Bea', 'email' => 'angreifer@example.org'] + $rd, false);
    pruefe(($f['email'] ?? '') === ANM_TEAM_ADRESSE_TEXT && konto_laden($bId)['email'] === 'bea.betrieb@example.org' && anm_laden($bAnm)['email'] === 'bea.betrieb@example.org', '(a) Team ändert Adresse einer Anmeldung mit Betreiber-Konto: abgelehnt, beides unverändert');
    pruefe(team_anm_daten($bAnm, ['vorname' => 'Beate', 'email' => 'bea.betrieb@example.org'] + $rd, false) === [] && anm_laden($bAnm)['vorname'] === 'Beate' && konto_laden($bId)['name'] === 'Bea Betrieb', 'übrige Felder änderbar, Team-Konto zieht nicht mit');
    $r = anm_daten_setzen($bAnm, ['vorname' => 'Beate', 'email' => 'bea.privat@example.org'] + $rd);
    pruefe(($r['fehler']['email'] ?? '') === ANM_TEAM_ADRESSE_TEXT && !$r['email_link'] && (int)db_wert("SELECT COUNT(*) FROM link WHERE art = 'anmeldung' AND anmeldung_id = ? AND zweck = 'email'", [$bAnm]) === 0 && (int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === 0, '(b) Team-Mitglied ändert eigene Adresse über Meine Anmeldung: abgelehnt, kein Link, keine Mail');
    $tok = anm_link_erzeugen($bAnm, 'email', time() + 3600, 'bea.privat@example.org');
    $e = anm_link_einloesen($tok);
    pruefe($e !== null && str_contains((string)$e['meldung'], 'Team-Kontos') && konto_laden($bId)['email'] === 'bea.betrieb@example.org' && anm_laden($bAnm)['email'] === 'bea.betrieb@example.org', 'zweite Sperre: alter E-Mail-Link schreibt nicht ins Team-Konto');
    $tId = konto_anlegen('Tom Teil', 'tom.teil@example.org', 'teilnehmend');
    $tAnm = team_anlegen(['vorname' => 'Tom', 'email' => 'tom.teil@example.org'] + $rd, true, false)['id'];
    db_ausfuehren('UPDATE anmeldung SET konto_id = ? WHERE id = ?', [$tId, $tAnm]);
    $au0 = (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'konto' AND text = ?", ['Konto ' . $tId . ': Adresse vom Team geändert']);
    $f = team_anm_daten($tAnm, ['vorname' => 'Tom', 'email' => 'tom.neu@example.org'] + $rd, false);
    $hin = db_zeile("SELECT * FROM mail_ausgang WHERE an = 'tom.teil@example.org' ORDER BY id DESC LIMIT 1");
    pruefe($f === [] && anm_laden($tAnm)['email'] === 'tom.neu@example.org' && konto_laden($tId)['email'] === 'tom.neu@example.org', '(c) Teilnehmenden-Konto: Adresse in Anmeldung und Konto geändert');
    pruefe($hin !== null && str_contains($hin['text'], 'Adresse deiner Anmeldung und deines Kontos geändert') && !str_contains($hin['text'], 'tom.neu@'), 'Hinweis-Mail an die alte Adresse (ohne neue Adresse)');
    $au = db_zeile("SELECT text FROM audit WHERE art = 'konto' ORDER BY id DESC LIMIT 1");
    pruefe((int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'konto' AND text = ?", ['Konto ' . $tId . ': Adresse vom Team geändert']) === $au0 + 1 && !str_contains($au['text'], '@'), 'Protokoll „Konto N: Adresse vom Team geändert“ ohne Adressen');
    db_ausfuehren('DELETE FROM mail_ausgang');

    // Unterpunkt 2 (B-1, T-276): Newsletter-Mails überleben den Versand nach einer Admin-Aktion
    $nlListe = nl_liste_speichern(null, nl_liste_pruefen(['name' => 'Review-Test', 'sichtbar' => '1'])['werte'], null);
    foreach (['nl.eins', 'nl.zwei', 'nl.drei'] as $nlN) {
        nl_anfragen($nlN . '@example.org', 'NL', [$nlListe], 'formular');
        $nlTok = preg_match('#/newsletter/bestaetigen\?t=([A-Za-z0-9_-]{43})#', (string)db_wert('SELECT text FROM mail_ausgang WHERE an = ? ORDER BY id DESC LIMIT 1', [$nlN . '@example.org']), $m) === 1 ? $m[1] : '';
        nl_bestaetigen($nlTok);
    }
    db_ausfuehren('DELETE FROM mail_ausgang');
    $nlRm = rm_speichern(null, rm_pruefen(['art' => 'newsletter', 'listen' => [$nlListe], 'betreff' => 'Review', 'html' => '<p>Hallo {vorname}</p>', 'postfach' => 'newsletter'])['werte'], null);
    $nlAnz = rm_einreihen($nlRm, null);
    ctx_setzen('team_rolle', 'admin');
    $nlE = mail_senden_faellige(5, 20.0);
    pruefe($nlAnz === 3 && $nlE['gesendet'] === 3 && (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE rundmail_id = ? AND status = 'gesendet'", [$nlRm]) === 3, 'Newsletter mit 3 Empfängern, Team-Rolle admin: alle 3 gesendet, keine gelöscht');
    $vaFremd = (int)db_wert('SELECT id FROM veranstaltung WHERE id <> ? ORDER BY id LIMIT 1', [va_id()]);
    $rmFremd = rm_speichern(null, rm_pruefen(['betreff' => 'Fremd', 'html' => '<p>x</p>', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system'])['werte'], null);
    db_ausfuehren('UPDATE rundmail SET veranstaltung_id = ? WHERE id = ?', [$vaFremd, $rmFremd]);
    $rmF = rm_laden($rmFremd);
    pruefe($vaFremd > 0 && $rmF !== null && function_exists('rm_sichtbar') && !rm_sichtbar($rmF) && !rm_sichtbar(rm_laden($nlRm)), 'Admin: Mailing einer fremden Veranstaltung und Newsletter nicht sichtbar, rm_laden liefert die Zeile');
    ctx_setzen('team_rolle', 'betreiber');
    pruefe(function_exists('rm_sichtbar') && rm_sichtbar(rm_laden($nlRm)), 'Betreiber: Newsletter-Mailing sichtbar');
    ctx_weg('team_rolle');
    db_ausfuehren("INSERT INTO mail_ausgang (an, betreff, text, prio, status, postfach, rundmail_id, anmeldung_id, erstellt_am, naechster_versuch) VALUES ('weg@example.org', '', '', 5, 'offen', 'system', 999999, 1, ?, ?)", [db_jetzt(), db_jetzt()]);
    mail_senden_faellige(5, 20.0);
    $weg = db_zeile("SELECT status, versuche, fehler FROM mail_ausgang WHERE an = 'weg@example.org'");
    pruefe($weg !== null && $weg['status'] === 'offen' && (int)$weg['versuche'] === 1 && str_contains((string)$weg['fehler'], 'nicht gefunden'), 'Mailing fehlt: Fehler mit Wiederholung statt Löschen');
    db_ausfuehren('DELETE FROM mail_ausgang');
    db_ausfuehren('DELETE FROM rundmail WHERE id IN (?, ?)', [$nlRm, $rmFremd]);
    db_ausfuehren('DELETE FROM newsletter_abo WHERE liste_id = ?', [$nlListe]);
    db_ausfuehren('DELETE FROM newsletter_liste WHERE id = ?', [$nlListe]);

    // Unterpunkt 3 (B-2, B-3, T-277): Warte-Einträge überleben „Speichern“
    $formWahl = function (int $anmId, bool $team): array {
        $bisher = array_values(array_filter(anm_plaetze($anmId), fn($p) => (int)$p['termin_id'] !== 0));
        $html = anm_workshopwahl_html(anm_wahl_aus_plaetzen($bisher), $bisher, $team);
        // was der Browser sendet: angekreuzte Felder
        $post = ['ws' => [], 'wl' => []];
        preg_match_all('/<input[^>]*name="(ws\[(\d+)\]|wl\[\])" value="(\d*)"[^>]* checked/', $html, $mm, PREG_SET_ORDER);
        foreach ($mm as $x) {
            if ($x[1] === 'wl[]') {
                $post['wl'][] = $x[3];
            } else {
                $post['ws'][$x[2]] = $x[3];
            }
        }
        return ['html' => $html, 'wahl' => anm_wahl_lesen($post)];
    };
    // Beispieldatensatz (AP-14): Wanda auf der Warteliste von WS 01 19:30, der Termin hat freie Plätze
    $bw = beispieldaten_warteliste();
    [$wlP, $wlT] = [$bw['wanda'], $bw['termin']];
    $wlSeit = (string)db_wert("SELECT seit FROM platz WHERE anmeldung_id = ? AND termin_id = ? AND status = 'warte'", [$wlP, $wlT]);
    $wlSeitNoch = fn(): ?string => db_wert("SELECT seit FROM platz WHERE anmeldung_id = ? AND termin_id = ? AND status = 'warte'", [$wlP, $wlT]);
    $fw = $formWahl($wlP, true);
    pruefe(str_contains($fw['html'], 'name="wl[]" value="' . $wlT . '" checked'), 'Team-Modus: bestehender Warte-Eintrag als vorangekreuztes Kästchen');
    team_anm_daten($wlP, ['vorname' => 'Wanda-Maria', 'email' => 'wanda.warte@example.org'] + $rd, false);
    $f = anm_workshops_setzen($wlP, $fw['wahl'], null, true, false, false);
    pruefe($f === [] && $wlSeitNoch() === $wlSeit, '(a) Team speichert nur den Namen: Warte-Eintrag mit altem „seit“ bleibt');
    $fw = $formWahl($wlP, false);
    pruefe(anm_frei($wlT) > 0 && str_contains($fw['html'], 'fest wählen oder auf der Warteliste bleiben') && str_contains($fw['html'], 'name="wl[]" value="' . $wlT . '" checked'), 'Termin mit freien Plätzen: Warte-Eintrag sichtbar mit Hinweis');
    $f = anm_workshops_setzen($wlP, $fw['wahl'], null, false, false, false);
    pruefe($f === [] && $wlSeitNoch() === $wlSeit, '(b) Teilnehmende speichert ohne Änderung: Eintrag bleibt');
    pruefe(anm_wahl_pruefen(['fest' => [], 'warte' => [$wlT]], []) !== [], 'neue Warteliste für einen freien Termin bleibt verboten');
    $f = anm_workshops_setzen($wlP, ['fest' => $fw['wahl']['fest'], 'warte' => []], null, false, false, false);
    pruefe($f === [] && $wlSeitNoch() === null, '(c) Kästchen abgewählt: Eintrag weg');

    // Unterpunkt 4 (S-2, T-278): Ratenschutz für Adressänderungen
    db_ausfuehren('DELETE FROM rate_limit');
    db_ausfuehren('DELETE FROM mail_ausgang');
    $raP = team_anlegen(['vorname' => 'Rita', 'email' => 'rita.rate@example.org'] + $rd, true, false)['id'];
    $raErg = [];
    for ($i = 1; $i <= 11; $i++) {
        $raErg[$i] = anm_daten_setzen($raP, ['vorname' => 'Rita', 'email' => 'ziel' . $i . '@example.org'] + $rd);
    }
    pruefe($raErg[10]['email_link'] && !$raErg[11]['email_link'] && !empty($raErg[11]['email_gesperrt']) && (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE an = 'ziel11@example.org'") === 0, '11 Adressänderungen auf 11 Zieladressen von einer IP: die 11. gesperrt (link_ip 10/h)');
    pruefe((int)db_wert("SELECT COUNT(*) FROM link WHERE art = 'anmeldung' AND anmeldung_id = ? AND zweck = 'email'", [$raP]) === 1 && db_wert("SELECT JSON_UNQUOTE(JSON_EXTRACT(daten, '$.neue_email')) FROM link WHERE art = 'anmeldung' AND anmeldung_id = ? AND zweck = 'email'", [$raP]) === 'ziel10@example.org', 'nur ein offener E-Mail-Link je Anmeldung (der neueste)');
    $raN = anm_daten_setzen($raP, ['vorname' => 'Ritaneu', 'email' => 'ziel12@example.org'] + $rd);
    pruefe(!empty($raN['email_gesperrt']) && $raN['fehler'] === [] && anm_laden($raP)['vorname'] === 'Ritaneu', 'gesperrt: übrige Daten trotzdem gespeichert');
    $raErg = [];
    db_ausfuehren('DELETE FROM rate_limit');
    for ($i = 1; $i <= 4; $i++) {
        $raErg[$i] = anm_daten_setzen($raP, ['vorname' => 'Rita', 'email' => 'gleich@example.org'] + $rd);
    }
    pruefe($raErg[3]['email_link'] && !$raErg[4]['email_link'] && !empty($raErg[4]['email_gesperrt']), '4 Änderungen auf dieselbe Zieladresse: die 4. gesperrt (link_ziel 3/h)');
    db_ausfuehren('DELETE FROM rate_limit');
    $raK = konto_anlegen('Kai Konto', 'kai.konto@example.org', 'teilnehmend');
    $raKf = [];
    for ($i = 1; $i <= 4; $i++) {
        $raKf[$i] = tk_email_aendern($raK, 'kai.neu@example.org');
    }
    pruefe($raKf[3] === null && $raKf[4] === ANM_ADRESSE_RATE_TEXT && (int)db_wert("SELECT COUNT(*) FROM link WHERE art = 'konto' AND konto_id = ? AND zweck = 'email'", [$raK]) === 1, 'Konto: Adressänderung mit Ratenschutz, ein offener Link');
    $raM = (string)db_wert("SELECT text FROM mail_ausgang WHERE an = 'gleich@example.org' ORDER BY id DESC LIMIT 1");
    pruefe(str_starts_with($raM, "Hallo,\n") && !str_contains($raM, 'Rita'), 'Mail an die neue Adresse ohne Vornamen („Hallo,“)');
    db_ausfuehren('DELETE FROM rate_limit');
    db_ausfuehren('DELETE FROM mail_ausgang');

    // Unterpunkt 6 (B-13, T-280): Vorlage „Daten übernehmen“ läuft ab
    $voP = team_anlegen(['vorname' => 'Vera', 'email' => 'vera.vorlage@example.org'] + $rd, true, false)['id'];
    $_SESSION[SITZUNG_SCHLUESSEL_ANM_VORLAGE] = ['id' => $voP, 'zeit' => time() - 29 * 60];
    $voFrisch = anm_vorlage();
    $_SESSION[SITZUNG_SCHLUESSEL_ANM_VORLAGE] = ['id' => $voP, 'zeit' => time() - 31 * 60];
    $voAlt = anm_vorlage();
    pruefe(($voFrisch['email'] ?? '') === 'vera.vorlage@example.org' && $voAlt === null && !isset($_SESSION[SITZUNG_SCHLUESSEL_ANM_VORLAGE]), 'Vorlage nach 29 min da, nach 31 min weg (Zeitstempel im Sitzungseintrag)');
    $_SESSION[SITZUNG_SCHLUESSEL_ANM_VORLAGE] = $voP;
    pruefe(anm_vorlage() === null, 'alter Sitzungseintrag ohne Zeitstempel gilt nicht');
    unset($_SESSION[SITZUNG_SCHLUESSEL_ANM_VORLAGE]);
}
