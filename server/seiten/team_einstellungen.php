<?php
declare(strict_types=1);

/*
 * Veranstaltungs-Einstellungen (AP-3 Schritt 2, R-11) für Admin und
 * Betreiber. Katalog und Prüfung: lib/veranstaltung_lib.php.
 */

$konto = team_tor();
$fehler = [];
$eingabe = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $r = va_pruefen($_POST);
    if ($r['fehler'] === []) {
        $geaendert = va_speichern($r['werte'], (int)$konto['id']);
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

team_seite_kopf($konto, 'Einstellungen', '/team/einstellungen');
if ($fehler !== []) {
    echo '<div class="meldung fehler" role="alert">Nicht gespeichert. Bitte die markierten Felder prüfen.</div>';
}
?>
<form method="post" action="/team/einstellungen" class="einstellungen" novalidate>
    <?= csrf_feld() ?>
    <?php foreach (VA_GRUPPEN as $g => [$gTitel, $gHilfe]): ?>
    <fieldset class="kasten gruppe">
        <legend class="zwischen"><?= e($gTitel) ?></legend>
        <p class="meta klein"><?= e($gHilfe) ?></p>
        <div class="felder">
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
    <button type="submit" class="knopf">Einstellungen speichern</button>
</form>
<?php
team_seite_fuss();
