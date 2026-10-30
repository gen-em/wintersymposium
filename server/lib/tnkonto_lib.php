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
/** Links für Teilnehmenden-Konten gelten 48 h (wie die Einmal-Links, E-46). */
const TK_LINK_GUELTIG_S = 48 * 3600;

/** Gültiges, eingeloggtes Teilnehmenden-Konto dieser Sitzung oder null. */
function tk_aktuell(): ?array
{
    $s = $_SESSION['tk'] ?? null;
    if (!is_array($s) || !isset($s['id'], $s['version'])) {
        return null;
    }
    $k = konto_laden((int)$s['id']);
    if ($k === null || $k['rolle'] !== 'teilnehmend' || (int)$k['aktiv'] !== 1 || $k['email_bestaetigt_am'] === null || (int)$k['sitzung_version'] !== (int)$s['version']) {
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
    db_ausfuehren('UPDATE konto SET letzte_anmeldung = ? WHERE id = ?', [db_jetzt(), (int)$k['id']]);
    $a = tk_anmeldung((int)$k['id']);
    if ($a !== null) {
        $_SESSION['anm'] = ['id' => (int)$a['id'], 'version' => (int)$a['sitzung_version']];
    }
}

function tk_ausloggen(): void
{
    unset($_SESSION['tk'], $_SESSION['anm'], $_SESSION['tk_pw']);
    sitzung_erneuern();
}

/** Verknüpfte Anmeldung eines Kontos oder null. */
function tk_anmeldung(int $kontoId): ?array
{
    return db_zeile('SELECT * FROM anmeldung WHERE konto_id = ?', [$kontoId]);
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
    if ($k === null || $k['email_bestaetigt_am'] === null || tk_anmeldung($kontoId) !== null) {
        return;
    }
    $a = db_zeile("SELECT * FROM anmeldung WHERE email = ? AND konto_id IS NULL AND status = 'bestaetigt'", [$k['email']]);
    if ($a === null) {
        return;
    }
    db_ausfuehren('UPDATE anmeldung SET konto_id = ? WHERE id = ?', [$kontoId, $a['id']]);
    db_ausfuehren(
        'UPDATE konto SET name = ?, berufsgruppe = ?, beruf_sonstige = ?, institution = ?, efn = ?, geaendert_am = ? WHERE id = ?',
        [trim($a['vorname'] . ' ' . $a['nachname']), $a['berufsgruppe'], $a['beruf_sonstige'], $a['institution'], $a['efn'], db_jetzt(), $kontoId]
    );
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
    $id = konto_anlegen(trim($a['vorname'] . ' ' . $a['nachname']), $a['email'], 'teilnehmend');
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
            [$kontoId, hash('sha256', $token), $zweck, $neueEmail, gmdate('Y-m-d H:i:s', $jetzt + TK_LINK_GUELTIG_S), gmdate('Y-m-d H:i:s', $jetzt)]
        );
    });
    return ['url' => url('/konto/link?t=' . $token), 'gueltig' => datum_zeit_text(gmdate('Y-m-d H:i:s', $jetzt + TK_LINK_GUELTIG_S))];
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
            if (konto_per_email($neu) !== null || db_wert('SELECT COUNT(*) FROM anmeldung WHERE email = ? AND id <> ?', [$neu, $a['id'] ?? 0]) > 0) {
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
        return ['fehler' => 'Zu viele Versuche. Bitte warte 15 Minuten und versuche es dann noch einmal.'];
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
function tk_profil_setzen(int $kontoId, array $e): array
{
    $k = konto_laden($kontoId);
    $a = tk_anmeldung($kontoId);
    if ($a !== null) {
        // Mit Anmeldung: dort ändern (Adresse über das Konto), Konto zieht mit
        $r = anm_daten_setzen((int)$a['id'], ['email' => $a['email']] + $e);
        if ($r['fehler'] === []) {
            $a = anm_laden((int)$a['id']);
            db_ausfuehren('UPDATE konto SET name = ?, berufsgruppe = ?, beruf_sonstige = ?, institution = ?, efn = ?, geaendert_am = ? WHERE id = ?',
                [trim($a['vorname'] . ' ' . $a['nachname']), $a['berufsgruppe'], $a['beruf_sonstige'], $a['institution'], $a['efn'], db_jetzt(), $kontoId]);
        }
        return $r['fehler'];
    }
    $r = anm_daten_pruefen(['email' => $k['email'], 'essen' => 'egal'] + $e);
    $f = array_intersect_key($r['fehler'], array_flip(['vorname', 'nachname', 'berufsgruppe', 'beruf_sonstige', 'institution', 'efn']));
    if ($f !== []) {
        return $f;
    }
    $w = $r['werte'];
    db_ausfuehren('UPDATE konto SET name = ?, berufsgruppe = ?, beruf_sonstige = ?, institution = ?, efn = ?, geaendert_am = ? WHERE id = ?',
        [trim($w['vorname'] . ' ' . $w['nachname']), $w['berufsgruppe'], $w['beruf_sonstige'], $w['institution'], $w['efn'] !== '' ? $w['efn'] : null, db_jetzt(), $kontoId]);
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
    $l = tk_link($kontoId, 'email', $neu);
    mail_vorlage_einreihen($neu, 'email_bestaetigen', ['vorname' => tk_namen($k)['vorname'], 'link' => $l['url'], 'gueltig' => $l['gueltig']]);
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
        [gmdate('Y-m-d H:i:s', $jetzt - TK_LINK_GUELTIG_S)]
    )->rowCount();
}
