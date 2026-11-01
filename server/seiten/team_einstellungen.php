<?php
declare(strict_types=1);

/*
 * Veranstaltungs-Einstellungen (AP-3 Schritt 2, R-11) für Admin und
 * Betreiber, als Übersicht (AP-18, E-70 (8), Variante B): je Gruppe eine
 * Kachel mit Zusammenfassung; „Bearbeiten“ klappt die Gruppe auf (eine zur
 * Zeit), gespeichert wird je Kachel. Fehler öffnen ihre Kachel. Ohne
 * JavaScript sind die Kacheln details-Elemente mit je einem Formular. Die
 * Texte der Startseite stehen unter Verwaltung → Startseite (Kachel mit
 * Mini-Vorschau). Katalog und Prüfung: lib/veranstaltung_lib.php.
 */

$konto = team_tor();
team_schreibschutz('/team/einstellungen'); // abgeschlossene Veranstaltung: nur lesen (E-58)
$fehler = [];
$eingabe = null;
$offen = null;

if (ist_post()) {
    $gruppe = isset(VA_GRUPPEN[post_text('gruppe')]) ? post_text('gruppe') : null;
    if (aktion() === 'abbrechen') {
        umleiten('/team/einstellungen' . ($gruppe !== null ? '#' . $gruppe : ''));
    }
    $r = va_gruppe_pruefen($_POST, $gruppe);
    // Kürzel der Veranstaltung (Adresse /kürzel, E-58) – in der Tabelle veranstaltung, Kachel Grunddaten
    $mitKuerzel = $gruppe === 'veranstaltung' || ($gruppe === null && isset($_POST['kuerzel']));
    $kuerzel = $mitKuerzel ? mb_strtolower(post_text('kuerzel')) : va_zeile()['kuerzel'];
    if ($mitKuerzel && ($kf = va_kuerzel_fehler($kuerzel, va_id())) !== null) {
        $r['fehler']['kuerzel'] = $kf;
    }
    // Reihe der Veranstaltung (AP-19): Kachel „Reihe & Zielgruppe“, nur BetreiberInnen
    $reihe = $gruppe === 'reihe' && $konto['rolle'] === 'betreiber' && isset($_POST['reihe_id']) ? (post_zahl('reihe_id') ?: null) : false;
    if ($reihe !== false && $reihe !== null && va_reihe($reihe) === null) {
        $r['fehler']['reihe_id'] = 'Unbekannte Reihe.';
    }
    if ($r['fehler'] === []) {
        $geaendert = va_speichern($r['werte'], (int)$konto['id']);
        if ($reihe !== false && (va_reihe_von(va_id())['id'] ?? null) !== $reihe) {
            va_reihe_setzen(va_id(), $reihe, (int)$konto['id']);
            $geaendert[] = 'reihe';
        }
        if ($kuerzel !== va_zeile()['kuerzel']) {
            va_kuerzel_setzen(va_id(), $kuerzel, (int)$konto['id']);
            $geaendert[] = 'kuerzel';
        }
        fertig('ok', $geaendert === [] ? 'Nichts geändert.' : 'Gespeichert (' . count($geaendert) . ' ' . (count($geaendert) === 1 ? 'Einstellung' : 'Einstellungen') . ' geändert). Die Startseite zeigt die neuen Werte sofort.', '/team/einstellungen' . ($gruppe !== null ? '#' . $gruppe : ''));
    }
    $fehler = $r['fehler'];
    $eingabe = $r['eingabe'] + ['kuerzel' => $kuerzel];
    $offen = $gruppe;
}

$nurLesen = va_abgeschlossen();
// Offen: die bearbeitete Kachel und jede mit Fehler (Fehler über Gruppen hinweg, z. B. Stichtag nach dem Datum)
$mitFehler = array_unique(array_map(fn($k) => ['kuerzel' => 'veranstaltung', 'reihe_id' => 'reihe'][$k] ?? VA_KATALOG[$k]['gruppe'], array_keys($fehler)));
$istOffen = fn(string $g): bool => $g === $offen || in_array($g, $mitFehler, true);

/** Formular einer Gruppe: Felder aus dem Katalog, Abbrechen und Speichern. */
$formular = function (string $g, string $hilfe) use ($eingabe, $fehler, $konto): string {
    $h = '<form method="post" action="/team/einstellungen#' . e($g) . '" class="einst-form stapel" novalidate>' . csrf_feld() . versteckt_html(['gruppe' => $g])
        . '<p class="meta klein">' . e($hilfe) . '</p><div class="felder">';
    if ($g === 'veranstaltung') {
        $kf = $fehler['kuerzel'] ?? null;
        $h .= feld('kuerzel', 'Kürzel (Adresse)', ['wert' => $eingabe !== null ? (string)$eingabe['kuerzel'] : va_zeile()['kuerzel'], 'fehler' => $kf, 'pflicht' => true, 'attr' => ['maxlength' => 40],
            'hilfe_html' => $kf !== null ? null : 'Die Veranstaltung ist unter <span class="mono">' . e(parse_url(url('/'), PHP_URL_HOST)) . '/' . e(va_zeile()['kuerzel']) . '</span> erreichbar. Kleinbuchstaben ohne Umlaute, Ziffern, Bindestrich.']);
    }
    if ($g === 'reihe') {
        $aktuell = va_reihe_von(va_id());
        $h .= feld('reihe_id', 'Reihe', ['typ' => 'auswahl', 'wert' => (string)($aktuell['id'] ?? 0), 'fehler' => $fehler['reihe_id'] ?? null,
            'optionen' => ['0' => 'einzeln (keine Reihe)'] + array_column(va_reihen(), 'name', 'id'),
            'attr' => ['disabled' => $konto['rolle'] !== 'betreiber'],
            'hilfe' => $konto['rolle'] === 'betreiber' ? 'Reihen verwaltest du unter Betrieb → Veranstaltungen.' : 'Die Reihe ändern BetreiberInnen.']);
    }
    foreach (VA_KATALOG as $k => $d) {
        if ($d['gruppe'] !== $g) {
            continue;
        }
        if ($d['typ'] === 'zuordnung') {
            // Vorauswahl bei Allgäu Akut je Berufsgruppe (O-50): ein Auswahlfeld je Berufsgruppe
            $map = json_decode($eingabe !== null ? (string)$eingabe[$k] : va_formularwert($k), true);
            $h .= '<fieldset class="feld breit"><legend>' . e($d['titel']) . '</legend><div class="felder">';
            foreach (va_liste('berufsgruppen') as $i => $bg) {
                $h .= feld($k . '[' . $bg . ']', $bg, ['typ' => 'auswahl', 'id' => 'bgi-' . $i, 'optionen' => VA_INTERESSEN, 'wert' => is_array($map) && isset($map[$bg]) ? (string)$map[$bg] : va_interesse($bg)]);
            }
            $h .= '</div>' . (isset($fehler[$k]) ? '<p class="feldfehler">' . e($fehler[$k]) . '</p>' : '<p class="hilfe">Nach dem Speichern neuer Berufsgruppen erscheinen sie hier; bis dahin gilt die Regel (NotSan, RettSan → Präklinik; Pflege/MFA → Klinik; sonst beides).</p>') . '</fieldset>';
            continue;
        }
        // Mit Fehler steht der Fehler an der Stelle des Hilfetexts
        $f = $fehler[$k] ?? null;
        $opt = ['wert' => $eingabe !== null ? (string)$eingabe[$k] : va_formularwert($k), 'fehler' => $f, 'hilfe' => $f === null ? (string)($d['hilfe'] ?? '') : '', 'pflicht' => !empty($d['pflicht'])];
        $h .= feld($k, $d['titel'], $opt + match ($d['typ']) {
            'datum' => ['typ' => 'date'],
            'zeit' => ['typ' => 'time', 'attr' => ['step' => 60]],
            'zeitpunkt' => ['typ' => 'datetime-local', 'attr' => ['step' => 60]],
            'zahl' => ['typ' => 'zahl', 'attr' => ['inputmode' => 'numeric', 'min' => (int)$d['min'], 'max' => (int)$d['max']]],
            'auswahl' => ['typ' => 'auswahl', 'optionen' => $d['optionen']],
            'liste' => ['typ' => 'textarea', 'klasse' => 'breit', 'attr' => ['rows' => 7]],
            default => ['attr' => ['maxlength' => (int)$d['max']]],
        });
    }
    // Abbrechen als Absenden mit Umleitung: lädt immer neu, auch wenn #gruppe schon in der Adresse steht (sonst bliebe die Kachel offen).
    // Speichern steht im Markup zuerst, damit Enter speichert; die Reihenfolge auf dem Schirm dreht .einst-knoepfe um.
    return $h . '</div><div class="aktionen einst-knoepfe"><button type="submit" class="knopf">Speichern</button><button type="submit" class="knopf zweit" name="aktion" value="abbrechen">Abbrechen</button></div></form>';
};

/** Zusammenfassung einer Gruppe (Bausteine als Chips). */
$zusammenfassung = function (string $g): string {
    $z = va_zusammenfassung($g);
    if ($g === 'bausteine') {
        return '<p class="aktionen einst-zus">' . implode('', array_map(fn($x) => chip($x[1] === 'an' ? 'ok' : 'gesperrt', $x[0] . ($x[1] === 'an' ? '' : ': aus')), $z)) . '</p>';
    }
    return '<dl class="liste-def einst-zus">' . implode('', array_map(fn($x) => '<div><dt>' . e($x[0]) . '</dt><dd>' . e($x[1]) . '</dd></div>', $z)) . '</dl>';
};

$kachel = function (string $g) use ($formular, $zusammenfassung, $nurLesen, $istOffen, $eingabe): string {
    [$titel, $hilfe] = VA_GRUPPEN[$g];
    if ($nurLesen) {
        return '<section class="kasten einst-kachel" id="' . e($g) . '"><h2 class="zwischen klein-titel">' . e($titel) . '</h2>' . $zusammenfassung($g) . '</section>';
    }
    // Eine Kachel zur Zeit (name); nach einem Fehler dürfen mehrere offen sein
    return '<section class="kasten einst-kachel" id="' . e($g) . '"><details class="einst-auf"' . ($eingabe === null ? ' name="einstellungen"' : '') . ($istOffen($g) ? ' open' : '') . '>'
        . '<summary class="einst-kopf"><h2 class="zwischen klein-titel">' . e($titel) . '</h2><span class="knopf zweit klein einst-zu" aria-hidden="true">Bearbeiten</span><span class="meta klein einst-offen">wird bearbeitet</span></summary>'
        . $formular($g, $hilfe) . '</details>' . $zusammenfassung($g) . '</section>';
};

$w = start_werte();
$startTitel = start_titel_html($w['start_titel']);

team_seite_kopf($konto, 'Einstellungen', '/team/einstellungen');
?>
<?= $fehler !== [] ? meldung_html(NICHT_GESPEICHERT_TEXT, 'fehler') : '' ?>
<div class="einst-raster">
    <?= $kachel('veranstaltung') ?>
    <?= $kachel('bausteine') ?>
    <section class="kasten einst-kachel einst-start" id="startseite">
        <div class="einst-kopf"><h2 class="zwischen klein-titel">Startseite</h2><a class="knopf klein" href="/team/startseite">Startseite bearbeiten</a></div>
        <div class="einst-mini" aria-label="Kopf der Startseite">
            <?php if (($k = start_kicker_text($w['start_kicker'])) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
            <p class="titel"><?= $startTitel ?></p>
            <?php if ($w['start_unter'] !== ''): ?><p class="meta klein"><?= editor_html($w['start_unter'], 'inline') ?></p><?php endif; ?>
        </div>
        <p class="meta klein">Titel, Texte und Kacheln mit Vorschau für Handy, Tablet und Desktop.</p>
    </section>
    <?php foreach (array_slice(array_keys(VA_GRUPPEN), 2) as $g): ?>
    <?= $kachel($g) ?>
    <?php endforeach; ?>
</div>
<p class="meta klein">Zeiten in Ortszeit (Deutschland). Stichtage gelten bis einschließlich des genannten Tages.</p>
<?php
team_seite_fuss();
