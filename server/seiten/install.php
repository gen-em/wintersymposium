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
    seite_kopf('Installation');
    echo '<h1>Installation</h1><p>Die Konfiguration ist noch nicht vollständig:</p><ul>';
    foreach ($maengel as $m) {
        echo '<li>' . e($m) . '</li>';
    }
    echo '</ul>';
    seite_fuss();
    exit;
}

// Tabellen anlegen bzw. nachziehen (idempotent), damit Ratenschutz und
// Prüfung auf vorhandene Konten funktionieren.
migration_anwenden();

// Schon installiert, nur die Sperrdatei fehlt: Sperre wiederherstellen.
if ((int)db_wert('SELECT COUNT(*) FROM team_konto') > 0) {
    @file_put_contents(install_sperrdatei(), gmdate('c') . " wiederhergestellt\n");
    audit_schreiben('installation', 'install.lock fehlte und wurde neu angelegt');
    seite_meldung(200, 'Bereits installiert', 'Die Installation ist schon erfolgt. Die Sperre des Installers wurde wiederhergestellt.');
}

sitzung_starten('team');
$fehler = [];
$werte = ['name' => '', 'email' => '', 'weitere_hosts' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $merkmal = rate_merkmal_ip();
    if (!rate_erlaubt('installation', $merkmal)) {
        seite_meldung(429, 'Zu viele Versuche', 'Bitte später noch einmal versuchen.');
    }
    $werte = [
        'name' => (string)($_POST['name'] ?? ''),
        'email' => (string)($_POST['email'] ?? ''),
        'weitere_hosts' => (string)($_POST['weitere_hosts'] ?? ''),
    ];
    $schluessel = (string)($_POST['schluessel'] ?? '');
    if (!hash_equals((string)konfig('installations_schluessel'), $schluessel)) {
        rate_misserfolg('installation', $merkmal);
        $fehler[] = 'Der Installationsschlüssel ist falsch.';
    } else {
        $eingabe = $werte + [
            'passwort' => (string)($_POST['passwort'] ?? ''),
            'passwort2' => (string)($_POST['passwort2'] ?? ''),
        ];
        $fehler = install_pruefen($eingabe);
        if ($fehler === []) {
            install_ausfuehren($host, $eingabe);
            rate_erfolg('installation', $merkmal);
            sitzung_erneuern();
            seite_kopf('Installation abgeschlossen');
            echo '<h1>Installation abgeschlossen</h1>'
                . '<p>Datenbank angelegt, Haupt-Host <strong>' . e($host) . '</strong> eingetragen, '
                . 'erster Betreiber angelegt. Der Installer ist jetzt gesperrt.</p>'
                . '<p>Die Anmeldung für das Team folgt mit dem Team-Bereich.</p>';
            seite_fuss();
            exit;
        }
    }
}

seite_kopf('Installation');
echo '<h1>Installation</h1>';
echo '<p>Legt die Datenbank und den ersten Betreiber an. Haupt-Host wird <strong>' . e($host) . '</strong>.</p>';
if ($fehler !== []) {
    echo '<div class="meldung fehler" role="alert"><ul>';
    foreach ($fehler as $f) {
        echo '<li>' . e($f) . '</li>';
    }
    echo '</ul></div>';
}
?>
<form method="post" action="/install" class="formular">
    <?= csrf_feld() ?>
    <label for="schluessel">Installationsschlüssel (aus config.php)</label>
    <input id="schluessel" name="schluessel" type="password" required autocomplete="off">

    <label for="name">Name des ersten Betreibers</label>
    <input id="name" name="name" type="text" required maxlength="120" value="<?= e($werte['name']) ?>" autocomplete="name">

    <label for="email">E-Mail</label>
    <input id="email" name="email" type="email" required maxlength="254" value="<?= e($werte['email']) ?>" autocomplete="email">

    <label for="passwort">Passwort (mindestens <?= PASSWORT_MIN_LAENGE ?> Zeichen)</label>
    <input id="passwort" name="passwort" type="password" required minlength="<?= PASSWORT_MIN_LAENGE ?>" autocomplete="new-password">

    <label for="passwort2">Passwort wiederholen</label>
    <input id="passwort2" name="passwort2" type="password" required minlength="<?= PASSWORT_MIN_LAENGE ?>" autocomplete="new-password">

    <label for="weitere_hosts">Weitere Hostnamen (optional, je Zeile einer)</label>
    <textarea id="weitere_hosts" name="weitere_hosts" rows="3"><?= e($werte['weitere_hosts']) ?></textarea>

    <button type="submit">Installieren</button>
</form>
<?php
seite_fuss();
