<?php
declare(strict_types=1);

/*
 * Betrieb → Komponenten (AP-17 Unterpunkt 6, E-64 (1); nur BetreiberInnen,
 * in jeder Umgebung): das Komponentenblatt. Zeigt jeden Helfer aus
 * formular_lib/html_lib und jede Komponente aus site.css einmal mit ihren
 * Varianten und Tönen, darunter den Aufruf als Text. Es ist die Vorlage für
 * neue Seiten (statt der Mockups in docs/mockups/, die Archiv sind) und der
 * Regressionstest für das CSS im Browser-Durchlauf.
 *
 * Alle Formulare hier sind Beispiele: Ein Absenden ändert nichts.
 */

$konto = team_tor(['betreiber']);
if (ist_post()) {
    fertig('hinweis', 'Beispiel – es wurde nichts geändert.', '/team/komponenten');
}

$ziel = '/team/komponenten';
$muster = fn(string $html, string $code): string => '<div class="kb-muster">' . $html . '</div><pre class="kb-code">' . e($code) . '</pre>';
$abschnitt = fn(string $id, string $titel, string $text = ''): string => '<h2 class="zwischen" id="' . e($id) . '">' . e($titel) . '</h2>'
    . ($text !== '' ? '<p class="meta klein">' . e($text) . '</p>' : '');

team_seite_kopf($konto, 'Komponenten', '/team/komponenten');
?>
<p class="text">Jede Komponente einmal mit ihren Varianten, darunter der Aufruf. Neue Seiten bauen sich hieraus; neue CSS-Klassen kommen nur mit einem Eintrag hier dazu (E-64). Alle Knöpfe auf dieser Seite sind Beispiele und ändern nichts.</p>
<nav aria-label="Abschnitte" class="filter">
    <?php foreach (['typografie' => 'Typografie', 'knoepfe' => 'Knöpfe', 'felder' => 'Felder', 'kaesten' => 'Kästen', 'chips' => 'Chips', 'meldungen' => 'Meldungen', 'kacheln' => 'Kacheln', 'filter' => 'Filter', 'tabellen' => 'Tabellen', 'dialoge' => 'Dialoge', 'menues' => 'Menüs'] as $id => $t): ?>
    <a class="knopf zweit klein" href="#<?= $id ?>"><?= e($t) ?></a>
    <?php endforeach; ?>
</nav>

<?= $abschnitt('typografie', 'Typografie') ?>
<?= $muster('<p class="kicker">Kicker</p><h1 class="titel">Titel</h1><h2 class="zwischen">Zwischentitel</h2><p class="unterkopf">Unterkopf <span class="meta">– mit Zusatz</span></p><p class="text">Fließtext mit <span class="meta">Meta</span>, <span class="mono">Mono 12:30</span> und <span class="klein">klein</span>.</p><p><span class="etikett">Etikett</span> · <span class="etikett-mono">Etikett mono</span></p>',
    '<p class="kicker">…</p><h1 class="titel">…</h1><h2 class="zwischen">…</h2><p class="unterkopf">…</p>' . "\n" . '<span class="meta">, "mono", "klein", "etikett", "etikett-mono"' . "\n" . 'seiten_kopfzeile($titel, $kicker, $text)  // Kopf öffentlicher Seiten') ?>

<?= $abschnitt('knoepfe', 'Knöpfe', 'Normal 44 px, klein 36 px. Haupt: Akzent gefüllt; zweit: Ring; gefahr: Ring in Rot; grün: Anmelden.') ?>
<?= $muster('<div class="aktionen"><button class="knopf" type="button">Speichern</button><button class="knopf zweit" type="button">Abbrechen</button><button class="knopf gefahr" type="button">Löschen …</button><a class="knopf gruen" href="#knoepfe">Anmelden</a><button class="knopf gesperrt" type="button" disabled>Gesperrt</button></div>'
    . '<div class="aktionen abstand"><button class="knopf klein" type="button">Klein</button><button class="knopf zweit klein" type="button">Zweit klein</button><button class="knopf gefahr klein" type="button">Gefahr klein</button><a class="klein-link" href="#knoepfe">Klein-Link</a><button class="link-knopf" type="button">Link-Knopf</button></div>'
    . '<div class="aktionen abstand">' . aktion_knopf($ziel, 'beispiel', ['id' => 1], 'Aktion (aktion_knopf)') . '</div>',
    '<button class="knopf [zweit|gefahr|gruen|gesperrt] [klein]">' . "\n" . 'aktion_knopf($ziel, \'aktion\', [\'id\' => 1], \'Text\', \'knopf zweit klein\')') ?>

<?= $abschnitt('felder', 'Felder', 'Ein Markup für alle Felder: Label, Element, Hilfe, Fehler; aria-describedby zeigt auf Hilfe und Fehler.') ?>
<?= $muster('<form class="form" method="post" action="' . e($ziel) . '" novalidate>' . csrf_feld()
    . '<div class="paar">' . feld('kb_text', 'Textfeld', ['wert' => 'Wert', 'hilfe' => 'Hilfetext unter dem Feld.', 'zusatz' => '(freiwillig)'])
    . feld('kb_fehler', 'Mit Fehler', ['wert' => 'falsch', 'fehler' => 'So geht das nicht.', 'pflicht' => true]) . '</div>'
    . '<div class="paar">' . feld('kb_auswahl', 'Auswahl', ['typ' => 'auswahl', 'optionen' => ['a' => 'Erste', 'b' => 'Zweite'], 'leer' => 'Bitte wählen'])
    . feld('kb_datum', 'Datum und Zeit', ['typ' => 'datetime-local', 'wert' => '2027-01-30T19:30']) . '</div>'
    . feld('kb_lang', 'Textfeld lang', ['typ' => 'textarea', 'wert' => 'Mehrere Zeilen …'])
    . feld('kb_wahl', 'Auswahl als Reihe', ['typ' => 'radio-reihe', 'optionen' => ['ja' => 'Ja', 'nein' => 'Nein', 'egal' => 'Egal'], 'wert' => 'ja'])
    . '<div class="feld haken-liste">' . feld('kb_haken', 'Haken mit Text', ['typ' => 'haken', 'wert' => true]) . '</div>'
    . '<div class="aktionen"><button class="knopf" type="submit">Speichern</button><a class="knopf zweit" href="' . e($ziel) . '">Abbrechen</a></div></form>',
    "feld('name', 'Label', ['wert' => …, 'hilfe' => …, 'fehler' => …, 'pflicht' => true, 'zusatz' => '(freiwillig)', 'attr' => […]])\n"
    . "feld(…, ['typ' => 'auswahl', 'optionen' => […], 'leer' => 'Bitte wählen'])   // auch textarea, haken, radio, radio-reihe, zahl, passwort, email, date …\n"
    . "wert(\$eingabe, \$datensatz, 'name')   // Eingabe nach Fehler, sonst gespeicherter Wert\n"
    . "profil_felder_html(\$werte, \$fehler, ['email' => 'frei|fest|ohne', 'pflicht' => …, 'cme' => …, 'hinweise' => …])") ?>

<?= $abschnitt('kaesten', 'Kästen') ?>
<?= $muster('<div class="team-raster"><div class="kasten"><h3 class="zwischen klein-titel">Kasten</h3><p>Standardfläche für Inhalte.</p></div>'
    . '<div class="kasten akzent-kasten"><h3 class="zwischen klein-titel">Akzent-Kasten</h3><p>Mit Akzent oben.</p></div>'
    . '<div class="kasten gefahr-kasten"><h3 class="zwischen klein-titel">Gefahr-Kasten</h3><p>Rote Umrandung für folgenreiche Aktionen.</p></div>'
    . '<div class="merke"><p class="kicker">Merke</p><p>Hervorgehobener Hinweis im Text.</p></div>'
    . '<div class="angebot"><p class="kicker">Angebot</p><p>Rahmen in der Akzentfarbe.</p></div>'
    . '<div class="ersetzt"><p class="kicker">Ersetzt</p><p>Was wegfällt.</p></div>'
    . '<div class="als-naechstes"><p class="naechstes-zeile"><span>Als Nächstes</span><a class="knopf zweit klein" href="#kaesten">Weiter</a></p></div>'
    . '<div class="gefahrenzone"><p class="unterkopf">Gefahrenzone</p><p>Gestreifter Bereich für Löschen und Zurücksetzen.</p></div>'
    . '<div class="status-band"><span class="sticker">Angemeldet</span><span class="mono">Status-Band</span></div>'
    . '<div class="zettel"><p class="zettel-kopf">Notiz vom Team</p><p>Zettel auf Papier.</p></div></div>',
    '<div class="kasten [akzent-kasten|gefahr-kasten]">, <div class="merke">, <div class="angebot">, <div class="ersetzt">,' . "\n" . '<div class="als-naechstes">, <div class="gefahrenzone">, <div class="status-band">, <div class="zettel">') ?>

<?= $abschnitt('chips', 'Chips und Etiketten', 'chip() mit Ton; Status über VA_STATUS_CHIP, PLATZ_STATUS_CHIP, RM_STATUS_CHIP, ANM_STATUS_CHIP, BETRIEB_AMPEL_CHIP.') ?>
<?= $muster('<p class="aktionen">' . chip('ok', 'ok') . chip('offen', 'offen') . chip('wl', 'wl') . chip('gesperrt', 'gesperrt') . chip('warnung', 'warnung') . chip('rolle', 'Rolle') . chip('rolle-leise', 'Rolle leise') . '</p>'
    . '<p class="aktionen"><span class="format">VT 01</span><span class="format rot">WS 01</span><span class="format offen">OFFEN</span><span class="st pub">✓ veröffentlicht</span><span class="st ges">nicht veröffentlicht</span><span class="st neu">neu</span><span class="pp-mehr">Infos</span><span class="schild sicher">Dein Platz</span><span class="schild angebot">Angebot</span><span class="schild warte">Warteliste</span></p>'
    . '<p class="aktionen"><span class="sticker">Sticker</span> <span class="sticker hinweis">Hinweis</span></p>',
    "chip('ok|offen|wl|gesperrt|warnung|rolle|rolle-leise', 'Text')\nchip(VA_STATUS_CHIP[\$status], VA_STATUS[\$status])\n<span class=\"format [rot|offen]\">, <span class=\"st pub|ges|neu\">, <span class=\"schild sicher|angebot|warte\">, <span class=\"sticker [hinweis]\">") ?>

<?= $abschnitt('meldungen', 'Meldungen') ?>
<?= $muster(meldung_html('Gespeichert.', 'ok') . meldung_html('Bitte beachten: Hinweis.') . meldung_html(NICHT_GESPEICHERT_TEXT, 'fehler') . fehler_html(['vorname' => 'Bitte den Vornamen eintragen.', 'Noch ein Fehler ohne Feld.'])
    . '<p class="feldfehler">Fehler an einem Feld</p>' . leer_hinweis('Einträge', true)
    . '<div class="entwurf-banner"><span><strong>Entwurf-Band.</strong> Gespeichert, aber nicht veröffentlicht.</span><span class="eb-knoepfe"><button class="knopf zweit klein" type="button">Ansehen</button><button class="knopf klein" type="button">Veröffentlichen</button></span></div>'
    . '<div class="sperre"><span class="sperre-text">Sperre (geschlossen)</span>' . icon('lock', 22, 'icon sperre-symbol') . '</div><div class="sperre offen"><span class="sperre-text">Sperre (offen)</span>' . icon('lock-open', 22, 'icon sperre-symbol') . '</div>'
    . '<p class="nl-hinweis"><span>Newsletter-Hinweis</span><a href="#meldungen">Bestellen</a></p><p class="va-archiv"><strong>Archiv:</strong> Diese Veranstaltung ist vorbei.</p>'
    . '<div class="kasten sicherheit"><div class="pflicht-balken">Pflicht-Balken: zuerst den zweiten Faktor einrichten.</div></div>',
    "meldung_html('Text', 'ok|hinweis|fehler')   // auch Liste oder Flash-Meldung; seite_kopf(…, ['meldung' => true])\nfehler_html(\$fehler)   // Liste, auch mit Feldnamen als Schlüssel\nmeldung_html(NICHT_GESPEICHERT_TEXT, 'fehler')\nleer_hinweis('Einträge', \$gefiltert)   // „Keine Einträge in dieser Auswahl.“\n<div class=\"entwurf-banner\">, <div class=\"sperre [offen]\">, <p class=\"nl-hinweis\">, <p class=\"va-archiv\">, <div class=\"pflicht-balken\">") ?>

<?= $abschnitt('kacheln', 'Kacheln') ?>
<?= $muster('<div class="kacheln">' . kachel('Anmeldungen', '42 / 120', '35 bestätigt · +3 seit gestern', ['#kacheln', 'Personen']) . kachel('Warteliste', 7, 'über 2 Termine') . kachel('Mails heute', '12 / 400') . '</div>',
    "kachel('Label', \$zahl, 'Meta', ['/pfad', 'Linktext'])") ?>

<?= $abschnitt('filter', 'Filter und Suche') ?>
<?= $muster(filter_leiste(['ziel' => $ziel, 'suche' => true, 'felder' => feld('kb_q', 'Suchen', ['typ' => 'search', 'sr' => true, 'ohne_rahmen' => true, 'wert' => '', 'attr' => ['placeholder' => 'Name, Adresse']]), 'nach_html' => '<a class="klein-link" href="#filter">Link daneben</a>'])
    . filter_chips(['alle' => ['Alle', 12], 'wl' => ['Warteliste', 3], 'offen' => 'Offen'], 'wl', fn($k) => $ziel . '?f=' . $k . '#filter'),
    "filter_leiste(['ziel' => \$pfad, 'suche' => true, 'versteckt' => […], 'felder' => feld(…, ['sr' => true, 'ohne_rahmen' => true]), 'knopf' => 'Suchen'])\nfilter_chips(\$optionen, \$aktiv, fn(\$k) => \$href)") ?>

<?= $abschnitt('tabellen', 'Tabellen', 'table.t; mit .karten unter 1000 px jede Zeile als Karte, mit .karten-immer in jeder Breite (schmale Spalten).') ?>
<?= $muster('<div class="tabelle-wrap"><table class="t karten"><thead><tr><th>Name</th><th>Status</th><th>Workshops</th></tr></thead><tbody>'
    . '<tr><td data-label="Name">Erika Muster</td><td data-label="Status">' . chip('ok', 'Angemeldet') . '</td><td data-label="Workshops" class="mono klein">WS 01 19:30</td></tr>'
    . '<tr class="gewaehlt"><td data-label="Name">Ben Beispiel</td><td data-label="Status">' . chip('wl', 'Warteliste') . '</td><td data-label="Workshops" class="mono klein">–</td></tr></tbody></table></div>'
    . '<dl class="liste-def abstand-oben-klein"><div><dt>Liste</dt><dd>Begriff und Wert</dd></div><div><dt>Status</dt><dd>' . chip('offen', 'offen') . '</dd></div></dl>',
    '<div class="tabelle-wrap"><table class="t karten [karten-immer]"> … <td data-label="Status"> …' . "\n" . '<tr class="gewaehlt">, <dl class="liste-def">') ?>

<?= $abschnitt('dialoge', 'Dialoge', 'bestaetigung_html(): ohne JavaScript aufklappbar, mit JavaScript der einheitliche Dialog (dialog.js). Rot = löschen, grün = bestätigen, lila = Info.') ?>
<?= $muster('<div class="aktionen">'
    . bestaetigung_html('Löschen …', 'Der Eintrag wird gelöscht.', 'Ja, löschen', ['aktion' => 'beispiel'], ['ziel' => $ziel, 'titel' => 'Eintrag löschen?'])
    . bestaetigung_html('Freischalten …', 'Alle bekommen eine Mail.', 'Ja, freischalten', ['aktion' => 'beispiel'], ['ziel' => $ziel, 'art' => 'gruen', 'titel' => 'Freischalten?', 'inhalt_html' => feld('kb_ohne_mail', 'ohne Mail', ['typ' => 'haken'])])
    . bestaetigung_html('Info …', 'Ein Hinweis ohne Entscheidung.', 'Verstanden', ['aktion' => 'beispiel'], ['ziel' => $ziel, 'art' => 'lila', 'titel' => 'Gut zu wissen'])
    . '</div>',
    "bestaetigung_html('Knopf …', 'Frage', 'Ja, löschen', ['aktion' => …, 'id' => …], ['art' => 'rot|gruen|lila', 'titel' => …, 'inhalt_html' => …, 'ausloeser' => id, 'im_formular' => true])") ?>

<?= $abschnitt('menues', 'Menüs', 'Menü-Karte für aufgeklappte Menüs (Zeilen-Menü, Personen-Menü, Veranstaltungs-Wechsler).') ?>
<?= $muster('<details class="zeilen-menue"><summary class="knopf zweit klein">Bearbeiten ' . icon('caret-down', 14) . '</summary><div class="menue-karte zeilen-liste" role="menu"><button class="menue-punkt" type="button" role="menuitem">Punkt</button><button class="menue-punkt" type="button" role="menuitem">Noch ein Punkt</button><hr><button class="menue-punkt rot" type="button" role="menuitem">Sperren …</button></div></details>'
    . ' <details class="schlicht tabelle-details"><summary>Aufklapper ohne Dreieck (details.schlicht)</summary><p class="meta klein">Inhalt</p></details>',
    '<details class="zeilen-menue"><summary class="knopf zweit klein">…</summary><div class="menue-karte zeilen-liste" role="menu"><button class="menue-punkt [rot]">' . "\n" . '<details class="schlicht">') ?>
<?php
team_seite_fuss();
