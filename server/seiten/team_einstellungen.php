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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $r = va_pruefen($_POST);
    // Kürzel der Veranstaltung (Adresse /kürzel, E-58) – in der Tabelle veranstaltung
    $kuerzel = mb_strtolower(trim((string)($_POST['kuerzel'] ?? va_zeile()['kuerzel'])));
    if (($kf = va_kuerzel_fehler($kuerzel, va_id())) !== null) {
        $r['fehler']['kuerzel'] = $kf;
    }
    if ($r['fehler'] === []) {
        $geaendert = va_speichern($r['werte'], (int)$konto['id']);
        if ($kuerzel !== va_zeile()['kuerzel']) {
            va_kuerzel_setzen(va_id(), $kuerzel, (int)$konto['id']);
            $geaendert[] = 'kuerzel';
        }
        flash_setzen('ok', $geaendert === [] ? 'Nichts geändert.' : 'Gespeichert (' . count($geaendert) . ' ' . (count($geaendert) === 1 ? 'Einstellung' : 'Einstellungen') . ' geändert). Die Startseite zeigt die neuen Werte sofort.');
        umleiten('/team/einstellungen');
    }
    $fehler = $r['fehler'];
    $eingabe = $_POST;
}

/** Wert fürs Formular: nach einem Fehler die Eingabe, sonst der gespeicherte Wert. */
$wert = function (string $k) use ($eingabe): string {
    return $eingabe !== null ? (string)($eingabe[$k] ?? '') : va_formularwert($k);
};

$offen = isset($_GET['bearbeiten']) || $eingabe !== null;
team_seite_kopf($konto, 'Einstellungen', '/team/einstellungen');
?>
<div class="sperre <?= $offen ? 'offen' : 'zu' ?>" role="status">
    <svg class="sperre-symbol" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" fill="currentColor"/><path d="<?= $offen ? 'M8 11V7a4 4 0 0 1 7.5-2' : 'M8 11V7a4 4 0 0 1 8 0v4' ?>" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    <span class="sperre-text"><?= $offen ? '<strong>Entsperrt.</strong> Änderungen wirken nach dem Speichern sofort auf der Startseite und bei der Anmeldung.' : '<strong>Gesperrt.</strong> Damit nichts aus Versehen geändert wird – zum Bearbeiten entsperren.' ?></span>
    <a class="knopf <?= $offen ? 'zweit' : '' ?> klein-knopf" href="/team/einstellungen<?= $offen ? '' : '?bearbeiten=1' ?>"><?= $offen ? 'Wieder sperren' : 'Entsperren' ?></a>
</div>
<?php
if ($fehler !== []) {
    echo '<div class="meldung fehler" role="alert">Nicht gespeichert. Bitte die markierten Felder prüfen.</div>';
}
?>
<form method="post" action="/team/einstellungen" class="einstellungen" novalidate>
    <?= csrf_feld() ?>
    <?php foreach (VA_GRUPPEN as $g => [$gTitel, $gHilfe]): ?>
    <fieldset class="kasten gruppe" id="<?= e($g) ?>"<?= $offen ? '' : ' disabled' ?>>
        <legend class="zwischen"><?= e($gTitel) ?></legend>
        <p class="meta klein"><?= e($gHilfe) ?></p>
        <div class="felder">
        <?php if ($g === 'veranstaltung'): $kf = $fehler['kuerzel'] ?? null; ?>
            <div class="feld">
                <label for="kuerzel">Kürzel (Adresse)</label>
                <input type="text" id="kuerzel" name="kuerzel" maxlength="40" required aria-describedby="h-kuerzel"<?= $kf !== null ? ' aria-invalid="true"' : '' ?> value="<?= e($eingabe !== null ? (string)($eingabe['kuerzel'] ?? '') : va_zeile()['kuerzel']) ?>">
                <?php if ($kf !== null): ?><p class="feldfehler" id="h-kuerzel"><?= e($kf) ?></p>
                <?php else: ?><p class="hilfe" id="h-kuerzel">Die Veranstaltung ist unter <span class="mono"><?= e(parse_url(url('/'), PHP_URL_HOST)) ?>/<?= e(va_zeile()['kuerzel']) ?></span> erreichbar. Kleinbuchstaben ohne Umlaute, Ziffern, Bindestrich.</p><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php foreach (VA_KATALOG as $k => $d): if ($d['gruppe'] !== $g) continue;
            $f = $fehler[$k] ?? null;
            $hilfeId = 'h-' . $k;
            $attr = ' id="' . e($k) . '" name="' . e($k) . '"' . ($f !== null ? ' aria-invalid="true"' : '')
                . (isset($d['hilfe']) || $f !== null ? ' aria-describedby="' . $hilfeId . '"' : '') . (!empty($d['pflicht']) ? ' required' : ''); ?>
            <div class="feld<?= $d['typ'] === 'liste' ? ' breit' : '' ?>">
                <label for="<?= e($k) ?>"><?= e($d['titel']) ?></label>
                <?php switch ($d['typ']):
                    case 'datum': ?><input type="date"<?= $attr ?> value="<?= e($wert($k)) ?>"><?php break;
                    case 'zeit': ?><input type="time" step="60"<?= $attr ?> value="<?= e($wert($k)) ?>"><?php break;
                    case 'zeitpunkt': ?><input type="datetime-local" step="60"<?= $attr ?> value="<?= e($wert($k)) ?>"><?php break;
                    case 'zahl': ?><input type="number" inputmode="numeric" min="<?= (int)$d['min'] ?>" max="<?= (int)$d['max'] ?>"<?= $attr ?> value="<?= e($wert($k)) ?>"><?php break;
                    case 'auswahl': ?><select<?= $attr ?>><?php foreach ($d['optionen'] as $ow => $ot): ?><option value="<?= e($ow) ?>"<?= $wert($k) === $ow ? ' selected' : '' ?>><?= e($ot) ?></option><?php endforeach; ?></select><?php break;
                    case 'liste': ?><textarea rows="7"<?= $attr ?>><?= e($wert($k)) ?></textarea><?php break;
                    default: ?><input type="text" maxlength="<?= (int)$d['max'] ?>"<?= $attr ?> value="<?= e($wert($k)) ?>"><?php
                endswitch; ?>
                <?php if ($f !== null): ?><p class="feldfehler" id="<?= $hilfeId ?>"><?= e($f) ?></p>
                <?php elseif (isset($d['hilfe'])): ?><p class="hilfe" id="<?= $hilfeId ?>"><?= e($d['hilfe']) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </fieldset>
    <?php endforeach; ?>
    <p class="meta klein">Zeiten in Ortszeit (Deutschland). Stichtage gelten bis einschließlich des genannten Tages.</p>
    <?php if ($offen): ?><button type="submit" class="knopf">Einstellungen speichern</button><?php endif; ?>
</form>
<?php
team_seite_fuss();
