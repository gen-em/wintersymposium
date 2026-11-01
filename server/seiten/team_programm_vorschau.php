<?php
declare(strict_types=1);

/* Vorschau: das Programm aus dem Arbeitsstand, so wie es nach dem Veröffentlichen auf der Startseite stünde. */

$konto = team_tor();
seite_kopf('Vorschau Programm', ['konto' => $konto, 'aktiv' => '/team', 'skripte' => ['programm.js']]);
?>
<div class="innen">
    <div class="entwurf-banner vorschau" role="status">
        <span><strong>Vorschau.</strong> So sähe das Programm auf der Startseite nach „Jetzt veröffentlichen“ aus. Besucherinnen und Besucher sehen noch den veröffentlichten Stand.</span>
        <span class="eb-knoepfe"><a class="knopf zweit" href="/team/programm">Zurück zur Bearbeitung</a></span>
    </div>
</div>
<section class="abschnitt" id="programm" aria-labelledby="programm-titel"><div class="innen">
    <p class="kicker">Programm</p>
    <h2 class="zwischen" id="programm-titel">Der Abend im Überblick</h2>
    <?= programm_html(programm_arbeitsstand()) ?>
</div></section>
<?php
seite_fuss();
