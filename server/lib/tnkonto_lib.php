<?php
declare(strict_types=1);

/*
 * Konten für Teilnehmende (AP-4b, E-30, E-48). Freiwillig: Anmeldung und
 * Änderungen gehen weiter ohne Konto über Einmal-Links.
 *
 *   - Konto anlegen: beim Anmelden (Häkchen, Passwort nach der Bestätigung),
 *     nachträglich aus „Deine Anmeldung“ oder ohne Anmeldung (/konto/neu,
 *     Bestätigung per Mail). Erst eine bestätigte Adresse macht das Konto gültig.
 *   - Einloggen über denselben Login wie das Team (/einloggen oder Kasten auf
 *     „Deine Anmeldung“); Teilnehmende ohne zweiten Faktor (E-11).
 *   - Sitzung 'link' (wie nach einem Einmal-Link): $_SESSION['tk'] = Konto,
 *     $_SESSION['anm'] = verknüpfte Anmeldung.
 *   - Gibt es zu einer bestätigten Konto-Adresse eine Anmeldung, werden beide
 *     verknüpft (Admin 2026-10-29).
 *   - Konto löschen: wahlweise mit oder ohne Anmeldung (Admin 2026-10-29).
 * Links (Bestätigen, Passwort, neue Adresse) nutzen konto_link wie beim Team.
 */

/** Mindestlänge für Passwörter Teilnehmender (Admin 2026-10-29; Team bleibt bei 12). */
const PASSWORT_MIN_TN = 10;
/**
 * Löschfrist (Admin 2026-10-30, E-54): Konten Teilnehmender ohne Login seit
 * 14 Monaten werden gelöscht, 30 Tage vorher kommt eine Erinnerung; jeder
 * Login setzt die Frist neu. Team-Konten sind ausgenommen. Die Anmeldung
 * bleibt (wie „Konto löschen, Anmeldung behalten“, E-48).
 */
const KONTO_LOESCHEN_MONATE = 14;
const KONTO_ERINNERN_TAGE = 30;
/** Links für Teilnehmenden-Konten gelten 48 h (wie die Einmal-Links, E-46). */
const TK_LINK_GUELTIG_S = 48 * 3600;

/**
 * Eingeloggtes Konto dieser Sitzung oder null (ein Konto für alles, E-54):
 * Teilnehmende mit bestätigter Adresse; Team-Konten nur nach vollständigem
 * Login mit zweitem Faktor.
 */
function tk_aktuell(): ?array
{
    $s = $_SESSION['tk'] ?? null;
    if (!is_array($s) || !isset($s['id'], $s['version'])) {
        return null;
    }
    $k = konto_laden((int)$s['id']);
    $ok = $k !== null && (int)$k['aktiv'] === 1 && (int)$k['sitzung_version'] === (int)$s['version']
        && (konto_ist_team($k) ? (int)(anmeldung_konto()['id'] ?? 0) === (int)$k['id'] : ($k['rolle'] === 'teilnehmend' && $k['email_bestaetigt_am'] !== null));
    if (!$ok) {
        unset($_SESSION['tk']);
        return null;
    }
    return $k;
}

/** Konto in der Sitzung merken (nach Login, Bestätigung oder neuem Passwort). */
function tk_einloggen(array $k): void
{
    sitzung_erneuern();
    $k = konto_laden((int)$k['id']) ?? $k;
    $_SESSION['tk'] = ['id' => (int)$k['id'], 'version' => (int)$k['sitzung_version']];
    // Login setzt die Löschfrist neu (14 Monate ohne Login, E-54)
    db_ausfuehren('UPDATE konto SET letzte_anmeldung = ?, loesch_erinnert_am = NULL WHERE id = ?', [db_jetzt(), (int)$k['id']]);
    $a = tk_anmeldung((int)$k['id']);
    if ($a !== null) {
        anm_merken($a, false);
    }
}

/** Ausloggen: beendet die ganze Sitzung (Team, Konto und Link, E-54) und startet eine leere. */
function tk_ausloggen(): void
{
    $k = tk_aktuell();
    if ($k !== null && konto_ist_team($k)) {
        audit_schreiben('login', 'Ausgeloggt', (int)$k['id']);
    }
    sitzung_beenden();
    sitzung_starten('link');
}

/** Verknüpfte Anmeldung eines Kontos oder null. */
function tk_anmeldung(int $kontoId): ?array
{
    return db_zeile('SELECT * FROM anmeldung WHERE konto_id = ? AND veranstaltung_id = ?', [$kontoId, va_id()]);
}

/**
 * Alle Veranstaltungen, zu denen das Konto angemeldet war oder ist (E-58),
 * neueste zuerst: id (Anmeldung), name, datum, kuerzel, status, stand (Text),
 * teilgenommen, zertifikat (herunterladbar).
 */
function tk_veranstaltungen(int $kontoId): array
{
    $liste = [];
    foreach (db_zeilen("SELECT a.*, v.kuerzel, v.status AS va_status FROM anmeldung a JOIN veranstaltung v ON v.id = a.veranstaltung_id WHERE a.konto_id = ? AND a.status = 'bestaetigt'", [$kontoId]) as $a) {
        $va = (int)$a['veranstaltung_id'];
        $sym = db_wert('SELECT status FROM platz WHERE anmeldung_id = ? AND termin_id = 0', [$a['id']]);
        $vorbei = $a['va_status'] === 'abgeschlossen' || va_von($va, 'veranstaltung_datum') < anm_heute();
        $da = tn_anwesend((int)$a['id']);
        $stand = $sym !== 'fest' ? 'Warteliste' : (!$vorbei ? 'angemeldet' : ($da ? 'teilgenommen' : 'nicht erschienen'));
        $liste[] = ['id' => (int)$a['id'], 'name' => va_von($va, 'veranstaltung_name') ?: va_mit($va, 'va_kurz'), 'datum' => va_von($va, 'veranstaltung_datum'),
            'kuerzel' => $a['kuerzel'], 'status' => $a['va_status'], 'stand' => $stand, 'teilgenommen' => $vorbei && $da, 'zertifikat' => zertifikat_verfuegbar($a)];
    }
    usort($liste, fn($x, $y) => [$y['datum'], $y['id']] <=> [$x['datum'], $x['id']]);
    return $liste;
}

/** Vor- und Nachname aus dem Konto (Name „Vorname Nachname“) für das Anmeldeformular. */
function tk_namen(array $k): array
{
    $teile = preg_split('/\s+/u', trim((string)$k['name']), 2) ?: [''];
    return ['vorname' => $teile[0], 'nachname' => $teile[1] ?? ''];
}

/** Anmeldung mit gleicher Adresse verknüpfen (beide Adressen bestätigt, E-48). */
function tk_verknuepfen(int $kontoId): void
{
    $k = konto_laden($kontoId);
    if ($k === null || ($k['email_bestaetigt_am'] === null && !konto_ist_team($k)) || tk_anmeldung($kontoId) !== null) {
        return;
    }
    $a = db_zeile("SELECT * FROM anmeldung WHERE veranstaltung_id = ? AND email = ? AND konto_id IS NULL AND status = 'bestaetigt'", [va_id(), $k['email']]);
    if ($a === null) {
        return;
    }
    db_ausfuehren('UPDATE anmeldung SET konto_id = ? WHERE id = ?', [$kontoId, $a['id']]);
    konto_profil_setzen($kontoId, $a);
    audit_schreiben('konto', 'Konto ' . $kontoId . ' mit Anmeldung ' . $a['id'] . ' verknüpft', $kontoId);
}

/**
 * Konto zu einer bestätigten Anmeldung anlegen (Häkchen beim Anmelden oder
 * Kasten in „Deine Anmeldung“). Das Passwort legt die Person danach fest.
 * Gibt die Konto-ID zurück oder null (Adresse gehört einem Team-Konto).
 */
function tk_anlegen_aus_anmeldung(int $anmId): ?int
{
    $a = anm_laden($anmId);
    if ($a === null || $a['status'] !== 'bestaetigt') {
        return null;
    }
    if ($a['konto_id'] !== null) {
        return (int)$a['konto_id'];
    }
    $k = konto_per_email($a['email']);
    if ($k !== null) {
        if ($k['rolle'] !== 'teilnehmend') {
            return null;
        }
        if ($k['email_bestaetigt_am'] === null) {
            db_ausfuehren('UPDATE konto SET email_bestaetigt_am = ? WHERE id = ?', [db_jetzt(), $k['id']]);
        }
        tk_verknuepfen((int)$k['id']);
        return (int)$k['id'];
    }
    $id = konto_anlegen(person_name($a), $a['email'], 'teilnehmend');
    db_ausfuehren('UPDATE konto SET email_bestaetigt_am = ? WHERE id = ?', [db_jetzt(), $id]);
    tk_verknuepfen($id);
    return $id;
}

/** Link für ein Teilnehmenden-Konto (bestaetigen, passwort, email). Gibt die URL zurück. */
function tk_link(int $kontoId, string $zweck, ?string $neueEmail = null, ?int $jetzt = null): array
{
    $jetzt ??= time();
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    db_transaktion(function () use ($kontoId, $zweck, $neueEmail, $token, $jetzt): void {
        db_ausfuehren('DELETE FROM konto_link WHERE konto_id = ? AND zweck = ?', [$kontoId, $zweck]);
        db_ausfuehren(
            'INSERT INTO konto_link (konto_id, token_hash, zweck, neue_email, gueltig_bis, erstellt_am) VALUES (?, ?, ?, ?, ?, ?)',
            [$kontoId, hash('sha256', $token), $zweck, $neueEmail, db_zeit($jetzt + TK_LINK_GUELTIG_S), db_zeit($jetzt)]
        );
    });
    return ['url' => url('/konto/link?t=' . $token), 'gueltig' => datum_zeit_text(db_zeit($jetzt + TK_LINK_GUELTIG_S))];
}

/**
 * Konto ohne Anmeldung anlegen (/konto/neu). Gleiche Antwort für jede
 * Adresse (R-03): neue Adresse → Bestätigungsmail; schon ein Konto → Hinweis
 * per Mail; Team-Adresse → nichts.
 */
function tk_registrieren(string $name, string $email): void
{
    $k = konto_per_email($email);
    if ($k === null) {
        $id = konto_anlegen($name, $email, 'teilnehmend');
        $l = tk_link($id, 'bestaetigen');
        mail_vorlage_einreihen($email, 'konto_bestaetigen', ['vorname' => tk_namen(['name' => $name])['vorname'], 'link' => $l['url'], 'gueltig' => $l['gueltig']]);
    } elseif ($k['rolle'] === 'teilnehmend') {
        if ($k['email_bestaetigt_am'] === null) {
            $l = tk_link((int)$k['id'], 'bestaetigen');
            mail_vorlage_einreihen($email, 'konto_bestaetigen', ['vorname' => tk_namen($k)['vorname'], 'link' => $l['url'], 'gueltig' => $l['gueltig']]);
        } else {
            mail_vorlage_einreihen($email, 'konto_vorhanden', ['vorname' => tk_namen($k)['vorname']]);
        }
    }
}

/**
 * Passwort vergessen (gleiche Antwort für alle): bestätigte Teilnehmenden-Konten
 * bekommen einen Konto-Link; aktive Team-Konten mit Passwort einen Link über
 * /einrichten (AP-6, E-50) – der zweite Faktor bleibt dabei bestehen.
 */
function tk_passwort_vergessen(string $email): void
{
    $k = konto_per_email($email);
    if ($k === null || (int)$k['aktiv'] !== 1) {
        return;
    }
    if (konto_ist_team($k)) {
        if ($k['passwort_hash'] !== null) {
            $token = konto_link_erzeugen((int)$k['id'], 'passwort');
            mail_vorlage_einreihen($k['email'], 'team_passwort', ['vorname' => tk_namen($k)['vorname'], 'link' => konto_link_url($token)]);
        }
        return;
    }
    if ($k['rolle'] !== 'teilnehmend' || $k['email_bestaetigt_am'] === null) {
        return;
    }
    $l = tk_link((int)$k['id'], 'passwort');
    mail_vorlage_einreihen($k['email'], 'konto_passwort', ['vorname' => tk_namen($k)['vorname'], 'link' => $l['url'], 'gueltig' => $l['gueltig']]);
}

/**
 * Link einlösen (einmal). Gibt ['konto' => …, 'zweck' => …, 'meldung' => …]
 * oder null zurück. Team-Links (/einrichten) gehen hier nicht.
 */
function tk_link_einloesen(string $token): ?array
{
    $f = konto_link_finden($token);
    if ($f === null || $f['konto']['rolle'] !== 'teilnehmend' || !in_array($f['link']['zweck'], ['bestaetigen', 'passwort', 'email'], true)) {
        return null;
    }
    if (!konto_link_verbrauchen((int)$f['link']['id'])) {
        return null;
    }
    $k = $f['konto'];
    $id = (int)$k['id'];
    $meldung = null;
    switch ($f['link']['zweck']) {
        case 'bestaetigen':
            db_ausfuehren('UPDATE konto SET email_bestaetigt_am = COALESCE(email_bestaetigt_am, ?) WHERE id = ?', [db_jetzt(), $id]);
            audit_schreiben('konto', 'Adresse bestätigt', $id);
            tk_verknuepfen($id);
            $meldung = 'Deine Adresse ist bestätigt. Leg jetzt dein Passwort fest.';
            break;
        case 'passwort':
            $meldung = 'Leg jetzt ein neues Passwort fest.';
            break;
        case 'email':
            $neu = (string)$f['link']['neue_email'];
            $a = tk_anmeldung($id);
            if (konto_per_email($neu) !== null || db_wert('SELECT COUNT(*) FROM anmeldung WHERE veranstaltung_id = ? AND email = ? AND id <> ?', [va_id(), $neu, $a['id'] ?? 0]) > 0) {
                $meldung = 'Diese Adresse gehört schon zu einem anderen Konto oder einer anderen Anmeldung. Deine Adresse bleibt, wie sie war.';
            } else {
                db_transaktion(function () use ($id, $neu, $a): void {
                    db_ausfuehren('UPDATE konto SET email = ?, geaendert_am = ? WHERE id = ?', [$neu, db_jetzt(), $id]);
                    if ($a !== null) {
                        db_ausfuehren('UPDATE anmeldung SET email = ?, geaendert_am = ? WHERE id = ?', [$neu, db_jetzt(), $a['id']]);
                    }
                });
                audit_schreiben('konto', 'Neue Adresse bestätigt', $id);
                $meldung = 'Deine neue Adresse gilt ab jetzt' . ($a !== null ? ', auch für deine Anmeldung.' : '.');
            }
            break;
    }
    return ['konto' => konto_laden($id), 'zweck' => $f['link']['zweck'], 'meldung' => $meldung];
}

/**
 * Login für alle Konten (E-48): prüft Passwort und Ratenschutz.
 * Gibt ['konto' => …] bei Erfolg zurück, sonst ['fehler' => …].
 */
function tk_login_pruefen(string $email, string $passwort): array
{
    $ip = rate_merkmal_ip();
    $kennung = rate_merkmal_kennung($email);
    if (!rate_erlaubt('login_ip', $ip) || !rate_erlaubt('login', $kennung)) {
        return ['fehler' => rate_text('login')];
    }
    $beginn = microtime(true);
    $k = konto_per_email($email);
    $ok = anmeldung_passwort_ok($k, $passwort) && (int)$k['aktiv'] === 1
        && (konto_ist_team($k) || ($k['rolle'] === 'teilnehmend' && $k['email_bestaetigt_am'] !== null));
    rate_gleiche_dauer($beginn);
    if (!$ok) {
        rate_misserfolg('login_ip', $ip);
        rate_misserfolg('login', $kennung);
        return ['fehler' => 'E-Mail-Adresse oder Passwort stimmt nicht.'];
    }
    rate_erfolg('login', $kennung);
    return ['konto' => $k];
}

/** Profil ohne verknüpfte Anmeldung (Name, Berufsgruppe, Institution, EFN). Fehlerliste. */
/**
 * Fehler ohne Feldbezug (Zahlen-Schlüssel, z. B. „Die Anmeldung gibt es nicht
 * mehr.“) unter '_'; „Mein Konto“ zeigt sie als Meldung (Review B-14).
 */
function tk_profil_fehler(array $fehler): array
{
    $f = [];
    foreach ($fehler as $feld => $text) {
        $f[is_int($feld) ? '_' : $feld] = $text;
    }
    return $f;
}

/** Profil speichern. Gibt Fehler je Feld zurück; Fehler ohne Feldbezug unter '_'. */
function tk_profil_setzen(int $kontoId, array $e): array
{
    $k = konto_laden($kontoId);
    $a = tk_anmeldung($kontoId);
    if ($a !== null) {
        // Mit Anmeldung: dort ändern (Adresse über das Konto), Konto zieht mit
        $r = anm_daten_setzen((int)$a['id'], ['email' => $a['email']] + $e);
        if ($r['fehler'] === []) {
            $a = anm_laden((int)$a['id']);
            konto_profil_setzen($kontoId, $a);
        }
        return tk_profil_fehler($r['fehler']);
    }
    $r = anm_daten_pruefen(['email' => $k['email'], 'essen' => 'egal'] + $e);
    $f = array_intersect_key($r['fehler'], array_flip(['vorname', 'nachname', 'berufsgruppe', 'beruf_sonstige', 'institution', 'efn']));
    if ($f !== []) {
        return $f;
    }
    $w = $r['werte'];
    konto_profil_setzen($kontoId, $w);
    audit_schreiben('konto', 'Profil geändert', $kontoId);
    return [];
}

/** Neue Adresse: gilt erst nach dem Link an die neue Adresse. Gibt eine Fehlermeldung oder null zurück. */
function tk_email_aendern(int $kontoId, string $neu): ?string
{
    $neu = mail_adresse($neu) ?? '';
    if ($neu === '') {
        return 'Diese E-Mail-Adresse sieht nicht wie eine Adresse aus.';
    }
    $k = konto_laden($kontoId);
    if ($neu === $k['email']) {
        return null;
    }
    if (!adresse_aendern_erlaubt($neu)) {
        return ANM_ADRESSE_RATE_TEXT;
    }
    // tk_link löscht ältere Links desselben Zwecks; Anrede ohne Namen (Review S-2)
    $l = tk_link($kontoId, 'email', $neu);
    mail_vorlage_einreihen($neu, 'email_bestaetigen', ['link' => $l['url'], 'gueltig' => $l['gueltig']]);
    return null;
}

/**
 * Konto löschen; die Anmeldung bleibt (ohne Konto, per Link erreichbar) oder
 * wird storniert (Admin 2026-10-29). Gibt eine Fehlermeldung oder null zurück.
 */
function tk_loeschen(int $kontoId, bool $anmeldungAuch): ?string
{
    $a = tk_anmeldung($kontoId);
    if ($anmeldungAuch && $a !== null) {
        if (!anm_frist_offen('stichtag_storno')) {
            return 'Absagen geht nicht mehr selbst. Lösche nur das Konto, oder schreib uns.';
        }
        anm_stornieren((int)$a['id']);
    }
    db_ausfuehren("DELETE FROM konto WHERE id = ? AND rolle = 'teilnehmend'", [$kontoId]);
    audit_schreiben('konto', 'Konto ' . $kontoId . ' gelöscht' . ($a !== null ? ($anmeldungAuch ? ' (Anmeldung storniert)' : ' (Anmeldung bleibt)') : ''));
    return null;
}

/** Unbestätigte Konten nach Ablauf des Links löschen (Job 'anmeldung'). */
function tk_aufraeumen(int $jetzt): int
{
    return db_ausfuehren(
        "DELETE FROM konto WHERE rolle = 'teilnehmend' AND email_bestaetigt_am IS NULL AND erstellt_am < ?",
        [db_zeit($jetzt - TK_LINK_GUELTIG_S)]
    )->rowCount();
}

/**
 * Löschfrist der Konten (Job 'anmeldung'): erinnern, dann löschen.
 * Stichtag = letzter Login (sonst Bestätigung, sonst Anlage) + 14 Monate.
 * Gelöscht wird erst, wenn die Erinnerung mindestens 30 Tage alt ist – auch
 * wenn der Job einmal ausfällt, hat jede Person die volle Frist.
 */
function tk_loeschfrist(int $jetzt): array
{
    $j = db_zeit($jetzt);
    $basis = 'COALESCE(letzte_anmeldung, email_bestaetigt_am, erstellt_am)';
    $erinnert = 0;
    $faellig = db_zeilen(
        "SELECT id, name, email, DATE_ADD($basis, INTERVAL " . KONTO_LOESCHEN_MONATE . " MONTH) AS loeschen_am FROM konto
         WHERE rolle = 'teilnehmend' AND aktiv = 1 AND email_bestaetigt_am IS NOT NULL AND loesch_erinnert_am IS NULL
           AND DATE_SUB(DATE_ADD($basis, INTERVAL " . KONTO_LOESCHEN_MONATE . " MONTH), INTERVAL " . KONTO_ERINNERN_TAGE . " DAY) <= ?",
        [$j]
    );
    foreach ($faellig as $k) {
        // Frühestens 30 Tage nach der Erinnerung löschen
        $am = max(db_ts($k['loeschen_am']), $jetzt + KONTO_ERINNERN_TAGE * 86400);
        mail_vorlage_einreihen((string)$k['email'], 'konto_loeschen_bald', ['vorname' => tk_namen($k)['vorname'], 'datum' => datum_text(db_tag($am)), 'link' => url('/einloggen')]);
        db_ausfuehren('UPDATE konto SET loesch_erinnert_am = ? WHERE id = ?', [$j, (int)$k['id']]);
        $erinnert++;
    }
    $geloescht = 0;
    foreach (db_zeilen(
        "SELECT id FROM konto WHERE rolle = 'teilnehmend' AND loesch_erinnert_am IS NOT NULL AND loesch_erinnert_am <= ?
           AND DATE_ADD($basis, INTERVAL " . KONTO_LOESCHEN_MONATE . " MONTH) <= ?",
        [db_zeit($jetzt - KONTO_ERINNERN_TAGE * 86400), $j]
    ) as $k) {
        tk_loeschen((int)$k['id'], false);
        $geloescht++;
    }
    if ($erinnert + $geloescht > 0) {
        audit_schreiben('konto', "Löschfrist: $erinnert erinnert, $geloescht Konten gelöscht (Anmeldungen bleiben)");
    }
    return ['erinnert' => $erinnert, 'geloescht' => $geloescht];
}

/** Anmeldung eines Kontos in irgendeiner Veranstaltung (Zertifikat aus „Mein Konto“, E-58) oder null. */
function tk_eigene_anmeldung(int $kontoId, int $anmId): ?array
{
    return db_zeile('SELECT * FROM anmeldung WHERE id = ? AND konto_id = ?', [$anmId, $kontoId]);
}
