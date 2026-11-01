<?php
declare(strict_types=1);

/* Testbereich: Sicherung anlegen, prüfen, wiederherstellen und löschen, Testdaten-Schalter und Mail-Umleitung auf Staging (E-47). */

function test_sicherung(): void
{
    abschnitt('Sicherung und Wiederherstellung (E-47)');
    $lea = (int)test_frisch()['bd']['anmeldungen']['lea'];
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
    pruefe(['anmeldung' => $zahl('anmeldung'), 'programmpunkt' => $zahl('programmpunkt'), 'platz' => $zahl('platz'), 'konto' => $zahl('konto')] === $vorher
        && (anm_laden($lea)['email'] ?? '') === 'lea@example.org' && db_wert("SELECT COUNT(*) FROM programmpunkt WHERE titel = 'Geändert'") == 0, 'alle Daten zurück');
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
    pruefe(testdaten_moeglich(), 'Testdaten in der Entwicklung erlaubt');

    abschnitt('Staging zurücksetzen (Admin 2026-11-01)');
    // Ein Team-Konto, das nicht zu den Testdaten gehört (example.net): Gegenstand ist, dass es bleibt
    $echt = konto_anlegen('Rita Real', 'rita.real@example.net', 'admin', TEST_PASSWORT, null);
    va_rechte_setzen($echt, false, [1], null, [(int)va_reihe_von(1)['id']]);
    db_ausfuehren("INSERT INTO konto_code (konto_id, hash) VALUES (?, 'x')", [$echt]);
    inhalt_setzen('impressum', '<p>Eigenes Impressum</p>');
    einstellung_setzen('mail_absender_name', 'Staging-Absender');
    $maxVorher = (int)db_wert('SELECT MAX(id) FROM konto');
    $sAnz = count(sicherung_liste());
    $sz = testdaten_zuruecksetzen(null);
    pruefe(sicherung_kopf($sz)['anlass'] === 'vor dem Zurücksetzen von Staging' && count(sicherung_liste()) === $sAnz + 1, 'Zurücksetzen: vorher gesichert');
    pruefe(array_column(db_zeilen('SELECT email FROM konto'), 'email') === ['rita.real@example.net'] && db_zahl('SELECT COUNT(*) FROM konto_code WHERE konto_id = ?', [$echt]) === 1, 'nur das eigene Team-Konto bleibt (mit Codes), Testkonten und Teilnehmende weg');
    pruefe(db_zahl('SELECT COUNT(*) FROM konto_veranstaltung WHERE konto_id = ? AND veranstaltung_id = 1', [$echt]) === 1 && db_zahl("SELECT COUNT(*) FROM konto_reihe kr JOIN reihe r ON r.id = kr.reihe_id WHERE kr.konto_id = ? AND r.name = 'Wintersymposium'", [$echt]) === 1, 'Rechte für die erste Veranstaltung und die Reihe Wintersymposium bleiben');
    pruefe(db_zahl('SELECT COUNT(*) FROM anmeldung') + db_zahl('SELECT COUNT(*) FROM programmpunkt') + db_zahl('SELECT COUNT(*) FROM dozent') + db_zahl('SELECT COUNT(*) FROM rundmail') + db_zahl('SELECT COUNT(*) FROM newsletter_person') === 0, 'Anmeldungen, Programm, Dozierende, Mailings, Newsletter leer');
    pruefe(db_zahl('SELECT COUNT(*) FROM veranstaltung') === 1 && (va_reihe_von(1)['name'] ?? '') === 'Wintersymposium' && array_column(va_reihen(), 'name') === ['Wintersymposium'], 'eine Veranstaltung in der Reihe Wintersymposium, wie nach der Installation');
    pruefe(inhalt('impressum') === '<p>Eigenes Impressum</p>' && einstellung('mail_absender_name') === 'Staging-Absender' && db_zahl('SELECT COUNT(*) FROM host') > 0, 'Impressum, Betriebs-Einstellungen und Hostnamen bleiben');
    pruefe(konto_anlegen('Neu', 'neu@example.net', 'teilnehmend', null, null) > $maxVorher, 'Nummern gelöschter Konten werden nicht neu vergeben');
    pruefe(migration_ist() === migration_soll() && db_zahl("SELECT COUNT(*) FROM audit WHERE text LIKE 'Staging zurückgesetzt%'") === 1, 'Datenbank auf dem neuesten Stand, Eintrag im Protokoll');
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
