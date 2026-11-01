<?php
declare(strict_types=1);

/*
 * Veranstaltungs-Einstellungen (AP-3 Schritt 2, R-11) für Admin und
 * Betreiber. Katalog und Prüfung: lib/veranstaltung_lib.php.
 * Gesperrt, bis man „Entsperren“ wählt (Rückmeldung 2026-10-30: nichts aus
 * Versehen ändern); nach dem Speichern wieder gesperrt.
 */

$konto = team_tor();
team_schreibschutz('/team/einstellungen'); // abgeschlossene Veranstaltung: nur lesen (E-58)
$fehler = [];
$eingabe = null;

if (ist_post()) {
    $r = va_pruefen($_POST);
    // Kürzel der Veranstaltung (Adresse /kürzel, E-58) – in der Tabelle veranstaltung
    $kuerzel = mb_strtolower(trim(isset($_POST['kuerzel']) ? post_text('kuerzel') : va_zeile()['kuerzel']));
    if (($kf = va_kuerzel_fehler($kuerzel, va_id())) !== null) {
        $r['fehler']['kuerzel'] = $kf;
    }
    // Texte der Startseite (Admin 2026-10-31: alles einer Veranstaltung auf einer Seite)
    foreach (START_TEXTE as $tk => [$tt, $ta]) {
        if (isset($_POST[$tk]) && (mb_strlen(post_text($tk)) > EDITOR_MAX || ($ta === 'text' && mb_strlen(post_text($tk)) > 120))) {
            $r['fehler'][$tk] = 'Zu lang.';
        }
    }
    if ($r['fehler'] === []) {
        $geaendert = va_speichern($r['werte'], (int)$konto['id']);
        [$textGeaendert] = start_texte_speichern($_POST, (int)$konto['id']);
        if ($textGeaendert !== []) {
            audit_schreiben('texte', 'Startseite geändert: ' . implode(', ', $textGeaendert), (int)$konto['id'], -1);
            $geaendert = array_merge($geaendert, $textGeaendert);
        }
        if ($kuerzel !== va_zeile()['kuerzel']) {
            va_kuerzel_setzen(va_id(), $kuerzel, (int)$konto['id']);
            $geaendert[] = 'kuerzel';
        }
        fertig('ok', $geaendert === [] ? 'Nichts geändert.' : 'Gespeichert (' . count($geaendert) . ' ' . (count($geaendert) === 1 ? 'Einstellung' : 'Einstellungen') . ' geändert). Die Startseite zeigt die neuen Werte sofort.', '/team/einstellungen');
    }
    $fehler = $r['fehler'];
    $eingabe = $_POST;
}

$offen = isset($_GET['bearbeiten']) || $eingabe !== null;
team_seite_kopf($konto, 'Einstellungen', '/team/einstellungen');
?>
<div class="sperre <?= $offen ? 'offen' : 'zu' ?>" role="status">
    <?= icon($offen ? 'lock-open' : 'lock', 22, 'icon sperre-symbol') ?>
    <span class="sperre-text"><?= $offen ? '<strong>Entsperrt.</strong> Änderungen wirken nach dem Speichern sofort auf der Startseite und bei der Anmeldung.' : '<strong>Gesperrt.</strong> Damit nichts aus Versehen geändert wird – zum Bearbeiten entsperren.' ?></span>
    <a class="knopf <?= $offen ? 'zweit' : '' ?> klein" href="/team/einstellungen<?= $offen ? '' : '?bearbeiten=1' ?>"><?= $offen ? 'Wieder sperren' : 'Entsperren' ?></a>
</div>
<?= $fehler !== [] ? meldung_html(NICHT_GESPEICHERT_TEXT, 'fehler') : '' ?>
<form method="post" action="/team/einstellungen" class="einstellungen" novalidate>
    <?= csrf_feld() ?>
    <?php foreach (VA_GRUPPEN as $g => [$gTitel, $gHilfe]): ?>
    <fieldset class="kasten gruppe" id="<?= e($g) ?>"<?= $offen ? '' : ' disabled' ?>>
        <legend class="zwischen"><?= e($gTitel) ?></legend>
        <p class="meta klein"><?= e($gHilfe) ?></p>
        <div class="felder">
        <?php if ($g === 'veranstaltung'): $kf = $fehler['kuerzel'] ?? null; ?>
            <?= feld('kuerzel', 'Kürzel (Adresse)', ['wert' => $eingabe !== null ? (string)($eingabe['kuerzel'] ?? '') : va_zeile()['kuerzel'], 'fehler' => $kf, 'pflicht' => true, 'attr' => ['maxlength' => 40],
                'hilfe_html' => $kf !== null ? null : 'Die Veranstaltung ist unter <span class="mono">' . e(parse_url(url('/'), PHP_URL_HOST)) . '/' . e(va_zeile()['kuerzel']) . '</span> erreichbar. Kleinbuchstaben ohne Umlaute, Ziffern, Bindestrich.']) ?>
        <?php endif; ?>
        <?php foreach (VA_KATALOG as $k => $d): if ($d['gruppe'] !== $g) continue;
            // Mit Fehler steht der Fehler an der Stelle des Hilfetexts
            $f = $fehler[$k] ?? null;
            $opt = ['wert' => wert($eingabe ?? [], $eingabe === null ? [$k => va_formularwert($k)] : [], $k), 'fehler' => $f, 'hilfe' => $f === null ? (string)($d['hilfe'] ?? '') : '', 'pflicht' => !empty($d['pflicht'])];
            echo feld($k, $d['titel'], $opt + match ($d['typ']) {
                'datum' => ['typ' => 'date'],
                'zeit' => ['typ' => 'time', 'attr' => ['step' => 60]],
                'zeitpunkt' => ['typ' => 'datetime-local', 'attr' => ['step' => 60]],
                'zahl' => ['typ' => 'zahl', 'attr' => ['inputmode' => 'numeric', 'min' => (int)$d['min'], 'max' => (int)$d['max']]],
                'auswahl' => ['typ' => 'auswahl', 'optionen' => $d['optionen']],
                'liste' => ['typ' => 'textarea', 'klasse' => 'breit', 'attr' => ['rows' => 7]],
                default => ['attr' => ['maxlength' => (int)$d['max']]],
            });
        ?>
        <?php endforeach; ?>
        </div>
    </fieldset>
    <?php endforeach; ?>
    <fieldset class="kasten gruppe" id="startseite"<?= $offen ? '' : ' disabled' ?>>
        <legend class="zwischen">Startseite</legend>
        <p class="meta klein">Zeile über dem Titel: „<?= e(reihe_name() . ' · ' . va_kurz()) ?>“ (Name der Reihe · Kurzname). Titel: der Name der Veranstaltung, das erste Wort weiß, der Rest lila (ein Wort: ganz lila).</p>
        <div class="felder">
        <?php foreach (START_TEXTE as $k => [$tTitel, $art, $tHilfe]):
            $f = $fehler[$k] ?? null;
            $w = $eingabe !== null ? (string)($eingabe[$k] ?? '') : start_text_wert($k); ?>
            <div class="feld breit">
                <label for="t-<?= e($k) ?>"><?= e($tTitel) ?></label>
                <?php if (!$offen): ?>
                <div><?= $w === '' ? '<span class="meta klein">leer</span>' : ($art === 'text' ? e($w) : editor_html($w, $art)) ?></div>
                <?php elseif ($art === 'text'): ?>
                <input type="text" id="t-<?= e($k) ?>" name="<?= e($k) ?>" maxlength="120" value="<?= e($w) ?>"<?= $f !== null ? ' aria-invalid="true"' : '' ?>>
                <?php else: ?>
                <textarea id="t-<?= e($k) ?>" name="<?= e($k) ?>" rows="<?= $art === 'inline' ? 2 : 6 ?>"<?= editor_attribute($art) ?>><?= e($w) ?></textarea>
                <?php endif; ?>
                <?php if ($f !== null): ?><p class="feldfehler"><?= e($f) ?></p><?php elseif ($tHilfe !== ''): ?><p class="hilfe"><?= e($tHilfe) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </fieldset>
    <p class="meta klein">Zeiten in Ortszeit (Deutschland). Stichtage gelten bis einschließlich des genannten Tages.</p>
    <?php if ($offen): ?><button type="submit" class="knopf">Einstellungen speichern</button><?php endif; ?>
</form>
<?php
team_seite_fuss();
