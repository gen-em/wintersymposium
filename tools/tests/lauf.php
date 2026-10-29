<?php
declare(strict_types=1);

/*
 * Testlauf auf der Kommandozeile (lokal und im Prüf-Workflow):
 *
 *   php tools/tests/lauf.php
 *
 * Ohne Datenbank laufen nur die Teile ohne DB. Mit Datenbank die
 * Umgebungsvariablen setzen (eigene, leere Testdatenbank – sie wird geleert!):
 *
 *   WS_TEST_DB_HOST, WS_TEST_DB_PORT, WS_TEST_DB_NAME, WS_TEST_DB_USER, WS_TEST_DB_PASS
 *
 * Der HTTP-Teil startet den eingebauten PHP-Server auf 127.0.0.1.
 */

$wurzel = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ws-test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/var', 0700, true);

$mitDb = getenv('WS_TEST_DB_NAME') !== false && getenv('WS_TEST_DB_NAME') !== '';
$konfig = [
    'umgebung' => 'entwicklung',
    'db' => [
        'host' => getenv('WS_TEST_DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('WS_TEST_DB_PORT') ?: 3306),
        'name' => getenv('WS_TEST_DB_NAME') ?: '',
        'user' => getenv('WS_TEST_DB_USER') ?: '',
        'pass' => getenv('WS_TEST_DB_PASS') ?: '',
    ],
    'geheimnis' => str_repeat('g', 40),
    'installations_schluessel' => str_repeat('i', 40),
    'jobs_token' => str_repeat('j', 40),
    'health_token' => str_repeat('h', 40),
    'laufzeit_ordner' => $tmp . '/var',
];
file_put_contents($tmp . '/config.php', '<?php return ' . var_export($konfig, true) . ";\n");
putenv('WS_CONFIG=' . $tmp . '/config.php');

require $wurzel . '/server/bootstrap.php';
restore_exception_handler();

/* ---- Mini-Rahmen ---------------------------------------------------------- */

$GLOBALS['t_ok'] = 0;
$GLOBALS['t_fehler'] = [];

function pruefe(bool $bedingung, string $was): void
{
    if ($bedingung) {
        $GLOBALS['t_ok']++;
        echo "  ok    $was\n";
    } else {
        $GLOBALS['t_fehler'][] = $was;
        echo "  FEHLER $was\n";
    }
}

function abschnitt(string $titel): void
{
    echo "\n== $titel\n";
}

function aufraeumen_tmp(string $pfad): void
{
    if (!is_dir($pfad)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pfad, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $d) {
        $d->isDir() ? @rmdir($d->getPathname()) : @unlink($d->getPathname());
    }
    @rmdir($pfad);
}

/* ---- Ohne Datenbank -------------------------------------------------------- */

abschnitt('Formate');
pruefe(datum_text('2027-01-20 08:00:00') === '20.01.2027', 'datum_text');
pruefe(datum_zeit_text('2027-01-20 08:00:00') === '20.01.2027, 09:00', 'UTC → Europe/Berlin (Winterzeit)');
pruefe(datum_zeit_text('2026-07-01 08:00:00') === '01.07.2026, 10:00', 'UTC → Europe/Berlin (Sommerzeit)');
pruefe(lokal_nach_utc('2027-01-20 09:00') === '2027-01-20 08:00:00', 'Ortszeit → UTC');
pruefe(zahl_text(1234.5, 1) === '1.234,5', 'zahl_text');
pruefe(iso_utc('2026-10-29 12:00:00') === '2026-10-29T12:00:00Z', 'iso_utc');

abschnitt('Hostnamen (E-27)');
pruefe(host_normalisieren('Winter-Notfallsymposium.DE') === 'winter-notfallsymposium.de', 'Kleinschreibung');
pruefe(host_normalisieren('test.winter-notfallsymposium.de:443') === 'test.winter-notfallsymposium.de', 'Port entfernt');
pruefe(host_normalisieren('beispiel.de.') === 'beispiel.de', 'Punkt am Ende entfernt');
pruefe(host_normalisieren('evil.de/x') === null, 'Schrägstrich abgelehnt');
pruefe(host_normalisieren('-x.de') === null, 'Bindestrich am Anfang abgelehnt');
pruefe(host_normalisieren('') === null, 'leer abgelehnt');
pruefe(host_normalisieren('a b.de') === null, 'Leerzeichen abgelehnt');

abschnitt('Kopfzeilen');
$csp = kopf_csp();
pruefe(str_contains($csp, "'nonce-" . kopf_nonce() . "'"), 'CSP enthält Nonce');
pruefe(str_contains($csp, "default-src 'none'") && str_contains($csp, "frame-ancestors 'none'"), 'CSP streng');
pruefe(!str_contains($csp, 'unsafe-inline') && !str_contains($csp, 'http'), 'CSP ohne unsafe-inline und fremde Quellen');

abschnitt('Konfiguration');
pruefe(konfig_maengel() === [] || !$mitDb, 'Test-Konfiguration vollständig');
pruefe(umgebung() === 'entwicklung', 'Umgebung gelesen');
pruefe(preg_match('/^\d+\.\d+\.\d+$/', app_version()) === 1, 'VERSION lesbar (' . app_version() . ')');

abschnitt('Textauszeichnung (pflegbare Texte)');
pruefe(text_inline('Ein **fetter** und *betonter* Satz') === 'Ein <strong>fetter</strong> und <em>betonter</em> Satz', 'fett und betont');
pruefe(text_inline('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'HTML wird maskiert');
pruefe(str_contains(text_inline('[Kammer](https://www.blaek.de)'), '<a href="https://www.blaek.de" rel="noopener">Kammer</a>'), 'https-Link');
pruefe(!str_contains(text_inline('[x](javascript:alert(1))'), '<a '), 'javascript-Link abgelehnt');
pruefe(!str_contains(text_inline('[x](http://example.org)'), '<a '), 'http-Link (ohne s) abgelehnt');
pruefe(text_zu_html("## Titel\n\nZeile 1\nZeile 2\n\n- a\n- b") === "<h2>Titel</h2>\n<p>Zeile 1<br>Zeile 2</p>\n<ul><li>a</li><li>b</li></ul>", 'Absätze, Überschrift, Liste');
$abs = text_abschnitte("## Für wen\nAlle.\n## Wie\nSo.");
pruefe(count($abs) === 2 && $abs[1]['titel'] === 'Wie' && $abs[1]['text'] === 'So.', 'Abschnitte für Infokästen');
pruefe(tag_text('2027-01-20') === 'Mi 20.01.2027', 'Wochentag zum Datum');

abschnitt('Installer-Eingaben');
$gut = ['name' => 'Test', 'email' => 'a@b.de', 'passwort' => str_repeat('x', 12), 'passwort2' => str_repeat('x', 12), 'weitere_hosts' => "www.beispiel.de\n"];
pruefe(install_pruefen($gut) === [], 'gültige Eingabe');
pruefe(install_pruefen(['passwort2' => 'y'] + $gut) !== [], 'Passwörter verschieden');
pruefe(install_pruefen(['passwort' => 'kurz', 'passwort2' => 'kurz'] + $gut) !== [], 'Passwort zu kurz');
pruefe(install_pruefen(['email' => 'kein-at'] + $gut) !== [], 'E-Mail ungültig');
pruefe(install_pruefen(['weitere_hosts' => 'böse/host'] + $gut) !== [], 'Host ungültig');

abschnitt('Zweiter Faktor (RFC 6238) und Versiegeln');
$rfc = '12345678901234567890';
pruefe(totp_code($rfc, 59, 8) === '94287082', 'RFC 6238: T=59');
pruefe(totp_code($rfc, 1111111109, 8) === '07081804', 'RFC 6238: T=1111111109');
pruefe(totp_code($rfc, 2000000000, 8) === '69279037', 'RFC 6238: T=2000000000');
pruefe(totp_base32('foobar') === 'MZXW6YTBOI', 'Base32 (RFC 4648)');
$jetzt = 1_900_000_000;
$c = totp_code($rfc, $jetzt);
pruefe(totp_code_passt($rfc, $c, $jetzt, null) === totp_schritt($jetzt), 'aktueller Code passt');
pruefe(totp_code_passt($rfc, $c, $jetzt + 30, null) !== null, 'ein Schritt Toleranz');
pruefe(totp_code_passt($rfc, $c, $jetzt + 90, null) === null, 'drei Schritte später abgelehnt');
pruefe(totp_code_passt($rfc, $c, $jetzt, totp_schritt($jetzt)) === null, 'derselbe Schritt kein zweites Mal');
pruefe(totp_code_passt($rfc, '12a456', $jetzt, null) === null, 'keine Ziffern abgelehnt');
$codes = totp_codes_erzeugen();
pruefe(count($codes) === TOTP_CODES && count(array_unique($codes)) === TOTP_CODES, 'zehn verschiedene Wiederherstellungscodes');
pruefe(totp_code_normieren(strtolower(totp_code_anzeige($codes[0]))) === $codes[0], 'Code mit Leerzeichen und klein erkannt');
pruefe(totp_code_normieren('ABCD-EFG0') === null, 'Code mit 0 abgelehnt');
pruefe(str_starts_with(totp_otpauth($rfc, 'a@b.de', 'Wintersymposium'), 'otpauth://totp/Wintersymposium:a%40b.de?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'), 'otpauth-Adresse');
$p = versiegeln('geheim', 'totp|1');
pruefe(str_starts_with($p, KRYPTO_PRAEFIX) && !str_contains($p, 'geheim'), 'versiegelt, kein Klartext');
pruefe(oeffnen($p, 'totp|1') === 'geheim', 'öffnen mit gleichem Zweck');
pruefe(oeffnen($p, 'totp|2') === null, 'anderer Zweck (anderes Konto) öffnet nicht');
pruefe(oeffnen(substr($p, 0, -4) . 'AAAA', 'totp|1') === null, 'beschädigtes Paket öffnet nicht');
pruefe(passwort_regel_fehler('kurz', 'kurz') !== null && passwort_regel_fehler(str_repeat('a', 12), str_repeat('a', 12)) === null, 'Passwortregeln');

/* ---- Mit Datenbank --------------------------------------------------------- */

if (!$mitDb) {
    echo "\n(Ohne WS_TEST_DB_NAME: Datenbank- und HTTP-Tests übersprungen.)\n";
} else {
    abschnitt('Datenbank leeren');
    db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (db_zeilen('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $z) {
        db()->exec('DROP TABLE `' . $z['t'] . '`');
    }
    db()->exec('SET FOREIGN_KEY_CHECKS = 1');
    pruefe(migration_ist() === 0, 'leere Datenbank');

    abschnitt('Migrationen');
    // Stand vor AP-3 nachbauen (Schritte 1–3) mit einem Team-Konto, dann den Rest anwenden.
    migration_tabelle_anlegen();
    foreach ([1, 2, 3] as $nr) {
        foreach (migration_katalog()[$nr]['sql'] as $sql) {
            db()->exec($sql);
        }
        db_ausfuehren('INSERT INTO schema_migration (nr, beschreibung, angewendet_am) VALUES (?, ?, ?)', [$nr, 'test', db_jetzt()]);
    }
    db_ausfuehren("INSERT INTO team_konto (id, email, name, passwort_hash, rolle, aktiv, erstellt_am, geaendert_am) VALUES (7, 'alt@example.org', 'Alt', 'h', 'betreiber', 1, ?, ?)", [db_jetzt(), db_jetzt()]);
    $neu = migration_anwenden();
    pruefe($neu === array_values(array_filter(array_keys(migration_katalog()), fn($n) => $n > 3)), 'restliche Schritte angewendet');
    $alt = db_zeile('SELECT id, rolle, totp_seit FROM konto WHERE email = ?', ['alt@example.org']);
    pruefe($alt !== null && (int)$alt['id'] === 7 && $alt['rolle'] === 'betreiber' && $alt['totp_seit'] === null, 'Team-Konto mit gleicher id in konto übernommen, ohne zweiten Faktor');
    pruefe(!db_tabelle_da('team_konto'), 'team_konto entfernt');
    pruefe(migration_anwenden() === [], 'zweiter Lauf wendet nichts an');
    pruefe(migration_ist() === migration_soll(), 'Stand = Soll');
    $zeit = (string)db_wert('SELECT @@session.time_zone');
    pruefe($zeit === '+00:00', 'DB-Sitzung in UTC');

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

    abschnitt('Konten und Links');
    $kid = konto_anlegen('Test Admin', ' Admin@Example.org ', 'admin');
    pruefe(konto_per_email('admin@example.org')['id'] == $kid, 'E-Mail normiert gespeichert');
    pruefe(konto_eingabe_fehler('X', 'admin@example.org', 'admin') !== [], 'doppelte E-Mail abgelehnt');
    $t0 = 1_900_000_000;
    $tok = konto_link_erzeugen($kid, 'einrichten', null, $t0);
    pruefe(db_wert('SELECT token_hash FROM konto_link WHERE konto_id = ?', [$kid]) === hash('sha256', $tok), 'nur Hash des Links gespeichert');
    pruefe(konto_link_finden($tok, $t0 + 60) !== null, 'Link gültig');
    pruefe(konto_link_finden($tok, $t0 + KONTO_LINK_GUELTIG_S + 1) === null, 'Link nach 72 h abgelaufen');
    $tok2 = konto_link_erzeugen($kid, 'passwort', null, $t0);
    pruefe(konto_link_finden($tok, $t0) === null && konto_link_finden($tok2, $t0) !== null, 'neuer Link ersetzt alten');
    pruefe(konto_link_aufraeumen($t0 + KONTO_LINK_GUELTIG_S + 1) === 1, 'abgelaufene Links aufgeräumt');
    $roh = totp_einrichtung_beginnen($kid);
    pruefe(!totp_an($kid) && totp_zustand($kid)['angefangen'], 'Einrichtung begonnen, noch nicht an');
    pruefe(totp_einrichtung_abschliessen($kid, '000000', $t0) === null || totp_code($roh, $t0) === '000000', 'falscher Code richtet nicht ein');
    $codes = totp_einrichtung_abschliessen($kid, totp_code($roh, $t0), $t0);
    pruefe(is_array($codes) && totp_an($kid) && totp_zustand($kid)['codes_offen'] === 10, 'eingerichtet, zehn Codes');
    pruefe(!totp_anmeldung_pruefen($kid, totp_code($roh, $t0), $t0)['ok'], 'Einrichtungscode gilt nicht noch einmal');
    pruefe(totp_anmeldung_pruefen($kid, totp_code($roh, $t0 + 30), $t0 + 30)['ok'], 'nächster Code gilt');
    $r = totp_anmeldung_pruefen($kid, $codes[3], $t0 + 60);
    pruefe($r['ok'] && $r['art'] === 'code' && $r['codes_offen'] === 9, 'Wiederherstellungscode gilt einmal');
    pruefe(!totp_anmeldung_pruefen($kid, $codes[3], $t0 + 60)['ok'], 'derselbe Wiederherstellungscode kein zweites Mal');
    $v = (int)konto_laden($kid)['sitzung_version'];
    totp_zuruecksetzen($kid, 'test');
    pruefe(!totp_an($kid) && totp_zustand($kid)['codes_offen'] === 0 && (int)konto_laden($kid)['sitzung_version'] === $v + 1, 'Zurücksetzen: Faktor aus, Codes weg, Sitzungen beendet');
    db_ausfuehren('DELETE FROM konto WHERE id = ?', [$kid]);

    abschnitt('Veranstaltungs-Einstellungen (AP-3 Schritt 2)');
    $gut = [];
    foreach (VA_KATALOG as $k => $_) {
        $gut[$k] = va_formularwert($k);
    }
    $r = va_pruefen($gut);
    pruefe($r['fehler'] === [], 'Startwerte bestehen die Prüfung');
    pruefe($r['werte']['anmeldung_start'] === '2026-11-24 23:00:00', 'Anmeldung ab 25.11.2026 00:00 Ortszeit = 24.11. 23:00 UTC');
    pruefe(va_formularwert('anmeldung_start') === '2026-11-25T00:00', 'Formular zeigt Ortszeit');
    $f = fn(array $x) => va_pruefen($x + $gut)['fehler'];
    pruefe(isset($f(['veranstaltung_datum' => '2027-02-30'])['veranstaltung_datum']), 'ungültiges Datum abgelehnt');
    pruefe(isset($f(['veranstaltung_datum' => ''])['veranstaltung_datum']), 'Pflichtfeld Datum');
    pruefe(isset($f(['veranstaltung_ende' => '17:00'])['veranstaltung_ende']), 'Ende vor Beginn abgelehnt');
    pruefe(isset($f(['veranstaltung_beginn' => '25:00'])['veranstaltung_beginn']), 'ungültige Uhrzeit abgelehnt');
    pruefe(isset($f(['veranstaltung_kapazitaet' => '-5'])['veranstaltung_kapazitaet']) && isset($f(['veranstaltung_kapazitaet' => '12a'])['veranstaltung_kapazitaet']), 'Kapazität nur ganze Zahlen im Bereich');
    pruefe(isset($f(['nachrueck_frist_h' => '0'])['nachrueck_frist_h']), 'Annahmefrist mindestens 1 h');
    pruefe(isset($f(['workshop_pflicht' => 'vielleicht'])['workshop_pflicht']), 'Auswahl nur aus der Liste');
    pruefe(isset($f(['stichtag_storno' => '2027-01-21'])['stichtag_storno']), 'Stichtag nach der Veranstaltung abgelehnt');
    pruefe(isset($f(['anmeldung_ende' => '2026-11-20T10:00'])['anmeldung_ende']), 'Anmeldeschluss vor Anmeldestart abgelehnt');
    pruefe(isset($f(['anmeldung_start' => '2027-02-01T10:00'])['anmeldung_start']), 'Anmeldestart nach der Veranstaltung abgelehnt');
    pruefe(isset($f(['berufsgruppen' => "ÄrztIn\närztin"])['berufsgruppen']), 'doppelte Berufsgruppe abgelehnt');
    $r = va_pruefen(['berufsgruppen' => "  ÄrztIn \n\n Pflege/MFA  \n", 'veranstaltung_ort' => "  Klinikum   Kempten "] + $gut);
    pruefe($r['werte']['berufsgruppen'] === "ÄrztIn\nPflege/MFA" && $r['werte']['veranstaltung_ort'] === 'Klinikum Kempten', 'Leerzeilen und Leerzeichen bereinigt');
    pruefe(va('veranstaltung_kapazitaet') === '240' && va_liste('berufsgruppen')[0] === 'ÄrztIn', 'ohne gespeicherten Wert gilt der Startwert');
    $geaendert = va_speichern(va_pruefen(['veranstaltung_kapazitaet' => '100'] + $gut)['werte'], null);
    pruefe($geaendert === ['veranstaltung_kapazitaet'] && va_zahl('veranstaltung_kapazitaet') === 100, 'Speichern meldet nur Geändertes');
    pruefe(in_array(['Freie Plätze', '100 / 100'], va_eckdaten(), true), 'Eckdaten mit Kapazität');
    va_speichern(va_pruefen(['veranstaltung_kapazitaet' => '0', 'cme_anzeige' => ''] + $gut)['werte'], null);
    $eckTitel = array_column(va_eckdaten(), 0);
    pruefe(!in_array('Freie Plätze', $eckTitel, true) && !in_array('CME', $eckTitel, true), 'Kapazität 0 und leere CME-Anzeige: nicht in den Eckdaten');
    db_ausfuehren("DELETE FROM einstellung WHERE schluessel IN ('" . implode("','", array_keys(VA_KATALOG)) . "')");

    abschnitt('Programm (AP-3 Schritt 3)');
    $r = programm_punkt_pruefen(['typ' => 'workshop', 'titel' => 'WS', 't_beginn' => ['20:00', '', '21:00'], 't_ende' => ['20:45', '', '20:30'], 't_plaetze' => ['10', '', '5']]);
    pruefe(isset($r['fehler']['termine']) && str_contains($r['fehler']['termine'], 'Termin 2'), 'Termin mit Ende vor Beginn abgelehnt (leere Zeile übersprungen)');
    $r = programm_punkt_pruefen(['typ' => 'workshop', 'titel' => 'WS', 't_beginn' => ['20:00'], 't_ende' => ['20:45'], 't_plaetze' => ['0']]);
    pruefe(isset($r['fehler']['termine']), 'Termin mit 0 Plätzen abgelehnt');
    $r = programm_punkt_pruefen(['typ' => 'vortrag', 'titel' => 'X', 'beginn' => '18:00']);
    pruefe(isset($r['fehler']['ende']), 'Beginn ohne Ende abgelehnt');
    $r = programm_punkt_pruefen(['typ' => 'pause', 'titel' => 'P']);
    pruefe($r['fehler'] === [], 'Punkt ohne Uhrzeit speicherbar (wird im Plan gezogen)');
    $r = programm_punkt_pruefen(['typ' => 'fremd', 'titel' => '']);
    pruefe(isset($r['fehler']['typ'], $r['fehler']['titel']), 'Typ und Titel Pflicht');
    $r = programm_punkt_pruefen(['typ' => 'block', 'titel' => 'B', 'beginn' => '19:00', 'ende' => '21:00', 'ort' => 'X', 'bring' => 'Y', 'kuerzel' => 'Z']);
    pruefe($r['werte']['ort'] === '' && $r['werte']['bring'] === '' && $r['werte']['kuerzel'] === '', 'Felder, die der Typ nicht hat, werden geleert');
    $mk = fn(int $id, string $typ, string $b, string $e, array $t = []) => ['id' => $id, 'typ' => $typ, 'kuerzel' => '', 'titel' => "T$id", 'untertitel' => '', 'beschreibung' => '', 'ort' => '', 'beginn' => $b, 'ende' => $e, 'bring' => '', 'notiz' => '', 'termine' => $t, 'dozenten' => []];
    $g = programm_gliederung([
        $mk(1, 'block', '19:00', '21:00'),
        $mk(2, 'workshop', '', '', [['id' => 7, 'beginn' => '19:00', 'ende' => '19:45', 'plaetze' => 5], ['id' => 8, 'beginn' => '21:30', 'ende' => '22:00', 'plaetze' => 5]]),
        $mk(3, 'vortrag', '18:00', '18:30'),
        $mk(4, 'station', '19:00', '21:00'),
        $mk(5, 'pause', '', ''),
    ]);
    pruefe(array_map(fn($x) => $x['art'] . ($x['termin']['id'] ?? $x['punkt']['id']), $g) === ['punkt5', 'punkt3', 'block1', 'termin8'], 'Gliederung: ohne Zeit oben, dann nach Uhrzeit; Termin außerhalb des Blocks einzeln');
    pruefe(count($g[2]['kinder']) === 2 && $g[2]['kinder'][0]['art'] === 'termin' && $g[2]['kinder'][1]['art'] === 'station', 'Block sammelt Termin und Station');
    $st = programm_status([$mk(1, 'vortrag', '18:00', '18:30'), $mk(2, 'pause', '19:00', '19:15')], ['punkte' => [$mk(1, 'vortrag', '18:00', '18:30'), $mk(3, 'pause', '20:00', '20:15')], 'am' => '']);
    pruefe($st['status'] === [1 => 'veroeffentlicht', 2 => 'neu'] && count($st['geloescht']) === 1 && $st['offen'], 'Status: veröffentlicht, neu, gelöscht');
    pruefe(programm_hinweise([array_merge($mk(1, 'vortrag', '18:00', '18:30'), ['ort' => 'A']), array_merge($mk(2, 'station', '18:15', '19:00'), ['ort' => 'a'])]) !== [], 'Raum doppelt belegt (Groß-/Kleinschreibung egal)');
    pruefe(hm_aus_minuten(minuten('23:30') + 90) === '23:59', 'Uhrzeit bleibt am selben Tag');

    abschnitt('Test-Beispieldatensatz (server/testdaten)');
    require_once dirname(__DIR__, 2) . '/server/testdaten/beispieldaten.php';
    $bd = beispieldaten_laden();
    $pv = programm_veroeffentlicht()['punkte'] ?? [];
    pruefe(count($pv) === count($bd['programm']['punkte']) && !programm_status(programm_arbeitsstand(), programm_veroeffentlicht())['offen'], 'Programm geladen und vollständig veröffentlicht');
    $bl = array_values(array_filter(programm_gliederung($pv), fn($x) => $x['art'] === 'block'));
    pruefe(count($bl) === 1 && count($bl[0]['kinder']) === 6, 'Workshop-Block mit 5 Terminen und 1 Station');
    // Einfügen mit Nachrücken (Admin 2026-10-29): 30-min-Pause nach Fall #1 (endet 19:15)
    $r = programm_punkt_pruefen(['typ' => 'pause', 'titel' => 'Eingefügt']);
    $neuId = programm_punkt_speichern(null, $r, null);
    $zeit = fn(string $titel) => db_zeile('SELECT TIME_FORMAT(beginn, "%H:%i") AS b, TIME_FORMAT(ende, "%H:%i") AS e FROM programmpunkt WHERE titel = ?', [$titel]);
    $neu = programm_verschieben('punkt', $neuId, '19:15', null, true);
    pruefe($neu === '19:15–19:45' && $GLOBALS['programm_nachgerueckt'] === 2 && $zeit('Workshops und offene Stationen') == ['b' => '19:45', 'e' => '21:45'] && $zeit('Abschluss') == ['b' => '21:45', 'e' => '22:15'], 'Einfügen: Block und Abschluss rücken nach hinten');
    pruefe(db_wert("SELECT MIN(beginn) FROM workshop_termin") === '19:45:00' && db_wert("SELECT beginn FROM programmpunkt WHERE typ = 'station'") === '19:45:00', 'Block nimmt beim Nachrücken Termine und Station mit');
    pruefe($zeit('Fall #1') == ['b' => '18:45', 'e' => '19:15'], 'Punkte davor bleiben');
    $r = programm_punkt_pruefen(['typ' => 'pause', 'titel' => 'Ohne Folgen']);
    $id2 = programm_punkt_speichern(null, $r, null);
    programm_verschieben('punkt', $id2, '23:00', null, true);
    pruefe($GLOBALS['programm_nachgerueckt'] === 0, 'Lücke danach: nichts rückt');
    programm_verwerfen(null);
    pruefe($zeit('Abschluss') == ['b' => '21:30', 'e' => '22:00'] && $zeit('Eingefügt') === null, 'Verwerfen stellt den Plan wieder her');

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
    preg_match('~/link\?t=([0-9a-f]{48})~', (string)$m['text'], $mm);
    pruefe((int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === $mails0 + 1 && $m['an'] === 'kim@example.org' && isset($mm[1]), 'Bestätigungsmail mit Link in der Warteschlange');
    pruefe(db_wert('SELECT COUNT(*) FROM anmeldung_link WHERE token_hash = ?', [hash('sha256', $mm[1] ?? '')]) == 1 && !str_contains((string)db_wert('SELECT token_hash FROM anmeldung_link ORDER BY id DESC LIMIT 1'), $mm[1] ?? 'x'), 'Link nur als Hash gespeichert');
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
    pruefe(db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE an = 'kim@example.org' AND betreff LIKE 'Platz frei%'") == 1, 'Angebotsmail an Kim');
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

    abschnitt('Konten für Teilnehmende (AP-4b)');
    $letzteMail = fn(string $an) => db_zeile('SELECT * FROM mail_ausgang WHERE an = ? ORDER BY id DESC LIMIT 1', [$an]);
    $kLink = fn(?array $m) => preg_match('~/konto/link\?t=([A-Za-z0-9_-]{40,60})~', (string)($m['text'] ?? ''), $x) === 1 ? $x[1] : '';
    $lk = konto_laden($bd['anmeldungen']['lea_konto'] ?? 0);
    pruefe($lk !== null && $lk['rolle'] === 'teilnehmend' && anmeldung_passwort_ok($lk, BEISPIEL_PASSWORT), 'Beispieldaten: Lea hat ein Konto mit Passwort');
    pruefe(passwort_regel_fehler('123456789', '123456789', PASSWORT_MIN_TN) !== null && passwort_regel_fehler('1234567890', '1234567890', PASSWORT_MIN_TN) === null && passwort_regel_fehler('1234567890', '1234567890') !== null, 'Passwort: Teilnehmende ab 10 Zeichen, Team weiter ab 12');
    tk_registrieren('Rita Registriert', 'rita@example.org');
    $rita = konto_per_email('rita@example.org');
    $tok = $kLink($letzteMail('rita@example.org'));
    pruefe($rita !== null && $rita['email_bestaetigt_am'] === null && $tok !== '', 'Konto ohne Anmeldung: unbestätigt, Mail mit Link');
    pruefe(isset(tk_login_pruefen('rita@example.org', 'egal-egal-egal')['fehler']), 'unbestätigtes Konto kommt nicht rein');
    $r = tk_link_einloesen($tok);
    pruefe($r !== null && $r['konto']['email_bestaetigt_am'] !== null && tk_link_einloesen($tok) === null, 'Link bestätigt die Adresse, gilt nur einmal');
    konto_passwort_setzen((int)$rita['id'], 'ritas-passwort');
    pruefe(isset(tk_login_pruefen('rita@example.org', 'ritas-passwort')['konto']) && isset(tk_login_pruefen('rita@example.org', 'falsch-falsch')['fehler']), 'Login mit Passwort');
    $vor = (int)db_wert('SELECT COUNT(*) FROM mail_ausgang');
    tk_registrieren('Rita Nochmal', 'rita@example.org');
    pruefe(str_starts_with((string)$letzteMail('rita@example.org')['betreff'], 'Du hast schon ein Konto') && konto_per_email('rita@example.org')['name'] === 'Rita Registriert', 'gleiche Adresse: kein zweites Konto, Hinweis per Mail');
    $teamId = konto_anlegen('Team Test', 'team-test@example.org', 'admin');
    tk_registrieren('Team', 'team-test@example.org');
    pruefe((int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === $vor + 1, 'Team-Adresse: keine Mail');
    // Anmeldung mit eingeloggtem Konto: sofort bestätigt und verknüpft
    $da = ['vorname' => 'Rita', 'nachname' => 'Registriert', 'email' => 'rita@example.org', 'berufsgruppe' => 'StudentIn', 'institution' => 'Uni', 'efn' => '', 'essen' => 'egal'];
    $e = anm_anlegen(anm_daten_pruefen($da)['werte'], ['fest' => [], 'warte' => []], false, null, false, konto_laden((int)$rita['id']));
    pruefe($e['art'] === 'neu' && $e['bestaetigt'] === true && anm_laden($e['id'])['konto_id'] == $rita['id'], 'Anmeldung mit Konto: ohne Bestätigungsmail, verknüpft');
    // Häkchen „Konto anlegen“: nach dem Bestätigungslink Konto angelegt und verknüpft
    $e2 = anm_anlegen(anm_daten_pruefen(['vorname' => 'Kurt', 'email' => 'kurt@example.org'] + $da)['werte'], ['fest' => [], 'warte' => []], false, null, true);
    preg_match('~/link\?t=([0-9a-f]{48})~', (string)$letzteMail('kurt@example.org')['text'], $mk);
    $r = anm_link_einloesen($mk[1] ?? '');
    $kurt = konto_per_email('kurt@example.org');
    pruefe($r !== null && !empty($r['konto_neu']) && $kurt !== null && $kurt['passwort_hash'] === null && anm_laden($e2['id'])['konto_id'] == $kurt['id'], 'Häkchen beim Anmelden: Konto nach der Bestätigung, Passwort folgt');
    // Verknüpfung: Anmeldung erst, Konto danach mit gleicher Adresse
    $e3 = anm_anlegen(anm_daten_pruefen(['vorname' => 'Vera', 'email' => 'vera@example.org'] + $da)['werte'], ['fest' => [], 'warte' => []], false);
    db_ausfuehren("UPDATE anmeldung SET status = 'bestaetigt' WHERE id = ?", [$e3['id']]);
    tk_registrieren('Vera V', 'vera@example.org');
    tk_link_einloesen($kLink($letzteMail('vera@example.org')));
    pruefe(anm_laden($e3['id'])['konto_id'] == konto_per_email('vera@example.org')['id'], 'bestätigtes Konto und Anmeldung mit gleicher Adresse verknüpft (E-48)');
    // Neue Adresse: Konto und Anmeldung ziehen mit
    tk_email_aendern((int)$rita['id'], 'rita.neu@example.org');
    pruefe(konto_laden((int)$rita['id'])['email'] === 'rita@example.org', 'neue Adresse gilt erst nach Bestätigung');
    tk_link_einloesen($kLink($letzteMail('rita.neu@example.org')));
    pruefe(konto_laden((int)$rita['id'])['email'] === 'rita.neu@example.org' && anm_laden($e['id'])['email'] === 'rita.neu@example.org', 'nach dem Link: Konto und Anmeldung mit neuer Adresse');
    // Löschen: Anmeldung bleibt bzw. wird storniert (Admin 2026-10-29)
    pruefe(tk_loeschen((int)$rita['id'], false) === null && konto_laden((int)$rita['id']) === null && anm_laden($e['id']) !== null && anm_laden($e['id'])['konto_id'] === null, 'Konto löschen, Anmeldung bleibt');
    pruefe(tk_loeschen((int)$kurt['id'], true) === null && konto_laden((int)$kurt['id']) === null && anm_laden($e2['id']) === null, 'Konto löschen mit Storno der Anmeldung');
    tk_registrieren('Ute Unbestätigt', 'unbestaetigt@example.org');
    $n = tk_aufraeumen(time() + 49 * 3600);
    pruefe($n >= 1 && konto_per_email('unbestaetigt@example.org') === null && konto_laden($teamId) !== null, "unbestätigte Konten nach 48 h gelöscht, Team-Konten nicht ($n)");
    db_ausfuehren('DELETE FROM konto WHERE id = ?', [$teamId]);

    abschnitt('Sicherung und Wiederherstellung (E-47)');
    $zahl = fn(string $t) => (int)db_wert("SELECT COUNT(*) FROM `$t`");
    $vorher = ['anmeldung' => $zahl('anmeldung'), 'programmpunkt' => $zahl('programmpunkt'), 'platz' => $zahl('platz'), 'konto' => $zahl('konto')];
    $s1 = sicherung_anlegen('Test');
    pruefe(sicherung_kopf($s1)['migration'] === migration_ist() && sicherung_pruefen($s1) === null, 'Sicherung angelegt und lesbar');
    db_ausfuehren('DELETE FROM anmeldung');
    db_ausfuehren("UPDATE programmpunkt SET titel = 'Geändert'");
    pruefe(sicherung_wiederherstellen($s1, null) === null, 'Wiederherstellen ohne Fehler');
    pruefe(['anmeldung' => $zahl('anmeldung'), 'programmpunkt' => $zahl('programmpunkt'), 'platz' => $zahl('platz'), 'konto' => $zahl('konto')] === $vorher && db_wert("SELECT COUNT(*) FROM programmpunkt WHERE titel = 'Geändert'") == 0, 'alle Daten zurück');
    pruefe(count(sicherung_liste()) === 2, 'Stand davor automatisch gesichert');
    pruefe(sicherung_pfad('../config.php') === null && sicherung_pruefen('sicherung-20260101-000000-abcdef.jsonl.gz') !== null, 'nur gültige Namen im eigenen Ordner');
    $kaputt = 'sicherung-20260101-000000-aaaaaa.jsonl.gz';
    $roh = (string)gzdecode((string)file_get_contents(sicherung_ordner() . '/' . $s1));
    file_put_contents(sicherung_ordner() . '/' . $kaputt, gzencode(substr($roh, 0, intdiv(strlen($roh), 2))));
    pruefe(str_contains((string)sicherung_pruefen($kaputt), 'unvollständig') && sicherung_wiederherstellen($kaputt, null) !== null && $zahl('konto') === $vorher['konto'], 'unvollständige Sicherung abgelehnt, nichts geändert');
    $neuer = 'sicherung-20260101-000000-bbbbbb.jsonl.gz';
    file_put_contents(sicherung_ordner() . '/' . $neuer, gzencode(preg_replace('/"migration":\d+/', '"migration":999', $roh, 1)));
    pruefe(str_contains((string)sicherung_pruefen($neuer), 'neueren Stand'), 'Sicherung aus neuerem Stand abgelehnt');
    pruefe(sicherung_loeschen($kaputt, null) && sicherung_loeschen($neuer, null) && count(sicherung_liste()) === 2, 'Sicherung löschen');
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

    // Für den HTTP-Teil wieder leer starten.
    db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (db_zeilen('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $z) {
        db()->exec('DROP TABLE `' . $z['t'] . '`');
    }
    db()->exec('SET FOREIGN_KEY_CHECKS = 1');

    require __DIR__ . '/http_tests.php';
    http_tests($wurzel, $tmp);
}

if (getenv("WS_TEST_BEHALTEN") !== "1") { aufraeumen_tmp($tmp); } else { echo "Testordner: $tmp\n"; }

$n = count($GLOBALS['t_fehler']);
echo "\n" . $GLOBALS['t_ok'] . " ok, $n Fehler\n";
foreach ($GLOBALS['t_fehler'] as $f) {
    echo "  - $f\n";
}
exit($n === 0 ? 0 : 1);
