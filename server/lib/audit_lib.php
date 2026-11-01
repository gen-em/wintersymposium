<?php
declare(strict_types=1);

/*
 * Audit-Protokoll: Tabelle audit(zeit, konto_id, art, text, veranstaltung_id,
 * objekt_art, objekt_id) (Muster aus NAdoku protokoll_lib.php). Keine Namen
 * und Adressen in den Text, nur Nummern (R-02). Protokolliert wird nur in
 * Libs, nicht in Seiten (Konzept 5a K-d).
 *
 * Objektbezug (AP-17 Unterpunkt 9, V-8): Ein Eintrag hat höchstens einen
 * Hauptbezug, z. B. ['anmeldung' => 12]; weitere Nummern stehen im Text.
 * Texte mit Bezug nach dem Muster „Anmeldung 12: storniert“ (audit_text).
 */

/** Objekt-Arten des Protokolls mit ihrer Bezeichnung im Text. */
const AUDIT_OBJEKTE = [
    'anmeldung' => 'Anmeldung', 'konto' => 'Konto', 'rundmail' => 'Mailing', 'veranstaltung' => 'Veranstaltung',
    'programmpunkt' => 'Programmpunkt', 'termin' => 'Termin', 'dozent' => 'Dozent·in', 'liste' => 'Liste', 'person' => 'Person',
    'host' => 'Host', 'sicherung' => 'Sicherung', 'audit' => 'Eintrag', 'reihe' => 'Reihe',
];

/** Einheitlicher Text mit Bezug: „Anmeldung 12: storniert“. */
function audit_text(string $objekt, int|string $id, string $was): string
{
    return (AUDIT_OBJEKTE[$objekt] ?? $objekt) . ' ' . $id . ': ' . $was;
}

/**
 * Eintrag schreiben. Arten, die eine Veranstaltung betreffen (AUDIT_ARTEN_VA),
 * bekommen die Veranstaltung des Kontexts (AP-12, E-60); $vaId setzt sie
 * ausdrücklich, 0 = keine (z. B. Mailing an Newsletter-Listen).
 * $bezug: ['anmeldung' => 12] – Objekt-Art aus AUDIT_OBJEKTE und Nummer.
 */
function audit_schreiben(string $art, string $text, ?int $durch = null, array $bezug = [], int $vaId = -1): void
{
    $objektArt = $bezug !== [] ? (string)array_key_first($bezug) : null;
    if ($objektArt !== null && !isset(AUDIT_OBJEKTE[$objektArt])) {
        throw new InvalidArgumentException('Unbekannte Objekt-Art im Protokoll: ' . $objektArt);
    }
    $objektId = $objektArt !== null && is_numeric($bezug[$objektArt]) ? (int)$bezug[$objektArt] : null;
    if (function_exists('va_rechte_da') && va_rechte_da()) {
        if ($vaId < 0) {
            $vaId = in_array($art, AUDIT_ARTEN_VA, true) && va_tabellen_da() ? va_id() : 0;
        }
        $mitObjekt = db_spalte_da('audit', 'objekt_art');
        db_ausfuehren(
            'INSERT INTO audit (zeit, konto_id, art, text, veranstaltung_id' . ($mitObjekt ? ', objekt_art, objekt_id' : '') . ') VALUES (?, ?, ?, ?, ?' . ($mitObjekt ? ', ?, ?' : '') . ')',
            array_merge([db_jetzt(), $durch, mb_substr($art, 0, 64), $text, $vaId > 0 ? $vaId : null], $mitObjekt ? [$objektArt, $objektId] : [])
        );
        return;
    }
    db_ausfuehren(
        'INSERT INTO audit (zeit, konto_id, art, text) VALUES (?, ?, ?, ?)',
        [db_jetzt(), $durch, mb_substr($art, 0, 64), $text]
    );
}

/** Die neuesten Einträge, neueste zuerst. */
function audit_liste(int $anzahl = 100): array
{
    $anzahl = max(1, min(1000, $anzahl));
    return db_zeilen("SELECT id, zeit, konto_id, art, text FROM audit ORDER BY id DESC LIMIT $anzahl");
}

/*
 * Sichtbarkeit (Rückmeldung 2026-10-30): Betreiber sehen alles. Admins sehen
 * die Arten, deren Aktionen sie selbst ausführen dürfen, und ihre eigenen
 * Einträge – ausgeblendete Einträge nicht. Betreiber können Einträge
 * ausblenden (nur noch für Betreiber sichtbar) und wieder einblenden;
 * löschen kann niemand.
 */
const AUDIT_ARTEN = [
    'anmeldung' => 'Anmeldungen', 'nachruecken' => 'Nachrücken', 'programm' => 'Programm', 'einstellungen' => 'Einstellungen',
    'texte' => 'Texte', 'export' => 'Export', 'mailing' => 'Mailings', 'newsletter' => 'Newsletter', 'login' => 'Login', 'konto' => 'Konten',
    'zweitfaktor' => 'Zweiter Faktor', 'mail' => 'Mailversand', 'host' => 'Hostnamen', 'sicherung' => 'Sicherung',
    'installation' => 'Installation', 'migration' => 'Migration', 'notzugang' => 'Notzugang', 'protokoll' => 'Protokoll', 'teilnahme' => 'Teilnahme', 'datenschutz' => 'Datenschutz', 'betrieb' => 'Betrieb',
];
/* Admins sehen diese Arten (Newsletter liegt seit AP-12 unter Betrieb, E-60). */
const AUDIT_ARTEN_ADMIN = ['anmeldung', 'nachruecken', 'programm', 'einstellungen', 'texte', 'export', 'mailing', 'teilnahme'];
/* Arten, deren Einträge zu einer Veranstaltung gehören (AP-12, E-60). */
const AUDIT_ARTEN_VA = ['anmeldung', 'nachruecken', 'programm', 'einstellungen', 'texte', 'export', 'mailing', 'teilnahme'];

/**
 * WHERE-Teil (und Parameter) für das, was dieses Konto im Protokoll sieht:
 * Betreiber alles; Admins die Arten ihrer Rolle aus ihren Veranstaltungen –
 * Einträge ohne Veranstaltung (Konten, Newsletter, Logins) nur Betreiber
 * (AP-12, E-60; vorher sahen Admins auch alle eigenen Einträge).
 */
function audit_sichtbar(array $konto): array
{
    if ($konto['rolle'] === 'betreiber') {
        return ['1 = 1', []];
    }
    $platz = implode(',', array_fill(0, count(AUDIT_ARTEN_ADMIN), '?'));
    if (!va_rechte_da()) {
        return ["a.ausgeblendet_am IS NULL AND (a.art IN ($platz) OR a.konto_id = ?)", array_merge(AUDIT_ARTEN_ADMIN, [(int)$konto['id']])];
    }
    [$va, $vaP] = va_sql_erlaubt($konto, 'a.veranstaltung_id');
    return ["a.ausgeblendet_am IS NULL AND a.art IN ($platz) AND a.veranstaltung_id IS NOT NULL AND $va", array_merge(AUDIT_ARTEN_ADMIN, $vaP)];
}

/** Eintrag aus- oder einblenden (nur Betreiber; selbst protokolliert, nur für Betreiber sichtbar). */
function audit_ausblenden(int $id, bool $aus, int $kontoId): bool
{
    $n = db_ausfuehren(
        'UPDATE audit SET ausgeblendet_von = ?, ausgeblendet_am = ? WHERE id = ? AND art <> ?',
        [$aus ? $kontoId : null, $aus ? db_jetzt() : null, $id, 'protokoll']
    )->rowCount();
    if ($n === 1) {
        audit_schreiben('protokoll', audit_text('audit', $id, $aus ? 'ausgeblendet' : 'wieder eingeblendet'), $kontoId, ['audit' => $id]);
    }
    return $n === 1;
}

/** Letzter Protokolleintrag zu einem Objekt (z. B. Anmeldung): ['zeit', 'text', 'name'] oder null (über objekt_art/objekt_id statt LIKE, V-8). */
function audit_letzter(string $objekt, int $id): ?array
{
    return db_zeile('SELECT a.zeit, a.text, k.name FROM audit a LEFT JOIN konto k ON k.id = a.konto_id WHERE a.objekt_art = ? AND a.objekt_id = ? ORDER BY a.id DESC LIMIT 1',
        [$objekt, $id]);
}

/**
 * Protokoll für die Seite: Einträge, die das Konto sehen darf, gefiltert nach
 * Veranstaltung ($vf: '' gewählte, 'alle', 'ohne'), Objekt ([art, id]), Suchtext und Art, eine
 * Seite davon. Gibt ['zahlen' => [art => n], 'gesamt' => n, 'zeilen' => […]] zurück.
 */
function audit_suchen(array $konto, string $vf, string $q, string $art, int $seite, int $proSeite, ?array $objekt = null): array
{
    [$sicht, $sichtPar] = audit_sichtbar($konto);
    $wo = [$sicht];
    $par = $sichtPar;
    if (va_rechte_da() && $vf === '') {
        $wo[] = 'a.veranstaltung_id = ?';
        $par[] = va_id();
    } elseif (va_rechte_da() && $vf === 'ohne') {
        $wo[] = 'a.veranstaltung_id IS NULL';
    }
    if ($objekt !== null) {
        // Einträge zu einem Objekt (z. B. aus Personen: „Protokoll ansehen“)
        $wo[] = 'a.objekt_art = ? AND a.objekt_id = ?';
        array_push($par, $objekt[0], $objekt[1]);
    }
    if ($q !== '') {
        $wo[] = 'a.text LIKE ?';
        $par[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
    }
    // Zahlen je Art für die Filter (mit Suche, ohne Art-Filter)
    $zahlen = [];
    foreach (db_zeilen('SELECT a.art, COUNT(*) AS n FROM audit a WHERE ' . implode(' AND ', $wo) . ' GROUP BY a.art', $par) as $z) {
        $zahlen[$z['art']] = (int)$z['n'];
    }
    if ($art !== '') {
        $wo[] = 'a.art = ?';
        $par[] = $art;
    }
    $sqlWo = ' WHERE ' . implode(' AND ', $wo);
    $gesamt = db_zahl('SELECT COUNT(*) FROM audit a' . $sqlWo, $par);
    $zeilen = db_zeilen('SELECT a.*, k.name FROM audit a LEFT JOIN konto k ON k.id = a.konto_id' . $sqlWo . ' ORDER BY a.id DESC LIMIT ' . $proSeite . ' OFFSET ' . (($seite - 1) * $proSeite), $par);
    return ['zahlen' => $zahlen, 'gesamt' => $gesamt, 'zeilen' => $zeilen];
}
