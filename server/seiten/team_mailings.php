<?php
declare(strict_types=1);

/*
 * Mailings (AP-6, Mockup „Mailings“, E-41, E-50): Rundmails an Teilnehmende.
 * Ohne ?id: Übersicht (Entwürfe, geplante, gesendete) und Versand-Stand.
 * Mit ?id (oder ?neu=1): Editor für Entwürfe, sonst die gesendete Mail mit
 * Empfängerliste (Archiv). Senden, Planen und Abbrechen stehen im Protokoll.
 */

$konto = team_tor();
team_schreibschutz('/team/mailings'); // abgeschlossene Veranstaltung: nur lesen (E-58)
$ich = (int)$konto['id'];
$neu = isset($_GET['neu']);
$id = isset($_GET['id']) ? (int)$_GET['id'] : (post_zahl('id') > 0 ? post_zahl('id') : null);
$rm = $id !== null ? rm_laden($id) : null;
if ($id !== null && ($rm === null || !rm_sichtbar($rm))) {
    nicht_gefunden();
}
$fehler = [];
$eingabe = null;

if (ist_post()) {
    $aktion = aktion();
    $ziel = fn(?int $i) => '/team/mailings' . ($i !== null ? '?id=' . $i : '');
    if (in_array($aktion, ['abbrechen', 'kopieren'], true) && $rm !== null) {
        if ($aktion === 'abbrechen') {
            $t = rm_abbrechen($id, $ich);
            fertig($t !== null ? 'ok' : 'fehler', $t ?? 'Das Mailing lässt sich nicht mehr abbrechen.', $ziel($id));
        }
        $kopie = rm_kopieren($id, $ich);
        fertig('ok', 'Kopie als neuer Entwurf angelegt.', $ziel($kopie));
    }
    if ($aktion === 'loeschen' && $rm !== null) {
        fertig(rm_loeschen($id, $ich) ? 'ok' : 'fehler', 'Entwurf gelöscht.', '/team/mailings');
    }
    if ($rm !== null && $rm['status'] !== 'entwurf') {
        fertig('fehler', 'Dieses Mailing ist kein Entwurf mehr und lässt sich nicht ändern.', $ziel($id));
    }
    // Jede andere Aktion speichert zuerst den Entwurf
    $p = rm_pruefen($_POST);
    $fehler = $p['fehler'];
    $eingabe = $p['werte'];
    if ($fehler === []) {
        $id = rm_speichern($id, $p['werte'], $ich);
        $rm = rm_laden($id);
        switch ($aktion) {
            case 'bild':
                try {
                    $b = rm_bild_speichern($_FILES['bild'] ?? [], $ich);
                    rm_bild_anhaengen($id, (int)$b['id']);
                } catch (RuntimeException $ex) {
                    fertig('fehler', $ex->getMessage(), $ziel($id));
                }
                fertig('ok', 'Bild eingefügt (am Ende der Mail).', $ziel($id));
            case 'test':
                $m = rm_zusammensetzen($rm, rm_werte(['vorname' => tk_namen($konto)['vorname'], 'nachname' => tk_namen($konto)['nachname']], false), 'cid', substr(strrchr(mail_absender(mail_konfig($rm['postfach'])), '@') ?: '@localhost', 1));
                $f = mail_zustellen($konto['email'], '[Test] ' . $m['betreff'], $m['text'], ['postfach' => $rm['postfach'], 'html' => $m['html'], 'bilder' => $m['bilder'], 'rundmail' => true]);
                fertig($f === null ? 'ok' : 'fehler', $f === null ? 'Testmail an ' . $konto['email'] . ' gesendet (Platzhalter mit deinem Namen, {link} zeigt auf „Deine Anmeldung“).' : 'Testmail nicht gesendet: ' . $f, $ziel($id));
            case 'planen':
                $roh = str_replace('T', ' ', post_text('geplant'));
                $utc = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $roh) === 1 ? lokal_nach_utc($roh . ':00') : null;
                $sf = rm_sendefehler($rm);
                if ($utc === null || strtotime($utc . ' UTC') < time() + 60) {
                    $fehler['geplant'] = 'Bitte einen Zeitpunkt in der Zukunft wählen.';
                } elseif ($sf !== []) {
                    $fehler['senden'] = implode(' ', $sf);
                } else {
                    rm_planen($id, $utc, $ich);
                    fertig('ok', 'Geplant für ' . datum_zeit_text($utc) . '. Bis dahin kannst du die Planung aufheben.', $ziel($id));
                }
                break;
            case 'senden':
                $sf = rm_sendefehler($rm);
                if ($sf !== []) {
                    $fehler['senden'] = implode(' ', $sf);
                    break;
                }
                $n = rm_einreihen($id, $ich);
                fertig($n !== null ? 'ok' : 'fehler', $n !== null ? $n . ' Mails stehen in der Warteschlange. Größere Mailings verteilt die App auf mehrere Tage.' : 'Nicht gesendet.', $ziel($id));
            default:
                fertig('ok', 'Entwurf gespeichert.', $ziel($id));
        }
    }
}

$stand = mail_stand();
$versandKasten = function () use ($stand): void { ?>
    <div class="kasten">
        <h2 class="zwischen klein-titel">Versand</h2>
        <ul class="schlicht">
        <?php foreach ($stand['postfaecher'] as $p => $pf): ?>
            <li><?= e($pf['titel']) ?>: <?php if ($pf['da']): ?><strong class="mono"><?= $pf['heute'] ?> / <?= $pf['limit'] ?></strong> heute<?= $pf['offen'] > 0 ? ' · ' . $pf['offen'] . ' warten' : '' ?><?php else: ?><span class="meta">nicht eingerichtet</span><?php endif; ?></li>
        <?php endforeach; ?>
        </ul>
        <p class="meta klein">Mailings gehen über ein Postfach bis zu seinem Tageslimit (über anmeldung@ bis <?= (int)round((1 - MAIL_SYSTEM_RESERVE) * 100) ?> %), der Rest am nächsten Tag. Bestätigungen und Einmal-Links haben immer Vorrang.</p>
    </div>
<?php };
$liste = rm_liste();
$zuletzt = array_slice(array_values(array_filter($liste, fn($r) => in_array($r['status'], ['gesendet', 'versand', 'abgebrochen'], true))), 0, 5);
$zuletztKasten = function () use ($zuletzt, $id): void { ?>
    <div class="kasten">
        <h2 class="zwischen klein-titel">Zuletzt gesendet</h2>
        <?php if ($zuletzt === []): ?><?= leer_hinweis('Noch nichts gesendet.') ?><?php else: ?>
        <ul class="schlicht zuletzt">
            <?php foreach ($zuletzt as $r): ?><li><a href="/team/mailings?id=<?= (int)$r['id'] ?>"<?= $id === (int)$r['id'] ? ' aria-current="true"' : '' ?>><?= e($r['betreff'] !== '' ? $r['betreff'] : '(ohne Betreff)') ?></a> <span class="mono meta"><?= (int)$r['empfaenger_zahl'] ?> · <?= e(datum_text($r['gesendet_am'])) ?></span></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
<?php };

team_seite_kopf($konto, 'Mailings', '/team/mailings', ['skripte' => ['mailings.js']]);

/* ---- Übersicht ---- */
if ($rm === null && !$neu && $eingabe === null): ?>
<div class="aktionen abstand-unten"><a class="knopf" href="/team/mailings?neu=1">+ Neues Mailing</a></div>
<div class="personen-raster">
    <div class="spalte">
        <?php if ($liste === []): ?><?= leer_hinweis('Noch keine Mailings.') ?><?php else: ?>
        <div class="tabelle-wrap"><table class="t karten karten-immer">
            <thead><tr><th>Betreff</th><th>Status</th><th>Empfänger</th><th>Datum</th></tr></thead>
            <tbody>
            <?php foreach ($liste as $r):
                $datum = $r['status'] === 'geplant' ? $r['geplant_fuer'] : ($r['gesendet_am'] ?? $r['geaendert_am']); ?>
                <tr>
                    <td data-label="Betreff"><a href="/team/mailings?id=<?= (int)$r['id'] ?>"><?= e($r['betreff'] !== '' ? $r['betreff'] : '(ohne Betreff)') ?></a><br><span class="meta klein"><?= e($r['auswahl_text'] !== '' ? $r['auswahl_text'] : rm_auswahl_text($r['auswahl'], $r['berufsgruppe'])) ?></span></td>
                    <td data-label="Status"><?= chip(RM_STATUS_CHIP[$r['status']] ?? 'offen', RM_STATUS[$r['status']]) ?></td>
                    <td data-label="Empfänger" class="mono"><?= $r['status'] === 'entwurf' || $r['status'] === 'geplant' ? '–' : (int)$r['empfaenger_zahl'] ?></td>
                    <td data-label="Datum" class="mono klein"><?= e(datum_zeit_text($datum)) ?><?= $r['von'] !== null ? '<br><span class="meta">' . e($r['von']) . '</span>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
    <div class="spalte stapel">
        <?php $versandKasten(); ?>
    </div>
</div>
<?php
/* ---- Gesendet, geplant, abgebrochen: Archiv ---- */
elseif ($rm !== null && $rm['status'] !== 'entwurf'):
    $fs = rm_fortschritt($id);
    $empf = rm_empfaenger_archiv($id); ?>
<p><a href="/team/mailings">← Alle Mailings</a></p>
<div class="ml-raster">
    <div class="kasten detail">
        <p class="kicker"><?= e(RM_STATUS[$rm['status']]) ?><?= $rm['gesendet_am'] !== null ? ' · ' . e(datum_zeit_text($rm['gesendet_am'])) : '' ?><?= $rm['status'] === 'geplant' ? ' · für ' . e(datum_zeit_text($rm['geplant_fuer'])) : '' ?></p>
        <h2 class="zwischen"><?= e($rm['betreff']) ?></h2>
        <dl class="liste-def">
            <div><dt>Von</dt><dd><?= e(mail_absender_name(mail_konfig($rm['postfach'])) . ' <' . mail_absender(mail_konfig($rm['postfach'])) . '>') ?></dd></div>
            <div><dt>An</dt><dd><?= e($rm['auswahl_text'] !== '' ? $rm['auswahl_text'] : rm_auswahl_text($rm['auswahl'], $rm['berufsgruppe'])) ?></dd></div>
            <?php if ($rm['status'] !== 'geplant'): ?>
            <div><dt>Empfänger</dt><dd class="mono"><?= (int)$rm['empfaenger_zahl'] ?></dd></div>
            <div><dt>Versand</dt><dd><?php if ($rm['status'] === 'gesendet' && $fs['fehler'] === 0): ?>vollständig gesendet<?php else: ?><?= $fs['gesendet'] ?> gesendet<?= $fs['wartet'] > 0 ? ' · ' . $fs['wartet'] . ' warten (Tageslimit)' : '' ?><?php endif; ?><?= $fs['fehler'] > 0 ? ' · ' . chip('wl', $fs['fehler'] . ' Fehler') : '' ?></dd></div>
            <?php endif; ?>
        </dl>
        <?php if ($rm['status'] !== 'geplant'): ?>
        <details class="tabelle-details"><summary>Empfängerliste (<?= count($empf) ?>)</summary>
            <?php if ($empf === []): ?><p class="meta klein">Die Liste ist nach der Löschfrist entfernt (E-24).</p><?php else: ?>
            <ul class="schlicht empfaenger-liste"><?php foreach ($empf as $x): ?><li><?= e($x['name']) ?> <span class="meta klein"><?= e($x['email']) ?></span></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if ($rm['loeschen_ab'] !== null): ?><p class="meta klein">Wird am <?= e(tag_text($rm['loeschen_ab'])) ?> gelöscht (<?= RM_ARCHIV_MONATE ?> Monate nach dem Symposium).</p><?php endif; ?>
        </details>
        <?php endif; ?>
        <form method="post" action="/team/mailings?id=<?= $id ?>" class="aktionen">
            <?= csrf_feld() ?>
            <?php if (in_array($rm['status'], ['geplant', 'versand'], true)): ?>
            <button class="knopf gefahr" type="submit" name="aktion" value="abbrechen"><?= $rm['status'] === 'geplant' ? 'Planung aufheben' : 'Restlichen Versand abbrechen' ?></button>
            <?php endif; ?>
            <button class="knopf zweit" type="submit" name="aktion" value="kopieren">Als neue Mail kopieren</button>
        </form>
    </div>
    <div class="stapel">
        <div class="kasten">
            <div class="stat-kopf"><h2 class="zwischen klein-titel">So sah die Mail aus</h2></div>
            <div class="ml-vorschau-rahmen"><iframe class="ml-vorschau-frame" title="Vorschau der Mail" src="/team/mailings/vorschau?id=<?= $id ?>" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"></iframe></div>
        </div>
        <?php $zuletztKasten(); ?>
    </div>
</div>
<?php
/* ---- Editor ---- */
else:
$w = $eingabe ?? ($rm !== null ? $rm : ['betreff' => '', 'html' => '<p>Hallo {vorname},</p><p></p><p>Dein Orga-Team</p>', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system']);
$empfaenger = rm_empfaenger($w['auswahl'], $w['berufsgruppe']);
$jetztLokal = (new DateTimeImmutable('now', new DateTimeZone(ANZEIGE_ZEITZONE)))->modify('+1 hour')->format('Y-m-d\TH:00');
?>
<p><a href="/team/mailings">← Alle Mailings</a></p>
<?= meldung_html($fehler['senden'] ?? null, 'fehler') ?>
<div class="ml-raster">
    <form class="kasten form ml-editor rm-form" method="post" action="/team/mailings<?= $id !== null ? '?id=' . $id : '' ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_feld() ?>
        <?php if ($id !== null): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
        <?php $nlAuswahl = rm_nl_listen($w['auswahl']); ?>
        <?php if (rm_newsletter_erlaubt()): // Newsletter-Listen nur für Betreiber (AP-12, E-60) ?>
        <fieldset class="feld rm-art"><legend>Empfänger</legend>
            <label class="haken"><input type="radio" name="art" value="teilnehmende"<?= $nlAuswahl === null ? ' checked' : '' ?>><span>Teilnehmende der Veranstaltung</span></label>
            <label class="haken"><input type="radio" name="art" value="newsletter"<?= $nlAuswahl !== null ? ' checked' : '' ?>><span>Newsletter-Listen</span></label>
        </fieldset>
        <fieldset class="feld rm-listen" data-empfaenger="newsletter"><legend>Newsletter-Listen</legend>
            <?php foreach (nl_zahlen() as $lid => $z): ?><label class="haken"><input type="checkbox" name="listen[]" value="<?= $lid ?>"<?= in_array($lid, $nlAuswahl ?? [], true) ? ' checked' : '' ?>><span><?= e($z['liste']['name']) ?> <span class="meta klein">(<?= $z['bestaetigt'] ?> bestätigt)</span></span></label><?php endforeach; ?>
            <p class="hilfe">Wer auf mehreren Listen steht, bekommt die Mail nur einmal. Abmeldelink und List-Unsubscribe hängt die App an; ohne Vorname lautet die Anrede „Hallo,“.</p>
        </fieldset>
        <?php endif; ?>
        <div class="paar" data-empfaenger="teilnehmende">
            <div class="feld"><label for="auswahl">An</label>
                <select id="auswahl" name="auswahl"<?= isset($fehler['auswahl']) ? ' aria-invalid="true" aria-describedby="f-auswahl"' : '' ?>><?php foreach (rm_auswahl_gruppen() as $gruppe => $opt): ?><?= $gruppe !== '' ? '<optgroup label="' . e($gruppe) . '">' : '' ?><?php foreach ($opt as $k => $t): ?><option value="<?= e($k) ?>"<?= $w['auswahl'] === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?><?= $gruppe !== '' ? '</optgroup>' : '' ?><?php endforeach; ?></select><?= isset($fehler['auswahl']) ? '<p class="feldfehler" id="f-auswahl">' . e($fehler['auswahl']) . '</p>' : '' ?></div>
            <?= feld('berufsgruppe', 'Berufsgruppe', ['typ' => 'auswahl', 'optionen' => va_liste('berufsgruppen'), 'leer' => 'Alle Berufsgruppen', 'wert' => $w['berufsgruppe'], 'fehler' => $fehler['berufsgruppe'] ?? null]) ?>
        </div>
        <div class="feld"><label for="postfach">Absender</label>
            <select id="postfach" name="postfach"<?= isset($fehler['postfach']) ? ' aria-invalid="true" aria-describedby="f-postfach"' : '' ?>><?php foreach ($stand['postfaecher'] as $p => $pf): ?><option value="<?= e($p) ?>"<?= $w['postfach'] === $p ? ' selected' : '' ?><?= $pf['da'] ? '' : ' disabled' ?>><?= e($pf['titel']) ?><?= $pf['da'] ? '' : ' – nicht eingerichtet' ?></option><?php endforeach; ?></select><?= isset($fehler['postfach']) ? '<p class="feldfehler" id="f-postfach">' . e($fehler['postfach']) . '</p>' : '' ?>
            <p class="hilfe"><?php foreach ($stand['postfaecher'] as $p => $pf): if ($pf['da']): ?><span class="umbruch"><?= e($pf['titel']) ?>: <?= e(mail_absender_name(mail_konfig($p)) . ' <' . $pf['absender'] . '>') ?></span><br><?php endif; endforeach; ?></p></div>
        <div class="empf"><p><strong class="mono" id="rm-zahl"><?= count($empfaenger) ?></strong> Empfänger <span class="meta klein">(nur bestätigte Anmeldungen bzw. Abonnierende)</span></p>
            <details class="tabelle-details"><summary>Liste ansehen</summary><ul class="schlicht empfaenger-liste" id="rm-liste"><?php foreach ($empfaenger as $x): ?><li><?= e(person_name($x) ?: $x['email']) ?></li><?php endforeach; ?></ul></details></div>
        <?= feld('betreff', 'Betreff', ['wert' => $w['betreff'], 'attr' => ['maxlength' => 200]]) ?>
        <div class="feld">
            <label for="rm-html">Text der Mail</label>
            <textarea id="rm-html" name="html" rows="14" class="mono"<?= editor_attribute('block', true) ?>><?= e($w['html']) ?></textarea>
            <p class="hilfe"><strong>Platzhalter</strong> ersetzt die App beim Versand für jede Person: <code>{vorname}</code> und <code>{nachname}</code> aus der Anmeldung; <code>{workshops}</code> die Workshops mit festem Platz (je Zeile Titel und Uhrzeit); <code>{link}</code> ein persönlicher Link zu „Deine Anmeldung“ (48 Stunden gültig), auch als Ziel eines Knopfs. Im Newsletter bleiben <code>{nachname}</code> und <code>{workshops}</code> leer, <code>{link}</code> führt zur Abo-Verwaltung.</p>
            <p class="hilfe">Bilder (JPG/PNG, höchstens <?= RM_BILD_MAX_KANTE ?> px) werden auf dieser Webseite gespeichert und in die Mail eingebettet – keine fremden Server. Klick aufs Bild schaltet zwischen voller und halber Breite. Impressum und Datenschutz hängt die App an.</p>
        </div>
        <div class="feld rm-bild-feld"><label for="rm-bild">Bild hochladen</label><input id="rm-bild" name="bild" type="file" accept="image/jpeg,image/png"><button class="knopf zweit klein" type="submit" name="aktion" value="bild">Bild ans Ende setzen</button></div>
        <div class="aktionen">
            <button class="knopf zweit" type="submit" name="aktion" value="test">Testmail an mich</button>
            <button class="knopf zweit" type="submit" name="aktion" value="speichern">Entwurf speichern</button>
            <details class="planen"<?= isset($fehler['geplant']) ? ' open' : '' ?>><summary class="knopf zweit">Planen …</summary>
                <div class="planen-feld feld"><?= feld('geplant', 'Senden am', ['typ' => 'datetime-local', 'wert' => isset($_POST['geplant']) ? post_text('geplant') : $jetztLokal, 'fehler' => $fehler['geplant'] ?? null, 'ohne_rahmen' => true]) ?>
                    <button class="knopf klein" type="submit" name="aktion" value="planen">Planen</button></div>
            </details>
            <?= bestaetigung_html('An ' . count($empfaenger) . ' senden', '', 'Ja, jetzt senden', ['aktion' => 'senden'], [
                'im_formular' => true, 'art' => 'gruen', 'titel' => 'Mailing senden?', 'klasse' => 'senden', 'knopfklasse' => 'knopf', 'knopf_id' => 'rm-senden',
                'frage_html' => 'Jetzt an <strong id="rm-zahl2">' . count($empfaenger) . '</strong> Empfänger senden? Das lässt sich nur für noch nicht versandte Mails abbrechen.']) ?>
        </div>
        <?php if ($id !== null): ?>
        <?= bestaetigung_html('Entwurf löschen', 'Diesen Entwurf löschen?', 'Ja, löschen', ['aktion' => 'loeschen'], ['im_formular' => true, 'titel' => 'Entwurf löschen?', 'knopfklasse' => 'klein-link']) ?>
        <?php endif; ?>
    </form>
    <div class="stapel">
        <div class="kasten">
            <div class="stat-kopf"><h2 class="zwischen klein-titel">Vorschau</h2>
                <?php if ($id !== null): ?><div class="filter" role="group" aria-label="Vorschau-Breite"><button type="button" class="knopf klein" data-breite="handy" aria-pressed="true">Handy</button><button type="button" class="knopf klein zweit" data-breite="desktop" aria-pressed="false">Desktop</button></div><?php endif; ?></div>
            <?php if ($id === null): ?><?= leer_hinweis('Die Vorschau erscheint nach dem ersten Speichern.') ?><?php else: ?>
            <p class="meta klein">Gespeicherter Stand<?= $empfaenger !== [] ? ', mit den Daten von ' . e(person_name($empfaenger[0])) : '' ?>. „Entwurf speichern“ aktualisiert sie.</p>
            <div class="ml-vorschau-rahmen handy" id="rm-vorschau"><iframe class="ml-vorschau-frame" title="Vorschau der Mail" src="/team/mailings/vorschau?id=<?= $id ?>&amp;v=<?= e(substr(hash('sha256', (string)$rm['geaendert_am'] . $rm['html']), 0, 8)) ?>" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"></iframe></div>
            <?php endif; ?>
        </div>
        <?php $versandKasten(); $zuletztKasten(); ?>
    </div>
</div>
<?php endif;
team_seite_fuss();
