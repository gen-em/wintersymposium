<?php
declare(strict_types=1);

/*
 * Notweg für Team-Konten auf der Kommandozeile (nur mit Zugang zum Server):
 *
 *   php cli/konto.php liste
 *   php cli/konto.php link <email>          neuer Passwort- bzw. Einrichtungslink (72 h)
 *   php cli/konto.php zweitfaktor <email>   zweiten Faktor zurücksetzen
 *
 * Für den Fall, dass der einzige Betreiber Handy und Wiederherstellungscodes
 * verloren hat. Jede Aktion steht im Audit-Protokoll.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

if (konfig_maengel() !== [] || !installiert()) {
    fwrite(STDERR, "Nicht installiert oder Konfiguration unvollständig.\n");
    exit(2);
}
migration_bei_bedarf();

$befehl = $argv[1] ?? '';
$email = $argv[2] ?? '';

if ($befehl === 'liste') {
    foreach (konto_team_liste() as $k) {
        printf("%-30s %-10s %s%s\n", $k['email'], $k['rolle'], (int)$k['aktiv'] === 1 ? 'aktiv' : 'gesperrt', zweitfaktor_da($k) ? '' : ', ohne zweiten Faktor');
    }
    exit(0);
}

if (!in_array($befehl, ['link', 'zweitfaktor'], true) || $email === '') {
    fwrite(STDERR, "Aufruf: php cli/konto.php liste | link <email> | zweitfaktor <email>\n");
    exit(2);
}
$k = konto_per_email($email);
if (!konto_ist_team($k)) {
    fwrite(STDERR, "Kein Team-Konto mit dieser Adresse.\n");
    exit(1);
}
if ($befehl === 'link') {
    $token = konto_link_erzeugen((int)$k['id'], $k['passwort_hash'] === null ? 'einrichten' : 'passwort');
    audit_schreiben('konto', 'Link über die Kommandozeile erzeugt für Konto ' . $k['id']);
    echo konto_link_url($token), "\n";
} else {
    totp_zuruecksetzen((int)$k['id'], 'Kommandozeile');
    echo "Zweiter Faktor zurückgesetzt. Beim nächsten Einloggen wird er neu eingerichtet.\n";
}
exit(0);
