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
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $f !== null) {
    csrf_pruefen();
    $pid = (int)$f['person']['id'];
    if (($_POST['aktion'] ?? '') === 'alle') {
        nl_abmelden($pid);
        flash_setzen('ok', 'Du bist von allen Newslettern abgemeldet. Deine Adresse ist gelöscht.');
    } else {
        nl_listen_setzen($pid, (array)($_POST['listen'] ?? []), 'verwalten');
        flash_setzen('ok', nl_person($pid) === null ? 'Du bist von allen Newslettern abgemeldet. Deine Adresse ist gelöscht.' : 'Gespeichert.');
    }
    umleiten('/newsletter/verwalten?t=' . rawurlencode($token));
}
$p = $f !== null ? nl_person((int)$f['person']['id']) : null;
seite_kopf('Newsletter verwalten', ['nav' => false]);
?>
<div class="innen form-kopf">
    <p class="kicker">Newsletter</p>
    <h1 class="titel">Deine Newsletter</h1>
</div>
<div class="innen">
    <?= meldung_html(flash_holen()) ?>
<?php if ($p === null): ?>
    <p class="text">Für diesen Link gibt es keine Newsletter mehr<?= $f === null ? ' oder er ist abgelaufen' : '' ?>.</p>
    <p><a class="knopf zweit" href="/newsletter">Newsletter bestellen</a></p>
<?php else: $abos = nl_abos((int)$p['id']); ?>
    <p class="text">Für <?= e($p['email']) ?>. Häkchen setzen oder entfernen und speichern.</p>
    <form class="form" method="post" action="/newsletter/verwalten">
        <?= csrf_feld() ?><input type="hidden" name="t" value="<?= e($token) ?>">
        <fieldset class="feld nl-listen"><legend>Newsletter</legend>
        <?php foreach (nl_listen(true) as $l): $st = $abos[(int)$l['id']] ?? null; ?>
            <label class="haken"><input type="checkbox" name="listen[]" value="<?= (int)$l['id'] ?>"<?= $st === 'bestaetigt' ? ' checked' : '' ?>><span><strong><?= e($l['name']) ?></strong><?= $st === 'offen' ? ' <span class="chip offen">wartet auf Bestätigung</span>' : '' ?><?= $l['beschreibung'] !== '' ? '<br><span class="meta klein">' . e($l['beschreibung']) . '</span>' : '' ?></span></label>
        <?php endforeach; ?>
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
