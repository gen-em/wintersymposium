<?php
declare(strict_types=1);

/*
 * Newsletter im Team-Bereich (AP-7, E-52): Listen anlegen und ändern,
 * Abonnierende suchen und löschen, jemanden einladen (Bestätigungsmail –
 * direkt als bestätigt eintragen geht nicht, Double-Opt-In). Versendet wird
 * über „Mailings“ mit der Empfängerart „Newsletter-Listen“.
 */

$konto = team_tor(['betreiber']); // Newsletter gilt für alle Veranstaltungen: nur Betreiber (AP-12, E-60)
$ich = (int)$konto['id'];
$fehler = [];
$listeForm = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    switch ((string)($_POST['aktion'] ?? '')) {
        case 'liste':
            $lid = (int)($_POST['id'] ?? 0) ?: null;
            $r = nl_liste_pruefen($_POST);
            if ($r['fehler'] === []) {
                nl_liste_speichern($lid, $r['werte'], $ich);
                flash_setzen('ok', 'Liste gespeichert.');
                umleiten('/team/newsletter');
            }
            $fehler = $r['fehler'];
            $listeForm = $_POST;
            break;
        case 'einladen':
            $email = mail_adresse((string)($_POST['email'] ?? ''));
            $ids = array_map('intval', (array)($_POST['listen'] ?? []));
            if ($email === null || $ids === []) {
                $fehler['einladen'] = 'Bitte eine gültige Adresse und mindestens eine Liste angeben.';
                break;
            }
            nl_anfragen($email, (string)($_POST['vorname'] ?? ''), $ids, 'team', $ich);
            flash_setzen('ok', 'Einladung an ' . $email . ' geschickt. Eingetragen ist die Person erst, wenn sie bestätigt.');
            umleiten('/team/newsletter');
        case 'loeschen':
            flash_setzen(nl_person_loeschen((int)($_POST['person'] ?? 0), $ich) ? 'ok' : 'fehler', 'Aus allen Listen gelöscht.');
            umleiten('/team/newsletter?' . http_build_query(array_filter(['q' => $_POST['q'] ?? null, 'liste' => $_POST['liste'] ?? null])));
    }
}
$zahlen = nl_zahlen();
$q = trim((string)($_GET['q'] ?? ''));
$filterListe = (int)($_GET['liste'] ?? 0);
$personen = nl_personen($q, $filterListe);
$bearbeiten = isset($_GET['bearbeiten']) ? nl_liste((int)$_GET['bearbeiten']) : null;
$lf = $listeForm ?? ($bearbeiten ?? ['name' => '', 'beschreibung' => '', 'absender_name' => '', 'sichtbar' => 1, 'bei_anmeldung' => 0]);
team_seite_kopf($konto, 'Newsletter', '/team/newsletter');
?>
<p class="meta klein">Versand über <a href="/team/mailings?neu=1">Mailings</a> mit der Empfängerart „Newsletter-Listen“. Eingetragen ist nur, wer den Link in der Bestätigungsmail angeklickt hat (Double-Opt-In); unbestätigte Anfragen verschwinden nach <?= NL_BESTAETIGEN_TAGE ?> Tagen, Abgemeldete werden gelöscht.</p>
<div class="kacheln">
    <?php foreach ($zahlen as $lid => $z): ?>
    <div class="kachel"><div class="lbl"><?= e($z['liste']['name']) ?><?= (int)$z['liste']['sichtbar'] === 0 ? ' · ausgeblendet' : '' ?></div><div class="zahl"><?= $z['bestaetigt'] ?></div>
        <p class="meta klein"><?= $z['offen'] ?> warten auf Bestätigung · <?= $z['abgemeldet'] ?> abgemeldet<?= (int)$z['liste']['bei_anmeldung'] === 1 ? '<br>bei der Symposium-Anmeldung angeboten' : '' ?></p>
        <p class="aktionen"><a class="knopf zweit klein-knopf" href="/team/newsletter?bearbeiten=<?= $lid ?>#liste">Bearbeiten</a> <?= export_knoepfe(['art' => 'newsletter', 't' => $lid]) ?></p></div>
    <?php endforeach; ?>
</div>

<div class="team-raster abstand-oben">
    <div class="kasten">
        <h2 class="zwischen">Abonnierende</h2>
        <form class="filterzeile" method="get" action="/team/newsletter" role="search">
            <label class="sr" for="nl-q">Suchen</label><input id="nl-q" name="q" type="search" value="<?= e($q) ?>" placeholder="Adresse oder Vorname">
            <label class="sr" for="nl-liste">Liste</label><select id="nl-liste" name="liste"><option value="0">Alle Listen</option><?php foreach ($zahlen as $lid => $z): ?><option value="<?= $lid ?>"<?= $filterListe === $lid ? ' selected' : '' ?>><?= e($z['liste']['name']) ?></option><?php endforeach; ?></select>
            <button class="knopf zweit klein-knopf" type="submit">Suchen</button>
        </form>
        <?php if ($personen === []): ?><p class="hinweis-leer">Niemand gefunden.</p><?php else: ?>
        <ul class="schlicht nl-personen">
            <?php foreach ($personen as $p): ?>
            <li><strong><?= e($p['email']) ?></strong><?= $p['vorname'] !== '' ? ' <span class="meta">' . e($p['vorname']) . '</span>' : '' ?><br>
                <span class="klein"><?php foreach ($p['abos'] as $a): ?><span class="chip <?= $a['status'] === 'bestaetigt' ? 'ok' : 'offen' ?>"><?= e($a['name']) ?><?= $a['status'] === 'offen' ? ' · wartet' : '' ?></span> <?php endforeach; ?></span>
                <details class="bestaetigen inline-details" data-titel="Aus allen Listen löschen?"><summary class="knopf zweit klein-knopf">Löschen</summary>
                    <form method="post" action="/team/newsletter"><?= csrf_feld() ?><input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="person" value="<?= (int)$p['id'] ?>"><input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="liste" value="<?= $filterListe ?: '' ?>">
                        <p><?= e($p['email']) ?> wird aus allen Listen gelöscht.</p><button class="knopf gefahr" type="submit">Ja, löschen</button></form>
                </details></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <div class="stapel">
        <div class="kasten">
            <h2 class="zwischen">Einladen</h2>
            <p class="meta klein">Die Person bekommt eine Bestätigungsmail und ist erst nach dem Klick eingetragen.</p>
            <form class="form" method="post" action="/team/newsletter"><?= csrf_feld() ?><input type="hidden" name="aktion" value="einladen">
                <div class="feld"><label for="ein-email">E-Mail</label><input id="ein-email" name="email" type="email" maxlength="190"></div>
                <div class="feld"><label for="ein-vorname">Vorname (freiwillig)</label><input id="ein-vorname" name="vorname" type="text" maxlength="80"></div>
                <fieldset class="feld nl-listen"><legend>Listen</legend><?php foreach ($zahlen as $lid => $z): ?><label class="haken"><input type="checkbox" name="listen[]" value="<?= $lid ?>"><span><?= e($z['liste']['name']) ?></span></label><?php endforeach; ?></fieldset>
                <?= isset($fehler['einladen']) ? '<p class="feldfehler">' . e($fehler['einladen']) . '</p>' : '' ?>
                <div><button class="knopf zweit" type="submit">Bestätigungsmail schicken</button></div>
            </form>
        </div>
        <div class="kasten" id="liste">
            <h2 class="zwischen"><?= $bearbeiten !== null ? 'Liste bearbeiten' : 'Neue Liste' ?></h2>
            <form class="form" method="post" action="/team/newsletter"><?= csrf_feld() ?><input type="hidden" name="aktion" value="liste"><?php if ($bearbeiten !== null): ?><input type="hidden" name="id" value="<?= (int)$bearbeiten['id'] ?>"><?php endif; ?>
                <div class="feld"><label for="l-name">Name</label><input id="l-name" name="name" type="text" maxlength="80" value="<?= e((string)$lf['name']) ?>"<?= isset($fehler['name']) ? ' aria-invalid="true"' : '' ?>><?= isset($fehler['name']) ? '<p class="feldfehler">' . e($fehler['name']) . '</p>' : '' ?></div>
                <div class="feld"><label for="l-beschreibung">Beschreibung (öffentlich)</label><textarea id="l-beschreibung" name="beschreibung" rows="2"<?= editor_attribute('inline') ?>><?= e((string)$lf['beschreibung']) ?></textarea></div>
                <div class="feld"><label for="l-absender">Absendername</label><input id="l-absender" name="absender_name" type="text" maxlength="80" value="<?= e((string)$lf['absender_name']) ?>" placeholder="z. B. Allgäu Akut"><p class="hilfe">Adresse bleibt newsletter@ (bzw. anmeldung@, solange newsletter@ fehlt).</p></div>
                <label class="haken"><input type="checkbox" name="sichtbar" value="1"<?= !empty($lf['sichtbar']) ? ' checked' : '' ?>><span>Öffentlich wählbar (Seite „Newsletter“)</span></label>
                <label class="haken"><input type="checkbox" name="bei_anmeldung" value="1"<?= !empty($lf['bei_anmeldung']) ? ' checked' : '' ?>><span>Bei der Symposium-Anmeldung anbieten (nur eine Liste)</span></label>
                <div class="aktionen"><button class="knopf" type="submit">Speichern</button><?php if ($bearbeiten !== null): ?><a class="knopf zweit" href="/team/newsletter">Abbrechen</a><?php endif; ?></div>
            </form>
        </div>
    </div>
</div>
<?php
team_seite_fuss();
