<?php
declare(strict_types=1);

/*
 * Texte (AP-5, E-49; pflegbare Texte aus AP-2): /team/texte – Impressum und
 * Datenschutz, gemeinsam für alle Veranstaltungen (Betrieb, nur Betreiber).
 * Die Texte der Startseite stehen je Veranstaltung unter Verwaltung →
 * Startseite (team_startseite.php, START_TEXTE). Derselbe Editor wie bei
 * den Mailings (E-54), gespeichert als bereinigtes HTML (inhalt_lib.php).
 */

$konto = team_tor(['betreiber']);
// Schlüssel => Titel (ganzer Text mit Editor)
const TEXTE_KATALOG = [
    'impressum' => 'Impressum',
    'datenschutz' => 'Datenschutzerklärung',
];
$fehler = [];
if (ist_post()) {
    $geaendert = [];
    foreach (TEXTE_KATALOG as $k => $titel) {
        if (!isset($_POST[$k])) {
            continue;
        }
        $roh = post_text($k);
        if (mb_strlen($roh) > EDITOR_MAX) {
            $fehler[] = $titel . ': zu lang.';
            continue;
        }
        $neu = html_bereinigen($roh, 'block', true);
        if ($neu !== inhalt($k)) {
            inhalt_setzen($k, $neu, (int)$konto['id']);
            $geaendert[] = $k;
        }
    }
    if ($fehler === []) {
        if ($geaendert !== []) {
            inhalt_protokollieren('Geändert', $geaendert, (int)$konto['id'], false);
        }
        fertig('ok', $geaendert === [] ? 'Nichts geändert.' : 'Gespeichert. Die Seiten zeigen den neuen Text sofort.', '/team/texte');
    }
}
team_seite_kopf($konto, 'Texte', '/team/texte');
?>
<?= fehler_html($fehler) ?>
<p class="meta">Derselbe Editor wie bei den Mailings: Knöpfe für fett, kursiv, Zwischenüberschrift, Listen, Link, Bild, Knopf und HTML-Quelltext; sie zeigen an, was an der Schreibmarke gilt, und schalten es an und aus. Ohne JavaScript bleibt ein einfaches Textfeld.</p>
<p class="meta klein">Impressum und Datenschutz gelten für alle Veranstaltungen. Die Texte der Startseite stehen je Veranstaltung unter Verwaltung → <a href="/team/startseite">Startseite</a>.</p>
<form class="form breit-form" method="post" action="/team/texte">
    <?= csrf_feld() ?>
    <?php foreach (TEXTE_KATALOG as $k => $titel): ?>
    <div class="feld"><label for="t-<?= e($k) ?>"><?= e($titel) ?></label>
        <textarea id="t-<?= e($k) ?>" name="<?= e($k) ?>" rows="10"<?= editor_attribute() ?>><?= e(inhalt($k)) ?></textarea>
        <?php if ($k === 'datenschutz'): ?><p class="hilfe">Muss Anmeldung, Konten, Mails und Löschfristen beschreiben (betrieb.md, Abschnitt Anmeldung).</p><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div class="aktionen"><button class="knopf" type="submit">Speichern</button><a class="knopf zweit" href="/datenschutz" target="_blank" rel="noopener">Datenschutz ansehen</a></div>
</form>
<?php
team_seite_fuss();
