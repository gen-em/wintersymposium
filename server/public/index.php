<?php
declare(strict_types=1);

/*
 * Einziger Einstieg für alle Seiten (die .htaccess leitet alles, was keine
 * Datei ist, hierher). Reihenfolge der Prüfungen:
 *   1. Konfiguration vorhanden? Installiert? Sonst nur /install.
 *   2. Host erlaubt (E-27), HTTPS erzwingen.
 *   3. Ausstehende Migrationen nachziehen.
 *   4. Wartungsmodus.
 *   5. Seite ausführen.
 */

if (PHP_SAPI === 'cli-server') {
    // Eingebauter PHP-Server (nur Tests/Entwicklung): vorhandene Dateien direkt ausliefern.
    $datei = __DIR__ . parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($datei !== __FILE__ && is_file($datei) && !str_ends_with($datei, '.php')) {
        return false;
    }
}

require dirname(__DIR__) . '/bootstrap.php';

const ROUTEN = [
    '/' => 'start',
    '/install' => 'install',
    '/jobs' => 'jobs',
    '/health' => 'health',
    '/impressum' => 'impressum',
    '/datenschutz' => 'datenschutz',
    '/einloggen' => 'einloggen',
    '/ausloggen' => 'ausloggen',
    '/einrichten' => 'einrichten',
    '/notzugang' => 'notzugang',
    '/team' => 'team',
    '/team/profil' => 'team_profil_alt',
    '/konto/zweitfaktor' => 'konto_zweitfaktor',
    '/passkey' => 'passkey',
    '/team/konten' => 'team_konten',
    '/team/einstellungen' => 'team_einstellungen',
    '/team/programm' => 'team_programm',
    '/team/programm/vorschau' => 'team_programm_vorschau',
    '/team/dozierende' => 'team_dozierende',
    '/team/anmeldungen' => 'team_anmeldungen',
    '/team/daten' => 'team_daten',
    '/team/personen' => 'team_personen',
    '/team/workshops' => 'team_workshops',
    '/team/statistik' => 'team_statistik',
    '/team/export' => 'team_export',
    '/team/texte' => 'team_texte',
    '/team/startseite' => 'team_startseite',
    '/team/texte/bild' => 'team_texte_bild',
    '/team/protokoll' => 'team_protokoll',
    '/team/mailings' => 'team_mailings',
    '/team/mailings/vorschau' => 'team_mailings_vorschau',
    '/team/mailings/bild' => 'team_mailings_bild',
    '/team/mailings/empfaenger' => 'team_mailings_empfaenger',
    '/anmelden' => 'anmelden',
    '/link' => 'link',
    '/deine-anmeldung' => 'deine_anmeldung',
    '/konto' => 'konto',
    '/konto/neu' => 'konto_neu',
    '/konto/link' => 'konto_link',
    '/konto/passwort-vergessen' => 'konto_passwort_vergessen',
    '/newsletter' => 'newsletter',
    '/newsletter/bestaetigen' => 'newsletter_bestaetigen',
    '/newsletter/verwalten' => 'newsletter_verwalten',
    '/newsletter/abmelden' => 'newsletter_abmelden',
    '/team/newsletter' => 'team_newsletter',
    '/team/teilnahme' => 'team_teilnahme',
    '/team/veranstaltungen' => 'team_veranstaltungen',
    '/konto/zertifikat' => 'zertifikat',
    '/team/teilnahme/liste' => 'team_teilnahme_datei',
    '/team/teilnahme/zertifikat' => 'team_teilnahme_datei',
    '/team/teilnahme/unterschrift' => 'team_teilnahme_datei',
    '/deine-anmeldung/zertifikat' => 'zertifikat',
];

$pfad = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$pfad = $pfad === '/' ? '/' : rtrim($pfad, '/');
$seite = ROUTEN[$pfad] ?? (str_starts_with($pfad, '/bild/') ? 'bild' : null);

// 1. Noch nicht eingerichtet: nur der Installer antwortet.
if (konfig_maengel() !== [] || !installiert()) {
    if ($seite !== 'install') {
        kopf_gemeinsam();
        seite_meldung(503, 'Noch nicht eingerichtet', 'Diese Webseite wird gerade eingerichtet.');
    }
    $host = host_angefragt() ?? 'localhost';
    https_tor($host);
    $GLOBALS['ws_host'] = $host;
    require dirname(__DIR__) . '/seiten/install.php';
    exit;
}

// 2. Host und HTTPS
$host = host_tor();
https_tor($host);

// 3. Migrationen nach einer Auslieferung
migration_bei_bedarf();

// 4. Wartung
wartung_tor($pfad);

// 5. Veranstaltung über ihr Kürzel (E-58): /wintersymposium2027 zeigt deren Startseite;
//    abgeschlossene nur zum Ansehen, Entwürfe sind nicht öffentlich.
if ($seite === null && preg_match('#^/([a-z0-9][a-z0-9-]{1,39})$#', $pfad, $m) === 1
    && ($v = va_per_kuerzel($m[1])) !== null && $v['status'] !== 'entwurf') {
    va_kontext((int)$v['id']);
    $seite = 'start';
}

// 6. Seite
if ($seite === null || $seite === 'install') {
    nicht_gefunden();
}
require dirname(__DIR__) . '/seiten/' . $seite . '.php';
