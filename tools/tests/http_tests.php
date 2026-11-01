<?php
declare(strict_types=1);

/*
 * HTTP-Durchlauf gegen den eingebauten PHP-Server. Zuerst der Installer auf
 * leerer Datenbank (Installation, Kopfzeilen, Hosts, Health, Jobs, Wartung),
 * danach die Bereiche (Login, Programm, Anmeldung, Konten, Team, Mailings …):
 * jeder beginnt mit http_frisch() – test_frisch() aus basis.php mit den
 * Beispieldaten – und prüft über HTTP gegen diesen Stand. Wird von lauf.php
 * aufgerufen (WS_TESTS=http); einzelne Bereiche mit WS_HTTP=name,name
 * („installer“ für den Installer-Teil).
 */

function http_anfrage(string $basis, string $methode, string $pfad, string $host, array $kopf = [], ?array $formular = null, ?string $keks = null): array
{
    $ch = curl_init($basis . $pfad);
    $kopfzeilen = array_merge(['Host: ' . $host], $kopf);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $methode,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $kopfzeilen,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($keks !== null) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $keks);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $keks);
    }
    if ($formular !== null) {
        // Mit Datei (CURLFile) als multipart/form-data, sonst urlencoded
        $mitDatei = array_filter($formular, fn($w) => $w instanceof CURLFile) !== [];
        curl_setopt($ch, CURLOPT_POSTFIELDS, $mitDatei ? $formular : http_build_query($formular));
    }
    $roh = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $kopfLaenge = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $kopfText = substr($roh, 0, $kopfLaenge);
    $koerper = substr($roh, $kopfLaenge);
    $k = [];
    foreach (explode("\r\n", $kopfText) as $zeile) {
        if (str_contains($zeile, ':')) {
            [$n, $w] = explode(':', $zeile, 2);
            $k[strtolower(trim($n))] = trim($w);
        }
    }
    return ['status' => $status, 'kopf' => $k, 'text' => $koerper];
}

function http_csrf(string $html): string
{
    return preg_match('/name="csrf" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** Geheimnis aus der Einrichtungsseite (otpauth-Adresse im QR-Code). */
function http_totp_geheimnis(string $html): ?string
{
    return preg_match('/secret=([A-Z2-7]+)/', $html, $m) === 1 ? totp_base32_dekodieren($m[1]) : null;
}

/** Programm pflegen, veröffentlichen, anzeigen (AP-3 Schritt 3); $b = Kekse einer eingeloggten Betreiberin. */


/**
 * Neueste Mail im Datei-Modus an eine Adresse (Text dekodiert) oder ''. Mit
 * $enthaelt: wartet auf eine Mail mit diesem Text (Versand läuft nach der Antwort).
 */
function http_mail_an(string $tmp, string $an, int $warten = 20, string $enthaelt = ''): string
{
    for ($i = 0; $i < $warten; $i++) {
        $dateien = glob("$tmp/var/mails/*.eml") ?: [];
        rsort($dateien);
        foreach ($dateien as $d) {
            $text = quoted_printable_decode((string)file_get_contents($d));
            if (str_contains($text, "To: <$an>") && ($enthaelt === '' || str_contains($text, $enthaelt))) {
                return $text;
            }
        }
        usleep(150_000);
    }
    return '';
}

function http_mail_anzahl(string $tmp, string $an): int
{
    return count(array_filter(glob("$tmp/var/mails/*.eml") ?: [], fn($d) => str_contains((string)file_get_contents($d), "To: <$an>")));
}

/** Rechenaufgabe des Spamschutzes lösen (wie assets/pow-worker.js). */
function http_pow(string $aufgabe): string
{
    for ($n = 0; ; $n++) {
        if (spam_nullbits(hash('sha256', $aufgabe . $n, true), SPAM_POW_BITS)) {
            return (string)$n;
        }
    }
}

/** Anmeldeformular absenden wie ein Browser (Aufgabe gelöst, Formular 10 s alt). */
function http_anmelden(string $basis, string $h, string $keks, array $felder): array
{
    $r = http_anfrage($basis, 'GET', '/anmelden', $h, [], null, $keks);
    preg_match('/name="pow_aufgabe" value="([0-9a-f]{64})"/', $r['text'], $m);
    $form = $felder + ['csrf' => http_csrf($r['text']), 'zeit' => spam_stempel(time() - 10), 'pow_aufgabe' => $m[1] ?? '', 'pow_loesung' => http_pow($m[1] ?? ''), 'website' => '', 'einwilligung' => '1'];
    return http_anfrage($basis, 'POST', '/anmelden', $h, [], $form, $keks);
}

/** Bild hochladen wie der Editor (multipart, CSRF im Kopf). */
function http_bild_hochladen(string $basis, string $h, string $keks, string $csrf, string $datei): array
{
    $ch = curl_init($basis . '/team/mailings/bild');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Host: ' . $h, 'X-CSRF: ' . $csrf],
        CURLOPT_COOKIEFILE => $keks, CURLOPT_COOKIEJAR => $keks,
        CURLOPT_POSTFIELDS => ['bild' => new CURLFile($datei, 'image/png', 'plan.png')],
    ]);
    $text = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode($text, true) ?? []];
}

/**
 * Frischer Stand für einen HTTP-Bereich (test_frisch(), siehe basis.php). Die
 * Datenbank wird unter dem laufenden Server geleert: Keks-Dateien und Mails
 * des vorigen Bereichs kommen weg, damit keine alte Sitzung und kein alter
 * Code gilt.
 */
function http_frisch(array $u, array $opt = []): array
{
    $f = test_frisch($opt);
    foreach (array_merge(glob($u['tmp'] . '/kekse*.txt') ?: [], glob($u['tmp'] . '/var/mails/*.eml') ?: []) as $d) {
        @unlink($d);
    }
    return $f;
}

/**
 * Team-Konto beim ersten Login einloggen: Passwort, dann die Authenticator-App
 * einrichten (Geheimnis aus der Seite). Gibt ['keks', 'geheimnis', 'codes'
 * (zehn Wiederherstellungscodes)] zurück.
 */
function http_team_einloggen(array $u, string $email, string $pw, ?string $host = null): array
{
    $h = $host ?? $u['h'];
    $k = $u['tmp'] . '/kekse-' . md5($email . $h) . '.txt';
    @unlink($k);
    $r = http_anfrage($u['basis'], 'GET', '/einloggen', $h, [], null, $k);
    http_anfrage($u['basis'], 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => $email, 'passwort' => $pw], $k);
    $r = http_anfrage($u['basis'], 'GET', '/einloggen', $h, [], null, $k);
    $roh = (string)http_totp_geheimnis($r['text']);
    $r = http_anfrage($u['basis'], 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'einrichten', 'totp' => totp_code($roh, time())], $k);
    preg_match_all('/<li class="mono">([A-Z2-9]{4} [A-Z2-9]{4})<\/li>/', $r['text'], $m);
    if (count($m[1]) !== TOTP_CODES) {
        throw new RuntimeException("Team-Login $email: zweiter Faktor nicht eingerichtet");
    }
    return ['keks' => $k, 'geheimnis' => $roh, 'codes' => $m[1]];
}

/** Vollständiges Formular der Einstellungen (Werte wie gespeichert) – Startseiten-Texte stehen dort mit drin (2026-10-31). */
function test_va_form(string $csrf): array
{
    $form = ['csrf' => $csrf];
    foreach (VA_KATALOG as $k => $_) {
        $form[$k] = va_formularwert($k);
    }
    return $form;
}

/** HTTP-Bereiche in der Reihenfolge des Laufs (Funktion http_bereich_<name>). */
const HTTP_BEREICHE = ['login', 'einstellungen', 'programm', 'anmeldung', 'konto', 'daten', 'betrieb', 'team', 'mailings',
    'rueckmeldung', 'konto_alles', 'passkey', 'newsletter', 'veranstaltungen', 'rechte', 'ap14', 'ap16', 'ap18', 'ap19'];

function http_tests(string $wurzel, string $tmp): void
{
    $port = 18000 + random_int(0, 999);
    $basis = "http://127.0.0.1:$port";
    $befehl = [PHP_BINARY, '-d', 'output_buffering=0', '-S', "127.0.0.1:$port", '-t', "$wurzel/server/public", "$wurzel/server/public/index.php"];
    $env = array_merge(getenv(), ['WS_CONFIG' => "$tmp/config.php"]);
    $proc = proc_open($befehl, [0 => ['pipe', 'r'], 1 => ['file', "$tmp/server.log", 'a'], 2 => ['file', "$tmp/server.log", 'a']], $pipes, null, $env);
    if (!is_resource($proc)) {
        pruefe(false, 'PHP-Server startet');
        return;
    }
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $port);
        if ($s) {
            fclose($s);
            break;
        }
        usleep(100_000);
    }
    // Nach test_frisch() sind 127.0.0.1 (Haupt-Host) und localhost erlaubt
    $u = ['basis' => $basis, 'h' => '127.0.0.1', 'tmp' => $tmp, 'wurzel' => $wurzel];
    $wahl = array_values(array_filter(array_map('trim', explode(',', (string)getenv('WS_HTTP')))));
    try {
        if ($wahl === [] || in_array('installer', $wahl, true)) {
            $t = microtime(true);
            http_installer($u);
            echo '  (http_installer: ' . test_dauer($t) . ")\n";
        }
        foreach (HTTP_BEREICHE as $name) {
            if ($wahl === [] || in_array($name, $wahl, true)) {
                $t = microtime(true);
                ('http_bereich_' . $name)($u);
                echo "  (http_$name: " . test_dauer($t) . ")\n";
            }
        }
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
}

/** Installer auf leerer Datenbank (lauf.php leert sie vorher): Installation, Kopfzeilen, Hosts, Health, Jobs, Wartung, öffentliche Seiten. */
function http_installer(array $u): void
{
    ['basis' => $basis, 'tmp' => $tmp, 'wurzel' => $wurzel] = $u;
    $keks = "$tmp/kekse.txt";
    $h = 'ws.test';

    abschnitt('HTTP: vor der Installation');
    $r = http_anfrage($basis, 'GET', '/', $h);
    pruefe($r['status'] === 503, 'Startseite 503 „noch nicht eingerichtet"');
    $r = http_anfrage($basis, 'GET', '/health', $h);
    pruefe($r['status'] === 503, 'Health vor Installation 503');
    $r = http_anfrage($basis, 'GET', '/install', $h, [], null, $keks);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Installationsschlüssel'), 'Installer erreichbar');
    $csrf = http_csrf($r['text']);
    pruefe($csrf !== '', 'Formular enthält CSRF-Token');

    abschnitt('HTTP: CSRF (T-102)');
    $form = [
        'schluessel' => str_repeat('i', 40), 'name' => 'Erste Betreiberin', 'email' => 'Betrieb@Example.org',
        'passwort' => 'ein-langes-passwort', 'passwort2' => 'ein-langes-passwort',
        'weitere_hosts' => "www.ws.test\nlocalhost",
    ];
    $r = http_anfrage($basis, 'POST', '/install', $h, [], $form, $keks);
    pruefe($r['status'] === 403, 'POST ohne Token abgelehnt');
    $r = http_anfrage($basis, 'POST', '/install', $h, [], $form + ['csrf' => str_repeat('0', 64)], $keks);
    pruefe($r['status'] === 403, 'POST mit falschem Token abgelehnt');

    abschnitt('HTTP: Installation (T-100)');
    $r = http_anfrage($basis, 'POST', '/install', $h, [], ['csrf' => $csrf, 'schluessel' => 'falsch'] + $form, $keks);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Installationsschlüssel ist falsch'), 'falscher Schlüssel abgelehnt');
    pruefe(db_wert('SELECT COUNT(*) FROM konto') == 0, 'kein Konto angelegt');
    $r = http_anfrage($basis, 'POST', '/install', $h, [], ['csrf' => $csrf] + $form, $keks);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Installation abgeschlossen'), 'Installation abgeschlossen');
    $konto = db_zeile('SELECT email, rolle, passwort_hash FROM konto');
    pruefe($konto !== null && $konto['rolle'] === 'betreiber' && $konto['email'] === 'betrieb@example.org', 'erster Betreiber angelegt');
    pruefe($konto !== null && password_verify('ein-langes-passwort', $konto['passwort_hash']), 'Passwort nur als Hash');
    pruefe(db_wert("SELECT haupt FROM host WHERE name = 'ws.test'") == 1, 'Haupt-Host eingetragen');
    pruefe(db_wert("SELECT haupt FROM host WHERE name = 'www.ws.test'") == 0, 'weiterer Host eingetragen');
    pruefe(is_file("$tmp/var/install.lock"), 'install.lock angelegt');
    pruefe(db_wert("SELECT COUNT(*) FROM audit WHERE art = 'installation'") == 1, 'Audit-Eintrag geschrieben');
    $r = http_anfrage($basis, 'GET', '/install', $h);
    pruefe($r['status'] === 404, 'Installer danach gesperrt (404)');
    $r = http_anfrage($basis, 'POST', '/install', $h, [], ['csrf' => $csrf] + $form, $keks);
    pruefe($r['status'] === 404 && db_wert('SELECT COUNT(*) FROM konto') == 1, 'zweite Installation per POST unmöglich');

    abschnitt('HTTP: Kopfzeilen (T-101)');
    $r = http_anfrage($basis, 'GET', '/', $h);
    pruefe($r['status'] === 200, 'Startseite 200');
    $k = $r['kopf'];
    pruefe(isset($k['content-security-policy']) && str_contains($k['content-security-policy'], "'nonce-"), 'CSP mit Nonce');
    pruefe(($k['x-content-type-options'] ?? '') === 'nosniff', 'nosniff');
    pruefe(($k['referrer-policy'] ?? '') === 'strict-origin-when-cross-origin', 'Referrer-Policy');
    pruefe(($k['x-frame-options'] ?? '') === 'DENY', 'X-Frame-Options');
    pruefe(!isset($k['x-powered-by']), 'kein X-Powered-By');
    pruefe(!isset($k['strict-transport-security']), 'HSTS nur über HTTPS (hier HTTP)');
    $r2 = http_anfrage($basis, 'GET', '/', $h);
    pruefe(($r2['kopf']['content-security-policy'] ?? '') !== $k['content-security-policy'], 'Nonce je Anfrage neu');

    abschnitt('HTTP: Hostnamen (T-110)');
    $r = http_anfrage($basis, 'GET', '/', 'fremd.example');
    pruefe($r['status'] === 421, 'fremder Host abgelehnt (421)');
    $r = http_anfrage($basis, 'GET', '/', 'www.ws.test');
    pruefe($r['status'] === 200, 'weiterer erlaubter Host angenommen');
    $r = http_anfrage($basis, 'GET', '/', 'WS.TEST:8080');
    pruefe($r['status'] === 200, 'Host mit Port und Großschreibung angenommen');

    abschnitt('HTTP: Health (T-107)');
    $r = http_anfrage($basis, 'GET', '/health', $h);
    pruefe($r['status'] === 404, 'ohne Token 404');
    $r = http_anfrage($basis, 'GET', '/health', $h, ['X-Health-Token: falsch']);
    pruefe($r['status'] === 404, 'falsches Token 404');
    $r = http_anfrage($basis, 'GET', '/health', $h, ['X-Health-Token: ' . str_repeat('h', 40)]);
    $j = json_decode($r['text'], true);
    pruefe($r['status'] === 200 && in_array($j['status'] ?? '', ['ok', 'fehler'], true) && count($j['zeilen'] ?? []) === 7, 'mit Token 200 und JSON mit sieben Zeilen (AP-15)');
    pruefe(($j['zeilen']['cron']['ampel'] ?? '') === 'fehler' && ($j['status'] ?? '') === 'fehler', 'Cron noch nie gelaufen → Zeile rot, status fehler (HTTP 200)');
    $gleich = true;
    foreach (betrieb_status() as $bz) {
        $gleich = $gleich && ($j['zeilen'][$bz['id']]['ampel'] ?? '') === $bz['ampel'];
    }
    pruefe($gleich, 'health liefert dieselben Ampeln wie betrieb_status() (T-282)');
    pruefe(($j['schema']['ist'] ?? -1) === ($j['schema']['soll'] ?? -2), 'Schema aktuell');
    pruefe(str_starts_with($r['kopf']['content-type'] ?? '', 'application/json'), 'Content-Type JSON');

    abschnitt('HTTP: Jobs per URL (T-105)');
    $r = http_anfrage($basis, 'GET', '/jobs?token=falsch', $h);
    pruefe($r['status'] === 404, 'falsches Token 404');
    $r = http_anfrage($basis, 'GET', '/jobs?token=' . str_repeat('j', 40), $h);
    $j = json_decode($r['text'], true);
    pruefe($r['status'] === 200 && ($j['status'] ?? '') === 'ok', 'Lauf ausgeführt');
    pruefe(einstellung('jobs_letzter_ausloeser') === 'url', 'Auslöser url gespeichert');
    $j = json_decode(http_anfrage($basis, 'GET', '/health', $h, ['X-Health-Token: ' . str_repeat('h', 40)])['text'], true);
    pruefe(($j['zeilen']['cron']['ampel'] ?? '') === 'ok' && ($j['zeilen']['cron']['details']['Ausgelöst über'] ?? '') === 'URL-Aufruf', 'nach dem Lauf: Cron-Zeile grün');

    abschnitt('Jobs per Kommandozeile (T-105)');
    $aus = [];
    $code = 0;
    $cmd = 'WS_CONFIG=' . escapeshellarg("$tmp/config.php") . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$wurzel/server/cli/jobs.php") . ' 2>&1';
    exec($cmd, $aus, $code);
    pruefe($code === 0 && einstellung('jobs_letzter_ausloeser') === 'cli', 'CLI-Lauf erfolgreich');

    abschnitt('HTTP: Wartung (T-108)');
    file_put_contents("$tmp/var/wartung.lock", 'test');
    $r = http_anfrage($basis, 'GET', '/', $h);
    pruefe($r['status'] === 503 && isset($r['kopf']['retry-after']), 'Startseite 503 mit Retry-After');
    $r = http_anfrage($basis, 'GET', '/health', $h, ['X-Health-Token: ' . str_repeat('h', 40)]);
    $j = json_decode($r['text'], true);
    pruefe($r['status'] === 200 && ($j['wartung'] ?? false) === true, 'Health meldet Wartung');
    $r = http_anfrage($basis, 'GET', '/team', $h);
    pruefe($r['status'] !== 503, 'Team-Pfad von der Wartung ausgenommen');
    unlink("$tmp/var/wartung.lock");
    $r = http_anfrage($basis, 'GET', '/', $h);
    pruefe($r['status'] === 200, 'nach Wartung wieder 200');

    abschnitt('HTTP: Sonstiges');
    $r = http_anfrage($basis, 'GET', '/gibtesnicht', $h);
    pruefe($r['status'] === 404, 'unbekannte Seite 404');
    $r = http_anfrage($basis, 'GET', '/assets/site.css', $h);
    pruefe($r['status'] === 200 && str_contains($r['text'], '--lila'), 'Stylesheet mit Design-Tokens ausgeliefert');

    abschnitt('HTTP: Öffentliche Seiten und Erscheinungsbild (T-112 bis T-116, lokal)');
    $r = http_anfrage($basis, 'GET', '/', $h);
    $html = $r['text'];
    pruefe(str_contains($html, 'ALLGAEU-AKUT_Logo_quer_negativ-lila.svg'), 'Logo eingebunden');
    pruefe(str_contains($html, '<h1 class="titel titel-l">Allgäuer <em>Winter-Notfallsymposium</em></h1>') && str_contains($html, '<p class="kicker">Allgäu Akut · Wintersymposium</p>'), 'Titel und Zeile aus der Veranstaltung (erstes Wort weiß, Rest lila; Größenstufe L nach „Notfallsymposium“)');
    pruefe(str_contains($html, 'Für wen') && str_contains($html, 'id="programm"'), 'Infokästen und Programmabschnitt vorhanden');
    pruefe(str_contains($html, '<dl class="eckdaten">') && str_contains($html, '240 / 240') && str_contains($html, 'Mi 20.01.2027'), 'Eckdaten aus den Einstellungen (Startwerte)');
    pruefe(str_contains($html, 'Das Programm erscheint hier, sobald es veröffentlicht ist') && !str_contains($html, 'Tigerente'), 'ohne veröffentlichtes Programm: Hinweis, kein Beispielprogramm');
    pruefe(str_contains($html, 'href="/impressum"') && str_contains($html, 'href="/datenschutz"') && str_contains($html, 'Quelltext (AGPL)'), 'Fußzeile mit Impressum, Datenschutz, Quelltext');
    preg_match_all('/(?:src|href)="(https?:[^"]+)"/', $html, $m);
    $fremd = array_filter($m[1], fn($u) => !str_starts_with($u, 'https://github.com/gen-em/'));
    pruefe($fremd === [], 'keine externen Ressourcen (nur Quelltext-Link nach GitHub)');
    pruefe(!str_contains(file_get_contents($wurzel . '/server/public/assets/site.css'), 'http'), 'CSS ohne externe Adressen');
    foreach (['Anton-Regular', 'Antonio-Bold', 'Tajawal-Regular', 'IBMPlexMono-Regular'] as $font) {
        $r = http_anfrage($basis, 'GET', '/assets/fonts/' . $font . '.woff2', $h);
        pruefe($r['status'] === 200 && strlen($r['text']) > 10000, "Schrift $font ausgeliefert");
    }
    $r = http_anfrage($basis, 'GET', '/favicon.svg', $h);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'prefers-color-scheme: dark'), 'Favicon ausgeliefert, passt sich hell/dunkel an');
    foreach (['/impressum' => 'Impressum', '/datenschutz' => 'Datenschutz'] as $pf => $titel) {
        $r = http_anfrage($basis, 'GET', $pf, $h);
        pruefe($r['status'] === 200 && str_contains($r['text'], $titel) && str_contains($r['text'], 'wird gerade erstellt'), "$titel erreichbar (noch leer)");
    }
    inhalt_setzen('impressum', '<h2>Angaben</h2><p>Philipp Chadid</p><p>Kontakt: <a href="mailto:admin@winter-notfallsymposium.de">admin@winter-notfallsymposium.de</a></p>');
    $r = http_anfrage($basis, 'GET', '/impressum', $h);
    pruefe(str_contains($r['text'], '<h2>Angaben</h2>') && str_contains($r['text'], 'href="mailto:admin@winter-notfallsymposium.de"'), 'Impressum zeigt gepflegten Text');

}

/** Team-Login mit zweitem Faktor, Team-Konto per Einladung, Rollen, Sperre, Ausloggen, Ratenschutz, Notzugang (AP-3 Schritt 1, O-34). */
function http_bereich_login(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp, 'wurzel' => $wurzel] = $u;
    $f = http_frisch($u, ['beispiel' => false]); // Login braucht keine Beispieldaten
    $b = "$tmp/kekse-betreiber.txt";
    $pw = TEST_PASSWORT;

    abschnitt('HTTP: Team-Login (AP-3 Schritt 1)');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $b);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/einloggen', 'Team-Bereich ohne Login → Einloggen');
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'name="passwort"'), 'Login-Formular');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'passwort', 'email' => TEST_BETREIBER, 'passwort' => 'falsch-falsch'], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'oder Passwort stimmt nicht'), 'falsches Passwort abgelehnt');
    $r2 = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'passwort', 'email' => 'niemand@example.org', 'passwort' => 'falsch-falsch'], $b);
    pruefe(str_contains($r2['text'], 'oder Passwort stimmt nicht'), 'unbekannte Adresse: gleiche Meldung (R-03)');
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'passwort', 'email' => 'Betrieb@Example.org', 'passwort' => $pw], $b);
    pruefe($r['status'] === 303, 'richtiges Passwort (Adresse in anderer Schreibweise) → weiter zum zweiten Faktor');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $b);
    pruefe($r['status'] === 303, 'nur Passwort reicht nicht für den Team-Bereich');
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $b);
    $roh = http_totp_geheimnis($r['text']);
    pruefe($r['status'] === 200 && $roh !== null && str_contains($r['text'], 'data-qr="otpauth://totp/'), 'Einrichtung mit QR-Code und Schlüssel');
    pruefe(str_contains($r['text'], '/assets/vendor/qrcode.js') && str_contains($r['text'], '/assets/qr.js'), 'QR-Skripte lokal eingebunden');
    pruefe(str_contains($r['text'], 'name="totp"') && str_contains($r['text'], 'autocomplete="one-time-code"'), 'Code-Feld heißt totp (Bitwarden in Firefox)');
    $r2 = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $b);
    pruefe(http_totp_geheimnis($r2['text']) === $roh, 'Neuladen behält das Geheimnis');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'einrichten', 'totp' => '000000'], $b);
    pruefe(str_contains($r['text'], 'Der Code stimmt nicht'), 'falscher Einrichtungscode abgelehnt');
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'einrichten', 'totp' => totp_code((string)$roh, time())], $b);
    preg_match_all('/<li class="mono">([A-Z2-9]{4} [A-Z2-9]{4})<\/li>/', $r['text'], $m);
    $codes = $m[1];
    pruefe($r['status'] === 200 && count($codes) === 10, 'eingerichtet, zehn Wiederherstellungscodes angezeigt');
    pruefe(db_wert("SELECT COUNT(*) FROM konto_code WHERE hash LIKE '$%'") == 10 && !str_contains((string)db_wert('SELECT totp_geheimnis FROM konto WHERE id = ?', [$f['betreiber']]), totp_base32((string)$roh)), 'Codes nur als Hash, Geheimnis versiegelt');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Bea Betreiberin') && str_contains($r['text'], 'Ausloggen'), 'Team-Bereich mit Name und Ausloggen (E-40)');
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Neues Team-Konto'), 'Team-Konten für Betreiber');

    abschnitt('HTTP: Team-Konto anlegen und einrichten');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $csrf, 'aktion' => 'anlegen', 'name' => 'Ina Einladung', 'email' => 'ina@example.org', 'rolle' => 'admin'], $b);
    pruefe($r['status'] === 303 && konto_per_email('ina@example.org')['rolle'] === 'admin', 'Konto angelegt');
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $b);
    pruefe(!str_contains($r['text'], '/einrichten?t=') && str_contains($r['text'], 'Einladung an ina@example.org geschickt') && str_contains($r['text'], 'Einrichtung offen'), 'Einladung per Mail, Link nicht angezeigt (AP-6)');
    $mail = http_mail_an($tmp, 'ina@example.org', 20, 'Einladung in den Team-Bereich');
    $link = preg_match('#/einrichten\?t=([A-Za-z0-9_-]+)#', $mail, $m) === 1 ? $m[1] : '';
    pruefe($link !== '' && str_contains($mail, 'Rolle „Admin“'), 'Einladungsmail mit Einrichtungslink und Rolle');
    $a = "$tmp/kekse-admin.txt";
    $r = http_anfrage($basis, 'GET', '/einrichten?t=falsch' . str_repeat('x', 40), $h, [], null, $a);
    pruefe($r['status'] === 404, 'falscher Link abgelehnt');
    $r = http_anfrage($basis, 'GET', '/einrichten?t=' . $link, $h, [], null, $a);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Ina Einladung'), 'Einrichtungsseite');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/einrichten', $h, [], ['csrf' => $csrf, 't' => $link, 'passwort' => 'kurz', 'passwort2' => 'kurz'], $a);
    pruefe(str_contains($r['text'], 'mindestens'), 'zu kurzes Passwort abgelehnt');
    $r = http_anfrage($basis, 'POST', '/einrichten', $h, [], ['csrf' => $csrf, 't' => $link, 'passwort' => 'inas-langes-passwort', 'passwort2' => 'inas-langes-passwort'], $a);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/einloggen', 'Passwort gesetzt → zweiten Faktor einrichten');
    $r = http_anfrage($basis, 'GET', '/einrichten?t=' . $link, $h, [], null, "$tmp/kekse-x.txt");
    pruefe($r['status'] === 404, 'Link gilt nur einmal');
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $a);
    $rohA = http_totp_geheimnis($r['text']);
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'einrichten', 'totp' => totp_code((string)$rohA, time())], $a);
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $a);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Ina Einladung'), 'Admin eingeloggt');
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $a);
    pruefe($r['status'] === 403, 'Admin sieht keine Team-Konten (E-11)');
    pruefe(!str_contains(http_anfrage($basis, 'GET', '/team', $h, [], null, $a)['text'], 'href="/team/konten"'), 'Reiter Team-Konten für Admin ausgeblendet');

    abschnitt('HTTP: Rollenwechsel, Sperre, Sitzungen');
    $inaId = (int)konto_per_email('ina@example.org')['id'];
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $b);
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $csrf, 'aktion' => 'rolle', 'id' => $inaId, 'rolle' => 'betreiber', 'totp' => '000000'], $b);
    pruefe(str_contains($r['text'], 'Die Rolle wurde nicht geändert') && konto_laden($inaId)['rolle'] === 'admin', 'Rollenwechsel ohne gültigen eigenen Code abgelehnt');
    // Code einmal erzeugen und unten wiederverwenden (sonst kann eine 30-s-Grenze dazwischen liegen)
    $benutzt = totp_code((string)$roh, time() + 30);
    $r = http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $csrf, 'aktion' => 'rolle', 'id' => $inaId, 'rolle' => 'betreiber', 'totp' => $benutzt], $b);
    pruefe($r['status'] === 303 && konto_laden($inaId)['rolle'] === 'betreiber', 'Rollenwechsel mit eigenem Code (E-41)');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $a);
    pruefe($r['status'] === 303, 'Rollenwechsel beendet die Sitzung des Kontos');
    $r = http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $csrf, 'aktion' => 'sperren', 'id' => $inaId], $b);
    pruefe((int)konto_laden($inaId)['aktiv'] === 1 && str_contains($r['text'], 'Nichts geändert'), 'Sperren ohne eigenen zweiten Faktor abgelehnt (Review B-7)');
    // Mail-Code gilt zum Bestätigen auch ohne eingeschalteten Code per E-Mail (T-290)
    db_ausfuehren('UPDATE konto SET mailcode_hash = ?, mailcode_bis = ? WHERE id = ?', [password_hash('482913', PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s', time() + 600), $f['betreiber']]);
    http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $csrf, 'aktion' => 'sperren', 'id' => $inaId, 'totp' => '482913'], $b);
    pruefe((int)konto_laden($inaId)['aktiv'] === 0, 'Konto gesperrt (bestätigt mit Code aus der Mail)');
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $a);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'ina@example.org', 'passwort' => 'inas-langes-passwort'], $a);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'stimmt nicht'), 'gesperrtes Konto kommt nicht rein');
    $r = http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $csrf, 'aktion' => 'sperren', 'id' => $f['betreiber']], $b);
    pruefe((int)konto_laden($f['betreiber'])['aktiv'] === 1 && str_contains($r['text'], 'eigenes Konto'), 'eigenes Konto nicht sperrbar');

    abschnitt('HTTP: Ausloggen, Wiederholungsschutz, Wiederherstellungscode');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $b);
    $r = http_anfrage($basis, 'POST', '/ausloggen', $h, [], ['csrf' => http_csrf($r['text'])], $b);
    $r2 = http_anfrage($basis, 'GET', '/team', $h, [], null, $b);
    pruefe($r['status'] === 303 && $r2['status'] === 303, 'ausgeloggt, danach kein Zugriff');
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $b);
    $csrf = http_csrf($r['text']);
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'passwort', 'email' => TEST_BETREIBER, 'passwort' => $pw], $b);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Authenticator-App ein') && !str_contains($r['text'], 'data-qr'), 'zweites Login fragt nur den Code');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'code', 'totp' => $benutzt], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Der Code stimmt nicht'), 'schon benutzter Code gilt nicht noch einmal');
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'code', 'totp' => strtolower($codes[0])], $b);
    pruefe($r['status'] === 303, 'Wiederherstellungscode öffnet');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Wiederherstellungscode benutzt') && str_contains($r['text'], 'Übrig: 9'), 'Hinweis auf benutzten Code');

    abschnitt('HTTP: Ratenschutz Login');
    $x = "$tmp/kekse-rate.txt";
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $x);
    $csrf = http_csrf($r['text']);
    for ($i = 0; $i < RATE_TOEPFE['login'][0]; $i++) {
        http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'passwort', 'email' => 'ziel@example.org', 'passwort' => 'falsch-' . $i], $x);
    }
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'passwort', 'email' => 'ziel@example.org', 'passwort' => 'x'], $x);
    pruefe(str_contains($r['text'], 'Zu viele Versuche'), 'nach ' . RATE_TOEPFE['login'][0] . ' Fehlversuchen für eine Adresse gesperrt');

    abschnitt('HTTP: Notzugang mit Installationsschlüssel (O-34)');
    $n = "$tmp/kekse-not.txt";
    $r = http_anfrage($basis, 'GET', '/notzugang', $h, [], null, $n);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Installationsschlüssel'), 'Notzugang erreichbar');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/notzugang', $h, [], ['csrf' => $csrf, 'schluessel' => 'falsch', 'email' => TEST_BETREIBER], $n);
    pruefe(str_contains($r['text'], 'stimmt nicht') && konto_laden($f['betreiber'])['totp_seit'] !== null, 'falscher Schlüssel: nichts zurückgesetzt');
    $r = http_anfrage($basis, 'POST', '/notzugang', $h, [], ['csrf' => $csrf, 'schluessel' => str_repeat('i', 40), 'email' => 'niemand@example.org'], $n);
    pruefe(str_contains($r['text'], 'stimmt nicht'), 'unbekannte Adresse: gleiche Meldung');
    $r = http_anfrage($basis, 'POST', '/notzugang', $h, [], ['csrf' => $csrf, 'schluessel' => str_repeat('i', 40), 'email' => TEST_BETREIBER], $n);
    $notlink = preg_match('#/einrichten\?t=([A-Za-z0-9_-]+)#', $r['text'], $m) === 1 ? $m[1] : '';
    pruefe($notlink !== '' && konto_laden($f['betreiber'])['totp_seit'] === null, 'richtiger Schlüssel: zweiter Faktor zurückgesetzt, Link angezeigt');
    pruefe(db_wert("SELECT COUNT(*) FROM audit WHERE art = 'notzugang'") == 1, 'Notzugang im Audit-Protokoll');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $b);
    pruefe($r['status'] === 303, 'alte Sitzung des Kontos beendet');
    for ($i = 0; $i < RATE_TOEPFE['notzugang'][0]; $i++) {
        http_anfrage($basis, 'POST', '/notzugang', $h, [], ['csrf' => $csrf, 'schluessel' => 'falsch', 'email' => 'x@example.org'], $n);
    }
    $r = http_anfrage($basis, 'POST', '/notzugang', $h, [], ['csrf' => $csrf, 'schluessel' => str_repeat('i', 40), 'email' => TEST_BETREIBER], $n);
    pruefe(str_contains($r['text'], 'Zu viele Versuche') && !str_contains($r['text'], '/einrichten?t='), 'nach drei Fehlversuchen gesperrt, auch mit richtigem Schlüssel');

    abschnitt('Notweg auf der Kommandozeile');
    $aus = [];
    exec('WS_CONFIG=' . escapeshellarg("$tmp/config.php") . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$wurzel/server/cli/konto.php") . ' zweitfaktor ina@example.org 2>&1', $aus, $code);
    pruefe($code === 0 && konto_laden($inaId)['totp_seit'] === null, 'zweiten Faktor zurückgesetzt');
    $aus = [];
    exec('WS_CONFIG=' . escapeshellarg("$tmp/config.php") . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$wurzel/server/cli/konto.php") . ' link ina@example.org 2>&1', $aus, $code);
    pruefe($code === 0 && str_contains(implode('', $aus), '/einrichten?t='), 'neuen Link erzeugt');
}

/** Veranstaltungs-Einstellungen über HTTP (AP-3 Schritt 2): Formular, Fehler, Speichern, Startseite, Protokoll. */
function http_bereich_einstellungen(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp] = $u;
    http_frisch($u);
    $b = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT)['keks'];

    abschnitt('HTTP: Veranstaltungs-Einstellungen (AP-3 Schritt 2)');
    $r = http_anfrage($basis, 'GET', '/team/einstellungen', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'name="veranstaltung_ort"') && str_contains($r['text'], 'name="berufsgruppen"'), 'Einstellungsseite mit allen Feldern');
    // gespeichert ist UTC, im Feld steht die Ortszeit (Winter: eine Stunde später)
    $start = new DateTimeImmutable(va('anmeldung_start'), new DateTimeZone('UTC'));
    pruefe(str_contains($r['text'], 'value="' . $start->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i') . '"') && !str_contains($r['text'], 'value="' . $start->format('Y-m-d\TH:i') . '"'), 'Anmeldestart in Ortszeit vorbelegt');
    $form = test_va_form(http_csrf($r['text']));
    $r = http_anfrage($basis, 'POST', '/team/einstellungen', $h, [], ['veranstaltung_ende' => '17:00'] + $form, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Nicht gespeichert') && str_contains($r['text'], 'aria-invalid="true"') && str_contains($r['text'], 'value="17:00"') && va('veranstaltung_ende') === '22:00', 'Fehler: markiert, Eingabe bleibt stehen, nichts gespeichert');
    $audit = (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'einstellungen'");
    $r = http_anfrage($basis, 'POST', '/team/einstellungen', $h, [], ['veranstaltung_ort' => 'Klinikum Test', 'veranstaltung_kapazitaet' => '120'] + $form, $b);
    pruefe($r['status'] === 303 && va('veranstaltung_ort') === 'Klinikum Test', 'gespeichert');
    $r = http_anfrage($basis, 'GET', '/', $h);
    // 120 Plätze, belegt von den vier Beispiel-Anmeldungen (Lea, Paula, Tom und Adas untergeschobene)
    pruefe(str_contains($r['text'], 'Klinikum Test') && str_contains($r['text'], 'Freie Plätze</dt><dd>116 / 120'), 'Startseite zeigt die neuen Werte sofort (T-121)');
    pruefe((int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'einstellungen'") === $audit + 1, 'Änderung im Audit-Protokoll');
    $r = http_anfrage($basis, 'POST', '/team/einstellungen', $h, [], ['veranstaltung_ort' => 'Ohne Login'] + $form, "$tmp/kekse-leer.txt");
    pruefe($r['status'] === 303 && va('veranstaltung_ort') === 'Klinikum Test', 'ohne Login nicht speicherbar');
}

/** Programm pflegen, veröffentlichen, anzeigen (AP-3 Schritt 3) – am Beispielprogramm, neue Punkte über das Formular. */
function http_bereich_programm(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp] = $u;
    $f = http_frisch($u);
    $entwurf = beispieldaten_entwurf();
    [$vtId, $blockId, $stId] = [$f['bd']['programm']['punkte'][1], $f['bd']['programm']['punkte'][3], $f['bd']['programm']['punkte'][7]];
    $b = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT)['keks'];

    abschnitt('HTTP: Programm pflegen und veröffentlichen (AP-3 Schritt 3)');
    $r = http_anfrage($basis, 'GET', '/team/programm?va=' . $entwurf, $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Noch keine Programmpunkte'), 'Programm-Reiter einer neuen Veranstaltung: noch leer');
    $r = http_anfrage($basis, 'GET', '/team/programm?va=' . va_aktuell_id(), $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Atemweg. Plan B.') && str_contains($r['text'], 'Alles veröffentlicht'), 'Programm-Reiter mit dem veröffentlichten Beispielprogramm');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'GET', '/team/programm?neu=vortrag', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'value="VT 02"'), 'Kürzel-Vorschlag VT 02 (VT 01 gibt es schon)');
    // Dozentin anlegen
    $r = http_anfrage($basis, 'POST', '/team/dozierende', $h, [], ['csrf' => $csrf, 'aktion' => 'speichern', 'name' => 'Dr. Erika Test', 'funktion' => 'Oberärztin', 'kurzbio' => 'Kurz.'], $b);
    $dozId = (int)db_wert("SELECT id FROM dozent WHERE name = 'Dr. Erika Test'");
    pruefe($r['status'] === 303 && $dozId > 0, 'Dozentin angelegt');
    // Fehlerfall: Ende vor Beginn
    $punkte = (int)db_wert('SELECT COUNT(*) FROM programmpunkt WHERE veranstaltung_id = ?', [va_aktuell_id()]);
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'speichern', 'typ' => 'vortrag', 'kuerzel' => 'VT 02', 'titel' => 'Delir', 'beginn' => '18:30', 'ende' => '18:00'], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Das Ende muss nach dem Beginn liegen') && str_contains($r['text'], 'value="Delir"') && (int)db_wert('SELECT COUNT(*) FROM programmpunkt WHERE veranstaltung_id = ?', [va_aktuell_id()]) === $punkte, 'Fehler am Feld, Eingabe bleibt, nichts gespeichert');
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'speichern', 'typ' => 'vortrag', 'kuerzel' => 'VT 02', 'titel' => 'Delir <b>jetzt</b>', 'beginn' => '17:30', 'ende' => '18:00', 'ort' => 'Hörsaal', 'dozenten' => [$dozId]], $b);
    pruefe($r['status'] === 303 && db_wert("SELECT titel FROM programmpunkt WHERE kuerzel = 'VT 02' AND veranstaltung_id = ?", [va_aktuell_id()]) === 'Delir <b>jetzt</b>', 'Vortrag gespeichert (Titel als Text)');
    // Workshop mit zwei Terminen im Raum von WS 01, Station: hängen unter dem Block
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'speichern', 'typ' => 'workshop', 'kuerzel' => 'WS 03', 'titel' => 'Nadeldekompression', 'ort' => 'Skills-Lab 1', 'bring' => 'Handschuhe', 'notiz' => 'Bitte pünktlich',
        't_id' => [0, 0], 't_beginn' => ['19:30', '20:30'], 't_ende' => ['20:15', '21:15'], 't_plaetze' => ['8', '6']], $b);
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'speichern', 'typ' => 'station', 'titel' => 'Ultraschall zum Anfassen', 'ort' => 'Foyer', 'beginn' => '19:30', 'ende' => '21:00'], $b);
    $ws3 = (int)db_wert("SELECT id FROM programmpunkt WHERE kuerzel = 'WS 03' AND veranstaltung_id = ?", [va_aktuell_id()]);
    pruefe((int)db_wert('SELECT COUNT(*) FROM programmpunkt WHERE veranstaltung_id = ?', [va_aktuell_id()]) === $punkte + 3 && (int)db_wert('SELECT COUNT(*) FROM workshop_termin WHERE punkt_id = ?', [$ws3]) === 2, 'Workshop mit zwei Terminen und Station gespeichert');
    $r = http_anfrage($basis, 'GET', '/team/programm', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Gespeichert, aber nicht veröffentlicht') && substr_count($r['text'], '<span class="st ges">nicht veröffentlicht</span>') >= 3, 'Banner und Markierung „nicht veröffentlicht“');
    pruefe(str_contains($r['text'], 'Skills-Lab 1 ist 19:30–20:15 doppelt belegt'), 'Hinweis: Raum doppelt belegt');
    pruefe(preg_match('#<ol class="pg-kinder">.*WS 03.*Ultraschall zum Anfassen#s', $r['text']) === 1, 'neue Termine und Station hängen unter dem Block');
    $r = http_anfrage($basis, 'GET', '/', $h);
    pruefe(str_contains($r['text'], 'Atemweg. Plan B.') && !str_contains($r['text'], 'Delir') && !str_contains($r['text'], 'Nadeldekompression'), 'Startseite noch ohne die neuen Punkte (nur gespeichert)');
    $r = http_anfrage($basis, 'GET', '/team/programm/vorschau', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Vorschau') && str_contains($r['text'], 'Nadeldekompression'), 'Vorschau zeigt Arbeitsstand');
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'veroeffentlichen'], $b);
    pruefe($r['status'] === 303, 'veröffentlicht');
    $html = http_anfrage($basis, 'GET', '/', $h)['text'];
    pruefe(str_contains($html, 'Delir &lt;b&gt;jetzt&lt;/b&gt;') && !str_contains($html, '<b>jetzt</b>'), 'Startseite zeigt Programm, Titel maskiert');
    pruefe(str_contains($html, 'data-dialog="pp-') && str_contains($html, '<dialog class="popup"') && str_contains($html, 'Dr. Erika Test'), 'Popups mit der neuen Dozentin');
    pruefe(str_contains($html, 'class="gantt-wrap"') && str_contains($html, 'class="ws-mobil"') && str_contains($html, '8 frei'), 'Workshop-Block: Raster und Liste, freie Plätze des neuen Termins');
    pruefe(str_contains($html, 'Bitte pünktlich') && str_contains($html, 'Handschuhe'), 'Workshop-Popup mit Bring und Notiz');
    pruefe(str_contains($html, '/assets/programm.js'), 'Skript für Popups eingebunden');
    preg_match_all('/(?:src|href)="(https?:[^"]+)"/', $html, $m);
    pruefe(array_filter($m[1], fn($x) => !str_starts_with($x, 'https://github.com/gen-em/')) === [], 'Programm ohne externe Ressourcen');
    $r = http_anfrage($basis, 'GET', '/team/programm', $h, [], null, $b);
    pruefe(!str_contains($r['text'], 'Gespeichert, aber nicht veröffentlicht') && str_contains($r['text'], 'Alles veröffentlicht'), 'nach dem Veröffentlichen kein Banner');
    // Ändern, verschieben, verwerfen (VT 01 18:15–18:45, Block 19:30–21:30)
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'verschieben', 'art' => 'punkt', 'id' => $vtId, 'beginn' => '18:30'], $b);
    pruefe($r['status'] === 303 && db_wert('SELECT beginn FROM programmpunkt WHERE id = ?', [$vtId]) === '18:30:00' && db_wert('SELECT ende FROM programmpunkt WHERE id = ?', [$vtId]) === '19:00:00', 'Verschieben: neuer Beginn, Dauer bleibt');
    $r = http_anfrage($basis, 'GET', '/team/programm', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Neue Uhrzeit vorgeschlagen: 18:30–19:00'), 'Meldung nennt neue Uhrzeit von–bis');
    pruefe(str_contains($r['text'], '<span class="st ges">nicht veröffentlicht</span>') && str_contains($r['text'], 'Gespeichert, aber nicht veröffentlicht'), 'geändert markiert');
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'verschieben', 'art' => 'punkt', 'id' => $blockId, 'beginn' => '20:00'], $b);
    pruefe(db_wert('SELECT MIN(t.beginn) FROM workshop_termin t JOIN programmpunkt p ON p.id = t.punkt_id WHERE p.veranstaltung_id = ?', [va_aktuell_id()]) === '20:00:00' && db_wert('SELECT beginn FROM programmpunkt WHERE id = ?', [$stId]) === '20:00:00', 'Block verschieben nimmt Termine und Station mit');
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'verwerfen'], $b);
    pruefe(db_wert('SELECT beginn FROM programmpunkt WHERE id = ?', [$vtId]) === '18:15:00' && db_wert('SELECT MIN(t.beginn) FROM workshop_termin t JOIN programmpunkt p ON p.id = t.punkt_id WHERE p.veranstaltung_id = ?', [va_aktuell_id()]) === '19:30:00', 'Verwerfen stellt den veröffentlichten Stand her');
    pruefe(programm_status()['offen'] === false, 'danach nichts offen');
    // Löschen ist erst nach dem Veröffentlichen weg
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'loeschen', 'id' => $stId], $b);
    pruefe(str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], 'Reanimation im Kreis'), 'gelöscht, aber bis zum Veröffentlichen noch auf der Startseite');
    $r = http_anfrage($basis, 'GET', '/team/programm', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Reanimation im Kreis (gelöscht)'), 'Banner nennt gelöschten Punkt');
    // Veröffentlichen verlangt Uhrzeiten
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'speichern', 'typ' => 'pause', 'titel' => 'Pause ohne Zeit'], $b);
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'veroeffentlichen'], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Nicht veröffentlicht') && str_contains($r['text'], 'Pause ohne Zeit'), 'Punkt ohne Uhrzeit verhindert das Veröffentlichen');
    $r = http_anfrage($basis, 'GET', '/team/programm', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'pg-neu-punkt'), 'Punkt ohne Uhrzeit steht oben als neu');
    $pauseId = (int)db_wert("SELECT id FROM programmpunkt WHERE titel = 'Pause ohne Zeit'");
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'verschieben', 'art' => 'punkt', 'id' => $pauseId, 'beginn' => '22:00'], $b);
    pruefe(db_wert('SELECT ende FROM programmpunkt WHERE id = ?', [$pauseId]) === '22:30:00', 'Ziehen gibt einem neuen Punkt 30 Minuten');
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'veroeffentlichen'], $b);
    pruefe($r['status'] === 303 && !str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], 'Reanimation im Kreis'), 'nach dem Veröffentlichen ist der gelöschte Punkt weg');
    // Foto der Beispiel-Dozentin (braucht die PHP-Erweiterung GD; ohne sie ist der Upload ausgeblendet)
    if (!foto_moeglich()) {
        echo "  --    Foto-Prüfungen übersprungen: GD fehlt\n";
    } else {
        $d1 = $f['bd']['programm']['dozenten'][0];
        $png = "$tmp/foto.png";
        $bild = imagecreatetruecolor(800, 600);
        imagefill($bild, 0, 0, imagecolorallocate($bild, 180, 140, 255));
        imagepng($bild, $png);
        $r = http_anfrage($basis, 'POST', '/team/dozierende', $h, [], ['csrf' => $csrf, 'aktion' => 'speichern', 'id' => $d1, 'name' => 'Dr. Erika Muster', 'funktion' => 'Oberärztin, Zentrale Notaufnahme', 'kurzbio' => 'Seit 12 Jahren in der Notaufnahme.', 'foto' => new CURLFile($png, 'image/png', 'foto.png')], $b);
        $foto = (string)db_wert('SELECT foto FROM dozent WHERE id = ?', [$d1]);
        pruefe(preg_match('/^dozent-\d+-[0-9a-f]{12}\.jpg$/', $foto) === 1, 'Foto hochgeladen und als JPEG gespeichert');
        $r = http_anfrage($basis, 'GET', '/bild/' . $foto, $h);
        $info = @getimagesizefromstring($r['text']);
        pruefe($r['status'] === 200 && $info !== false && $info[0] === FOTO_KANTE && $info[1] === FOTO_KANTE, 'Foto quadratisch verkleinert ausgeliefert');
        pruefe(http_anfrage($basis, 'GET', '/bild/../config.php', $h)['status'] === 404 && http_anfrage($basis, 'GET', '/bild/dozent-1-zzz.jpg', $h)['status'] === 404, 'Bild-Adresse nimmt nur echte Fotonamen');
        $r = http_anfrage($basis, 'GET', '/team/programm', $h, [], null, $b);
        pruefe(str_contains($r['text'], 'Gespeichert, aber nicht veröffentlicht') && !str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], '/bild/' . $foto), 'Foto-Änderung zählt als unveröffentlicht');
        http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'veroeffentlichen'], $b);
        pruefe(str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], '/bild/' . $foto), 'Foto im Popup nach dem Veröffentlichen');
    }
    // Ohne Login
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'loeschen', 'id' => $vtId], "$tmp/kekse-niemand.txt");
    pruefe(in_array($r['status'], [303, 403], true) && db_wert('SELECT COUNT(*) FROM programmpunkt WHERE id = ?', [$vtId]) == 1, 'ohne Login nichts änderbar');
}

/** Anmeldung über das Formular, Einmal-Links, Deine Anmeldung, Team-Liste (AP-4) – neben den Beispiel-Anmeldungen. */
function http_bereich_anmeldung(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp] = $u;
    http_frisch($u);
    $b = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT)['keks'];
    $anzahl = fn(): int => (int)db_wert('SELECT COUNT(*) FROM anmeldung');
    $vorher = $anzahl();

    abschnitt('HTTP: Anmeldung (AP-4)');
    $start = einstellung('anmeldung_start');
    einstellung_setzen('anmeldung_start', gmdate('Y-m-d H:i:s', time() + 86400));
    $r = http_anfrage($basis, 'GET', '/anmelden', $h);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Die Anmeldung öffnet am') && !str_contains($r['text'], 'name="vorname"'), 'vor dem Start: Hinweis statt Formular');
    pruefe(!str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], 'Jetzt anmelden'), 'Startseite ohne Anmeldeknopf vor dem Start');
    einstellung_setzen('anmeldung_start', (string)$start);
    $k1 = "$tmp/kekse-tn1.txt";
    $r = http_anfrage($basis, 'GET', '/anmelden', $h, [], null, $k1);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'name="pow_aufgabe"') && str_contains($r['text'], 'name="website"') && str_contains($r['text'], 'name="ws['), 'Formular mit Spamschutz und Workshop-Wahl');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], 'Jetzt anmelden'), 'Startseite: „Jetzt anmelden“ (E-42)');
    $r = http_anfrage($basis, 'POST', '/anmelden', $h, [], ['vorname' => 'X'], $k1);
    pruefe($r['status'] === 403, 'ohne CSRF-Token abgelehnt');
    $wid = test_workshop('WS 01');
    $t1 = test_termin('WS 01', '19:30');
    $t2 = test_termin('WS 01', '20:30');
    $person = ['vorname' => 'Lena', 'nachname' => 'Test', 'email' => 'Lena.Test@Example.org', 'berufsgruppe' => 'ÄrztIn', 'institution' => 'ZNA Test', 'efn' => '80276 00123 45673', 'essen' => 'vegetarisch', 'ws' => [$wid => $t1]];
    $r = http_anmelden($basis, $h, $k1, ['email' => 'kaputt', 'einwilligung' => ''] + $person);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Noch nicht angemeldet') && str_contains($r['text'], 'aria-invalid="true"') && str_contains($r['text'], 'value="Lena"') && $anzahl() === $vorher, 'Eingabefehler sichtbar, Eingabe bleibt, nichts angelegt');
    $r = http_anmelden($basis, $h, $k1, ['website' => 'http://spam.example'] + $person);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Schau in dein Postfach') && $anzahl() === $vorher, 'Honigtopf: gleiche Antwort, nichts angelegt (T-131)');
    $r = http_anmelden($basis, $h, $k1, ['pow_loesung' => '1'] + $person);
    pruefe(str_contains($r['text'], 'Schau in dein Postfach') && $anzahl() === $vorher, 'ohne gelöste Rechenaufgabe: gleiche Antwort, nichts angelegt');
    $r = http_anmelden($basis, $h, $k1, ['zeit' => spam_stempel(time() - 1)] + $person);
    pruefe(str_contains($r['text'], 'Schau in dein Postfach') && $anzahl() === $vorher, 'Formular unter 4 s: gleiche Antwort, nichts angelegt');
    $r = http_anmelden($basis, $h, $k1, ['email' => 'wer@mailinator.com'] + $person);
    pruefe(str_contains($r['text'], 'Schau in dein Postfach') && $anzahl() === $vorher, 'Wegwerfadresse: gleiche Antwort, nichts angelegt (T-132)');
    $r = http_anmelden($basis, $h, $k1, $person);
    $a = db_zeile("SELECT * FROM anmeldung WHERE email = 'lena.test@example.org'");
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Schau in dein Postfach') && $a !== null && $a['status'] === 'unbestaetigt' && $a['efn'] === '802760012345673', 'Anmeldung angelegt (unbestätigt, Adresse klein, EFN ohne Leerzeichen)');
    $aId = (int)($a['id'] ?? 0);
    pruefe(db_wert('SELECT COUNT(*) FROM platz WHERE anmeldung_id = ? AND termin_id = ?', [$aId, $t1]) == 1, 'Workshop-Platz reserviert');
    $mail = http_mail_an($tmp, 'lena.test@example.org');
    preg_match('~(/link\?t=[A-Za-z0-9_-]{43})~', $mail, $m);
    pruefe(str_contains($mail, 'Subject: ') && preg_match('/^From: (.*) <anmeldung@' . preg_quote((string)host_haupt(), '/') . '>\r?$/m', $mail, $von) === 1 && mb_decode_mimeheader($von[1]) === va_absender_name() && str_contains($mail, 'Sie gilt erst, wenn du sie') && isset($m[1]), 'Bestätigungsmail mit Link (nach der Antwort versendet), Absender ist die Veranstaltung (E-67)');
    for ($i = 0; $i < 20 && (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE status = 'gesendet' AND an = ''") === 0; $i++) {
        usleep(100_000); // Datei ist geschrieben, der Vermerk in der Datenbank folgt gleich
    }
    pruefe((int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE status = 'gesendet' AND an = ''") >= 1 && db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE an = 'lena.test@example.org'") == 0, 'Warteschlange: Empfänger nach dem Versand geleert');
    $link = $m[1] ?? '/link?t=x';
    $r = http_anfrage($basis, 'GET', $link, $h, [], null, $k1);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Anmeldung bestätigen') && db_wert('SELECT COUNT(*) FROM link WHERE art = "anmeldung" AND anmeldung_id = ?', [$aId]) == 1 && anm_laden($aId)['status'] === 'unbestaetigt', 'Link öffnen verbraucht ihn nicht (Mail-Scanner)');
    $r = http_anfrage($basis, 'POST', '/link', $h, [], ['csrf' => http_csrf($r['text']), 't' => substr($link, 8)], $k1);
    pruefe($r['status'] === 303 && anm_laden($aId)['status'] === 'bestaetigt', 'Klick bestätigt die Anmeldung (T-130)');
    $r = http_anfrage($basis, 'GET', $link, $h, [], null, $k1);
    pruefe(str_contains($r['text'], 'Link abgelaufen'), 'Link danach ungültig (T-135)');
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k1);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Hallo Lena.') && str_contains($r['text'], 'Anmeldung bestätigt') && str_contains($r['text'], 'Platz bestätigt') && str_contains($r['text'], '80276 00123 45673'), 'Deine Anmeldung: Status, Workshop, EFN');
    pruefe(str_contains($r['text'], '<strong>Lena Test</strong>') && str_contains($r['text'], 'action="/ausloggen"'), 'Personen-Menü zeigt Name und „Ausloggen“ (E-40, E-54)');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $csrf, 'aktion' => 'essen', 'essen' => 'kein'], $k1);
    pruefe($r['status'] === 303 && anm_laden($aId)['essen'] === 'kein', 'Essen ändern');
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $csrf, 'aktion' => 'workshops', 'ws' => [$wid => $t2]], $k1);
    pruefe($r['status'] === 303 && db_wert('SELECT termin_id FROM platz WHERE anmeldung_id = ? AND termin_id <> 0', [$aId]) == $t2, 'Workshop-Termin wechseln');
    pruefe(http_mail_an($tmp, 'lena.test@example.org', 20, 'deine Workshops sind ge') !== '', 'Mail nach der Änderung');
    einstellung_setzen('stichtag_workshop', gmdate('Y-m-d', time() - 86400));
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k1);
    pruefe(str_contains($r['text'], 'nur noch über das Team') && !str_contains($r['text'], 'href="/deine-anmeldung?aendern=workshops"'), 'Nach dem Stichtag: Knopf gesperrt mit Hinweis (E-31)');
    http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $csrf, 'aktion' => 'workshops', 'ws' => [$wid => $t1]], $k1);
    pruefe(db_wert('SELECT termin_id FROM platz WHERE anmeldung_id = ? AND termin_id <> 0', [$aId]) == $t2, 'Nach dem Stichtag: Änderung abgelehnt (T-143)');
    db_ausfuehren("DELETE FROM va_einstellung WHERE schluessel = 'stichtag_workshop'");
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $csrf, 'aktion' => 'daten', 'vorname' => 'Lena', 'nachname' => 'Test', 'email' => 'neu@example.org', 'berufsgruppe' => 'ÄrztIn', 'institution' => 'ZNA Test', 'efn' => ''], $k1);
    pruefe($r['status'] === 303 && anm_laden($aId)['email'] === 'lena.test@example.org', 'Neue Adresse gilt erst nach Bestätigung');
    preg_match('~/link\?t=([A-Za-z0-9_-]{43})~', http_mail_an($tmp, 'neu@example.org'), $m2);
    anm_link_einloesen($m2[1] ?? '');
    pruefe(anm_laden($aId)['email'] === 'neu@example.org', 'nach dem Link gilt die neue Adresse');

    abschnitt('HTTP: Gleiche Adresse, Link per Mail, Storno, Team (AP-4)');
    $k2 = "$tmp/kekse-tn2.txt";
    $mails = http_mail_anzahl($tmp, 'neu@example.org');
    $r = http_anmelden($basis, $h, $k2, ['email' => 'neu@example.org'] + $person);
    pruefe(str_contains($r['text'], 'Schau in dein Postfach') && $anzahl() === $vorher + 1, 'gleiche Adresse: gleiche Antwort, keine zweite Anmeldung (E-46)');
    pruefe(http_mail_an($tmp, 'neu@example.org', 20, 'gibt es schon eine Anmeldung') !== '' && http_mail_anzahl($tmp, 'neu@example.org') === $mails + 1, 'stattdessen Link zur bestehenden Anmeldung');
    $k3 = "$tmp/kekse-tn3.txt";
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k3);
    pruefe(str_contains($r['text'], 'Wie willst du rein?') && str_contains($r['text'], 'Link schicken'), 'Einstieg ohne Sitzung');
    $c3 = http_csrf($r['text']);
    $t0 = microtime(true);
    $r1 = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $c3, 'aktion' => 'link', 'email' => 'unbekannt@example.org', 'website' => ''], $k3);
    $d1 = microtime(true) - $t0;
    $t0 = microtime(true);
    $r2 = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $c3, 'aktion' => 'link', 'email' => 'paula@example.org', 'website' => ''], $k3);
    $d2 = microtime(true) - $t0;
    $f1 = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k3);
    pruefe($r1['status'] === 303 && $r2['status'] === 303 && abs($d1 - $d2) < 0.3 && str_contains($f1['text'], 'Wenn die Adresse bei uns angemeldet ist'), 'Link anfordern: gleiche Antwort und Dauer (T-134)');
    pruefe(http_mail_an($tmp, 'unbekannt@example.org', 3) === '' && http_mail_an($tmp, 'paula@example.org', 20, 'siehst und änderst du deine Anmeldung') !== '', 'Mail nur an bekannte Adresse (Paula)');
    pruefe((http_anfrage($basis, 'GET', '/team/anmeldungen', $h, [], null, $b)['kopf']['location'] ?? '') === '/team/personen', 'alte Adresse /team/anmeldungen leitet auf Personen');
    $r = http_anfrage($basis, 'GET', '/team/personen', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Lena Test') && str_contains($r['text'], 'neu@example.org') && str_contains($r['text'], 'Paula Probe') && str_contains($r['text'], 'href="/team/personen" aria-current'), 'Team: Personen (neue und Beispiel-Anmeldungen)');
    pruefe(http_anfrage($basis, 'GET', '/team/personen', $h, [], null, "$tmp/kekse-niemand.txt")['status'] === 303, 'Team-Liste nur mit Login');
    $r = http_anfrage($basis, 'GET', '/', $h);
    // 240 Plätze: vier Beispiel-Anmeldungen (Lea, Paula, Tom, Adas untergeschobene) und Lena
    pruefe(preg_match('~Freie Plätze</dt><dd>235 / 240~u', $r['text']) === 1, 'Startseite: freie Plätze X / Y (E-40)');
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $csrf, 'aktion' => 'storno'], $k1);
    pruefe($r['status'] === 303 && anm_laden($aId) === null && db_wert('SELECT COUNT(*) FROM platz WHERE anmeldung_id = ?', [$aId]) == 0 && $anzahl() === $vorher, 'Storno löscht Anmeldung und Plätze');
    pruefe(http_mail_an($tmp, 'neu@example.org', 20, 'ist storniert') !== '', 'Storno-Mail');
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k1);
    pruefe(str_contains($r['text'], 'Wie willst du rein?'), 'danach keine Sitzung mehr');
}

/** Konten für Teilnehmende (AP-4b): registrieren, bestätigen, Passwort, Login (Lea), Häkchen beim Anmelden, Löschen. */
function http_bereich_konto(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp] = $u;
    $f = http_frisch($u);
    $b = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT)['keks'];

    abschnitt('HTTP: Konten für Teilnehmende (AP-4b)');
    $k = "$tmp/kekse-konto.txt";
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $k);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/deine-anmeldung', 'Mein Konto nur eingeloggt');
    $r = http_anfrage($basis, 'GET', '/konto/neu', $h, [], null, $k);
    preg_match('/name="pow_aufgabe" value="([0-9a-f]{64})"/', $r['text'], $m);
    $r = http_anfrage($basis, 'POST', '/konto/neu', $h, [], ['csrf' => http_csrf($r['text']), 'vorname' => 'Karla', 'nachname' => 'Konto', 'email' => 'karla@example.org', 'einwilligung' => '1',
        'zeit' => spam_stempel(time() - 10), 'pow_aufgabe' => $m[1] ?? '', 'pow_loesung' => http_pow($m[1] ?? ''), 'website' => ''], $k);
    $karla = konto_per_email('karla@example.org');
    pruefe(str_contains($r['text'], 'Schau in dein Postfach') && $karla !== null && $karla['email_bestaetigt_am'] === null && $karla['rolle'] === 'teilnehmend', 'Konto ohne Anmeldung angelegt, unbestätigt');
    preg_match('~(/konto/link\?t=[A-Za-z0-9_-]{40,60})~', http_mail_an($tmp, 'karla@example.org', 20, 'Bestätige deine Adresse'), $ml);
    $r = http_anfrage($basis, 'GET', $ml[1] ?? '/konto/link', $h, [], null, $k);
    pruefe(str_contains($r['text'], 'Adresse bestätigen') && konto_per_email('karla@example.org')['email_bestaetigt_am'] === null, 'Link-Seite: erst Klick bestätigt');
    $r = http_anfrage($basis, 'POST', '/konto/link', $h, [], ['csrf' => http_csrf($r['text']), 't' => substr($ml[1] ?? '', 14)], $k);
    pruefe($r['status'] === 303 && konto_per_email('karla@example.org')['email_bestaetigt_am'] !== null, 'Adresse bestätigt');
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $k);
    pruefe(str_contains($r['text'], 'Passwort festlegen') && !str_contains($r['text'], 'Dein Profil'), 'danach: Passwort festlegen');
    $c = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/konto', $h, [], ['csrf' => $c, 'aktion' => 'passwort_setzen', 'passwort' => '123456789', 'passwort2' => '123456789'], $k);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'mindestens 10 Zeichen'), 'Passwort unter 10 Zeichen abgelehnt');
    http_anfrage($basis, 'POST', '/konto', $h, [], ['csrf' => $c, 'aktion' => 'passwort_setzen', 'passwort' => 'karlas-passwort', 'passwort2' => 'karlas-passwort'], $k);
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $k);
    pruefe(str_contains($r['text'], 'id="h-profil">Profil<') && str_contains($r['text'], 'Einloggen und Sicherheit') && str_contains($r['text'], 'href="/konto" aria-current') && password_verify('karlas-passwort', (string)konto_per_email('karla@example.org')['passwort_hash']), 'Passwort gespeichert, Profil, „Mein Konto“ in der Navigation');
    $c = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/konto', $h, [], ['csrf' => $c, 'aktion' => 'profil', 'vorname' => 'Karla', 'nachname' => 'Konto', 'berufsgruppe' => 'ÄrztIn', 'institution' => 'ZNA', 'efn' => '802760012345673'], $k);
    pruefe($r['status'] === 303 && konto_per_email('karla@example.org')['efn'] === '802760012345673', 'Profil ohne Anmeldung speichern (EFN)');
    http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $c, 'aktion' => 'ausloggen'], $k);
    pruefe(http_anfrage($basis, 'GET', '/konto', $h, [], null, $k)['status'] === 303, 'Ausloggen');
    // Login über /einloggen (gemeinsamer Login, E-48): ohne Anmeldung zu „Mein Konto“, Lea (mit Anmeldung) zu ihrer Anmeldung
    $k2 = "$tmp/kekse-konto2.txt";
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k2);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'karla@example.org', 'passwort' => 'karlas-passwort'], $k2);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/konto' && str_contains(http_anfrage($basis, 'GET', '/konto', $h, [], null, $k2)['text'], 'Hallo Karla.'), 'Login unter /einloggen führt Teilnehmende ohne Anmeldung zu „Mein Konto“');
    $k2 = "$tmp/kekse-konto2b.txt";
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k2);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'lea@example.org', 'passwort' => BEISPIEL_PASSWORT], $k2);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/deine-anmeldung' && str_contains(http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k2)['text'], 'Hallo Lea.'), 'Login unter /einloggen führt Teilnehmende mit Anmeldung zu ihrer Anmeldung');
    // Login im Kasten auf „Deine Anmeldung“
    $k3 = "$tmp/kekse-konto3.txt";
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k3);
    pruefe(str_contains($r['text'], 'Mit Konto') && str_contains($r['text'], 'Passwort vergessen?'), 'Einstieg mit drittem Kasten „Einloggen“');
    $c3 = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $c3, 'aktion' => 'einloggen', 'email' => 'lea@example.org', 'passwort' => 'falsch-falsch'], $k3);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'E-Mail-Adresse oder Passwort stimmt nicht'), 'falsches Passwort: Meldung');
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $c3, 'aktion' => 'einloggen', 'email' => 'lea@example.org', 'passwort' => BEISPIEL_PASSWORT], $k3);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/deine-anmeldung' && str_contains(http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k3)['text'], 'Hallo Lea.'), 'Login im Kasten, danach die eigene Anmeldung');
    $k4 = "$tmp/kekse-konto4.txt";
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k4);
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'einloggen', 'email' => 'anna@example.org', 'passwort' => TEST_PASSWORT], $k4);
    $r2 = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k4);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/einloggen' && str_contains($r2['text'], 'Authenticator-App'), 'Team im Kasten: weiter zum zweiten Faktor');
    // Passwort vergessen
    $r = http_anfrage($basis, 'GET', '/konto/passwort-vergessen', $h, [], null, $k4);
    $r = http_anfrage($basis, 'POST', '/konto/passwort-vergessen', $h, [], ['csrf' => http_csrf($r['text']), 'email' => 'lea@example.org', 'website' => ''], $k4);
    pruefe($r['status'] === 303 && http_mail_an($tmp, 'lea@example.org', 20, 'neues Passwort fest') !== '', 'Passwort vergessen: Link per Mail');
    // Häkchen „Konto anlegen“ beim Anmelden
    $k5 = "$tmp/kekse-konto5.txt";
    http_anmelden($basis, $h, $k5, ['vorname' => 'Hanna', 'nachname' => 'Haken', 'email' => 'hanna@example.org', 'berufsgruppe' => 'StudentIn', 'institution' => 'Uni', 'essen' => 'egal', 'konto' => '1']);
    preg_match('~(/link\?t=[A-Za-z0-9_-]{43})~', http_mail_an($tmp, 'hanna@example.org', 20, 'Sie gilt erst'), $mh);
    pruefe(konto_per_email('hanna@example.org') === null, 'Häkchen: vor dem Bestätigen noch kein Konto');
    $r = http_anfrage($basis, 'GET', $mh[1] ?? '/link', $h, [], null, $k5);
    $r = http_anfrage($basis, 'POST', '/link', $h, [], ['csrf' => http_csrf($r['text']), 't' => substr($mh[1] ?? '', 8)], $k5);
    $hk = konto_per_email('hanna@example.org');
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/konto' && $hk !== null && db_wert('SELECT konto_id FROM anmeldung WHERE email = ?', ['hanna@example.org']) == $hk['id'], 'Häkchen beim Anmelden: nach dem Link Konto angelegt und verknüpft');
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $k5);
    pruefe(str_contains($r['text'], 'Passwort festlegen'), 'und gleich Passwort festlegen');
    $c5 = http_csrf($r['text']);
    http_anfrage($basis, 'POST', '/konto', $h, [], ['csrf' => $c5, 'aktion' => 'passwort_setzen', 'passwort' => 'hannas-passwort', 'passwort2' => 'hannas-passwort'], $k5);
    $r = http_anfrage($basis, 'GET', '/team/personen?f=konto', $h, [], null, $b);
    pruefe(preg_match('~Hanna Haken.*?data-label="Konto">Konto<~s', $r['text']) === 1 && str_contains($r['text'], 'Lea Beispiel') && !str_contains($r['text'], 'Paula Probe'), 'Personen: Filter „Mit Konto“, Spalte Konto');
    $r = http_anfrage($basis, 'POST', '/konto', $h, [], ['csrf' => $c5, 'aktion' => 'loeschen', 'passwort' => 'falsch', 'anmeldung' => 'behalten'], $k5);
    pruefe(str_contains($r['text'], 'Das Passwort stimmt nicht') && konto_per_email('hanna@example.org') !== null, 'Löschen nur mit Passwort');
    $r = http_anfrage($basis, 'POST', '/konto', $h, [], ['csrf' => $c5, 'aktion' => 'loeschen', 'passwort' => 'hannas-passwort', 'anmeldung' => 'behalten'], $k5);
    pruefe($r['status'] === 303 && konto_per_email('hanna@example.org') === null && db_wert('SELECT COUNT(*) FROM anmeldung WHERE email = ?', ['hanna@example.org']) == 1, 'Konto gelöscht, Anmeldung bleibt (Wahl)');
}

/** Daten: Sicherung, CME-Nachweise, Testmail (E-47, AP-9); Testdaten laden und zurück auf leerer Installation. */
function http_bereich_daten(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp] = $u;
    http_frisch($u);
    beispieldaten_cme_nachweis();
    $ein = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT);
    [$b, $codes] = [$ein['keks'], $ein['codes']];

    abschnitt('HTTP: Daten – Sicherung und Testdaten (E-47)');
    $r = http_anfrage($basis, 'GET', '/team/daten', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Sicherung anlegen') && str_contains($r['text'], 'Testdaten laden') && str_contains($r['text'], 'href="/team/daten" aria-current'), 'Seite „Daten“ für Betreiber, Testdaten außerhalb der Produktion');
    pruefe(http_anfrage($basis, 'GET', '/team/daten', $h, [], null, "$tmp/kekse-niemand.txt")['status'] === 303, 'ohne Login kein Zugriff');
    $csrf = http_csrf($r['text']);
    pruefe(str_contains($r['text'], 'Mailversand') && str_contains($r['text'], 'Testmail an mich'), 'Kasten Mailversand mit Testmail');
    pruefe(str_contains($r['text'], 'id="cme-nachweise"') && str_contains($r['text'], 'alle 3 Stunden') && str_contains($r['text'], '1 Nachweis'), 'Kasten CME-Nachweise (ein Nachweis) und Sicherungsplan (AP-9)');
    $x = http_anfrage($basis, 'GET', '/team/export?art=cme_nachweise', $h, [], null, $b);
    pruefe($x['status'] === 200 && str_contains($x['text'], '802760098765437') && str_contains($x['text'], 'Probe'), 'Betreiber: CME-Nachweise exportierbar');
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $csrf, 'aktion' => 'testmail'], $b);
    $f = http_anfrage($basis, 'GET', '/team/daten', $h, [], null, $b);
    pruefe($r['status'] === 303 && str_contains($f['text'], 'beim Mailserver angenommen') && http_mail_an($tmp, TEST_BETREIBER, 20, 'Diese Mail prüft den Versand') !== '', 'Testmail sofort versendet, Ergebnis angezeigt');
    pruefe(str_contains($f['text'], 'Version ' . app_version() . ' · Entwicklung'), 'Version in der Fußzeile (außerhalb der Produktion)');
    $alt = glob("$tmp/var/sicherungen/sicherung-*.jsonl.gz") ?: [];
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $csrf, 'aktion' => 'anlegen'], $b);
    $dateien = array_values(array_diff(glob("$tmp/var/sicherungen/sicherung-*.jsonl.gz") ?: [], $alt));
    pruefe($r['status'] === 303 && count($dateien) === 1, 'Sicherung angelegt (gzip im Laufzeitordner)');
    $name = basename($dateien[0] ?? 'x');
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $csrf, 'aktion' => 'herunterladen', 'name' => $name, 'totp' => '000000'], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Der Code stimmt nicht oder ist abgelaufen') && ($r['kopf']['content-type'] ?? '') !== 'application/gzip', 'Herunterladen ohne gültigen Code abgelehnt');
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $csrf, 'aktion' => 'herunterladen', 'name' => $name, 'totp' => strtolower($codes[0])], $b);
    $roh = (string)@gzdecode($r['text']);
    pruefe($r['status'] === 200 && ($r['kopf']['content-type'] ?? '') === 'application/gzip' && str_contains((string)strtok($roh, "\n"), '"art":"kopf"') && str_contains($roh, 'paula@example.org'), 'Herunterladen mit Code: gzip mit Kopfzeile und den Daten');
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $csrf, 'aktion' => 'herunterladen', 'name' => '../config.php', 'totp' => strtolower($codes[1])], $b);
    pruefe($r['status'] === 200 && ($r['kopf']['content-type'] ?? '') !== 'application/gzip' && !str_contains($r['text'], 'installations_schluessel'), 'nur gültige Sicherungsnamen (auch mit gültigem Code)');

    abschnitt('HTTP: Testdaten laden und wiederherstellen (E-47, leere Installation)');
    // Gegenstand ist das Laden in eine leere Installation: ohne Beispieldaten beginnen
    http_frisch($u, ['beispiel' => false]);
    $ein = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT);
    [$b, $codes] = [$ein['keks'], $ein['codes']];
    $csrf = http_csrf(http_anfrage($basis, 'GET', '/team/daten', $h, [], null, $b)['text']);
    $sich = count(sicherung_liste());
    $konten = (int)db_wert("SELECT COUNT(*) FROM konto WHERE rolle <> 'teilnehmend'");
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $csrf, 'aktion' => 'testdaten'], $b);
    pruefe($r['status'] === 303 && db_wert('SELECT COUNT(*) FROM programmpunkt WHERE veranstaltung_id = 1') == 9 && db_wert('SELECT COUNT(*) FROM anmeldung WHERE veranstaltung_id = 1') == 4 /* 3 + Adas untergeschobene (AP-16) */ && db_wert("SELECT COUNT(*) FROM veranstaltung WHERE status = 'abgeschlossen'") == 2, 'Testdaten geladen: Programm, Anmeldungen, zwei frühere Veranstaltungen');
    pruefe((int)db_wert("SELECT COUNT(*) FROM konto WHERE rolle <> 'teilnehmend'") === $konten + 1 && db_wert("SELECT COUNT(*) FROM konto WHERE email = 'ada.abend@example.org' AND rolle = 'admin'") == 1 && konto_per_email(TEST_BETREIBER) !== null, 'Team-Konten bleiben, Ada (Admin) dazu');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], 'Atemweg. Plan B.'), 'Startseite zeigt das Beispielprogramm');
    $vorTest = '';
    foreach (sicherung_liste() as $s) {
        if (($s['kopf']['anlass'] ?? '') === 'vor dem Laden der Testdaten') {
            $vorTest = $s['name'];
        }
    }
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $csrf, 'aktion' => 'wiederherstellen', 'name' => $vorTest, 'totp' => strtolower($codes[0])], $b);
    pruefe($r['status'] === 303 && db_wert('SELECT COUNT(*) FROM programmpunkt') == 0 && db_wert('SELECT COUNT(*) FROM anmeldung') == 0 && !str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], 'Atemweg. Plan B.'), 'Wiederherstellen: Stand vor den Testdaten zurück');
    pruefe(count(sicherung_liste()) === $sich + 2 && http_anfrage($basis, 'GET', '/team/daten', $h, [], null, $b)['status'] === 200, 'davor automatisch gesichert, Team bleibt eingeloggt');
}

/** Betrieb mit den Beispieldaten: Status-Seite, Komponentenblatt und alle Seiten aus ROUTEN (V-52). */
function http_bereich_betrieb(array $u): void
{
    http_frisch($u);
    beispieldaten_sicherung(); // „Daten“ mit einer Sicherung in der Tabelle
    $b = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT)['keks'];
    status_http_tests($u['basis'], $u['h'], $b);
    komponenten_http_tests($u['basis'], $u['h'], $b);
    seiten_http_tests($u['basis'], $u['h'], $b);
}


/** Jede HTML-Seite aus der Seitenliste (tools/tests/seiten.php, V-52) antwortet: Team-Seiten der BetreiberIn, öffentliche allen. */
function seiten_http_tests(string $basis, string $h, string $b): void
{
    abschnitt('HTTP: alle Seiten aus ROUTEN (AP-17 Unterpunkt 11)');
    $s = test_seiten();
    $team = [];
    foreach (array_merge($s['team'], $s['betreiber']) as $pfad) {
        $team[$pfad] = http_anfrage($basis, 'GET', $pfad, $h, [], null, $b)['status'];
    }
    pruefe(count($team) >= 15 && array_unique(array_values($team)) === [200], 'BetreiberIn: alle ' . count($team) . ' Team-Seiten 200' . (array_unique(array_values($team)) === [200] ? '' : ': ' . json_encode($team)));
    $offen = [];
    foreach ($s['oeffentlich'] as $pfad) {
        $offen[$pfad] = http_anfrage($basis, 'GET', $pfad, $h)['status'];
    }
    $schlecht = array_filter($offen, fn($st) => $st >= 400);
    pruefe(count($offen) >= 8 && $schlecht === [], 'Gäste: alle ' . count($offen) . ' öffentlichen Seiten ohne Fehler (/konto leitet zum Einloggen)' . ($schlecht === [] ? '' : ': ' . json_encode($schlecht)));
}

/** Betrieb → Komponenten (AP-17 Unterpunkt 6, E-64 (1)): alle Abschnitte, Beispiele ändern nichts. */
function komponenten_http_tests(string $basis, string $h, string $b): void
{
    abschnitt('HTTP: Komponentenblatt (AP-17 Unterpunkt 6)');
    $r = http_anfrage($basis, 'GET', '/team/komponenten', $h, [], null, $b);
    $abschnitte = ['typografie', 'knoepfe', 'felder', 'kaesten', 'chips', 'meldungen', 'kacheln', 'filter', 'tabellen', 'dialoge', 'menues'];
    $da = array_filter($abschnitte, fn($a) => str_contains($r['text'], 'id="' . $a . '"'));
    pruefe($r['status'] === 200 && count($da) === count($abschnitte), 'Betreiber: Komponentenblatt mit allen ' . count($abschnitte) . ' Abschnitten');
    pruefe(substr_count($r['text'], 'class="kb-code"') >= count($abschnitte) && str_contains($r['text'], 'class="bestaetigen"') && str_contains($r['text'], 'class="kachel"'), 'je Abschnitt der Aufruf als Text, Helfer erzeugen das Markup');
    pruefe(str_contains($r['text'], 'href="/team/komponenten" aria-current="page"'), 'Unterseite „Komponenten“ unter Betrieb aktiv');
    $a0 = (int)db_wert('SELECT COUNT(*) FROM audit');
    $r = http_anfrage($basis, 'POST', '/team/komponenten', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'beispiel'], $b);
    pruefe($r['status'] === 303 && (int)db_wert('SELECT COUNT(*) FROM audit') === $a0, 'Beispiel-Aktion ändert nichts (nur Hinweis)');
}

/** Betrieb → Status (AP-15, E-65): Seite, Cron-Anleitung, Token nach Klick, Fehlerprotokoll. */
function status_http_tests(string $basis, string $h, string $b): void
{
    abschnitt('HTTP: Status-Seite (AP-15, T-283 bis T-285)');
    $token = (string)konfig('jobs_token');
    $r = http_anfrage($basis, 'GET', '/team/status', $h, [], null, $b);
    $zeilen = preg_match_all('/<div id="zeile-[a-z]+"><dt>/', $r['text']);
    pruefe($r['status'] === 200 && $zeilen === 7 && str_contains($r['text'], 'href="/team/status" aria-current') && str_contains($r['text'], 'Jetzt prüfen'), 'Betreiber: Status mit allen sieben Zeilen, Reiter aktiv');
    $unter = substr($r['text'], (int)strpos($r['text'], 'aria-label="Unterseiten von Betrieb"'));
    pruefe(str_contains($r['text'], 'href="/team/status" class="an"') && strpos($unter, 'href="/team/status"') < strpos($unter, 'href="/team/veranstaltungen"'), 'Status ist die erste Seite unter Betrieb');
    pruefe(str_contains($r['text'], '/cli/jobs.php') && str_contains($r['text'], 'token=&lt;jobs_token&gt;') && !str_contains($r['text'], $token) && str_contains($r['text'], 'data-kopieren="cron-kommando"') && str_contains($r['text'], '/assets/status.js'), 'GET: Befehl mit Kopieren, URL mit Platzhalter, kein Token im HTML');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/team/daten', $h, [], null, $b)['text'], 'href="/team/status#zeile-mail"'), 'Betrieb verweist für Mail-Zahlen auf Status');
    $csrf = http_csrf($r['text']);
    $a0 = (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'betrieb' AND text = 'Cron-URL mit Token angezeigt'");
    $r = http_anfrage($basis, 'POST', '/team/status', $h, [], ['csrf' => $csrf, 'aktion' => 'token_zeigen'], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'token=' . $token) && (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'betrieb' AND text = 'Cron-URL mit Token angezeigt'") === $a0 + 1, 'POST anzeigen: Token im HTML, Protokolleintrag');
    file_put_contents(betrieb_fehlerlog_datei(), "[test] erste Zeile <b>\n[test] zweite Zeile\n", FILE_APPEND);
    $r = http_anfrage($basis, 'POST', '/team/status', $h, [], ['csrf' => $csrf, 'aktion' => 'log_zeigen'], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], '<pre class="status-log">') && str_contains($r['text'], '[test] erste Zeile &lt;b&gt;'), 'Letzte Zeilen auf Abruf, maskiert');
    $r = http_anfrage($basis, 'POST', '/team/status', $h, [], ['csrf' => $csrf, 'aktion' => 'log_leeren'], $b);
    clearstatcache();
    pruefe($r['status'] === 303 && filesize(betrieb_fehlerlog_datei()) === 0 && str_contains(http_anfrage($basis, 'GET', '/team/status', $h, [], null, $b)['text'], 'Fehlerprotokoll geleert'), 'Leeren: Datei leer, Meldung');
}

/** Team-Bereich (AP-5): Seiten, Person anlegen, Paula bearbeiten, Export, Teilnahme und Zertifikat, Texte, Protokoll. */
function http_bereich_team(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp] = $u;
    $f = http_frisch($u);
    $paula = $f['bd']['anmeldungen']['paula'];
    $b = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT)['keks'];

    abschnitt('HTTP: Team-Bereich (AP-5)');
    // Rückmeldung 2026-10-31: eingeloggt zeigt auch die Startseite das Personen-Menü (nicht „Einloggen“)
    $r = http_anfrage($basis, 'GET', '/', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'class="person-menue"') && !str_contains($r['text'], '>Einloggen</a>') && str_contains($r['text'], 'href="/team"'), 'Startseite erkennt das eingeloggte Team-Konto');
    pruefe(str_contains($r['text'], '<a class="person-punkt team" href="/team"'), 'Personen-Menü: Team-Bereich für Team-Konten (Rückmeldung 2026-10-31)');
    foreach (['/team' => 'Zu erledigen', '/team/personen' => '+ Person anlegen', '/team/workshops' => 'Termin anklicken', '/team/statistik' => 'Anmeldungen seit Start', '/team/texte' => 'Datenschutzerklärung', '/team/protokoll' => 'Einträge', '/team/daten' => 'Hostnamen'] as $pfad => $text) {
        $r = http_anfrage($basis, 'GET', $pfad, $h, [], null, $b);
        pruefe($r['status'] === 200 && str_contains($r['text'], $text), "Seite $pfad");
    }
    pruefe(http_anfrage($basis, 'GET', '/team/personen', $h, [], null, "$tmp/kekse-niemand.txt")['status'] === 303, 'Personen nur mit Login');
    $r = http_anfrage($basis, 'GET', '/team/personen?neu=1', $h, [], null, $b);
    $c = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/team/personen', $h, [], ['csrf' => $c, 'aktion' => 'anlegen', 'vorname' => 'Petra', 'nachname' => 'Person', 'email' => 'petra@example.org', 'berufsgruppe' => 'Pflege/MFA', 'institution' => 'Station 3', 'essen' => 'vegetarisch'], $b);
    $pid = (int)db_wert("SELECT id FROM anmeldung WHERE email = 'petra@example.org'");
    pruefe($r['status'] === 303 && str_contains($r['kopf']['location'] ?? '', '?a=' . $pid) && anm_laden($pid)['status'] === 'bestaetigt', 'Person anlegen: bestätigt, weiter zur Person');
    pruefe(http_mail_an($tmp, 'petra@example.org', 20, 'ist bestätigt') !== '', 'Mail an die angelegte Person');
    $r = http_anfrage($basis, 'GET', '/team/personen?a=' . $paula, $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Anmeldung bearbeiten – auch nach den Stichtagen') && str_contains($r['text'], 'Gefahrenzone') && str_contains($r['text'], 'class="gewaehlt"') && str_contains($r['text'], 'value="Klinik am Probeberg"'), 'Person ausgewählt (Paula): Bearbeiten, Gefahrenzone');
    $form = ['csrf' => $c, 'aktion' => 'speichern', 'a' => $paula, 'vorname' => 'Paula', 'nachname' => 'Probe', 'email' => 'paula@example.org', 'berufsgruppe' => 'Pflege/MFA'];
    $vorher = http_mail_anzahl($tmp, 'paula@example.org');
    $r = http_anfrage($basis, 'POST', '/team/personen?a=' . $paula, $h, [], $form + ['institution' => 'Station 4', 'essen' => 'egal', 'ohne_mail' => '1'], $b);
    usleep(400_000);
    pruefe($r['status'] === 303 && anm_laden($paula)['essen'] === 'egal' && anm_laden($paula)['institution'] === 'Station 4' && http_mail_anzahl($tmp, 'paula@example.org') === $vorher, 'Ändern „ohne Mail“: gespeichert, keine Mail');
    einstellung_setzen('stichtag_essen', gmdate('Y-m-d', time() - 86400));
    http_anfrage($basis, 'POST', '/team/personen?a=' . $paula, $h, [], $form + ['institution' => 'Station 4', 'essen' => 'vegetarisch'], $b);
    pruefe(anm_laden($paula)['essen'] === 'vegetarisch' && http_mail_an($tmp, 'paula@example.org', 20, 'das Team hat deine Anmeldung aktualisiert') !== '', 'Team ändert nach dem Stichtag, Mail „vom Team“ (T-144)');
    db_ausfuehren("DELETE FROM va_einstellung WHERE schluessel = 'stichtag_essen'");
    http_anfrage($basis, 'POST', '/team/personen?a=' . $paula, $h, [], ['csrf' => $c, 'aktion' => 'link', 'a' => $paula], $b);
    pruefe(http_mail_an($tmp, 'paula@example.org', 20, 'siehst und änderst du deine Anmeldung') !== '', 'Einmal-Link senden');
    $r = http_anfrage($basis, 'GET', '/team/workshops?t=0', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Paula Probe') && str_contains($r['text'], 'Petra Person') && str_contains($r['text'], 'Warteliste in Reihenfolge'), 'Workshops: Symposium mit Teilnehmenden');
    $r = http_anfrage($basis, 'GET', '/team/export?art=teilnehmende', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_starts_with($r['kopf']['content-type'] ?? '', 'text/csv') && str_starts_with($r['text'], "\u{FEFF}") && str_contains($r['text'], 'Paula') && str_contains($r['text'], 'Lea') && str_contains($r['kopf']['content-disposition'] ?? '', '.csv'), 'Export als CSV (Excel)');
    pruefe(http_anfrage($basis, 'GET', '/team/export?art=gibtsnicht', $h, [], null, $b)['status'] === 404, 'unbekannter Export: 404');
    // xlsx und PDF (E-56)
    $r = http_anfrage($basis, 'GET', '/team/export?art=teilnehmende&format=xlsx', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['kopf']['content-type'] ?? '', 'spreadsheetml') && str_starts_with($r['text'], 'PK') && str_contains($r['kopf']['content-disposition'] ?? '', '.xlsx"'), 'Export als Excel (xlsx)');
    $r = http_anfrage($basis, 'GET', '/team/export?art=termine&format=pdf', $h, [], null, $b);
    pruefe($r['status'] === 200 && ($r['kopf']['content-type'] ?? '') === 'application/pdf' && str_starts_with($r['text'], '%PDF-') && str_contains($r['kopf']['content-disposition'] ?? '', '.pdf"'), 'Export Listen je Termin als PDF');
    pruefe(http_anfrage($basis, 'GET', '/team/export?art=newsletter&format=pdf', $h, [], null, $b)['status'] === 404 && http_anfrage($basis, 'GET', '/team/export?art=cme&format=exe', $h, [], null, $b)['status'] === 404, 'Newsletter ohne PDF, unbekanntes Format: 404');
    pruefe(db_wert("SELECT COUNT(*) FROM audit WHERE art = 'export' AND text LIKE '%als PDF%'") >= 1, 'Export mit Format im Protokoll');
    $r = http_anfrage($basis, 'GET', '/team/statistik', $h, [], null, $b);
    pruefe(substr_count($r['text'], 'format=xlsx') === 7 && substr_count($r['text'], 'format=pdf') === 6, 'Statistik: Knöpfe Excel und PDF');
    // Teilnahme und Zertifikate (AP-10, E-57)
    $r = http_anfrage($basis, 'GET', '/team/teilnahme', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Probe, Paula') && str_contains($r['text'], 'Unterschriftenliste') && str_contains($r['text'], 'aria-current="page">Teilnahme<'), 'Teilnahme: Seite mit Liste, Navigation unter Anmeldungen');
    $c = http_csrf($r['text']);
    $teilnahme = (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'teilnahme'");
    $pPlatz = (int)db_wert('SELECT id FROM platz WHERE anmeldung_id = ? AND termin_id = 0', [$paula]);
    $r = http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'erschienen', 'platz' => $pPlatz, 'wert' => '0'], $b);
    pruefe($r['status'] === 303 && str_ends_with($r['kopf']['location'] ?? '', '#a' . $paula) && (int)db_wert('SELECT nicht_erschienen FROM platz WHERE id = ?', [$pPlatz]) === 1, 'Nicht erschienen eintragen');
    $r = http_anfrage($basis, 'GET', '/team/teilnahme?f=fehlt', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Probe, Paula') && !str_contains($r['text'], 'Beispiel, Lea'), 'Filter „nicht erschienen“');
    http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'erschienen', 'platz' => $pPlatz, 'wert' => '1'], $b);
    pruefe((int)db_wert('SELECT nicht_erschienen FROM platz WHERE id = ?', [$pPlatz]) === 0, 'Doch da');
    pruefe(http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['aktion' => 'erschienen', 'platz' => $pPlatz, 'wert' => '0'], $b)['status'] === 403 && (int)db_wert('SELECT nicht_erschienen FROM platz WHERE id = ?', [$pPlatz]) === 0, 'Ohne CSRF abgelehnt');
    $r = http_anfrage($basis, 'GET', '/team/teilnahme/liste?liste=aerzte&sort=vorname', $h, [], null, $b);
    pruefe($r['status'] === 200 && ($r['kopf']['content-type'] ?? '') === 'application/pdf' && str_starts_with($r['text'], '%PDF-') && str_contains($r['kopf']['content-disposition'] ?? '', 'attachment'), 'Unterschriftenliste als PDF');
    $r = http_anfrage($basis, 'GET', '/team/teilnahme/zertifikat?beispiel=1', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_starts_with($r['text'], '%PDF-') && str_contains($r['kopf']['content-disposition'] ?? '', 'inline'), 'Vorschau mit Beispiel');
    pruefe(http_anfrage($basis, 'GET', '/team/teilnahme/zertifikat?a=' . $paula, $h, [], null, $b)['status'] === 200 && http_anfrage($basis, 'GET', '/team/teilnahme/zertifikat?a=999999', $h, [], null, $b)['status'] === 404, 'Vorschau je Person, unbekannt 404');
    pruefe(http_anfrage($basis, 'GET', '/team/teilnahme', $h)['status'] === 303 && http_anfrage($basis, 'GET', '/team/teilnahme/liste', $h)['status'] === 303, 'Teilnahme nur für das Team');
    // Unterschrift hochladen (die Beispiel-Unterschrift wird ersetzt)
    $bild = imagecreatetruecolor(200, 60);
    imagefill($bild, 0, 0, imagecolorallocate($bild, 255, 255, 255));
    imagepng($bild, "$tmp/unterschrift.png");
    $r = http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'unterschrift', 'bild' => new CURLFile("$tmp/unterschrift.png", 'image/png', 'u.png')], $b);
    $r2 = http_anfrage($basis, 'GET', '/team/teilnahme/unterschrift', $h, [], null, $b);
    $info = @getimagesizefromstring($r2['text']);
    pruefe($r['status'] === 303 && $r2['status'] === 200 && ($r2['kopf']['content-type'] ?? '') === 'image/png' && $info !== false && $info[0] === 200, 'Unterschrift hochladen und anzeigen');
    file_put_contents("$tmp/falsch.png", '<?php echo 1;');
    http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'unterschrift', 'bild' => new CURLFile("$tmp/falsch.png", 'image/png', 'f.png')], $b);
    pruefe(str_contains(http_anfrage($basis, 'GET', '/team/teilnahme', $h, [], null, $b)['text'], 'Bitte die Unterschrift als PNG'), 'Keine Bilddatei: abgelehnt');
    http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'unterschrift_weg'], $b);
    pruefe(http_anfrage($basis, 'GET', '/team/teilnahme/unterschrift', $h, [], null, $b)['status'] === 404, 'Unterschrift entfernen');
    // Freischalten: vor dem Tag gesperrt, danach Mail und Download für die Person (Beispiel-Veranstaltung liegt in 60 Tagen)
    http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'freischalten', 'a' => $paula], $b);
    pruefe(anm_laden($paula)['zertifikat_am'] === null, 'Vor dem Veranstaltungstag: nicht freigeschaltet');
    $kp = "$tmp/kekse-paula.txt";
    $lt = anm_link_erzeugen($paula, 'zugang', time() + 3600);
    $r = http_anfrage($basis, 'GET', '/link?t=' . $lt, $h, [], null, $kp);
    http_anfrage($basis, 'POST', '/link', $h, [], ['csrf' => http_csrf($r['text']), 't' => $lt], $kp);
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung/zertifikat', $h, [], null, $kp);
    $r2 = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $kp)['text'];
    pruefe($r['status'] === 303 && str_contains($r2, 'Hallo Paula.') && str_contains($r2, 'Teilnahmebescheinigung') && !str_contains($r2, 'Zertifikat herunterladen'), 'Ohne Freischaltung kein Download');
    einstellung_setzen('veranstaltung_datum', gmdate('Y-m-d', time() - 86400));
    $vorher = http_mail_anzahl($tmp, 'paula@example.org');
    http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'freischalten', 'a' => $paula], $b);
    pruefe(anm_laden($paula)['zertifikat_am'] !== null && http_mail_an($tmp, 'paula@example.org', 20, 'Teilnahmebescheinigung') !== '' && http_mail_anzahl($tmp, 'paula@example.org') === $vorher + 1, 'Freischalten mit Mail');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $kp)['text'], 'Zertifikat herunterladen'), 'Meine Anmeldung: Knopf zum Herunterladen');
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung/zertifikat', $h, [], null, $kp);
    pruefe($r['status'] === 200 && ($r['kopf']['content-type'] ?? '') === 'application/pdf' && str_starts_with($r['text'], '%PDF-') && str_contains($r['kopf']['content-disposition'] ?? '', 'Teilnahmebescheinigung-Probe-'), 'Zertifikat herunterladen');
    pruefe(http_anfrage($basis, 'GET', '/deine-anmeldung/zertifikat', $h)['status'] === 303, 'Ohne Sitzung kein Download');
    http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'erschienen', 'platz' => $pPlatz, 'wert' => '0'], $b);
    pruefe(http_anfrage($basis, 'GET', '/deine-anmeldung/zertifikat', $h, [], null, $kp)['status'] === 303, 'Nachträglich „nicht erschienen“: kein Download');
    pruefe((int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'teilnahme'") >= $teilnahme + 4, 'Teilnahme im Protokoll');
    $r = http_anfrage($basis, 'POST', '/team/startseite', $h, [], ['csrf' => $c, 'start_sticker' => 'Gratis!', 'impressum' => 'nicht hier'], $b);
    http_anfrage($basis, 'POST', '/team/texte', $h, [], ['csrf' => $c, 'impressum' => "## Verantwortlich\nTest Impressum", 'start_sticker' => 'nicht hier'], $b);
    $start = http_anfrage($basis, 'GET', '/', $h)['text'];
    pruefe($r['status'] === 303 && str_contains($start, 'Gratis!') && !str_contains($start, 'nicht hier') && str_contains(http_anfrage($basis, 'GET', '/impressum', $h)['text'], 'Test Impressum'), 'Texte: Startseite und Impressum sofort geändert (jedes Feld nur auf seiner Seite)');
    // Drei Kacheln auf der Seite „Startseite“ (AP-18, E-70 (1)); das frühere Feld „Infokästen“ wird danach geleert
    $kach = ['start_kachel_1_titel' => 'Für alle', 'start_kachel_1_text' => '<p>Kachel eins</p>', 'start_kachel_2_titel' => 'Ablauf', 'start_kachel_2_text' => '<p>Kachel zwei</p>', 'start_kachel_3_titel' => 'Kosten', 'start_kachel_3_text' => '<p>Kachel drei</p>'];
    http_anfrage($basis, 'POST', '/team/startseite', $h, [], ['csrf' => $c] + $kach, $b);
    $start = http_anfrage($basis, 'GET', '/', $h)['text'];
    pruefe(str_contains($start, '<strong>Für alle</strong>') && str_contains($start, 'Kachel drei') && !str_contains($start, 'Für wen') && inhalt('start_infos') === '', 'Startseite: drei Kacheln von der Seite „Startseite“, alte Infokästen ersetzt');
    pruefe(http_anfrage($basis, 'GET', '/team/startseite', $h, [], null, $b)['status'] === 200, 'Seite „Startseite“ wieder eigene Seite (E-70 (1))');
    $r = http_anfrage($basis, 'GET', '/team/protokoll?art=texte', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'start_sticker'), 'Protokoll mit Filter');
    $r = http_anfrage($basis, 'POST', '/team/personen?a=' . $paula, $h, [], ['csrf' => $c, 'aktion' => 'storno', 'a' => $paula, 'ohne_mail' => '1'], $b);
    pruefe($r['status'] === 303 && anm_laden($paula) === null, 'Storno durch das Team');
}

/** Mailings und Team-Mails (AP-6): Entwurf, Bild, Vorschau, Empfänger (Lea und Paula), Test, Senden, Archiv; Passwort vergessen fürs Team. */
function http_bereich_mailings(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp] = $u;
    http_frisch($u);
    $b = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT)['keks'];

    abschnitt('HTTP: Mailings und Team-Mails (AP-6)');
    $r = http_anfrage($basis, 'GET', '/team/mailings', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'href="/team/mailings" class="an" aria-current="page"') && str_contains($r['text'], '+ Neues Mailing') && str_contains($r['text'], 'Anfahrt und Parken'), 'Reiter Mailings mit Übersicht (Beispiel-Mailings)');
    pruefe(str_contains($r['kopf']['content-security-policy'] ?? '', "frame-src 'self'"), 'Seite erlaubt die Vorschau im eigenen iframe');
    $r = http_anfrage($basis, 'GET', '/team/mailings?neu=1', $h, [], null, $b);
    $c = http_csrf($r['text']);
    pruefe(str_contains($r['text'], 'data-editor="block"') && str_contains($r['text'], '/assets/editor.js') && str_contains($r['text'], 'name="html"') && str_contains($r['text'], '/assets/mailings.js') && str_contains($r['text'], 'Die Vorschau erscheint nach dem ersten Speichern'), 'Editor mit Schreibfeld und Textfeld ohne Skript');
    $r = http_anfrage($basis, 'POST', '/team/mailings', $h, [], ['csrf' => $c, 'aktion' => 'speichern', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system', 'betreff' => 'Hallo {vorname}', 'html' => '<p>Hallo {vorname},</p><script>x</script><p><a class="knopf" href="{link}">Ansehen</a></p>'], $b);
    $id = (int)db_wert('SELECT MAX(id) FROM rundmail');
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/team/mailings?id=' . $id && db_wert('SELECT betreff FROM rundmail WHERE id = ?', [$id]) === 'Hallo {vorname}' && !str_contains((string)db_wert('SELECT html FROM rundmail WHERE id = ?', [$id]), 'script'), 'Entwurf gespeichert und bereinigt');
    $png = "$tmp/plan.png";
    $g = imagecreatetruecolor(300, 150);
    imagepng($g, $png);
    imagedestroy($g);
    $up = http_bild_hochladen($basis, $h, $b, $c, $png);
    pruefe($up['status'] === 200 && ($up['json']['breite'] ?? 0) === 300 && str_starts_with($up['json']['url'] ?? '', '/team/mailings/bild?id='), 'Bild hochladen (Editor)');
    pruefe(http_bild_hochladen($basis, $h, $b, 'falsch', $png)['status'] === 403, 'Hochladen ohne gültiges CSRF abgelehnt');
    file_put_contents("$tmp/kein-bild.png", '<?php echo 1;');
    pruefe(http_bild_hochladen($basis, $h, $b, $c, "$tmp/kein-bild.png")['status'] === 400, 'nur echte Bilder');
    $r = http_anfrage($basis, 'GET', $up['json']['url'] ?? '/', $h, [], null, $b);
    pruefe($r['status'] === 200 && ($r['kopf']['content-type'] ?? '') === 'image/png', 'Bild für das Team abrufbar');
    pruefe(http_anfrage($basis, 'GET', $up['json']['url'] ?? '/', $h, [], null, "$tmp/kekse-niemand2.txt")['status'] === 303, 'Bild nicht öffentlich');
    $html = '<p>Hallo {vorname},</p><p>{workshops}</p><p><img src="' . ($up['json']['url'] ?? '') . '" alt="Plan" data-breite="50"></p><p><a class="knopf" href="{link}">Ansehen</a></p>';
    $form = ['csrf' => $c, 'id' => $id, 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system', 'betreff' => 'Hallo {vorname}', 'html' => $html];
    http_anfrage($basis, 'POST', '/team/mailings?id=' . $id, $h, [], ['aktion' => 'speichern'] + $form, $b);
    $r = http_anfrage($basis, 'GET', '/team/mailings?id=' . $id, $h, [], null, $b);
    pruefe(str_contains($r['text'], '/team/mailings/vorschau?id=' . $id) && str_contains($r['text'], 'sandbox="allow-same-origin'), 'Vorschau im iframe (ohne Skripte)');
    $r = http_anfrage($basis, 'GET', '/team/mailings/vorschau?id=' . $id, $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['kopf']['content-security-policy'] ?? '', "style-src 'unsafe-inline'") && !str_contains($r['kopf']['content-security-policy'] ?? '', 'script-src') && ($r['kopf']['x-frame-options'] ?? '') === 'SAMEORIGIN', 'Vorschau: eigene Richtlinie ohne Skripte, nur im eigenen Rahmen');
    pruefe(preg_match('/Hallo (Lea|Paula),/', $r['text']) === 1 && !str_contains($r['text'], '{vorname}') && str_contains($r['text'], 'width="272"') && str_contains($r['text'], '/impressum'), 'Vorschau mit Daten der ersten Person, Bild halbe Breite, Impressum');
    $r = http_anfrage($basis, 'GET', '/team/mailings/empfaenger?auswahl=angemeldet&bg=', $h, [], null, $b);
    // angemeldet = bestätigt: Lea und Paula (Tom und Adas untergeschobene sind unbestätigt)
    pruefe($r['status'] === 200 && (json_decode($r['text'], true)['zahl'] ?? -1) === 2, 'Empfängerzahl für den Editor (nur Bestätigte)');
    pruefe(http_anfrage($basis, 'GET', '/team/mailings/empfaenger?auswahl=quatsch', $h, [], null, $b)['status'] === 400, 'unbekannte Auswahl: 400');
    $r = http_anfrage($basis, 'POST', '/team/mailings?id=' . $id, $h, [], ['aktion' => 'test'] + $form, $b);
    $tm = http_mail_an($tmp, TEST_BETREIBER, 20, '[Test] Hallo Bea');
    pruefe($r['status'] === 303 && str_contains($tm, 'multipart/related') && str_contains($tm, 'Content-ID: <bild') && str_contains($tm, '/deine-anmeldung'), 'Testmail an mich: HTML mit Bild, {link} allgemein');
    $r = http_anfrage($basis, 'POST', '/team/mailings?id=' . $id, $h, [], ['aktion' => 'planen', 'geplant' => '2020-01-01T10:00'] + $form, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Zeitpunkt in der Zukunft') && db_wert('SELECT status FROM rundmail WHERE id = ?', [$id]) === 'entwurf', 'Planen in der Vergangenheit abgelehnt');
    $r = http_anfrage($basis, 'POST', '/team/mailings?id=' . $id, $h, [], ['aktion' => 'senden'] + $form, $b);
    pruefe($r['status'] === 303 && db_wert('SELECT status FROM rundmail WHERE id = ?', [$id]) === 'versand' && (int)db_wert('SELECT empfaenger_zahl FROM rundmail WHERE id = ?', [$id]) === 2, 'Senden: Mailing in der Warteschlange');
    $lea = http_mail_an($tmp, 'lea@example.org', 30, 'Ansehen');
    pruefe(preg_match('#/link\?t=[A-Za-z0-9_-]{43}#', $lea) === 1 && str_contains($lea, 'Hallo Lea'), 'Mailing kommt an, mit persönlichem Einmal-Link');
    $r = http_anfrage($basis, 'GET', '/team/mailings?id=' . $id, $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Empfängerliste (2)') && str_contains($r['text'], 'Als neue Mail kopieren') && !str_contains($r['text'], 'data-editor='), 'Gesendetes Mailing: Archiv mit Empfängerliste, nicht mehr änderbar');
    http_anfrage($basis, 'POST', '/team/mailings?id=' . $id, $h, [], ['csrf' => $c, 'id' => $id, 'aktion' => 'speichern', 'betreff' => 'Geändert', 'html' => '<p>x</p>', 'auswahl' => 'angemeldet', 'postfach' => 'system'], $b);
    pruefe(db_wert('SELECT betreff FROM rundmail WHERE id = ?', [$id]) === 'Hallo {vorname}', 'Gesendetes lässt sich nicht ändern');
    $r = http_anfrage($basis, 'GET', '/team/mailings', $h, [], null, $b);
    pruefe(preg_match('#Hallo \{vorname\}.{0,600}?(Wird gesendet|Gesendet)#s', $r['text']) === 1, 'Übersicht zeigt das Mailing mit Status');
    pruefe(str_contains((string)db_wert("SELECT text FROM audit WHERE art = 'mailing' ORDER BY id DESC LIMIT 1"), 'Mailing ' . $id), 'Senden steht im Protokoll');
    // Team: Passwort vergessen per Mail (AP-6)
    $k = "$tmp/kekse-vergessen.txt";
    $r = http_anfrage($basis, 'GET', '/konto/passwort-vergessen', $h, [], null, $k);
    http_anfrage($basis, 'POST', '/konto/passwort-vergessen', $h, [], ['csrf' => http_csrf($r['text']), 'email' => TEST_BETREIBER], $k);
    $pm = http_mail_an($tmp, TEST_BETREIBER, 20, 'zweiter Faktor bleibt');
    pruefe(preg_match('#/einrichten\?t=[A-Za-z0-9_-]{40,}#', $pm) === 1, 'Team: Passwort vergessen schickt einen Link per Mail (zweiter Faktor bleibt)');
    pruefe(!str_contains(http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k)['text'], 'ein Betreiber schickt dir'), 'Hinweis „Betreiber schickt Link“ entfernt');
}

/** Rückmeldung 2026-10-30: Navigation, Einstieg, Sperre der Einstellungen, Texte mit Bild, Login mit Mail-Code (Anna), Protokoll, Dialog. */
function http_bereich_rueckmeldung(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp] = $u;
    $f = http_frisch($u);
    $b = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT)['keks'];
    $gast = "$tmp/kekse-gast.txt";

    abschnitt('HTTP: Rückmeldung 2026-10-30');
    // Team-Login wird auf öffentlichen Seiten erkannt (vorher sah „Deine Anmeldung“ wie ausgeloggt aus)
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'href="/team"') && str_contains($r['text'], 'Meine Anmeldung') && !str_contains($r['text'], 'class="nav-anmelden"'), 'Team eingeloggt: „Meine Anmeldung“ und Team-Bereich, nicht ausgeloggt');
    pruefe(http_anfrage($basis, 'GET', '/team', $h, [], null, $b)['status'] === 200, 'Team-Sitzung bleibt danach bestehen');
    $r = http_anfrage($basis, 'GET', '/', $h, [], null, $gast);
    pruefe(str_contains($r['text'], 'class="nav-anmelden" href="/deine-anmeldung"') && !str_contains($r['text'], 'Meine Anmeldung') && !str_contains($r['text'], '>Team</a>'), 'Gäste: grüner Knopf „Anmelden“, kein Team-Link in der Fußzeile');
    pruefe(str_contains($r['text'], '/assets/basis.js') && !str_contains($r['text'], '/assets/dialog.js'), 'JS-Basis (mit Dialogen, AP-17) auf jeder Seite');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/team', $h, [], null, $b)['text'], '<meta name="csrf" content="'), 'CSRF-Meta für Skripte, wenn eine Sitzung besteht');
    // Vor dem Anmeldestart: Hinweis statt „Link per Mail“
    $start = einstellung('anmeldung_start');
    einstellung_setzen('anmeldung_start', gmdate('Y-m-d H:i:s', time() + 86400 * 3));
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $gast);
    pruefe(str_contains($r['text'], 'Anmeldung noch nicht möglich') && !str_contains($r['text'], 'Link per Mail') && str_contains($r['text'], 'Einloggen'), 'Vor dem Start: Hinweis über zwei Kacheln, kein „Link per Mail“');
    einstellung_setzen('anmeldung_start', (string)$start);
    pruefe(str_contains(http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $gast)['text'], 'Link per Mail'), 'Nach dem Start: „Link per Mail“ wieder da');
    // Einstellungen als Übersicht (AP-18): alle Kacheln zu, „Bearbeiten“ statt Entsperren
    $r = http_anfrage($basis, 'GET', '/team/einstellungen', $h, [], null, $b);
    pruefe(substr_count($r['text'], '<details class="einst-auf" name="einstellungen">') === count(VA_GRUPPEN) && !str_contains($r['text'], ' open>') && !str_contains($r['text'], 'Entsperren'), 'Einstellungen: alle Kacheln zu, ohne Entsperren');
    // Texte: Bild hochladen, öffentlich sichtbar
    $png = "$tmp/text.png";
    $g = imagecreatetruecolor(50, 30);
    imagepng($g, $png);
    imagedestroy($g);
    $c = http_csrf($r['text']);
    $ch = curl_init($basis . '/team/texte/bild');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Host: ' . $h, 'X-CSRF: ' . $c], CURLOPT_COOKIEFILE => $b, CURLOPT_COOKIEJAR => $b, CURLOPT_POSTFIELDS => ['bild' => new CURLFile($png, 'image/png', 'a.png')]]);
    $j = json_decode((string)curl_exec($ch), true) ?? [];
    curl_close($ch);
    $url = (string)($j['url'] ?? '');
    pruefe(preg_match('#^/bild/text-[0-9a-f]{16}\.png$#', $url) === 1, 'Bild für einen Text hochgeladen');
    pruefe(http_anfrage($basis, 'GET', $url, $h, [], null, $gast)['status'] === 200, 'Text-Bild öffentlich abrufbar');
    http_anfrage($basis, 'POST', '/team/startseite', $h, [], ['csrf' => $c, 'start_cme' => '<p>Mit Bild:</p><p><img src="' . $url . '" alt="Lageplan" data-breite="50"><script>x()</script></p>'], $b);
    $start = http_anfrage($basis, 'GET', '/', $h, [], null, $gast)['text'];
    pruefe(str_contains($start, '<img class="text-bild" loading="lazy" src="' . $url . '" alt="Lageplan" data-breite="50">') && !str_contains($start, '<script>x()'), 'Bild erscheint auf der Startseite (halbe Breite), Skript entfernt');
    $r = http_anfrage($basis, 'GET', '/team/texte', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'data-editor="block"') && str_contains($r['text'], 'data-werkzeug="fett kursiv titel | liste nliste link bild knopf | html"'), 'Impressum und Datenschutz mit dem Editor, Werkzeugleiste wie bei den Mailings (Knopf, HTML)');
    // Login mit Code per E-Mail: Anna (Admin) loggt sich zum ersten Mal ein
    $k = "$tmp/kekse-anna.txt";
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k);
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => TEST_ADMIN, 'passwort' => TEST_PASSWORT], $k);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k);
    pruefe(str_contains($r['text'], 'Code per E-Mail nutzen') && str_contains($r['text'], 'data-qr="otpauth://'), 'Erstes Einloggen: Wahl zwischen E-Mail-Code und App');
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'mail_waehlen'], $k);
    $mail = http_mail_an($tmp, TEST_ADMIN, 20, 'Er gilt 10 Minuten');
    $code = preg_match('/^\s+(\d{6})\s*$/m', $mail, $m) === 1 ? $m[1] : '';
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k);
    pruefe($code !== '' && str_contains($r['text'], 'Code aus der Mail'), 'Code kommt per Mail');
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'mail_einrichten', 'totp' => $code], $k);
    pruefe($r['status'] === 200 && substr_count($r['text'], '<li class="mono">') === TOTP_CODES && http_anfrage($basis, 'GET', '/team', $h, [], null, $k)['status'] === 200, 'Mit dem Code eingerichtet, Wiederherstellungscodes, eingeloggt');
    // Nächstes Einloggen (später: Grenze 1 Code je Minute, Review S-9): Code kommt automatisch
    db_ausfuehren('DELETE FROM rate_limit');
    $k2 = "$tmp/kekse-anna2.txt";
    $vorher = http_mail_anzahl($tmp, TEST_ADMIN);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k2);
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => TEST_ADMIN, 'passwort' => TEST_PASSWORT], $k2);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k2);
    for ($i = 0; $i < 30 && http_mail_anzahl($tmp, TEST_ADMIN) <= $vorher; $i++) {
        usleep(150_000);
    }
    $mail = http_mail_an($tmp, TEST_ADMIN, 5, 'Er gilt 10 Minuten');
    $code2 = preg_match('/^\s+(\d{6})\s*$/m', $mail, $m) === 1 ? $m[1] : '';
    pruefe(str_contains($r['text'], 'Wir haben dir einen sechsstelligen Code') && http_mail_anzahl($tmp, TEST_ADMIN) === $vorher + 1 && $code2 !== $code, 'Später: Code kommt beim Einloggen automatisch (ein neuer)');
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'code', 'totp' => $code2], $k2);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/team', 'Einloggen mit dem Code aus der Mail');
    $r = http_anfrage($basis, 'GET', '/konto/zweitfaktor', $h, [], null, $k2);
    pruefe(str_contains($r['text'], 'Zwei-Faktor-Authentifizierung') && str_contains($r['text'], 'Code per E-Mail') && str_contains($r['text'], 'Passkey'), 'Mein Konto › Zwei-Faktor');
    $r = http_anfrage($basis, 'POST', '/konto/zweitfaktor', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'mail_aus', 'pw' => TEST_PASSWORT], $k2);
    pruefe(str_contains($r['text'], 'Richte zuerst die Authenticator-App oder einen Passkey ein') && totp_zustand($f['admin'])['mail'], 'Mail-Code ohne App nicht abschaltbar');
    pruefe(http_anfrage($basis, 'GET', '/team/export?art=cme_nachweise', $h, [], null, $k2)['status'] === 404, 'CME-Nachweise: Export nur für Betreiber (AP-9)');
    // Protokoll: Admin sieht keine Sicherung, kann nicht ausblenden
    beispieldaten_sicherung();
    $r = http_anfrage($basis, 'GET', '/team/protokoll?art=sicherung&v=alle', $h, [], null, $k2);
    $r2 = http_anfrage($basis, 'GET', '/team/protokoll?art=sicherung&v=alle', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Keine Einträge') && !str_contains($r['text'], 'Ausblenden') && !str_contains($r2['text'], 'Keine Einträge'), 'Admin: keine Sicherungs-Einträge (die BetreiberIn sieht sie), kein Ausblenden');
    $r = http_anfrage($basis, 'GET', '/team/protokoll', $h, [], null, $b);
    pruefe(str_contains($r['text'], '>Ausblenden<') && str_contains($r['text'], 'class="filter"'), 'Betreiber: Ausblenden und Filter-Knöpfe');
    // Programm: Löschen-Knopf neben Speichern, Dialog-Markierungen
    $r = http_anfrage($basis, 'GET', '/team/programm?id=' . $f['bd']['programm']['punkte'][1], $h, [], null, $b);
    pruefe(str_contains($r['text'], 'id="pg-loeschen" hidden') && str_contains($r['text'], 'data-ausloeser="pg-loeschen"'), 'Programmpunkt löschen neben Speichern (mit Dialog)');
    pruefe(!str_contains($r['text'], '+ neu') && !str_contains($r['text'], 'Markiert:'), 'Programm: ein Label, keine Legende');
}

/** Ein Konto für alles, Personen-Menü, Team-Navigation, ein Editor (Rückmeldung 2026-10-30 (2), E-54) – mit Anna (Admin) und Lea (Konto). */
function http_bereich_konto_alles(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp] = $u;
    $f = http_frisch($u);
    $b = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT)['keks'];
    $k2 = http_team_einloggen($u, TEST_ADMIN, TEST_PASSWORT)['keks'];
    $mk = $f['admin'];

    abschnitt('HTTP: Ein Konto für alles, Navigation, Editor (E-54)');
    $r = http_anfrage($basis, 'GET', '/team/personen', $h, [], null, $k2);
    pruefe(str_contains($r['text'], 'aria-label="Unterseiten von Anmeldungen"') && str_contains($r['text'], 'href="/team/workshops"') && str_contains($r['text'], '>Mailings<') && !str_contains($r['text'], '>Kommunikation<') && !str_contains($r['text'], '>Team-Konten<') && !str_contains($r['text'], '>Betrieb<') && !str_contains($r['text'], 'Neue Veranstaltung'), 'Team-Navigation: Bereiche mit Unterseiten; Betrieb (Team-Konten, Neue Veranstaltung) nur für Betreiber (AP-12)');
    pruefe(str_contains($r['text'], 'class="person-menue"') && str_contains($r['text'], '<strong>Anna Admin</strong>') && str_contains($r['text'], 'href="/konto"'), 'Personen-Menü mit Name und „Mein Konto“');
    $r = http_anfrage($basis, 'GET', '/team/einstellungen', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'aria-label="Unterseiten von Verwaltung"') && str_contains($r['text'], '>Startseite<') && str_contains($r['text'], 'href="/team/status">Betrieb<'), 'Betreiber: Verwaltung mit Startseite, Reiter Betrieb führt zu Status (AP-15)');
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'aria-label="Unterseiten von Betrieb"') && str_contains($r['text'], '>Team-Konten<') && str_contains($r['text'], '>Newsletter<') && str_contains($r['text'], '>Texte<') && str_contains($r['text'], 'class="va-wechsler"') && str_contains($r['text'], 'Neue Veranstaltung'), 'Betreiber: Reiter Betrieb mit Unterseiten, Wechsler mit „Neue Veranstaltung“ (AP-12)');
    $r = http_anfrage($basis, 'GET', '/team/profil', $h, [], null, $k2);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/konto', 'Alte Adresse „Mein Konto“ im Team-Bereich führt zu /konto');
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $k2);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Team-Konto') && str_contains($r['text'], 'href="/konto/zweitfaktor"') && !str_contains($r['text'], '>Konto löschen<') && str_contains($r['text'], 'minlength="12"'), 'Mein Konto für Team: Zwei-Faktor, kein Löschen, Passwort 12 Zeichen');
    $start = einstellung('anmeldung_start');
    einstellung_setzen('anmeldung_start', gmdate('Y-m-d H:i:s', time() + 86400 * 3));
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k2);
    pruefe(str_contains($r['text'], 'Eingeloggt als Anna Admin') && str_contains($r['text'], 'Anmeldung noch nicht möglich') && !str_contains($r['text'], 'name="passwort"'), 'Meine Anmeldung eingeloggt vor dem Start: kein Login-Feld');
    einstellung_setzen('anmeldung_start', (string)$start);
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k2);
    pruefe(str_contains($r['text'], 'Eingeloggt als Anna Admin') && !str_contains($r['text'], 'name="passwort"') && str_contains($r['text'], 'href="/anmelden"'), 'Meine Anmeldung eingeloggt: kein Login-Feld, „Jetzt anmelden“');
    // Team-Mitglied meldet sich mit dem eigenen Konto an: vorausgefüllt, gleich bestätigt
    $r = http_anfrage($basis, 'GET', '/anmelden', $h, [], null, $k2);
    pruefe(str_contains($r['text'], 'value="' . TEST_ADMIN . '" readonly'), 'Anmeldeformular mit der Adresse des Team-Kontos');
    http_anmelden($basis, $h, $k2, ['vorname' => 'Anna', 'nachname' => 'Admin', 'berufsgruppe' => 'Pflege/MFA', 'institution' => 'ZNA', 'essen' => 'egal']);
    $a = db_zeile('SELECT id, status, konto_id FROM anmeldung WHERE email = ?', [TEST_ADMIN]);
    pruefe(($a['status'] ?? '') === 'bestaetigt' && (int)($a['konto_id'] ?? 0) === $mk, 'Team-Konto: eigene Anmeldung, verknüpft und gleich bestätigt');
    $mAnm = (int)($a['id'] ?? 0);
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k2);
    pruefe(str_contains($r['text'], 'Hallo Anna.'), 'Meine Anmeldung zeigt die eigene Anmeldung');
    pruefe(str_contains($r['text'], 'class="nav-team" href="/team"'), 'Meine Anmeldung zeigt dem Team-Konto den Team-Bereich in der Leiste');
    // AP-14 Unterpunkt 1 (S-1, T-275): Adresse eines Team-Kontos nicht über die Anmeldung
    $r = http_anfrage($basis, 'GET', '/team/personen?a=' . $mAnm, $h, [], null, $b);
    pruefe(str_contains($r['text'], 'value="' . TEST_ADMIN . '" readonly') && str_contains($r['text'], 'Bitte wende dich an eine BetreiberIn'), 'Team: E-Mail-Feld eines Team-Kontos schreibgeschützt mit Hinweis');
    $r = http_anfrage($basis, 'POST', '/team/personen?a=' . $mAnm, $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'speichern', 'a' => $mAnm, 'vorname' => 'Anna', 'nachname' => 'Admin', 'email' => 'fremd@example.org', 'berufsgruppe' => 'Pflege/MFA', 'institution' => 'ZNA', 'essen' => 'egal', 'ohne_mail' => '1'], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Bitte wende dich an eine BetreiberIn') && konto_laden($mk)['email'] === TEST_ADMIN && anm_laden($mAnm)['email'] === TEST_ADMIN, 'POST mit fremder Adresse auf Team-Anmeldung: Fehler, Konto-Adresse gleich');
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung?aendern=daten', $h, [], null, $k2);
    pruefe(str_contains($r['text'], 'value="' . TEST_ADMIN . '" readonly') && str_contains($r['text'], 'Adresse deines Team-Kontos'), 'Meine Anmeldung → Daten: Adresse des Team-Kontos schreibgeschützt');
    // AP-14 Unterpunkt 5 (B-5, T-279): Storno lässt den Team-Login bestehen
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k2);
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'storno'], $k2);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/deine-anmeldung' && anm_laden($mAnm) === null, 'Team-Mitglied storniert eigene Anmeldung: 303 auf /deine-anmeldung');
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k2);
    pruefe(http_anfrage($basis, 'GET', '/team', $h, [], null, $k2)['status'] === 200 && str_contains($r['text'], 'Eingeloggt als Anna Admin') && str_contains($r['text'], 'href="/anmelden"') && str_contains($r['text'], '<strong>Anna Admin</strong>'), 'nach dem Storno: Team-Bereich erreichbar, „Eingeloggt als …“ mit „Jetzt anmelden“');
    // Ausloggen beendet alles
    http_anfrage($basis, 'POST', '/ausloggen', $h, [], ['csrf' => http_csrf($r['text'])], $k2);
    pruefe(http_anfrage($basis, 'GET', '/team', $h, [], null, $k2)['status'] === 303 && !str_contains(http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k2)['text'], 'Eingeloggt als Anna Admin'), 'Ausloggen beendet Team und Konto zugleich');
    // Lea (Teilnehmenden-Konto mit Anmeldung): Login ohne zweiten Faktor, Storno, Rolle angehoben
    $lea = $f['bd']['anmeldungen'];
    $k3 = "$tmp/kekse-lea.txt";
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k3);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'lea@example.org', 'passwort' => BEISPIEL_PASSWORT], $k3);
    pruefe(($r['kopf']['location'] ?? '') === '/deine-anmeldung' && str_contains(http_anfrage($basis, 'GET', '/konto', $h, [], null, $k3)['text'], 'Konto löschen'), 'Teilnehmend: Login ohne zweiten Faktor, Mein Konto mit Löschen');
    // AP-14 Unterpunkt 5 (B-5, T-279): Storno lässt den Konto-Login bestehen
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k3);
    $sv = str_contains($r['text'], 'Hallo Lea.');
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'storno'], $k3);
    pruefe($sv && $r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/deine-anmeldung' && anm_laden($lea['lea']) === null, 'Konto: Anmeldung stornieren → 303 auf /deine-anmeldung');
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k3);
    pruefe(http_anfrage($basis, 'GET', '/konto', $h, [], null, $k3)['status'] === 200 && str_contains($r['text'], 'Eingeloggt als Lea Beispiel') && str_contains($r['text'], '<strong>Lea Beispiel</strong>'), 'nach dem Storno: weiter eingeloggt, Name im Personen-Menü');
    konto_rolle_setzen($lea['lea_konto'], 'admin', null);
    pruefe(http_anfrage($basis, 'GET', '/konto', $h, [], null, $k3)['status'] === 303, 'Rolle angehoben: alte Sitzung gilt nicht mehr');
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k3);
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'lea@example.org', 'passwort' => BEISPIEL_PASSWORT], $k3);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k3);
    pruefe(str_contains($r['text'], 'data-qr="otpauth://') && http_anfrage($basis, 'GET', '/team', $h, [], null, $k3)['status'] === 303, 'Nächster Login: zweiten Faktor einrichten, vorher kein Team-Bereich');
    // Ein Editor: Programm-Beschreibung wird bereinigt gespeichert
    $r = http_anfrage($basis, 'GET', '/team/programm?neu=vortrag', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'id="pg-beschreibung"') && str_contains($r['text'], 'data-editor="block"') && str_contains($r['text'], '/assets/editor.js'), 'Programm-Beschreibung mit dem Editor');
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'speichern', 'typ' => 'pause', 'titel' => 'Editor-Test', 'beschreibung' => '<p>Mit <b>fett</b><script>x()</script></p><ol><li>eins</li></ol>'], $b);
    pruefe(db_wert("SELECT beschreibung FROM programmpunkt WHERE titel = 'Editor-Test'") === '<p>Mit <strong>fett</strong></p><ol><li>eins</li></ol>', 'Beschreibung als bereinigtes HTML gespeichert (nummerierte Liste)');
}

/** Passkeys über die Endpunkte, mit dem Software-Authenticator (E-55): Lea (Konto) und Anna (Team). Host localhost (Passkeys brauchen eine Domain). */
function http_bereich_passkey(array $u): void
{
    ['basis' => $basis, 'tmp' => $tmp] = $u;
    $f = http_frisch($u);
    require_once __DIR__ . '/passkey_hilfe.php';
    $h = 'localhost';
    $ursprung = 'http://localhost:' . parse_url($basis, PHP_URL_PORT);
    $json = fn(array $r) => json_decode($r['text'], true) ?? [];
    $kp = $f['bd']['anmeldungen']['lea_konto'];

    abschnitt('HTTP: Passkeys (E-55)');
    $k = "$tmp/kekse-pk.txt";
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k);
    pruefe(str_contains($r['text'], 'data-passkey-login') && str_contains($r['text'], 'Mit Passkey einloggen') && str_contains($r['text'], '/assets/passkey.js'), 'Login-Seite: „Mit Passkey einloggen“');
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'lea@example.org', 'passwort' => BEISPIEL_PASSWORT], $k);
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $k);
    $c = http_csrf($r['text']);
    pruefe(str_contains($r['text'], 'id="passkeys"') && str_contains($r['text'], 'data-passkey-anlage') && str_contains($r['text'], 'Noch kein Passkey'), 'Mein Konto: Abschnitt Passkeys');
    $r = http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'anlage_optionen', 'pw' => 'falsch'], $k);
    pruefe($r['status'] === 400 && str_contains($r['text'], 'Passwort stimmt nicht'), 'Passkey anlegen nur mit Passwort');
    pruefe(http_anfrage($basis, 'POST', '/passkey', $h, [], ['aktion' => 'login_optionen'], $k)['status'] === 403, 'Ohne CSRF-Token abgelehnt');
    $o = $json(http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'anlage_optionen', 'pw' => BEISPIEL_PASSWORT], $k))['optionen'] ?? [];
    pruefe(($o['rp']['id'] ?? '') === 'localhost', 'Anlage-Optionen mit rp.id localhost');
    $g = test_pk_geraet();
    $r = http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'anlegen', 'bezeichnung' => 'Testgerät', 'antwort' => json_encode(test_pk_anlage($g, (string)($o['challenge'] ?? ''), 'localhost', $ursprung))], $k);
    pruefe(($json($r)['ok'] ?? false) === true && pk_zahl($kp) === 1, 'Passkey über den Endpunkt angelegt');
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $k);
    pruefe(str_contains($r['text'], 'Testgerät') && str_contains($r['text'], 'noch nicht benutzt'), 'Liste zeigt den Passkey');
    // Ausloggen, dann ohne Passwort mit dem Passkey einloggen
    http_anfrage($basis, 'POST', '/ausloggen', $h, [], ['csrf' => http_csrf($r['text'])], $k);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k);
    $c = http_csrf($r['text']);
    $o = $json(http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'login_optionen'], $k))['optionen'] ?? [];
    $r = http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'login', 'antwort' => json_encode(test_pk_login($g, (string)($o['challenge'] ?? ''), 'localhost', $ursprung))], $k);
    pruefe(($json($r)['ok'] ?? false) === true && ($json($r)['ziel'] ?? '') === '/deine-anmeldung', 'Einloggen mit Passkey ohne Passwort (Lea hat eine Anmeldung: weiter zu ihr)');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/konto', $h, [], null, $k)['text'], 'zuletzt benutzt'), 'Danach eingeloggt, Passkey als benutzt markiert');
    $o = $json(http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'login_optionen'], $k))['optionen'] ?? [];
    $r = http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'login', 'antwort' => json_encode(test_pk_login($g, (string)($o['challenge'] ?? ''), 'localhost', 'https://boese.example'))], $k);
    pruefe($r['status'] === 400 && str_contains($r['text'], 'nicht angenommen'), 'Fremder Ursprung abgelehnt');
    // Team-Konto (Anna): beim ersten Login Passkey als zweiter Faktor, dann Wiederherstellungscodes
    $kt = "$tmp/kekse-pk-team.txt";
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $kt);
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => TEST_ADMIN, 'passwort' => TEST_PASSWORT], $kt);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $kt);
    pruefe(str_contains($r['text'], 'data-passkey-anlage') && str_contains($r['text'], 'Passkey anlegen') && str_contains($r['text'], 'Möglichkeit 3'), 'Erster Team-Login: Passkey als erste Wahl');
    $c = http_csrf($r['text']);
    $o = $json(http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'anlage_optionen'], $kt))['optionen'] ?? [];
    $gt = test_pk_geraet();
    $r = http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'anlegen', 'antwort' => json_encode(test_pk_anlage($gt, (string)($o['challenge'] ?? ''), 'localhost', $ursprung))], $kt);
    pruefe(($json($r)['ziel'] ?? '') === '/einloggen?codes=1' && pk_zahl($f['admin']) === 1, 'Einrichtung mit Passkey abgeschlossen');
    $r = http_anfrage($basis, 'GET', '/einloggen?codes=1', $h, [], null, $kt);
    pruefe(substr_count($r['text'], '<li class="mono">') === TOTP_CODES, 'Danach Wiederherstellungscodes, einmal');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $kt);
    pruefe($r['status'] === 200, 'Team-Bereich offen');
    http_anfrage($basis, 'POST', '/ausloggen', $h, [], ['csrf' => http_csrf($r['text'])], $kt);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $kt);
    $c = http_csrf($r['text']);
    $o = $json(http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'login_optionen'], $kt))['optionen'] ?? [];
    $r = http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'login', 'antwort' => json_encode(test_pk_login($gt, (string)($o['challenge'] ?? ''), 'localhost', $ursprung))], $kt);
    pruefe(($json($r)['ziel'] ?? '') === '/team' && http_anfrage($basis, 'GET', '/team', $h, [], null, $kt)['status'] === 200, 'Team: Passkey ersetzt Passwort und zweiten Faktor');
}

/** Newsletter (AP-7): bestellen, bestätigen, verwalten, One-Click-Abmelden, Team – neben Nina, Otto und Pia aus den Beispieldaten. */
function http_bereich_newsletter(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp] = $u;
    http_frisch($u);
    $b = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT)['keks'];
    $abo = fn(string $email) => db_wert('SELECT a.status FROM newsletter_abo a JOIN newsletter_person p ON p.id = a.person_id WHERE p.email = ?', [$email]);

    abschnitt('HTTP: Newsletter (AP-7)');
    $k = "$tmp/kekse-nl.txt";
    $r = http_anfrage($basis, 'GET', '/newsletter', $h, [], null, $k);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Wintersymposium') && str_contains($r['text'], 'Allgäu Akut') && !str_contains($r['text'], 'Referierende (intern)') && str_contains($r['text'], 'name="pow_aufgabe"'), 'Seite /newsletter mit den sichtbaren Listen und Spamschutz');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/', $h, [], null, $k)['text'], 'href="/newsletter"'), 'Link in Fußzeile und Hinweis auf der Startseite');
    $lw = (int)db_wert("SELECT id FROM newsletter_liste WHERE name = 'Wintersymposium'");
    preg_match('/name="pow_aufgabe" value="([0-9a-f]{64})"/', $r['text'], $m);
    $form = ['csrf' => http_csrf($r['text']), 'zeit' => spam_stempel(time() - 10), 'pow_aufgabe' => $m[1] ?? '', 'pow_loesung' => http_pow($m[1] ?? ''), 'website' => '', 'email' => 'sven@example.org', 'vorname' => 'Sven', 'listen' => [$lw]];
    $r = http_anfrage($basis, 'POST', '/newsletter', $h, [], $form, $k);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Einwilligung') && nl_person_per_email('sven@example.org') === null, 'ohne Einwilligung: Hinweis, nichts angelegt');
    $r = http_anfrage($basis, 'POST', '/newsletter', $h, [], $form + ['einwilligung' => '1'], $k);
    $mail = http_mail_an($tmp, 'sven@example.org', 20, '/newsletter/bestaetigen?t=');
    $tok = preg_match('#/newsletter/bestaetigen\?t=([A-Za-z0-9_-]{43})#', $mail, $mm) === 1 ? $mm[1] : '';
    pruefe($r['status'] === 303 && $tok !== '', 'Bestellt: Bestätigungsmail');
    $r = http_anfrage($basis, 'GET', '/newsletter/bestaetigen?t=' . $tok, $h, [], null, $k);
    pruefe(str_contains($r['text'], 'Bestätigen') && $abo('sven@example.org') === 'offen', 'Öffnen allein bestätigt nicht (Mail-Scanner)');
    http_anfrage($basis, 'POST', '/newsletter/bestaetigen', $h, [], ['csrf' => http_csrf($r['text']), 't' => $tok], $k);
    pruefe($abo('sven@example.org') === 'bestaetigt', 'Bestätigt (Double-Opt-In)');
    // Verwalten und One-Click (Ninas Link aus den Beispieldaten)
    $nina = (int)nl_person_per_email('nina@example.org')['id'];
    $vt = nl_link_erzeugen($nina, 'verwalten');
    $r = http_anfrage($basis, 'GET', '/newsletter/verwalten?t=' . $vt, $h, [], null, $k);
    pruefe(str_contains($r['text'], 'nina@example.org') && str_contains($r['text'], 'Von allen abmelden'), 'Verwalten über den Link aus der Mail');
    $ch = curl_init($basis . '/newsletter/abmelden?t=' . $vt);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Host: ' . $h], CURLOPT_POSTFIELDS => 'List-Unsubscribe=One-Click']);
    curl_exec($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    pruefe($st === 200 && nl_person_per_email('nina@example.org') === null && nl_person_per_email('otto@example.org') !== null, 'One-Click-Abmelden ohne Login: sofort gelöscht (Abnahme AP-7)');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/newsletter/abmelden?t=' . str_repeat('a', 48), $h, [], null, $k)['text'], 'gilt nicht mehr'), 'ungültiger Abmeldelink: freundlicher Hinweis');
    // Team
    $r = http_anfrage($basis, 'GET', '/team/newsletter', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'aria-current="page">Newsletter') && str_contains($r['text'], 'Einladen') && str_contains($r['text'], 'Neue Liste'), 'Team: Reiter Newsletter');
    $c = http_csrf($r['text']);
    http_anfrage($basis, 'POST', '/team/newsletter', $h, [], ['csrf' => $c, 'aktion' => 'liste', 'name' => 'Testliste', 'beschreibung' => 'Nur Test', 'absender_name' => 'Test', 'sichtbar' => '1'], $b);
    $tl = (int)db_wert("SELECT id FROM newsletter_liste WHERE name = 'Testliste'");
    pruefe($tl > 0 && str_contains(http_anfrage($basis, 'GET', '/newsletter', $h, [], null, $k)['text'], 'Testliste'), 'Liste anlegen, öffentlich wählbar');
    http_anfrage($basis, 'POST', '/team/newsletter', $h, [], ['csrf' => $c, 'aktion' => 'einladen', 'email' => 'tara@example.org', 'listen' => [$tl]], $b);
    pruefe(http_mail_an($tmp, 'tara@example.org', 20, '/newsletter/bestaetigen?t=') !== '' && $abo('tara@example.org') === 'offen', 'Einladen: nur Bestätigungsmail, nicht eingetragen');
    $r = http_anfrage($basis, 'GET', '/team/newsletter?q=tara', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'tara@example.org') && str_contains($r['text'], 'wartet') && !str_contains($r['text'], 'otto@example.org'), 'Suche zeigt offene Einladung');
    http_anfrage($basis, 'POST', '/team/newsletter', $h, [], ['csrf' => $c, 'aktion' => 'loeschen', 'person' => (int)nl_person_per_email('tara@example.org')['id']], $b);
    pruefe(nl_person_per_email('tara@example.org') === null, 'Löschen durch das Team');
    $r = http_anfrage($basis, 'GET', '/team/export?art=newsletter', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_starts_with($r['text'], "\u{FEFF}\"Liste\";\"E-Mail\"") && str_contains($r['text'], 'otto@example.org') && str_contains($r['text'], 'sven@example.org'), 'Export Newsletter als CSV');
    $r = http_anfrage($basis, 'GET', '/team/mailings?neu=1', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'value="newsletter"') && str_contains($r['text'], 'name="listen[]"'), 'Mailings: Empfängerart Newsletter-Listen');
    // „Wintersymposium“: Sven bestätigt, Pia wartet noch (Nina ist abgemeldet)
    $r = http_anfrage($basis, 'GET', '/team/mailings/empfaenger?auswahl=nl:' . $lw, $h, [], null, $b);
    pruefe($r['status'] === 200 && (json_decode($r['text'], true)['zahl'] ?? -1) === 1, 'Empfängerzahl einer Liste (nur bestätigte)');
}

/** Mehrere Veranstaltungen (E-58): anlegen, wechseln, abschließen, Archiv, neue aktuelle. */
function http_bereich_veranstaltungen(array $u): void
{
    ['basis' => $basis, 'h' => $h] = $u;
    http_frisch($u);
    $b = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT)['keks'];

    abschnitt('HTTP: Mehrere Veranstaltungen (E-58)');
    $v1 = (int)va_aktuell_id();
    $k1 = (string)va_zeile($v1)['kuerzel'];
    $r = http_anfrage($basis, 'GET', '/team/veranstaltungen', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], '/' . $k1) && str_contains($r['text'], '/fortbildungsabend-notfallmedizin') && str_contains($r['text'], 'Neue Veranstaltung') && str_contains($r['text'], 'class="va-wechsler"'), 'Team: Seite Veranstaltungen mit Wechsler im Kopf');
    $c = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/team/veranstaltungen', $h, [], ['csrf' => $c, 'aktion' => 'anlegen', 'name' => 'Sommerabend', 'kuerzel' => 'team', 'vorlage' => $v1], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'schon eine Adresse') && va_per_kuerzel('team') === null, 'Kürzel „team“ abgelehnt');
    $r = http_anfrage($basis, 'POST', '/team/veranstaltungen', $h, [], ['csrf' => $c, 'aktion' => 'anlegen', 'name' => 'Sommerabend', 'kuerzel' => 'sommerabend', 'vorlage' => $v1], $b);
    $v2 = (int)(va_per_kuerzel('sommerabend')['id'] ?? 0);
    pruefe($r['status'] === 303 && $v2 > 0 && str_contains($r['kopf']['location'] ?? '', '/team/einstellungen?va=' . $v2), 'Neue Veranstaltung angelegt, weiter zu ihren Einstellungen');
    $r = http_anfrage($basis, 'GET', '/team/einstellungen?va=' . $v2, $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Entwurf – noch nicht öffentlich') && str_contains($r['text'], 'value="sommerabend"') && str_contains($r['text'], 'Sommerabend'), 'Team arbeitet in der gewählten Veranstaltung (Entwurf)');
    $r = http_anfrage($basis, 'GET', '/team/personen', $h, [], null, $b);
    // Teilnehmenden-Konten (Lea) gelten für alle Veranstaltungen, Anmeldungen nicht
    pruefe($r['status'] === 200 && !str_contains($r['text'], 'Paula Probe') && !str_contains($r['text'], 'Tom Test'), 'Personen: nur die Anmeldungen der gewählten Veranstaltung');
    pruefe(http_anfrage($basis, 'GET', '/sommerabend', $h)['status'] === 404 && http_anfrage($basis, 'GET', '/' . $k1, $h)['status'] === 200, 'Entwurf nicht öffentlich, aktuelle unter ihrem Kürzel');
    // Abschließen: nur noch lesen
    http_anfrage($basis, 'GET', '/team?va=' . $v1, $h, [], null, $b);
    $r = http_anfrage($basis, 'POST', '/team/veranstaltungen', $h, [], ['csrf' => $c, 'aktion' => 'abschliessen', 'id' => $v1], $b);
    pruefe($r['status'] === 303 && va_zeile($v1)['status'] === 'abgeschlossen', 'Abschließen');
    $r = http_anfrage($basis, 'POST', '/team/einstellungen', $h, [], ['csrf' => $c, 'veranstaltung_ort' => 'Geändert'], $b);
    $r2 = http_anfrage($basis, 'GET', '/team/einstellungen', $h, [], null, $b);
    pruefe($r['status'] === 303 && str_contains($r2['text'], 'nur noch zum Ansehen') && va_von($v1, 'veranstaltung_ort') === 'Hörsaal Testklinik', 'Abgeschlossen: Speichern abgewiesen');
    $r = http_anfrage($basis, 'GET', '/' . $k1, $h);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Diese Veranstaltung ist vorbei') && !str_contains($r['text'], 'Jetzt anmelden'), 'Archiv-Seite unter dem Kürzel, ohne Anmeldung');
    $x = http_anfrage($basis, 'GET', '/team/export?art=teilnehmende', $h, [], null, $b)['text'];
    pruefe(str_starts_with($x, "\u{FEFF}") && str_contains($x, 'Paula'), 'Exporte gehen weiter');
    http_anfrage($basis, 'POST', '/team/veranstaltungen', $h, [], ['csrf' => $c, 'aktion' => 'aktuell', 'id' => $v2], $b);
    $r2 = http_anfrage($basis, 'GET', '/', $h);
    va_zwischenspeicher_leeren(); // Zwischenspeicher dieses Testprozesses, nicht der Seite
    pruefe(va_aktuell_id() === $v2 && $r2['status'] === 200 && !str_contains($r2['text'], 'Diese Veranstaltung ist vorbei') && str_contains($r2['text'], 'Sommerabend'), 'Neue aktuelle Veranstaltung auf der Startseite');
}

/** Rechte je Veranstaltung (AP-12, E-60): Ada (Admin) nur für eine Veranstaltung, Betrieb nur für BetreiberInnen. */
function http_bereich_rechte(array $u): void
{
    ['basis' => $basis, 'h' => $h] = $u;
    $f = http_frisch($u);
    $aktuell = (int)va_aktuell_id();
    $erlaubt = beispieldaten_entwurf();
    $fremdAnm = $f['bd']['anmeldungen']['lea'];
    $kid = $f['bd']['admin']; // Ada, bisher nur für den Fortbildungsabend
    $b = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT)['keks'];

    abschnitt('HTTP: Rechte je Veranstaltung (AP-12, E-60)');
    // BetreiberIn schaltet Ada nur für den Entwurf frei (Team-Konten)
    $c = http_csrf(http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $b)['text']);
    $r = http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $c, 'aktion' => 'veranstaltungen', 'id' => $kid, 'umfang' => 'auswahl', 'va' => [$erlaubt]], $b);
    pruefe($r['status'] === 303 && va_erlaubte_ids(konto_laden($kid)) === [$erlaubt], 'Team-Konten: Admin nur für eine Veranstaltung freigeschaltet');
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'value="auswahl" checked') && str_contains($r['text'], 'Darf diese Veranstaltungen bearbeiten'), 'Team-Konten zeigen die Auswahl');
    $k = http_team_einloggen($u, 'ada.abend@example.org', BEISPIEL_PASSWORT_ADMIN)['keks'];
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $k);
    $kuerzel = (string)va_zeile($erlaubt)['kuerzel'];
    $fremd = (string)va_zeile($aktuell)['kuerzel'];
    pruefe($r['status'] === 200 && str_contains($r['text'], 'class="va-wechsler"') && str_contains($r['text'], '?va=' . $erlaubt . '"') && !str_contains($r['text'], '?va=' . $aktuell . '"') && !str_contains($r['text'], '?va=' . $f['bd']['abend']['id'] . '"'), 'Wechsler zeigt nur die freigeschaltete Veranstaltung');
    pruefe(!str_contains($r['text'], 'Neue Veranstaltung') && !str_contains($r['text'], 'href="/team/veranstaltungen"') && !str_contains($r['text'], '>Betrieb<'), 'Admin: kein Reiter Betrieb, keine neue Veranstaltung');
    pruefe(http_anfrage($basis, 'GET', '/team/personen?va=' . $aktuell, $h, [], null, $k)['status'] === 403, 'fremde Veranstaltung per ?va= abgewiesen (403)');
    pruefe(http_anfrage($basis, 'GET', '/team/personen?a=' . $fremdAnm, $h, [], null, $k)['status'] === 404, 'Anmeldung einer fremden Veranstaltung: 404');
    $nur = [];
    foreach (test_seiten()['betreiber'] as $pfad) {
        $nur[$pfad] = http_anfrage($basis, 'GET', $pfad, $h, [], null, $k)['status'];
    }
    pruefe(count($nur) >= 7 && array_unique(array_values($nur)) === [403], 'Betrieb-Seiten aus der Seitenliste (' . count($nur) . ') nur für BetreiberInnen: ' . json_encode($nur));
    pruefe(str_contains(http_anfrage($basis, 'GET', '/team/startseite', $h, [], null, $k)['text'], 'name="start_kachel_1_titel"') && http_anfrage($basis, 'GET', '/team/export?art=newsletter', $h, [], null, $k)['status'] === 404, 'Startseiten-Texte ja (Seite „Startseite“), Newsletter-Export nein');
    $r = http_anfrage($basis, 'GET', '/team/mailings?neu=1', $h, [], null, $k);
    pruefe($r['status'] === 200 && !str_contains($r['text'], 'name="art" value="newsletter"') && !str_contains($r['text'], 'name="listen[]"'), 'Mailings: Newsletter-Listen nur für BetreiberInnen');
    // Protokoll: nur Einträge der freigeschalteten Veranstaltung
    audit_schreiben('programm', 'Fremder Eintrag Rechte-Test', null, vaId: $aktuell);
    audit_schreiben('programm', 'Eigener Eintrag Rechte-Test', null, vaId: $erlaubt);
    $r = http_anfrage($basis, 'GET', '/team/protokoll?v=alle', $h, [], null, $k);
    pruefe(str_contains($r['text'], 'Eigener Eintrag Rechte-Test') && !str_contains($r['text'], 'Fremder Eintrag Rechte-Test') && !str_contains($r['text'], 'Ohne Veranstaltung'), 'Protokoll: nur die eigene Veranstaltung');
    $r = http_anfrage($basis, 'GET', '/team/protokoll?v=ohne', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'aria-current="true">Ohne Veranstaltung') && !str_contains($r['text'], 'Fremder Eintrag Rechte-Test'), 'BetreiberIn: Filter „Ohne Veranstaltung“');
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $k);
    pruefe(str_contains($r['text'], 'Admin für:') && str_contains($r['text'], 'class="rechte-liste"') && str_contains($r['text'], 'Sommerfest Notfallmedizin') && str_contains($r['text'], 'Pflicht für Team-Konten'), 'Mein Konto: Rolle mit den Veranstaltungen, Hinweis zweiter Schritt');
    // Ohne Freischaltung: Hinweis statt Seite
    va_rechte_setzen($kid, false, [], null);
    pruefe(http_anfrage($basis, 'GET', '/team', $h, [], null, $k)['status'] === 403, 'ohne Veranstaltung: Hinweis statt Team-Bereich');
    va_rechte_setzen($kid, true, [], null);
    pruefe(http_anfrage($basis, 'GET', '/team/personen?va=' . $aktuell, $h, [], null, $k)['status'] === 200, '„Alle, auch künftige“: wieder alle');
    // Veranstaltung löschen: nur mit Kürzel, vorher Sicherung
    $c = http_csrf(http_anfrage($basis, 'GET', '/team/veranstaltungen', $h, [], null, $b)['text']);
    $vorher = count(sicherung_liste());
    http_anfrage($basis, 'POST', '/team/veranstaltungen', $h, [], ['csrf' => $c, 'aktion' => 'loeschen', 'id' => $erlaubt, 'kuerzel' => 'falsch'], $b);
    pruefe(va_zeile($erlaubt) !== null && count(sicherung_liste()) === $vorher, 'Löschen mit falschem Kürzel abgewiesen');
    http_anfrage($basis, 'POST', '/team/veranstaltungen', $h, [], ['csrf' => $c, 'aktion' => 'loeschen', 'id' => $aktuell, 'kuerzel' => $fremd], $b);
    pruefe(va_zeile($aktuell) !== null, 'aktuelle Veranstaltung nicht löschbar');
    $r = http_anfrage($basis, 'POST', '/team/veranstaltungen', $h, [], ['csrf' => $c, 'aktion' => 'loeschen', 'id' => $erlaubt, 'kuerzel' => $kuerzel], $b);
    pruefe($r['status'] === 303 && va_zeile($erlaubt) === null && count(sicherung_liste()) === $vorher + 1, 'Löschen mit Kürzel: weg, vorher gesichert');
    pruefe(http_anfrage($basis, 'GET', '/team/veranstaltungen', $h, [], null, $k)['status'] === 403, 'Seite Veranstaltungen für Admins gesperrt');
}

/** Review-Behebung 1 (AP-14): Vorlage „Daten übernehmen“ (Kai vom Fortbildungsabend) nach dem Absenden weg (B-13, T-280). */
function http_bereich_ap14(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp] = $u;
    $f = http_frisch($u);

    abschnitt('HTTP: Review-Behebung 1 (AP-14)');
    $tok = anm_link_erzeugen($f['bd']['abend']['kai'], 'uebernehmen', time() + 3600);
    $k = "$tmp/kekse-vorlage.txt";
    $r = http_anfrage($basis, 'GET', '/link?t=' . $tok, $h, [], null, $k);
    $r = http_anfrage($basis, 'POST', '/link', $h, [], ['csrf' => http_csrf($r['text']), 't' => $tok], $k);
    $vorher = http_anfrage($basis, 'GET', '/anmelden', $h, [], null, $k);
    http_anmelden($basis, $h, $k, ['vorname' => 'Kai', 'nachname' => 'Neu', 'email' => 'kai.neu@example.org', 'berufsgruppe' => 'Pflege/MFA', 'institution' => 'Station 9', 'efn' => '', 'essen' => 'egal']);
    $neu = db_zeile("SELECT id FROM anmeldung WHERE email = 'kai.neu@example.org'");
    $nachher = http_anfrage($basis, 'GET', '/anmelden', $h, [], null, $k);
    pruefe(($r['kopf']['location'] ?? '') === '/anmelden' && str_contains($vorher['text'], 'value="kai.abend@example.org"') && str_contains($vorher['text'], 'value="Klinikum Beispielstadt"'), 'Vorlage füllt das Formular mit den Daten vom Fortbildungsabend');
    pruefe($neu !== null && !str_contains($nachher['text'], 'kai.abend@example.org') && !str_contains($nachher['text'], 'Klinikum Beispielstadt'), 'nach dem Absenden ist die Vorlage weg');
}

/** Review-Behebung 2 (AP-16, T-288 bis T-295) über HTTP: Ada (Admin nur Fortbildungsabend), Anna, Bea, Petra Passkey. */
function http_bereich_ap16(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp] = $u;
    $f = http_frisch($u);
    $json = fn(array $r) => json_decode($r['text'], true) ?? [];
    $vAkt = (int)va_aktuell_id();
    $vAbend = (int)$f['bd']['abend']['id'];
    $fremdAda = $f['bd']['review2']['fremd_ada'];
    beispieldaten_ada_abend($f['bd']['abend']);
    $ein = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT);
    $b = $ein['keks'];

    abschnitt('HTTP: Review-Behebung 2 (AP-16)');
    // Unterpunkt 4 (S-3, T-291): unbestätigte Anmeldung mit der Team-Adresse ist nicht „Meine Anmeldung“
    $s = http_team_einloggen($u, 'ada.abend@example.org', BEISPIEL_PASSWORT_ADMIN)['keks'];
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $s);
    pruefe(anm_laden($fremdAda)['status'] === 'unbestaetigt' && !str_contains($r['text'], 'Hallo Ada.') && str_contains($r['text'], 'Eingeloggt als Ada Abend'), 'S-3: unbestätigte Anmeldung mit Team-Adresse nicht als eigene gezeigt');
    db_ausfuehren("UPDATE anmeldung SET status = 'bestaetigt' WHERE id = ?", [$fremdAda]);
    pruefe(str_contains(http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $s)['text'], 'Hallo Ada.'), 'S-3: bestätigt mit gleicher Adresse: übernommen');

    // Unterpunkt 1 (B-4, T-288): Team-Seite einer alten Veranstaltung legt deren Anmeldung nicht in die Sitzung
    $r = http_anfrage($basis, 'GET', '/team/personen?va=' . $vAbend, $h, [], null, $s);
    $r2 = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $s);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Kai Abend') && str_contains($r2['text'], 'Hallo Ada.') && str_contains($r2['text'], 'Untergeschoben') && !str_contains($r2['text'], 'Abendklinik') && !str_contains($r2['text'], 'Diese Veranstaltung ist vorbei'), 'B-4: nach der alten Veranstaltung im Team-Bereich zeigt „Meine Anmeldung“ die aktuelle');
    $r = http_anfrage($basis, 'GET', '/fortbildungsabend-notfallmedizin', $h, [], null, $s);
    $r2 = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $s);
    pruefe($r['status'] === 200 && !str_contains($r['text'], 'Abendklinik') && str_contains($r2['text'], 'Untergeschoben') && !str_contains($r2['text'], 'Abendklinik'), 'B-4: Archivseite ändert die Anmeldung der Sitzung nicht');

    // Unterpunkt 7 (S-5, T-294): Mailing-Bilder nur für Berechtigte (Anna: Admin für alle Veranstaltungen)
    $nl = beispieldaten_newsletter_mailing();
    if ($nl === null || $f['bd']['mailings']['bild'] === null) {
        echo "  --    Mailing-Bilder übersprungen: GD fehlt\n";
    } else {
        $vaBild = (int)$f['bd']['mailings']['bild']; // im Entwurf „WS 01 Atemweg: Raumänderung“ der aktuellen Veranstaltung
        $k = http_team_einloggen($u, TEST_ADMIN, TEST_PASSWORT)['keks'];
        // Seit AP-19 (O-52) verschicken Admins die Newsletter ihrer Reihen; Anna (alle Veranstaltungen) sieht den der Reihe Wintersymposium.
        // Ohne Recht auf die Reihe bleibt es 404 (geprüft im Bereich ap19).
        pruefe(http_anfrage($basis, 'GET', '/team/mailings/bild?id=' . $nl['bild'], $h, [], null, $k)['status'] === 200, 'S-5/O-52: Admin für alle Veranstaltungen sieht das Bild des Reihen-Newsletters');
        pruefe(http_anfrage($basis, 'GET', '/team/mailings/bild?id=' . $vaBild, $h, [], null, $k)['status'] === 200, 'S-5: Bild im Mailing der eigenen Veranstaltung sichtbar');
        pruefe(http_anfrage($basis, 'GET', '/team/mailings/bild?id=' . $nl['bild'], $h, [], null, $b)['status'] === 200, 'S-5: BetreiberIn sieht das Newsletter-Bild');
        http_anfrage($basis, 'GET', '/team?va=' . $vAbend, $h, [], null, $k);
        pruefe(http_anfrage($basis, 'GET', '/team/mailings/bild?id=' . $vaBild, $h, [], null, $k)['status'] === 404, 'S-5: Bild aus dem Mailing einer anderen Veranstaltung → 404');
    }

    // Unterpunkt 3 (B-7, T-290): Sicherung herunterladen mit Code aus der Mail (Knopf „Code per E-Mail schicken“)
    $name = beispieldaten_sicherung();
    $r = http_anfrage($basis, 'GET', '/team/daten', $h, [], null, $b);
    $c = http_csrf($r['text']);
    pruefe(str_contains($r['text'], 'data-zf-mailcode') && str_contains($r['text'], 'Code aus der App, aus der Mail oder ein Wiederherstellungscode') && str_contains($r['text'], '/assets/zweitfaktor.js'), 'B-7: Bestätigung mit Knopf „Code per E-Mail schicken“');
    $vorher = http_mail_anzahl($tmp, TEST_BETREIBER);
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, ['Accept: application/json'], ['csrf' => $c, 'aktion' => 'herunterladen', 'name' => $name, 'zf_mailcode' => '1'], $b);
    for ($i = 0; $i < 30 && http_mail_anzahl($tmp, TEST_BETREIBER) <= $vorher; $i++) {
        usleep(150_000);
    }
    $mail = http_mail_an($tmp, TEST_BETREIBER, 5, 'Er gilt 10 Minuten');
    $code = preg_match('/^\s+(\d{6})\s*$/m', $mail, $m) === 1 ? $m[1] : '';
    pruefe(($json($r)['ok'] ?? false) === true && $code !== '', 'B-7: Code per E-Mail angefordert (JSON), Mail mit Code');
    $r2 = http_anfrage($basis, 'POST', '/team/daten', $h, ['Accept: application/json'], ['csrf' => $c, 'aktion' => 'herunterladen', 'name' => $name, 'zf_mailcode' => '1'], $b);
    usleep(300_000);
    pruefe(($json($r2)['ok'] ?? true) === false && str_contains((string)($json($r2)['text'] ?? ''), 'gerade erst unterwegs') && http_mail_anzahl($tmp, TEST_BETREIBER) === $vorher + 1, 'S-9: zweiter Code in derselben Minute nicht verschickt');
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $c, 'aktion' => 'herunterladen', 'name' => $name, 'totp' => $code], $b);
    pruefe($r['status'] === 200 && ($r['kopf']['content-type'] ?? '') === 'application/gzip', 'B-7: Herunterladen mit dem Code aus der Mail');

    // Unterpunkt 3 (B-7, T-290): BetreiberIn nur mit Passkey bestätigt über /passkey (Aktion bestaetigen)
    require_once __DIR__ . '/passkey_hilfe.php';
    $lh = 'localhost';
    $ursprung = 'http://localhost:' . parse_url($basis, PHP_URL_PORT);
    beispieldaten_betreiberin_passkey();
    $p = "$tmp/kekse-petra.txt";
    $r = http_anfrage($basis, 'GET', '/einloggen', $lh, [], null, $p);
    http_anfrage($basis, 'POST', '/einloggen', $lh, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'petra.passkey@example.org', 'passwort' => BEISPIEL_PASSWORT_PASSKEY], $p);
    $r = http_anfrage($basis, 'GET', '/einloggen', $lh, [], null, $p);
    $c = http_csrf($r['text']);
    $o = $json(http_anfrage($basis, 'POST', '/passkey', $lh, [], ['csrf' => $c, 'aktion' => 'anlage_optionen'], $p))['optionen'] ?? [];
    $g = test_pk_geraet();
    http_anfrage($basis, 'POST', '/passkey', $lh, [], ['csrf' => $c, 'aktion' => 'anlegen', 'antwort' => json_encode(test_pk_anlage($g, (string)($o['challenge'] ?? ''), 'localhost', $ursprung))], $p);
    $r = http_anfrage($basis, 'GET', '/team/daten', $lh, [], null, $p);
    $c = http_csrf($r['text']);
    pruefe(str_contains($r['text'], 'data-zf-passkey'), 'B-7: mit Passkey: Knopf „Mit Passkey bestätigen“');
    $r = http_anfrage($basis, 'POST', '/team/daten', $lh, [], ['csrf' => $c, 'aktion' => 'herunterladen', 'name' => $name], $p);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Der Code stimmt nicht') && ($r['kopf']['content-type'] ?? '') !== 'application/gzip', 'B-7: ohne Bestätigung abgelehnt');
    $o = $json(http_anfrage($basis, 'POST', '/passkey', $lh, [], ['csrf' => $c, 'aktion' => 'bestaetigen_optionen'], $p))['optionen'] ?? [];
    $r = http_anfrage($basis, 'POST', '/passkey', $lh, [], ['csrf' => $c, 'aktion' => 'bestaetigen', 'antwort' => json_encode(test_pk_login($g, (string)($o['challenge'] ?? ''), 'localhost', $ursprung))], $p);
    pruefe(($json($r)['ok'] ?? false) === true && count($o['allowCredentials'] ?? []) === 1, 'B-7: Passkey-Bestätigung angenommen (nur Passkeys des Kontos angeboten)');
    $r = http_anfrage($basis, 'POST', '/team/daten', $lh, [], ['csrf' => $c, 'aktion' => 'herunterladen', 'name' => $name], $p);
    pruefe($r['status'] === 200 && ($r['kopf']['content-type'] ?? '') === 'application/gzip', 'B-7: danach Herunterladen ohne Code');
    $r = http_anfrage($basis, 'POST', '/team/daten', $lh, [], ['csrf' => $c, 'aktion' => 'herunterladen', 'name' => $name], $p);
    pruefe(($r['kopf']['content-type'] ?? '') !== 'application/gzip', 'B-7: Bestätigung gilt nur einmal');
    $o = $json(http_anfrage($basis, 'POST', '/passkey', $lh, [], ['csrf' => $c, 'aktion' => 'bestaetigen_optionen'], $p))['optionen'] ?? [];
    $fremd = test_pk_geraet();
    $r = http_anfrage($basis, 'POST', '/passkey', $lh, [], ['csrf' => $c, 'aktion' => 'bestaetigen', 'antwort' => json_encode(test_pk_login($fremd, (string)($o['challenge'] ?? ''), 'localhost', $ursprung))], $p);
    $r2 = http_anfrage($basis, 'POST', '/team/daten', $lh, [], ['csrf' => $c, 'aktion' => 'herunterladen', 'name' => $name], $p);
    pruefe($r['status'] === 400 && ($r2['kopf']['content-type'] ?? '') !== 'application/gzip', 'B-7: fremder Passkey abgelehnt, kein Herunterladen');

    // Unterpunkt 6 (B-10, T-293): keine aktuelle Veranstaltung
    db_ausfuehren("UPDATE veranstaltung SET status = 'abgeschlossen' WHERE id = ?", [$vAkt]);
    $r = http_anfrage($basis, 'GET', '/', $h);
    $r2 = http_anfrage($basis, 'GET', '/anmelden', $h);
    $r3 = http_anfrage($basis, 'GET', '/deine-anmeldung', $h);
    db_ausfuehren("UPDATE veranstaltung SET status = 'aktuell' WHERE id = ?", [$vAkt]);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Derzeit keine Veranstaltung') && !str_contains($r['text'], 'Jetzt anmelden') && !str_contains($r['text'], '<dl class="eckdaten">'), 'B-10: Startseite ohne aktuelle Veranstaltung: Hinweis, keine Eckdaten, keine Anmeldung');
    pruefe($r2['status'] === 200 && str_contains($r2['text'], 'Derzeit gibt es keine Veranstaltung') && !str_contains($r2['text'], 'name="vorname"') && str_contains($r3['text'], 'Derzeit keine Veranstaltung'), 'B-10: /anmelden und /deine-anmeldung mit Hinweis');

    // Unterpunkt 8 (S-12, T-295): erfundene Sitzungs-ID im Keks wird nicht übernommen
    $erfunden = str_repeat('Ab1-', 12);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, ['Cookie: ws_sitzung=' . $erfunden]);
    $neu = preg_match('/ws_sitzung=([^;]+)/', (string)($r['kopf']['set-cookie'] ?? ''), $m) === 1 ? $m[1] : '';
    pruefe($r['status'] === 200 && $neu !== '' && $neu !== $erfunden && !is_file(sitzung_ablage_pfad() . '/sess_' . $erfunden), 'S-12: erfundene Sitzungs-ID im Keks → neue ID (use_strict_mode wirkt)');
}

/** Startseite mit Vorschau, Einstellungen als Übersicht (AP-18, E-70): T-312 bis T-315 über HTTP. */
function http_bereich_ap18(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp] = $u;
    $f = http_frisch($u);
    $vorjahr = (int)$f['bd']['vorjahr']['id'];
    $aktuell = (int)va_aktuell_id();
    $k = http_team_einloggen($u, TEST_ADMIN, TEST_PASSWORT)['keks'];

    abschnitt('HTTP: Seite „Startseite“ (AP-18 Unterpunkt 5, T-313)');
    $r = http_anfrage($basis, 'GET', '/team/startseite', $h, [], null, $k);
    $c = http_csrf($r['text']);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'aria-label="Unterseiten von Verwaltung"') && str_contains($r['text'], 'href="/team/startseite" aria-current="page"'), 'Admin: Verwaltung → Startseite mit Unterreiter');
    pruefe(str_contains($r['text'], 'name="start_titel"') && str_contains($r['text'], 'data-editor="titel" data-werkzeug="lila trennstelle"') && str_contains($r['text'], 'name="start_programm_titel"')
        && str_contains($r['text'], 'Fest, nicht hier zu ändern') && str_contains($r['text'], 'data-vorschau="/team/startseite/vorschau"'), 'Abschnitte Kopf, Worum es geht, Programm, Merke; Titel mit Editor „Lila · Trennstelle“; feste Teile genannt');
    $alleFelder = array_map(fn($k) => start_text_wert($k), array_combine(array_keys(START_TEXTE), array_keys(START_TEXTE)));
    $r = http_anfrage($basis, 'POST', '/team/startseite', $h, [], ['csrf' => $c, 'start_titel' => 'Neuer <i>Abend</i>', 'start_programm_titel' => 'Unser Abend', 'start_kachel_2_text' => ''] + $alleFelder, $k);
    $start = http_anfrage($basis, 'GET', '/', $h)['text'];
    pruefe($r['status'] === 303 && str_contains($start, '>Neuer <em>Abend</em></h1>') && str_contains($start, 'id="programm-titel">Unser Abend</h2>') && str_contains($start, '<strong>Wie</strong>'), 'Speichern: Startseite zeigt Titel und eigene Programm-Überschrift (Kachel mit Titel bleibt)');
    $r = http_anfrage($basis, 'POST', '/team/startseite', $h, [], ['csrf' => $c, 'start_sticker' => str_repeat('x', START_TEXT_MAX + 1), 'start_unter' => 'Nicht gespeichert'], $k);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Nicht gespeichert') && str_contains($r['text'], 'aria-invalid="true"') && inhalt('start_unter') !== 'Nicht gespeichert', 'Fehler: markiert, nichts gespeichert');
    $r = http_anfrage($basis, 'GET', '/team/einstellungen', $h, [], null, $k);
    pruefe(!str_contains($r['text'], 'name="start_') && str_contains($r['text'], 'href="/team/startseite">Startseite bearbeiten</a>') && str_contains($r['text'], '<p class="titel">Neuer <em>Abend</em></p>'), 'Einstellungen ohne Startseiten-Felder, Kachel „Startseite“ mit Mini-Vorschau und Link');
    $r = http_anfrage($basis, 'GET', '/team/startseite?va=' . $vorjahr, $h, [], null, $k);
    pruefe(substr_count($r['text'], '<fieldset class="st-gruppe stapel" disabled>') === 4 && !str_contains($r['text'], '>Speichern</button>') && !str_contains($r['text'], 'data-editor='), 'abgeschlossene Veranstaltung: nur lesen');
    $r = http_anfrage($basis, 'POST', '/team/startseite', $h, [], ['csrf' => $c, 'start_unter' => 'Darf nicht'], $k);
    pruefe($r['status'] === 303 && va_mit($vorjahr, fn() => inhalt('start_unter')) !== 'Darf nicht', 'abgeschlossene Veranstaltung: Speichern abgewiesen');
    http_anfrage($basis, 'GET', '/team/startseite?va=' . $aktuell, $h, [], null, $k);

    abschnitt('HTTP: Vorschau (AP-18 Unterpunkt 6, T-314)');
    $vorher = inhalt('start_titel');
    $r = http_anfrage($basis, 'POST', '/team/startseite/vorschau', $h, [], ['csrf' => $c, 'start_titel' => "Vorschau <em>Titel</em>", 'start_kachel_1_titel' => '', 'start_kachel_1_text' => '', 'start_infos_titel' => 'Kacheln'] + $alleFelder, $k);
    $kopf = $r['kopf'];
    pruefe($r['status'] === 200 && str_contains($r['text'], '>Vorschau <em>Titel</em></h1>') && inhalt('start_titel') === $vorher, 'Vorschau zeigt die Formularwerte, speichert nichts');
    pruefe(($kopf['x-frame-options'] ?? '') === 'SAMEORIGIN' && str_contains($kopf['content-security-policy'] ?? '', "frame-ancestors 'self'") && ($kopf['cache-control'] ?? '') === 'no-store', 'nur in derselben Herkunft einbettbar, nicht zwischengespeichert');
    $hw = preg_match('/<body class="akzent-lila vorschau-seite" data-hinweise="([^"]*)">/', $r['text'], $m) === 1 ? json_decode(html_entity_decode($m[1], ENT_QUOTES), true) : null;
    pruefe(is_array($hw) && $hw['stufe'] === 'XL' && $hw['leer'] === [1, 3], 'Hinweise am body: Größenstufe, leere Kacheln');
    pruefe(!str_contains($r['text'], 'nav-team') && !str_contains($r['text'], 'person-menue') && str_contains($r['text'], 'href="/einloggen"'), 'Navigation wie für Besucherinnen und Besucher');
    $normal = http_anfrage($basis, 'GET', '/team/startseite', $h, [], null, $k)['kopf'];
    pruefe(($normal['x-frame-options'] ?? '') === 'DENY' && str_contains($normal['content-security-policy'] ?? '', "frame-ancestors 'none'"), 'übrige Seiten bleiben nicht einbettbar');
    $r = http_anfrage($basis, 'POST', '/team/startseite/vorschau', $h, [], ['start_titel' => 'Ohne CSRF'], $k);
    pruefe($r['status'] === 403 && !str_contains($r['text'], 'Ohne CSRF'), 'ohne CSRF 403');
    pruefe(http_anfrage($basis, 'GET', '/team/startseite/vorschau', $h, [], null, $k)['status'] === 303, 'GET: zurück zur Seite „Startseite“');
    $leer = "$tmp/kekse-ap18-gast.txt";
    $r = http_anfrage($basis, 'GET', '/', $h, [], null, $leer);
    $r = http_anfrage($basis, 'POST', '/team/startseite/vorschau', $h, [], ['csrf' => http_csrf($r['text']), 'start_titel' => 'Gast'], $leer);
    pruefe($r['status'] === 303 && str_contains($r['kopf']['location'] ?? '', '/einloggen') && !str_contains($r['text'], 'Gast'), 'ohne Team-Login: zum Einloggen, keine Vorschau');
    $lea = "$tmp/kekse-ap18-lea.txt";
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $lea);
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'lea@example.org', 'passwort' => BEISPIEL_PASSWORT], $lea);
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $lea);
    $r = http_anfrage($basis, 'POST', '/team/startseite/vorschau', $h, [], ['csrf' => http_csrf($r['text']), 'start_titel' => 'Teilnehmerin'], $lea);
    pruefe($r['status'] !== 200 && !str_contains($r['text'], 'Teilnehmerin'), 'als Teilnehmende: keine Vorschau (Status ' . $r['status'] . ', wie alle Team-Seiten)');

    abschnitt('HTTP: Einstellungen als Übersicht (AP-18 Unterpunkt 7, T-315)');
    $r = http_anfrage($basis, 'GET', '/team/einstellungen', $h, [], null, $k);
    pruefe(substr_count($r['text'], '<details class="einst-auf" name="einstellungen">') === count(VA_GRUPPEN) && str_contains($r['text'], '<dt>Wo</dt><dd>Hörsaal Testklinik</dd>') && str_contains($r['text'], '<span class="chip ok">Essen</span>'), 'je Gruppe eine Kachel (zu) mit Zusammenfassung');
    $gruppe = fn(string $g) => array_map('va_formularwert', array_combine($ks = array_keys(array_filter(VA_KATALOG, fn($d) => $d['gruppe'] === $g)), $ks));
    $audit = (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'einstellungen'");
    $r = http_anfrage($basis, 'POST', '/team/einstellungen', $h, [], ['csrf' => $c, 'gruppe' => 'nachruecken', 'nachrueck_frist_h' => '12', 'veranstaltung_ort' => 'Fremd'] + $gruppe('nachruecken'), $k);
    pruefe($r['status'] === 303 && str_ends_with($r['kopf']['location'] ?? '', '/team/einstellungen#nachruecken') && va('nachrueck_frist_h') === '12' && va('veranstaltung_ort') === 'Hörsaal Testklinik'
        && (int)db_wert("SELECT COUNT(*) FROM audit WHERE art = 'einstellungen'") === $audit + 1, 'Speichern einer Kachel ändert nur deren Werte (protokolliert)');
    $r = http_anfrage($basis, 'POST', '/team/einstellungen', $h, [], ['csrf' => $c, 'gruppe' => 'veranstaltung', 'kuerzel' => va_zeile()['kuerzel'], 'veranstaltung_ende' => '17:00'] + $gruppe('veranstaltung'), $k);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Nicht gespeichert') && preg_match('#id="veranstaltung"><details class="einst-auf" open>#', $r['text']) === 1 && substr_count($r['text'], ' open>') === 1
        && str_contains($r['text'], 'value="17:00"') && va('veranstaltung_ende') === '22:00', 'Fehler: nur diese Kachel offen, Eingabe bleibt, nichts gespeichert');
    $r = http_anfrage($basis, 'GET', '/team/einstellungen?va=' . $vorjahr, $h, [], null, $k);
    pruefe(!str_contains($r['text'], 'einst-auf') && str_contains($r['text'], 'class="kasten einst-kachel" id="veranstaltung"><h2'), 'abgeschlossene Veranstaltung: Kacheln ohne Bearbeiten');
    http_anfrage($basis, 'GET', '/team/einstellungen?va=' . $aktuell, $h, [], null, $k);

    abschnitt('HTTP: Ohne aktuelle Veranstaltung (AP-18 Unterpunkt 4, T-312)');
    $r = http_anfrage($basis, 'GET', '/', $h);
    pruefe(str_contains($r['text'], 'href="/#programm"'), 'mit aktueller Veranstaltung: „Programm“ in der Navigation');
    db_ausfuehren("UPDATE veranstaltung SET status = 'abgeschlossen' WHERE id = ?", [$aktuell]);
    $r = http_anfrage($basis, 'GET', '/', $h);
    pruefe(str_contains($r['text'], '<h1 class="titel titel-xl">Allgäu <em>Akut</em></h1>') && !str_contains($r['text'], 'href="/#programm"') && !str_contains($r['text'], 'id="programm"'), 'keine aktuelle: Titel = Dachmarke, kein „Programm“ in der Navigation');
}

/** Veranstaltungsreihen und Newsletter (AP-19, E-71): T-317, T-320, T-321, T-323 über HTTP. */
function http_bereich_ap19(array $u): void
{
    ['basis' => $basis, 'h' => $h, 'tmp' => $tmp] = $u;
    $f = http_frisch($u);
    $vAbend = (int)$f['bd']['abend']['id'];
    $vAkt = (int)va_aktuell_id();
    $b = http_team_einloggen($u, TEST_BETREIBER, TEST_PASSWORT)['keks'];

    abschnitt('HTTP: Dachmarke, Reihen, Einstellungen (AP-19, T-317, T-318)');
    $r = http_anfrage($basis, 'GET', '/team/daten', $h, [], null, $b);
    pruefe(str_contains($r['text'], '>Dachmarke<') && !str_contains($r['text'], 'Name der Reihe'), 'Betrieb: Einstellung heißt „Dachmarke“');
    $r = http_anfrage($basis, 'GET', '/team/veranstaltungen', $h, [], null, $b);
    $c = http_csrf($r['text']);
    pruefe(str_contains($r['text'], 'id="reihen"') && str_contains($r['text'], '<strong>Fortbildungsabend</strong>') && str_contains($r['text'], 'name="reihe"'), 'Veranstaltungen: Reihen und Auswahl der Reihe beim Anlegen');
    $r = http_anfrage($basis, 'POST', '/team/veranstaltungen', $h, [], ['csrf' => $c, 'aktion' => 'reihe', 'name' => 'Journal Club', 'kurz' => 'Journal Club', 'beschreibung' => 'Ein Paper, ein Abend.'], $b);
    $jc = array_column(va_reihen(), null, 'name')['Journal Club'] ?? null;
    pruefe($r['status'] === 303 && $jc !== null && nl_liste((int)$jc['liste_id'])['name'] === 'Journal Club', 'Reihe angelegt, mit Liste');
    $r = http_anfrage($basis, 'POST', '/team/veranstaltungen', $h, [], ['csrf' => $c, 'aktion' => 'anlegen', 'name' => 'Journal Club März', 'kuerzel' => 'jc-maerz', 'vorlage' => '', 'reihe' => (string)$jc['id']], $b);
    $vJc = (int)(va_per_kuerzel('jc-maerz')['id'] ?? 0);
    pruefe($r['status'] === 303 && va_reihe_von($vJc)['id'] === $jc['id'] && va_von($vJc, 'veranstaltung_kurz') === 'Journal Club', 'neue Veranstaltung mit Reihe, Kurzname vorbelegt');
    $r = http_anfrage($basis, 'GET', '/team/einstellungen?va=' . $vAkt, $h, [], null, $b);
    pruefe(str_contains($r['text'], 'id="reihe"') && str_contains($r['text'], '<dt>Reihe</dt><dd>Wintersymposium</dd>') && str_contains($r['text'], 'name="zielgruppe"') && str_contains($r['text'], 'name="berufsgruppen_interesse[NotfallsanitäterIn]"'), 'Einstellungen: Kachel „Reihe & Zielgruppe“, Vorauswahl je Berufsgruppe');
    $r = http_anfrage($basis, 'POST', '/team/einstellungen', $h, [], ['csrf' => $c, 'gruppe' => 'reihe', 'reihe_id' => '0', 'zielgruppe' => 'klinik'], $b);
    pruefe($r['status'] === 303 && va_reihe_von($vAkt) === null && va('zielgruppe') === 'klinik', 'BetreiberIn ändert Reihe und Zielgruppe');
    http_anfrage($basis, 'POST', '/team/einstellungen', $h, [], ['csrf' => $c, 'gruppe' => 'reihe', 'reihe_id' => (string)array_column(va_reihen(), 'id', 'name')['Wintersymposium'], 'zielgruppe' => 'beide'], $b);

    abschnitt('HTTP: Admin-Rechte je Reihe (AP-19 Unterpunkt 4, T-320)');
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'name="reihe[]"') && str_contains($r['text'], 'Reihe <strong>Fortbildungsabend</strong>'), 'Team-Konten: Reihen wählbar');
    $rita = beispieldaten_admin_reihe();
    $k = http_team_einloggen($u, 'rita.reihe@example.org', BEISPIEL_PASSWORT_ADMIN)['keks'];
    pruefe(http_anfrage($basis, 'GET', '/team/personen?va=' . $vAbend, $h, [], null, $k)['status'] === 200 && http_anfrage($basis, 'GET', '/team/personen?va=' . $vAkt, $h, [], null, $k)['status'] === 403, 'Admin mit Reihe: eigene Veranstaltung ja, fremde 403');
    $neu = va_anlegen('fortbildungsabend-2', 'Fortbildungsabend Februar', $vAbend, null);
    pruefe(http_anfrage($basis, 'GET', '/team/personen?va=' . $neu['id'], $h, [], null, $k)['status'] === 200, 'neue Veranstaltung der Reihe ohne neue Freigabe');
    $r = http_anfrage($basis, 'GET', '/team/einstellungen?va=' . $neu['id'], $h, [], null, $k);
    pruefe(preg_match('#<select id="reihe_id" name="reihe_id"[^>]* disabled#', $r['text']) === 1, 'Admin: Reihe nur lesen');
    http_anfrage($basis, 'POST', '/team/einstellungen', $h, [], ['csrf' => http_csrf($r['text']), 'gruppe' => 'reihe', 'reihe_id' => '0', 'zielgruppe' => 'klinik'], $k);
    pruefe(va_reihe_von($neu['id']) !== null && va_von($neu['id'], 'zielgruppe') === 'klinik', 'Admin ändert die Zielgruppe, die Reihe nicht');
    $r = http_anfrage($basis, 'GET', '/team/mailings?neu=1', $h, [], null, $k);
    $abendListe = (int)va_reihe(beispieldaten_reihe_abend())['liste_id'];
    $dm = (int)nl_liste_dachmarke()['id'];
    pruefe(str_contains($r['text'], 'name="listen[]" value="' . $abendListe . '"') && !str_contains($r['text'], 'name="listen[]" value="' . $dm . '"') && !str_contains($r['text'], 'name="nl_ziel"'), 'Mailings: Admin wählt nur den Newsletter ihrer Reihe (O-52)');
    $r = http_anfrage($basis, 'POST', '/team/mailings', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'speichern', 'art' => 'newsletter', 'listen' => [$dm], 'betreff' => 'Darf nicht', 'html' => '<p>x</p>', 'postfach' => 'newsletter'], $k);
    pruefe(db_wert("SELECT COUNT(*) FROM rundmail WHERE betreff = 'Darf nicht'") == 0, 'Allgäu Akut für Admins abgewiesen');
    $nlm = beispieldaten_newsletter_mailing();
    if ($nlm !== null) {
        pruefe(http_anfrage($basis, 'GET', '/team/mailings/bild?id=' . $nlm['bild'], $h, [], null, $k)['status'] === 404, 'S-5: Bild des Newsletters einer fremden Reihe → 404');
    }

    abschnitt('HTTP: Newsletter bestellen mit Interessen (AP-19 Unterpunkt 5, T-321)');
    $g = "$tmp/kekse-ap19-gast.txt";
    $r = http_anfrage($basis, 'GET', '/newsletter', $h, [], null, $g);
    pruefe(str_contains($r['text'], '<strong>Wintersymposium</strong>') && str_contains($r['text'], '<strong>Fortbildungsabend</strong>') && str_contains($r['text'], '<strong>Journal Club</strong>') && str_contains($r['text'], 'name="nl_interesse[]" value="praeklinik"'), 'Formular zeigt alle Reihen-Newsletter und Allgäu Akut mit Interessen');
    preg_match('/name="pow_aufgabe" value="([0-9a-f]{64})"/', $r['text'], $m);
    $spam = ['zeit' => spam_stempel(time() - 10), 'pow_aufgabe' => $m[1] ?? '', 'pow_loesung' => http_pow($m[1] ?? ''), 'website' => '', 'einwilligung' => '1'];
    $r = http_anfrage($basis, 'POST', '/newsletter', $h, [], ['csrf' => http_csrf($r['text']), 'email' => 'ina@example.org', 'listen' => [$dm]] + $spam, $g);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Klinik, Präklinik oder beides') && nl_person_per_email('ina@example.org') === null, 'Allgäu Akut ohne Interesse: Fehler, nichts angefragt');

    abschnitt('HTTP: Anmeldung mit zwei Häkchen (AP-19 Unterpunkt 7, T-323)');
    $a = "$tmp/kekse-ap19-anm.txt";
    $r = http_anfrage($basis, 'GET', '/anmelden', $h, [], null, $a);
    pruefe(str_contains($r['text'], 'Newsletter „Wintersymposium“: Infos zum nächsten Wintersymposium.') && str_contains($r['text'], 'name="nl_dachmarke"') && str_contains($r['text'], 'data-vorauswahl=') && !str_contains($r['text'], 'bei_anmeldung'), 'Formular: Häkchen Reihe und Allgäu Akut mit Vorauswahl');
    $r = http_anmelden($basis, $h, $a, ['vorname' => 'Nils', 'nachname' => 'Notsan', 'email' => 'nils@example.org', 'berufsgruppe' => 'NotfallsanitäterIn', 'beruf_sonstige' => '', 'institution' => 'Rettungswache', 'efn' => '', 'essen' => 'egal',
        'newsletter' => '1', 'nl_dachmarke' => '1']);
    $an = db_zeile("SELECT * FROM anmeldung WHERE email = 'nils@example.org'");
    pruefe($an !== null && (int)$an['newsletter'] === 1 && $an['nl_interessen'] === 'praeklinik', 'ohne gewähltes Interesse: Vorauswahl der Berufsgruppe (Präklinik, O-50)');
    $mail = http_mail_an($tmp, 'nils@example.org');
    preg_match('~(/link\?t=[A-Za-z0-9_-]{43})~', $mail, $lm);
    $r = http_anfrage($basis, 'GET', $lm[1] ?? '/link?t=x', $h, [], null, $a);
    http_anfrage($basis, 'POST', '/link', $h, [], ['csrf' => http_csrf($r['text']), 't' => substr($lm[1] ?? '/link?t=x', 8)], $a);
    $nm = http_mail_an($tmp, 'nils@example.org', 20, '/newsletter/bestaetigen');
    $np = nl_person_per_email('nils@example.org');
    $abos = $np !== null ? nl_abos((int)$np['id']) : [];
    pruefe($nm !== '' && str_contains($nm, 'Wintersymposium, Allgäu Akut (Präklinik)') && count($abos) === 2 && array_unique(array_values($abos)) === ['offen'], 'nach der Bestätigung: eine Mail für beide Listen (O-54)');
    preg_match('~/newsletter/bestaetigen\?t=([A-Za-z0-9_-]{43})~', $nm, $bm);
    $r = http_anfrage($basis, 'GET', '/newsletter/bestaetigen?t=' . ($bm[1] ?? ''), $h, [], null, $a);
    http_anfrage($basis, 'POST', '/newsletter/bestaetigen', $h, [], ['csrf' => http_csrf($r['text']), 't' => $bm[1] ?? ''], $a);
    pruefe(nl_abos((int)$np['id']) === array_fill_keys(array_keys($abos), 'bestaetigt') && nl_interessen_von((int)$np['id']) === 'praeklinik', 'bestätigt: beide Abos, Interesse Präklinik');
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $a);
    pruefe(str_contains($r['text'], 'Newsletter der Reihe, Allgäu Akut (Präklinik)'), 'Meine Anmeldung zeigt den Newsletter-Wunsch');
}
