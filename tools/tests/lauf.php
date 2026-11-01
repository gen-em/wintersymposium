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

abschnitt('Kleine Helfer (AP-17 Unterpunkt 2)');
pruefe(datum_zeit_text('2027-01-20 18:30:00', uhr: true) === '20.01.2027, 19:30 Uhr', 'datum_zeit_text mit „Uhr“');
pruefe(dauer_text(0) === '0 Sekunden' && dauer_text(1) === 'eine Sekunde' && dauer_text(59) === '59 Sekunden', 'dauer_text: Sekunden');
pruefe(dauer_text(60) === 'eine Minute' && dauer_text(119) === 'eine Minute' && dauer_text(900) === '15 Minuten' && dauer_text(5400) === '90 Minuten', 'dauer_text: Minuten (auch 90 Minuten statt 1,5 Stunden)');
pruefe(dauer_text(3600) === 'eine Stunde' && dauer_text(48 * 3600) === '48 Stunden' && dauer_text(72 * 3600) === '72 Stunden', 'dauer_text: Stunden bis 72');
pruefe(dauer_text(86400 * 4) === '4 Tage' && dauer_text(400 * 86400) === '400 Tage' && dauer_text(-5) === '0 Sekunden', 'dauer_text: Tage, negativ wird 0');
pruefe(bytes_text(0) === '0 KB' && bytes_text(1) === '1 KB' && bytes_text(2048) === '2 KB', 'bytes_text: KB, mindestens 1');
pruefe(bytes_text(4 * 1024 * 1024) === '4 MB' && bytes_text((int)(1.5 * 1048576)) === '1,5 MB', 'bytes_text: MB glatt und mit Komma');
pruefe(person_name(['vorname' => ' Anna  ', 'nachname' => ' Muster ']) === 'Anna Muster', 'person_name: Leerzeichen');
pruefe(person_name(['vorname' => '', 'nachname' => 'Muster']) === 'Muster' && person_name([]) === '', 'person_name: fehlende Teile');
pruefe(hm('19:30:00') === '19:30' && minuten('19:30') === 1170 && hm_aus_minuten(1170) === '19:30' && hm_aus_minuten(-5) === '00:00', 'hm/minuten (jetzt format_lib)');
pruefe(db_zeit(0) === '1970-01-01 00:00:00' && db_ts('1970-01-02 00:00:00') === 86400 && db_tag(86400) === '1970-01-02', 'db_zeit/db_ts/db_tag');
pruefe(db_ts(db_zeit(1767225600)) === 1767225600, 'db_ts(db_zeit(x)) = x');
pruefe(rate_text('login') === 'Zu viele Versuche. Bitte warte 15 Minuten und versuche es dann noch einmal.', 'rate_text aus der Sperre des Topfs');
pruefe(rate_text('notzugang', 'falsche Codes') === 'Zu viele falsche Codes. Bitte warte eine Stunde und versuche es dann noch einmal.', 'rate_text mit eigenem Wort');
pruefe(anm_nummer(12) === '0012' && anm_nummer(12345) === '12345', 'anm_nummer');

abschnitt('Formular-Helfer (AP-17 Unterpunkt 3)');
// Eigener Prozess: csrf_feld() braucht eine Sitzung, die nach der ersten Ausgabe nicht mehr startet
foreach (explode("\n", trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/formular_helfer.php') . ' 2>&1'))) as $zeile) {
    [$ok, $was] = array_pad(explode('|', $zeile, 2), 2, $zeile);
    pruefe($ok === 'ok', $was);
}

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

abschnitt('Editor-Inhalte (ein Editor überall, E-54)');
pruefe(editor_bereinigen('<p>Ein <b>fetter</b> und <i>betonter</i> Satz</p>') === '<p>Ein <strong>fetter</strong> und <em>betonter</em> Satz</p>', 'fett und kursiv (b/i werden strong/em)');
pruefe(editor_bereinigen('<p>a<script>alert(1)</script><img src=x onerror=alert(1)></p>') === '<p>a</p>', 'Skript und fremdes Bild entfernt');
pruefe(editor_bereinigen('<p onclick="x()" style="color:red">Text</p>') === '<p>Text</p>', 'Attribute entfernt');
pruefe(str_contains(editor_bereinigen('<p><a href="https://www.blaek.de">Kammer</a></p>'), '<a href="https://www.blaek.de" rel="noopener">Kammer</a>'), 'https-Link');
pruefe(editor_bereinigen('<p><a class="knopf rot" href="https://example.org/a" onclick="x()">Los</a></p>') === '<p><a class="knopf" href="https://example.org/a" rel="noopener">Los</a></p>' && !str_contains(editor_bereinigen('<p><a class="knopf" href="https://example.org">x</a></p>', 'inline'), 'class='), 'Knopf in Absätzen bleibt Knopf, sonst nur Link (Admin 2026-10-31)');
pruefe(str_contains(editor_attribute(), 'data-werkzeug="fett kursiv titel | liste nliste link bild knopf | html"') && str_contains(editor_attribute(), 'data-bild-url="/team/texte/bild"') && !str_contains(editor_attribute(), 'data-platzhalter'), 'Editor: eine Leiste für alle mehrzeiligen Felder (Admin 2026-10-31)');
pruefe(str_contains(editor_attribute('block', true), 'data-platzhalter="') && str_contains(editor_attribute('block', true), 'data-bild-url="/team/mailings/bild"') && str_contains(editor_attribute('block', true), EDITOR_LEISTE['block']), 'Editor in Mails: dieselbe Leiste plus Platzhalter');
pruefe(str_contains(editor_attribute('inline'), 'data-werkzeug="fett kursiv link"') && !str_contains(editor_attribute('inline'), 'data-bild-url'), 'Editor einzeilig: fett, kursiv, Link');
pruefe(!str_contains(editor_bereinigen('<p><a href="javascript:alert(1)">x</a></p>'), '<a ') && !str_contains(editor_bereinigen('<p><a href="http://example.org">x</a></p>'), '<a '), 'javascript- und http-Link abgelehnt');
pruefe(editor_bereinigen('<h2>Titel</h2><ul><li>a</li></ul><ol><li>b</li></ol>') === '<h2>Titel</h2><ul><li>a</li></ul><ol><li>b</li></ol>', 'Überschrift, Liste, nummerierte Liste');
pruefe(editor_bereinigen("Zeile 1\nZeile 2\n\nAbsatz 2") === '<p>Zeile 1<br>Zeile 2</p><p>Absatz 2</p>', 'Klartext: Absätze und Zeilen');
pruefe(editor_bereinigen('<p>Titel <b>fett</b></p><p>zwei</p>', 'inline') === 'Titel <strong>fett</strong> zwei', 'Inline: nur fett, kursiv, Link in einer Zeile');
pruefe(str_contains(editor_bereinigen('<p><img src="/bild/text-0123456789abcdef.png" alt="Plan" data-breite="50"></p>', 'block', true), '<img src="/bild/text-0123456789abcdef.png" alt="Plan" data-breite="50">') && editor_bereinigen('<p><img src="/bild/text-0123456789abcdef.png"></p>') === '', 'Bilder nur eigene und nur, wo erlaubt');
pruefe(editor_text('<p>a <b>b</b></p><ul><li>c</li></ul>') === "a b\nc", 'Klartext für Längenprüfung');
$abs = editor_abschnitte('<h2>Für wen</h2><p>Alle.</p><h2>Wie</h2><p>So.</p>');
pruefe(count($abs) === 2 && $abs[1]['titel'] === 'Wie' && $abs[1]['text'] === '<p>So.</p>', 'Abschnitte für Infokästen');
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

abschnitt('Code-Konventionen (Konzept 5a, AP-17)');
require __DIR__ . '/konventionen.php';
konventionen_pruefen($wurzel);

/* ---- Mit Datenbank --------------------------------------------------------- */

if (!$mitDb) {
    echo "\n(Ohne WS_TEST_DB_NAME: Datenbank- und HTTP-Tests übersprungen.)\n";
} else {
    // Ein Bereich je Datei in tools/tests/lauf/ (NN_name.php mit test_name()),
    // jeder beginnt mit test_frisch() – siehe basis.php.
    require __DIR__ . '/basis.php';
    foreach (glob(__DIR__ . '/lauf/*.php') as $datei) {
        require $datei;
        $name = 'test_' . preg_replace('/^\d+_/', '', basename($datei, '.php'));
        if (test_gewaehlt($name)) {
            $t = microtime(true);
            $name();
            echo '  (' . substr($name, 5) . ': ' . test_dauer($t) . ")\n";
        }
    }

    if (test_gewaehlt('test_http')) {
        // Der HTTP-Teil beginnt mit leerer Datenbank (er prüft die Installation).
        test_db_leeren();
        require_once __DIR__ . '/seiten.php';
        require __DIR__ . '/http_tests.php';
        http_tests($wurzel, $tmp);
    }
}

if (getenv("WS_TEST_BEHALTEN") !== "1") { aufraeumen_tmp($tmp); } else { echo "Testordner: $tmp\n"; }

$n = count($GLOBALS['t_fehler']);
echo "\n" . $GLOBALS['t_ok'] . " ok, $n Fehler\n";
foreach ($GLOBALS['t_fehler'] as $f) {
    echo "  - $f\n";
}
exit($n === 0 ? 0 : 1);
