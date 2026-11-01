<?php
declare(strict_types=1);

/* Testbereich: Aufbewahrungsplan der Sicherungen, Anmeldungen leeren mit CME-Nachweisen, Protokoll kürzen (AP-9, E-59). */

function test_fristen(): void
{
    abschnitt('Fristen und Datenschutz (AP-9, E-59)');
    test_frisch();
    // Sicherungsplan: automatisch alle 3 h über 40 Tage, dazu zwei von Hand
    $t0 = gmmktime(12, 0, 0, 6, 1, 2026);
    $plan = [];
    for ($h = 0; $h < 40 * 24; $h += 3) {
        $plan[] = ['name' => 'sicherung-' . gmdate('Ymd-His', $t0 - $h * 3600) . '-aaaaaa.jsonl.gz', 'automatisch' => true];
    }
    $plan[] = ['name' => 'sicherung-' . gmdate('Ymd-His', $t0 - 10 * 86400) . '-bbbbbb.jsonl.gz', 'automatisch' => false];
    $plan[] = ['name' => 'sicherung-' . gmdate('Ymd-His', $t0 - 100 * 86400) . '-cccccc.jsonl.gz', 'automatisch' => false];
    $weg = ds_sicherungen_zu_loeschen($plan, $t0);
    $bleibt = array_values(array_filter($plan, fn($x) => !in_array($x['name'], $weg, true)));
    $alterTage = fn(array $x) => ($t0 - ds_sicherung_zeit($x['name'])) / 86400;
    $auto = array_values(array_filter($bleibt, fn($x) => $x['automatisch']));
    $zaehle = fn(float $von, float $bis) => count(array_filter($auto, fn($x) => $alterTage($x) > $von && $alterTage($x) <= $bis));
    pruefe($zaehle(-1, 3) === 25 && $zaehle(3, 7) >= 4 && $zaehle(3, 7) <= 5 && $zaehle(7, 14) >= 2 && $zaehle(7, 14) <= 4 && $zaehle(14, 28) >= 2 && $zaehle(14, 28) <= 3 && $zaehle(28, 99) === 0, 'Sicherungen: alle 3 Tage, dann je Tag, je 3 Tage, je Woche bis 28 Tage');
    pruefe(in_array($plan[count($plan) - 1]['name'], $weg, true) && !in_array($plan[count($plan) - 2]['name'], $weg, true), 'Von Hand: nach 90 Tagen gelöscht');
    pruefe(ds_sicherungen_zu_loeschen(array_values(array_filter($plan, fn($x) => !in_array($x['name'], $weg, true))), $t0) === [], 'Plan ist stabil (zweiter Lauf löscht nichts)');
    // Automatische Sicherung als Job: nicht im Protokoll
    $vorAudit = (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'sicherung'");
    $j = ds_sicherung_job(time());
    pruefe(sicherung_kopf($j['neu'])['anlass'] === DS_SICHERUNG_AUTOMATISCH && (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'sicherung'") === $vorAudit, 'Automatische Sicherung angelegt, ohne Protokolleintrag');
    $altName = 'sicherung-' . gmdate('Ymd-His', time() - 95 * 86400) . '-dddddd.jsonl.gz';
    copy(sicherung_ordner() . '/' . $j['neu'], sicherung_ordner() . '/' . $altName);
    $roh = (string)gzdecode((string)file_get_contents(sicherung_ordner() . '/' . $altName));
    file_put_contents(sicherung_ordner() . '/' . $altName, gzencode(preg_replace('/"anlass":"automatisch"/', '"anlass":"von Hand"', $roh, 1)));
    $j2 = ds_sicherung_job(time());
    pruefe(sicherung_pfad($altName) === null && sicherung_pfad($j['neu']) !== null && $j2['geloescht'] === 1, 'Job löscht alte Sicherung von Hand, behält die neuen');
    // Anmeldungen leeren: Veranstaltung vor 15 Monaten (Ada, Pia, Kai mit Konto), vor 13 Monaten (Nora)
    $fr = beispieldaten_fristen();
    [$aArzt, $aPflege, $aKonto] = [$fr['ada'], $fr['pia'], $fr['kai']];
    $r = ds_anmeldungen_leeren(time());
    $ada = anm_laden($aArzt);
    $cme = db_zeile('SELECT * FROM cme_nachweis WHERE anmeldung_id = ?', [$aArzt]);
    pruefe($r['geleert'] === 2 && $ada['nachname'] === '' && $ada['efn'] === null && str_ends_with($ada['email'], '@anonym.invalid') && $ada['berufsgruppe'] === 'ÄrztIn' && $ada['anonymisiert_am'] !== null, 'Ohne Konto 14 Monate nach der Veranstaltung geleert, Berufsgruppe bleibt');
    pruefe($cme !== null && $cme['nachname'] === 'Altdaten' && $cme['efn'] === '802760012345673' && $cme['punkte'] === '4' && db_wert('SELECT COUNT(*) FROM cme_nachweis WHERE anmeldung_id = ?', [$aPflege]) == 0, 'CME-Nachweis nur für anwesende ÄrztInnen, getrennt abgelegt');
    pruefe(anm_laden($aKonto)['nachname'] === 'Konto' && ds_anmeldungen_leeren(time())['geleert'] === 0, 'Mit Konto bleibt; zweiter Lauf leert nichts mehr');
    pruefe(anm_laden($fr['nora'])['nachname'] === 'Neu' && anm_laden($fr['nora'])['anonymisiert_am'] === null, 'Veranstaltung vor 13 Monaten: noch nicht geleert');
    $ex = export_erzeugen('cme_nachweise');
    pruefe($ex !== null && $ex['kopf'][4] === 'EFN' && str_contains($ex['text'], '"Altdaten"') && str_contains($ex['text'], '802760012345673') && !str_contains($ex['text'], '"Pflege"'), 'Export der CME-Nachweise');
    pruefe(ds_cme_loeschen(time()) === 0 && ds_cme_loeschen(time() + 11 * 366 * 86400) === 1 && db_wert('SELECT COUNT(*) FROM cme_nachweis WHERE anmeldung_id = ?', [$aArzt]) == 0, 'CME-Nachweise nach 10 Jahren gelöscht');
    // Protokoll
    db_ausfuehren("INSERT INTO audit (zeit, konto_id, art, text) VALUES (?, NULL, 'login', 'alt'), (?, NULL, 'login', 'neu'), (?, NULL, 'programm', 'alt'), (?, NULL, 'programm', 'mittel')",
        [gmdate('Y-m-d H:i:s', time() - 100 * 86400), gmdate('Y-m-d H:i:s', time() - 10 * 86400), gmdate('Y-m-d H:i:s', time() - 450 * 86400), gmdate('Y-m-d H:i:s', time() - 100 * 86400)]);
    ds_protokoll_kuerzen(time());
    pruefe(db_wert("SELECT COUNT(*) FROM audit WHERE text = 'alt' AND art IN ('login', 'programm')") == 0 && db_wert("SELECT COUNT(*) FROM audit WHERE text IN ('neu', 'mittel')") == 2, 'Protokoll: 14 Monate, Logins 90 Tage');
    pruefe((jobs_katalog()['datenschutz']['abstand_s'] ?? 0) === 3600 && (jobs_katalog()['sicherung']['abstand_s'] ?? 0) === 3 * 3600, 'Jobs: Datenschutz stündlich, Sicherung alle 3 Stunden');
}
