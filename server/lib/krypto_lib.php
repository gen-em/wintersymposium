<?php
declare(strict_types=1);

/*
 * Versiegeln kleiner Geheimnisse in der Datenbank (Muster aus NAdoku
 * serverkrypto_lib.php: sk_versiegeln/sk_oeffnen, AES-256-GCM, E-18).
 *
 * Anders als in NAdoku gibt es keinen eigenen Serverschlüssel in config.php:
 * Der Schlüssel wird per HKDF aus dem vorhandenen 'geheimnis' abgeleitet.
 * So braucht die Einrichtung keinen weiteren Eintrag. Folge: Wird das
 * Geheimnis in config.php getauscht, lassen sich versiegelte Werte nicht mehr
 * öffnen – beim zweiten Faktor helfen dann die Wiederherstellungscodes oder
 * ein Zurücksetzen durch den Betreiber.
 *
 * Der Zweck (z. B. 'totp|12') geht als zusätzliche Daten in die Prüfsumme ein:
 * Ein Wert lässt sich nicht auf ein anderes Konto umhängen.
 */

const KRYPTO_PRAEFIX = 'ws1:';
const KRYPTO_NONCE = 12;
const KRYPTO_TAG = 16;

function krypto_schluessel(): string
{
    $geheimnis = (string)konfig('geheimnis', '');
    if (strlen($geheimnis) < 32) {
        throw new RuntimeException('Kein ausreichendes Geheimnis in config.php.');
    }
    return hash_hkdf('sha256', $geheimnis, 32, 'wintersymposium versiegeln v1');
}

function versiegeln(string $klartext, string $zweck): string
{
    $nonce = random_bytes(KRYPTO_NONCE);
    $tag = '';
    $ct = openssl_encrypt($klartext, 'aes-256-gcm', krypto_schluessel(), OPENSSL_RAW_DATA, $nonce, $tag, 'ws1|' . $zweck, KRYPTO_TAG);
    if ($ct === false) {
        throw new RuntimeException('Versiegeln fehlgeschlagen.');
    }
    return KRYPTO_PRAEFIX . base64_encode($nonce . $tag . $ct);
}

/** Klartext oder null (anderer Schlüssel, anderer Zweck, beschädigt). */
function oeffnen(string $paket, string $zweck): ?string
{
    if (!str_starts_with($paket, KRYPTO_PRAEFIX)) {
        return null;
    }
    $roh = base64_decode(substr($paket, strlen(KRYPTO_PRAEFIX)), true);
    if ($roh === false || strlen($roh) < KRYPTO_NONCE + KRYPTO_TAG) {
        return null;
    }
    $klar = openssl_decrypt(
        substr($roh, KRYPTO_NONCE + KRYPTO_TAG),
        'aes-256-gcm',
        krypto_schluessel(),
        OPENSSL_RAW_DATA,
        substr($roh, 0, KRYPTO_NONCE),
        substr($roh, KRYPTO_NONCE, KRYPTO_TAG),
        'ws1|' . $zweck
    );
    return $klar === false ? null : $klar;
}
