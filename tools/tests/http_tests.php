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
        pruefe(db_wert('SELECT COUNT(*) FROM team_konto') == 0, 'kein Konto angelegt');
        $r = http_anfrage($basis, 'POST', '/install', $h, [], ['csrf' => $csrf] + $form, $keks);
        pruefe($r['status'] === 200 && str_contains($r['text'], 'Installation abgeschlossen'), 'Installation abgeschlossen');
        $konto = db_zeile('SELECT email, rolle, passwort_hash FROM team_konto');
        pruefe($konto !== null && $konto['rolle'] === 'betreiber' && $konto['email'] === 'betrieb@example.org', 'erster Betreiber angelegt');
        pruefe($konto !== null && password_verify('ein-langes-passwort', $konto['passwort_hash']), 'Passwort nur als Hash');
        pruefe(db_wert("SELECT haupt FROM host WHERE name = 'ws.test'") == 1, 'Haupt-Host eingetragen');
        pruefe(db_wert("SELECT haupt FROM host WHERE name = 'www.ws.test'") == 0, 'weiterer Host eingetragen');
        pruefe(is_file("$tmp/var/install.lock"), 'install.lock angelegt');
        pruefe(db_wert("SELECT COUNT(*) FROM audit WHERE art = 'installation'") == 1, 'Audit-Eintrag geschrieben');
        $r = http_anfrage($basis, 'GET', '/install', $h);
        pruefe($r['status'] === 404, 'Installer danach gesperrt (404)');
        $r = http_anfrage($basis, 'POST', '/install', $h, [], ['csrf' => $csrf] + $form, $keks);
        pruefe($r['status'] === 404 && db_wert('SELECT COUNT(*) FROM team_konto') == 1, 'zweite Installation per POST unmöglich');

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
        pruefe($r['status'] === 200, 'Favicon ausgeliefert');
        foreach (['/impressum' => 'Impressum', '/datenschutz' => 'Datenschutz'] as $pf => $titel) {
            $r = http_anfrage($basis, 'GET', $pf, $h);
            pruefe($r['status'] === 200 && str_contains($r['text'], $titel) && str_contains($r['text'], 'wird gerade erstellt'), "$titel erreichbar (noch leer)");
        }
        inhalt_setzen('impressum', "## Angaben\nPhilipp Chadid\n\nKontakt: [admin@winter-notfallsymposium.de](mailto:admin@winter-notfallsymposium.de)");
        $r = http_anfrage($basis, 'GET', '/impressum', $h);
        pruefe(str_contains($r['text'], '<h2>Angaben</h2>') && str_contains($r['text'], 'href="mailto:admin@winter-notfallsymposium.de"'), 'Impressum zeigt gepflegten Text');
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
}
