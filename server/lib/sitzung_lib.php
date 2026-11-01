<?php
declare(strict_types=1);

/*
 * Sitzungen (angepasst aus NAdoku sitzung_lib.php und session_lib.php).
 *
 * Eine Sitzung für alles (Admin 2026-10-30, E-54: „ein Konto für alles“): Die
 * Arten 'team' und 'link' teilen sich denselben Keks. Team-Login (mit zweitem
 * Faktor), Konto für Teilnehmende und Einmal-Link stehen nebeneinander in
 * derselben Sitzung; Ausloggen beendet alles.
 *
 * Der Hoster teilt session.save_path mit anderen Kunden und räumt nach 24 min
 * auf (R-08). Deshalb eigene Ablage im Laufzeitordner, kein Aufräumen durch
 * PHP (gc_probability = 0); das Aufräumen übernimmt der Job 'aufraeumen'.
 * Ablauf nach 30 min ohne Anfrage wird in der Sitzung selbst geprüft.
 */

const SITZUNG_ARTEN = [
    'team' => ['name' => 'ws_sitzung', 'samesite' => 'Lax'],
    'link' => ['name' => 'ws_sitzung', 'samesite' => 'Lax'],
];

/** Ablauf ohne Aktivität in Sekunden. Technische Sicherheitsgrenze, keine Veranstaltungseinstellung. */
const SITZUNG_LEERLAUF_S = 1800;

/* Schlüssel der Sitzung (Konzept 5a K-e): nur über diese Konstanten */
const SITZUNG_SCHLUESSEL_ANM = 'anm'; // Anmeldung der Teilnehmenden (Einmal-Link oder Konto)
const SITZUNG_SCHLUESSEL_ANM_VORLAGE = 'anm_vorlage'; // „Daten übernehmen“ für Wiederkehrende (E-58)
const SITZUNG_SCHLUESSEL_HALB = 'halb'; // halbe Team-Anmeldung (Passwort richtig, Faktor fehlt)
const SITZUNG_SCHLUESSEL_KONTO = 'konto'; // volle Team-Anmeldung
const SITZUNG_SCHLUESSEL_ZF_MAIL = 'zf_mail'; // Mail-Code beim Einloggen (Zeit)
const SITZUNG_SCHLUESSEL_TK_PW = 'tk_pw'; // nach Link: neues Passwort festlegen
const SITZUNG_SCHLUESSEL_TEAM_VA = 'team_va'; // gewählte Veranstaltung im Team-Bereich
const SITZUNG_SCHLUESSEL_ZF_BESTAETIGT = 'zf_bestaetigt'; // heikle Aktion mit zweitem Faktor bestätigt (Zeit)
const SITZUNG_SCHLUESSEL_TK = 'tk'; // Konto der Teilnehmenden
const SITZUNG_SCHLUESSEL_POW = 'pow'; // Rechenaufgabe des Spamschutzes
const SITZUNG_SCHLUESSEL_PK_CODES = 'pk_codes'; // Wiederherstellungscodes nach Passkey-Einrichtung
const SITZUNG_SCHLUESSEL_PK = 'pk'; // laufende Passkey-Abfragen (je Zweck bis zu PK_OFFEN_MAX)
const SITZUNG_SCHLUESSEL_PK_MERKER_WEG = 'pk_merker_weg'; // letzter Passkey entfernt: Merker im Browser löschen (E-75)
const SITZUNG_SCHLUESSEL_CSRF = 'csrf'; // CSRF-Token
const SITZUNG_SCHLUESSEL_APP_EINRICHTEN = 'app_einrichten'; // Einrichtung der Authenticator-App
const SITZUNG_SCHLUESSEL_NEUER_LINK = 'neuer_link'; // gerade erzeugter Link zum Weitergeben (Team-Konten)
const SITZUNG_SCHLUESSEL_FLASH = 'flash'; // Meldung über die Umleitung
const SITZUNG_SCHLUESSEL_ABGELAUFEN = '_abgelaufen'; // Sitzung war abgelaufen
const SITZUNG_SCHLUESSEL_ZF_MAIL_HALB = 'zf_mail_halb'; // Mail-Code bei halber Anmeldung
const SITZUNG_SCHLUESSEL_ZULETZT = '_zuletzt'; // letzte Aktivität
const SITZUNG_SCHLUESSEL_ART = '_art'; // Art der Sitzung

function sitzung_ablage_pfad(): string
{
    return laufzeit_pfad('sitzungen');
}

function sitzung_starten(string $art): void
{
    if (!isset(SITZUNG_ARTEN[$art])) {
        throw new InvalidArgumentException('Unbekannte Sitzungsart: ' . $art);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $a = SITZUNG_ARTEN[$art];
    ini_set('session.save_path', sitzung_ablage_pfad());
    ini_set('session.gc_probability', '0');
    ini_set('session.gc_maxlifetime', (string)(SITZUNG_LEERLAUF_S + 600));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    session_name($a['name']);
    // Nach team_gesehen() steht noch die ID der Team-Sitzung: durch die eigene ersetzen
    if (session_id() !== '') {
        $c = $_COOKIE[$a['name']] ?? null;
        session_id(is_string($c) && preg_match('/^[A-Za-z0-9,-]{22,256}$/', $c) === 1 ? $c : session_create_id());
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => kopf_https(),
        'httponly' => true,
        'samesite' => $a['samesite'],
    ]);
    session_start();

    $jetzt = time();
    $zuletzt = (int)($_SESSION[SITZUNG_SCHLUESSEL_ZULETZT] ?? 0);
    if ($zuletzt > 0 && $jetzt - $zuletzt > SITZUNG_LEERLAUF_S) {
        // Abgelaufen: alles verwerfen, neue leere Sitzung.
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION[SITZUNG_SCHLUESSEL_ABGELAUFEN] = true;
    }
    $_SESSION[SITZUNG_SCHLUESSEL_ART] = $art;
    $_SESSION[SITZUNG_SCHLUESSEL_ZULETZT] = $jetzt;
}

/**
 * Zur anderen Sitzungsart wechseln (gemeinsamer Login, E-48): z. B. nach dem
 * Passwort auf /einloggen für ein Teilnehmenden-Konto von 'team' zu 'link'.
 */
function sitzung_wechseln(string $art): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Gleicher Keks (E-54): es ist dieselbe Sitzung, nichts zu wechseln
        if (($_SESSION[SITZUNG_SCHLUESSEL_ART] ?? '') === $art || session_name() === SITZUNG_ARTEN[$art]['name']) {
            return;
        }
        session_write_close();
        $_SESSION = [];
        // Sonst nähme session_start() die ID der alten Sitzung mit
        $name = SITZUNG_ARTEN[$art]['name'];
        $id = is_string($_COOKIE[$name] ?? null) && preg_match('/^[A-Za-z0-9,-]{22,256}$/', $_COOKIE[$name]) === 1 ? $_COOKIE[$name] : session_create_id();
        session_id($id);
    }
    sitzung_starten($art);
}

/** Neue Sitzungs-ID nach Login oder Rechtewechsel. */
function sitzung_erneuern(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/** War die vorige Sitzung wegen Leerlauf abgelaufen? (einmalig abfragbar) */
function sitzung_war_abgelaufen(): bool
{
    $war = !empty($_SESSION[SITZUNG_SCHLUESSEL_ABGELAUFEN]);
    unset($_SESSION[SITZUNG_SCHLUESSEL_ABGELAUFEN]);
    return $war;
}

function sitzung_beenden(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $p['path'],
        'secure' => $p['secure'],
        'httponly' => true,
        'samesite' => $p['samesite'],
    ]);
    session_destroy();
}

/** Sitzungsdateien löschen, die länger als $aelterAls Sekunden unberührt sind. */
function sitzung_aufraeumen(int $aelterAls = SITZUNG_LEERLAUF_S + 600): int
{
    $grenze = time() - $aelterAls;
    $n = 0;
    foreach (glob(sitzung_ablage_pfad() . '/sess_*') ?: [] as $datei) {
        $m = @filemtime($datei);
        if ($m !== false && $m < $grenze && @unlink($datei)) {
            $n++;
        }
    }
    return $n;
}

/* ---- CSRF (aus NAdoku session_lib.php) ---------------------------------- */

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new LogicException('CSRF-Token ohne Sitzung.');
    }
    if (empty($_SESSION[SITZUNG_SCHLUESSEL_CSRF])) {
        $_SESSION[SITZUNG_SCHLUESSEL_CSRF] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION[SITZUNG_SCHLUESSEL_CSRF];
}

function csrf_feld(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $mit = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!is_string($mit) || $mit === '') {
        return false;
    }
    $soll = (string)($_SESSION[SITZUNG_SCHLUESSEL_CSRF] ?? '');
    return $soll !== '' && hash_equals($soll, $mit);
}

/** Für jeden POST: ohne gültiges Token 403 und Ende. */
function csrf_pruefen(): void
{
    if (!csrf_ok()) {
        seite_meldung(403, 'Sitzung abgelaufen', 'Das Formular war zu alt oder ungültig. Bitte die Seite neu laden und noch einmal absenden.');
    }
}

/* ---- Meldungen über eine Umleitung hinweg (Post/Redirect/Get) ---------- */

const FLASH_TOENE = ['ok', 'fehler', 'hinweis'];

function flash_setzen(string $ton, string $text): void
{
    if (!in_array($ton, FLASH_TOENE, true)) {
        throw new InvalidArgumentException('Unbekannter Meldungston: ' . $ton);
    }
    $_SESSION[SITZUNG_SCHLUESSEL_FLASH] = ['ton' => $ton, 'text' => $text];
}

function flash_holen(): ?array
{
    $f = $_SESSION[SITZUNG_SCHLUESSEL_FLASH] ?? null;
    unset($_SESSION[SITZUNG_SCHLUESSEL_FLASH]);
    return is_array($f) && isset($f['ton'], $f['text']) ? $f : null;
}
