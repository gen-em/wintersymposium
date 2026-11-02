<?php
declare(strict_types=1);

/*
 * Mailversand (AP-4, Grundlage aus AP-6 vorgezogen; E-13, R-02, R-08).
 *
 * Jede Mail geht zuerst in die Warteschlange (Tabelle mail_ausgang) und wird
 * dann versendet: direkt nach der Antwort an den Browser (mail_nach_antwort)
 * und spätestens beim nächsten Job-Lauf. So bleibt die Antwortzeit gleich,
 * egal ob eine Mail entsteht (R-03), und ein langsamer Mailserver hält keine
 * Seite auf. Nach dem Versand werden Empfänger, Betreff und Text geleert
 * (R-02); stehen bleibt nur, dass und wann versendet wurde (Tageszähler).
 *
 * Zwei Postfächer (E-13, E-50): 'system' (anmeldung@, Bestätigungen, Links)
 * und 'newsletter' (newsletter@, Rundmails), jedes mit eigenem Tageslimit.
 * Rundmails (AP-6) stehen nur als Verweis in der Warteschlange und werden
 * erst beim Versand aus Mailing und Anmeldung zusammengesetzt (rundmail_lib).
 *
 * Konfiguration in config.php unter 'mail' (siehe config.example.php):
 *   modus 'smtp'  – Versand über SMTP (implizites TLS oder STARTTLS)
 *   modus 'datei' – nur für Tests: jede Mail als Datei in var/mails/
 * Ohne Eintrag gilt in der Entwicklung 'datei', sonst 'smtp'; fehlt dann der
 * Host, bleiben Mails in der Warteschlange und der Team-Bereich warnt.
 *
 * SMTP-Client nach NAdoku smtp.php: ein Zeitbudget für den ganzen Versand
 * (nicht je Zeile), Empfänger ohne Zeilenumbrüche, keine Adressen im Protokoll.
 */

/** Priorität: System-Mails (Bestätigung, Links, Angebote) vor Rundmails (AP-6). */
const MAIL_PRIO_SYSTEM = 1;
const MAIL_PRIO_RUNDMAIL = 5;
/** Zeitbudget je SMTP-Versand in Sekunden (Hoster: 5 s, R-08). */
const MAIL_SMTP_FRIST_S = 5;
/** Anteil des Tageslimits, den Rundmails über das System-Postfach frei lassen (für Bestätigungen und Links). */
const MAIL_SYSTEM_RESERVE = 0.2;
const MAIL_POSTFAECHER = ['system' => 'System (anmeldung@)', 'newsletter' => 'Newsletter (newsletter@)'];
/** Wiederholungen nach einem Fehlschlag (Minuten bis zum nächsten Versuch). */
const MAIL_WIEDERHOLUNG_MIN = [5, 30, 120, 360];
/** Versendete und fehlgeschlagene Einträge werden nach so vielen Tagen gelöscht. */
const MAIL_AUFBEWAHREN_TAGE = 30;

function mail_konfig(string $postfach = 'system'): array
{
    $c = konfig('mail', []);
    $c = is_array($c) ? $c : [];
    $nl = is_array($c['newsletter'] ?? null) ? $c['newsletter'] : null;
    unset($c['newsletter']);
    $c += [
        'modus' => entwicklung() ? 'datei' : 'smtp',
        'host' => '',
        'port' => 465,
        'verschluesselung' => 'ssl',
        'benutzer' => '',
        'passwort' => '',
        'absender' => '',
        'absender_name' => 'Wintersymposium Notfallmedizin',
        'antwort_an' => '',
        'tageslimit' => 450,
        'umleiten_an' => '',
    ];
    $c['postfach'] = 'system';
    if ($postfach === 'newsletter') {
        // Zweites Postfach: eigene Anmeldung, Absender und Tageslimit; Server wie beim System-Postfach
        $c['postfach'] = 'newsletter';
        $c['eingetragen'] = $nl !== null;
        $c = array_merge($c, array_intersect_key($nl ?? [], array_flip(['host', 'port', 'verschluesselung', 'benutzer', 'passwort', 'absender', 'absender_name', 'tageslimit'])));
        if ($nl === null || !isset($nl['absender'])) {
            $c['absender'] = '';
        }
    }
    return $c;
}

/** Ist das Postfach nutzbar? newsletter@ braucht einen eigenen Eintrag in config.php (außer im Datei-Modus). */
function mail_postfach_da(string $postfach): bool
{
    if ($postfach === 'system') {
        return mail_eingerichtet();
    }
    $c = mail_konfig('newsletter');
    return $c['modus'] === 'datei' || (!empty($c['eingetragen']) && trim((string)$c['host']) !== '');
}

/** Tageslimit eines Postfachs und die Grenze, bis zu der Rundmails darüber gehen. */
function mail_limit(string $postfach): array
{
    $limit = max(0, (int)mail_konfig($postfach)['tageslimit']);
    return ['limit' => $limit, 'rundmail' => $postfach === 'system' ? (int)floor($limit * (1 - MAIL_SYSTEM_RESERVE)) : $limit];
}

/** Absender: aus der Konfiguration, sonst anmeldung@ der Haupt-Domain (E-13). */
function mail_absender(array $c): string
{
    $nl = ($c['postfach'] ?? 'system') === 'newsletter';
    // Einstellung im Team-Bereich (Betrieb, E-27/AP-5) vor config.php
    $a = trim((string)(einstellung($nl ? 'mail_absender_newsletter' : 'mail_absender') ?? ''));
    if ($a !== '') {
        return $a;
    }
    $a = trim((string)$c['absender']);
    return $a !== '' ? $a : ($nl ? 'newsletter@' : 'anmeldung@') . (host_haupt() ?? 'localhost');
}

/** Anzeigename des Absenders: Einstellung vor config.php. */
function mail_absender_name(array $c): string
{
    $nl = ($c['postfach'] ?? 'system') === 'newsletter';
    // System-Postfach: Dachmarke (E-67, E-71); Newsletter: eigene Einstellung, sonst die Dachmarke
    $n = $nl ? trim((string)(einstellung('mail_absender_name_newsletter') ?? '')) : '';
    return $n !== '' ? $n : reihe_name();
}

/** Ist der Versand eingerichtet? (Datei-Modus zählt als eingerichtet.) */
function mail_eingerichtet(): bool
{
    $c = mail_konfig();
    if ($c['modus'] === 'datei') {
        return true;
    }
    return trim((string)$c['host']) !== '';
}

/** Adresse für den Versand prüfen und normieren (klein, ohne Leerraum) oder null. */
function mail_adresse(string $roh): ?string
{
    $e = mb_strtolower(trim($roh));
    if ($e === '' || strlen($e) > 190 || strcspn($e, "\r\n") !== strlen($e) || filter_var($e, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    return $e;
}

/** Mail in die Warteschlange stellen. Gibt die ID zurück. */
function mail_einreihen(string $an, string $betreff, string $text, int $prio = MAIL_PRIO_SYSTEM, ?string $html = null, ?string $absenderName = null): int
{
    $adresse = mail_adresse($an);
    if ($adresse === null) {
        throw new InvalidArgumentException('Unbrauchbare Empfängeradresse.');
    }
    $jetzt = db_jetzt();
    db_ausfuehren(
        "INSERT INTO mail_ausgang (an, betreff, text, html, absender_name, prio, status, versuche, naechster_versuch, erstellt_am) VALUES (?, ?, ?, ?, ?, ?, 'offen', 0, ?, ?)",
        [$adresse, $betreff, $text, $html, $absenderName, $prio, $jetzt, $jetzt]
    );
    $id = (int)db()->lastInsertId();
    mail_nach_antwort_vormerken();
    return $id;
}

/** Im Web nach der Antwort senden (auch nach einer Umleitung mit exit); Jobs und Tests senden selbst. */
function mail_nach_antwort_vormerken(): void
{
    ctx_setzen('mail_neu', true);
    if (PHP_SAPI !== 'cli' && empty(ctx('mail_shutdown'))) {
        ctx_setzen('mail_shutdown', true);
        register_shutdown_function('mail_nach_antwort');
    }
}

/** Mail aus einer Vorlage einreihen. */
function mail_vorlage_einreihen(string $an, string $vorlage, array $daten, int $prio = MAIL_PRIO_SYSTEM): int
{
    [$betreff, $text] = mail_vorlage($vorlage, $daten);
    // HTML-Fassung im Erscheinungsbild (O-40); der Text bleibt für Programme ohne HTML
    // Absender (E-67): Veranstaltung bei Mails zu einer Anmeldung, sonst die Reihe
    $absender = in_array($vorlage, MAIL_VORLAGEN_ALLGEMEIN, true) ? reihe_name() : va_absender_name();
    return mail_einreihen($an, $betreff, $text, $prio, mail_html_aus_text($vorlage, $betreff, $text), $absender);
}

/** Heute (Ortszeit) versendete Mails – für das Tageslimit (je Postfach oder alle). */
function mail_heute_gesendet(?int $jetzt = null, ?string $postfach = null): int
{
    $jetzt ??= time();
    $tag = (new DateTimeImmutable('@' . $jetzt))->setTimezone(new DateTimeZone(ANZEIGE_ZEITZONE))->format('Y-m-d');
    $ab = lokal_nach_utc($tag . ' 00:00:00');
    if ($postfach === null) {
        return db_zahl("SELECT COUNT(*) FROM mail_ausgang WHERE status = 'gesendet' AND gesendet_am >= ?", [$ab]);
    }
    return db_zahl("SELECT COUNT(*) FROM mail_ausgang WHERE status = 'gesendet' AND gesendet_am >= ? AND postfach = ?", [$ab, $postfach]);
}

/**
 * Fällige Mails senden: System-Mails immer, Rundmails nur bis zum Tageslimit
 * ihres Postfachs – über anmeldung@ nur bis 80 %, damit Bestätigungen Platz
 * haben (der Rest wartet auf den nächsten Tag, E-50). Höchstens $max Mails und
 * etwa $budget Sekunden. Gibt Zahlen zurück: gesendet, fehler, wartet.
 */
function mail_senden_faellige(int $max = 20, float $budget = 20.0, ?int $jetzt = null): array
{
    $jetzt ??= time();
    $ende = microtime(true) + $budget;
    $erg = ['gesendet' => 0, 'fehler' => 0, 'wartet' => 0];
    if (!mail_eingerichtet()) {
        $erg['wartet'] = db_zahl("SELECT COUNT(*) FROM mail_ausgang WHERE status = 'offen'");
        return $erg;
    }
    $grenze = ['system' => mail_limit('system')['rundmail'], 'newsletter' => mail_limit('newsletter')['rundmail']];
    $jetztDb = db_zeit($jetzt);
    // Hängengebliebene Versuche (Abbruch mitten im Versand) nach 10 min freigeben
    db_ausfuehren("UPDATE mail_ausgang SET status = 'offen' WHERE status = 'sendet' AND naechster_versuch < ?", [db_zeit($jetzt - 600)]);
    for ($i = 0; $i < $max && microtime(true) < $ende; $i++) {
        $z = db_zeile(
            "SELECT id, an, betreff, text, html, absender_name, prio, postfach, rundmail_id, anmeldung_id, nl_person_id, versuche FROM mail_ausgang
             WHERE status = 'offen' AND naechster_versuch <= ?
               AND (prio = ? OR (postfach = 'system' AND ? = 1) OR (postfach = 'newsletter' AND ? = 1))
             ORDER BY prio, id LIMIT 1",
            // Limit in PHP vergleichen: als Parameter kämen die Zahlen als Text an, und MySQL verglich „4“ < „360“ als Text (falsch)
            [$jetztDb, MAIL_PRIO_SYSTEM, mail_heute_gesendet($jetzt, 'system') < $grenze['system'] ? 1 : 0, mail_heute_gesendet($jetzt, 'newsletter') < $grenze['newsletter'] ? 1 : 0]
        );
        if ($z === null) {
            break;
        }
        // Eintrag für diesen Lauf beanspruchen (zwei gleichzeitige Läufe senden nicht doppelt)
        $n = db_ausfuehren("UPDATE mail_ausgang SET status = 'sendet', naechster_versuch = ? WHERE id = ? AND status = 'offen'", [$jetztDb, $z['id']])->rowCount();
        if ($n !== 1) {
            continue;
        }
        $opt = ['postfach' => $z['postfach']] + ($z['html'] !== null ? ['html' => $z['html']] : []) + ($z['absender_name'] !== null ? ['absender_name' => $z['absender_name']] : []);
        $betreff = $z['betreff'];
        $text = $z['text'];
        $fehler = null;
        if ($z['rundmail_id'] !== null) {
            // Rundmail erst jetzt zusammensetzen. Gelöscht wird der Eintrag nur, wenn die
            // Anmeldung storniert oder die Person abgemeldet ist; fehlt das Mailing selbst,
            // ist das ein Fehler mit Wiederholung (Review B-1)
            if (rm_laden((int)$z['rundmail_id']) === null) {
                $fehler = 'Mailing ' . (int)$z['rundmail_id'] . ' nicht gefunden';
            } else {
                $m = $z['nl_person_id'] !== null ? rm_mail_fuer_nl((int)$z['rundmail_id'], (int)$z['nl_person_id']) : rm_mail_fuer((int)$z['rundmail_id'], (int)$z['anmeldung_id']);
                if ($m === null) {
                    db_ausfuehren('DELETE FROM mail_ausgang WHERE id = ?', [$z['id']]);
                    continue;
                }
                [$betreff, $text] = [$m['betreff'], $m['text']];
                $opt = ['postfach' => $z['postfach'], 'html' => $m['html'], 'bilder' => $m['bilder'], 'rundmail' => true, 'kopf' => $m['kopf'] ?? [], 'absender_name' => $m['absender_name'] ?? null];
            }
        }
        $fehler ??= mail_zustellen($z['an'], $betreff, $text, $opt);
        if ($fehler === null) {
            // R-02: Inhalt und Empfänger nach dem Versand leeren
            db_ausfuehren("UPDATE mail_ausgang SET status = 'gesendet', gesendet_am = ?, an = '', betreff = '', text = '', html = NULL, absender_name = NULL, fehler = NULL WHERE id = ?", [db_zeit(max($jetzt, time())), $z['id']]);
            $erg['gesendet']++;
        } else {
            $versuche = (int)$z['versuche'] + 1;
            if ($versuche > count(MAIL_WIEDERHOLUNG_MIN)) {
                db_ausfuehren("UPDATE mail_ausgang SET status = 'fehler', versuche = ?, fehler = ? WHERE id = ?", [$versuche, $fehler, $z['id']]);
            } else {
                $naechster = db_zeit($jetzt + 60 * MAIL_WIEDERHOLUNG_MIN[$versuche - 1]);
                db_ausfuehren("UPDATE mail_ausgang SET status = 'offen', versuche = ?, naechster_versuch = ?, fehler = ? WHERE id = ?", [$versuche, $naechster, $fehler, $z['id']]);
            }
            error_log('Mail ' . $z['id'] . ': ' . $fehler);
            $erg['fehler']++;
        }
    }
    $erg['wartet'] = db_zahl("SELECT COUNT(*) FROM mail_ausgang WHERE status = 'offen'");
    return $erg;
}

/**
 * Nach der Antwort an den Browser senden, was diese Anfrage eingereiht hat.
 * Der Browser wartet nicht darauf (gleiche Antwortzeit, R-03).
 */
function mail_nach_antwort(): void
{
    if (empty(ctx('mail_neu'))) {
        return;
    }
    ctx_setzen('mail_neu', false);
    antwort_abschliessen();
    try {
        mail_senden_faellige(5, 12.0);
    } catch (Throwable $ex) {
        error_log('Mailversand nach der Antwort: ' . $ex->getMessage());
    }
}

/** Antwort an den Browser abschließen, das Skript läuft weiter (Muster NAdoku smtp.php). */
function antwort_abschliessen(): void
{
    ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    foreach (['fastcgi_finish_request', 'litespeed_finish_request'] as $fn) {
        if (function_exists($fn)) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
            $fn();
            return;
        }
    }
    if (headers_sent()) {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
        return;
    }
    $inhalt = '';
    while (ob_get_level() > 0) {
        $stueck = ob_get_clean();
        if ($stueck !== false) {
            $inhalt = $stueck . $inhalt;
        }
    }
    header('Connection: close');
    header('Content-Length: ' . strlen($inhalt));
    echo $inhalt;
    @flush();
}

/**
 * Eine Mail zustellen. Gibt null bei Erfolg zurück, sonst einen Grund (ohne Empfänger).
 * $opt: postfach ('system'|'newsletter'), html (Text), bilder ([cid => [typ, pfad]]), rundmail (bool).
 */
function mail_zustellen(string $an, string $betreff, string $text, array $opt = []): ?string
{
    $postfach = (string)($opt['postfach'] ?? 'system');
    if (!mail_postfach_da($postfach)) {
        return 'Postfach ' . $postfach . ' ist nicht eingerichtet';
    }
    $c = mail_konfig($postfach);
    // Staging: alles an eine Adresse umleiten, der eigentliche Empfänger steht im Betreff (E-47)
    $um = mail_adresse((string)($c['umleiten_an'] ?? ''));
    if ($um !== null) {
        $betreff = '[an ' . $an . '] ' . $betreff;
        $an = $um;
    }
    $nachricht = mail_nachricht($c, $an, $betreff, $text, $opt);
    if ($c['modus'] === 'datei') {
        // Name sortierbar nach Zeit (Mikrosekunden), damit Tests die neueste Mail finden
        $datei = laufzeit_pfad('mails') . '/' . sprintf('%.6f', microtime(true)) . '-' . bin2hex(random_bytes(3)) . '.eml';
        return @file_put_contents($datei, $nachricht) === false ? 'Datei nicht schreibbar' : null;
    }
    return smtp_senden($c, $an, $nachricht);
}

/** Text mit CRLF, quoted-printable. */
function mail_qp(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return quoted_printable_encode(str_replace("\n", "\r\n", $text));
}

/**
 * Vollständige Nachricht. Ohne HTML: text/plain. Mit HTML (Rundmails):
 * multipart/alternative (Text und HTML), mit Bildern zusätzlich
 * multipart/related, die Bilder eingebettet über Content-ID (E-41).
 */
function mail_nachricht(array $c, string $an, string $betreff, string $text, array $opt = []): string
{
    $absender = mail_absender($c);
    $domain = substr(strrchr($absender, '@') ?: '@localhost', 1);
    $kopf = [
        'From: ' . mb_encode_mimeheader((string)($opt['absender_name'] ?? null ?: mail_absender_name($c)), 'UTF-8', 'Q') . ' <' . $absender . '>',
        'To: <' . $an . '>',
        'Subject: ' . mb_encode_mimeheader(trim(preg_replace('/[\r\n]+/', ' ', $betreff) ?? ''), 'UTF-8', 'Q'),
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
    ];
    if (empty($opt['rundmail'])) {
        $kopf[] = 'Auto-Submitted: auto-generated';
    }
    if (trim((string)$c['antwort_an']) !== '') {
        $kopf[] = 'Reply-To: <' . trim((string)$c['antwort_an']) . '>';
    }
    // Zusätzliche Kopfzeilen (Newsletter: List-Unsubscribe), ohne Zeilenumbrüche
    foreach ($opt['kopf'] ?? [] as $k) {
        $kopf[] = str_replace(["\r", "\n"], '', (string)$k);
    }
    $html = $opt['html'] ?? null;
    if ($html === null) {
        $kopf[] = 'Content-Type: text/plain; charset=UTF-8';
        $kopf[] = 'Content-Transfer-Encoding: quoted-printable';
        return implode("\r\n", $kopf) . "\r\n\r\n" . mail_qp($text) . "\r\n";
    }
    $bilder = $opt['bilder'] ?? [];
    if (str_contains((string)$html, MAIL_LOGO_PLATZHALTER)) {
        // Logo eingebettet (O-40): erscheint ohne Nachladen, auch wenn Bilder von außen gesperrt sind
        $html = mail_logo_einsetzen((string)$html, 'cid:logo@' . $domain);
        $bilder = ['logo' => mail_logo()] + $bilder;
    }
    $ga = 'alt-' . bin2hex(random_bytes(8));
    $alt = '--' . $ga . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . mail_qp($text) . "\r\n"
        . '--' . $ga . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . mail_qp((string)$html) . "\r\n"
        . '--' . $ga . "--\r\n";
    if ($bilder === []) {
        $kopf[] = 'Content-Type: multipart/alternative; boundary="' . $ga . '"';
        return implode("\r\n", $kopf) . "\r\n\r\n" . $alt;
    }
    $gr = 'rel-' . bin2hex(random_bytes(8));
    $kopf[] = 'Content-Type: multipart/related; type="multipart/alternative"; boundary="' . $gr . '"';
    $teile = '--' . $gr . "\r\nContent-Type: multipart/alternative; boundary=\"" . $ga . "\"\r\n\r\n" . $alt;
    foreach ($bilder as $cid => [$typ, $pfad]) {
        $name = $cid . ($typ === 'png' ? '.png' : '.jpg');
        $teile .= '--' . $gr . "\r\nContent-Type: image/" . $typ . '; name="' . $name . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <" . $cid . '@' . $domain . ">\r\n"
            . 'Content-Disposition: inline; filename="' . $name . "\"\r\n\r\n" . chunk_split(base64_encode((string)@file_get_contents($pfad)), 76, "\r\n");
    }
    return implode("\r\n", $kopf) . "\r\n\r\n" . $teile . '--' . $gr . "--\r\n";
}

/** SMTP-Gespräch. Gibt null bei Erfolg zurück, sonst den Grund. */
function smtp_senden(array $c, string $an, string $nachricht): ?string
{
    if (strcspn($an, "\r\n") !== strlen($an) || filter_var($an, FILTER_VALIDATE_EMAIL) === false) {
        return 'Empfängeradresse unbrauchbar';
    }
    $host = trim((string)$c['host']);
    $port = (int)$c['port'];
    $starttls = ($c['verschluesselung'] ?? 'ssl') === 'starttls';
    $frist = microtime(true) + MAIL_SMTP_FRIST_S;
    $rest = fn(): float => $frist - microtime(true);
    $ziel = ($starttls ? 'tcp://' : 'ssl://') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
    $fp = @stream_socket_client($ziel, $errno, $errstr, max(0.5, $rest()), STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return 'Verbindung nicht möglich: ' . $errstr;
    }
    $grund = null;
    $erwarte = function (string $code) use ($fp, $rest, &$grund): bool {
        do {
            $r = $rest();
            if ($r <= 0) {
                $grund = 'Zeitbudget erschöpft (erwartet ' . $code . ')';
                return false;
            }
            stream_set_timeout($fp, (int)$r, (int)(fmod($r, 1.0) * 1_000_000));
            $zeile = fgets($fp, 1024);
            if ($zeile === false) {
                $grund = 'Verbindung abgebrochen (erwartet ' . $code . ')';
                return false;
            }
        } while (isset($zeile[3]) && $zeile[3] === '-');
        if (strncmp($zeile, $code, 3) === 0) {
            return true;
        }
        $grund = 'Server antwortete ' . substr(trim($zeile), 0, 3) . ', erwartet ' . $code;
        return false;
    };
    $sende = fn(string $befehl) => fwrite($fp, $befehl . "\r\n");
    $ehlo = host_haupt() ?? 'localhost';
    $ok = $erwarte('220');
    if ($ok) {
        $sende('EHLO ' . $ehlo);
        $ok = $erwarte('250');
    }
    if ($ok && $starttls) {
        $sende('STARTTLS');
        $ok = $erwarte('220') && @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) === true;
        if ($ok) {
            $sende('EHLO ' . $ehlo);
            $ok = $erwarte('250');
        } elseif ($grund === null) {
            $grund = 'STARTTLS fehlgeschlagen';
        }
    }
    if ($ok && (string)$c['benutzer'] !== '') {
        $sende('AUTH LOGIN');
        $ok = $erwarte('334');
        if ($ok) {
            $sende(base64_encode((string)$c['benutzer']));
            $ok = $erwarte('334');
        }
        if ($ok) {
            $sende(base64_encode((string)$c['passwort']));
            $ok = $erwarte('235');
        }
    }
    foreach (['MAIL FROM:<' . mail_absender($c) . '>' => '250', 'RCPT TO:<' . $an . '>' => '250', 'DATA' => '354'] as $befehl => $code) {
        if ($ok) {
            $sende($befehl);
            $ok = $erwarte($code);
        }
    }
    if ($ok) {
        // Punkt am Zeilenanfang verdoppeln (Dot-Stuffing)
        fwrite($fp, preg_replace('/^\./m', '..', $nachricht) . "\r\n.\r\n");
        $ok = $erwarte('250');
    }
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok ? null : ($grund ?? 'unbekannter Fehler');
}

/** Alte Einträge entfernen (Job 'aufraeumen'). */
function mail_aufraeumen(?int $jetzt = null): int
{
    $jetzt ??= time();
    $grenze = db_zeit($jetzt - MAIL_AUFBEWAHREN_TAGE * 86400);
    return db_ausfuehren("DELETE FROM mail_ausgang WHERE status IN ('gesendet','fehler') AND erstellt_am < ?", [$grenze])->rowCount();
}

/** Stand für den Team-Bereich. */
function mail_stand(): array
{
    $pf = [];
    foreach (MAIL_POSTFAECHER as $p => $titel) {
        $pf[$p] = ['titel' => $titel, 'da' => mail_postfach_da($p), 'heute' => mail_heute_gesendet(null, $p), 'absender' => mail_absender(mail_konfig($p))] + mail_limit($p)
            + ['offen' => db_zahl("SELECT COUNT(*) FROM mail_ausgang WHERE status IN ('offen','sendet') AND postfach = ?", [$p])];
    }
    return [
        'postfaecher' => $pf,
        'eingerichtet' => mail_eingerichtet(),
        'modus' => mail_konfig()['modus'],
        'offen' => db_zahl("SELECT COUNT(*) FROM mail_ausgang WHERE status IN ('offen','sendet')"),
        'fehler' => db_zahl("SELECT COUNT(*) FROM mail_ausgang WHERE status = 'fehler'"),
        'heute' => mail_heute_gesendet(),
        'limit' => (int)mail_konfig()['tageslimit'],
        'letzter_fehler' => db_zeile("SELECT fehler, erstellt_am, versuche FROM mail_ausgang WHERE fehler IS NOT NULL AND status IN ('offen','fehler') ORDER BY id DESC LIMIT 1"),
        'aelteste_offen' => db_wert("SELECT MIN(erstellt_am) FROM mail_ausgang WHERE status IN ('offen','sendet')"),
        'absender' => mail_absender(mail_konfig()),
        'umleiten' => (string)(mail_konfig()['umleiten_an'] ?? ''),
    ];
}

/** Testmail sofort zustellen (ohne Warteschlange). Gibt null bei Erfolg zurück, sonst den Grund. */
/** Testmail sofort zustellen; null = angenommen, sonst der Grund. Mit $durch protokolliert. */
function mail_testen(string $an, ?int $durch = null): ?string
{
    if (!mail_eingerichtet()) {
        $grund = 'Der Mailversand ist nicht eingerichtet: In der config.php fehlt im Abschnitt mail der Eintrag host.';
    } else {
        [$betreff, $text] = mail_vorlage('test', []);
        $grund = mail_zustellen($an, $betreff, $text);
    }
    if ($durch !== null) {
        audit_schreiben('mail', 'Testmail ' . ($grund === null ? 'versendet' : 'fehlgeschlagen: ' . $grund), $durch);
    }
    return $grund;
}

/* ---- Vorlagen (R-09: Deutsch, Ton nach zina-branding: du, klar, kurz) ---- */

function mail_gruss(bool $allgemein = false): string
{
    return "\n\nBis bald\nDein Team " . ($allgemein ? 'von ' . reihe_name() : 'vom ' . va_kurz()) . "\nALLGÄU AKUT – Fortbildung Notfallmedizin\n" . url('/');
}

/**
 * Dachmarke (E-67; Begriff E-71, Einstellung reihe_name): steht in Mails, die für alle Veranstaltungen gelten
 * (Team, Konto, Newsletter), als Absender, im Betreff und im Gruß. Einstellung
 * unter Betrieb → Absender.
 */
function reihe_name(): string
{
    $n = trim((string)(einstellung('reihe_name') ?? ''));
    return $n !== '' ? $n : 'Allgäu Akut';
}

/** Vorlagen, die für alle Veranstaltungen gelten (E-67): Absender, Betreff, Kicker und Gruß mit dem Namen der Reihe. */
const MAIL_VORLAGEN_ALLGEMEIN = ['konto_loeschen_bald', 'konto_bestaetigen', 'konto_vorhanden', 'konto_passwort', 'team_rolle', 'team_einladung', 'team_adresse',
    'newsletter_bestaetigen', 'newsletter_schon', 'zweitfaktor_code', 'team_passwort', 'test'];

/** Betreff und Text einer Vorlage; allgemeine Vorlagen grüßen im Namen der Reihe. */
function mail_vorlage(string $name, array $d): array
{
    [$betreff, $text] = mail_vorlage_roh($name, $d);
    if (in_array($name, MAIL_VORLAGEN_ALLGEMEIN, true)) {
        $text = str_replace(mail_gruss(), mail_gruss(true), $text);
    }
    return [$betreff, $text];
}

/**
 * Betreff und Text einer Vorlage. $d enthält je Vorlage: vorname, link,
 * gueltig (Text), uebersicht (Text), frist (Text), was (Text) …
 */
function mail_vorlage_roh(string $name, array $d): array
{
    $hallo = 'Hallo ' . ($d['vorname'] ?? '') . ",\n\n";
    $u = isset($d['uebersicht']) ? "\n\n" . $d['uebersicht'] : '';
    switch ($name) {
        case 'bestaetigen':
            return ['Bitte bestätige deine Anmeldung zum ' . va_kurz(),
                $hallo . "danke für deine Anmeldung. Sie gilt erst, wenn du sie über diesen Link bestätigst:\n\n"
                . $d['link'] . "\n\nDer Link gilt bis " . $d['gueltig'] . '. Bis dahin halten wir deine Plätze frei; danach verfällt die Anmeldung und die Plätze gehen an andere.'
                . $u . "\n\nDu hast dich nicht angemeldet? Dann ignoriere diese Mail einfach." . mail_gruss()];
        case 'bestaetigt':
            return ['Deine Anmeldung zum ' . va_kurz() . ' ist bestätigt',
                $hallo . 'deine Anmeldung ist bestätigt.' . $u
                . "\n\nÄndern oder absagen kannst du unter " . url('/deine-anmeldung') . ' – dort schicken wir dir einen Link an diese Adresse.' . mail_gruss()];
        case 'zugang':
            return ['Dein Link zur Anmeldung beim ' . va_kurz(),
                $hallo . "mit diesem Link siehst und änderst du deine Anmeldung:\n\n" . $d['link']
                . "\n\nEr gilt bis " . $d['gueltig'] . ' und nur einmal. Danach kannst du dir jederzeit einen neuen schicken lassen.'
                . "\n\nDu hast keinen Link angefordert? Dann ignoriere diese Mail." . mail_gruss()];
        case 'schon_angemeldet':
            return ['Du bist schon beim ' . va_kurz() . ' angemeldet',
                $hallo . "mit dieser Adresse gibt es schon eine Anmeldung. Eine zweite legen wir nicht an. Mit diesem Link siehst und änderst du die bestehende:\n\n"
                . $d['link'] . "\n\nEr gilt bis " . $d['gueltig'] . ' und nur einmal.'
                . "\n\nDas warst du nicht? Dann ignoriere diese Mail." . mail_gruss()];
        case 'konto_loeschen_bald':
            // Löschfrist (E-54): 14 Monate ohne Login, Erinnerung 30 Tage vorher
            return ['Dein Konto bei ' . reihe_name() . ' wird bald gelöscht',
                $hallo . 'du hast dich seit längerer Zeit nicht in deinem Konto bei ' . reihe_name() . ' eingeloggt. Wir löschen Konten nach ' . KONTO_LOESCHEN_MONATE . ' Monaten ohne Login – deins am '
                . $d['datum'] . ".\n\nWillst du es behalten? Dann logg dich einfach bis dahin einmal ein:\n\n" . $d['link']
                . "\n\nWillst du es nicht mehr? Dann musst du nichts tun. Eine Anmeldung zum Symposium bleibt bestehen; du erreichst sie weiter über einen Link per Mail." . mail_gruss()];
        case 'geaendert':
            return ['Deine Anmeldung zum ' . va_kurz() . ' wurde geändert',
                $hallo . ($d['was'] ?? 'deine Anmeldung wurde geändert.') . $u . mail_gruss()];
        case 'storniert':
            return ['Deine Anmeldung zum ' . va_kurz() . ' ist storniert',
                $hallo . "deine Anmeldung ist storniert. Deine Plätze gehen an die Warteliste. Danke, dass du abgesagt hast.\n\nWenn du doch kommen willst, melde dich einfach neu an: " . url('/anmelden') . mail_gruss()];
        case 'angebot':
            return ['Platzangebot: ' . $d['was'],
                $hallo . 'auf der Warteliste ist ein Platz für dich frei geworden: ' . $d['was'] . ".\n\nDer Platz ist für dich reserviert bis " . $d['frist']
                . ". Annehmen oder ablehnen kannst du hier:\n\n" . $d['link']
                . "\n\nLässt du die Frist verstreichen, geht der Platz an die nächste Person und du rückst ans Ende der Warteliste."
                . (($d['tausch'] ?? '') !== '' ? "\n\nAchtung: " . $d['tausch'] : '') . mail_gruss()];
        case 'angebot_abgelaufen':
            return ['Angebot abgelaufen: ' . $d['was'],
                $hallo . 'das Angebot für ' . $d['was'] . ' ist abgelaufen. Der Platz geht an die nächste Person; du stehst jetzt am Ende der Warteliste.' . $u . mail_gruss()];
        case 'platz_erhalten':
            return ['Du hast den Platz: ' . $d['was'],
                $hallo . 'du hast den Platz angenommen: ' . $d['was'] . '.' . $u . mail_gruss()];
        case 'email_bestaetigen':
            // Anrede ohne Vornamen: Wer die Mail bekommt, kennt die Person vielleicht nicht (Review S-2)
            return ['Bitte bestätige deine neue Adresse',
                "Hallo,\n\n" . 'du willst deine Anmeldung zum ' . va_kurz() . " auf diese Adresse umstellen. Bestätige das über diesen Link:\n\n" . $d['link']
                . "\n\nEr gilt bis " . $d['gueltig'] . ". Bis dahin bleibt die bisherige Adresse gültig.\n\nDas warst du nicht? Dann ignoriere diese Mail." . mail_gruss()];
        case 'adresse_vom_team':
            // an die alte Adresse, wenn das Team die Adresse geändert hat (E-63 (2))
            return ['Deine Adresse beim ' . va_kurz() . ' wurde geändert',
                $hallo . 'das Team hat die Adresse deiner Anmeldung und deines Kontos geändert. Mails zum '
                . va_kurz() . " und der Login laufen ab jetzt über die neue Adresse, diese hier gilt nicht mehr.\n\nDas war nicht mit dir abgesprochen? Dann melde dich bitte beim Team." . mail_gruss()];
        case 'konto_bestaetigen':
            return ['Bitte bestätige dein Konto bei ' . reihe_name(),
                $hallo . "danke für dein Konto. Bestätige deine Adresse über diesen Link, danach legst du dein Passwort fest:\n\n" . $d['link']
                . "\n\nDer Link gilt bis " . $d['gueltig'] . ".\n\nDu hast kein Konto angelegt? Dann ignoriere diese Mail; ohne Bestätigung wird es gelöscht." . mail_gruss()];
        case 'konto_vorhanden':
            return ['Du hast schon ein Konto bei ' . reihe_name(),
                $hallo . "für diese Adresse gibt es schon ein Konto. Einloggen kannst du dich unter " . url('/einloggen')
                . ". Passwort vergessen? Unter " . url('/konto/passwort-vergessen') . " schicken wir dir einen Link.\n\nDas warst du nicht? Dann ignoriere diese Mail." . mail_gruss()];
        case 'konto_passwort':
            return ['Neues Passwort für dein Konto bei ' . reihe_name(),
                $hallo . "mit diesem Link legst du ein neues Passwort fest:\n\n" . $d['link'] . "\n\nEr gilt bis " . $d['gueltig']
                . " und nur einmal.\n\nDu hast keinen Link angefordert? Dann ignoriere diese Mail; dein Passwort bleibt, wie es ist." . mail_gruss()];
        case 'team_rolle':
            return ['Du bist jetzt im Team von ' . reihe_name(),
                $hallo . 'du hast jetzt die Rolle „' . $d['rolle'] . '“ im Team-Bereich. Einloggen kannst du dich unter ' . url('/einloggen')
                . ($d['link'] === '' ? ' mit deiner Adresse und deinem Passwort.' : '. Lege vorher über diesen Link dein Passwort fest (gilt ' . dauer_text(KONTO_LINK_GUELTIG_S) . "):\n\n" . $d['link'] . "\n\n")
                . " Beim ersten Login richtest du einen zweiten Faktor ein (Authenticator-App auf dem Handy).\n\nDas warst nicht du? Dann melde dich bitte beim Team." . mail_gruss()];
        case 'team_adresse':
            // an die alte Adresse, wenn eine BetreiberIn die Adresse des Team-Kontos geändert hat (O-48)
            return ['Deine Adresse im Team von ' . reihe_name() . ' wurde geändert',
                $hallo . 'eine BetreiberIn hat die Adresse deines Team-Kontos geändert. Der Login und alle Mails laufen ab jetzt über die neue Adresse, diese hier gilt nicht mehr. '
                . "Passwort, zweiter Faktor und Passkeys bleiben, wie sie waren.\n\nDas war nicht mit dir abgesprochen? Dann melde dich bitte sofort beim Team." . mail_gruss()];
        case 'team_einladung':
            return ['Einladung in den Team-Bereich von ' . reihe_name(),
                $hallo . 'du bist jetzt im Team von ' . reihe_name() . ' (Rolle „' . $d['rolle'] . "“). Lege über diesen Link dein Passwort fest:\n\n" . $d['link']
                . "\n\nDer Link gilt " . dauer_text(KONTO_LINK_GUELTIG_S) . ' und nur einmal. Danach richtest du einen zweiten Faktor ein (Authenticator-App auf dem Handy). Einloggen kannst du dich später unter ' . url('/einloggen') . '.'
                . "\n\nDu erwartest diese Mail nicht? Dann ignoriere sie und sag dem Team Bescheid." . mail_gruss()];
        case 'newsletter_bestaetigen':
            return ['Bitte bestätige deinen Newsletter',
                'Hallo' . (($d['vorname'] ?? '') !== '' ? ' ' . $d['vorname'] : '') . ",\n\n"
                . 'du möchtest diese Newsletter bekommen: ' . $d['listen'] . ". Bestätige das über diesen Link:\n\n" . $d['link']
                . "\n\nDer Link gilt " . dauer_text(NL_BESTAETIGEN_S) . '. Ohne Bestätigung tragen wir dich nicht ein und löschen die Anfrage.'
                . "\n\nMit der Bestätigung willigst du ein: „" . $d['einwilligung'] . '“'
                . "\n\nDu hast dich nicht eingetragen? Dann ignoriere diese Mail." . mail_gruss()];
        case 'newsletter_schon':
            return ['Dein Newsletter bei ' . reihe_name(),
                'Hallo' . (($d['vorname'] ?? '') !== '' ? ' ' . $d['vorname'] : '') . ",\n\n"
                . "für diese Adresse sind die gewählten Newsletter schon eingetragen. Hier kannst du deine Listen ansehen und ändern oder dich abmelden:\n\n" . $d['link']
                . "\n\nDas warst du nicht? Dann ignoriere diese Mail." . mail_gruss()];
        case 'zweitfaktor_code':
            return ['Dein Code zum Einloggen: ' . $d['code'],
                $hallo . 'dein Code zum Einloggen in den Team-Bereich:' . "\n\n    " . $d['code'] . "\n\nEr gilt " . dauer_text(MAILCODE_GUELTIG_S) . ' und nur einmal.'
                . "\n\nDu loggst dich gerade nicht ein? Dann hat jemand dein Passwort. Ändere es unter " . url('/konto/passwort-vergessen') . ' und sag dem Team Bescheid.' . mail_gruss()];
        case 'team_passwort':
            return ['Neues Passwort für den Team-Bereich von ' . reihe_name(),
                $hallo . "mit diesem Link legst du ein neues Passwort fest:\n\n" . $d['link']
                . "\n\nEr gilt " . dauer_text(KONTO_LINK_GUELTIG_S) . ' und nur einmal. Dein zweiter Faktor bleibt, wie er ist – beim Einloggen brauchst du weiter den Code aus deiner App.'
                . "\n\nDu hast keinen Link angefordert? Dann ignoriere diese Mail; dein Passwort bleibt, wie es ist." . mail_gruss()];
        case 'uebernehmen':
            // Wiederkehrende ohne Konto (E-58)
            return ['Deine Anmeldung zum ' . va_kurz() . ' – mit deinen bisherigen Angaben',
                $hallo . 'schön, dass du wieder dabei sein willst! Mit diesem Link öffnest du die Anmeldung zum ' . va_kurz() . " – deine Angaben vom letzten Mal sind schon eingetragen:\n\n"
                . $d['link'] . "\n\nDer Link gilt bis " . $d['gueltig'] . ' und einmal. Angemeldet bist du erst, wenn du das Formular abschickst und bestätigst.'
                . "\n\nDu hast keinen Link angefordert? Dann ignoriere diese Mail." . mail_gruss()];
        case 'zertifikat':
            // AP-10, E-57: Freischaltung durch das Team; der Link führt zu „Meine Anmeldung“
            return ['Deine Teilnahmebescheinigung ist da',
                $hallo . 'danke, dass du beim ' . $d['name'] . " dabei warst! Deine Teilnahmebescheinigung liegt für dich bereit. Mit diesem Link kommst du zu deiner Anmeldung und lädst sie als PDF herunter:\n\n"
                . $d['link'] . "\n\nDer Link gilt bis " . $d['gueltig'] . ' und einmal. Danach kannst du dir unter ' . url('/deine-anmeldung')
                . ' jederzeit einen neuen schicken lassen – oder du loggst dich mit deinem Konto ein.' . mail_gruss()];
        case 'test':
            return ['Testmail von ' . reihe_name(), "Diese Mail prüft den Versand. Sie kam an – alles gut." . mail_gruss()];
    }
    throw new InvalidArgumentException('Unbekannte Mail-Vorlage: ' . $name);
}

/**
 * Absender je Postfach speichern (Betrieb, E-27, E-50, E-67): System
 * (anmeldung@, mit der Dachmarke) oder Newsletter (newsletter@, mit eigenem
 * Namen). Gibt null oder eine Fehlermeldung zurück; protokolliert.
 */
function mail_absender_setzen(string $postfach, string $adresse, string $name, string $reihe, ?int $durch): ?string
{
    $nl = $postfach === 'newsletter' ? '_newsletter' : '';
    $adr = trim($adresse);
    $nam = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($adr !== '' && mail_adresse($adr) === null) {
        return 'Die Absenderadresse ist keine gültige Adresse.';
    }
    einstellung_setzen('mail_absender' . $nl, $adr === '' ? null : mail_adresse($adr));
    if ($nl !== '') {
        einstellung_setzen('mail_absender_name' . $nl, $nam === '' ? null : mb_substr($nam, 0, 80));
    } else {
        // System-Postfach: Dachmarke (E-67); der Absender der Mails zu einer Anmeldung ist die Veranstaltung
        $reihe = trim(preg_replace('/\s+/u', ' ', $reihe) ?? '');
        einstellung_setzen('reihe_name', $reihe === '' ? null : mb_substr($reihe, 0, 60));
        einstellung_setzen('mail_absender_name', null);
    }
    audit_schreiben('mail', 'Absender ' . ($nl !== '' ? 'Newsletter' : 'System') . ' geändert: ' . ($adr ?: 'aus config.php'), $durch);
    return null;
}
