<?php
declare(strict_types=1);

/*
 * HTTP-Durchlauf gegen den eingebauten PHP-Server: Installer, Kopfzeilen,
 * Hosts, Health, Jobs, Wartung. Wird von lauf.php aufgerufen.
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($formular));
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

function base32_dekodieren(string $b32): string
{
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper($b32)) as $z) {
        $bits .= str_pad(decbin(strpos($abc, $z)), 5, '0', STR_PAD_LEFT);
    }
    $roh = '';
    foreach (str_split($bits, 8) as $b) {
        if (strlen($b) === 8) {
            $roh .= chr(bindec($b));
        }
    }
    return $roh;
}

/** Geheimnis aus der Einrichtungsseite (otpauth-Adresse im QR-Code). */
function http_totp_geheimnis(string $html): ?string
{
    return preg_match('/secret=([A-Z2-7]+)/', $html, $m) === 1 ? base32_dekodieren($m[1]) : null;
}

function team_login_tests(string $basis, string $h, string $tmp, string $wurzel): void
{
    $b = "$tmp/kekse-betreiber.txt";
    $pw = 'ein-langes-passwort';

    abschnitt('HTTP: Team-Login (AP-3 Schritt 1)');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $b);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/einloggen', 'Team-Bereich ohne Login → Einloggen');
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'name="passwort"'), 'Login-Formular');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'passwort', 'email' => 'betrieb@example.org', 'passwort' => 'falsch-falsch'], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'oder Passwort stimmt nicht'), 'falsches Passwort abgelehnt');
    $r2 = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'passwort', 'email' => 'niemand@example.org', 'passwort' => 'falsch-falsch'], $b);
    pruefe(str_contains($r2['text'], 'oder Passwort stimmt nicht'), 'unbekannte Adresse: gleiche Meldung (R-03)');
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'passwort', 'email' => 'Betrieb@Example.org', 'passwort' => $pw], $b);
    pruefe($r['status'] === 303, 'richtiges Passwort → weiter zum zweiten Faktor');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $b);
    pruefe($r['status'] === 303, 'nur Passwort reicht nicht für den Team-Bereich');
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $b);
    $roh = http_totp_geheimnis($r['text']);
    pruefe($r['status'] === 200 && $roh !== null && str_contains($r['text'], 'data-qr="otpauth://totp/'), 'Einrichtung mit QR-Code und Schlüssel');
    pruefe(str_contains($r['text'], '/assets/vendor/qrcode.js') && str_contains($r['text'], '/assets/qr.js'), 'QR-Skripte lokal eingebunden');
    $r2 = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $b);
    pruefe(http_totp_geheimnis($r2['text']) === $roh, 'Neuladen behält das Geheimnis');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'einrichten', 'code' => '000000'], $b);
    pruefe(str_contains($r['text'], 'Der Code stimmt nicht'), 'falscher Einrichtungscode abgelehnt');
    $code1 = totp_code((string)$roh, time());
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'einrichten', 'code' => $code1], $b);
    preg_match_all('/<li class="mono">([A-Z2-9]{4} [A-Z2-9]{4})<\/li>/', $r['text'], $m);
    $codes = $m[1];
    pruefe($r['status'] === 200 && count($codes) === 10, 'eingerichtet, zehn Wiederherstellungscodes angezeigt');
    pruefe(db_wert("SELECT COUNT(*) FROM konto_code WHERE hash LIKE '$%'") == 10 && !str_contains((string)db_wert('SELECT totp_geheimnis FROM konto'), totp_base32((string)$roh)), 'Codes nur als Hash, Geheimnis versiegelt');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Erste Betreiberin') && str_contains($r['text'], 'Ausloggen'), 'Team-Bereich mit Name und Ausloggen (E-40)');
    pruefe(str_contains($r['text'], 'nur einen aktiven Betreiber'), 'Hinweis auf zweiten Betreiber');
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Neues Team-Konto'), 'Team-Konten für Betreiber');

    abschnitt('HTTP: Team-Konto anlegen und einrichten');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $csrf, 'aktion' => 'anlegen', 'name' => 'Anna Admin', 'email' => 'anna@example.org', 'rolle' => 'admin'], $b);
    pruefe($r['status'] === 303, 'Konto angelegt');
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $b);
    $link = preg_match('#/einrichten\?t=([A-Za-z0-9_-]+)#', $r['text'], $m) === 1 ? $m[1] : '';
    pruefe($link !== '' && str_contains($r['text'], 'Einrichtung offen'), 'Einrichtungslink einmal angezeigt');
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $b);
    pruefe(!str_contains($r['text'], '/einrichten?t='), 'Link beim Neuladen nicht mehr sichtbar');
    $a = "$tmp/kekse-admin.txt";
    $r = http_anfrage($basis, 'GET', '/einrichten?t=falsch' . str_repeat('x', 40), $h, [], null, $a);
    pruefe($r['status'] === 404, 'falscher Link abgelehnt');
    $r = http_anfrage($basis, 'GET', '/einrichten?t=' . $link, $h, [], null, $a);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Anna Admin'), 'Einrichtungsseite');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/einrichten', $h, [], ['csrf' => $csrf, 't' => $link, 'passwort' => 'kurz', 'passwort2' => 'kurz'], $a);
    pruefe(str_contains($r['text'], 'mindestens'), 'zu kurzes Passwort abgelehnt');
    $r = http_anfrage($basis, 'POST', '/einrichten', $h, [], ['csrf' => $csrf, 't' => $link, 'passwort' => 'anna-ihr-passwort', 'passwort2' => 'anna-ihr-passwort'], $a);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/einloggen', 'Passwort gesetzt → zweiten Faktor einrichten');
    $r = http_anfrage($basis, 'GET', '/einrichten?t=' . $link, $h, [], null, "$tmp/kekse-x.txt");
    pruefe($r['status'] === 404, 'Link gilt nur einmal');
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $a);
    $rohA = http_totp_geheimnis($r['text']);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'einrichten', 'code' => totp_code((string)$rohA, time())], $a);
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $a);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Anna Admin'), 'Admin eingeloggt');
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $a);
    pruefe($r['status'] === 403, 'Admin sieht keine Team-Konten (E-11)');
    pruefe(!str_contains(http_anfrage($basis, 'GET', '/team', $h, [], null, $a)['text'], 'href="/team/konten"'), 'Reiter Team-Konten für Admin ausgeblendet');

    abschnitt('HTTP: Rollenwechsel, Sperre, Sitzungen');
    $annaId = (int)db_wert("SELECT id FROM konto WHERE email = 'anna@example.org'");
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $b);
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $csrf, 'aktion' => 'rolle', 'id' => $annaId, 'rolle' => 'betreiber', 'code' => '000000'], $b);
    pruefe(str_contains($r['text'], 'Die Rolle wurde nicht geändert') && db_wert('SELECT rolle FROM konto WHERE id = ?', [$annaId]) === 'admin', 'Rollenwechsel ohne gültigen eigenen Code abgelehnt');
    $r = http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $csrf, 'aktion' => 'rolle', 'id' => $annaId, 'rolle' => 'betreiber', 'code' => totp_code((string)$roh, time() + 30)], $b);
    pruefe($r['status'] === 303 && db_wert('SELECT rolle FROM konto WHERE id = ?', [$annaId]) === 'betreiber', 'Rollenwechsel mit eigenem Code (E-41)');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $a);
    pruefe($r['status'] === 303, 'Rollenwechsel beendet die Sitzung des Kontos');
    $r = http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $csrf, 'aktion' => 'sperren', 'id' => $annaId], $b);
    pruefe(db_wert('SELECT aktiv FROM konto WHERE id = ?', [$annaId]) == 0, 'Konto gesperrt');
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $a);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'anna@example.org', 'passwort' => 'anna-ihr-passwort'], $a);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'stimmt nicht'), 'gesperrtes Konto kommt nicht rein');
    $ich = (int)db_wert("SELECT id FROM konto WHERE email = 'betrieb@example.org'");
    $r = http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $csrf, 'aktion' => 'sperren', 'id' => $ich], $b);
    pruefe(db_wert('SELECT aktiv FROM konto WHERE id = ?', [$ich]) == 1 && str_contains($r['text'], 'eigenes Konto'), 'eigenes Konto nicht sperrbar');

    abschnitt('HTTP: Ausloggen, Wiederholungsschutz, Wiederherstellungscode');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $b);
    $r = http_anfrage($basis, 'POST', '/ausloggen', $h, [], ['csrf' => http_csrf($r['text'])], $b);
    pruefe($r['status'] === 303, 'ausgeloggt');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $b);
    pruefe($r['status'] === 303, 'danach kein Zugriff');
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $b);
    $csrf = http_csrf($r['text']);
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'passwort', 'email' => 'betrieb@example.org', 'passwort' => $pw], $b);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Authenticator-App ein') && !str_contains($r['text'], 'data-qr'), 'zweites Login fragt nur den Code');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'code', 'code' => totp_code((string)$roh, time() + 30)], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Der Code stimmt nicht'), 'schon benutzter Code gilt nicht noch einmal');
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'code', 'code' => strtolower($codes[0])], $b);
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

    abschnitt('Notweg auf der Kommandozeile');
    $aus = [];
    exec('WS_CONFIG=' . escapeshellarg("$tmp/config.php") . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$wurzel/server/cli/konto.php") . ' zweitfaktor anna@example.org 2>&1', $aus, $code);
    pruefe($code === 0 && db_wert('SELECT totp_seit FROM konto WHERE id = ?', [$annaId]) === null, 'zweiten Faktor zurückgesetzt');
    $aus = [];
    exec('WS_CONFIG=' . escapeshellarg("$tmp/config.php") . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$wurzel/server/cli/konto.php") . ' link anna@example.org 2>&1', $aus, $code);
    pruefe($code === 0 && str_contains(implode('', $aus), '/einrichten?t='), 'neuen Link erzeugt');
}

function http_tests(string $wurzel, string $tmp): void
{
    $port = 18000 + random_int(0, 999);
    $basis = "http://127.0.0.1:$port";
    $befehl = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$wurzel/server/public", "$wurzel/server/public/index.php"];
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
    $keks = "$tmp/kekse.txt";
    $h = 'ws.test';

    try {
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
            'weitere_hosts' => "www.ws.test",
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
        pruefe($r['status'] === 200 && ($j['status'] ?? '') === 'ok', 'mit Token 200 und JSON');
        pruefe(($j['schema']['ist'] ?? -1) === ($j['schema']['soll'] ?? -2), 'Schema aktuell');
        pruefe(str_starts_with($r['kopf']['content-type'] ?? '', 'application/json'), 'Content-Type JSON');

        abschnitt('HTTP: Jobs per URL (T-105)');
        $r = http_anfrage($basis, 'GET', '/jobs?token=falsch', $h);
        pruefe($r['status'] === 404, 'falsches Token 404');
        $r = http_anfrage($basis, 'GET', '/jobs?token=' . str_repeat('j', 40), $h);
        $j = json_decode($r['text'], true);
        pruefe($r['status'] === 200 && ($j['status'] ?? '') === 'ok', 'Lauf ausgeführt');
        pruefe(einstellung('jobs_letzter_ausloeser') === 'url', 'Auslöser url gespeichert');

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
        pruefe(str_contains($html, '<em>Notfallmedizin</em>'), 'Titel aus pflegbarem Text (mit Akzent)');
        pruefe(str_contains($html, 'Für wen') && str_contains($html, 'id="programm"'), 'Infokästen und Programmabschnitt vorhanden');
        pruefe(str_contains($html, '<dl class="eckdaten">') && str_contains($html, '240 / 240') && str_contains($html, 'Mi 20.01.2027'), 'Eckdaten-Platzhalter mit freien Plätzen');
        pruefe(str_contains($html, 'Beispielprogramm') && str_contains($html, 'class="programm"') && str_contains($html, 'Tigerente'), 'Beispielprogramm als Platzhalter sichtbar und gekennzeichnet');
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
        inhalt_setzen('impressum', "## Angaben\nPhilipp Chadid\n\nKontakt: [admin@winter-notfallsymposium.de](mailto:admin@winter-notfallsymposium.de)");
        $r = http_anfrage($basis, 'GET', '/impressum', $h);
        pruefe(str_contains($r['text'], '<h2>Angaben</h2>') && str_contains($r['text'], 'href="mailto:admin@winter-notfallsymposium.de"'), 'Impressum zeigt gepflegten Text');

        team_login_tests($basis, $h, $tmp, $wurzel);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
}
