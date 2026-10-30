<?php
declare(strict_types=1);

/*
 * Mailings (AP-6, Mockup „Mailings“, E-41, E-50): Rundmails an Teilnehmende.
 * Ohne ?id: Übersicht (Entwürfe, geplante, gesendete) und Versand-Stand.
 * Mit ?id (oder ?neu=1): Editor für Entwürfe, sonst die gesendete Mail mit
 * Empfängerliste (Archiv). Senden, Planen und Abbrechen stehen im Protokoll.
 */

$konto = team_tor();
$ich = (int)$konto['id'];
$neu = isset($_GET['neu']);
$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) && (int)$_POST['id'] > 0 ? (int)$_POST['id'] : null);
$rm = $id !== null ? rm_laden($id) : null;
if ($id !== null && $rm === null) {
    nicht_gefunden();
}
$fehler = [];
$eingabe = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $aktion = (string)($_POST['aktion'] ?? '');
    $ziel = fn(?int $i) => '/team/mailings' . ($i !== null ? '?id=' . $i : '');
    if (in_array($aktion, ['abbrechen', 'kopieren'], true) && $rm !== null) {
        if ($aktion === 'abbrechen') {
            $t = rm_abbrechen($id, $ich);
            flash_setzen($t !== null ? 'ok' : 'fehler', $t ?? 'Das Mailing lässt sich nicht mehr abbrechen.');
            umleiten($ziel($id));
        }
        $kopie = rm_kopieren($id, $ich);
        flash_setzen('ok', 'Kopie als neuer Entwurf angelegt.');
        umleiten($ziel($kopie));
    }
    if ($aktion === 'loeschen' && $rm !== null) {
        flash_setzen(rm_loeschen($id, $ich) ? 'ok' : 'fehler', 'Entwurf gelöscht.');
        umleiten('/team/mailings');
    }
    if ($rm !== null && $rm['status'] !== 'entwurf') {
        flash_setzen('fehler', 'Dieses Mailing ist kein Entwurf mehr und lässt sich nicht ändern.');
        umleiten($ziel($id));
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
                    db_ausfuehren('UPDATE rundmail SET html = CONCAT(html, ?) WHERE id = ?', ['<p><img src="/team/mailings/bild?id=' . $b['id'] . '" alt="" data-breite="100"></p>', $id]);
                    flash_setzen('ok', 'Bild eingefügt (am Ende der Mail).');
                } catch (RuntimeException $ex) {
                    flash_setzen('fehler', $ex->getMessage());
                }
                umleiten($ziel($id));
            case 'test':
                $m = rm_zusammensetzen($rm, rm_werte(['vorname' => tk_namen($konto)['vorname'], 'nachname' => tk_namen($konto)['nachname']], false), 'cid', substr(strrchr(mail_absender(mail_konfig($rm['postfach'])), '@') ?: '@localhost', 1));
                $f = mail_zustellen($konto['email'], '[Test] ' . $m['betreff'], $m['text'], ['postfach' => $rm['postfach'], 'html' => $m['html'], 'bilder' => $m['bilder'], 'rundmail' => true]);
                flash_setzen($f === null ? 'ok' : 'fehler', $f === null ? 'Testmail an ' . $konto['email'] . ' gesendet (Platzhalter mit deinem Namen, {link} zeigt auf „Deine Anmeldung“).' : 'Testmail nicht gesendet: ' . $f);
                umleiten($ziel($id));
            case 'planen':
                $roh = str_replace('T', ' ', (string)($_POST['geplant'] ?? ''));
                $utc = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $roh) === 1 ? lokal_nach_utc($roh . ':00') : null;
                $sf = rm_sendefehler($rm);
                if ($utc === null || strtotime($utc . ' UTC') < time() + 60) {
                    $fehler['geplant'] = 'Bitte einen Zeitpunkt in der Zukunft wählen.';
                } elseif ($sf !== []) {
                    $fehler['senden'] = implode(' ', $sf);
                } else {
                    rm_planen($id, $utc, $ich);
                    flash_setzen('ok', 'Geplant für ' . datum_zeit_text($utc) . '. Bis dahin kannst du die Planung aufheben.');
                    umleiten($ziel($id));
                }
                break;
            case 'senden':
                $sf = rm_sendefehler($rm);
                if ($sf !== []) {
                    $fehler['senden'] = implode(' ', $sf);
                    break;
                }
                $n = rm_einreihen($id, $ich);
                flash_setzen($n !== null ? 'ok' : 'fehler', $n !== null ? $n . ' Mails stehen in der Warteschlange. Größere Mailings verteilt die App auf mehrere Tage.' : 'Nicht gesendet.');
                umleiten($ziel($id));
            default:
                flash_setzen('ok', 'Entwurf gespeichert.');
                umleiten($ziel($id));
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
        <?php if ($zuletzt === []): ?><p class="hinweis-leer">Noch nichts gesendet.</p><?php else: ?>
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
        <?php if ($liste === []): ?><p class="hinweis-leer">Noch keine Mailings.</p><?php else: ?>
        <div class="tabelle-wrap"><table class="t karten">
            <thead><tr><th>Betreff</th><th>Status</th><th>Empfänger</th><th>Datum</th></tr></thead>
            <tbody>
            <?php foreach ($liste as $r):
                $datum = $r['status'] === 'geplant' ? $r['geplant_fuer'] : ($r['gesendet_am'] ?? $r['geaendert_am']); ?>
                <tr>
                    <td data-label="Betreff"><a href="/team/mailings?id=<?= (int)$r['id'] ?>"><?= e($r['betreff'] !== '' ? $r['betreff'] : '(ohne Betreff)') ?></a><br><span class="meta klein"><?= e($r['auswahl_text'] !== '' ? $r['auswahl_text'] : rm_auswahl_text($r['auswahl'], $r['berufsgruppe'])) ?></span></td>
                    <td data-label="Status"><span class="chip <?= in_array($r['status'], ['gesendet'], true) ? 'ok' : ($r['status'] === 'abgebrochen' ? 'gesperrt' : 'offen') ?>"><?= e(RM_STATUS[$r['status']]) ?></span></td>
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
    team_seite_fuss();
    return;
endif;

/* ---- Gesendet, geplant, abgebrochen: Archiv ---- */
if ($rm !== null && $rm['status'] !== 'entwurf'):
    $fs = rm_fortschritt($id);
    $empf = db_zeilen('SELECT name, email FROM rundmail_empfaenger WHERE rundmail_id = ? ORDER BY name', [$id]); ?>
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
            <div><dt>Versand</dt><dd><?php if ($rm['status'] === 'gesendet' && $fs['fehler'] === 0): ?>vollständig gesendet<?php else: ?><?= $fs['gesendet'] ?> gesendet<?= $fs['wartet'] > 0 ? ' · ' . $fs['wartet'] . ' warten (Tageslimit)' : '' ?><?php endif; ?><?= $fs['fehler'] > 0 ? ' · <span class="chip wl">' . $fs['fehler'] . ' Fehler</span>' : '' ?></dd></div>
            <?php endif; ?>
        </dl>
        <?php if ($rm['status'] !== 'geplant'): ?>
        <details class="tabelle-details"><summary>Empfängerliste (<?= count($empf) ?>)</summary>
            <?php if ($empf === []): ?><p class="meta klein">Die Liste ist nach der Löschfrist entfernt (E-24).</p><?php else: ?>
            <ul class="schlicht empfaenger-liste"><?php foreach ($empf as $x): ?><li><?= e($x['name']) ?> <span class="meta klein"><?= e($x['email']) ?></span></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if ($rm['loeschen_ab'] !== null): ?><p class="meta klein">Wird am <?= e(tag_text($rm['loeschen_ab'])) ?> gelöscht (14 Monate nach dem Symposium).</p><?php endif; ?>
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
    team_seite_fuss();
    return;
endif;

/* ---- Editor ---- */
$w = $eingabe ?? ($rm !== null ? $rm : ['betreff' => '', 'html' => '<p>Hallo {vorname},</p><p></p><p>Dein Orga-Team</p>', 'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system']);
$empfaenger = rm_empfaenger($w['auswahl'], $w['berufsgruppe']);
$fehlerFeld = fn(string $f) => isset($fehler[$f]) ? '<p class="feldfehler" id="f-' . $f . '">' . e($fehler[$f]) . '</p>' : '';
$ungueltig = fn(string $f) => isset($fehler[$f]) ? ' aria-invalid="true" aria-describedby="f-' . $f . '"' : '';
$jetztLokal = (new DateTimeImmutable('now', new DateTimeZone(ANZEIGE_ZEITZONE)))->modify('+1 hour')->format('Y-m-d\TH:00');
?>
<p><a href="/team/mailings">← Alle Mailings</a></p>
<?= isset($fehler['senden']) ? '<div class="meldung fehler" role="alert">' . e($fehler['senden']) . '</div>' : '' ?>
<div class="ml-raster">
    <form class="kasten form ml-editor rm-form" method="post" action="/team/mailings<?= $id !== null ? '?id=' . $id : '' ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_feld() ?>
        <?php if ($id !== null): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
        <?php $nlAuswahl = rm_nl_listen($w['auswahl']); ?>
        <fieldset class="feld rm-art"><legend>Empfänger</legend>
            <label class="haken"><input type="radio" name="art" value="teilnehmende"<?= $nlAuswahl === null ? ' checked' : '' ?>><span>Teilnehmende des Symposiums</span></label>
            <label class="haken"><input type="radio" name="art" value="newsletter"<?= $nlAuswahl !== null ? ' checked' : '' ?>><span>Newsletter-Listen</span></label>
        </fieldset>
        <fieldset class="feld rm-listen" data-empfaenger="newsletter"><legend>Newsletter-Listen</legend>
            <?php foreach (nl_zahlen() as $lid => $z): ?><label class="haken"><input type="checkbox" name="listen[]" value="<?= $lid ?>"<?= in_array($lid, $nlAuswahl ?? [], true) ? ' checked' : '' ?>><span><?= e($z['liste']['name']) ?> <span class="meta klein">(<?= $z['bestaetigt'] ?> bestätigt)</span></span></label><?php endforeach; ?>
            <p class="hilfe">Wer auf mehreren Listen steht, bekommt die Mail nur einmal. Abmeldelink und List-Unsubscribe hängt die App an; ohne Vorname lautet die Anrede „Hallo,“.</p>
        </fieldset>
        <div class="paar" data-empfaenger="teilnehmende">
            <div class="feld"><label for="auswahl">An</label>
                <select id="auswahl" name="auswahl"<?= $ungueltig('auswahl') ?>><?php foreach (rm_auswahl_gruppen() as $gruppe => $opt): ?><?= $gruppe !== '' ? '<optgroup label="' . e($gruppe) . '">' : '' ?><?php foreach ($opt as $k => $t): ?><option value="<?= e($k) ?>"<?= $w['auswahl'] === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?><?= $gruppe !== '' ? '</optgroup>' : '' ?><?php endforeach; ?></select><?= $fehlerFeld('auswahl') ?></div>
            <div class="feld"><label for="berufsgruppe">Berufsgruppe</label>
                <select id="berufsgruppe" name="berufsgruppe"><option value="">Alle Berufsgruppen</option><?php foreach (va_liste('berufsgruppen') as $g): ?><option value="<?= e($g) ?>"<?= $w['berufsgruppe'] === $g ? ' selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select><?= $fehlerFeld('berufsgruppe') ?></div>
        </div>
        <div class="feld"><label for="postfach">Absender</label>
            <select id="postfach" name="postfach"<?= $ungueltig('postfach') ?>><?php foreach ($stand['postfaecher'] as $p => $pf): ?><option value="<?= e($p) ?>"<?= $w['postfach'] === $p ? ' selected' : '' ?><?= $pf['da'] ? '' : ' disabled' ?>><?= e($pf['titel']) ?><?= $pf['da'] ? '' : ' – nicht eingerichtet' ?></option><?php endforeach; ?></select><?= $fehlerFeld('postfach') ?>
            <p class="hilfe"><?php foreach ($stand['postfaecher'] as $p => $pf): if ($pf['da']): ?><span class="umbruch"><?= e($pf['titel']) ?>: <?= e(mail_absender_name(mail_konfig($p)) . ' <' . $pf['absender'] . '>') ?></span><br><?php endif; endforeach; ?></p></div>
        <div class="empf"><p><strong class="mono" id="rm-zahl"><?= count($empfaenger) ?></strong> Empfänger <span class="meta klein">(nur bestätigte Anmeldungen bzw. Abonnierende)</span></p>
            <details class="tabelle-details"><summary>Liste ansehen</summary><ul class="schlicht empfaenger-liste" id="rm-liste"><?php foreach ($empfaenger as $x): ?><li><?= e(trim($x['vorname'] . ' ' . $x['nachname']) ?: $x['email']) ?></li><?php endforeach; ?></ul></details></div>
        <div class="feld"><label for="betreff">Betreff</label><input id="betreff" name="betreff" type="text" maxlength="200" value="<?= e($w['betreff']) ?>"></div>
        <div class="feld">
            <label for="rm-html">Text der Mail</label>
            <textarea id="rm-html" name="html" rows="14" class="mono" data-editor="block" data-werkzeug="fett kursiv titel | liste nliste link bild knopf | html" data-bild-url="/team/mailings/bild" data-knopf-ziel="{link}" data-platzhalter="<?= e(json_encode(RM_PLATZHALTER, JSON_UNESCAPED_UNICODE)) ?>"><?= e($w['html']) ?></textarea>
            <p class="hilfe">Bilder werden hochgeladen und auf dieser Webseite gespeichert (JPG/PNG, verkleinert auf höchstens 1200 px), dann direkt in die Mail eingebettet – keine fremden Server. Ein Klick aufs Bild im Text schaltet zwischen voller und halber Breite. Erlaubt sind nur einfache Elemente (Text, Überschrift, Listen, Link, Bild, Knopf). Derselbe Editor steht bei den Texten der Webseite, im Programm und bei den Dozierenden. Impressum und Datenschutz hängt die App an.</p>
        </div>
        <div class="feld rm-bild-feld"><label for="rm-bild">Bild hochladen</label><input id="rm-bild" name="bild" type="file" accept="image/jpeg,image/png"><button class="knopf zweit klein-knopf" type="submit" name="aktion" value="bild">Bild ans Ende setzen</button></div>
        <div class="aktionen">
            <button class="knopf zweit" type="submit" name="aktion" value="test">Testmail an mich</button>
            <button class="knopf zweit" type="submit" name="aktion" value="speichern">Entwurf speichern</button>
            <details class="planen<?= isset($fehler['geplant']) ? '' : '' ?>"<?= isset($fehler['geplant']) ? ' open' : '' ?>><summary class="knopf zweit">Planen …</summary>
                <div class="planen-feld feld"><label for="geplant">Senden am</label><input id="geplant" name="geplant" type="datetime-local" value="<?= e((string)($_POST['geplant'] ?? $jetztLokal)) ?>"<?= $ungueltig('geplant') ?>><?= $fehlerFeld('geplant') ?>
                    <button class="knopf klein-knopf" type="submit" name="aktion" value="planen">Planen</button></div>
            </details>
            <details class="bestaetigen senden" data-art="gruen" data-titel="Mailing senden?"><summary class="knopf" id="rm-senden">An <?= count($empfaenger) ?> senden</summary>
                <div class="bestaetigen-feld"><p>Jetzt an <strong id="rm-zahl2"><?= count($empfaenger) ?></strong> Empfänger senden? Das lässt sich nur für noch nicht versandte Mails abbrechen.</p>
                    <button class="knopf" type="submit" name="aktion" value="senden">Ja, jetzt senden</button></div>
            </details>
        </div>
        <?php if ($id !== null): ?>
        <details class="bestaetigen" data-titel="Entwurf löschen?"><summary class="klein-link">Entwurf löschen</summary>
            <div class="bestaetigen-feld"><p>Diesen Entwurf löschen?</p><button class="knopf gefahr" type="submit" name="aktion" value="loeschen">Ja, löschen</button></div>
        </details>
        <?php endif; ?>
    </form>
    <div class="stapel">
        <div class="kasten">
            <div class="stat-kopf"><h2 class="zwischen klein-titel">Vorschau</h2>
                <?php if ($id !== null): ?><div class="filter" role="group" aria-label="Vorschau-Breite"><button type="button" class="knopf klein-knopf" data-breite="handy" aria-pressed="true">Handy</button><button type="button" class="knopf klein-knopf zweit" data-breite="desktop" aria-pressed="false">Desktop</button></div><?php endif; ?></div>
            <?php if ($id === null): ?><p class="hinweis-leer">Die Vorschau erscheint nach dem ersten Speichern.</p><?php else: ?>
            <p class="meta klein">Gespeicherter Stand<?= $empfaenger !== [] ? ', mit den Daten von ' . e($empfaenger[0]['vorname'] . ' ' . $empfaenger[0]['nachname']) : '' ?>. „Entwurf speichern“ aktualisiert sie.</p>
            <div class="ml-vorschau-rahmen handy" id="rm-vorschau"><iframe class="ml-vorschau-frame" title="Vorschau der Mail" src="/team/mailings/vorschau?id=<?= $id ?>&amp;v=<?= e(substr(hash('sha256', (string)$rm['geaendert_am'] . $rm['html']), 0, 8)) ?>" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"></iframe></div>
            <?php endif; ?>
        </div>
        <?php $versandKasten(); $zuletztKasten(); ?>
    </div>
</div>
<?php
team_seite_fuss();
