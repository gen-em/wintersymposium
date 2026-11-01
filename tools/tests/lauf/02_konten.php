<?php
declare(strict_types=1);

/* Testbereich: Konten – Anlegen, Einmal-Links, zweiter Faktor, Löschfrist teilnehmender Konten und Passkeys. */

function test_konten(): void
{
    $f = test_frisch();
    $lea = $f['bd']['anmeldungen']['lea_konto'];
    $anna = $f['admin'];

    abschnitt('Konten und Links');
    // Anlegen selbst wird geprüft (Normierung der Adresse)
    $kid = konto_anlegen('Test Admin', ' Admin@Example.org ', 'admin');
    pruefe(konto_per_email('admin@example.org')['id'] == $kid, 'E-Mail normiert gespeichert');
    pruefe(konto_eingabe_fehler('X', ' ADMIN@example.org', 'admin') === ['Für diese E-Mail-Adresse gibt es schon ein Konto.'], 'doppelte E-Mail abgelehnt (auch anders geschrieben)');
    $t0 = 1_900_000_000;
    $tok = konto_link_erzeugen($kid, 'einrichten', null, $t0);
    pruefe(db_wert('SELECT token_hash FROM link WHERE art = "konto" AND konto_id = ?', [$kid]) === hash('sha256', $tok), 'nur Hash des Links gespeichert');
    pruefe(konto_link_finden($tok, $t0 + 60) !== null, 'Link gültig');
    pruefe(konto_link_finden($tok, $t0 + KONTO_LINK_GUELTIG_S + 1) === null, 'Link nach 72 h abgelaufen');
    $tok2 = konto_link_erzeugen($kid, 'passwort', null, $t0);
    pruefe(konto_link_finden($tok, $t0) === null && konto_link_finden($tok2, $t0) !== null, 'neuer Link ersetzt alten');
    pruefe(konto_link_aufraeumen($t0 + KONTO_LINK_GUELTIG_S + 1) === 1, 'abgelaufene Links aufgeräumt');
    $roh = totp_einrichtung_beginnen($kid);
    pruefe(!totp_an($kid) && totp_zustand($kid)['angefangen'], 'Einrichtung begonnen, noch nicht an');
    $richtig = totp_code($roh, $t0);
    $falsch = substr($richtig, 0, 5) . (((int)$richtig[5] + 1) % 10);
    pruefe(totp_einrichtung_abschliessen($kid, $falsch, $t0) === null && !totp_an($kid), 'falscher Code richtet nicht ein');
    $codes = totp_einrichtung_abschliessen($kid, $richtig, $t0);
    pruefe(is_array($codes) && totp_an($kid) && totp_zustand($kid)['codes_offen'] === 10, 'eingerichtet, zehn Codes');
    pruefe(!totp_anmeldung_pruefen($kid, $richtig, $t0)['ok'], 'Einrichtungscode gilt nicht noch einmal');
    pruefe(totp_anmeldung_pruefen($kid, totp_code($roh, $t0 + 30), $t0 + 30)['ok'], 'nächster Code gilt');
    $r = totp_anmeldung_pruefen($kid, $codes[3], $t0 + 60);
    pruefe($r['ok'] && $r['art'] === 'code' && $r['codes_offen'] === 9, 'Wiederherstellungscode gilt einmal');
    pruefe(!totp_anmeldung_pruefen($kid, $codes[3], $t0 + 60)['ok'], 'derselbe Wiederherstellungscode kein zweites Mal');
    $v = (int)konto_laden($kid)['sitzung_version'];
    totp_zuruecksetzen($kid, 'test');
    pruefe(!totp_an($kid) && totp_zustand($kid)['codes_offen'] === 0 && (int)konto_laden($kid)['sitzung_version'] === $v + 1, 'Zurücksetzen: Faktor aus, Codes weg, Sitzungen beendet');

    abschnitt('Löschfrist für Konten (E-54)');
    // Lea (teilnehmend, Konto aus den Beispieldaten) und Anna (Team) seit knapp 14 Monaten ohne Login
    $jetzt = time();
    $alt = gmdate('Y-m-d H:i:s', $jetzt - (14 * 30 - 20) * 86400); // in knapp 20 Tagen 14 Monate ohne Login
    $altern = fn() => db_ausfuehren('UPDATE konto SET email_bestaetigt_am = ?, letzte_anmeldung = ?, erstellt_am = ? WHERE id IN (?, ?)', [$alt, $alt, $alt, $lea, $anna]);
    $erinnert = fn(int $id) => db_wert('SELECT loesch_erinnert_am FROM konto WHERE id = ?', [$id]) !== null;
    $altern();
    $r = tk_loeschfrist($jetzt);
    pruefe($r === ['erinnert' => 1, 'geloescht' => 0] && str_contains((string)(test_mail_an('lea@example.org')['text'] ?? ''), 'einmal ein') && $erinnert($lea), 'Erinnerung 30 Tage vor der Löschung, per Mail');
    pruefe(!$erinnert($anna), 'Team-Konten ausgenommen');
    pruefe(tk_loeschfrist($jetzt)['erinnert'] === 0, 'Keine zweite Erinnerung');
    pruefe(tk_loeschfrist($jetzt + 25 * 86400)['geloescht'] === 0 && konto_laden($lea) !== null, 'Nicht vor Ablauf von 30 Tagen nach der Erinnerung gelöscht');
    $_SESSION = [];
    tk_einloggen(konto_laden($lea));
    pruefe(!$erinnert($lea) && tk_loeschfrist($jetzt + 31 * 86400)['geloescht'] === 0 && konto_laden($lea) !== null, 'Login setzt die Frist neu');
    $_SESSION = [];
    ctx_weg('ich', 'anmeldung_konto');
    $altern();
    tk_loeschfrist($jetzt);
    $r = tk_loeschfrist($jetzt + 31 * 86400);
    pruefe($r['geloescht'] === 1 && konto_laden($lea) === null && konto_laden($anna) !== null, 'Nach der Frist gelöscht, Team-Konto bleibt');
    $leaAnm = db_zeilen("SELECT konto_id, status FROM anmeldung WHERE email = 'lea@example.org'");
    pruefe(count($leaAnm) === 3 && array_unique(array_column($leaAnm, 'konto_id')) === [null] && anm_laden($f['bd']['anmeldungen']['lea'])['status'] === 'bestaetigt', 'Anmeldungen bleiben (ohne Konto), in allen drei Veranstaltungen');

    abschnitt('Passkeys (O-14, E-55)');
    // eigener Ausgangsstand: Lea ist oben gelöscht
    $f = test_frisch();
    $kp = $f['bd']['anmeldungen']['lea_konto'];
    $kt = $f['admin'];
    require_once dirname(__DIR__) . '/passkey_hilfe.php';
    $hostAlt = ctx('host') ?? null;
    ctx_setzen('host', 'localhost');
    $u = pk_ursprung();
    pruefe($u === ['ursprung' => 'http://localhost', 'rp_id' => 'localhost'], 'Ursprung aus der Adresse (localhost ohne HTTPS erlaubt)');
    ctx_setzen('host', '127.0.0.1');
    pruefe(pk_ursprung() === null, 'Keine Passkeys unter einer IP-Adresse');
    ctx_setzen('host', 'localhost');
    $pkFehlt = function (callable $fn): bool {
        try {
            $fn();
            return false;
        } catch (PkFehler) {
            return true;
        }
    };
    $g = test_pk_geraet();
    $o = pk_anlage_optionen(konto_laden($kp));
    pruefe($o['authenticatorSelection']['userVerification'] === 'required' && $o['attestation'] === 'none' && strlen(pk_b64u_lesen($o['user']['id'])) === 16, 'Anlage-Optionen: UV verlangt, keine Herstellerdaten, Nutzerkennung ohne Kontonummer');
    $pid = pk_anlegen($kp, test_pk_anlage($g, $o['challenge'], 'localhost', 'http://localhost'), "Handy\u{0007}  von Lea");
    pruefe($pid > 0 && pk_zahl($kp) === 1 && pk_liste($kp)[0]['bezeichnung'] === 'Handy von Lea', 'Passkey angelegt (ES256), Name bereinigt');
    pruefe($pkFehlt(fn() => pk_anlegen($kp, test_pk_anlage($g, $o['challenge'], 'localhost', 'http://localhost'), '')), 'Herausforderung gilt nur einmal');
    $o = pk_login_optionen();
    pruefe(pk_anmelden(test_pk_login($g, $o['challenge'], 'localhost', 'http://localhost')) === $kp && (int)db_wert('SELECT zaehler FROM passkey WHERE id = ?', [$pid]) === 1, 'Einloggen mit Passkey, Zähler gespeichert');
    $o = pk_login_optionen();
    pruefe($pkFehlt(fn() => pk_anmelden(test_pk_login($g, $o['challenge'], 'localhost', 'http://localhost', ['zaehler' => 1]))), 'Zähler zurückgelaufen: abgelehnt (kopierter Passkey)');
    foreach (['ohne UV (nur Geste)' => ['flags' => 0x01], 'fremder Ursprung' => ['origin' => 'https://boese.example'], 'fremde rpId' => ['rp' => 'boese.example'], 'Signatur verändert' => ['sig_kaputt' => true]] as $was => $opt) {
        $o = pk_login_optionen();
        pruefe($pkFehlt(fn() => pk_anmelden(test_pk_login($g, $o['challenge'], 'localhost', 'http://localhost', $opt))), "Anmeldung abgelehnt: $was");
    }
    pruefe($pkFehlt(fn() => pk_anmelden(test_pk_login($g, 'AAAA', 'localhost', 'http://localhost'))), 'Ohne gestellte Herausforderung abgelehnt');
    $g2 = test_pk_geraet();
    $o = pk_anlage_optionen(konto_laden($kp));
    pruefe($pkFehlt(fn() => pk_anlegen($kp, test_pk_anlage($g2, $o['challenge'], 'localhost', 'http://localhost', ['flags' => 0x41]), '')), 'Anlage ohne UV abgelehnt');
    $o = pk_anlage_optionen(konto_laden($kp));
    pruefe($pkFehlt(fn() => pk_anlegen($kp, test_pk_anlage($g2, $o['challenge'], 'localhost', 'http://localhost', ['cose' => $g2['cose'] + [-4 => new TestBytes(str_repeat('x', 32))]]), '')), 'COSE mit zusätzlichem Feld (privater Teil) abgelehnt');
    $o = pk_anlage_optionen(konto_laden($kp));
    pruefe($pkFehlt(fn() => pk_anlegen($kp, test_pk_anlage($g2, $o['challenge'], 'localhost', 'http://localhost', ['cose' => [1 => 2, 3 => -7, -1 => 1, -2 => new TestBytes(str_repeat("\xff", 32)), -3 => new TestBytes(str_repeat("\x01", 32))]]), '')), 'Punkt außerhalb der Kurve abgelehnt');
    $o = pk_anlage_optionen(konto_laden($kp));
    pruefe($pkFehlt(fn() => pk_anlegen($kp + 999, test_pk_anlage($g2, $o['challenge'], 'localhost', 'http://localhost'), '')), 'Herausforderung gehört zum Konto');
    $gr = test_pk_geraet('rs256');
    $o = pk_anlage_optionen(konto_laden($kp));
    pk_anlegen($kp, test_pk_anlage($gr, $o['challenge'], 'localhost', 'http://localhost'), 'Laptop');
    $o = pk_login_optionen();
    pruefe(pk_anmelden(test_pk_login($gr, $o['challenge'], 'localhost', 'http://localhost')) === $kp && pk_zahl($kp) === 2, 'RS256 (2048 Bit): anlegen und einloggen');
    // E-75: mehrere offene Herausforderungen (Adressfeld, Knopf, zweiter Tab) machen sich nicht ungültig
    $o1 = pk_login_optionen();
    $o2 = pk_login_optionen();
    pruefe(pk_anmelden(test_pk_login($g, $o1['challenge'], 'localhost', 'http://localhost')) === $kp && pk_anmelden(test_pk_login($gr, $o2['challenge'], 'localhost', 'http://localhost')) === $kp, 'E-75: zwei Herausforderungen kurz nacheinander gelten beide');
    pruefe($pkFehlt(fn() => pk_anmelden(test_pk_login($g, $o1['challenge'], 'localhost', 'http://localhost'))), 'E-75: jede gilt weiter nur einmal');
    $o = [pk_login_optionen(), pk_login_optionen(), pk_login_optionen(), pk_login_optionen()];
    pruefe(count($_SESSION[SITZUNG_SCHLUESSEL_PK]['login']) === PK_OFFEN_MAX && $pkFehlt(fn() => pk_anmelden(test_pk_login($g, $o[0]['challenge'], 'localhost', 'http://localhost'))), 'E-75: höchstens ' . PK_OFFEN_MAX . ' offen, die älteste fällt heraus');
    pruefe(pk_anmelden(test_pk_login($g, $o[3]['challenge'], 'localhost', 'http://localhost')) === $kp, 'E-75: die neueste gilt');
    $o = pk_login_optionen();
    $_SESSION[SITZUNG_SCHLUESSEL_PK]['login'][count($_SESSION[SITZUNG_SCHLUESSEL_PK]['login']) - 1]['bis'] = time() - 1;
    pruefe($pkFehlt(fn() => pk_anmelden(test_pk_login($g, $o['challenge'], 'localhost', 'http://localhost'))), 'E-75: abgelaufene Herausforderung abgelehnt');
    // Team: Passkey als einziger zweiter Faktor
    pruefe(!zweitfaktor_da(konto_laden($kt)), 'Team ohne zweiten Faktor');
    $gt = test_pk_geraet();
    $o = pk_anlage_optionen(konto_laden($kt));
    $ptid = pk_anlegen($kt, test_pk_anlage($gt, $o['challenge'], 'localhost', 'http://localhost'), '');
    pruefe(zweitfaktor_da(konto_laden($kt)) && totp_zustand($kt)['eingerichtet'], 'Passkey zählt als zweiter Faktor');
    pruefe(pk_entfernen($kt, $ptid) !== null && pk_zahl($kt) === 1, 'Letzter zweiter Faktor lässt sich nicht entfernen');
    pruefe(pk_entfernen($kp, (int)db_wert('SELECT id FROM passkey WHERE konto_id = ? ORDER BY id LIMIT 1', [$kp])) === null && pk_zahl($kp) === 1, 'Teilnehmende: Passkey entfernen');
    pruefe(pk_entfernen($kp, $ptid) !== null && pk_zahl($kt) === 1, 'Fremden Passkey nicht entfernbar');
    totp_zuruecksetzen($kt, 'test');
    pruefe(pk_zahl($kt) === 0, 'Zurücksetzen des zweiten Faktors entfernt auch die Passkeys');
    db_ausfuehren('DELETE FROM konto WHERE id IN (?, ?)', [$kp, $kt]);
    pruefe((int)db_wert('SELECT COUNT(*) FROM passkey WHERE konto_id IN (?, ?)', [$kp, $kt]) === 0, 'Konto gelöscht: Passkeys weg');
    ctx_setzen('host', $hostAlt);
    unset($_SESSION[SITZUNG_SCHLUESSEL_PK]);
}
