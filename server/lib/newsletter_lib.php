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
 * - Listenarten (AP-19, E-71): 'reihe' (gehört zu einer Veranstaltungsreihe,
 *   angelegt und benannt mit ihr), 'dachmarke' (Allgäu Akut, mit Interessen
 *   Klinik/Präklinik je Abo, mindestens eins), 'weitere' (z. B. intern).
 */

const NL_BESTAETIGEN_S = 7 * 86400;
const NL_VERWALTEN_S = 400 * 86400;
const NL_EINWILLIGUNG = 'Ich möchte die ausgewählten Newsletter per E-Mail erhalten. Die Einwilligung kann ich jederzeit über den Link in jeder Mail widerrufen. Gespeichert werden meine Adresse, mein Vorname (falls angegeben), die gewählten Listen, beim Newsletter von Allgäu Akut meine Interessen (Klinik, Präklinik) sowie Zeitpunkt und Fassung dieser Einwilligung.';

/** Listenarten (E-71). */
const NL_ARTEN = ['reihe' => 'Reihe', 'dachmarke' => 'Dachmarke', 'weitere' => 'weitere'];
/** Interessen beim Newsletter der Dachmarke (E-71 (4)). */
const NL_INTERESSEN = ['klinik' => 'Klinik', 'praeklinik' => 'Präklinik'];

/* ---- Listen ---------------------------------------------------------------- */

function nl_listen(bool $nurSichtbar = false): array
{
    // Allgäu Akut (Dachmarke) zuerst: der große Newsletter (Rückmeldung nach AP-19)
    return db_zeilen('SELECT * FROM newsletter_liste' . ($nurSichtbar ? ' WHERE sichtbar = 1' : '') . " ORDER BY art = 'dachmarke' DESC, pos, id");
}

function nl_liste(int $id): ?array
{
    return db_zeile('SELECT * FROM newsletter_liste WHERE id = ?', [$id]);
}

/** Eingaben einer Liste prüfen: ['werte' => …, 'fehler' => […]]. */
function nl_liste_pruefen(array $e): array
{
    $t = fn(string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($e[$k] ?? '')) ?? ''), 0, $max);
    // Beschreibung mit dem Editor (eine Zeile: fett, kursiv, Link; E-54)
    $w = ['name' => $t('name', 80), 'beschreibung' => html_bereinigen(mb_substr((string)($e['beschreibung'] ?? ''), 0, 900), 'inline'), 'absender_name' => $t('absender_name', 80),
        'sichtbar' => empty($e['sichtbar']) ? 0 : 1];
    $f = [];
    if ($w['name'] === '') {
        $f['name'] = 'Bitte einen Namen angeben.';
    }
    if (mb_strlen(editor_text($w['beschreibung'])) > 300 || strlen($w['beschreibung']) > 1000) {
        $f['beschreibung'] = 'Höchstens 300 Zeichen.';
    }
    return ['werte' => $w, 'fehler' => $f];
}

/**
 * Liste anlegen (Art aus $w['art'], Standard 'weitere') oder ändern. Name und
 * Beschreibung einer Reihen-Liste kommen von der Reihe (va_reihe_speichern);
 * hier ändern sich bei ihr nur Absender und Sichtbarkeit.
 */
function nl_liste_speichern(?int $id, array $w, ?int $kontoId): int
{
    db_transaktion(function () use (&$id, $w): void {
        if ($id === null) {
            db_ausfuehren(
                'INSERT INTO newsletter_liste (name, art, beschreibung, absender_name, sichtbar, pos, erstellt_am) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$w['name'], isset(NL_ARTEN[$w['art'] ?? '']) ? $w['art'] : 'weitere', $w['beschreibung'], $w['absender_name'], $w['sichtbar'], db_zahl('SELECT COALESCE(MAX(pos), 0) + 1 FROM newsletter_liste'), db_jetzt()]
            );
            $id = (int)db()->lastInsertId();
        } elseif ((nl_liste($id)['art'] ?? '') === 'reihe') {
            db_ausfuehren('UPDATE newsletter_liste SET absender_name = ?, sichtbar = ? WHERE id = ?', [$w['absender_name'], $w['sichtbar'], $id]);
        } else {
            db_ausfuehren('UPDATE newsletter_liste SET name = ?, beschreibung = ?, absender_name = ?, sichtbar = ? WHERE id = ?',
                [$w['name'], $w['beschreibung'], $w['absender_name'], $w['sichtbar'], $id]);
        }
    });
    audit_schreiben('newsletter', audit_text('liste', (int)$id, 'gespeichert (' . $w['name'] . ')'), $kontoId, ['liste' => (int)$id]);
    return (int)$id;
}

/** Der Newsletter der Dachmarke (Allgäu Akut, mit Interessen) oder null. */
function nl_liste_dachmarke(): ?array
{
    return db_zeile("SELECT * FROM newsletter_liste WHERE art = 'dachmarke' ORDER BY id LIMIT 1");
}

/**
 * Interessen aus Formularwerten (Liste aus 'klinik', 'praeklinik'):
 * Speicherform „klinik,praeklinik“, '' wenn keins.
 */
function nl_interessen(array $werte): string
{
    return implode(',', array_values(array_intersect(array_keys(NL_INTERESSEN), array_map('strval', $werte))));
}

/** Interessen als Text („Klinik, Präklinik“). */
function nl_interessen_text(string $interessen): string
{
    return implode(', ', array_map(fn($i) => NL_INTERESSEN[$i], array_filter(explode(',', $interessen), fn($i) => isset(NL_INTERESSEN[$i]))));
}

/** Interessen einer Berufsgruppe für die Vorauswahl (O-50): Speicherform. */
function nl_interessen_fuer(string $berufsgruppe): string
{
    $i = va_interesse($berufsgruppe);
    return $i === 'beides' ? 'klinik,praeklinik' : $i;
}

/** Gespeicherte Interessen einer Person beim Dachmarken-Newsletter ('' ohne Abo). */
function nl_interessen_von(int $personId): string
{
    $d = nl_liste_dachmarke();
    $a = $d === null ? null : db_zeile('SELECT klinik, praeklinik FROM newsletter_abo WHERE person_id = ? AND liste_id = ?', [$personId, (int)$d['id']]);
    return $a === null ? '' : nl_interessen(array_keys(array_filter(['klinik' => (int)$a['klinik'], 'praeklinik' => (int)$a['praeklinik']])));
}

/**
 * Listenwahl aus einem Formular prüfen: Fehlertext oder null. Wer die Dachmarke
 * wählt, braucht mindestens ein Interesse (E-71 (4)).
 */
function nl_wahl_fehler(array $listeIds, string $interessen): ?string
{
    $d = nl_liste_dachmarke();
    if ($d !== null && in_array((int)$d['id'], array_map('intval', $listeIds), true) && $interessen === '') {
        return 'Bei „' . $d['name'] . '“ bitte Klinik, Präklinik oder beides wählen.';
    }
    return null;
}

/**
 * Auswahl der öffentlichen Listen für die Formulare (Bestellen, Verwalten,
 * Mein Konto): je Liste ein Häkchen, beim Newsletter der Dachmarke darunter
 * die Interessen (E-71 (4), (7)). $abos: [liste_id => status] für „wartet auf
 * Bestätigung“.
 */
function nl_listen_html(array $gewaehlt, string $interessen, array $abos = []): string
{
    $gewaehlt = array_map('intval', $gewaehlt);
    $iw = explode(',', $interessen);
    $h = '';
    foreach (nl_listen(true) as $l) {
        $lid = (int)$l['id'];
        $dm = $l['art'] === 'dachmarke';
        // Allgäu Akut hervorgehoben in einem Akzent-Kasten (Rückmeldung nach AP-19)
        $h .= ($dm ? '<div class="kasten akzent-kasten stapel">' : '') . feld('listen[]', $l['name'], ['typ' => 'haken', 'wert_haken' => $lid, 'wert' => in_array($lid, $gewaehlt, true),
            'label_html' => '<strong>' . e($l['name']) . '</strong>' . ($dm ? ' ' . chip('rolle', 'alle Fortbildungen') : '') . (($abos[$lid] ?? '') === 'offen' ? ' ' . chip('offen', 'wartet auf Bestätigung') : '')
                . ($l['beschreibung'] !== '' ? '<br><span class="meta klein">' . editor_html($l['beschreibung'], 'inline') . '</span>' : '')]);
        if ($dm) {
            $h .= '<fieldset class="feld nl-interessen"><legend>Interessiert an <span class="meta">(mindestens eins)</span></legend><div class="wahl wahl-reihe">';
            foreach (NL_INTERESSEN as $ik => $it) {
                $h .= '<label><input type="checkbox" name="nl_interesse[]" value="' . e($ik) . '"' . (in_array($ik, $iw, true) ? ' checked' : '') . '><span>' . e($it) . '</span></label>';
            }
            $h .= '</div></fieldset></div>';
        }
    }
    return $h;
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
    return link_erzeugen('newsletter', $personId, $zweck, $zweck === 'bestaetigen' ? NL_BESTAETIGEN_S : NL_VERWALTEN_S, [], $jetzt);
}

/** Gültigen Link finden: ['link' => …, 'person' => …] oder null. */
function nl_link_finden(string $token, ?string $zweck = null): ?array
{
    $l = link_finden('newsletter', $token, $zweck);
    if ($l === null) {
        return null;
    }
    $p = nl_person($l['ziel_id']);
    return $p === null ? null : ['link' => $l, 'person' => $p];
}

function nl_verwalten_url(int $personId): string
{
    return link_url('newsletter', nl_link_erzeugen($personId, 'verwalten'), 'verwalten');
}

/* ---- Eintragen, bestätigen, abmelden ------------------------------------- */

/**
 * Eintragen anfragen (Formular, Häkchen bei der Anmeldung, Einladung durch das
 * Team): offene Abos für noch nicht bestätigte Listen und eine
 * Bestätigungsmail. Ist schon alles bestätigt, kommt ein Link zum Verwalten.
 * Gleiche Antwort für jede Adresse (R-03) – der Aufrufer erfährt nichts.
 */
function nl_anfragen(string $email, string $vorname, array $listeIds, string $quelle, ?int $kontoId = null, string $interessen = 'klinik,praeklinik'): void
{
    $dachmarke = (int)(nl_liste_dachmarke()['id'] ?? 0);
    $ik = str_contains($interessen, 'praeklinik') ? 1 : 0;
    $kl = in_array('klinik', explode(',', $interessen), true) ? 1 : 0;
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
        $mitI = $lid === $dachmarke;
        db_ausfuehren(
            'INSERT INTO newsletter_abo (person_id, liste_id, status, quelle, angefragt_am, klinik, praeklinik) VALUES (?, ?, \'offen\', ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE angefragt_am = VALUES(angefragt_am), quelle = VALUES(quelle), klinik = VALUES(klinik), praeklinik = VALUES(praeklinik)',
            [$pid, $lid, $quelle, db_jetzt(), $mitI ? $kl : 0, $mitI ? $ik : 0]
        );
        $neu[] = $lid;
    }
    $p = nl_person($pid);
    if ($neu === []) {
        mail_vorlage_einreihen($email, 'newsletter_schon', ['vorname' => $p['vorname'], 'link' => nl_verwalten_url($pid)]);
        return;
    }
    $namen = array_map(fn($l) => $l['name'] . ((int)$l['id'] === $dachmarke ? ' (' . nl_interessen_text($interessen) . ')' : ''), array_filter(nl_listen(), fn($l) => in_array((int)$l['id'], $neu, true)));
    mail_vorlage_einreihen($email, 'newsletter_bestaetigen', ['vorname' => $p['vorname'], 'listen' => implode(', ', $namen),
        'link' => link_url('newsletter', nl_link_erzeugen($pid, 'bestaetigen'), 'bestaetigen'), 'einwilligung' => NL_EINWILLIGUNG]);
    audit_schreiben('newsletter', audit_text('person', $pid, 'Eintrag angefragt (' . $quelle . '): ' . implode(', ', $namen)), $kontoId, ['person' => $pid]);
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
    link_loeschen('newsletter', $pid, 'bestaetigen');
    if ($n > 0) {
        audit_schreiben('newsletter', audit_text('person', $pid, $n . ' Liste(n) bestätigt (Double-Opt-In)'), null, ['person' => $pid]);
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
        if (!db_gibt_es('SELECT COUNT(*) FROM newsletter_abo WHERE person_id = ?', [$personId])) {
            db_ausfuehren('DELETE FROM newsletter_person WHERE id = ?', [$personId]);
        }
    });
    if ($n > 0) {
        audit_schreiben('newsletter', audit_text('person', $personId, 'von ' . $n . ' Liste(n) abgemeldet'), $kontoId, ['person' => $personId]);
    }
    return $n;
}

/**
 * Listen einer Person setzen (Seite „Verwalten“ über den Link aus der Mail,
 * „Mein Konto“): Die Adresse ist durch den Link bzw. das Konto bewiesen, neue
 * Listen gelten deshalb gleich als bestätigt (mit Fassung). Abgewählte
 * Listen werden abgemeldet. Beide Formulare zeigen nur sichtbare Listen:
 * Abos ausgeblendeter Listen (z. B. per Team-Einladung, E-52) bleiben (Review B-6).
 */
function nl_listen_setzen(int $personId, array $listeIds, string $quelle, string $interessen = 'klinik,praeklinik'): void
{
    $sichtbar = array_map('intval', array_column(nl_listen(true), 'id'));
    $ids = array_values(array_intersect(array_map('intval', $listeIds), $sichtbar));
    $abos = nl_abos($personId);
    $weg = array_values(array_diff(array_intersect(array_map('intval', array_keys($abos)), $sichtbar), $ids));
    $fassung = nl_fassung();
    $dachmarke = (int)(nl_liste_dachmarke()['id'] ?? 0);
    $kl = in_array('klinik', explode(',', $interessen), true) ? 1 : 0;
    $pk = in_array('praeklinik', explode(',', $interessen), true) ? 1 : 0;
    if (in_array($dachmarke, $ids, true) && ($abos[$dachmarke] ?? '') === 'bestaetigt' && nl_interessen_von($personId) !== $interessen) {
        db_ausfuehren('UPDATE newsletter_abo SET klinik = ?, praeklinik = ? WHERE person_id = ? AND liste_id = ?', [$kl, $pk, $personId, $dachmarke]);
        audit_schreiben('newsletter', audit_text('person', $personId, 'Interessen: ' . nl_interessen_text($interessen) . ' (' . $quelle . ')'), null, ['person' => $personId]);
    }
    foreach ($ids as $lid) {
        if (($abos[$lid] ?? '') !== 'bestaetigt') {
            db_ausfuehren(
                "INSERT INTO newsletter_abo (person_id, liste_id, status, quelle, angefragt_am, bestaetigt_am, fassung, klinik, praeklinik) VALUES (?, ?, 'bestaetigt', ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE status = 'bestaetigt', quelle = VALUES(quelle), bestaetigt_am = VALUES(bestaetigt_am), fassung = VALUES(fassung), klinik = VALUES(klinik), praeklinik = VALUES(praeklinik)",
                [$personId, $lid, $quelle, db_jetzt(), db_jetzt(), $fassung, $lid === $dachmarke ? $kl : 0, $lid === $dachmarke ? $pk : 0]
            );
            audit_schreiben('newsletter', audit_text('person', $personId, 'Liste ' . $lid . ' eingetragen (' . $quelle . ')'), null, ['person' => $personId]);
        }
    }
    if ($weg !== []) {
        nl_abmelden($personId, $weg);
    }
}

/**
 * Häkchen bei der Anmeldung (E-71 (6)): Newsletter der Reihe (Spalte
 * newsletter) und Allgäu Akut mit Interessen (nl_interessen). Nach der
 * Bestätigung der Anmeldung eine gemeinsame Bestätigungsmail für beide (O-54).
 */
function nl_aus_anmeldung(int $anmId): void
{
    $a = anm_laden($anmId);
    if ($a === null) {
        return;
    }
    $ids = [];
    $r = (int)$a['newsletter'] === 1 ? va_reihe_von((int)$a['veranstaltung_id']) : null;
    if ($r !== null && $r['liste_id'] !== null) {
        $ids[] = $r['liste_id'];
    }
    $d = nl_liste_dachmarke();
    if ((string)($a['nl_interessen'] ?? '') !== '' && $d !== null) {
        $ids[] = (int)$d['id'];
    }
    if ($ids !== []) {
        nl_anfragen((string)$a['email'], (string)$a['vorname'], $ids, 'anmeldung', null, (string)($a['nl_interessen'] ?? '') ?: 'klinik,praeklinik');
    }
}

/** Was die Anmeldung zum Newsletter anbietet: ['reihe' => Liste oder null, 'dachmarke' => Liste oder null]. */
function nl_angebot_anmeldung(): array
{
    $r = va_reihe_von(va_id());
    return ['reihe' => $r !== null && $r['liste_id'] !== null ? nl_liste($r['liste_id']) : null, 'reihe_kurz' => $r['kurz'] ?? '', 'dachmarke' => nl_liste_dachmarke()];
}

/* ---- Team, Versand, Statistik, Aufräumen --------------------------------- */

/**
 * Bestätigte Abonnierende einer oder mehrerer Listen, jede Person einmal.
 * $ziel gilt für den Newsletter der Dachmarke (E-71 (5)): 'klinik' = mit
 * Interesse Klinik (auch „beides“), 'praeklinik' entsprechend, 'beide' = alle.
 */
function nl_empfaenger(array $listeIds, string $ziel = 'beide'): array
{
    $ids = array_values(array_filter(array_map('intval', $listeIds)));
    if ($ids === []) {
        return [];
    }
    $platz = implode(',', array_fill(0, count($ids), '?'));
    $d = (int)(nl_liste_dachmarke()['id'] ?? 0);
    $filter = in_array($ziel, ['klinik', 'praeklinik'], true) ? ' AND (a.liste_id <> ' . $d . ' OR a.' . $ziel . ' = 1)' : '';
    return db_zeilen(
        "SELECT p.id, p.vorname, '' AS nachname, p.email FROM newsletter_person p
         WHERE EXISTS (SELECT 1 FROM newsletter_abo a WHERE a.person_id = p.id AND a.status = 'bestaetigt' AND a.liste_id IN ($platz)$filter)
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
    $grenze = db_zeit($jetzt - NL_BESTAETIGEN_S);
    $offen = db_ausfuehren("DELETE FROM newsletter_abo WHERE status = 'offen' AND angefragt_am < ?", [$grenze])->rowCount();
    $links = link_aufraeumen($jetzt, 'newsletter');
    $leer = db_ausfuehren('DELETE p FROM newsletter_person p LEFT JOIN newsletter_abo a ON a.person_id = p.id WHERE a.person_id IS NULL')->rowCount();
    return ['offen' => $offen, 'links' => $links, 'personen' => $leer];
}
