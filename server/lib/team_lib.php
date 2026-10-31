<?php
declare(strict_types=1);

/*
 * Team-Bereich (AP-5, E-49): Personen und Plätze verwalten, Aufgaben,
 * Zahlen. Das Team darf mehr als Teilnehmende (E-31): auch nach den
 * Stichtagen, ohne Workshop-Limit, volle Termine überbuchen (nur mit
 * Bestätigung), Plätze von Hand vergeben – auch nach der Grenze für
 * automatisches Nachrücken. Jede Änderung steht im Audit-Protokoll; die
 * Person bekommt eine Mail, außer das Team kreuzt „ohne Mail“ an
 * (Admin 2026-10-29).
 */

/** Filter der Personenliste mit Beschriftung (Mockup „Personen“). */
const TEAM_FILTER = [
    'alle' => 'Alle',
    'angemeldet' => 'Angemeldet',
    'warteliste' => 'Warteliste Symposium',
    'unbestaetigt' => 'Mail nicht bestätigt',
    'konto' => 'Mit Konto',
    'team' => 'Team',
];

/**
 * Alle Personen: Anmeldungen (mit Konto, falls verknüpft) und Konten ohne
 * Anmeldung. Je Eintrag: art ('anm'|'konto'), id, name, email, status,
 * konto (Zeile oder null), plaetze, essen, erstellt.
 */
function team_personen(): array
{
    $konten = [];
    foreach (db_zeilen('SELECT * FROM konto') as $k) {
        $konten[(int)$k['id']] = $k;
    }
    $liste = [];
    $mitAnmeldung = [];
    foreach (anm_liste() as $a) {
        $k = $a['konto_id'] !== null ? ($konten[(int)$a['konto_id']] ?? null) : null;
        if ($k !== null) {
            $mitAnmeldung[(int)$k['id']] = true;
        }
        $sym = '';
        foreach ($a['plaetze'] as $p) {
            if ((int)$p['termin_id'] === 0) {
                $sym = $p['status'];
            }
        }
        $status = $a['status'] === 'unbestaetigt' ? 'unbestaetigt' : ($sym === 'fest' ? 'angemeldet' : 'warteliste');
        $liste[] = ['art' => 'anm', 'id' => (int)$a['id'], 'name' => $a['vorname'] . ' ' . $a['nachname'], 'email' => $a['email'],
            'status' => $status, 'sym' => $sym, 'konto' => $k, 'anmeldung' => $a, 'essen' => $a['essen'], 'erstellt' => $a['erstellt_am']];
    }
    foreach ($konten as $id => $k) {
        if (isset($mitAnmeldung[$id])) {
            continue;
        }
        $liste[] = ['art' => 'konto', 'id' => $id, 'name' => $k['name'], 'email' => $k['email'],
            'status' => $k['rolle'] === 'teilnehmend' ? ($k['email_bestaetigt_am'] === null ? 'unbestaetigt' : 'konto') : 'team',
            'sym' => '', 'konto' => $k, 'anmeldung' => null, 'essen' => null, 'erstellt' => $k['erstellt_am']];
    }
    usort($liste, fn($x, $y) => strcoll(mb_strtolower($x['name']), mb_strtolower($y['name'])));
    return $liste;
}

/** Passt eine Person zum Filter? */
function team_filter_passt(array $p, string $filter): bool
{
    return match ($filter) {
        'angemeldet' => $p['status'] === 'angemeldet',
        'warteliste' => $p['status'] === 'warteliste',
        'unbestaetigt' => $p['status'] === 'unbestaetigt',
        'konto' => $p['konto'] !== null && $p['konto']['rolle'] === 'teilnehmend',
        'team' => $p['konto'] !== null && $p['konto']['rolle'] !== 'teilnehmend',
        default => true,
    };
}

/** Suche in Name, Adresse und Institution (ohne Groß-/Kleinschreibung). */
function team_suche_passt(array $p, string $q): bool
{
    $q = mb_strtolower(trim($q));
    if ($q === '') {
        return true;
    }
    $heu = mb_strtolower($p['name'] . ' ' . $p['email'] . ' ' . ($p['anmeldung']['institution'] ?? $p['konto']['institution'] ?? ''));
    return str_contains($heu, $q);
}

/* ---- Anmeldung bearbeiten ------------------------------------------------ */

/**
 * Daten einer Anmeldung durch das Team ändern; eine neue Adresse gilt sofort
 * (das Team trägt sie ein). Verknüpftes Konto zieht mit. Fehlerliste.
 */
function team_anm_daten(int $anmId, array $e, bool $mail): array
{
    $a = anm_laden($anmId);
    if ($a === null) {
        return ['Diese Anmeldung gibt es nicht mehr.'];
    }
    $r = anm_daten_pruefen($e + ['essen' => $a['essen']]);
    if ($r['fehler'] !== []) {
        return $r['fehler'];
    }
    $w = $r['werte'];
    if ($w['email'] !== $a['email']) {
        $k = konto_per_email($w['email']);
        if (db_wert('SELECT COUNT(*) FROM anmeldung WHERE veranstaltung_id = ? AND email = ? AND id <> ?', [va_id(), $w['email'], $anmId]) > 0
            || ($k !== null && (int)$k['id'] !== (int)($a['konto_id'] ?? 0))) {
            return ['email' => 'Diese Adresse gehört schon zu einer anderen Anmeldung oder einem anderen Konto.'];
        }
    }
    db_transaktion(function () use ($anmId, $w, $a): void {
        db_ausfuehren(
            'UPDATE anmeldung SET vorname = ?, nachname = ?, email = ?, berufsgruppe = ?, beruf_sonstige = ?, institution = ?, efn = ?, sitzung_version = sitzung_version + ?, geaendert_am = ? WHERE id = ?',
            [$w['vorname'], $w['nachname'], $w['email'], $w['berufsgruppe'], $w['beruf_sonstige'], $w['institution'], $w['efn'] !== '' ? $w['efn'] : null, $w['email'] !== $a['email'] ? 1 : 0, db_jetzt(), $anmId]
        );
        if ($a['konto_id'] !== null) {
            db_ausfuehren('UPDATE konto SET email = ?, name = ?, berufsgruppe = ?, beruf_sonstige = ?, institution = ?, efn = ?, geaendert_am = ? WHERE id = ?',
                [$w['email'], trim($w['vorname'] . ' ' . $w['nachname']), $w['berufsgruppe'], $w['beruf_sonstige'], $w['institution'], $w['efn'] !== '' ? $w['efn'] : null, db_jetzt(), $a['konto_id']]);
        }
    });
    anm_geaendert_melden($anmId, 'deine Angaben sind aktualisiert.', $mail, true);
    return [];
}

/**
 * Person durch das Team anmelden (Mockup „+ Person anlegen“): gilt sofort als
 * bestätigt. Ist das Symposium voll, kommt sie auf die Warteliste – außer
 * das Team bestätigt das Überbuchen. Gibt ['id' => …] oder ['fehler' => [...]] zurück.
 */
function team_anlegen(array $e, bool $ueberbuchen, bool $mail): array
{
    $r = anm_daten_pruefen($e);
    if ($r['fehler'] !== []) {
        return ['fehler' => $r['fehler']];
    }
    $w = $r['werte'];
    return anm_sperre(function () use ($w, $ueberbuchen, $mail): array {
        if (anm_per_email($w['email']) !== null) {
            return ['fehler' => ['email' => 'Mit dieser Adresse gibt es schon eine Anmeldung.']];
        }
        $jetzt = db_jetzt();
        $k = konto_per_email($w['email']);
        $kontoId = $k !== null && $k['rolle'] === 'teilnehmend' && $k['email_bestaetigt_am'] !== null ? (int)$k['id'] : null;
        db_ausfuehren(
            "INSERT INTO anmeldung (veranstaltung_id, konto_id, vorname, nachname, email, berufsgruppe, beruf_sonstige, institution, efn, essen, newsletter, status, einwilligung_am, erstellt_am, bestaetigt_am, geaendert_am)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'bestaetigt', ?, ?, ?, ?)",
            [va_id(), $kontoId, $w['vorname'], $w['nachname'], $w['email'], $w['berufsgruppe'], $w['beruf_sonstige'], $w['institution'], $w['efn'] !== '' ? $w['efn'] : null, $w['essen'], $jetzt, $jetzt, $jetzt, $jetzt]
        );
        $id = (int)db()->lastInsertId();
        $fest = anm_frei(0) > 0 || $ueberbuchen;
        anm_platz_anlegen($id, 0, $fest ? 'fest' : 'warte', time());
        audit_schreiben('anmeldung', 'Anmeldung ' . $id . ' vom Team angelegt' . ($fest ? '' : ' (Warteliste)') . ($mail ? '' : ', ohne Mail'), anm_team_konto());
        if ($mail) {
            mail_vorlage_einreihen($w['email'], 'bestaetigt', ['vorname' => $w['vorname'], 'uebersicht' => anm_uebersicht_text($id)]);
        }
        return ['id' => $id, 'warteliste' => !$fest];
    });
}

/* ---- Plätze von Hand ----------------------------------------------------- */

/**
 * Platz direkt geben (auch nach der Grenze, Admin 2026-10-29). Ist der Termin
 * voll oder fallen dafür andere Plätze weg (Tausch, E-38), gibt die Funktion
 * ['frage' => [Texte]] zurück, bis das Team bestätigt. Sonst ['ok' => true]
 * oder ['fehler' => '…'].
 */
function team_platz_geben(int $anmId, int $terminId, bool $bestaetigt, bool $mail): array
{
    return anm_sperre(function () use ($anmId, $terminId, $bestaetigt, $mail): array {
        $a = anm_laden($anmId);
        if ($a === null) {
            return ['fehler' => 'Diese Anmeldung gibt es nicht mehr.'];
        }
        if ($terminId !== 0 && anm_termin($terminId) === null) {
            return ['fehler' => 'Diesen Termin gibt es im veröffentlichten Programm nicht.'];
        }
        if ($terminId !== 0 && anm_symposium_status($anmId) !== 'fest') {
            return ['fehler' => 'Zuerst einen Platz beim Symposium geben, dann Workshops.'];
        }
        $p = db_zeile('SELECT * FROM platz WHERE anmeldung_id = ? AND termin_id = ? FOR UPDATE', [$anmId, $terminId]);
        if ($p !== null && $p['status'] === 'fest') {
            return ['ok' => true];
        }
        $frage = [];
        $belegtOhne = anm_belegt($terminId) - ($p !== null && $p['status'] === 'angebot' ? 1 : 0);
        $kap = anm_kapazitaet($terminId);
        if ($kap !== null && $belegtOhne >= $kap) {
            $frage[] = anm_termin_text($terminId) . ' ist voll (' . $belegtOhne . ' / ' . $kap . ') – wird überbucht.';
        }
        $tausch = anm_tausch($anmId, ['termin_id' => $terminId]);
        foreach ($tausch as $x) {
            $frage[] = anm_termin_text((int)$x['termin_id']) . ' wird storniert (' . $x['grund'] . ').';
        }
        if ($frage !== [] && !$bestaetigt) {
            return ['frage' => $frage];
        }
        foreach ($tausch as $x) {
            db_ausfuehren('DELETE FROM platz WHERE id = ?', [$x['id']]);
        }
        if ($p !== null) {
            db_ausfuehren("UPDATE platz SET status = 'fest', angebot_bis = NULL, seit = ? WHERE id = ?", [db_jetzt(), $p['id']]);
        } else {
            anm_platz_anlegen($anmId, $terminId, 'fest', time());
        }
        foreach ($tausch as $x) {
            anm_nachruecken((int)$x['termin_id']);
        }
        audit_schreiben('nachruecken', 'Team: Platz für Termin ' . $terminId . ' an Anmeldung ' . $anmId . ' vergeben' . ($frage !== [] ? ' (bestätigt: ' . implode(' ', $frage) . ')' : ''), anm_team_konto());
        if ($mail && $a['status'] === 'bestaetigt') {
            mail_vorlage_einreihen($a['email'], 'platz_erhalten', ['vorname' => $a['vorname'], 'was' => anm_termin_text($terminId), 'uebersicht' => anm_uebersicht_text($anmId)]);
        }
        return ['ok' => true];
    });
}

/**
 * Angebot von Hand schicken (wie beim Nachrücken, mit Annahmefrist), auch
 * nach der Grenze. Nur für einen Wartelisten-Eintrag und nur bei freiem Platz.
 * Gibt eine Fehlermeldung oder null zurück.
 */
function team_angebot(int $platzId): ?string
{
    return anm_sperre(function () use ($platzId): ?string {
        $p = db_zeile("SELECT * FROM platz WHERE id = ? AND status = 'warte' FOR UPDATE", [$platzId]);
        if ($p === null) {
            return 'Dieser Eintrag steht nicht mehr auf der Warteliste.';
        }
        $tid = (int)$p['termin_id'];
        if (anm_frei($tid) <= 0) {
            return 'Kein freier Platz. Mit „Platz geben“ lässt sich überbuchen.';
        }
        $a = anm_laden((int)$p['anmeldung_id']);
        if ($a === null || $a['status'] !== 'bestaetigt') {
            return 'Die Anmeldung ist noch nicht bestätigt.';
        }
        $jetzt = time();
        $fristTs = $jetzt + va_zahl('nachrueck_frist_h') * 3600;
        db_ausfuehren("UPDATE platz SET status = 'angebot', angebot_bis = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', $fristTs), $platzId]);
        $tausch = anm_tausch((int)$a['id'], $p);
        $token = anm_link_erzeugen((int)$a['id'], 'zugang', max($fristTs, $jetzt + ANM_LINK_GUELTIG_H * 3600));
        mail_vorlage_einreihen($a['email'], 'angebot', [
            'vorname' => $a['vorname'], 'was' => anm_termin_text($tid), 'frist' => datum_zeit_text(gmdate('Y-m-d H:i:s', $fristTs)), 'link' => anm_link_url($token),
            'tausch' => $tausch === [] ? '' : 'Nimmst du an, fällt dafür weg: ' . implode('; ', array_map(fn($x) => anm_termin_text((int)$x['termin_id']), $tausch)) . '.',
        ]);
        audit_schreiben('nachruecken', 'Team: Angebot an Anmeldung ' . $a['id'] . ' für Termin ' . $tid, anm_team_konto());
        return null;
    });
}

/**
 * Workshop-Platz, Angebot oder Wartelisten-Eintrag entfernen. Ein frei
 * gewordener Platz geht ans Nachrücken (automatisch bis zur Grenze).
 * Den Platz beim Symposium nimmt nur das Stornieren weg.
 */
function team_platz_entfernen(int $platzId, bool $mail): bool
{
    return anm_sperre(function () use ($platzId, $mail): bool {
        $p = db_zeile('SELECT * FROM platz WHERE id = ? FOR UPDATE', [$platzId]);
        if ($p === null || (int)$p['termin_id'] === 0) {
            return false;
        }
        db_ausfuehren('DELETE FROM platz WHERE id = ?', [$platzId]);
        if ($p['status'] !== 'warte') {
            anm_nachruecken((int)$p['termin_id']);
        }
        $was = ($p['status'] === 'warte' ? 'von der Warteliste genommen: ' : 'Platz entfernt: ') . anm_termin_text((int)$p['termin_id']) . '.';
        anm_geaendert_melden((int)$p['anmeldung_id'], $was, $mail, true);
        return true;
    });
}

/* ---- Termine und Aufgaben ------------------------------------------------ */

/** Symposium und alle Termine mit Zahlen: [['id','text','kap','belegt','frei','warte','angebote']]. */
function team_termine(): array
{
    $liste = [];
    $ids = [0];
    foreach (anm_workshops() as $w) {
        foreach ($w['termine'] as $t) {
            $ids[] = (int)$t['id'];
        }
    }
    foreach ($ids as $tid) {
        $kap = anm_kapazitaet($tid);
        $belegt = anm_belegt($tid);
        $liste[] = [
            'id' => $tid, 'text' => $tid === 0 ? 'Symposium' : anm_termin_text($tid), 'termin' => $tid === 0 ? null : anm_termin($tid),
            'kap' => $kap, 'belegt' => $belegt, 'frei' => $kap === null ? null : $kap - $belegt, 'warte' => anm_wartend($tid),
            'angebote' => (int)db_wert("SELECT COUNT(*) FROM platz WHERE veranstaltung_id = ? AND termin_id = ? AND status = 'angebot'", [va_id(), $tid]),
        ];
    }
    return $liste;
}

/** Personen eines Termins: fest und Angebote, dann Warteliste in Reihenfolge. */
function team_termin_personen(int $terminId): array
{
    $z = db_zeilen(
        "SELECT p.*, a.vorname, a.nachname, a.email, a.status AS anm_status, a.berufsgruppe FROM platz p JOIN anmeldung a ON a.id = p.anmeldung_id
         WHERE p.veranstaltung_id = ? AND p.termin_id = ? ORDER BY FIELD(p.status, 'fest', 'angebot', 'warte'), CASE WHEN p.status = 'warte' THEN p.seit END, p.id, a.nachname",
        [va_id(), $terminId]
    );
    $fest = array_values(array_filter($z, fn($p) => $p['status'] !== 'warte'));
    usort($fest, fn($x, $y) => [$x['status'], mb_strtolower($x['nachname'])] <=> [$y['status'], mb_strtolower($y['nachname'])]);
    return ['fest' => $fest, 'warte' => array_values(array_filter($z, fn($p) => $p['status'] === 'warte'))];
}

/** „Zu erledigen“ auf der Übersicht (Mockup). Liste von ['art','text','link','knopf']. */
function team_aufgaben(?int $jetzt = null): array
{
    $jetzt ??= time();
    $auf = [];
    $grenzeVorbei = $jetzt + va_zahl('nachrueck_frist_h') * 3600 > anm_grenze_ts();
    foreach (team_termine() as $t) {
        $wartendBestaetigt = (int)db_wert("SELECT COUNT(*) FROM platz p JOIN anmeldung a ON a.id = p.anmeldung_id WHERE p.veranstaltung_id = ? AND p.termin_id = ? AND p.status = 'warte' AND a.status = 'bestaetigt'", [va_id(), $t['id']]);
        if ($t['frei'] !== null && $t['frei'] > 0 && $wartendBestaetigt > 0) {
            $auf[] = ['art' => 'Manuell', 'text' => $t['text'] . ': ' . $t['frei'] . ($t['frei'] === 1 ? ' Platz' : ' Plätze') . ' frei, ' . $wartendBestaetigt . ' auf der Warteliste'
                . ($grenzeVorbei ? ' – automatische Angebote sind vorbei.' : ' – das Nachrücken läuft beim nächsten Job.'), 'link' => '/team/workshops?t=' . $t['id'], 'knopf' => 'Vergeben'];
        }
    }
    $ms = mail_stand();
    if (!$ms['eingerichtet']) {
        $auf[] = ['art' => 'Mail', 'text' => 'Der Mailversand ist nicht eingerichtet; ' . $ms['offen'] . ' Mails warten.', 'link' => '/team/daten', 'knopf' => 'Ansehen'];
    } elseif ($ms['fehler'] > 0) {
        $auf[] = ['art' => 'Mail', 'text' => $ms['fehler'] . ($ms['fehler'] === 1 ? ' Mail ließ' : ' Mails ließen') . ' sich nicht zustellen.', 'link' => '/team/daten', 'knopf' => 'Ansehen'];
    }
    foreach (db_zeilen("SELECT p.*, a.vorname, a.nachname FROM platz p JOIN anmeldung a ON a.id = p.anmeldung_id WHERE p.veranstaltung_id = ? AND p.status = 'angebot' AND p.angebot_bis < ? ORDER BY p.angebot_bis", [va_id(), gmdate('Y-m-d H:i:s', $jetzt + 86400)]) as $o) {
        $auf[] = ['art' => 'Frist', 'text' => 'Angebot an ' . $o['vorname'] . ' ' . $o['nachname'] . ' (' . anm_termin_text((int)$o['termin_id']) . ') läuft am ' . datum_zeit_text($o['angebot_bis']) . ' ab.', 'link' => '/team/personen?a=' . $o['anmeldung_id'], 'knopf' => 'Ansehen'];
    }
    // Nach der Veranstaltung: abschließen, sobald die Zertifikate freigeschaltet sind (E-58)
    if ((va_zeile()['status'] ?? '') === 'aktuell' && va('veranstaltung_datum') !== '' && va('veranstaltung_datum') < anm_heute($jetzt)) {
        $offen = tn_zahlen()['offen'];
        $auf[] = ['art' => 'Abschluss', 'text' => $offen > 0 && va_an('mit_zertifikat')
            ? 'Die Veranstaltung ist vorbei; ' . $offen . ' Zertifikate sind noch nicht freigeschaltet. Danach die Veranstaltung abschließen.'
            : 'Die Veranstaltung ist vorbei. Schließ sie ab, damit nichts mehr geändert wird, und mach die nächste zur aktuellen.',
            'link' => $offen > 0 && va_an('mit_zertifikat') ? '/team/teilnahme' : '/team/veranstaltungen', 'knopf' => $offen > 0 && va_an('mit_zertifikat') ? 'Teilnahme' : 'Abschließen'];
    }
    if (programm_status()['offen'] ?? false) {
        $auf[] = ['art' => 'Programm', 'text' => 'Es gibt gespeicherte, noch nicht veröffentlichte Änderungen am Programm.', 'link' => '/team/programm', 'knopf' => 'Ansehen'];
    }
    return $auf;
}

/* ---- Konten -------------------------------------------------------------- */

/** Passwort-Link an ein Teilnehmenden-Konto schicken. */
function team_passwort_link(int $kontoId): bool
{
    $k = konto_laden($kontoId);
    if ($k === null || $k['rolle'] !== 'teilnehmend' || $k['email_bestaetigt_am'] === null) {
        return false;
    }
    $l = tk_link($kontoId, 'passwort');
    mail_vorlage_einreihen($k['email'], 'konto_passwort', ['vorname' => tk_namen($k)['vorname'], 'link' => $l['url'], 'gueltig' => $l['gueltig']]);
    audit_schreiben('konto', 'Team: Passwort-Link an Konto ' . $kontoId . ' geschickt', anm_team_konto());
    return true;
}

/**
 * Teilnehmenden-Konto ins Team holen (E-41; nur Betreiber, mit Code auf der
 * Seite geprüft). Beim nächsten Login wird der zweite Faktor eingerichtet.
 */
function team_rolle_vergeben(int $kontoId, string $rolle): bool
{
    $k = konto_laden($kontoId);
    if ($k === null || $k['rolle'] !== 'teilnehmend' || !in_array($rolle, TEAM_ROLLEN, true)) {
        return false;
    }
    konto_rolle_setzen($kontoId, $rolle, anm_team_konto());
    db_ausfuehren('UPDATE konto SET email_bestaetigt_am = COALESCE(email_bestaetigt_am, ?) WHERE id = ?', [db_jetzt(), $kontoId]);
    // Ohne Passwort: Einrichtungslink wie bei neuen Team-Konten (E-43)
    $link = $k['passwort_hash'] === null ? konto_link_url(konto_link_erzeugen($kontoId, 'einrichten', anm_team_konto())) : '';
    mail_vorlage_einreihen($k['email'], 'team_rolle', ['vorname' => tk_namen($k)['vorname'], 'rolle' => ROLLEN[$rolle], 'link' => $link]);
    return true;
}

/* ---- CSV ----------------------------------------------------------------- */

/** CSV für Excel: Semikolon, UTF-8 mit BOM; Zellen mit = + - @ am Anfang werden entschärft. */
function csv_text(array $kopf, array $zeilen): string
{
    $zelle = function ($w): string {
        $w = (string)$w;
        if ($w !== '' && strpbrk($w[0], "=+-@\t\r") !== false) {
            $w = "'" . $w;
        }
        return '"' . str_replace('"', '""', $w) . '"';
    };
    $out = "\u{FEFF}" . implode(';', array_map($zelle, $kopf)) . "\r\n";
    foreach ($zeilen as $z) {
        $out .= implode(';', array_map($zelle, $z)) . "\r\n";
    }
    return $out;
}
