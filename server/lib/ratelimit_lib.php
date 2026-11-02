<?php
declare(strict_types=1);

/*
 * Ratenschutz, nur der Kern aus NAdoku ratelimit_lib.php (ohne Sperrleiter
 * und Mail-Alarm, E-18).
 *
 * Ein "Topf" ist ein Zweck (z. B. 'health'), ein "Merkmal" ein Hash der
 * Herkunft (IP) oder einer Kennung (E-Mail). Gespeichert wird nur der
 * HMAC mit dem Geheimnis aus config.php, nie die IP selbst (R-02).
 *
 * Ablauf: Vor der Prüfung rate_erlaubt(); bei Fehlschlag rate_misserfolg();
 * bei Erfolg rate_erfolg(). Nach max Fehlversuchen im Fenster ist das
 * Merkmal für die Sperrdauer gesperrt.
 *
 * Die Grenzen sind technische Sicherheitswerte, keine Veranstaltungs-
 * einstellungen (R-11 betrifft sie nicht).
 */

const RATE_TOEPFE = [
    // topf          => [max Fehlversuche, Fenster s, Sperre s]
    'installation' => [5, 900, 900],
    'health'       => [10, 600, 600],
    'jobs'         => [10, 600, 600],
    // Team-Login: je Herkunft großzügig, je Konto streng
    'login_ip'     => [30, 900, 900],
    'login'        => [5, 900, 900],
    // Login von einer bekannten IP des Kontos (O-47): eigener Zähler je Konto und IP
    'login_bekannt' => [5, 900, 900],
    'zweitfaktor'  => [5, 900, 900],
    // Mail-Codes je Konto (Review S-9): jeder Versand zählt
    'mailcode_minute' => [1, 60, 60],
    'mailcode'     => [10, 3600, 3600],
    'einrichten'   => [10, 900, 900],
    'notzugang'    => [3, 3600, 3600],
    // Anmeldung und Link per Mail (AP-4): jede Absendung zählt
    'anmelden'     => [60, 3600, 3600],
    'link_ip'      => [10, 3600, 3600],
    'link_ziel'    => [3, 3600, 3600],
];

function rate_grenze(string $topf): array
{
    if (!isset(RATE_TOEPFE[$topf])) {
        throw new InvalidArgumentException('Unbekannter Ratenschutz-Topf: ' . $topf);
    }
    [$max, $fenster, $sperre] = RATE_TOEPFE[$topf];
    return ['max' => $max, 'fenster' => $fenster, 'sperre' => $sperre];
}

/** „Bitte warte 15 Minuten und versuche es dann noch einmal.“ – Wartezeit aus der Sperre des Topfs. */
function rate_warten_text(string $topf): string
{
    return 'Bitte warte ' . dauer_text(rate_grenze($topf)['sperre']) . ' und versuche es dann noch einmal.';
}

/** Sperr-Meldung für die Oberfläche (V-48): „Zu viele Versuche. Bitte warte …“; $was z. B. „falsche Codes“. */
function rate_text(string $topf, string $was = 'Versuche'): string
{
    return 'Zu viele ' . $was . '. ' . rate_warten_text($topf);
}

/** IP der Anfrage (REMOTE_ADDR; Proxy-Kopfzeilen werden bewusst nicht gelesen). */
function rate_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

function rate_merkmal(string $wert): string
{
    return hash_hmac('sha256', $wert, (string)konfig('geheimnis', ''));
}

function rate_merkmal_ip(): string
{
    return rate_merkmal('ip:' . rate_ip());
}

function rate_merkmal_kennung(string $kennung): string
{
    return rate_merkmal('kennung:' . mb_strtolower(trim($kennung)));
}

/** Ist das Merkmal gerade nicht gesperrt? */
function rate_erlaubt(string $topf, string $merkmal, ?int $jetzt = null): bool
{
    rate_grenze($topf);
    $jetzt ??= time();
    $bis = db_wert('SELECT gesperrt_bis FROM rate_limit WHERE topf = ? AND merkmal = ?', [$topf, $merkmal]);
    return $bis === null || db_ts($bis) <= $jetzt;
}

/** Fehlversuch zählen; sperrt bei Erreichen der Grenze. Gibt true zurück, wenn jetzt gesperrt. */
function rate_misserfolg(string $topf, string $merkmal, ?int $jetzt = null): bool
{
    $g = rate_grenze($topf);
    $jetzt ??= time();
    $jetztDb = db_zeit($jetzt);
    $fensterAb = db_zeit($jetzt - $g['fenster']);
    return db_transaktion(function () use ($topf, $merkmal, $g, $jetzt, $jetztDb, $fensterAb): bool {
        $z = db_zeile(
            'SELECT fenster_start, fehlversuche FROM rate_limit WHERE topf = ? AND merkmal = ? FOR UPDATE',
            [$topf, $merkmal]
        );
        if ($z === null || $z['fenster_start'] < $fensterAb) {
            $n = 1;
            db_ausfuehren(
                'REPLACE INTO rate_limit (topf, merkmal, fenster_start, fehlversuche, gesperrt_bis) VALUES (?, ?, ?, 1, NULL)',
                [$topf, $merkmal, $jetztDb]
            );
        } else {
            $n = (int)$z['fehlversuche'] + 1;
            db_ausfuehren(
                'UPDATE rate_limit SET fehlversuche = ? WHERE topf = ? AND merkmal = ?',
                [$n, $topf, $merkmal]
            );
        }
        if ($n >= $g['max']) {
            db_ausfuehren(
                'UPDATE rate_limit SET gesperrt_bis = ? WHERE topf = ? AND merkmal = ?',
                [db_zeit($jetzt + $g['sperre']), $topf, $merkmal]
            );
            return true;
        }
        return false;
    });
}

/** Erfolg: Zähler für dieses Merkmal zurücksetzen. */
function rate_erfolg(string $topf, string $merkmal): void
{
    db_ausfuehren('DELETE FROM rate_limit WHERE topf = ? AND merkmal = ?', [$topf, $merkmal]);
}

/**
 * Antwortzeit angleichen: wartet, bis seit $beginn (microtime(true)) mindestens
 * $mindestSekunden vergangen sind. Verhindert, dass die Dauer verrät, ob eine
 * Adresse bekannt ist (R-03).
 */
function rate_gleiche_dauer(float $beginn, float $mindestSekunden = 0.35): void
{
    $rest = $mindestSekunden - (microtime(true) - $beginn);
    if ($rest > 0) {
        usleep((int)round($rest * 1_000_000));
    }
}

/* ---- Bekannte IPs je Konto (O-47) ---------------------------------------- */

/**
 * Wie lange eine IP nach dem letzten erfolgreichen Login als bekannt gilt.
 * Technischer Sicherheitswert (R-11 betrifft ihn nicht).
 */
const RATE_BEKANNT_TAGE = 30;

/** HMAC aus Konto und IP; die IP selbst wird nie gespeichert (R-02). */
function rate_merkmal_bekannt(int $kontoId): string
{
    return rate_merkmal('bekannt:' . $kontoId . ':' . rate_ip());
}

/** Nach einem erfolgreichen Login: diese IP gilt für das Konto 30 Tage als bekannt. */
function rate_bekannt_merken(int $kontoId, ?int $jetzt = null): void
{
    $jetzt ??= time();
    db_ausfuehren(
        'INSERT INTO login_bekannt (konto_id, merkmal, zuletzt) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE zuletzt = VALUES(zuletzt)',
        [$kontoId, rate_merkmal_bekannt($kontoId), db_zeit($jetzt)]
    );
}

/** Hat sich das Konto von dieser IP in den letzten 30 Tagen erfolgreich eingeloggt? */
function rate_bekannt(int $kontoId, ?int $jetzt = null): bool
{
    $jetzt ??= time();
    return db_gibt_es(
        'SELECT COUNT(*) FROM login_bekannt WHERE konto_id = ? AND merkmal = ? AND zuletzt >= ?',
        [$kontoId, rate_merkmal_bekannt($kontoId), db_zeit($jetzt - RATE_BEKANNT_TAGE * 86400)]
    );
}

/** Abgelaufene Einträge entfernen (Job 'aufraeumen'). */
function rate_aufraeumen(?int $jetzt = null): int
{
    $jetzt ??= time();
    $laengstesFenster = max(array_map(fn(array $t) => max($t[1], $t[2]), RATE_TOEPFE));
    $grenze = db_zeit($jetzt - $laengstesFenster);
    $jetztDb = db_zeit($jetzt);
    $n = db_ausfuehren(
        'DELETE FROM rate_limit WHERE fenster_start < ? AND (gesperrt_bis IS NULL OR gesperrt_bis < ?)',
        [$grenze, $jetztDb]
    )->rowCount();
    // Bekannte IPs nach 30 Tagen ohne Login vergessen (O-47)
    return $n + db_ausfuehren('DELETE FROM login_bekannt WHERE zuletzt < ?', [db_zeit($jetzt - RATE_BEKANNT_TAGE * 86400)])->rowCount();
}
