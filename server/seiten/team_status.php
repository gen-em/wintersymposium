<?php
declare(strict_types=1);

/*
 * Betrieb → Status (AP-15, E-65; nur BetreiberInnen): Ampel-Zeilen aus
 * betrieb_status(), dieselbe Quelle wie /health. Dazu die Cron-Anleitung
 * (Shell-Cronjob als Standard, E-64 (2); URL mit Token erst nach Klick und
 * protokolliert) und das Fehlerprotokoll (ansehen auf Abruf, leeren).
 * Der Serverpfad im Kommando erscheint nur hier (R-10).
 */

$konto = team_tor(['betreiber']);
$ich = (int)$konto['id'];
$mitToken = false;
$logZeilen = null;

if (ist_post()) {
    switch (aktion()) {
        case 'token_zeigen':
            betrieb_token_angezeigt($ich);
            $mitToken = true;
            break;
        case 'log_zeigen':
            $logZeilen = betrieb_fehlerlog_ende();
            break;
        case 'log_leeren':
            $ok = betrieb_fehlerlog_leeren($ich);
            fertig($ok ? 'ok' : 'fehler', $ok ? 'Fehlerprotokoll geleert.' : 'Das Fehlerprotokoll ließ sich nicht leeren (Schreibrechte).', '/team/status');
    }
}

$zeilen = betrieb_status();
$offen = array_values(array_filter($zeilen, fn($z) => $z['ampel'] !== 'ok'));
$gruen = array_values(array_filter($zeilen, fn($z) => $z['ampel'] === 'ok'));
usort($offen, fn($a, $b) => ($a['ampel'] === 'fehler' ? 0 : 1) <=> ($b['ampel'] === 'fehler' ? 0 : 1));
$anl = betrieb_cron_anleitung($mitToken);

/** Zusätze in den Details einer Zeile (Cron-Anleitung, Fehlerprotokoll). */
$zusatz = function (array $z) use ($anl, $mitToken, $logZeilen): string {
    $h = '';
    if ($z['id'] === 'cron') {
        $h .= '<p class="unterkopf abstand-oben-klein">Einrichten: Shell-Cronjob beim Hoster</p>'
            . '<p class="meta klein">Im Kundenmenü des Hosters einen Cronjob anlegen, Art „Befehl“ bzw. „Shell“, Zeitplan alle 5 Minuten. Verlangt der Hoster eine bestimmte PHP-Version, statt <span class="mono">php</span> deren Pfad eintragen.</p>'
            . '<div class="form">' . feld('cron-zeitplan', 'Zeitplan', ['wert' => $anl['zeitplan'], 'attr' => ['class' => 'mono', 'readonly' => true]])
            . feld('cron-kommando', 'Befehl', ['wert' => $anl['kommando'], 'attr' => ['class' => 'mono', 'readonly' => true]]) . '</div>'
            . '<p class="aktionen"><button type="button" class="knopf klein" data-kopieren="cron-kommando">Befehl kopieren</button><span class="meta klein" role="status" data-kopiert="cron-kommando"></span></p>'
            . '<p class="unterkopf abstand-oben-klein">Ausweichweg: Aufruf einer URL</p>'
            . '<p class="meta klein">Nur, wenn der Hoster keine Befehle erlaubt. Der Token steht dann in der URL und landet in Zugriffslogs; das Anzeigen wird protokolliert.</p>'
            . feld('cron-url', 'URL', ['wert' => $anl['url'], 'attr' => ['class' => 'mono', 'readonly' => true]]);
        if ($mitToken) {
            $h .= '<p class="aktionen"><button type="button" class="knopf zweit klein" data-kopieren="cron-url">URL kopieren</button><span class="meta klein" role="status" data-kopiert="cron-url"></span></p>';
        } else {
            $h .= aktion_knopf('/team/status#zeile-cron', 'token_zeigen', [], 'URL mit Token anzeigen', 'knopf zweit klein', ['form_klasse' => 'aktionen']);
        }
    }
    if ($z['id'] === 'fehlerlog') {
        if ($logZeilen !== null) {
            $h .= '<p class="unterkopf abstand-oben-klein">Letzte ' . BETRIEB_LOG_ZEILEN . ' Zeilen</p>'
                . ($logZeilen === [] ? leer_hinweis('Das Fehlerprotokoll ist leer.') : '<pre class="status-log">' . e(implode("\n", $logZeilen)) . '</pre>');
        }
        $h .= '<div class="aktionen abstand-oben-klein">';
        if ($logZeilen === null) {
            $h .= aktion_knopf('/team/status#zeile-fehlerlog', 'log_zeigen', [], 'Letzte ' . BETRIEB_LOG_ZEILEN . ' Zeilen anzeigen');
        }
        $h .= bestaetigung_html('Leeren', 'Alle Einträge im Fehlerprotokoll werden gelöscht. Vorher ansehen, falls etwas zu klären ist.', 'Ja, leeren', ['aktion' => 'log_leeren'],
            ['ziel' => '/team/status', 'titel' => 'Fehlerprotokoll leeren?', 'knopfklasse' => 'knopf gefahr klein']) . '</div>';
    }
    return $h;
};

/** Eine Ampel-Zeile; Details aufklappbar, offen nach einer Aktion in dieser Zeile. */
$zeile = function (array $z) use ($zusatz, $mitToken, $logZeilen): string {
    $offen = ($z['id'] === 'cron' && $mitToken) || ($z['id'] === 'fehlerlog' && $logZeilen !== null);
    $det = '';
    foreach ($z['details'] as $d) {
        $det .= '<div><dt>' . e((string)$d[0]) . '</dt><dd>' . e((string)$d[1]) . '</dd></div>';
    }
    return '<div id="zeile-' . e($z['id']) . '"><dt>' . e($z['titel']) . '</dt><dd>' . chip(BETRIEB_AMPEL_CHIP[$z['ampel']], BETRIEB_AMPEL[$z['ampel']]) . ' ' . e($z['text'])
        . ($z['link'] !== null ? ' <a href="' . e($z['link']) . '">Betrieb</a>' : '')
        . '<details class="tabelle-details"' . ($offen ? ' open' : '') . '><summary>' . ($z['id'] === 'cron' ? 'Details und Einrichtung' : 'Details') . '</summary>'
        . ($det !== '' ? '<dl class="liste-def">' . $det . '</dl>' : '') . $zusatz($z) . '</details></dd></div>';
};

team_seite_kopf($konto, 'Status', '/team/status', ['skripte' => ['status.js']]);
?>
<p class="aktionen"><span class="meta klein mono">Stand <?= e(jetzt_text()) ?></span> <a class="knopf zweit klein" href="/team/status">Jetzt prüfen</a></p>

<?php if ($offen === []): ?>
<?= meldung_html('Alles in Ordnung, Stand ' . jetzt_text() . '.', 'ok') ?>
<?php else: ?>
<div class="kasten">
    <h2 class="zwischen"><?= count($offen) ?> <?= count($offen) === 1 ? 'Punkt braucht' : 'Punkte brauchen' ?> Aufmerksamkeit</h2>
    <dl class="liste-def"><?= implode('', array_map($zeile, $offen)) ?></dl>
</div>
<?php endif; ?>

<?php if ($gruen !== []): ?>
<div class="kasten abstand-oben">
    <h2 class="zwischen">In Ordnung</h2>
    <dl class="liste-def"><?= implode('', array_map($zeile, $gruen)) ?></dl>
</div>
<?php endif; ?>
<p class="meta klein">Dieselben Ampeln liefert <span class="mono">/health</span> als JSON für ein Monitoring (mit health_token aus der config.php, siehe Betriebsanleitung).</p>
<?php
team_seite_fuss();
