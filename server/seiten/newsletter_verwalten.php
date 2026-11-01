<?php
declare(strict_types=1);

/*
 * Newsletter verwalten über den Link aus jeder Newsletter-Mail (AP-7): Listen
 * an- und abwählen oder ganz abmelden – sofort, ohne Login. Der Link beweist
 * die Adresse; neue Listen gelten deshalb gleich als bestätigt.
 */

anm_sitzung();
$token = is_string($_REQUEST['t'] ?? null) ? $_REQUEST['t'] : '';
$f = nl_link_finden($token, 'verwalten');
if ($f !== null && ist_post()) {
    $pid = (int)$f['person']['id'];
    $ziel = '/newsletter/verwalten?t=' . rawurlencode($token);
    if (aktion() === 'alle') {
        nl_abmelden($pid);
        fertig('ok', 'Du bist von allen Newslettern abgemeldet. Deine Adresse ist gelöscht.', $ziel);
    }
    $interessen = nl_interessen((array)($_POST['nl_interesse'] ?? []));
    if (($wf = nl_wahl_fehler((array)($_POST['listen'] ?? []), $interessen)) !== null) {
        fertig('fehler', $wf . ' Nichts geändert.', $ziel);
    }
    nl_listen_setzen($pid, (array)($_POST['listen'] ?? []), 'verwalten', $interessen);
    fertig('ok', nl_person($pid) === null ? 'Du bist von allen Newslettern abgemeldet. Deine Adresse ist gelöscht.' : 'Gespeichert.', $ziel);
}
$p = $f !== null ? nl_person((int)$f['person']['id']) : null;
seite_kopf('Newsletter verwalten', ['nav' => false]);
echo seiten_kopfzeile('Deine Newsletter', 'Newsletter');
?>
<div class="innen">
    <?= meldung_html(flash_holen()) ?>
<?php if ($p === null): ?>
    <p class="text">Für diesen Link gibt es keine Newsletter mehr<?= $f === null ? ' oder er ist abgelaufen' : '' ?>.</p>
    <p><a class="knopf zweit" href="/newsletter">Newsletter bestellen</a></p>
<?php else: $abos = nl_abos((int)$p['id']); ?>
    <p class="text">Für <?= e($p['email']) ?>. Häkchen setzen oder entfernen und speichern.</p>
    <form class="form" method="post" action="/newsletter/verwalten">
        <?= csrf_feld() ?><?= versteckt_html(['t' => $token]) ?>
        <fieldset class="feld nl-listen"><legend>Newsletter</legend>
        <?= nl_listen_html(array_keys(array_filter($abos, fn($st) => $st === 'bestaetigt')), nl_interessen_von((int)$p['id']) ?: 'klinik,praeklinik', $abos) ?>
        </fieldset>
        <p class="meta klein"><?= e(NL_EINWILLIGUNG) ?></p>
        <div class="aktionen">
            <button class="knopf" type="submit" name="aktion" value="speichern">Speichern</button>
            <button class="knopf gefahr" type="submit" name="aktion" value="alle">Von allen abmelden</button>
        </div>
    </form>
<?php endif; ?>
</div>
<?php
seite_fuss();
