<?php
declare(strict_types=1);

/*
 * Abschluss und Datenschutz (AP-9, E-24, E-59): Fristen, die ein Job
 * regelmäßig anwendet.
 *
 *  - Anmeldungen ohne Konto werden 14 Monate nach ihrer Veranstaltung geleert
 *    (Name, Adresse, Institution, EFN). Berufsgruppe, Essen und Plätze bleiben
 *    für die Statistik. Mit Konto bleiben sie, solange das Konto besteht
 *    (Löschfrist der Konten: E-54); danach greift dieselbe Regel.
 *  - Vor dem Leeren werden die CME-Nachweise der anwesenden ÄrztInnen (Name,
 *    EFN, Veranstaltung, Datum, VNR, Punkte, Kammer) getrennt abgelegt und
 *    10 Jahre nach der Veranstaltung gelöscht. Nur Betreiber sehen sie.
 *  - Protokoll: Einträge nach 14 Monaten gelöscht, Logins nach 90 Tagen.
 *  - Sicherung: automatisch alle 3 Stunden; behalten werden alle der letzten
 *    3 Tage, bis 7 Tage eine je Tag, bis 14 Tage eine je 3 Tage, bis 28 Tage
 *    eine je Woche. Sicherungen von Hand nach 90 Tagen.
 */

const DS_LEEREN_MONATE = 14;
const DS_CME_JAHRE = 10;
const DS_PROTOKOLL_MONATE = 14;
const DS_LOGIN_TAGE = 90;
const DS_SICHERUNG_ABSTAND_S = 3 * 3600;
const DS_SICHERUNG_HAND_TAGE = 90;
const DS_SICHERUNG_AUTOMATISCH = 'automatisch';

/** Datum (Y-m-d) vor $monate Monaten, Ortszeit. */
function ds_vor_monaten(int $monate, int $jetzt): string
{
    return (new DateTimeImmutable('@' . $jetzt))->setTimezone(new DateTimeZone(ANZEIGE_ZEITZONE))->modify('-' . $monate . ' months')->format('Y-m-d');
}

/* ---- Anmeldungen leeren und CME-Nachweise ------------------------------- */

/**
 * Anmeldungen ohne Konto leeren, deren Veranstaltung mehr als 14 Monate
 * zurückliegt; vorher CME-Nachweise ablegen. Gibt ['geleert' => n, 'cme' => n] zurück.
 */
function ds_anmeldungen_leeren(int $jetzt): array
{
    $erg = ['geleert' => 0, 'cme' => 0];
    $grenze = ds_vor_monaten(DS_LEEREN_MONATE, $jetzt);
    foreach (db_zeilen('SELECT id FROM veranstaltung ORDER BY id') as $v) {
        $vid = (int)$v['id'];
        $datum = va_von($vid, 'veranstaltung_datum');
        if ($datum === '' || $datum >= $grenze) {
            continue;
        }
        foreach (db_zeilen('SELECT * FROM anmeldung WHERE veranstaltung_id = ? AND konto_id IS NULL AND anonymisiert_am IS NULL', [$vid]) as $a) {
            $erg['cme'] += ds_cme_ablegen($a, $jetzt) ? 1 : 0;
            ds_anmeldung_leeren((int)$a['id'], $jetzt);
            $erg['geleert']++;
        }
    }
    if ($erg['geleert'] > 0) {
        audit_schreiben('datenschutz', 'Anmeldungen geleert (14 Monate nach der Veranstaltung): ' . $erg['geleert'] . ', CME-Nachweise abgelegt: ' . $erg['cme']);
    }
    return $erg;
}

/** Eine Anmeldung leeren: personenbezogene Felder weg, Zahlen für die Statistik bleiben. */
function ds_anmeldung_leeren(int $anmId, int $jetzt): void
{
    db_transaktion(function () use ($anmId, $jetzt): void {
        db_ausfuehren(
            "UPDATE anmeldung SET vorname = '', nachname = '', email = ?, institution = '', beruf_sonstige = '', efn = NULL, newsletter = 0, konto_wunsch = 0,
                sitzung_version = sitzung_version + 1, anonymisiert_am = ?, geaendert_am = ? WHERE id = ?",
            ['geleert-' . $anmId . '@anonym.invalid', gmdate('Y-m-d H:i:s', $jetzt), gmdate('Y-m-d H:i:s', $jetzt), $anmId]
        );
        db_ausfuehren('DELETE FROM anmeldung_link WHERE anmeldung_id = ?', [$anmId]);
        db_ausfuehren('UPDATE rundmail_empfaenger SET name = ?, email = ? WHERE anmeldung_id = ?', ['–', '', $anmId]);
    });
}

/** CME-Nachweis einer anwesenden Ärztin / eines anwesenden Arztes ablegen (sonst false). */
function ds_cme_ablegen(array $a, int $jetzt): bool
{
    $vid = (int)$a['veranstaltung_id'];
    return va_mit($vid, function () use ($a, $jetzt, $vid): bool {
        if (!va_an('mit_cme') || $a['berufsgruppe'] !== (va_liste('berufsgruppen')[0] ?? '') || $a['status'] !== 'bestaetigt' || !tn_anwesend((int)$a['id'])) {
            return false;
        }
        db_ausfuehren(
            'INSERT INTO cme_nachweis (veranstaltung_id, anmeldung_id, veranstaltung, datum, nachname, vorname, efn, vnr, punkte, kammer, erstellt_am)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE efn = VALUES(efn)',
            [$vid, (int)$a['id'], tn_name(), va('veranstaltung_datum'), $a['nachname'], $a['vorname'], $a['efn'], va('cme_veranstaltungsnummer'), va('cme_punkte'), va('cme_kammer'), gmdate('Y-m-d H:i:s', $jetzt)]
        );
        return true;
    });
}

/** CME-Nachweise 10 Jahre nach der Veranstaltung löschen. */
function ds_cme_loeschen(int $jetzt): int
{
    $n = db_ausfuehren('DELETE FROM cme_nachweis WHERE datum < ?', [ds_vor_monaten(DS_CME_JAHRE * 12, $jetzt)])->rowCount();
    if ($n > 0) {
        audit_schreiben('datenschutz', 'CME-Nachweise gelöscht (10 Jahre nach der Veranstaltung): ' . $n);
    }
    return $n;
}

/** Abgelegte CME-Nachweise, neueste Veranstaltung zuerst. */
function ds_cme_liste(): array
{
    return db_zeilen('SELECT * FROM cme_nachweis ORDER BY datum DESC, nachname, vorname');
}

/* ---- Protokoll ----------------------------------------------------------- */

function ds_protokoll_kuerzen(int $jetzt): array
{
    $alt = db_ausfuehren('DELETE FROM audit WHERE zeit < ?', [gmdate('Y-m-d H:i:s', (new DateTimeImmutable('@' . $jetzt))->modify('-' . DS_PROTOKOLL_MONATE . ' months')->getTimestamp())])->rowCount();
    $login = db_ausfuehren("DELETE FROM audit WHERE art = 'login' AND zeit < ?", [gmdate('Y-m-d H:i:s', $jetzt - DS_LOGIN_TAGE * 86400)])->rowCount();
    return ['eintraege' => $alt, 'logins' => $login];
}

/* ---- Sicherungen --------------------------------------------------------- */

/** Zeitpunkt (Unix) einer Sicherung aus ihrem Namen. */
function ds_sicherung_zeit(string $name): int
{
    return preg_match('/^sicherung-(\d{8})-(\d{6})-/', $name, $m) === 1 ? (int)strtotime($m[1] . ' ' . $m[2] . ' UTC') : 0;
}

/**
 * Welche Sicherungen weg dürfen (Namen). Automatische: alle der letzten 3 Tage,
 * bis 7 Tage die neueste je Tag, bis 14 Tage die neueste je 3 Tage, bis 28 Tage
 * die neueste je Woche, danach keine. Von Hand angelegte: nach 90 Tagen.
 * $liste: [['name' => …, 'automatisch' => bool], …]
 */
function ds_sicherungen_zu_loeschen(array $liste, int $jetzt): array
{
    $weg = [];
    $behalten = [];
    usort($liste, fn($a, $b) => strcmp($b['name'], $a['name'])); // neueste zuerst
    foreach ($liste as $s) {
        $ts = ds_sicherung_zeit($s['name']);
        $alter = ($jetzt - $ts) / 86400;
        if (!$s['automatisch']) {
            if ($alter > DS_SICHERUNG_HAND_TAGE) {
                $weg[] = $s['name'];
            }
            continue;
        }
        $tag = intdiv($ts, 86400);
        $topf = match (true) {
            $alter <= 3 => null,
            $alter <= 7 => 'tag-' . $tag,
            $alter <= 14 => 'drei-' . intdiv($tag, 3),
            $alter <= 28 => 'woche-' . intdiv($tag, 7),
            default => 'weg',
        };
        if ($topf === null) {
            continue;
        }
        if ($topf === 'weg' || isset($behalten[$topf])) {
            $weg[] = $s['name'];
        } else {
            $behalten[$topf] = true;
        }
    }
    return $weg;
}

/** Job: alle 3 Stunden eine automatische Sicherung, danach nach Plan aufräumen. */
function ds_sicherung_job(int $jetzt): array
{
    $neu = sicherung_anlegen(DS_SICHERUNG_AUTOMATISCH);
    $liste = array_map(fn($s) => ['name' => $s['name'], 'automatisch' => ($s['kopf']['anlass'] ?? '') === DS_SICHERUNG_AUTOMATISCH], sicherung_liste());
    $weg = 0;
    foreach (ds_sicherungen_zu_loeschen($liste, $jetzt) as $name) {
        if ($name !== $neu && sicherung_loeschen($name, null, false)) {
            $weg++;
        }
    }
    return ['neu' => $neu, 'geloescht' => $weg];
}
