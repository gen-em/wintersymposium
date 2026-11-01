<?php
declare(strict_types=1);

/*
 * Installation (Muster aus NAdoku install.php, neu und klein).
 *
 * Legt die Tabellen an, trägt den Haupt-Host (und weitere Hosts) ein und
 * legt den ersten Betreiber an. Danach sperrt install.lock im
 * Laufzeitordner den Installer dauerhaft. Gibt es schon ein Konto,
 * verweigert der Installer die Arbeit auch ohne Sperrdatei.
 */

const PASSWORT_MIN_LAENGE = 12;

function install_sperrdatei(): string
{
    return laufzeit_pfad() . '/install.lock';
}

function installiert(): bool
{
    return is_file(install_sperrdatei());
}

/**
 * Eingaben prüfen. Gibt eine Liste von Fehlertexten zurück (leer = gut).
 * $e: name, email, passwort, passwort2, weitere_hosts (Text, je Zeile ein Host)
 */
function install_pruefen(array $e): array
{
    $f = [];
    $name = trim((string)($e['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) {
        $f[] = 'Bitte einen Namen angeben (höchstens 120 Zeichen).';
    }
    $email = trim((string)($e['email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
        $f[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    }
    $pw = (string)($e['passwort'] ?? '');
    if (mb_strlen($pw) < PASSWORT_MIN_LAENGE) {
        $f[] = 'Das Passwort muss mindestens ' . PASSWORT_MIN_LAENGE . ' Zeichen haben.';
    } elseif (!hash_equals($pw, (string)($e['passwort2'] ?? ''))) {
        $f[] = 'Die beiden Passwörter stimmen nicht überein.';
    }
    foreach (install_weitere_hosts((string)($e['weitere_hosts'] ?? '')) as $h) {
        if (host_normalisieren($h) === null) {
            $f[] = 'Ungültiger Hostname: ' . $h;
        }
    }
    return $f;
}

/** Text mit je einem Host pro Zeile (oder durch Komma/Leerzeichen getrennt) zerlegen. */
function install_weitere_hosts(string $text): array
{
    $teile = preg_split('/[\s,]+/', trim($text)) ?: [];
    return array_values(array_filter($teile, fn($t) => $t !== ''));
}

/**
 * Installation ausführen. $hauptHost ist der angefragte Host.
 * Wirft RuntimeException, wenn bereits ein Team-Konto existiert.
 */
function install_ausfuehren(string $hauptHost, array $e): void
{
    // Sperre gegen zwei gleichzeitige Installationsversuche.
    if (db_zahl("SELECT GET_LOCK('ws_install', 10)") !== 1) {
        throw new RuntimeException('Installationssperre nicht erhalten.');
    }
    try {
        install_ausfuehren_gesperrt($hauptHost, $e);
    } finally {
        db_wert("SELECT RELEASE_LOCK('ws_install')");
    }
}

function install_ausfuehren_gesperrt(string $hauptHost, array $e): void
{
    if (installiert()) {
        throw new RuntimeException('Die Installation ist schon erfolgt.');
    }
    migration_anwenden();
    if (db_gibt_es('SELECT COUNT(*) FROM konto')) {
        throw new RuntimeException('Es gibt bereits ein Konto; die Installation ist schon erfolgt.');
    }
    db_transaktion(function () use ($hauptHost, $e): void {
        host_anlegen($hauptHost, true);
        foreach (install_weitere_hosts((string)($e['weitere_hosts'] ?? '')) as $h) {
            host_anlegen($h, false);
        }
        $jetzt = db_jetzt();
        $kontoId = konto_anlegen((string)$e['name'], (string)$e['email'], 'betreiber', (string)$e['passwort']);
        einstellung_setzen('installiert_am', $jetzt);
        audit_schreiben('installation', 'Installation abgeschlossen, Haupt-Host ' . $hauptHost, $kontoId);
    });
    if (@file_put_contents(install_sperrdatei(), gmdate('c') . "\n") === false) {
        throw new RuntimeException('install.lock konnte nicht geschrieben werden (Schreibrechte im Laufzeitordner prüfen).');
    }
}

/** Sperrdatei neu anlegen, wenn die App schon installiert ist und sie fehlt. */
function install_sperre_wiederherstellen(): void
{
    @file_put_contents(install_sperrdatei(), gmdate('c') . " wiederhergestellt\n");
}
