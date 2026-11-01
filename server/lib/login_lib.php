<?php
declare(strict_types=1);

/*
 * Team-Login: Passwort, dann Pflicht-Zweitfaktor (E-11). Muster aus NAdoku
 * login.php (halbe Anmeldung mit Frist, Wiederholungsschutz), stark
 * verkleinert.
 *
 * Ablauf in der Sitzung 'team':
 *   1. Passwort richtig  → $_SESSION[SITZUNG_SCHLUESSEL_HALB] = [id, zeit, version]
 *   2. Code richtig      → $_SESSION[SITZUNG_SCHLUESSEL_KONTO] = [id, version], halb entfällt
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
    // je Anfrage gemerkt (Kontext, ctx_leeren() vergisst es)
    if (ctx_da('anmeldung_konto')) {
        return ctx('anmeldung_konto')[0];
    }
    $k = null;
    $s = $_SESSION[SITZUNG_SCHLUESSEL_KONTO] ?? null;
    if (is_array($s) && isset($s['id'], $s['version'])) {
        $k = konto_laden((int)$s['id']);
        if ($k === null || (int)$k['aktiv'] !== 1 || !konto_ist_team($k) || (int)$k['sitzung_version'] !== (int)$s['version'] || !zweitfaktor_da($k)) {
            unset($_SESSION[SITZUNG_SCHLUESSEL_KONTO]);
            $k = null;
        }
    }
    ctx_setzen('anmeldung_konto', [$k]);
    return $k;
}

/**
 * Wer bin ich in dieser Anfrage (V-6): ['team' => Team-Konto (auch halb
 * eingeloggt erkannt) oder null, 'konto' => eingeloggtes Konto (alle Rollen)
 * oder null, 'anmeldung' => Anmeldung aus Sitzung oder Keks oder null].
 * Je Anfrage gemerkt; Ein- und Ausloggen vergessen es.
 */
function ich(): array
{
    if (!ctx_da('ich')) {
        ctx_setzen('ich', [
            'team' => team_gesehen(),
            'konto' => session_status() === PHP_SESSION_ACTIVE ? tk_aktuell() : null,
            'anmeldung' => anm_aus_keks(),
        ]);
    }
    return ctx('ich');
}

/**
 * Team-Login auf öffentlichen Seiten erkennen (Rückmeldung 2026-10-30).
 * Bis 0.19 wurde die Sitzung dafür nur gelesen (read_and_close, mit eigenen
 * Einstellungen); auf Staging erkannte die Startseite so kein eingeloggtes
 * Konto (Rückmeldung 2026-10-31). Jetzt wird die Sitzung wie auf allen
 * anderen Seiten geöffnet – derselbe Keks für alles (E-54); das hält auch die
 * Sitzung beim Lesen öffentlicher Seiten wach.
 */
function team_gesehen(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $c = $_COOKIE[SITZUNG_ARTEN['team']['name']] ?? null;
        if (!is_string($c) || preg_match('/^[A-Za-z0-9,-]{22,256}$/', $c) !== 1) {
            return null;
        }
        team_sitzung();
    }
    return anmeldung_konto(); // eine Sitzung für alles (E-54)
}

function anmeldung_halb_setzen(array $k): void
{
    sitzung_erneuern();
    unset($_SESSION[SITZUNG_SCHLUESSEL_KONTO]);
    $_SESSION[SITZUNG_SCHLUESSEL_HALB] = ['id' => (int)$k['id'], 'zeit' => time(), 'version' => (int)$k['sitzung_version']];
}

/** Konto der halben Anmeldung (Passwort bestätigt, innerhalb der Frist) oder null. */
function anmeldung_halb(): ?array
{
    $h = $_SESSION[SITZUNG_SCHLUESSEL_HALB] ?? null;
    if (!is_array($h) || !isset($h['id'], $h['zeit'], $h['version'])) {
        return null;
    }
    if (time() - (int)$h['zeit'] > ANMELDUNG_HALB_FRIST_S) {
        unset($_SESSION[SITZUNG_SCHLUESSEL_HALB]);
        return null;
    }
    $k = konto_laden((int)$h['id']);
    if ($k === null || (int)$k['aktiv'] !== 1 || !konto_ist_team($k) || (int)$k['sitzung_version'] !== (int)$h['version']) {
        unset($_SESSION[SITZUNG_SCHLUESSEL_HALB]);
        return null;
    }
    return $k;
}

function anmeldung_halb_verwerfen(): void
{
    unset($_SESSION[SITZUNG_SCHLUESSEL_HALB]);
}

function anmeldung_voll(array $k): void
{
    ctx_weg('ich', 'anmeldung_konto'); // Wer bin ich neu bestimmen
    sitzung_erneuern();
    unset($_SESSION[SITZUNG_SCHLUESSEL_HALB]);
    $_SESSION[SITZUNG_SCHLUESSEL_KONTO] = ['id' => (int)$k['id'], 'version' => (int)$k['sitzung_version']];
    audit_schreiben('login', audit_text('konto', (int)$k['id'], 'Team-Login'), (int)$k['id'], ['konto' => (int)$k['id']]);
    // Ein Konto für alles (E-54): dasselbe Konto gilt auch als Teilnehmenden-Konto (Meine Anmeldung, Mein Konto)
    tk_einloggen($k);
}

function anmeldung_beenden(): void
{
    ctx_weg('ich', 'anmeldung_konto'); // Wer bin ich neu bestimmen
    $k = anmeldung_konto();
    if ($k !== null) {
        audit_schreiben('login', audit_text('konto', (int)$k['id'], 'ausgeloggt'), (int)$k['id'], ['konto' => (int)$k['id']]);
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
    ctx_setzen('team_konto', (int)$k['id']); // für das Audit-Protokoll bei Team-Änderungen
    ctx_setzen('team_rolle', $k['rolle']); // Newsletter-Mailings nur für Betreiber (AP-12)
    team_veranstaltung_waehlen($k);
    team_ids_pruefen($k);
    return $k;
}

/**
 * Nummern aus der Anfrage gehören zur gewählten Veranstaltung (AP-12, E-60):
 * Anmeldung (a), Platz (platz) und – bei Admins mit Auswahl – das Konto (k)
 * einer Person, die in keiner ihrer Veranstaltungen angemeldet ist, ergeben 404.
 */
function team_ids_pruefen(array $konto): void
{
    if (!va_tabellen_da()) {
        return;
    }
    $a = (int)($_GET['a'] ?? $_POST['a'] ?? 0);
    if ($a > 0 && db_zahl('SELECT veranstaltung_id FROM anmeldung WHERE id = ?', [$a]) !== va_id()) {
        nicht_gefunden();
    }
    $p = (int)($_POST['platz'] ?? 0);
    if ($p > 0 && db_zahl('SELECT veranstaltung_id FROM platz WHERE id = ?', [$p]) !== va_id()) {
        nicht_gefunden();
    }
    $kid = (int)($_GET['k'] ?? $_POST['k'] ?? 0);
    if ($kid > 0 && va_erlaubte_ids($konto) !== null) {
        $ziel = konto_laden($kid);
        [$wo, $par] = va_sql_erlaubt($konto, 'veranstaltung_id');
        if ($ziel === null || ($ziel['rolle'] === 'teilnehmend' && !db_gibt_es('SELECT COUNT(*) FROM anmeldung WHERE konto_id = ? AND ' . $wo, array_merge([$kid], $par)))) {
            nicht_gefunden();
        }
    }
}

/**
 * Veranstaltung des Team-Bereichs (E-58): ?va=ID wählt um und merkt sich die
 * Wahl in der Sitzung; ohne Wahl die aktuelle. Setzt den Kontext für die Seite.
 * Admins nur unter ihren freigeschalteten Veranstaltungen (AP-12, E-60): eine
 * fremde wird abgewiesen; ohne Freischaltung ein Hinweis statt der Seite.
 */
function team_veranstaltung_waehlen(array $konto): void
{
    if (!va_tabellen_da()) {
        return;
    }
    if (isset($_GET['va']) && va_zeile((int)$_GET['va']) !== null) {
        if (!va_darf($konto, (int)$_GET['va'])) {
            seite_meldung(403, 'Kein Zugriff', 'Für diese Veranstaltung bist du nicht freigeschaltet.');
        }
        $_SESSION[SITZUNG_SCHLUESSEL_TEAM_VA] = (int)$_GET['va'];
    }
    $id = (int)($_SESSION[SITZUNG_SCHLUESSEL_TEAM_VA] ?? 0);
    if ($id === 0 || va_zeile($id) === null || !va_darf($konto, $id)) {
        unset($_SESSION[SITZUNG_SCHLUESSEL_TEAM_VA]);
        $aktuell = va_aktuell_id();
        if ($aktuell !== null && va_darf($konto, $aktuell)) {
            $id = $aktuell;
        } else {
            $erlaubt = va_alle_fuer($konto);
            if ($erlaubt === []) {
                seite_meldung(403, 'Noch keine Veranstaltung', 'Dir ist noch keine Veranstaltung zugeordnet. Eine BetreiberIn schaltet dich unter Team-Konten frei.');
            }
            $id = $erlaubt[0]['id'];
        }
    }
    va_kontext($id);
}

/**
 * Schreibschutz für abgeschlossene Veranstaltungen (E-58): POST auf Seiten der
 * Veranstaltung wird abgewiesen; Lesen, Exporte und Downloads bleiben.
 */
function team_schreibschutz(string $ziel): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && va_abgeschlossen()) {
        flash_setzen('fehler', 'Diese Veranstaltung ist abgeschlossen und nur noch zum Ansehen da.');
        umleiten($ziel);
    }
}

/**
 * Passwort im eingeloggten Zustand bestätigen (Passwort ändern, Konto löschen,
 * zweiten Faktor ändern, Passkey anlegen), mit Ratenschutz Topf login je Konto
 * wie beim Einloggen (Review S-4, V-4). Gibt null oder den Fehlertext zurück.
 */
function passwort_bestaetigen(array $k, string $pw, string $falsch = 'Das Passwort stimmt nicht.'): ?string
{
    $kennung = rate_merkmal('konto:' . (int)$k['id']);
    if (!rate_erlaubt('login', $kennung)) {
        return rate_text('login');
    }
    if (!anmeldung_passwort_ok($k, $pw)) {
        rate_misserfolg('login', $kennung);
        return $falsch;
    }
    return null;
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
