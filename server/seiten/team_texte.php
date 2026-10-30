<?php
declare(strict_types=1);

/*
 * Texte (AP-5, E-49; pflegbare Texte aus AP-2): Startseite für Admin und
 * Betreiber, Impressum und Datenschutz nur für Betreiber (Admin 2026-10-29).
 * Derselbe Editor wie bei den Mailings (E-54), gespeichert als bereinigtes HTML
 * (inhalt_lib.php); Änderungen gelten sofort und stehen im Protokoll.
 */

$konto = team_tor();
$betreiber = $konto['rolle'] === 'betreiber';
// Schlüssel => [Titel, Art (inline: eine Zeile mit fett/kursiv/Link; block: ganzer Text; text: ohne Auszeichnung), nur Betreiber]
const TEXTE_KATALOG = [
    'start_kicker' => ['Startseite: Zeile über dem Titel', 'inline', false],
    'start_titel' => ['Startseite: Titel (kursiv = lila)', 'inline', false],
    'start_unter' => ['Startseite: Untertitel', 'inline', false],
    'start_sticker' => ['Startseite: Sticker (z. B. „Kostenlos“)', 'text', false],
    'start_infos_titel' => ['Startseite: Überschrift der Infokästen', 'inline', false],
    'start_infos' => ['Startseite: Infokästen (je Kasten eine Zwischenüberschrift „H“, darunter der Text)', 'block', false],
    'start_cme' => ['Startseite: Hinweis Fortbildungspunkte („Merke“)', 'block', false],
    'impressum' => ['Impressum', 'block', true],
    'datenschutz' => ['Datenschutzerklärung', 'block', true],
];
$fehler = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $geaendert = [];
    foreach (TEXTE_KATALOG as $k => [$titel, $art, $nurBetreiber]) {
        if (!isset($_POST[$k]) || ($nurBetreiber && !$betreiber)) {
            continue;
        }
        $roh = (string)$_POST[$k];
        if (mb_strlen($roh) > EDITOR_MAX) {
            $fehler[] = $titel . ': zu lang.';
            continue;
        }
        $neu = $art === 'text' ? trim(preg_replace('/\s+/u', ' ', $roh) ?? '') : editor_bereinigen($roh, $art, $art === 'block');
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
<p class="meta">Derselbe Editor wie bei den Mailings: Knöpfe für fett, kursiv, Zwischenüberschrift, Listen, Link und Bild; sie zeigen an, was an der Schreibmarke gilt, und schalten es an und aus. Ohne JavaScript bleibt ein einfaches Textfeld.</p>
<form class="form breit-form" method="post" action="/team/texte">
    <?= csrf_feld() ?>
    <?php foreach (TEXTE_KATALOG as $k => [$titel, $art, $nurBetreiber]): if ($nurBetreiber && !$betreiber) continue; ?>
    <div class="feld"><label for="t-<?= e($k) ?>"><?= e($titel) ?></label>
        <?php if ($art === 'text'): ?><input id="t-<?= e($k) ?>" name="<?= e($k) ?>" type="text" maxlength="300" value="<?= e(inhalt($k)) ?>">
        <?php elseif ($art === 'inline'): ?><textarea id="t-<?= e($k) ?>" name="<?= e($k) ?>" rows="2" data-editor="inline"><?= e(inhalt($k)) ?></textarea>
        <?php else: ?><textarea id="t-<?= e($k) ?>" name="<?= e($k) ?>" rows="10" data-editor="block" data-werkzeug="fett kursiv titel | liste nliste link bild" data-bild-url="/team/texte/bild"><?= e(inhalt($k)) ?></textarea><?php endif; ?>
        <?php if ($k === 'datenschutz'): ?><p class="hilfe">Muss Anmeldung, Konten, Mails und Löschfristen beschreiben (betrieb.md, Abschnitt Anmeldung).</p><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div class="aktionen"><button class="knopf" type="submit">Speichern</button><a class="knopf zweit" href="/" target="_blank" rel="noopener">Startseite ansehen</a></div>
</form>
<?php if (!$betreiber): ?><p class="meta klein">Impressum und Datenschutz bearbeitet ein Betreiber.</p><?php endif; ?>
<?php
team_seite_fuss();
