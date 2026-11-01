<?php
declare(strict_types=1);

/*
 * Pflegbare Texte der öffentlichen Seiten (Startseite, Impressum,
 * Datenschutz) und alle Textfelder mit Editor (Rückmeldung 2026-10-30 (2),
 * E-54: „Nur ein Editor überall“ – derselbe wie bei den Mailings).
 *
 * Gespeichert wird bereinigtes HTML aus wenigen Elementen:
 *   Block-Felder:  p, h2, h3, ul, ol, li, strong, em, a, br, img (nur eigene
 *                  hochgeladene Bilder /bild/text-…, wo das Feld Bilder erlaubt)
 *   Inline-Felder: strong, em, a (Titel, Untertitel, Kicker …)
 * Links nur https und mailto. Alles andere wird zu Text. Beim Anzeigen wird
 * noch einmal bereinigt; ein Wert ohne „<“ (ältere Klartexte) gilt als Text
 * mit Absätzen.
 */

/** Texte der Startseite (start_…) gehören zur Veranstaltung (E-58); Impressum und Datenschutz sind gemeinsam (0). */
function inhalt_va(string $schluessel): int
{
    return str_starts_with($schluessel, 'start_') && va_tabellen_da() ? va_id() : 0;
}

/** Text eines Schlüssels oder '' (fehlt die Tabelle noch, ebenfalls ''). */
function inhalt(string $schluessel): string
{
    $va = inhalt_va($schluessel);
    $k = $va . ':' . $schluessel;
    if (!array_key_exists($k, $GLOBALS['ws_inhalt_cache'] ??= [])) {
        $GLOBALS['ws_inhalt_cache'][$k] = (string)(va_tabellen_da()
            ? db_wert('SELECT text FROM inhalt WHERE veranstaltung_id = ? AND schluessel = ?', [$va, $schluessel]) ?? ''
            : db_wert('SELECT text FROM inhalt WHERE schluessel = ?', [$schluessel]) ?? '');
    }
    return $GLOBALS['ws_inhalt_cache'][$k];
}

function inhalt_setzen(string $schluessel, string $text, ?int $kontoId = null): void
{
    $va = inhalt_va($schluessel);
    if (!va_tabellen_da()) {
        db_ausfuehren(
            'INSERT INTO inhalt (schluessel, text, geaendert_am, geaendert_von) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE text = VALUES(text), geaendert_am = VALUES(geaendert_am), geaendert_von = VALUES(geaendert_von)',
            [$schluessel, $text, db_jetzt(), $kontoId]
        );
        return;
    }
    db_ausfuehren(
        'INSERT INTO inhalt (veranstaltung_id, schluessel, text, geaendert_am, geaendert_von) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE text = VALUES(text), geaendert_am = VALUES(geaendert_am), geaendert_von = VALUES(geaendert_von)',
        [$va, $schluessel, $text, db_jetzt(), $kontoId]
    );
    unset($GLOBALS['ws_inhalt_cache'][$va . ':' . $schluessel]);
}

/** Pfad eines hochgeladenen Text-Bildes (öffentlich unter /bild/). */
const TEXT_BILD_MUSTER = '#^/bild/text-[0-9a-f]{16}\.(jpg|png)$#';

/** Größte Länge eines gespeicherten Editor-Inhalts. */
const EDITOR_MAX = 60000;

/**
 * Werkzeugleisten des Editors, an einer Stelle (Admin 2026-10-31: überall
 * gleich). Mehrzeilige Felder haben alle dieselbe Leiste; einzeilige Felder
 * (Titel, Untertitel) nur fett, kursiv und Link. Platzhalter gibt es nur in
 * Mails, weil nur dort beim Versand ersetzt wird.
 */
const EDITOR_LEISTE = ['block' => 'fett kursiv titel | liste nliste link bild knopf | html', 'inline' => 'fett kursiv link'];

/**
 * Attribute für ein <textarea> mit Editor. $mail: Bilder der Mailings,
 * Platzhalter und {link} als Knopf-Ziel; sonst Bilder der Webseite.
 */
function editor_attribute(string $art = 'block', bool $mail = false): string
{
    $art = $art === 'inline' ? 'inline' : 'block';
    $h = ' data-editor="' . $art . '" data-werkzeug="' . EDITOR_LEISTE[$art] . '"';
    if ($art === 'block') {
        $h .= ' data-bild-url="' . ($mail ? '/team/mailings/bild' : '/team/texte/bild') . '"';
    }
    if ($mail) {
        $h .= ' data-knopf-ziel="{link}" data-platzhalter="' . e(json_encode(RM_PLATZHALTER, JSON_UNESCAPED_UNICODE)) . '"';
    }
    return $h;
}

/**
 * Editor-HTML bereinigen. $art 'block' oder 'inline'; $bilder: eigene
 * Text-Bilder erlauben (nur in den Texten der Webseite).
 */
function editor_bereinigen(string $html, string $art = 'block', bool $bilder = false): string
{
    $html = trim(mb_substr(str_replace("\r", '', $html), 0, EDITOR_MAX));
    if ($html === '') {
        return '';
    }
    if (!str_contains($html, '<')) {
        // Klartext (ohne Skript abgeschickt oder älterer Wert): Absätze und Zeilen übernehmen
        if ($art === 'inline') {
            return e(preg_replace('/\s+/u', ' ', $html) ?? $html);
        }
        return implode('', array_map(fn($a) => '<p>' . str_replace("\n", '<br>', e(trim($a))) . '</p>', preg_split('/\n[ \t]*\n/', $html) ?: []));
    }
    $wurzel = rm_dom($html);
    if ($wurzel === null) {
        return '';
    }
    $h = editor_kinder($wurzel, $art, $bilder);
    $h = preg_replace('#(<br>\s*)+(</(p|h2|h3|li)>)#', '$2', $h) ?? $h;
    return trim($art === 'inline' ? (preg_replace('/\s+/u', ' ', $h) ?? $h) : $h);
}

function editor_kinder(DOMNode $n, string $art, bool $bilder): string
{
    $h = '';
    foreach ($n->childNodes as $c) {
        $h .= editor_knoten($c, $art, $bilder);
    }
    return $h;
}

function editor_knoten(DOMNode $n, string $art, bool $bilder): string
{
    if ($n instanceof DOMText) {
        return e($n->data);
    }
    if (!$n instanceof DOMElement) {
        return '';
    }
    $tag = strtolower($n->tagName);
    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'head', 'title', 'template', 'svg', 'math', 'form', 'input', 'button', 'select', 'textarea'], true)) {
        return '';
    }
    $tag = ['b' => 'strong', 'i' => 'em', 'h1' => 'h2', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3', 'div' => 'p'][$tag] ?? $tag;
    $innen = editor_kinder($n, $art, $bilder);
    if ($art === 'inline') {
        return match ($tag) {
            'strong', 'em' => trim(strip_tags($innen)) === '' ? $innen : "<$tag>$innen</$tag>",
            'a' => editor_link($n, $innen),
            'br', 'p', 'h2', 'h3', 'li' => ' ' . $innen . ' ',
            default => $innen,
        };
    }
    switch ($tag) {
        case 'p':
        case 'h2':
        case 'h3':
            if (preg_match('#<(p|h2|h3|ul|ol)[ >]#', $innen) === 1) {
                return $innen; // Absatz in Absatz (Chrome-Editor) auflösen
            }
            if (trim(strip_tags($innen, '<img>')) === '' && !str_contains($innen, '<img')) {
                return '';
            }
            return "<$tag>$innen</$tag>";
        case 'ul':
        case 'ol':
            return trim(strip_tags($innen)) === '' ? '' : "<$tag>$innen</$tag>";
        case 'li':
            return "<li>$innen</li>";
        case 'br':
            return '<br>';
        case 'strong':
        case 'em':
            return trim(strip_tags($innen)) === '' ? $innen : "<$tag>$innen</$tag>";
        case 'a':
            return editor_link($n, $innen, true);
        case 'img':
            $src = trim($n->getAttribute('src'));
            if (!$bilder || preg_match(TEXT_BILD_MUSTER, $src) !== 1) {
                return '';
            }
            $breite = $n->getAttribute('data-breite') === '50' ? '50' : '100';
            return '<img src="' . e($src) . '" alt="' . e(mb_substr(trim($n->getAttribute('alt')), 0, 200)) . '" data-breite="' . $breite . '">';
        default:
            return $innen;
    }
}

/**
 * Link nur mit https oder mailto, sonst bleibt der Text. $knopf: in Absätzen
 * bleibt die Klasse „knopf“ erhalten (Knopf wie in den Mailings, Admin 2026-10-31).
 */
function editor_link(DOMElement $n, string $innen, bool $knopf = false): string
{
    $href = trim($n->getAttribute('href'));
    if (preg_match('#^(https://[^\s<>"]+|mailto:[^\s<>"@]+@[^\s<>"]+)$#i', $href) !== 1 || trim(strip_tags($innen)) === '') {
        return $innen;
    }
    $klasse = $knopf && in_array('knopf', preg_split('/\s+/', $n->getAttribute('class')) ?: [], true) ? ' class="knopf"' : '';
    return '<a' . $klasse . ' href="' . e($href) . '"' . (str_starts_with(strtolower($href), 'https://') ? ' rel="noopener"' : '') . '>' . $innen . '</a>';
}

/** Gespeicherten Inhalt anzeigen (noch einmal bereinigt; Bilder mit Klasse für die Breite). */
function editor_html(string $s, string $art = 'block', bool $bilder = true): string
{
    $h = editor_bereinigen($s, $art, $bilder);
    return str_replace('<img ', '<img class="text-bild" loading="lazy" ', $h);
}

/** Klartext eines Editor-Inhalts (für Mails ohne HTML, Exporte, Längenprüfung). */
function editor_text(string $s): string
{
    $h = preg_replace(['#<br>#', '#</(p|h2|h3|li)>#'], ["\n", "\n"], editor_bereinigen($s)) ?? '';
    return trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Inhalt in Abschnitte nach Zwischenüberschriften (h2) zerlegen (Infokästen
 * der Startseite). Gibt [['titel' => Inline-HTML, 'text' => HTML], …] zurück.
 */
function editor_abschnitte(string $s): array
{
    $h = editor_bereinigen($s, 'block', true);
    $teile = preg_split('#<h2>(.*?)</h2>#s', $h, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $erg = [];
    if (trim($teile[0] ?? '') !== '') {
        $erg[] = ['titel' => '', 'text' => trim($teile[0])];
    }
    for ($i = 1; $i < count($teile); $i += 2) {
        $erg[] = ['titel' => $teile[$i], 'text' => trim($teile[$i + 1] ?? '')];
    }
    return $erg;
}

/* ---- Startseite einer Veranstaltung (Admin 2026-10-31) ------------------- */

/**
 * Texte der Startseite, bearbeitet unter Verwaltung → Einstellungen (eine
 * Seite für alles einer Veranstaltung). Zeile und Titel oben entstehen aus
 * den Einstellungen (Reihe · Kurzname, Name). Immer genau drei Kacheln.
 * Schlüssel => [Titel, Art: inline | block | text, Hilfe].
 */
const START_TEXTE = [
    'start_unter' => ['Untertitel', 'inline', 'Ein, zwei Sätze unter dem Titel.'],
    'start_sticker' => ['Sticker', 'text', 'Schräger Aufkleber oben rechts, z. B. „Kostenlos“. Leer: kein Sticker.'],
    'start_infos_titel' => ['Überschrift der Kacheln', 'inline', ''],
    'start_kachel_1_titel' => ['Kachel 1 – Titel', 'text', ''],
    'start_kachel_1_text' => ['Kachel 1 – Text', 'block', ''],
    'start_kachel_2_titel' => ['Kachel 2 – Titel', 'text', ''],
    'start_kachel_2_text' => ['Kachel 2 – Text', 'block', ''],
    'start_kachel_3_titel' => ['Kachel 3 – Titel', 'text', ''],
    'start_kachel_3_text' => ['Kachel 3 – Text', 'block', ''],
    'start_cme' => ['Hinweis Fortbildungspunkte („Merke“)', 'block', 'Nur sichtbar, wenn ausgefüllt.'],
];

/**
 * Die drei Kacheln der Startseite: [['titel' => Text, 'text' => HTML], …].
 * Bis zum ersten Speichern kommen sie aus dem früheren Feld „Infokästen“
 * (Zwischenüberschrift je Kasten).
 */
function start_kacheln(): array
{
    $k = [];
    for ($i = 1; $i <= 3; $i++) {
        $k[] = ['titel' => inhalt("start_kachel_{$i}_titel"), 'text' => inhalt("start_kachel_{$i}_text")];
    }
    if (implode('', array_merge(...array_map('array_values', $k))) === '' && inhalt('start_infos') !== '') {
        foreach (array_slice(editor_abschnitte(inhalt('start_infos')), 0, 3) as $i => $a) {
            $k[$i] = ['titel' => html_entity_decode(strip_tags($a['titel']), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'text' => $a['text']];
        }
    }
    return $k;
}

/** Formularwert eines Startseiten-Texts (Kacheln mit Übernahme aus „Infokästen“). */
function start_text_wert(string $schluessel): string
{
    if (preg_match('/^start_kachel_([123])_(titel|text)$/', $schluessel, $m) === 1) {
        return start_kacheln()[(int)$m[1] - 1][$m[2]];
    }
    return inhalt($schluessel);
}

/** Startseiten-Texte aus dem Formular speichern. Gibt [geänderte Schlüssel, Fehler je Schlüssel] zurück. */
function start_texte_speichern(array $eingabe, int $kontoId): array
{
    $geaendert = [];
    $fehler = [];
    foreach (START_TEXTE as $k => [$titel, $art]) {
        if (!isset($eingabe[$k])) {
            continue;
        }
        $roh = (string)$eingabe[$k];
        if (mb_strlen($roh) > EDITOR_MAX || ($art === 'text' && mb_strlen($roh) > 120)) {
            $fehler[$k] = 'Zu lang.';
            continue;
        }
        $neu = $art === 'text' ? trim(preg_replace('/\s+/u', ' ', $roh) ?? '') : editor_bereinigen($roh, $art, $art === 'block');
        if ($neu !== inhalt($k)) {
            inhalt_setzen($k, $neu, $kontoId);
            $geaendert[] = $k;
        }
    }
    // Die Kacheln sind jetzt gespeichert: das frühere Feld „Infokästen“ wird nicht mehr gebraucht
    if ($fehler === [] && isset($eingabe['start_kachel_1_titel']) && inhalt('start_infos') !== '') {
        inhalt_setzen('start_infos', '', $kontoId);
    }
    return [$geaendert, $fehler];
}
