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
    return $bg === '' ? ['', []] : [" AND $alias.berufsgruppe = ?", [$bg]];
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
    $aerzte = (int)db_wert("SELECT COUNT(*) FROM anmeldung WHERE status = 'bestaetigt' AND berufsgruppe = ?", [$erste]);
    $mitEfn = (int)db_wert("SELECT COUNT(*) FROM anmeldung WHERE status = 'bestaetigt' AND berufsgruppe = ? AND efn IS NOT NULL", [$erste]);
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
        $gruppen[$g] = (int)db_wert("SELECT COUNT(*) FROM anmeldung WHERE status = 'bestaetigt' AND berufsgruppe = ?", [$g]);
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
    'cme' => ['CME-Liste', 'Name, Berufsgruppe und EFN der ÄrztInnen (Format für die Kammer folgt mit AP-10)'],
    'statistik' => ['Statistik', 'alle Zahlen dieser Seite'],
];

/** Export erzeugen: ['datei' => Name, 'text' => CSV] oder null. */
function export_erzeugen(string $art, string $bg = '', int $terminId = -1): ?array
{
    [$w, $p] = stat_filter($bg);
    $anm = db_zeilen("SELECT a.* FROM anmeldung a WHERE a.status = 'bestaetigt'" . $w . ' ORDER BY a.nachname, a.vorname', $p);
    $kurz = fn(string $s) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue']))) ?? '', '-');
    $datei = fn(string $n) => 'wintersymposium-' . $n . ($bg !== '' ? '-' . $kurz($bg) : '') . '-' . gmdate('Ymd') . '.csv';
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
            return ['datei' => $datei('teilnehmende'), 'text' => csv_text(['Nachname', 'Vorname', 'E-Mail', 'Berufsgruppe', 'Institution', 'Symposium', 'Workshops', 'Essen', 'Bestätigt am'], $z)];
        case 'termin':
        case 'termine':
        case 'wartelisten':
            $z = [];
            foreach (team_termine() as $t) {
                if ($t['id'] === 0 || ($art === 'termin' && $t['id'] !== $terminId)) {
                    continue;
                }
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
            return ['datei' => $datei($name), 'text' => csv_text(['Termin', $art === 'wartelisten' ? 'Platz auf der Liste' : 'Status', 'Nachname', 'Vorname', 'Berufsgruppe', 'E-Mail'], $z)];
        case 'essen':
            $z = [];
            foreach ($anm as $a) {
                if (anm_symposium_status((int)$a['id']) === 'fest') {
                    $z[] = [ANM_ESSEN[$a['essen']] ?? '', $a['nachname'], $a['vorname']];
                }
            }
            usort($z, fn($x, $y) => $x <=> $y);
            return ['datei' => $datei('essen'), 'text' => csv_text(['Essen', 'Nachname', 'Vorname'], $z)];
        case 'cme':
            $erste = va_liste('berufsgruppen')[0] ?? '';
            $z = [];
            foreach ($anm as $a) {
                if ($a['berufsgruppe'] === $erste && anm_symposium_status((int)$a['id']) === 'fest') {
                    $z[] = [$a['nachname'], $a['vorname'], $a['berufsgruppe'], $a['efn'] ?? ''];
                }
            }
            return ['datei' => $datei('cme'), 'text' => csv_text(['Nachname', 'Vorname', 'Berufsgruppe', 'EFN'], $z)];
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
            return ['datei' => $datei('statistik'), 'text' => csv_text(['Kennzahl', 'Wert'], $z)];
    }
    return null;
}
