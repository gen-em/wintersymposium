<?php
declare(strict_types=1);

/*
 * Impressum und Datenschutz (AP-2). Text aus der Tabelle inhalt, gepflegt
 * im Betreiber-Bereich (AP-5). Solange der Text leer ist, erscheint ein
 * Hinweis – vor dem Anmeldestart müssen beide gefüllt sein (Checkliste
 * Konzept Abschnitt 9).
 *
 * Erwartet $rechtstext = ['schluessel' => …, 'titel' => …] von der Seite.
 */

$text = inhalt($rechtstext['schluessel']);
seite_kopf($rechtstext['titel'], ['suchmaschinen' => true]);
?>
<?= seiten_kopfzeile($rechtstext['titel']) ?>
<div class="innen">
    <div class="text">
        <?= $text !== '' ? editor_html($text) : leer_hinweis('Dieser Text wird gerade erstellt.') ?>
    </div>
</div>
<?php
seite_fuss();
