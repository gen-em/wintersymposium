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
    abschnitt('Datenbank leeren');
    db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (db_zeilen('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $z) {
        db()->exec('DROP TABLE `' . $z['t'] . '`');
    }
    db()->exec('SET FOREIGN_KEY_CHECKS = 1');
va_zwischenspeicher_leeren();
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
    // Einstellung der Veranstaltung und Startseiten-Text im alten Stand (vor E-58)
    db_ausfuehren("INSERT INTO einstellung (schluessel, wert, geaendert_am) VALUES ('veranstaltung_ort', 'Alter Ort', ?)", [db_jetzt()]);
    db_ausfuehren("UPDATE inhalt SET text = 'Alter Titel' WHERE schluessel = 'start_titel'");
    $neu = migration_anwenden();
    pruefe(va_zeile(1)['status'] === 'aktuell' && va_id() === 1 && va('veranstaltung_ort') === 'Alter Ort' && db_wert("SELECT COUNT(*) FROM einstellung WHERE schluessel = 'veranstaltung_ort'") == 0
        && inhalt('start_titel') === 'Alter Titel' && inhalt('impressum') === '', 'Migration 15: alles wird Veranstaltung 1 (aktuell), Einstellungen und Startseiten-Texte umgezogen');
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
    pruefe(db_wert('SELECT token_hash FROM link WHERE art = "konto" AND konto_id = ?', [$kid]) === hash('sha256', $tok), 'nur Hash des Links gespeichert');
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
    db_ausfuehren("DELETE FROM va_einstellung WHERE schluessel IN ('" . implode("','", array_keys(VA_KATALOG)) . "')");

    abschnitt('Programm (AP-3 Schritt 3)');
    $r = programm_punkt_pruefen(['typ' => 'workshop', 'titel' => 'WS', 't_beginn' => ['20:00', '', '21:00'], 't_ende' => ['20:45', '', '20:30'], 't_plaetze' => ['10', '', '5']]);
    pruefe(isset($r['fehler']['termine']) && str_contains($r['fehler']['termine'], 'Termin 2'), 'Termin mit Ende vor Beginn abgelehnt (leere Zeile übersprungen)');
    $r = programm_punkt_pruefen(['typ' => 'workshop', 'titel' => 'WS', 't_beginn' => ['20:00'], 't_ende' => ['20:45'], 't_plaetze' => ['0']]);
    pruefe(isset($r['fehler']['termine']), 'Termin mit 0 Plätzen abgelehnt');
    $r = programm_punkt_pruefen(['typ' => 'vortrag', 'titel' => 'X', 'beginn' => '18:00']);
    pruefe(isset($r['fehler']['ende']), 'Beginn ohne Ende abgelehnt');
    [$t1, $t3] = va_mit(va_aktuell_id() ?? 1, function (): array {
        $nameAlt = va('veranstaltung_name');
        einstellung_setzen('veranstaltung_name', 'Abend');
        $a = va_titel_html();
        einstellung_setzen('veranstaltung_name', 'Fortbildungsabend klinische Notfallmedizin');
        $b = va_titel_html();
        einstellung_setzen('veranstaltung_name', $nameAlt === '' ? null : $nameAlt);
        return [$a, $b];
    });
    pruefe($t1 === '<em>Abend</em>' && $t3 === 'Fortbildungsabend <em>klinische Notfallmedizin</em>', 'Titel der Startseite: ein Wort lila, sonst erstes Wort weiß und Rest lila');
    $r = programm_punkt_pruefen(['typ' => 'pause', 'titel' => 'P']);
    pruefe($r['fehler'] === [], 'Punkt ohne Uhrzeit speicherbar (wird im Plan gezogen)');
    $r = programm_punkt_pruefen(['typ' => 'vortrag', 'titel' => 'Bild', 'beschreibung' => '<p>Text <img src="/bild/text-0123456789abcdef.jpg" alt="x"></p><p><a class="knopf" href="https://example.org">Mehr</a></p>']);
    pruefe(str_contains($r['werte']['beschreibung'], '<img src="/bild/text-0123456789abcdef.jpg"') && str_contains($r['werte']['beschreibung'], 'class="knopf"'), 'Programm-Beschreibung: Bild und Knopf wie in den Texten (Admin 2026-10-31)');
    $d = dozent_pruefen(['name' => 'Bild Test', 'kurzbio' => '<p><img src="/bild/text-0123456789abcdef.png" alt=""></p>']);
    pruefe(str_contains($d['werte']['kurzbio'] ?? '', '<img src="/bild/text-0123456789abcdef.png"'), 'Kurzbio: Bild erlaubt');
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
    pruefe(count($g[2]['kinder']) === 2 && $g[2]['kinder'][0]['art'] === 'termin' && $g[2]['kinder'][1]['art'] === 'punkt', 'Block sammelt Termin und Station');
    $st = programm_status([$mk(1, 'vortrag', '18:00', '18:30'), $mk(2, 'pause', '19:00', '19:15')], ['punkte' => [$mk(1, 'vortrag', '18:00', '18:30'), $mk(3, 'pause', '20:00', '20:15')], 'am' => '']);
    pruefe($st['status'] === [1 => 'veroeffentlicht', 2 => 'neu'] && count($st['geloescht']) === 1 && $st['offen'], 'Status: veröffentlicht, neu, gelöscht');
    pruefe(programm_hinweise([array_merge($mk(1, 'vortrag', '18:00', '18:30'), ['ort' => 'A']), array_merge($mk(2, 'station', '18:15', '19:00'), ['ort' => 'a'])]) !== [], 'Raum doppelt belegt (Groß-/Kleinschreibung egal)');
    pruefe(hm_aus_minuten(minuten('23:30') + 90) === '23:59', 'Uhrzeit bleibt am selben Tag');

    abschnitt('Test-Beispieldatensatz (server/testdaten)');
    require_once dirname(__DIR__, 2) . '/server/testdaten/beispieldaten.php';
    $bd = beispieldaten_laden();
    $vj = tk_veranstaltungen($bd['anmeldungen']['lea_konto']);
    pruefe(va_zeile($bd['vorjahr']['id'])['status'] === 'abgeschlossen' && count($vj) === 3 && $vj[1]['zertifikat'] && $vj[1]['stand'] === 'teilgenommen' && $vj[2]['zertifikat'], 'Beispieldaten: abgeschlossenes Vorjahr, Lea mit Zertifikat im Rückblick');
    $ab = $bd['abend'];
    pruefe(va_zeile($ab['id'])['status'] === 'abgeschlossen' && !va_mit($ab['id'], fn() => va_an('mit_essen')) && va_von($ab['id'], 'veranstaltung_art') === 'vortrag'
        && tn_anwesend($ab['kai']) && !tn_anwesend($ab['nora']) && $vj[1]['kuerzel'] === 'fortbildungsabend-notfallmedizin', 'Beispieldaten: Fortbildungsabend ohne Essen, Kai da, Nora nicht erschienen, Lea mit drei Veranstaltungen');
    $ada = konto_laden($bd['admin']);
    pruefe($ada['rolle'] === 'admin' && va_erlaubte_ids($ada) === [(int)$ab['id']] && array_column(va_alle_fuer($ada), 'id') === [(int)$ab['id']] && va_darf($ada, (int)$ab['id']) && !va_darf($ada, (int)va_aktuell_id()), 'Beispieldaten: Ada (Admin) nur für den Fortbildungsabend (AP-12)');
    $r2 = $bd['review2'];
    $leaNl = nl_person_per_email('lea@example.org');
    pruefe(anm_laden($r2['fremd_ada'])['status'] === 'unbestaetigt' && anm_laden($r2['fremd_ada'])['email'] === 'ada.abend@example.org' && (!function_exists('anm_eigene_team') || anm_eigene_team($ada) === null)
        && (int)nl_liste($r2['liste_intern'])['sichtbar'] === 0 && $leaNl !== null && (nl_abos((int)$leaNl['id'])[$r2['liste_intern']] ?? '') === 'bestaetigt', 'Beispieldaten (AP-16): unbestätigte Anmeldung mit Adas Adresse, Lea auf ausgeblendeter Liste');
    $personenAda = va_mit((int)$ab['id'], fn() => team_personen($ada));
    $kontenAda = array_filter($personenAda, fn($p) => $p['art'] === 'konto' && $p['konto']['rolle'] === 'teilnehmend');
    pruefe(count(array_filter($personenAda, fn($p) => $p['art'] === 'anm')) === 3 && $kontenAda === [], 'Ada sieht im Abend dessen drei Anmeldungen, keine fremden Konten');
    pruefe(va_rechte_liste($ada) === [va_von((int)$ab['id'], 'veranstaltung_name') . ' (' . tag_text(va_von((int)$ab['id'], 'veranstaltung_datum')) . ')'] && count(va_rechte_liste(['id' => 0, 'rolle' => 'betreiber'])) === 2, 'Mein Konto: Liste „zuständig für“');
    $pv = programm_veroeffentlicht()['punkte'] ?? [];
    pruefe(count($pv) === count($bd['programm']['punkte']) && !programm_status(programm_arbeitsstand(), programm_veroeffentlicht())['offen'], 'Programm geladen und vollständig veröffentlicht');
    $bl = array_values(array_filter(programm_gliederung($pv), fn($x) => $x['art'] === 'block'));
    pruefe(count($bl) === 1 && count($bl[0]['kinder']) === 7, 'Workshop-Block mit 5 Terminen, 1 Station und 1 Fallbesprechung');
    // Einfügen mit Nachrücken (Admin 2026-10-29): 30-min-Pause nach Fall #1 (endet 19:15)
    $r = programm_punkt_pruefen(['typ' => 'pause', 'titel' => 'Eingefügt']);
    $neuId = programm_punkt_speichern(null, $r, null);
    $zeit = fn(string $titel) => db_zeile('SELECT TIME_FORMAT(beginn, "%H:%i") AS b, TIME_FORMAT(ende, "%H:%i") AS e FROM programmpunkt WHERE titel = ?', [$titel]);
    $neu = programm_verschieben('punkt', $neuId, '19:15', null, true);
    pruefe($neu === '19:15–19:45' && $GLOBALS['programm_nachgerueckt'] === 2 && $zeit('Workshops und offene Stationen') == ['b' => '19:45', 'e' => '21:45'] && $zeit('Abschluss') == ['b' => '21:45', 'e' => '22:15'], 'Einfügen: Block und Abschluss rücken nach hinten');
    pruefe(db_wert("SELECT MIN(t.beginn) FROM workshop_termin t JOIN programmpunkt p ON p.id = t.punkt_id WHERE p.veranstaltung_id = ?", [va_id()]) === '19:45:00' && db_wert("SELECT beginn FROM programmpunkt WHERE typ = 'station' AND veranstaltung_id = ?", [va_id()]) === '19:45:00', 'Block nimmt beim Nachrücken Termine und Station mit');
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
    preg_match('~/link\?t=([A-Za-z0-9_-]{43})~', (string)$m['text'], $mm);
    pruefe((int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === $mails0 + 1 && $m['an'] === 'kim@example.org' && isset($mm[1]), 'Bestätigungsmail mit Link in der Warteschlange');
    pruefe(db_wert('SELECT COUNT(*) FROM link WHERE token_hash = ?', [hash('sha256', $mm[1] ?? '')]) == 1 && !str_contains((string)db_wert('SELECT token_hash FROM link WHERE art = "anmeldung" ORDER BY id DESC LIMIT 1'), $mm[1] ?? 'x'), 'Link nur als Hash gespeichert');
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
    pruefe(db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE an = 'kim@example.org' AND betreff LIKE 'Platzangebot%'") == 1, 'Angebotsmail an Kim');
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
    preg_match('~/link\?t=([A-Za-z0-9_-]{43})~', (string)$letzteMail('kurt@example.org')['text'], $mk);
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

    abschnitt('Team-Bereich (AP-5)');
    $GLOBALS['ws_team_konto'] = null;
    $ws = fn(string $k) => (int)array_values(array_filter(anm_workshops(true), fn($w) => $w['kuerzel'] === $k))[0]['id'];
    $tt = function (string $k, string $b): int {
        foreach (anm_workshops() as $w) {
            foreach ($w['termine'] as $t) {
                if ($w['kuerzel'] === $k && $t['beginn'] === $b) {
                    return (int)$t['id'];
                }
            }
        }
        return 0;
    };
    $dt = ['vorname' => 'Theo', 'nachname' => 'Team', 'email' => 'theo@example.org', 'berufsgruppe' => 'StudentIn', 'institution' => 'Uni', 'efn' => '', 'essen' => 'egal'];
    $m0 = (int)db_wert('SELECT COUNT(*) FROM mail_ausgang');
    $t1 = team_anlegen($dt, false, true);
    pruefe(isset($t1['id']) && anm_laden($t1['id'])['status'] === 'bestaetigt' && anm_symposium_status($t1['id']) === 'fest' && (int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === $m0 + 1, 'Person anlegen: sofort bestätigt, Platz, Mail');
    pruefe(isset(team_anlegen($dt, false, true)['fehler']), 'gleiche Adresse: abgelehnt');
    $kapAlt = va('veranstaltung_kapazitaet');
    einstellung_setzen('veranstaltung_kapazitaet', (string)anm_belegt(0));
    $t2 = team_anlegen(['vorname' => 'Wanda', 'email' => 'wanda@example.org'] + $dt, false, false);
    $t3 = team_anlegen(['vorname' => 'Uwe', 'email' => 'uwe@example.org'] + $dt, true, false);
    pruefe($t2['warteliste'] === true && anm_symposium_status($t2['id']) === 'warte' && $t3['warteliste'] === false && anm_belegt(0) > (int)va('veranstaltung_kapazitaet'), 'Symposium voll: Warteliste, mit Bestätigung überbucht');
    pruefe((int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === $m0 + 1, '„ohne Mail“: keine Mail');
    einstellung_setzen('veranstaltung_kapazitaet', $kapAlt);
    $voll = $tt('WS 02', '20:30');
    while (anm_frei($voll) > 0) {
        $x = team_anlegen(['vorname' => 'Fill', 'email' => 'fill' . anm_belegt($voll) . '@example.org'] + $dt, true, false);
        team_platz_geben($x['id'], $voll, true, false);
    }
    $r = team_platz_geben($t1['id'], $voll, false, false);
    pruefe(isset($r['frage']) && str_contains($r['frage'][0], 'überbucht'), 'Platz geben in vollem Termin: fragt nach');
    $r = team_platz_geben($t1['id'], $voll, true, false);
    pruefe(isset($r['ok']) && anm_frei($voll) === 0 && anm_belegt($voll) > anm_kapazitaet($voll), 'mit Bestätigung überbucht');
    $r = team_platz_geben($t1['id'], $tt('WS 02', '19:30'), false, false);
    pruefe(isset($r['frage']) && str_contains(implode(' ', $r['frage']), 'storniert'), 'gleicher Workshop: Tausch wird angekündigt');
    team_platz_geben($t1['id'], $tt('WS 02', '19:30'), true, false);
    $pl = array_column(anm_plaetze($t1['id']), 'status', 'termin_id');
    pruefe(($pl[$tt('WS 02', '19:30')] ?? '') === 'fest' && !isset($pl[$voll]), 'nach Bestätigung: Tausch ausgeführt');
    pruefe(isset(team_platz_geben($t2['id'], $voll, true, false)['fehler']), 'Workshop nur mit Platz beim Symposium');
    einstellung_setzen('workshop_limit', '1');
    pruefe(anm_workshops_setzen($t3['id'], ['fest' => [$ws('WS 01') => $tt('WS 01', '19:30'), $ws('WS 02') => $tt('WS 02', '20:30')], 'warte' => []], null, true, true, false) === [], 'Team: ohne Workshop-Limit, mit Überbuchen');
    einstellung_setzen('workshop_limit', '0');
    // Warteliste: Angebot von Hand, Entfernen lässt nachrücken
    $wl = team_anlegen(['vorname' => 'Wilma', 'email' => 'wilma@example.org'] + $dt, false, false);
    db_ausfuehren("INSERT INTO platz (anmeldung_id, veranstaltung_id, termin_id, workshop_id, status, seit) VALUES (?, ?, ?, ?, 'warte', ?)", [$wl['id'], va_id(), $voll, $ws('WS 02'), db_jetzt()]);
    $wlPlatz = (int)db_wert('SELECT id FROM platz WHERE anmeldung_id = ? AND termin_id = ?', [$wl['id'], $voll]);
    pruefe(team_angebot($wlPlatz) !== null, 'Angebot ohne freien Platz abgelehnt');
    $fest = db_zeilen("SELECT id FROM platz WHERE termin_id = ? AND status = 'fest' ORDER BY id", [$voll]);
    foreach (array_slice($fest, 0, count($fest) - (int)anm_kapazitaet($voll) + 1) as $f) {
        team_platz_entfernen((int)$f['id'], false);
    }
    pruefe(db_wert('SELECT status FROM platz WHERE id = ?', [$wlPlatz]) === 'angebot', 'Platz entfernen: Nachrücken bietet ihn an');
    // Daten durch das Team: neue Adresse gilt sofort, doppelte abgelehnt
    $f = team_anm_daten($t1['id'], ['email' => 'theo.neu@example.org'] + $dt, false);
    pruefe($f === [] && anm_laden($t1['id'])['email'] === 'theo.neu@example.org', 'Team ändert Adresse sofort');
    pruefe(isset(team_anm_daten($t1['id'], ['email' => 'wanda@example.org'] + $dt, false)['email']), 'Adresse einer anderen Anmeldung abgelehnt');
    // Personenliste und Filter
    tk_registrieren('Nur Konto', 'nurkonto@example.org');
    $ps = team_personen();
    $nk = array_values(array_filter($ps, fn($p) => $p['email'] === 'nurkonto@example.org'));
    pruefe(count($nk) === 1 && $nk[0]['art'] === 'konto' && team_filter_passt($nk[0], 'unbestaetigt') && !team_filter_passt($nk[0], 'angemeldet'), 'Personen: Konto ohne Anmeldung in der Liste');
    pruefe(count(array_filter($ps, fn($p) => team_filter_passt($p, 'warteliste') && $p['email'] === 'wanda@example.org')) === 1 && team_suche_passt($nk[0], 'NURKONTO') && !team_suche_passt($nk[0], 'zzz'), 'Filter Warteliste, Suche ohne Groß-/Kleinschreibung');
    // Aufgaben nach der Grenze
    $datum = va('veranstaltung_datum');
    einstellung_setzen('veranstaltung_datum', gmdate('Y-m-d', time() + 5 * 86400));
    einstellung_setzen('veranstaltung_kapazitaet', (string)(anm_belegt(0) + 1));
    $auf = team_aufgaben();
    pruefe(count(array_filter($auf, fn($a) => $a['art'] === 'Manuell' && $a['link'] === '/team/workshops?t=0' && str_contains($a['text'], 'vorbei'))) === 1, 'Zu erledigen: freier Platz nach der Grenze');
    einstellung_setzen('veranstaltung_datum', $datum);
    einstellung_setzen('veranstaltung_kapazitaet', $kapAlt);
    // CSV und Export
    $csv = csv_text(['A', 'B'], [['=SUMME(1)', 'Müller; "x"']]);
    pruefe(str_starts_with($csv, "\u{FEFF}\"A\";\"B\"\r\n") && str_contains($csv, "\"'=SUMME(1)\";\"Müller; \"\"x\"\"\""), 'CSV: BOM, Semikolon, Formeln entschärft');
    $ex = export_erzeugen('teilnehmende');
    pruefe($ex !== null && str_contains($ex['text'], 'Theo') && str_ends_with($ex['datei'], '.csv'), 'Export Teilnehmende');
    $ex = export_erzeugen('cme');
    pruefe($ex !== null && !str_contains($ex['text'], 'Theo'), 'CME-Liste nur ÄrztInnen');
    pruefe(export_erzeugen('unbekannt') === null && export_erzeugen('termin', '', 999999) === null, 'unbekannter Export: nichts');
    // xlsx und PDF (O-35, E-56)
    pruefe(xlsx_spalte(0) === 'A' && xlsx_spalte(25) === 'Z' && xlsx_spalte(26) === 'AA' && xlsx_spalte(701) === 'ZZ', 'xlsx: Spaltenbuchstaben');
    pruefe(xlsx_datum('20.01.2027') === ['wert' => 46407, 'stil' => 2] && abs(xlsx_datum('20.01.2027, 12:00')['wert'] - 46407.5) < 1e-9 && xlsx_datum('31.02.2027') === null && xlsx_datum('Müller') === null, 'xlsx: Datum als Excel-Zahl');
    $xl = export_datei(['datei' => 'a.csv', 'basis' => 'a', 'titel' => 'Liste: [x]', 'kopf' => ['Name', 'Datum', 'Zahl'], 'zeilen' => [['=HYPERLINK("x")', '20.01.2027, 14:30', 3], ["Ä\x01<b>", '', 2.5]], 'text' => '', 'bg' => '', 'pdf' => null], 'xlsx');
    $xlTmp = tempnam(sys_get_temp_dir(), 'wst');
    file_put_contents($xlTmp, $xl['inhalt']);
    $zip = new ZipArchive();
    $zipOk = $zip->open($xlTmp) === true;
    $blatt = $zipOk ? (string)$zip->getFromName('xl/worksheets/sheet1.xml') : '';
    $mappe = $zipOk ? (string)$zip->getFromName('xl/workbook.xml') : '';
    pruefe($zipOk && $xl['datei'] === 'a.xlsx' && str_contains($xl['typ'], 'spreadsheetml') && $zip->getFromName('xl/styles.xml') !== false && $zip->getFromName('[Content_Types].xml') !== false, 'xlsx: Zip mit Mappe, Blatt und Stilen');
    pruefe(str_contains($blatt, 'state="frozen"') && str_contains($blatt, '<autoFilter ref="A1:C3"/>') && str_contains($blatt, 's="1" t="inlineStr"'), 'xlsx: Kopfzeile fixiert, mit Filter und Stil');
    pruefe(!str_contains($blatt, '<f>') && str_contains($blatt, '=HYPERLINK(&quot;x&quot;)') && str_contains($blatt, 'Ä&lt;b&gt;') && !str_contains($blatt, "\x01") && str_contains($blatt, '<v>3</v>') && str_contains($blatt, 's="3"><v>46407.6041'), 'xlsx: Text nie Formel, Zeichen maskiert, Zahl und Datum als Zahl');
    pruefe(str_contains($mappe, 'name="Liste   x "') || str_contains($mappe, 'name="Liste  x"') || preg_match('/sheet name="Liste[^"]*x[^"]*"/', $mappe) === 1, 'xlsx: Blattname ohne verbotene Zeichen');
    $zip->close();
    @unlink($xlTmp);
    $pdfText = function (array $d) : string {
        $f = tempnam(sys_get_temp_dir(), 'wsp');
        file_put_contents($f, $d['inhalt']);
        $t = (string)shell_exec('pdftotext -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
        @unlink($f);
        return $t;
    };
    $ex = export_erzeugen('teilnehmende');
    $pd = export_datei($ex, 'pdf');
    pruefe($pd !== null && str_starts_with($pd['inhalt'], '%PDF-') && $pd['datei'] === preg_replace('/\.csv$/', '.pdf', $ex['datei']), 'PDF Teilnehmende: Datei');
    if (trim((string)shell_exec('command -v pdftotext')) !== '') {
        $pt = $pdfText($pd);
        pruefe(str_contains($pt, 'Theo') && str_contains($pt, 'Seite 1 von') && str_contains($pt, 'TEILNEHMENDE') && !str_contains($pt, 'theo.neu@example.org'), 'PDF Teilnehmende: Namen, Seitenzahl, ohne E-Mail');
        $exT = export_erzeugen('termine');
        $ptT = $pdfText(export_datei($exT, 'pdf'));
        $seiten = substr_count($ptT, "\f");
        pruefe($seiten >= count(array_unique(array_column($exT['zeilen'], 0))) && str_contains($ptT, 'Plätzen belegt') && !str_contains($ptT, '@example.org'), 'PDF je Termin: eine Seite je Termin, Belegung, ohne E-Mail');
        $leer = export_datei(['datei' => 'l.csv', 'basis' => 'l', 'titel' => 'Leer', 'kopf' => ['A'], 'zeilen' => [], 'text' => '', 'bg' => '', 'pdf' => ['gruppe' => null, 'ohne' => [], 'quer' => false, 'info' => []]], 'pdf');
        pruefe(str_contains($pdfText($leer), 'Niemand eingetragen'), 'PDF ohne Zeilen: Hinweis statt leerer Tabelle');
        $lang = export_datei(['datei' => 'g.csv', 'basis' => 'g', 'titel' => 'Lang', 'kopf' => ['Name', 'Text'], 'zeilen' => array_fill(0, 120, ['Zora Zett', str_repeat('Workshop Atemweg ', 6)]), 'text' => '', 'bg' => 'ÄrztIn', 'pdf' => ['gruppe' => null, 'ohne' => [], 'quer' => false, 'info' => []]], 'pdf');
        $ptL = $pdfText($lang);
        pruefe(substr_count($ptL, 'Zora Zett') === 120 && substr_count($ptL, "\f") >= 3 && str_contains($ptL, 'Berufsgruppe: ÄrztIn'), 'PDF: lange Liste über mehrere Seiten, nichts verloren');
    }
    pruefe(export_datei(export_erzeugen('newsletter'), 'pdf') === null && export_datei($ex, 'doc') === null, 'Newsletter ohne PDF, unbekanntes Format nichts');
    pruefe(str_contains(export_knoepfe(['art' => 'cme']), 'format=pdf') && str_contains(export_knoepfe(['art' => 'cme']), 'format=xlsx') && !str_contains(export_knoepfe(['art' => 'newsletter']), 'format=pdf'), 'Knöpfe CSV, Excel, PDF (Newsletter ohne PDF)');
    $sd = stat_daten();
    pruefe($sd['angemeldet'] === (int)db_wert("SELECT COUNT(*) FROM anmeldung a JOIN platz s ON s.anmeldung_id = a.id AND s.termin_id = 0 AND s.status = 'fest' WHERE a.status = 'bestaetigt' AND a.veranstaltung_id = ?", [va_id()]) && array_sum($sd['berufsgruppen']) === $sd['bestaetigt'] && stat_daten('StudentIn')['bestaetigt'] <= $sd['bestaetigt'], 'Statistik stimmt mit der Datenbank (mit Filter)');
    pruefe(str_contains(stat_balken(['A' => 2, 'B' => 4], 'T'), 'width="50"') && str_contains(stat_balken(['A' => 2, 'B' => 4], 'T'), 'width="100"'), 'Balken als SVG-Attribut (keine Inline-Stile)');
    // Rolle vergeben (Teilnehmende → Team)
    $nkId = (int)konto_per_email('nurkonto@example.org')['id'];
    pruefe(team_rolle_vergeben($nkId, 'admin') && konto_laden($nkId)['rolle'] === 'admin' && str_contains((string)db_wert("SELECT text FROM mail_ausgang WHERE an = 'nurkonto@example.org' ORDER BY id DESC LIMIT 1"), '/einrichten?t='), 'Rolle vergeben: Admin, Mail mit Einrichtungslink (ohne Passwort)');
    pruefe(!team_rolle_vergeben($nkId, 'betreiber'), 'nur Teilnehmende lassen sich ins Team holen');
    db_ausfuehren('DELETE FROM konto WHERE id = ?', [$nkId]);
    // Hosts und Absender
    host_anlegen('www.beispiel.test');
    pruefe(host_aendern('www.beispiel.test', 'aus', null) && !host_erlaubt('www.beispiel.test'), 'weiteren Host abschalten');
    einstellung_setzen('mail_absender', 'team@example.org');
    pruefe(mail_absender(mail_konfig()) === 'team@example.org', 'Absender aus der Einstellung vor config.php');
    einstellung_setzen('mail_absender', null);

    abschnitt('Mailings (AP-6)');
    // HTML bereinigen
    $bildTest = rm_bild_aus_daten((function (): string { $g = imagecreatetruecolor(1600, 400); ob_start(); imagejpeg($g); imagedestroy($g); return (string)ob_get_clean(); })(), null);
    pruefe($bildTest['breite'] === 1200 && $bildTest['hoehe'] === 300 && is_file(foto_ordner() . '/' . $bildTest['datei']), 'Bild auf 1200 px verkleinert und gespeichert');
    $sauber = rm_html_bereinigen('<h1 onclick="x()">Titel</h1><div>Zeile <b>fett</b> <i>k</i> <span style="color:red">rot</span></div><script>alert(1)</script>'
        . '<p><a href="javascript:alert(1)">böse</a> <a href="https://example.org/x?a=1&b=2">gut</a> <a class="knopf extra" href="{link}">Knopf</a></p>'
        . '<p><img src="https://fremd.example/x.png"><img src="/team/mailings/bild?id=' . $bildTest['id'] . '" alt="Plan" data-breite="50" onerror="x()"></p><iframe src="x"></iframe>');
    pruefe($sauber === '<h2>Titel</h2><p>Zeile <strong>fett</strong> <em>k</em> rot</p><p>böse <a href="https://example.org/x?a=1&amp;b=2">gut</a> <a href="{link}" class="knopf">Knopf</a></p><p><img src="/team/mailings/bild?id=' . $bildTest['id'] . '" alt="Plan" data-breite="50"></p>', 'HTML bereinigt: nur erlaubte Elemente, keine Skripte, fremden Bilder oder javascript:');
    pruefe(rm_html_bereinigen('<p>a &lt;b&gt; &amp; "c"</p>') === '<p>a &lt;b&gt; &amp; &quot;c&quot;</p>', 'Text bleibt maskiert');
    // Empfänger
    $angem = (int)db_wert("SELECT COUNT(*) FROM anmeldung a WHERE a.status = 'bestaetigt' AND a.veranstaltung_id = " . va_id() . " AND EXISTS (SELECT 1 FROM platz p WHERE p.anmeldung_id = a.id AND p.termin_id = 0 AND p.status = 'fest')");
    pruefe(count(rm_empfaenger('angemeldet')) === $angem && $angem > 3, 'Auswahl „Alle Angemeldeten“');
    pruefe(count(rm_empfaenger('angemeldet', 'StudentIn')) === (int)db_wert("SELECT COUNT(*) FROM anmeldung a WHERE a.status = 'bestaetigt' AND berufsgruppe = 'StudentIn' AND EXISTS (SELECT 1 FROM platz p WHERE p.anmeldung_id = a.id AND p.termin_id = 0 AND p.status = 'fest')"), 'zusätzlich nach Berufsgruppe');
    $wsT = $tt('WS 01', '19:30');
    pruefe(array_column(rm_empfaenger('termin:' . $wsT), 'id') == array_map('intval', array_column(db_zeilen("SELECT a.id FROM platz p JOIN anmeldung a ON a.id = p.anmeldung_id WHERE p.termin_id = ? AND p.status = 'fest' AND a.status = 'bestaetigt' ORDER BY a.nachname, a.vorname, a.id", [$wsT]), 'id')), 'Auswahl Workshop-Termin');
    pruefe(in_array('paula@example.org', array_column(rm_empfaenger('essen_kein'), 'email'), true) && !in_array('lea@example.org', array_column(rm_empfaenger('essen_kein'), 'email'), true), 'Auswahl „Ohne Essen“');
    pruefe(rm_empfaenger('unbekannt') === [] && !array_key_exists('termin:999999', rm_auswahl_optionen()), 'unbekannte Auswahl: niemand');
    // Tageslimit je Postfach: config kurz auf ein Limit von 5 setzen (Rundmails über anmeldung@ bis 4)
    $cfgDatei = konfig_datei();
    $cfgAlt = (string)file_get_contents($cfgDatei);
    $cfg = require $cfgDatei;
    $cfg['mail'] = ['modus' => 'datei', 'tageslimit' => 5, 'newsletter' => ['tageslimit' => 3]];
    file_put_contents($cfgDatei, '<?php return ' . var_export($cfg, true) . ";\n");
    konfig_verwerfen();
    db_ausfuehren("DELETE FROM mail_ausgang");
    pruefe(mail_limit('system') === ['limit' => 5, 'rundmail' => 4] && mail_limit('newsletter') === ['limit' => 3, 'rundmail' => 3] && mail_postfach_da('newsletter'), 'Limits je Postfach, Reserve für System-Mails');
    pruefe(mail_absender(mail_konfig('newsletter')) === 'newsletter@' . (host_haupt() ?? 'localhost'), 'Absender newsletter@ der Haupt-Domain');
    $w = rm_pruefen(['betreff' => "Info\nfür {vorname}", 'html' => '<p>Hallo {vorname} {nachname},</p><p>{workshops}</p><p><a class="knopf" href="{link}">Ansehen</a></p><p><img src="/team/mailings/bild?id=' . $bildTest['id'] . '" alt="Plan"></p>', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system']);
    pruefe($w['fehler'] === [] && $w['werte']['betreff'] === 'Info für {vorname}', 'Eingaben geprüft, Betreff einzeilig');
    $rmId = rm_speichern(null, $w['werte'], null);
    $n = rm_einreihen($rmId, null);
    pruefe($n === $angem && (int)db_wert('SELECT COUNT(*) FROM rundmail_empfaenger WHERE rundmail_id = ?', [$rmId]) === $angem && rm_laden($rmId)['status'] === 'versand', 'Senden: Empfängerliste im Archiv, alle in der Warteschlange');
    pruefe(db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE rundmail_id = ? AND (betreff <> '' OR text <> '')", [$rmId]) == 0, 'Warteschlange enthält nur Verweise, keinen Inhalt');
    pruefe(rm_einreihen($rmId, null) === null, 'zweites Senden nicht möglich');
    pruefe(rm_laden($rmId)['loeschen_ab'] === (new DateTimeImmutable(va('veranstaltung_datum')))->modify('+14 months')->format('Y-m-d'), 'Archiv bis 14 Monate nach dem Symposium (E-24)');
    // eine Person storniert inzwischen: ihre Mail entfällt
    $weg = (int)db_wert('SELECT anmeldung_id FROM mail_ausgang WHERE rundmail_id = ? ORDER BY id DESC LIMIT 1', [$rmId]);
    anm_stornieren($weg);
    db_ausfuehren("DELETE FROM mail_ausgang WHERE rundmail_id IS NULL");
    $vorMails = count(glob(laufzeit_pfad('mails') . '/*.eml') ?: []);
    $e1 = mail_senden_faellige(50, 20.0);
    pruefe($e1['gesendet'] === 4 && rm_fortschritt($rmId)['gesendet'] === 4, 'Rundmail über anmeldung@: 4 von 5 (Reserve bleibt frei)');
    mail_einreihen('system@example.org', 'System', 'Text');
    $e2 = mail_senden_faellige(50, 20.0);
    pruefe($e2['gesendet'] === 1 && db_wert("SELECT status FROM mail_ausgang WHERE rundmail_id IS NULL ORDER BY id DESC LIMIT 1") === 'gesendet', 'Bestätigungen gehen trotzdem sofort raus (Abnahme AP-6)');
    mail_einreihen('system2@example.org', 'System', 'Text');
    pruefe(mail_senden_faellige(50, 20.0)['gesendet'] === 1, 'auch über dem Tageslimit');
        pruefe(rm_fortschritt($rmId)['wartet'] === $angem - 4, 'Rest der Rundmail wartet auf den nächsten Tag');
    $morgen = time() + 86400;
    $e3 = mail_senden_faellige(50, 20.0, $morgen);
    pruefe($e3['gesendet'] === min(4, $angem - 5), 'am nächsten Tag geht es weiter');
    // Inhalt der gesendeten Mail
    $dateien = glob(laufzeit_pfad('mails') . '/*.eml') ?: [];
    sort($dateien);
    $roh = '';
    foreach ($dateien as $d) {
        // Seit O-40 haben alle HTML-Mails das Logo eingebettet: das Mailing am eigenen Bild erkennen
        if (str_contains((string)file_get_contents($d), 'Content-ID: <bild' . $bildTest['id'] . '@')) {
            $roh = (string)file_get_contents($d);
            break;
        }
    }
    $dek = quoted_printable_decode($roh);
    pruefe(str_contains($roh, 'Content-Type: multipart/related') && str_contains($roh, 'multipart/alternative') && preg_match('/Content-ID: <bild' . $bildTest['id'] . '@[^>]+>/', $roh) === 1 && str_contains($dek, 'src="cid:bild' . $bildTest['id'] . '@'), 'HTML-Mail mit Textfassung und eingebettetem Bild (Content-ID)');
    // Mails im Erscheinungsbild (O-40, Variante A): HTML aus dem Text, Knopf, Übersicht, Logo eingebettet
    $ueb = "Deine Anmeldung (Nr. 0009):\n\n- WS 02 Kleine PatientInnen · 19:30–20:00: Platz bestätigt\n- WS 01 Atemweg · 20:30–21:15: Warteliste · Platz 3 von 5\n- Essen: egal";
    [$bt, $tx] = mail_vorlage('bestaetigt', ['vorname' => 'Lea', 'uebersicht' => $ueb]);
    $hb = mail_html_aus_text('bestaetigt', $bt, $tx);
    pruefe(str_contains($hb, 'Anmeldung bestätigt') && str_contains($hb, '>Bestätigt</span>') && str_contains($hb, 'Warteliste · 3</span>') && str_contains($hb, '>WORKSHOP · 19:30–20:00</span>WS 02') && str_contains($hb, 'border-left:4px solid #FF5A5F') && str_contains($hb, '>ESSEN</span>egal')
        && str_contains($hb, '>Anmeldung ansehen oder ändern</a>') && !str_contains($hb, 'Ändern oder absagen') && str_contains($tx, 'Ändern oder absagen') && str_contains($hb, MAIL_LOGO_PLATZHALTER) && str_contains($hb, 'Impressum</a>') && !str_contains($tx, '<'), 'Mail-HTML: Übersicht als Tabelle mit Schildern, Knopf, Logo, Fuß; Text bleibt Text');
    [$ab, $at] = mail_vorlage('angebot', ['vorname' => 'Lea', 'was' => 'WS 01 · 20:30–21:15', 'frist' => 'Do 18:00', 'link' => 'https://example.org/link?t=abc']);
    $ha = mail_html_aus_text('angebot', $ab, $at);
    pruefe(str_starts_with($ab, 'Platzangebot:') && str_contains($ha, 'href="https://example.org/link?t=abc"') && str_contains($ha, '>Platz annehmen oder ablehnen</a>') && str_contains($ha, 'Knopf geht nicht? https://example.org/link?t=abc'), 'Mail-HTML: Link allein im Absatz wird Knopf mit Ersatzadresse');
    $rohM = mail_nachricht(mail_konfig(), 'lea@example.org', $ab, $at, ['html' => $ha]);
    pruefe(preg_match('/Content-ID: <logo@[^>]+>/', $rohM) === 1 && str_contains(quoted_printable_decode($rohM), 'src="cid:logo@') && !str_contains($rohM, MAIL_LOGO_PLATZHALTER) && is_file(mail_logo()[1]), 'Mail: Logo als eingebettetes PNG (Content-ID)');
    // Namen (E-67): allgemeine Mails im Namen der Reihe, Mails zu einer Anmeldung im Namen der Veranstaltung
    [$pb, $pt] = mail_vorlage('team_passwort', ['vorname' => 'Bea', 'link' => 'https://example.org/x']);
    pruefe(reihe_name() === 'Allgäu Akut' && $pb === 'Neues Passwort für den Team-Bereich von Allgäu Akut' && str_contains($pt, 'Dein Team von Allgäu Akut') && !str_contains($pb . $pt, 'Wintersymposium')
        && str_contains(mail_html_aus_text('team_passwort', $pb, $pt), 'Team-Bereich · Allgäu Akut') && str_contains($tx, 'Dein Team vom ' . va_kurz()), 'Mail-Namen: Team-Mail nennt die Reihe, Anmeldungs-Mail die Veranstaltung');
    einstellung_setzen('reihe_name', 'Testreihe');
    pruefe(mail_vorlage('konto_vorhanden', ['vorname' => 'X'])[0] === 'Du hast schon ein Konto bei Testreihe' && mail_absender_name(mail_konfig()) === 'Testreihe', 'Name der Reihe ist eine Einstellung');
    einstellung_setzen('reihe_name', null);
    [, $ct] = mail_vorlage('zweitfaktor_code', ['vorname' => 'Bea', 'code' => '123456']);
    pruefe(str_contains(mail_html_aus_text('zweitfaktor_code', 'Code', $ct), 'letter-spacing:6px;color:#001E3A;">123456</p>'), 'Mail-HTML: Code groß und gut lesbar');
    pruefe(preg_match('#Ansehen: http\S+/link\?t=[A-Za-z0-9_-]{43}#', $dek) === 1 && str_contains($dek, 'Hallo ') && !str_contains($dek, '{vorname}') && !str_contains($dek, '{link}') && str_contains($dek, '/impressum'), 'Platzhalter ersetzt, persönlicher Einmal-Link, Impressum');
    pruefe(!str_contains($roh, 'Auto-Submitted') && preg_match('/^Subject: .*f=C3=BCr.*Kim|^Subject: .*f=C3=BCr/m', $roh) === 1 && !str_contains($roh, '{vorname}'), 'Rundmail ohne Auto-Submitted, Betreff mit Vorname');
    $rest = rm_fortschritt($rmId)['wartet'];
    if ($rest > 0) {
        pruefe(str_contains((string)rm_abbrechen($rmId, 0), 'abgebrochen') && rm_fortschritt($rmId)['wartet'] === 0 && rm_laden($rmId)['status'] === 'abgebrochen', 'Restlichen Versand abbrechen');
    } else {
        rm_job(time());
        pruefe(rm_laden($rmId)['status'] === 'gesendet', 'fertig gesendet');
    }
    // Newsletter-Postfach eigenes Limit
    $w2 = rm_pruefen(['betreff' => 'NL', 'html' => '<p>Hallo</p>', 'auswahl' => 'angemeldet', 'postfach' => 'newsletter']);
    $nl = rm_speichern(null, $w2['werte'], null);
    rm_einreihen($nl, null);
    pruefe(mail_senden_faellige(50, 20.0, $morgen + 86400)['gesendet'] === 3 && db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE postfach = 'newsletter' AND status = 'gesendet'") == 3, 'newsletter@ mit eigenem Tageslimit');
    rm_abbrechen($nl, 0);
    // Planen und Job
    $pl = rm_speichern(null, $w['werte'], null);
    pruefe(rm_planen($pl, gmdate('Y-m-d H:i:s', time() + 3600), 0) && rm_laden($pl)['status'] === 'geplant', 'Mailing geplant');
    rm_job(time());
    pruefe(rm_laden($pl)['status'] === 'geplant', 'vor dem Zeitpunkt passiert nichts');
    pruefe(str_contains((string)rm_abbrechen($pl, 0), 'Entwurf') && rm_laden($pl)['status'] === 'entwurf', 'Planung aufheben → wieder Entwurf');
    rm_planen($pl, gmdate('Y-m-d H:i:s', time() + 3600), 0);
    $j = rm_job(time() + 3700);
    pruefe($j['gestartet'] === 1 && rm_laden($pl)['status'] === 'versand', 'Job startet das geplante Mailing');
    db_ausfuehren("UPDATE mail_ausgang SET status = 'gesendet', gesendet_am = ? WHERE rundmail_id = ?", [db_jetzt(), $pl]);
    pruefe(rm_job(time() + 3800)['fertig'] >= 1 && rm_laden($pl)['status'] === 'gesendet', 'Job schließt fertige Mailings ab');
    pruefe(rm_job(strtotime(rm_laden($pl)['loeschen_ab'] . ' 12:00:00 UTC'))['archiv'] > 0 && (int)db_wert('SELECT COUNT(*) FROM rundmail_empfaenger WHERE rundmail_id = ?', [$pl]) === 0, 'Empfängerliste nach der Löschfrist entfernt');
    $leer = rm_speichern(null, ['betreff' => 'Leer', 'html' => '<p>x</p>', 'auswahl' => 'termin:' . $wsT, 'berufsgruppe' => 'Sonstige', 'postfach' => 'system'], null);
    pruefe(rm_sendefehler(rm_laden($leer)) !== [] && rm_einreihen($leer, null) === null, 'ohne Empfänger nicht sendbar');
    $kopie = rm_kopieren($rmId, 0);
    pruefe(rm_laden($kopie)['status'] === 'entwurf' && rm_laden($kopie)['html'] === rm_laden($rmId)['html'], 'Als neue Mail kopieren');
    pruefe(rm_loeschen($kopie, 0) && !rm_loeschen($rmId, 0), 'nur Entwürfe lassen sich löschen');
    // Bilder aufräumen: unbenutzt und älter als ein Tag
    $alt = rm_bild_aus_daten((function (): string { $g = imagecreatetruecolor(10, 10); ob_start(); imagepng($g); imagedestroy($g); return (string)ob_get_clean(); })(), null);
    pruefe($alt['typ'] === 'png' && rm_bilder_aufraeumen(time() + 2 * 86400) >= 1 && rm_bild($alt['id']) === null && !is_file(foto_ordner() . '/' . $alt['datei']) && rm_bild($bildTest['id']) !== null, 'unbenutzte Bilder entfernt, benutzte bleiben');
    // Text-Bilder im Programm und bei Dozierenden bleiben beim Aufräumen stehen (Admin 2026-10-31)
    $tb = foto_ordner() . '/text-aaaaaaaaaaaaaaa1.jpg';
    $tbWeg = foto_ordner() . '/text-aaaaaaaaaaaaaaa2.jpg';
    file_put_contents($tb, 'x'); file_put_contents($tbWeg, 'x');
    touch($tb, time() - 3 * 86400); touch($tbWeg, time() - 3 * 86400);
    $dz = dozent_speichern(null, dozent_pruefen(['name' => 'Bild Aufräumen', 'kurzbio' => '<p><img src="/bild/text-aaaaaaaaaaaaaaa1.jpg" alt=""></p>'])['werte'], null);
    text_bilder_aufraeumen(time());
    pruefe(is_file($tb) && !is_file($tbWeg), 'Aufräumen: Bild aus einer Kurzbio bleibt, unbenutztes Text-Bild geht');
    dozent_loeschen($dz, null); @unlink($tb);
    // Team: Passwort vergessen per Mail (AP-6)
    $tkId = konto_anlegen('Tina Team', 'tina@example.org', 'admin', 'tina-ihr-passwort-1', null);
    tk_passwort_vergessen('tina@example.org');
    pruefe(str_contains((string)db_wert("SELECT text FROM mail_ausgang WHERE an = 'tina@example.org' ORDER BY id DESC LIMIT 1"), '/einrichten?t='), 'Team: Passwort vergessen schickt einen Link');
    db_ausfuehren('DELETE FROM konto WHERE id = ?', [$tkId]);
    file_put_contents($cfgDatei, $cfgAlt);
    konfig_verwerfen();
    // Tageslimit als Zahl vergleichen (Fehler gefunden mit AP-10): 4 gesendet, Grenze 360 → Rundmail geht raus
    db_ausfuehren('DELETE FROM mail_ausgang');
    for ($i = 0; $i < 4; $i++) {
        db_ausfuehren("INSERT INTO mail_ausgang (an, betreff, text, prio, status, versuche, naechster_versuch, erstellt_am, gesendet_am) VALUES ('', '', '', 1, 'gesendet', 0, ?, ?, ?)", [db_jetzt(), db_jetzt(), db_jetzt()]);
    }
    db_ausfuehren("INSERT INTO mail_ausgang (an, betreff, text, prio, status, versuche, naechster_versuch, erstellt_am) VALUES ('limit@example.org', 'Limit', 'Text', ?, 'offen', 0, ?, ?)", [MAIL_PRIO_RUNDMAIL, db_jetzt(), db_jetzt()]);
    pruefe(mail_limit('system')['rundmail'] >= 10 && mail_senden_faellige(5, 10.0)['gesendet'] === 1, 'Tageslimit als Zahl verglichen (4 gesendet, Grenze dreistellig)');
    db_ausfuehren('DELETE FROM mail_ausgang');

    abschnitt('Rückmeldung 2026-10-30: Block-Zuordnung, Mail-Code, Protokoll');
    // Block-Zuordnung
    $blockId = (int)db_wert("SELECT id FROM programmpunkt WHERE typ = 'block' LIMIT 1");
    $wsId = (int)db_wert("SELECT id FROM programmpunkt WHERE typ = 'workshop' ORDER BY id LIMIT 1");
    $r = programm_punkt_pruefen(['typ' => 'workshop', 'titel' => 'Ohne Termin', 'kuerzel' => 'WS 99', 'block_id' => (string)$blockId]);
    $neuWs = programm_punkt_speichern(null, $r, null);
    $kind = function (int $punkt) use ($blockId): int {
        foreach (programm_gliederung(programm_arbeitsstand()) as $g) {
            if ($g['art'] === 'block' && $g['punkt']['id'] === $blockId) {
                return count(array_filter($g['kinder'], fn($k) => $k['punkt']['id'] === $punkt));
            }
        }
        return 0;
    };
    $termineWs = (int)db_wert('SELECT COUNT(*) FROM workshop_termin WHERE punkt_id = ?', [$wsId]);
    pruefe($kind($neuWs) === 1, 'Workshop ohne Termin über das Formular einem Block zugeordnet');
    $pauseId = (int)db_wert("SELECT id FROM programmpunkt WHERE typ = 'pause' LIMIT 1");
    pruefe(!programm_zuordnen($blockId, $blockId, null) && !programm_zuordnen($neuWs, $wsId, null) && !programm_zuordnen($pauseId, $blockId, null), 'nur Inhalte (keine Pause, kein Block), nur zu einem Block');
    // Vorträge und Fallbesprechungen im Block (Rückmeldung 2026-10-30)
    $fb2 = (int)db_wert("SELECT id FROM programmpunkt WHERE kuerzel = 'FB 02'");
    $vt1 = (int)db_wert("SELECT id FROM programmpunkt WHERE kuerzel = 'VT 01'");
    $haupt = fn() => array_map(fn($g) => $g['punkt']['id'], array_filter(programm_gliederung(programm_arbeitsstand()), fn($g) => $g['art'] !== 'termin'));
    pruefe($kind($fb2) === 1 && !in_array($fb2, $haupt(), true), 'Fallbesprechung (Beispieldaten) steht im Block, nicht im Hauptprogramm');
    pruefe($kind($vt1) === 0, 'Vortrag ohne Zuordnung bleibt im Hauptprogramm, auch wenn ein Block läuft');
    $faelle = db_zeile("SELECT beginn FROM programmpunkt WHERE kuerzel = 'FB 01'");
    pruefe(programm_verschieben('punkt', $fb2, '19:40', null, true) === '19:40–20:10' && db_zeile("SELECT beginn FROM programmpunkt WHERE kuerzel = 'FB 01'") == $faelle && (int)($GLOBALS['programm_nachgerueckt'] ?? 0) === 0, 'Punkt im Block verschieben schiebt die Hauptspur nicht');
    $blockAlt = db_zeile('SELECT beginn FROM programmpunkt WHERE id = ?', [$blockId]);
    programm_verschieben('punkt', $blockId, '19:40', null);
    pruefe(hm((string)db_wert('SELECT beginn FROM programmpunkt WHERE id = ?', [$fb2])) === '19:50', 'Block verschieben nimmt die zugeordnete Fallbesprechung mit');
    programm_verwerfen(null);
    pruefe($kind($fb2) === 1 && hm((string)db_wert('SELECT beginn FROM programmpunkt WHERE id = ?', [$fb2])) === '20:00', 'Verwerfen: Fallbesprechung wieder im Block um 20:00');
    // Dozierende je Termin
    $ws1 = (int)db_wert("SELECT id FROM programmpunkt WHERE kuerzel = 'WS 01'");
    $ws1p = array_values(array_filter(programm_arbeitsstand(), fn($p) => $p['id'] === $ws1))[0];
    pruefe(!isset($ws1p['termine'][0]['dozenten']) && array_column($ws1p['termine'][1]['dozenten'] ?? [], 'name') === ['Dr. Erika Muster'], 'Dozierende je Termin: erster wie Workshop, zweiter abweichend');
    $pub = programm_veroeffentlicht()['punkte'];
    $ws1v = array_values(array_filter($pub, fn($p) => $p['id'] === $ws1))[0];
    pruefe(isset($ws1v['termine'][1]['dozenten']), 'abweichende Dozierende sind veröffentlicht');
    $tids = array_column($ws1p['termine'], 'id');
    $r = programm_punkt_pruefen(['typ' => 'workshop', 'titel' => $ws1p['titel'], 'kuerzel' => 'WS 01', 'dozenten' => array_column($ws1p['dozenten'], 'id'),
        't_id' => [$tids[0], $tids[1], 0], 't_key' => ['z0', 'z1', 'n1'], 't_beginn' => ['19:30', '20:30', '21:15'], 't_ende' => ['20:15', '21:15', '21:30'], 't_plaetze' => ['12', '12', '4'],
        't_doz' => ['z0' => [(string)$ws1p['dozenten'][0]['id'], '999999'], 'n1' => []]], $ws1);
    pruefe($r['fehler'] === [] && $r['termine'][0]['dozenten'] === [(int)$ws1p['dozenten'][0]['id']] && $r['termine'][1]['dozenten'] === [] && $r['termine'][2]['dozenten'] === [], 'Dozierende je Termin über den Zeilen-Schlüssel, unbekannte verworfen');
    programm_punkt_speichern($ws1, $r, null);
    pruefe((int)db_wert('SELECT COUNT(*) FROM termin_dozent td JOIN workshop_termin t ON t.id = td.termin_id WHERE t.punkt_id = ?', [$ws1]) === 1, 'gespeichert: nur der erste Termin weicht ab, der zweite wieder wie Workshop');
    programm_verwerfen(null);
    pruefe((int)db_wert('SELECT COUNT(*) FROM termin_dozent td JOIN workshop_termin t ON t.id = td.termin_id WHERE t.punkt_id = ? AND t.id = ?', [$ws1, $tids[1]]) === 1, 'Verwerfen stellt die abweichenden Dozierenden wieder her');
    db_ausfuehren("UPDATE workshop_termin SET beginn = '23:00:00', ende = '23:30:00' WHERE punkt_id = ? ORDER BY id LIMIT 1", [$wsId]);
    programm_zuordnen($wsId, $blockId, null);
    pruefe($kind($wsId) === $termineWs && array_filter(programm_hinweise(programm_arbeitsstand()), fn($x) => str_contains($x, 'liegt aber 23:00')) !== [], 'zugeordnet trotz Uhrzeit außerhalb, mit Hinweis');
    programm_zuordnen($wsId, null, null);
    pruefe($kind($wsId) === $termineWs - 1, 'Zuordnung lösen: wieder nach Uhrzeit (Termin um 23:00 nicht mehr im Block)');
    programm_verwerfen(null);
    pruefe(db_wert('SELECT COUNT(*) FROM programmpunkt WHERE id = ?', [$neuWs]) == 0 && $kind($wsId) === $termineWs, 'Verwerfen stellt den veröffentlichten Stand her');
    // Zweiter Faktor per E-Mail-Code
    $zk = konto_anlegen('Zora Zwei', 'zora@example.org', 'admin', 'zora-ihr-passwort-1', null);
    pruefe(!zweitfaktor_da(konto_laden($zk)), 'ohne zweiten Faktor nicht fertig');
    $codes = mailcode_einschalten($zk, true);
    pruefe(is_array($codes) && count($codes) === TOTP_CODES && zweitfaktor_da(konto_laden($zk)) && totp_zustand($zk)['mail'] && !totp_zustand($zk)['an'], 'Code per E-Mail eingeschaltet, Wiederherstellungscodes erzeugt');
    mailcode_senden(konto_laden($zk));
    $mcode = preg_match('/\b(\d{6})\b/', (string)db_wert("SELECT text FROM mail_ausgang WHERE an = 'zora@example.org' ORDER BY id DESC LIMIT 1"), $m) === 1 ? $m[1] : '';
    pruefe($mcode !== '' && db_wert('SELECT mailcode_hash FROM konto WHERE id = ?', [$zk]) !== $mcode, 'Code per Mail, gespeichert nur als Hash');
    pruefe(!totp_anmeldung_pruefen($zk, '000000')['ok'] || $mcode === '000000', 'falscher Code abgelehnt');
    pruefe(totp_anmeldung_pruefen($zk, $mcode)['art'] === 'mail' && !totp_anmeldung_pruefen($zk, $mcode)['ok'], 'Code gilt einmal');
    db_ausfuehren('DELETE FROM rate_limit'); // höchstens ein Code je Minute (Review S-9)
    mailcode_senden(konto_laden($zk));
    db_ausfuehren('UPDATE konto SET mailcode_bis = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 1), $zk]);
    $alt2 = preg_match('/\b(\d{6})\b/', (string)db_wert("SELECT text FROM mail_ausgang WHERE an = 'zora@example.org' ORDER BY id DESC LIMIT 1"), $m) === 1 ? $m[1] : '';
    pruefe(!mailcode_pruefen($zk, $alt2), 'abgelaufener Code abgelehnt');
    pruefe(totp_anmeldung_pruefen($zk, $codes[0])['art'] === 'code', 'Wiederherstellungscode geht auch ohne App');
    totp_zuruecksetzen($zk, 'Test');
    pruefe(!zweitfaktor_da(konto_laden($zk)), 'Zurücksetzen schaltet auch den Mail-Code ab');
    // Protokoll: Sichtbarkeit und Ausblenden
    audit_schreiben('sicherung', 'Test Sicherung', null);
    $sid = (int)db()->lastInsertId();
    audit_schreiben('programm', 'Test Programm', null);
    $pidA = (int)db()->lastInsertId();
    audit_schreiben('konto', 'Eigene Aktion', $zk);
    $eigen = (int)db()->lastInsertId();
    $sieht = function (array $k, int $id): bool { [$w, $p] = audit_sichtbar($k); return (int)db_wert("SELECT COUNT(*) FROM audit a WHERE $w AND a.id = ?", array_merge($p, [$id])) === 1; };
    $admin = konto_laden($zk);
    $betr = ['id' => 0, 'rolle' => 'betreiber'];
    pruefe(!$sieht($admin, $sid) && $sieht($admin, $pidA) && !$sieht($admin, $eigen) && $sieht($betr, $sid) && $sieht($betr, $eigen), 'Admin sieht Programm seiner Veranstaltung, nicht Sicherung und Konten (auch eigene, AP-12); Betreiber alles');
    pruefe(audit_ausblenden($pidA, true, 1) && !$sieht($admin, $pidA) && $sieht($betr, $pidA), 'ausgeblendet: nur noch Betreiber');
    pruefe(db_wert("SELECT art FROM audit ORDER BY id DESC LIMIT 1") === 'protokoll' && audit_ausblenden($pidA, false, 1) && $sieht($admin, $pidA), 'Ausblenden steht im Protokoll, Einblenden geht');
    pruefe(!audit_ausblenden((int)db_wert("SELECT id FROM audit WHERE art = 'protokoll' ORDER BY id DESC LIMIT 1"), true, 1), 'Protokoll-Einträge zum Ausblenden lassen sich nicht ausblenden');
    db_ausfuehren('DELETE FROM konto WHERE id = ?', [$zk]);

    abschnitt('Löschfrist für Konten (E-54)');
    $jetzt = time();
    $alt = gmdate('Y-m-d H:i:s', $jetzt - (14 * 30 - 20) * 86400); // in knapp 20 Tagen 14 Monate ohne Login
    $kl = konto_anlegen('Lotte Lange', 'lotte@example.org', 'teilnehmend', 'lottes-passwort', null);
    $kt = konto_anlegen('Tara Team', 'tara@example.org', 'admin', 'taras-passwort-12', null);
    db_ausfuehren('UPDATE konto SET email_bestaetigt_am = ?, letzte_anmeldung = ?, erstellt_am = ? WHERE id IN (?, ?)', [$alt, $alt, $alt, $kl, $kt]);
    db_ausfuehren("INSERT INTO anmeldung (veranstaltung_id, konto_id, vorname, nachname, email, berufsgruppe, institution, essen, status, einwilligung_am, erstellt_am, geaendert_am)
        VALUES (?, ?, 'Lotte', 'Lange', 'lotte@example.org', 'Pflege/MFA', '', 'egal', 'bestaetigt', ?, ?, ?)", [va_id(), $kl, $alt, $alt, $alt]);
    $anmL = (int)db()->lastInsertId();
    $r = tk_loeschfrist($jetzt);
    $mailL = (string)db_wert("SELECT text FROM mail_ausgang WHERE an = 'lotte@example.org' ORDER BY id DESC LIMIT 1");
    pruefe($r['erinnert'] >= 1 && str_contains($mailL, 'einmal ein') && db_wert('SELECT loesch_erinnert_am FROM konto WHERE id = ?', [$kl]) !== null, 'Erinnerung 30 Tage vor der Löschung, per Mail');
    pruefe(db_wert('SELECT loesch_erinnert_am FROM konto WHERE id = ?', [$kt]) === null, 'Team-Konten ausgenommen');
    pruefe(tk_loeschfrist($jetzt)['erinnert'] === 0, 'Keine zweite Erinnerung');
    pruefe(tk_loeschfrist($jetzt + 25 * 86400)['geloescht'] === 0 && konto_laden($kl) !== null, 'Nicht vor Ablauf von 30 Tagen nach der Erinnerung gelöscht');
    $r = tk_loeschfrist($jetzt + 31 * 86400);
    pruefe($r['geloescht'] >= 1 && konto_laden($kl) === null && konto_laden($kt) !== null, 'Nach der Frist gelöscht, Team-Konto bleibt');
    pruefe(db_wert('SELECT konto_id FROM anmeldung WHERE id = ?', [$anmL]) === null && db_wert('SELECT status FROM anmeldung WHERE id = ?', [$anmL]) === 'bestaetigt', 'Anmeldung bleibt (ohne Konto)');
    $km = konto_anlegen('Mo Login', 'mo@example.org', 'teilnehmend', 'mos-passwort-1', null);
    db_ausfuehren('UPDATE konto SET email_bestaetigt_am = ?, letzte_anmeldung = ?, erstellt_am = ? WHERE id = ?', [$alt, $alt, $alt, $km]);
    tk_loeschfrist($jetzt);
    $_SESSION = [];
    tk_einloggen(konto_laden($km));
    pruefe(db_wert('SELECT loesch_erinnert_am FROM konto WHERE id = ?', [$km]) === null && tk_loeschfrist($jetzt + 31 * 86400)['geloescht'] === 0 && konto_laden($km) !== null, 'Login setzt die Frist neu');
    $_SESSION = [];
    db_ausfuehren("DELETE FROM konto WHERE id IN (?, ?)", [$kt, $km]);
    db_ausfuehren('DELETE FROM anmeldung WHERE id = ?', [$anmL]);

    abschnitt('Passkeys (O-14, E-55)');
    require_once __DIR__ . '/passkey_hilfe.php';
    $hostAlt = $GLOBALS['ws_host'] ?? null;
    $GLOBALS['ws_host'] = 'localhost';
    $u = pk_ursprung();
    pruefe($u === ['ursprung' => 'http://localhost', 'rp_id' => 'localhost'], 'Ursprung aus der Adresse (localhost ohne HTTPS erlaubt)');
    $GLOBALS['ws_host'] = '127.0.0.1';
    pruefe(pk_ursprung() === null, 'Keine Passkeys unter einer IP-Adresse');
    $GLOBALS['ws_host'] = 'localhost';
    $kp = konto_anlegen('Paul Passkey', 'paul@example.org', 'teilnehmend', 'pauls-passwort-1', null);
    db_ausfuehren('UPDATE konto SET email_bestaetigt_am = ? WHERE id = ?', [db_jetzt(), $kp]);
    $pkFehlt = function (callable $f): bool {
        try {
            $f();
            return false;
        } catch (PkFehler) {
            return true;
        }
    };
    $g = test_pk_geraet();
    $o = pk_anlage_optionen(konto_laden($kp));
    pruefe($o['authenticatorSelection']['userVerification'] === 'required' && $o['attestation'] === 'none' && strlen(pk_b64u_lesen($o['user']['id'])) === 16, 'Anlage-Optionen: UV verlangt, keine Herstellerdaten, Nutzerkennung ohne Kontonummer');
    $pid = pk_anlegen($kp, test_pk_anlage($g, $o['challenge'], 'localhost', 'http://localhost'), "Handy\u{0007}  von Paul");
    pruefe($pid > 0 && pk_zahl($kp) === 1 && pk_liste($kp)[0]['bezeichnung'] === 'Handy von Paul', 'Passkey angelegt (ES256), Name bereinigt');
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
    // Team: Passkey als einziger zweiter Faktor
    $kt = konto_anlegen('Tina Team', 'tina@example.org', 'admin', 'tinas-passwort-12', null);
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
    $GLOBALS['ws_host'] = $hostAlt;
    unset($_SESSION['pk']);

    abschnitt('Newsletter (AP-7)');
    $ls = array_column(nl_listen(), 'id', 'name');
    $lw = (int)$ls['Wintersymposium'];
    $la = (int)$ls['Allgäu Akut'];
    pruefe(count($ls) >= 2 && nl_liste_anmeldung()['name'] === 'Allgäu Akut', 'Zwei Listen, „Allgäu Akut“ bei der Anmeldung angeboten');
    $nn = array_column(nl_empfaenger([$lw, $la]), 'email');
    pruefe(in_array('nina@example.org', $nn, true) && in_array('otto@example.org', $nn, true) && !in_array('pia@example.org', $nn, true) && count($nn) === count(array_unique($nn)), 'Beispieldaten: bestätigt bekommen Mails, Pia (unbestätigt) nicht, jede Person einmal');
    db_ausfuehren('DELETE FROM mail_ausgang');
    nl_anfragen('Quinn@Example.org', 'Quinn', [$lw, 999], 'formular');
    $qm = (string)db_wert("SELECT text FROM mail_ausgang WHERE an = 'quinn@example.org' ORDER BY id DESC LIMIT 1");
    $qtok = preg_match('#/newsletter/bestaetigen\?t=([A-Za-z0-9_-]{43})#', $qm, $m) === 1 ? $m[1] : '';
    $qp = nl_person_per_email('quinn@example.org');
    pruefe($qtok !== '' && $qp !== null && nl_abos((int)$qp['id']) === [$lw => 'offen'] && str_contains($qm, NL_EINWILLIGUNG), 'Anfrage: offen, Bestätigungsmail mit Einwilligungstext, unbekannte Liste ignoriert');
    pruefe(!in_array('quinn@example.org', array_column(nl_empfaenger([$lw]), 'email'), true), 'ohne Bestätigung kein Versand (Abnahme AP-7)');
    pruefe(nl_bestaetigen($qtok) !== null && nl_abos((int)$qp['id']) === [$lw => 'bestaetigt'] && db_wert('SELECT fassung FROM newsletter_abo WHERE person_id = ?', [$qp['id']]) === nl_fassung() && db_wert('SELECT text FROM newsletter_fassung WHERE fassung = ?', [nl_fassung()]) === NL_EINWILLIGUNG, 'Bestätigt mit Zeitpunkt und Fassung (Wortlaut gespeichert)');
    pruefe(nl_bestaetigen($qtok) === null, 'Bestätigungslink gilt einmal');
    nl_anfragen('quinn@example.org', '', [$lw], 'formular');
    pruefe(str_contains((string)db_wert("SELECT text FROM mail_ausgang WHERE an = 'quinn@example.org' ORDER BY id DESC LIMIT 1"), '/newsletter/verwalten?t='), 'schon eingetragen: Mail mit Link zum Verwalten');
    // Versand an Listen
    $nlw = rm_pruefen(['art' => 'newsletter', 'listen' => [$lw, $la], 'betreff' => 'Neu {vorname}', 'html' => '<p>Hallo {vorname},</p><p>Text</p>', 'postfach' => 'newsletter']);
    pruefe($nlw['fehler'] === [] && $nlw['werte']['auswahl'] === 'nl:' . min($lw, $la) . ',' . max($lw, $la) && rm_auswahl_text($nlw['werte']['auswahl'], '') === 'Newsletter: Wintersymposium, Allgäu Akut', 'Mailing an zwei Listen');
    pruefe(rm_pruefen(['art' => 'newsletter', 'listen' => [], 'betreff' => 'x', 'html' => '<p>x</p>'])['fehler'] !== [], 'ohne Liste: Fehler');
    $nrm = rm_speichern(null, $nlw['werte'], null);
    db_ausfuehren('DELETE FROM mail_ausgang');
    $anz = rm_einreihen($nrm, null);
    pruefe($anz === count(nl_empfaenger([$lw, $la])) && (int)db_wert('SELECT COUNT(*) FROM mail_ausgang WHERE nl_person_id IS NOT NULL') === $anz && rm_laden($nrm)['loeschen_ab'] === (new DateTimeImmutable(gmdate('Y-m-d')))->modify('+14 months')->format('Y-m-d'), 'eingereiht, jede Person einmal, Archiv 14 Monate ab Versand');
    nl_abmelden((int)nl_person_per_email('quinn@example.org')['id']);
    $vorher = count(glob(laufzeit_pfad('mails') . '/*.eml') ?: []);
    mail_senden_faellige(50, 20.0);
    $nachher = glob(laufzeit_pfad('mails') . '/*.eml') ?: [];
    sort($nachher);
    $otto = '';
    $quinn = false;
    foreach (array_slice($nachher, $vorher) as $d) {
        $t = (string)file_get_contents($d);
        $otto = str_contains($t, 'To: <otto@example.org>') ? $t : $otto;
        $quinn = $quinn || str_contains($t, 'To: <quinn@example.org>');
    }
    $od = quoted_printable_decode($otto);
    pruefe($otto !== '' && !$quinn, 'Mail an Abonnierende, nicht an inzwischen Abgemeldete');
    pruefe(preg_match('#^List-Unsubscribe: <https?://[^>]+/newsletter/abmelden\?t=[A-Za-z0-9_-]{43}>#m', $otto) === 1 && str_contains($otto, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'), 'List-Unsubscribe mit One-Click');
    pruefe(str_contains($od, 'Hallo,') && !str_contains($od, 'Hallo ,') && str_contains($od, 'Abmelden oder Listen ändern') && str_contains($od, '/newsletter/verwalten?t='), 'ohne Vorname „Hallo,“, Abmeldelink im Fuß');
    pruefe(str_contains($otto, 'From: =?UTF-8?Q?Allg=C3=A4u=20Akut?='), 'Absendername der Liste');
    // Abmelden, Statistik, Verwalten, Aufräumen
    $op = (int)nl_person_per_email('otto@example.org')['id'];
    $vor = nl_zahlen()[$la]['abgemeldet'];
    pruefe(nl_abmelden($op) === 1 && nl_person($op) === null && nl_zahlen()[$la]['abgemeldet'] === $vor + 1, 'Abmelden: Person gelöscht, nur die Zahl bleibt');
    $np = (int)nl_person_per_email('nina@example.org')['id'];
    nl_listen_setzen($np, [$la], 'verwalten');
    pruefe(nl_abos($np) === [$la => 'bestaetigt'], 'Verwalten: abgewählte Liste abgemeldet');
    $pia = (int)nl_person_per_email('pia@example.org')['id'];
    nl_aufraeumen(time() + 8 * 86400);
    pruefe(nl_person($pia) === null && nl_person($np) !== null, 'Unbestätigte nach 7 Tagen gelöscht, Bestätigte bleiben');
    // Häkchen bei der Anmeldung: nach der Bestätigung eigene Mail
    $nlAnm = anm_anlegen(anm_daten_pruefen(['vorname' => 'Rosa', 'nachname' => 'Rundbrief', 'email' => 'rosa@example.org', 'berufsgruppe' => 'StudentIn', 'beruf_sonstige' => '', 'institution' => 'Uni', 'efn' => '', 'essen' => 'egal'])['werte'], ['fest' => [], 'warte' => []], true);
    pruefe(nl_person_per_email('rosa@example.org') === null, 'Häkchen: vor der Bestätigung der Anmeldung nichts');
    anm_link_einloesen(anm_link_erzeugen($nlAnm['id'], 'bestaetigen', time() + 3600));
    $rp = nl_person_per_email('rosa@example.org');
    pruefe($rp !== null && nl_abos((int)$rp['id']) === [$la => 'offen'] && str_contains((string)db_wert("SELECT text FROM mail_ausgang WHERE an = 'rosa@example.org' ORDER BY id DESC LIMIT 1"), '/newsletter/bestaetigen?t='), 'nach der Bestätigung: eigene Newsletter-Bestätigungsmail');
    // Export
    $ex = export_erzeugen('newsletter', '', $la);
    pruefe($ex !== null && str_contains($ex['text'], 'nina@example.org') && !str_contains($ex['text'], 'rosa@example.org') && str_contains($ex['text'], nl_fassung()), 'CSV je Liste: nur bestätigte, mit Fassung');

    abschnitt('Teilnahme und Zertifikate (AP-10, E-57)');
    $tl = tn_liste();
    $mitWs = array_values(array_filter($tl, fn($e) => $e['workshops'] !== [] && $e['anwesend']));
    pruefe($tl !== [] && $mitWs !== [] && count(array_filter($tl, fn($e) => $e['anm']['status'] !== 'bestaetigt' || $e['platz']['status'] !== 'fest')) === 0, 'Teilnahme: alle bestätigten mit festem Platz, Standard anwesend');
    $e = $mitWs[0];
    $aid = (int)$e['anm']['id'];
    $wsPlatz = (int)$e['workshops'][0]['id'];
    $wsTitel = anm_termin((int)$e['workshops'][0]['termin_id'])['workshop']['titel'];
    pruefe(count(zertifikat_daten($e['anm'])['workshops']) === count($e['workshops']), 'Zertifikat: alle festen Workshops als besucht');
    pruefe(tn_setzen($wsPlatz, false, null) && count(zertifikat_daten(anm_laden($aid))['workshops']) === count($e['workshops']) - 1 && tn_anwesend($aid), 'Workshop „nicht erschienen“: fehlt auf dem Zertifikat, Symposium bleibt');
    pruefe(db_wert("SELECT COUNT(*) FROM audit WHERE art = 'teilnahme' AND text LIKE '%nicht erschienen%'") >= 1, 'Anwesenheit im Protokoll');
    tn_setzen($wsPlatz, true, null);
    pruefe(!tn_setzen(999999, false, null), 'unbekannter Platz: nichts');
    // Ohne Anwesenheit kein Zertifikat (T-191)
    tn_setzen((int)$e['platz']['id'], false, null);
    $datumAlt = va('veranstaltung_datum');
    einstellung_setzen('veranstaltung_datum', gmdate('Y-m-d', time() + 30 * 86400));
    pruefe(!zertifikat_frei_ab_heute(), 'Freischalten erst ab dem Veranstaltungstag');
    einstellung_setzen('veranstaltung_datum', gmdate('Y-m-d', time() - 86400));
    pruefe(zertifikat_frei_ab_heute() && !tn_anwesend($aid) && zertifikat_freischalten($aid, null) === 0, 'Nicht erschienen: kein Zertifikat');
    db_ausfuehren('DELETE FROM mail_ausgang');
    $anwesend = tn_zahlen()['offen'];
    $n = zertifikat_freischalten(null, null);
    pruefe($n === $anwesend && $n > 0 && (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE betreff LIKE 'Deine Teilnahmebescheinigung%'") === $n, 'Alle Anwesenden freigeschaltet, je eine Mail');
    pruefe(!zertifikat_verfuegbar(anm_laden($aid)) && anm_laden($aid)['zertifikat_am'] === null, 'Nicht erschienen: nicht freigeschaltet');
    $mailText = (string)db_wert("SELECT text FROM mail_ausgang ORDER BY id LIMIT 1");
    pruefe(str_contains($mailText, '/link?t=') && str_contains($mailText, tn_name()), 'Mail mit Link und Name der Veranstaltung');
    tn_setzen((int)$e['platz']['id'], true, null);
    pruefe(zertifikat_freischalten($aid, null, false) === 1 && zertifikat_verfuegbar(anm_laden($aid)) && zertifikat_freischalten($aid, null) === 0, 'Einzeln freischalten (ohne Mail), nicht doppelt');
    tn_setzen((int)$e['platz']['id'], false, null);
    pruefe(!zertifikat_verfuegbar(anm_laden($aid)), 'Nach der Freischaltung als nicht erschienen markiert: kein Download');
    tn_setzen((int)$e['platz']['id'], true, null);
    pruefe(zertifikat_sperren($aid, null) && !zertifikat_verfuegbar(anm_laden($aid)) && zertifikat_freischalten($aid, null, false) === 1, 'Freischaltung zurücknehmen und wieder freischalten');
    // CME-Liste: nur anwesende ÄrztInnen
    $arzt = array_values(array_filter(tn_liste(), fn($x) => $x['aerztin']));
    if ($arzt !== []) {
        tn_setzen((int)$arzt[0]['platz']['id'], false, null);
        pruefe(!str_contains(export_erzeugen('cme')['text'], '"' . $arzt[0]['anm']['nachname'] . '"'), 'CME-Liste ohne „nicht erschienen“');
        tn_setzen((int)$arzt[0]['platz']['id'], true, null);
        pruefe(str_contains(export_erzeugen('cme')['text'], '"' . $arzt[0]['anm']['nachname'] . '"'), 'CME-Liste mit Anwesenden');
    }
    // Barcode Code 128 B
    pruefe(count(array_filter(array_slice(CODE128_MUSTER, 0, 106), fn($m) => array_sum(str_split($m)) !== 11)) === 0 && array_sum(str_split(CODE128_MUSTER[106])) === 13 && count(array_unique(CODE128_MUSTER)) === 107, 'Code 128: 106 Zeichen mit 11 Modulen, Stopp mit 13');
    $bc = code128_module('*802760012345673*');
    pruefe($bc !== null && array_sum($bc) === 11 * 19 + 13 && code128_module("ä") === null, 'Code 128: *EFN* hat 222 Module; Umlaut abgelehnt');
    pruefe(array_slice(code128_module('A') ?? [], 6, 6) === [1, 1, 1, 3, 2, 3] && array_slice(code128_module('A') ?? [], 12, 6) === array_map('intval', str_split(CODE128_MUSTER[(104 + 33) % 103])), 'Code 128: Zeichen und Prüfzeichen');
    // PDFs
    einstellung_setzen('cme_punkte', '4');
    einstellung_setzen('cme_kategorie', 'A');
    einstellung_setzen('cme_kammer', 'Beispielkammer');
    einstellung_setzen('cme_veranstaltungsnummer', '2761234567890123');
    einstellung_setzen('zertifikat_leitung', 'Dr. Erika Erfunden');
    einstellung_setzen('zertifikat_leitung_rolle', 'Wissenschaftliche Leitung');
    einstellung_setzen('veranstaltung_name', 'Allgäuer Winter-Notfallsymposium');
    einstellung_setzen('veranstaltung_auflage', '3. Auflage');
    $a = anm_laden($aid);
    $z = zertifikat_pdf(zertifikat_daten($a));
    pruefe(str_starts_with($z, '%PDF-'), 'Zertifikat: PDF');
    $ausgabe = getenv('WS_TEST_AUSGABE') ?: '';
    if ($ausgabe !== '') {
        file_put_contents($ausgabe . '/zertifikat.pdf', $z);
    }
    if (trim((string)shell_exec('command -v pdftotext')) !== '') {
        $txt = function (string $pdf): string {
            $f = tempnam(sys_get_temp_dir(), 'wsz');
            file_put_contents($f, $pdf);
            $t = (string)shell_exec('pdftotext -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
            @unlink($f);
            return $t;
        };
        $zt = $txt($z);
        pruefe(str_contains($zt, mb_strtoupper($a['vorname'] . ' ' . $a['nachname'])) && str_contains($zt, 'NICHT NUR') && str_contains($zt, $wsTitel) && str_contains($zt, 'Nr. ' . zertifikat_nr($aid)), 'Zertifikat: Name, Titel, Workshop, Nummer');
        pruefe(str_contains($zt, '4 Punkte') && str_contains($zt, 'Kategorie A') && str_contains($zt, 'Beispielkammer') && str_contains($zt, 'Dr. Erika Erfunden') && !str_contains($zt, $a['berufsgruppe']), 'Zertifikat: Punkte, Kammer, Leitung, keine Berufsgruppe');
        pruefe(str_contains($zt, 'ALLGÄUER') && str_contains($zt, '3. AUFLAGE'), 'Zertifikat: Name der Veranstaltung und Auflage aus den Einstellungen');
        $u1 = unterschriftenliste_pdf(false, 'vorname');
        $u2 = unterschriftenliste_pdf(true, 'nachname');
        if ($ausgabe !== '') {
            file_put_contents($ausgabe . '/unterschriften.pdf', $u1);
            file_put_contents($ausgabe . '/unterschriften-aerzte.pdf', $u2);
        }
        $ut = $txt($u1);
        pruefe(str_starts_with($u1, '%PDF-') && str_contains($ut, 'UNTERSCHRIFTENLISTE') && str_contains($ut, $a['nachname']) && str_contains($ut, 'sortiert nach Vorname'), 'Unterschriftenliste: alle, sortiert');
        $ut2 = $txt($u2);
        pruefe(count(array_filter(tn_liste(), fn($x) => $x['aerztin'])) === 0 || (str_contains($ut2, 'EFN') && !str_contains($ut2, 'StudentIn')), 'Unterschriftenliste ÄrztInnen: mit EFN, ohne andere Berufsgruppen');
    }
    // Unterschrift als Bild
    $b = imagecreatetruecolor(300, 80);
    imagefill($b, 0, 0, imagecolorallocate($b, 255, 255, 255));
    imageline($b, 10, 60, 290, 20, imagecolorallocate($b, 0, 0, 0));
    ob_start();
    imagepng($b);
    unterschrift_schreiben((string)ob_get_clean());
    pruefe(unterschrift_da() && str_starts_with(zertifikat_pdf(zertifikat_beispiel()), '%PDF-'), 'Unterschrift gespeichert, Zertifikat mit Bild');
    $abgelehnt = false;
    try {
        unterschrift_schreiben('<svg></svg>');
    } catch (RuntimeException $f) {
        $abgelehnt = true;
    }
    pruefe($abgelehnt, 'Unterschrift: nur PNG oder JPG');
    unterschrift_entfernen(null);
    pruefe(!unterschrift_da(), 'Unterschrift entfernen');
    pruefe(mail_vorlage('zertifikat', ['vorname' => 'X', 'link' => 'L', 'gueltig' => 'G', 'name' => 'N'])[0] === 'Deine Teilnahmebescheinigung ist da', 'Mail-Vorlage Zertifikat');
    einstellung_setzen('veranstaltung_datum', $datumAlt);
    db_ausfuehren('DELETE FROM mail_ausgang');

    abschnitt('Mehrere Veranstaltungen (E-58)');
    $v1 = va_id();
    $anmV1 = count(anm_liste());
    pruefe(va_zeile($v1)['status'] === 'aktuell' && $anmV1 > 0, 'Ausgangslage: eine aktuelle Veranstaltung mit Anmeldungen');
    pruefe(va_kuerzel_fehler('Team 2028') !== null && va_kuerzel_fehler('team') !== null && va_kuerzel_fehler(va_zeile($v1)['kuerzel']) !== null && va_kuerzel_fehler('winter-2028') === null, 'Kürzel: Format, belegte Adressen und doppelte abgelehnt');
    $r = va_anlegen('winter-2028', 'Winter 2028', $v1, null);
    $v2 = (int)($r['id'] ?? 0);
    pruefe($v2 > 0 && va_zeile($v2)['status'] === 'entwurf' && va_von($v2, 'veranstaltung_ort') === va('veranstaltung_ort') && va_von($v2, 'veranstaltung_name') === 'Winter 2028', 'Neue Veranstaltung als Kopie: Entwurf mit Einstellungen und eigenem Namen');
    $pv1 = count(programm_arbeitsstand());
    pruefe(va_mit($v2, fn() => count(programm_arbeitsstand())) === $pv1 && va_mit($v2, fn() => programm_veroeffentlicht()) === null, 'Programm kopiert (Arbeitsstand, nicht veröffentlicht)');
    pruefe(va_mit($v2, fn() => anm_liste()) === [] && va_mit($v2, fn() => anm_belegt(0)) === 0 && anm_belegt(0) > 0, 'Anmeldungen und Plätze getrennt');
    va_mit($v2, fn() => einstellung_setzen('veranstaltung_ort', 'Neuer Ort'));
    va_mit($v2, fn() => inhalt_setzen('start_titel', 'Titel 2028'));
    pruefe(va('veranstaltung_ort') !== 'Neuer Ort' && va_von($v2, 'veranstaltung_ort') === 'Neuer Ort' && inhalt('start_titel') !== 'Titel 2028' && va_mit($v2, fn() => inhalt('start_titel')) === 'Titel 2028', 'Einstellungen und Startseiten-Texte je Veranstaltung');
    pruefe(va_mit($v2, fn() => anm_zeitraum()) === 'vorher', 'Entwurf nimmt keine Anmeldungen an');
    pruefe(va_aktuell_machen($v2, null) !== null, 'Zweite aktuelle nur nach Abschluss der ersten');
    // Gleiche Adresse in beiden Veranstaltungen
    $e1 = anm_liste()[0];
    $daten = ['vorname' => $e1['vorname'], 'nachname' => $e1['nachname'], 'email' => $e1['email'], 'berufsgruppe' => $e1['berufsgruppe'], 'institution' => $e1['institution'] ?: 'X', 'essen' => 'egal', 'efn' => '', 'beruf_sonstige' => ''];
    $neuV2 = va_mit($v2, fn() => anm_anlegen(anm_daten_pruefen($daten)['werte'], ['fest' => [], 'warte' => []], false));
    pruefe(($neuV2['art'] ?? '') === 'neu' && (int)anm_laden($neuV2['id'])['veranstaltung_id'] === $v2 && anm_per_email($e1['email'])['id'] == $e1['id'], 'Eine Anmeldung je Veranstaltung und Adresse');
    pruefe(va_mit($v2, fn() => count(rm_empfaenger('angemeldet'))) <= 1 && count(rm_empfaenger('angemeldet')) > 1, 'Mailing-Empfänger aus der eigenen Veranstaltung');
    // Wiederkehrende ohne Konto: Daten übernehmen
    $mailVor = (int)db_wert('SELECT COUNT(*) FROM mail_ausgang');
    $fr = va_mit($v2, fn() => anm_frueher_per_email('wer@example.org'));
    $mailFrueher = (string)db_wert("SELECT email FROM anmeldung WHERE veranstaltung_id = ? AND status = 'bestaetigt' ORDER BY id LIMIT 1", [$v1]);
    $fr2 = va_mit($v2, fn() => anm_frueher_per_email($mailFrueher));
    pruefe($fr === null && $fr2 !== null && (int)$fr2['veranstaltung_id'] === $v1, 'Frühere Anmeldung einer anderen Veranstaltung gefunden');
    va_mit($v2, fn() => anm_uebernehmen_senden((int)$fr2['id']));
    $tok = '';
    if (preg_match('#/link\?t=([A-Za-z0-9_-]{43})#', (string)db_wert('SELECT text FROM mail_ausgang ORDER BY id DESC LIMIT 1'), $m) === 1) {
        $tok = $m[1];
    }
    $ein = anm_link_einloesen($tok);
    pruefe((int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === $mailVor + 1 && ($ein['zweck'] ?? '') === 'uebernehmen' && $ein['anm'] === (int)$fr2['id'], 'Link „Daten übernehmen“ per Mail, einlösbar');
    $_SESSION['anm_vorlage'] = ['id' => (int)$fr2['id'], 'zeit' => time()];
    pruefe((anm_vorlage()['email'] ?? '') === $fr2['email'] && (anm_vorlage()['institution'] ?? '') === $fr2['institution'], 'Vorlage fürs Formular aus der früheren Anmeldung');
    unset($_SESSION['anm_vorlage']);
    // Bausteine: ohne Essen und ohne Fortbildungspunkte
    va_mit($v2, fn() => einstellung_setzen('mit_essen', 'nein'));
    va_mit($v2, fn() => einstellung_setzen('mit_cme', 'nein'));
    $ohne = va_mit($v2, fn() => anm_daten_pruefen(['essen' => '', 'efn' => '802760012345673', 'berufsgruppe' => va_liste('berufsgruppen')[0]] + $daten));
    pruefe(!isset($ohne['fehler']['essen']) && $ohne['werte']['essen'] === 'kein' && $ohne['werte']['efn'] === '' && va_an('mit_essen'), 'Ohne Essen und Punkte: keine Essenswahl, keine EFN (nur in dieser Veranstaltung)');
    // Profil: Veranstaltungen eines Kontos
    tk_registrieren('Vera Viel', 'vera.viel@example.org');
    $kid = (int)konto_per_email('vera.viel@example.org')['id'];
    db_ausfuehren('UPDATE konto SET email_bestaetigt_am = ? WHERE id = ?', [db_jetzt(), $kid]);
    db_ausfuehren("UPDATE anmeldung SET konto_id = ?, status = 'bestaetigt' WHERE id = ?", [$kid, $neuV2['id']]);
    $va1Anm = (int)db_wert("SELECT id FROM anmeldung WHERE veranstaltung_id = ? AND konto_id IS NULL AND status = 'bestaetigt' ORDER BY id LIMIT 1", [$v1]);
    db_ausfuehren('UPDATE anmeldung SET konto_id = ? WHERE id = ?', [$kid, $va1Anm]);
    $liste = tk_veranstaltungen($kid);
    pruefe(count($liste) === 2 && in_array('Winter 2028', array_column($liste, 'name'), true), 'Mein Konto: alle Veranstaltungen der Person');
    pruefe(tk_anmeldung($kid)['id'] == $va1Anm && va_mit($v2, fn() => tk_anmeldung($kid))['id'] == $neuV2['id'], 'Anmeldung des Kontos je Veranstaltung');
    // Abschließen und neue aktuelle
    // Ohne aktuelle Veranstaltung kein Rückfall auf Nr. 1 (Review B-10): öffentlich „geschlossen“, im Kontext der alten „vorbei“
    pruefe(va_abschliessen($v1, null) === null && va_abgeschlossen($v1) && anm_zeitraum() === 'geschlossen' && va_mit($v1, fn() => anm_zeitraum()) === 'vorbei' && va_abschliessen($v1, null) !== null, 'Abschließen: nur noch lesen, keine Anmeldung');
    pruefe(va_aktuell_machen($v2, null) === null && va_aktuell_id() === $v2 && va_id() === $v2, 'Danach wird die neue Veranstaltung die aktuelle');
    pruefe(va_kuerzel_setzen($v2, 'Ungültig!', null) !== null && va_kuerzel_setzen($v2, 'winter2028', null) === null && va_per_kuerzel('winter2028')['id'] == $v2, 'Kürzel ändern');
    pruefe(va_loeschen($v2, null) !== null, 'Aktuelle mit Anmeldungen lässt sich nicht löschen');
    $v3 = (int)va_anlegen('leer-2029', 'Leer', null, null)['id'];
    pruefe(va_mit($v3, fn() => programm_arbeitsstand()) === [] && va_loeschen($v3, null) !== null && va_zeile($v3) !== null, 'Löschen ohne eingetipptes Kürzel abgewiesen');
    $sichVor = count(sicherung_liste());
    pruefe(va_loeschen($v3, null, 'leer-2029') === null && va_zeile($v3) === null && count(sicherung_liste()) === $sichVor + 1, 'Leerer Entwurf mit Kürzel gelöscht, vorher gesichert');
    pruefe(va_alle()[0]['id'] !== 0 && count(va_alle()) === 1 + (int)db_wert("SELECT COUNT(*) FROM veranstaltung WHERE status = 'abgeschlossen'"), 'Liste aller Veranstaltungen');
    // Zertifikat einer vergangenen Veranstaltung im eigenen Kontext
    $pdfAlt = zertifikat_pdf_fuer(anm_laden($va1Anm));
    pruefe(str_starts_with($pdfAlt, '%PDF-'), 'Zertifikat einer abgeschlossenen Veranstaltung');
    // Aufräumen: wieder nur Veranstaltung 1 als aktuelle
    db_ausfuehren('DELETE FROM anmeldung WHERE veranstaltung_id = ?', [$v2]);
    db_ausfuehren('DELETE FROM programmpunkt WHERE veranstaltung_id = ?', [$v2]);
    db_ausfuehren('DELETE FROM veranstaltung WHERE id = ?', [$v2]);
    db_ausfuehren("UPDATE veranstaltung SET status = 'aktuell', abgeschlossen_am = NULL WHERE id = ?", [$v1]);
    db_ausfuehren('DELETE FROM konto WHERE id = ?', [$kid]);
    va_zwischenspeicher_leeren();
    pruefe(va_id() === $v1 && count(anm_liste()) === $anmV1, 'Zurück auf Veranstaltung 1');

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

    abschnitt('Fristen und Datenschutz (AP-9, E-59)');
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
    pruefe(sicherung_pfad($altName) === null && sicherung_pfad($j['neu']) !== null && $j2['geloescht'] >= 1, 'Job löscht alte Sicherung von Hand, behält die neuen');
    // Anmeldungen leeren: Veranstaltung vor 15 Monaten
    $vAlt = (int)va_anlegen('alt-test', 'Altes Symposium', null, null)['id'];
    $altTag = ds_vor_monaten(15, time());
    va_mit($vAlt, function () use ($altTag): void {
        foreach (['veranstaltung_datum' => $altTag, 'berufsgruppen' => VA_KATALOG['berufsgruppen']['start'], 'cme_punkte' => '4', 'cme_veranstaltungsnummer' => '2760001', 'cme_kammer' => 'Beispielkammer'] as $k => $w) {
            einstellung_setzen($k, $w);
        }
    });
    $mk = function (array $d) use ($vAlt): int {
        return va_mit($vAlt, function () use ($d): int {
            $e = anm_anlegen(anm_daten_pruefen($d + ['beruf_sonstige' => '', 'essen' => 'egal'])['werte'], ['fest' => [], 'warte' => []], false);
            db_ausfuehren("UPDATE anmeldung SET status = 'bestaetigt' WHERE id = ?", [$e['id']]);
            return (int)$e['id'];
        });
    };
    $aArzt = $mk(['vorname' => 'Ada', 'nachname' => 'Altdaten', 'email' => 'ada.alt@example.org', 'berufsgruppe' => 'ÄrztIn', 'institution' => 'Klinik A', 'efn' => '802760012345673']);
    $aPflege = $mk(['vorname' => 'Pia', 'nachname' => 'Pflege', 'email' => 'pia.alt@example.org', 'berufsgruppe' => 'Pflege/MFA', 'institution' => 'Klinik B', 'efn' => '']);
    $aKonto = $mk(['vorname' => 'Kai', 'nachname' => 'Konto', 'email' => 'kai.alt@example.org', 'berufsgruppe' => 'ÄrztIn', 'institution' => 'Klinik C', 'efn' => '']);
    tk_registrieren('Kai Konto', 'kai.alt@example.org');
    $kaiKonto = (int)konto_per_email('kai.alt@example.org')['id'];
    db_ausfuehren('UPDATE anmeldung SET konto_id = ? WHERE id = ?', [$kaiKonto, $aKonto]);
    $r = ds_anmeldungen_leeren(time());
    $ada = anm_laden($aArzt);
    $cme = db_zeile('SELECT * FROM cme_nachweis WHERE anmeldung_id = ?', [$aArzt]);
    pruefe($r['geleert'] === 2 && $ada['nachname'] === '' && $ada['efn'] === null && str_ends_with($ada['email'], '@anonym.invalid') && $ada['berufsgruppe'] === 'ÄrztIn' && $ada['anonymisiert_am'] !== null, 'Ohne Konto 14 Monate nach der Veranstaltung geleert, Berufsgruppe bleibt');
    pruefe($cme !== null && $cme['nachname'] === 'Altdaten' && $cme['efn'] === '802760012345673' && $cme['punkte'] === '4' && db_wert('SELECT COUNT(*) FROM cme_nachweis WHERE anmeldung_id = ?', [$aPflege]) == 0, 'CME-Nachweis nur für anwesende ÄrztInnen, getrennt abgelegt');
    pruefe(anm_laden($aKonto)['nachname'] === 'Konto' && ds_anmeldungen_leeren(time())['geleert'] === 0, 'Mit Konto bleibt; zweiter Lauf leert nichts mehr');
    $neuV = (int)va_anlegen('neu-test', 'Neues Symposium', null, null)['id'];
    va_mit($neuV, fn() => einstellung_setzen('veranstaltung_datum', ds_vor_monaten(13, time())));
    $aNeu = va_mit($neuV, fn() => anm_anlegen(anm_daten_pruefen(['vorname' => 'Nora', 'nachname' => 'Neu', 'email' => 'nora@example.org', 'berufsgruppe' => 'Pflege/MFA', 'institution' => 'X', 'efn' => '', 'beruf_sonstige' => '', 'essen' => 'egal'])['werte'], ['fest' => [], 'warte' => []], false)['id']);
    ds_anmeldungen_leeren(time());
    pruefe(anm_laden((int)$aNeu)['nachname'] === 'Neu', 'Veranstaltung vor 13 Monaten: noch nicht geleert');
    pruefe(ds_cme_loeschen(time()) === 0 && ds_cme_loeschen(time() + 11 * 366 * 86400) >= 1 && db_wert('SELECT COUNT(*) FROM cme_nachweis WHERE anmeldung_id = ?', [$aArzt]) == 0, 'CME-Nachweise nach 10 Jahren gelöscht');
    // Protokoll
    db_ausfuehren("INSERT INTO audit (zeit, konto_id, art, text) VALUES (?, NULL, 'login', 'alt'), (?, NULL, 'login', 'neu'), (?, NULL, 'programm', 'alt'), (?, NULL, 'programm', 'mittel')",
        [gmdate('Y-m-d H:i:s', time() - 100 * 86400), gmdate('Y-m-d H:i:s', time() - 10 * 86400), gmdate('Y-m-d H:i:s', time() - 450 * 86400), gmdate('Y-m-d H:i:s', time() - 100 * 86400)]);
    ds_protokoll_kuerzen(time());
    pruefe(db_wert("SELECT COUNT(*) FROM audit WHERE text = 'alt' AND art IN ('login', 'programm')") == 0 && db_wert("SELECT COUNT(*) FROM audit WHERE text IN ('neu', 'mittel')") == 2, 'Protokoll: 14 Monate, Logins 90 Tage');
    pruefe(isset(jobs_katalog()['datenschutz'], jobs_katalog()['sicherung']) && jobs_katalog()['sicherung']['abstand_s'] === 3 * 3600, 'Jobs: Datenschutz stündlich, Sicherung alle 3 Stunden');
    $ex = export_erzeugen('cme_nachweise');
    pruefe($ex !== null && str_contains($ex['kopf'][4], 'EFN'), 'Export der CME-Nachweise');
    foreach ([$vAlt, $neuV] as $vx) {
        db_ausfuehren('DELETE FROM anmeldung WHERE veranstaltung_id = ?', [$vx]);
        db_ausfuehren('DELETE FROM veranstaltung WHERE id = ?', [$vx]);
    }
    db_ausfuehren('DELETE FROM konto WHERE id = ?', [$kaiKonto]);

    abschnitt('Gesamtablauf mit zwei Personen (T-202)');
    $vG = (int)va_anlegen('gesamttest', 'Gesamttest', null, null)['id'];
    $gesamt = va_mit($vG, function (): array {
        $e = [];
        foreach (['veranstaltung_datum' => gmdate('Y-m-d', time() + 20 * 86400), 'veranstaltung_kapazitaet' => '10', 'berufsgruppen' => VA_KATALOG['berufsgruppen']['start'],
            'anmeldung_bestaetigung_h' => '48', 'nachrueck_frist_h' => '48', 'nachrueck_grenze_tage' => '1', 'cme_punkte' => '3'] as $k => $w) {
            einstellung_setzen($k, $w);
        }
        programm_punkt_speichern(null, programm_punkt_pruefen(['typ' => 'vortrag', 'kuerzel' => 'VT 01', 'titel' => 'Eröffnung', 'beginn' => '18:00', 'ende' => '18:30']), null);
        programm_punkt_speichern(null, programm_punkt_pruefen(['typ' => 'workshop', 'kuerzel' => 'WS 01', 'titel' => 'Engpass', 't_beginn' => ['19:00'], 't_ende' => ['20:00'], 't_plaetze' => ['1']]), null);
        $e['pub'] = programm_veroeffentlichen(null);
        $ws = anm_workshops(true)[0];
        $tid = (int)$ws['termine'][0]['id'];
        $daten = fn(string $v, string $m) => anm_daten_pruefen(['vorname' => $v, 'nachname' => 'Gesamt', 'email' => $m, 'berufsgruppe' => 'ÄrztIn', 'institution' => 'Klinik', 'efn' => '802760012345673', 'beruf_sonstige' => '', 'essen' => 'egal'])['werte'];
        $mailVon = fn(string $an) => (string)db_wert('SELECT text FROM mail_ausgang WHERE an = ? ORDER BY id DESC LIMIT 1', [$an]);
        $token = fn(string $text) => preg_match('#/link\?t=([A-Za-z0-9_-]{43})#', $text, $m) === 1 ? $m[1] : '';
        // 1. Ohne Konto: anmelden mit dem einzigen Workshop-Platz, über den Link bestätigen
        $p1 = anm_anlegen($daten('Ohne', 'ohne.konto@example.org'), ['fest' => [$ws['id'] => $tid], 'warte' => []], false);
        $e['p1_bestaetigt'] = anm_link_einloesen($token($mailVon('ohne.konto@example.org')))['zweck'] ?? '';
        // 2. Mit Konto-Wunsch: Warteliste für den vollen Workshop, Bestätigung legt das Konto an
        $p2 = anm_anlegen($daten('Mit', 'mit.konto@example.org'), ['fest' => [], 'warte' => [$tid]], false, null, true);
        $e['p2_konto'] = anm_link_einloesen($token($mailVon('mit.konto@example.org')))['konto_neu'] ?? null;
        $e['p2_warte'] = db_wert('SELECT status FROM platz WHERE anmeldung_id = ? AND termin_id = ?', [$p2['id'], $tid]);
        // 3. Änderung: Person 1 gibt den Workshop ab → Nachrück-Angebot an Person 2 → Annahme
        $e['aendern'] = anm_workshops_setzen($p1['id'], ['fest' => [], 'warte' => []]);
        $angebot = db_zeile("SELECT * FROM platz WHERE anmeldung_id = ? AND termin_id = ? AND status = 'angebot'", [$p2['id'], $tid]);
        $e['angebot'] = $angebot !== null;
        $e['annahme'] = $angebot !== null ? anm_angebot_annehmen($p2['id'], (int)$angebot['id'], true) : ['x'];
        $e['p2_fest'] = db_wert('SELECT status FROM platz WHERE anmeldung_id = ? AND termin_id = ?', [$p2['id'], $tid]);
        // 4. Stichtag vorbei: Teilnehmende ändern nicht mehr selbst
        einstellung_setzen('stichtag_workshop', gmdate('Y-m-d', time() - 86400));
        $e['stichtag'] = anm_frist_offen('stichtag_workshop');
        // 5. Eine dritte Person storniert
        $p3 = anm_anlegen($daten('Weg', 'storno@example.org'), ['fest' => [], 'warte' => []], false);
        anm_link_einloesen($token($mailVon('storno@example.org')));
        $e['storno'] = anm_stornieren($p3['id']) && anm_laden($p3['id']) === null;
        // 6. Rundmail an alle Angemeldeten
        $rw = rm_pruefen(['betreff' => 'Bis bald, {vorname}', 'html' => '<p>Hallo {vorname}</p>', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system']);
        $e['rundmail'] = rm_einreihen((int)rm_speichern(null, $rw['werte'], null), null);
        // 7. Veranstaltungstag vorbei: Anwesenheit (Person 1 ist nicht da), Zertifikate
        einstellung_setzen('veranstaltung_datum', gmdate('Y-m-d', time() - 86400));
        tn_setzen((int)db_wert('SELECT id FROM platz WHERE anmeldung_id = ? AND termin_id = 0', [$p1['id']]), false, null);
        $e['frei'] = zertifikat_freischalten(null, null);
        $e['z1'] = zertifikat_verfuegbar(anm_laden($p1['id']));
        $e['z2'] = zertifikat_verfuegbar(anm_laden($p2['id']));
        $e['pdf'] = str_starts_with(zertifikat_pdf_fuer(anm_laden($p2['id'])), '%PDF-');
        $e['konto_rueckblick'] = $e['p2_konto'] !== null ? (tk_veranstaltungen((int)$e['p2_konto'])[0]['zertifikat'] ?? false) : false;
        $e['ids'] = [$p1['id'], $p2['id']];
        return $e;
    });
    pruefe($gesamt['pub'] === [] && $gesamt['p1_bestaetigt'] === 'bestaetigen' && $gesamt['p2_konto'] !== null && $gesamt['p2_warte'] === 'warte', 'Anmelden: ohne Konto bestätigt, mit Konto angelegt, volle Workshops auf die Warteliste');
    pruefe($gesamt['aendern'] === [] && $gesamt['angebot'] && ($gesamt['annahme'] === [] || isset($gesamt['annahme']['ok'])) && $gesamt['p2_fest'] === 'fest', 'Änderung macht den Platz frei, Angebot, Annahme');
    pruefe($gesamt['stichtag'] === false && $gesamt['storno'] === true, 'Stichtag sperrt Änderungen; Storno löscht die Anmeldung');
    pruefe($gesamt['rundmail'] === 2, 'Rundmail an die beiden Angemeldeten');
    pruefe($gesamt['frei'] === 1 && !$gesamt['z1'] && $gesamt['z2'] && $gesamt['pdf'] && $gesamt['konto_rueckblick'], 'Anwesenheit und Zertifikat: nur die anwesende Person, auch im Konto-Rückblick');
    db_ausfuehren('DELETE FROM konto WHERE id = ?', [(int)$gesamt['p2_konto']]);
    db_ausfuehren('DELETE FROM anmeldung WHERE veranstaltung_id = ?', [$vG]);
    db_ausfuehren('DELETE FROM rundmail WHERE veranstaltung_id = ?', [$vG]);
    db_ausfuehren('DELETE FROM programmpunkt WHERE veranstaltung_id = ?', [$vG]);
    db_ausfuehren('DELETE FROM veranstaltung WHERE id = ?', [$vG]);
    db_ausfuehren('DELETE FROM mail_ausgang');

    abschnitt('Status-Seite (AP-15, T-282 bis T-286)');
    $amp = function (string $id, ?int $jetzt = null): string {
        foreach (betrieb_status($jetzt) as $z) {
            if ($z['id'] === $id) {
                return $z['ampel'];
            }
        }
        return '?';
    };
    $st = betrieb_status();
    pruefe(array_column($st, 'id') === ['cron', 'jobs', 'mail', 'datenbank', 'sicherung', 'fehlerlog', 'umgebung'], 'sieben Zeilen in fester Reihenfolge');
    $t0 = time();
    $lauf = fn(int $vor) => einstellung_setzen('jobs_letzter_lauf', gmdate('Y-m-d H:i:s', $t0 - $vor));
    $lauf(5 * 60);
    $a5 = $amp('cron', $t0);
    $lauf(20 * 60);
    $a20 = $amp('cron', $t0);
    $lauf(2 * 3600);
    $a2h = $amp('cron', $t0);
    pruefe($a5 === 'ok' && $a20 === 'warnung' && $a2h === 'fehler', 'Cron: vor 5 min ok, vor 20 min warnung, vor 2 h fehler');
    einstellung_setzen('jobs_letzter_lauf', null);
    pruefe($amp('cron', $t0) === 'fehler', 'Cron: noch nie gelaufen → fehler');
    // Aufgaben auf der Übersicht (Unterpunkt 5): rote Zeile nur für BetreiberInnen
    $aufB = array_column(team_aufgaben($t0, 'betreiber'), 'art');
    $aufA = array_column(team_aufgaben($t0, 'admin'), 'art');
    pruefe(in_array('Betrieb', $aufB, true) && !in_array('Betrieb', $aufA, true), 'Cron rot → Aufgabe „Betrieb“ für Betreiber, nicht für Admin (T-286)');
    $lauf(60);
    $jobsVorher = db_zeilen('SELECT * FROM job');
    foreach (array_keys(jobs_katalog()) as $jn) {
        db_ausfuehren('INSERT INTO job (name, letzter_lauf, letzte_dauer_ms, letzter_fehler, laeufe) VALUES (?, ?, 5, NULL, 1) ON DUPLICATE KEY UPDATE letzter_lauf = VALUES(letzter_lauf), letzter_fehler = NULL', [$jn, db_jetzt()]);
    }
    $jOk = $amp('jobs');
    db_ausfuehren("UPDATE job SET letzter_fehler = 'Test kaputt' WHERE name = 'aufraeumen'");
    pruefe($jOk === 'ok' && $amp('jobs') === 'fehler', 'Jobs: ohne Fehler ok, mit letzter_fehler → fehler');
    db_ausfuehren('DELETE FROM job');
    foreach ($jobsVorher as $jz) {
        db_ausfuehren('INSERT INTO job (' . implode(',', array_keys($jz)) . ') VALUES (' . implode(',', array_fill(0, count($jz), '?')) . ')', array_values($jz));
    }
    $sAuto = sicherung_anlegen(DS_SICHERUNG_AUTOMATISCH);
    pruefe($amp('sicherung', $t0 + 3600) === 'ok' && $amp('sicherung', $t0 + 7 * 3600) === 'warnung' && $amp('sicherung', $t0 + 25 * 3600) === 'fehler', 'Sicherung: 1 h ok, 7 h warnung, 25 h fehler');
    sicherung_loeschen($sAuto, null, false);
    $mig = db_zeile('SELECT * FROM schema_migration ORDER BY nr DESC LIMIT 1');
    db_ausfuehren('DELETE FROM schema_migration WHERE nr = ?', [$mig['nr']]);
    $aMig = $amp('datenbank');
    db_ausfuehren('INSERT INTO schema_migration (nr, beschreibung, angewendet_am) VALUES (?, ?, ?)', [$mig['nr'], $mig['beschreibung'], $mig['angewendet_am']]);
    pruefe($aMig === 'fehler' && $amp('datenbank') === 'ok', 'Datenbank: Schema ist < soll → fehler, danach ok');
    wartung_einschalten('test');
    pruefe($amp('datenbank') === 'warnung', 'Datenbank: Wartungsmodus → warnung');
    wartung_ausschalten();
    db_ausfuehren("INSERT INTO mail_ausgang (an, betreff, text, prio, status, postfach, erstellt_am, naechster_versuch) VALUES ('x@example.org', 'b', 't', 1, 'fehler', 'system', ?, ?)", [db_jetzt(), db_jetzt()]);
    pruefe($amp('mail') === 'fehler', 'Mail: fehlgeschlagene Mail → fehler');
    db_ausfuehren("DELETE FROM mail_ausgang WHERE an = 'x@example.org'");
    db_ausfuehren("INSERT INTO mail_ausgang (an, betreff, text, prio, status, postfach, erstellt_am, naechster_versuch) VALUES ('x@example.org', 'b', 't', 1, 'offen', 'system', ?, ?)", [gmdate('Y-m-d H:i:s', $t0 - 1800), db_jetzt()]);
    pruefe($amp('mail', $t0) === 'warnung', 'Mail: älteste offene seit 30 min → warnung');
    db_ausfuehren("DELETE FROM mail_ausgang WHERE an = 'x@example.org'");
    pruefe($amp('umgebung') === 'ok', 'Umgebung: Laufzeitordner beschreibbar');
    $json = betrieb_status_json(betrieb_status());
    pruefe(array_keys($json) === array_column(betrieb_status(), 'id') && !str_contains(json_encode($json), (string)konfig('jobs_token')) && !str_contains(json_encode($json), dirname(__DIR__, 2)), 'health-Daten: alle Zeilen, ohne Token und ohne Pfad');
    $anl = betrieb_cron_anleitung();
    pruefe(str_contains($anl['url'], '<jobs_token>') && !str_contains($anl['url'], (string)konfig('jobs_token')) && str_contains(betrieb_cron_anleitung(true)['url'], 'token=' . konfig('jobs_token')) && str_ends_with($anl['kommando'], '/cli/jobs.php') && $anl['zeitplan'] === '*/5 * * * *', 'Cron-Anleitung: Kommando, URL mit Platzhalter, Token nur auf Wunsch');
    // Fehlerprotokoll (Unterpunkt 4)
    $log = betrieb_fehlerlog_datei();
    $zeilen = [];
    for ($i = 1; $i <= 40; $i++) {
        $zeilen[] = '[31-Oct-2026 10:00:00 UTC] Zeile ' . $i . ($i === 40 ? ' ' . str_repeat('x', 600) : '');
    }
    file_put_contents($log, implode("\n", $zeilen) . "\n");
    $stand = betrieb_fehlerlog_stand();
    $ende = betrieb_fehlerlog_ende();
    pruefe($stand['zeilen'] === 40 && $stand['groesse'] === filesize($log) && $amp('fehlerlog') === 'warnung', 'Fehlerprotokoll: 40 Zeilen, Größe stimmt, warnung');
    pruefe(count($ende) === 30 && str_contains($ende[0], 'Zeile 11') && mb_strlen($ende[29]) <= BETRIEB_LOG_ZEICHEN + 2 && str_ends_with($ende[29], '…'), 'letzte 30 Zeilen, lange Zeile gekürzt');
    $audit0 = (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'betrieb' AND text = 'Fehlerprotokoll geleert'");
    $geleert = betrieb_fehlerlog_leeren(null);
    clearstatcache();
    pruefe($geleert && filesize($log) === 0 && einstellung('betrieb_fehlerlog_geleert') !== null
        && (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'betrieb' AND text = 'Fehlerprotokoll geleert'") === $audit0 + 1 && $amp('fehlerlog') === 'ok', 'Leeren: 0 Byte, Zeitpunkt gesetzt, Protokolleintrag, wieder ok');

    abschnitt('Review-Behebung 1 (AP-14, T-275 bis T-281)');
    $GLOBALS['ws_team_konto'] = null;
    db_ausfuehren('DELETE FROM mail_ausgang');
    $rd = ['nachname' => 'Review', 'berufsgruppe' => 'StudentIn', 'institution' => 'Uni', 'efn' => '', 'essen' => 'egal'];
    // Unterpunkt 1 (S-1, T-275): Adresse eines Team-Kontos nicht über eine Anmeldung
    $bId = konto_anlegen('Bea Betrieb', 'bea.betrieb@example.org', 'betreiber');
    $bAnm = team_anlegen(['vorname' => 'Bea', 'email' => 'bea.betrieb@example.org'] + $rd, true, false)['id'];
    db_ausfuehren('UPDATE anmeldung SET konto_id = ? WHERE id = ?', [$bId, $bAnm]);
    $f = team_anm_daten($bAnm, ['vorname' => 'Bea', 'email' => 'angreifer@example.org'] + $rd, false);
    pruefe(($f['email'] ?? '') === ANM_TEAM_ADRESSE_TEXT && konto_laden($bId)['email'] === 'bea.betrieb@example.org' && anm_laden($bAnm)['email'] === 'bea.betrieb@example.org', '(a) Team ändert Adresse einer Anmeldung mit Betreiber-Konto: abgelehnt, beides unverändert');
    pruefe(team_anm_daten($bAnm, ['vorname' => 'Beate', 'email' => 'bea.betrieb@example.org'] + $rd, false) === [] && anm_laden($bAnm)['vorname'] === 'Beate' && konto_laden($bId)['name'] === 'Bea Betrieb', 'übrige Felder änderbar, Team-Konto zieht nicht mit');
    $r = anm_daten_setzen($bAnm, ['vorname' => 'Beate', 'email' => 'bea.privat@example.org'] + $rd);
    pruefe(($r['fehler']['email'] ?? '') === ANM_TEAM_ADRESSE_TEXT && !$r['email_link'] && (int)db_wert("SELECT COUNT(*) FROM link WHERE art = 'anmeldung' AND anmeldung_id = ? AND zweck = 'email'", [$bAnm]) === 0 && (int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === 0, '(b) Team-Mitglied ändert eigene Adresse über Meine Anmeldung: abgelehnt, kein Link, keine Mail');
    $tok = anm_link_erzeugen($bAnm, 'email', time() + 3600, 'bea.privat@example.org');
    $e = anm_link_einloesen($tok);
    pruefe($e !== null && str_contains((string)$e['meldung'], 'Team-Kontos') && konto_laden($bId)['email'] === 'bea.betrieb@example.org' && anm_laden($bAnm)['email'] === 'bea.betrieb@example.org', 'zweite Sperre: alter E-Mail-Link schreibt nicht ins Team-Konto');
    $tId = konto_anlegen('Tom Teil', 'tom.teil@example.org', 'teilnehmend');
    $tAnm = team_anlegen(['vorname' => 'Tom', 'email' => 'tom.teil@example.org'] + $rd, true, false)['id'];
    db_ausfuehren('UPDATE anmeldung SET konto_id = ? WHERE id = ?', [$tId, $tAnm]);
    $au0 = (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'konto' AND text = ?", ['Konto ' . $tId . ': Adresse vom Team geändert']);
    $f = team_anm_daten($tAnm, ['vorname' => 'Tom', 'email' => 'tom.neu@example.org'] + $rd, false);
    $hin = db_zeile("SELECT * FROM mail_ausgang WHERE an = 'tom.teil@example.org' ORDER BY id DESC LIMIT 1");
    pruefe($f === [] && anm_laden($tAnm)['email'] === 'tom.neu@example.org' && konto_laden($tId)['email'] === 'tom.neu@example.org', '(c) Teilnehmenden-Konto: Adresse in Anmeldung und Konto geändert');
    pruefe($hin !== null && str_contains($hin['text'], 'Adresse deiner Anmeldung und deines Kontos geändert') && !str_contains($hin['text'], 'tom.neu@'), 'Hinweis-Mail an die alte Adresse (ohne neue Adresse)');
    $au = db_zeile("SELECT text FROM audit WHERE art = 'konto' ORDER BY id DESC LIMIT 1");
    pruefe((int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'konto' AND text = ?", ['Konto ' . $tId . ': Adresse vom Team geändert']) === $au0 + 1 && !str_contains($au['text'], '@'), 'Protokoll „Konto N: Adresse vom Team geändert“ ohne Adressen');
    db_ausfuehren('DELETE FROM mail_ausgang');

    // Unterpunkt 2 (B-1, T-276): Newsletter-Mails überleben den Versand nach einer Admin-Aktion
    $nlListe = nl_liste_speichern(null, nl_liste_pruefen(['name' => 'Review-Test', 'sichtbar' => '1'])['werte'], null);
    foreach (['nl.eins', 'nl.zwei', 'nl.drei'] as $nlN) {
        nl_anfragen($nlN . '@example.org', 'NL', [$nlListe], 'formular');
        $nlTok = preg_match('#/newsletter/bestaetigen\?t=([A-Za-z0-9_-]{43})#', (string)db_wert('SELECT text FROM mail_ausgang WHERE an = ? ORDER BY id DESC LIMIT 1', [$nlN . '@example.org']), $m) === 1 ? $m[1] : '';
        nl_bestaetigen($nlTok);
    }
    db_ausfuehren('DELETE FROM mail_ausgang');
    $nlRm = rm_speichern(null, rm_pruefen(['art' => 'newsletter', 'listen' => [$nlListe], 'betreff' => 'Review', 'html' => '<p>Hallo {vorname}</p>', 'postfach' => 'newsletter'])['werte'], null);
    $nlAnz = rm_einreihen($nlRm, null);
    $GLOBALS['ws_team_rolle'] = 'admin';
    $nlE = mail_senden_faellige(5, 20.0);
    pruefe($nlAnz === 3 && $nlE['gesendet'] === 3 && (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE rundmail_id = ? AND status = 'gesendet'", [$nlRm]) === 3, 'Newsletter mit 3 Empfängern, Team-Rolle admin: alle 3 gesendet, keine gelöscht');
    $vaFremd = (int)db_wert('SELECT id FROM veranstaltung WHERE id <> ? ORDER BY id LIMIT 1', [va_id()]);
    $rmFremd = rm_speichern(null, rm_pruefen(['betreff' => 'Fremd', 'html' => '<p>x</p>', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system'])['werte'], null);
    db_ausfuehren('UPDATE rundmail SET veranstaltung_id = ? WHERE id = ?', [$vaFremd, $rmFremd]);
    $rmF = rm_laden($rmFremd);
    pruefe($vaFremd > 0 && $rmF !== null && function_exists('rm_sichtbar') && !rm_sichtbar($rmF) && !rm_sichtbar(rm_laden($nlRm)), 'Admin: Mailing einer fremden Veranstaltung und Newsletter nicht sichtbar, rm_laden liefert die Zeile');
    $GLOBALS['ws_team_rolle'] = 'betreiber';
    pruefe(function_exists('rm_sichtbar') && rm_sichtbar(rm_laden($nlRm)), 'Betreiber: Newsletter-Mailing sichtbar');
    unset($GLOBALS['ws_team_rolle']);
    db_ausfuehren("INSERT INTO mail_ausgang (an, betreff, text, prio, status, postfach, rundmail_id, anmeldung_id, erstellt_am, naechster_versuch) VALUES ('weg@example.org', '', '', 5, 'offen', 'system', 999999, 1, ?, ?)", [db_jetzt(), db_jetzt()]);
    mail_senden_faellige(5, 20.0);
    $weg = db_zeile("SELECT status, versuche, fehler FROM mail_ausgang WHERE an = 'weg@example.org'");
    pruefe($weg !== null && $weg['status'] === 'offen' && (int)$weg['versuche'] === 1 && str_contains((string)$weg['fehler'], 'nicht gefunden'), 'Mailing fehlt: Fehler mit Wiederholung statt Löschen');
    db_ausfuehren('DELETE FROM mail_ausgang');
    db_ausfuehren('DELETE FROM rundmail WHERE id IN (?, ?)', [$nlRm, $rmFremd]);
    db_ausfuehren('DELETE FROM newsletter_abo WHERE liste_id = ?', [$nlListe]);
    db_ausfuehren('DELETE FROM newsletter_liste WHERE id = ?', [$nlListe]);

    // Unterpunkt 3 (B-2, B-3, T-277): Warte-Einträge überleben „Speichern“
    $formWahl = function (int $anmId, bool $team): array {
        $bisher = array_values(array_filter(anm_plaetze($anmId), fn($p) => (int)$p['termin_id'] !== 0));
        $html = anm_workshopwahl_html(anm_wahl_aus_plaetzen($bisher), $bisher, $team);
        // was der Browser sendet: angekreuzte Felder
        $post = ['ws' => [], 'wl' => []];
        preg_match_all('/<input[^>]*name="(ws\[(\d+)\]|wl\[\])" value="(\d*)"[^>]* checked/', $html, $mm, PREG_SET_ORDER);
        foreach ($mm as $x) {
            if ($x[1] === 'wl[]') {
                $post['wl'][] = $x[3];
            } else {
                $post['ws'][$x[2]] = $x[3];
            }
        }
        return ['html' => $html, 'wahl' => anm_wahl_lesen($post)];
    };
    // Beispieldatensatz (AP-14): Wanda auf der Warteliste von WS 01 19:30, der Termin hat freie Plätze
    $bw = beispieldaten_warteliste();
    [$wlP, $wlT] = [$bw['wanda'], $bw['termin']];
    $wlSeit = (string)db_wert("SELECT seit FROM platz WHERE anmeldung_id = ? AND termin_id = ? AND status = 'warte'", [$wlP, $wlT]);
    $wlSeitNoch = fn(): ?string => db_wert("SELECT seit FROM platz WHERE anmeldung_id = ? AND termin_id = ? AND status = 'warte'", [$wlP, $wlT]);
    $fw = $formWahl($wlP, true);
    pruefe(str_contains($fw['html'], 'name="wl[]" value="' . $wlT . '" checked'), 'Team-Modus: bestehender Warte-Eintrag als vorangekreuztes Kästchen');
    team_anm_daten($wlP, ['vorname' => 'Wanda-Maria', 'email' => 'wanda.warte@example.org'] + $rd, false);
    $f = anm_workshops_setzen($wlP, $fw['wahl'], null, true, false, false);
    pruefe($f === [] && $wlSeitNoch() === $wlSeit, '(a) Team speichert nur den Namen: Warte-Eintrag mit altem „seit“ bleibt');
    $fw = $formWahl($wlP, false);
    pruefe(anm_frei($wlT) > 0 && str_contains($fw['html'], 'fest wählen oder auf der Warteliste bleiben') && str_contains($fw['html'], 'name="wl[]" value="' . $wlT . '" checked'), 'Termin mit freien Plätzen: Warte-Eintrag sichtbar mit Hinweis');
    $f = anm_workshops_setzen($wlP, $fw['wahl'], null, false, false, false);
    pruefe($f === [] && $wlSeitNoch() === $wlSeit, '(b) Teilnehmende speichert ohne Änderung: Eintrag bleibt');
    pruefe(anm_wahl_pruefen(['fest' => [], 'warte' => [$wlT]], []) !== [], 'neue Warteliste für einen freien Termin bleibt verboten');
    $f = anm_workshops_setzen($wlP, ['fest' => $fw['wahl']['fest'], 'warte' => []], null, false, false, false);
    pruefe($f === [] && $wlSeitNoch() === null, '(c) Kästchen abgewählt: Eintrag weg');

    // Unterpunkt 4 (S-2, T-278): Ratenschutz für Adressänderungen
    db_ausfuehren('DELETE FROM rate_limit');
    db_ausfuehren('DELETE FROM mail_ausgang');
    $raP = team_anlegen(['vorname' => 'Rita', 'email' => 'rita.rate@example.org'] + $rd, true, false)['id'];
    $raErg = [];
    for ($i = 1; $i <= 11; $i++) {
        $raErg[$i] = anm_daten_setzen($raP, ['vorname' => 'Rita', 'email' => 'ziel' . $i . '@example.org'] + $rd);
    }
    pruefe($raErg[10]['email_link'] && !$raErg[11]['email_link'] && !empty($raErg[11]['email_gesperrt']) && (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE an = 'ziel11@example.org'") === 0, '11 Adressänderungen auf 11 Zieladressen von einer IP: die 11. gesperrt (link_ip 10/h)');
    pruefe((int)db_wert("SELECT COUNT(*) FROM link WHERE art = 'anmeldung' AND anmeldung_id = ? AND zweck = 'email'", [$raP]) === 1 && db_wert("SELECT JSON_UNQUOTE(JSON_EXTRACT(daten, '$.neue_email')) FROM link WHERE art = 'anmeldung' AND anmeldung_id = ? AND zweck = 'email'", [$raP]) === 'ziel10@example.org', 'nur ein offener E-Mail-Link je Anmeldung (der neueste)');
    $raN = anm_daten_setzen($raP, ['vorname' => 'Ritaneu', 'email' => 'ziel12@example.org'] + $rd);
    pruefe(!empty($raN['email_gesperrt']) && $raN['fehler'] === [] && anm_laden($raP)['vorname'] === 'Ritaneu', 'gesperrt: übrige Daten trotzdem gespeichert');
    $raErg = [];
    db_ausfuehren('DELETE FROM rate_limit');
    for ($i = 1; $i <= 4; $i++) {
        $raErg[$i] = anm_daten_setzen($raP, ['vorname' => 'Rita', 'email' => 'gleich@example.org'] + $rd);
    }
    pruefe($raErg[3]['email_link'] && !$raErg[4]['email_link'] && !empty($raErg[4]['email_gesperrt']), '4 Änderungen auf dieselbe Zieladresse: die 4. gesperrt (link_ziel 3/h)');
    db_ausfuehren('DELETE FROM rate_limit');
    $raK = konto_anlegen('Kai Konto', 'kai.konto@example.org', 'teilnehmend');
    $raKf = [];
    for ($i = 1; $i <= 4; $i++) {
        $raKf[$i] = tk_email_aendern($raK, 'kai.neu@example.org');
    }
    pruefe($raKf[3] === null && $raKf[4] === ANM_ADRESSE_RATE_TEXT && (int)db_wert("SELECT COUNT(*) FROM link WHERE art = 'konto' AND konto_id = ? AND zweck = 'email'", [$raK]) === 1, 'Konto: Adressänderung mit Ratenschutz, ein offener Link');
    $raM = (string)db_wert("SELECT text FROM mail_ausgang WHERE an = 'gleich@example.org' ORDER BY id DESC LIMIT 1");
    pruefe(str_starts_with($raM, "Hallo,\n") && !str_contains($raM, 'Rita'), 'Mail an die neue Adresse ohne Vornamen („Hallo,“)');
    db_ausfuehren('DELETE FROM rate_limit');
    db_ausfuehren('DELETE FROM mail_ausgang');

    // Unterpunkt 6 (B-13, T-280): Vorlage „Daten übernehmen“ läuft ab
    $voP = team_anlegen(['vorname' => 'Vera', 'email' => 'vera.vorlage@example.org'] + $rd, true, false)['id'];
    $_SESSION['anm_vorlage'] = ['id' => $voP, 'zeit' => time() - 29 * 60];
    $voFrisch = anm_vorlage();
    $_SESSION['anm_vorlage'] = ['id' => $voP, 'zeit' => time() - 31 * 60];
    $voAlt = anm_vorlage();
    pruefe(($voFrisch['email'] ?? '') === 'vera.vorlage@example.org' && $voAlt === null && !isset($_SESSION['anm_vorlage']), 'Vorlage nach 29 min da, nach 31 min weg (Zeitstempel im Sitzungseintrag)');
    $_SESSION['anm_vorlage'] = $voP;
    pruefe(anm_vorlage() === null, 'alter Sitzungseintrag ohne Zeitstempel gilt nicht');
    unset($_SESSION['anm_vorlage']);

    abschnitt('Review-Behebung 2 (AP-16, T-288 bis T-296)');
    $GLOBALS['ws_team_konto'] = null;
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
    $_SESSION['anm'] = ['id' => $kimAlt, 'version' => $kimAltVer, 'va' => $vAlt, 'link' => false];
    pruefe(anm_aktuell() === null && !isset($_SESSION['anm']), 'B-4: abgeleiteter Eintrag mit va der alten Veranstaltung im Kontext der aktuellen verworfen');
    $_SESSION['anm'] = ['id' => $kimAlt, 'version' => $kimAltVer, 'va' => $vAlt, 'link' => true];
    pruefe((int)(anm_aktuell()['id'] ?? 0) === $kimAlt, 'B-4: Eintrag aus einem Einmal-Link bleibt (Zertifikat-Mail einer vergangenen Veranstaltung, E-58)');
    unset($_SESSION['anm']);
    va_kontext($vAlt);
    $imArchiv = anm_aktuell();
    unset($GLOBALS['ws_va_id']);
    pruefe($imArchiv === null && !isset($_SESSION['anm']), 'B-4: mit gesetztem Kontext (Team-Seite, Archivseite) wird nichts abgeleitet');
    $kimNeu = (int)team_anlegen(['vorname' => 'Kim', 'email' => 'kim.konto@example.org'] + $rd, true, false)['id'];
    db_ausfuehren('UPDATE anmeldung SET konto_id = ? WHERE id = ?', [$kimId, $kimNeu]);
    pruefe((int)(anm_aktuell()['id'] ?? 0) === $kimNeu && (int)($_SESSION['anm']['va'] ?? 0) === $vAkt && empty($_SESSION['anm']['link']), 'B-4: öffentliche Seite der aktuellen Veranstaltung: verknüpfte Anmeldung abgeleitet, mit va');
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
        $_SESSION['zf_bestaetigt'] = ['konto' => $zId, 'bis' => time() - 1];
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
        $GLOBALS['ws_host'] = 'localhost';
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
        $GLOBALS['ws_host'] = $hostAlt;
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

    abschnitt('Einmal-Links in einer Tabelle (AP-17 Unterpunkt 8, V-1)');
    $lt = link_token();
    pruefe(preg_match('/^[A-Za-z0-9_-]{43}$/', $lt) === 1 && link_token() !== $lt, 'ein Token-Format: 32 Byte Base64url (43 Zeichen)');
    pruefe(!db_tabelle_da('konto_link') && !db_tabelle_da('anmeldung_link') && !db_tabelle_da('newsletter_link') && db_tabelle_da('link'), 'alte Link-Tabellen entfernt, eine Tabelle link (E-69 (7))');
    // Migration der Altdaten: dieselben INSERT … SELECT wie Migration 20, auf vorübergehenden Tabellen mit den alten Namen
    $lAnm = (int)db_wert('SELECT MIN(id) FROM anmeldung');
    $lKonto = (int)db_wert("SELECT MIN(id) FROM konto WHERE rolle = 'teilnehmend'");
    $lPerson = nl_person_anlegen('alt-link@example.org', 'Alt');
    $altHex = bin2hex(random_bytes(24));
    $altKonto = link_token();
    $altNl = bin2hex(random_bytes(24));
    $t0 = time();
    db()->exec('CREATE TEMPORARY TABLE konto_link (konto_id INT UNSIGNED, token_hash CHAR(64), zweck VARCHAR(20), neue_email VARCHAR(254) NULL, gueltig_bis DATETIME, erstellt_am DATETIME)');
    db()->exec('CREATE TEMPORARY TABLE anmeldung_link (anmeldung_id INT UNSIGNED, token_hash CHAR(64), zweck VARCHAR(20), neue_email VARCHAR(190) NULL, gueltig_bis DATETIME, erstellt_am DATETIME)');
    db()->exec('CREATE TEMPORARY TABLE newsletter_link (person_id INT UNSIGNED, token_hash CHAR(64), zweck VARCHAR(20), gueltig_bis DATETIME)');
    db_ausfuehren('INSERT INTO konto_link VALUES (?, ?, ?, ?, ?, ?)', [$lKonto, hash('sha256', $altKonto), 'email', 'neu-alt@example.org', db_zeit($t0 + 3600), db_zeit($t0)]);
    db_ausfuehren('INSERT INTO anmeldung_link VALUES (?, ?, ?, NULL, ?, ?)', [$lAnm, hash('sha256', $altHex), 'zugang', db_zeit($t0 + 3600), db_zeit($t0)]);
    db_ausfuehren('INSERT INTO newsletter_link VALUES (?, ?, ?, ?)', [$lPerson, hash('sha256', $altNl), 'verwalten', db_zeit($t0 + 3600)]);
    foreach (array_slice(migration_katalog()[20]['sql'], 1, 3) as $sql) {
        db()->exec($sql);
    }
    db()->exec('DROP TEMPORARY TABLE konto_link, anmeldung_link, newsletter_link');
    $fk = link_finden('konto', $altKonto);
    pruefe($fk !== null && $fk['ziel_id'] === $lKonto && $fk['zweck'] === 'email' && $fk['neue_email'] === 'neu-alt@example.org', 'Migration: Konto-Link mit neuer Adresse übernommen (Hash bleibt gültig)');
    $fa = anm_link_finden($altHex);
    pruefe($fa !== null && (int)$fa['anmeldung_id'] === $lAnm && $fa['zweck'] === 'zugang', 'Migration: alter Hex-Token einer Anmeldung wird gefunden');
    pruefe(nl_link_finden($altNl, 'verwalten') !== null && nl_link_finden($altNl, 'bestaetigen') === null, 'Migration: Newsletter-Link übernommen, Zweck getrennt');
    pruefe(link_verbrauchen((int)$fa['id']) && anm_link_finden($altHex) === null && !link_verbrauchen((int)$fa['id']), 'alter Hex-Token wird einmal verbraucht');
    // Zweck- und Art-Trennung
    $teamK = (int)db_wert("SELECT MIN(id) FROM konto WHERE rolle <> 'teilnehmend'");
    $teamTok = konto_link_erzeugen($teamK, 'passwort');
    pruefe(konto_link_finden($teamTok) !== null && tk_link_einloesen($teamTok) === null && konto_link_finden($teamTok) !== null, 'Team-Link geht nicht über /konto/link (und bleibt unverbraucht)');
    pruefe(link_finden('anmeldung', $teamTok) === null && link_finden('newsletter', $altKonto) === null, 'Token einer Art gilt nicht für eine andere Art');
    $tk1 = tk_link($lKonto, 'passwort', null, $t0);
    $tk2 = tk_link($lKonto, 'bestaetigen', null, $t0);
    $tk3 = tk_link($lKonto, 'passwort', null, $t0);
    $tokVon = fn(array $l) => (string)substr((string)strstr($l['url'], '?t='), 3);
    pruefe(link_finden('konto', $tokVon($tk1)) === null && link_finden('konto', $tokVon($tk2)) !== null && link_finden('konto', $tokVon($tk3)) !== null, 'Teilnehmenden-Link ersetzt nur ältere mit gleichem Zweck');
    konto_link_erzeugen($lKonto, 'einrichten');
    pruefe(link_finden('konto', $tokVon($tk2)) === null, 'Team-Link ersetzt alle älteren Links des Kontos');
    // Aufräumen
    $kurz = link_erzeugen('anmeldung', $lAnm, 'zugang', 60, [], $t0);
    $lang = link_erzeugen('anmeldung', $lAnm, 'zugang', 7200, [], $t0);
    $weg = link_aufraeumen($t0 + 120);
    pruefe($weg >= 1 && link_finden('anmeldung', $kurz, null, $t0) === null && link_finden('anmeldung', $lang, null, $t0) !== null, 'Aufräumen löscht nur abgelaufene Links');
    pruefe(str_ends_with(konto_link_url('abc'), '/einrichten?t=abc') && str_ends_with(link_url('konto', 'abc'), '/konto/link?t=abc') && str_ends_with(anm_link_url('abc'), '/link?t=abc') && str_ends_with(link_url('newsletter', 'abc', 'bestaetigen'), '/newsletter/bestaetigen?t=abc'), 'Pfade der Links unverändert (verschickte Mails gelten weiter)');
    link_loeschen('anmeldung', $lAnm);
    link_loeschen('konto', $lKonto);
    link_loeschen('konto', $teamK);

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
va_zwischenspeicher_leeren();

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
