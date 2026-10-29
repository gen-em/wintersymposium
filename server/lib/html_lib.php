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
 *       'nav' => false für Seiten ohne Navigation (Installer, Fehler),
 *       'konto' => angemeldetes Konto (zeigt Name und „Ausloggen“, E-40/E-42),
 *       'skripte' => Liste eigener Skripte unter /assets/.
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
        . implode('', array_map(fn($js) => '<script src="/assets/' . e($js) . '" defer></script>', $opt['skripte'] ?? []))
        . '</head><body class="akzent-' . $akzent . '">'
        . '<a class="sprung" href="#inhalt">Zum Inhalt</a>'
        . '<header class="kopf"><div class="innen">'
        . '<a href="/" aria-label="Zur Startseite">'
        . '<img class="logo logo-lila" src="/assets/logo/ALLGAEU-AKUT_Logo_quer_negativ-lila.svg" alt="ALLGÄU AKUT – Fortbildung Notfallmedizin" width="158" height="46">'
        . '<img class="logo logo-rot" src="/assets/logo/ALLGAEU-AKUT_Logo_quer_negativ-rot.svg" alt="ALLGÄU AKUT – Fortbildung Notfallmedizin" width="158" height="46">'
        . '</a>';
    if ($nav) {
        $punkte = [['/#programm', 'Programm', '/'], ['/deine-anmeldung', 'Deine Anmeldung', '/deine-anmeldung']];
        $konto = $opt['konto'] ?? null;
        if (is_array($konto)) {
            $punkte[] = ['/team', 'Team-Bereich', '/team'];
        }
        // Teilnehmende nach dem Einmal-Link (E-40); nur wenn schon eine Sitzung besteht
        $tn = !is_array($konto) ? ($opt['teilnehmer'] ?? anm_aus_keks()) : null;
        $tk = !is_array($konto) && session_status() === PHP_SESSION_ACTIVE ? tk_aktuell() : null;
        if (is_array($tk)) {
            $punkte[] = ['/konto', 'Mein Konto', '/konto'];
        }
        $liste = '';
        foreach ($punkte as [$href, $text, $pfad]) {
            $liste .= '<li><a href="' . e($href) . '"' . ($aktiv === $pfad ? ' aria-current="page"' : '') . '>' . e($text) . '</a></li>';
        }
        // Grüner Knopf „Anmelden“, solange die Anmeldung offen ist (E-42); nicht für Angemeldete
        if (!is_array($tn) && !is_array($konto) && !is_array($tk) && anm_zeitraum() === 'offen') {
            $liste .= '<li><a class="nav-anmelden" href="/anmelden"' . ($aktiv === '/anmelden' ? ' aria-current="page"' : '') . '>Anmelden</a></li>';
        }
        if (is_array($konto)) {
            $liste .= '<li class="nav-person"><span class="nav-name">' . e($konto['name']) . ' <small>' . e(ROLLEN[$konto['rolle']] ?? '') . '</small></span>'
                . '<form method="post" action="/ausloggen">' . csrf_feld() . '<button type="submit" class="nav-ab">Ausloggen</button></form></li>';
        } elseif (is_array($tn) || is_array($tk)) {
            $liste .= '<li class="nav-person"><span class="nav-name">' . e(is_array($tn) ? $tn['vorname'] . ' ' . $tn['nachname'] : $tk['name']) . '</span>'
                . '<form method="post" action="/deine-anmeldung">' . csrf_feld() . '<input type="hidden" name="aktion" value="ausloggen"><button type="submit" class="nav-ab">Ausloggen</button></form></li>';
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
        // Version außerhalb der Produktion sichtbar (Admin 2026-10-29), damit klar ist, welcher Stand läuft
        . (umgebung() !== 'produktion' ? '<span class="version mono">Version ' . e(app_version()) . ' · ' . e(ucfirst(umgebung())) . '</span>' : '')
        . '<nav aria-label="Rechtliches"><ul>'
        . '<li><a href="/impressum">Impressum</a></li>'
        . '<li><a href="/datenschutz">Datenschutz</a></li>'
        . '<li><a href="' . e(QUELLTEXT_URL) . '" rel="noopener">Quelltext (AGPL)</a></li>'
        . '<li><a href="/einloggen">Team</a></li>'
        . '</ul></nav></div></footer></body></html>';
}

/** Reiter des Team-Bereichs (Mockup E-41); weitere folgen mit AP-3 Schritt 3 und AP-5. */
function team_reiter(array $konto, string $aktiv): string
{
    $reiter = [
        ['/team', 'Übersicht'],
        ['/team/anmeldungen', 'Anmeldungen'],
        ['/team/programm', 'Programm'],
        ['/team/einstellungen', 'Einstellungen'],
        ['/team/profil', 'Mein Konto'],
    ];
    if ($konto['rolle'] === 'betreiber') {
        $reiter[] = ['/team/konten', 'Team-Konten'];
        $reiter[] = ['/team/daten', 'Daten'];
    }
    $h = '<nav class="team-nav" aria-label="Team-Bereich">';
    foreach ($reiter as [$pfad, $text]) {
        $h .= '<a href="' . e($pfad) . '"' . ($pfad === $aktiv ? ' aria-current="page"' : '') . '>' . e($text) . '</a>';
    }
    return $h . '</nav>';
}

/** Kopf einer Team-Seite: Rahmen, Titel, Reiter, Meldung aus der Umleitung. */
function team_seite_kopf(array $konto, string $titel, string $aktiv, array $opt = []): void
{
    seite_kopf($titel, $opt + ['konto' => $konto, 'aktiv' => '/team']);
    echo '<div class="innen team-kopf"><div><p class="kicker">Team-Bereich</p><h1 class="titel">' . e($titel) . '</h1></div></div>'
        . '<div class="innen">' . team_reiter($konto, $aktiv) . meldung_html(flash_holen());
}

function team_seite_fuss(): void
{
    echo '</div>';
    seite_fuss();
}

/** Meldung (ok, fehler, hinweis) als HTML; null → ''. */
function meldung_html(?array $m): string
{
    if ($m === null) {
        return '';
    }
    $ton = in_array($m['ton'] ?? '', ['ok', 'fehler', 'hinweis'], true) ? $m['ton'] : 'hinweis';
    return '<div class="meldung ' . $ton . '" role="' . ($ton === 'fehler' ? 'alert' : 'status') . '">' . e($m['text']) . '</div>';
}

/** Fehlerliste als HTML. */
function fehler_html(array $fehler): string
{
    if ($fehler === []) {
        return '';
    }
    return '<div class="meldung fehler" role="alert"><ul>' . implode('', array_map(fn($f) => '<li>' . e($f) . '</li>', $fehler)) . '</ul></div>';
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
