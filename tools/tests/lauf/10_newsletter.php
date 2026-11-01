<?php
declare(strict_types=1);

/* Testbereich: Newsletter – Double-Opt-in, Versand an Listen mit Abmeldelink, Abmelden, Verwalten, Aufräumen, Häkchen bei der Anmeldung, Export (AP-7). */

function test_newsletter(): void
{
    abschnitt('Newsletter (AP-7)');
    test_frisch();
    $ls = array_column(nl_listen(), 'id', 'name');
    $lw = (int)$ls['Wintersymposium'];
    $la = (int)$ls['Allgäu Akut'];
    pruefe(nl_liste_dachmarke()['name'] === 'Allgäu Akut' && (int)nl_liste((int)$ls['Referierende (intern)'])['sichtbar'] === 0, '„Allgäu Akut“ ist die Dachmarke, interne Liste ausgeblendet');
    $nn = array_column(nl_empfaenger([$lw, $la]), 'email');
    sort($nn);
    pruefe($nn === ['nina@example.org', 'otto@example.org'], 'Beispieldaten: bestätigt bekommen Mails, Pia (unbestätigt) und Lea (andere Liste) nicht, Nina (zwei Listen) einmal');
    nl_anfragen('Quinn@Example.org', 'Quinn', [$lw, 999], 'formular');
    $qm = (string)(test_mail_an('quinn@example.org')['text'] ?? '');
    $qtok = preg_match('#/newsletter/bestaetigen\?t=([A-Za-z0-9_-]{43})#', $qm, $m) === 1 ? $m[1] : '';
    $qp = nl_person_per_email('quinn@example.org');
    pruefe($qtok !== '' && $qp !== null && nl_abos((int)$qp['id']) === [$lw => 'offen'] && str_contains($qm, NL_EINWILLIGUNG), 'Anfrage: offen, Bestätigungsmail mit Einwilligungstext, unbekannte Liste ignoriert');
    pruefe(!in_array('quinn@example.org', array_column(nl_empfaenger([$lw]), 'email'), true), 'ohne Bestätigung kein Versand (Abnahme AP-7)');
    pruefe(nl_bestaetigen($qtok) !== null && nl_abos((int)$qp['id']) === [$lw => 'bestaetigt'] && db_wert('SELECT fassung FROM newsletter_abo WHERE person_id = ?', [$qp['id']]) === nl_fassung() && db_wert('SELECT text FROM newsletter_fassung WHERE fassung = ?', [nl_fassung()]) === NL_EINWILLIGUNG, 'Bestätigt mit Zeitpunkt und Fassung (Wortlaut gespeichert)');
    pruefe(nl_bestaetigen($qtok) === null, 'Bestätigungslink gilt einmal');
    nl_anfragen('quinn@example.org', '', [$lw], 'formular');
    pruefe(str_contains((string)test_mail_an('quinn@example.org')['text'], '/newsletter/verwalten?t=') && nl_abos((int)$qp['id']) === [$lw => 'bestaetigt'], 'schon eingetragen: Mail mit Link zum Verwalten, Abo unverändert');
    // Versand an Listen
    $nlw = rm_pruefen(['art' => 'newsletter', 'listen' => [$lw, $la], 'betreff' => 'Neu {vorname}', 'html' => '<p>Hallo {vorname},</p><p>Text</p>', 'postfach' => 'newsletter']);
    pruefe($nlw['fehler'] === [] && $nlw['werte']['auswahl'] === 'nl:' . min($lw, $la) . ',' . max($lw, $la) && rm_auswahl_text($nlw['werte']['auswahl'], '') === 'Newsletter: Wintersymposium, Allgäu Akut', 'Mailing an zwei Listen');
    pruefe(array_keys(rm_pruefen(['art' => 'newsletter', 'listen' => [], 'betreff' => 'x', 'html' => '<p>x</p>'])['fehler']) === ['auswahl'], 'ohne Liste: Fehler');
    $nrm = rm_speichern(null, $nlw['werte'], null);
    db_ausfuehren('DELETE FROM mail_ausgang');
    $anz = rm_einreihen($nrm, null);
    pruefe($anz === 3 && (int)db_wert('SELECT COUNT(*) FROM mail_ausgang WHERE nl_person_id IS NOT NULL') === $anz && rm_laden($nrm)['loeschen_ab'] === (new DateTimeImmutable(gmdate('Y-m-d')))->modify('+14 months')->format('Y-m-d'), 'eingereiht, jede Person einmal, Archiv 14 Monate ab Versand');
    nl_abmelden((int)nl_person_per_email('quinn@example.org')['id']);
    $vorher = count(glob(laufzeit_pfad('mails') . '/*.eml') ?: []);
    mail_senden_faellige(50, 20.0);
    $nachher = glob(laufzeit_pfad('mails') . '/*.eml') ?: [];
    sort($nachher);
    $otto = '';
    $quinn = false;
    $nina = false;
    foreach (array_slice($nachher, $vorher) as $d) {
        $t = (string)file_get_contents($d);
        $otto = str_contains($t, 'To: <otto@example.org>') ? $t : $otto;
        $quinn = $quinn || str_contains($t, 'To: <quinn@example.org>');
        $nina = $nina || str_contains($t, 'To: <nina@example.org>');
    }
    $od = quoted_printable_decode($otto);
    pruefe($otto !== '' && $nina && !$quinn, 'Mail an Abonnierende, nicht an inzwischen Abgemeldete');
    pruefe(preg_match('#^List-Unsubscribe: <https?://[^>]+/newsletter/abmelden\?t=[A-Za-z0-9_-]{43}>#m', $otto) === 1 && str_contains($otto, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'), 'List-Unsubscribe mit One-Click');
    pruefe(str_contains($od, 'Hallo,') && !str_contains($od, 'Hallo ,') && str_contains($od, 'Abmelden oder Listen ändern') && str_contains($od, '/newsletter/verwalten?t='), 'ohne Vorname „Hallo,“, Abmeldelink im Fuß');
    pruefe(str_contains($otto, 'From: =?UTF-8?Q?Allg=C3=A4u=20Akut?='), 'Absendername der Liste');
    // Abmelden, Statistik, Verwalten, Aufräumen
    $op = (int)nl_person_per_email('otto@example.org')['id'];
    $vor = nl_zahlen()[$la]['abgemeldet'];
    pruefe(nl_abmelden($op) === 1 && nl_person($op) === null && nl_zahlen()[$la]['abgemeldet'] === $vor + 1, 'Abmelden: Person gelöscht, nur die Zahl bleibt');
    $np = (int)nl_person_per_email('nina@example.org')['id'];
    nl_listen_setzen($np, [$la], 'verwalten');
    pruefe(nl_abos($np) === [$la => 'bestaetigt'], 'Verwalten: abgewählte Liste abgemeldet');
    $pia = (int)nl_person_per_email('pia@example.org')['id'];
    nl_aufraeumen(time() + 8 * 86400);
    pruefe(nl_person($pia) === null && nl_person($np) !== null, 'Unbestätigte nach 7 Tagen gelöscht, Bestätigte bleiben');
    // Zwei Häkchen bei der Anmeldung (AP-19): nach der Bestätigung eine gemeinsame Mail (O-54)
    $rw = anm_daten_pruefen(['vorname' => 'Rosa', 'nachname' => 'Rundbrief', 'email' => 'rosa@example.org', 'berufsgruppe' => 'StudentIn', 'beruf_sonstige' => '', 'institution' => 'Uni', 'efn' => '', 'essen' => 'egal'])['werte'];
    $nlAnm = anm_anlegen($rw + ['nl_interessen' => 'klinik'], ['fest' => [], 'warte' => []], true);
    pruefe(nl_person_per_email('rosa@example.org') === null, 'Häkchen: vor der Bestätigung der Anmeldung nichts');
    anm_link_einloesen(anm_link_erzeugen($nlAnm['id'], 'bestaetigen', time() + 3600));
    $rp = nl_person_per_email('rosa@example.org');
    $ra = $rp !== null ? nl_abos((int)$rp['id']) : [];
    ksort($ra);
    $erwartet = [$lw => 'offen', $la => 'offen'];
    ksort($erwartet);
    $rm = (string)(test_mail_an('rosa@example.org')['text'] ?? '');
    pruefe($ra === $erwartet && str_contains($rm, '/newsletter/bestaetigen?t=') && str_contains($rm, 'Wintersymposium, Allgäu Akut (Klinik)') && db_zahl("SELECT COUNT(*) FROM mail_ausgang WHERE an = 'rosa@example.org' AND text LIKE '%/newsletter/bestaetigen?t=%'") === 1,
        'nach der Bestätigung: eine Bestätigungsmail für Reihe und Allgäu Akut mit Interesse');
    // Export
    $ex = export_erzeugen('newsletter', '', $la);
    pruefe($ex !== null && str_contains($ex['text'], 'nina@example.org') && !str_contains($ex['text'], 'rosa@example.org') && str_contains($ex['text'], nl_fassung()), 'CSV je Liste: nur bestätigte, mit Fassung');
}
