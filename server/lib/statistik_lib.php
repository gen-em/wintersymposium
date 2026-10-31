<?php
declare(strict_types=1);

/*
 * Statistik und Export (AP-5, Mockup „Statistik“, E-49). Zahlen über
 * bestätigte Anmeldungen, wahlweise gefiltert nach Berufsgruppe.
 * CSV für Excel: Semikolon, UTF-8 mit BOM (Admin 2026-10-29).
 */

/** WHERE-Zusatz und Parameter für den Berufsgruppen-Filter. */
function stat_filter(string $bg, string $alias = 'a'): array
{
    // Immer nur die Veranstaltung des Kontexts (E-58)
    $va = " AND $alias.veranstaltung_id = " . va_id();
    return $bg === '' ? [$va, []] : [$va . " AND $alias.berufsgruppe = ?", [$bg]];
}

/** Alle Zahlen der Statistik-Seite. */
function stat_daten(string $bg = ''): array
{
    [$w, $p] = stat_filter($bg);
    $zahl = fn(string $sql, array $par = []) => (int)db_wert($sql, array_merge($par, $p));
    $angemeldet = $zahl("SELECT COUNT(*) FROM anmeldung a JOIN platz s ON s.anmeldung_id = a.id AND s.termin_id = 0 AND s.status = 'fest' WHERE a.status = 'bestaetigt'" . $w);
    $unbestaetigt = $zahl("SELECT COUNT(*) FROM anmeldung a WHERE a.status = 'unbestaetigt'" . $w);
    $warteSym = $zahl("SELECT COUNT(*) FROM anmeldung a JOIN platz s ON s.anmeldung_id = a.id AND s.termin_id = 0 AND s.status <> 'fest' WHERE a.status = 'bestaetigt'" . $w);
    $wlEintraege = $zahl("SELECT COUNT(*) FROM platz s JOIN anmeldung a ON a.id = s.anmeldung_id WHERE s.status = 'warte' AND s.termin_id <> 0" . $w);
    $wlPersonen = $zahl("SELECT COUNT(DISTINCT s.anmeldung_id) FROM platz s JOIN anmeldung a ON a.id = s.anmeldung_id WHERE s.status = 'warte' AND s.termin_id <> 0" . $w);
    $erste = va_liste('berufsgruppen')[0] ?? '';
    $aerzte = (int)db_wert("SELECT COUNT(*) FROM anmeldung WHERE veranstaltung_id = ? AND status = 'bestaetigt' AND berufsgruppe = ?", [va_id(), $erste]);
    $mitEfn = (int)db_wert("SELECT COUNT(*) FROM anmeldung WHERE veranstaltung_id = ? AND status = 'bestaetigt' AND berufsgruppe = ? AND efn IS NOT NULL", [va_id(), $erste]);
    $bestaetigt = $zahl("SELECT COUNT(*) FROM anmeldung a WHERE a.status = 'bestaetigt'" . $w);
    $konten = $zahl("SELECT COUNT(*) FROM anmeldung a WHERE a.status = 'bestaetigt' AND a.konto_id IS NOT NULL" . $w);
    $newsletter = $zahl("SELECT COUNT(*) FROM anmeldung a WHERE a.status = 'bestaetigt' AND a.newsletter = 1" . $w);

    // Verlauf: bestätigte Anmeldungen je Tag (Ortszeit), kumuliert
    $tage = [];
    foreach (db_zeilen("SELECT a.bestaetigt_am FROM anmeldung a WHERE a.status = 'bestaetigt' AND a.bestaetigt_am IS NOT NULL" . $w . ' ORDER BY a.bestaetigt_am', $p) as $r) {
        $tag = zeit_lokal($r['bestaetigt_am'], 'Y-m-d');
        $tage[$tag] = ($tage[$tag] ?? 0) + 1;
    }
    $verlauf = [];
    $summe = 0;
    foreach ($tage as $tag => $n) {
        $summe += $n;
        $verlauf[] = ['tag' => $tag, 'neu' => $n, 'summe' => $summe];
    }

    $termine = [];
    foreach (team_termine() as $t) {
        if ($t['id'] === 0) {
            continue;
        }
        $belegt = $bg === '' ? $t['belegt'] : (int)db_wert("SELECT COUNT(*) FROM platz s JOIN anmeldung a ON a.id = s.anmeldung_id WHERE s.termin_id = ? AND s.status IN ('fest','angebot')" . $w, array_merge([$t['id']], $p));
        $termine[] = ['text' => $t['text'], 'kap' => (int)$t['kap'], 'belegt' => $belegt, 'frei' => max(0, (int)$t['kap'] - $t['belegt']), 'warte' => $t['warte']];
    }
    $gruppen = [];
    foreach (va_liste('berufsgruppen') as $g) {
        $gruppen[$g] = (int)db_wert("SELECT COUNT(*) FROM anmeldung WHERE veranstaltung_id = ? AND status = 'bestaetigt' AND berufsgruppe = ?", [va_id(), $g]);
    }
    $inst = [];
    foreach (db_zeilen("SELECT a.institution, COUNT(*) AS n FROM anmeldung a WHERE a.status = 'bestaetigt'" . $w . ' GROUP BY a.institution ORDER BY n DESC, a.institution LIMIT 15', $p) as $r) {
        $inst[$r['institution']] = (int)$r['n'];
    }
    $essen = array_fill_keys(array_keys(ANM_ESSEN), 0);
    foreach (db_zeilen("SELECT a.essen, COUNT(*) AS n FROM anmeldung a WHERE a.status = 'bestaetigt'" . $w . ' GROUP BY a.essen', $p) as $r) {
        $essen[$r['essen']] = (int)$r['n'];
    }
    return [
        'angemeldet' => $angemeldet, 'unbestaetigt' => $unbestaetigt, 'warteliste' => $warteSym, 'kapazitaet' => anm_kapazitaet(0),
        'wl_eintraege' => $wlEintraege, 'wl_personen' => $wlPersonen, 'aerzte' => $aerzte, 'mit_efn' => $mitEfn,
        'bestaetigt' => $bestaetigt, 'konten' => $konten, 'newsletter' => $newsletter,
        'verlauf' => $verlauf, 'termine' => $termine, 'berufsgruppen' => $gruppen, 'institutionen' => $inst, 'essen' => $essen,
    ];
}

/** Prozent als Text („79 %“) oder „–“. */
function stat_prozent(int $teil, int $ganz): string
{
    return $ganz > 0 ? (string)round(100 * $teil / $ganz) . ' %' : '–';
}

/**
 * Waagrechte Balken als HTML mit SVG (eine Farbe, Zahl als Text daneben).
 * Ohne Inline-Stile: Breite als SVG-Attribut (CSP). $daten: [Beschriftung => Wert].
 */
function stat_balken(array $daten, string $titel): string
{
    $max = max(1, max($daten ?: [0]));
    $h = '<div class="balken-liste" role="list" aria-label="' . e($titel) . '">';
    foreach ($daten as $label => $wert) {
        $breite = round(100 * $wert / $max, 2);
        $h .= '<div class="balken-zeile" role="listitem"><span class="balken-label">' . e((string)$label) . '</span>'
            . '<svg class="balken-svg" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true"><title>' . e($label . ': ' . $wert) . '</title>'
            . '<rect x="0" y="1" width="' . $breite . '" height="8" rx="1" class="balken-wert"/></svg>'
            . '<span class="balken-zahl mono">' . (int)$wert . '</span></div>';
    }
    return $h . '</div>';
}

/** Kumulierter Verlauf als Fläche (SVG), mit Punkten und Tooltip je Tag. */
function stat_verlauf(array $verlauf): string
{
    if ($verlauf === []) {
        return '<p class="hinweis-leer">Noch keine bestätigten Anmeldungen.</p>';
    }
    $n = count($verlauf);
    $max = max(1, $verlauf[$n - 1]['summe']);
    $x = fn(int $i) => $n === 1 ? 300.0 : round($i * 600 / ($n - 1), 1);
    $y = fn(int $v) => round(150 - $v * 140 / $max, 1);
    $punkte = [];
    foreach ($verlauf as $i => $v) {
        $punkte[] = $x($i) . ',' . $y($v['summe']);
    }
    $svg = '<svg class="verlauf-svg" viewBox="0 0 600 160" preserveAspectRatio="none" role="img" aria-label="Bestätigte Anmeldungen kumuliert, ' . $verlauf[$n - 1]['summe'] . ' am ' . e(tag_text($verlauf[$n - 1]['tag'])) . '">'
        . '<line x1="0" y1="150" x2="600" y2="150" class="verlauf-achse"/>'
        . '<polygon class="verlauf-flaeche" points="' . $x(0) . ',150 ' . implode(' ', $punkte) . ' ' . $x($n - 1) . ',150"/>'
        . '<polyline class="verlauf-linie" points="' . implode(' ', $punkte) . '"/>';
    foreach ($verlauf as $i => $v) {
        $svg .= '<circle class="verlauf-punkt" cx="' . $x($i) . '" cy="' . $y($v['summe']) . '" r="4"><title>' . e(tag_text($v['tag']) . ': ' . $v['summe'] . ' (+' . $v['neu'] . ')') . '</title></circle>';
    }
    return $svg . '</svg><div class="verlauf-skala mono"><span>' . e(tag_text($verlauf[0]['tag'])) . '</span><span>' . $max . ' gesamt</span><span>' . e(tag_text($verlauf[$n - 1]['tag'])) . '</span></div>';
}

/* ---- Export ---------------------------------------------------------------- */

const EXPORT_ARTEN = [
    'teilnehmende' => ['Teilnehmendenliste', 'Name, Berufsgruppe, Institution, Workshops, Essen'],
    'termine' => ['Listen je Workshop-Termin', 'alle Termine in einer Datei, sortiert nach Termin (einzeln unter „Workshops“)'],
    'wartelisten' => ['Wartelisten', 'je Termin in Reihenfolge'],
    'essen' => ['Essensliste', 'Namen je Essen'],
    'cme' => ['CME-Liste', 'Name und EFN der anwesenden ÄrztInnen (ohne „nicht erschienen“, AP-10)'],
    'statistik' => ['Statistik', 'alle Zahlen dieser Seite'],
    'newsletter' => ['Newsletter-Listen', 'nur bestätigte Einträge, je Liste mit Zeitpunkt und Fassung der Einwilligung (ohne Berufsgruppen-Filter)'],
];

/**
 * Export erzeugen oder null. Rückgabe: 'datei' und 'text' (CSV wie bisher),
 * dazu für xlsx und PDF (O-35, E-56): 'basis' (Dateiname ohne Endung),
 * 'titel', 'kopf', 'zeilen', 'bg' und 'pdf' (Gruppen-Spalte, ausgelassene
 * Spalten, Querformat, Zusatzzeile je Gruppe) – oder 'pdf' => null, wenn es
 * kein PDF gibt (Newsletter: Adresslisten gehören nicht auf Papier).
 */
function export_erzeugen(string $art, string $bg = '', int $terminId = -1): ?array
{
    $paket = function (string $datei, string $titel, array $kopf, array $zeilen, ?array $pdf = []) use ($bg): array {
        return ['datei' => $datei, 'text' => csv_text($kopf, $zeilen), 'basis' => preg_replace('/\.csv$/', '', $datei), 'titel' => $titel,
            'kopf' => $kopf, 'zeilen' => $zeilen, 'bg' => $bg, 'pdf' => $pdf === null ? null : $pdf + ['gruppe' => null, 'ohne' => [], 'quer' => false, 'info' => []]];
    };
    [$w, $p] = stat_filter($bg);
    $anm = db_zeilen("SELECT a.* FROM anmeldung a WHERE a.status = 'bestaetigt'" . $w . ' ORDER BY a.nachname, a.vorname', $p);
    $kurz = fn(string $s) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue']))) ?? '', '-');
    $datei = fn(string $n) => (va_zeile()['kuerzel'] ?? 'wintersymposium') . '-' . $n . ($bg !== '' ? '-' . $kurz($bg) : '') . '-' . gmdate('Ymd') . '.csv';
    switch ($art) {
        case 'teilnehmende':
            $z = [];
            foreach ($anm as $a) {
                $ws = [];
                $sym = '';
                foreach (anm_plaetze((int)$a['id']) as $pl) {
                    if ((int)$pl['termin_id'] === 0) {
                        $sym = $pl['status'] === 'fest' ? 'Platz' : 'Warteliste';
                    } else {
                        $r = anm_platz_zeile($pl);
                        $ws[] = $r['text'] . ($pl['status'] === 'fest' ? '' : ' (' . $r['status'] . ')');
                    }
                }
                $z[] = [$a['nachname'], $a['vorname'], $a['email'], $a['berufsgruppe'] . ($a['beruf_sonstige'] !== '' ? ' (' . $a['beruf_sonstige'] . ')' : ''), $a['institution'], $sym, implode(' | ', $ws), ANM_ESSEN[$a['essen']] ?? '', datum_zeit_text($a['bestaetigt_am'])];
            }
            return $paket($datei('teilnehmende'), 'Teilnehmende', ['Nachname', 'Vorname', 'E-Mail', 'Berufsgruppe', 'Institution', 'Symposium', 'Workshops', 'Essen', 'Bestätigt am'], $z, ['quer' => true, 'ohne' => [2, 8]]);
        case 'termin':
        case 'termine':
        case 'wartelisten':
            $z = [];
            $info = [];
            foreach (team_termine() as $t) {
                if ($t['id'] === 0 || ($art === 'termin' && $t['id'] !== $terminId)) {
                    continue;
                }
                // PDF: Kontrollliste für Dozierende (Admin 2026-10-30) – je Termin Ort, Leitung, Plätze
                $info[$t['text']] = export_termin_info($t['id']);
                $pers = team_termin_personen($t['id']);
                $liste = $art === 'wartelisten' ? $pers['warte'] : $pers['fest'];
                foreach ($liste as $i => $pl) {
                    if ($bg !== '' && db_wert('SELECT berufsgruppe FROM anmeldung WHERE id = ?', [$pl['anmeldung_id']]) !== $bg) {
                        continue;
                    }
                    $z[] = [$t['text'], $art === 'wartelisten' ? $i + 1 : ($pl['status'] === 'fest' ? 'Platz' : 'Angebot offen'), $pl['nachname'], $pl['vorname'], $pl['berufsgruppe'], $pl['email']];
                }
            }
            if ($art === 'termin' && anm_termin($terminId) === null) {
                return null;
            }
            $name = $art === 'termin' ? 'termin-' . $kurz(anm_termin_text($terminId)) : $art;
            $titel = $art === 'wartelisten' ? 'Wartelisten' : ($art === 'termin' ? 'Liste ' . anm_termin_text($terminId) : 'Listen je Workshop-Termin');
            // PDF ohne E-Mail (Liste für Dozierende); je Termin eine Seite
            return $paket($datei($name), $titel, ['Termin', $art === 'wartelisten' ? 'Platz auf der Liste' : 'Status', 'Nachname', 'Vorname', 'Berufsgruppe', 'E-Mail'], $z, ['gruppe' => 0, 'ohne' => [5], 'info' => $info, 'alle_gruppen' => array_keys($info)]);
        case 'essen':
            $z = [];
            foreach ($anm as $a) {
                if (anm_symposium_status((int)$a['id']) === 'fest') {
                    $z[] = [ANM_ESSEN[$a['essen']] ?? '', $a['nachname'], $a['vorname']];
                }
            }
            usort($z, fn($x, $y) => $x <=> $y);
            return $paket($datei('essen'), 'Essensliste', ['Essen', 'Nachname', 'Vorname'], $z, ['gruppe' => 0]);
        case 'cme':
            $erste = va_liste('berufsgruppen')[0] ?? '';
            $z = [];
            foreach ($anm as $a) {
                if ($a['berufsgruppe'] === $erste && tn_anwesend((int)$a['id'])) {
                    $z[] = [$a['nachname'], $a['vorname'], $a['berufsgruppe'], $a['efn'] ?? ''];
                }
            }
            return $paket($datei('cme'), 'CME-Liste', ['Nachname', 'Vorname', 'Berufsgruppe', 'EFN'], $z);
        case 'newsletter':
            // AP-7: bestätigte Abonnierende (eine Liste über t=…, sonst alle)
            $par = [];
            $nur = '';
            if ($terminId > 0) {
                $nur = ' AND a.liste_id = ?';
                $par[] = $terminId;
            }
            $zeilen = array_map(fn($r) => [$r['liste'], $r['email'], $r['vorname'], datum_zeit_text($r['bestaetigt_am']), $r['fassung'], $r['quelle']], db_zeilen(
                "SELECT l.name AS liste, p.email, p.vorname, a.bestaetigt_am, a.fassung, a.quelle FROM newsletter_abo a JOIN newsletter_person p ON p.id = a.person_id JOIN newsletter_liste l ON l.id = a.liste_id
                 WHERE a.status = 'bestaetigt'" . $nur . ' ORDER BY l.pos, p.email', $par));
            return $paket('wintersymposium-newsletter-' . gmdate('Ymd') . '.csv', 'Newsletter-Listen', ['Liste', 'E-Mail', 'Vorname', 'Bestätigt am', 'Fassung der Einwilligung', 'Quelle'], $zeilen, null);
        case 'statistik':
            $d = stat_daten($bg);
            $z = [['Angemeldet', $d['angemeldet']], ['Kapazität', $d['kapazitaet'] ?? 'unbegrenzt'], ['Mail nicht bestätigt', $d['unbestaetigt']], ['Warteliste Symposium', $d['warteliste']],
                ['Wartelisten-Einträge Workshops', $d['wl_eintraege']], ['Personen auf Workshop-Wartelisten', $d['wl_personen']], ['ÄrztInnen', $d['aerzte']], ['davon mit EFN', $d['mit_efn']],
                ['Anmeldungen mit Konto', $d['konten']], ['Newsletter-Wunsch', $d['newsletter']]];
            foreach ($d['termine'] as $t) {
                $z[] = ['Termin ' . $t['text'] . ' belegt', $t['belegt']];
                $z[] = ['Termin ' . $t['text'] . ' Warteliste', $t['warte']];
            }
            foreach ($d['berufsgruppen'] as $g => $n) {
                $z[] = ['Berufsgruppe ' . $g, $n];
            }
            foreach ($d['essen'] as $k => $n) {
                $z[] = ['Essen ' . (ANM_ESSEN[$k] ?? $k), $n];
            }
            foreach ($d['verlauf'] as $v) {
                $z[] = ['Bestätigt bis ' . $v['tag'], $v['summe']];
            }
            return $paket($datei('statistik'), 'Statistik', ['Kennzahl', 'Wert'], $z);
    }
    return null;
}

/** Zusatzzeile eines Termins für das PDF: Ort, Leitung (abweichend je Termin, E-53), Plätze. */
function export_termin_info(int $terminId): string
{
    foreach (programm_veroeffentlicht()['punkte'] ?? [] as $p) { // Plätze gelten im veröffentlichten Programm
        foreach ($p['termine'] as $t) {
            if ((int)$t['id'] === $terminId) {
                $leitung = implode(', ', array_column($t['dozenten'] ?? $p['dozenten'], 'name'));
                return implode(' · ', array_filter([$p['ort'], $leitung !== '' ? 'Leitung: ' . $leitung : '', termin_belegt($terminId) . ' von ' . $t['plaetze'] . ' Plätzen belegt']));
            }
        }
    }
    return '';
}
