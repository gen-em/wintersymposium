<?php
declare(strict_types=1);

/*
 * Installer (T-100). Erreichbar nur, solange install.lock fehlt.
 * Verlangt den Installationsschlüssel aus config.php, damit niemand Fremdes
 * eine frisch ausgelieferte, noch leere Installation übernehmen kann.
 */

$host = (string)$GLOBALS['ws_host'];

// Konfiguration unvollständig: sagen, was fehlt (ohne Werte), sonst nichts.
$maengel = konfig_maengel();
if ($maengel !== []) {
    http_response_code(503);
    seite_kopf('Installation', ['nav' => false]);
    echo seiten_kopfzeile('Installation') . '<div class="innen text"><p>Die Konfiguration ist noch nicht vollständig:</p><ul>';
    foreach ($maengel as $m) {
        echo '<li>' . e($m) . '</li>';
    }
    echo '</ul></div>';
    seite_ende();
}

// Tabellen anlegen bzw. nachziehen (idempotent), damit Ratenschutz und
// Prüfung auf vorhandene Konten funktionieren.
migration_anwenden();

// Schon installiert, nur die Sperrdatei fehlt: Sperre wiederherstellen.
if (konto_irgendeins_da()) {
    install_sperre_wiederherstellen();
    audit_schreiben('installation', 'install.lock fehlte und wurde neu angelegt');
    seite_meldung(200, 'Bereits installiert', 'Die Installation ist schon erfolgt. Die Sperre des Installers wurde wiederhergestellt.');
}

sitzung_starten('team');
$fehler = [];
$werte = ['name' => '', 'email' => '', 'weitere_hosts' => ''];

if (ist_post()) {
    $merkmal = rate_merkmal_ip();
    if (!rate_erlaubt('installation', $merkmal)) {
        seite_meldung(429, 'Zu viele Versuche', 'Bitte später noch einmal versuchen.');
    }
    $werte = [
        'name' => post_text('name'),
        'email' => post_text('email'),
        'weitere_hosts' => post_text('weitere_hosts'),
    ];
    $schluessel = post_text('schluessel');
    if (!hash_equals((string)konfig('installations_schluessel'), $schluessel)) {
        rate_misserfolg('installation', $merkmal);
        $fehler[] = 'Der Installationsschlüssel ist falsch.';
    } else {
        $eingabe = $werte + [
            'passwort' => post_text('passwort'),
            'passwort2' => post_text('passwort2'),
        ];
        $fehler = install_pruefen($eingabe);
        if ($fehler === []) {
            install_ausfuehren($host, $eingabe);
            rate_erfolg('installation', $merkmal);
            sitzung_erneuern();
            seite_kopf('Installation abgeschlossen', ['nav' => false]);
            echo seiten_kopfzeile('Installation abgeschlossen') . '<div class="innen text">'
                . '<p>Datenbank angelegt, Haupt-Host <strong>' . e($host) . '</strong> eingetragen, '
                . 'erste BetreiberIn angelegt. Der Installer ist jetzt gesperrt.</p>'
                . '<p>Als Nächstes <a href="/einloggen">einloggen</a> und den zweiten Faktor mit einer Authenticator-App einrichten.</p></div>';
            seite_ende();
        }
    }
}

seite_kopf('Installation', ['nav' => false]);
echo seiten_kopfzeile('Installation') . '<div class="innen">';
echo '<p class="text">Legt die Datenbank und die erste BetreiberIn an. Haupt-Host wird <strong>' . e($host) . '</strong>.</p>';
echo fehler_html($fehler);
?>
<form method="post" action="/install" class="formular">
    <?= csrf_feld() ?>
    <?= feld('schluessel', 'Installationsschlüssel (aus config.php)', ['typ' => 'passwort', 'pflicht' => true, 'ohne_rahmen' => true, 'attr' => ['autocomplete' => 'off']]) ?>

    <?= feld('name', 'Name der ersten BetreiberIn', ['pflicht' => true, 'wert' => $werte['name'], 'ohne_rahmen' => true, 'attr' => ['maxlength' => 120, 'autocomplete' => 'name']]) ?>

    <?= feld('email', 'E-Mail', ['typ' => 'email', 'pflicht' => true, 'wert' => $werte['email'], 'ohne_rahmen' => true, 'attr' => ['maxlength' => 254, 'autocomplete' => 'email']]) ?>

    <?= feld('passwort', 'Passwort (mindestens ' . PASSWORT_MIN_LAENGE . ' Zeichen)', ['typ' => 'passwort', 'pflicht' => true, 'ohne_rahmen' => true, 'attr' => ['minlength' => PASSWORT_MIN_LAENGE, 'autocomplete' => 'new-password']]) ?>

    <?= feld('passwort2', 'Passwort wiederholen', ['typ' => 'passwort', 'pflicht' => true, 'ohne_rahmen' => true, 'attr' => ['minlength' => PASSWORT_MIN_LAENGE, 'autocomplete' => 'new-password']]) ?>

    <?= feld('weitere_hosts', 'Weitere Hostnamen (optional, je Zeile einer)', ['typ' => 'textarea', 'wert' => $werte['weitere_hosts'], 'ohne_rahmen' => true, 'attr' => ['rows' => 3]]) ?>

    <button type="submit">Installieren</button>
</form>
</div>
<?php
seite_fuss();
