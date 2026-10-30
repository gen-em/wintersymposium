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
    $offen = (int)db_wert('SELECT COUNT(*) FROM konto_code WHERE konto_id = ? AND benutzt_am IS NULL', [$kontoId]);
    $an = $z !== null && $z['totp_seit'] !== null;
    $mail = $z !== null && (int)$z['zweitfaktor_mail'] === 1;
    return [
        'an' => $an,
        'mail' => $mail,
        'eingerichtet' => $an || $mail,
        'angefangen' => $z !== null && $z['totp_seit'] === null && (int)$z['g'] === 1,
        'codes_offen' => $offen,
    ];
}

/** Ist ein zweiter Faktor eingerichtet (App oder E-Mail-Code)? Für Konto-Zeilen aus der Datenbank. */
function zweitfaktor_da(array $k): bool
{
    return ($k['totp_seit'] ?? null) !== null || (int)($k['zweitfaktor_mail'] ?? 0) === 1;
}

/* ---- E-Mail-Code (Admin 2026-10-30: für alle frei wählbar) --------------- */

const MAILCODE_GUELTIG_S = 600;

/** Neuen Code per Mail schicken (der vorige gilt nicht mehr). */
function mailcode_senden(array $k): void
{
    $code = str_pad((string)random_int(0, 999999), TOTP_STELLEN, '0', STR_PAD_LEFT);
    db_ausfuehren('UPDATE konto SET mailcode_hash = ?, mailcode_bis = ? WHERE id = ?', [password_hash($code, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s', time() + MAILCODE_GUELTIG_S), (int)$k['id']]);
    mail_vorlage_einreihen((string)$k['email'], 'zweitfaktor_code', ['vorname' => tk_namen($k)['vorname'], 'code' => $code]);
}

/** Code aus der Mail prüfen (einmal gültig). */
function mailcode_pruefen(int $kontoId, string $eingabe): bool
{
    $ziffern = preg_replace('/\s+/', '', $eingabe) ?? '';
    if (preg_match('/^\d{' . TOTP_STELLEN . '}$/', $ziffern) !== 1) {
        return false;
    }
    $z = db_zeile('SELECT mailcode_hash, mailcode_bis FROM konto WHERE id = ?', [$kontoId]);
    if ($z === null || $z['mailcode_hash'] === null || strtotime($z['mailcode_bis'] . ' UTC') < time() || !password_verify($ziffern, (string)$z['mailcode_hash'])) {
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

/** Zweiten Faktor zurücksetzen (Geheimnis, Zeitschritt, Codes weg). Nächste Anmeldung richtet neu ein. */
function totp_zuruecksetzen(int $kontoId, string $wer, ?int $durch = null): void
{
    db_transaktion(function () use ($kontoId): void {
        db_ausfuehren('UPDATE konto SET totp_geheimnis = NULL, totp_seit = NULL, totp_schritt = NULL, zweitfaktor_mail = 0, mailcode_hash = NULL, mailcode_bis = NULL, sitzung_version = sitzung_version + 1 WHERE id = ?', [$kontoId]);
        db_ausfuehren('DELETE FROM konto_code WHERE konto_id = ?', [$kontoId]);
    });
    audit_schreiben('zweitfaktor', 'Zweiter Faktor zurückgesetzt (' . $wer . ') für Konto ' . $kontoId, $durch);
}
