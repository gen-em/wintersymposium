<?php
declare(strict_types=1);

/* Testbereich: Anmeldung (mechanisch aus lauf.php ausgelagert, noch nicht auf test_frisch umgestellt). */

function test_anmeldung(): void
{
    abschnitt('Anmeldung: Beispieldaten und Regeln (AP-4)');
    $tid = function (string $k, string $b): int {
        foreach (anm_workshops(true) as $w) {
            foreach ($w['termine'] as $t) {
                if ($w['kuerzel'] === $k && $t['beginn'] === $b) {
                    return (int)$t['id'];
                }
            }
        }
        return 0;
    };
    $wid = fn(string $k) => (int)array_values(array_filter(anm_workshops(), fn($w) => $w['kuerzel'] === $k))[0]['id'];
    $an = $bd['anmeldungen'];
    $voll = $tid('WS 02', '20:30');
    pruefe(anm_laden($an['lea'])['status'] === 'bestaetigt' && anm_laden($an['tom'])['status'] === 'unbestaetigt', 'Beispieldaten: bestätigt und unbestätigt');
    $paulaAngebot = anm_angebote($an['paula']);
    pruefe(count($paulaAngebot) === 1 && (int)$paulaAngebot[0]['termin_id'] === $voll && anm_belegt($voll) === 2 && anm_frei($voll) === 0, 'Storno → Angebot an die erste der Warteliste, Platz bleibt reserviert');
    pruefe(count(anm_tausch($an['paula'], $paulaAngebot[0])) === 1, 'Angebot überschneidet sich mit festem Termin → Tausch angekündigt');
    pruefe(efn_gueltig('802760012345673') && !efn_gueltig('802760012345674') && !efn_gueltig('80276001234567'), 'EFN: 15 Ziffern, Prüfziffer nach Luhn');
    $d = ['vorname' => 'A', 'nachname' => 'B', 'email' => 'a@example.org', 'berufsgruppe' => 'StudentIn', 'institution' => 'X', 'efn' => '123', 'essen' => 'egal'];
    $r = anm_daten_pruefen($d);
    pruefe($r['fehler'] === [] && $r['werte']['efn'] === '', 'EFN nur bei ÄrztInnen (sonst verworfen)');
    $r = anm_daten_pruefen(['berufsgruppe' => 'ÄrztIn'] + $d);
    pruefe(isset($r['fehler']['efn']), 'falsche EFN bei ÄrztIn abgelehnt');
    $r = anm_daten_pruefen(['vorname' => '', 'email' => 'kaputt', 'berufsgruppe' => 'Pilot', 'institution' => '', 'essen' => 'viel'] + $d);
    pruefe(count($r['fehler']) === 5, 'Pflichtfelder, Adresse, Berufsgruppe aus der Liste, Essen');
    $f = anm_wahl_pruefen(['fest' => [$wid('WS 01') => $tid('WS 01', '19:30'), $wid('WS 02') => $tid('WS 02', '19:30')], 'warte' => []]);
    pruefe(count($f) === 1 && str_contains($f[0], 'überschneiden'), 'Überschneidende feste Termine abgelehnt (E-20)');
    pruefe(anm_wahl_pruefen(['fest' => [$wid('WS 02') => $voll], 'warte' => []]) !== [], 'voller Termin nicht als fester Platz');
    pruefe(anm_wahl_pruefen(['fest' => [], 'warte' => [$tid('WS 01', '19:30')]]) !== [], 'Warteliste nur für volle Termine');
    pruefe(anm_wahl_pruefen(['fest' => [$wid('WS 01') => $tid('WS 01', '19:30')], 'warte' => [$voll]]) === [], 'Warteliste darf sich mit festem Termin überschneiden (E-42)');
    einstellung_setzen('workshop_limit', '1');
    pruefe(anm_wahl_pruefen(['fest' => [$wid('WS 01') => $tid('WS 01', '19:30')], 'warte' => [$voll]]) !== [], 'Limit zählt feste Plätze und Wartelisten');
    einstellung_setzen('workshop_limit', '0');
    einstellung_setzen('workshop_pflicht', 'pflicht');
    pruefe(anm_wahl_pruefen(['fest' => [], 'warte' => []]) !== [], 'Workshop-Pflicht');
    einstellung_setzen('workshop_pflicht', 'freiwillig');
    $neu = fn(string $vn, string $mail, array $fest = [], array $warte = []) => anm_anlegen(anm_daten_pruefen(['vorname' => $vn, 'email' => $mail] + $d)['werte'], ['fest' => $fest, 'warte' => $warte], false);
    pruefe($neu('Doppelt', 'lea@example.org')['art'] === 'vorhanden', 'gleiche Adresse: keine zweite Anmeldung (E-46)');

    abschnitt('Anmeldung: Bestätigen, Links, Tausch, Nachrücken (AP-4)');
    $mails0 = (int)db_wert('SELECT COUNT(*) FROM mail_ausgang');
    $kim = $neu('Kim', 'kim@example.org');
    pruefe(anm_laden($kim['id'])['status'] === 'unbestaetigt' && anm_symposium_status($kim['id']) === 'fest', 'neue Anmeldung unbestätigt, Platz reserviert');
    $m = db_zeile('SELECT * FROM mail_ausgang ORDER BY id DESC LIMIT 1');
    preg_match('~/link\?t=([A-Za-z0-9_-]{43})~', (string)$m['text'], $mm);
    pruefe((int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === $mails0 + 1 && $m['an'] === 'kim@example.org' && isset($mm[1]), 'Bestätigungsmail mit Link in der Warteschlange');
    pruefe(db_wert('SELECT COUNT(*) FROM link WHERE token_hash = ?', [hash('sha256', $mm[1] ?? '')]) == 1 && !str_contains((string)db_wert('SELECT token_hash FROM link WHERE art = "anmeldung" ORDER BY id DESC LIMIT 1'), $mm[1] ?? 'x'), 'Link nur als Hash gespeichert');
    $r = anm_link_einloesen($mm[1] ?? '');
    pruefe($r !== null && anm_laden($kim['id'])['status'] === 'bestaetigt', 'Link bestätigt die Anmeldung');
    pruefe(anm_link_einloesen($mm[1] ?? '') === null, 'Link gilt nur einmal');
    $alt = anm_link_erzeugen($kim['id'], 'zugang', time() - 10);
    pruefe(anm_link_einloesen($alt) === null, 'abgelaufener Link gilt nicht');
    // Kim auf die Warteliste des vollen Termins, Ute (unbestätigt) davor
    $ute = $neu('Ute', 'ute@example.org', [], [$voll]);
    db_ausfuehren("UPDATE platz SET seit = '2000-01-01 00:00:00' WHERE anmeldung_id = ? AND termin_id = ?", [$ute['id'], $voll]);
    pruefe(anm_workshops_setzen($kim['id'], ['fest' => [], 'warte' => [$voll]]) === [], 'Workshops ändern: Warteliste');
    $r = anm_angebot_annehmen($an['paula'], (int)$paulaAngebot[0]['id'], false);
    pruefe(isset($r['tausch']) && count($r['tausch']) === 1, 'Annehmen mit Tausch verlangt Bestätigung (E-38)');
    $r = anm_angebot_annehmen($an['paula'], (int)$paulaAngebot[0]['id'], true);
    $pp = array_column(anm_plaetze($an['paula']), 'status', 'termin_id');
    pruefe(isset($r['ok']) && ($pp[$voll] ?? '') === 'fest' && !isset($pp[$tid('WS 01', '20:30')]), 'Tausch: neuer Platz fest, alter storniert');
    pruefe(anm_stornieren($an['lea']), 'Storno');
    $kimP = array_column(anm_plaetze($kim['id']), 'status', 'termin_id');
    $uteP = array_column(anm_plaetze($ute['id']), 'status', 'termin_id');
    pruefe(($kimP[$voll] ?? '') === 'angebot' && ($uteP[$voll] ?? '') === 'warte', 'Nachrücken überspringt Unbestätigte (Ute vor Kim)');
    pruefe(db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE an = 'kim@example.org' AND betreff LIKE 'Platzangebot%'") == 1, 'Angebotsmail an Kim');
    $spaeter = time() + 49 * 3600;
    $j = anm_job($spaeter);
    pruefe($j['verfallen'] >= 1 && anm_laden($ute['id']) === null && anm_laden($an['tom']) === null, 'Job: unbestätigte nach der Frist gelöscht');
    pruefe($j['abgelaufen'] === 1 && db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE an = 'kim@example.org' AND betreff LIKE 'Angebot abgelaufen%'") == 1, 'Job: abgelaufenes Angebot, Mail an Kim');
    $kimNeu = db_zeile('SELECT * FROM platz WHERE anmeldung_id = ? AND termin_id = ?', [$kim['id'], $voll]);
    pruefe($kimNeu['status'] === 'angebot' && strtotime($kimNeu['angebot_bis'] . ' UTC') > $spaeter, 'Kim ist wieder dran (einzige auf der Liste), neue Frist');
    pruefe(anm_angebot_ablehnen($kim['id'], (int)$kimNeu['id']) && anm_frei($voll) === 1, 'Ablehnen gibt den Platz frei');
    $datum = va('veranstaltung_datum');
    einstellung_setzen('veranstaltung_datum', gmdate('Y-m-d', time() + 10 * 86400));
    pruefe(anm_workshops_setzen($kim['id'], ['fest' => [], 'warte' => []]) === [] && anm_sperre(fn() => anm_nachruecken(0)) === 0, 'Nach der Grenze keine automatischen Angebote (E-19)');
    einstellung_setzen('veranstaltung_datum', $datum);

    abschnitt('Anmeldung: Kapazität und Paralleltest (T-137)');
    einstellung_setzen('veranstaltung_kapazitaet', (string)(anm_belegt(0)));
    $w = $neu('Wartend', 'warte@example.org', [$wid('WS 01') => $tid('WS 01', '19:30')]);
    pruefe($w['warteliste'] === true && count(anm_plaetze($w['id'])) === 1, 'Symposium voll: Warteliste, noch keine Workshops');
    einstellung_setzen('veranstaltung_kapazitaet', (string)(anm_belegt(0) + 1));
    db_ausfuehren('DELETE FROM platz WHERE termin_id = 0 AND status = "warte"');
    $vorher = anm_belegt(0);
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
                    file_put_contents("$tmp/parallel-fehler-$i.txt", $ex->getMessage());
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
        pruefe($neuFest === 1 && $neuWarte === 19 && glob("$tmp/parallel-fehler-*") === [], "20 gleichzeitige Anmeldungen auf 1 freien Platz: genau 1 Platz, 19 Warteliste ($neuFest/$neuWarte)");
    } else {
        echo "  --    Paralleltest übersprungen: pcntl fehlt\n";
    }
    einstellung_setzen('veranstaltung_kapazitaet', '240');
    $q = mail_senden_faellige(500, 30.0);
    pruefe($q['gesendet'] > 0 && $q['fehler'] === 0 && (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE status = 'gesendet' AND (an <> '' OR text <> '')") === 0, 'Versand (Datei-Modus): Empfänger und Text danach geleert (R-02)');
}
