<?php
declare(strict_types=1);

/*
 * Veranstaltungen (E-58): alle Veranstaltungen mit Status; neue anlegen
 * (leer oder als Kopie mit Einstellungen, Texten und Programm), zur aktuellen
 * machen, abschließen (danach nur lesen), löschen.
 * Immer nur eine ist aktuell: Startseite, Anmeldung und „Meine Anmeldung“.
 * Nur Betreiber (AP-12, E-60). Löschen geht bei allen außer der aktuellen,
 * mit Dialog und eingetipptem Kürzel; vorher legt die App eine Sicherung an.
 */

$konto = team_tor(['betreiber']);
$ich = (int)$konto['id'];
$fehler = [];
$eingabe = [];

if (ist_post()) {
    $id = post_zahl('id');
    switch (aktion()) {
        case 'anlegen':
            $eingabe = $_POST;
            $name = trim(preg_replace('/\s+/u', ' ', post_text('name')) ?? '');
            $vorlage = post_zahl('vorlage');
            if ($name === '' || mb_strlen($name) > 80) {
                $fehler['name'] = 'Bitte einen Namen angeben (höchstens 80 Zeichen).';
            } else {
                $r = va_anlegen(post_text('kuerzel'), $name, $vorlage > 0 && va_zeile($vorlage) !== null ? $vorlage : null, $ich);
                if (isset($r['fehler'])) {
                    $fehler['kuerzel'] = $r['fehler'];
                } else {
                    fertig('ok', 'Veranstaltung angelegt (Entwurf). Jetzt Einstellungen, Programm und Texte anpassen.', '/team/einstellungen?va=' . $r['id']);
                }
            }
            break;
        case 'aktuell':
            $f = va_aktuell_machen($id, $ich);
            if ($f === null) {
                $_SESSION['team_va'] = $id;
            }
            fertig_oder_fehler($f, 'Diese Veranstaltung ist jetzt die aktuelle: Startseite, Anmeldung und „Meine Anmeldung“ zeigen sie.', '/team/veranstaltungen');
        case 'abschliessen':
            $f = va_abschliessen($id, $ich);
            fertig_oder_fehler($f, 'Abgeschlossen. Sie ist nur noch zum Ansehen da; Zertifikate bleiben herunterladbar.', '/team/veranstaltungen');
        case 'loeschen':
            $f = va_loeschen($id, $ich, post_text('kuerzel'));
            unset($_SESSION['team_va']);
            fertig_oder_fehler($f, 'Veranstaltung gelöscht. Der Stand davor liegt als Sicherung unter Betrieb.', '/team/veranstaltungen');
    }
}

$alle = va_alle();
team_seite_kopf($konto, 'Veranstaltungen', '/team/veranstaltungen');
?>
<p class="meta klein">Immer eine Veranstaltung ist <strong>aktuell</strong>: Sie steht auf der Startseite, unter „Anmelden“ und „Meine Anmeldung“. Jede ist zusätzlich unter ihrem Kürzel erreichbar (z. B. <span class="mono"><?= e(parse_url(url('/'), PHP_URL_HOST) . '/' . ($alle[0]['kuerzel'] ?? 'kuerzel')) ?></span>). Abgeschlossene sind nur noch zum Ansehen da.</p>
<?= fehler_html(array_values($fehler)) ?>
<div class="tabelle-wrap"><table class="t karten">
    <thead><tr><th>Veranstaltung</th><th>Datum</th><th>Status</th><th>Anmeldungen</th><th>Aktionen</th></tr></thead>
    <tbody>
    <?php $zahlen = va_bestaetigte_zahlen(); foreach ($alle as $v): $n = $zahlen[(int)$v['id']] ?? 0; ?>
        <tr<?= $v['id'] === va_id() ? ' class="gewaehlt"' : '' ?>>
            <td data-label="Veranstaltung"><strong><?= e($v['name']) ?></strong><br><a class="mono klein" href="/<?= e($v['kuerzel']) ?>">/<?= e($v['kuerzel']) ?></a></td>
            <td data-label="Datum" class="mono"><?= $v['datum'] !== '' ? e(tag_text($v['datum'])) : '–' ?></td>
            <td data-label="Status"><?= chip(VA_STATUS_CHIP[$v['status']], VA_STATUS[$v['status']]) ?></td>
            <td data-label="Anmeldungen" class="mono"><?= $n ?></td>
            <td data-label="Aktionen"><span class="zellen-aktionen">
                <a class="knopf zweit klein-knopf" href="/team?va=<?= $v['id'] ?>">Öffnen</a>
                <?php if ($v['status'] === 'entwurf'): ?>
                <?= bestaetigung_html('Aktuell machen', $v['name'] . ' erscheint dann auf der Startseite; die Anmeldung öffnet zum eingestellten Zeitpunkt. Es darf keine andere aktuelle geben.', 'Ja, aktuell machen', ['aktion' => 'aktuell', 'id' => $v['id']],
                    ['ziel' => '/team/veranstaltungen', 'klasse' => 'inline-details', 'titel' => 'Zur aktuellen machen?', 'knopfklasse' => 'knopf klein-knopf', 'ja_klasse' => 'knopf']) ?>
                <?php endif; ?>
                <?php if ($v['status'] !== 'abgeschlossen'): ?>
                <?= bestaetigung_html('Abschließen', 'Danach lässt sich an ' . $v['name'] . ' nichts mehr ändern: keine Anmeldungen, kein Programm, keine Anwesenheit, keine Freischaltungen. Ansehen, Exporte und bereits freigeschaltete Zertifikate bleiben. Erst Anwesenheit eintragen und Zertifikate freischalten.', 'Ja, abschließen', ['aktion' => 'abschliessen', 'id' => $v['id']],
                    ['ziel' => '/team/veranstaltungen', 'klasse' => 'inline-details', 'titel' => 'Veranstaltung abschließen?']) ?>
                <?php endif; ?>
                <?php if ($v['status'] !== 'aktuell'): ?>
                <?= bestaetigung_html('Löschen', $v['name'] . ' wird mit Programm, Einstellungen, Texten, ' . $n . ' Anmeldungen, Wartelisten, Teilnahme und Mailings gelöscht. Die CME-Nachweise bleiben. Vorher legt die App eine Sicherung an.', 'Ja, löschen', ['aktion' => 'loeschen', 'id' => $v['id']],
                    ['ziel' => '/team/veranstaltungen', 'klasse' => 'inline-details', 'titel' => 'Veranstaltung löschen?', 'knopfklasse' => 'klein-link',
                        'inhalt_html' => feld('kuerzel', 'Zur Bestätigung das Kürzel eintippen:', ['id' => 'lo-' . $v['id'], 'label_html' => 'Zur Bestätigung das Kürzel eintippen: <span class="mono">' . e($v['kuerzel']) . '</span>',
                            'pflicht' => true, 'attr' => ['autocomplete' => 'off', 'pattern' => $v['kuerzel']]])]) ?>
                <?php endif; ?>
            </span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<div class="kasten abstand-oben" id="neu">
    <h2 class="zwischen klein-zwischen">Neue Veranstaltung</h2>
    <form class="formular" method="post" action="/team/veranstaltungen"><?= csrf_feld() ?><input type="hidden" name="aktion" value="anlegen">
        <?php /* Fehlertexte stehen oben in der Liste; die Felder sind nur markiert */ ?>
        <?= feld('name', 'Name', ['id' => 'va-name', 'wert' => wert($eingabe, [], 'name'), 'pflicht' => true,
            'attr' => ['maxlength' => 80, 'placeholder' => 'z. B. Allgäuer Winter-Notfallsymposium', 'aria-invalid' => isset($fehler['name']) ? 'true' : null]]) ?>
        <?= feld('kuerzel', 'Kürzel (Adresse)', ['id' => 'va-kuerzel', 'wert' => wert($eingabe, [], 'kuerzel'), 'pflicht' => true, 'attr' => ['maxlength' => 40, 'pattern' => '[a-z0-9][a-z0-9\-]{1,39}', 'placeholder' => 'z. B. wintersymposium2028', 'aria-invalid' => isset($fehler['kuerzel']) ? 'true' : null],
            'hilfe' => 'Kleinbuchstaben ohne Umlaute, Ziffern, Bindestrich. Später unter Einstellungen änderbar.']) ?>
        <?= feld('vorlage', 'Vorlage', ['typ' => 'auswahl', 'id' => 'va-vorlage', 'leer' => 'leer beginnen', 'wert' => (string)(int)($eingabe['vorlage'] ?? ($alle[0]['id'] ?? 0)),
            'optionen' => array_combine(array_column($alle, 'id'), array_map(fn($v) => 'Kopie von ' . $v['name'] . ' (' . $v['kuerzel'] . ')', $alle)),
            'hilfe' => 'Kopiert Einstellungen, Startseiten-Texte und Programm (als Entwurf, noch nicht veröffentlicht); keine Anmeldungen.']) ?>
        <button class="knopf" type="submit">Anlegen (Entwurf)</button>
    </form>
</div>
<?php
team_seite_fuss();
