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

/** Programm pflegen, veröffentlichen, anzeigen (AP-3 Schritt 3); $b = Kekse einer eingeloggten Betreiberin. */
function programm_http_tests(string $basis, string $h, string $tmp, string $b): void
{
    abschnitt('HTTP: Programm pflegen und veröffentlichen (AP-3 Schritt 3)');
    $r = http_anfrage($basis, 'GET', '/team/programm', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Noch keine Programmpunkte'), 'Programm-Reiter, noch leer');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'GET', '/team/programm?neu=vortrag', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'value="VT 01"'), 'Kürzel-Vorschlag VT 01');
    // Dozentin anlegen
    $r = http_anfrage($basis, 'POST', '/team/dozierende', $h, [], ['csrf' => $csrf, 'aktion' => 'speichern', 'name' => 'Dr. Erika Test', 'funktion' => 'Oberärztin', 'kurzbio' => 'Kurz.'], $b);
    $dozId = (int)db_wert("SELECT id FROM dozent WHERE name = 'Dr. Erika Test'");
    pruefe($r['status'] === 303 && $dozId > 0, 'Dozentin angelegt');
    // Fehlerfall: Ende vor Beginn
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'speichern', 'typ' => 'vortrag', 'kuerzel' => 'VT 01', 'titel' => 'Sepsis', 'beginn' => '18:30', 'ende' => '18:00'], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Das Ende muss nach dem Beginn liegen') && str_contains($r['text'], 'value="Sepsis"'), 'Fehler am Feld, Eingabe bleibt');
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'speichern', 'typ' => 'vortrag', 'kuerzel' => 'VT 01', 'titel' => 'Sepsis <b>jetzt</b>', 'beginn' => '18:15', 'ende' => '18:45', 'ort' => 'Hörsaal', 'dozenten' => [$dozId]], $b);
    pruefe($r['status'] === 303, 'Vortrag gespeichert');
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'speichern', 'typ' => 'block', 'titel' => 'Workshops', 'beginn' => '19:00', 'ende' => '21:00'], $b);
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'speichern', 'typ' => 'workshop', 'kuerzel' => 'WS 01', 'titel' => 'Atemweg', 'ort' => 'Lab 1', 'bring' => 'Stethoskop', 'notiz' => 'Brille mit',
        't_id' => [0, 0], 't_beginn' => ['19:00', '20:00'], 't_ende' => ['19:45', '20:45'], 't_plaetze' => ['12', '10']], $b);
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'speichern', 'typ' => 'station', 'titel' => 'Reanimation', 'ort' => 'Lab 1', 'beginn' => '19:00', 'ende' => '21:00'], $b);
    pruefe(db_wert('SELECT COUNT(*) FROM programmpunkt') == 4 && db_wert('SELECT COUNT(*) FROM workshop_termin') == 2, 'Block, Workshop mit zwei Terminen, Station gespeichert');
    $r = http_anfrage($basis, 'GET', '/team/programm', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Gespeichert, aber nicht veröffentlicht') && substr_count($r['text'], '<span class="st ges">nicht veröffentlicht</span>') >= 4, 'Banner und Markierung „nicht veröffentlicht“');
    pruefe(str_contains($r['text'], 'Lab 1 ist 19:00–19:45 doppelt belegt'), 'Hinweis: Raum doppelt belegt');
    pruefe(preg_match('#<ol class="pg-kinder">.*WS 01.*Reanimation#s', $r['text']) === 1, 'Termine und Station hängen unter dem Block');
    $r = http_anfrage($basis, 'GET', '/', $h);
    pruefe(!str_contains($r['text'], 'Sepsis') && str_contains($r['text'], 'sobald es veröffentlicht ist'), 'Startseite noch ohne Programm (nur gespeichert)');
    $r = http_anfrage($basis, 'GET', '/team/programm/vorschau', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Vorschau') && str_contains($r['text'], 'Atemweg'), 'Vorschau zeigt Arbeitsstand');
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'veroeffentlichen'], $b);
    pruefe($r['status'] === 303, 'veröffentlicht');
    $r = http_anfrage($basis, 'GET', '/', $h);
    $html = $r['text'];
    pruefe(str_contains($html, 'Sepsis &lt;b&gt;jetzt&lt;/b&gt;') && !str_contains($html, '<b>jetzt</b>'), 'Startseite zeigt Programm, Titel maskiert');
    pruefe(str_contains($html, 'data-dialog="pp-') && str_contains($html, '<dialog class="popup"') && str_contains($html, 'Dr. Erika Test'), 'Popups mit Dozentin');
    pruefe(str_contains($html, 'class="gantt-wrap"') && str_contains($html, 'class="ws-mobil"') && str_contains($html, '12 frei'), 'Workshop-Block: Raster und Liste, freie Plätze');
    pruefe(str_contains($html, 'Brille mit') && str_contains($html, 'Stethoskop'), 'Workshop-Popup mit Bring und Notiz');
    pruefe(str_contains($html, '/assets/programm.js'), 'Skript für Popups eingebunden');
    preg_match_all('/(?:src|href)="(https?:[^"]+)"/', $html, $m);
    pruefe(array_filter($m[1], fn($u) => !str_starts_with($u, 'https://github.com/gen-em/')) === [], 'Programm ohne externe Ressourcen');
    $r = http_anfrage($basis, 'GET', '/team/programm', $h, [], null, $b);
    pruefe(!str_contains($r['text'], 'Gespeichert, aber nicht veröffentlicht') && str_contains($r['text'], 'Alles veröffentlicht'), 'nach dem Veröffentlichen kein Banner');
    // Ändern, verschieben, verwerfen
    $vtId = (int)db_wert("SELECT id FROM programmpunkt WHERE typ = 'vortrag'");
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'verschieben', 'art' => 'punkt', 'id' => $vtId, 'beginn' => '18:30'], $b);
    pruefe($r['status'] === 303 && db_wert('SELECT beginn FROM programmpunkt WHERE id = ?', [$vtId]) === '18:30:00' && db_wert('SELECT ende FROM programmpunkt WHERE id = ?', [$vtId]) === '19:00:00', 'Verschieben: neuer Beginn, Dauer bleibt');
    $r = http_anfrage($basis, 'GET', '/team/programm', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Neue Uhrzeit vorgeschlagen: 18:30–19:00'), 'Meldung nennt neue Uhrzeit von–bis');
    pruefe(str_contains($r['text'], '<span class="st ges">nicht veröffentlicht</span>') && str_contains($r['text'], 'Gespeichert, aber nicht veröffentlicht'), 'geändert markiert');
    $blockId = (int)db_wert("SELECT id FROM programmpunkt WHERE typ = 'block'");
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'verschieben', 'art' => 'punkt', 'id' => $blockId, 'beginn' => '19:30'], $b);
    pruefe(db_wert("SELECT MIN(beginn) FROM workshop_termin") === '19:30:00' && db_wert("SELECT beginn FROM programmpunkt WHERE typ = 'station'") === '19:30:00', 'Block verschieben nimmt Termine und Station mit');
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'verwerfen'], $b);
    pruefe(db_wert('SELECT beginn FROM programmpunkt WHERE id = ?', [$vtId]) === '18:15:00' && db_wert("SELECT MIN(beginn) FROM workshop_termin") === '19:00:00', 'Verwerfen stellt den veröffentlichten Stand her');
    pruefe(programm_status()['offen'] === false, 'danach nichts offen');
    // Löschen ist erst nach dem Veröffentlichen weg
    $stId = (int)db_wert("SELECT id FROM programmpunkt WHERE typ = 'station'");
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'loeschen', 'id' => $stId], $b);
    pruefe(str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], 'Reanimation'), 'gelöscht, aber bis zum Veröffentlichen noch auf der Startseite');
    $r = http_anfrage($basis, 'GET', '/team/programm', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Reanimation (gelöscht)'), 'Banner nennt gelöschten Punkt');
    // Veröffentlichen verlangt Uhrzeiten
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'speichern', 'typ' => 'pause', 'titel' => 'Pause ohne Zeit'], $b);
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'veroeffentlichen'], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Nicht veröffentlicht') && str_contains($r['text'], 'Pause ohne Zeit'), 'Punkt ohne Uhrzeit verhindert das Veröffentlichen');
    $r = http_anfrage($basis, 'GET', '/team/programm', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'pg-neu-punkt'), 'Punkt ohne Uhrzeit steht oben als neu');
    $pauseId = (int)db_wert("SELECT id FROM programmpunkt WHERE titel = 'Pause ohne Zeit'");
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'verschieben', 'art' => 'punkt', 'id' => $pauseId, 'beginn' => '18:45'], $b);
    pruefe(db_wert('SELECT ende FROM programmpunkt WHERE id = ?', [$pauseId]) === '19:15:00', 'Ziehen gibt einem neuen Punkt 30 Minuten');
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'veroeffentlichen'], $b);
    pruefe($r['status'] === 303 && !str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], 'Reanimation'), 'nach dem Veröffentlichen ist der gelöschte Punkt weg');
    // Foto (braucht die PHP-Erweiterung GD; ohne sie ist der Upload ausgeblendet)
    if (!foto_moeglich()) {
        echo "  --    Foto-Prüfungen übersprungen: GD fehlt\n";
    } else {
    $png = "$tmp/foto.png";
    $bild = imagecreatetruecolor(800, 600);
    imagefill($bild, 0, 0, imagecolorallocate($bild, 180, 140, 255));
    imagepng($bild, $png);
    $ch = curl_init("$basis/team/dozierende");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Host: ' . $h], CURLOPT_COOKIEFILE => $b, CURLOPT_COOKIEJAR => $b,
        CURLOPT_POSTFIELDS => ['csrf' => $csrf, 'aktion' => 'speichern', 'id' => $dozId, 'name' => 'Dr. Erika Test', 'funktion' => 'Oberärztin', 'kurzbio' => 'Kurz.', 'foto' => new CURLFile($png, 'image/png', 'foto.png')]]);
    curl_exec($ch);
    $foto = (string)db_wert('SELECT foto FROM dozent WHERE id = ?', [$dozId]);
    pruefe(preg_match('/^dozent-\d+-[0-9a-f]{12}\.jpg$/', $foto) === 1, 'Foto hochgeladen und als JPEG gespeichert');
    $r = http_anfrage($basis, 'GET', '/bild/' . $foto, $h);
    $info = @getimagesizefromstring($r['text']);
    pruefe($r['status'] === 200 && $info !== false && $info[0] === FOTO_KANTE && $info[1] === FOTO_KANTE, 'Foto quadratisch verkleinert ausgeliefert');
    pruefe(http_anfrage($basis, 'GET', '/bild/../config.php', $h)['status'] === 404 && http_anfrage($basis, 'GET', '/bild/dozent-1-zzz.jpg', $h)['status'] === 404, 'Bild-Adresse nimmt nur echte Fotonamen');
    $r = http_anfrage($basis, 'GET', '/team/programm', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Gespeichert, aber nicht veröffentlicht'), 'Foto-Änderung zählt als unveröffentlicht');
    http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'veroeffentlichen'], $b);
    pruefe(str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], '/bild/' . $foto), 'Foto im Popup nach dem Veröffentlichen');
    }
    // Ohne Login
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => $csrf, 'aktion' => 'veroeffentlichen'], "$tmp/kekse-niemand.txt");
    pruefe($r['status'] === 303 || $r['status'] === 403, 'ohne Login nichts änderbar');
}

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

/** Anmeldung, Einmal-Links, Deine Anmeldung, Team-Liste (AP-4). */
function anmeldung_http_tests(string $basis, string $h, string $tmp, string $b): void
{
    abschnitt('HTTP: Anmeldung (AP-4)');
    einstellung_setzen('anmeldung_start', gmdate('Y-m-d H:i:s', time() + 86400));
    $r = http_anfrage($basis, 'GET', '/anmelden', $h);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Die Anmeldung öffnet am') && !str_contains($r['text'], 'name="vorname"'), 'vor dem Start: Hinweis statt Formular');
    pruefe(!str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], 'Jetzt anmelden'), 'Startseite ohne Anmeldeknopf vor dem Start');
    einstellung_setzen('anmeldung_start', gmdate('Y-m-d H:i:s', time() - 60));
    $k1 = "$tmp/kekse-tn1.txt";
    $r = http_anfrage($basis, 'GET', '/anmelden', $h, [], null, $k1);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'name="pow_aufgabe"') && str_contains($r['text'], 'name="website"') && str_contains($r['text'], 'name="ws['), 'Formular mit Spamschutz und Workshop-Wahl');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], 'Jetzt anmelden'), 'Startseite: „Jetzt anmelden“ (E-42)');
    $r = http_anfrage($basis, 'POST', '/anmelden', $h, [], ['vorname' => 'X'], $k1);
    pruefe($r['status'] === 403, 'ohne CSRF-Token abgelehnt');
    $wid = (int)db_wert("SELECT id FROM programmpunkt WHERE typ = 'workshop'");
    $t1 = (int)db_wert("SELECT id FROM workshop_termin WHERE beginn = '19:00:00'");
    $t2 = (int)db_wert("SELECT id FROM workshop_termin WHERE beginn = '20:00:00'");
    $person = ['vorname' => 'Lena', 'nachname' => 'Test', 'email' => 'Lena.Test@Example.org', 'berufsgruppe' => 'ÄrztIn', 'institution' => 'ZNA Test', 'efn' => '80276 00123 45673', 'essen' => 'vegetarisch', 'ws' => [$wid => $t1]];
    $r = http_anmelden($basis, $h, $k1, ['email' => 'kaputt', 'einwilligung' => ''] + $person);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Noch nicht angemeldet') && str_contains($r['text'], 'aria-invalid="true"') && str_contains($r['text'], 'value="Lena"') && db_wert('SELECT COUNT(*) FROM anmeldung') == 0, 'Eingabefehler sichtbar, Eingabe bleibt, nichts angelegt');
    $r = http_anmelden($basis, $h, $k1, ['website' => 'http://spam.example'] + $person);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Schau in dein Postfach') && db_wert('SELECT COUNT(*) FROM anmeldung') == 0, 'Honigtopf: gleiche Antwort, nichts angelegt (T-131)');
    $r = http_anmelden($basis, $h, $k1, ['pow_loesung' => '1'] + $person);
    pruefe(str_contains($r['text'], 'Schau in dein Postfach') && db_wert('SELECT COUNT(*) FROM anmeldung') == 0, 'ohne gelöste Rechenaufgabe: gleiche Antwort, nichts angelegt');
    $r = http_anmelden($basis, $h, $k1, ['zeit' => spam_stempel(time() - 1)] + $person);
    pruefe(str_contains($r['text'], 'Schau in dein Postfach') && db_wert('SELECT COUNT(*) FROM anmeldung') == 0, 'Formular unter 4 s: gleiche Antwort, nichts angelegt');
    $r = http_anmelden($basis, $h, $k1, ['email' => 'wer@mailinator.com'] + $person);
    pruefe(str_contains($r['text'], 'Schau in dein Postfach') && db_wert('SELECT COUNT(*) FROM anmeldung') == 0, 'Wegwerfadresse: gleiche Antwort, nichts angelegt (T-132)');
    $r = http_anmelden($basis, $h, $k1, $person);
    $a = db_zeile("SELECT * FROM anmeldung WHERE email = 'lena.test@example.org'");
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Schau in dein Postfach') && $a !== null && $a['status'] === 'unbestaetigt' && $a['efn'] === '802760012345673', 'Anmeldung angelegt (unbestätigt, EFN ohne Leerzeichen)');
    pruefe(db_wert('SELECT COUNT(*) FROM platz WHERE anmeldung_id = ? AND termin_id = ?', [$a['id'] ?? 0, $t1]) == 1, 'Workshop-Platz reserviert');
    $mail = http_mail_an($tmp, 'lena.test@example.org');
    preg_match('~(/link\?t=[0-9a-f]{48})~', $mail, $m);
    pruefe(str_contains($mail, 'Subject: ') && str_contains($mail, 'From: Wintersymposium Notfallmedizin <anmeldung@ws.test>') && str_contains($mail, 'Sie gilt erst, wenn du sie') && isset($m[1]), 'Bestätigungsmail mit Link (nach der Antwort versendet)');
    for ($i = 0; $i < 20 && (int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE status = 'gesendet' AND an = ''") === 0; $i++) {
        usleep(100_000); // Datei ist geschrieben, der Vermerk in der Datenbank folgt gleich
    }
    pruefe((int)db_wert("SELECT COUNT(*) FROM mail_ausgang WHERE status = 'gesendet' AND an = ''") >= 1, 'Warteschlange: Empfänger nach dem Versand geleert');
    $link = $m[1] ?? '/link?t=x';
    $r = http_anfrage($basis, 'GET', $link, $h, [], null, $k1);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Anmeldung bestätigen') && db_wert('SELECT COUNT(*) FROM anmeldung_link WHERE anmeldung_id = ?', [$a['id'] ?? 0]) == 1, 'Link öffnen verbraucht ihn nicht (Mail-Scanner)');
    $r = http_anfrage($basis, 'POST', '/link', $h, [], ['csrf' => http_csrf($r['text']), 't' => substr($link, 8)], $k1);
    pruefe($r['status'] === 303 && db_wert('SELECT status FROM anmeldung WHERE id = ?', [$a['id'] ?? 0]) === 'bestaetigt', 'Klick bestätigt die Anmeldung (T-130)');
    $r = http_anfrage($basis, 'GET', $link, $h, [], null, $k1);
    pruefe(str_contains($r['text'], 'Link abgelaufen'), 'Link danach ungültig (T-135)');
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k1);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Hallo Lena.') && str_contains($r['text'], 'Anmeldung bestätigt') && str_contains($r['text'], 'Platz bestätigt') && str_contains($r['text'], '80276 00123 45673'), 'Deine Anmeldung: Status, Workshop, EFN');
    pruefe(str_contains($r['text'], '<strong>Lena Test</strong>') && str_contains($r['text'], 'action="/ausloggen"'), 'Personen-Menü zeigt Name und „Ausloggen“ (E-40, E-54)');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $csrf, 'aktion' => 'essen', 'essen' => 'kein'], $k1);
    pruefe($r['status'] === 303 && db_wert('SELECT essen FROM anmeldung WHERE id = ?', [$a['id'] ?? 0]) === 'kein', 'Essen ändern');
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $csrf, 'aktion' => 'workshops', 'ws' => [$wid => $t2]], $k1);
    pruefe($r['status'] === 303 && db_wert('SELECT termin_id FROM platz WHERE anmeldung_id = ? AND termin_id <> 0', [$a['id'] ?? 0]) == $t2, 'Workshop-Termin wechseln');
    pruefe(http_mail_an($tmp, 'lena.test@example.org', 20, 'deine Workshops sind ge') !== '', 'Mail nach der Änderung');
    einstellung_setzen('stichtag_workshop', gmdate('Y-m-d', time() - 86400));
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k1);
    pruefe(str_contains($r['text'], 'nur noch über das Team') && !str_contains($r['text'], 'href="/deine-anmeldung?aendern=workshops"'), 'Nach dem Stichtag: Knopf gesperrt mit Hinweis (E-31)');
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $csrf, 'aktion' => 'workshops', 'ws' => [$wid => $t1]], $k1);
    pruefe(db_wert('SELECT termin_id FROM platz WHERE anmeldung_id = ? AND termin_id <> 0', [$a['id'] ?? 0]) == $t2, 'Nach dem Stichtag: Änderung abgelehnt (T-143)');
    db_ausfuehren("DELETE FROM va_einstellung WHERE schluessel = 'stichtag_workshop'");
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $csrf, 'aktion' => 'daten', 'vorname' => 'Lena', 'nachname' => 'Test', 'email' => 'neu@example.org', 'berufsgruppe' => 'ÄrztIn', 'institution' => 'ZNA Test', 'efn' => ''], $k1);
    pruefe($r['status'] === 303 && db_wert('SELECT email FROM anmeldung WHERE id = ?', [$a['id'] ?? 0]) === 'lena.test@example.org', 'Neue Adresse gilt erst nach Bestätigung');
    preg_match('~/link\?t=([0-9a-f]{48})~', http_mail_an($tmp, 'neu@example.org'), $m2);
    anm_link_einloesen($m2[1] ?? '');
    pruefe(db_wert('SELECT email FROM anmeldung WHERE id = ?', [$a['id'] ?? 0]) === 'neu@example.org', 'nach dem Link gilt die neue Adresse');

    abschnitt('HTTP: Gleiche Adresse, Link per Mail, Storno, Team (AP-4)');
    $k2 = "$tmp/kekse-tn2.txt";
    $vorher = http_mail_anzahl($tmp, 'neu@example.org');
    $r = http_anmelden($basis, $h, $k2, ['email' => 'neu@example.org'] + $person);
    pruefe(str_contains($r['text'], 'Schau in dein Postfach') && db_wert('SELECT COUNT(*) FROM anmeldung') == 1, 'gleiche Adresse: gleiche Antwort, keine zweite Anmeldung (E-46)');
    pruefe(http_mail_an($tmp, 'neu@example.org', 20, 'gibt es schon eine Anmeldung') !== '' && http_mail_anzahl($tmp, 'neu@example.org') === $vorher + 1, 'stattdessen Link zur bestehenden Anmeldung');
    $k3 = "$tmp/kekse-tn3.txt";
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k3);
    pruefe(str_contains($r['text'], 'Wie willst du rein?') && str_contains($r['text'], 'Link schicken'), 'Einstieg ohne Sitzung');
    $c3 = http_csrf($r['text']);
    $t0 = microtime(true);
    $r1 = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $c3, 'aktion' => 'link', 'email' => 'unbekannt@example.org', 'website' => ''], $k3);
    $d1 = microtime(true) - $t0;
    $t0 = microtime(true);
    $r2 = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $c3, 'aktion' => 'link', 'email' => 'neu@example.org', 'website' => ''], $k3);
    $d2 = microtime(true) - $t0;
    $f1 = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k3);
    pruefe($r1['status'] === 303 && $r2['status'] === 303 && abs($d1 - $d2) < 0.3 && str_contains($f1['text'], 'Wenn die Adresse bei uns angemeldet ist'), 'Link anfordern: gleiche Antwort und Dauer (T-134)');
    pruefe(http_mail_an($tmp, 'unbekannt@example.org', 3) === '' && http_mail_an($tmp, 'neu@example.org', 20, 'siehst und änderst du deine Anmeldung') !== '', 'Mail nur an bekannte Adresse');
    pruefe((http_anfrage($basis, 'GET', '/team/anmeldungen', $h, [], null, $b)['kopf']['location'] ?? '') === '/team/personen', 'alte Adresse /team/anmeldungen leitet auf Personen');
    $r = http_anfrage($basis, 'GET', '/team/personen', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Lena Test') && str_contains($r['text'], 'neu@example.org') && str_contains($r['text'], 'href="/team/personen" aria-current'), 'Team: Personen');
    pruefe(http_anfrage($basis, 'GET', '/team/anmeldungen', $h, [], null, "$tmp/kekse-niemand.txt")['status'] === 303, 'Team-Liste nur mit Login');
    $r = http_anfrage($basis, 'GET', '/', $h);
    pruefe(preg_match('~Freie Plätze</dt><dd>239 / 240~u', $r['text']) === 1, 'Startseite: freie Plätze X / Y (E-40)');
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $csrf, 'aktion' => 'storno'], $k1);
    pruefe($r['status'] === 303 && db_wert('SELECT COUNT(*) FROM anmeldung') == 0 && db_wert('SELECT COUNT(*) FROM platz') == 0, 'Storno löscht Anmeldung und Plätze');
    pruefe(http_mail_an($tmp, 'neu@example.org', 20, 'ist storniert') !== '', 'Storno-Mail');
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k1);
    pruefe(str_contains($r['text'], 'Wie willst du rein?'), 'danach keine Sitzung mehr');
    db_ausfuehren("DELETE FROM va_einstellung WHERE schluessel = 'anmeldung_start'");
}

/** Konten für Teilnehmende (AP-4b): anlegen, bestätigen, Passwort, Login, Löschen. $teamPw: Passwort der Betreiberin. */
function konto_http_tests(string $basis, string $h, string $tmp, string $teamPw): void
{
    abschnitt('HTTP: Konten für Teilnehmende (AP-4b)');
    $k = "$tmp/kekse-konto.txt";
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $k);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/deine-anmeldung', 'Mein Konto nur eingeloggt');
    $r = http_anfrage($basis, 'GET', '/konto/neu', $h, [], null, $k);
    preg_match('/name="pow_aufgabe" value="([0-9a-f]{64})"/', $r['text'], $m);
    $r = http_anfrage($basis, 'POST', '/konto/neu', $h, [], ['csrf' => http_csrf($r['text']), 'vorname' => 'Karla', 'nachname' => 'Konto', 'email' => 'karla@example.org', 'einwilligung' => '1',
        'zeit' => spam_stempel(time() - 10), 'pow_aufgabe' => $m[1] ?? '', 'pow_loesung' => http_pow($m[1] ?? ''), 'website' => ''], $k);
    pruefe(str_contains($r['text'], 'Schau in dein Postfach') && konto_per_email('karla@example.org')['email_bestaetigt_am'] === null, 'Konto ohne Anmeldung angelegt, unbestätigt');
    preg_match('~(/konto/link\?t=[A-Za-z0-9_-]{40,60})~', http_mail_an($tmp, 'karla@example.org', 20, 'Bestätige deine Adresse'), $ml);
    $r = http_anfrage($basis, 'GET', $ml[1] ?? '/konto/link', $h, [], null, $k);
    pruefe(str_contains($r['text'], 'Adresse bestätigen'), 'Link-Seite: erst Klick bestätigt');
    $r = http_anfrage($basis, 'POST', '/konto/link', $h, [], ['csrf' => http_csrf($r['text']), 't' => substr($ml[1] ?? '', 14)], $k);
    pruefe($r['status'] === 303 && konto_per_email('karla@example.org')['email_bestaetigt_am'] !== null, 'Adresse bestätigt');
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $k);
    pruefe(str_contains($r['text'], 'Passwort festlegen') && !str_contains($r['text'], 'Dein Profil'), 'danach: Passwort festlegen');
    $c = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/konto', $h, [], ['csrf' => $c, 'aktion' => 'passwort_setzen', 'passwort' => '123456789', 'passwort2' => '123456789'], $k);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'mindestens 10 Zeichen'), 'Passwort unter 10 Zeichen abgelehnt');
    $r = http_anfrage($basis, 'POST', '/konto', $h, [], ['csrf' => $c, 'aktion' => 'passwort_setzen', 'passwort' => 'karlas-passwort', 'passwort2' => 'karlas-passwort'], $k);
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $k);
    pruefe(str_contains($r['text'], 'Dein Profil') && str_contains($r['text'], 'href="/konto" aria-current'), 'Passwort gespeichert, Profil, „Mein Konto“ in der Navigation');
    $c = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/konto', $h, [], ['csrf' => $c, 'aktion' => 'profil', 'vorname' => 'Karla', 'nachname' => 'Konto', 'berufsgruppe' => 'ÄrztIn', 'institution' => 'ZNA', 'efn' => '802760012345673'], $k);
    pruefe($r['status'] === 303 && konto_per_email('karla@example.org')['efn'] === '802760012345673', 'Profil ohne Anmeldung speichern (EFN)');
    http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $c, 'aktion' => 'ausloggen'], $k);
    pruefe(http_anfrage($basis, 'GET', '/konto', $h, [], null, $k)['status'] === 303, 'Ausloggen');
    // Login über /einloggen (gemeinsamer Login, E-48)
    $k2 = "$tmp/kekse-konto2.txt";
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k2);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'karla@example.org', 'passwort' => 'karlas-passwort'], $k2);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/konto' && str_contains(http_anfrage($basis, 'GET', '/konto', $h, [], null, $k2)['text'], 'Hallo Karla.'), 'Login unter /einloggen führt Teilnehmende zu „Mein Konto“');
    // Login im Kasten auf „Deine Anmeldung“
    $k3 = "$tmp/kekse-konto3.txt";
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k3);
    pruefe(str_contains($r['text'], 'Mit Konto') && str_contains($r['text'], 'Passwort vergessen?'), 'Einstieg mit drittem Kasten „Einloggen“');
    $c3 = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $c3, 'aktion' => 'einloggen', 'email' => 'karla@example.org', 'passwort' => 'falsch-falsch'], $k3);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'E-Mail-Adresse oder Passwort stimmt nicht'), 'falsches Passwort: Meldung');
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $c3, 'aktion' => 'einloggen', 'email' => 'karla@example.org', 'passwort' => 'karlas-passwort'], $k3);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/konto', 'Login im Kasten');
    $k4 = "$tmp/kekse-konto4.txt";
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k4);
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'einloggen', 'email' => 'betrieb@example.org', 'passwort' => $teamPw], $k4);
    $r2 = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k4);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/einloggen' && str_contains($r2['text'], 'Authenticator-App'), 'Team im Kasten: weiter zum zweiten Faktor');
    // Passwort vergessen
    $r = http_anfrage($basis, 'GET', '/konto/passwort-vergessen', $h, [], null, $k4);
    $r = http_anfrage($basis, 'POST', '/konto/passwort-vergessen', $h, [], ['csrf' => http_csrf($r['text']), 'email' => 'karla@example.org', 'website' => ''], $k4);
    pruefe($r['status'] === 303 && http_mail_an($tmp, 'karla@example.org', 20, 'neues Passwort fest') !== '', 'Passwort vergessen: Link per Mail');
    // Häkchen „Konto anlegen“ beim Anmelden
    einstellung_setzen('anmeldung_start', gmdate('Y-m-d H:i:s', time() - 60));
    $k5 = "$tmp/kekse-konto5.txt";
    $r = http_anmelden($basis, $h, $k5, ['vorname' => 'Hanna', 'nachname' => 'Haken', 'email' => 'hanna@example.org', 'berufsgruppe' => 'StudentIn', 'institution' => 'Uni', 'essen' => 'egal', 'konto' => '1']);
    preg_match('~(/link\?t=[0-9a-f]{48})~', http_mail_an($tmp, 'hanna@example.org', 20, 'Sie gilt erst'), $mh);
    $r = http_anfrage($basis, 'GET', $mh[1] ?? '/link', $h, [], null, $k5);
    $r = http_anfrage($basis, 'POST', '/link', $h, [], ['csrf' => http_csrf($r['text']), 't' => substr($mh[1] ?? '', 8)], $k5);
    $hk = konto_per_email('hanna@example.org');
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/konto' && $hk !== null && db_wert('SELECT konto_id FROM anmeldung WHERE email = ?', ['hanna@example.org']) == $hk['id'], 'Häkchen beim Anmelden: nach dem Link Konto angelegt und verknüpft');
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $k5);
    pruefe(str_contains($r['text'], 'Passwort festlegen'), 'und gleich Passwort festlegen');
    $c5 = http_csrf($r['text']);
    http_anfrage($basis, 'POST', '/konto', $h, [], ['csrf' => $c5, 'aktion' => 'passwort_setzen', 'passwort' => 'hannas-passwort', 'passwort2' => 'hannas-passwort'], $k5);
    $r = http_anfrage($basis, 'GET', '/team/personen?f=konto', $h, [], null, "$tmp/kekse-betreiber.txt");
    pruefe(preg_match('~Hanna Haken.*?data-label="Konto">Konto<~s', $r['text']) === 1, 'Personen: Filter „Mit Konto“, Spalte Konto');
    $r = http_anfrage($basis, 'POST', '/konto', $h, [], ['csrf' => $c5, 'aktion' => 'loeschen', 'passwort' => 'falsch', 'anmeldung' => 'behalten'], $k5);
    pruefe(str_contains($r['text'], 'Das Passwort stimmt nicht') && konto_per_email('hanna@example.org') !== null, 'Löschen nur mit Passwort');
    $r = http_anfrage($basis, 'POST', '/konto', $h, [], ['csrf' => $c5, 'aktion' => 'loeschen', 'passwort' => 'hannas-passwort', 'anmeldung' => 'behalten'], $k5);
    pruefe($r['status'] === 303 && konto_per_email('hanna@example.org') === null && db_wert('SELECT COUNT(*) FROM anmeldung WHERE email = ?', ['hanna@example.org']) == 1, 'Konto gelöscht, Anmeldung bleibt (Wahl)');
    db_ausfuehren("DELETE FROM anmeldung WHERE email = 'hanna@example.org'");
    db_ausfuehren("DELETE FROM va_einstellung WHERE schluessel = 'anmeldung_start'");
}

/** Team-Bereich AP-5: Seiten, Person anlegen und ändern, Export, Texte, Protokoll, Betrieb. */
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

function team_ap6_http_tests(string $basis, string $h, string $tmp, string $b): void
{
    abschnitt('HTTP: Mailings und Team-Mails (AP-6)');
    $GLOBALS['ws_team_konto'] = null;
    team_anlegen(['vorname' => 'Rita', 'nachname' => 'Rundmail', 'email' => 'rita@example.org', 'berufsgruppe' => 'Pflege/MFA', 'institution' => 'Station 5', 'efn' => '', 'beruf_sonstige' => '', 'essen' => 'egal'], true, false);
    $r = http_anfrage($basis, 'GET', '/team/mailings', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'href="/team/mailings" aria-current="page"') && str_contains($r['text'], '+ Neues Mailing'), 'Reiter Mailings mit Übersicht');
    pruefe(str_contains($r['kopf']['content-security-policy'] ?? '', "frame-src 'self'"), 'Seite erlaubt die Vorschau im eigenen iframe');
    $r = http_anfrage($basis, 'GET', '/team/mailings?neu=1', $h, [], null, $b);
    $c = http_csrf($r['text']);
    pruefe(str_contains($r['text'], 'data-editor="block"') && str_contains($r['text'], '/assets/editor.js') && str_contains($r['text'], 'name="html"') && str_contains($r['text'], '/assets/mailings.js') && str_contains($r['text'], 'Die Vorschau erscheint nach dem ersten Speichern'), 'Editor mit Schreibfeld und Textfeld ohne Skript');
    $r = http_anfrage($basis, 'POST', '/team/mailings', $h, [], ['csrf' => $c, 'aktion' => 'speichern', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system', 'betreff' => 'Hallo {vorname}', 'html' => '<p>Hallo {vorname},</p><script>x</script><p><a class="knopf" href="{link}">Ansehen</a></p>'], $b);
    $id = (int)db_wert('SELECT MAX(id) FROM rundmail');
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/team/mailings?id=' . $id && !str_contains((string)db_wert('SELECT html FROM rundmail WHERE id = ?', [$id]), 'script'), 'Entwurf gespeichert und bereinigt');
    $png = "$tmp/plan.png";
    $g = imagecreatetruecolor(300, 150);
    imagepng($g, $png);
    imagedestroy($g);
    $u = http_bild_hochladen($basis, $h, $b, $c, $png);
    pruefe($u['status'] === 200 && ($u['json']['breite'] ?? 0) === 300 && str_starts_with($u['json']['url'] ?? '', '/team/mailings/bild?id='), 'Bild hochladen (Editor)');
    pruefe(http_bild_hochladen($basis, $h, $b, 'falsch', $png)['status'] === 403, 'Hochladen ohne gültiges CSRF abgelehnt');
    file_put_contents("$tmp/kein-bild.png", '<?php echo 1;');
    pruefe(http_bild_hochladen($basis, $h, $b, $c, "$tmp/kein-bild.png")['status'] === 400, 'nur echte Bilder');
    $r = http_anfrage($basis, 'GET', $u['json']['url'] ?? '/', $h, [], null, $b);
    pruefe($r['status'] === 200 && ($r['kopf']['content-type'] ?? '') === 'image/png', 'Bild für das Team abrufbar');
    pruefe(http_anfrage($basis, 'GET', $u['json']['url'] ?? '/', $h, [], null, "$tmp/kekse-niemand2.txt")['status'] === 303, 'Bild nicht öffentlich');
    $html = '<p>Hallo {vorname},</p><p>{workshops}</p><p><img src="' . ($u['json']['url'] ?? '') . '" alt="Plan" data-breite="50"></p><p><a class="knopf" href="{link}">Ansehen</a></p>';
    http_anfrage($basis, 'POST', '/team/mailings?id=' . $id, $h, [], ['csrf' => $c, 'id' => $id, 'aktion' => 'speichern', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system', 'betreff' => 'Hallo {vorname}', 'html' => $html], $b);
    $r = http_anfrage($basis, 'GET', '/team/mailings?id=' . $id, $h, [], null, $b);
    pruefe(str_contains($r['text'], '/team/mailings/vorschau?id=' . $id) && str_contains($r['text'], 'sandbox="allow-same-origin'), 'Vorschau im iframe (ohne Skripte)');
    $r = http_anfrage($basis, 'GET', '/team/mailings/vorschau?id=' . $id, $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['kopf']['content-security-policy'] ?? '', "style-src 'unsafe-inline'") && !str_contains($r['kopf']['content-security-policy'] ?? '', 'script-src') && ($r['kopf']['x-frame-options'] ?? '') === 'SAMEORIGIN', 'Vorschau: eigene Richtlinie ohne Skripte, nur im eigenen Rahmen');
    pruefe(str_contains($r['text'], 'Hallo ') && !str_contains($r['text'], '{vorname}') && str_contains($r['text'], 'width="272"') && str_contains($r['text'], '/impressum'), 'Vorschau mit Daten der ersten Person, Bild halbe Breite, Impressum');
    $r = http_anfrage($basis, 'GET', '/team/mailings/empfaenger?auswahl=angemeldet&bg=', $h, [], null, $b);
    $j = json_decode($r['text'], true);
    $soll = count(rm_empfaenger('angemeldet'));
    pruefe($r['status'] === 200 && ($j['zahl'] ?? -1) === $soll && $soll > 0, 'Empfängerzahl für den Editor');
    pruefe(http_anfrage($basis, 'GET', '/team/mailings/empfaenger?auswahl=quatsch', $h, [], null, $b)['status'] === 400, 'unbekannte Auswahl: 400');
    $r = http_anfrage($basis, 'POST', '/team/mailings?id=' . $id, $h, [], ['csrf' => $c, 'id' => $id, 'aktion' => 'test', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system', 'betreff' => 'Hallo {vorname}', 'html' => $html], $b);
    $tm = http_mail_an($tmp, 'betrieb@example.org', 20, '[Test] Hallo Erste');
    pruefe($r['status'] === 303 && str_contains($tm, 'multipart/related') && str_contains($tm, 'Content-ID: <bild') && str_contains($tm, '/deine-anmeldung'), 'Testmail an mich: HTML mit Bild, {link} allgemein');
    $r = http_anfrage($basis, 'POST', '/team/mailings?id=' . $id, $h, [], ['csrf' => $c, 'id' => $id, 'aktion' => 'planen', 'geplant' => '2020-01-01T10:00', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system', 'betreff' => 'Hallo {vorname}', 'html' => $html], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Zeitpunkt in der Zukunft') && db_wert('SELECT status FROM rundmail WHERE id = ?', [$id]) === 'entwurf', 'Planen in der Vergangenheit abgelehnt');
    $r = http_anfrage($basis, 'POST', '/team/mailings?id=' . $id, $h, [], ['csrf' => $c, 'id' => $id, 'aktion' => 'senden', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system', 'betreff' => 'Hallo {vorname}', 'html' => $html], $b);
    pruefe($r['status'] === 303 && db_wert('SELECT status FROM rundmail WHERE id = ?', [$id]) === 'versand' && (int)db_wert('SELECT empfaenger_zahl FROM rundmail WHERE id = ?', [$id]) === $soll, 'Senden: Mailing in der Warteschlange');
    $erste = rm_empfaenger('angemeldet')[0]['email'] ?? '';
    $dbgm = http_mail_an($tmp, $erste, 30, 'Ansehen');
    pruefe(preg_match('#/link\?t=[0-9a-f]{48}#', $dbgm) === 1, 'Mailing kommt an, mit persönlichem Einmal-Link');
    $r = http_anfrage($basis, 'GET', '/team/mailings?id=' . $id, $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Empfängerliste (' . $soll . ')') && str_contains($r['text'], 'Als neue Mail kopieren') && !str_contains($r['text'], 'data-editor='), 'Gesendetes Mailing: Archiv mit Empfängerliste, nicht mehr änderbar');
    $r = http_anfrage($basis, 'POST', '/team/mailings?id=' . $id, $h, [], ['csrf' => $c, 'id' => $id, 'aktion' => 'speichern', 'betreff' => 'Geändert', 'html' => '<p>x</p>', 'auswahl' => 'angemeldet', 'postfach' => 'system'], $b);
    pruefe(db_wert('SELECT betreff FROM rundmail WHERE id = ?', [$id]) === 'Hallo {vorname}', 'Gesendetes lässt sich nicht ändern');
    $r = http_anfrage($basis, 'GET', '/team/mailings', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Hallo {vorname}') && (str_contains($r['text'], 'Wird gesendet') || str_contains($r['text'], 'Gesendet')), 'Übersicht zeigt das Mailing mit Status');
    pruefe(str_contains((string)db_wert("SELECT text FROM audit WHERE art = 'mailing' ORDER BY id DESC LIMIT 1"), 'Mailing ' . $id), 'Senden steht im Protokoll');
    // Team: Passwort vergessen per Mail (AP-6)
    $k = "$tmp/kekse-vergessen.txt";
    db_ausfuehren('DELETE FROM rate_limit');
    $r = http_anfrage($basis, 'GET', '/konto/passwort-vergessen', $h, [], null, $k);
    http_anfrage($basis, 'POST', '/konto/passwort-vergessen', $h, [], ['csrf' => http_csrf($r['text']), 'email' => 'betrieb@example.org'], $k);
    $pm = http_mail_an($tmp, 'betrieb@example.org', 20, 'zweiter Faktor bleibt');
    pruefe(preg_match('#/einrichten\?t=[A-Za-z0-9_-]{40,}#', $pm) === 1 && str_contains($pm, 'zweiter Faktor bleibt'), 'Team: Passwort vergessen schickt einen Link per Mail');
    pruefe(!str_contains(http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k)['text'], 'ein Betreiber schickt dir'), 'Hinweis „Betreiber schickt Link“ entfernt');
}

/** Newsletter (AP-7): bestellen, bestätigen, verwalten, One-Click-Abmelden, Team. */
function newsletter_http_tests(string $basis, string $h, string $tmp, string $b): void
{
    abschnitt('HTTP: Newsletter (AP-7)');
    db_ausfuehren('DELETE FROM rate_limit');
    $k = "$tmp/kekse-nl.txt";
    $r = http_anfrage($basis, 'GET', '/newsletter', $h, [], null, $k);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Wintersymposium') && str_contains($r['text'], 'Allgäu Akut') && str_contains($r['text'], 'name="pow_aufgabe"'), 'Seite /newsletter mit Listen und Spamschutz');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/', $h, [], null, $k)['text'], 'href="/newsletter"'), 'Link in Fußzeile und Hinweis auf der Startseite');
    $lw = (int)db_wert("SELECT id FROM newsletter_liste WHERE name = 'Wintersymposium'");
    preg_match('/name="pow_aufgabe" value="([0-9a-f]{64})"/', $r['text'], $m);
    $form = ['csrf' => http_csrf($r['text']), 'zeit' => spam_stempel(time() - 10), 'pow_aufgabe' => $m[1] ?? '', 'pow_loesung' => http_pow($m[1] ?? ''), 'website' => '', 'email' => 'sven@example.org', 'vorname' => 'Sven', 'listen' => [$lw]];
    $r = http_anfrage($basis, 'POST', '/newsletter', $h, [], $form, $k);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Einwilligung'), 'ohne Einwilligung: Hinweis');
    $r = http_anfrage($basis, 'POST', '/newsletter', $h, [], $form + ['einwilligung' => '1'], $k);
    $mail = http_mail_an($tmp, 'sven@example.org', 20, '/newsletter/bestaetigen?t=');
    $tok = preg_match('#/newsletter/bestaetigen\?t=([0-9a-f]{48})#', $mail, $mm) === 1 ? $mm[1] : '';
    pruefe($r['status'] === 303 && $tok !== '', 'Bestellt: Bestätigungsmail');
    $r = http_anfrage($basis, 'GET', '/newsletter/bestaetigen?t=' . $tok, $h, [], null, $k);
    pruefe(str_contains($r['text'], 'Bestätigen') && db_wert("SELECT status FROM newsletter_abo a JOIN newsletter_person p ON p.id = a.person_id WHERE p.email = 'sven@example.org'") === 'offen', 'Öffnen allein bestätigt nicht (Mail-Scanner)');
    http_anfrage($basis, 'POST', '/newsletter/bestaetigen', $h, [], ['csrf' => http_csrf($r['text']), 't' => $tok], $k);
    pruefe(db_wert("SELECT status FROM newsletter_abo a JOIN newsletter_person p ON p.id = a.person_id WHERE p.email = 'sven@example.org'") === 'bestaetigt', 'Bestätigt (Double-Opt-In)');
    // Verwalten und One-Click
    $pid = (int)db_wert("SELECT id FROM newsletter_person WHERE email = 'sven@example.org'");
    $vt = nl_link_erzeugen($pid, 'verwalten');
    $r = http_anfrage($basis, 'GET', '/newsletter/verwalten?t=' . $vt, $h, [], null, $k);
    pruefe(str_contains($r['text'], 'sven@example.org') && str_contains($r['text'], 'Von allen abmelden'), 'Verwalten über den Link aus der Mail');
    $ch = curl_init($basis . '/newsletter/abmelden?t=' . $vt);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Host: ' . $h], CURLOPT_POSTFIELDS => 'List-Unsubscribe=One-Click']);
    curl_exec($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    pruefe($st === 200 && db_wert("SELECT COUNT(*) FROM newsletter_person WHERE email = 'sven@example.org'") == 0, 'One-Click-Abmelden ohne Login: sofort gelöscht (Abnahme AP-7)');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/newsletter/abmelden?t=' . str_repeat('a', 48), $h, [], null, $k)['text'], 'gilt nicht mehr'), 'ungültiger Abmeldelink: freundlicher Hinweis');
    // Team
    $r = http_anfrage($basis, 'GET', '/team/newsletter', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'aria-current="page">Newsletter') && str_contains($r['text'], 'Einladen') && str_contains($r['text'], 'Neue Liste'), 'Team: Reiter Newsletter');
    $c = http_csrf($r['text']);
    http_anfrage($basis, 'POST', '/team/newsletter', $h, [], ['csrf' => $c, 'aktion' => 'liste', 'name' => 'Testliste', 'beschreibung' => 'Nur Test', 'absender_name' => 'Test', 'sichtbar' => '1'], $b);
    $tl = (int)db_wert("SELECT id FROM newsletter_liste WHERE name = 'Testliste'");
    pruefe($tl > 0 && str_contains(http_anfrage($basis, 'GET', '/newsletter', $h, [], null, $k)['text'], 'Testliste'), 'Liste anlegen, öffentlich wählbar');
    http_anfrage($basis, 'POST', '/team/newsletter', $h, [], ['csrf' => $c, 'aktion' => 'einladen', 'email' => 'tara@example.org', 'listen' => [$tl]], $b);
    pruefe(http_mail_an($tmp, 'tara@example.org', 20, '/newsletter/bestaetigen?t=') !== '' && db_wert("SELECT a.status FROM newsletter_abo a JOIN newsletter_person p ON p.id = a.person_id WHERE p.email = 'tara@example.org'") === 'offen', 'Einladen: nur Bestätigungsmail, nicht eingetragen');
    $r = http_anfrage($basis, 'GET', '/team/newsletter?q=tara', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'tara@example.org') && str_contains($r['text'], 'wartet'), 'Suche zeigt offene Einladung');
    http_anfrage($basis, 'POST', '/team/newsletter', $h, [], ['csrf' => $c, 'aktion' => 'loeschen', 'person' => (int)db_wert("SELECT id FROM newsletter_person WHERE email = 'tara@example.org'")], $b);
    pruefe(db_wert("SELECT COUNT(*) FROM newsletter_person WHERE email = 'tara@example.org'") == 0, 'Löschen durch das Team');
    $r = http_anfrage($basis, 'GET', '/team/export?art=newsletter', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_starts_with($r['text'], "\u{FEFF}\"Liste\";\"E-Mail\""), 'Export Newsletter als CSV');
    $r = http_anfrage($basis, 'GET', '/team/mailings?neu=1', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'value="newsletter"') && str_contains($r['text'], 'name="listen[]"'), 'Mailings: Empfängerart Newsletter-Listen');
    $r = http_anfrage($basis, 'GET', '/team/mailings/empfaenger?auswahl=nl:' . $tl, $h, [], null, $b);
    pruefe($r['status'] === 200 && (json_decode($r['text'], true)['zahl'] ?? -1) === 0, 'Empfängerzahl einer Liste (nur bestätigte)');
}

/** Rückmeldung 2026-10-30: Navigation, Einstieg, Sperre, Texte mit Bild, Mail-Code, Dialog. */
function r30_http_tests(string $basis, string $h, string $tmp, string $b): void
{
    abschnitt('HTTP: Rückmeldung 2026-10-30');
    // Team-Login wird auf öffentlichen Seiten erkannt (vorher sah „Deine Anmeldung“ wie ausgeloggt aus)
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'href="/team"') && str_contains($r['text'], 'Meine Anmeldung') && !str_contains($r['text'], 'class="nav-anmelden"'), 'Team eingeloggt: „Meine Anmeldung“ und Team-Bereich, nicht ausgeloggt');
    pruefe(http_anfrage($basis, 'GET', '/team', $h, [], null, $b)['status'] === 200, 'Team-Sitzung bleibt danach bestehen');
    $r = http_anfrage($basis, 'GET', '/', $h, [], null, "$tmp/kekse-gast.txt");
    pruefe(str_contains($r['text'], 'class="nav-anmelden" href="/deine-anmeldung"') && !str_contains($r['text'], 'Meine Anmeldung') && !str_contains($r['text'], '>Team</a>'), 'Gäste: grüner Knopf „Anmelden“, kein Team-Link in der Fußzeile');
    pruefe(str_contains($r['text'], '/assets/dialog.js'), 'Dialog-Skript auf jeder Seite');
    // Vor dem Anmeldestart: Hinweis statt „Link per Mail“
    $start = einstellung('anmeldung_start');
    einstellung_setzen('anmeldung_start', gmdate('Y-m-d H:i:s', time() + 86400 * 3));
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, "$tmp/kekse-gast.txt");
    pruefe(str_contains($r['text'], 'Anmeldung noch nicht möglich') && !str_contains($r['text'], 'Link per Mail') && str_contains($r['text'], 'Einloggen'), 'Vor dem Start: Hinweis über zwei Kacheln, kein „Link per Mail“');
    einstellung_setzen('anmeldung_start', gmdate('Y-m-d H:i:s', time() - 86400));
    pruefe(str_contains(http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, "$tmp/kekse-gast.txt")['text'], 'Link per Mail'), 'Nach dem Start: „Link per Mail“ wieder da');
    einstellung_setzen('anmeldung_start', $start);
    // Einstellungen gesperrt
    $r = http_anfrage($basis, 'GET', '/team/einstellungen', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Gesperrt.') && str_contains($r['text'], '<fieldset class="kasten gruppe" id="veranstaltung" disabled>') && !str_contains($r['text'], 'Einstellungen speichern'), 'Einstellungen zuerst gesperrt');
    $r = http_anfrage($basis, 'GET', '/team/einstellungen?bearbeiten=1', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Entsperrt.') && !str_contains($r['text'], ' disabled>') && str_contains($r['text'], 'Einstellungen speichern'), 'Entsperren zeigt die Felder zum Bearbeiten');
    // Texte: Bild hochladen, öffentlich sichtbar
    $png = "$tmp/text.png";
    $g = imagecreatetruecolor(50, 30);
    imagepng($g, $png);
    imagedestroy($g);
    $c = http_csrf(http_anfrage($basis, 'GET', '/team/texte', $h, [], null, $b)['text']);
    $ch = curl_init($basis . '/team/texte/bild');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Host: ' . $h, 'X-CSRF: ' . $c], CURLOPT_COOKIEFILE => $b, CURLOPT_COOKIEJAR => $b, CURLOPT_POSTFIELDS => ['bild' => new CURLFile($png, 'image/png', 'a.png')]]);
    $j = json_decode((string)curl_exec($ch), true) ?? [];
    curl_close($ch);
    $url = (string)($j['url'] ?? '');
    pruefe(preg_match('#^/bild/text-[0-9a-f]{16}\.png$#', $url) === 1, 'Bild für einen Text hochgeladen');
    pruefe(http_anfrage($basis, 'GET', $url, $h, [], null, "$tmp/kekse-gast.txt")['status'] === 200, 'Text-Bild öffentlich abrufbar');
    $alt = inhalt('start_cme');
    http_anfrage($basis, 'POST', '/team/texte', $h, [], ['csrf' => $c, 'start_cme' => '<p>Mit Bild:</p><p><img src="' . $url . '" alt="Lageplan" data-breite="50"><script>x()</script></p>'], $b);
    pruefe(str_contains(http_anfrage($basis, 'GET', '/', $h, [], null, "$tmp/kekse-gast.txt")['text'], '<img class="text-bild" loading="lazy" src="' . $url . '" alt="Lageplan" data-breite="50">') && !str_contains(http_anfrage($basis, 'GET', '/', $h, [], null, "$tmp/kekse-gast.txt")['text'], '<script>x()'), 'Bild erscheint auf der Startseite (halbe Breite), Skript entfernt');
    http_anfrage($basis, 'POST', '/team/texte', $h, [], ['csrf' => $c, 'start_cme' => $alt], $b);
    pruefe(str_contains(http_anfrage($basis, 'GET', '/team/texte', $h, [], null, $b)['text'], 'data-editor="block"'), 'Texte mit dem Editor (wie Mailings)');
    // Login mit Code per E-Mail
    db_ausfuehren('DELETE FROM rate_limit');
    $mk = konto_anlegen('Mia Mailcode', 'mia@example.org', 'admin', 'mia-ihr-passwort-1', null);
    $k = "$tmp/kekse-mia.txt";
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k);
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'mia@example.org', 'passwort' => 'mia-ihr-passwort-1'], $k);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k);
    pruefe(str_contains($r['text'], 'Code per E-Mail nutzen') && str_contains($r['text'], 'data-qr="otpauth://'), 'Erstes Einloggen: Wahl zwischen E-Mail-Code und App');
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'mail_waehlen'], $k);
    $mail = http_mail_an($tmp, 'mia@example.org', 20, 'Er gilt 10 Minuten');
    $code = preg_match('/^\s+(\d{6})\s*$/m', $mail, $m) === 1 ? $m[1] : '';
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k);
    pruefe($code !== '' && str_contains($r['text'], 'Code aus der Mail'), 'Code kommt per Mail');
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'mail_einrichten', 'totp' => $code], $k);
    pruefe($r['status'] === 200 && substr_count($r['text'], '<li class="mono">') === TOTP_CODES && http_anfrage($basis, 'GET', '/team', $h, [], null, $k)['status'] === 200, 'Mit dem Code eingerichtet, Wiederherstellungscodes, eingeloggt');
    // Nächstes Einloggen: Code kommt automatisch
    $k2 = "$tmp/kekse-mia2.txt";
    $vorher = http_mail_anzahl($tmp, 'mia@example.org');
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k2);
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'mia@example.org', 'passwort' => 'mia-ihr-passwort-1'], $k2);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k2);
    usleep(600_000);
    $mail = '';
    for ($i = 0; $i < 20 && http_mail_anzahl($tmp, 'mia@example.org') <= $vorher; $i++) {
        usleep(150_000);
    }
    $mail = http_mail_an($tmp, 'mia@example.org', 5, 'Er gilt 10 Minuten');
    $code = preg_match('/^\s+(\d{6})\s*$/m', $mail, $m) === 1 ? $m[1] : '';
    pruefe(str_contains($r['text'], 'Wir haben dir einen sechsstelligen Code') && http_mail_anzahl($tmp, 'mia@example.org') > $vorher, 'Später: Code kommt beim Einloggen automatisch');
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'code', 'totp' => $code], $k2);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/team', 'Einloggen mit dem Code aus der Mail');
    $r = http_anfrage($basis, 'GET', '/konto/zweitfaktor', $h, [], null, $k2);
    pruefe(str_contains($r['text'], 'Zwei-Faktor-Authentifizierung') && str_contains($r['text'], 'Code per E-Mail') && str_contains($r['text'], 'Passkey'), 'Mein Konto › Zwei-Faktor');
    $r = http_anfrage($basis, 'POST', '/konto/zweitfaktor', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'mail_aus', 'pw' => 'mia-ihr-passwort-1'], $k2);
    pruefe(str_contains($r['text'], 'Richte zuerst die Authenticator-App oder einen Passkey ein') && totp_zustand($mk)['mail'], 'Mail-Code ohne App nicht abschaltbar');
    // Protokoll: Admin sieht keine Sicherung, kann nicht ausblenden
    $r = http_anfrage($basis, 'GET', '/team/protokoll?art=sicherung', $h, [], null, $k2);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Keine Einträge') && !str_contains($r['text'], 'Ausblenden'), 'Admin: keine Sicherungs-Einträge, kein Ausblenden');
    $r = http_anfrage($basis, 'GET', '/team/protokoll', $h, [], null, $b);
    pruefe(str_contains($r['text'], '>Ausblenden<') && str_contains($r['text'], 'class="filter"'), 'Betreiber: Ausblenden und Filter-Knöpfe');
    // Programm: Löschen-Knopf neben Speichern, Dialog-Markierungen
    $pid = (int)db_wert("SELECT id FROM programmpunkt WHERE typ <> 'workshop' ORDER BY id LIMIT 1");
    $r = http_anfrage($basis, 'GET', '/team/programm?id=' . $pid, $h, [], null, $b);
    pruefe(str_contains($r['text'], 'id="pg-loeschen" hidden') && str_contains($r['text'], 'data-ausloeser="pg-loeschen"'), 'Programmpunkt löschen neben Speichern (mit Dialog)');
    pruefe(!str_contains($r['text'], '+ neu') && !str_contains($r['text'], 'Markiert:'), 'Programm: ein Label, keine Legende');
}

/** Ein Konto für alles, Personen-Menü, Team-Navigation, ein Editor (Rückmeldung 2026-10-30 (2), E-54). */
function konto_alles_http_tests(string $basis, string $h, string $tmp, string $b): void
{
    abschnitt('HTTP: Ein Konto für alles, Navigation, Editor (E-54)');
    $k2 = "$tmp/kekse-mia2.txt"; // Mia (Admin) ist aus dem vorigen Abschnitt eingeloggt
    $mk = (int)konto_per_email('mia@example.org')['id'];
    $r = http_anfrage($basis, 'GET', '/team/personen', $h, [], null, $k2);
    pruefe(str_contains($r['text'], 'aria-label="Unterseiten von Anmeldungen"') && str_contains($r['text'], 'href="/team/workshops"') && str_contains($r['text'], '>Kommunikation<') && !str_contains($r['text'], '>Team-Konten<'), 'Team-Navigation: Bereiche mit Unterseiten; Team-Konten nur für Betreiber');
    pruefe(str_contains($r['text'], 'class="person-menue"') && str_contains($r['text'], '<strong>Mia Mailcode</strong>') && str_contains($r['text'], 'href="/konto"'), 'Personen-Menü mit Name und „Mein Konto“');
    $r = http_anfrage($basis, 'GET', '/team/einstellungen', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'aria-label="Unterseiten von Verwaltung"') && str_contains($r['text'], '>Team-Konten<') && str_contains($r['text'], '>Betrieb<'), 'Betreiber: Verwaltung mit Team-Konten und Betrieb');
    $r = http_anfrage($basis, 'GET', '/team/profil', $h, [], null, $k2);
    pruefe($r['status'] === 303 && ($r['kopf']['location'] ?? '') === '/konto', 'Alte Adresse „Mein Konto“ im Team-Bereich führt zu /konto');
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $k2);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Team-Konto') && str_contains($r['text'], 'href="/konto/zweitfaktor"') && !str_contains($r['text'], '>Konto löschen<') && str_contains($r['text'], 'minlength="12"'), 'Mein Konto für Team: Zwei-Faktor, kein Löschen, Passwort 12 Zeichen');
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k2);
    pruefe(str_contains($r['text'], 'Eingeloggt als Mia Mailcode') && str_contains($r['text'], 'Anmeldung noch nicht möglich') && !str_contains($r['text'], 'name="passwort"'), 'Meine Anmeldung eingeloggt vor dem Start: kein Login-Feld');
    einstellung_setzen('anmeldung_start', gmdate('Y-m-d H:i:s', time() - 60));
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k2);
    pruefe(str_contains($r['text'], 'Eingeloggt als Mia Mailcode') && !str_contains($r['text'], 'name="passwort"') && str_contains($r['text'], 'href="/anmelden"'), 'Meine Anmeldung eingeloggt: kein Login-Feld, „Jetzt anmelden“');
    // Team-Mitglied meldet sich mit dem eigenen Konto an: vorausgefüllt, gleich bestätigt
    $r = http_anfrage($basis, 'GET', '/anmelden', $h, [], null, $k2);
    pruefe(str_contains($r['text'], 'value="mia@example.org" readonly'), 'Anmeldeformular mit der Adresse des Team-Kontos');
    http_anmelden($basis, $h, $k2, ['vorname' => 'Mia', 'nachname' => 'Mailcode', 'berufsgruppe' => 'Pflege/MFA', 'institution' => 'ZNA', 'essen' => 'egal']);
    $a = db_zeile("SELECT status, konto_id FROM anmeldung WHERE email = 'mia@example.org'");
    pruefe(($a['status'] ?? '') === 'bestaetigt' && (int)($a['konto_id'] ?? 0) === $mk, 'Team-Konto: eigene Anmeldung, verknüpft und gleich bestätigt');
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k2);
    pruefe(str_contains($r['text'], 'Hallo Mia.'), 'Meine Anmeldung zeigt die eigene Anmeldung');
    db_ausfuehren("DELETE FROM va_einstellung WHERE schluessel = 'anmeldung_start'");
    // Ausloggen beendet alles
    $r = http_anfrage($basis, 'POST', '/ausloggen', $h, [], ['csrf' => http_csrf($r['text'])], $k2);
    pruefe(http_anfrage($basis, 'GET', '/team', $h, [], null, $k2)['status'] === 303 && !str_contains(http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k2)['text'], 'Hallo Mia.'), 'Ausloggen beendet Team und Konto zugleich');
    // Rolle angehoben: nächster Login verlangt den zweiten Faktor
    $tk = konto_anlegen('Theo Teil', 'theo@example.org', 'teilnehmend', 'theos-passwort-1', null);
    db_ausfuehren('UPDATE konto SET email_bestaetigt_am = ? WHERE id = ?', [db_jetzt(), $tk]);
    $k3 = "$tmp/kekse-theo.txt";
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k3);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'theo@example.org', 'passwort' => 'theos-passwort-1'], $k3);
    pruefe(($r['kopf']['location'] ?? '') === '/konto' && str_contains(http_anfrage($basis, 'GET', '/konto', $h, [], null, $k3)['text'], 'Konto löschen'), 'Teilnehmend: Login ohne zweiten Faktor, Mein Konto mit Löschen');
    konto_rolle_setzen($tk, 'admin', null);
    pruefe(http_anfrage($basis, 'GET', '/konto', $h, [], null, $k3)['status'] === 303, 'Rolle angehoben: alte Sitzung gilt nicht mehr');
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k3);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'theo@example.org', 'passwort' => 'theos-passwort-1'], $k3);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k3);
    pruefe(str_contains($r['text'], 'data-qr="otpauth://') && http_anfrage($basis, 'GET', '/team', $h, [], null, $k3)['status'] === 303, 'Nächster Login: zweiten Faktor einrichten, vorher kein Team-Bereich');
    // Ein Editor: Programm-Beschreibung wird bereinigt gespeichert
    $r = http_anfrage($basis, 'GET', '/team/programm?neu=vortrag', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'id="pg-beschreibung"') && str_contains($r['text'], 'data-editor="block"') && str_contains($r['text'], '/assets/editor.js'), 'Programm-Beschreibung mit dem Editor');
    $r = http_anfrage($basis, 'POST', '/team/programm', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'speichern', 'typ' => 'pause', 'titel' => 'Editor-Test', 'beschreibung' => '<p>Mit <b>fett</b><script>x()</script></p><ol><li>eins</li></ol>'], $b);
    pruefe(db_wert("SELECT beschreibung FROM programmpunkt WHERE titel = 'Editor-Test'") === '<p>Mit <strong>fett</strong></p><ol><li>eins</li></ol>', 'Beschreibung als bereinigtes HTML gespeichert (nummerierte Liste)');
    db_ausfuehren("DELETE FROM programmpunkt WHERE titel = 'Editor-Test'");
}

/** Passkeys über die Endpunkte, mit dem Software-Authenticator (E-55). Host localhost (Passkeys brauchen eine Domain). */
function passkey_http_tests(string $basis, string $tmp): void
{
    abschnitt('HTTP: Passkeys (E-55)');
    require_once __DIR__ . '/passkey_hilfe.php';
    $h = 'localhost';
    $ursprung = 'http://localhost:' . parse_url($basis, PHP_URL_PORT);
    $json = fn(array $r) => json_decode($r['text'], true) ?? [];
    $kp = konto_anlegen('Pia Passkey', 'pia.pk@example.org', 'teilnehmend', 'pias-passwort-1', null);
    db_ausfuehren('UPDATE konto SET email_bestaetigt_am = ? WHERE id = ?', [db_jetzt(), $kp]);
    $k = "$tmp/kekse-pk.txt";
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $k);
    pruefe(str_contains($r['text'], 'data-passkey-login') && str_contains($r['text'], 'Mit Passkey einloggen') && str_contains($r['text'], '/assets/passkey.js'), 'Login-Seite: „Mit Passkey einloggen“');
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'pia.pk@example.org', 'passwort' => 'pias-passwort-1'], $k);
    $r = http_anfrage($basis, 'GET', '/konto', $h, [], null, $k);
    $c = http_csrf($r['text']);
    pruefe(str_contains($r['text'], 'id="passkeys"') && str_contains($r['text'], 'data-passkey-anlage') && str_contains($r['text'], 'Noch kein Passkey'), 'Mein Konto: Abschnitt Passkeys');
    $r = http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'anlage_optionen', 'pw' => 'falsch'], $k);
    pruefe($r['status'] === 400 && str_contains($r['text'], 'Passwort stimmt nicht'), 'Passkey anlegen nur mit Passwort');
    pruefe(http_anfrage($basis, 'POST', '/passkey', $h, [], ['aktion' => 'login_optionen'], $k)['status'] === 403, 'Ohne CSRF-Token abgelehnt');
    $o = $json(http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'anlage_optionen', 'pw' => 'pias-passwort-1'], $k))['optionen'] ?? [];
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
    pruefe(($json($r)['ok'] ?? false) === true && ($json($r)['ziel'] ?? '') === '/konto', 'Einloggen mit Passkey ohne Passwort');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/konto', $h, [], null, $k)['text'], 'zuletzt benutzt'), 'Danach eingeloggt, Passkey als benutzt markiert');
    $o = $json(http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'login_optionen'], $k))['optionen'] ?? [];
    $r = http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'login', 'antwort' => json_encode(test_pk_login($g, (string)($o['challenge'] ?? ''), 'localhost', 'https://boese.example'))], $k);
    pruefe($r['status'] === 400 && str_contains($r['text'], 'nicht angenommen'), 'Fremder Ursprung abgelehnt');
    // Team-Konto: beim ersten Login Passkey als zweiter Faktor, dann Wiederherstellungscodes
    konto_anlegen('Tom Team', 'tom.pk@example.org', 'admin', 'toms-passwort-123', null);
    $kt = "$tmp/kekse-pk-team.txt";
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $kt);
    http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'passwort', 'email' => 'tom.pk@example.org', 'passwort' => 'toms-passwort-123'], $kt);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $kt);
    pruefe(str_contains($r['text'], 'data-passkey-anlage') && str_contains($r['text'], 'Passkey anlegen') && str_contains($r['text'], 'Möglichkeit 3'), 'Erster Team-Login: Passkey als erste Wahl');
    $c = http_csrf($r['text']);
    $o = $json(http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'anlage_optionen'], $kt))['optionen'] ?? [];
    $gt = test_pk_geraet();
    $r = http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'anlegen', 'antwort' => json_encode(test_pk_anlage($gt, (string)($o['challenge'] ?? ''), 'localhost', $ursprung))], $kt);
    pruefe(($json($r)['ziel'] ?? '') === '/einloggen?codes=1', 'Einrichtung mit Passkey abgeschlossen');
    $r = http_anfrage($basis, 'GET', '/einloggen?codes=1', $h, [], null, $kt);
    pruefe(substr_count($r['text'], '<li class="mono">') === TOTP_CODES, 'Danach Wiederherstellungscodes, einmal');
    pruefe(http_anfrage($basis, 'GET', '/team', $h, [], null, $kt)['status'] === 200, 'Team-Bereich offen');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $kt);
    http_anfrage($basis, 'POST', '/ausloggen', $h, [], ['csrf' => http_csrf($r['text'])], $kt);
    $r = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $kt);
    $c = http_csrf($r['text']);
    $o = $json(http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'login_optionen'], $kt))['optionen'] ?? [];
    $r = http_anfrage($basis, 'POST', '/passkey', $h, [], ['csrf' => $c, 'aktion' => 'login', 'antwort' => json_encode(test_pk_login($gt, (string)($o['challenge'] ?? ''), 'localhost', $ursprung))], $kt);
    pruefe(($json($r)['ziel'] ?? '') === '/team' && http_anfrage($basis, 'GET', '/team', $h, [], null, $kt)['status'] === 200, 'Team: Passkey ersetzt Passwort und zweiten Faktor');
}

function team_ap5_http_tests(string $basis, string $h, string $tmp, string $b): void
{
    abschnitt('HTTP: Team-Bereich (AP-5)');
    foreach (['/team' => 'Zu erledigen', '/team/personen' => '+ Person anlegen', '/team/workshops' => 'Termin anklicken', '/team/statistik' => 'Anmeldungen seit Start', '/team/texte' => 'Datenschutzerklärung', '/team/protokoll' => 'Einträge', '/team/daten' => 'Hostnamen'] as $pfad => $text) {
        $r = http_anfrage($basis, 'GET', $pfad, $h, [], null, $b);
        pruefe($r['status'] === 200 && str_contains($r['text'], $text), "Seite $pfad");
    }
    pruefe(http_anfrage($basis, 'GET', '/team/personen', $h, [], null, "$tmp/kekse-niemand.txt")['status'] === 303, 'Personen nur mit Login');
    $r = http_anfrage($basis, 'GET', '/team/personen?neu=1', $h, [], null, $b);
    $c = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/team/personen', $h, [], ['csrf' => $c, 'aktion' => 'anlegen', 'vorname' => 'Petra', 'nachname' => 'Person', 'email' => 'petra@example.org', 'berufsgruppe' => 'Pflege/MFA', 'institution' => 'Station 3', 'essen' => 'vegetarisch'], $b);
    $pid = (int)db_wert("SELECT id FROM anmeldung WHERE email = 'petra@example.org'");
    pruefe($r['status'] === 303 && str_contains($r['kopf']['location'] ?? '', '?a=' . $pid) && db_wert('SELECT status FROM anmeldung WHERE id = ?', [$pid]) === 'bestaetigt', 'Person anlegen: bestätigt, weiter zur Person');
    pruefe(http_mail_an($tmp, 'petra@example.org', 20, 'ist bestätigt') !== '', 'Mail an die angelegte Person');
    $r = http_anfrage($basis, 'GET', '/team/personen?a=' . $pid, $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Anmeldung bearbeiten – auch nach den Stichtagen') && str_contains($r['text'], 'Gefahrenzone') && str_contains($r['text'], 'class="gewaehlt"'), 'Person ausgewählt: Bearbeiten, Gefahrenzone');
    $vorher = http_mail_anzahl($tmp, 'petra@example.org');
    $r = http_anfrage($basis, 'POST', '/team/personen?a=' . $pid, $h, [], ['csrf' => $c, 'aktion' => 'speichern', 'a' => $pid, 'vorname' => 'Petra', 'nachname' => 'Person', 'email' => 'petra@example.org', 'berufsgruppe' => 'Pflege/MFA', 'institution' => 'Station 4', 'essen' => 'kein', 'ohne_mail' => '1'], $b);
    usleep(400_000);
    pruefe($r['status'] === 303 && db_wert('SELECT essen FROM anmeldung WHERE id = ?', [$pid]) === 'kein' && db_wert('SELECT institution FROM anmeldung WHERE id = ?', [$pid]) === 'Station 4' && http_mail_anzahl($tmp, 'petra@example.org') === $vorher, 'Ändern „ohne Mail“: gespeichert, keine Mail');
    einstellung_setzen('stichtag_essen', gmdate('Y-m-d', time() - 86400));
    $r = http_anfrage($basis, 'POST', '/team/personen?a=' . $pid, $h, [], ['csrf' => $c, 'aktion' => 'speichern', 'a' => $pid, 'vorname' => 'Petra', 'nachname' => 'Person', 'email' => 'petra@example.org', 'berufsgruppe' => 'Pflege/MFA', 'institution' => 'Station 4', 'essen' => 'egal'], $b);
    pruefe(db_wert('SELECT essen FROM anmeldung WHERE id = ?', [$pid]) === 'egal' && http_mail_an($tmp, 'petra@example.org', 20, 'das Team hat deine Anmeldung aktualisiert') !== '', 'Team ändert nach dem Stichtag, Mail „vom Team“ (T-144)');
    db_ausfuehren("DELETE FROM va_einstellung WHERE schluessel = 'stichtag_essen'");
    http_anfrage($basis, 'POST', '/team/personen?a=' . $pid, $h, [], ['csrf' => $c, 'aktion' => 'link', 'a' => $pid], $b);
    pruefe(http_mail_an($tmp, 'petra@example.org', 20, 'siehst und änderst du deine Anmeldung') !== '', 'Einmal-Link senden');
    $r = http_anfrage($basis, 'GET', '/team/workshops?t=0', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Petra Person') && str_contains($r['text'], 'Warteliste in Reihenfolge'), 'Workshops: Symposium mit Teilnehmenden');
    $r = http_anfrage($basis, 'GET', '/team/export?art=teilnehmende', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_starts_with($r['kopf']['content-type'] ?? '', 'text/csv') && str_starts_with($r['text'], "\u{FEFF}") && str_contains($r['text'], 'Petra') && str_contains($r['kopf']['content-disposition'] ?? '', '.csv'), 'Export als CSV (Excel)');
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
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Person, Petra') && str_contains($r['text'], 'Unterschriftenliste') && str_contains($r['text'], 'aria-current="page">Teilnahme<'), 'Teilnahme: Seite mit Liste, Navigation unter Anmeldungen');
    $c = http_csrf($r['text']);
    $pPlatz = (int)db_wert('SELECT id FROM platz WHERE anmeldung_id = ? AND termin_id = 0', [$pid]);
    $r = http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'erschienen', 'platz' => $pPlatz, 'wert' => '0'], $b);
    pruefe($r['status'] === 303 && str_ends_with($r['kopf']['location'] ?? '', '#a' . $pid) && (int)db_wert('SELECT nicht_erschienen FROM platz WHERE id = ?', [$pPlatz]) === 1, 'Nicht erschienen eintragen');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/team/teilnahme?f=fehlt', $h, [], null, $b)['text'], 'Person, Petra'), 'Filter „nicht erschienen“');
    http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'erschienen', 'platz' => $pPlatz, 'wert' => '1'], $b);
    pruefe((int)db_wert('SELECT nicht_erschienen FROM platz WHERE id = ?', [$pPlatz]) === 0, 'Doch da');
    pruefe(http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['aktion' => 'erschienen', 'platz' => $pPlatz, 'wert' => '0'], $b)['status'] === 403, 'Ohne CSRF abgelehnt');
    $r = http_anfrage($basis, 'GET', '/team/teilnahme/liste?liste=aerzte&sort=vorname', $h, [], null, $b);
    pruefe($r['status'] === 200 && ($r['kopf']['content-type'] ?? '') === 'application/pdf' && str_starts_with($r['text'], '%PDF-') && str_contains($r['kopf']['content-disposition'] ?? '', 'attachment'), 'Unterschriftenliste als PDF');
    $r = http_anfrage($basis, 'GET', '/team/teilnahme/zertifikat?beispiel=1', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_starts_with($r['text'], '%PDF-') && str_contains($r['kopf']['content-disposition'] ?? '', 'inline'), 'Vorschau mit Beispiel');
    pruefe(http_anfrage($basis, 'GET', '/team/teilnahme/zertifikat?a=' . $pid, $h, [], null, $b)['status'] === 200 && http_anfrage($basis, 'GET', '/team/teilnahme/zertifikat?a=999999', $h, [], null, $b)['status'] === 404, 'Vorschau je Person, unbekannt 404');
    pruefe(http_anfrage($basis, 'GET', '/team/teilnahme', $h)['status'] === 303 && http_anfrage($basis, 'GET', '/team/teilnahme/liste', $h)['status'] === 303, 'Teilnahme nur für das Team');
    // Unterschrift hochladen
    $bild = imagecreatetruecolor(200, 60);
    imagefill($bild, 0, 0, imagecolorallocate($bild, 255, 255, 255));
    imagepng($bild, "$tmp/unterschrift.png");
    $r = http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'unterschrift', 'bild' => new CURLFile("$tmp/unterschrift.png", 'image/png', 'u.png')], $b);
    $r2 = http_anfrage($basis, 'GET', '/team/teilnahme/unterschrift', $h, [], null, $b);
    pruefe($r['status'] === 303 && $r2['status'] === 200 && ($r2['kopf']['content-type'] ?? '') === 'image/png' && str_starts_with($r2['text'], "\x89PNG"), 'Unterschrift hochladen und anzeigen');
    file_put_contents("$tmp/falsch.png", '<?php echo 1;');
    http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'unterschrift', 'bild' => new CURLFile("$tmp/falsch.png", 'image/png', 'f.png')], $b);
    pruefe(str_contains(http_anfrage($basis, 'GET', '/team/teilnahme', $h, [], null, $b)['text'], 'Bitte die Unterschrift als PNG'), 'Keine Bilddatei: abgelehnt');
    http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'unterschrift_weg'], $b);
    pruefe(http_anfrage($basis, 'GET', '/team/teilnahme/unterschrift', $h, [], null, $b)['status'] === 404, 'Unterschrift entfernen');
    // Freischalten: vor dem Tag gesperrt, danach Mail und Download für die Person
    $datumAlt = va('veranstaltung_datum');
    einstellung_setzen('veranstaltung_datum', gmdate('Y-m-d', time() + 20 * 86400));
    http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'freischalten', 'a' => $pid], $b);
    pruefe(db_wert('SELECT zertifikat_am FROM anmeldung WHERE id = ?', [$pid]) === null, 'Vor dem Veranstaltungstag: nicht freigeschaltet');
    $kp = "$tmp/kekse-petra.txt";
    $lt = anm_link_erzeugen($pid, 'zugang', time() + 3600);
    $r = http_anfrage($basis, 'GET', '/link?t=' . $lt, $h, [], null, $kp);
    http_anfrage($basis, 'POST', '/link', $h, [], ['csrf' => http_csrf($r['text']), 't' => $lt], $kp);
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung/zertifikat', $h, [], null, $kp);
    $r2 = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $kp)['text'];
    pruefe($r['status'] === 303 && str_contains($r2, 'Teilnahmebescheinigung') && str_contains($r2, 'Person') && !str_contains($r2, 'Zertifikat herunterladen'), 'Ohne Freischaltung kein Download');
    einstellung_setzen('veranstaltung_datum', gmdate('Y-m-d', time() - 86400));
    $vorher = http_mail_anzahl($tmp, 'petra@example.org');
    http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'freischalten', 'a' => $pid], $b);
    pruefe(db_wert('SELECT zertifikat_am FROM anmeldung WHERE id = ?', [$pid]) !== null && http_mail_an($tmp, 'petra@example.org', 20, 'Teilnahmebescheinigung') !== '' && http_mail_anzahl($tmp, 'petra@example.org') === $vorher + 1, 'Freischalten mit Mail');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $kp)['text'], 'Zertifikat herunterladen'), 'Meine Anmeldung: Knopf zum Herunterladen');
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung/zertifikat', $h, [], null, $kp);
    pruefe($r['status'] === 200 && ($r['kopf']['content-type'] ?? '') === 'application/pdf' && str_starts_with($r['text'], '%PDF-') && str_contains($r['kopf']['content-disposition'] ?? '', 'Teilnahmebescheinigung-Person-'), 'Zertifikat herunterladen');
    pruefe(http_anfrage($basis, 'GET', '/deine-anmeldung/zertifikat', $h)['status'] === 303, 'Ohne Sitzung kein Download');
    http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'erschienen', 'platz' => $pPlatz, 'wert' => '0'], $b);
    pruefe(http_anfrage($basis, 'GET', '/deine-anmeldung/zertifikat', $h, [], null, $kp)['status'] === 303, 'Nachträglich „nicht erschienen“: kein Download');
    http_anfrage($basis, 'POST', '/team/teilnahme', $h, [], ['csrf' => $c, 'aktion' => 'erschienen', 'platz' => $pPlatz, 'wert' => '1'], $b);
    einstellung_setzen('veranstaltung_datum', $datumAlt);
    pruefe(db_wert("SELECT COUNT(*) FROM audit WHERE art = 'teilnahme'") >= 4, 'Teilnahme im Protokoll');
    pruefe(db_wert("SELECT COUNT(*) FROM audit WHERE art = 'export'") >= 1, 'Export im Protokoll');
    $r = http_anfrage($basis, 'POST', '/team/texte', $h, [], ['csrf' => $c, 'start_sticker' => 'Gratis!', 'impressum' => "## Verantwortlich\nTest Impressum"], $b);
    pruefe($r['status'] === 303 && str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], 'Gratis!') && str_contains(http_anfrage($basis, 'GET', '/impressum', $h)['text'], 'Test Impressum'), 'Texte: Startseite und Impressum sofort geändert');
    $r = http_anfrage($basis, 'GET', '/team/protokoll?art=texte', $h, [], null, $b);
    pruefe(str_contains($r['text'], 'start_sticker'), 'Protokoll mit Filter');
    $r = http_anfrage($basis, 'POST', '/team/personen?a=' . $pid, $h, [], ['csrf' => $c, 'aktion' => 'storno', 'a' => $pid, 'ohne_mail' => '1'], $b);
    pruefe($r['status'] === 303 && db_wert('SELECT COUNT(*) FROM anmeldung WHERE id = ?', [$pid]) == 0, 'Storno durch das Team');
}

/** Sicherung, Wiederherstellung, Testdaten (E-47); $codes: zwei unbenutzte Wiederherstellungscodes. */
function daten_http_tests(string $basis, string $h, string $tmp, string $b, array $codes): void
{
    abschnitt('HTTP: Daten – Sicherung und Testdaten (E-47)');
    $r = http_anfrage($basis, 'GET', '/team/daten', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Sicherung anlegen') && str_contains($r['text'], 'Testdaten laden') && str_contains($r['text'], 'href="/team/daten" aria-current'), 'Seite „Daten“ für Betreiber, Testdaten außerhalb der Produktion');
    pruefe(http_anfrage($basis, 'GET', '/team/daten', $h, [], null, "$tmp/kekse-niemand.txt")['status'] === 303, 'ohne Login kein Zugriff');
    $csrf = http_csrf($r['text']);
    pruefe(str_contains($r['text'], 'Mailversand') && str_contains($r['text'], 'Testmail an mich'), 'Kasten Mailversand mit Testmail');
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $csrf, 'aktion' => 'testmail'], $b);
    $f = http_anfrage($basis, 'GET', '/team/daten', $h, [], null, $b);
    pruefe($r['status'] === 303 && str_contains($f['text'], 'beim Mailserver angenommen') && http_mail_an($tmp, 'betrieb@example.org', 20, 'Diese Mail prüft den Versand') !== '', 'Testmail sofort versendet, Ergebnis angezeigt');
    pruefe(str_contains($f['text'], 'Version ' . app_version() . ' · Entwicklung'), 'Version in der Fußzeile (außerhalb der Produktion)');
    $alt = glob("$tmp/var/sicherungen/sicherung-*.jsonl.gz") ?: [];
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $csrf, 'aktion' => 'anlegen'], $b);
    $dateien = array_values(array_diff(glob("$tmp/var/sicherungen/sicherung-*.jsonl.gz") ?: [], $alt));
    pruefe($r['status'] === 303 && count($dateien) === 1, 'Sicherung angelegt (gzip im Laufzeitordner)');
    $name = basename($dateien[0] ?? 'x');
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $csrf, 'aktion' => 'herunterladen', 'name' => $name, 'totp' => '000000'], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Der Code aus deiner App stimmt nicht'), 'Herunterladen ohne gültigen Code abgelehnt');
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $csrf, 'aktion' => 'herunterladen', 'name' => $name, 'totp' => strtolower($codes[0])], $b);
    $erste = strtok((string)@gzdecode($r['text']), "\n");
    pruefe($r['status'] === 200 && ($r['kopf']['content-type'] ?? '') === 'application/gzip' && str_contains((string)$erste, '"art":"kopf"'), 'Herunterladen mit Code: gzip mit Kopfzeile');
    pruefe(http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $csrf, 'aktion' => 'herunterladen', 'name' => '../config.php', 'totp' => '1'], $b)['status'] === 200 && sicherung_pfad('../config.php') === null, 'nur gültige Sicherungsnamen');
    $vorher = ['punkte' => (int)db_wert('SELECT COUNT(*) FROM programmpunkt'), 'anm' => (int)db_wert('SELECT COUNT(*) FROM anmeldung'), 'konten' => (int)db_wert("SELECT COUNT(*) FROM konto WHERE rolle <> 'teilnehmend'")];
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $csrf, 'aktion' => 'testdaten'], $b);
    pruefe($r['status'] === 303 && db_wert('SELECT COUNT(*) FROM programmpunkt WHERE veranstaltung_id = 1') == 9 && db_wert('SELECT COUNT(*) FROM anmeldung WHERE veranstaltung_id = 1') == 3 && db_wert("SELECT COUNT(*) FROM veranstaltung WHERE status = 'abgeschlossen'") == 1 && (int)db_wert("SELECT COUNT(*) FROM konto WHERE rolle <> 'teilnehmend'") === $vorher['konten'], 'Testdaten geladen, Team-Konten unverändert');
    pruefe(str_contains(http_anfrage($basis, 'GET', '/', $h)['text'], 'Atemweg. Plan B.'), 'Startseite zeigt das Beispielprogramm');
    $vorTest = '';
    foreach (sicherung_liste() as $s) {
        if (($s['kopf']['anlass'] ?? '') === 'vor dem Laden der Testdaten') {
            $vorTest = $s['name'];
        }
    }
    $r = http_anfrage($basis, 'POST', '/team/daten', $h, [], ['csrf' => $csrf, 'aktion' => 'wiederherstellen', 'name' => $vorTest, 'totp' => strtolower($codes[1])], $b);
    pruefe($r['status'] === 303 && (int)db_wert('SELECT COUNT(*) FROM programmpunkt') === $vorher['punkte'] && (int)db_wert('SELECT COUNT(*) FROM anmeldung') === $vorher['anm'], 'Wiederherstellen: Stand vor den Testdaten zurück');
    pruefe(count(sicherung_liste()) === count($alt) + 3 && http_anfrage($basis, 'GET', '/team/daten', $h, [], null, $b)['status'] === 200, 'davor automatisch gesichert, Team bleibt eingeloggt');
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
    pruefe(str_contains($r['text'], 'name="totp"') && str_contains($r['text'], 'autocomplete="one-time-code"'), 'Code-Feld heißt totp (Bitwarden in Firefox)');
    $r2 = http_anfrage($basis, 'GET', '/einloggen', $h, [], null, $b);
    pruefe(http_totp_geheimnis($r2['text']) === $roh, 'Neuladen behält das Geheimnis');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'einrichten', 'totp' => '000000'], $b);
    pruefe(str_contains($r['text'], 'Der Code stimmt nicht'), 'falscher Einrichtungscode abgelehnt');
    $code1 = totp_code((string)$roh, time());
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => $csrf, 'aktion' => 'einrichten', 'totp' => $code1], $b);
    preg_match_all('/<li class="mono">([A-Z2-9]{4} [A-Z2-9]{4})<\/li>/', $r['text'], $m);
    $codes = $m[1];
    pruefe($r['status'] === 200 && count($codes) === 10, 'eingerichtet, zehn Wiederherstellungscodes angezeigt');
    pruefe(db_wert("SELECT COUNT(*) FROM konto_code WHERE hash LIKE '$%'") == 10 && !str_contains((string)db_wert('SELECT totp_geheimnis FROM konto'), totp_base32((string)$roh)), 'Codes nur als Hash, Geheimnis versiegelt');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Erste Betreiberin') && str_contains($r['text'], 'Ausloggen'), 'Team-Bereich mit Name und Ausloggen (E-40)');
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Neues Team-Konto'), 'Team-Konten für Betreiber');

    abschnitt('HTTP: Team-Konto anlegen und einrichten');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $csrf, 'aktion' => 'anlegen', 'name' => 'Anna Admin', 'email' => 'anna@example.org', 'rolle' => 'admin'], $b);
    pruefe($r['status'] === 303, 'Konto angelegt');
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $b);
    pruefe(!str_contains($r['text'], '/einrichten?t=') && str_contains($r['text'], 'Einladung an anna@example.org geschickt') && str_contains($r['text'], 'Einrichtung offen'), 'Einladung per Mail, Link nicht angezeigt (AP-6)');
    $mail = http_mail_an($tmp, 'anna@example.org', 20, 'Einladung in den Team-Bereich');
    $link = preg_match('#/einrichten\?t=([A-Za-z0-9_-]+)#', $mail, $m) === 1 ? $m[1] : '';
    pruefe($link !== '' && str_contains($mail, 'Rolle „Admin“'), 'Einladungsmail mit Einrichtungslink und Rolle');
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
    $r = http_anfrage($basis, 'POST', '/einloggen', $h, [], ['csrf' => http_csrf($r['text']), 'aktion' => 'einrichten', 'totp' => totp_code((string)$rohA, time())], $a);
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $a);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Anna Admin'), 'Admin eingeloggt');
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $a);
    pruefe($r['status'] === 403, 'Admin sieht keine Team-Konten (E-11)');
    pruefe(!str_contains(http_anfrage($basis, 'GET', '/team', $h, [], null, $a)['text'], 'href="/team/konten"'), 'Reiter Team-Konten für Admin ausgeblendet');

    abschnitt('HTTP: Rollenwechsel, Sperre, Sitzungen');
    $annaId = (int)db_wert("SELECT id FROM konto WHERE email = 'anna@example.org'");
    $r = http_anfrage($basis, 'GET', '/team/konten', $h, [], null, $b);
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $csrf, 'aktion' => 'rolle', 'id' => $annaId, 'rolle' => 'betreiber', 'totp' => '000000'], $b);
    pruefe(str_contains($r['text'], 'Die Rolle wurde nicht geändert') && db_wert('SELECT rolle FROM konto WHERE id = ?', [$annaId]) === 'admin', 'Rollenwechsel ohne gültigen eigenen Code abgelehnt');
    // Code einmal erzeugen und unten wiederverwenden (sonst kann eine 30-s-Grenze dazwischen liegen)
    $benutzt = totp_code((string)$roh, time() + 30);
    $r = http_anfrage($basis, 'POST', '/team/konten', $h, [], ['csrf' => $csrf, 'aktion' => 'rolle', 'id' => $annaId, 'rolle' => 'betreiber', 'totp' => $benutzt], $b);
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

    abschnitt('HTTP: Veranstaltungs-Einstellungen (AP-3 Schritt 2)');
    $r = http_anfrage($basis, 'GET', '/team/einstellungen', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'name="veranstaltung_ort"') && str_contains($r['text'], 'name="berufsgruppen"'), 'Einstellungsseite mit allen Feldern');
    pruefe(str_contains($r['text'], 'value="2026-11-25T00:00"'), 'Anmeldestart in Ortszeit vorbelegt');
    $form = ['csrf' => http_csrf($r['text'])];
    foreach (VA_KATALOG as $k => $_) {
        $form[$k] = va_formularwert($k);
    }
    $r = http_anfrage($basis, 'POST', '/team/einstellungen', $h, [], ['veranstaltung_ende' => '17:00'] + $form, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Nicht gespeichert') && str_contains($r['text'], 'aria-invalid="true"') && str_contains($r['text'], 'value="17:00"'), 'Fehler: markiert, Eingabe bleibt stehen');
    $r = http_anfrage($basis, 'POST', '/team/einstellungen', $h, [], ['veranstaltung_ort' => 'Klinikum Test', 'veranstaltung_kapazitaet' => '120'] + $form, $b);
    pruefe($r['status'] === 303 && va('veranstaltung_ort') === 'Klinikum Test', 'gespeichert');
    $r = http_anfrage($basis, 'GET', '/', $h);
    pruefe(str_contains($r['text'], 'Klinikum Test') && str_contains($r['text'], '120 / 120'), 'Startseite zeigt die neuen Werte sofort (T-121)');
    pruefe(db_wert("SELECT COUNT(*) FROM audit WHERE art = 'einstellungen'") == 1, 'Änderung im Audit-Protokoll');
    $r = http_anfrage($basis, 'POST', '/team/einstellungen', $h, [], ['veranstaltung_ort' => 'Klinikum Test', 'veranstaltung_kapazitaet' => '120'] + $form, "$tmp/kekse-leer.txt");
    pruefe($r['status'] === 403 || $r['status'] === 303, 'ohne Login nicht speicherbar');
    db_ausfuehren("DELETE FROM va_einstellung WHERE schluessel IN ('veranstaltung_ort', 'veranstaltung_kapazitaet')");

    programm_http_tests($basis, $h, $tmp, $b);
    anmeldung_http_tests($basis, $h, $tmp, $b);
    konto_http_tests($basis, $h, $tmp, $pw);
    daten_http_tests($basis, $h, $tmp, $b, [$codes[5], $codes[6]]);
    team_ap5_http_tests($basis, $h, $tmp, $b);
    team_ap6_http_tests($basis, $h, $tmp, $b);
    r30_http_tests($basis, $h, $tmp, $b);
    konto_alles_http_tests($basis, $h, $tmp, $b);
    passkey_http_tests($basis, $tmp);
    newsletter_http_tests($basis, $h, $tmp, $b);
    veranstaltung_http_tests($basis, $h, $tmp, $b);

    abschnitt('HTTP: Notzugang mit Installationsschlüssel (O-34)');
    $n = "$tmp/kekse-not.txt";
    $r = http_anfrage($basis, 'GET', '/notzugang', $h, [], null, $n);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Installationsschlüssel'), 'Notzugang erreichbar');
    $csrf = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/notzugang', $h, [], ['csrf' => $csrf, 'schluessel' => 'falsch', 'email' => 'betrieb@example.org'], $n);
    pruefe(str_contains($r['text'], 'stimmt nicht') && db_wert("SELECT totp_seit FROM konto WHERE email = 'betrieb@example.org'") !== null, 'falscher Schlüssel: nichts zurückgesetzt');
    $r = http_anfrage($basis, 'POST', '/notzugang', $h, [], ['csrf' => $csrf, 'schluessel' => str_repeat('i', 40), 'email' => 'niemand@example.org'], $n);
    pruefe(str_contains($r['text'], 'stimmt nicht'), 'unbekannte Adresse: gleiche Meldung');
    $r = http_anfrage($basis, 'POST', '/notzugang', $h, [], ['csrf' => $csrf, 'schluessel' => str_repeat('i', 40), 'email' => 'betrieb@example.org'], $n);
    $notlink = preg_match('#/einrichten\?t=([A-Za-z0-9_-]+)#', $r['text'], $m) === 1 ? $m[1] : '';
    pruefe($notlink !== '' && db_wert("SELECT totp_seit FROM konto WHERE email = 'betrieb@example.org'") === null, 'richtiger Schlüssel: zweiter Faktor zurückgesetzt, Link angezeigt');
    pruefe(db_wert("SELECT COUNT(*) FROM audit WHERE art = 'notzugang'") == 1, 'Notzugang im Audit-Protokoll');
    $r = http_anfrage($basis, 'GET', '/team', $h, [], null, $b);
    pruefe($r['status'] === 303, 'alte Sitzung des Kontos beendet');
    for ($i = 0; $i < RATE_TOEPFE['notzugang'][0]; $i++) {
        http_anfrage($basis, 'POST', '/notzugang', $h, [], ['csrf' => $csrf, 'schluessel' => 'falsch', 'email' => 'x@example.org'], $n);
    }
    $r = http_anfrage($basis, 'POST', '/notzugang', $h, [], ['csrf' => $csrf, 'schluessel' => str_repeat('i', 40), 'email' => 'betrieb@example.org'], $n);
    pruefe(str_contains($r['text'], 'Zu viele Versuche') && !str_contains($r['text'], '/einrichten?t='), 'nach drei Fehlversuchen gesperrt, auch mit richtigem Schlüssel');

    abschnitt('Notweg auf der Kommandozeile');
    $aus = [];
    exec('WS_CONFIG=' . escapeshellarg("$tmp/config.php") . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$wurzel/server/cli/konto.php") . ' zweitfaktor anna@example.org 2>&1', $aus, $code);
    pruefe($code === 0 && db_wert('SELECT totp_seit FROM konto WHERE id = ?', [$annaId]) === null, 'zweiten Faktor zurückgesetzt');
    $aus = [];
    exec('WS_CONFIG=' . escapeshellarg("$tmp/config.php") . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$wurzel/server/cli/konto.php") . ' link anna@example.org 2>&1', $aus, $code);
    pruefe($code === 0 && str_contains(implode('', $aus), '/einrichten?t='), 'neuen Link erzeugt');
}

function veranstaltung_http_tests(string $basis, string $h, string $tmp, string $b): void
{
    abschnitt('HTTP: Mehrere Veranstaltungen (E-58)');
    $v1 = (int)va_aktuell_id();
    $k1 = (string)va_zeile($v1)['kuerzel'];
    $r = http_anfrage($basis, 'GET', '/team/veranstaltungen', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], '/' . $k1) && str_contains($r['text'], 'Neue Veranstaltung') && str_contains($r['text'], 'id="va-wahl"'), 'Team: Seite Veranstaltungen mit Auswahl im Kopf');
    $c = http_csrf($r['text']);
    $r = http_anfrage($basis, 'POST', '/team/veranstaltungen', $h, [], ['csrf' => $c, 'aktion' => 'anlegen', 'name' => 'Sommerabend', 'kuerzel' => 'team', 'vorlage' => $v1], $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'schon eine Adresse'), 'Kürzel „team“ abgelehnt');
    $r = http_anfrage($basis, 'POST', '/team/veranstaltungen', $h, [], ['csrf' => $c, 'aktion' => 'anlegen', 'name' => 'Sommerabend', 'kuerzel' => 'sommerabend', 'vorlage' => $v1], $b);
    $v2 = (int)(va_per_kuerzel('sommerabend')['id'] ?? 0);
    pruefe($r['status'] === 303 && $v2 > 0 && str_contains($r['kopf']['location'] ?? '', '/team/einstellungen?va=' . $v2), 'Neue Veranstaltung angelegt, weiter zu ihren Einstellungen');
    $r = http_anfrage($basis, 'GET', '/team/einstellungen?va=' . $v2, $h, [], null, $b);
    pruefe(str_contains($r['text'], 'Entwurf – noch nicht öffentlich') && str_contains($r['text'], 'value="sommerabend"') && str_contains($r['text'], 'Sommerabend'), 'Team arbeitet in der gewählten Veranstaltung (Entwurf)');
    $r = http_anfrage($basis, 'GET', '/team/personen', $h, [], null, $b);
    pruefe($r['status'] === 200 && !str_contains($r['text'], 'Person, Petra') && !str_contains($r['text'], 'Petra Person'), 'Personen: nur die der gewählten Veranstaltung');
    pruefe(http_anfrage($basis, 'GET', '/sommerabend', $h)['status'] === 404 && http_anfrage($basis, 'GET', '/' . $k1, $h)['status'] === 200, 'Entwurf nicht öffentlich, aktuelle unter ihrem Kürzel');
    // Abschließen: nur noch lesen
    http_anfrage($basis, 'GET', '/team?va=' . $v1, $h, [], null, $b);
    $r = http_anfrage($basis, 'POST', '/team/veranstaltungen', $h, [], ['csrf' => $c, 'aktion' => 'abschliessen', 'id' => $v1], $b);
    pruefe($r['status'] === 303 && va_zeile($v1)['status'] === 'abgeschlossen', 'Abschließen');
    $r = http_anfrage($basis, 'POST', '/team/einstellungen', $h, [], ['csrf' => $c, 'veranstaltung_ort' => 'Geändert'], $b);
    $r2 = http_anfrage($basis, 'GET', '/team/einstellungen', $h, [], null, $b);
    pruefe($r['status'] === 303 && str_contains($r2['text'], 'nur noch zum Ansehen') && va_von($v1, 'veranstaltung_ort') !== 'Geändert', 'Abgeschlossen: Speichern abgewiesen');
    $r = http_anfrage($basis, 'GET', '/' . $k1, $h);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Diese Veranstaltung ist vorbei') && !str_contains($r['text'], 'Jetzt anmelden'), 'Archiv-Seite unter dem Kürzel, ohne Anmeldung');
    pruefe(str_starts_with(http_anfrage($basis, 'GET', '/team/export?art=teilnehmende', $h, [], null, $b)['text'], "\u{FEFF}"), 'Exporte gehen weiter');
    $r = http_anfrage($basis, 'POST', '/team/veranstaltungen', $h, [], ['csrf' => $c, 'aktion' => 'aktuell', 'id' => $v2], $b);
    $r2 = http_anfrage($basis, 'GET', '/', $h);
    va_zwischenspeicher_leeren(); // Zwischenspeicher dieses Testprozesses, nicht der Seite
    pruefe(va_aktuell_id() === $v2 && $r2['status'] === 200 && !str_contains($r2['text'], 'Diese Veranstaltung ist vorbei'), 'Neue aktuelle Veranstaltung auf der Startseite');
    // Zurück: Veranstaltung 1 wieder aktuell, die neue entfernen
    db_ausfuehren('DELETE FROM programmpunkt WHERE veranstaltung_id = ?', [$v2]);
    db_ausfuehren('DELETE FROM veranstaltung WHERE id = ?', [$v2]);
    db_ausfuehren("UPDATE veranstaltung SET status = 'aktuell', abgeschlossen_am = NULL WHERE id = ?", [$v1]);
    va_zwischenspeicher_leeren();
    http_anfrage($basis, 'GET', '/team?va=' . $v1, $h, [], null, $b);
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

        team_login_tests($basis, $h, $tmp, $wurzel);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
}
