<?php
declare(strict_types=1);

/* Testbereich: Linkvorschau, Suchmaschinen und Icon je Veranstaltung (AP-21, E-78 bis E-80). */

function test_vorschau(): void
{
    abschnitt('Linkvorschau: Angaben aus den Einstellungen (E-78, T-350)');
    $f = test_frisch();
    ctx_setzen('host', '127.0.0.1');
    $_SERVER['REQUEST_URI'] = '/';
    $v = vorschau_angaben('veranstaltung', ['titel' => 'Start', 'start' => true]);
    $jahr = substr(va('veranstaltung_datum'), 0, 4);
    pruefe(str_starts_with($v['titel'], va('veranstaltung_name')) && str_contains($v['titel'], $jahr) && str_ends_with($v['titel'], ' – Allgäu Akut'), 'Startseite: Titel „<Name> <Jahr> – Allgäu Akut“');
    pruefe(str_contains($v['beschreibung'], tag_text(va('veranstaltung_datum'))) && str_contains($v['beschreibung'], 'Fortbildung Notfallmedizin'), 'Beschreibung mit Datum und Unterzeile');
    pruefe($v['url'] === url('/') && $v['kanonisch'], 'Startseite: kanonische Adresse /');
    pruefe($v['bild'] === url(VORSCHAU_BILD), 'ohne Icon: Standard-Vorschaubild');
    va_speichern(['veranstaltung_ort' => 'Kempten, Hörsaal 1', 'veranstaltung_auflage' => '4. Auflage'], $f['betreiber']);
    va_zwischenspeicher_leeren();
    $v = vorschau_angaben('veranstaltung', ['titel' => 'Start', 'start' => true]);
    pruefe(str_contains($v['beschreibung'], 'Kempten, Hörsaal 1') && str_starts_with($v['beschreibung'], '4. Auflage'), 'Ort und Auflage aus den Einstellungen, sofort');
    $_SERVER['REQUEST_URI'] = '/anmelden?x=1';
    $v = vorschau_angaben('veranstaltung', ['titel' => 'Anmelden']);
    pruefe($v['titel'] === 'Anmelden – ' . va_kurz() && $v['url'] === url('/anmelden'), 'Anmeldung: Titel mit Kurzname, Adresse ohne Abfrage');
    $kuerzel = va_zeile()['kuerzel'];
    $_SERVER['REQUEST_URI'] = '/' . $kuerzel;
    pruefe(vorschau_kanonisch() === url('/'), 'aktuelle Veranstaltung über ihr Kürzel: kanonisch /');

    abschnitt('Linkvorschau: persönliche Seiten ohne Inhalte (E-78)');
    $_SERVER['REQUEST_URI'] = '/link?t=geheimes-token-123';
    $v = vorschau_angaben('allgemein', ['titel' => 'Meine Anmeldung']);
    $alles = implode(' ', array_map('strval', $v));
    pruefe($v['titel'] === 'Allgäu Akut' && !str_contains($alles, 'geheimes-token') && !str_contains($alles, 'Meine Anmeldung') && $v['url'] === url('/') && !$v['kanonisch'],
        'allgemein: nur die Marke, Startseite als Adresse, kein Link-Schlüssel, kein canonical');
    $html = vorschau_kopf_html($v);
    pruefe(str_contains($html, 'property="og:title" content="Allgäu Akut"') && !str_contains($html, 'rel="canonical"'), 'Kopf-HTML: og:title Marke, ohne canonical');
    $v = vorschau_angaben('oeffentlich', ['titel' => 'Impressum <b>', 'beschreibung' => 'A & "B"']);
    $html = vorschau_kopf_html($v);
    pruefe(str_contains($html, 'content="A &amp; &quot;B&quot;"') && str_contains($html, 'Impressum &lt;b&gt;') && !str_contains($html, '<b>'), 'Werte maskiert');

    abschnitt('Strukturierte Daten (schema.org Event, 21c)');
    $_SERVER['REQUEST_URI'] = '/';
    $json = vorschau_event_json();
    preg_match('#<script type="application/ld\+json">(.*)</script>#s', $json, $m);
    $d = json_decode($m[1] ?? '', true);
    pruefe(is_array($d) && $d['@type'] === 'EducationEvent' && $d['name'] === vorschau_va_titel() && $d['location']['name'] === 'Kempten, Hörsaal 1', 'Event mit Name und Ort');
    pruefe(isset($d['startDate']) && preg_match('/^' . va('veranstaltung_datum') . 'T18:00:00\+0[12]:00$/', $d['startDate']) === 1 && isset($d['endDate']), 'Beginn mit Zeitzone, Ende vorhanden');
    pruefe(($d['offers']['url'] ?? '') === url('/anmelden') && ($d['offers']['availability'] ?? '') === 'https://schema.org/InStock', 'Anmeldung offen: Angebot mit Anmeldelink');
    va_speichern(['veranstaltung_ort' => '</script><script>alert(1)</script>'], $f['betreiber']);
    va_zwischenspeicher_leeren();
    pruefe(!str_contains(vorschau_event_json(), '</script><script>'), 'Ort mit </script> kann den Block nicht verlassen');

    abschnitt('robots.txt und sitemap.xml mit dem Schalter (21c, E-76 (3))');
    pruefe(robots_text() === "User-agent: *\nDisallow: /\n", 'Schalter aus: alles gesperrt');
    pruefe(sitemap_xml() === null, 'Schalter aus: keine Sitemap');
    $cfgDatei = konfig_datei();
    $cfgAlt = (string)file_get_contents($cfgDatei);
    try {
        $cfg = require $cfgDatei;
        $cfg['umgebung'] = 'produktion';
        file_put_contents($cfgDatei, '<?php return ' . var_export($cfg, true) . ";\n");
        konfig_verwerfen();
        betrieb_suchmaschinen_setzen(true, $f['betreiber']);
        $r = robots_text();
        pruefe(str_contains($r, "Disallow: /team\n") && str_contains($r, "Disallow: /link\n") && str_contains($r, 'Sitemap: ' . url('/sitemap.xml')) && !str_contains($r, "Disallow: /\n"), 'Schalter an: Team und Links gesperrt, Verweis auf die Sitemap');
        $entwurf = beispieldaten_entwurf();
        $xml = (string)sitemap_xml();
        pruefe(str_contains($xml, '<loc>' . url('/anmelden') . '</loc>') && str_contains($xml, '<loc>' . url('/') . '</loc>') && !str_contains($xml, 'sommerfest'), 'Sitemap: öffentliche Seiten, kein Entwurf');
        db_ausfuehren("UPDATE veranstaltung SET status = 'abgeschlossen' WHERE id = ?", [$entwurf]);
        pruefe(str_contains((string)sitemap_xml(), url('/sommerfest')), 'abgeschlossene Veranstaltung mit ihrer Startseite');
        betrieb_suchmaschinen_setzen(false, $f['betreiber']);
    } finally {
        file_put_contents($cfgDatei, $cfgAlt);
        konfig_verwerfen();
    }

    abschnitt('Icon je Veranstaltung (E-79, T-351)');
    test_frisch();
    ctx_setzen('host', '127.0.0.1');
    $fehler = function (string $daten): string {
        try {
            va_icon_schreiben($daten);
            return '';
        } catch (RuntimeException $ex) {
            return $ex->getMessage();
        }
    };
    pruefe(str_contains($fehler(beispieldaten_icon_png(600, 400)), 'quadratisch'), 'nicht quadratisch: abgelehnt');
    pruefe(str_contains($fehler(beispieldaten_icon_png(300)), 'mindestens 512'), 'kleiner als 512 px: abgelehnt');
    pruefe(str_contains($fehler('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'PNG'), 'SVG: abgelehnt (nur PNG)');
    $jpg = imagecreatetruecolor(600, 600);
    ob_start();
    imagejpeg($jpg);
    pruefe(str_contains($fehler((string)ob_get_clean()), 'PNG'), 'JPG: abgelehnt');
    pruefe(va_icon_datei('icon') === null, 'ohne Icon: keine Datei');
    $name = beispieldaten_icon();
    $ordner = foto_ordner();
    $groesse = fn(string $art) => getimagesize($ordner . '/' . va_icon_datei($art));
    pruefe($groesse('icon')[0] === 512 && $groesse('touch')[0] === 180 && $groesse('vorschau')[0] === 1200 && $groesse('vorschau')[1] === 630, 'drei Dateien: 512, 180, 1200 × 630');
    pruefe(vorschau_bild_url(true) === url('/bild/' . $name . '-vorschau.png') && vorschau_bild_url(false) === url(VORSCHAU_BILD), 'Vorschaubild der Veranstaltung nur bei Seiten der Veranstaltung');
    $farbe = imagecolorat(imagecreatefrompng($ordner . '/' . va_icon_datei('vorschau')), 5, 5);
    pruefe((($farbe >> 16) & 0xFF) === 0x00 && (($farbe >> 8) & 0xFF) === 0x1E && ($farbe & 0xFF) === 0x3A, 'Vorschaubild: Rand in Nachtblau');
    // Sicherung nimmt die Icons mit (sonst fehlten sie nach dem Wiederherstellen)
    $sich = sicherung_anlegen('Test Icon');
    foreach (VA_ICON_ARTEN as $endung) {
        @unlink($ordner . '/' . $name . $endung . '.png');
    }
    pruefe(sicherung_pruefen($sich) === null && sicherung_wiederherstellen($sich, null) === null && is_file($ordner . '/' . $name . '-vorschau.png'), 'Sicherung und Wiederherstellen mit Icon');
    va_zwischenspeicher_leeren();
    // Kopie der Veranstaltung teilt das Icon; Entfernen bei einer löscht die Dateien erst, wenn keine es mehr nutzt
    $kopie = va_anlegen('kopie2027', 'Kopie', va_id(), null);
    pruefe(va_icon_name($kopie['id']) === $name, 'Kopie der Veranstaltung übernimmt das Icon');
    va_icon_entfernen(null);
    pruefe(va_icon_datei('icon') === null && is_file($ordner . '/' . $name . '-512.png'), 'entfernt: Veranstaltung ohne Icon, Dateien bleiben für die Kopie');
    db_ausfuehren("DELETE FROM va_einstellung WHERE schluessel = 'icon'");
    touch($ordner . '/' . $name . '-512.png', time() - 7200);
    touch($ordner . '/' . $name . '-180.png', time() - 7200);
    touch($ordner . '/' . $name . '-vorschau.png', time() - 7200);
    foto_aufraeumen();
    pruefe(!is_file($ordner . '/' . $name . '-512.png'), 'Aufräumen löscht Icons, die keine Veranstaltung mehr nutzt');
    unset($_SERVER['REQUEST_URI']);
}
