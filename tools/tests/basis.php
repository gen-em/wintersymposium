<?php
declare(strict_types=1);

/*
 * Gemeinsame Basis der Tests mit Datenbank (AP-17 Unterpunkt 11, V-50):
 * Jeder Testbereich in tools/tests/lauf/ beginnt mit test_frisch() – leere
 * Datenbank, alle Migrationen, Installation (BetreiberIn Bea, Admin Anna) und
 * der Test-Beispieldatensatz (server/testdaten/beispieldaten.php). Daten, die
 * ein Bereich darüber hinaus braucht, kommen als Funktion in den
 * Beispieldatensatz; selbst angelegt wird nur, wo das Anlegen geprüft wird.
 */

require_once dirname(__DIR__, 2) . '/server/testdaten/beispieldaten.php';

const TEST_PASSWORT = 'test-passwort-1234';
const TEST_BETREIBER = 'betrieb@example.org';
const TEST_ADMIN = 'anna@example.org';

/**
 * Bereichsauswahl über WS_TESTS (Komma-Liste, z. B. WS_TESTS=anmeldung,team);
 * ohne Angabe laufen alle Bereiche.
 */
function test_gewaehlt(string $funktion): bool
{
    $w = trim((string)getenv('WS_TESTS'));
    return $w === '' || in_array(substr($funktion, 5), array_map('trim', explode(',', $w)), true);
}

/** Datenbank leeren (alle Tabellen weg) und den Zustand der Anfrage vergessen. */
function test_db_leeren(): void
{
    db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (db_zeilen('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $z) {
        db()->exec('DROP TABLE `' . $z['t'] . '`');
    }
    db()->exec('SET FOREIGN_KEY_CHECKS = 1');
    @unlink(install_sperrdatei());
    $host = ctx('host');
    ctx_leeren();
    ctx_setzen('host', $host);
    $_SESSION = [];
    $_POST = [];
}

/**
 * Frischer Stand für einen Testbereich. Gibt zurück:
 *   bd        IDs aus beispieldaten_laden() (programm, anmeldungen, mailings …)
 *   betreiber ID der BetreiberIn (TEST_BETREIBER, Passwort TEST_PASSWORT)
 *   admin     ID der Admin (TEST_ADMIN, nur Team-Rolle admin)
 * $opt: beispiel => false ohne Beispieldaten; warteliste => true mit
 *       beispieldaten_warteliste() (Wanda, siehe dort).
 */
function test_frisch(array $opt = []): array
{
    test_db_leeren();
    migration_anwenden();
    install_ausfuehren('127.0.0.1', ['name' => 'Bea Betreiberin', 'email' => TEST_BETREIBER, 'passwort' => TEST_PASSWORT, 'weitere_hosts' => 'localhost']);
    va_zwischenspeicher_leeren();
    $erg = [
        'betreiber' => (int)konto_per_email(TEST_BETREIBER)['id'],
        'admin' => konto_anlegen('Anna Admin', TEST_ADMIN, 'admin', TEST_PASSWORT),
        'bd' => [],
    ];
    if ($opt['beispiel'] ?? true) {
        $erg['bd'] = beispieldaten_laden();
        if (!empty($opt['warteliste'])) {
            $erg['bd']['warteliste'] = beispieldaten_warteliste();
        }
    }
    db_ausfuehren('DELETE FROM mail_ausgang');
    va_zwischenspeicher_leeren();
    ctx_weg('team_konto', 'team_rolle', 'va_id', 'ich', 'anmeldung_konto', 'anm_workshops');
    return $erg;
}

/** ID eines Workshop-Termins aus dem Beispielprogramm (Kürzel und Beginn, z. B. 'WS 01', '19:30'); 0, wenn es ihn nicht gibt. */
function test_termin(string $kuerzel, string $beginn): int
{
    foreach (anm_workshops(true) as $w) {
        foreach ($w['termine'] as $t) {
            if ($w['kuerzel'] === $kuerzel && $t['beginn'] === $beginn) {
                return (int)$t['id'];
            }
        }
    }
    return 0;
}

/** ID eines Workshops aus dem Beispielprogramm nach Kürzel. */
function test_workshop(string $kuerzel): int
{
    foreach (anm_workshops(true) as $w) {
        if ($w['kuerzel'] === $kuerzel) {
            return (int)$w['id'];
        }
    }
    throw new RuntimeException("Workshop $kuerzel fehlt im Beispielprogramm");
}

/** Letzte Mail an eine Adresse aus der Warteschlange oder null. */
function test_mail_an(string $an): ?array
{
    return db_zeile('SELECT * FROM mail_ausgang WHERE an = ? ORDER BY id DESC LIMIT 1', [$an]);
}

/**
 * Text eines PDF (pdftotext -layout, Seiten durch \f getrennt). Fehlt
 * pdftotext, schlägt eine Prüfung fehl, statt die PDF-Prüfungen still
 * auszulassen (Paket poppler-utils; im Prüf-Workflow installiert).
 */
function test_pdf_text(string $pdf): string
{
    static $da = null;
    $da ??= trim((string)shell_exec('command -v pdftotext')) !== '';
    if (!$da) {
        pruefe(false, 'pdftotext vorhanden (für die PDF-Prüfungen, Paket poppler-utils)');
        return '';
    }
    $f = tempnam(sys_get_temp_dir(), 'wsp');
    file_put_contents($f, $pdf);
    $t = (string)shell_exec('pdftotext -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
    @unlink($f);
    return $t;
}

/**
 * Token eines Links aus der letzten Mail an eine Adresse, z. B.
 * test_link_aus_mail('lea@example.org') für /link?t=…, mit 'konto/link' für
 * Konto-Links; '' wenn keiner drin ist.
 */
function test_link_aus_mail(string $an, string $pfad = 'link'): string
{
    $text = (string)(test_mail_an($an)['text'] ?? '');
    return preg_match('~/' . preg_quote($pfad, '~') . '\?t=([A-Za-z0-9_-]{43,64})~', $text, $m) === 1 ? $m[1] : '';
}

/** Zeit messen und als Text zurückgeben (für die Laufzeit im Prüfprotokoll). */
function test_dauer(float $seit): string
{
    return number_format(microtime(true) - $seit, 1, ',', '') . ' s';
}
