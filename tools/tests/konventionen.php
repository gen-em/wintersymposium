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

/* ---- Ausnahmen (K-f): seit AP-17 Unterpunkt 4 leer ------------------------ */

const KONV_WORT_AUSNAHMEN = [
];

/** Klassen, die nur dynamisch entstehen oder JS/Tests als Haken dienen (V-29); je mit Grund. */
const KONV_CSS_OHNE_VORKOMMEN = [
    'akzent-rot' => 'seite_kopf setzt akzent-<farbe>',
    'dlg-gruen' => 'basis.js setzt dlg-<art>',
    'dlg-lila' => 'basis.js setzt dlg-<art>',
];
const KONV_KLASSE_OHNE_CSS = [
    'dlg-nein' => 'Haken in basis.js',
    'personen' => 'Haken im Browser-Durchlauf (table.personen)',
    'rm-form' => 'Haken in mailings.js',
    'vorlage' => 'Haken in programm-team.js',
    'vor' => 'Vergleichswert in programm-team.js (wo === \'vor\'), keine Klasse',
];

/** Klassen aus class="…"-Angaben in PHP und JS (nur feste Teile, dynamische Stücke entfallen). */
function konv_klassen_im_code(string $wurzel): array
{
    $dateien = array_merge(glob("$wurzel/server/seiten/*.php") ?: [], glob("$wurzel/server/lib/*.php") ?: [], glob("$wurzel/server/public/assets/*.js") ?: []);
    $k = [];
    foreach ($dateien as $p) {
        $q = (string)file_get_contents($p);
        preg_match_all('/class(?:Name)?\s*=\s*\\?"([^"]*)\\?"|class=\'([^\']*)\'|classList\.(?:add|remove|toggle)\(([^)]*)\)|\'(?:knopf|klasse|knopfklasse|form_klasse|ja_klasse)\'\s*=>\s*\'([^\']*)\'/', $q, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            if (($x[3] ?? '') !== '') {
                // classList.add/remove/toggle: nur die Zeichenketten, keine Ausdrücke
                preg_match_all('/"([^"]*)"|\'([^\']*)\'/', $x[3], $lit);
                $teil = implode(' ', array_merge($lit[1], $lit[2]));
            } else {
                $teil = $x[1] !== '' ? $x[1] : (($x[2] ?? '') !== '' ? $x[2] : ($x[4] ?? ''));
            }
            // dynamische Stücke entfernen (PHP-Ausgaben, Verkettungen in PHP und JS)
            $teil = (string)preg_replace(['/<\?=.*?\?>/s', '/\'\s*\..*?\.\s*\'/s', '/"\s*\+.*?\+\s*"/s'], ' ', $teil);
            foreach (preg_split('/[\s,"\']+/', $teil) ?: [] as $kl) {
                if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $kl) === 1) {
                    $k[$kl] = true;
                }
            }
        }
    }
    return array_keys($k);
}

function konventionen_pruefen(string $wurzel): void
{
    $alle = konv_alle_texte($wurzel);
    pruefe(count($alle) > 80, 'Konventionen: Texte aus ' . count($alle) . ' Dateien gelesen');

    // K-g Typografie
    konv_melden(konv_suchen($alle, '/\p{L}[^\n.]{0,20}(?<!\.)\.\.\.(?!\.)/u'), 'K-g: Auslassung als „…“, nicht „...“');
    konv_melden(konv_suchen($alle, '/\p{L}{2,} - \p{L}{2,}/u'), 'K-g: Gedankenstrich „ – “, nicht „ - “');
    konv_melden(konv_suchen($alle, '/„[^“"\n]{1,80}"/u'), 'K-g: „ wird mit “ geschlossen, nicht mit "');

    // K-c Zeit: gmdate nur in db_lib und an wenigen begründeten Stellen (Dateinamen, Zeitstempel in Dateien)
    $gm = 0;
    foreach (glob("$wurzel/server/lib/*.php") ?: [] as $p) {
        if (basename($p) !== 'db_lib.php') {
            $gm += preg_match_all('/\bgmdate\(/', (string)file_get_contents($p));
        }
    }
    pruefe($gm <= 5, "K-c: gmdate außerhalb von db_lib höchstens 5× ($gm)");

    // K-d und Unterpunkt 4: Seiten nutzen Lib-Funktionen und die Helfer aus formular_lib
    $dbInSeiten = [];
    $closures = [];
    $einknopf = [];
    foreach (glob("$wurzel/server/seiten/*.php") ?: [] as $p) {
        $quelle = (string)file_get_contents($p);
        $tok = token_get_all($quelle);
        foreach ($tok as $i => $t) {
            if (is_array($t) && $t[0] === T_STRING && str_starts_with($t[1], 'db_')) {
                $dbInSeiten[] = basename($p) . ':' . $t[2] . ': ' . $t[1];
            }
            if (is_array($t) && $t[0] === T_VARIABLE && in_array($t[1], ['$fehlerFeld', '$ungueltig'], true)) {
                $closures[] = basename($p) . ':' . $t[2] . ': ' . $t[1];
            }
        }
        if (preg_match_all('~<form[^>]*>\s*(?:<\?= csrf_feld\(\) \?>|\' \. csrf_feld\(\) \. \')\s*(?:<input type="hidden"[^>]*>\s*)+<button[^>]*>[^<]*</button>\s*</form>~', $quelle, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$x, $pos]) {
                $einknopf[] = basename($p) . ':' . (substr_count($quelle, "\n", 0, $pos) + 1);
            }
        }
    }
    konv_melden($dbInSeiten, 'K-d: keine db_*-Aufrufe in server/seiten');
    konv_melden($closures, 'Unterpunkt 4: keine Feld-Closures ($fehlerFeld, $ungueltig) in Seiten');
    konv_melden($einknopf, 'Unterpunkt 4: Einknopf-Formulare über aktion_knopf(), nicht von Hand');

    // Unterpunkt 5 (V-29): jede Klasse in site.css kommt in PHP/JS vor und umgekehrt
    $css = (string)preg_replace('~/\*.*?\*/~s', '', (string)file_get_contents("$wurzel/server/public/assets/site.css"));
    $selektoren = (string)preg_replace(['/\{[^{}]*\}/', '/url\([^)]*\)/'], ['{}', ''], $css);
    preg_match_all('/\.([a-z][a-z0-9-]*)/', (string)preg_replace('/\d*\.\d+/', '', $selektoren), $m);
    $cssKlassen = array_unique($m[1]);
    $code = '';
    foreach (array_merge(glob("$wurzel/server/seiten/*.php") ?: [], glob("$wurzel/server/lib/*.php") ?: [], glob("$wurzel/server/public/assets/*.js") ?: []) as $p) {
        $code .= (string)file_get_contents($p) . "\n";
    }
    $ohne = array_values(array_filter($cssKlassen, fn($k) => !isset(KONV_CSS_OHNE_VORKOMMEN[$k]) && preg_match('/(?<![\w-])' . preg_quote($k, '/') . '(?![\w-])/', $code) !== 1));
    konv_melden($ohne, 'V-29: jede Klasse in site.css wird in PHP/JS verwendet (' . count($cssKlassen) . ' Klassen)');
    $fehlt = array_values(array_filter(konv_klassen_im_code($wurzel), fn($k) => !in_array($k, $cssKlassen, true) && !isset(KONV_KLASSE_OHNE_CSS[$k])));
    konv_melden($fehlt, 'V-29: jede Klasse im Markup hat eine Regel in site.css');

    // K-f Wortliste
    $verboten = ['Endgültig löschen', 'Ja, Konto löschen', 'Von der Liste nehmen'];
    $muster = '/' . implode('|', array_map(fn($w) => preg_quote($w, '/'), $verboten)) . '/u';
    $ausnahmen = array_fill_keys(KONV_WORT_AUSNAHMEN, true);
    konv_melden(konv_suchen($alle, $muster, $ausnahmen), 'K-f: Wortliste (' . implode(', ', $verboten) . ' nicht verwenden)');
    pruefe(count(KONV_WORT_AUSNAHMEN) === 0, 'K-f: Ausnahmeliste der Wortliste (' . count(KONV_WORT_AUSNAHMEN) . ' Einträge)');
}
