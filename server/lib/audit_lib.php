<?php
declare(strict_types=1);

/*
 * Audit-Protokoll: einfache Tabelle audit(zeit, konto_id, art, text)
 * (Muster aus NAdoku protokoll_lib.php). Keine personenbezogenen Inhalte
 * über das Nötige hinaus in den Text schreiben (R-02).
 */

function audit_schreiben(string $art, string $text, ?int $kontoId = null): void
{
    db_ausfuehren(
        'INSERT INTO audit (zeit, konto_id, art, text) VALUES (?, ?, ?, ?)',
        [db_jetzt(), $kontoId, mb_substr($art, 0, 64), $text]
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
    'installation' => 'Installation', 'migration' => 'Migration', 'notzugang' => 'Notzugang', 'protokoll' => 'Protokoll', 'teilnahme' => 'Teilnahme', 'datenschutz' => 'Datenschutz',
];
const AUDIT_ARTEN_ADMIN = ['anmeldung', 'nachruecken', 'programm', 'einstellungen', 'texte', 'export', 'mailing', 'newsletter', 'teilnahme'];

/** WHERE-Teil (und Parameter) für das, was dieses Konto im Protokoll sieht. */
function audit_sichtbar(array $konto): array
{
    if ($konto['rolle'] === 'betreiber') {
        return ['1 = 1', []];
    }
    $platz = implode(',', array_fill(0, count(AUDIT_ARTEN_ADMIN), '?'));
    return ["a.ausgeblendet_am IS NULL AND (a.art IN ($platz) OR a.konto_id = ?)", array_merge(AUDIT_ARTEN_ADMIN, [(int)$konto['id']])];
}

/** Eintrag aus- oder einblenden (nur Betreiber; selbst protokolliert, nur für Betreiber sichtbar). */
function audit_ausblenden(int $id, bool $aus, int $kontoId): bool
{
    $n = db_ausfuehren(
        'UPDATE audit SET ausgeblendet_von = ?, ausgeblendet_am = ? WHERE id = ? AND art <> ?',
        [$aus ? $kontoId : null, $aus ? db_jetzt() : null, $id, 'protokoll']
    )->rowCount();
    if ($n === 1) {
        audit_schreiben('protokoll', 'Eintrag ' . $id . ($aus ? ' ausgeblendet' : ' wieder eingeblendet'), $kontoId);
    }
    return $n === 1;
}
