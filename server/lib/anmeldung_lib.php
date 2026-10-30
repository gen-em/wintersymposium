<?php
declare(strict_types=1);

/*
 * Team-Login: Passwort, dann Pflicht-Zweitfaktor (E-11). Muster aus NAdoku
 * login.php (halbe Anmeldung mit Frist, Wiederholungsschutz), stark
 * verkleinert.
 *
 * Ablauf in der Sitzung 'team':
 *   1. Passwort richtig  → $_SESSION['halb'] = [id, zeit, version]
 *   2. Code richtig      → $_SESSION['konto'] = [id, version], halb entfällt
 *      (ohne eingerichteten Faktor: erst Einrichtung, dann voll)
 * Jede Anfrage lädt das Konto neu: gesperrt, andere Rolle oder neue
 * sitzung_version (Passwortwechsel, Sperre, Rollenwechsel) beenden die Sitzung.
 *
 * Fehlermeldungen verraten nicht, ob eine Adresse bekannt ist (R-03).
 */

/** Wie lange die halbe Anmeldung (Passwort ja, Code noch nicht) gilt. */
const ANMELDUNG_HALB_FRIST_S = 300;

function team_sitzung(): void
{
    sitzung_starten('team');
}

/** Das angemeldete Team-Konto oder null. */
function anmeldung_konto(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $s = $_SESSION['konto'] ?? null;
    if (!is_array($s) || !isset($s['id'], $s['version'])) {
        return $cache = null;
    }
    $k = konto_laden((int)$s['id']);
    if ($k === null || (int)$k['aktiv'] !== 1 || !konto_ist_team($k) || (int)$k['sitzung_version'] !== (int)$s['version'] || !zweitfaktor_da($k)) {
        unset($_SESSION['konto']);
        return $cache = null;
    }
    return $cache = $k;
}

/**
 * Team-Login auf öffentlichen Seiten erkennen (Rückmeldung 2026-10-30): Diese
 * Seiten nutzen die Sitzung für Teilnehmende; die Team-Sitzung wird vorher nur
 * gelesen (read_and_close), nichts daran ändert sich. Geht nur, solange noch
 * keine Sitzung läuft – sonst zählt der zuerst gelesene Wert.
 */
function team_gesehen(): ?array
{
    static $k = false;
    if ($k !== false) {
        return $k;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        return $k = anmeldung_konto(); // eine Sitzung für alles (E-54)
    }
    $name = SITZUNG_ARTEN['team']['name'];
    $c = $_COOKIE[$name] ?? null;
    if (!is_string($c) || preg_match('/^[A-Za-z0-9,-]{22,256}$/', $c) !== 1) {
        return $k = null;
    }
    ini_set('session.save_path', sitzung_ablage_pfad());
    ini_set('session.use_strict_mode', '1');
    session_name($name);
    session_id($c);
    @session_start(['read_and_close' => true]);
    $s = $_SESSION['konto'] ?? null;
    $frisch = time() - (int)($_SESSION['_zuletzt'] ?? 0) <= SITZUNG_LEERLAUF_S;
    $_SESSION = [];
    if (!$frisch || !is_array($s) || !isset($s['id'], $s['version'])) {
        return $k = null;
    }
    $konto = konto_laden((int)$s['id']);
    $ok = $konto !== null && (int)$konto['aktiv'] === 1 && konto_ist_team($konto) && (int)$konto['sitzung_version'] === (int)$s['version'] && zweitfaktor_da($konto);
    return $k = $ok ? $konto : null;
}

function anmeldung_halb_setzen(array $k): void
{
    sitzung_erneuern();
    unset($_SESSION['konto']);
    $_SESSION['halb'] = ['id' => (int)$k['id'], 'zeit' => time(), 'version' => (int)$k['sitzung_version']];
}

/** Konto der halben Anmeldung (Passwort bestätigt, innerhalb der Frist) oder null. */
function anmeldung_halb(): ?array
{
    $h = $_SESSION['halb'] ?? null;
    if (!is_array($h) || !isset($h['id'], $h['zeit'], $h['version'])) {
        return null;
    }
    if (time() - (int)$h['zeit'] > ANMELDUNG_HALB_FRIST_S) {
        unset($_SESSION['halb']);
        return null;
    }
    $k = konto_laden((int)$h['id']);
    if ($k === null || (int)$k['aktiv'] !== 1 || !konto_ist_team($k) || (int)$k['sitzung_version'] !== (int)$h['version']) {
        unset($_SESSION['halb']);
        return null;
    }
    return $k;
}

function anmeldung_halb_verwerfen(): void
{
    unset($_SESSION['halb']);
}

function anmeldung_voll(array $k): void
{
    sitzung_erneuern();
    unset($_SESSION['halb']);
    $_SESSION['konto'] = ['id' => (int)$k['id'], 'version' => (int)$k['sitzung_version']];
    audit_schreiben('login', 'Team-Anmeldung', (int)$k['id']);
    // Ein Konto für alles (E-54): dasselbe Konto gilt auch als Teilnehmenden-Konto (Meine Anmeldung, Mein Konto)
    tk_einloggen($k);
}

function anmeldung_beenden(): void
{
    $k = anmeldung_konto();
    if ($k !== null) {
        audit_schreiben('login', 'Ausgeloggt', (int)$k['id']);
    }
    sitzung_beenden();
}

/**
 * Tor für Team-Seiten: startet die Sitzung, verlangt ein angemeldetes Konto
 * mit einer der Rollen und gibt es zurück. Sonst Umleitung zum Login bzw. 403.
 */
function team_tor(array $rollen = TEAM_ROLLEN): array
{
    team_sitzung();
    $k = anmeldung_konto();
    if ($k === null) {
        if (sitzung_war_abgelaufen()) {
            flash_setzen('hinweis', 'Du warst eine Weile nicht aktiv und wurdest ausgeloggt. Bitte logge dich neu ein.');
        }
        umleiten('/einloggen');
    }
    if (!in_array($k['rolle'], $rollen, true)) {
        seite_meldung(403, 'Kein Zugriff', 'Diese Seite ist für deine Rolle nicht freigegeben.');
    }
    $GLOBALS['ws_team_konto'] = (int)$k['id']; // für das Audit-Protokoll bei Team-Änderungen
    return $k;
}

/** Passwort prüfen, mit gleicher Laufzeit für unbekannte Adressen. */
function anmeldung_passwort_ok(?array $k, string $passwort): bool
{
    static $attrappe = null;
    $attrappe ??= password_hash('attrappe-' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    $hash = ($k !== null && is_string($k['passwort_hash'] ?? null)) ? $k['passwort_hash'] : $attrappe;
    $ok = password_verify($passwort, $hash);
    return $ok && $k !== null && $hash !== $attrappe;
}
