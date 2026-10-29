<?php
declare(strict_types=1);

/*
 * Pflegbare Texte der öffentlichen Seiten (Startseite, Impressum,
 * Datenschutz). Gespeichert in der Tabelle inhalt als einfacher Text mit
 * wenigen Auszeichnungen; bearbeitet werden sie im Betreiber-Bereich (AP-5).
 *
 * Auszeichnung (bewusst klein, damit kein HTML eingeschleust werden kann):
 *   Leerzeile        neuer Absatz
 *   ## Überschrift   Zwischenüberschrift (### kleiner)
 *   - Punkt          Aufzählung
 *   **fett**         fett
 *   *Akzent*         hervorgehoben (in Titeln: Akzentfarbe)
 *   [Text](https://…) bzw. [Text](mailto:…)  Link
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

/** Inline-Auszeichnung: Text wird zuerst maskiert, dann **, * und Links ersetzt. */
function text_inline(string $text): string
{
    $h = e($text);
    // Links: nur https:// und mailto:
    $h = preg_replace_callback(
        '/\[([^\]\n]{1,200})\]\(((?:https:\/\/|mailto:)[^\s)<>"]{1,500})\)/u',
        fn(array $m) => '<a href="' . $m[2] . '"' . (str_starts_with($m[2], 'https://') ? ' rel="noopener"' : '') . '>' . $m[1] . '</a>',
        $h
    );
    $h = preg_replace('/\*\*([^*\n]+)\*\*/u', '<strong>$1</strong>', $h);
    $h = preg_replace('/(?<![*\w])\*([^*\n]+)\*(?![*\w])/u', '<em>$1</em>', $h);
    return $h;
}

/** Ganzer Text zu HTML (Absätze, Überschriften, Listen). */
function text_zu_html(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') {
        return '';
    }
    $html = [];
    foreach (preg_split('/\n{2,}/', $text) as $block) {
        $zeilen = explode("\n", $block);
        // Überschriften am Blockanfang abtrennen (auch ohne Leerzeile danach)
        while ($zeilen !== [] && preg_match('/^(#{2,3}) (.+)$/', $zeilen[0], $m) === 1) {
            $tag = strlen($m[1]) === 2 ? 'h2' : 'h3';
            $html[] = "<$tag>" . text_inline($m[2]) . "</$tag>";
            array_shift($zeilen);
        }
        if ($zeilen === []) {
            continue;
        }
        if (array_filter($zeilen, fn($z) => !str_starts_with($z, '- ')) === []) {
            $html[] = '<ul>' . implode('', array_map(fn($z) => '<li>' . text_inline(substr($z, 2)) . '</li>', $zeilen)) . '</ul>';
            continue;
        }
        $html[] = '<p>' . implode('<br>', array_map('text_inline', $zeilen)) . '</p>';
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
        if (preg_match('/^## (.+)$/', $zeile, $m) === 1) {
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
