<?php
declare(strict_types=1);

/*
 * HTML-Hilfen und ein schlichter Seitenrahmen. Das Design kommt mit AP-2
 * (Mockups, Allgäu-Akut-Tokens); bis dahin nur ein neutraler Rahmen.
 */

function e(mixed $wert): string
{
    return htmlspecialchars((string)$wert, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function seite_kopf(string $titel): void
{
    kopfzeilen_seite();
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . e($titel) . ' – Wintersymposium</title>'
        . '<link rel="stylesheet" href="/assets/basis.css">'
        . '</head><body><main class="rahmen">';
}

function seite_fuss(): void
{
    echo '</main></body></html>';
}

/** Eine einfache Meldungsseite mit Statuscode. */
function seite_meldung(int $status, string $titel, string $text): never
{
    http_response_code($status);
    seite_kopf($titel);
    echo '<h1>' . e($titel) . '</h1><p>' . e($text) . '</p>';
    seite_fuss();
    exit;
}

/** Schlichte 404 ohne Auskunft. */
function nicht_gefunden(): never
{
    seite_meldung(404, 'Nicht gefunden', 'Diese Seite gibt es nicht.');
}

/** JSON ausgeben und beenden. */
function json_antwort(int $status, array $daten): never
{
    http_response_code($status);
    kopfzeilen_json();
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/** Umleitung nach einem POST (Post/Redirect/Get). */
function umleiten(string $pfad): never
{
    header('Location: ' . $pfad, true, 303);
    exit;
}
