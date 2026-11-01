<?php
declare(strict_types=1);

/* Testbereich: Release 1.0 – Login-Sperre mit bekannten IPs (O-47), Suchmaschinen-Schalter, Adresse eines Team-Kontos ändern (O-48). */

function test_release(): void
{
    abschnitt('Login-Sperre: bekannte IPs (O-47, T-343)');
    $f = test_frisch();
    $anna = $f['admin'];
    $ipAlt = $_SERVER['REMOTE_ADDR'] ?? null;
    $falsch = function (int $n): void {
        for ($i = 0; $i < $n; $i++) {
            tk_login_pruefen(TEST_ADMIN, 'falsch-falsch-falsch');
        }
    };
    $_SERVER['REMOTE_ADDR'] = '198.51.100.10'; // Annas Rechner
    pruefe(!rate_bekannt($anna), 'vor dem ersten Login ist keine IP bekannt');
    $_SESSION = [];
    tk_einloggen(konto_laden($anna));
    $_SESSION = [];
    pruefe(rate_bekannt($anna), 'nach dem Login gilt die IP für das Konto als bekannt');
    $merkmale = array_column(db_zeilen('SELECT merkmal FROM login_bekannt WHERE konto_id = ?', [$anna]), 'merkmal');
    pruefe(count($merkmale) === 1 && strlen($merkmale[0]) === 64 && !str_contains(implode('', $merkmale), '198.51'), 'gespeichert nur ein HMAC (64 Zeichen), keine IP (R-02)');

    $_SERVER['REMOTE_ADDR'] = '203.0.113.66'; // Fremde
    pruefe(!rate_bekannt($anna), 'eine andere IP ist für das Konto nicht bekannt');
    $falsch(5);
    pruefe((tk_login_pruefen(TEST_ADMIN, TEST_PASSWORT)['fehler'] ?? '') === rate_text('login'), 'Fremde: nach 5 falschen Passwörtern ist die Adresse von fremden IPs gesperrt, auch mit richtigem Passwort');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
    pruefe(isset(tk_login_pruefen(TEST_ADMIN, TEST_PASSWORT)['fehler']), 'die Sperre je Adresse gilt auch für eine weitere fremde IP');

    $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
    pruefe((int)(tk_login_pruefen(TEST_ADMIN, TEST_PASSWORT)['konto']['id'] ?? 0) === $anna, 'Anna kommt von ihrer bekannten IP trotz der Sperre rein (Variante C)');
    $falsch(5);
    pruefe((tk_login_pruefen(TEST_ADMIN, TEST_PASSWORT)['fehler'] ?? '') === rate_text('login'), 'auch von der bekannten IP: nach 5 eigenen Fehlversuchen gesperrt (eigener Zähler je Konto und IP)');
    db_ausfuehren("DELETE FROM rate_limit WHERE topf = 'login_bekannt'");

    // 30 Tage ohne Login: IP vergessen; der Job räumt auf
    $jetzt = time();
    pruefe(rate_bekannt($anna, $jetzt + 29 * 86400) && !rate_bekannt($anna, $jetzt + 31 * 86400), 'bekannt bis 30 Tage nach dem letzten Login, danach nicht mehr');
    rate_aufraeumen($jetzt + 31 * 86400);
    pruefe(db_zahl('SELECT COUNT(*) FROM login_bekannt') === 0, 'Aufräumen (Job) löscht Einträge nach 30 Tagen');
    pruefe(in_array('login_bekannt', SICHERUNG_OHNE_INHALT, true), 'Sicherung: login_bekannt ohne Inhalt (wie rate_limit)');
    if ($ipAlt === null) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $ipAlt;
    }
    db_ausfuehren('DELETE FROM rate_limit');

    abschnitt('Suchmaschinen-Schalter (Release 1.0, T-344)');
    pruefe(!betrieb_suchmaschinen_schalter() && !betrieb_suchmaschinen_erlaubt(), 'nach der Installation aus');
    pruefe(betrieb_suchmaschinen_setzen(true, $f['betreiber']) !== null && !betrieb_suchmaschinen_schalter(), 'in der Entwicklung (und auf Staging) lässt er sich nicht einschalten');
    einstellung_setzen('suchmaschinen', '1');
    pruefe(!betrieb_suchmaschinen_erlaubt(), 'auch mit gesetztem Wert (z. B. aus einer Sicherung von Produktion) bleibt außerhalb der Produktion alles gesperrt');
    einstellung_setzen('suchmaschinen', '0');
    $cfgDatei = konfig_datei();
    $cfgAlt = (string)file_get_contents($cfgDatei);
    try {
        $cfg = require $cfgDatei;
        $cfg['umgebung'] = 'produktion';
        file_put_contents($cfgDatei, '<?php return ' . var_export($cfg, true) . ";\n");
        konfig_verwerfen();
        pruefe(!betrieb_suchmaschinen_erlaubt(), 'Produktion: am Anfang gesperrt');
        pruefe(betrieb_suchmaschinen_setzen(true, $f['betreiber']) === null && betrieb_suchmaschinen_erlaubt(), 'Produktion: BetreiberIn erlaubt Suchmaschinen');
        pruefe(db_zahl("SELECT COUNT(*) FROM audit WHERE art = 'betrieb' AND text = 'Suchmaschinen erlaubt'") === 1, 'im Protokoll');
        pruefe(betrieb_suchmaschinen_setzen(true, $f['betreiber']) === null && db_zahl("SELECT COUNT(*) FROM audit WHERE art = 'betrieb' AND text LIKE 'Suchmaschinen%'") === 1, 'gleicher Wert noch einmal: kein zweiter Eintrag');
        pruefe(betrieb_suchmaschinen_setzen(false, $f['betreiber']) === null && !betrieb_suchmaschinen_erlaubt(), 'Produktion: wieder gesperrt');
    } finally {
        file_put_contents($cfgDatei, $cfgAlt);
        konfig_verwerfen();
    }

    abschnitt('Adresse eines Team-Kontos ändern (O-48, T-345)');
    $f = test_frisch();
    // Olaf Orga: Admin mit eigener, verknüpfter Anmeldung (Beispieldatensatz, ein Konto für alles, E-54)
    $to = beispieldaten_team_anmeldung();
    $olaf = $to['konto'];
    $anmId = $to['anmeldung'];
    $alt = 'olaf.orga@example.org';
    konto_link_erzeugen($olaf, 'passwort', $f['betreiber']);
    db_ausfuehren('DELETE FROM mail_ausgang');
    pruefe(konto_adresse_aendern($olaf, 'kein-adresse', $f['betreiber']) !== null, 'ungültige Adresse abgelehnt');
    pruefe(konto_adresse_aendern($olaf, TEST_BETREIBER, $f['betreiber']) === 'Für diese E-Mail-Adresse gibt es schon ein Konto.', 'Adresse eines anderen Kontos abgelehnt');
    pruefe(konto_adresse_aendern($olaf, 'lea@example.org', $f['betreiber']) !== null, 'Adresse einer fremden Anmeldung oder eines Kontos abgelehnt');
    pruefe(konto_adresse_aendern($olaf, ' ' . strtoupper($alt) . ' ', $f['betreiber']) === 'Das ist schon die Adresse dieses Kontos.', 'gleiche Adresse (Groß/klein, Leerzeichen) erkannt');
    $lea = (int)konto_per_email('lea@example.org')['id'];
    pruefe(konto_adresse_aendern($lea, 'lea-neu@example.org', $f['betreiber']) === 'Dieses Konto gibt es nicht.', 'Teilnehmenden-Konten ändern ihre Adresse selbst (nicht hier)');
    konto_passwort_setzen($olaf, 'olafs-passwort-123');
    $ver = (int)konto_laden($olaf)['sitzung_version'];
    pruefe(konto_adresse_aendern($olaf, 'Olaf.Neu@Example.org', $f['betreiber']) === null, 'BetreiberIn ändert die Adresse');
    $k = konto_laden($olaf);
    pruefe($k['email'] === 'olaf.neu@example.org' && (int)$k['sitzung_version'] === $ver, 'gilt sofort, klein geschrieben; Sitzung bleibt');
    pruefe(anm_laden($anmId)['email'] === 'olaf.neu@example.org', 'verknüpfte Anmeldung zieht mit');
    pruefe(db_zahl("SELECT COUNT(*) FROM link WHERE art = 'konto' AND konto_id = ?", [$olaf]) === 0, 'offene Passwort-Links verfallen');
    $m = test_mail_an($alt);
    pruefe($m !== null && str_contains((string)$m['betreff'], 'Adresse') && str_contains((string)$m['text'], 'Olaf Orga') && test_mail_an('olaf.neu@example.org') === null, 'Hinweis an die alte Adresse (nicht an die neue)');
    $a = db_zeile("SELECT text, konto_id FROM audit WHERE art = 'konto' AND text LIKE '%Adresse durch BetreiberIn%' ORDER BY id DESC LIMIT 1");
    pruefe($a !== null && (int)$a['konto_id'] === $f['betreiber'] && !str_contains((string)$a['text'], '@'), 'Protokoll nennt die BetreiberIn, keine Adressen (R-02)');
    pruefe((int)(tk_login_pruefen('olaf.neu@example.org', 'olafs-passwort-123')['konto']['id'] ?? 0) === $olaf && isset(tk_login_pruefen($alt, 'olafs-passwort-123')['fehler']), 'Login mit der neuen Adresse, die alte geht nicht mehr');
    db_ausfuehren('DELETE FROM rate_limit');
}
