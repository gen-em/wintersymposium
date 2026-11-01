<?php
declare(strict_types=1);

/*
 * Teilnahme (AP-10, E-57): Wer einen festen Platz beim Symposium hat, gilt
 * als anwesend (E-42); das Team markiert nur „nicht erschienen“ – beim
 * Symposium und je Workshop-Termin (Spalte platz.nicht_erschienen). Jede
 * Änderung steht im Protokoll (Art „teilnahme“).
 *
 * Zertifikat, Unterschrift und Unterschriftenliste: zertifikat_lib (AP-17).
 */

const TN_SORTIERUNG = ['nachname' => 'Nachname', 'vorname' => 'Vorname', 'berufsgruppe' => 'Berufsgruppe'];

/** Name der Veranstaltung für Zertifikat und Mail. */
function tn_name(): string
{
    return va('veranstaltung_name') !== '' ? va('veranstaltung_name') : 'Wintersymposium';
}

/* ---- Anwesenheit --------------------------------------------------------- */

/**
 * Alle mit festem Platz beim Symposium (bestätigte Anmeldungen), nach Nachname.
 * Je Eintrag: anm (Zeile), platz (Symposium), workshops (feste Plätze mit 'text', 'beginn'),
 * anwesend (bool), aerztin (bool).
 */
function tn_liste(): array
{
    $erste = va_liste('berufsgruppen')[0] ?? '';
    $liste = [];
    foreach (anm_liste() as $a) {
        if ($a['status'] !== 'bestaetigt') {
            continue;
        }
        $sym = null;
        $ws = [];
        foreach ($a['plaetze'] as $p) {
            if ($p['status'] !== 'fest') {
                continue;
            }
            if ((int)$p['termin_id'] === 0) {
                $sym = $p;
            } else {
                $t = anm_termin((int)$p['termin_id']);
                $ws[] = $p + ['text' => anm_termin_text((int)$p['termin_id']), 'beginn' => $t['beginn'] ?? '99'];
            }
        }
        if ($sym === null) {
            continue;
        }
        usort($ws, fn($x, $y) => [$x['beginn'], $x['id']] <=> [$y['beginn'], $y['id']]);
        $liste[] = ['anm' => $a, 'platz' => $sym, 'workshops' => $ws, 'anwesend' => (int)$sym['nicht_erschienen'] === 0, 'aerztin' => $a['berufsgruppe'] === $erste];
    }
    return tn_sortieren($liste, 'nachname');
}

function tn_sortieren(array $liste, string $nach): array
{
    $schluessel = fn(array $e): array => match ($nach) {
        'vorname' => [mb_strtolower($e['anm']['vorname']), mb_strtolower($e['anm']['nachname'])],
        'berufsgruppe' => [mb_strtolower($e['anm']['berufsgruppe']), mb_strtolower($e['anm']['nachname']), mb_strtolower($e['anm']['vorname'])],
        default => [mb_strtolower($e['anm']['nachname']), mb_strtolower($e['anm']['vorname'])],
    };
    usort($liste, function ($x, $y) use ($schluessel): int {
        foreach (array_map(null, $schluessel($x), $schluessel($y)) as [$a, $b]) {
            if (($c = strcoll($a, $b)) !== 0) {
                return $c;
            }
        }
        return (int)$x['anm']['id'] <=> (int)$y['anm']['id'];
    });
    return $liste;
}

/** Anwesend = bestätigt, fester Platz beim Symposium, nicht als „nicht erschienen“ markiert. */
function tn_anwesend(int $anmId): bool
{
    return db_gibt_es(
        "SELECT COUNT(*) FROM anmeldung a JOIN platz p ON p.anmeldung_id = a.id AND p.termin_id = 0 AND p.status = 'fest' AND p.nicht_erschienen = 0 WHERE a.id = ? AND a.status = 'bestaetigt'",
        [$anmId]
    );
}

/** „Nicht erschienen“ setzen oder zurücknehmen (fester Platz). Gibt false zurück, wenn es den Platz nicht gibt. */
function tn_setzen(int $platzId, bool $erschienen, ?int $kontoId): bool
{
    $p = db_zeile("SELECT * FROM platz WHERE id = ? AND status = 'fest'", [$platzId]);
    if ($p === null) {
        return false;
    }
    if ((int)$p['nicht_erschienen'] === ($erschienen ? 0 : 1)) {
        return true;
    }
    db_ausfuehren('UPDATE platz SET nicht_erschienen = ? WHERE id = ?', [$erschienen ? 0 : 1, $platzId]);
    // Ohne Namen, wie die übrigen Einträge (R-02, Review S-11)
    audit_schreiben('teilnahme', audit_text('anmeldung', (int)$p['anmeldung_id'], anm_termin_text((int)$p['termin_id']) . ' – ' . ($erschienen ? 'doch erschienen' : 'nicht erschienen')),
        $kontoId, ['anmeldung' => (int)$p['anmeldung_id']]);
    return true;
}

/** Zahlen für Teilnahme und Statistik. */
function tn_zahlen(): array
{
    $l = tn_liste();
    $anw = array_filter($l, fn($e) => $e['anwesend']);
    return [
        'angemeldet' => count($l),
        'anwesend' => count($anw),
        'nicht_erschienen' => count($l) - count($anw),
        'freigeschaltet' => count(array_filter($anw, fn($e) => $e['anm']['zertifikat_am'] !== null)),
        'offen' => count(array_filter($anw, fn($e) => $e['anm']['zertifikat_am'] === null)),
    ];
}

/* ---- Zertifikat: Freischalten ------------------------------------------- */

/* ---- Zertifikat: Inhalt -------------------------------------------------- */

/* ---- Unterschrift (PNG) -------------------------------------------------- */

/* ---- Zertifikat: PDF (Entwurf C) ----------------------------------------- */

/* ---- Barcode (Code 128, Zeichensatz B) ----------------------------------- */

/* ---- Unterschriftenliste ------------------------------------------------- */

