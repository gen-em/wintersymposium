<?php
declare(strict_types=1);

/* Testbereich: Newsletter (mechanisch aus lauf.php ausgelagert, noch nicht auf test_frisch umgestellt). */

function test_newsletter(): void
{
    abschnitt('Newsletter (AP-7)');
    $ls = array_column(nl_listen(), 'id', 'name');
    $lw = (int)$ls['Wintersymposium'];
    $la = (int)$ls['Allgäu Akut'];
    pruefe(count($ls) >= 2 && nl_liste_anmeldung()['name'] === 'Allgäu Akut', 'Zwei Listen, „Allgäu Akut“ bei der Anmeldung angeboten');
    $nn = array_column(nl_empfaenger([$lw, $la]), 'email');
    pruefe(in_array('nina@example.org', $nn, true) && in_array('otto@example.org', $nn, true) && !in_array('pia@example.org', $nn, true) && count($nn) === count(array_unique($nn)), 'Beispieldaten: bestätigt bekommen Mails, Pia (unbestätigt) nicht, jede Person einmal');
    db_ausfuehren('DELETE FROM mail_ausgang');
    nl_anfragen('Quinn@Example.org', 'Quinn', [$lw, 999], 'formular');
    $qm = (string)db_wert("SELECT text FROM mail_ausgang WHERE an = 'quinn@example.org' ORDER BY id DESC LIMIT 1");
    $qtok = preg_match('#/newsletter/bestaetigen\?t=([A-Za-z0-9_-]{43})#', $qm, $m) === 1 ? $m[1] : '';
    $qp = nl_person_per_email('quinn@example.org');
    pruefe($qtok !== '' && $qp !== null && nl_abos((int)$qp['id']) === [$lw => 'offen'] && str_contains($qm, NL_EINWILLIGUNG), 'Anfrage: offen, Bestätigungsmail mit Einwilligungstext, unbekannte Liste ignoriert');
    pruefe(!in_array('quinn@example.org', array_column(nl_empfaenger([$lw]), 'email'), true), 'ohne Bestätigung kein Versand (Abnahme AP-7)');
    pruefe(nl_bestaetigen($qtok) !== null && nl_abos((int)$qp['id']) === [$lw => 'bestaetigt'] && db_wert('SELECT fassung FROM newsletter_abo WHERE person_id = ?', [$qp['id']]) === nl_fassung() && db_wert('SELECT text FROM newsletter_fassung WHERE fassung = ?', [nl_fassung()]) === NL_EINWILLIGUNG, 'Bestätigt mit Zeitpunkt und Fassung (Wortlaut gespeichert)');
    pruefe(nl_bestaetigen($qtok) === null, 'Bestätigungslink gilt einmal');
    nl_anfragen('quinn@example.org', '', [$lw], 'formular');
    pruefe(str_contains((string)db_wert("SELECT text FROM mail_ausgang WHERE an = 'quinn@example.org' ORDER BY id DESC LIMIT 1"), '/newsletter/verwalten?t='), 'schon eingetragen: Mail mit Link zum Verwalten');
    // Versand an Listen
    $nlw = rm_pruefen(['art' => 'newsletter', 'listen' => [$lw, $la], 'betreff' => 'Neu {vorname}', 'html' => '<p>Hallo {vorname},</p><p>Text</p>', 'postfach' => 'newsletter']);
    pruefe($nlw['fehler'] === [] && $nlw['werte']['auswahl'] === 'nl:' . min($lw, $la) . ',' . max($lw, $la) && rm_auswahl_text($nlw['werte']['auswahl'], '') === 'Newsletter: Wintersymposium, Allgäu Akut', 'Mailing an zwei Listen');
    pruefe(rm_pruefen(['art' => 'newsletter', 'listen' => [], 'betreff' => 'x', 'html' => '<p>x</p>'])['fehler'] !== [], 'ohne Liste: Fehler');
    $nrm = rm_speichern(null, $nlw['werte'], null);
    db_ausfuehren('DELETE FROM mail_ausgang');
    $anz = rm_einreihen($nrm, null);
    pruefe($anz === count(nl_empfaenger([$lw, $la])) && (int)db_wert('SELECT COUNT(*) FROM mail_ausgang WHERE nl_person_id IS NOT NULL') === $anz && rm_laden($nrm)['loeschen_ab'] === (new DateTimeImmutable(gmdate('Y-m-d')))->modify('+14 months')->format('Y-m-d'), 'eingereiht, jede Person einmal, Archiv 14 Monate ab Versand');
    nl_abmelden((int)nl_person_per_email('quinn@example.org')['id']);
    $vorher = count(glob(laufzeit_pfad('mails') . '/*.eml') ?: []);
    mail_senden_faellige(50, 20.0);
    $nachher = glob(laufzeit_pfad('mails') . '/*.eml') ?: [];
    sort($nachher);
    $otto = '';
    $quinn = false;
    foreach (array_slice($nachher, $vorher) as $d) {
        $t = (string)file_get_contents($d);
        $otto = str_contains($t, 'To: <otto@example.org>') ? $t : $otto;
        $quinn = $quinn || str_contains($t, 'To: <quinn@example.org>');
    }
    $od = quoted_printable_decode($otto);
    pruefe($otto !== '' && !$quinn, 'Mail an Abonnierende, nicht an inzwischen Abgemeldete');
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
    // Häkchen bei der Anmeldung: nach der Bestätigung eigene Mail
    $nlAnm = anm_anlegen(anm_daten_pruefen(['vorname' => 'Rosa', 'nachname' => 'Rundbrief', 'email' => 'rosa@example.org', 'berufsgruppe' => 'StudentIn', 'beruf_sonstige' => '', 'institution' => 'Uni', 'efn' => '', 'essen' => 'egal'])['werte'], ['fest' => [], 'warte' => []], true);
    pruefe(nl_person_per_email('rosa@example.org') === null, 'Häkchen: vor der Bestätigung der Anmeldung nichts');
    anm_link_einloesen(anm_link_erzeugen($nlAnm['id'], 'bestaetigen', time() + 3600));
    $rp = nl_person_per_email('rosa@example.org');
    pruefe($rp !== null && nl_abos((int)$rp['id']) === [$la => 'offen'] && str_contains((string)db_wert("SELECT text FROM mail_ausgang WHERE an = 'rosa@example.org' ORDER BY id DESC LIMIT 1"), '/newsletter/bestaetigen?t='), 'nach der Bestätigung: eigene Newsletter-Bestätigungsmail');
    // Export
    $ex = export_erzeugen('newsletter', '', $la);
    pruefe($ex !== null && str_contains($ex['text'], 'nina@example.org') && !str_contains($ex['text'], 'rosa@example.org') && str_contains($ex['text'], nl_fassung()), 'CSV je Liste: nur bestätigte, mit Fassung');
}
