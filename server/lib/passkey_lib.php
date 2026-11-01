<?php
declare(strict_types=1);

/*
 * Passkeys (O-14, E-55; Admin 2026-10-30 „Empfehlungen ok“).
 *
 * Ein Passkey ersetzt Passwort UND zweiten Faktor: Das Gerät (Besitz) und
 * Fingerabdruck, Gesicht oder Geräte-PIN (User Verification, UV) sind zwei
 * Faktoren. Deshalb verlangen wir UV bei Anlage und Anmeldung. Für alle Konten:
 * Teilnehmende freiwillig; Team-Konten dürfen einen Passkey als einzigen
 * zweiten Faktor haben (zweitfaktor_da), die Wiederherstellungscodes bleiben.
 *
 * Übernommen aus NAdoku passkey_lib.php (gleicher Urheber, AGPL-3.0, E-18):
 * der strenge CBOR-Leser (nur die nötige Teilmenge), die Prüfung von
 * clientDataJSON, authData und Zähler, die Grenzen für Schlüssel und Felder.
 * Geändert: Signatur und Schlüssel über die PHP-Erweiterung OpenSSL statt
 * phpseclib (keine neue Abhängigkeit); der COSE-Schlüssel wird beim Anlegen
 * in SPKI (PEM) übersetzt und so gespeichert. Attestation „none“, keine
 * Herstellerdaten. rp.id = Host der Anfrage nach host_tor() (nur bekannte
 * Hosts); ein Passkey gilt nur unter dem Namen, unter dem er angelegt wurde.
 */

const PK_HOECHSTENS = 10;
const PK_GUELTIG_S = 300;
const PK_BEZEICHNUNG_MAX = 40;
const PK_ALGS = [-7 => 'ES256', -257 => 'RS256'];
const PK_KENNUNG_MAX = 1023;
const PK_CBOR_TIEFE = 8;
const PK_RSA_BITS_MIN = 2048;
const PK_RSA_BITS_MAX = 4096;
const PK_RSA_E_BYTES_MAX = 8;
const PK_FELD_MAX = 32768;
const PK_P256_P = "\xff\xff\xff\xff\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00\x00\x00"
    . "\x00\x00\x00\x00\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff";
/** Flags in authData */
const PK_UP = 0x01;
const PK_UV = 0x04;

/** Byte-Kette aus CBOR (getrennt von Textketten). */
final class PkBytes
{
    public function __construct(public readonly string $b)
    {
    }
}

/** Jede Ablehnung beim Lesen oder Prüfen (Meldung nur fürs Protokoll). */
final class PkFehler extends RuntimeException
{
}

/* ---- Base64url ----------------------------------------------------------- */

function pk_b64u(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** Streng: nur Base64url ohne Füllzeichen, in kanonischer Form; sonst null. */
function pk_b64u_lesen(string $text): ?string
{
    if ($text === '' || !preg_match('/^[A-Za-z0-9_-]+\z/', $text) || strlen($text) % 4 === 1) {
        return null;
    }
    $roh = base64_decode(strtr($text, '-_', '+/'), true);
    return $roh === false || pk_b64u($roh) !== $text ? null : $roh;
}

/** Feld der Browser-Antwort (Base64url-Text, begrenzte Größe). */
function pk_feld(array $antwort, string $name): string
{
    $v = $antwort[$name] ?? null;
    if (!is_string($v) || strlen($v) > intdiv(PK_FELD_MAX * 4 + 2, 3)) {
        throw new PkFehler('Antwort: ' . $name . ' fehlt oder zu groß');
    }
    $roh = pk_b64u_lesen($v);
    if ($roh === null) {
        throw new PkFehler('Antwort: ' . $name . ' ist kein Base64url');
    }
    return $roh;
}

/* ---- CBOR (RFC 8949, nur die nötige Teilmenge; aus NAdoku) --------------- */

function pk_cbor_lesen(string $bytes): mixed
{
    $pos = 0;
    $wert = pk_cbor_element($bytes, $pos, 1);
    if ($pos !== strlen($bytes)) {
        throw new PkFehler('CBOR: Rest hinter dem Element');
    }
    return $wert;
}

function pk_cbor_art(string $b, int $pos): int
{
    return $pos < strlen($b) ? ord($b[$pos]) >> 5 : -1;
}

function pk_cbor_element(string $b, int &$pos, int $tiefe): mixed
{
    $laenge = strlen($b);
    if ($pos >= $laenge) {
        throw new PkFehler('CBOR: abgeschnitten');
    }
    $erstes = ord($b[$pos++]);
    $typ = $erstes >> 5;
    $info = $erstes & 0x1f;
    if ($typ === 6 || $typ === 7) {
        throw new PkFehler('CBOR: Marken, Fließzahlen und einfache Werte werden nicht gelesen');
    }
    if ($info >= 28) {
        throw new PkFehler('CBOR: unbestimmte oder reservierte Länge');
    }
    if (($typ === 4 || $typ === 5) && $tiefe > PK_CBOR_TIEFE) {
        throw new PkFehler('CBOR: zu tief');
    }
    if ($info < 24) {
        $n = $info;
    } else {
        $stellen = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$info];
        if ($pos + $stellen > $laenge) {
            throw new PkFehler('CBOR: abgeschnitten');
        }
        $n = 0;
        for ($i = 0; $i < $stellen; $i++) {
            if ($n > (PHP_INT_MAX >> 8)) {
                throw new PkFehler('CBOR: Zahl zu groß');
            }
            $n = ($n << 8) | ord($b[$pos++]);
        }
    }
    switch ($typ) {
        case 0:
            return $n;
        case 1:
            return -1 - $n;
        case 2:
        case 3:
            if ($n > $laenge - $pos) {
                throw new PkFehler('CBOR: Kette länger als der Rest');
            }
            $kette = substr($b, $pos, $n);
            $pos += $n;
            if ($typ === 2) {
                return new PkBytes($kette);
            }
            if (!mb_check_encoding($kette, 'UTF-8')) {
                throw new PkFehler('CBOR: Textkette ist kein UTF-8');
            }
            return $kette;
        case 4:
            if ($n > $laenge - $pos) {
                throw new PkFehler('CBOR: Liste länger als der Rest');
            }
            $liste = [];
            for ($i = 0; $i < $n; $i++) {
                $liste[] = pk_cbor_element($b, $pos, $tiefe + 1);
            }
            return $liste;
        default:
            if ($n * 2 > $laenge - $pos) {
                throw new PkFehler('CBOR: Karte länger als der Rest');
            }
            $karte = [];
            for ($i = 0; $i < $n; $i++) {
                $s = pk_cbor_element($b, $pos, $tiefe + 1);
                if (!is_int($s) && !is_string($s)) {
                    throw new PkFehler('CBOR: Schlüssel weder Zahl noch Text');
                }
                // PHP machte aus dem Text "1" den Schlüssel 1 (NAdoku F-SR-34)
                if (is_string($s) && preg_match('/^-?(0|[1-9][0-9]*)\z/', $s)) {
                    throw new PkFehler('CBOR: Textschlüssel sieht aus wie eine Zahl');
                }
                if (array_key_exists($s, $karte)) {
                    throw new PkFehler('CBOR: doppelter Schlüssel');
                }
                $karte[$s] = pk_cbor_element($b, $pos, $tiefe + 1);
            }
            return $karte;
    }
}

/* ---- COSE → SPKI (PEM) über OpenSSL -------------------------------------- */

/** DER-Länge */
function pk_der_laenge(int $n): string
{
    if ($n < 0x80) {
        return chr($n);
    }
    $b = ltrim(pack('N', $n), "\0");
    return chr(0x80 | strlen($b)) . $b;
}

function pk_der(int $tag, string $inhalt): string
{
    return chr($tag) . pk_der_laenge(strlen($inhalt)) . $inhalt;
}

/** Positive ASN.1-Ganzzahl aus Bytes (führende 0, wenn das oberste Bit gesetzt ist). */
function pk_der_int(string $bytes): string
{
    return pk_der(0x02, (ord($bytes[0]) & 0x80) ? "\0" . $bytes : $bytes);
}

function pk_pem(string $der): string
{
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * COSE-Schlüssel (nur EC2/P-256 mit -7 und RSA mit -257, genau die nötigen
 * Labels) in PEM. Gibt ['pem' => …, 'alg' => …] zurück.
 */
function pk_cose_laden(mixed $cose): array
{
    if (!is_array($cose) || array_is_list($cose)) {
        throw new PkFehler('COSE: keine Karte');
    }
    $alg = $cose[3] ?? null;
    if (!is_int($alg) || !isset(PK_ALGS[$alg])) {
        throw new PkFehler('COSE: Verfahren nicht erlaubt');
    }
    $labels = array_keys($cose);
    sort($labels);
    $bytes = static fn(mixed $v): ?string => $v instanceof PkBytes ? $v->b : null;
    if ($alg === -7) {
        $x = $bytes($cose[-2] ?? null);
        $y = $bytes($cose[-3] ?? null);
        if ($labels !== [-3, -2, -1, 1, 3] || ($cose[1] ?? null) !== 2 || ($cose[-1] ?? null) !== 1
            || $x === null || $y === null || strlen($x) !== 32 || strlen($y) !== 32
            || strcmp($x, PK_P256_P) >= 0 || strcmp($y, PK_P256_P) >= 0) {
            throw new PkFehler('COSE: kein EC2-Schlüssel auf P-256');
        }
        // SPKI für P-256: AlgorithmIdentifier (ecPublicKey, prime256v1) + unkomprimierter Punkt
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        $pem = pk_pem($der);
        if (pk_schluessel_laden($pem, -7) === null) {
            throw new PkFehler('COSE: Punkt liegt nicht auf der Kurve');
        }
        return ['pem' => $pem, 'alg' => -7];
    }
    $n = $bytes($cose[-1] ?? null);
    $e = $bytes($cose[-2] ?? null);
    if ($labels !== [-2, -1, 1, 3] || ($cose[1] ?? null) !== 3 || $n === null || $e === null || $n === '' || $e === '') {
        throw new PkFehler('COSE: kein RSA-Schlüssel');
    }
    if ($e[0] === "\0" || $n[0] === "\0") {
        throw new PkFehler('COSE: RSA-Parameter nicht minimal');
    }
    if (strlen($e) > PK_RSA_E_BYTES_MAX || (ord($e[-1]) & 1) === 0 || (strlen($e) === 1 && ord($e) < 3) || (ord($n[-1]) & 1) === 0) {
        throw new PkFehler('COSE: RSA-Exponent oder -Modul nicht erlaubt');
    }
    $rsaKey = pk_der(0x30, pk_der_int($n) . pk_der_int($e));
    $der = pk_der(0x30, hex2bin('300d06092a864886f70d0101010500') . pk_der(0x03, "\0" . $rsaKey));
    $pem = pk_pem($der);
    if (pk_schluessel_laden($pem, -257) === null) {
        throw new PkFehler('COSE: RSA-Schlüssel außerhalb der Grenzen');
    }
    return ['pem' => $pem, 'alg' => -257];
}

/** Gespeicherten Schlüssel laden, mit denselben Grenzen wie beim Anlegen; sonst null. */
function pk_schluessel_laden(string $pem, int $alg): ?OpenSSLAsymmetricKey
{
    $k = @openssl_pkey_get_public($pem);
    if ($k === false) {
        return null;
    }
    $d = openssl_pkey_get_details($k);
    if (!is_array($d)) {
        return null;
    }
    if ($alg === -7) {
        return ($d['type'] ?? null) === OPENSSL_KEYTYPE_EC && ($d['ec']['curve_name'] ?? '') === 'prime256v1' ? $k : null;
    }
    if ($alg === -257 && ($d['type'] ?? null) === OPENSSL_KEYTYPE_RSA) {
        $bits = (int)($d['bits'] ?? 0);
        $e = (string)($d['rsa']['e'] ?? '');
        if ($bits >= PK_RSA_BITS_MIN && $bits <= PK_RSA_BITS_MAX && $e !== '' && strlen(ltrim($e, "\0")) <= PK_RSA_E_BYTES_MAX
            && (ord($e[-1]) & 1) === 1 && !(strlen(ltrim($e, "\0")) === 1 && ord(ltrim($e, "\0")) < 3)) {
            return $k;
        }
    }
    return null;
}

/** DER-Form einer ES256-Signatur: Folge aus genau zwei INTEGER (aus NAdoku). */
function pk_es256_form(string $sig): bool
{
    $n = strlen($sig);
    if ($n < 8 || $n > 73 || $sig[0] !== "\x30") {
        return false;
    }
    $l = ord($sig[1]);
    $p = 2;
    if ($l === 0x81) {
        $l = ord($sig[2]);
        $p = 3;
    } elseif ($l >= 0x80) {
        return false;
    }
    if ($p + $l !== $n) {
        return false;
    }
    for ($i = 0; $i < 2; $i++) {
        if ($p + 2 > $n || $sig[$p] !== "\x02") {
            return false;
        }
        $li = ord($sig[$p + 1]);
        if ($li < 1 || $li > 33 || $p + 2 + $li > $n) {
            return false;
        }
        $p += 2 + $li;
    }
    return $p === $n;
}

/* ---- Ursprung, Herausforderung, authData --------------------------------- */

/**
 * Ursprung und rp.id aus der Adresse der Anfrage (basis_url nach host_tor,
 * also nur eingetragene Hosts). Keine IP-Adresse; HTTPS, außer localhost.
 * Gibt null zurück, wenn hier keine Passkeys gehen.
 */
function pk_ursprung(): ?array
{
    $t = parse_url(basis_url());
    $schema = strtolower((string)($t['scheme'] ?? ''));
    $host = rtrim(strtolower((string)($t['host'] ?? '')), '.');
    if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\z/', $host)) {
        return null;
    }
    if ($schema !== 'https' && !($schema === 'http' && $host === 'localhost')) {
        return null;
    }
    $p = isset($t['port']) ? (int)$t['port'] : null;
    // Entwicklung und Browser-Durchlauf (localhost:Port): der Port gehört zum Ursprung, den der Browser schickt
    if ($p === null && $host === 'localhost' && isset($_SERVER['SERVER_PORT']) && PHP_SAPI !== 'cli') {
        $p = (int)$_SERVER['SERVER_PORT'];
    }
    $port = ($p === null || ($schema === 'https' && $p === 443) || ($schema === 'http' && $p === 80)) ? '' : ':' . $p;
    return ['ursprung' => $schema . '://' . $host . $port, 'rp_id' => $host];
}

/** Herausforderung in der Sitzung ablegen (je Zweck eine, neue verdrängt alte). */
function pk_herausforderung(string $zweck, ?int $kontoId = null): string
{
    $h = pk_b64u(random_bytes(32));
    $_SESSION['pk'][$zweck] = ['h' => $h, 'bis' => time() + PK_GUELTIG_S, 'konto' => $kontoId];
    return $h;
}

/** Abgelegte Herausforderung holen und verbrauchen (gilt einmal). */
function pk_herausforderung_nehmen(string $zweck): array
{
    $a = $_SESSION['pk'][$zweck] ?? null;
    unset($_SESSION['pk'][$zweck]);
    if (!is_array($a) || !is_string($a['h'] ?? null) || (int)($a['bis'] ?? 0) < time()) {
        throw new PkFehler('Herausforderung fehlt oder ist abgelaufen');
    }
    return $a;
}

function pk_authdata_lesen(string $a, bool $mitSchluessel): array
{
    if (strlen($a) < 37) {
        throw new PkFehler('authData: zu kurz');
    }
    $flags = ord($a[32]);
    $zaehler = unpack('N', substr($a, 33, 4))[1];
    $pos = 37;
    $kennung = null;
    $cose = null;
    $at = ($flags & 0x40) !== 0;
    if ($mitSchluessel !== $at) {
        throw new PkFehler($mitSchluessel ? 'authData: AT fehlt' : 'authData: AT bei einer Anmeldung');
    }
    if (($flags & 0x10) !== 0 && ($flags & 0x08) === 0) {
        throw new PkFehler('authData: BS ohne BE');
    }
    if ($at) {
        if (strlen($a) < $pos + 18) {
            throw new PkFehler('authData: abgeschnitten');
        }
        $pos += 16; // AAGUID: nicht verwendet (keine Herstellerdaten)
        $lk = unpack('n', substr($a, $pos, 2))[1];
        $pos += 2;
        if ($lk < 1 || $lk > PK_KENNUNG_MAX || strlen($a) < $pos + $lk) {
            throw new PkFehler('authData: Kennung ungültig');
        }
        $kennung = substr($a, $pos, $lk);
        $pos += $lk;
        if (pk_cbor_art($a, $pos) !== 5) {
            throw new PkFehler('authData: COSE-Schlüssel ist keine Karte');
        }
        $cose = pk_cbor_element($a, $pos, 1);
    }
    if (($flags & 0x80) !== 0) {
        if (pk_cbor_art($a, $pos) !== 5) {
            throw new PkFehler('authData: Erweiterungen sind keine Karte');
        }
        pk_cbor_element($a, $pos, 1);
    }
    if ($pos !== strlen($a)) {
        throw new PkFehler('authData: Rest hinter den Daten');
    }
    return ['rp_hash' => substr($a, 0, 32), 'flags' => $flags, 'zaehler' => $zaehler, 'kennung' => $kennung, 'cose' => $cose];
}

function pk_client_pruefen(string $json, string $art, string $herausforderung, string $ursprung): void
{
    $c = json_decode($json, true);
    if (!is_array($c) || array_is_list($c)) {
        throw new PkFehler('clientData: kein JSON-Objekt');
    }
    if (($c['type'] ?? null) !== $art) {
        throw new PkFehler('clientData: falsche Art');
    }
    if (!is_string($c['challenge'] ?? null) || !hash_equals($herausforderung, $c['challenge'])) {
        throw new PkFehler('clientData: fremde Herausforderung');
    }
    if (($c['origin'] ?? null) !== $ursprung) {
        throw new PkFehler('clientData: fremder Ursprung');
    }
    if ((array_key_exists('crossOrigin', $c) && $c['crossOrigin'] !== false) || isset($c['topOrigin'])) {
        throw new PkFehler('clientData: aus einem fremden Rahmen');
    }
}

/** Flags prüfen: Nutzergeste und Nutzerprüfung (UV) – der Passkey ersetzt Passwort und zweiten Faktor. */
function pk_flags_pruefen(array $a, string $rpId): void
{
    if (!hash_equals(hash('sha256', $rpId, true), $a['rp_hash'])) {
        throw new PkFehler('authData: fremde rpId');
    }
    if (($a['flags'] & PK_UP) === 0) {
        throw new PkFehler('authData: UP fehlt');
    }
    if (($a['flags'] & PK_UV) === 0) {
        throw new PkFehler('authData: UV fehlt (ohne Fingerabdruck, Gesicht oder PIN)');
    }
}

/* ---- Zeremonien ---------------------------------------------------------- */

/** Stabile, nicht sprechende Nutzerkennung für den Authenticator (keine Kontonummer nach außen). */
function pk_nutzer_kennung(int $kontoId): string
{
    return substr(hash_hmac('sha256', 'passkey-nutzer|' . $kontoId, krypto_schluessel(), true), 0, 16);
}

/** Optionen für navigator.credentials.create() */
function pk_anlage_optionen(array $konto): array
{
    $u = pk_ursprung();
    if ($u === null) {
        throw new PkFehler('Passkeys gehen unter dieser Adresse nicht');
    }
    return [
        'challenge' => pk_herausforderung('anlage', (int)$konto['id']),
        'rp' => ['id' => $u['rp_id'], 'name' => 'Wintersymposium'],
        'user' => ['id' => pk_b64u(pk_nutzer_kennung((int)$konto['id'])), 'name' => (string)$konto['email'], 'displayName' => (string)$konto['name']],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
        'timeout' => PK_GUELTIG_S * 1000,
        'attestation' => 'none',
        'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
        'excludeCredentials' => array_map(fn($k) => ['type' => 'public-key', 'id' => $k], pk_kennungen((int)$konto['id'], $u['rp_id'])),
    ];
}

/** Optionen für navigator.credentials.get() (ohne Konto: der Browser bietet die passenden Passkeys an). */
function pk_login_optionen(): array
{
    $u = pk_ursprung();
    if ($u === null) {
        throw new PkFehler('Passkeys gehen unter dieser Adresse nicht');
    }
    return ['challenge' => pk_herausforderung('login'), 'rpId' => $u['rp_id'], 'timeout' => PK_GUELTIG_S * 1000, 'userVerification' => 'required', 'allowCredentials' => []];
}

/**
 * Anlage prüfen und speichern. Gibt die neue ID zurück; wirft PkFehler.
 * $antwort: rawId, clientDataJSON, attestationObject (Base64url).
 */
function pk_anlegen(int $kontoId, array $antwort, string $bezeichnung): int
{
    $u = pk_ursprung();
    if ($u === null) {
        throw new PkFehler('Passkeys gehen unter dieser Adresse nicht');
    }
    $abl = pk_herausforderung_nehmen('anlage');
    if ((int)($abl['konto'] ?? 0) !== $kontoId) {
        throw new PkFehler('Herausforderung gehört zu einem anderen Konto');
    }
    $client = pk_feld($antwort, 'clientDataJSON');
    $att = pk_feld($antwort, 'attestationObject');
    $rawId = pk_feld($antwort, 'rawId');
    pk_client_pruefen($client, 'webauthn.create', $abl['h'], $u['ursprung']);
    if (pk_cbor_art($att, 0) !== 5) {
        throw new PkFehler('attestationObject: keine Karte');
    }
    $obj = pk_cbor_lesen($att);
    $stmt = is_array($obj) ? ($obj['attStmt'] ?? null) : null;
    if (!is_array($obj) || !is_string($obj['fmt'] ?? null) || !is_array($stmt) || ($stmt !== [] && array_is_list($stmt))
        || !($obj['authData'] ?? null) instanceof PkBytes) {
        throw new PkFehler('attestationObject: Aufbau');
    }
    $a = pk_authdata_lesen($obj['authData']->b, true);
    pk_flags_pruefen($a, $u['rp_id']);
    if (!hash_equals((string)$a['kennung'], $rawId)) {
        throw new PkFehler('Kennung passt nicht zu rawId');
    }
    $k = pk_cose_laden($a['cose']);
    if (pk_zahl($kontoId) >= PK_HOECHSTENS) {
        throw new PkFehler('Höchstens ' . PK_HOECHSTENS . ' Passkeys je Konto');
    }
    $hash = hash('sha256', (string)$a['kennung']);
    if (db_gibt_es('SELECT COUNT(*) FROM passkey WHERE kennung_hash = ?', [$hash])) {
        throw new PkFehler('Diesen Passkey gibt es schon');
    }
    $bez = pk_bezeichnung($bezeichnung);
    db_ausfuehren(
        'INSERT INTO passkey (konto_id, rp_id, kennung, kennung_hash, oeffentlich, alg, zaehler, bezeichnung, erstellt_am) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$kontoId, $u['rp_id'], pk_b64u((string)$a['kennung']), $hash, $k['pem'], $k['alg'], $a['zaehler'], $bez, db_jetzt()]
    );
    $id = (int)db()->lastInsertId();
    audit_schreiben('zweitfaktor', audit_text('konto', $kontoId, 'Passkey ' . $id . ' angelegt'), $kontoId, ['konto' => $kontoId]);
    return $id;
}

/** Optionen für navigator.credentials.get() zum Bestätigen: nur Passkeys dieses Kontos (Review B-7). */
function pk_bestaetigen_optionen(array $konto): array
{
    $u = pk_ursprung();
    if ($u === null) {
        throw new PkFehler('Passkeys gehen unter dieser Adresse nicht');
    }
    return [
        'challenge' => pk_herausforderung('bestaetigen', (int)$konto['id']), 'rpId' => $u['rp_id'], 'timeout' => PK_GUELTIG_S * 1000, 'userVerification' => 'required',
        'allowCredentials' => array_map(fn($k) => ['type' => 'public-key', 'id' => $k], pk_kennungen((int)$konto['id'], $u['rp_id'])),
    ];
}

/**
 * Anmeldung mit Passkey prüfen. Gibt die Konto-ID zurück; wirft PkFehler.
 * $antwort: rawId, clientDataJSON, authenticatorData, signature (Base64url).
 */
function pk_anmelden(array $antwort): int
{
    return pk_signatur_pruefen('login', $antwort);
}

/** Bestätigen mit einem Passkey des eingeloggten Kontos; wirft PkFehler. */
function pk_bestaetigen(int $kontoId, array $antwort): void
{
    if (pk_signatur_pruefen('bestaetigen', $antwort, $kontoId) !== $kontoId) {
        throw new PkFehler('Passkey gehört zu einem anderen Konto');
    }
}

/** Antwort auf navigator.credentials.get() prüfen (Herausforderung je Zweck, bei $kontoId an das Konto gebunden). */
function pk_signatur_pruefen(string $zweck, array $antwort, ?int $kontoId = null): int
{
    $u = pk_ursprung();
    if ($u === null) {
        throw new PkFehler('Passkeys gehen unter dieser Adresse nicht');
    }
    $abl = pk_herausforderung_nehmen($zweck);
    if ($kontoId !== null && (int)($abl['konto'] ?? 0) !== $kontoId) {
        throw new PkFehler('Herausforderung gehört zu einem anderen Konto');
    }
    $rawId = pk_feld($antwort, 'rawId');
    $client = pk_feld($antwort, 'clientDataJSON');
    $auth = pk_feld($antwort, 'authenticatorData');
    $sig = pk_feld($antwort, 'signature');
    pk_client_pruefen($client, 'webauthn.get', $abl['h'], $u['ursprung']);
    $pk = db_zeile('SELECT * FROM passkey WHERE kennung_hash = ? AND rp_id = ?', [hash('sha256', $rawId), $u['rp_id']]);
    if ($pk === null || !hash_equals((string)$pk['kennung'], pk_b64u($rawId))) {
        throw new PkFehler('unbekannter Passkey');
    }
    $a = pk_authdata_lesen($auth, false);
    pk_flags_pruefen($a, $u['rp_id']);
    $alg = (int)$pk['alg'];
    $schluessel = pk_schluessel_laden((string)$pk['oeffentlich'], $alg);
    if ($schluessel === null) {
        throw new PkFehler('gespeicherter Schlüssel lässt sich nicht laden');
    }
    if ($alg === -7 && !pk_es256_form($sig)) {
        throw new PkFehler('Signatur: keine DER-Folge mit r und s');
    }
    if ($alg === -257 && strlen($sig) !== intdiv((int)openssl_pkey_get_details($schluessel)['bits'] + 7, 8)) {
        throw new PkFehler('Signatur: Länge passt nicht');
    }
    if (openssl_verify($auth . hash('sha256', $client, true), $sig, $schluessel, OPENSSL_ALGO_SHA256) !== 1) {
        throw new PkFehler('Signatur passt nicht');
    }
    // Zähler: 0/0 (Authenticator ohne Zähler) oder streng steigend, atomar (aus NAdoku)
    $alt = (int)$pk['zaehler'];
    $neu = (int)$a['zaehler'];
    if ($neu === 0 && $alt === 0) {
        db_ausfuehren('UPDATE passkey SET zuletzt_am = ? WHERE id = ?', [db_jetzt(), (int)$pk['id']]);
    } elseif ($neu <= $alt || db_ausfuehren('UPDATE passkey SET zaehler = ?, zuletzt_am = ? WHERE id = ? AND zaehler = ?', [$neu, db_jetzt(), (int)$pk['id'], $alt])->rowCount() !== 1) {
        audit_schreiben('zweitfaktor', audit_text('konto', (int)$pk['konto_id'], 'Passkey ' . (int)$pk['id'] . ': Zähler zurückgelaufen (' . $alt . ' → ' . $neu . '), Login abgelehnt – möglicherweise kopiert'), (int)$pk['konto_id'], ['konto' => (int)$pk['konto_id']]);
        throw new PkFehler('Zähler zurückgelaufen');
    }
    return (int)$pk['konto_id'];
}

/* ---- Verwaltung ---------------------------------------------------------- */

function pk_bezeichnung(string $roh): string
{
    $t = trim(preg_replace('/[\p{Cc}\p{Cf}\s]+/u', ' ', $roh) ?? '');
    return mb_substr($t, 0, PK_BEZEICHNUNG_MAX);
}

/** Gibt es die Tabelle schon (vor Migration 13 nicht)? Nur „ja“ wird gemerkt. */
function pk_tabelle_da(): bool
{
    static $da = false;
    return $da = $da || db_tabelle_da('passkey');
}

/** Passkeys eines Kontos (alle Hosts). */
function pk_liste(int $kontoId): array
{
    return pk_tabelle_da() ? db_zeilen('SELECT id, rp_id, bezeichnung, erstellt_am, zuletzt_am FROM passkey WHERE konto_id = ? ORDER BY erstellt_am, id', [$kontoId]) : [];
}

function pk_zahl(int $kontoId): int
{
    return pk_tabelle_da() ? db_zahl('SELECT COUNT(*) FROM passkey WHERE konto_id = ?', [$kontoId]) : 0;
}

/** Kennungen (Base64url) für excludeCredentials. */
function pk_kennungen(int $kontoId, string $rpId): array
{
    return array_column(db_zeilen('SELECT kennung FROM passkey WHERE konto_id = ? AND rp_id = ?', [$kontoId, $rpId]), 'kennung');
}

function pk_umbenennen(int $kontoId, int $id, string $bezeichnung): bool
{
    return db_ausfuehren('UPDATE passkey SET bezeichnung = ? WHERE id = ? AND konto_id = ?', [pk_bezeichnung($bezeichnung), $id, $kontoId])->rowCount() > 0;
}

/**
 * Passkey entfernen. Team-Konten behalten immer einen zweiten Faktor: Der
 * letzte Passkey geht nicht weg, wenn weder App noch E-Mail-Code an sind.
 * Gibt eine Fehlermeldung oder null zurück.
 */
function pk_entfernen(int $kontoId, int $id): ?string
{
    $k = konto_laden($kontoId);
    $z = totp_zustand($kontoId);
    if ($k !== null && konto_ist_team($k) && !$z['an'] && !$z['mail'] && pk_zahl($kontoId) <= 1) {
        return 'Das ist dein einziger zweiter Faktor. Richte zuerst die Authenticator-App oder den Code per E-Mail ein.';
    }
    if (db_ausfuehren('DELETE FROM passkey WHERE id = ? AND konto_id = ?', [$id, $kontoId])->rowCount() === 0) {
        return 'Diesen Passkey gibt es nicht mehr.';
    }
    audit_schreiben('zweitfaktor', audit_text('konto', $kontoId, 'Passkey ' . $id . ' entfernt'), $kontoId, ['konto' => $kontoId]);
    return null;
}

/** Gelungenen Login mit Passkey protokollieren. */
function pk_login_protokollieren(int $kontoId): void
{
    audit_schreiben('login', audit_text('konto', $kontoId, 'Login mit Passkey'), $kontoId, ['konto' => $kontoId]);
}
