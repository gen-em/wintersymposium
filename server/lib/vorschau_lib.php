<?php
declare(strict_types=1);

/*
 * Linkvorschau und Suchmaschinen (AP-21, E-78): Meta-Angaben im Seitenkopf
 * (Beschreibung, canonical, Open Graph, Twitter-Karte, strukturierte Daten
 * schema.org „Event“), robots.txt und sitemap.xml. Alles entsteht automatisch
 * aus den Einstellungen der Veranstaltung, ohne Felder zum Überschreiben.
 *
 * Öffentliche Seiten (seite_kopf mit 'suchmaschinen' => true) zeigen die
 * Angaben der Veranstaltung; persönliche und interne Seiten (Meine Anmeldung,
 * Einmal-Links, Konto, Login, Team-Bereich) nur eine allgemeine Vorschau ohne
 * Namen, Inhalte oder die angefragte Adresse (die einen Link-Schlüssel tragen kann).
 */

/** Standard-Vorschaubild (1200 × 630, lila Bildzeichen auf Nachtblau); ?v= bei jeder Änderung hochzählen. */
const VORSCHAU_BILD = '/assets/logo/vorschau.png?v=1';
const VORSCHAU_BREITE = 1200;
const VORSCHAU_HOEHE = 630;
/** Unterzeile der Dachmarke (wie im Fuß der Seiten). */
const VORSCHAU_UNTERZEILE = 'Fortbildung Notfallmedizin';
/** Öffentliche Pfade für robots.txt und sitemap.xml (E-76 (3)); Startseiten der Veranstaltungen kommen dazu. */
const VORSCHAU_OEFFENTLICH = ['/', '/anmelden', '/newsletter', '/impressum', '/datenschutz'];
/** Nie aufnehmen: Team-Bereich, Konto, Einmal-Links, Login und Technik. */
const VORSCHAU_GESPERRT = ['/team', '/konto', '/link', '/deine-anmeldung', '/einloggen', '/einrichten', '/notzugang', '/install', '/jobs', '/health', '/passkey'];

/**
 * Angaben für die Vorschau einer Seite. $art: 'veranstaltung' (Startseite,
 * Anmeldung), 'oeffentlich' (Newsletter, Impressum, Datenschutz) oder
 * 'allgemein' (alle übrigen). $opt: 'titel' (Seitentitel), 'beschreibung'
 * (statt der automatischen, nur öffentliche Seiten), 'start' (Startseite).
 */
function vorschau_angaben(string $art, array $opt = []): array
{
    $marke = vorschau_marke();
    $va = $art === 'veranstaltung' && vorschau_va_da();
    $titel = (string)($opt['titel'] ?? $marke);
    if ($va && !empty($opt['start'])) {
        $titel = vorschau_va_titel() . ' – ' . $marke;
    } elseif ($art !== 'allgemein' && $titel !== $marke) {
        $titel .= ' – ' . ($va ? va_kurz() : $marke);
    } elseif ($art === 'allgemein') {
        // Keine Seiteninhalte nach außen: nur die Marke
        $titel = $marke;
    }
    $beschreibung = $art === 'allgemein' ? $marke . ' – ' . VORSCHAU_UNTERZEILE
        : (string)($opt['beschreibung'] ?? ($va ? vorschau_va_beschreibung() : $marke . ' – ' . VORSCHAU_UNTERZEILE . '. Fortbildungen für Ärztinnen, Ärzte und Rettungsdienst.'));
    return [
        'titel' => $titel,
        'beschreibung' => $beschreibung,
        // Allgemein: nie die angefragte Adresse (Link-Schlüssel), nur die Startseite
        'url' => $art === 'allgemein' ? url('/') : vorschau_kanonisch(),
        'kanonisch' => $art !== 'allgemein',
        'bild' => vorschau_bild_url($va || $art === 'veranstaltung'),
        'marke' => $marke,
    ];
}

/** Name der Dachmarke (Einstellung reihe_name, sonst „Allgäu Akut“). */
function vorschau_marke(): string
{
    try {
        return installiert() ? reihe_name() : 'Allgäu Akut';
    } catch (Throwable) {
        return 'Allgäu Akut';
    }
}

/** Gibt es eine Veranstaltung im Kontext (installiert, Tabellen da, nicht „keine“)? */
function vorschau_va_da(): bool
{
    try {
        return installiert() && va_tabellen_da() && !va_keine();
    } catch (Throwable) {
        return false;
    }
}

/** „Allgäuer Winter-Notfallsymposium 2027“ – Name mit Jahr, falls das Jahr nicht schon im Namen steht. */
function vorschau_va_titel(): string
{
    $name = va('veranstaltung_name') !== '' ? va('veranstaltung_name') : va_kurz();
    $jahr = substr(va('veranstaltung_datum'), 0, 4);
    return $jahr !== '' && !str_contains($name, $jahr) ? $name . ' ' . $jahr : $name;
}

/** „3. Auflage · Mi 20.01.2027, 18:00–22:00 Uhr · Kempten · Fortbildung Notfallmedizin · CME: Punkte beantragt“ */
function vorschau_va_beschreibung(): string
{
    $teile = [];
    if (va('veranstaltung_auflage') !== '') {
        $teile[] = va('veranstaltung_auflage');
    }
    if (va('veranstaltung_datum') !== '') {
        $zeit = va('veranstaltung_beginn') !== '' ? ', ' . va('veranstaltung_beginn') . (va('veranstaltung_ende') !== '' ? '–' . va('veranstaltung_ende') : '') . ' Uhr' : '';
        $teile[] = tag_text(va('veranstaltung_datum')) . $zeit;
    }
    if (va('veranstaltung_ort') !== '') {
        $teile[] = va('veranstaltung_ort');
    }
    $teile[] = VORSCHAU_UNTERZEILE;
    if (va_an('mit_cme') && va('cme_anzeige') !== '') {
        $teile[] = 'CME: ' . va('cme_anzeige');
    }
    return implode(' · ', $teile);
}

/**
 * Kanonische Adresse der angefragten Seite: Pfad ohne Abfrage; die Startseite
 * der aktuellen Veranstaltung ist „/“, auch wenn sie über ihr Kürzel kam.
 */
function vorschau_kanonisch(): string
{
    $pfad = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $pfad = $pfad === '' || $pfad === '/' ? '/' : rtrim($pfad, '/');
    if (preg_match('#^/[a-z0-9][a-z0-9-]{1,39}$#', $pfad) === 1 && vorschau_va_da() && va_id() === va_aktuell_id()
        && (va_zeile()['kuerzel'] ?? '') === substr($pfad, 1)) {
        $pfad = '/';
    }
    // Nur unbedenkliche Zeichen (die Pfade der App); sonst die Startseite
    return url(preg_match('#^/[A-Za-z0-9/_.-]*$#', $pfad) === 1 ? $pfad : '/');
}

/** Absolute Adresse des Vorschaubilds: das der Veranstaltung (Icon, E-79) oder das Standardbild. */
function vorschau_bild_url(bool $veranstaltung): string
{
    $eigen = $veranstaltung && vorschau_va_da() ? va_icon_datei('vorschau') : null;
    return url($eigen !== null ? '/bild/' . $eigen : VORSCHAU_BILD);
}

/** Meta-Angaben für den Seitenkopf (nach <title>). */
function vorschau_kopf_html(array $v): string
{
    $m = fn(string $attr, string $name, string $wert) => '<meta ' . $attr . '="' . $name . '" content="' . e($wert) . '">';
    return $m('name', 'description', $v['beschreibung'])
        . ($v['kanonisch'] ? '<link rel="canonical" href="' . e($v['url']) . '">' : '')
        . $m('property', 'og:type', 'website')
        . $m('property', 'og:site_name', $v['marke'])
        . $m('property', 'og:locale', 'de_DE')
        . $m('property', 'og:url', $v['url'])
        . $m('property', 'og:title', $v['titel'])
        . $m('property', 'og:description', $v['beschreibung'])
        . $m('property', 'og:image', $v['bild'])
        . $m('property', 'og:image:type', 'image/png')
        . $m('property', 'og:image:width', (string)VORSCHAU_BREITE)
        . $m('property', 'og:image:height', (string)VORSCHAU_HOEHE)
        . $m('property', 'og:image:alt', $v['marke'])
        . $m('name', 'twitter:card', 'summary_large_image')
        . $m('name', 'twitter:title', $v['titel'])
        . $m('name', 'twitter:description', $v['beschreibung'])
        . $m('name', 'twitter:image', $v['bild']);
}

/**
 * Strukturierte Daten schema.org „Event“ (JSON-LD) für die Startseite der
 * Veranstaltung (21c). Leer ohne Veranstaltung oder ohne Datum.
 */
function vorschau_event_json(): string
{
    if (!vorschau_va_da() || va('veranstaltung_datum') === '') {
        return '';
    }
    $zeit = fn(string $hm) => (new DateTimeImmutable(va('veranstaltung_datum') . ' ' . ($hm !== '' ? $hm : '00:00'), new DateTimeZone(ANZEIGE_ZEITZONE)))->format(DATE_ATOM);
    $abgeschlossen = va_abgeschlossen();
    $event = [
        '@context' => 'https://schema.org',
        '@type' => 'EducationEvent',
        'name' => vorschau_va_titel(),
        'description' => vorschau_va_beschreibung(),
        'startDate' => $zeit(va('veranstaltung_beginn')),
        'eventStatus' => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'inLanguage' => 'de',
        'url' => vorschau_kanonisch(),
        'image' => [vorschau_bild_url(true)],
        'organizer' => ['@type' => 'Organization', 'name' => vorschau_marke(), 'url' => url('/')],
    ];
    if (va('veranstaltung_ende') !== '') {
        $event['endDate'] = $zeit(va('veranstaltung_ende'));
    }
    if (va('veranstaltung_ort') !== '') {
        $event['location'] = ['@type' => 'Place', 'name' => va('veranstaltung_ort'), 'address' => va('veranstaltung_ort')];
    }
    // Anmeldung: Link und Zeitraum; kostenlos ist hier nicht festgelegt, daher kein Preis
    if (!$abgeschlossen) {
        $event['offers'] = ['@type' => 'Offer', 'url' => url('/anmelden'), 'validFrom' => (new DateTimeImmutable(va('anmeldung_start') ?: 'now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'availability' => anm_zeitraum() === 'offen' ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut'];
        if (anm_zeitraum() === 'vorher') {
            $event['offers']['availability'] = 'https://schema.org/PreOrder';
        }
    }
    $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    return $json === false ? '' : '<script type="application/ld+json">' . $json . '</script>';
}

/** robots.txt (21c): mit erlaubtem Schalter die öffentlichen Seiten und die Sitemap, sonst alles gesperrt. */
function robots_text(): string
{
    if (!betrieb_suchmaschinen_erlaubt()) {
        return "User-agent: *\nDisallow: /\n";
    }
    return "User-agent: *\n"
        . implode('', array_map(fn($p) => 'Disallow: ' . $p . "\n", VORSCHAU_GESPERRT))
        . "Allow: /\n\nSitemap: " . url('/sitemap.xml') . "\n";
}

/** Adressen der Sitemap: öffentliche Seiten und die Startseiten der öffentlichen Veranstaltungen (nicht Entwürfe). */
function sitemap_adressen(): array
{
    $adressen = array_map(fn($p) => url($p), VORSCHAU_OEFFENTLICH);
    foreach (va_alle() as $v) {
        if ($v['status'] !== 'entwurf' && (int)$v['id'] !== va_aktuell_id()) {
            $adressen[] = url('/' . $v['kuerzel']);
        }
    }
    return $adressen;
}

/** sitemap.xml (21c); null, solange Suchmaschinen gesperrt sind. */
function sitemap_xml(): ?string
{
    if (!betrieb_suchmaschinen_erlaubt()) {
        return null;
    }
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
        . implode('', array_map(fn($a) => '<url><loc>' . e($a) . "</loc></url>\n", sitemap_adressen()))
        . "</urlset>\n";
}
