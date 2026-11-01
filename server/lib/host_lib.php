<?php
declare(strict_types=1);

/*
 * Mehrere Hostnamen (E-27). Erlaubte Hosts stehen in der Tabelle host; die
 * Basis-URL für Links und Mails wird je Anfrage aus dem angefragten,
 * erlaubten Host gebildet. Ein fremder Host (z. B. gefälschte Host-Kopfzeile)
 * wird abgelehnt, damit nie Links mit fremder Adresse entstehen.
 */

/**
 * Hostname normalisieren: Kleinbuchstaben, ohne Port, ohne Punkt am Ende.
 * Gibt null zurück, wenn der Name keine gültige Form hat.
 */
function host_normalisieren(string $roh): ?string
{
    $h = strtolower(trim($roh));
    if ($h === '') {
        return null;
    }
    // Port abtrennen (IPv6-Literale werden nicht unterstützt).
    if (preg_match('/^([^:\[\]]+)(:\d{1,5})?$/', $h, $m) !== 1) {
        return null;
    }
    $h = rtrim($m[1], '.');
    if (strlen($h) > 253 || $h === '') {
        return null;
    }
    foreach (explode('.', $h) as $label) {
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
            return null;
        }
    }
    return $h;
}

/** Der angefragte Host (normalisiert) oder null. */
function host_angefragt(): ?string
{
    return host_normalisieren((string)($_SERVER['HTTP_HOST'] ?? ''));
}

/** Alle aktiven Hosts aus der Datenbank (je Anfrage einmal gelesen). */
function host_liste(bool $neu = false): array
{
    static $liste = null;
    if ($liste === null || $neu) {
        $liste = db_zeilen('SELECT name, haupt FROM host WHERE aktiv = 1 ORDER BY haupt DESC, name');
    }
    return $liste;
}

function host_erlaubt(string $host): bool
{
    foreach (host_liste() as $z) {
        if ($z['name'] === $host) {
            return true;
        }
    }
    return false;
}

/** Der Haupt-Host (für Links aus Jobs ohne Anfrage, z. B. Mails aus dem Cron). */
function host_haupt(): ?string
{
    foreach (host_liste() as $z) {
        if ((int)$z['haupt'] === 1) {
            return (string)$z['name'];
        }
    }
    return null;
}

/**
 * Basis-URL der laufenden Anfrage, ohne Schrägstrich am Ende. Auf der
 * Kommandozeile der Haupt-Host. Wird erst nach host_tor() gesetzt.
 */
function basis_url(): string
{
    $host = ctx('host') ?? null;
    if (!is_string($host) || $host === '') {
        $host = host_haupt() ?? 'localhost';
    }
    $schema = (entwicklung() && !kopf_https()) ? 'http' : 'https';
    return $schema . '://' . $host;
}

/** Absolute URL zu einem Pfad. */
function url(string $pfad = '/'): string
{
    return basis_url() . '/' . ltrim($pfad, '/');
}

/**
 * Prüft den angefragten Host und merkt ihn für basis_url(). Unbekannte
 * Hosts bekommen 421 ohne weitere Auskunft.
 */
function host_tor(): string
{
    $host = host_angefragt();
    if ($host === null || !host_erlaubt($host)) {
        http_response_code(421);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo "Unbekannter Hostname.\n";
        exit;
    }
    ctx_setzen('host', $host);
    return $host;
}

/** Haupt-Host wechseln oder einen weiteren Host abschalten (Betrieb, AP-5). */
function host_aendern(string $name, string $was, ?int $kontoId): bool
{
    $h = db_zeile('SELECT * FROM host WHERE name = ? AND aktiv = 1', [$name]);
    if ($h === null) {
        return false;
    }
    if ($was === 'haupt') {
        db_transaktion(function () use ($name): void {
            db_ausfuehren('UPDATE host SET haupt = 0');
            db_ausfuehren('UPDATE host SET haupt = 1 WHERE name = ?', [$name]);
        });
    } elseif ($was === 'aus' && (int)$h['haupt'] === 0) {
        db_ausfuehren('UPDATE host SET aktiv = 0 WHERE name = ?', [$name]);
    } else {
        return false;
    }
    host_liste(true);
    audit_schreiben('host', 'Host ' . $name . ($was === 'haupt' ? ' ist Haupt-Host' : ' abgeschaltet'), $kontoId);
    return true;
}

/** Hosts hinzufügen (Installer und Betrieb, AP-5). */
/** Hostnamen anlegen; mit $durch (Team-Bereich) protokolliert. */
function host_anlegen(string $name, bool $haupt = false, ?int $durch = null): void
{
    $n = host_normalisieren($name);
    if ($n === null) {
        throw new InvalidArgumentException('Ungültiger Hostname: ' . $name);
    }
    db_transaktion(function () use ($n, $haupt): void {
        if ($haupt) {
            db_ausfuehren('UPDATE host SET haupt = 0');
        }
        db_ausfuehren(
            'INSERT INTO host (name, haupt, aktiv, erstellt_am) VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE aktiv = 1, haupt = GREATEST(haupt, VALUES(haupt))',
            [$n, $haupt ? 1 : 0, db_jetzt()]
        );
    });
    if ($durch !== null) {
        audit_schreiben('host', 'Host ' . $n . ' hinzugefügt', $durch);
    }
    host_liste(true);
}
