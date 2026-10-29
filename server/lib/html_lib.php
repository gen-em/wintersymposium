<?php
declare(strict_types=1);

/*
 * HTML-Hilfen und Seitenrahmen im Erscheinungsbild Allgäu Akut
 * (Variante A „Plakat“, freigegebene Mockups in docs/mockups/).
 * Keine externen Ressourcen: Schriften, Logo und CSS kommen von der
 * eigenen Domain (E-06).
 */

const QUELLTEXT_URL = 'https://github.com/gen-em/wintersymposium';

function e(mixed $wert): string
{
    return htmlspecialchars((string)$wert, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Seitenkopf mit Logo und Navigation.
 * $opt: 'akzent' => 'lila'|'rot', 'aktiv' => Pfad des aktiven Menüpunkts,
 *       'nav' => false für Seiten ohne Navigation (Installer, Fehler).
 */
function seite_kopf(string $titel, array $opt = []): void
{
    kopfzeilen_seite();
    $akzent = ($opt['akzent'] ?? 'lila') === 'rot' ? 'rot' : 'lila';
    $nav = $opt['nav'] ?? true;
    $aktiv = (string)($opt['aktiv'] ?? '');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        // Bis zum Anmeldestart nicht in Suchmaschinen (Konzept AP-2, Checkliste Abschnitt 9)
        . '<meta name="robots" content="noindex">'
        . '<title>' . e($titel) . ' – Wintersymposium</title>'
        . '<link rel="stylesheet" href="/assets/site.css">'
        // .ico zuerst mit fester Größe, SVG danach: so nehmen Chrome, Edge und Firefox die SVG,
        // die sich hellen und dunklen Oberflächen anpasst; Safari bleibt bei der .ico (Kachel).
        . '<link rel="icon" href="/favicon.ico" sizes="32x32">'
        . '<link rel="icon" href="/favicon.svg" type="image/svg+xml">'
        . '<link rel="apple-touch-icon" href="/apple-touch-icon.png">'
        . '<meta name="theme-color" content="#001E3A">'
        . '</head><body class="akzent-' . $akzent . '">'
        . '<a class="sprung" href="#inhalt">Zum Inhalt</a>'
        . '<header class="kopf"><div class="innen">'
        . '<a href="/" aria-label="Zur Startseite">'
        . '<img class="logo logo-lila" src="/assets/logo/ALLGAEU-AKUT_Logo_quer_negativ-lila.svg" alt="ALLGÄU AKUT – Fortbildung Notfallmedizin" width="158" height="46">'
        . '<img class="logo logo-rot" src="/assets/logo/ALLGAEU-AKUT_Logo_quer_negativ-rot.svg" alt="ALLGÄU AKUT – Fortbildung Notfallmedizin" width="158" height="46">'
        . '</a>';
    if ($nav) {
        // Weitere Menüpunkte (Workshops, Anmeldung, Deine Anmeldung) kommen mit AP-3/AP-4.
        $punkte = [['/#programm', 'Programm', '/']];
        $liste = '';
        foreach ($punkte as [$href, $text, $pfad]) {
            $liste .= '<li><a href="' . e($href) . '"' . ($aktiv === $pfad ? ' aria-current="page"' : '') . '>' . e($text) . '</a></li>';
        }
        // Zwei Darstellungen derselben Liste: aufklappbar (Handy) und daneben (Desktop), ohne JavaScript.
        echo '<nav aria-label="Hauptnavigation"><ul class="nav-liste nav-gross">' . $liste . '</ul>'
            . '<details class="menue"><summary>Menü</summary><ul class="nav-liste">' . $liste . '</ul></details></nav>';
    }
    echo '</div></header><main id="inhalt">';
}

function seite_fuss(): void
{
    echo '</main><footer class="fuss"><div class="innen">'
        . '<span>ALLGÄU AKUT – Fortbildung Notfallmedizin</span>'
        . '<nav aria-label="Rechtliches"><ul>'
        . '<li><a href="/impressum">Impressum</a></li>'
        . '<li><a href="/datenschutz">Datenschutz</a></li>'
        . '<li><a href="' . e(QUELLTEXT_URL) . '" rel="noopener">Quelltext (AGPL)</a></li>'
        . '</ul></nav></div></footer></body></html>';
}

/** Eine einfache Meldungsseite mit Statuscode. */
function seite_meldung(int $status, string $titel, string $text): never
{
    http_response_code($status);
    seite_kopf($titel, ['nav' => false]);
    echo '<div class="innen seitenkopf"><h1 class="titel">' . e($titel) . '</h1></div>'
        . '<div class="innen"><p class="text">' . e($text) . '</p></div>';
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
