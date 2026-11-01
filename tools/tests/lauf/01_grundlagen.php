<?php
declare(strict_types=1);

/* Testbereich: Grundlagen: Transaktionen, Ratenschutz, Jobs, Sitzungsablage (mechanisch aus lauf.php ausgelagert, noch nicht auf test_frisch umgestellt). */

function test_grundlagen(): void
{
    abschnitt('Transaktionen (R-04)');
    try {
        db_transaktion(function (): void {
            einstellung_setzen('t_aussen', '1');
            db_transaktion(function (): void {
                einstellung_setzen('t_innen', '1');
            });
            throw new RuntimeException('Abbruch');
        });
    } catch (RuntimeException) {
    }
    pruefe(einstellung('t_aussen') === null && einstellung('t_innen') === null, 'verschachtelt: alles zurückgerollt');
    db_transaktion(fn() => einstellung_setzen('t_ok', 'ja'));
    pruefe(einstellung('t_ok') === 'ja', 'festgeschrieben');

    abschnitt('Ratenschutz (T-104)');
    $m = rate_merkmal('test');
    $t0 = 1_900_000_000;
    [$max, $fenster, $sperre] = RATE_TOEPFE['health'];
    for ($i = 1; $i < $max; $i++) {
        rate_misserfolg('health', $m, $t0);
    }
    pruefe(rate_erlaubt('health', $m, $t0), ($max - 1) . ' Fehlversuche: noch erlaubt');
    pruefe(rate_misserfolg('health', $m, $t0) === true, "$max. Fehlversuch sperrt");
    pruefe(!rate_erlaubt('health', $m, $t0 + 1), 'gesperrt');
    pruefe(rate_erlaubt('health', $m, $t0 + $sperre + 1), 'nach Sperrdauer wieder frei');
    rate_erfolg('health', $m);
    pruefe(db_wert('SELECT COUNT(*) FROM rate_limit WHERE merkmal = ?', [$m]) == 0, 'Erfolg setzt zurück');
    rate_misserfolg('health', $m, $t0);
    pruefe(rate_aufraeumen($t0 + 100000) >= 1, 'Aufräumen entfernt alte Einträge');
    pruefe(strlen(rate_merkmal_ip()) === 64 && !str_contains(rate_merkmal_ip(), '.'), 'Merkmal ist Hash, keine IP');

    abschnitt('Jobs (T-105)');
    $r = jobs_lauf('test', 1_900_000_000);
    pruefe($r['status'] === 'ok' && $r['jobs']['aufraeumen']['status'] === 'ok', 'erster Lauf führt Job aus');
    pruefe(einstellung('jobs_letzter_lauf') === gmdate('Y-m-d H:i:s', 1_900_000_000), 'letzter Lauf gespeichert');
    $r = jobs_lauf('test', 1_900_000_060);
    pruefe($r['jobs']['aufraeumen']['status'] === 'nicht_faellig', 'Mindestabstand eingehalten');
    pruefe(einstellung('jobs_letzter_lauf') === gmdate('Y-m-d H:i:s', 1_900_000_060), 'Zeitstempel trotzdem aktualisiert');
    wartung_einschalten('test');
    pruefe(jobs_lauf('test')['status'] === 'wartung', 'Jobs pausieren bei Wartung');
    wartung_ausschalten();

    abschnitt('Sitzungsablage (T-103)');
    $alt = sitzung_ablage_pfad() . '/sess_alt';
    $frisch = sitzung_ablage_pfad() . '/sess_frisch';
    file_put_contents($alt, 'x');
    touch($alt, time() - 7200);
    file_put_contents($frisch, 'x');
    pruefe(sitzung_aufraeumen() === 1 && !is_file($alt) && is_file($frisch), 'nur abgelaufene Sitzungsdateien gelöscht');
    pruefe(str_starts_with(sitzung_ablage_pfad(), laufzeit_ordner()), 'Ablage im eigenen Laufzeitordner');
}
