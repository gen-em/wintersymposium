<?php
declare(strict_types=1);

/*
 * Zweiter Faktor per Authenticator-App (TOTP, RFC 6238) und
 * Wiederherstellungscodes. Kern übernommen aus NAdoku totp_lib.php (E-18),
 * ohne Demo, Passkeys und „Gerät merken"; Base32 hier selbst (keine
 * Fremdbibliothek).
 *
 * - 30 s, 6 Stellen, ein Schritt Toleranz nach beiden Seiten.
 * - Kein Code gilt zweimal: konto.totp_schritt hält den letzten angenommenen
 *   Zeitschritt; angenommen wird nur ein späterer, atomar im UPDATE.
 * - Das Geheimnis liegt versiegelt in konto.totp_geheimnis (krypto_lib,
 *   Zweck 'totp|<id>'). Eingeschaltet ist der Faktor erst mit totp_seit,
 *   also nach einem bestätigten Code.
 * - Zehn Wiederherstellungscodes, je 8 Zeichen, nur als password_hash in
 *   konto_code. Sie hängen nicht am Schlüssel und helfen auch dann, wenn das
 *   Geheimnis nicht mehr zu öffnen ist.
 *
 * Die Werte sind technische Sicherheitsgrenzen, keine Veranstaltungs-
 * einstellungen (R-11 betrifft sie nicht).
 */

const TOTP_SCHRITT_S = 30;
const TOTP_STELLEN = 6;
const TOTP_FENSTER = 1;
const TOTP_GEHEIMNIS_BYTES = 20;
const TOTP_CODES = 10;
const TOTP_CODE_LAENGE = 8;
/** Ohne 0, 1, I, L, O, U (Verwechslungsgefahr). */
const TOTP_CODE_ZEICHEN = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';

/* ---- RFC 4226 / 6238 ---------------------------------------------------- */

function totp_hotp(string $geheimnis, int $zaehler, int $stellen = TOTP_STELLEN): string
{
    $h = hash_hmac('sha1', pack('J', $zaehler), $geheimnis, true);
    $o = ord($h[19]) & 0x0f;
    $bin = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($bin % (10 ** $stellen)), $stellen, '0', STR_PAD_LEFT);
}

function totp_schritt(int $zeit): int
{
    return intdiv($zeit, TOTP_SCHRITT_S);
}

function totp_code(string $geheimnis, int $zeit, int $stellen = TOTP_STELLEN): string
{
    return totp_hotp($geheimnis, totp_schritt($zeit), $stellen);
}

/** Getroffener Zeitschritt oder null. Nur ein Schritt nach $letzter zählt. */
function totp_code_passt(string $geheimnis, string $eingabe, int $zeit, ?int $letzter): ?int
{
    $eingabe = preg_replace('/\s+/', '', $eingabe) ?? '';
    if (preg_match('/^\d{' . TOTP_STELLEN . '}$/', $eingabe) !== 1) {
        return null;
    }
    $jetzt = totp_schritt($zeit);
    $treffer = null;
    for ($d = -TOTP_FENSTER; $d <= TOTP_FENSTER; $d++) {
        // Alle Fenster rechnen, damit die Laufzeit nichts verrät.
        if (hash_equals(totp_hotp($geheimnis, $jetzt + $d), $eingabe)) {
            $treffer = $jetzt + $d;
        }
    }
    if ($treffer === null || ($letzter !== null && $treffer <= $letzter)) {
        return null;
    }
    return $treffer;
}

/* ---- Base32 (RFC 4648, groß, ohne Auffüllung) --------------------------- */

function totp_base32(string $roh): string
{
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($roh) as $z) {
        $bits .= str_pad(decbin(ord($z)), 8, '0', STR_PAD_LEFT);
    }
    $aus = '';
    foreach (str_split($bits, 5) as $teil) {
        $aus .= $abc[bindec(str_pad($teil, 5, '0'))];
    }
    return $aus;
}

function totp_base32_gruppen(string $roh): string
{
    return trim(chunk_split(totp_base32($roh), 4, ' '));
}

/** otpauth-Adresse für die App (Key Uri Format). */
function totp_otpauth(string $roh, string $konto, string $aussteller): string
{
    return 'otpauth://totp/' . rawurlencode($aussteller) . ':' . rawurlencode($konto)
        . '?secret=' . totp_base32($roh)
        . '&issuer=' . rawurlencode($aussteller)
        . '&algorithm=SHA1&digits=' . TOTP_STELLEN . '&period=' . TOTP_SCHRITT_S;
}

/* ---- Wiederherstellungscodes -------------------------------------------- */

function totp_codes_erzeugen(): array
{
    $codes = [];
    $n = strlen(TOTP_CODE_ZEICHEN);
    for ($i = 0; $i < TOTP_CODES; $i++) {
        $c = '';
        for ($j = 0; $j < TOTP_CODE_LAENGE; $j++) {
            $c .= TOTP_CODE_ZEICHEN[random_int(0, $n - 1)];
        }
        $codes[] = $c;
    }
    return $codes;
}

function totp_code_anzeige(string $code): string
{
    return substr($code, 0, 4) . ' ' . substr($code, 4);
}

function totp_code_normieren(string $eingabe): ?string
{
    $c = strtoupper(preg_replace('/[\s\-]+/', '', $eingabe) ?? '');
    if (strlen($c) !== TOTP_CODE_LAENGE) {
        return null;
    }
    return strspn($c, TOTP_CODE_ZEICHEN) === TOTP_CODE_LAENGE ? $c : null;
}

function totp_codes_schreiben(int $kontoId, array $codes): void
{
    db_ausfuehren('DELETE FROM konto_code WHERE konto_id = ?', [$kontoId]);
    foreach ($codes as $c) {
        db_ausfuehren('INSERT INTO konto_code (konto_id, hash) VALUES (?, ?)', [$kontoId, password_hash($c, PASSWORD_DEFAULT)]);
    }
}

/* ---- Zustand eines Kontos ------------------------------------------------ */

/** ['an' => bool, 'angefangen' => bool, 'codes_offen' => int] */
function totp_zustand(int $kontoId): array
{
    $z = db_zeile('SELECT totp_seit, zweitfaktor_mail, totp_geheimnis IS NOT NULL AS g FROM konto WHERE id = ?', [$kontoId]);
    $offen = db_zahl('SELECT COUNT(*) FROM konto_code WHERE konto_id = ? AND benutzt_am IS NULL', [$kontoId]);
    $an = $z !== null && $z['totp_seit'] !== null;
    $mail = $z !== null && (int)$z['zweitfaktor_mail'] === 1;
    $pk = pk_zahl($kontoId);
    return [
        'an' => $an,
        'mail' => $mail,
        'passkeys' => $pk,
        'eingerichtet' => $an || $mail || $pk > 0,
        'angefangen' => $z !== null && $z['totp_seit'] === null && (int)$z['g'] === 1,
        'codes_offen' => $offen,
    ];
}

/** Ist ein zweiter Faktor eingerichtet (App, E-Mail-Code oder Passkey, E-55)? Für Konto-Zeilen aus der Datenbank. */
function zweitfaktor_da(array $k): bool
{
    return ($k['totp_seit'] ?? null) !== null || (int)($k['zweitfaktor_mail'] ?? 0) === 1 || pk_zahl((int)($k['id'] ?? 0)) > 0;
}

/* ---- E-Mail-Code (Admin 2026-10-30: für alle frei wählbar) --------------- */

const MAILCODE_GUELTIG_S = 600;

const MAILCODE_GRENZE_TEXT = 'Ein Code ist gerade erst unterwegs, oder es waren in der letzten Stunde schon 10. Bitte warte etwas, bevor du einen neuen anforderst.';

/**
 * Neuen Code per Mail schicken (der vorige gilt nicht mehr). Höchstens einer
 * je Minute und 10 je Stunde je Konto, gezählt in rate_limit (Review S-9).
 * Gibt null zurück, wenn verschickt, sonst den Grund.
 */
function mailcode_senden(array $k): ?string
{
    $merkmal = rate_merkmal('konto:' . (int)$k['id']);
    if (!rate_erlaubt('mailcode_minute', $merkmal) || !rate_erlaubt('mailcode', $merkmal)) {
        return MAILCODE_GRENZE_TEXT;
    }
    rate_misserfolg('mailcode_minute', $merkmal); // jeder Versand zählt
    rate_misserfolg('mailcode', $merkmal);
    $code = str_pad((string)random_int(0, 999999), TOTP_STELLEN, '0', STR_PAD_LEFT);
    db_ausfuehren('UPDATE konto SET mailcode_hash = ?, mailcode_bis = ? WHERE id = ?', [password_hash($code, PASSWORD_DEFAULT), db_zeit(time() + MAILCODE_GUELTIG_S), (int)$k['id']]);
    mail_vorlage_einreihen((string)$k['email'], 'zweitfaktor_code', ['vorname' => tk_namen($k)['vorname'], 'code' => $code]);
    return null;
}

/** Code aus der Mail prüfen (einmal gültig). */
function mailcode_pruefen(int $kontoId, string $eingabe): bool
{
    $ziffern = preg_replace('/\s+/', '', $eingabe) ?? '';
    if (preg_match('/^\d{' . TOTP_STELLEN . '}$/', $ziffern) !== 1) {
        return false;
    }
    $z = db_zeile('SELECT mailcode_hash, mailcode_bis FROM konto WHERE id = ?', [$kontoId]);
    if ($z === null || $z['mailcode_hash'] === null || db_ts($z['mailcode_bis']) < time() || !password_verify($ziffern, (string)$z['mailcode_hash'])) {
        return false;
    }
    return db_ausfuehren('UPDATE konto SET mailcode_hash = NULL, mailcode_bis = NULL WHERE id = ? AND mailcode_hash = ?', [$kontoId, $z['mailcode_hash']])->rowCount() === 1;
}

/**
 * E-Mail-Code ein- oder ausschalten. Beim ersten Einrichten (noch keine App)
 * gibt es Wiederherstellungscodes zurück, sonst null.
 */
function mailcode_einschalten(int $kontoId, bool $an, ?int $durch = null): ?array
{
    $z = totp_zustand($kontoId);
    $codes = null;
    db_transaktion(function () use ($kontoId, $an, $z, &$codes): void {
        db_ausfuehren('UPDATE konto SET zweitfaktor_mail = ? WHERE id = ?', [$an ? 1 : 0, $kontoId]);
        if ($an && !$z['eingerichtet']) {
            $codes = totp_codes_erzeugen();
            totp_codes_schreiben($kontoId, $codes);
        }
    });
    audit_schreiben('zweitfaktor', 'Code per E-Mail ' . ($an ? 'eingeschaltet' : 'ausgeschaltet'), $durch ?? $kontoId);
    return $codes;
}

function totp_an(int $kontoId): bool
{
    return totp_zustand($kontoId)['an'];
}

/** Neue Einrichtung: neues Geheimnis versiegelt speichern, noch nicht eingeschaltet. Gibt das Rohgeheimnis zurück. */
function totp_einrichtung_beginnen(int $kontoId): string
{
    if (totp_an($kontoId)) {
        throw new LogicException('Zweiter Faktor ist schon eingeschaltet.');
    }
    $roh = random_bytes(TOTP_GEHEIMNIS_BYTES);
    db_ausfuehren(
        'UPDATE konto SET totp_geheimnis = ?, totp_seit = NULL, totp_schritt = NULL WHERE id = ?',
        [versiegeln($roh, 'totp|' . $kontoId), $kontoId]
    );
    return $roh;
}

function totp_geheimnis(int $kontoId): ?string
{
    $paket = db_wert('SELECT totp_geheimnis FROM konto WHERE id = ?', [$kontoId]);
    if (!is_string($paket) || $paket === '') {
        return null;
    }
    return oeffnen($paket, 'totp|' . $kontoId);
}

/** Einrichtung mit einem passenden Code abschließen. Gibt die Codes zurück oder null. */
function totp_einrichtung_abschliessen(int $kontoId, string $eingabe, ?int $zeit = null): ?array
{
    $z = totp_zustand($kontoId);
    if ($z['an'] || !$z['angefangen']) {
        return null;
    }
    $roh = totp_geheimnis($kontoId);
    if ($roh === null) {
        return null;
    }
    $schritt = totp_code_passt($roh, $eingabe, $zeit ?? time(), null);
    if ($schritt === null) {
        return null;
    }
    $codes = totp_codes_erzeugen();
    db_transaktion(function () use ($kontoId, $schritt, $codes): void {
        db_ausfuehren('UPDATE konto SET totp_seit = ?, totp_schritt = ? WHERE id = ? AND totp_seit IS NULL', [db_jetzt(), $schritt, $kontoId]);
        totp_codes_schreiben($kontoId, $codes);
    });
    audit_schreiben('zweitfaktor', 'Zweiter Faktor eingerichtet', $kontoId);
    return $codes;
}

/** Neue Wiederherstellungscodes; die alten gelten nicht mehr. */
function totp_codes_erneuern(int $kontoId): ?array
{
    if (!totp_zustand($kontoId)['eingerichtet']) {
        return null;
    }
    $codes = totp_codes_erzeugen();
    db_transaktion(fn() => totp_codes_schreiben($kontoId, $codes));
    audit_schreiben('zweitfaktor', 'Wiederherstellungscodes erneuert', $kontoId);
    return $codes;
}

/**
 * Code bei der Anmeldung: 6 Ziffern aus der App oder ein Wiederherstellungscode.
 * ['ok' => bool, 'art' => 'app'|'code'|null, 'codes_offen' => int]
 */
function totp_anmeldung_pruefen(int $kontoId, string $eingabe, ?int $zeit = null): array
{
    $nein = ['ok' => false, 'art' => null, 'codes_offen' => 0];
    $zs = totp_zustand($kontoId);
    if (!$zs['eingerichtet']) {
        return $nein;
    }
    $ziffern = preg_replace('/\s+/', '', $eingabe) ?? '';
    // Code aus der Mail (wenn eingeschaltet), sonst aus der App
    if ($zs['mail'] && mailcode_pruefen($kontoId, $ziffern)) {
        return ['ok' => true, 'art' => 'mail', 'codes_offen' => $zs['codes_offen']];
    }
    if ($zs['an'] && preg_match('/^\d{' . TOTP_STELLEN . '}$/', $ziffern) === 1) {
        $roh = totp_geheimnis($kontoId);
        if ($roh === null) {
            return $nein;
        }
        $letzter = db_wert('SELECT totp_schritt FROM konto WHERE id = ?', [$kontoId]);
        $schritt = totp_code_passt($roh, $ziffern, $zeit ?? time(), $letzter === null ? null : (int)$letzter);
        if ($schritt === null) {
            return $nein;
        }
        $n = db_ausfuehren(
            'UPDATE konto SET totp_schritt = ? WHERE id = ? AND (totp_schritt IS NULL OR totp_schritt < ?)',
            [$schritt, $kontoId, $schritt]
        )->rowCount();
        return $n === 1 ? ['ok' => true, 'art' => 'app', 'codes_offen' => totp_zustand($kontoId)['codes_offen']] : $nein;
    }
    $code = totp_code_normieren($eingabe);
    if ($code === null) {
        return $nein;
    }
    foreach (db_zeilen('SELECT id, hash FROM konto_code WHERE konto_id = ? AND benutzt_am IS NULL', [$kontoId]) as $z) {
        if (!password_verify($code, (string)$z['hash'])) {
            continue;
        }
        $n = db_ausfuehren('UPDATE konto_code SET benutzt_am = ? WHERE id = ? AND benutzt_am IS NULL', [db_jetzt(), (int)$z['id']])->rowCount();
        if ($n !== 1) {
            return $nein;
        }
        audit_schreiben('zweitfaktor', 'Anmeldung mit Wiederherstellungscode', $kontoId);
        return ['ok' => true, 'art' => 'code', 'codes_offen' => totp_zustand($kontoId)['codes_offen']];
    }
    return $nein;
}

/* ---- Bestätigen im eingeloggten Zustand (Review B-7, S-4, V-4) ----------- */

const ZWEITFAKTOR_BESTAETIGT_S = 300;
const ZWEITFAKTOR_FELD_TEXT = 'Code aus der App, aus der Mail oder ein Wiederherstellungscode';

/**
 * Eine heikle Aktion (Rolle, Sicherung, Wiederherstellen, zweiten Faktor
 * zurücksetzen, Sperren) mit dem zweiten Faktor bestätigen: Code aus der App,
 * Code aus der Mail (wenn gerade einer gilt, auch ohne eingeschalteten
 * Mail-Code) oder Wiederherstellungscode, mit Ratenschutz je Konto.
 * Bei Erfolg gilt die Bestätigung für eine Aktion innerhalb von 5 Minuten
 * (zweitfaktor_bestaetigt). Gibt null oder den Fehlertext zurück.
 */
function zweitfaktor_bestaetigen(int $kontoId, string $code): ?string
{
    $kennung = rate_merkmal('konto:' . $kontoId);
    if (!rate_erlaubt('zweitfaktor', $kennung)) {
        return rate_text('zweitfaktor', 'falsche Codes');
    }
    if (!mailcode_pruefen($kontoId, $code) && !totp_anmeldung_pruefen($kontoId, $code)['ok']) {
        rate_misserfolg('zweitfaktor', $kennung);
        return 'Der Code stimmt nicht oder ist abgelaufen.';
    }
    rate_erfolg('zweitfaktor', $kennung);
    zweitfaktor_merken($kontoId);
    return null;
}

/** Bestätigung in der Sitzung merken (nach Code oder Passkey). */
function zweitfaktor_merken(int $kontoId): void
{
    $_SESSION['zf_bestaetigt'] = ['konto' => $kontoId, 'bis' => time() + ZWEITFAKTOR_BESTAETIGT_S];
}

/** Liegt eine frische Bestätigung vor? Gilt einmal: wird beim Lesen gelöscht. */
function zweitfaktor_bestaetigt(int $kontoId): bool
{
    $z = $_SESSION['zf_bestaetigt'] ?? null;
    unset($_SESSION['zf_bestaetigt']);
    return is_array($z) && (int)($z['konto'] ?? 0) === $kontoId && (int)($z['bis'] ?? 0) >= time();
}

/**
 * Für die Seiten: Bestätigung aus dem Formular. Vorher mit Passkey bestätigt
 * (passkey.php, Aktion bestaetigen) oder Code im Feld totp. Null = bestätigt.
 */
function zweitfaktor_aus_formular(int $kontoId): ?string
{
    if (zweitfaktor_bestaetigt($kontoId)) {
        return null;
    }
    $f = zweitfaktor_bestaetigen($kontoId, (string)($_POST['totp'] ?? ''));
    return $f ?? (zweitfaktor_bestaetigt($kontoId) ? null : 'Bitte noch einmal bestätigen.');
}

/**
 * „Code per E-Mail schicken“ aus einem Bestätigungs-Formular
 * (assets/zweitfaktor.js schickt das Formular mit zf_mailcode=1). Antwort als
 * JSON, ohne Accept-Kopfzeile zurück zur Seite. Kehrt nur zurück, wenn
 * zf_mailcode fehlt.
 */
function zweitfaktor_mailcode_aktion(array $konto, string $ziel): void
{
    if (empty($_POST['zf_mailcode'])) {
        return;
    }
    $f = mailcode_senden($konto);
    $text = $f ?? 'Code ist unterwegs an ' . $konto['email'] . '. Er gilt ' . dauer_text(MAILCODE_GUELTIG_S) . '.';
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        json_antwort(200, ['ok' => $f === null, 'text' => $text]);
    }
    flash_setzen($f === null ? 'ok' : 'hinweis', $text);
    umleiten($ziel);
}

/**
 * Feld für die Bestätigung mit Knöpfen „Code per E-Mail schicken“ und (mit
 * Passkey und WebAuthn im Browser) „Mit Passkey bestätigen“. Beide sind
 * type="button" und brauchen assets/zweitfaktor.js; ohne JavaScript bleiben
 * sie verborgen. Ein zweiter Absende-Knopf würde sonst mit der Eingabetaste
 * statt „Bestätigen“ ausgelöst.
 */
function zweitfaktor_feld_html(array $konto, string $id): string
{
    $pk = pk_zahl((int)$konto['id']) > 0
        ? ' <button type="button" class="knopf zweit klein-knopf" data-zf-passkey hidden>Mit Passkey bestätigen</button>'
        : '';
    return '<div class="feld"><label for="' . e($id) . '">' . e(ZWEITFAKTOR_FELD_TEXT) . '</label>'
        . '<input id="' . e($id) . '" name="totp" type="text" required autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" maxlength="12"></div>'
        . '<p class="aktionen" data-zf-wege hidden><button type="button" class="knopf zweit klein-knopf" data-zf-mailcode>Code per E-Mail schicken</button>' . $pk . '</p>'
        . '<p class="hilfe" data-zf-meldung role="status" hidden></p>';
}

/** Zweiten Faktor zurücksetzen (Geheimnis, Zeitschritt, Codes weg). Nächste Anmeldung richtet neu ein. */
function totp_zuruecksetzen(int $kontoId, string $wer, ?int $durch = null): void
{
    db_transaktion(function () use ($kontoId): void {
        db_ausfuehren('UPDATE konto SET totp_geheimnis = NULL, totp_seit = NULL, totp_schritt = NULL, zweitfaktor_mail = 0, mailcode_hash = NULL, mailcode_bis = NULL, sitzung_version = sitzung_version + 1 WHERE id = ?', [$kontoId]);
        db_ausfuehren('DELETE FROM konto_code WHERE konto_id = ?', [$kontoId]);
        if (db_tabelle_da('passkey')) {
            db_ausfuehren('DELETE FROM passkey WHERE konto_id = ?', [$kontoId]); // Passkeys gehören zum zweiten Faktor (E-55)
        }
    });
    audit_schreiben('zweitfaktor', 'Zweiter Faktor zurückgesetzt (' . $wer . ') für Konto ' . $kontoId, $durch);
}
