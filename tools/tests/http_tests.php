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
    pruefe(str_contains($r['text'], 'Gespeichert, aber nicht veröffentlicht') && substr_count($r['text'], '+ neu') >= 4, 'Banner und Markierung „neu“');
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
    pruefe(str_contains($r['text'], '✎ nicht veröffentlicht') && str_contains($r['text'], 'Gespeichert, aber nicht veröffentlicht'), 'geändert markiert');
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
    pruefe(str_contains($r['text'], 'Lena Test</span>') && str_contains($r['text'], 'value="ausloggen"'), 'Navigation zeigt Name und „Ausloggen“ (E-40)');
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
    db_ausfuehren("DELETE FROM einstellung WHERE schluessel = 'stichtag_workshop'");
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
    $r = http_anfrage($basis, 'GET', '/team/anmeldungen', $h, [], null, $b);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Lena Test') && str_contains($r['text'], 'neu@example.org') && str_contains($r['text'], 'href="/team/anmeldungen" aria-current'), 'Team: Liste der Anmeldungen');
    pruefe(http_anfrage($basis, 'GET', '/team/anmeldungen', $h, [], null, "$tmp/kekse-niemand.txt")['status'] === 303, 'Team-Liste nur mit Login');
    $r = http_anfrage($basis, 'GET', '/', $h);
    pruefe(preg_match('~Freie Plätze</dt><dd>239 / 240~u', $r['text']) === 1, 'Startseite: freie Plätze X / Y (E-40)');
    $r = http_anfrage($basis, 'POST', '/deine-anmeldung', $h, [], ['csrf' => $csrf, 'aktion' => 'storno'], $k1);
    pruefe($r['status'] === 303 && db_wert('SELECT COUNT(*) FROM anmeldung') == 0 && db_wert('SELECT COUNT(*) FROM platz') == 0, 'Storno löscht Anmeldung und Plätze');
    pruefe(http_mail_an($tmp, 'neu@example.org', 20, 'ist storniert') !== '', 'Storno-Mail');
    $r = http_anfrage($basis, 'GET', '/deine-anmeldung', $h, [], null, $k1);
    pruefe(str_contains($r['text'], 'Wie willst du rein?'), 'danach keine Sitzung mehr');
    db_ausfuehren("DELETE FROM einstellung WHERE schluessel = 'anmeldung_start'");
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
    db_ausfuehren("DELETE FROM einstellung WHERE schluessel IN ('veranstaltung_ort', 'veranstaltung_kapazitaet')");

    programm_http_tests($basis, $h, $tmp, $b);
    anmeldung_http_tests($basis, $h, $tmp, $b);

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
        inhalt_setzen('impressum', "## Angaben\nPhilipp Chadid\n\nKontakt: [admin@winter-notfallsymposium.de](mailto:admin@winter-notfallsymposium.de)");
        $r = http_anfrage($basis, 'GET', '/impressum', $h);
        pruefe(str_contains($r['text'], '<h2>Angaben</h2>') && str_contains($r['text'], 'href="mailto:admin@winter-notfallsymposium.de"'), 'Impressum zeigt gepflegten Text');

        team_login_tests($basis, $h, $tmp, $wurzel);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
}
