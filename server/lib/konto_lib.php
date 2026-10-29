<?php
declare(strict_types=1);

/*
 * Konten (eine Tabelle mit Rollen, E-42/O-28). In AP-3 nur Team-Konten
 * (Admin, Betreiber); Konten für Teilnehmende folgen mit AP-4b.
 *
 * Neue Team-Mitglieder legt ein Betreiber an. Weil es noch keinen
 * Mailversand gibt, bekommt der Betreiber einen Einrichtungslink angezeigt
 * und gibt ihn selbst weiter. Der Link ist ein Token, gespeichert wird nur
 * sein SHA-256 (R-02).
 */

const ROLLEN = ['teilnehmend' => 'Teilnehmend', 'admin' => 'Admin', 'betreiber' => 'Betreiber'];
const TEAM_ROLLEN = ['admin', 'betreiber'];
/** Gültigkeit eines Einrichtungs- oder Passwortlinks (technische Grenze, keine Veranstaltungseinstellung). */
const KONTO_LINK_GUELTIG_S = 72 * 3600;

function konto_email_normieren(string $email): string
{
    return mb_strtolower(trim($email));
}

function konto_laden(int $id): ?array
{
    return db_zeile('SELECT * FROM konto WHERE id = ?', [$id]);
}

function konto_per_email(string $email): ?array
{
    return db_zeile('SELECT * FROM konto WHERE email = ?', [konto_email_normieren($email)]);
}

function konto_ist_team(?array $k): bool
{
    return $k !== null && in_array($k['rolle'], TEAM_ROLLEN, true);
}

/** Fehlertext oder null. */
function passwort_regel_fehler(string $pw, string $pw2, int $min = PASSWORT_MIN_LAENGE): ?string
{
    if (mb_strlen($pw) < $min) {
        return 'Das Passwort muss mindestens ' . $min . ' Zeichen haben.';
    }
    if (mb_strlen($pw) > 200) {
        return 'Das Passwort ist zu lang (höchstens 200 Zeichen).';
    }
    if (!hash_equals($pw, $pw2)) {
        return 'Die beiden Passwörter stimmen nicht überein.';
    }
    return null;
}

/** Eingaben für ein neues Konto prüfen. Liste von Fehlertexten. */
function konto_eingabe_fehler(string $name, string $email, string $rolle): array
{
    $f = [];
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) {
        $f[] = 'Bitte einen Namen angeben (höchstens 120 Zeichen).';
    }
    $email = konto_email_normieren($email);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
        $f[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    } elseif (konto_per_email($email) !== null) {
        $f[] = 'Für diese E-Mail-Adresse gibt es schon ein Konto.';
    }
    if (!isset(ROLLEN[$rolle])) {
        $f[] = 'Unbekannte Rolle.';
    }
    return $f;
}

/** Konto anlegen; Passwort optional (ohne Passwort: Einrichtung über Link). Gibt die id zurück. */
function konto_anlegen(string $name, string $email, string $rolle, ?string $passwort = null, ?int $durch = null): int
{
    $jetzt = db_jetzt();
    db_ausfuehren(
        'INSERT INTO konto (email, email_bestaetigt_am, name, passwort_hash, rolle, aktiv, erstellt_am, geaendert_am) VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
        // Team-Konten legt ein Betreiber an: Adresse gilt als bestätigt; Teilnehmende bestätigen per Mail (AP-4b)
        [konto_email_normieren($email), $rolle === 'teilnehmend' ? null : $jetzt, trim($name), $passwort === null ? null : password_hash($passwort, PASSWORD_DEFAULT), $rolle, $jetzt, $jetzt]
    );
    $id = (int)db()->lastInsertId();
    audit_schreiben('konto', 'Konto ' . $id . ' angelegt (Rolle ' . $rolle . ')', $durch);
    return $id;
}

/** Passwort setzen; beendet andere Sitzungen des Kontos. */
function konto_passwort_setzen(int $id, string $passwort): void
{
    db_ausfuehren(
        'UPDATE konto SET passwort_hash = ?, sitzung_version = sitzung_version + 1, geaendert_am = ? WHERE id = ?',
        [password_hash($passwort, PASSWORD_DEFAULT), db_jetzt(), $id]
    );
    audit_schreiben('konto', 'Passwort gesetzt', $id);
}

function konto_aktiv_setzen(int $id, bool $aktiv, ?int $durch): void
{
    db_ausfuehren(
        'UPDATE konto SET aktiv = ?, sitzung_version = sitzung_version + 1, geaendert_am = ? WHERE id = ?',
        [$aktiv ? 1 : 0, db_jetzt(), $id]
    );
    audit_schreiben('konto', 'Konto ' . $id . ($aktiv ? ' entsperrt' : ' gesperrt'), $durch);
}

function konto_rolle_setzen(int $id, string $rolle, ?int $durch): void
{
    if (!isset(ROLLEN[$rolle])) {
        throw new InvalidArgumentException('Unbekannte Rolle.');
    }
    db_ausfuehren(
        'UPDATE konto SET rolle = ?, sitzung_version = sitzung_version + 1, geaendert_am = ? WHERE id = ?',
        [$rolle, db_jetzt(), $id]
    );
    audit_schreiben('konto', 'Rolle von Konto ' . $id . ' auf ' . $rolle . ' gesetzt', $durch);
}

/** Zahl aktiver Betreiber (es muss immer mindestens einer bleiben). */
function konto_aktive_betreiber(): int
{
    return (int)db_wert("SELECT COUNT(*) FROM konto WHERE rolle = 'betreiber' AND aktiv = 1");
}

function konto_team_liste(): array
{
    return db_zeilen(
        "SELECT k.id, k.name, k.email, k.rolle, k.aktiv, k.totp_seit, k.letzte_anmeldung, k.passwort_hash IS NOT NULL AS hat_passwort
           FROM konto k WHERE k.rolle IN ('admin','betreiber') ORDER BY k.rolle DESC, k.name"
    );
}

/* ---- Einrichtungs- und Passwortlinks ------------------------------------ */

/** Neuen Link erzeugen ('einrichten' oder 'passwort'); ältere Links des Kontos verfallen. Gibt das Token zurück. */
function konto_link_erzeugen(int $kontoId, string $zweck, ?int $durch = null, ?int $jetzt = null): string
{
    if (!in_array($zweck, ['einrichten', 'passwort'], true)) {
        throw new InvalidArgumentException('Unbekannter Link-Zweck.');
    }
    $jetzt ??= time();
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    db_transaktion(function () use ($kontoId, $zweck, $token, $jetzt): void {
        db_ausfuehren('DELETE FROM konto_link WHERE konto_id = ?', [$kontoId]);
        db_ausfuehren(
            'INSERT INTO konto_link (konto_id, token_hash, zweck, gueltig_bis, erstellt_am) VALUES (?, ?, ?, ?, ?)',
            [$kontoId, hash('sha256', $token), $zweck, gmdate('Y-m-d H:i:s', $jetzt + KONTO_LINK_GUELTIG_S), gmdate('Y-m-d H:i:s', $jetzt)]
        );
    });
    audit_schreiben('konto', 'Link (' . $zweck . ') für Konto ' . $kontoId . ' erzeugt', $durch);
    return $token;
}

/** Gültigen Link zum Token finden: ['link' => …, 'konto' => …] oder null. */
function konto_link_finden(string $token, ?int $jetzt = null): ?array
{
    if (preg_match('/^[A-Za-z0-9_-]{40,60}$/', $token) !== 1) {
        return null;
    }
    $l = db_zeile('SELECT * FROM konto_link WHERE token_hash = ?', [hash('sha256', $token)]);
    if ($l === null || strtotime($l['gueltig_bis'] . ' UTC') < ($jetzt ?? time())) {
        return null;
    }
    $k = konto_laden((int)$l['konto_id']);
    if ($k === null || (int)$k['aktiv'] !== 1) {
        return null;
    }
    return ['link' => $l, 'konto' => $k];
}

function konto_link_verbrauchen(int $linkId): bool
{
    return db_ausfuehren('DELETE FROM konto_link WHERE id = ?', [$linkId])->rowCount() === 1;
}

function konto_link_url(string $token): string
{
    return url('/einrichten?t=' . rawurlencode($token));
}

/** Abgelaufene Links löschen (Job 'aufraeumen'). */
function konto_link_aufraeumen(?int $jetzt = null): int
{
    return db_ausfuehren('DELETE FROM konto_link WHERE gueltig_bis < ?', [gmdate('Y-m-d H:i:s', $jetzt ?? time())])->rowCount();
}
