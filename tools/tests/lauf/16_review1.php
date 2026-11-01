<?php
declare(strict_types=1);

/* Testbereich: Review-Behebung 1 (AP-14) – Adressen von Team-Konten, Newsletter-Versand, Warte-Einträge, Ratenschutz für Adressänderungen, Vorlage „Daten übernehmen“. */

/** Angaben einer Anmeldung als Formulareingabe, einzelne Felder geändert. */
function test_review1_angaben(int $anmId, array $neu): array
{
    $a = anm_laden($anmId);
    $e = [];
    foreach (['vorname', 'nachname', 'email', 'berufsgruppe', 'beruf_sonstige', 'institution', 'efn', 'essen'] as $k) {
        $e[$k] = (string)$a[$k];
    }
    return $neu + $e;
}

function test_review1(): void
{
    abschnitt('Review-Behebung 1 (AP-14, T-275 bis T-281)');
    $f = test_frisch(['warteliste' => true]);
    $an = $f['bd']['anmeldungen'];
    $angaben = 'test_review1_angaben';

    // Unterpunkt 1 (S-1, T-275): Adresse eines Team-Kontos nicht über eine Anmeldung
    $ta = beispieldaten_team_anmeldung();
    [$bId, $bAnm, $bMail] = [$ta['konto'], $ta['anmeldung'], konto_laden($ta['konto'])['email']];
    $fe = team_anm_daten($bAnm, $angaben($bAnm, ['email' => 'angreifer@example.org']), false);
    pruefe($fe === ['email' => ANM_TEAM_ADRESSE_TEXT] && konto_laden($bId)['email'] === $bMail && anm_laden($bAnm)['email'] === $bMail, '(a) Team ändert Adresse einer Anmeldung mit Team-Konto: abgelehnt, beides unverändert');
    $kontoName = konto_laden($bId)['name'];
    pruefe(team_anm_daten($bAnm, $angaben($bAnm, ['vorname' => 'Geändert']), false) === [] && anm_laden($bAnm)['vorname'] === 'Geändert' && konto_laden($bId)['name'] === $kontoName, 'übrige Felder änderbar, Team-Konto zieht nicht mit');
    $r = anm_daten_setzen($bAnm, $angaben($bAnm, ['email' => 'privat@example.org']));
    pruefe($r['fehler'] === ['email' => ANM_TEAM_ADRESSE_TEXT] && !$r['email_link'] && (int)db_wert("SELECT COUNT(*) FROM link WHERE art = 'anmeldung' AND anmeldung_id = ? AND zweck = 'email'", [$bAnm]) === 0 && (int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === 0, '(b) Team-Mitglied ändert eigene Adresse über Meine Anmeldung: abgelehnt, kein Link, keine Mail');
    $e = anm_link_einloesen(anm_link_erzeugen($bAnm, 'email', time() + 3600, 'privat@example.org'));
    pruefe($e !== null && str_contains((string)$e['meldung'], 'Team-Kontos') && konto_laden($bId)['email'] === $bMail && anm_laden($bAnm)['email'] === $bMail, 'zweite Sperre: alter E-Mail-Link schreibt nicht ins Team-Konto');
    // Lea: Anmeldung mit Teilnehmenden-Konto
    [$lea, $leaKonto] = [$an['lea'], $an['lea_konto']];
    $fe = team_anm_daten($lea, $angaben($lea, ['email' => 'lea.neu@example.org']), false);
    pruefe($fe === [] && anm_laden($lea)['email'] === 'lea.neu@example.org' && konto_laden($leaKonto)['email'] === 'lea.neu@example.org', '(c) Teilnehmenden-Konto: Adresse in Anmeldung und Konto geändert');
    $hin = test_mail_an('lea@example.org');
    pruefe($hin !== null && str_contains($hin['text'], 'Adresse deiner Anmeldung und deines Kontos geändert') && !str_contains($hin['text'], 'lea.neu@'), 'Hinweis-Mail an die alte Adresse (ohne neue Adresse)');
    $au = db_zeilen("SELECT text FROM audit WHERE art = 'konto' AND objekt_id = ?", [$leaKonto]);
    $auTexte = array_column($au, 'text');
    pruefe((array_count_values($auTexte)['Konto ' . $leaKonto . ': Adresse vom Team geändert'] ?? 0) === 1 && array_filter($auTexte, fn($t) => str_contains($t, '@')) === [], 'Protokoll „Konto N: Adresse vom Team geändert“ ohne Adressen');

    // Unterpunkt 2 (B-1, T-276): Newsletter-Mails überleben den Versand nach einer Admin-Aktion
    db_ausfuehren('DELETE FROM mail_ausgang');
    $akut = (int)array_column(nl_listen(), 'id', 'name')['Allgäu Akut'];
    $nlRm = rm_speichern(null, rm_pruefen(['art' => 'newsletter', 'listen' => [$akut], 'betreff' => 'Review', 'html' => '<p>Hallo {vorname}</p>', 'postfach' => 'newsletter'])['werte'], null);
    $nlAnz = rm_einreihen($nlRm, null);
    ctx_setzen('team_rolle', 'admin');
    $nlE = mail_senden_faellige(5, 20.0);
    pruefe($nlAnz === 2 && $nlE['gesendet'] === 2 && (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE rundmail_id = ? AND status = 'gesendet'", [$nlRm]) === 2, 'Newsletter an Nina und Otto, Team-Rolle admin: beide gesendet, keine gelöscht');
    $rmFremd = va_mit((int)$f['bd']['vorjahr']['id'], fn() => rm_speichern(null, rm_pruefen(['betreff' => 'Fremd', 'html' => '<p>x</p>', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system'])['werte'], null));
    $rmF = rm_laden($rmFremd);
    pruefe($rmF !== null && !rm_sichtbar($rmF) && !rm_sichtbar(rm_laden($nlRm)), 'Admin: Mailing einer fremden Veranstaltung und Newsletter nicht sichtbar, rm_laden liefert die Zeile');
    ctx_setzen('team_rolle', 'betreiber');
    pruefe(rm_sichtbar(rm_laden($nlRm)), 'Betreiber: Newsletter-Mailing sichtbar');
    ctx_weg('team_rolle');
    db_ausfuehren("INSERT INTO mail_ausgang (an, betreff, text, prio, status, postfach, rundmail_id, anmeldung_id, erstellt_am, naechster_versuch) VALUES ('weg@example.org', '', '', 5, 'offen', 'system', 999999, ?, ?, ?)", [$an['paula'], db_jetzt(), db_jetzt()]);
    mail_senden_faellige(5, 20.0);
    $weg = db_zeile("SELECT status, versuche, fehler FROM mail_ausgang WHERE an = 'weg@example.org'");
    pruefe($weg !== null && $weg['status'] === 'offen' && (int)$weg['versuche'] === 1 && str_contains((string)$weg['fehler'], 'nicht gefunden'), 'Mailing fehlt: Fehler mit Wiederholung statt Löschen');

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
    // Beispieldatensatz: Wanda auf der Warteliste von WS 01 19:30, der Termin hat freie Plätze
    [$wlP, $wlT] = [$f['bd']['warteliste']['wanda'], $f['bd']['warteliste']['termin']];
    $wlSeit = (string)db_wert("SELECT seit FROM platz WHERE anmeldung_id = ? AND termin_id = ? AND status = 'warte'", [$wlP, $wlT]);
    $wlSeitNoch = fn(): ?string => db_wert("SELECT seit FROM platz WHERE anmeldung_id = ? AND termin_id = ? AND status = 'warte'", [$wlP, $wlT]);
    $fw = $formWahl($wlP, true);
    pruefe(str_contains($fw['html'], 'name="wl[]" value="' . $wlT . '" checked'), 'Team-Modus: bestehender Warte-Eintrag als vorangekreuztes Kästchen');
    team_anm_daten($wlP, $angaben($wlP, ['vorname' => 'Wanda-Maria']), false);
    $fe = anm_workshops_setzen($wlP, $fw['wahl'], null, true, false, false);
    pruefe($fe === [] && anm_laden($wlP)['vorname'] === 'Wanda-Maria' && $wlSeitNoch() === $wlSeit, '(a) Team speichert nur den Namen: Warte-Eintrag mit altem „seit“ bleibt');
    $fw = $formWahl($wlP, false);
    pruefe(anm_frei($wlT) > 0 && str_contains($fw['html'], 'fest wählen oder auf der Warteliste bleiben') && str_contains($fw['html'], 'name="wl[]" value="' . $wlT . '" checked'), 'Termin mit freien Plätzen: Warte-Eintrag sichtbar mit Hinweis');
    $fe = anm_workshops_setzen($wlP, $fw['wahl'], null, false, false, false);
    pruefe($fe === [] && $wlSeitNoch() === $wlSeit, '(b) Teilnehmende speichert ohne Änderung: Eintrag bleibt');
    pruefe(anm_wahl_pruefen(['fest' => [], 'warte' => [$wlT]], []) === [anm_termin_text($wlT) . ' hat noch freie Plätze – wähle ihn als festen Termin statt der Warteliste.'], 'neue Warteliste für einen freien Termin bleibt verboten');
    $fe = anm_workshops_setzen($wlP, ['fest' => $fw['wahl']['fest'], 'warte' => []], null, false, false, false);
    pruefe($fe === [] && $wlSeitNoch() === null, '(c) Kästchen abgewählt: Eintrag weg');

    // Unterpunkt 4 (S-2, T-278): Ratenschutz für Adressänderungen (Paula)
    db_ausfuehren('DELETE FROM rate_limit');
    db_ausfuehren('DELETE FROM mail_ausgang');
    $raP = $an['paula'];
    $raErg = [];
    for ($i = 1; $i <= 11; $i++) {
        $raErg[$i] = anm_daten_setzen($raP, $angaben($raP, ['email' => 'ziel' . $i . '@example.org']));
    }
    pruefe($raErg[10]['email_link'] && !$raErg[11]['email_link'] && $raErg[11]['email_gesperrt'] && test_mail_an('ziel10@example.org') !== null && test_mail_an('ziel11@example.org') === null, '11 Adressänderungen auf 11 Zieladressen von einer IP: die 11. gesperrt (link_ip 10/h)');
    pruefe((int)db_wert("SELECT COUNT(*) FROM link WHERE art = 'anmeldung' AND anmeldung_id = ? AND zweck = 'email'", [$raP]) === 1 && db_wert("SELECT JSON_UNQUOTE(JSON_EXTRACT(daten, '$.neue_email')) FROM link WHERE art = 'anmeldung' AND anmeldung_id = ? AND zweck = 'email'", [$raP]) === 'ziel10@example.org', 'nur ein offener E-Mail-Link je Anmeldung (der neueste)');
    $raN = anm_daten_setzen($raP, $angaben($raP, ['vorname' => 'Paulaneu', 'email' => 'ziel12@example.org']));
    pruefe($raN['email_gesperrt'] && $raN['fehler'] === [] && anm_laden($raP)['vorname'] === 'Paulaneu' && anm_laden($raP)['email'] === 'paula@example.org', 'gesperrt: übrige Daten trotzdem gespeichert, Adresse bleibt');
    $raErg = [];
    db_ausfuehren('DELETE FROM rate_limit');
    for ($i = 1; $i <= 4; $i++) {
        $raErg[$i] = anm_daten_setzen($raP, $angaben($raP, ['email' => 'gleich@example.org']));
    }
    pruefe($raErg[3]['email_link'] && !$raErg[4]['email_link'] && $raErg[4]['email_gesperrt'], '4 Änderungen auf dieselbe Zieladresse: die 4. gesperrt (link_ziel 3/h)');
    $raM = (string)(test_mail_an('gleich@example.org')['text'] ?? '');
    pruefe(str_starts_with($raM, "Hallo,\n") && !str_contains($raM, 'Paula'), 'Mail an die neue Adresse ohne Vornamen („Hallo,“)');
    db_ausfuehren('DELETE FROM rate_limit');
    $raKf = [];
    for ($i = 1; $i <= 4; $i++) {
        $raKf[$i] = tk_email_aendern($leaKonto, 'lea.zwei@example.org');
    }
    pruefe($raKf[3] === null && $raKf[4] === ANM_ADRESSE_RATE_TEXT && (int)db_wert("SELECT COUNT(*) FROM link WHERE art = 'konto' AND konto_id = ? AND zweck = 'email'", [$leaKonto]) === 1, 'Konto: Adressänderung mit Ratenschutz, ein offener Link');

    // Unterpunkt 6 (B-13, T-280): Vorlage „Daten übernehmen“ läuft ab (Tom)
    $voP = $an['tom'];
    $_SESSION[SITZUNG_SCHLUESSEL_ANM_VORLAGE] = ['id' => $voP, 'zeit' => time() - 29 * 60];
    $voFrisch = anm_vorlage();
    $_SESSION[SITZUNG_SCHLUESSEL_ANM_VORLAGE] = ['id' => $voP, 'zeit' => time() - 31 * 60];
    $voAlt = anm_vorlage();
    pruefe(($voFrisch['email'] ?? '') === 'tom@example.org' && $voAlt === null && !isset($_SESSION[SITZUNG_SCHLUESSEL_ANM_VORLAGE]), 'Vorlage nach 29 min da, nach 31 min weg (Zeitstempel im Sitzungseintrag)');
    $_SESSION[SITZUNG_SCHLUESSEL_ANM_VORLAGE] = $voP;
    pruefe(anm_vorlage() === null, 'alter Sitzungseintrag ohne Zeitstempel gilt nicht');
    unset($_SESSION[SITZUNG_SCHLUESSEL_ANM_VORLAGE]);
}
