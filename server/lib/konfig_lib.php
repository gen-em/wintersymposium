<?php
declare(strict_types=1);

/*
 * Konfiguration lesen (Muster aus NAdoku konfig_lib.php).
 *
 * Die Datei ist server/config.php. Für Tests auf der Kommandozeile und im
 * eingebauten PHP-Server darf die Umgebungsvariable WS_CONFIG einen anderen
 * Pfad nennen; auf dem Webspace (FPM) wird sie nie beachtet.
 */

function konfig_datei(): string
{
    $sapi = PHP_SAPI;
    if ($sapi === 'cli' || $sapi === 'cli-server') {
        $env = getenv('WS_CONFIG');
        if (is_string($env) && $env !== '') {
            return $env;
        }
    }
    return dirname(__DIR__) . '/config.php';
}

function konfig_speicher(bool $verwerfen = false): array
{
    static $cfg = null;
    if ($verwerfen) {
        $cfg = null;
        return [];
    }
    if ($cfg === null) {
        $datei = konfig_datei();
        $roh = is_file($datei) ? require $datei : [];
        $cfg = is_array($roh) ? $roh : [];
    }
    return $cfg;
}

/** Wert über einen Punktpfad lesen, z. B. konfig('db.name'). */
function konfig(string $pfad, mixed $vorgabe = null): mixed
{
    $wert = konfig_speicher();
    foreach (explode('.', $pfad) as $teil) {
        if (!is_array($wert) || !array_key_exists($teil, $wert)) {
            return $vorgabe;
        }
        $wert = $wert[$teil];
    }
    return $wert;
}

function konfig_vorhanden(): bool
{
    return is_file(konfig_datei());
}

function konfig_verwerfen(): void
{
    konfig_speicher(true);
}

function umgebung(): string
{
    $u = (string)konfig('umgebung', 'produktion');
    return in_array($u, ['staging', 'produktion', 'entwicklung'], true) ? $u : 'produktion';
}

function entwicklung(): bool
{
    return umgebung() === 'entwicklung';
}

/**
 * Was an der Konfiguration fehlt, als Liste lesbarer Hinweise (ohne Werte).
 * Leer heißt: vollständig.
 */
function konfig_maengel(): array
{
    if (!konfig_vorhanden()) {
        return ['Die Datei config.php fehlt (Vorlage: config.example.php).'];
    }
    $m = [];
    foreach (['db.host', 'db.name', 'db.user'] as $k) {
        if (trim((string)konfig($k, '')) === '') {
            $m[] = "Eintrag $k ist leer.";
        }
    }
    foreach (['geheimnis', 'installations_schluessel', 'jobs_token', 'health_token'] as $k) {
        if (strlen((string)konfig($k, '')) < 32) {
            $m[] = "Eintrag $k fehlt oder ist kürzer als 32 Zeichen.";
        }
    }
    return $m;
}

/** Ordner für Laufzeitdaten (Sitzungen, Sperrdateien, Logs), außerhalb des Webroots. */
function laufzeit_ordner(): string
{
    $pfad = (string)konfig('laufzeit_ordner', dirname(__DIR__) . '/var');
    return rtrim($pfad, '/');
}

/** Unterordner im Laufzeitordner, wird bei Bedarf angelegt. */
function laufzeit_pfad(string $unter = ''): string
{
    $basis = laufzeit_ordner();
    $pfad = $unter === '' ? $basis : $basis . '/' . $unter;
    if (!is_dir($pfad)) {
        @mkdir($pfad, 0700, true);
    }
    if (!is_file($basis . '/.htaccess')) {
        // Zweite Sicherung, falls der Ordner doch im Webroot landet.
        @file_put_contents($basis . '/.htaccess', "Require all denied\n");
    }
    return $pfad;
}

/** Version der App aus der Datei VERSION (bei Auslieferung nach server/ kopiert). */
function app_version(): string
{
    static $v = null;
    if ($v === null) {
        $v = 'unbekannt';
        foreach ([dirname(__DIR__) . '/VERSION', dirname(__DIR__, 2) . '/VERSION'] as $datei) {
            if (is_file($datei)) {
                $v = trim((string)file_get_contents($datei));
                break;
            }
        }
    }
    return $v;
}
