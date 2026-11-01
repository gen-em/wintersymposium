<?php
declare(strict_types=1);

/* Testbereich: Status-Seite (mechanisch aus lauf.php ausgelagert, noch nicht auf test_frisch umgestellt). */

function test_status(): void
{
    abschnitt('Status-Seite (AP-15, T-282 bis T-286)');
    $amp = function (string $id, ?int $jetzt = null): string {
        foreach (betrieb_status($jetzt) as $z) {
            if ($z['id'] === $id) {
                return $z['ampel'];
            }
        }
        return '?';
    };
    $st = betrieb_status();
    pruefe(array_column($st, 'id') === ['cron', 'jobs', 'mail', 'datenbank', 'sicherung', 'fehlerlog', 'umgebung'], 'sieben Zeilen in fester Reihenfolge');
    $t0 = time();
    $lauf = fn(int $vor) => einstellung_setzen('jobs_letzter_lauf', gmdate('Y-m-d H:i:s', $t0 - $vor));
    $lauf(5 * 60);
    $a5 = $amp('cron', $t0);
    $lauf(20 * 60);
    $a20 = $amp('cron', $t0);
    $lauf(2 * 3600);
    $a2h = $amp('cron', $t0);
    pruefe($a5 === 'ok' && $a20 === 'warnung' && $a2h === 'fehler', 'Cron: vor 5 min ok, vor 20 min warnung, vor 2 h fehler');
    einstellung_setzen('jobs_letzter_lauf', null);
    pruefe($amp('cron', $t0) === 'fehler', 'Cron: noch nie gelaufen → fehler');
    // Aufgaben auf der Übersicht (Unterpunkt 5): rote Zeile nur für BetreiberInnen
    $aufB = array_column(team_aufgaben($t0, 'betreiber'), 'art');
    $aufA = array_column(team_aufgaben($t0, 'admin'), 'art');
    pruefe(in_array('Betrieb', $aufB, true) && !in_array('Betrieb', $aufA, true), 'Cron rot → Aufgabe „Betrieb“ für Betreiber, nicht für Admin (T-286)');
    $lauf(60);
    $jobsVorher = db_zeilen('SELECT * FROM job');
    foreach (array_keys(jobs_katalog()) as $jn) {
        db_ausfuehren('INSERT INTO job (name, letzter_lauf, letzte_dauer_ms, letzter_fehler, laeufe) VALUES (?, ?, 5, NULL, 1) ON DUPLICATE KEY UPDATE letzter_lauf = VALUES(letzter_lauf), letzter_fehler = NULL', [$jn, db_jetzt()]);
    }
    $jOk = $amp('jobs');
    db_ausfuehren("UPDATE job SET letzter_fehler = 'Test kaputt' WHERE name = 'aufraeumen'");
    pruefe($jOk === 'ok' && $amp('jobs') === 'fehler', 'Jobs: ohne Fehler ok, mit letzter_fehler → fehler');
    db_ausfuehren('DELETE FROM job');
    foreach ($jobsVorher as $jz) {
        db_ausfuehren('INSERT INTO job (' . implode(',', array_keys($jz)) . ') VALUES (' . implode(',', array_fill(0, count($jz), '?')) . ')', array_values($jz));
    }
    $sAuto = sicherung_anlegen(DS_SICHERUNG_AUTOMATISCH);
    pruefe($amp('sicherung', $t0 + 3600) === 'ok' && $amp('sicherung', $t0 + 7 * 3600) === 'warnung' && $amp('sicherung', $t0 + 25 * 3600) === 'fehler', 'Sicherung: 1 h ok, 7 h warnung, 25 h fehler');
    sicherung_loeschen($sAuto, null, false);
    $mig = db_zeile('SELECT * FROM schema_migration ORDER BY nr DESC LIMIT 1');
    db_ausfuehren('DELETE FROM schema_migration WHERE nr = ?', [$mig['nr']]);
    $aMig = $amp('datenbank');
    db_ausfuehren('INSERT INTO schema_migration (nr, beschreibung, angewendet_am) VALUES (?, ?, ?)', [$mig['nr'], $mig['beschreibung'], $mig['angewendet_am']]);
    pruefe($aMig === 'fehler' && $amp('datenbank') === 'ok', 'Datenbank: Schema ist < soll → fehler, danach ok');
    wartung_einschalten('test');
    pruefe($amp('datenbank') === 'warnung', 'Datenbank: Wartungsmodus → warnung');
    wartung_ausschalten();
    db_ausfuehren("INSERT INTO mail_ausgang (an, betreff, text, prio, status, postfach, erstellt_am, naechster_versuch) VALUES ('x@example.org', 'b', 't', 1, 'fehler', 'system', ?, ?)", [db_jetzt(), db_jetzt()]);
    pruefe($amp('mail') === 'fehler', 'Mail: fehlgeschlagene Mail → fehler');
    db_ausfuehren("DELETE FROM mail_ausgang WHERE an = 'x@example.org'");
    db_ausfuehren("INSERT INTO mail_ausgang (an, betreff, text, prio, status, postfach, erstellt_am, naechster_versuch) VALUES ('x@example.org', 'b', 't', 1, 'offen', 'system', ?, ?)", [gmdate('Y-m-d H:i:s', $t0 - 1800), db_jetzt()]);
    pruefe($amp('mail', $t0) === 'warnung', 'Mail: älteste offene seit 30 min → warnung');
    db_ausfuehren("DELETE FROM mail_ausgang WHERE an = 'x@example.org'");
    pruefe($amp('umgebung') === 'ok', 'Umgebung: Laufzeitordner beschreibbar');
    $json = betrieb_status_json(betrieb_status());
    pruefe(array_keys($json) === array_column(betrieb_status(), 'id') && !str_contains(json_encode($json), (string)konfig('jobs_token')) && !str_contains(json_encode($json), dirname(__DIR__, 2)), 'health-Daten: alle Zeilen, ohne Token und ohne Pfad');
    $anl = betrieb_cron_anleitung();
    pruefe(str_contains($anl['url'], '<jobs_token>') && !str_contains($anl['url'], (string)konfig('jobs_token')) && str_contains(betrieb_cron_anleitung(true)['url'], 'token=' . konfig('jobs_token')) && str_ends_with($anl['kommando'], '/cli/jobs.php') && $anl['zeitplan'] === '*/5 * * * *', 'Cron-Anleitung: Kommando, URL mit Platzhalter, Token nur auf Wunsch');
    // Fehlerprotokoll (Unterpunkt 4)
    $log = betrieb_fehlerlog_datei();
    $zeilen = [];
    for ($i = 1; $i <= 40; $i++) {
        $zeilen[] = '[31-Oct-2026 10:00:00 UTC] Zeile ' . $i . ($i === 40 ? ' ' . str_repeat('x', 600) : '');
    }
    file_put_contents($log, implode("\n", $zeilen) . "\n");
    $stand = betrieb_fehlerlog_stand();
    $ende = betrieb_fehlerlog_ende();
    pruefe($stand['zeilen'] === 40 && $stand['groesse'] === filesize($log) && $amp('fehlerlog') === 'warnung', 'Fehlerprotokoll: 40 Zeilen, Größe stimmt, warnung');
    pruefe(count($ende) === 30 && str_contains($ende[0], 'Zeile 11') && mb_strlen($ende[29]) <= BETRIEB_LOG_ZEICHEN + 2 && str_ends_with($ende[29], '…'), 'letzte 30 Zeilen, lange Zeile gekürzt');
    $audit0 = (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'betrieb' AND text = 'Fehlerprotokoll geleert'");
    $geleert = betrieb_fehlerlog_leeren(null);
    clearstatcache();
    pruefe($geleert && filesize($log) === 0 && einstellung('betrieb_fehlerlog_geleert') !== null
        && (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'betrieb' AND text = 'Fehlerprotokoll geleert'") === $audit0 + 1 && $amp('fehlerlog') === 'ok', 'Leeren: 0 Byte, Zeitpunkt gesetzt, Protokolleintrag, wieder ok');
}
