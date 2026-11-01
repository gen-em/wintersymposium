<?php
declare(strict_types=1);

/* Testbereich: Review-Behebung 2 (mechanisch aus lauf.php ausgelagert, noch nicht auf test_frisch umgestellt). */

function test_review2(): void
{
    abschnitt('Review-Behebung 2 (AP-16, T-288 bis T-296)');
    ctx_setzen('team_konto', null);
    db_ausfuehren('DELETE FROM mail_ausgang');
    db_ausfuehren('DELETE FROM rate_limit');
    $rd = ['nachname' => 'Sechzehn', 'berufsgruppe' => 'StudentIn', 'institution' => 'Uni', 'efn' => '', 'essen' => 'egal'];
    $gibt = fn(string ...$f): bool => array_filter($f, fn($x) => !function_exists($x)) === [];
    $vAkt = va_id();

    // Unterpunkt 1 (B-4, T-288): Sitzungseintrag an die Veranstaltung gebunden
    $vAlt = (int)va_anlegen('ap16-alt', 'AP16 Alt', $vAkt, null)['id'];
    $kimId = konto_anlegen('Kim Konto', 'kim.konto@example.org', 'teilnehmend');
    db_ausfuehren('UPDATE konto SET email_bestaetigt_am = ? WHERE id = ?', [db_jetzt(), $kimId]);
    $kimAlt = (int)va_mit($vAlt, fn() => team_anlegen(['vorname' => 'Kim', 'email' => 'kim.konto@example.org'] + $rd, true, false)['id']);
    db_ausfuehren('UPDATE anmeldung SET konto_id = ? WHERE id = ?', [$kimId, $kimAlt]);
    db_ausfuehren("UPDATE veranstaltung SET status = 'abgeschlossen', abgeschlossen_am = ? WHERE id = ?", [db_jetzt(), $vAlt]);
    va_zwischenspeicher_leeren();
    $sitzungAlt = $_SESSION;
    $kimVer = (int)konto_laden($kimId)['sitzung_version'];
    $kimAltVer = (int)anm_laden($kimAlt)['sitzung_version'];
    $_SESSION = ['tk' => ['id' => $kimId, 'version' => $kimVer]];
    pruefe(anm_aktuell() === null, 'B-4: Konto mit Anmeldung nur in der abgeschlossenen Veranstaltung: im Kontext der aktuellen keine Anmeldung');
    $_SESSION[SITZUNG_SCHLUESSEL_ANM] = ['id' => $kimAlt, 'version' => $kimAltVer, 'va' => $vAlt, 'link' => false];
    pruefe(anm_aktuell() === null && !isset($_SESSION[SITZUNG_SCHLUESSEL_ANM]), 'B-4: abgeleiteter Eintrag mit va der alten Veranstaltung im Kontext der aktuellen verworfen');
    $_SESSION[SITZUNG_SCHLUESSEL_ANM] = ['id' => $kimAlt, 'version' => $kimAltVer, 'va' => $vAlt, 'link' => true];
    pruefe((int)(anm_aktuell()['id'] ?? 0) === $kimAlt, 'B-4: Eintrag aus einem Einmal-Link bleibt (Zertifikat-Mail einer vergangenen Veranstaltung, E-58)');
    unset($_SESSION[SITZUNG_SCHLUESSEL_ANM]);
    va_kontext($vAlt);
    $imArchiv = anm_aktuell();
    ctx_weg('va_id');
    pruefe($imArchiv === null && !isset($_SESSION[SITZUNG_SCHLUESSEL_ANM]), 'B-4: mit gesetztem Kontext (Team-Seite, Archivseite) wird nichts abgeleitet');
    $kimNeu = (int)team_anlegen(['vorname' => 'Kim', 'email' => 'kim.konto@example.org'] + $rd, true, false)['id'];
    db_ausfuehren('UPDATE anmeldung SET konto_id = ? WHERE id = ?', [$kimId, $kimNeu]);
    pruefe((int)(anm_aktuell()['id'] ?? 0) === $kimNeu && (int)($_SESSION[SITZUNG_SCHLUESSEL_ANM]['va'] ?? 0) === $vAkt && empty($_SESSION[SITZUNG_SCHLUESSEL_ANM]['link']), 'B-4: öffentliche Seite der aktuellen Veranstaltung: verknüpfte Anmeldung abgeleitet, mit va');
    $_SESSION = $sitzungAlt;

    // Unterpunkt 2 (B-6, T-289): ausgeblendete Listen bleiben
    $lA = nl_liste_speichern(null, nl_liste_pruefen(['name' => 'AP16 sichtbar', 'sichtbar' => '1'])['werte'], null);
    $lB = nl_liste_speichern(null, nl_liste_pruefen(['name' => 'AP16 ausgeblendet', 'sichtbar' => ''])['werte'], null);
    $np = nl_person_anlegen('nele.nl@example.org', 'Nele');
    nl_listen_setzen($np, [$lA], 'team');
    db_ausfuehren("INSERT INTO newsletter_abo (person_id, liste_id, status, quelle, angefragt_am, bestaetigt_am, fassung) VALUES (?, ?, 'bestaetigt', 'team', ?, ?, ?)", [$np, $lB, db_jetzt(), db_jetzt(), nl_fassung()]);
    nl_listen_setzen($np, [$lA], 'konto');
    pruefe(array_keys(nl_abos($np)) == [$lA, $lB] || (isset(nl_abos($np)[$lA]) && isset(nl_abos($np)[$lB])), 'B-6: nur sichtbare Liste A gespeichert: Abo der ausgeblendeten Liste B bleibt');
    nl_listen_setzen($np, [], 'konto');
    pruefe(!isset(nl_abos($np)[$lA]) && isset(nl_abos($np)[$lB]) && nl_person($np) !== null, 'B-6: nichts angekreuzt: A abgemeldet, B bleibt');
    db_ausfuehren('DELETE FROM newsletter_abo WHERE person_id = ?', [$np]);
    db_ausfuehren('DELETE FROM newsletter_person WHERE id = ?', [$np]);
    db_ausfuehren('DELETE FROM newsletter_liste WHERE id IN (?, ?)', [$lA, $lB]);

    // Unterpunkt 3 (B-7, S-4, V-4, T-290): zweiter Faktor beim Bestätigen
    if (!$gibt('zweitfaktor_bestaetigen', 'zweitfaktor_bestaetigt', 'zweitfaktor_merken', 'passwort_bestaetigen', 'pk_bestaetigen', 'pk_bestaetigen_optionen')) {
        pruefe(false, 'B-7: Helfer zweitfaktor_bestaetigen / zweitfaktor_bestaetigt / passwort_bestaetigen / pk_bestaetigen vorhanden');
    } else {
        $zId = konto_anlegen('Zelda Zweit', 'zelda.zweit@example.org', 'betreiber', 'zeldas-passwort-16', null);
        mailcode_einschalten($zId, true);
        $sitzungAlt = $_SESSION;
        $_SESSION = [];
        $mailcode = function () use ($zId): string {
            db_ausfuehren("DELETE FROM rate_limit WHERE topf IN ('mailcode', 'mailcode_minute')");
            mailcode_senden(konto_laden($zId));
            return preg_match('/\b(\d{6})\b/', (string)db_wert("SELECT text FROM mail_ausgang WHERE an = 'zelda.zweit@example.org' ORDER BY id DESC LIMIT 1"), $m) === 1 ? $m[1] : '';
        };
        $c = $mailcode();
        pruefe(zweitfaktor_bestaetigen($zId, $c) === null && zweitfaktor_bestaetigt($zId) && !zweitfaktor_bestaetigt($zId), 'B-7: Konto nur mit Mail-Code: Code aus der Mail bestätigt, Bestätigung gilt einmal');
        zweitfaktor_merken($zId);
        pruefe(!zweitfaktor_bestaetigt($zId + 1), 'B-7: Bestätigung gilt nur für das eigene Konto');
        $_SESSION[SITZUNG_SCHLUESSEL_ZF_BESTAETIGT] = ['konto' => $zId, 'bis' => time() - 1];
        pruefe(!zweitfaktor_bestaetigt($zId), 'B-7: Bestätigung älter als 5 Minuten gilt nicht');
        $codes = totp_codes_erneuern($zId);
        pruefe(zweitfaktor_bestaetigen($zId, strtolower($codes[0])) === null && zweitfaktor_bestaetigt($zId), 'B-7: Wiederherstellungscode bestätigt');
        for ($i = 0; $i < 5; $i++) {
            zweitfaktor_bestaetigen($zId, '000000');
        }
        $c = $mailcode();
        pruefe(str_contains((string)zweitfaktor_bestaetigen($zId, $c), 'Zu viele') && !zweitfaktor_bestaetigt($zId), 'B-7: nach 5 falschen Codes gesperrt, auch mit richtigem');
        db_ausfuehren('DELETE FROM rate_limit');
        // Konto nur mit Passkey: Bestätigen über pk_bestaetigen (Aktion bestaetigen in passkey.php)
        ctx_setzen('host', 'localhost');
        $pkK = konto_anlegen('Pit Passkey', 'pit.pk@example.org', 'betreiber', 'pits-passwort-16', null);
        $gp = test_pk_geraet();
        $o = pk_anlage_optionen(konto_laden($pkK));
        pk_anlegen($pkK, test_pk_anlage($gp, $o['challenge'], 'localhost', 'http://localhost'), 'Pit');
        $o = pk_bestaetigen_optionen(konto_laden($pkK));
        $pkOk = true;
        try {
            pk_bestaetigen($pkK, test_pk_login($gp, $o['challenge'], 'localhost', 'http://localhost'));
        } catch (PkFehler) {
            $pkOk = false;
        }
        pruefe($pkOk && count($o['allowCredentials']) === 1, 'B-7: Konto nur mit Passkey: Bestätigung mit eigenem Passkey');
        $o = pk_bestaetigen_optionen(konto_laden($zId));
        pruefe($pkFehlt(fn() => pk_bestaetigen($zId, test_pk_login($gp, $o['challenge'], 'localhost', 'http://localhost'))), 'B-7: Passkey eines anderen Kontos abgelehnt');
        ctx_setzen('host', $hostAlt);
        // S-4: Passwort im eingeloggten Zustand mit Ratenschutz
        for ($i = 0; $i < 5; $i++) {
            passwort_bestaetigen(konto_laden($zId), 'falsch');
        }
        pruefe(str_contains((string)passwort_bestaetigen(konto_laden($zId), 'zeldas-passwort-16'), 'Zu viele'), 'S-4: Passwort ändern / Konto löschen: nach 5 falschen Passwörtern gesperrt');
        db_ausfuehren('DELETE FROM rate_limit');
        pruefe(passwort_bestaetigen(konto_laden($zId), 'zeldas-passwort-16') === null, 'S-4: richtiges Passwort ohne Sperre angenommen');
        $_SESSION = $sitzungAlt;
    }

    // Unterpunkt 4 (S-3, T-291): nur eigene oder bestätigte Anmeldungen für Team-Konten
    if (!$gibt('anm_eigene_team')) {
        pruefe(false, 'S-3: anm_eigene_team() vorhanden');
    } else {
        $tId = konto_anlegen('Tara Team', 'tara.team@example.org', 'admin');
        $fremd = anm_anlegen(anm_daten_pruefen(['vorname' => 'Fremd', 'email' => 'tara.team@example.org'] + $rd)['werte'], ['fest' => [], 'warte' => []], false);
        pruefe(anm_laden($fremd['id'])['status'] === 'unbestaetigt' && anm_eigene_team(konto_laden($tId)) === null, 'S-3: unbestätigte Anmeldung mit Team-Adresse wird nicht übernommen');
        db_ausfuehren("UPDATE anmeldung SET status = 'bestaetigt' WHERE id = ?", [$fremd['id']]);
        pruefe((int)(anm_eigene_team(konto_laden($tId))['id'] ?? 0) === (int)$fremd['id'], 'S-3: bestätigte mit gleicher Adresse übernommen');
        db_ausfuehren("UPDATE anmeldung SET status = 'unbestaetigt', konto_id = ? WHERE id = ?", [$tId, $fremd['id']]);
        pruefe((int)(anm_eigene_team(konto_laden($tId))['id'] ?? 0) === (int)$fremd['id'], 'S-3: verknüpfte Anmeldung gilt immer als eigene');
    }

    // Unterpunkt 5 (B-8, B-12, B-14, S-11, T-292)
    $fotoGenutzt = foto_ordner() . '/dozent-ap16-archiv.jpg';
    $fotoWeg = foto_ordner() . '/dozent-ap16-weg.jpg';
    foreach ([$fotoGenutzt, $fotoWeg] as $d) {
        file_put_contents($d, 'x');
        touch($d, time() - 7200);
    }
    db_ausfuehren('REPLACE INTO programm_stand (id, daten, veroeffentlicht_am, veroeffentlicht_von) VALUES (?, ?, ?, NULL)', [$vAlt, json_encode([['typ' => 'workshop', 'dozenten' => [], 'termine' => [['id' => 1, 'dozenten' => [['name' => 'Archiv', 'foto' => 'dozent-ap16-archiv.jpg']]]]]]), db_jetzt()]);
    foto_aufraeumen();
    pruefe(is_file($fotoGenutzt) && !is_file($fotoWeg), 'B-8: Foto eines Termins in einer abgeschlossenen Veranstaltung bleibt, ungenutztes geht');
    db_ausfuehren('DELETE FROM programm_stand WHERE id = ?', [$vAlt]);
    @unlink($fotoGenutzt);
    $rmA = rm_speichern(null, rm_pruefen(['betreff' => 'Abbruch', 'html' => '<p>x</p>', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system'])['werte'], null);
    for ($i = 1; $i <= 5; $i++) {
        db_ausfuehren('INSERT INTO rundmail_empfaenger (rundmail_id, anmeldung_id, nl_person_id, name, email) VALUES (?, NULL, NULL, ?, ?)', [$rmA, 'E' . $i, 'abbruch' . $i . '@example.org']);
        db_ausfuehren("INSERT INTO mail_ausgang (an, betreff, text, prio, status, postfach, rundmail_id, erstellt_am, naechster_versuch) VALUES (?, '', '', 5, ?, 'system', ?, ?, ?)", ['abbruch' . $i . '@example.org', $i <= 2 ? 'gesendet' : 'offen', $rmA, db_jetzt(), db_jetzt()]);
    }
    db_ausfuehren("UPDATE rundmail SET status = 'versand', empfaenger_zahl = 5 WHERE id = ?", [$rmA]);
    rm_abbrechen($rmA, 0);
    $emp = array_column(db_zeilen('SELECT email FROM rundmail_empfaenger WHERE rundmail_id = ? ORDER BY email', [$rmA]), 'email');
    pruefe($emp === ['abbruch1@example.org', 'abbruch2@example.org'] && (int)db_wert('SELECT empfaenger_zahl FROM rundmail WHERE id = ?', [$rmA]) === 2, 'B-12: Abbrechen mit 3 offenen von 5: Empfängerliste und Zahl 2');
    db_ausfuehren('DELETE FROM mail_ausgang WHERE rundmail_id = ?', [$rmA]);
    db_ausfuehren('DELETE FROM rundmail WHERE id = ?', [$rmA]);
    $warnung = null;
    set_error_handler(function (int $n, string $t) use (&$warnung): bool {
        $warnung = $t;
        return true;
    });
    $rmWeg = rm_speichern($rmA, rm_pruefen(['betreff' => 'Weg', 'html' => '<p>x</p>', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system'])['werte'], null);
    restore_error_handler();
    pruefe($rmWeg === null && $warnung === null, 'B-14: rm_speichern auf gelöschtes Mailing: null ohne PHP-Warnung');
    pruefe(function_exists('tk_profil_fehler') && tk_profil_fehler(['Die Anmeldung gibt es nicht mehr.', 'vorname' => 'x']) === ['_' => 'Die Anmeldung gibt es nicht mehr.', 'vorname' => 'x'], 'B-14: Fehler ohne Feldbezug unter „_“ (Mein Konto zeigt sie als Meldung)');
    $tnPlatz = db_zeile("SELECT p.id, a.nachname FROM platz p JOIN anmeldung a ON a.id = p.anmeldung_id WHERE p.status = 'fest' AND p.veranstaltung_id = ? AND a.nachname <> '' ORDER BY p.id LIMIT 1", [$vAkt]);
    tn_setzen((int)$tnPlatz['id'], false, null);
    $tnText = (string)db_wert("SELECT text FROM audit WHERE art = 'teilnahme' ORDER BY id DESC LIMIT 1");
    tn_setzen((int)$tnPlatz['id'], true, null);
    pruefe(str_starts_with($tnText, 'Anmeldung ') && !str_contains($tnText, (string)$tnPlatz['nachname']) && str_contains($tnText, 'nicht erschienen'), 'S-11: Protokolltext zur Anwesenheit ohne Namen');

    // Unterpunkt 6 (B-10, T-293): kein Rückfall auf Veranstaltung 1
    db_ausfuehren("UPDATE veranstaltung SET status = 'abgeschlossen' WHERE id = ?", [$vAkt]);
    va_zwischenspeicher_leeren();
    $keineId = va_id();
    $keineZr = anm_zeitraum();
    $keineAnm = anm_anlegen(anm_daten_pruefen(['vorname' => 'Ohne', 'email' => 'ohne.va@example.org'] + $rd)['werte'], ['fest' => [], 'warte' => []], false);
    db_ausfuehren("UPDATE veranstaltung SET status = 'aktuell' WHERE id = ?", [$vAkt]);
    va_zwischenspeicher_leeren();
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
    pruefe($keineId !== 1 && $keineId !== $vAkt && $keineZr === 'geschlossen' && ($keineAnm['art'] ?? '') === 'fehler' && db_wert("SELECT COUNT(*) FROM anmeldung WHERE email = 'ohne.va@example.org'") == 0, 'B-10: ohne aktuelle Veranstaltung: kein Rückfall auf 1, geschlossen, anm_anlegen liefert Fehler');

    // Unterpunkt 7 (S-9, T-294): Mail-Codes je Konto begrenzt
    db_ausfuehren('DELETE FROM rate_limit');
    db_ausfuehren('DELETE FROM mail_ausgang');
    $mcK = konto_laden(konto_anlegen('Mara Mailcode', 'mara.mc@example.org', 'admin'));
    $erst = mailcode_senden($mcK);
    $gleich = mailcode_senden($mcK);
    pruefe($erst === null && $gleich !== null && (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE an = 'mara.mc@example.org'") === 1, 'S-9: zweiter Code in derselben Minute nicht verschickt');
    for ($i = 2; $i <= 11; $i++) {
        db_ausfuehren("DELETE FROM rate_limit WHERE topf = 'mailcode_minute'"); // eine Minute später
        $letzter = mailcode_senden($mcK);
    }
    pruefe($letzter !== null && (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE an = 'mara.mc@example.org'") === 10, 'S-9: 11 Mail-Codes in einer Stunde: der 11. wird nicht verschickt');
    db_ausfuehren('DELETE FROM rate_limit');
    db_ausfuehren('DELETE FROM mail_ausgang');

    // Unterpunkt 8 (S-6, T-295): Proxy-Verdacht in der Zeile Umgebung
    if (!$gibt('betrieb_proxy_verdacht')) {
        pruefe(false, 'S-6: betrieb_proxy_verdacht() vorhanden');
    } else {
        $pv = fn(array $s, bool $ein = false, string $u = 'produktion') => betrieb_proxy_verdacht($s, $ein, $u);
        pruefe($pv(['REMOTE_ADDR' => '10.1.2.3']) && $pv(['REMOTE_ADDR' => '172.20.0.1']) && $pv(['REMOTE_ADDR' => '192.168.1.1']) && $pv(['REMOTE_ADDR' => '127.0.0.1']), 'S-6: private Adressen 10/8, 172.16/12, 192.168/16, 127/8 → Warnung');
        pruefe(!$pv(['REMOTE_ADDR' => '172.32.0.1']) && !$pv(['REMOTE_ADDR' => '203.0.113.5']) && !$pv(['REMOTE_ADDR' => '127.0.0.1'], false, 'entwicklung'), 'S-6: öffentliche Adresse und 127/8 in der Entwicklung → keine Warnung');
        pruefe($pv(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7']) && !$pv(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], true), 'S-6: X-Forwarded-For → Warnung; mit https_ueber_proxy keine');
        $_SERVER['REMOTE_ADDR'] = '10.0.0.7';
        $um = array_column(betrieb_status(), null, 'id')['umgebung'];
        unset($_SERVER['REMOTE_ADDR']);
        pruefe($um['ampel'] === 'warnung' && str_contains($um['text'], 'Ratenschutz zählt dann alle Besucher zusammen'), 'S-6: Status-Seite Zeile Umgebung zeigt die Warnung');
    }
}
