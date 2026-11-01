<?php
declare(strict_types=1);

/*
 * Grep-Tests für die Code-Konventionen (Konzept Abschnitt 5a, AP-17).
 * Wird von lauf.php eingebunden (Teil ohne Datenbank).
 *
 * Geprüft werden nur Texte: Zeichenketten und HTML in PHP, Zeichenketten in
 * JavaScript – keine Kommentare. Ausnahmen stehen in den Listen unten, je mit
 * Begründung; Ziel ist, dass sie leer sind.
 */

/** Texte einer PHP-Datei: [[zeile, text], …] ohne Kommentare. */
function konv_php_texte(string $datei): array
{
    $texte = [];
    foreach (token_get_all((string)file_get_contents($datei)) as $t) {
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $texte[] = [$t[2], substr($t[1], 1, -1)];
        } elseif ($t[0] === T_INLINE_HTML || $t[0] === T_ENCAPSED_AND_WHITESPACE) {
            $texte[] = [$t[2], $t[1]];
        }
    }
    return $texte;
}

/** Zeichenketten einer JS-Datei: [[zeile, text], …]; Kommentare werden vorher entfernt. */
function konv_js_texte(string $datei): array
{
    $quelle = (string)file_get_contents($datei);
    $texte = [];
    // Kommentare und Zeichenketten in einem Durchgang, damit // in Zeichenketten nicht stört
    preg_match_all('~/\*.*?\*/|//[^\n]*|\'(?:[^\'\\\\\n]|\\\\.)*\'|"(?:[^"\\\\\n]|\\\\.)*"|`(?:[^`\\\\]|\\\\.)*`~s', $quelle, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as [$x, $pos]) {
        if ($x[0] === '/') {
            continue;
        }
        $texte[] = [substr_count($quelle, "\n", 0, $pos) + 1, substr($x, 1, -1)];
    }
    return $texte;
}

/** Alle Text-Dateien der Oberfläche: [relativer Pfad => [[zeile, text], …]]. */
function konv_alle_texte(string $wurzel): array
{
    $alle = [];
    foreach (['server/seiten', 'server/lib', 'server/pdf'] as $ordner) {
        if (!is_dir("$wurzel/$ordner")) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$wurzel/$ordner", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = str_replace('\\', '/', $f->getPathname());
            if (str_ends_with($p, '.php') && !str_contains($p, '/vendor/')) {
                $alle[substr($p, strlen($wurzel) + 1)] = konv_php_texte($p);
            }
        }
    }
    foreach (glob("$wurzel/server/public/assets/*.js") ?: [] as $p) {
        $alle[substr($p, strlen($wurzel) + 1)] = konv_js_texte($p);
    }
    ksort($alle);
    return $alle;
}

/**
 * Sucht ein Muster in allen Texten. Gibt Fundstellen „datei:zeile: auszug“ zurück,
 * ohne die Ausnahmen (Schlüssel „datei|auszug“).
 */
function konv_suchen(array $alle, string $muster, array $ausnahmen = []): array
{
    $funde = [];
    foreach ($alle as $datei => $texte) {
        foreach ($texte as [$zeile, $text]) {
            if (!preg_match_all($muster, $text, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($m[0] as [$x, $pos]) {
                $auszug = trim($x);
                if (isset($ausnahmen["$datei|$auszug"])) {
                    continue;
                }
                $funde[] = $datei . ':' . ($zeile + substr_count($text, "\n", 0, $pos)) . ': ' . $auszug;
            }
        }
    }
    return $funde;
}

function konv_melden(array $funde, string $was): void
{
    pruefe($funde === [], $was . ($funde === [] ? '' : ': ' . count($funde) . ' Fundstelle(n)'));
    foreach (array_slice($funde, 0, 10) as $f) {
        echo "          $f\n";
    }
}

/* ---- Ausnahmen (K-f): leert AP-17 Unterpunkt 4 ---------------------------- */

const KONV_WORT_AUSNAHMEN = [
    // Stand vor AP-17 Unterpunkt 4; die Seiten werden dort umgestellt
    'server/seiten/konto.php|Ja, Konto löschen',
    'server/seiten/team_personen.php|Von der Liste nehmen',
    'server/seiten/team_personen.php|Ja, Konto löschen',
    'server/seiten/team_veranstaltungen.php|Endgültig löschen',
    'server/seiten/team_workshops.php|Von der Liste nehmen',
];

function konventionen_pruefen(string $wurzel): void
{
    $alle = konv_alle_texte($wurzel);
    pruefe(count($alle) > 80, 'Konventionen: Texte aus ' . count($alle) . ' Dateien gelesen');

    // K-g Typografie
    konv_melden(konv_suchen($alle, '/\p{L}[^\n.]{0,20}(?<!\.)\.\.\.(?!\.)/u'), 'K-g: Auslassung als „…“, nicht „...“');
    konv_melden(konv_suchen($alle, '/\p{L}{2,} - \p{L}{2,}/u'), 'K-g: Gedankenstrich „ – “, nicht „ - “');
    konv_melden(konv_suchen($alle, '/„[^“"\n]{1,80}"/u'), 'K-g: „ wird mit “ geschlossen, nicht mit "');

    // K-f Wortliste
    $verboten = ['Endgültig löschen', 'Ja, Konto löschen', 'Von der Liste nehmen'];
    $muster = '/' . implode('|', array_map(fn($w) => preg_quote($w, '/'), $verboten)) . '/u';
    $ausnahmen = array_fill_keys(KONV_WORT_AUSNAHMEN, true);
    konv_melden(konv_suchen($alle, $muster, $ausnahmen), 'K-f: Wortliste (' . implode(', ', $verboten) . ' nicht verwenden)');
    pruefe(count(KONV_WORT_AUSNAHMEN) <= 5, 'K-f: Ausnahmeliste der Wortliste (' . count(KONV_WORT_AUSNAHMEN) . ' Einträge)');
}
