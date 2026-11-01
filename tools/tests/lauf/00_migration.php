<?php
declare(strict_types=1);

/* Testbereich: Migration vom alten Stand (vor AP-3) auf den aktuellen (mechanisch aus lauf.php ausgelagert, noch nicht auf test_frisch umgestellt). */

function test_migration(): void
{
    abschnitt('Datenbank leeren');
    db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (db_zeilen('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $z) {
        db()->exec('DROP TABLE `' . $z['t'] . '`');
    }
    db()->exec('SET FOREIGN_KEY_CHECKS = 1');
va_zwischenspeicher_leeren();
    pruefe(migration_ist() === 0, 'leere Datenbank');

    abschnitt('Migrationen');
    // Stand vor AP-3 nachbauen (Schritte 1–3) mit einem Team-Konto, dann den Rest anwenden.
    migration_tabelle_anlegen();
    foreach ([1, 2, 3] as $nr) {
        foreach (migration_katalog()[$nr]['sql'] as $sql) {
            db()->exec($sql);
        }
        db_ausfuehren('INSERT INTO schema_migration (nr, beschreibung, angewendet_am) VALUES (?, ?, ?)', [$nr, 'test', db_jetzt()]);
    }
    db_ausfuehren("INSERT INTO team_konto (id, email, name, passwort_hash, rolle, aktiv, erstellt_am, geaendert_am) VALUES (7, 'alt@example.org', 'Alt', 'h', 'betreiber', 1, ?, ?)", [db_jetzt(), db_jetzt()]);
    // Einstellung der Veranstaltung und Startseiten-Text im alten Stand (vor E-58)
    db_ausfuehren("INSERT INTO einstellung (schluessel, wert, geaendert_am) VALUES ('veranstaltung_ort', 'Alter Ort', ?)", [db_jetzt()]);
    db_ausfuehren("UPDATE inhalt SET text = 'Alter Titel' WHERE schluessel = 'start_titel'");
    $neu = migration_anwenden();
    pruefe(va_zeile(1)['status'] === 'aktuell' && va_id() === 1 && va('veranstaltung_ort') === 'Alter Ort' && db_wert("SELECT COUNT(*) FROM einstellung WHERE schluessel = 'veranstaltung_ort'") == 0
        && inhalt('start_titel') === 'Alter Titel' && inhalt('impressum') === '', 'Migration 15: alles wird Veranstaltung 1 (aktuell), Einstellungen und Startseiten-Texte umgezogen');
    pruefe($neu === array_values(array_filter(array_keys(migration_katalog()), fn($n) => $n > 3)), 'restliche Schritte angewendet');
    $alt = db_zeile('SELECT id, rolle, totp_seit FROM konto WHERE email = ?', ['alt@example.org']);
    pruefe($alt !== null && (int)$alt['id'] === 7 && $alt['rolle'] === 'betreiber' && $alt['totp_seit'] === null, 'Team-Konto mit gleicher id in konto übernommen, ohne zweiten Faktor');
    pruefe(!db_tabelle_da('team_konto'), 'team_konto entfernt');
    pruefe(migration_anwenden() === [], 'zweiter Lauf wendet nichts an');
    pruefe(migration_ist() === migration_soll(), 'Stand = Soll');
    $zeit = (string)db_wert('SELECT @@session.time_zone');
    pruefe($zeit === '+00:00', 'DB-Sitzung in UTC');
}
