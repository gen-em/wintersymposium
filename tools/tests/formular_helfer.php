<?php
declare(strict_types=1);

/*
 * Tests der Formular-Helfer (AP-17 Unterpunkt 3), von lauf.php als eigener
 * Prozess aufgerufen: csrf_feld() braucht eine Sitzung, und die startet nur vor
 * der ersten Ausgabe. Gibt je Prüfung eine Zeile „ok|…“ oder „FEHLER|…“ aus.
 */

ob_start();
require dirname(__DIR__, 2) . '/server/bootstrap.php';
session_save_path(sys_get_temp_dir());
session_start();

$erg = [];
function pruefe(bool $bedingung, string $was): void
{
    $GLOBALS['erg'][] = ($bedingung ? 'ok' : 'FEHLER') . '|' . $was;
}

$_SESSION = ['csrf' => str_repeat('c', 64)];
$h = feld('vorname', 'Vor<name>', ['wert' => 'A"b<c', 'fehler' => 'Fehlt <noch>', 'hilfe' => 'So & so', 'pflicht' => true, 'attr' => ['maxlength' => 80, 'autocomplete' => 'given-name']]);
pruefe($h === '<div class="feld"><label for="vorname">Vor&lt;name&gt;</label><input id="vorname" name="vorname" type="text" value="A&quot;b&lt;c" maxlength="80" autocomplete="given-name" required aria-invalid="true" aria-describedby="h-vorname f-vorname"><p class="hilfe" id="h-vorname">So &amp; so</p><p class="feldfehler" id="f-vorname">Fehlt &lt;noch&gt;</p></div>', 'feld: Markup, Escaping, aria-describedby auf Hilfe und Fehler, required');
pruefe(!str_contains(feld('x', 'X', ['wert' => '1']), 'aria-'), 'feld ohne Hilfe und Fehler: keine aria-Attribute');
pruefe(str_contains(feld('x', 'X', ['hilfe' => 'H']), 'aria-describedby="h-x"') && !str_contains(feld('x', 'X', ['hilfe' => 'H']), 'aria-invalid'), 'feld mit Hilfe: describedby ohne invalid');
$h = feld('bg', 'Gruppe', ['typ' => 'auswahl', 'liste' => ['ÄrztIn', 'Pflege "A"'], 'leer' => 'Bitte wählen', 'wert' => 'Pflege "A"']);
pruefe(str_contains($h, '<select id="bg" name="bg"><option value="">Bitte wählen</option><option value="ÄrztIn">ÄrztIn</option><option value="Pflege &quot;A&quot;" selected>Pflege &quot;A&quot;</option></select>'), 'feld auswahl: Liste, leere Option, selected, Escaping');
$h = feld('r', 'Reihe', ['typ' => 'auswahl', 'optionen' => ['0' => 'einzeln'] + [1 => 'Abend', 2 => 'Winter'], 'wert' => '2']);
pruefe(str_contains($h, '<option value="0">einzeln</option><option value="1">Abend</option><option value="2" selected>Winter</option>'), 'feld auswahl: Schlüssel 0, 1, 2 bleiben Werte (Reihe-Fehler auf Staging)');
pruefe(str_contains(feld('m', 'M', ['typ' => 'auswahl', 'optionen' => ['' => 'alle'], 'liste' => ['A', '3'], 'wert' => '3']), '<option value="">alle</option><option value="A">A</option><option value="3" selected>3</option>'), 'feld auswahl: liste hängt sich an optionen an');
pruefe(feld('h', 'Ja <b>', ['typ' => 'haken', 'wert' => true]) === '<label class="haken"><input type="checkbox" name="h" value="1" checked><span>Ja &lt;b&gt;</span></label>', 'feld haken');
pruefe(feld('essen', 'Essen', ['typ' => 'radio-reihe', 'optionen' => ['a' => 'A', 'b' => 'B'], 'wert' => 'b']) === '<fieldset class="feld"><legend>Essen</legend><div class="wahl wahl-reihe"><label><input type="radio" name="essen" value="a"><span>A</span></label><label><input type="radio" name="essen" value="b" checked><span>B</span></label></div></fieldset>', 'feld radio-reihe');
pruefe(str_contains(feld('pw', 'Passwort', ['typ' => 'passwort', 'wert' => 'geheim']), 'type="password"') && !str_contains(feld('pw', 'Passwort', ['typ' => 'passwort', 'wert' => 'geheim']), 'geheim'), 'feld passwort: Wert wird nie ausgegeben');
pruefe(str_contains(feld('t', 'T', ['typ' => 'textarea', 'wert' => '<p>x</p>']), '<textarea id="t" name="t">&lt;p&gt;x&lt;/p&gt;</textarea>'), 'feld textarea escaped');
pruefe(feld('q', 'Suchen', ['typ' => 'search', 'sr' => true, 'ohne_rahmen' => true, 'wert' => '']) === '<label class="sr" for="q">Suchen</label><input id="q" name="q" type="search" value="">', 'feld für Filterzeilen (sr, ohne Rahmen)');
pruefe(wert([], ['a' => 'x'], 'a') === 'x' && wert(['a' => 'y'], ['a' => 'x'], 'a') === 'y' && wert(['b' => '1'], ['a' => 'x'], 'a') === '' && wert(['a' => ['z']], [], 'a') === '', 'wert: Eingabe vor Datensatz, Listen leer');
$h = aktion_knopf('/team/x?a=1&b=2', 'loeschen', ['id' => 5, 'q' => null], 'Löschen <jetzt>');
pruefe($h === '<form method="post" action="/team/x?a=1&amp;b=2"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="id" value="5"><button class="knopf zweit klein" type="submit">Löschen &lt;jetzt&gt;</button></form>', 'aktion_knopf: CSRF, aktion, Felder, null fällt weg, Escaping');
$h = bestaetigung_html('Löschen', 'Name <x> wird gelöscht.', 'Ja, löschen', ['aktion' => 'loeschen', 'id' => 3], ['ziel' => '/team/y', 'titel' => 'Wirklich?']);
pruefe($h === '<details class="bestaetigen" data-titel="Wirklich?"><summary class="knopf zweit klein">Löschen</summary><form method="post" action="/team/y"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="id" value="3"><p>Name &lt;x&gt; wird gelöscht.</p><button type="submit" class="knopf gefahr">Ja, löschen</button></form></details>', 'bestaetigung_html: Standard rot');
$h = bestaetigung_html('Senden', 'Jetzt?', 'Ja, senden', ['aktion' => 'senden'], ['art' => 'gruen', 'im_formular' => true, 'knopfklasse' => 'knopf', 'offen' => true]);
pruefe($h === '<details class="bestaetigen" data-art="gruen" open><summary class="knopf">Senden</summary><div class="bestaetigen-feld"><p>Jetzt?</p><button class="knopf" type="submit" name="aktion" value="senden">Ja, senden</button></div></details>', 'bestaetigung_html: grün, offen, im umgebenden Formular');
pruefe(kachel('Warte<liste>', 3, '2 Termine', ['/team/x', 'Ansehen']) === '<div class="kachel"><div class="lbl">Warte&lt;liste&gt;</div><div class="zahl">3</div><p class="meta klein">2 Termine</p><p><a href="/team/x">Ansehen</a></p></div>', 'kachel');
pruefe(chip(VA_STATUS_CHIP['abgeschlossen'], 'Ab<geschlossen>') === '<span class="chip gesperrt">Ab&lt;geschlossen&gt;</span>', 'chip mit Status-Map');
$h = filter_chips(['alle' => ['Alle', 4], 'wl' => 'Warteliste'], 'wl', fn($k) => '/team/p?f=' . $k);
pruefe($h === '<div class="filter" role="group" aria-label="Filter"><a class="knopf klein zweit" href="/team/p?f=alle">Alle <span class="mono">4</span></a><a class="knopf klein" href="/team/p?f=wl" aria-current="true">Warteliste</a></div>', 'filter_chips: aktiv, Zahl');
pruefe(filter_leiste(['ziel' => '/team/p', 'suche' => true, 'versteckt' => ['f' => 'wl', 'x' => '', 'y' => null], 'felder' => '<i></i>']) === '<form class="filterzeile" method="get" action="/team/p" role="search"><input type="hidden" name="f" value="wl"><i></i><button class="knopf zweit klein" type="submit">Suchen</button></form>', 'filter_leiste: leere versteckte Felder fallen weg');
pruefe(leer_hinweis('Personen', true) === '<p class="hinweis-leer">Keine Personen in dieser Auswahl.</p>' && leer_hinweis('Niemand gefunden.') === '<p class="hinweis-leer">Niemand gefunden.</p>', 'leer_hinweis');
pruefe(seiten_kopfzeile('Titel', 'Kicker', 'Text <b>') === '<div class="innen seitenkopf"><p class="kicker">Kicker</p><h1 class="titel">Titel</h1><p class="text">Text &lt;b&gt;</p></div>', 'seiten_kopfzeile');
pruefe(meldung_html('A<b>', 'fehler') === '<div class="meldung fehler" role="alert">A&lt;b&gt;</div>' && meldung_html(['ton' => 'ok', 'text' => 'Gut']) === '<div class="meldung ok" role="status">Gut</div>', 'meldung_html: Text und Flash');
pruefe(meldung_html(['email' => 'X', 'Y'], 'fehler') === '<div class="meldung fehler" role="alert"><ul><li>X</li><li>Y</li></ul></div>' && fehler_html(['a' => 'X']) === '<div class="meldung fehler" role="alert"><ul><li>X</li></ul></div>' && meldung_html(null) === '', 'meldung_html/fehler_html: Liste auch mit Feldnamen');
$_POST = ['aktion' => 'x', 'n' => '12', 'l' => ['a'], 't' => 'Äbcdef', 'h' => '1'];
pruefe(aktion() === 'x' && post_zahl('n') === 12 && post_text('l') === '' && post_zahl('l') === 0 && post_text('t', 3) === 'Äbc' && post_haken('h') && !post_haken('fehlt'), 'post_text/post_zahl/post_haken');

$_SESSION = [];
session_destroy();
ob_end_clean();
echo implode("\n", $erg), "\n";
