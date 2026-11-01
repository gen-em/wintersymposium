<?php
declare(strict_types=1);

/*
 * Helfer für Formulare und Seitenbausteine (AP-17 Unterpunkt 3, Review V-15
 * bis V-25). Jede Seite baut Felder, Aktionsknöpfe, Bestätigungen, Kacheln,
 * Chips, Filter und Leer-Hinweise hierüber; das Markup steht so nur an einer
 * Stelle. Die Vorlage für Aussehen und Varianten ist das Komponentenblatt
 * /team/komponenten (E-64).
 *
 * Alle Texte werden escaped, außer Optionen mit der Endung _html.
 */

/* ---- Attribute ------------------------------------------------------------ */

/**
 * Attribute als HTML: ['maxlength' => 80, 'readonly' => true, 'data-x' => 'y'].
 * true = Attribut ohne Wert, false/null = weglassen.
 */
function attr_html(array $attr): string
{
    $h = '';
    foreach ($attr as $k => $w) {
        if ($w === null || $w === false) {
            continue;
        }
        $h .= ' ' . $k . ($w === true ? '' : '="' . e($w) . '"');
    }
    return $h;
}

/* ---- Felder --------------------------------------------------------------- */

/**
 * Ein Formularfeld mit Label, Hilfe und Fehler (V-17), ein Markup überall:
 *
 *   <div class="feld"><label for="id">Label</label><input …><p class="hilfe" id="h-id">…</p><p class="feldfehler" id="f-id">…</p></div>
 *
 * Mit Fehler bekommt das Feld aria-invalid; aria-describedby zeigt auf Hilfe und Fehler.
 *
 * $opt:
 *   typ      text (Standard), email, zahl (type=number), passwort, auswahl, textarea,
 *            haken (Checkbox mit Text), radio, radio-reihe; sonst als type übernommen
 *            (date, time, datetime-local, search, url, tel …)
 *   wert     aktueller Wert (bei haken: bool)
 *   fehler   Fehlertext oder null
 *   hilfe    Hilfetext (escaped) | hilfe_html (roh)
 *   zusatz   grauer Zusatz im Label, z. B. „(freiwillig)“ | zusatz_html (roh)
 *   label_html  Label als HTML statt Text
 *   pflicht  true = required
 *   attr     weitere Attribute des Eingabeelements (maxlength, autocomplete, readonly, data-* …)
 *   id       id des Eingabeelements (Standard: name)
 *   klasse   weitere Klassen am Rahmen; rahmen_id: id am Rahmen (z. B. sonst-feld);
 *            rahmen_attr: weitere Attribute am Rahmen (z. B. data-fuer)
 *   sr       true = Label nur für Screenreader (Filterzeilen)
 *   optionen bei auswahl/radio: [wert => text] oder Liste (wert = text)
 *   leer     bei auswahl: Text einer leeren ersten Option, z. B. „Bitte wählen“
 *   ohne_rahmen  true = nur Label und Element ohne <div class="feld"> (z. B. in Filterzeilen)
 */
function feld(string $name, string $label, array $opt = []): string
{
    $typ = (string)($opt['typ'] ?? 'text');
    $id = (string)($opt['id'] ?? $name);
    $fehler = $opt['fehler'] ?? null;
    $fehler = $fehler === null || $fehler === '' ? null : (string)$fehler;
    $hilfe = isset($opt['hilfe_html']) ? (string)$opt['hilfe_html'] : (isset($opt['hilfe']) && $opt['hilfe'] !== '' ? e($opt['hilfe']) : '');
    $beschrieben = array_filter([$hilfe !== '' ? 'h-' . $id : null, $fehler !== null ? 'f-' . $id : null]);
    $aria = ($fehler !== null ? ['aria-invalid' => 'true'] : []) + ($beschrieben !== [] ? ['aria-describedby' => implode(' ', $beschrieben)] : []);
    $labelText = isset($opt['label_html']) ? (string)$opt['label_html'] : e($label);
    $zusatz = isset($opt['zusatz_html']) ? ' <span class="meta">' . $opt['zusatz_html'] . '</span>' : (isset($opt['zusatz']) && $opt['zusatz'] !== '' ? ' <span class="meta">' . e($opt['zusatz']) . '</span>' : '');
    $attr = (array)($opt['attr'] ?? []);
    $pflicht = !empty($opt['pflicht']);
    $unten = ($hilfe !== '' ? '<p class="hilfe" id="h-' . e($id) . '">' . $hilfe . '</p>' : '')
        . ($fehler !== null ? '<p class="feldfehler" id="f-' . e($id) . '">' . e($fehler) . '</p>' : '');
    $rahmen = static function (string $innen, string $tag = 'div') use ($opt): string {
        if (!empty($opt['ohne_rahmen'])) {
            return $innen;
        }
        $klasse = trim('feld ' . ($opt['klasse'] ?? ''));
        return '<' . $tag . ' class="' . e($klasse) . '"' . (isset($opt['rahmen_id']) ? ' id="' . e($opt['rahmen_id']) . '"' : '') . attr_html((array)($opt['rahmen_attr'] ?? [])) . '>' . $innen . '</' . $tag . '>';
    };
    $optionen = (array)($opt['optionen'] ?? []);
    if ($optionen !== [] && array_is_list($optionen)) {
        $optionen = array_combine(array_map('strval', $optionen), $optionen);
    }

    if ($typ === 'haken') {
        $html = '<label class="haken"><input type="checkbox" name="' . e($name) . '" value="' . e($opt['wert_haken'] ?? '1') . '"'
            . (!empty($opt['wert']) ? ' checked' : '') . attr_html(['id' => isset($opt['id']) ? $id : null] + $attr + $aria) . '><span>' . $labelText . '</span></label>';
        return $html . $unten;
    }
    if ($typ === 'radio' || $typ === 'radio-reihe') {
        $wert = (string)($opt['wert'] ?? '');
        $html = '<legend>' . $labelText . $zusatz . '</legend><div class="wahl' . ($typ === 'radio-reihe' ? ' wahl-reihe' : '') . '">';
        foreach ($optionen as $w => $t) {
            $html .= '<label><input type="radio" name="' . e($name) . '" value="' . e($w) . '"' . ((string)$w === $wert ? ' checked' : '') . attr_html($attr) . '><span>' . e($t) . '</span></label>';
        }
        return $rahmen($html . '</div>' . $unten, 'fieldset');
    }

    $label = '<label' . (!empty($opt['sr']) ? ' class="sr"' : '') . ' for="' . e($id) . '">' . $labelText . $zusatz . '</label>';
    $basis = ['id' => $id, 'name' => $name];
    if ($typ === 'auswahl') {
        $wert = (string)($opt['wert'] ?? '');
        $h = '<select' . attr_html($basis + ['required' => $pflicht] + $attr + $aria) . '>';
        if (isset($opt['leer'])) {
            $h .= '<option value="">' . e($opt['leer']) . '</option>';
        }
        foreach ($optionen as $w => $t) {
            $h .= '<option value="' . e($w) . '"' . ((string)$w === $wert ? ' selected' : '') . '>' . e($t) . '</option>';
        }
        $element = $h . '</select>';
    } elseif ($typ === 'textarea') {
        $element = '<textarea' . attr_html($basis + ['required' => $pflicht] + $attr + $aria) . '>' . e($opt['wert'] ?? '') . '</textarea>';
    } else {
        $type = ['zahl' => 'number', 'passwort' => 'password'][$typ] ?? $typ;
        $wert = $opt['wert'] ?? null;
        // value vor den übrigen Attributen (… value="x" readonly), wie im bisherigen Markup
        $element = '<input' . attr_html($basis + ['type' => $type] + ($type === 'password' || $wert === null ? [] : ['value' => (string)$wert])
            + $attr + ['required' => $pflicht] + $aria) . '>';
    }
    return $rahmen($label . $element . $unten);
}

/**
 * Wert für ein Feld: nach einem abgeschickten Formular die Eingabe, sonst der
 * gespeicherte Datensatz (V-17). Nie escaped – das macht feld().
 */
function wert(array $eingabe, array $datensatz, string $k): string
{
    $quelle = $eingabe !== [] ? $eingabe : $datensatz;
    $w = $quelle[$k] ?? '';
    return is_scalar($w) ? (string)$w : '';
}

/**
 * Die Profilfelder einer Person (V-16, ersetzt fünf Kopien): Vor- und Nachname,
 * E-Mail, Berufsgruppe, Institution, sonstige Berufsgruppe, EFN.
 *
 * $opt:
 *   email        'frei' (Standard) | 'fest' (nur lesen) | 'ohne'
 *   email_hilfe  Hilfetext unter der Adresse
 *   pflicht      true = required an den Pflichtfeldern (öffentliche Anmeldung)
 *   cme          false = ohne EFN-Feld
 *   hinweise     false = ohne „(freiwillig)“ und EFN-Hilfe (Team trägt für andere ein)
 *   autocomplete false = ohne autocomplete (Team trägt für andere ein)
 */
function profil_felder_html(array $werte, array $fehler, array $opt = []): string
{
    $email = $opt['email'] ?? 'frei';
    $pflicht = !empty($opt['pflicht']);
    $hinweise = $opt['hinweise'] ?? true;
    $auto = $opt['autocomplete'] ?? true;
    $gruppen = va_liste('berufsgruppen');
    $f = fn(string $k) => $fehler[$k] ?? null;
    $w = fn(string $k) => is_scalar($werte[$k] ?? null) ? (string)$werte[$k] : '';
    $ac = fn(string $wert) => $auto ? $wert : null;

    $h = '<div class="paar">'
        . feld('vorname', 'Vorname', ['wert' => $w('vorname'), 'fehler' => $f('vorname'), 'pflicht' => $pflicht, 'attr' => ['autocomplete' => $ac('given-name'), 'maxlength' => 80]])
        . feld('nachname', 'Nachname', ['wert' => $w('nachname'), 'fehler' => $f('nachname'), 'pflicht' => $pflicht, 'attr' => ['autocomplete' => $ac('family-name'), 'maxlength' => 80]])
        . '</div>';
    if ($email !== 'ohne') {
        $h .= feld('email', 'E-Mail', ['typ' => 'email', 'wert' => $w('email'), 'fehler' => $f('email'), 'hilfe' => (string)($opt['email_hilfe'] ?? ''),
            'pflicht' => $pflicht && $email === 'frei',
            'attr' => $email === 'fest' ? ['readonly' => true] : ['autocomplete' => $ac('email'), 'maxlength' => 190]]);
    }
    $h .= '<div class="paar">'
        . feld('berufsgruppe', 'Berufsgruppe', ['typ' => 'auswahl', 'optionen' => $gruppen, 'leer' => 'Bitte wählen', 'wert' => $w('berufsgruppe'),
            'fehler' => $f('berufsgruppe'), 'pflicht' => $pflicht, 'attr' => ['data-efn-gruppe' => $gruppen[0] ?? '']])
        . feld('institution', 'Klinik / Institution', ['wert' => $w('institution'), 'fehler' => $f('institution'), 'pflicht' => $pflicht,
            'attr' => ['autocomplete' => $ac('organization'), 'maxlength' => 160]])
        . '</div>'
        . feld('beruf_sonstige', 'Sonstige – welche?', ['wert' => $w('beruf_sonstige'), 'fehler' => $f('beruf_sonstige'), 'rahmen_id' => 'sonst-feld',
            'zusatz' => $hinweise ? '(freiwillig)' : '', 'attr' => ['maxlength' => 80]]);
    if ($opt['cme'] ?? true) {
        $h .= feld('efn', 'EFN', ['wert' => $w('efn'), 'fehler' => $f('efn'), 'rahmen_id' => 'efn-feld',
            'zusatz' => $hinweise ? '(nur für ' . ($gruppen[0] ?? 'ÄrztInnen') . ', freiwillig, für die Fortbildungspunkte)' : '',
            'hilfe' => $hinweise ? 'Die einheitliche Fortbildungsnummer (15 Ziffern) steht auf deinem Fortbildungsausweis. Kannst du auch später nachtragen.' : '',
            'attr' => ['inputmode' => 'numeric', 'autocomplete' => $ac('off'), 'maxlength' => 20]]);
    }
    return $h;
}

/* ---- Aktionen und Bestätigungen ------------------------------------------- */

/** Versteckte Felder: ['a' => 12, 'q' => ''] (null wird weggelassen). */
function versteckt_html(array $felder): string
{
    $h = '';
    foreach ($felder as $k => $w) {
        if ($w !== null) {
            $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($w) . '">';
        }
    }
    return $h;
}

/**
 * Formular mit einem Knopf (V-20): POST an $ziel mit CSRF, aktion und den
 * versteckten Feldern. $opt: text_html (Knopf-Inhalt roh), form_klasse, vor_html
 * (vor dem Knopf, z. B. ein Chip), titel (title-Attribut des Knopfs).
 */
function aktion_knopf(string $ziel, string $aktion, array $felder, string $text, string $klasse = 'knopf zweit klein', array $opt = []): string
{
    return '<form method="post" action="' . e($ziel) . '"' . (isset($opt['form_klasse']) ? ' class="' . e($opt['form_klasse']) . '"' : '') . '>'
        . csrf_feld() . versteckt_html(['aktion' => $aktion] + $felder) . ($opt['vor_html'] ?? '')
        . '<button class="' . e($klasse) . '" type="submit"' . (isset($opt['titel']) ? ' title="' . e($opt['titel']) . '"' : '') . '>'
        . ($opt['text_html'] ?? e($text)) . '</button></form>';
}

/**
 * Nachfrage vor einer Aktion (V-19): <details class="bestaetigen"> mit
 * Formular; dialog.js macht daraus den einheitlichen Dialog.
 *
 *   $knopf  Text des Auslösers (Menüpunkte, die einen Dialog öffnen, enden auf „…“)
 *   $frage  Text im Dialog (escaped) – roh über $opt['frage_html']; '' = ohne
 *   $jaText Text des Ja-Knopfs, z. B. „Ja, löschen“ (Konzept 5a K-f)
 *   $felder versteckte Felder, mindestens 'aktion'
 *
 * $opt:
 *   ziel        Formularziel (Standard: aktueller Pfad)
 *   art         rot (Standard) | gruen | lila – Farbe des Dialogs
 *   titel       Überschrift im Dialog (Standard bei dialog.js: Auslöser mit „?“)
 *   ausloeser   id eines anderen Knopfs, der den Dialog öffnet
 *   knopfklasse Klasse des Auslösers (Standard: knopf zweit klein)
 *   ja_klasse   Klasse des Ja-Knopfs (Standard: rot → knopf gefahr, sonst knopf)
 *   klasse      weitere Klassen am details (inline-details, gefahrzone, senden)
 *   inhalt_html weiteres HTML vor dem Ja-Knopf (Felder, Haken)
 *   vor_frage   true = inhalt_html vor der Frage statt danach
 *   offen       true = gleich offen (z. B. nach einem Fehler)
 *   im_formular true = ohne eigenes <form>, Ja-Knopf sendet name="aktion" im umgebenden Formular
 *   knopf_id    id am Auslöser
 */
function bestaetigung_html(string $knopf, string $frage, string $jaText, array $felder = [], array $opt = []): string
{
    $art = (string)($opt['art'] ?? 'rot');
    $frageHtml = isset($opt['frage_html']) ? (string)$opt['frage_html'] : ($frage !== '' ? e($frage) : '');
    $frageHtml = $frageHtml !== '' ? '<p>' . $frageHtml . '</p>' : '';
    $inhalt = (string)($opt['inhalt_html'] ?? '');
    $mitte = !empty($opt['vor_frage']) ? $inhalt . $frageHtml : $frageHtml . $inhalt;
    $jaKlasse = (string)($opt['ja_klasse'] ?? ($art === 'rot' ? 'knopf gefahr' : 'knopf'));
    $h = '<details class="' . e(trim('bestaetigen ' . ($opt['klasse'] ?? ''))) . '"'
        . ($art !== 'rot' ? ' data-art="' . e($art) . '"' : '')
        . (isset($opt['ausloeser']) ? ' data-ausloeser="' . e($opt['ausloeser']) . '"' : '')
        . (isset($opt['titel']) ? ' data-titel="' . e($opt['titel']) . '"' : '')
        . (!empty($opt['offen']) ? ' open' : '') . '>'
        . '<summary class="' . e($opt['knopfklasse'] ?? 'knopf zweit klein') . '"' . (isset($opt['knopf_id']) ? ' id="' . e($opt['knopf_id']) . '"' : '') . '>' . ($opt['knopf_html'] ?? e($knopf)) . '</summary>';
    if (!empty($opt['im_formular'])) {
        $aktion = (string)($felder['aktion'] ?? '');
        return $h . '<div class="bestaetigen-feld">' . $mitte . '<button class="' . e($jaKlasse) . '" type="submit" name="aktion" value="' . e($aktion) . '">' . e($jaText) . '</button></div></details>';
    }
    $ziel = (string)($opt['ziel'] ?? strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?'));
    return $h . '<form method="post" action="' . e($ziel) . '">' . csrf_feld() . versteckt_html($felder)
        . $mitte . '<button type="submit" class="' . e($jaKlasse) . '">' . e($jaText) . '</button></form></details>';
}

/* ---- Bausteine ------------------------------------------------------------ */

/** Kachel mit Zahl (V-22). $link: [href, text]. */
function kachel(string $label, string|int $zahl, string $meta = '', ?array $link = null): string
{
    return '<div class="kachel"><div class="lbl">' . e($label) . '</div><div class="zahl">' . e($zahl) . '</div>'
        . ($meta !== '' ? '<p class="meta klein">' . e($meta) . '</p>' : '')
        . ($link !== null ? '<p><a href="' . e($link[0]) . '">' . e($link[1]) . '</a></p>' : '') . '</div>';
}

/** Ton der Chips je Status (V-23). */
const VA_STATUS_CHIP = ['aktuell' => 'ok', 'entwurf' => 'offen', 'abgeschlossen' => 'gesperrt'];
const PLATZ_STATUS_CHIP = ['fest' => 'ok', 'warte' => 'wl', 'angebot' => 'wl'];
const RM_STATUS_CHIP = ['entwurf' => 'offen', 'geplant' => 'offen', 'versand' => 'offen', 'gesendet' => 'ok', 'abgebrochen' => 'gesperrt'];
const ANM_STATUS_CHIP = ['bestaetigt' => 'ok', 'unbestaetigt' => 'offen'];
const BETRIEB_AMPEL_CHIP = ['ok' => 'ok', 'warnung' => 'warnung', 'fehler' => 'wl'];

/** Chip (Etikett) mit Ton: ok, offen, wl, gesperrt, warnung. */
function chip(string $ton, string $text): string
{
    return '<span class="chip ' . e($ton) . '">' . e($text) . '</span>';
}

/**
 * Filter als Knopfreihe (V-21): $optionen [schluessel => text] oder
 * [schluessel => [text, zahl]]; $href(schluessel) liefert den Link.
 */
function filter_chips(array $optionen, string $aktiv, callable $href, string $label = 'Filter'): string
{
    $h = '<div class="filter" role="group" aria-label="' . e($label) . '">';
    foreach ($optionen as $k => $t) {
        [$text, $zahl] = is_array($t) ? [$t[0], $t[1] ?? null] : [$t, null];
        $an = (string)$k === $aktiv;
        $h .= '<a class="knopf klein' . ($an ? '' : ' zweit') . '" href="' . e($href((string)$k)) . '"' . ($an ? ' aria-current="true"' : '') . '>'
            . e($text) . ($zahl !== null ? ' <span class="mono">' . e($zahl) . '</span>' : '') . '</a>';
    }
    return $h . '</div>';
}

/**
 * Filterzeile (V-21): GET-Formular mit Suche, Auswahlfeldern und Knopf.
 *
 * $opt:
 *   ziel       Pfad (Pflicht)
 *   suche      true = role="search"
 *   versteckt  [name => wert] (null und '' fallen weg)
 *   felder     HTML der Felder (über feld(…, ['sr' => true, 'ohne_rahmen' => true]))
 *   knopf      Text des Knopfs (Standard „Suchen“); '' = ohne
 *   nach_html  HTML nach dem Knopf (z. B. ein Link)
 */
function filter_leiste(array $opt): string
{
    $versteckt = array_filter((array)($opt['versteckt'] ?? []), fn($w) => $w !== null && $w !== '');
    $knopf = (string)($opt['knopf'] ?? 'Suchen');
    return '<form class="filterzeile" method="get" action="' . e($opt['ziel']) . '"' . (!empty($opt['suche']) ? ' role="search"' : '') . '>'
        . versteckt_html($versteckt) . ($opt['felder'] ?? '')
        . ($knopf !== '' ? '<button class="knopf zweit klein" type="submit">' . e($knopf) . '</button>' : '')
        . ($opt['nach_html'] ?? '') . '</form>';
}

/**
 * Hinweis für eine leere Liste (V-25). $was ohne Punkt: „Keine $was“ (mit
 * $gefiltert „… in dieser Auswahl“); endet $was auf einen Punkt, gilt es als
 * ganzer Satz.
 */
function leer_hinweis(string $was, bool $gefiltert = false): string
{
    $text = str_ends_with($was, '.') ? $was : 'Keine ' . $was . ($gefiltert ? ' in dieser Auswahl' : '') . '.';
    return '<p class="hinweis-leer">' . e($text) . '</p>';
}

/**
 * Seitenkopf öffentlicher Seiten (V-24): Kicker, Titel, Einleitung. Mit
 * $offen = true bleibt der Rahmen offen (die Seite schließt ihn nach weiterem
 * Inhalt mit </div>).
 */
function seiten_kopfzeile(string $titel, string $kicker = '', string $text = '', bool $offen = false): string
{
    return '<div class="innen seitenkopf">' . ($kicker !== '' ? '<p class="kicker">' . e($kicker) . '</p>' : '')
        . '<h1 class="titel">' . e($titel) . '</h1>' . ($text !== '' ? '<p class="text">' . e($text) . '</p>' : '')
        . ($offen ? '' : '</div>');
}

/* ---- Seitenablauf (POST/Redirect/GET) -------------------------------------- */

/** Ob die Anfrage ein POST ist; prüft dann gleich das CSRF-Token (bricht sonst ab). */
function ist_post(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return false;
    }
    csrf_pruefen();
    return true;
}

/** Gewählte Aktion des abgeschickten Formulars ('' = keine). */
function aktion(): string
{
    return post_text('aktion');
}

/** Text aus dem POST ('' bei fehlend oder Liste); $max > 0 kürzt auf so viele Zeichen. */
function post_text(string $k, int $max = 0): string
{
    $w = $_POST[$k] ?? '';
    $w = is_string($w) ? $w : '';
    return $max > 0 ? mb_substr($w, 0, $max) : $w;
}

/** Ganzzahl aus dem POST (0 bei fehlend). */
function post_zahl(string $k): int
{
    $w = $_POST[$k] ?? 0;
    return is_scalar($w) ? (int)$w : 0;
}

/** Ob ein Haken im POST gesetzt ist. */
function post_haken(string $k): bool
{
    return !empty($_POST[$k]);
}

/** Meldung merken und zurück auf die Seite (Ende jeder Aktion). */
function fertig(string $ton, string $text, string $ziel): never
{
    flash_setzen($ton, $text);
    umleiten($ziel);
}

/** Ergebnis einer Aktionsfunktion (null = ok, Text = Fehler, Konzept 5a K-a) melden und zurück. */
function fertig_oder_fehler(?string $fehler, string $okText, string $ziel): never
{
    fertig($fehler === null ? 'ok' : 'fehler', $fehler ?? $okText, $ziel);
}
