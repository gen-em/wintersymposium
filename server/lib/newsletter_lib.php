<?php
declare(strict_types=1);

/*
 * Newsletter (AP-7, E-52): Listen mit Double-Opt-In, unabhängig von der
 * Symposium-Anmeldung.
 *
 * - Eine Person je Adresse (newsletter_person), Abos je Liste
 *   (newsletter_abo: offen → bestätigt). Erst der Klick auf den Link in der
 *   Bestätigungsmail (und „Bestätigen“ auf der Seite, Mail-Scanner) trägt ein.
 * - Nachweis: Zeitpunkt und Fassung des Einwilligungstexts (Wortlaut in
 *   newsletter_fassung); keine IP-Adresse.
 * - Abmelden: Link in jeder Newsletter-Mail und List-Unsubscribe (One-Click,
 *   RFC 8058); wirkt sofort, ohne Login. Wer sich abmeldet, wird gelöscht –
 *   je Liste bleibt nur eine Zahl für die Statistik.
 * - Unbestätigte Einträge löscht der Job nach 7 Tagen.
 * - Das Team kann einladen (Bestätigungsmail), aber niemanden direkt als
 *   bestätigt eintragen.
 */

const NL_BESTAETIGEN_TAGE = 7;
const NL_VERWALTEN_TAGE = 400;
const NL_EINWILLIGUNG = 'Ich möchte die ausgewählten Newsletter per E-Mail erhalten. Die Einwilligung kann ich jederzeit über den Link in jeder Mail widerrufen. Gespeichert werden meine Adresse, mein Vorname (falls angegeben), die gewählten Listen sowie Zeitpunkt und Fassung dieser Einwilligung.';

/* ---- Listen ---------------------------------------------------------------- */

function nl_listen(bool $nurSichtbar = false): array
{
    return db_zeilen('SELECT * FROM newsletter_liste' . ($nurSichtbar ? ' WHERE sichtbar = 1' : '') . ' ORDER BY pos, id');
}

function nl_liste(int $id): ?array
{
    return db_zeile('SELECT * FROM newsletter_liste WHERE id = ?', [$id]);
}

/** Eingaben einer Liste prüfen: ['werte' => …, 'fehler' => […]]. */
function nl_liste_pruefen(array $e): array
{
    $t = fn(string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($e[$k] ?? '')) ?? ''), 0, $max);
    $w = ['name' => $t('name', 80), 'beschreibung' => $t('beschreibung', 300), 'absender_name' => $t('absender_name', 80),
        'sichtbar' => empty($e['sichtbar']) ? 0 : 1, 'bei_anmeldung' => empty($e['bei_anmeldung']) ? 0 : 1];
    $f = [];
    if ($w['name'] === '') {
        $f['name'] = 'Bitte einen Namen angeben.';
    }
    return ['werte' => $w, 'fehler' => $f];
}

function nl_liste_speichern(?int $id, array $w, ?int $kontoId): int
{
    db_transaktion(function () use (&$id, $w): void {
        // Nur eine Liste wird bei der Symposium-Anmeldung angeboten
        if ($w['bei_anmeldung'] === 1) {
            db_ausfuehren('UPDATE newsletter_liste SET bei_anmeldung = 0');
        }
        if ($id === null) {
            db_ausfuehren(
                'INSERT INTO newsletter_liste (name, beschreibung, absender_name, sichtbar, bei_anmeldung, pos, erstellt_am) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$w['name'], $w['beschreibung'], $w['absender_name'], $w['sichtbar'], $w['bei_anmeldung'], (int)db_wert('SELECT COALESCE(MAX(pos), 0) + 1 FROM newsletter_liste'), db_jetzt()]
            );
            $id = (int)db()->lastInsertId();
        } else {
            db_ausfuehren('UPDATE newsletter_liste SET name = ?, beschreibung = ?, absender_name = ?, sichtbar = ?, bei_anmeldung = ? WHERE id = ?',
                [$w['name'], $w['beschreibung'], $w['absender_name'], $w['sichtbar'], $w['bei_anmeldung'], $id]);
        }
    });
    audit_schreiben('newsletter', 'Liste ' . $id . ' gespeichert (' . $w['name'] . ')', $kontoId);
    return (int)$id;
}

/** Die Liste, die bei der Symposium-Anmeldung angeboten wird, oder null. */
function nl_liste_anmeldung(): ?array
{
    return db_zeile('SELECT * FROM newsletter_liste WHERE bei_anmeldung = 1 ORDER BY id LIMIT 1');
}

/* ---- Personen, Links, Einwilligung --------------------------------------- */

/** Fassung des Einwilligungstexts (Kurz-Hash), Wortlaut einmal gespeichert. */
function nl_fassung(): string
{
    $f = substr(hash('sha256', NL_EINWILLIGUNG), 0, 12);
    db_ausfuehren('INSERT IGNORE INTO newsletter_fassung (fassung, text, erstellt_am) VALUES (?, ?, ?)', [$f, NL_EINWILLIGUNG, db_jetzt()]);
    return $f;
}

function nl_person(int $id): ?array
{
    return db_zeile('SELECT * FROM newsletter_person WHERE id = ?', [$id]);
}

function nl_person_per_email(string $email): ?array
{
    return db_zeile('SELECT * FROM newsletter_person WHERE email = ?', [$email]);
}

function nl_person_anlegen(string $email, string $vorname): int
{
    $p = nl_person_per_email($email);
    if ($p !== null) {
        if ($vorname !== '' && $p['vorname'] === '') {
            db_ausfuehren('UPDATE newsletter_person SET vorname = ? WHERE id = ?', [$vorname, $p['id']]);
        }
        return (int)$p['id'];
    }
    db_ausfuehren('INSERT INTO newsletter_person (email, vorname, erstellt_am) VALUES (?, ?, ?)', [$email, $vorname, db_jetzt()]);
    return (int)db()->lastInsertId();
}

function nl_link_erzeugen(int $personId, string $zweck, ?int $jetzt = null): string
{
    $jetzt ??= time();
    $token = bin2hex(random_bytes(24));
    $tage = $zweck === 'bestaetigen' ? NL_BESTAETIGEN_TAGE : NL_VERWALTEN_TAGE;
    db_ausfuehren('INSERT INTO newsletter_link (person_id, token_hash, zweck, gueltig_bis) VALUES (?, ?, ?, ?)',
        [$personId, hash('sha256', $token), $zweck, gmdate('Y-m-d H:i:s', $jetzt + $tage * 86400)]);
    return $token;
}

/** Gültigen Link finden: ['link' => …, 'person' => …] oder null. */
function nl_link_finden(string $token, ?string $zweck = null): ?array
{
    if (preg_match('/^[0-9a-f]{48}$/', $token) !== 1) {
        return null;
    }
    $l = db_zeile('SELECT * FROM newsletter_link WHERE token_hash = ?', [hash('sha256', $token)]);
    if ($l === null || strtotime($l['gueltig_bis'] . ' UTC') < time() || ($zweck !== null && $l['zweck'] !== $zweck)) {
        return null;
    }
    $p = nl_person((int)$l['person_id']);
    return $p === null ? null : ['link' => $l, 'person' => $p];
}

function nl_verwalten_url(int $personId): string
{
    return url('/newsletter/verwalten?t=' . nl_link_erzeugen($personId, 'verwalten'));
}

/* ---- Eintragen, bestätigen, abmelden ------------------------------------- */

/**
 * Eintragen anfragen (Formular, Häkchen bei der Anmeldung, Einladung durch das
 * Team): offene Abos für noch nicht bestätigte Listen und eine
 * Bestätigungsmail. Ist schon alles bestätigt, kommt ein Link zum Verwalten.
 * Gleiche Antwort für jede Adresse (R-03) – der Aufrufer erfährt nichts.
 */
function nl_anfragen(string $email, string $vorname, array $listeIds, string $quelle, ?int $kontoId = null): void
{
    $email = mail_adresse($email) ?? '';
    $ids = array_values(array_intersect(array_map('intval', $listeIds), array_map('intval', array_column(nl_listen(), 'id'))));
    if ($email === '' || $ids === []) {
        return;
    }
    $vorname = mb_substr(trim(preg_replace('/\s+/u', ' ', $vorname) ?? ''), 0, 80);
    $pid = nl_person_anlegen($email, $vorname);
    $neu = [];
    foreach ($ids as $lid) {
        $st = db_wert('SELECT status FROM newsletter_abo WHERE person_id = ? AND liste_id = ?', [$pid, $lid]);
        if ($st === 'bestaetigt') {
            continue;
        }
        db_ausfuehren(
            'INSERT INTO newsletter_abo (person_id, liste_id, status, quelle, angefragt_am) VALUES (?, ?, \'offen\', ?, ?) ON DUPLICATE KEY UPDATE angefragt_am = VALUES(angefragt_am), quelle = VALUES(quelle)',
            [$pid, $lid, $quelle, db_jetzt()]
        );
        $neu[] = $lid;
    }
    $p = nl_person($pid);
    if ($neu === []) {
        mail_vorlage_einreihen($email, 'newsletter_schon', ['vorname' => $p['vorname'], 'link' => nl_verwalten_url($pid)]);
        return;
    }
    $namen = array_map(fn($l) => $l['name'], array_filter(nl_listen(), fn($l) => in_array((int)$l['id'], $neu, true)));
    mail_vorlage_einreihen($email, 'newsletter_bestaetigen', ['vorname' => $p['vorname'], 'listen' => implode(', ', $namen),
        'link' => url('/newsletter/bestaetigen?t=' . nl_link_erzeugen($pid, 'bestaetigen')), 'einwilligung' => NL_EINWILLIGUNG]);
    audit_schreiben('newsletter', 'Eintrag angefragt (' . $quelle . ') für Person ' . $pid . ': ' . implode(', ', $namen), $kontoId);
}

/** Bestätigungslink einlösen: offene Abos werden bestätigt. Gibt die Person zurück oder null. */
function nl_bestaetigen(string $token): ?array
{
    $f = nl_link_finden($token, 'bestaetigen');
    if ($f === null) {
        return null;
    }
    $pid = (int)$f['person']['id'];
    $n = db_ausfuehren("UPDATE newsletter_abo SET status = 'bestaetigt', bestaetigt_am = ?, fassung = ? WHERE person_id = ? AND status = 'offen'", [db_jetzt(), nl_fassung(), $pid])->rowCount();
    db_ausfuehren("DELETE FROM newsletter_link WHERE person_id = ? AND zweck = 'bestaetigen'", [$pid]);
    if ($n > 0) {
        audit_schreiben('newsletter', 'Person ' . $pid . ': ' . $n . ' Liste(n) bestätigt (Double-Opt-In)');
    }
    return $f['person'];
}

/** Abos einer Person: [liste_id => status]. */
function nl_abos(int $personId): array
{
    $a = [];
    foreach (db_zeilen('SELECT liste_id, status FROM newsletter_abo WHERE person_id = ?', [$personId]) as $z) {
        $a[(int)$z['liste_id']] = $z['status'];
    }
    return $a;
}

/**
 * Abmelden (alle Listen oder die genannten). Bestätigte Abmeldungen zählen in
 * der Statistik; ohne Abos wird die Person gelöscht.
 */
function nl_abmelden(int $personId, ?array $listeIds = null, ?int $kontoId = null): int
{
    $n = 0;
    db_transaktion(function () use ($personId, $listeIds, &$n): void {
        foreach (nl_abos($personId) as $lid => $st) {
            if ($listeIds !== null && !in_array($lid, array_map('intval', $listeIds), true)) {
                continue;
            }
            db_ausfuehren('DELETE FROM newsletter_abo WHERE person_id = ? AND liste_id = ?', [$personId, $lid]);
            if ($st === 'bestaetigt') {
                db_ausfuehren('UPDATE newsletter_liste SET abmeldungen = abmeldungen + 1 WHERE id = ?', [$lid]);
            }
            $n++;
        }
        if ((int)db_wert('SELECT COUNT(*) FROM newsletter_abo WHERE person_id = ?', [$personId]) === 0) {
            db_ausfuehren('DELETE FROM newsletter_person WHERE id = ?', [$personId]);
        }
    });
    if ($n > 0) {
        audit_schreiben('newsletter', 'Person ' . $personId . ': von ' . $n . ' Liste(n) abgemeldet', $kontoId);
    }
    return $n;
}

/**
 * Listen einer Person setzen (Seite „Verwalten“ über den Link aus der Mail,
 * „Mein Konto“): Die Adresse ist durch den Link bzw. das Konto bewiesen, neue
 * Listen gelten deshalb gleich als bestätigt (mit Fassung). Abgewählte
 * Listen werden abgemeldet.
 */
function nl_listen_setzen(int $personId, array $listeIds, string $quelle): void
{
    $ids = array_values(array_intersect(array_map('intval', $listeIds), array_map('intval', array_column(nl_listen(true), 'id'))));
    $abos = nl_abos($personId);
    $weg = array_values(array_diff(array_keys($abos), $ids));
    $fassung = nl_fassung();
    foreach ($ids as $lid) {
        if (($abos[$lid] ?? '') !== 'bestaetigt') {
            db_ausfuehren(
                "INSERT INTO newsletter_abo (person_id, liste_id, status, quelle, angefragt_am, bestaetigt_am, fassung) VALUES (?, ?, 'bestaetigt', ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE status = 'bestaetigt', quelle = VALUES(quelle), bestaetigt_am = VALUES(bestaetigt_am), fassung = VALUES(fassung)",
                [$personId, $lid, $quelle, db_jetzt(), db_jetzt(), $fassung]
            );
            audit_schreiben('newsletter', 'Person ' . $personId . ': Liste ' . $lid . ' eingetragen (' . $quelle . ')');
        }
    }
    if ($weg !== []) {
        nl_abmelden($personId, $weg);
    }
}

/** Häkchen bei der Symposium-Anmeldung: nach deren Bestätigung eine eigene Bestätigungsmail (Admin 2026-10-30). */
function nl_aus_anmeldung(int $anmId): void
{
    $a = anm_laden($anmId);
    $l = nl_liste_anmeldung();
    if ($a === null || (int)$a['newsletter'] !== 1 || $l === null) {
        return;
    }
    nl_anfragen((string)$a['email'], (string)$a['vorname'], [(int)$l['id']], 'anmeldung');
}

/* ---- Team, Versand, Statistik, Aufräumen --------------------------------- */

/** Bestätigte Abonnierende einer oder mehrerer Listen, jede Person einmal. */
function nl_empfaenger(array $listeIds): array
{
    $ids = array_values(array_filter(array_map('intval', $listeIds)));
    if ($ids === []) {
        return [];
    }
    $platz = implode(',', array_fill(0, count($ids), '?'));
    return db_zeilen(
        "SELECT p.id, p.vorname, '' AS nachname, p.email FROM newsletter_person p
         WHERE EXISTS (SELECT 1 FROM newsletter_abo a WHERE a.person_id = p.id AND a.status = 'bestaetigt' AND a.liste_id IN ($platz))
         ORDER BY p.email",
        $ids
    );
}

/** Zahlen je Liste: bestätigt, offen, abgemeldet. */
function nl_zahlen(): array
{
    $z = [];
    foreach (nl_listen() as $l) {
        $z[(int)$l['id']] = ['liste' => $l, 'bestaetigt' => 0, 'offen' => 0, 'abgemeldet' => (int)$l['abmeldungen']];
    }
    foreach (db_zeilen('SELECT liste_id, status, COUNT(*) AS n FROM newsletter_abo GROUP BY liste_id, status') as $r) {
        if (isset($z[(int)$r['liste_id']])) {
            $z[(int)$r['liste_id']][$r['status']] = (int)$r['n'];
        }
    }
    return $z;
}

/** Abonnierende für den Team-Bereich (Suche nach Adresse oder Vorname, Filter Liste). */
function nl_personen(string $q = '', int $listeId = 0): array
{
    $wo = ['1 = 1'];
    $par = [];
    if ($q !== '') {
        $wo[] = '(p.email LIKE ? OR p.vorname LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
        $par[] = $like;
        $par[] = $like;
    }
    if ($listeId > 0) {
        $wo[] = 'EXISTS (SELECT 1 FROM newsletter_abo a WHERE a.person_id = p.id AND a.liste_id = ?)';
        $par[] = $listeId;
    }
    $personen = db_zeilen('SELECT p.* FROM newsletter_person p WHERE ' . implode(' AND ', $wo) . ' ORDER BY p.email LIMIT 500', $par);
    foreach ($personen as &$p) {
        $p['abos'] = db_zeilen('SELECT a.*, l.name FROM newsletter_abo a JOIN newsletter_liste l ON l.id = a.liste_id WHERE a.person_id = ? ORDER BY l.pos', [$p['id']]);
    }
    return $personen;
}

/** Person aus allen Listen löschen (Team). */
function nl_person_loeschen(int $personId, int $kontoId): bool
{
    return nl_abmelden($personId, null, $kontoId) > 0;
}

/** Job: unbestätigte Einträge nach 7 Tagen, abgelaufene Links, Personen ohne Abo. */
function nl_aufraeumen(int $jetzt): array
{
    if (!db_tabelle_da('newsletter_abo')) {
        return [];
    }
    $grenze = gmdate('Y-m-d H:i:s', $jetzt - NL_BESTAETIGEN_TAGE * 86400);
    $offen = db_ausfuehren("DELETE FROM newsletter_abo WHERE status = 'offen' AND angefragt_am < ?", [$grenze])->rowCount();
    $links = db_ausfuehren('DELETE FROM newsletter_link WHERE gueltig_bis < ?', [gmdate('Y-m-d H:i:s', $jetzt)])->rowCount();
    $leer = db_ausfuehren('DELETE p FROM newsletter_person p LEFT JOIN newsletter_abo a ON a.person_id = p.id WHERE a.person_id IS NULL')->rowCount();
    return ['offen' => $offen, 'links' => $links, 'personen' => $leer];
}
