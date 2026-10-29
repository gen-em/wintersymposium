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
