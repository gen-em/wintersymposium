<?php
declare(strict_types=1);

/*
 * Veranstaltungs-Einstellungen (AP-3 Schritt 2, R-11): alles, was die
 * Veranstaltung betrifft, ist hier eine Einstellung und im Team-Bereich
 * unter „Einstellungen“ änderbar. Gespeichert in der Tabelle einstellung.
 *
 * Ein Katalog beschreibt jede Einstellung (Typ, Startwert, Grenzen, Text);
 * Formular, Prüfung, Anzeige und Tests lesen von hier. Startwerte gelten,
 * bis jemand speichert. Zeitpunkte werden in Ortszeit eingegeben und in UTC
 * gespeichert; Stichtage gelten „bis einschließlich“ dieses Tages (Ortszeit).
 *
 * Die Einstellungen zur Anmeldung (Zeitraum, Limits, Nachrücken, Stichtage)
 * werden mit AP-4 wirksam; bis dahin werden sie nur gespeichert.
 */

/** Arten von Veranstaltungen (E-58): beeinflussen nur Texte, das Grundmuster ist für alle gleich. */
const VA_ARTEN = ['symposium' => 'Symposium', 'vortrag' => 'Vortragsabend', 'journalclub' => 'Journal Club', 'workshop' => 'Workshop-Abend', 'fall' => 'Fallbesprechung', 'sonstige' => 'Sonstige'];

const VA_GRUPPEN = [
    'veranstaltung' => ['Veranstaltung', 'Erscheint in den Eckdaten auf der Startseite.'],
    'bausteine' => ['Bausteine', 'Was diese Veranstaltung braucht. Workshops gibt es, sobald das Programm welche enthält (E-58).'],
    'cme' => ['Fortbildungspunkte (CME)', 'Die Anzeige erscheint auf der Startseite; Punkte, Kategorie, Kammer und Nummer stehen auf der Teilnahmebescheinigung (AP-10).'],
    'zertifikat' => ['Teilnahmebescheinigung', 'Steht auf dem Zertifikat (AP-10, E-57). Die Unterschrift als Bild lädst du unter „Teilnahme“ hoch.'],
    'anmeldung' => ['Anmeldung', 'Zeitraum und Regeln des Anmeldeformulars.'],
    'nachruecken' => ['Warteliste und Nachrücken', 'Ein frei gewordener Platz wird der ersten Person der Warteliste angeboten (E-19).'],
    'stichtage' => ['Stichtage für Teilnehmende', 'Bis einschließlich dieses Tages können Teilnehmende selbst ändern; danach nur noch das Team (E-31). Leer: bis zur Veranstaltung.'],
    'listen' => ['Auswahllisten', 'Je Zeile ein Eintrag; die Reihenfolge gilt im Formular.'],
];

/**
 * typ: datum (Y-m-d), zeit (H:i), zeitpunkt (Ortszeit-Eingabe, UTC gespeichert),
 *      zahl (min/max), text (max), auswahl (optionen), liste (Zeilen)
 */
const VA_KATALOG = [
    'veranstaltung_kurz' => ['gruppe' => 'veranstaltung', 'typ' => 'text', 'titel' => 'Kurzname', 'start' => 'Wintersymposium', 'max' => 40, 'hilfe' => 'In Mails, Kopfzeilen und Seitentiteln, z. B. „Wintersymposium“ oder „Journal Club“. Leer: „Wintersymposium“.'],
    'veranstaltung_art' => ['gruppe' => 'veranstaltung', 'typ' => 'auswahl', 'titel' => 'Art', 'start' => 'symposium', 'optionen' => VA_ARTEN],
    'veranstaltung_name' => ['gruppe' => 'veranstaltung', 'typ' => 'text', 'titel' => 'Name', 'start' => 'Allgäuer Winter-Notfallsymposium', 'max' => 80, 'hilfe' => 'Steht auf der Teilnahmebescheinigung. Leer: „Wintersymposium“.'],
    'veranstaltung_auflage' => ['gruppe' => 'veranstaltung', 'typ' => 'text', 'titel' => 'Auflage', 'start' => '3. Auflage', 'max' => 30, 'hilfe' => 'z. B. „3. Auflage“; steht mit dem Jahr auf der Teilnahmebescheinigung. Leer: nur das Jahr.'],
    'veranstaltung_datum' => ['gruppe' => 'veranstaltung', 'typ' => 'datum', 'titel' => 'Datum', 'start' => '2027-01-20', 'pflicht' => true],
    'veranstaltung_beginn' => ['gruppe' => 'veranstaltung', 'typ' => 'zeit', 'titel' => 'Beginn', 'start' => '18:00'],
    'veranstaltung_ende' => ['gruppe' => 'veranstaltung', 'typ' => 'zeit', 'titel' => 'Ende', 'start' => '22:00'],
    'veranstaltung_ort' => ['gruppe' => 'veranstaltung', 'typ' => 'text', 'titel' => 'Ort', 'start' => '', 'max' => 200, 'hilfe' => 'Leer: Ort erscheint nicht auf der Startseite.'],
    'veranstaltung_kapazitaet' => ['gruppe' => 'veranstaltung', 'typ' => 'zahl', 'titel' => 'Gesamtkapazität (Personen)', 'start' => '240', 'min' => 0, 'max' => 10000, 'hilfe' => '0 = unbegrenzt. Bestimmt „Freie Plätze“ auf der Startseite.'],
    'cme_anzeige' => ['gruppe' => 'cme', 'typ' => 'text', 'titel' => 'Anzeige in den Eckdaten', 'start' => 'Punkte beantragt', 'max' => 60, 'hilfe' => 'z. B. „Punkte beantragt“ oder „4 CME-Punkte“. Leer: CME erscheint nicht in den Eckdaten.'],
    'cme_punkte' => ['gruppe' => 'cme', 'typ' => 'zahl', 'titel' => 'Punkte', 'start' => '', 'min' => 0, 'max' => 50, 'leer_erlaubt' => true],
    'cme_kategorie' => ['gruppe' => 'cme', 'typ' => 'text', 'titel' => 'Kategorie', 'start' => '', 'max' => 10, 'hilfe' => 'z. B. „A“ – wie von der Kammer anerkannt.'],
    'cme_kammer' => ['gruppe' => 'cme', 'typ' => 'text', 'titel' => 'Ärztekammer', 'start' => '', 'max' => 120],
    'cme_veranstaltungsnummer' => ['gruppe' => 'cme', 'typ' => 'text', 'titel' => 'Veranstaltungsnummer (VNR)', 'start' => '', 'max' => 40],
    'mit_essen' => ['gruppe' => 'bausteine', 'typ' => 'auswahl', 'titel' => 'Essenswahl bei der Anmeldung', 'start' => 'ja', 'optionen' => ['ja' => 'ja', 'nein' => 'nein']],
    'mit_cme' => ['gruppe' => 'bausteine', 'typ' => 'auswahl', 'titel' => 'Fortbildungspunkte (EFN abfragen, CME-Liste)', 'start' => 'ja', 'optionen' => ['ja' => 'ja', 'nein' => 'nein']],
    'mit_zertifikat' => ['gruppe' => 'bausteine', 'typ' => 'auswahl', 'titel' => 'Teilnahmebescheinigung', 'start' => 'ja', 'optionen' => ['ja' => 'ja', 'nein' => 'nein']],
    'zertifikat_leitung' => ['gruppe' => 'zertifikat', 'typ' => 'text', 'titel' => 'Name unter der Unterschrift', 'start' => '', 'max' => 120, 'hilfe' => 'z. B. „Dr. Vorname Nachname“. Leer: nur die Rolle.'],
    'zertifikat_leitung_rolle' => ['gruppe' => 'zertifikat', 'typ' => 'text', 'titel' => 'Rolle unter der Unterschrift', 'start' => 'Wissenschaftliche Leitung', 'max' => 80],
    'anmeldung_start' => ['gruppe' => 'anmeldung', 'typ' => 'zeitpunkt', 'titel' => 'Anmeldung ab', 'start' => '2026-11-24 23:00:00', 'pflicht' => true],
    'anmeldung_ende' => ['gruppe' => 'anmeldung', 'typ' => 'zeitpunkt', 'titel' => 'Anmeldeschluss', 'start' => '', 'hilfe' => 'Leer: bis zum Beginn der Veranstaltung.'],
    'anmeldung_bestaetigung_h' => ['gruppe' => 'anmeldung', 'typ' => 'zahl', 'titel' => 'Bestätigungsfrist (Stunden)', 'start' => '48', 'min' => 1, 'max' => 168, 'hilfe' => 'So lange hält eine neue Anmeldung ihre Plätze, bis die Person den Link in der Mail anklickt. Danach verfällt sie und die Plätze gehen an die Warteliste.'],
    'workshop_limit' => ['gruppe' => 'anmeldung', 'typ' => 'zahl', 'titel' => 'Workshops je Person (höchstens)', 'start' => '0', 'min' => 0, 'max' => 20, 'hilfe' => '0 = kein Limit (E-20).'],
    'workshop_pflicht' => ['gruppe' => 'anmeldung', 'typ' => 'auswahl', 'titel' => 'Workshop-Wahl', 'start' => 'freiwillig', 'optionen' => ['freiwillig' => 'freiwillig', 'pflicht' => 'Pflicht (mindestens ein Workshop)']],
    'nachrueck_frist_h' => ['gruppe' => 'nachruecken', 'typ' => 'zahl', 'titel' => 'Annahmefrist für ein Angebot (Stunden)', 'start' => '48', 'min' => 1, 'max' => 336],
    'nachrueck_grenze_tage' => ['gruppe' => 'nachruecken', 'typ' => 'zahl', 'titel' => 'Automatisch nachrücken bis … Tage vor der Veranstaltung', 'start' => '14', 'min' => 0, 'max' => 90, 'hilfe' => 'Danach vergibt nur noch das Team freie Plätze.'],
    'stichtag_workshop' => ['gruppe' => 'stichtage', 'typ' => 'datum', 'titel' => 'Workshops ändern bis', 'start' => ''],
    'stichtag_essen' => ['gruppe' => 'stichtage', 'typ' => 'datum', 'titel' => 'Essen ändern bis', 'start' => ''],
    'stichtag_storno' => ['gruppe' => 'stichtage', 'typ' => 'datum', 'titel' => 'Selbst stornieren bis', 'start' => ''],
    'berufsgruppen' => ['gruppe' => 'listen', 'typ' => 'liste', 'titel' => 'Berufsgruppen', 'start' => "ÄrztIn\nNotfallsanitäterIn\nRettungssanitäterIn\nPflege/MFA\nStudentIn\nSonstige", 'max_zeilen' => 30, 'hilfe' => 'Die erste Zeile fragt die EFN ab (ÄrztInnen, E-22); „Sonstige“ erlaubt einen Freitext (E-21).'],
];

/** Ein Baustein (mit_essen, mit_cme, mit_zertifikat) ist eingeschaltet; leer gilt der Startwert. */
function va_an(string $schluessel): bool
{
    $w = va($schluessel);
    return ($w !== '' ? $w : VA_KATALOG[$schluessel]['start']) === 'ja';
}

/** Kurzname der Veranstaltung für Mails und Kopfzeilen. */
/**
 * Titel der Startseite aus dem Namen der Veranstaltung (Admin 2026-10-31):
 * ein Wort ganz lila, sonst das erste Wort weiß und der Rest lila.
 */
function va_titel_html(): string
{
    $name = trim(preg_replace('/\s+/u', ' ', va('veranstaltung_name') !== '' ? va('veranstaltung_name') : va_kurz()) ?? '');
    $teile = explode(' ', $name, 2);
    return count($teile) === 1 ? '<em>' . e($name) . '</em>' : e($teile[0]) . ' <em>' . e($teile[1]) . '</em>';
}

function va_kurz(): string
{
    return va('veranstaltung_kurz') !== '' ? va('veranstaltung_kurz') : 'Wintersymposium';
}

/* ---- Mehrere Veranstaltungen (E-58) -------------------------------------- */

/**
 * Jede Anfrage arbeitet im Kontext genau einer Veranstaltung: öffentlich die
 * aktuelle (bzw. die zum Kürzel), im Team-Bereich die gewählte, in Jobs die
 * jeweils bearbeitete. va_id() liefert sie; va_kontext() setzt sie.
 */
const VA_STATUS = ['entwurf' => 'Entwurf', 'aktuell' => 'aktuell', 'abgeschlossen' => 'abgeschlossen'];
const VA_KEINE = 0;
/** Adressen, die kein Kürzel sein dürfen (erste Stelle eines Pfads der Anwendung). */
const VA_KUERZEL_VERBOTEN = ['team', 'konto', 'anmelden', 'link', 'deine-anmeldung', 'newsletter', 'impressum', 'datenschutz', 'einloggen', 'ausloggen',
    'einrichten', 'notzugang', 'install', 'jobs', 'health', 'passkey', 'bild', 'assets', 'favicon', 'apple-touch-icon', 'robots', 'well-known', 'veranstaltung'];

function va_tabellen_da(): bool
{
    if (empty($GLOBALS['ws_va_tab'])) {
        $GLOBALS['ws_va_tab'] = db_tabelle_da('veranstaltung') && db_tabelle_da('va_einstellung') && db_tabelle_da('inhalt')
            && db_gibt_es("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inhalt' AND column_name = 'veranstaltung_id'");
    }
    return $GLOBALS['ws_va_tab'];
}

/** Nach Migrationen oder geleerter Datenbank neu nachsehen. */
function va_zwischenspeicher_leeren(): void
{
    unset($GLOBALS['ws_va_tab'], $GLOBALS['ws_va_aktuell'], $GLOBALS['ws_inhalt_cache'], $GLOBALS['ws_va_rechte']);
}

function va_kontext(int $id): void
{
    $GLOBALS['ws_va_id'] = $id;
}

/**
 * Veranstaltung des Kontexts (ohne gesetzten Kontext: die aktuelle). Gibt es
 * keine aktuelle, ist es 0 (VA_KEINE): kein Rückfall auf Veranstaltung 1,
 * die öffentlichen Seiten zeigen „Derzeit keine Veranstaltung“ (Review B-10).
 * Vor Migration 15 (ohne Tabelle veranstaltung) bleibt es 1.
 */
function va_id(): int
{
    if (isset($GLOBALS['ws_va_id'])) {
        return (int)$GLOBALS['ws_va_id'];
    }
    return va_aktuell_id() ?? (va_tabellen_da() ? VA_KEINE : 1);
}

/** Gibt es im Kontext keine Veranstaltung (keine aktuelle, Review B-10)? */
function va_keine(): bool
{
    return va_tabellen_da() && va_zeile() === null;
}

/** Die aktuelle Veranstaltung (Startseite, Anmeldung) oder null. */
function va_aktuell_id(): ?int
{
    if (!va_tabellen_da()) {
        return null;
    }
    if (!array_key_exists('ws_va_aktuell', $GLOBALS)) {
        $id = db_wert("SELECT id FROM veranstaltung WHERE status = 'aktuell' ORDER BY id LIMIT 1");
        $GLOBALS['ws_va_aktuell'] = $id === null ? null : (int)$id;
    }
    return $GLOBALS['ws_va_aktuell'];
}

/** Zeile einer Veranstaltung (Standard: Kontext) oder null. */
function va_zeile(?int $id = null): ?array
{
    if (!va_tabellen_da()) {
        return null;
    }
    return db_zeile('SELECT * FROM veranstaltung WHERE id = ?', [$id ?? va_id()]);
}

function va_per_kuerzel(string $kuerzel): ?array
{
    return va_tabellen_da() ? db_zeile('SELECT * FROM veranstaltung WHERE kuerzel = ?', [mb_strtolower($kuerzel)]) : null;
}

/** Abgeschlossen = nur noch lesen (Daten bleiben, nichts mehr änderbar). */
function va_abgeschlossen(?int $id = null): bool
{
    return (va_zeile($id)['status'] ?? '') === 'abgeschlossen';
}

/** Ein Wert einer anderen Veranstaltung lesen, ohne den Kontext zu wechseln. */
function va_von(int $id, string $schluessel): string
{
    $alt = $GLOBALS['ws_va_id'] ?? null;
    va_kontext($id);
    try {
        return va($schluessel);
    } finally {
        if ($alt === null) {
            unset($GLOBALS['ws_va_id']);
        } else {
            va_kontext((int)$alt);
        }
    }
}

/** Code im Kontext einer anderen Veranstaltung ausführen (Jobs, Profil, Mailings). */
function va_mit(int $id, callable $fn): mixed
{
    $alt = $GLOBALS['ws_va_id'] ?? null;
    va_kontext($id);
    try {
        return $fn();
    } finally {
        if ($alt === null) {
            unset($GLOBALS['ws_va_id']);
        } else {
            va_kontext((int)$alt);
        }
    }
}

/** Alle Veranstaltungen, neueste zuerst (Datum aus den Einstellungen), mit 'name', 'datum', 'kurz'. */
function va_alle(): array
{
    if (!va_tabellen_da()) {
        return [];
    }
    $liste = [];
    foreach (db_zeilen('SELECT * FROM veranstaltung ORDER BY id') as $v) {
        $v['id'] = (int)$v['id'];
        $v['datum'] = va_von($v['id'], 'veranstaltung_datum');
        $v['name'] = va_von($v['id'], 'veranstaltung_name') ?: va_von($v['id'], 'veranstaltung_kurz') ?: $v['kuerzel'];
        $v['kurz'] = va_mit($v['id'], 'va_kurz');
        $liste[] = $v;
    }
    usort($liste, fn($a, $b) => [$b['datum'], $b['id']] <=> [$a['datum'], $a['id']]);
    return $liste;
}

/** Kürzel prüfen: Fehlertext oder null. */
function va_kuerzel_fehler(string $kuerzel, ?int $ausser = null): ?string
{
    if (preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $kuerzel) !== 1) {
        return 'Kürzel: 2 bis 40 Zeichen, nur Kleinbuchstaben ohne Umlaute, Ziffern und Bindestrich (z. B. wintersymposium2027).';
    }
    if (in_array($kuerzel, VA_KUERZEL_VERBOTEN, true)) {
        return 'Dieses Kürzel ist schon eine Adresse der Seite. Bitte ein anderes wählen.';
    }
    if (db_gibt_es('SELECT COUNT(*) FROM veranstaltung WHERE kuerzel = ? AND id <> ?', [$kuerzel, $ausser ?? 0])) {
        return 'Dieses Kürzel hat schon eine andere Veranstaltung.';
    }
    return null;
}

function va_kuerzel_setzen(int $id, string $kuerzel, ?int $kontoId): ?string
{
    $kuerzel = mb_strtolower(trim($kuerzel));
    $f = va_kuerzel_fehler($kuerzel, $id);
    if ($f !== null) {
        return $f;
    }
    $alt = (string)db_wert('SELECT kuerzel FROM veranstaltung WHERE id = ?', [$id]);
    if ($alt !== $kuerzel) {
        db_ausfuehren('UPDATE veranstaltung SET kuerzel = ? WHERE id = ?', [$kuerzel, $id]);
        audit_schreiben('einstellungen', 'Veranstaltung ' . $id . ': Kürzel ' . $alt . ' → ' . $kuerzel, $kontoId);
    }
    return null;
}

/**
 * Neue Veranstaltung (Entwurf). $vorlage: Einstellungen und Programm einer
 * bestehenden kopieren (Programm als Arbeitsstand, noch nicht veröffentlicht).
 */
function va_anlegen(string $kuerzel, string $name, ?int $vorlage, ?int $kontoId): array
{
    $kuerzel = mb_strtolower(trim($kuerzel));
    if (($f = va_kuerzel_fehler($kuerzel)) !== null) {
        return ['fehler' => $f];
    }
    $id = db_transaktion(function () use ($kuerzel, $name, $vorlage): int {
        db_ausfuehren("INSERT INTO veranstaltung (kuerzel, status, erstellt_am) VALUES (?, 'entwurf', ?)", [$kuerzel, db_jetzt()]);
        $id = (int)db()->lastInsertId();
        if ($vorlage !== null) {
            db_ausfuehren('INSERT INTO va_einstellung (veranstaltung_id, schluessel, wert, geaendert_am) SELECT ?, schluessel, wert, ? FROM va_einstellung WHERE veranstaltung_id = ?', [$id, db_jetzt(), $vorlage]);
            db_ausfuehren('INSERT INTO inhalt (veranstaltung_id, schluessel, text, geaendert_am, geaendert_von) SELECT ?, schluessel, text, ?, NULL FROM inhalt WHERE veranstaltung_id = ?', [$id, db_jetzt(), $vorlage]);
            programm_kopieren($vorlage, $id);
        } else {
            // Startwerte der Texte von der aktuellen Veranstaltung, damit die Startseite nicht leer ist
            db_ausfuehren("INSERT INTO inhalt (veranstaltung_id, schluessel, text, geaendert_am, geaendert_von) SELECT ?, schluessel, '', ?, NULL FROM inhalt WHERE veranstaltung_id = ?", [$id, db_jetzt(), va_aktuell_id() ?? db_zahl('SELECT MIN(id) FROM veranstaltung WHERE id <> ?', [$id])]);
        }
        db_ausfuehren("INSERT INTO va_einstellung (veranstaltung_id, schluessel, wert, geaendert_am) VALUES (?, 'veranstaltung_name', ?, ?)
            ON DUPLICATE KEY UPDATE wert = VALUES(wert)", [$id, $name, db_jetzt()]);
        return $id;
    });
    audit_schreiben('einstellungen', 'Veranstaltung ' . $id . ' angelegt: ' . $kuerzel . ($vorlage !== null ? ' (Kopie von ' . $vorlage . ')' : ''), $kontoId);
    return ['id' => $id];
}

/** Zur aktuellen machen: nur, wenn keine andere aktuell ist (die vorige zuerst abschließen). */
function va_aktuell_machen(int $id, ?int $kontoId): ?string
{
    $v = va_zeile($id);
    if ($v === null || $v['status'] !== 'entwurf') {
        return 'Nur ein Entwurf kann die aktuelle Veranstaltung werden.';
    }
    $akt = va_aktuell_id();
    if ($akt !== null && $akt !== $id) {
        return 'Es gibt schon eine aktuelle Veranstaltung. Schließ sie zuerst ab.';
    }
    db_ausfuehren("UPDATE veranstaltung SET status = 'aktuell' WHERE id = ?", [$id]);
    unset($GLOBALS['ws_va_aktuell']);
    audit_schreiben('einstellungen', 'Veranstaltung ' . $id . ' (' . $v['kuerzel'] . ') ist jetzt die aktuelle', $kontoId);
    return null;
}

/** Abschließen: danach nur noch lesen; offene Wartelisten und Angebote bleiben als Stand stehen. */
function va_abschliessen(int $id, ?int $kontoId): ?string
{
    $v = va_zeile($id);
    if ($v === null || $v['status'] === 'abgeschlossen') {
        return 'Diese Veranstaltung ist schon abgeschlossen.';
    }
    db_ausfuehren("UPDATE veranstaltung SET status = 'abgeschlossen', abgeschlossen_am = ? WHERE id = ?", [db_jetzt(), $id]);
    unset($GLOBALS['ws_va_aktuell']);
    audit_schreiben('einstellungen', 'Veranstaltung ' . $id . ' (' . $v['kuerzel'] . ') abgeschlossen', $kontoId);
    return null;
}

/** Entwurf ohne Anmeldungen löschen (z. B. versehentlich angelegt). */
function va_loeschen(int $id, ?int $kontoId, string $bestaetigung = ''): ?string
{
    // Löschen (AP-12, E-60): nur Betreiber (prüft die Seite), jede Veranstaltung
    // außer der aktuellen; zur Bestätigung das Kürzel eintippen; vorher eine
    // Sicherung. CME-Nachweise bleiben (E-59), das Protokoll auch.
    $v = va_zeile($id);
    if ($v === null) {
        return 'Diese Veranstaltung gibt es nicht.';
    }
    if ($v['status'] === 'aktuell') {
        return 'Die aktuelle Veranstaltung lässt sich nicht löschen. Mach zuerst eine andere zur aktuellen oder schließ sie ab.';
    }
    if (db_zahl('SELECT COUNT(*) FROM veranstaltung') <= 1) {
        return 'Die letzte Veranstaltung lässt sich nicht löschen. Leg zuerst eine neue an.'; // Review B-10
    }
    if (trim($bestaetigung) !== $v['kuerzel']) {
        return 'Zur Bestätigung bitte das Kürzel genau eintippen: ' . $v['kuerzel'];
    }
    $sicherung = sicherung_anlegen('vor dem Löschen von ' . $v['kuerzel'], $kontoId);
    $anm = array_map('intval', array_column(db_zeilen('SELECT id FROM anmeldung WHERE veranstaltung_id = ?', [$id]), 'id'));
    $rm = array_map('intval', array_column(db_zeilen('SELECT id FROM rundmail WHERE veranstaltung_id = ?', [$id]), 'id'));
    db_transaktion(function () use ($id, $anm, $rm): void {
        $liste = fn(array $ids) => implode(',', $ids ?: [0]);
        // offene Mails dieser Veranstaltung nicht mehr verschicken
        db_ausfuehren("DELETE FROM mail_ausgang WHERE status IN ('offen', 'sendet') AND (anmeldung_id IN (" . $liste($anm) . ') OR rundmail_id IN (' . $liste($rm) . '))');
        db_ausfuehren('DELETE FROM anmeldung WHERE veranstaltung_id = ?', [$id]); // Plätze und Links per Fremdschlüssel
        db_ausfuehren('DELETE FROM platz WHERE veranstaltung_id = ?', [$id]);
        db_ausfuehren('DELETE FROM rundmail WHERE veranstaltung_id = ?', [$id]); // Empfängerlisten per Fremdschlüssel
        db_ausfuehren('DELETE FROM programmpunkt WHERE veranstaltung_id = ?', [$id]); // Termine und Dozierenden-Zuordnung per Fremdschlüssel
        db_ausfuehren('DELETE FROM programm_stand WHERE id = ?', [$id]);
        db_ausfuehren('DELETE FROM inhalt WHERE veranstaltung_id = ?', [$id]);
        db_ausfuehren('DELETE FROM konto_veranstaltung WHERE veranstaltung_id = ?', [$id]);
        db_ausfuehren('DELETE FROM veranstaltung WHERE id = ?', [$id]); // Einstellungen per Fremdschlüssel
    });
    $unterschrift = laufzeit_pfad('zertifikat/' . $id) . '/unterschrift.png';
    if (is_file($unterschrift)) {
        @unlink($unterschrift);
    }
    if (($GLOBALS['ws_va_id'] ?? null) === $id) {
        unset($GLOBALS['ws_va_id']);
    }
    audit_schreiben('einstellungen', 'Veranstaltung ' . $id . ' (' . $v['kuerzel'] . ', ' . VA_STATUS[$v['status']] . ') gelöscht: ' . count($anm) . ' Anmeldungen; vorher gesichert: ' . $sicherung, $kontoId, 0);
    return null;
}

/** Gespeicherter Wert oder Startwert (Speicherform: UTC bei Zeitpunkten). */
function va(string $schluessel): string
{
    if (!isset(VA_KATALOG[$schluessel])) {
        throw new InvalidArgumentException('Unbekannte Einstellung: ' . $schluessel);
    }
    return einstellung($schluessel) ?? VA_KATALOG[$schluessel]['start'];
}

function va_zahl(string $schluessel): int
{
    return (int)va($schluessel);
}

/** Liste als Array (ohne leere Zeilen). */
function va_liste(string $schluessel): array
{
    return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', va($schluessel)))), fn($z) => $z !== ''));
}

/** Wert für das Formularfeld (Zeitpunkte in Ortszeit als Y-m-d\TH:i). */
function va_formularwert(string $schluessel, ?string $gespeichert = null): string
{
    $w = $gespeichert ?? va($schluessel);
    if (VA_KATALOG[$schluessel]['typ'] === 'zeitpunkt' && $w !== '') {
        return zeit_lokal($w, 'Y-m-d\TH:i');
    }
    return $w;
}

/**
 * Formulareingaben prüfen und in Speicherform bringen.
 * Gibt ['werte' => [schluessel => wert], 'fehler' => [schluessel => text]] zurück.
 */
function va_pruefen(array $eingabe): array
{
    $werte = [];
    $fehler = [];
    foreach (VA_KATALOG as $k => $d) {
        $roh = str_replace("\r", '', trim((string)($eingabe[$k] ?? '')));
        $titel = $d['titel'];
        if ($roh === '') {
            if (!empty($d['pflicht'])) {
                $fehler[$k] = "„{$titel}“ darf nicht leer sein.";
            }
            $werte[$k] = '';
            continue;
        }
        switch ($d['typ']) {
            case 'datum':
                $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $roh);
                if ($dt === false || $dt->format('Y-m-d') !== $roh) {
                    $fehler[$k] = "„{$titel}“: bitte ein gültiges Datum angeben.";
                }
                break;
            case 'zeit':
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $roh) !== 1) {
                    $fehler[$k] = "„{$titel}“: bitte eine Uhrzeit wie 18:00 angeben.";
                }
                break;
            case 'zeitpunkt':
                $roh = str_replace('T', ' ', $roh);
                $utc = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $roh) === 1 ? lokal_nach_utc($roh) : null;
                if ($utc === null) {
                    $fehler[$k] = "„{$titel}“: bitte Datum und Uhrzeit angeben.";
                } else {
                    $roh = $utc;
                }
                break;
            case 'zahl':
                if (preg_match('/^\d{1,6}$/', $roh) !== 1 || (int)$roh < $d['min'] || (int)$roh > $d['max']) {
                    $fehler[$k] = "„{$titel}“: bitte eine ganze Zahl von {$d['min']} bis {$d['max']} angeben.";
                } else {
                    $roh = (string)(int)$roh;
                }
                break;
            case 'text':
                $roh = preg_replace('/\s+/u', ' ', $roh) ?? '';
                if (mb_strlen($roh) > $d['max']) {
                    $fehler[$k] = "„{$titel}“: höchstens {$d['max']} Zeichen.";
                }
                break;
            case 'auswahl':
                if (!isset($d['optionen'][$roh])) {
                    $fehler[$k] = "„{$titel}“: bitte eine der Möglichkeiten wählen.";
                }
                break;
            case 'liste':
                $zeilen = array_values(array_filter(array_map(fn($z) => trim(preg_replace('/\s+/u', ' ', $z) ?? ''), explode("\n", $roh)), fn($z) => $z !== ''));
                if (count($zeilen) > $d['max_zeilen'] || array_filter($zeilen, fn($z) => mb_strlen($z) > 60) !== []) {
                    $fehler[$k] = "„{$titel}“: höchstens {$d['max_zeilen']} Zeilen mit je bis zu 60 Zeichen.";
                } elseif (count(array_unique(array_map('mb_strtolower', $zeilen))) !== count($zeilen)) {
                    $fehler[$k] = "„{$titel}“: ein Eintrag steht doppelt.";
                } elseif ($zeilen === []) {
                    $fehler[$k] = "„{$titel}“ braucht mindestens einen Eintrag.";
                }
                $roh = implode("\n", $zeilen);
                break;
        }
        $werte[$k] = $roh;
    }
    // Zusammenhänge
    if (!isset($fehler['veranstaltung_beginn'], $fehler['veranstaltung_ende']) && $werte['veranstaltung_beginn'] !== '' && $werte['veranstaltung_ende'] !== ''
        && $werte['veranstaltung_ende'] <= $werte['veranstaltung_beginn']) {
        $fehler['veranstaltung_ende'] = '„Ende“ muss nach „Beginn“ liegen.';
    }
    if (!isset($fehler['anmeldung_start'], $fehler['anmeldung_ende']) && $werte['anmeldung_ende'] !== '' && $werte['anmeldung_ende'] <= $werte['anmeldung_start']) {
        $fehler['anmeldung_ende'] = '„Anmeldeschluss“ muss nach „Anmeldung ab“ liegen.';
    }
    if (!isset($fehler['veranstaltung_datum']) && $werte['veranstaltung_datum'] !== '') {
        $tag = $werte['veranstaltung_datum'];
        if (!isset($fehler['anmeldung_start']) && $werte['anmeldung_start'] !== '' && zeit_lokal($werte['anmeldung_start'], 'Y-m-d') > $tag) {
            $fehler['anmeldung_start'] = '„Anmeldung ab“ liegt nach der Veranstaltung.';
        }
        foreach (['stichtag_workshop', 'stichtag_essen', 'stichtag_storno'] as $k) {
            if (!isset($fehler[$k]) && $werte[$k] !== '' && $werte[$k] > $tag) {
                $fehler[$k] = '„' . VA_KATALOG[$k]['titel'] . '“ liegt nach der Veranstaltung.';
            }
        }
    }
    return ['werte' => $werte, 'fehler' => $fehler];
}

/** Geprüfte Werte speichern; gibt die Schlüssel der geänderten Einstellungen zurück. */
function va_speichern(array $werte, ?int $kontoId): array
{
    $geaendert = [];
    db_transaktion(function () use ($werte, &$geaendert): void {
        foreach (VA_KATALOG as $k => $_) {
            if (!array_key_exists($k, $werte)) {
                continue;
            }
            if (va($k) !== $werte[$k] || einstellung($k) === null) {
                if (va($k) !== $werte[$k]) {
                    $geaendert[] = $k;
                }
                einstellung_setzen($k, $werte[$k]);
            }
        }
    });
    if ($geaendert !== []) {
        audit_schreiben('einstellungen', 'Geändert: ' . implode(', ', $geaendert), $kontoId);
    }
    return $geaendert;
}

/** Eckdaten für die Startseite: Liste von [Titel, Text]. */
function va_eckdaten(): array
{
    $eck = [];
    if (va('veranstaltung_datum') !== '') {
        $eck[] = ['Datum', tag_text(va('veranstaltung_datum'))];
    }
    $von = va('veranstaltung_beginn');
    $bis = va('veranstaltung_ende');
    if ($von !== '') {
        $eck[] = ['Zeit', $von . ($bis !== '' ? '–' . $bis : '')];
    }
    if (va('veranstaltung_ort') !== '') {
        $eck[] = ['Ort', va('veranstaltung_ort')];
    }
    if (va('cme_anzeige') !== '') {
        $eck[] = ['CME', va('cme_anzeige')];
    }
    $kap = va_zahl('veranstaltung_kapazitaet');
    if ($kap > 0 && !va_abgeschlossen()) {
        // Belegt: feste Plätze und offene Angebote, auch unbestätigte Anmeldungen (E-40, E-46)
        $eck[] = ['Freie Plätze', max(0, $kap - anm_belegt(0)) . ' / ' . $kap];
    }
    return $eck;
}

/* ---- Rechte je Veranstaltung (AP-12, E-60) -------------------------------- */

/*
 * Betreiber sehen und bearbeiten alles. Admins sind für alle Veranstaltungen
 * (auch künftige) oder nur für ausgewählte freigeschaltet und sehen nur diese:
 * Seiten, Personen, Exporte, Mailings und Protokoll. Neue Veranstaltungen
 * legen nur Betreiber an.
 */

/** Rechte-Spalten da? (vor Migration 17 gelten alle Team-Konten für alle Veranstaltungen) */
function va_rechte_da(): bool
{
    if (empty($GLOBALS['ws_va_rechte'])) {
        $GLOBALS['ws_va_rechte'] = db_tabelle_da('konto_veranstaltung');
    }
    return $GLOBALS['ws_va_rechte'];
}

/** IDs der Veranstaltungen, die dieses Team-Konto sehen darf; null = alle. */
function va_erlaubte_ids(array $konto): ?array
{
    if (($konto['rolle'] ?? '') === 'betreiber' || !va_rechte_da()) {
        return null;
    }
    $k = db_zeile('SELECT alle_veranstaltungen FROM konto WHERE id = ?', [(int)$konto['id']]);
    if ($k === null || (int)$k['alle_veranstaltungen'] === 1) {
        return null;
    }
    return array_map('intval', array_column(db_zeilen('SELECT veranstaltung_id FROM konto_veranstaltung WHERE konto_id = ?', [(int)$konto['id']]), 'veranstaltung_id'));
}

/** Darf dieses Team-Konto die Veranstaltung sehen und bearbeiten? */
function va_darf(array $konto, int $vaId): bool
{
    $ids = va_erlaubte_ids($konto);
    return $ids === null || in_array($vaId, $ids, true);
}

/** Veranstaltungen, die dieses Team-Konto sieht (neueste zuerst). */
function va_alle_fuer(array $konto): array
{
    $ids = va_erlaubte_ids($konto);
    return $ids === null ? va_alle() : array_values(array_filter(va_alle(), fn($v) => in_array($v['id'], $ids, true)));
}

/** SQL-Bedingung „Veranstaltung erlaubt“ für eine Spalte (mit Parametern). */
function va_sql_erlaubt(array $konto, string $spalte): array
{
    $ids = va_erlaubte_ids($konto);
    if ($ids === null) {
        return ['1 = 1', []];
    }
    if ($ids === []) {
        return ['1 = 0', []];
    }
    return [$spalte . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

/** Rechte eines Admins setzen: alle (auch künftige) oder die Auswahl. */
function va_rechte_setzen(int $kontoId, bool $alle, array $ids, ?int $durch): void
{
    $gueltig = array_column(va_alle(), 'id');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => in_array($i, $gueltig, true))));
    db_transaktion(function () use ($kontoId, $alle, $ids): void {
        db_ausfuehren('UPDATE konto SET alle_veranstaltungen = ? WHERE id = ?', [$alle ? 1 : 0, $kontoId]);
        db_ausfuehren('DELETE FROM konto_veranstaltung WHERE konto_id = ?', [$kontoId]);
        foreach ($alle ? [] : $ids as $id) {
            db_ausfuehren('INSERT INTO konto_veranstaltung (konto_id, veranstaltung_id) VALUES (?, ?)', [$kontoId, $id]);
        }
    });
    $namen = $alle ? 'alle, auch künftige' : (implode(', ', array_map(fn($i) => va_zeile($i)['kuerzel'] ?? (string)$i, $ids)) ?: 'keine');
    audit_schreiben('konto', 'Veranstaltungen von Konto ' . $kontoId . ': ' . $namen, $durch);
}

/** Kurzbeschreibung der Rechte für Listen („alle“, „Kürzel, Kürzel“, „keine“). */
function va_rechte_text(array $konto): string
{
    if ($konto['rolle'] === 'betreiber') {
        return 'alle (BetreiberIn)';
    }
    $ids = va_erlaubte_ids($konto);
    if ($ids === null) {
        return 'alle, auch künftige';
    }
    $namen = [];
    foreach (va_alle() as $v) {
        if (in_array($v['id'], $ids, true)) {
            $namen[] = $v['kurz'] . ' (' . $v['kuerzel'] . ')';
        }
    }
    return $namen === [] ? 'keine' : implode(', ', $namen);
}

/** Für „Mein Konto“: wofür ein Team-Konto zuständig ist, als Liste (AP-12, E-60). */
function va_rechte_liste(array $konto): array
{
    if ($konto['rolle'] === 'betreiber') {
        return ['alle Veranstaltungen', 'Betrieb (Veranstaltungen, Team-Konten, Newsletter, Texte)'];
    }
    $ids = va_erlaubte_ids($konto);
    if ($ids === null) {
        return ['alle Veranstaltungen, auch künftige'];
    }
    $namen = [];
    foreach (va_alle() as $v) {
        if (in_array($v['id'], $ids, true)) {
            $namen[] = $v['name'] . ($v['datum'] !== '' ? ' (' . tag_text($v['datum']) . ')' : '');
        }
    }
    return $namen === [] ? ['noch keine – eine BetreiberIn schaltet dich frei'] : $namen;
}
