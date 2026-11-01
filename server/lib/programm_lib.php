<?php
declare(strict_types=1);

/*
 * Programm (AP-3 Schritt 3, E-39). Zwei Stände:
 *   - Arbeitsstand: Tabellen programmpunkt, workshop_termin, punkt_dozent, dozent.
 *     Das Team speichert hier, ohne dass sich die Startseite ändert.
 *   - Veröffentlichter Stand: eine JSON-Kopie in programm_stand; nur diese
 *     zeigt die Startseite. „Veröffentlichen“ kopiert den Arbeitsstand dorthin,
 *     „Verwerfen“ stellt den Arbeitsstand aus der Kopie wieder her.
 * Status je Punkt: veröffentlicht (gleich), geändert (gespeichert, nicht
 * veröffentlicht), neu (noch nie veröffentlicht); dazu gelöschte Punkte.
 *
 * Typen: Vortrag (VT), Fallbesprechung (FB), Workshop (WS, mit Terminen und
 * Plätzen), Offene Station (ohne Anmeldung), Workshop-Block (Zeitfenster, sammelt
 * Termine und Stationen in seiner Zeit), Pause/Info.
 */

const PROGRAMM_TYPEN = [
    'vortrag' => ['kurz' => 'VT', 'name' => 'Vortrag', 'farbe' => 'lila', 'zeit' => true],
    'fall' => ['kurz' => 'FB', 'name' => 'Fallbesprechung', 'farbe' => 'rot', 'zeit' => true],
    'workshop' => ['kurz' => 'WS', 'name' => 'Workshop', 'farbe' => 'rot', 'zeit' => false],
    'station' => ['kurz' => '', 'name' => 'Offene Station', 'farbe' => 'offen', 'zeit' => true],
    'block' => ['kurz' => '', 'name' => 'Workshop-Block (Zeitfenster)', 'farbe' => '', 'zeit' => true],
    'pause' => ['kurz' => '', 'name' => 'Pause / Info', 'farbe' => '', 'zeit' => true],
];
/** Höchstzahl Termine je Workshop und Plätze je Termin (technische Grenzen gegen Tippfehler). */
const PROGRAMM_MAX_TERMINE = 12;
const PROGRAMM_MAX_PLAETZE = 500;

function hm(?string $zeit): string
{
    return $zeit === null || $zeit === '' ? '' : substr($zeit, 0, 5);
}

function minuten(string $hm): int
{
    [$h, $m] = array_map('intval', explode(':', $hm));
    return $h * 60 + $m;
}

function hm_aus_minuten(int $min): string
{
    $min = max(0, min(23 * 60 + 59, $min));
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

/* ---- Lesen --------------------------------------------------------------- */

/** Arbeitsstand: Liste der Punkte mit Terminen und Dozierenden (Speicherform für Vergleich und Kopie). */
function programm_arbeitsstand(): array
{
    $punkte = [];
    foreach (db_zeilen('SELECT * FROM programmpunkt WHERE veranstaltung_id = ? ORDER BY id', [va_id()]) as $z) {
        $punkte[(int)$z['id']] = [
            'id' => (int)$z['id'], 'typ' => $z['typ'], 'kuerzel' => $z['kuerzel'], 'titel' => $z['titel'],
            'untertitel' => $z['untertitel'], 'beschreibung' => $z['beschreibung'], 'ort' => $z['ort'],
            'beginn' => hm($z['beginn']), 'ende' => hm($z['ende']), 'bring' => $z['bring'], 'notiz' => $z['notiz'],
            'termine' => [], 'dozenten' => [],
        ];
        // Nur wenn gesetzt: ältere veröffentlichte Stände bleiben so unverändert (gleicher Hash)
        if ($z['block_id'] !== null) {
            $punkte[(int)$z['id']]['block_id'] = (int)$z['block_id'];
        }
    }
    foreach (db_zeilen('SELECT t.* FROM workshop_termin t JOIN programmpunkt p ON p.id = t.punkt_id WHERE p.veranstaltung_id = ? ORDER BY t.beginn, t.id', [va_id()]) as $t) {
        if (isset($punkte[(int)$t['punkt_id']])) {
            $punkte[(int)$t['punkt_id']]['termine'][] = ['id' => (int)$t['id'], 'beginn' => hm($t['beginn']), 'ende' => hm($t['ende']), 'plaetze' => (int)$t['plaetze']];
        }
    }
    // Abweichende Dozierende je Termin (Rückmeldung 2026-10-30); Schlüssel nur wenn gesetzt (gleicher Hash wie vorher)
    $jeTermin = [];
    foreach (db_zeilen('SELECT td.termin_id, d.id, d.name, d.funktion, d.kurzbio, d.foto FROM termin_dozent td JOIN dozent d ON d.id = td.dozent_id ORDER BY td.pos, d.name') as $d) {
        $jeTermin[(int)$d['termin_id']][] = ['id' => (int)$d['id'], 'name' => $d['name'], 'funktion' => $d['funktion'], 'kurzbio' => $d['kurzbio'], 'foto' => $d['foto']];
    }
    if ($jeTermin !== []) {
        foreach ($punkte as &$p) {
            foreach ($p['termine'] as &$t) {
                if (isset($jeTermin[$t['id']])) {
                    $t['dozenten'] = $jeTermin[$t['id']];
                }
            }
            unset($t);
        }
        unset($p);
    }
    foreach (db_zeilen('SELECT pd.punkt_id, d.id, d.name, d.funktion, d.kurzbio, d.foto FROM punkt_dozent pd JOIN dozent d ON d.id = pd.dozent_id ORDER BY pd.pos, d.name') as $d) {
        if (isset($punkte[(int)$d['punkt_id']])) {
            $punkte[(int)$d['punkt_id']]['dozenten'][] = ['id' => (int)$d['id'], 'name' => $d['name'], 'funktion' => $d['funktion'], 'kurzbio' => $d['kurzbio'], 'foto' => $d['foto']];
        }
    }
    return array_values($punkte);
}

/** Veröffentlichter Stand: ['punkte' => [...], 'am' => UTC] oder null. */
function programm_veroeffentlicht(): ?array
{
    $z = db_zeile('SELECT daten, veroeffentlicht_am FROM programm_stand WHERE id = ?', [va_id()]);
    if ($z === null) {
        return null;
    }
    $d = json_decode($z['daten'], true);
    return is_array($d) ? ['punkte' => $d, 'am' => $z['veroeffentlicht_am']] : null;
}

function programm_punkt_hash(array $p): string
{
    return hash('sha256', json_encode($p, JSON_UNESCAPED_UNICODE));
}

/**
 * Vergleich Arbeitsstand ↔ veröffentlicht.
 * ['status' => [id => 'veroeffentlicht'|'geaendert'|'neu'], 'geloescht' => [punkt…], 'offen' => bool]
 */
function programm_status(?array $arbeit = null, ?array $pub = null): array
{
    $arbeit ??= programm_arbeitsstand();
    $pub ??= programm_veroeffentlicht();
    $alt = [];
    foreach ($pub['punkte'] ?? [] as $p) {
        $alt[$p['id']] = $p;
    }
    $status = [];
    foreach ($arbeit as $p) {
        $status[$p['id']] = !isset($alt[$p['id']]) ? 'neu' : (programm_punkt_hash($alt[$p['id']]) === programm_punkt_hash($p) ? 'veroeffentlicht' : 'geaendert');
        unset($alt[$p['id']]);
    }
    $geloescht = array_values($alt);
    $offen = $geloescht !== [] || in_array('neu', $status, true) || in_array('geaendert', $status, true);
    return ['status' => $status, 'geloescht' => $geloescht, 'offen' => $offen];
}

/** Beginn eines Punkts für die Sortierung (Workshop: frühester Termin); '' = ohne Uhrzeit. */
function programm_beginn(array $p): string
{
    if ($p['typ'] === 'workshop') {
        $b = array_column($p['termine'], 'beginn');
        sort($b);
        return $b[0] ?? '';
    }
    return $p['beginn'];
}

function programm_sortieren(array $punkte): array
{
    usort($punkte, function (array $a, array $b): int {
        $x = programm_beginn($a);
        $y = programm_beginn($b);
        if ($x === '' || $y === '') {
            return [$x !== '', $a['id']] <=> [$y !== '', $b['id']];
        }
        // Blöcke vor ihren Inhalten, sonst nach Beginn, dann nach id
        return [$x, $a['typ'] === 'block' ? 0 : 1, $a['id']] <=> [$y, $b['typ'] === 'block' ? 0 : 1, $b['id']];
    });
    return $punkte;
}

/**
 * Gliederung für Plan und Startseite: Liste von Einträgen
 *   ['art' => 'punkt', 'punkt' => p]               Vortrag, Fall, Pause, Station/Workshop außerhalb von Blöcken
 *   ['art' => 'block', 'punkt' => p, 'kinder' => [['art' => 'termin'|'punkt', 'punkt' => p, 'termin' => t|null]]]
 *   (art 'punkt' im Block: Station, Vortrag oder Fallbesprechung)
 * Ein Workshop-Termin oder eine Station gehört zum ersten Block, in dessen Zeit er ganz liegt.
 */
function programm_gliederung(array $punkte): array
{
    $punkte = programm_sortieren($punkte);
    $bloecke = array_values(array_filter($punkte, fn($p) => $p['typ'] === 'block' && $p['beginn'] !== '' && $p['ende'] !== ''));
    $blockIds = array_column(array_filter($punkte, fn($p) => $p['typ'] === 'block'), 'id');
    // Ausdrücklich zugeordnet (Rückmeldung 2026-10-30) geht vor der Uhrzeit
    $zugeordnet = fn(array $p): ?int => isset($p['block_id']) && in_array($p['block_id'], $blockIds, true) ? (int)$p['block_id'] : null;
    $imBlock = function (string $von, string $bis) use ($bloecke): ?int {
        foreach ($bloecke as $b) {
            if ($von >= $b['beginn'] && $bis <= $b['ende']) {
                return $b['id'];
            }
        }
        return null;
    };
    $kinder = [];
    $aussen = [];
    foreach ($punkte as $p) {
        if ($p['typ'] === 'workshop') {
            $zb = $zugeordnet($p);
            if ($zb !== null && $p['termine'] === []) {
                $kinder[$zb][] = ['art' => 'punkt', 'punkt' => $p, 'termin' => null];
                continue;
            }
            foreach ($p['termine'] as $t) {
                $b = $zb ?? $imBlock($t['beginn'], $t['ende']);
                if ($b !== null) {
                    $kinder[$b][] = ['art' => 'termin', 'punkt' => $p, 'termin' => $t];
                } else {
                    $aussen[] = ['art' => 'termin', 'punkt' => $p, 'termin' => $t];
                }
            }
            if ($p['termine'] === []) {
                $aussen[] = ['art' => 'punkt', 'punkt' => $p];
            }
        } elseif ($p['typ'] === 'station' && (($b = $zugeordnet($p)) !== null || ($p['beginn'] !== '' && ($b = $imBlock($p['beginn'], $p['ende'])) !== null))) {
            $kinder[$b][] = ['art' => 'punkt', 'punkt' => $p, 'termin' => null];
        } elseif (in_array($p['typ'], ['vortrag', 'fall'], true) && ($b = $zugeordnet($p)) !== null) {
            // Vorträge und Fallbesprechungen nur ausdrücklich zugeordnet, nicht über die Uhrzeit
            $kinder[$b][] = ['art' => 'punkt', 'punkt' => $p, 'termin' => null];
        }
    }
    $liste = [];
    foreach ($punkte as $p) {
        if ($p['typ'] === 'block') {
            $k = $kinder[$p['id']] ?? [];
            usort($k, fn($a, $b) => [($a['termin']['beginn'] ?? $a['punkt']['beginn']), $a['art'] === 'punkt' ? 1 : 0] <=> [($b['termin']['beginn'] ?? $b['punkt']['beginn']), $b['art'] === 'punkt' ? 1 : 0]);
            $liste[] = ['art' => 'block', 'punkt' => $p, 'kinder' => $k, 'beginn' => $p['beginn']];
        } elseif ($p['typ'] === 'station') {
            if ($zugeordnet($p) === null && ($p['beginn'] === '' || $imBlock($p['beginn'], $p['ende']) === null)) {
                $liste[] = ['art' => 'punkt', 'punkt' => $p, 'beginn' => $p['beginn']];
            }
        } elseif (in_array($p['typ'], ['vortrag', 'fall'], true) && $zugeordnet($p) !== null) {
            continue;
        } elseif ($p['typ'] !== 'workshop') {
            $liste[] = ['art' => 'punkt', 'punkt' => $p, 'beginn' => $p['beginn']];
        }
    }
    foreach ($aussen as $a) {
        $liste[] = $a + ['beginn' => $a['termin']['beginn'] ?? ''];
    }
    usort($liste, fn($a, $b) => [$a['beginn'] !== '', $a['beginn'], $a['art'] === 'block' ? 0 : 1] <=> [$b['beginn'] !== '', $b['beginn'], $b['art'] === 'block' ? 0 : 1]);
    return $liste;
}

/** Vergebene Plätze eines Termins (feste Plätze und offene Nachrück-Angebote, AP-4). */
function termin_belegt(int $terminId): int
{
    return $terminId > 0 ? anm_belegt($terminId) : 0;
}

/** Vorschlag für das nächste freie Kürzel eines Typs (z. B. „VT 03“). */
function kuerzel_vorschlag(string $typ): string
{
    $kurz = PROGRAMM_TYPEN[$typ]['kurz'] ?? '';
    if ($kurz === '') {
        return '';
    }
    $hoechste = 0;
    foreach (db_zeilen('SELECT kuerzel FROM programmpunkt WHERE typ = ? AND veranstaltung_id = ?', [$typ, va_id()]) as $z) {
        if (preg_match('/(\d+)\s*$/', $z['kuerzel'], $m) === 1) {
            $hoechste = max($hoechste, (int)$m[1]);
        }
    }
    return sprintf('%s %02d', $kurz, $hoechste + 1);
}

/* ---- Prüfen und Speichern ------------------------------------------------ */

/**
 * Formulareingaben eines Punkts prüfen.
 * Gibt ['werte' => [...], 'termine' => [[id|null, beginn, ende, plaetze]], 'dozenten' => [ids], 'fehler' => [feld => text]] zurück.
 */
function programm_punkt_pruefen(array $e, ?int $id = null): array
{
    $f = [];
    $typ = (string)($e['typ'] ?? '');
    if (!isset(PROGRAMM_TYPEN[$typ])) {
        $f['typ'] = 'Bitte einen Typ wählen.';
        $typ = 'pause';
    }
    $text = fn(string $k, int $max) => mb_substr(trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', (string)($e[$k] ?? ''))) ?? ''), 0, $max + 1);
    $w = [
        'typ' => $typ,
        'kuerzel' => $text('kuerzel', 12),
        'titel' => $text('titel', 160),
        'untertitel' => $text('untertitel', 200),
        'beschreibung' => editor_bereinigen((string)($e['beschreibung'] ?? ''), 'block', true),
        'ort' => $text('ort', 120),
        'bring' => $text('bring', 160),
        'notiz' => $text('notiz', 300),
        'beginn' => trim((string)($e['beginn'] ?? '')),
        'ende' => trim((string)($e['ende'] ?? '')),
        'block_id' => (int)($e['block_id'] ?? 0) ?: null,
    ];
    // Zuordnung zu einem Block nur für Inhalte (PROGRAMM_IM_BLOCK), nur zu einem vorhandenen Block
    if (!in_array($typ, PROGRAMM_IM_BLOCK, true) || ($w['block_id'] !== null && db_wert("SELECT COUNT(*) FROM programmpunkt WHERE id = ? AND typ = 'block' AND veranstaltung_id = ?", [$w['block_id'], va_id()]) != 1)) {
        $w['block_id'] = null;
    }
    foreach (['kuerzel' => 12, 'titel' => 160, 'untertitel' => 200, 'beschreibung' => 4000, 'ort' => 120, 'bring' => 160, 'notiz' => 300] as $k => $max) {
        // Beschreibung: Länge des sichtbaren Texts (gespeichert als HTML aus dem Editor, E-54)
        if (mb_strlen($k === 'beschreibung' ? editor_text($w[$k]) : $w[$k]) > $max) {
            $f[$k] = "Höchstens $max Zeichen.";
        }
    }
    if ($w['titel'] === '') {
        $f['titel'] = 'Bitte einen Titel angeben.';
    }
    // Felder, die der Typ nicht hat, leeren
    if (!in_array($typ, ['vortrag', 'fall', 'workshop'], true)) {
        $w['kuerzel'] = '';
        $w['untertitel'] = '';
    }
    if ($typ !== 'workshop') {
        $w['bring'] = '';
        $w['notiz'] = '';
    }
    if (in_array($typ, ['block'], true)) {
        $w['beschreibung'] = '';
        $w['ort'] = '';
    }
    // Zeiten (Workshop: keine eigene Zeit, nur Termine)
    $zeitOk = fn(string $z) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $z) === 1;
    if ($typ === 'workshop') {
        $w['beginn'] = '';
        $w['ende'] = '';
    } else {
        foreach (['beginn', 'ende'] as $k) {
            if ($w[$k] !== '' && !$zeitOk($w[$k])) {
                $f[$k] = 'Bitte eine Uhrzeit wie 18:30 angeben.';
            }
        }
        if (!isset($f['beginn']) && !isset($f['ende'])) {
            if (($w['beginn'] === '') !== ($w['ende'] === '')) {
                $f[$w['beginn'] === '' ? 'beginn' : 'ende'] = 'Beginn und Ende gehören zusammen (oder beide leer lassen und den Punkt im Plan an seine Stelle ziehen).';
            } elseif ($w['beginn'] !== '' && $w['ende'] <= $w['beginn']) {
                $f['ende'] = 'Das Ende muss nach dem Beginn liegen.';
            }
        }
    }
    // Termine
    $termine = [];
    $gibt = array_map('intval', array_column(db_zeilen('SELECT id FROM dozent'), 'id'));
    if ($typ === 'workshop') {
        $tb = (array)($e['t_beginn'] ?? []);
        foreach ($tb as $i => $von) {
            $von = trim((string)$von);
            $bis = trim((string)(($e['t_ende'] ?? [])[$i] ?? ''));
            $pl = trim((string)(($e['t_plaetze'] ?? [])[$i] ?? ''));
            $tid = (int)(($e['t_id'] ?? [])[$i] ?? 0);
            $weg = !empty(($e['t_weg'] ?? [])[$i]);
            // Dozierende je Termin: Zeilen-Schlüssel statt Position (Zeilen lassen sich entfernen)
            $schluessel = (string)(($e['t_key'] ?? [])[$i] ?? '');
            $tDoz = [];
            foreach ((array)(($e['t_doz'] ?? [])[$schluessel] ?? []) as $d) {
                if (in_array((int)$d, $gibt, true) && !in_array((int)$d, $tDoz, true)) {
                    $tDoz[] = (int)$d;
                }
            }
            if ($weg || ($von === '' && $bis === '' && $pl === '')) {
                if ($weg && $tid > 0 && termin_belegt($tid) > 0) {
                    $f['termine'] = 'Ein Termin mit Anmeldungen kann nicht entfernt werden – zuerst die Personen umbuchen.';
                }
                continue;
            }
            $n = count($termine) + 1;
            if (!$zeitOk($von) || !$zeitOk($bis)) {
                $f['termine'] = "Termin $n: bitte Beginn und Ende wie 20:00 angeben.";
            } elseif ($bis <= $von) {
                $f['termine'] = "Termin $n: das Ende muss nach dem Beginn liegen.";
            } elseif (preg_match('/^\d{1,4}$/', $pl) !== 1 || (int)$pl < 1 || (int)$pl > PROGRAMM_MAX_PLAETZE) {
                $f['termine'] = "Termin $n: Plätze als Zahl von 1 bis " . PROGRAMM_MAX_PLAETZE . '.';
            } elseif ($tid > 0 && (int)$pl < termin_belegt($tid)) {
                $f['termine'] = "Termin $n: " . (int)$pl . ' Plätze, aber schon ' . termin_belegt($tid) . ' vergeben. Plätze können nicht unter die vergebenen gesenkt werden – zuerst Personen umbuchen.';
            }
            $termine[] = ['id' => $tid > 0 ? $tid : null, 'beginn' => $von, 'ende' => $bis, 'plaetze' => (int)$pl, 'dozenten' => $tDoz];
        }
        if (count($termine) > PROGRAMM_MAX_TERMINE) {
            $f['termine'] = 'Höchstens ' . PROGRAMM_MAX_TERMINE . ' Termine je Workshop.';
        }
    }
    // Dozierende (nur vorhandene)
    $dozenten = [];
    if (in_array($typ, ['vortrag', 'fall', 'workshop', 'station'], true)) {
        foreach ((array)($e['dozenten'] ?? []) as $d) {
            if (in_array((int)$d, $gibt, true) && !in_array((int)$d, $dozenten, true)) {
                $dozenten[] = (int)$d;
            }
        }
    }
    return ['werte' => $w, 'termine' => $termine, 'dozenten' => $dozenten, 'fehler' => $f];
}

/** Was einem Workshop-Block zugeordnet werden kann (Rückmeldung 2026-10-30: auch Vorträge und Fallbesprechungen). */
const PROGRAMM_IM_BLOCK = ['vortrag', 'fall', 'workshop', 'station'];

/** Punkte nacheinander im Ablauf; offene Stationen und Workshop-Termine laufen parallel im Block. */
const PROGRAMM_HAUPTSPUR = ['vortrag', 'fall', 'pause', 'block'];

/**
 * Nach dem Einfügen (Ziehen zwischen zwei Punkte, Admin 2026-10-29): Punkte
 * der Hauptspur, die jetzt mit dem eingefügten überlappen, rücken nach hinten,
 * jeweils direkt an das Ende des vorigen, bis eine Lücke reicht. Dauer bleibt;
 * ein Block nimmt seine Termine und Stationen mit. Gibt die Zahl zurück.
 */
function programm_nachruecken_kette(int $id, string $beginn, string $ende, ?int $kontoId): int
{
    $n = 0;
    $cursor = minuten($ende);
    $folgende = db_zeilen(
        "SELECT id, beginn, ende FROM programmpunkt WHERE veranstaltung_id = " . va_id() . " AND id <> ? AND beginn IS NOT NULL AND beginn >= ? AND block_id IS NULL AND typ IN ('" . implode("','", PROGRAMM_HAUPTSPUR) . "') ORDER BY beginn, id",
        [$id, $beginn . ':00']
    );
    foreach ($folgende as $f) {
        $b = minuten(hm($f['beginn']));
        if ($b >= $cursor) {
            break; // Lücke: ab hier passt der Ablauf wieder
        }
        $neu = programm_verschieben('punkt', (int)$f['id'], hm_aus_minuten($cursor), $kontoId);
        if ($neu === null) {
            break;
        }
        $cursor = minuten(substr($neu, -5));
        $n++;
    }
    return $n;
}

/** Hinweise ohne Sperre: Raum zur selben Zeit doppelt belegt. */
function programm_hinweise(array $punkte): array
{
    $belegung = [];
    foreach ($punkte as $p) {
        if ($p['ort'] === '' || $p['typ'] === 'block' || $p['typ'] === 'pause') {
            continue;
        }
        $zeiten = $p['typ'] === 'workshop' ? $p['termine'] : ($p['beginn'] !== '' ? [['beginn' => $p['beginn'], 'ende' => $p['ende']]] : []);
        foreach ($zeiten as $z) {
            $belegung[mb_strtolower($p['ort'])][] = ['p' => $p, 'von' => $z['beginn'], 'bis' => $z['ende']];
        }
    }
    $h = [];
    foreach ($belegung as $liste) {
        for ($i = 0; $i < count($liste); $i++) {
            for ($j = $i + 1; $j < count($liste); $j++) {
                $a = $liste[$i];
                $b = $liste[$j];
                if ($a['von'] < $b['bis'] && $b['von'] < $a['bis'] && $a['p']['id'] !== $b['p']['id']) {
                    $h[] = $a['p']['ort'] . ' ist ' . max($a['von'], $b['von']) . '–' . min($a['bis'], $b['bis']) . ' doppelt belegt: '
                        . trim($a['p']['kuerzel'] . ' ' . $a['p']['titel']) . ' und ' . trim($b['p']['kuerzel'] . ' ' . $b['p']['titel']) . '.';
                }
            }
        }
    }
    // Zugeordnet, aber zeitlich außerhalb des Blocks
    $bloecke = [];
    foreach ($punkte as $p) {
        if ($p['typ'] === 'block' && $p['beginn'] !== '') {
            $bloecke[$p['id']] = $p;
        }
    }
    foreach ($punkte as $p) {
        $b = isset($p['block_id']) ? ($bloecke[$p['block_id']] ?? null) : null;
        if ($b === null) {
            continue;
        }
        $zeiten = $p['typ'] === 'workshop' ? $p['termine'] : ($p['beginn'] !== '' ? [['beginn' => $p['beginn'], 'ende' => $p['ende']]] : []);
        foreach ($zeiten as $z) {
            if ($z['beginn'] < $b['beginn'] || $z['ende'] > $b['ende']) {
                $h[] = trim($p['kuerzel'] . ' ' . $p['titel']) . ' gehört zu „' . $b['titel'] . '“ (' . $b['beginn'] . '–' . $b['ende'] . '), liegt aber ' . $z['beginn'] . '–' . $z['ende'] . '.';
            }
        }
    }
    return array_values(array_unique($h));
}

/** Geprüften Punkt speichern (neu: $id = null). Gibt die id zurück. */
function programm_punkt_speichern(?int $id, array $r, ?int $kontoId): int
{
    $w = $r['werte'];
    $jetzt = db_jetzt();
    return db_transaktion(function () use ($id, $w, $r, $jetzt, $kontoId): int {
        $felder = [$w['typ'], $w['block_id'], $w['kuerzel'], $w['titel'], $w['untertitel'], $w['beschreibung'], $w['ort'],
            $w['beginn'] === '' ? null : $w['beginn'], $w['ende'] === '' ? null : $w['ende'], $w['bring'], $w['notiz']];
        if ($id === null) {
            db_ausfuehren(
                'INSERT INTO programmpunkt (veranstaltung_id, typ, block_id, kuerzel, titel, untertitel, beschreibung, ort, beginn, ende, bring, notiz, erstellt_am, geaendert_am) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge([va_id()], $felder, [$jetzt, $jetzt])
            );
            $id = (int)db()->lastInsertId();
        } else {
            db_ausfuehren(
                'UPDATE programmpunkt SET typ = ?, block_id = ?, kuerzel = ?, titel = ?, untertitel = ?, beschreibung = ?, ort = ?, beginn = ?, ende = ?, bring = ?, notiz = ?, geaendert_am = ? WHERE id = ? AND veranstaltung_id = ?',
                array_merge($felder, [$jetzt, $id, va_id()])
            );
        }
        // Termine: vorhandene aktualisieren, neue anlegen, fehlende entfernen
        $behalten = [];
        foreach ($r['termine'] as $t) {
            if ($t['id'] !== null && db_wert('SELECT COUNT(*) FROM workshop_termin WHERE id = ? AND punkt_id = ?', [$t['id'], $id]) == 1) {
                db_ausfuehren('UPDATE workshop_termin SET beginn = ?, ende = ?, plaetze = ? WHERE id = ?', [$t['beginn'], $t['ende'], $t['plaetze'], $t['id']]);
                $tid = $t['id'];
            } else {
                db_ausfuehren('INSERT INTO workshop_termin (punkt_id, beginn, ende, plaetze) VALUES (?, ?, ?, ?)', [$id, $t['beginn'], $t['ende'], $t['plaetze']]);
                $tid = (int)db()->lastInsertId();
            }
            $behalten[] = $tid;
            db_ausfuehren('DELETE FROM termin_dozent WHERE termin_id = ?', [$tid]);
            foreach ($t['dozenten'] ?? [] as $pos => $d) {
                db_ausfuehren('INSERT INTO termin_dozent (termin_id, dozent_id, pos) VALUES (?, ?, ?)', [$tid, $d, $pos]);
            }
        }
        $platz = $behalten === [] ? '0' : implode(',', array_map('intval', $behalten));
        db_ausfuehren("DELETE FROM workshop_termin WHERE punkt_id = ? AND id NOT IN ($platz)", [$id]);
        db_ausfuehren('DELETE FROM punkt_dozent WHERE punkt_id = ?', [$id]);
        foreach ($r['dozenten'] as $pos => $d) {
            db_ausfuehren('INSERT INTO punkt_dozent (punkt_id, dozent_id, pos) VALUES (?, ?, ?)', [$id, $d, $pos]);
        }
        audit_schreiben('programm', 'Programmpunkt ' . $id . ' gespeichert (' . $w['typ'] . ')', $kontoId);
        return $id;
    });
}

function programm_punkt_loeschen(int $id, ?int $kontoId): bool
{
    if (!programm_punkt_im_kontext($id)) {
        return false;
    }
    foreach (db_zeilen('SELECT id FROM workshop_termin WHERE punkt_id = ?', [$id]) as $t) {
        if (termin_belegt((int)$t['id']) > 0) {
            return false;
        }
    }
    db_ausfuehren('UPDATE programmpunkt SET block_id = NULL WHERE block_id = ?', [$id]);
    db_ausfuehren('DELETE FROM programmpunkt WHERE id = ?', [$id]);
    audit_schreiben('programm', 'Programmpunkt ' . $id . ' gelöscht', $kontoId);
    return true;
}

/**
 * Verschieben im Plan (Ziehen): neuer Beginn, die Dauer bleibt. Art 'punkt' oder 'termin'.
 * Ein Block nimmt alle Termine und Stationen in seiner Zeit mit. Punkte ohne Uhrzeit
 * bekommen 30 Minuten. Gibt den neuen Zeitraum als Text zurück oder null.
 */
function programm_verschieben(string $art, int $id, string $beginn, ?int $kontoId, bool $nachruecken = false): ?string
{
    if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $beginn) !== 1) {
        return null;
    }
    $GLOBALS['programm_nachgerueckt'] = 0;
    return db_transaktion(function () use ($art, $id, $beginn, $kontoId, $nachruecken): ?string {
        if ($art === 'termin') {
            $t = db_zeile('SELECT t.* FROM workshop_termin t JOIN programmpunkt p ON p.id = t.punkt_id WHERE t.id = ? AND p.veranstaltung_id = ?', [$id, va_id()]);
            if ($t === null) {
                return null;
            }
            $dauer = minuten(hm($t['ende'])) - minuten(hm($t['beginn']));
            $ende = hm_aus_minuten(minuten($beginn) + $dauer);
            db_ausfuehren('UPDATE workshop_termin SET beginn = ?, ende = ? WHERE id = ?', [$beginn, $ende, $id]);
            db_ausfuehren('UPDATE programmpunkt SET geaendert_am = ? WHERE id = ?', [db_jetzt(), (int)$t['punkt_id']]);
            audit_schreiben('programm', 'Termin ' . $id . ' verschoben auf ' . $beginn, $kontoId);
            return "{$beginn}–{$ende}";
        }
        $p = db_zeile('SELECT * FROM programmpunkt WHERE id = ? AND veranstaltung_id = ?', [$id, va_id()]);
        if ($p === null || $p['typ'] === 'workshop') {
            return null;
        }
        $alt = hm($p['beginn']);
        $dauer = $alt !== '' ? minuten(hm($p['ende'])) - minuten($alt) : 30;
        $ende = hm_aus_minuten(minuten($beginn) + $dauer);
        if ($p['typ'] === 'block' && $alt !== '') {
            // Inhalte mitnehmen
            $versatz = minuten($beginn) - minuten($alt);
            $altEnde = hm($p['ende']);
            // im Zeitfenster (ohne andere Zuordnung) oder ausdrücklich zugeordnet
            foreach (db_zeilen('SELECT t.*, p.block_id FROM workshop_termin t JOIN programmpunkt p ON p.id = t.punkt_id WHERE p.veranstaltung_id = ?', [va_id()]) as $t) {
                $zu = $t['block_id'] !== null ? (int)$t['block_id'] === $id : (hm($t['beginn']) >= $alt && hm($t['ende']) <= $altEnde);
                if ($zu) {
                    db_ausfuehren('UPDATE workshop_termin SET beginn = ?, ende = ? WHERE id = ?', [hm_aus_minuten(minuten(hm($t['beginn'])) + $versatz), hm_aus_minuten(minuten(hm($t['ende'])) + $versatz), $t['id']]);
                    db_ausfuehren('UPDATE programmpunkt SET geaendert_am = ? WHERE id = ?', [db_jetzt(), (int)$t['punkt_id']]);
                }
            }
            foreach (db_zeilen("SELECT * FROM programmpunkt WHERE veranstaltung_id = ? AND typ IN ('vortrag', 'fall', 'station') AND beginn IS NOT NULL", [va_id()]) as $s) {
                $zu = $s['block_id'] !== null ? (int)$s['block_id'] === $id : ($s['typ'] === 'station' && hm($s['beginn']) >= $alt && hm($s['ende']) <= $altEnde);
                if ($zu) {
                    db_ausfuehren('UPDATE programmpunkt SET beginn = ?, ende = ?, geaendert_am = ? WHERE id = ?', [hm_aus_minuten(minuten(hm($s['beginn'])) + $versatz), hm_aus_minuten(minuten(hm($s['ende'])) + $versatz), db_jetzt(), $s['id']]);
                }
            }
        }
        db_ausfuehren('UPDATE programmpunkt SET beginn = ?, ende = ?, geaendert_am = ? WHERE id = ?', [$beginn, $ende, db_jetzt(), $id]);
        audit_schreiben('programm', 'Programmpunkt ' . $id . ' verschoben auf ' . $beginn, $kontoId);
        // Im Block läuft ein Punkt parallel: dann rückt die Hauptspur nicht nach
        if ($nachruecken && $p['block_id'] === null && in_array($p['typ'], PROGRAMM_HAUPTSPUR, true)) {
            $GLOBALS['programm_nachgerueckt'] = programm_nachruecken_kette($id, $beginn, $ende, $kontoId);
        }
        return "{$beginn}–{$ende}";
    });
}

/* ---- Veröffentlichen und Verwerfen --------------------------------------- */

/** Was vor dem Veröffentlichen fehlt (Liste von Texten). */
function programm_vor_veroeffentlichen(array $punkte): array
{
    $f = [];
    foreach ($punkte as $p) {
        $name = trim($p['kuerzel'] . ' ' . $p['titel']);
        if ($p['typ'] === 'workshop' && $p['termine'] === []) {
            $f[] = "„{$name}“ hat noch keinen Termin.";
        } elseif ($p['typ'] !== 'workshop' && $p['beginn'] === '') {
            $f[] = "„{$name}“ hat noch keine Uhrzeit – im Plan an seine Stelle ziehen oder Beginn und Ende eintragen.";
        }
    }
    return $f;
}

/** Arbeitsstand veröffentlichen. Gibt eine Fehlerliste zurück (leer = veröffentlicht). */
function programm_veroeffentlichen(?int $kontoId): array
{
    // Unter der Platzsperre: zwischen Prüfung und Veröffentlichen bucht niemand (R-04)
    return anm_sperre(function () use ($kontoId): array {
        $punkte = programm_arbeitsstand();
        $f = programm_vor_veroeffentlichen($punkte);
        // Termine mit vergebenen Plätzen müssen bleiben, mit mindestens so vielen Plätzen
        $neu = [];
        foreach ($punkte as $p) {
            foreach ($p['termine'] as $t) {
                $neu[(int)$t['id']] = (int)$t['plaetze'];
            }
        }
        foreach (db_zeilen("SELECT termin_id, COUNT(*) AS n FROM platz WHERE veranstaltung_id = ? AND termin_id <> 0 AND status IN ('fest','angebot') GROUP BY termin_id", [va_id()]) as $b) {
            $tid = (int)$b['termin_id'];
            if (!isset($neu[$tid])) {
                $f[] = 'Ein Workshop-Termin mit ' . $b['n'] . ' vergebenen Plätzen fehlt im Arbeitsstand. Zuerst die Personen umbuchen.';
            } elseif ($neu[$tid] < (int)$b['n']) {
                $f[] = 'Ein Workshop-Termin hat ' . $neu[$tid] . ' Plätze, aber schon ' . $b['n'] . ' vergeben.';
            }
        }
        if ($f !== []) {
            return $f;
        }
        db_ausfuehren(
            'INSERT INTO programm_stand (id, daten, veroeffentlicht_am, veroeffentlicht_von) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE daten = VALUES(daten), veroeffentlicht_am = VALUES(veroeffentlicht_am), veroeffentlicht_von = VALUES(veroeffentlicht_von)',
            [va_id(), json_encode($punkte, JSON_UNESCAPED_UNICODE), db_jetzt(), $kontoId]
        );
        // Wartelisten entfallener Termine aufräumen
        $platz = $neu === [] ? '0' : implode(',', array_keys($neu));
        $weg = db_ausfuehren("DELETE FROM platz WHERE veranstaltung_id = ? AND termin_id <> 0 AND termin_id NOT IN ($platz)", [va_id()])->rowCount();
        anm_workshops(true);
        audit_schreiben('programm', 'Programm veröffentlicht (' . count($punkte) . ' Punkte' . ($weg > 0 ? ", $weg Wartelisten-Einträge entfallener Termine entfernt" : '') . ')', $kontoId);
        return [];
    });
}

/**
 * Workshop oder Station einem Block zuordnen (null: lösen). Rückmeldung
 * 2026-10-30: im Plan auf den Block ziehen. Gibt false zurück, wenn es nicht geht.
 */
function programm_zuordnen(int $punktId, ?int $blockId, ?int $kontoId): bool
{
    $p = db_zeile('SELECT id, typ, block_id FROM programmpunkt WHERE id = ? AND veranstaltung_id = ?', [$punktId, va_id()]);
    if ($p === null || !in_array($p['typ'], PROGRAMM_IM_BLOCK, true)) {
        return false;
    }
    if ($blockId !== null && db_wert("SELECT COUNT(*) FROM programmpunkt WHERE id = ? AND typ = 'block' AND veranstaltung_id = ?", [$blockId, va_id()]) != 1) {
        return false;
    }
    db_ausfuehren('UPDATE programmpunkt SET block_id = ?, geaendert_am = ? WHERE id = ?', [$blockId, db_jetzt(), $punktId]);
    audit_schreiben('programm', 'Programmpunkt ' . $punktId . ($blockId !== null ? ' dem Block ' . $blockId . ' zugeordnet' : ' aus dem Block gelöst'), $kontoId);
    return true;
}

/** Arbeitsstand auf den veröffentlichten Stand zurücksetzen. */
function programm_verwerfen(?int $kontoId): void
{
    $pub = programm_veroeffentlicht();
    $punkte = $pub['punkte'] ?? [];
    db_transaktion(function () use ($punkte): void {
        db_ausfuehren('DELETE FROM programmpunkt WHERE veranstaltung_id = ?', [va_id()]);
        $jetzt = db_jetzt();
        foreach ($punkte as $p) {
            db_ausfuehren(
                'INSERT INTO programmpunkt (id, veranstaltung_id, typ, block_id, kuerzel, titel, untertitel, beschreibung, ort, beginn, ende, bring, notiz, erstellt_am, geaendert_am) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$p['id'], va_id(), $p['typ'], $p['block_id'] ?? null, $p['kuerzel'], $p['titel'], $p['untertitel'], $p['beschreibung'], $p['ort'], $p['beginn'] ?: null, $p['ende'] ?: null, $p['bring'], $p['notiz'], $jetzt, $jetzt]
            );
            foreach ($p['termine'] as $t) {
                db_ausfuehren('INSERT INTO workshop_termin (id, punkt_id, beginn, ende, plaetze) VALUES (?, ?, ?, ?, ?)', [$t['id'], $p['id'], $t['beginn'], $t['ende'], $t['plaetze']]);
                foreach ($t['dozenten'] ?? [] as $pos => $d) {
                    db_ausfuehren(
                        'INSERT INTO dozent (id, name, funktion, kurzbio, foto, erstellt_am, geaendert_am) VALUES (?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE name = VALUES(name), funktion = VALUES(funktion), kurzbio = VALUES(kurzbio), foto = VALUES(foto)',
                        [$d['id'], $d['name'], $d['funktion'], $d['kurzbio'], $d['foto'], $jetzt, $jetzt]
                    );
                    db_ausfuehren('INSERT INTO termin_dozent (termin_id, dozent_id, pos) VALUES (?, ?, ?)', [$t['id'], $d['id'], $pos]);
                }
            }
            foreach ($p['dozenten'] as $pos => $d) {
                // Dozierende zurück auf den veröffentlichten Stand (neu anlegen, falls gelöscht)
                db_ausfuehren(
                    'INSERT INTO dozent (id, name, funktion, kurzbio, foto, erstellt_am, geaendert_am) VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE name = VALUES(name), funktion = VALUES(funktion), kurzbio = VALUES(kurzbio), foto = VALUES(foto)',
                    [$d['id'], $d['name'], $d['funktion'], $d['kurzbio'], $d['foto'], $jetzt, $jetzt]
                );
                db_ausfuehren('INSERT INTO punkt_dozent (punkt_id, dozent_id, pos) VALUES (?, ?, ?)', [$p['id'], $d['id'], $pos]);
            }
        }
    });
    audit_schreiben('programm', 'Änderungen am Programm verworfen', $kontoId);
}

/** Gehört der Programmpunkt zur Veranstaltung des Kontexts? (Schutz vor Eingriffen in andere Veranstaltungen) */
function programm_punkt_im_kontext(int $id): bool
{
    return (int)db_wert('SELECT COUNT(*) FROM programmpunkt WHERE id = ? AND veranstaltung_id = ?', [$id, va_id()]) === 1;
}

/** Arbeitsstand einer Veranstaltung in eine andere kopieren (neue IDs, Blöcke und Dozierende mit), E-58. */
function programm_kopieren(int $von, int $nach): void
{
    $karte = [];
    $jetzt = db_jetzt();
    $punkte = db_zeilen('SELECT * FROM programmpunkt WHERE veranstaltung_id = ? ORDER BY id', [$von]);
    foreach ($punkte as $p) {
        db_ausfuehren(
            'INSERT INTO programmpunkt (veranstaltung_id, typ, block_id, kuerzel, titel, untertitel, beschreibung, ort, beginn, ende, bring, notiz, erstellt_am, geaendert_am) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$nach, $p['typ'], $p['kuerzel'], $p['titel'], $p['untertitel'], $p['beschreibung'], $p['ort'], $p['beginn'], $p['ende'], $p['bring'], $p['notiz'], $jetzt, $jetzt]
        );
        $karte[(int)$p['id']] = (int)db()->lastInsertId();
    }
    foreach ($punkte as $p) {
        $neu = $karte[(int)$p['id']];
        if ($p['block_id'] !== null && isset($karte[(int)$p['block_id']])) {
            db_ausfuehren('UPDATE programmpunkt SET block_id = ? WHERE id = ?', [$karte[(int)$p['block_id']], $neu]);
        }
        db_ausfuehren('INSERT INTO punkt_dozent (punkt_id, dozent_id, pos) SELECT ?, dozent_id, pos FROM punkt_dozent WHERE punkt_id = ?', [$neu, $p['id']]);
        foreach (db_zeilen('SELECT * FROM workshop_termin WHERE punkt_id = ? ORDER BY id', [$p['id']]) as $t) {
            db_ausfuehren('INSERT INTO workshop_termin (punkt_id, beginn, ende, plaetze) VALUES (?, ?, ?, ?)', [$neu, $t['beginn'], $t['ende'], $t['plaetze']]);
            $tid = (int)db()->lastInsertId();
            db_ausfuehren('INSERT INTO termin_dozent (termin_id, dozent_id, pos) SELECT ?, dozent_id, pos FROM termin_dozent WHERE termin_id = ?', [$tid, $t['id']]);
        }
    }
}

/* ---- Dozierende ---------------------------------------------------------- */

function dozent_liste(): array
{
    return db_zeilen('SELECT d.*, (SELECT COUNT(*) FROM punkt_dozent pd WHERE pd.dozent_id = d.id) AS punkte FROM dozent d ORDER BY d.name');
}

function dozent_pruefen(array $e): array
{
    $w = [
        'name' => trim(preg_replace('/\s+/u', ' ', (string)($e['name'] ?? '')) ?? ''),
        'funktion' => trim(preg_replace('/\s+/u', ' ', (string)($e['funktion'] ?? '')) ?? ''),
        'kurzbio' => editor_bereinigen((string)($e['kurzbio'] ?? ''), 'block', true),
    ];
    $f = [];
    if ($w['name'] === '' || mb_strlen($w['name']) > 120) {
        $f['name'] = 'Bitte einen Namen angeben (höchstens 120 Zeichen).';
    }
    if (mb_strlen($w['funktion']) > 160) {
        $f['funktion'] = 'Höchstens 160 Zeichen.';
    }
    if (mb_strlen(editor_text($w['kurzbio'])) > 1000) {
        $f['kurzbio'] = 'Höchstens 1000 Zeichen.';
    }
    return ['werte' => $w, 'fehler' => $f];
}

function dozent_speichern(?int $id, array $w, ?int $kontoId): int
{
    $jetzt = db_jetzt();
    if ($id === null) {
        db_ausfuehren('INSERT INTO dozent (name, funktion, kurzbio, erstellt_am, geaendert_am) VALUES (?, ?, ?, ?, ?)', [$w['name'], $w['funktion'], $w['kurzbio'], $jetzt, $jetzt]);
        $id = (int)db()->lastInsertId();
    } else {
        db_ausfuehren('UPDATE dozent SET name = ?, funktion = ?, kurzbio = ?, geaendert_am = ? WHERE id = ?', [$w['name'], $w['funktion'], $w['kurzbio'], $jetzt, $id]);
    }
    audit_schreiben('programm', 'Dozent·in ' . $id . ' gespeichert', $kontoId);
    return $id;
}

function dozent_loeschen(int $id, ?int $kontoId): void
{
    db_ausfuehren('DELETE FROM dozent WHERE id = ?', [$id]);
    audit_schreiben('programm', 'Dozent·in ' . $id . ' gelöscht', $kontoId);
}

/* ---- Fotos der Dozierenden ----------------------------------------------- */

const FOTO_MAX_BYTES = 8 * 1024 * 1024;
const FOTO_KANTE = 480;

function foto_moeglich(): bool
{
    return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
}

function foto_ordner(): string
{
    return laufzeit_pfad('bilder');
}

/**
 * Hochgeladenes Bild prüfen, quadratisch zuschneiden, auf FOTO_KANTE verkleinern
 * und als JPEG neu schreiben (entfernt Metadaten). Gibt den Dateinamen zurück
 * oder wirft eine RuntimeException mit einem Text für die Oberfläche.
 */
function foto_speichern(int $dozentId, array $upload): string
{
    if (!foto_moeglich()) {
        throw new RuntimeException('Fotos werden auf diesem Server nicht unterstützt (PHP-Erweiterung GD fehlt).');
    }
    $fehler = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($fehler === UPLOAD_ERR_INI_SIZE || $fehler === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('Das Foto ist zu groß.');
    }
    if ($fehler !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
        throw new RuntimeException('Das Foto kam nicht an. Bitte noch einmal versuchen.');
    }
    if ((int)$upload['size'] > FOTO_MAX_BYTES) {
        throw new RuntimeException('Das Foto ist zu groß (höchstens 8 MB).');
    }
    $daten = (string)file_get_contents((string)$upload['tmp_name']);
    $info = @getimagesizefromstring($daten);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] * $info[1] > 40_000_000) {
        throw new RuntimeException('Bitte ein Foto als JPG, PNG oder WebP hochladen.');
    }
    $bild = @imagecreatefromstring($daten);
    if ($bild === false) {
        throw new RuntimeException('Das Foto lässt sich nicht lesen.');
    }
    $w = imagesx($bild);
    $h = imagesy($bild);
    $seite = min($w, $h);
    $ziel = imagecreatetruecolor(FOTO_KANTE, FOTO_KANTE);
    imagecopyresampled($ziel, $bild, 0, 0, intdiv($w - $seite, 2), intdiv($h - $seite, 2), FOTO_KANTE, FOTO_KANTE, $seite, $seite);
    $name = 'dozent-' . $dozentId . '-' . bin2hex(random_bytes(6)) . '.jpg';
    if (!imagejpeg($ziel, foto_ordner() . '/' . $name, 85)) {
        throw new RuntimeException('Das Foto konnte nicht gespeichert werden.');
    }
    imagedestroy($bild);
    imagedestroy($ziel);
    db_ausfuehren('UPDATE dozent SET foto = ?, geaendert_am = ? WHERE id = ?', [$name, db_jetzt(), $dozentId]);
    return $name;
}

function foto_entfernen(int $dozentId): void
{
    db_ausfuehren('UPDATE dozent SET foto = NULL, geaendert_am = ? WHERE id = ?', [db_jetzt(), $dozentId]);
}

/** Fotos löschen, die weder im Arbeitsstand noch im veröffentlichten Stand vorkommen (Job 'aufraeumen'). */
function foto_aufraeumen(): int
{
    if (!db_tabelle_da('dozent')) {
        return 0;
    }
    // Genutzt: Dozierende und alle veröffentlichten Stände aller Veranstaltungen, auch
    // die Dozierenden an Terminen – Archivseiten zeigen sie weiter (Review B-8)
    $genutzt = array_filter(array_column(db_zeilen('SELECT foto FROM dozent'), 'foto'));
    $sammeln = function (mixed $knoten) use (&$sammeln, &$genutzt): void {
        if (!is_array($knoten)) {
            return;
        }
        foreach ($knoten as $schluessel => $wert) {
            if ($schluessel === 'foto' && is_string($wert) && $wert !== '') {
                $genutzt[] = $wert;
            } else {
                $sammeln($wert);
            }
        }
    };
    foreach (db_zeilen('SELECT daten FROM programm_stand') as $z) {
        $sammeln(json_decode((string)$z['daten'], true));
    }
    $n = 0;
    foreach (glob(foto_ordner() . '/dozent-*.jpg') ?: [] as $datei) {
        if (!in_array(basename($datei), $genutzt, true) && filemtime($datei) < time() - 3600 && @unlink($datei)) {
            $n++;
        }
    }
    return $n;
}
