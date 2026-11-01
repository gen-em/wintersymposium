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
if (ist_post()) {
    switch (aktion()) {
        case 'liste':
            $lid = post_zahl('id') ?: null;
            $r = nl_liste_pruefen($_POST);
            if ($r['fehler'] === []) {
                nl_liste_speichern($lid, $r['werte'], $ich);
                fertig('ok', 'Liste gespeichert.', '/team/newsletter');
            }
            $fehler = $r['fehler'];
            $listeForm = $_POST;
            break;
        case 'einladen':
            $email = mail_adresse(post_text('email'));
            $ids = array_map('intval', (array)($_POST['listen'] ?? []));
            if ($email === null || $ids === []) {
                $fehler['einladen'] = 'Bitte eine gültige Adresse und mindestens eine Liste angeben.';
                break;
            }
            nl_anfragen($email, post_text('vorname'), $ids, 'team', $ich);
            fertig('ok', 'Einladung an ' . $email . ' geschickt. Eingetragen ist die Person erst, wenn sie bestätigt.', '/team/newsletter');
        case 'loeschen':
            fertig(nl_person_loeschen(post_zahl('person'), $ich) ? 'ok' : 'fehler', 'Aus allen Listen gelöscht.', '/team/newsletter?' . http_build_query(array_filter(['q' => post_text('q'), 'liste' => post_text('liste')])));
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
        <p class="aktionen"><a class="knopf zweit klein" href="/team/newsletter?bearbeiten=<?= $lid ?>#liste">Bearbeiten</a> <?= export_knoepfe(['art' => 'newsletter', 't' => $lid]) ?></p></div>
    <?php endforeach; ?>
</div>

<div class="team-raster abstand-oben">
    <div class="kasten">
        <h2 class="zwischen">Abonnierende</h2>
        <?= filter_leiste(['ziel' => '/team/newsletter', 'suche' => true, 'felder' =>
            feld('q', 'Suchen', ['typ' => 'search', 'id' => 'nl-q', 'wert' => $q, 'attr' => ['placeholder' => 'Adresse oder Vorname'], 'sr' => true, 'ohne_rahmen' => true])
            . feld('liste', 'Liste', ['typ' => 'auswahl', 'id' => 'nl-liste', 'leer' => 'Alle Listen', 'wert' => (string)$filterListe, 'sr' => true, 'ohne_rahmen' => true,
                'optionen' => array_map(fn($z) => $z['liste']['name'], $zahlen)])]) ?>
        <?php if ($personen === []): ?><?= leer_hinweis('Niemand gefunden.') ?><?php else: ?>
        <ul class="schlicht nl-personen">
            <?php foreach ($personen as $p): ?>
            <li><strong><?= e($p['email']) ?></strong><?= $p['vorname'] !== '' ? ' <span class="meta">' . e($p['vorname']) . '</span>' : '' ?><br>
                <span class="klein"><?php foreach ($p['abos'] as $a): ?><?= chip($a['status'] === 'bestaetigt' ? 'ok' : 'offen', $a['name'] . ($a['status'] === 'offen' ? ' · wartet' : '')) ?> <?php endforeach; ?></span>
                <?= bestaetigung_html('Löschen', $p['email'] . ' wird aus allen Listen gelöscht.', 'Ja, löschen', ['aktion' => 'loeschen', 'person' => (int)$p['id'], 'q' => $q, 'liste' => $filterListe ?: ''],
                    ['ziel' => '/team/newsletter', 'klasse' => 'inline-details', 'titel' => 'Aus allen Listen löschen?']) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <div class="stapel">
        <div class="kasten">
            <h2 class="zwischen">Einladen</h2>
            <p class="meta klein">Die Person bekommt eine Bestätigungsmail und ist erst nach dem Klick eingetragen.</p>
            <form class="form" method="post" action="/team/newsletter"><?= csrf_feld() ?><input type="hidden" name="aktion" value="einladen">
                <?= feld('email', 'E-Mail', ['typ' => 'email', 'id' => 'ein-email', 'attr' => ['maxlength' => 190]]) ?>
                <?= feld('vorname', 'Vorname (freiwillig)', ['id' => 'ein-vorname', 'attr' => ['maxlength' => 80]]) ?>
                <fieldset class="feld nl-listen"><legend>Listen</legend><?php foreach ($zahlen as $lid => $z): ?><label class="haken"><input type="checkbox" name="listen[]" value="<?= $lid ?>"><span><?= e($z['liste']['name']) ?></span></label><?php endforeach; ?></fieldset>
                <?= isset($fehler['einladen']) ? '<p class="feldfehler">' . e($fehler['einladen']) . '</p>' : '' ?>
                <div><button class="knopf zweit" type="submit">Bestätigungsmail schicken</button></div>
            </form>
        </div>
        <div class="kasten" id="liste">
            <h2 class="zwischen"><?= $bearbeiten !== null ? 'Liste bearbeiten' : 'Neue Liste' ?></h2>
            <form class="form" method="post" action="/team/newsletter"><?= csrf_feld() ?><input type="hidden" name="aktion" value="liste"><?php if ($bearbeiten !== null): ?><input type="hidden" name="id" value="<?= (int)$bearbeiten['id'] ?>"><?php endif; ?>
                <?= feld('name', 'Name', ['id' => 'l-name', 'wert' => (string)$lf['name'], 'fehler' => $fehler['name'] ?? null, 'attr' => ['maxlength' => 80]]) ?>
                <div class="feld"><label for="l-beschreibung">Beschreibung (öffentlich)</label><textarea id="l-beschreibung" name="beschreibung" rows="2"<?= editor_attribute('inline') ?>><?= e((string)$lf['beschreibung']) ?></textarea></div>
                <?= feld('absender_name', 'Absendername', ['id' => 'l-absender', 'wert' => (string)$lf['absender_name'], 'attr' => ['maxlength' => 80, 'placeholder' => 'z. B. Allgäu Akut'],
                    'hilfe' => 'Adresse bleibt newsletter@ (bzw. anmeldung@, solange newsletter@ fehlt).']) ?>
                <?= feld('sichtbar', 'Öffentlich wählbar (Seite „Newsletter“)', ['typ' => 'haken', 'wert' => !empty($lf['sichtbar'])]) ?>
                <?= feld('bei_anmeldung', 'Bei der Symposium-Anmeldung anbieten (nur eine Liste)', ['typ' => 'haken', 'wert' => !empty($lf['bei_anmeldung'])]) ?>
                <div class="aktionen"><button class="knopf" type="submit">Speichern</button><?php if ($bearbeiten !== null): ?><a class="knopf zweit" href="/team/newsletter">Abbrechen</a><?php endif; ?></div>
            </form>
        </div>
    </div>
</div>
<?php
team_seite_fuss();
