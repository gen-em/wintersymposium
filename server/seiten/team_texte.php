<?php
declare(strict_types=1);

/*
 * Texte (AP-5, E-49; pflegbare Texte aus AP-2): Startseite für Admin und
 * Betreiber, Impressum und Datenschutz nur für Betreiber (Admin 2026-10-29).
 * Auszeichnung: **fett**, *kursiv*, [Link](https://…), ## Überschrift,
 * Listen mit „- “; Änderungen gelten sofort und stehen im Protokoll.
 */

$konto = team_tor();
$betreiber = $konto['rolle'] === 'betreiber';
const TEXTE_KATALOG = [
    'start_kicker' => ['Startseite: Zeile über dem Titel', 1, false],
    'start_titel' => ['Startseite: Titel', 1, false],
    'start_unter' => ['Startseite: Untertitel', 2, false],
    'start_sticker' => ['Startseite: Sticker (z. B. „Kostenlos“)', 1, false],
    'start_infos_titel' => ['Startseite: Überschrift der Infokästen', 1, false],
    'start_infos' => ['Startseite: Infokästen (je Kasten „## Titel“, darunter der Text)', 10, false],
    'start_cme' => ['Startseite: Hinweis Fortbildungspunkte („Merke“)', 4, false],
    'impressum' => ['Impressum', 14, true],
    'datenschutz' => ['Datenschutzerklärung', 20, true],
];
$fehler = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $geaendert = [];
    foreach (TEXTE_KATALOG as $k => [$titel, , $nurBetreiber]) {
        if (!isset($_POST[$k]) || ($nurBetreiber && !$betreiber)) {
            continue;
        }
        $neu = str_replace("\r", '', trim((string)$_POST[$k]));
        if (mb_strlen($neu) > 20000) {
            $fehler[] = $titel . ': höchstens 20 000 Zeichen.';
            continue;
        }
        if ($neu !== inhalt($k)) {
            inhalt_setzen($k, $neu, (int)$konto['id']);
            $geaendert[] = $k;
        }
    }
    if ($fehler === []) {
        if ($geaendert !== []) {
            audit_schreiben('texte', 'Geändert: ' . implode(', ', $geaendert), (int)$konto['id']);
        }
        flash_setzen('ok', $geaendert === [] ? 'Nichts geändert.' : 'Gespeichert. Die Seiten zeigen den neuen Text sofort.');
        umleiten('/team/texte');
    }
}
team_seite_kopf($konto, 'Texte', '/team/texte');
?>
<?= fehler_html($fehler) ?>
<p class="meta klein">Auszeichnung: <span class="mono">**fett**</span>, <span class="mono">*kursiv*</span>, <span class="mono">[Linktext](https://…)</span>, Überschrift mit <span class="mono">## </span>, Liste mit <span class="mono">- </span> am Zeilenanfang, Leerzeile = neuer Absatz.</p>
<form class="form breit-form" method="post" action="/team/texte">
    <?= csrf_feld() ?>
    <?php foreach (TEXTE_KATALOG as $k => [$titel, $zeilen, $nurBetreiber]): if ($nurBetreiber && !$betreiber) continue; ?>
    <div class="feld"><label for="t-<?= e($k) ?>"><?= e($titel) ?></label>
        <?php if ($zeilen === 1): ?><input id="t-<?= e($k) ?>" name="<?= e($k) ?>" type="text" maxlength="300" value="<?= e(inhalt($k)) ?>">
        <?php else: ?><textarea id="t-<?= e($k) ?>" name="<?= e($k) ?>" rows="<?= $zeilen ?>"><?= e(inhalt($k)) ?></textarea><?php endif; ?>
        <?php if ($k === 'datenschutz'): ?><p class="hilfe">Muss Anmeldung, Konten, Mails und Löschfristen beschreiben (betrieb.md, Abschnitt Anmeldung).</p><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div class="aktionen"><button class="knopf" type="submit">Speichern</button><a class="knopf zweit" href="/" target="_blank" rel="noopener">Startseite ansehen</a></div>
</form>
<?php if (!$betreiber): ?><p class="meta klein">Impressum und Datenschutz bearbeitet ein Betreiber.</p><?php endif; ?>
<?php
team_seite_fuss();
