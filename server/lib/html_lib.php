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
        // dialog.js zuerst: macht aus jeder Nachfrage den einheitlichen Dialog (Rückmeldung 2026-10-30)
        . implode('', array_map(fn($js) => '<script src="/assets/' . e($js) . '" defer></script>', array_merge(['dialog.js'], $opt['skripte'] ?? [])))
        . '</head><body class="akzent-' . $akzent . '">'
        . '<a class="sprung" href="#inhalt">Zum Inhalt</a>'
        . '<header class="kopf"><div class="innen">'
        . '<a href="/" aria-label="Zur Startseite">'
        . '<img class="logo logo-lila" src="/assets/logo/ALLGAEU-AKUT_Logo_quer_negativ-lila.svg" alt="ALLGÄU AKUT – Fortbildung Notfallmedizin" width="158" height="46">'
        . '<img class="logo logo-rot" src="/assets/logo/ALLGAEU-AKUT_Logo_quer_negativ-rot.svg" alt="ALLGÄU AKUT – Fortbildung Notfallmedizin" width="158" height="46">'
        . '</a>';
    if ($nav) {
        // Ein Konto für alles (E-54): Team-Login, Konto und Einmal-Link in einer Sitzung
        $konto = $opt['konto'] ?? null;
        $team = is_array($konto) ? $konto : (!isset($opt['teilnehmer']) ? team_gesehen() : null);
        $tn = $opt['teilnehmer'] ?? anm_aus_keks();
        $tk = session_status() === PHP_SESSION_ACTIVE ? tk_aktuell() : null;
        $ich = $tk ?? $team; // eingeloggtes Konto
        $erkannt = is_array($tn) || is_array($ich);
        $a = fn(string $href, string $text, string $pfad, string $klasse = '') => '<li><a' . ($klasse !== '' ? ' class="' . $klasse . '"' : '') . ' href="' . e($href) . '"'
            . ($aktiv === $pfad ? ' aria-current="page"' : '') . '>' . e($text) . '</a></li>';
        $liste = $a('/#programm', 'Programm', '/');
        // Rückmeldung 2026-10-30: erkannt „Meine Anmeldung“, sonst der grüne Knopf „Anmelden“ zur Einstiegsseite (E-42)
        $liste .= $erkannt ? $a('/deine-anmeldung', 'Meine Anmeldung', '/deine-anmeldung') : $a('/deine-anmeldung', 'Anmelden', in_array($aktiv, ['/anmelden', '/deine-anmeldung'], true) ? $aktiv : '-', 'nav-anmelden');
        if (is_array($team)) {
            $liste .= $a('/team', 'Team-Bereich', '/team');
        }
        // Personen-Menü (Mockup Variante A): ausgeloggt „Einloggen“, sonst Name mit Menü
        if (!$erkannt) {
            $person = '<a class="person-knopf" href="/einloggen"' . ($aktiv === '/einloggen' ? ' aria-current="page"' : '') . '>'
                . '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4" fill="none" stroke="currentColor" stroke-width="2"></circle><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>Einloggen</a>';
            $flach = $a('/einloggen', 'Einloggen', '/einloggen');
        } else {
            $name = is_array($ich) ? (string)$ich['name'] : trim($tn['vorname'] . ' ' . $tn['nachname']);
            $teile = preg_split('/\s+/u', trim($name)) ?: [''];
            $kuerzel = mb_strtoupper(mb_substr($teile[0], 0, 1) . (count($teile) > 1 ? mb_substr((string)end($teile), 0, 1) : ''));
            $unter = is_array($ich) ? (konto_ist_team($ich) ? (ROLLEN[$ich['rolle']] ?? '') . ' · ' : '') . $ich['email'] : 'über den Link aus der Mail';
            $eintraege = [['/deine-anmeldung', 'Meine Anmeldung', 'Workshops, Essen, absagen']];
            if (is_array($ich)) {
                $eintraege[] = ['/konto', 'Mein Konto', konto_ist_team($ich) ? 'Profil, Passwort, Zwei-Faktor, Newsletter' : 'Profil, Passwort, Newsletter'];
            }
            if (is_array($team)) {
                $eintraege[] = ['/team', 'Team-Bereich', 'Personen, Programm, Mailings …'];
            }
            $ab = '<form method="post" action="/ausloggen">' . csrf_feld() . '<button type="submit" class="person-ab">Ausloggen</button></form>';
            $person = '<details class="person-menue"><summary aria-label="Konto-Menü: ' . e($name) . '"><span class="person-kuerzel" aria-hidden="true">' . e($kuerzel) . '</span><span class="person-vorname">' . e($teile[0]) . '</span></summary>'
                . '<div class="person-liste"><p class="person-kopf"><strong>' . e($name) . '</strong><span>' . e($unter) . '</span></p>'
                . implode('', array_map(fn($x) => '<a href="' . $x[0] . '"' . ($aktiv === $x[0] ? ' aria-current="page"' : '') . '>' . e($x[1]) . '<small>' . e($x[2]) . '</small></a>', $eintraege))
                . $ab . '</div></details>';
            $flach = '<li class="person-flach"><p class="person-kopf"><strong>' . e($name) . '</strong><span>' . e($unter) . '</span></p></li>'
                . (is_array($ich) ? $a('/konto', 'Mein Konto', '/konto') : '') . '<li>' . $ab . '</li>';
        }
        // Zwei Darstellungen: aufklappbar (Handy) und daneben (Desktop), ohne JavaScript.
        echo '<nav aria-label="Hauptnavigation"><ul class="nav-liste nav-gross">' . $liste . '<li class="person">' . $person . '</li></ul>'
            . '<details class="menue"><summary>Menü</summary><ul class="nav-liste">' . $liste . $flach . '</ul></details></nav>';
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
        . '<li><a href="/newsletter">Newsletter</a></li>'
        . '<li><a href="/impressum">Impressum</a></li>'
        . '<li><a href="/datenschutz">Datenschutz</a></li>'
        . '<li><a href="' . e(QUELLTEXT_URL) . '" rel="noopener">Quelltext (AGPL)</a></li>'
        . '</ul></nav></div></footer></body></html>';
}

/** Reiter des Team-Bereichs (Mockup E-41); weitere folgen mit AP-3 Schritt 3 und AP-5. */
/**
 * Bereiche des Team-Bereichs (Admin 2026-10-30, Mockup Variante A): sechs
 * Bereiche, darunter die Unterseiten des aktiven Bereichs. Dritter Wert true =
 * nur für Betreiber (E-49).
 */
const TEAM_BEREICHE = [
    'Übersicht' => [['/team', 'Übersicht']],
    'Anmeldungen' => [['/team/personen', 'Personen'], ['/team/workshops', 'Workshops']],
    'Programm' => [['/team/programm', 'Programm'], ['/team/dozierende', 'Dozierende']],
    'Kommunikation' => [['/team/mailings', 'Mailings'], ['/team/newsletter', 'Newsletter'], ['/team/texte', 'Texte']],
    'Statistik' => [['/team/statistik', 'Statistik']],
    'Verwaltung' => [['/team/einstellungen', 'Einstellungen'], ['/team/konten', 'Team-Konten', true], ['/team/protokoll', 'Protokoll'], ['/team/daten', 'Betrieb', true]],
];

function team_reiter(array $konto, string $aktiv): string
{
    $betreiber = $konto['rolle'] === 'betreiber';
    $haupt = '';
    $unter = '';
    foreach (TEAM_BEREICHE as $bereich => $seiten) {
        $seiten = array_values(array_filter($seiten, fn($x) => empty($x[2]) || $betreiber));
        $an = in_array($aktiv, array_column($seiten, 0), true);
        $haupt .= '<a href="' . e($seiten[0][0]) . '"' . ($an ? ' class="an" aria-current="' . ($aktiv === $seiten[0][0] && count($seiten) === 1 ? 'page' : 'true') . '"' : '') . '>' . e($bereich) . '</a>';
        if ($an && count($seiten) > 1) {
            $unter = '<nav class="team-unter" aria-label="Unterseiten von ' . e($bereich) . '"><span class="team-unter-kopf" aria-hidden="true">' . e($bereich) . ' ›</span>';
            foreach ($seiten as [$pfad, $text]) {
                $unter .= '<a href="' . e($pfad) . '"' . ($pfad === $aktiv ? ' aria-current="page"' : '') . '>' . e($text) . '</a>';
            }
            $unter .= '</nav>';
        }
    }
    return '<div class="team-navi"><nav class="team-nav" aria-label="Team-Bereich">' . $haupt . '</nav>' . $unter . '</div>';
}

/** Kopf einer Team-Seite: Rahmen, Titel, Reiter, Meldung aus der Umleitung. */
function team_seite_kopf(array $konto, string $titel, string $aktiv, array $opt = []): void
{
    // Ein Editor für alle Textfelder (E-54): auf jeder Team-Seite geladen, wirkt nur auf textarea[data-editor]
    $opt['skripte'] = array_values(array_unique(array_merge(['editor.js'], $opt['skripte'] ?? [])));
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
