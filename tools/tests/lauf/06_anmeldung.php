<?php
declare(strict_types=1);

/* Testbereich: Anmeldung – Datenprüfung, Workshop-Wahl, Bestätigen und Links, Tausch, Nachrücken, Kapazität mit Paralleltest und Mailversand. */

function test_anmeldung(): void
{
    abschnitt('Anmeldung: Regeln (AP-4)');
    // Beispieldaten: Lea (WS 01 19:30, WS 02 20:30), Paula (WS 01 20:30, Angebot WS 02 20:30), Tom unbestätigt, Max storniert
    $an = test_frisch()['bd']['anmeldungen'];
    $voll = test_termin('WS 02', '20:30');
    $ws1 = test_workshop('WS 01');
    $ws2 = test_workshop('WS 02');
    $ws1Frueh = test_termin('WS 01', '19:30');
    $paulaAngebot = anm_angebote($an['paula']);
    pruefe(count($paulaAngebot) === 1 && (int)$paulaAngebot[0]['termin_id'] === $voll && anm_belegt($voll) === 2 && anm_frei($voll) === 0, 'Storno → Angebot an die erste der Warteliste, Platz bleibt reserviert');
    pruefe(count(anm_tausch($an['paula'], $paulaAngebot[0])) === 1, 'Angebot überschneidet sich mit festem Termin → Tausch angekündigt');
    pruefe(efn_gueltig('802760012345673') && !efn_gueltig('802760012345674') && !efn_gueltig('80276001234567'), 'EFN: 15 Ziffern, Prüfziffer nach Luhn');
    $d = ['vorname' => 'A', 'nachname' => 'B', 'email' => 'a@example.org', 'berufsgruppe' => 'StudentIn', 'institution' => 'X', 'efn' => '123', 'essen' => 'egal'];
    $r = anm_daten_pruefen($d);
    pruefe($r['fehler'] === [] && $r['werte']['efn'] === '', 'EFN nur bei ÄrztInnen (sonst verworfen)');
    $r = anm_daten_pruefen(['berufsgruppe' => 'ÄrztIn'] + $d);
    pruefe(array_keys($r['fehler']) === ['efn'], 'falsche EFN bei ÄrztIn abgelehnt');
    $r = anm_daten_pruefen(['vorname' => '', 'email' => 'kaputt', 'berufsgruppe' => 'Pilot', 'institution' => '', 'essen' => 'viel'] + $d);
    $felder = array_keys($r['fehler']);
    sort($felder);
    pruefe($felder === ['berufsgruppe', 'email', 'essen', 'institution', 'vorname'], 'Pflichtfelder, Adresse, Berufsgruppe aus der Liste, Essen');
    $f = anm_wahl_pruefen(['fest' => [$ws1 => $ws1Frueh, $ws2 => test_termin('WS 02', '19:30')], 'warte' => []]);
    pruefe(count($f) === 1 && str_contains($f[0], 'überschneiden'), 'Überschneidende feste Termine abgelehnt (E-20)');
    $f = anm_wahl_pruefen(['fest' => [$ws2 => $voll], 'warte' => []]);
    pruefe(count($f) === 1 && str_contains($f[0], 'voll'), 'voller Termin nicht als fester Platz');
    $f = anm_wahl_pruefen(['fest' => [], 'warte' => [$ws1Frueh]]);
    pruefe(count($f) === 1 && str_contains($f[0], 'freie Plätze'), 'Warteliste nur für volle Termine');
    pruefe(anm_wahl_pruefen(['fest' => [$ws1 => $ws1Frueh], 'warte' => [$voll]]) === [], 'Warteliste darf sich mit festem Termin überschneiden (E-42)');
    einstellung_setzen('workshop_limit', '1');
    $f = anm_wahl_pruefen(['fest' => [$ws1 => $ws1Frueh], 'warte' => [$voll]]);
    pruefe(count($f) === 1 && str_contains($f[0], 'höchstens 1 Workshop'), 'Limit zählt feste Plätze und Wartelisten');
    einstellung_setzen('workshop_limit', '0');
    einstellung_setzen('workshop_pflicht', 'pflicht');
    pruefe(count(anm_wahl_pruefen(['fest' => [], 'warte' => []])) === 1, 'Workshop-Pflicht');
    einstellung_setzen('workshop_pflicht', 'freiwillig');
    // Anlegen selbst wird ab hier geprüft (Bestätigungsmail, Warteliste, Parallelität)
    $neu = fn(string $vn, string $mail, array $fest = [], array $warte = []) => anm_anlegen(anm_daten_pruefen(['vorname' => $vn, 'email' => $mail] + $d)['werte'], ['fest' => $fest, 'warte' => $warte], false);
    pruefe($neu('Doppelt', 'Lea@example.org')['art'] === 'vorhanden' && (int)db_wert("SELECT COUNT(*) FROM anmeldung WHERE email = 'lea@example.org' AND veranstaltung_id = ?", [va_id()]) === 1, 'gleiche Adresse: keine zweite Anmeldung (E-46)');

    abschnitt('Anmeldung: Bestätigen, Links, Tausch, Nachrücken (AP-4)');
    $mails0 = (int)db_wert('SELECT COUNT(*) FROM mail_ausgang');
    $kim = $neu('Kim', 'kim@example.org');
    pruefe($kim['art'] === 'neu' && anm_laden($kim['id'])['status'] === 'unbestaetigt' && anm_symposium_status($kim['id']) === 'fest', 'neue Anmeldung unbestätigt, Platz reserviert');
    $tok = test_link_aus_mail('kim@example.org');
    pruefe((int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === $mails0 + 1 && $tok !== '', 'Bestätigungsmail mit Link in der Warteschlange');
    pruefe(db_wert('SELECT COUNT(*) FROM link WHERE token_hash = ?', [hash('sha256', $tok)]) == 1, 'Link nur als Hash gespeichert');
    pruefe(anm_link_einloesen($tok) !== null && anm_laden($kim['id'])['status'] === 'bestaetigt', 'Link bestätigt die Anmeldung');
    pruefe(anm_link_einloesen($tok) === null, 'Link gilt nur einmal');
    $alt = anm_link_erzeugen($kim['id'], 'zugang', time() - 10);
    pruefe(anm_link_einloesen($alt) === null, 'abgelaufener Link gilt nicht');
    // Kim auf die Warteliste des vollen Termins, Tom (unbestätigt) steht schon länger davor
    pruefe(anm_workshops_setzen($an['tom'], ['fest' => [], 'warte' => [$voll]]) === [], 'Workshops ändern: Warteliste (auch unbestätigt)');
    db_ausfuehren("UPDATE platz SET seit = '2000-01-01 00:00:00' WHERE anmeldung_id = ? AND termin_id = ?", [$an['tom'], $voll]);
    pruefe(anm_workshops_setzen($kim['id'], ['fest' => [], 'warte' => [$voll]]) === [], 'Workshops ändern: Warteliste');
    $r = anm_angebot_annehmen($an['paula'], (int)$paulaAngebot[0]['id'], false);
    pruefe(isset($r['tausch']) && count($r['tausch']) === 1 && !isset($r['ok']), 'Annehmen mit Tausch verlangt Bestätigung (E-38)');
    $r = anm_angebot_annehmen($an['paula'], (int)$paulaAngebot[0]['id'], true);
    $pp = array_column(anm_plaetze($an['paula']), 'status', 'termin_id');
    pruefe(isset($r['ok']) && ($pp[$voll] ?? '') === 'fest' && !isset($pp[test_termin('WS 01', '20:30')]), 'Tausch: neuer Platz fest, alter storniert');
    pruefe(anm_stornieren($an['lea']) && anm_laden($an['lea']) === null && str_contains((string)(test_mail_an('lea@example.org')['betreff'] ?? ''), 'storniert'), 'Storno: Zeile gelöscht (R-02), Mail an Lea');
    $kimP = array_column(anm_plaetze($kim['id']), 'status', 'termin_id');
    $tomP = array_column(anm_plaetze($an['tom']), 'status', 'termin_id');
    pruefe(($kimP[$voll] ?? '') === 'angebot' && ($tomP[$voll] ?? '') === 'warte', 'Nachrücken überspringt Unbestätigte (Tom vor Kim)');
    pruefe(db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE an = 'kim@example.org' AND betreff LIKE 'Platzangebot%'") == 1, 'Angebotsmail an Kim');
    $spaeter = time() + 49 * 3600;
    $j = anm_job($spaeter);
    // unbestätigt sind Tom und die untergeschobene Anmeldung mit Adas Adresse (Beispieldaten Review 2)
    pruefe($j['verfallen'] === 2 && anm_laden($an['tom']) === null, 'Job: unbestätigte nach der Frist gelöscht');
    pruefe($j['abgelaufen'] === 1 && db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE an = 'kim@example.org' AND betreff LIKE 'Angebot abgelaufen%'") == 1, 'Job: abgelaufenes Angebot, Mail an Kim');
    $kimNeu = db_zeile('SELECT * FROM platz WHERE anmeldung_id = ? AND termin_id = ?', [$kim['id'], $voll]);
    pruefe($kimNeu['status'] === 'angebot' && db_ts($kimNeu['angebot_bis']) > $spaeter, 'Kim ist wieder dran (einzige auf der Liste), neue Frist');
    pruefe(anm_angebot_ablehnen($kim['id'], (int)$kimNeu['id']) && anm_frei($voll) === 1, 'Ablehnen gibt den Platz frei');
    $datum = va('veranstaltung_datum');
    einstellung_setzen('veranstaltung_datum', gmdate('Y-m-d', time() + 10 * 86400));
    pruefe(anm_workshops_setzen($kim['id'], ['fest' => [], 'warte' => []]) === [] && anm_sperre(fn() => anm_nachruecken(0)) === 0, 'Nach der Grenze keine automatischen Angebote (E-19)');
    einstellung_setzen('veranstaltung_datum', $datum);

    abschnitt('Anmeldung: Kapazität und Paralleltest (T-137)');
    einstellung_setzen('veranstaltung_kapazitaet', (string)anm_belegt(0));
    $w = $neu('Wartend', 'warte@example.org', [$ws1 => $ws1Frueh]);
    pruefe($w['warteliste'] === true && array_column(anm_plaetze($w['id']), 'status', 'termin_id') === [0 => 'warte'], 'Symposium voll: Warteliste, noch keine Workshops');
    einstellung_setzen('veranstaltung_kapazitaet', (string)(anm_belegt(0) + 1));
    db_ausfuehren('DELETE FROM platz WHERE termin_id = 0 AND status = "warte"');
    $vorher = anm_belegt(0);
    $ordner = laufzeit_ordner();
    $kinder = [];
    if (function_exists('pcntl_fork')) {
        $start = microtime(true) + 1.0;
        for ($i = 0; $i < 20; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                db(true);
                while (microtime(true) < $start) {
                    usleep(1000);
                }
                try {
                    anm_anlegen(anm_daten_pruefen(['vorname' => "P$i", 'email' => "parallel$i@example.org"] + $d)['werte'], ['fest' => [], 'warte' => []], false);
                } catch (Throwable $ex) {
                    file_put_contents("$ordner/parallel-fehler-$i.txt", $ex->getMessage());
                }
                posix_kill(posix_getpid(), SIGKILL);
            }
            $kinder[] = $pid;
        }
        foreach ($kinder as $pid) {
            pcntl_waitpid($pid, $status);
        }
        db(true);
        $neuFest = anm_belegt(0) - $vorher;
        $neuWarte = (int)db_wert("SELECT COUNT(*) FROM platz p JOIN anmeldung a ON a.id = p.anmeldung_id WHERE p.termin_id = 0 AND p.status = 'warte' AND a.email LIKE 'parallel%'");
        pruefe($neuFest === 1 && $neuWarte === 19 && glob("$ordner/parallel-fehler-*") === [], "20 gleichzeitige Anmeldungen auf 1 freien Platz: genau 1 Platz, 19 Warteliste ($neuFest/$neuWarte)");
    } else {
        echo "  --    Paralleltest übersprungen: pcntl fehlt\n";
    }
    $offen = (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE status = 'offen'");
    $q = mail_senden_faellige(500, 30.0);
    pruefe($offen > 0 && $q['gesendet'] === $offen && $q['fehler'] === 0 && (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE status = 'gesendet' AND (an <> '' OR text <> '')") === 0, 'Versand (Datei-Modus): alle offenen gesendet, Empfänger und Text danach geleert (R-02)');
}
