<?php
declare(strict_types=1);

/*
 * Pflegbare Texte der öffentlichen Seiten (Startseite, Impressum,
 * Datenschutz). Gespeichert in der Tabelle inhalt als Text mit Auszeichnung.
 *
 * Auszeichnung: eine Teilmenge von CommonMark (https://commonmark.org, der
 * Standard hinter Markdown; Rückmeldung 2026-10-30), ohne HTML:
 *   Leerzeile                 neuer Absatz; ein Zeilenumbruch bleibt erhalten
 *                             (wie in GitHub-Kommentaren)
 *   # / ## Überschrift        Zwischenüberschrift (### bis ###### kleiner)
 *   - Punkt  (auch * und +)   Aufzählung
 *   1. Punkt (auch 1))        nummerierte Liste
 *   **fett** / __fett__       fett
 *   *kursiv* / _kursiv_       kursiv (in Titeln: Akzentfarbe)
 *   [Text](https://…)         Link (nur https und mailto)
 *   ![Beschreibung](/bild/…)  Bild – nur hochgeladene Bilder dieser Webseite
 *   ---                       Trennlinie
 *   \*                        Zeichen ohne Wirkung (Backslash davor)
 */

/** Text eines Schlüssels oder '' (fehlt die Tabelle noch, ebenfalls ''). */
function inhalt(string $schluessel): string
{
    static $cache = [];
    if (!array_key_exists($schluessel, $cache)) {
        $cache[$schluessel] = (string)(db_wert('SELECT text FROM inhalt WHERE schluessel = ?', [$schluessel]) ?? '');
    }
    return $cache[$schluessel];
}

function inhalt_setzen(string $schluessel, string $text, ?int $kontoId = null): void
{
    db_ausfuehren(
        'INSERT INTO inhalt (schluessel, text, geaendert_am, geaendert_von) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE text = VALUES(text), geaendert_am = VALUES(geaendert_am), geaendert_von = VALUES(geaendert_von)',
        [$schluessel, $text, db_jetzt(), $kontoId]
    );
}

/** Pfad eines hochgeladenen Text-Bildes (öffentlich unter /bild/). */
const TEXT_BILD_MUSTER = '#^/bild/text-[0-9a-f]{16}\.(jpg|png)$#';

/** Inline-Auszeichnung (CommonMark-Teilmenge): Text wird zuerst maskiert, dann ersetzt. */
function text_inline(string $text): string
{
    // Maskierte Zeichen (\* \_ \[ …) vorübergehend durch Platzhalter ersetzen
    $esc = [];
    $text = preg_replace_callback('/\\\\([\\\\`*_{}\[\]()#+\-.!>])/', function (array $m) use (&$esc): string {
        $esc[] = $m[1];
        return "\u{E000}" . (count($esc) - 1) . "\u{E001}";
    }, $text) ?? $text;
    $h = e($text);
    // Bilder: nur eigene, hochgeladene
    $h = preg_replace_callback(
        '/!\[([^\]\n]{0,200})\]\(([^\s)<>"]{1,200})\)/u',
        fn(array $m) => preg_match(TEXT_BILD_MUSTER, $m[2]) === 1 ? '<img class="text-bild" src="' . $m[2] . '" alt="' . $m[1] . '" loading="lazy">' : $m[0],
        $h
    ) ?? $h;
    // Links: nur https und mailto (unverschlüsselte http-Links bewusst nicht)
    $h = preg_replace_callback(
        '/\[([^\]\n]{1,200})\]\(((?:https:\/\/|mailto:)[^\s)<>"]{1,500})\)/u',
        fn(array $m) => '<a href="' . $m[2] . '"' . (str_starts_with($m[2], 'https://') ? ' rel="noopener"' : '') . '>' . $m[1] . '</a>',
        $h
    ) ?? $h;
    $h = preg_replace('/\*\*(?=\S)([^*\n]*?\S)\*\*/u', '<strong>$1</strong>', $h) ?? $h;
    $h = preg_replace('/(?<![\w_])__(?=\S)([^_\n]*?\S)__(?![\w_])/u', '<strong>$1</strong>', $h) ?? $h;
    $h = preg_replace('/(?<![*\w])\*(?=\S)([^*\n]*?\S)\*(?![*\w])/u', '<em>$1</em>', $h) ?? $h;
    $h = preg_replace('/(?<![\w_])_(?=\S)([^_\n]*?\S)_(?![\w_])/u', '<em>$1</em>', $h) ?? $h;
    return preg_replace_callback("/\u{E000}(\\d+)\u{E001}/u", fn(array $m) => e($esc[(int)$m[1]] ?? ''), $h) ?? $h;
}

/** Ganzer Text zu HTML (Absätze, Überschriften, Listen, Bilder). */
function text_zu_html(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') {
        return '';
    }
    $html = [];
    foreach (preg_split('/\n[ \t]*\n/', $text) as $block) {
        $zeilen = array_values(array_filter(explode("\n", $block), fn($z) => trim($z) !== ''));
        $absatz = [];
        $liste = null;
        $fertig = function () use (&$html, &$absatz, &$liste): void {
            if ($absatz !== []) {
                $html[] = '<p>' . implode('<br>', array_map('text_inline', $absatz)) . '</p>';
                $absatz = [];
            }
            if ($liste !== null) {
                $html[] = '<' . $liste['tag'] . '>' . implode('', array_map(fn($p) => '<li>' . text_inline($p) . '</li>', $liste['punkte'])) . '</' . $liste['tag'] . '>';
                $liste = null;
            }
        };
        foreach ($zeilen as $z) {
            $z = rtrim($z);
            if (preg_match('/^ {0,3}([-*_])( *\1){2,} *$/', $z) === 1) {
                // Trennlinie (---, ***, ___)
                $fertig();
                $html[] = '<hr>';
            } elseif (preg_match('/^ {0,3}(#{1,6})[ \t]+(.+?)(?:[ \t]+#+)?$/', $z, $m) === 1) {
                $fertig();
                $tag = strlen($m[1]) <= 2 ? 'h2' : 'h3';
                $html[] = "<$tag>" . text_inline($m[2]) . "</$tag>";
            } elseif (preg_match('/^ {0,3}([-*+])[ \t]+(.*)$/', $z, $m) === 1 && preg_match('/^ {0,3}([-*_])( *\1){2,} *$/', $z) !== 1) {
                if ($absatz !== [] || ($liste !== null && $liste['tag'] !== 'ul')) {
                    $fertig();
                }
                $liste ??= ['tag' => 'ul', 'punkte' => []];
                $liste['punkte'][] = $m[2];
            } elseif (preg_match('/^ {0,3}\d{1,9}[.)][ \t]+(.*)$/', $z, $m) === 1) {
                if ($absatz !== [] || ($liste !== null && $liste['tag'] !== 'ol')) {
                    $fertig();
                }
                $liste ??= ['tag' => 'ol', 'punkte' => []];
                $liste['punkte'][] = $m[1];
            } elseif ($liste !== null && preg_match('/^[ \t]{2,}(\S.*)$/', $z, $m) === 1) {
                // eingerückte Fortsetzung eines Listenpunkts
                $liste['punkte'][count($liste['punkte']) - 1] .= ' ' . $m[1];
            } else {
                if ($liste !== null) {
                    $fertig();
                }
                $absatz[] = trim($z);
            }
        }
        $fertig();
    }
    return implode("\n", $html);
}

/**
 * Text in Abschnitte nach „## Überschrift“ zerlegen (für die Infokästen
 * der Startseite). Gibt [['titel' => …, 'text' => …], …] zurück.
 */
function text_abschnitte(string $text): array
{
    $teile = [];
    $aktuell = null;
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $zeile) {
        if (preg_match('/^ {0,3}##[ \t]+(.+?)[ \t]*$/', $zeile, $m) === 1) {
            if ($aktuell !== null) {
                $teile[] = $aktuell;
            }
            $aktuell = ['titel' => trim($m[1]), 'text' => ''];
            continue;
        }
        if ($aktuell === null) {
            $aktuell = ['titel' => '', 'text' => ''];
        }
        $aktuell['text'] .= $zeile . "\n";
    }
    if ($aktuell !== null) {
        $teile[] = $aktuell;
    }
    return array_values(array_filter(
        array_map(fn($t) => ['titel' => $t['titel'], 'text' => trim($t['text'])], $teile),
        fn($t) => $t['titel'] !== '' || $t['text'] !== ''
    ));
}
