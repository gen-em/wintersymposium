<?php
declare(strict_types=1);

/*
 * Texte (AP-5, E-49; pflegbare Texte aus AP-2), seit AP-12 (E-60) zwei Seiten:
 * /team/startseite – Texte der Startseite je Veranstaltung (Verwaltung, Admin
 * und Betreiber); /team/texte – Impressum und Datenschutz, gemeinsam für alle
 * Veranstaltungen (Betrieb, nur Betreiber). Derselbe Editor wie bei den
 * Mailings (E-54), gespeichert als bereinigtes HTML (inhalt_lib.php);
 * Änderungen gelten sofort und stehen im Protokoll.
 */

$textSeite = ($TEXTE_SEITE ?? '') === '/team/startseite' ? '/team/startseite' : '/team/texte';
$startseite = $textSeite === '/team/startseite';
$konto = $startseite ? team_tor() : team_tor(['betreiber']);
if ($startseite) {
    team_schreibschutz('/team/startseite'); // abgeschlossene Veranstaltung: nur lesen (E-58)
}
$betreiber = $konto['rolle'] === 'betreiber';
// Schlüssel => [Titel, Art (inline: eine Zeile mit fett/kursiv/Link; block: ganzer Text; text: ohne Auszeichnung), nur Betreiber]
const TEXTE_KATALOG = [
    'start_kicker' => ['Zeile über dem Titel', 'inline', false],
    'start_titel' => ['Titel (kursiv = lila)', 'inline', false],
    'start_unter' => ['Untertitel', 'inline', false],
    'start_sticker' => ['Sticker (z. B. „Kostenlos“)', 'text', false],
    'start_infos_titel' => ['Überschrift der Infokästen', 'inline', false],
    'start_infos' => ['Infokästen (je Kasten eine Zwischenüberschrift „H“, darunter der Text)', 'block', false],
    'start_cme' => ['Hinweis Fortbildungspunkte („Merke“)', 'block', false],
    'impressum' => ['Impressum', 'block', true],
    'datenschutz' => ['Datenschutzerklärung', 'block', true],
];
$fehler = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $geaendert = [];
    foreach (TEXTE_KATALOG as $k => [$titel, $art, $nurBetreiber]) {
        if (!isset($_POST[$k]) || $nurBetreiber === $startseite) {
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
            audit_schreiben('texte', 'Geändert: ' . implode(', ', $geaendert), (int)$konto['id'], $startseite ? -1 : 0);
        }
        flash_setzen('ok', $geaendert === [] ? 'Nichts geändert.' : 'Gespeichert. Die Seiten zeigen den neuen Text sofort.');
        umleiten($textSeite);
    }
}
team_seite_kopf($konto, $startseite ? 'Startseite' : 'Texte', $textSeite);
?>
<?= fehler_html($fehler) ?>
<p class="meta">Derselbe Editor wie bei den Mailings: Knöpfe für fett, kursiv, Zwischenüberschrift, Listen, Link, Bild, Knopf und HTML-Quelltext; sie zeigen an, was an der Schreibmarke gilt, und schalten es an und aus. Ohne JavaScript bleibt ein einfaches Textfeld.</p>
<p class="meta klein"><?= $startseite ? 'Texte der Startseite dieser Veranstaltung.' : 'Impressum und Datenschutz gelten für alle Veranstaltungen.' ?></p>
<form class="form breit-form" method="post" action="<?= e($textSeite) ?>">
    <?= csrf_feld() ?>
    <?php foreach (TEXTE_KATALOG as $k => [$titel, $art, $nurBetreiber]): if ($nurBetreiber === $startseite) continue; ?>
    <div class="feld"><label for="t-<?= e($k) ?>"><?= e($titel) ?></label>
        <?php if ($art === 'text'): ?><input id="t-<?= e($k) ?>" name="<?= e($k) ?>" type="text" maxlength="300" value="<?= e(inhalt($k)) ?>">
        <?php elseif ($art === 'inline'): ?><textarea id="t-<?= e($k) ?>" name="<?= e($k) ?>" rows="2"<?= editor_attribute('inline') ?>><?= e(inhalt($k)) ?></textarea>
        <?php else: ?><textarea id="t-<?= e($k) ?>" name="<?= e($k) ?>" rows="10"<?= editor_attribute() ?>><?= e(inhalt($k)) ?></textarea><?php endif; ?>
        <?php if ($k === 'datenschutz'): ?><p class="hilfe">Muss Anmeldung, Konten, Mails und Löschfristen beschreiben (betrieb.md, Abschnitt Anmeldung).</p><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div class="aktionen"><button class="knopf" type="submit">Speichern</button><?php if ($startseite): ?><a class="knopf zweit" href="/<?= e(va_zeile()['kuerzel'] ?? '') ?>" target="_blank" rel="noopener">Startseite ansehen</a><?php else: ?><a class="knopf zweit" href="/datenschutz" target="_blank" rel="noopener">Datenschutz ansehen</a><?php endif; ?></div>
</form>
<?php
team_seite_fuss();
