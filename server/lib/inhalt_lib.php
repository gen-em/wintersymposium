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
    $cache = ctx('inhalt_cache') ?? [];
    if (!array_key_exists($k, $cache)) {
        $cache[$k] = (string)(va_tabellen_da()
            ? db_wert('SELECT text FROM inhalt WHERE veranstaltung_id = ? AND schluessel = ?', [$va, $schluessel]) ?? ''
            : db_wert('SELECT text FROM inhalt WHERE schluessel = ?', [$schluessel]) ?? '');
        ctx_setzen('inhalt_cache', $cache);
    }
    return $cache[$k];
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
    $cache = ctx('inhalt_cache') ?? [];
    unset($cache[$va . ':' . $schluessel]);
    ctx_setzen('inhalt_cache', $cache);
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
const EDITOR_LEISTE = ['block' => 'fett kursiv titel | liste nliste link bild knopf | html', 'inline' => 'fett kursiv link', 'titel' => 'lila trennstelle'];

/**
 * Attribute für ein <textarea> mit Editor. $mail: Bilder der Mailings,
 * Platzhalter und {link} als Knopf-Ziel; sonst Bilder der Webseite.
 * Art 'titel': Titel der Startseite, nur „Lila“ und „Trennstelle“ (E-70 (3), (4)).
 */
function editor_attribute(string $art = 'block', bool $mail = false): string
{
    return attr_html(editor_attr($art, $mail));
}

/** Dieselben Attribute als Liste (für feld(…, ['attr' => …])). */
function editor_attr(string $art = 'block', bool $mail = false): array
{
    $art = in_array($art, ['inline', 'titel'], true) ? $art : 'block';
    $a = ['data-editor' => $art, 'data-werkzeug' => EDITOR_LEISTE[$art]];
    if ($art === 'block') {
        $a['data-bild-url'] = $mail ? '/team/mailings/bild' : '/team/texte/bild';
    }
    if ($mail) {
        $a += ['data-knopf-ziel' => '{link}', 'data-platzhalter' => json_encode(RM_PLATZHALTER, JSON_UNESCAPED_UNICODE)];
    }
    return $a;
}

/**
 * Editor-HTML bereinigen. $art 'block' oder 'inline'; $bilder: eigene
 * Text-Bilder erlauben (nur in den Texten der Webseite).
 */
/**
 * Ein Einstieg für alle HTML-Bereinigungen (V-13): Profil 'inline' (eine
 * Zeile: fett, kursiv, Link), 'block' (Absätze, Überschriften, Listen,
 * Links; mit $bilder auch Bilder der Texte), 'titel' (Titel der Startseite:
 * nur Lila als em und Trennstellen als weiches Trennzeichen, E-70 (3), (4))
 * und 'mail' (Mailings: wie block,
 * dazu Bilder der Mailings und der Platzhalter {link}). Unter 'mail' arbeitet
 * der Bereiniger der Mailings (rm_html_bereinigen), weil derselbe Durchlauf
 * auch Text- und HTML-Mail erzeugt; gespeichertes Mailing-HTML bleibt so
 * unverändert.
 */
function html_bereinigen(string $html, string $profil = 'block', bool $bilder = false): string
{
    return match ($profil) {
        'inline', 'block', 'titel' => editor_bereinigen($html, $profil, $bilder),
        'mail' => rm_html_bereinigen($html),
        default => throw new InvalidArgumentException('Unbekanntes Profil: ' . $profil),
    };
}

function editor_bereinigen(string $html, string $art = 'block', bool $bilder = false): string
{
    $html = trim(mb_substr(str_replace("\r", '', $html), 0, EDITOR_MAX));
    if ($html === '') {
        return '';
    }
    if ($art === 'titel') {
        // Trennstelle ohne Skript als &shy; eingetippt
        $html = str_ireplace('&shy;', "\u{AD}", $html);
    }
    if (!str_contains($html, '<')) {
        // Klartext (ohne Skript abgeschickt oder älterer Wert): Absätze und Zeilen übernehmen
        if ($art === 'block') {
            return implode('', array_map(fn($a) => '<p>' . str_replace("\n", '<br>', e(trim($a))) . '</p>', preg_split('/\n[ \t]*\n/', $html) ?: []));
        }
        $h = e($html);
    } else {
        $wurzel = rm_dom($html);
        if ($wurzel === null) {
            return '';
        }
        $h = editor_kinder($wurzel, $art, $bilder);
    }
    $h = preg_replace('#(<br>\s*)+(</(p|h2|h3|li)>)#', '$2', $h) ?? $h;
    if ($art === 'titel') {
        // Trennstellen nur mitten im Wort, nie doppelt
        $h = preg_replace(['/\x{AD}+/u', '/(^|[\s>])\x{AD}|\x{AD}(?=$|[\s<-])/u'], ["\u{AD}", '$1'], $h) ?? $h;
        $h = str_replace('<em></em>', '', $h);
    }
    return trim($art !== 'block' ? (preg_replace('/\s+/u', ' ', $h) ?? $h) : $h);
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
    if ($art === 'titel' && $tag === 'span' && in_array('trennstelle', preg_split('/\s+/', $n->getAttribute('class')) ?: [], true)) {
        return "\u{AD}"; // Markierung des Editors
    }
    $innen = editor_kinder($n, $art, $bilder);
    if ($art === 'titel') {
        return match ($tag) {
            'em' => trim(strip_tags($innen)) === '' ? $innen : '<em>' . strip_tags($innen) . '</em>',
            'br', 'p', 'h2', 'h3', 'li' => ' ' . $innen . ' ',
            default => $innen,
        };
    }
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

/* ---- Startseite einer Veranstaltung (E-70, AP-18) ------------------------ */

/**
 * Felder der Startseite je Veranstaltung, bearbeitet unter Verwaltung →
 * Startseite (E-70 (1), (2)). Leer = nicht angezeigt; Ausnahmen: Zeile über
 * dem Titel (leer: Dachmarke · Kurzname) und Titel (leer: Name der
 * Veranstaltung). Eckdaten und Newsletter-Hinweis sind fest.
 * Schlüssel => [Titel, Art: text | inline | block | titel, Hilfe, Abschnitt].
 */
const START_TEXTE = [
    'start_kicker' => ['Zeile über dem Titel', 'text', 'Leer: Dachmarke · Kurzname.', 'kopf'],
    'start_titel' => ['Titel', 'titel', 'Leer: Name der Veranstaltung. „Lila“ färbt das markierte Wort; ohne Markierung ist das erste Wort weiß, der Rest lila. Eine Trennstelle erlaubt die Trennung nur dort – der Titel bleibt dann größer.', 'kopf'],
    'start_unter' => ['Untertitel', 'inline', 'Ein, zwei Sätze unter dem Titel.', 'kopf'],
    'start_sticker' => ['Sticker', 'text', 'Schräger Aufkleber oben rechts, z. B. „Kostenlos“. Leer: kein Sticker.', 'kopf'],
    'start_infos_kicker' => ['Zeile darüber', 'text', '', 'infos'],
    'start_infos_titel' => ['Überschrift', 'inline', '', 'infos'],
    'start_kachel_1_titel' => ['Kachel 1 – Titel', 'text', '', 'infos'],
    'start_kachel_1_text' => ['Kachel 1 – Text', 'block', '', 'infos'],
    'start_kachel_2_titel' => ['Kachel 2 – Titel', 'text', '', 'infos'],
    'start_kachel_2_text' => ['Kachel 2 – Text', 'block', '', 'infos'],
    'start_kachel_3_titel' => ['Kachel 3 – Titel', 'text', '', 'infos'],
    'start_kachel_3_text' => ['Kachel 3 – Text', 'block', '', 'infos'],
    'start_programm_kicker' => ['Zeile darüber', 'text', '', 'programm'],
    'start_programm_titel' => ['Überschrift', 'inline', '', 'programm'],
    'start_cme' => ['Text', 'block', 'Leer: kein Kasten.', 'merke'],
];

/** Abschnitte der Seite „Startseite“ in der Reihenfolge der Startseite. */
const START_ABSCHNITTE = ['kopf' => 'Kopf', 'infos' => 'Worum es geht', 'programm' => 'Programm', 'merke' => 'Merke · Fortbildungspunkte'];

/** Startwerte für eine neue Veranstaltung ohne Vorlage und beim Update (Migration 22): die bisher festen Texte. */
const START_STANDARD = ['start_infos_kicker' => 'Worum es geht', 'start_programm_kicker' => 'Programm', 'start_programm_titel' => 'Der Abend im Überblick'];

/** Längste Zeile einfacher Textfelder (Sticker, Zeilen über Überschriften). */
const START_TEXT_MAX = 120;

/**
 * Größenstufen des Titels (E-70 (4)): nach Zeichen des längsten unteilbaren
 * Teils. Größen in site.css (Abschnitt 14), Werte im Komponentenblatt. Über
 * der letzten Grenze passt der Teil auch in der Mindestgröße (2rem) auf dem
 * Handy (390 px) nicht: dann darf der Browser trennen (titel-notfall).
 */
const START_TITEL_STUFEN = ['titel-xl' => [12, 'XL'], 'titel-l' => [16, 'L'], 'titel-m' => [21, 'M'], 'titel-s' => [PHP_INT_MAX, 'S']];
const START_TITEL_NOTFALL = 21;

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

/** Einen Startseiten-Text bereinigen; null bei zu langer Eingabe. */
function start_text_bereinigen(string $schluessel, string $roh): ?string
{
    $art = START_TEXTE[$schluessel][1];
    if (mb_strlen($roh) > EDITOR_MAX || ($art === 'text' && mb_strlen($roh) > START_TEXT_MAX)) {
        return null;
    }
    return $art === 'text' ? trim(preg_replace('/\s+/u', ' ', $roh) ?? '') : html_bereinigen($roh, $art, $art === 'block');
}

/**
 * Alle Werte der Startseite: gespeichert oder – für die Vorschau (E-70 (6))
 * und nach einem Fehler – aus $eingabe, bereinigt wie beim Speichern.
 */
function start_werte(?array $eingabe = null): array
{
    $w = [];
    foreach (START_TEXTE as $k => $_) {
        $w[$k] = $eingabe === null ? start_text_wert($k) : (start_text_bereinigen($k, (string)($eingabe[$k] ?? '')) ?? '');
    }
    return $w;
}

/** Startseiten-Texte aus dem Formular speichern. Gibt [geänderte Schlüssel, Fehler je Schlüssel] zurück. */
function start_texte_speichern(array $eingabe, ?int $kontoId): array
{
    $neu = [];
    $fehler = [];
    foreach (START_TEXTE as $k => [$titel]) {
        if (!isset($eingabe[$k])) {
            continue;
        }
        $wert = start_text_bereinigen($k, (string)$eingabe[$k]);
        if ($wert === null) {
            $fehler[$k] = 'Zu lang.';
        } else {
            $neu[$k] = $wert;
        }
    }
    if ($fehler !== []) {
        return [[], $fehler];
    }
    $geaendert = [];
    foreach ($neu as $k => $wert) {
        if ($wert !== inhalt($k)) {
            inhalt_setzen($k, $wert, $kontoId);
            $geaendert[] = $k;
        }
    }
    // Die Kacheln sind jetzt gespeichert: das frühere Feld „Infokästen“ wird nicht mehr gebraucht
    if (isset($eingabe['start_kachel_1_titel']) && inhalt('start_infos') !== '') {
        inhalt_setzen('start_infos', '', $kontoId);
    }
    return [$geaendert, []];
}

/**
 * Titel als HTML (E-70 (3), E-68): gespeicherter Titel oder, wenn leer, der
 * Name der Veranstaltung (ohne Veranstaltung die Dachmarke, E-70 (7)). Ohne
 * Lila-Markierung ist das erste Wort weiß und der Rest lila, ein einzelnes
 * Wort ganz lila.
 */
function start_titel_html(string $titel): string
{
    $h = trim(html_bereinigen($titel, 'titel'));
    if ($h === '') {
        $name = va_keine() ? reihe_name() : (va('veranstaltung_name') !== '' ? va('veranstaltung_name') : va_kurz());
        $h = e(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
    }
    if (str_contains($h, '<em>')) {
        return $h;
    }
    $teile = explode(' ', $h, 2);
    return count($teile) === 1 ? '<em>' . $h . '</em>' : $teile[0] . ' <em>' . $teile[1] . '</em>';
}

/** Zeile über dem Titel: eigener Text, sonst Dachmarke · Kurzname (ohne Veranstaltung: keine Zeile, E-70 (7)). */
function start_kicker_text(string $kicker): string
{
    if ($kicker !== '') {
        return $kicker;
    }
    return va_keine() ? '' : reihe_name() . ' · ' . va_kurz();
}

/**
 * Unteilbare Teile eines Titels (E-70 (4)): getrennt wird an Leerzeichen,
 * nach einem Bindestrich und an Trennstellen (weiches Trennzeichen; der Teil
 * davor bekommt dort beim Trennen einen Strich). Gibt [Teil, …] zurück.
 */
function start_titel_teile(string $titelHtml): array
{
    $text = html_entity_decode(strip_tags($titelHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $teile = [];
    foreach (preg_split('/\s+/u', trim($text)) ?: [] as $wort) {
        foreach (preg_split('/(?<=-)|(?<=\x{AD})/u', $wort) ?: [] as $t) {
            if ($t !== '') {
                $teile[] = str_replace("\u{AD}", '-', $t);
            }
        }
    }
    return $teile;
}

/**
 * Größenstufe des Titels: ['klasse' => CSS-Klassen, 'stufe' => XL|L|M|S,
 * 'laengster' => Teil, 'notfall' => bool (der Browser darf trennen),
 * 'trennstelle' => bool].
 */
function start_titel_stufe(string $titelHtml): array
{
    $teile = start_titel_teile($titelHtml);
    $laengster = '';
    foreach ($teile as $t) {
        if (mb_strlen($t) > mb_strlen($laengster)) {
            $laengster = $t;
        }
    }
    $n = mb_strlen($laengster);
    foreach (START_TITEL_STUFEN as $klasse => [$bis, $name]) {
        if ($n <= $bis) {
            break;
        }
    }
    $notfall = $n > START_TITEL_NOTFALL;
    return ['klasse' => $klasse . ($notfall ? ' titel-notfall' : ''), 'stufe' => $name, 'laengster' => $laengster, 'notfall' => $notfall,
        'trennstelle' => str_contains(html_entity_decode($titelHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8'), "\u{AD}")];
}

/** Hinweise zur Vorschau (E-70 (6)): Größenstufe, Notfall-Trennung, leere Kacheln. */
function start_hinweise(array $w): array
{
    $st = start_titel_stufe(start_titel_html($w['start_titel']));
    $h = ['stufe' => $st['stufe'], 'laengster' => $st['laengster'], 'notfall' => $st['notfall'], 'trennstelle' => $st['trennstelle'], 'leer' => []];
    for ($i = 1; $i <= 3; $i++) {
        if ($w["start_kachel_{$i}_titel"] === '' && $w["start_kachel_{$i}_text"] === '') {
            $h['leer'][] = $i;
        }
    }
    return $h;
}

/**
 * Inhalt der Startseite (ohne Rahmen) aus den Werten von start_werte():
 * Kopf mit Eckdaten, „Worum es geht“, Programm, Merke, Newsletter-Hinweis.
 * Leere Felder und leere Abschnitte erscheinen nicht (E-70 (2)).
 */
function start_html(array $w): string
{
    $keine = va_keine();
    $archiv = va_abgeschlossen();
    $h = '';
    if ($archiv) {
        $akt = va_aktuell_id();
        $h .= '<div class="innen"><p class="va-archiv" role="status"><strong>Diese Veranstaltung ist vorbei.</strong> Die Seite bleibt als Rückblick stehen'
            . ($akt !== null ? ' – die aktuelle findest du auf der <a href="/">Startseite</a>' : '') . '.</p></div>';
    }
    if ($keine) {
        $h .= '<div class="innen"><p class="va-archiv" role="status"><strong>Derzeit keine Veranstaltung.</strong> Die nächste kündigen wir hier und im <a href="/newsletter">Newsletter</a> an.</p></div>';
    }
    $titel = start_titel_html($w['start_titel']);
    $kicker = start_kicker_text($w['start_kicker']);
    $h .= '<div class="innen held"><div>'
        . ($kicker !== '' ? '<p class="kicker">' . e($kicker) . '</p>' : '')
        . '<h1 class="titel ' . start_titel_stufe($titel)['klasse'] . '">' . $titel . '</h1>'
        . ($w['start_unter'] !== '' ? '<p class="unter">' . editor_html($w['start_unter'], 'inline') . '</p>' : '')
        . '</div>'
        . ($w['start_sticker'] !== '' ? '<span class="sticker hinweis frei">' . e($w['start_sticker']) . '</span>' : '');
    $eck = $keine ? [] : va_eckdaten();
    if ($eck !== []) {
        $h .= '<dl class="eckdaten">' . implode('', array_map(fn($x) => '<div><dt>' . e($x[0]) . '</dt><dd>' . e($x[1]) . '</dd></div>', $eck)) . '</dl>';
    }
    if (!$keine && !$archiv && va_anmeldung_vor_start()) {
        $h .= '<p class="mono meta klein anmeldung-ab">' . e('Anmeldung ab ' . datum_text(va('anmeldung_start'))) . '</p>';
    }
    if (anm_zeitraum() === 'offen') {
        $h .= '<div class="held-knoepfe"><a class="knopf gruen" href="/anmelden">Jetzt anmelden</a><a class="knopf zweit" href="/deine-anmeldung">Anmeldung ändern</a></div>';
    }
    $h .= '</div>';

    $kacheln = [];
    for ($i = 1; $i <= 3; $i++) {
        if ($w["start_kachel_{$i}_titel"] !== '' || $w["start_kachel_{$i}_text"] !== '') {
            $kacheln[] = '<div class="kasten">' . ($w["start_kachel_{$i}_titel"] !== '' ? '<strong>' . e($w["start_kachel_{$i}_titel"]) . '</strong>' : '') . editor_html($w["start_kachel_{$i}_text"]) . '</div>';
        }
    }
    if ($kacheln !== []) {
        $h .= start_abschnitt_kopf_html('infos', $w['start_infos_kicker'], $w['start_infos_titel'])
            . '<div class="drei">' . implode('', $kacheln) . '</div></div></section>';
    }
    if (!$keine) {
        $h .= start_abschnitt_kopf_html('programm', $w['start_programm_kicker'], $w['start_programm_titel'], ' id="programm"')
            . programm_html(programm_veroeffentlicht()['punkte'] ?? []) . '</div></section>';
    }
    if ($w['start_cme'] !== '') {
        $h .= '<section class="abschnitt"><div class="innen"><div class="merke"><p class="kicker">Merke · Fortbildungspunkte</p>' . editor_html($w['start_cme']) . '</div></div></section>';
    }
    return $h . '<section class="abschnitt"><div class="innen">'
        . '<p class="nl-hinweis"><span class="kicker">Newsletter</span> Termine und Neuigkeiten per Mail – kostenlos, jederzeit abbestellbar. <a href="/newsletter">Newsletter bestellen</a></p>'
        . '</div></section>';
}

/** Anfang eines Abschnitts der Startseite mit Zeile und Überschrift (beide nur, wenn ausgefüllt). */
function start_abschnitt_kopf_html(string $id, string $kicker, string $titel, string $attr = ''): string
{
    $h = '<section class="abschnitt"' . $attr . ($titel !== '' ? ' aria-labelledby="' . $id . '-titel"' : '') . '><div class="innen">';
    if ($kicker !== '') {
        $h .= '<p class="kicker">' . e($kicker) . '</p>';
    }
    if ($titel !== '') {
        $h .= '<h2 class="zwischen" id="' . $id . '-titel">' . editor_html($titel, 'inline') . '</h2>';
    }
    return $h;
}

/** Geänderte Texte protokollieren; Startseiten-Texte gehören zur Veranstaltung, die übrigen nicht. */
function inhalt_protokollieren(string $was, array $schluessel, int $durch, bool $veranstaltung): void
{
    audit_schreiben('texte', $was . ': ' . implode(', ', $schluessel), $durch, vaId: $veranstaltung ? -1 : 0);
}
