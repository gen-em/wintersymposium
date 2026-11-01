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
 *       'skripte' => Liste eigener Skripte unter /assets/,
 *       'meldung' => true: Flash-Meldung aus der Umleitung unter dem Kopf ausgeben.
 */
function seite_kopf(string $titel, array $opt = []): void
{
    kopfzeilen_seite();
    $akzent = ($opt['akzent'] ?? 'lila') === 'rot' ? 'rot' : 'lila';
    $nav = $opt['nav'] ?? true;
    $aktiv = (string)($opt['aktiv'] ?? '');
    if ($nav) {
        // Ein Konto für alles (E-54): Team-Login, Konto und Einmal-Link in einer Sitzung.
        // Vor der ersten Ausgabe: Ohne Ausgabepuffer (wie beim Hoster) lässt sich die
        // Sitzung danach nicht mehr öffnen, die Startseite sah dann ausgeloggt aus
        // (Rückmeldung 2026-10-31).
        $konto = $opt['konto'] ?? null;
        // Auch mit übergebener Anmeldung (Meine Anmeldung): Team-Bereich in der Leiste (Rückmeldung 2026-10-31)
        $team = is_array($konto) ? $konto : team_gesehen();
        $tn = $opt['teilnehmer'] ?? anm_aus_keks();
        $tk = session_status() === PHP_SESSION_ACTIVE ? tk_aktuell() : null;
        $ich = $tk ?? $team; // eingeloggtes Konto
        $erkannt = is_array($tn) || is_array($ich);
    }
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        // Bis zum Anmeldestart nicht in Suchmaschinen (Konzept AP-2, Checkliste Abschnitt 9)
        . '<meta name="robots" content="noindex">'
        . '<title>' . e($titel) . ' – ' . e(seiten_name()) . '</title>'
        . '<link rel="stylesheet" href="/assets/site.css">'
        // .ico zuerst mit fester Größe, SVG danach: so nehmen Chrome, Edge und Firefox die SVG,
        // die sich hellen und dunklen Oberflächen anpasst; Safari bleibt bei der .ico (Kachel).
        . '<link rel="icon" href="/favicon.ico" sizes="32x32">'
        . '<link rel="icon" href="/favicon.svg" type="image/svg+xml">'
        . '<link rel="apple-touch-icon" href="/apple-touch-icon.png">'
        . '<meta name="theme-color" content="#001E3A">'
        // dialog.js zuerst: macht aus jeder Nachfrage den einheitlichen Dialog (Rückmeldung 2026-10-30)
        . implode('', array_map(fn($js) => '<script src="/assets/' . e($js) . '" defer></script>', array_merge(['dialog.js', 'kopf.js'], $opt['skripte'] ?? [])))
        . '</head><body class="akzent-' . $akzent . '">'
        . '<a class="sprung" href="#inhalt">Zum Inhalt</a>'
        . '<header class="kopf"><div class="innen">'
        . '<a href="/" aria-label="Zur Startseite">'
        . '<img class="logo logo-lila" src="/assets/logo/ALLGAEU-AKUT_Logo_quer_negativ-lila.svg" alt="ALLGÄU AKUT – Fortbildung Notfallmedizin" width="158" height="46">'
        . '<img class="logo logo-rot" src="/assets/logo/ALLGAEU-AKUT_Logo_quer_negativ-rot.svg" alt="ALLGÄU AKUT – Fortbildung Notfallmedizin" width="158" height="46">'
        . '</a>';
    if ($nav) {
        $a = fn(string $href, string $text, string $pfad, string $klasse = '') => '<li><a' . ($klasse !== '' ? ' class="' . $klasse . '"' : '') . ' href="' . e($href) . '"'
            . ($aktiv === $pfad ? ' aria-current="page"' : '') . '>' . e($text) . '</a></li>';
        $liste = $a('/#programm', 'Programm', '/');
        // Rückmeldung 2026-10-30: erkannt „Meine Anmeldung“, sonst der grüne Knopf „Anmelden“ zur Einstiegsseite (E-42)
        $liste .= $erkannt ? $a('/deine-anmeldung', 'Meine Anmeldung', '/deine-anmeldung') : $a('/deine-anmeldung', 'Anmelden', in_array($aktiv, ['/anmelden', '/deine-anmeldung'], true) ? $aktiv : '-', 'nav-anmelden');
        if (is_array($team)) {
            // Team-Bereich grün abgesetzt: kein Teil der öffentlichen Seite (Rückmeldung 2026-10-31)
            $liste .= $a('/team', 'Team-Bereich', '/team', 'nav-team');
        }
        // Personen-Menü (Mockup Variante A): ausgeloggt „Einloggen“, sonst Name mit Menü
        if (!$erkannt) {
            $person = '<a class="person-knopf" href="/einloggen"' . ($aktiv === '/einloggen' ? ' aria-current="page"' : '') . '>'
                . icon('user-circle', 20) . 'Einloggen</a>';
            $flach = $a('/einloggen', 'Einloggen', '/einloggen');
        } else {
            $name = is_array($ich) ? (string)$ich['name'] : person_name($tn);
            $teile = preg_split('/\s+/u', trim($name)) ?: [''];
            $kuerzel = mb_strtoupper(mb_substr($teile[0], 0, 1) . (count($teile) > 1 ? mb_substr((string)end($teile), 0, 1) : ''));
            $unter = is_array($ich) ? (konto_ist_team($ich) ? (ROLLEN[$ich['rolle']] ?? '') . ' · ' : '') . $ich['email'] : 'über den Link aus der Mail';
            // Personen-Menü (AP-12, E-60, Entwurf A): Kopf zum Lesen, darunter Punkte mit Icons; der Team-Bereich steht in der Leiste
            $eintraege = [['/deine-anmeldung', 'Meine Anmeldung', 'Workshops, Essen, absagen', 'ticket']];
            if (is_array($team)) {
                // Team-Bereich auch im Personen-Menü, grün wie in der Leiste (Rückmeldung 2026-10-31)
                array_unshift($eintraege, ['/team', 'Team-Bereich', 'Anmeldungen, Programm, Mailings', 'shield-check', ' team']);
            }
            if (is_array($ich)) {
                $eintraege[] = ['/konto', 'Mein Konto', konto_ist_team($ich) ? 'Profil, Passwort, Zwei-Faktor, Newsletter' : 'Profil, Passwort, Newsletter', 'user-circle'];
            }
            $ab = '<form method="post" action="/ausloggen">' . csrf_feld() . '<button type="submit" class="person-ab">' . icon('sign-out', 22) . '<span>Ausloggen</span></button></form>';
            $rolle = is_array($ich) && konto_ist_team($ich) ? '<span class="person-rolle">' . e(ROLLEN[$ich['rolle']] ?? '') . '</span>' : '';
            $adresse = is_array($ich) ? (string)$ich['email'] : 'über den Link aus der Mail';
            $person = '<details class="person-menue"><summary aria-label="Konto-Menü: ' . e($name) . '"><span class="person-kuerzel" aria-hidden="true">' . e($kuerzel) . '</span><span>' . e($teile[0]) . '</span>' . icon('caret-down', 14, 'icon person-pfeil') . '</summary>'
                . '<div class="menue-karte person-liste"><div class="person-kopf"><span class="person-kuerzel gross" aria-hidden="true">' . e($kuerzel) . '</span><span class="person-wer"><strong>' . e($name) . '</strong><span>' . e($adresse) . '</span>' . $rolle . '</span></div>'
                . implode('', array_map(fn($x) => '<a class="person-punkt' . ($x[4] ?? '') . '" href="' . $x[0] . '"' . ($aktiv === $x[0] ? ' aria-current="page"' : '') . '>' . icon($x[3], 22) . '<span class="person-text"><span class="person-titel">' . e($x[1]) . '</span><small>' . e($x[2]) . '</small></span>' . icon('caret-right', 16, 'icon person-weiter') . '</a>', $eintraege))
                . $ab . '</div></details>';
            $flach = '<li class="person-flach"><p class="person-flach-kopf"><strong>' . e($name) . '</strong><span>' . e($unter) . '</span></p></li>'
                . (is_array($ich) ? $a('/konto', 'Mein Konto', '/konto') : '') . '<li>' . $ab . '</li>';
        }
        // Zwei Darstellungen: aufklappbar (Handy) und daneben (Desktop), ohne JavaScript.
        echo '<nav aria-label="Hauptnavigation"><ul class="nav-liste nav-gross">' . $liste . '<li class="person">' . $person . '</li></ul>'
            . '<details class="menue"><summary>Menü</summary><ul class="nav-liste">' . $liste . $flach . '</ul></details></nav>';
    }
    echo '</div></header><main id="inhalt">';
    if (!empty($opt['meldung'])) {
        // Flash-Meldung aus der Umleitung gleich unter dem Kopf (V-18)
        echo '<div class="innen">' . meldung_html(flash_holen()) . '</div>';
    }
}

/** Seitenfuß ausgeben und die Seite beenden (statt „seite_fuss(); return;“, V-26). */
function seite_ende(): never
{
    seite_fuss();
    exit;
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

/**
 * Icon aus dem Phosphor-Set (lib/vendor/phosphor, MIT) als Inline-SVG; Farbe
 * über currentColor, nur Schmuck (aria-hidden). Unbekannte Namen: leer.
 */
function icon(string $name, int $groesse = 20, string $klasse = 'icon'): string
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $datei = __DIR__ . '/vendor/phosphor/' . $name . '.svg';
        $svg = preg_match('/^[a-z0-9-]+$/', $name) === 1 && is_file($datei) ? (string)file_get_contents($datei) : '';
        $cache[$name] = preg_match('#<svg[^>]*>(.*)</svg>#s', $svg, $m) === 1 ? $m[1] : '';
    }
    if ($cache[$name] === '') {
        return '';
    }
    return '<svg class="' . e($klasse) . '" width="' . $groesse . '" height="' . $groesse . '" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true" focusable="false">' . $cache[$name] . '</svg>';
}

/**
 * Bereiche des Team-Bereichs (Admin 2026-10-30, Mockup Variante A; neu
 * geordnet mit AP-12, E-60): darunter die Unterseiten des aktiven Bereichs.
 * Dritter Wert true = nur für Betreiber; ein Bereich ohne sichtbare Seite
 * entfällt. „Betrieb“ bündelt alles, was für alle Veranstaltungen gilt.
 */
const TEAM_BEREICHE = [
    'Übersicht' => [['/team', 'Übersicht']],
    'Anmeldungen' => [['/team/personen', 'Personen'], ['/team/workshops', 'Workshops'], ['/team/teilnahme', 'Teilnahme']],
    'Programm' => [['/team/programm', 'Programm'], ['/team/dozierende', 'Dozierende']],
    'Mailings' => [['/team/mailings', 'Mailings']],
    'Statistik' => [['/team/statistik', 'Statistik']],
    'Verwaltung' => [['/team/einstellungen', 'Einstellungen'], ['/team/protokoll', 'Protokoll']],
    'Betrieb' => [['/team/status', 'Status', true], ['/team/veranstaltungen', 'Veranstaltungen', true], ['/team/konten', 'Team-Konten', true], ['/team/newsletter', 'Newsletter', true], ['/team/texte', 'Texte', true], ['/team/daten', 'Betrieb', true], ['/team/komponenten', 'Komponenten', true]],
];

function team_reiter(array $konto, string $aktiv): string
{
    $betreiber = $konto['rolle'] === 'betreiber';
    $haupt = '';
    $unter = '';
    foreach (TEAM_BEREICHE as $bereich => $seiten) {
        $seiten = array_values(array_filter($seiten, fn($x) => empty($x[2]) || $betreiber));
        if ($seiten === []) {
            continue;
        }
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
    echo '<div class="innen team-kopf"><div><p class="kicker">Team-Bereich</p><h1 class="titel">' . e($titel) . '</h1></div>' . team_veranstaltung_wechsler($konto, $aktiv) . '</div>'
        . '<div class="innen">' . team_reiter($konto, $aktiv) . team_veranstaltung_hinweis() . meldung_html(flash_holen());
}

/**
 * Wechsler rechts neben dem Seitentitel (AP-12, E-60, ersetzt die Leiste aus
 * E-58): gewählte Veranstaltung mit Status, aufgeklappt die erlaubten mit
 * Häkchen; Betreiber dazu „Neue Veranstaltung“ und „Alle verwalten“. Ohne
 * JavaScript (details/summary), die Einträge sind Links mit ?va=.
 */
function team_veranstaltung_wechsler(array $konto, string $aktiv): string
{
    if (!va_tabellen_da() || ($v = va_zeile()) === null) {
        return '';
    }
    $chip = fn(string $st) => '<span class="chip ' . (['aktuell' => 'ok', 'entwurf' => 'offen', 'abgeschlossen' => 'gesperrt'][$st] ?? 'offen') . '">' . e(VA_STATUS[$st]) . '</span>';
    $liste = '';
    $gewaehltName = '';
    foreach (va_alle_fuer($konto) as $x) {
        $gewaehlt = $x['id'] === (int)$v['id'];
        if ($gewaehlt) {
            $gewaehltName = $x['name'];
        }
        $unter = implode(' · ', array_filter([VA_ARTEN[va_von($x['id'], 'veranstaltung_art')] ?? '', $x['datum'] !== '' ? tag_text($x['datum']) : '']));
        $liste .= '<a class="va-eintrag" href="' . e($aktiv) . '?va=' . $x['id'] . '"' . ($gewaehlt ? ' aria-current="true"' : '') . '>'
            . '<span class="va-haken">' . ($gewaehlt ? icon('check', 16) : '') . '</span>'
            . '<span class="va-text"><span class="va-name">' . e($x['name']) . '</span><small>' . e($unter) . '</small></span>' . $chip($x['status']) . '</a>';
    }
    $aktionen = $konto['rolle'] === 'betreiber' ? '<div class="va-aktionen"><a href="/team/veranstaltungen#neu">' . icon('plus', 18) . '<span>Neue Veranstaltung …</span></a>'
        . '<a class="va-verwalten" href="/team/veranstaltungen">' . icon('list-bullets', 18) . '<span>Alle Veranstaltungen verwalten</span></a></div>' : '';
    return '<details class="va-wechsler"><summary aria-label="Veranstaltung wechseln, gewählt: ' . e($gewaehltName) . '">'
        . '<span class="va-wahl"><span class="va-label">Veranstaltung</span><span class="va-name">' . e($gewaehltName) . '</span></span>' . $chip($v['status']) . icon('caret-down', 16, 'icon va-pfeil') . '</summary>'
        . '<div class="menue-karte va-menue"><p class="va-label">Wechseln zu</p>' . $liste . $aktionen . '</div></details>';
}

/** Hinweis unter den Reitern, wenn die gewählte Veranstaltung ein Entwurf oder abgeschlossen ist (E-58). */
function team_veranstaltung_hinweis(): string
{
    if (!va_tabellen_da() || ($v = va_zeile()) === null) {
        return '';
    }
    if ($v['status'] === 'abgeschlossen') {
        return '<p class="meldung hinweis va-hinweis" role="status">Abgeschlossen am ' . e(datum_zeit_text($v['abgeschlossen_am'])) . ' – alles nur noch zum Ansehen; Exporte und Zertifikate gehen weiter.</p>';
    }
    if ($v['status'] === 'entwurf') {
        return '<p class="meldung hinweis va-hinweis" role="status">Entwurf – noch nicht öffentlich. Unter Betrieb → Veranstaltungen wird sie zur aktuellen.</p>';
    }
    return '';
}

function team_seite_fuss(): void
{
    echo '</div>';
    seite_fuss();
}

/** Standardtext über einem Formular mit Fehlern (V-18). */
const NICHT_GESPEICHERT_TEXT = 'Nicht gespeichert. Bitte prüf die markierten Angaben.';

/**
 * Meldung als HTML (V-18): ein Text mit Ton (ok, fehler, hinweis), eine Flash-
 * Meldung ['ton' => …, 'text' => …] oder eine Liste von Texten (als Aufzählung).
 * null, '' und [] → ''. $opt: klasse (weitere Klassen), text_html (Inhalt roh).
 */
function meldung_html(string|array|null $m, string $ton = 'hinweis', array $opt = []): string
{
    if ($m === null || $m === '' || $m === []) {
        return '';
    }
    if (is_array($m) && isset($m['text']) && !array_is_list($m)) {
        $ton = (string)($m['ton'] ?? 'hinweis');
        $m = (string)$m['text'];
    }
    $ton = in_array($ton, ['ok', 'fehler', 'hinweis'], true) ? $ton : 'hinweis';
    $inhalt = $opt['text_html'] ?? (is_array($m)
        ? '<ul>' . implode('', array_map(fn($f) => '<li>' . e($f) . '</li>', array_values($m))) . '</ul>'
        : e($m));
    return '<div class="meldung ' . $ton . (isset($opt['klasse']) ? ' ' . e($opt['klasse']) : '') . '" role="' . ($ton === 'fehler' ? 'alert' : 'status') . '">' . $inhalt . '</div>';
}

/** Fehlerliste als HTML (auch mit Feldnamen als Schlüssel). */
function fehler_html(array $fehler): string
{
    return meldung_html(array_values($fehler), 'fehler');
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

/** Name im Seitentitel: Kurzname der Veranstaltung; vor der Installation „Wintersymposium“. */
function seiten_name(): string
{
    try {
        return installiert() && va_tabellen_da() ? va_kurz() : 'Wintersymposium';
    } catch (Throwable) {
        return 'Wintersymposium';
    }
}
