<?php
declare(strict_types=1);

/* Testbereich: Review-Behebung 2 (AP-16) – Sitzung je Veranstaltung, ausgeblendete Listen, zweiter Faktor beim Bestätigen, eigene Anmeldung von Team-Konten, Aufräumen und Abbruch, kein Rückfall auf Veranstaltung 1, Mail-Codes, Proxy-Verdacht. */

function test_review2(): void
{
    abschnitt('Review-Behebung 2 (AP-16, T-288 bis T-296)');
    require_once dirname(__DIR__) . '/passkey_hilfe.php';
    $f = test_frisch();
    $bd = $f['bd'];
    $vAkt = va_id();
    $abend = $bd['abend'];
    $vAlt = (int)$abend['id'];

    // Unterpunkt 1 (B-4, T-288): Sitzungseintrag an die Veranstaltung gebunden.
    // Kai hat ein Konto, angemeldet war er nur beim abgeschlossenen Fortbildungsabend.
    $kaiK = beispieldaten_konto_abend($abend);
    $kaiAlt = (int)$abend['kai'];
    $kaiVer = (int)konto_laden($kaiK)['sitzung_version'];
    $kaiAltVer = (int)anm_laden($kaiAlt)['sitzung_version'];
    $_SESSION = ['tk' => ['id' => $kaiK, 'version' => $kaiVer]];
    pruefe(anm_aktuell() === null, 'B-4: Konto mit Anmeldung nur in der abgeschlossenen Veranstaltung: im Kontext der aktuellen keine Anmeldung');
    $_SESSION[SITZUNG_SCHLUESSEL_ANM] = ['id' => $kaiAlt, 'version' => $kaiAltVer, 'va' => $vAlt, 'link' => false];
    pruefe(anm_aktuell() === null && !isset($_SESSION[SITZUNG_SCHLUESSEL_ANM]), 'B-4: abgeleiteter Eintrag mit va der alten Veranstaltung im Kontext der aktuellen verworfen');
    $_SESSION[SITZUNG_SCHLUESSEL_ANM] = ['id' => $kaiAlt, 'version' => $kaiAltVer, 'va' => $vAlt, 'link' => true];
    pruefe((int)(anm_aktuell()['id'] ?? 0) === $kaiAlt, 'B-4: Eintrag aus einem Einmal-Link bleibt (Zertifikat-Mail einer vergangenen Veranstaltung, E-58)');
    unset($_SESSION[SITZUNG_SCHLUESSEL_ANM]);
    va_kontext($vAlt);
    $imArchiv = anm_aktuell();
    ctx_weg('va_id');
    pruefe($imArchiv === null && !isset($_SESSION[SITZUNG_SCHLUESSEL_ANM]), 'B-4: mit gesetztem Kontext (Team-Seite, Archivseite) wird nichts abgeleitet');
    // Schritt des Ablaufs: Kai meldet sich jetzt auch in der aktuellen Veranstaltung an (das Team trägt ihn ein, Konto wird verknüpft)
    $kai = anm_laden($kaiAlt);
    $kaiNeu = (int)team_anlegen(['vorname' => 'Kai', 'nachname' => $kai['nachname'], 'email' => $kai['email'], 'berufsgruppe' => $kai['berufsgruppe'],
        'beruf_sonstige' => '', 'institution' => $kai['institution'], 'efn' => '', 'essen' => 'egal'], true, false)['id'];
    pruefe((int)(anm_aktuell()['id'] ?? 0) === $kaiNeu && (int)($_SESSION[SITZUNG_SCHLUESSEL_ANM]['va'] ?? 0) === $vAkt && empty($_SESSION[SITZUNG_SCHLUESSEL_ANM]['link']), 'B-4: öffentliche Seite der aktuellen Veranstaltung: verknüpfte Anmeldung abgeleitet, mit va');
    $_SESSION = [];

    // Unterpunkt 2 (B-6, T-289): ausgeblendete Listen bleiben (Lea auf „Referierende (intern)“)
    $lA = (int)array_column(nl_listen(), 'id', 'name')['Wintersymposium'];
    $lB = $bd['review2']['liste_intern'];
    $np = (int)nl_person_per_email('lea@example.org')['id'];
    nl_listen_setzen($np, [$lA], 'konto');
    pruefe(nl_abos($np) == [$lA => 'bestaetigt', $lB => 'bestaetigt'], 'B-6: nur sichtbare Liste angekreuzt und gespeichert: Abo der ausgeblendeten Liste bleibt');
    nl_listen_setzen($np, [], 'konto');
    pruefe(nl_abos($np) == [$lB => 'bestaetigt'] && nl_person($np) !== null, 'B-6: nichts angekreuzt: sichtbare Liste abgemeldet, ausgeblendete bleibt');

    // Unterpunkt 3 (B-7, S-4, V-4, T-290): zweiter Faktor beim Bestätigen (Bea, BetreiberIn mit Mail-Code)
    $zId = $f['betreiber'];
    mailcode_einschalten($zId, true);
    $_SESSION = [];
    $mailcode = function () use ($zId): string {
        db_ausfuehren("DELETE FROM rate_limit WHERE topf IN ('mailcode', 'mailcode_minute')");
        mailcode_senden(konto_laden($zId));
        return preg_match('/\b(\d{6})\b/', (string)(test_mail_an(TEST_BETREIBER)['text'] ?? ''), $m) === 1 ? $m[1] : '';
    };
    $c = $mailcode();
    pruefe(zweitfaktor_bestaetigen($zId, $c) === null && zweitfaktor_bestaetigt($zId) && !zweitfaktor_bestaetigt($zId), 'B-7: Konto nur mit Mail-Code: Code aus der Mail bestätigt, Bestätigung gilt einmal');
    zweitfaktor_merken($zId);
    pruefe(!zweitfaktor_bestaetigt($f['admin']), 'B-7: Bestätigung gilt nur für das eigene Konto');
    $_SESSION[SITZUNG_SCHLUESSEL_ZF_BESTAETIGT] = ['konto' => $zId, 'bis' => time() - 1];
    pruefe(!zweitfaktor_bestaetigt($zId), 'B-7: Bestätigung älter als 5 Minuten gilt nicht');
    $codes = totp_codes_erneuern($zId);
    pruefe(zweitfaktor_bestaetigen($zId, strtolower($codes[0] ?? '')) === null && zweitfaktor_bestaetigt($zId), 'B-7: Wiederherstellungscode bestätigt');
    for ($i = 0; $i < 5; $i++) {
        zweitfaktor_bestaetigen($zId, '000000');
    }
    $c = $mailcode();
    pruefe(str_contains((string)zweitfaktor_bestaetigen($zId, $c), 'Zu viele') && !zweitfaktor_bestaetigt($zId), 'B-7: nach 5 falschen Codes gesperrt, auch mit richtigem');
    db_ausfuehren('DELETE FROM rate_limit');
    // Konto nur mit Passkey (Ada, Admin): Bestätigen über pk_bestaetigen (Aktion bestaetigen in passkey.php)
    $pkFehlt = function (callable $fn): bool {
        try {
            $fn();
            return false;
        } catch (PkFehler) {
            return true;
        }
    };
    $hostAlt = ctx('host');
    ctx_setzen('host', 'localhost');
    $pkK = $bd['admin'];
    $gp = test_pk_geraet();
    $o = pk_anlage_optionen(konto_laden($pkK));
    pk_anlegen($pkK, test_pk_anlage($gp, $o['challenge'], 'localhost', 'http://localhost'), 'Ada');
    $o = pk_bestaetigen_optionen(konto_laden($pkK));
    pruefe(count($o['allowCredentials']) === 1 && !$pkFehlt(fn() => pk_bestaetigen($pkK, test_pk_login($gp, $o['challenge'], 'localhost', 'http://localhost'))), 'B-7: Konto nur mit Passkey: Bestätigung mit eigenem Passkey');
    $o = pk_bestaetigen_optionen(konto_laden($zId));
    pruefe($pkFehlt(fn() => pk_bestaetigen($zId, test_pk_login($gp, $o['challenge'], 'localhost', 'http://localhost'))), 'B-7: Passkey eines anderen Kontos abgelehnt');
    ctx_setzen('host', $hostAlt);
    // S-4: Passwort im eingeloggten Zustand mit Ratenschutz
    for ($i = 0; $i < 5; $i++) {
        passwort_bestaetigen(konto_laden($zId), 'falsch');
    }
    pruefe(str_contains((string)passwort_bestaetigen(konto_laden($zId), TEST_PASSWORT), 'Zu viele'), 'S-4: Passwort ändern / Konto löschen: nach 5 falschen Passwörtern gesperrt');
    db_ausfuehren('DELETE FROM rate_limit');
    pruefe(passwort_bestaetigen(konto_laden($zId), TEST_PASSWORT) === null, 'S-4: richtiges Passwort ohne Sperre angenommen');
    $_SESSION = [];

    // Unterpunkt 4 (S-3, T-291): nur eigene oder bestätigte Anmeldungen für Team-Konten
    // (Beispieldaten: unbestätigte Anmeldung mit Adas Adresse, von jemand Fremdem angelegt)
    $ada = konto_laden($bd['admin']);
    $fremd = $bd['review2']['fremd_ada'];
    pruefe(anm_laden($fremd)['status'] === 'unbestaetigt' && anm_eigene_team($ada) === null, 'S-3: unbestätigte Anmeldung mit Team-Adresse wird nicht übernommen');
    db_ausfuehren("UPDATE anmeldung SET status = 'bestaetigt' WHERE id = ?", [$fremd]);
    pruefe((int)(anm_eigene_team($ada)['id'] ?? 0) === $fremd, 'S-3: bestätigte mit gleicher Adresse übernommen');
    db_ausfuehren("UPDATE anmeldung SET status = 'unbestaetigt', konto_id = ? WHERE id = ?", [$ada['id'], $fremd]);
    pruefe((int)(anm_eigene_team($ada)['id'] ?? 0) === $fremd, 'S-3: verknüpfte Anmeldung gilt immer als eigene');

    // Unterpunkt 5 (B-8, B-12, B-14, S-11, T-292)
    // B-8: Foto einer Dozentin an einem Termin im veröffentlichten Stand des abgeschlossenen Vorjahrs
    $fotoGenutzt = foto_ordner() . '/dozent-ap16-archiv.jpg';
    $fotoWeg = foto_ordner() . '/dozent-ap16-weg.jpg';
    foreach ([$fotoGenutzt, $fotoWeg] as $d) {
        file_put_contents($d, 'x');
        touch($d, time() - 7200);
    }
    $vj = (int)$bd['vorjahr']['id'];
    $stand = json_decode((string)db_wert('SELECT daten FROM programm_stand WHERE id = ?', [$vj]), true);
    $wsNr = array_search('workshop', array_column($stand, 'typ'), true);
    $stand[$wsNr]['termine'][0]['dozenten'] = [['name' => 'Archiv', 'foto' => 'dozent-ap16-archiv.jpg']];
    db_ausfuehren('UPDATE programm_stand SET daten = ? WHERE id = ?', [json_encode($stand), $vj]);
    foto_aufraeumen();
    pruefe($wsNr !== false && is_file($fotoGenutzt) && !is_file($fotoWeg), 'B-8: Foto eines Termins in einer abgeschlossenen Veranstaltung bleibt, ungenutztes geht');
    @unlink($fotoGenutzt);
    @unlink($fotoWeg);
    // B-12: Versand eines Mailings an alle Angemeldeten nach der ersten Mail abbrechen
    db_ausfuehren('DELETE FROM mail_ausgang');
    $rmA = rm_speichern(null, rm_pruefen(['betreff' => 'Abbruch', 'html' => '<p>x</p>', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system'])['werte'], null);
    $rmZahl = rm_einreihen($rmA, $f['betreiber']);
    mail_senden_faellige(1, 20.0);
    // gesendete Mails verlieren ihre Adresse (R-02); gesendet ist, wer nicht mehr offen ist
    $vorher = array_column(db_zeilen('SELECT email FROM rundmail_empfaenger WHERE rundmail_id = ? ORDER BY email', [$rmA]), 'email');
    $offen = array_column(db_zeilen("SELECT an FROM mail_ausgang WHERE rundmail_id = ? AND status = 'offen'", [$rmA]), 'an');
    rm_abbrechen($rmA, $f['betreiber']);
    $emp = array_column(db_zeilen('SELECT email FROM rundmail_empfaenger WHERE rundmail_id = ? ORDER BY email', [$rmA]), 'email');
    pruefe($rmZahl === 3 && count($offen) === 2 && $emp === array_values(array_diff($vorher, $offen)) && (int)db_wert('SELECT empfaenger_zahl FROM rundmail WHERE id = ?', [$rmA]) === 1
        && (int)db_wert('SELECT COUNT(*) FROM mail_ausgang WHERE rundmail_id = ?', [$rmA]) === 1, 'B-12: Abbrechen nach 1 von 3 Mails: Empfängerliste und Zahl 1, offene entfernt');
    db_ausfuehren('DELETE FROM rundmail WHERE id = ?', [$rmA]);
    $warnung = null;
    set_error_handler(function (int $n, string $t) use (&$warnung): bool {
        $warnung = $t;
        return true;
    });
    $rmWeg = rm_speichern($rmA, rm_pruefen(['betreff' => 'Weg', 'html' => '<p>x</p>', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system'])['werte'], null);
    restore_error_handler();
    pruefe($rmWeg === null && $warnung === null, 'B-14: rm_speichern auf gelöschtes Mailing: null ohne PHP-Warnung');
    pruefe(tk_profil_fehler(['Die Anmeldung gibt es nicht mehr.', 'vorname' => 'x']) === ['_' => 'Die Anmeldung gibt es nicht mehr.', 'vorname' => 'x'], 'B-14: Fehler ohne Feldbezug unter „_“ (Mein Konto zeigt sie als Meldung)');
    // S-11: Lea in WS 01 nicht erschienen
    $tnPlatz = (int)db_wert('SELECT id FROM platz WHERE anmeldung_id = ? AND termin_id = ?', [$bd['anmeldungen']['lea'], test_termin('WS 01', '19:30')]);
    tn_setzen($tnPlatz, false, null);
    $tnText = (string)db_wert("SELECT text FROM audit WHERE art = 'teilnahme' ORDER BY id DESC LIMIT 1");
    pruefe(str_starts_with($tnText, 'Anmeldung ' . $bd['anmeldungen']['lea']) && str_contains($tnText, 'nicht erschienen') && !str_contains($tnText, 'Lea') && !str_contains($tnText, 'Beispiel'), 'S-11: Protokolltext zur Anwesenheit ohne Namen');

    // Unterpunkt 6 (B-10, T-293): kein Rückfall auf Veranstaltung 1
    db_ausfuehren("UPDATE veranstaltung SET status = 'abgeschlossen' WHERE id = ?", [$vAkt]);
    va_zwischenspeicher_leeren();
    $keineId = va_id();
    $keineZr = anm_zeitraum();
    $keineAnm = anm_anlegen(anm_daten_pruefen(['vorname' => 'Ohne', 'nachname' => 'Veranstaltung', 'email' => 'ohne.va@example.org', 'berufsgruppe' => 'StudentIn', 'beruf_sonstige' => '', 'institution' => 'Uni', 'efn' => '', 'essen' => 'egal'])['werte'], ['fest' => [], 'warte' => []], false);
    db_ausfuehren("UPDATE veranstaltung SET status = 'aktuell' WHERE id = ?", [$vAkt]);
    va_zwischenspeicher_leeren();
    pruefe($keineId === VA_KEINE && $keineZr === 'geschlossen' && ($keineAnm['art'] ?? '') === 'fehler' && (int)db_wert("SELECT COUNT(*) FROM anmeldung WHERE email = 'ohne.va@example.org'") === 0, 'B-10: ohne aktuelle Veranstaltung: kein Rückfall auf 1, geschlossen, anm_anlegen liefert Fehler');
    // Die letzte Veranstaltung lässt sich nicht löschen: in einer Transaktion alle anderen weg, danach zurückrollen
    db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    db()->beginTransaction();
    db_ausfuehren('DELETE FROM veranstaltung WHERE id <> ?', [$vAkt]);
    db_ausfuehren("UPDATE veranstaltung SET status = 'entwurf' WHERE id = ?", [$vAkt]);
    va_zwischenspeicher_leeren();
    $letzte = va_loeschen($vAkt, null, 'falsch');
    db()->rollBack();
    db()->exec('SET FOREIGN_KEY_CHECKS = 1');
    va_zwischenspeicher_leeren();
    pruefe(str_contains((string)$letzte, 'letzte Veranstaltung') && va_zeile($vAkt)['status'] === 'aktuell' && va_zeile($vAlt) !== null, 'B-10: die letzte verbliebene Veranstaltung lässt sich nicht löschen');

    // Unterpunkt 7 (S-9, T-294): Mail-Codes je Konto begrenzt (Ada)
    db_ausfuehren('DELETE FROM rate_limit');
    db_ausfuehren('DELETE FROM mail_ausgang');
    $mcK = konto_laden($bd['admin']);
    $mcZahl = fn(): int => (int)db_wert('SELECT COUNT(*) FROM mail_ausgang WHERE an = ?', [$mcK['email']]);
    $erst = mailcode_senden($mcK);
    $gleich = mailcode_senden($mcK);
    pruefe($erst === null && $gleich !== null && $mcZahl() === 1, 'S-9: zweiter Code in derselben Minute nicht verschickt');
    $letzter = null;
    for ($i = 2; $i <= 11; $i++) {
        db_ausfuehren("DELETE FROM rate_limit WHERE topf = 'mailcode_minute'"); // eine Minute später
        $letzter = mailcode_senden($mcK);
    }
    pruefe($letzter !== null && $mcZahl() === 10, 'S-9: 11 Mail-Codes in einer Stunde: der 11. wird nicht verschickt');

    // Unterpunkt 8 (S-6, T-295): Proxy-Verdacht in der Zeile Umgebung
    $pv = fn(array $s, bool $ein = false, string $u = 'produktion') => betrieb_proxy_verdacht($s, $ein, $u);
    pruefe($pv(['REMOTE_ADDR' => '10.1.2.3']) && $pv(['REMOTE_ADDR' => '172.20.0.1']) && $pv(['REMOTE_ADDR' => '192.168.1.1']) && $pv(['REMOTE_ADDR' => '127.0.0.1']), 'S-6: private Adressen 10/8, 172.16/12, 192.168/16, 127/8 → Warnung');
    pruefe(!$pv(['REMOTE_ADDR' => '172.32.0.1']) && !$pv(['REMOTE_ADDR' => '203.0.113.5']) && !$pv(['REMOTE_ADDR' => '127.0.0.1'], false, 'entwicklung'), 'S-6: öffentliche Adresse und 127/8 in der Entwicklung → keine Warnung');
    pruefe($pv(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7']) && !$pv(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], true), 'S-6: X-Forwarded-For → Warnung; mit https_ueber_proxy keine');
    $pm = fn(array $s) => array_column(betrieb_proxy_merkmale($s, 'produktion'), 1);
    pruefe($pm(['REMOTE_ADDR' => '10.0.0.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7']) === ['ja', 'vorhanden (1 Eintrag)', 'nein']
        && $pm(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 198.51.100.7']) === ['nein', 'vorhanden (2 Einträge)', 'ja']
        && $pm(['REMOTE_ADDR' => '198.51.100.7']) === ['nein', 'fehlt', '–'], 'E-74 (3) d: Merkmale ohne Adressen – intern, X-Forwarded-For, Absender = letzter Eintrag');
    $_SERVER['REMOTE_ADDR'] = '10.0.0.7';
    $um = array_column(betrieb_status(), null, 'id')['umgebung'];
    unset($_SERVER['REMOTE_ADDR']);
    pruefe($um['ampel'] === 'warnung' && str_contains($um['text'], 'Ratenschutz zählt dann alle Besucher zusammen') && in_array(['Absenderadresse intern', 'ja'], $um['details'], true), 'S-6: Status-Seite Zeile Umgebung zeigt die Warnung und die Merkmale');
}
