<?php
declare(strict_types=1);

/*
 * Sicherheits-Kopfzeilen und CSP mit Nonce (angepasst aus NAdoku
 * kopfzeilen_lib.php, ohne Geocoder und Karten). Keine externen Quellen
 * (E-06): Schriften, Bilder, Skripte kommen nur von der eigenen Domain.
 */

function kopf_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/** Attribut für eingebettete Skripte: <script<?= kopf_nonce_attr() ?>> */
function kopf_nonce_attr(): string
{
    return ' nonce="' . htmlspecialchars(kopf_nonce(), ENT_QUOTES, 'UTF-8') . '"';
}

function kopf_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((string)($_SERVER['REQUEST_SCHEME'] ?? '') === 'https') {
        return true;
    }
    if (konfig('https_ueber_proxy', false) === true) {
        $p = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($p !== '') {
            $teile = array_map('trim', explode(',', $p));
            return end($teile) === 'https';
        }
    }
    return false;
}

/** Richtlinie der Seiten; $einbettbar: nur in Seiten derselben Herkunft im iframe (Vorschau der Startseite, E-70 (6)). */
function kopf_csp(bool $einbettbar = false): string
{
    return implode('; ', [
        "default-src 'none'",
        "script-src 'self' 'nonce-" . kopf_nonce() . "'",
        "style-src 'self'",
        "img-src 'self' data:",
        "font-src 'self'",
        "connect-src 'self'",
        "worker-src 'self'",
        // Vorschau der Mailings im iframe (eigene, engere Richtlinie in team_mailings_vorschau)
        "frame-src 'self'",
        "manifest-src 'self'",
        "form-action 'self'",
        $einbettbar ? "frame-ancestors 'self'" : "frame-ancestors 'none'",
        "base-uri 'none'",
        "object-src 'none'",
    ]);
}

/** Kopfzeilen, die für jede Antwort gelten. */
function kopf_gemeinsam(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header_remove('X-Powered-By');
    if (kopf_https()) {
        // Ein Jahr, ohne includeSubDomains: die Subdomain test. und spätere
        // Subdomains sollen nicht ungewollt mitgebunden werden.
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/** HTML-Seite: alle Kopfzeilen inkl. CSP, nicht zwischenspeichern. $einbettbar: siehe kopf_csp(). */
function kopfzeilen_seite(bool $einbettbar = false): void
{
    if (headers_sent()) {
        return;
    }
    kopf_gemeinsam();
    if ($einbettbar) {
        header('X-Frame-Options: SAMEORIGIN');
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Security-Policy: ' . kopf_csp($einbettbar));
    header('Cache-Control: no-store');
}

function kopfzeilen_json(): void
{
    if (headers_sent()) {
        return;
    }
    kopf_gemeinsam();
    header('Content-Type: application/json; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('Cache-Control: no-store');
}

/**
 * HTTPS erzwingen (zusätzlich zur .htaccess). GET/HEAD werden umgeleitet,
 * alles andere abgelehnt, damit keine Formulardaten unverschlüsselt ankommen.
 * In der Umgebung 'entwicklung' abgeschaltet.
 */
function https_tor(string $host): void
{
    if (kopf_https() || entwicklung() || PHP_SAPI === 'cli') {
        return;
    }
    $methode = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($methode === 'GET' || $methode === 'HEAD') {
        header('Location: https://' . $host . (string)($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Bitte HTTPS verwenden.\n";
    exit;
}
