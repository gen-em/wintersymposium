<?php
declare(strict_types=1);

/*
 * Vorlage für die Konfiguration. Kopie als `config.php` im selben Ordner
 * ablegen (nur auf dem Webspace, nie ins Repo) und ausfüllen.
 *
 * Geheime Werte (geheimnis, installations_schluessel, jobs_token,
 * health_token) je mindestens 32 Zeichen, zufällig, z. B. aus dem
 * Passwortmanager. Jede Umgebung (Staging, Produktion) bekommt eigene Werte.
 */

return [
    // 'staging', 'produktion' oder 'entwicklung' (nur lokal: erlaubt HTTP, zeigt Fehler)
    'umgebung' => 'staging',

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'pass' => '',
    ],

    // Schlüssel für Prüfsummen (z. B. IP-Merkmale im Ratenschutz). Nach der
    // Installation nicht mehr ändern.
    'geheimnis' => '',

    // Wird im Installer abgefragt. Nach der Installation bedeutungslos.
    'installations_schluessel' => '',

    // Für den Cron-Aufruf per URL: https://<host>/jobs?token=<jobs_token>
    'jobs_token' => '',

    // Für den Health-Endpunkt: https://<host>/health mit Kopfzeile
    // "X-Health-Token: <health_token>" (oder ?token=…)
    'health_token' => '',

    // Nur setzen, wenn der Webspace HTTPS hinter einem Proxy abschließt und
    // $_SERVER['HTTPS'] deshalb leer ist; dann zählt X-Forwarded-Proto.
    'https_ueber_proxy' => false,

    // Mailversand (AP-4). Postfach anmeldung@ beim Hoster; Passwort nur hier.
    'mail' => [
        'modus' => 'smtp',              // 'smtp'; 'datei' nur für Tests (Mails in var/mails/)
        'host' => '',                   // SMTP-Server des Hosters
        'port' => 465,
        'verschluesselung' => 'ssl',    // 'ssl' (Port 465) oder 'starttls' (Port 587)
        'benutzer' => '',               // meist die volle Adresse des Postfachs
        'passwort' => '',
        'absender' => 'anmeldung@winter-notfallsymposium.de',
        'absender_name' => 'Wintersymposium Notfallmedizin',
        'antwort_an' => '',             // optional, z. B. admin@…
        'tageslimit' => 450,            // Mails je Tag; System-Mails gehen immer raus
    ],
];
