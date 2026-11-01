<?php
declare(strict_types=1);

/* Testbereich: Sicherung und Wiederherstellung (mechanisch aus lauf.php ausgelagert, noch nicht auf test_frisch umgestellt). */

function test_sicherung(): void
{
    abschnitt('Sicherung und Wiederherstellung (E-47)');
    $zahl = fn(string $t) => (int)db_wert("SELECT COUNT(*) FROM `$t`");
    $vorher = ['anmeldung' => $zahl('anmeldung'), 'programmpunkt' => $zahl('programmpunkt'), 'platz' => $zahl('platz'), 'konto' => $zahl('konto')];
    $ub = imagecreatetruecolor(40, 20);
    ob_start();
    imagepng($ub);
    unterschrift_schreiben((string)ob_get_clean());
    $sAnz = count(sicherung_liste());
    $s1 = sicherung_anlegen('Test');
    unterschrift_entfernen(null);
    pruefe(sicherung_kopf($s1)['migration'] === migration_ist() && sicherung_pruefen($s1) === null, 'Sicherung angelegt und lesbar');
    db_ausfuehren('DELETE FROM anmeldung');
    db_ausfuehren("UPDATE programmpunkt SET titel = 'Geändert'");
    pruefe(sicherung_wiederherstellen($s1, null) === null, 'Wiederherstellen ohne Fehler');
    pruefe(['anmeldung' => $zahl('anmeldung'), 'programmpunkt' => $zahl('programmpunkt'), 'platz' => $zahl('platz'), 'konto' => $zahl('konto')] === $vorher && db_wert("SELECT COUNT(*) FROM programmpunkt WHERE titel = 'Geändert'") == 0, 'alle Daten zurück');
    pruefe(count(sicherung_liste()) === $sAnz + 2, 'Stand davor automatisch gesichert');
    pruefe(unterschrift_da(), 'Unterschrift des Zertifikats in der Sicherung');
    unterschrift_entfernen(null);
    pruefe(sicherung_pfad('../config.php') === null && sicherung_pruefen('sicherung-20260101-000000-abcdef.jsonl.gz') !== null, 'nur gültige Namen im eigenen Ordner');
    $kaputt = 'sicherung-20260101-000000-aaaaaa.jsonl.gz';
    $roh = (string)gzdecode((string)file_get_contents(sicherung_ordner() . '/' . $s1));
    file_put_contents(sicherung_ordner() . '/' . $kaputt, gzencode(substr($roh, 0, intdiv(strlen($roh), 2))));
    pruefe(str_contains((string)sicherung_pruefen($kaputt), 'unvollständig') && sicherung_wiederherstellen($kaputt, null) !== null && $zahl('konto') === $vorher['konto'], 'unvollständige Sicherung abgelehnt, nichts geändert');
    $neuer = 'sicherung-20260101-000000-bbbbbb.jsonl.gz';
    file_put_contents(sicherung_ordner() . '/' . $neuer, gzencode(preg_replace('/"migration":\d+/', '"migration":999', $roh, 1)));
    pruefe(str_contains((string)sicherung_pruefen($neuer), 'neueren Stand'), 'Sicherung aus neuerem Stand abgelehnt');
    pruefe(sicherung_loeschen($kaputt, null) && sicherung_loeschen($neuer, null) && count(sicherung_liste()) === $sAnz + 2, 'Sicherung löschen');
    pruefe(testdaten_moeglich() && $zahl('konto') > 0, 'Testdaten in der Entwicklung erlaubt');
    // Mail-Umleitung (Staging): config kurz um mail.umleiten_an ergänzen
    $cfgDatei = konfig_datei();
    $cfgAlt = (string)file_get_contents($cfgDatei);
    $cfg = require $cfgDatei;
    $cfg['mail'] = ['modus' => 'datei', 'umleiten_an' => 'team@example.org'];
    file_put_contents($cfgDatei, '<?php return ' . var_export($cfg, true) . ";\n");
    konfig_verwerfen();
    mail_zustellen('jemand@example.org', 'Betreff', 'Text');
    $dateien = glob(laufzeit_pfad('mails') . '/*.eml') ?: [];
    rsort($dateien);
    $m = quoted_printable_decode((string)file_get_contents($dateien[0] ?? ''));
    pruefe(str_contains($m, 'To: <team@example.org>') && str_contains($m, 'jemand@example.org') && !str_contains($m, 'To: <jemand@'), 'Staging: Mails an eine Adresse umgeleitet, Empfänger im Betreff');
    file_put_contents($cfgDatei, $cfgAlt);
    konfig_verwerfen();
}
