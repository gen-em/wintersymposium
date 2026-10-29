<?php
declare(strict_types=1);

/*
 * Sitzungen (angepasst aus NAdoku sitzung_lib.php und session_lib.php).
 *
 * Zwei Arten: 'team' (Team-Bereich, ab AP-5 mit Login) und 'link'
 * (Teilnehmende nach Einlösen eines Einmal-Links, ab AP-4).
 *
 * Lima-City teilt session.save_path mit anderen Kunden und räumt nach 24 min
 * auf (R-08). Deshalb eigene Ablage im Laufzeitordner, kein Aufräumen durch
 * PHP (gc_probability = 0); das Aufräumen übernimmt der Job 'aufraeumen'.
 * Ablauf nach 30 min ohne Anfrage wird in der Sitzung selbst geprüft.
 */

const SITZUNG_ARTEN = [
    'team' => ['name' => 'ws_team', 'samesite' => 'Lax'],
    'link' => ['name' => 'ws_link', 'samesite' => 'Lax'],
];

/** Ablauf ohne Aktivität in Sekunden. Technische Sicherheitsgrenze, keine Veranstaltungseinstellung. */
const SITZUNG_LEERLAUF_S = 1800;

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
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => kopf_https(),
        'httponly' => true,
        'samesite' => $a['samesite'],
    ]);
    session_start();

    $jetzt = time();
    $zuletzt = (int)($_SESSION['_zuletzt'] ?? 0);
    if ($zuletzt > 0 && $jetzt - $zuletzt > SITZUNG_LEERLAUF_S) {
        // Abgelaufen: alles verwerfen, neue leere Sitzung.
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_abgelaufen'] = true;
    }
    $_SESSION['_art'] = $art;
    $_SESSION['_zuletzt'] = $jetzt;
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
    $war = !empty($_SESSION['_abgelaufen']);
    unset($_SESSION['_abgelaufen']);
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
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
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
    $soll = (string)($_SESSION['csrf'] ?? '');
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
    $_SESSION['flash'] = ['ton' => $ton, 'text' => $text];
}

function flash_holen(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($f) && isset($f['ton'], $f['text']) ? $f : null;
}
