<?php
declare(strict_types=1);

/*
 * Software-Authenticator für die Tests (Passkeys, E-55): erzeugt Schlüssel,
 * Anlage- und Anmelde-Antworten wie ein Browser, mit CBOR-Schreiber für die
 * nötige Teilmenge. Nur für Tests – nie im Server geladen.
 */

final class TestBytes
{
    public function __construct(public readonly string $b)
    {
    }
}

function test_cbor(mixed $v): string
{
    $kopf = function (int $typ, int $n): string {
        if ($n < 24) {
            return chr(($typ << 5) | $n);
        }
        if ($n < 256) {
            return chr(($typ << 5) | 24) . chr($n);
        }
        if ($n < 65536) {
            return chr(($typ << 5) | 25) . pack('n', $n);
        }
        return chr(($typ << 5) | 26) . pack('N', $n);
    };
    if (is_int($v)) {
        return $v >= 0 ? $kopf(0, $v) : $kopf(1, -1 - $v);
    }
    if ($v instanceof TestBytes) {
        return $kopf(2, strlen($v->b)) . $v->b;
    }
    if (is_string($v)) {
        return $kopf(3, strlen($v)) . $v;
    }
    if (is_array($v)) {
        $h = $kopf(5, count($v));
        foreach ($v as $k => $w) {
            $h .= test_cbor($k) . test_cbor($w);
        }
        return $h;
    }
    throw new InvalidArgumentException('CBOR: Typ');
}

/** Neuer Authenticator mit Schlüssel (ES256 oder RS256). */
function test_pk_geraet(string $art = 'es256'): array
{
    $priv = $art === 'rs256'
        ? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048])
        : openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $d = openssl_pkey_get_details($priv);
    $cose = $art === 'rs256'
        ? [1 => 3, 3 => -257, -1 => new TestBytes($d['rsa']['n']), -2 => new TestBytes($d['rsa']['e'])]
        : [1 => 2, 3 => -7, -1 => 1, -2 => new TestBytes(str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)), -3 => new TestBytes(str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT))];
    return ['priv' => $priv, 'cose' => $cose, 'kennung' => random_bytes(16), 'zaehler' => 0];
}

/** Antwort auf navigator.credentials.create(). $opt: flags, origin, cose (überschreiben für Fehlerfälle). */
function test_pk_anlage(array &$g, string $challenge, string $rpId, string $origin, array $opt = []): array
{
    $cdj = json_encode(['type' => 'webauthn.create', 'challenge' => $challenge, 'origin' => $opt['origin'] ?? $origin, 'crossOrigin' => false]);
    $flags = $opt['flags'] ?? 0x45; // UP | UV | AT
    $auth = hash('sha256', $opt['rp'] ?? $rpId, true) . chr($flags) . pack('N', $g['zaehler'])
        . str_repeat("\0", 16) . pack('n', strlen($g['kennung'])) . $g['kennung'] . test_cbor($opt['cose'] ?? $g['cose']);
    $att = test_cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new TestBytes($auth)]);
    return ['rawId' => pk_b64u($g['kennung']), 'clientDataJSON' => pk_b64u($cdj), 'attestationObject' => pk_b64u($att)];
}

/** Antwort auf navigator.credentials.get(). Zähler steigt, außer $opt['zaehler'] ist gesetzt. */
function test_pk_login(array &$g, string $challenge, string $rpId, string $origin, array $opt = []): array
{
    $g['zaehler'] = $opt['zaehler'] ?? $g['zaehler'] + 1;
    $cdj = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => $opt['origin'] ?? $origin, 'crossOrigin' => false]);
    $auth = hash('sha256', $opt['rp'] ?? $rpId, true) . chr($opt['flags'] ?? 0x05) . pack('N', $g['zaehler']);
    openssl_sign($auth . hash('sha256', $cdj, true), $sig, $g['priv'], OPENSSL_ALGO_SHA256);
    if (!empty($opt['sig_kaputt'])) {
        $sig[strlen($sig) - 1] = chr(ord($sig[strlen($sig) - 1]) ^ 1);
    }
    return ['rawId' => pk_b64u($g['kennung']), 'clientDataJSON' => pk_b64u($cdj), 'authenticatorData' => pk_b64u($auth), 'signature' => pk_b64u($sig)];
}
