<?php
declare(strict_types=1);

/*
 * Seitenliste für die Tests (AP-17 Unterpunkt 11, V-52): alle HTML-Seiten,
 * die man ohne Token per GET öffnet, nach Zugang – abgeleitet aus ROUTEN
 * (server/routen.php) und TEAM_BEREICHE (BetreiberInnen-Seiten).
 *
 *   php tools/tests/seiten.php   → JSON {oeffentlich, team, betreiber}
 *
 * http_tests.php nutzt test_seiten() direkt, der Browser-Durchlauf das JSON.
 */

/** Pfade ohne eigene HTML-Seite für GET: Token nötig, Datei/JSON, nur POST, Weiterleitung. */
const TEST_SEITEN_OHNE = [
    '/install', '/jobs', '/health', '/ausloggen', '/einrichten', '/passkey', '/link', '/konto/link',
    '/team/profil', '/team/anmeldungen', '/team/startseite/vorschau', '/team/export', '/team/texte/bild',
    '/team/mailings/vorschau', '/team/mailings/bild', '/team/mailings/empfaenger',
    '/newsletter/bestaetigen', '/newsletter/verwalten', '/newsletter/abmelden',
    '/team/teilnahme/liste', '/team/teilnahme/zertifikat', '/team/teilnahme/unterschrift',
    '/konto/zertifikat', '/deine-anmeldung/zertifikat',
];

function test_seiten(): array
{
    if (!defined('ROUTEN')) {
        require dirname(__DIR__, 2) . '/server/routen.php';
    }
    if (!defined('TEAM_BEREICHE')) {
        require_once dirname(__DIR__, 2) . '/server/lib/html_lib.php';
    }
    $betreiber = [];
    foreach (TEAM_BEREICHE as $seiten) {
        foreach ($seiten as $s) {
            if (!empty($s[2])) {
                $betreiber[] = $s[0];
            }
        }
    }
    // Nur BetreiberInnen, ohne Eintrag in der Navigation (Komponentenblatt, Rückmeldung nach AP-19)
    $betreiber[] = '/team/komponenten';
    $erg = ['oeffentlich' => [], 'team' => [], 'betreiber' => []];
    foreach (array_keys(ROUTEN) as $pfad) {
        if (in_array($pfad, TEST_SEITEN_OHNE, true)) {
            continue;
        }
        $art = in_array($pfad, $betreiber, true) ? 'betreiber' : (str_starts_with($pfad, '/team') || $pfad === '/konto/zweitfaktor' ? 'team' : 'oeffentlich');
        $erg[$art][] = $pfad;
    }
    return $erg;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo json_encode(test_seiten(), JSON_UNESCAPED_SLASHES), "\n";
}
