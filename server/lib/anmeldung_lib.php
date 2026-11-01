<?php
declare(strict_types=1);

/*
 * Anmeldung, Plätze, Wartelisten und Nachrücken (AP-4; E-19, E-20, E-21,
 * E-22, E-31, E-38, E-42, E-46).
 *
 * Datenmodell (Migration 6):
 *   anmeldung      – eine je E-Mail-Adresse; unbestätigt bis zum Klick auf den
 *                    Link in der Mail, dann bestätigt. Storno und verfallene
 *                    Bestätigung löschen die Zeile (R-02).
 *   platz          – termin_id 0 = Platz beim Symposium, sonst ein
 *                    Workshop-Termin aus dem veröffentlichten Programm.
 *                    Status fest (Platz), warte (Warteliste, Reihenfolge nach
 *                    seit, id) oder angebot (Nachrück-Angebot mit Frist).
 *   link (art anmeldung, link_lib) – Einmal-Links (bestätigen, Zugang, neue Adresse, übernehmen), nur Hash.
 *
 * Plätze zählen: fest + angebot. Auch unbestätigte Anmeldungen halten ihre
 * Plätze bis zum Ende der Bestätigungsfrist (Admin 2026-10-29).
 *
 * Jede Änderung an Plätzen läuft in anm_sperre(): eine MySQL-Sperre für alle
 * Platzvergaben, darin eine Transaktion. So wird kein Platz doppelt vergeben
 * (R-04), auch wenn viele gleichzeitig auf den letzten Platz drücken.
 */

/** Anmeldungsnummer zum Anzeigen: „0012“ (V-49). */
function anm_nummer(int $id): string
{
    return sprintf('%04d', $id);
}

/**
 * Profilfelder einer Anmeldung setzen (V-3): vorname, nachname, berufsgruppe,
 * beruf_sonstige, institution, efn (leer = NULL); mit $email auch die Adresse.
 * $sitzungNeu beendet offene Sitzungen der Anmeldung (neue Adresse).
 */
function anm_profil_setzen(int $id, array $w, ?string $email = null, bool $sitzungNeu = false): void
{
    $spalten = ['vorname' => $w['vorname'], 'nachname' => $w['nachname']];
    if ($email !== null) {
        $spalten['email'] = $email;
    }
    $spalten += ['berufsgruppe' => $w['berufsgruppe'], 'beruf_sonstige' => $w['beruf_sonstige'], 'institution' => $w['institution'],
        'efn' => ($w['efn'] ?? '') !== '' ? $w['efn'] : null];
    db_ausfuehren('UPDATE anmeldung SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($spalten)))
        . ', sitzung_version = sitzung_version + ?, geaendert_am = ? WHERE id = ?',
        [...array_values($spalten), $sitzungNeu ? 1 : 0, db_jetzt(), $id]);
}

/** Anmeldung eines Platzes (0 = gibt es nicht). */
function anm_id_von_platz(int $platzId): int
{
    return db_zahl('SELECT anmeldung_id FROM platz WHERE id = ?', [$platzId]);
}

/** Wahl aus den eigenen Plätzen vorbelegen (Workshops ändern): ['fest' => [wid => tid], 'warte' => [tid]]. */
function anm_wahl_aus_plaetzen(array $plaetze): array
{
    $wahl = ['fest' => [], 'warte' => []];
    foreach ($plaetze as $p) {
        if ((int)$p['termin_id'] === 0) {
            continue;
        }
        if ($p['status'] === 'fest') {
            $wahl['fest'][(int)$p['workshop_id']] = (int)$p['termin_id'];
        } elseif ($p['status'] === 'warte') {
            $wahl['warte'][] = (int)$p['termin_id'];
        }
    }
    return $wahl;
}

/**
 * Workshop-Wahl als HTML (V-27; Vorlage seiten/teil_workshopwahl.php):
 * $wahl vorbelegt, $bisher eigene Plätze ohne Termin 0, $team = Team-Bereich.
 */
function anm_workshopwahl_html(array $wahl, array $bisher, bool $team = false): string
{
    ob_start();
    require dirname(__DIR__) . '/seiten/teil_workshopwahl.php';
    return (string)ob_get_clean();
}

/** Einmal-Links gelten 48 Stunden und einmal (Admin 2026-10-29). */
const ANM_LINK_GUELTIG_S = 48 * 3600;
/** Hinweis, wenn die Adresse eines Team-Kontos über eine Anmeldung geändert werden soll (E-63 (2)). */
/** Sperre nach zu vielen Adressänderungen (Review S-2). */
const ANM_ADRESSE_RATE_TEXT = 'Zu viele Adressänderungen. Bitte warte eine Stunde.';
const ANM_TEAM_ADRESSE_TEXT = 'Die Adresse eines Team-Kontos lässt sich hier nicht ändern. Bitte wende dich an eine BetreiberIn.';

const ANM_ESSEN = [
    'egal' => 'Keine speziellen Wünsche',
    'vegetarisch' => 'Vegetarisch',
    'kein' => 'Kein Essen',
];

/* ---- Programm (veröffentlichter Stand) ----------------------------------- */

/**
 * Workshops aus dem veröffentlichten Programm mit ihren Terminen, nach dem
 * ersten Termin sortiert: [['id','kuerzel','titel','ort','termine' => [...]]].
 */
function anm_workshops(bool $neu = false): array
{
    static $cache = [];
    $va = va_id();
    if (isset($cache[$va]) && !$neu) {
        return $cache[$va];
    }
    $liste = [];
    foreach (programm_veroeffentlicht()['punkte'] ?? [] as $p) {
        if ($p['typ'] !== 'workshop' || $p['termine'] === []) {
            continue;
        }
        $termine = $p['termine'];
        usort($termine, fn($a, $b) => [$a['beginn'], $a['id']] <=> [$b['beginn'], $b['id']]);
        $liste[] = ['id' => (int)$p['id'], 'kuerzel' => $p['kuerzel'], 'titel' => $p['titel'], 'ort' => $p['ort'], 'termine' => $termine];
    }
    usort($liste, fn($a, $b) => [$a['termine'][0]['beginn'], $a['kuerzel'], $a['id']] <=> [$b['termine'][0]['beginn'], $b['kuerzel'], $b['id']]);
    return $cache[$va] = $liste;
}

/** Termin mit Workshop-Angaben oder null: ['id','beginn','ende','plaetze','workshop' => [...]]. */
function anm_termin(int $terminId): ?array
{
    foreach (anm_workshops() as $w) {
        foreach ($w['termine'] as $t) {
            if ((int)$t['id'] === $terminId) {
                return $t + ['workshop' => $w];
            }
        }
    }
    return null;
}

/** „WS 01 Atemweg. Plan B. · 19:30–20:15" bzw. „das Wintersymposium" */
function anm_termin_text(int $terminId): string
{
    if ($terminId === 0) {
        return 'Platz beim ' . va_kurz();
    }
    $t = anm_termin($terminId);
    if ($t === null) {
        return 'Workshop-Termin (nicht mehr im Programm)';
    }
    return trim($t['workshop']['kuerzel'] . ' ' . $t['workshop']['titel']) . ' · ' . $t['beginn'] . '–' . $t['ende'];
}

/* ---- Plätze zählen ------------------------------------------------------- */

/** Vergebene Plätze (fest und offene Angebote). Termin 0 = Symposium. */
function anm_belegt(int $terminId): int
{
    return db_zahl("SELECT COUNT(*) FROM platz WHERE veranstaltung_id = ? AND termin_id = ? AND status IN ('fest','angebot')", [va_id(), $terminId]);
}

function anm_wartend(int $terminId): int
{
    return db_zahl("SELECT COUNT(*) FROM platz WHERE veranstaltung_id = ? AND termin_id = ? AND status = 'warte'", [va_id(), $terminId]);
}

/** Plätze insgesamt; null = unbegrenzt (Gesamtkapazität 0). */
function anm_kapazitaet(int $terminId): ?int
{
    if ($terminId === 0) {
        $k = va_zahl('veranstaltung_kapazitaet');
        return $k > 0 ? $k : null;
    }
    $t = anm_termin($terminId);
    return $t === null ? 0 : (int)$t['plaetze'];
}

function anm_frei(int $terminId): int
{
    $k = anm_kapazitaet($terminId);
    return $k === null ? PHP_INT_MAX : max(0, $k - anm_belegt($terminId));
}

/** Platz auf der Warteliste: [position, gesamt]. */
function anm_position(array $platz): array
{
    $vor = db_zahl(
        "SELECT COUNT(*) FROM platz WHERE veranstaltung_id = ? AND termin_id = ? AND status = 'warte' AND (seit < ? OR (seit = ? AND id < ?))",
        [$platz['veranstaltung_id'], $platz['termin_id'], $platz['seit'], $platz['seit'], $platz['id']]
    );
    return [$vor + 1, anm_wartend((int)$platz['termin_id'])];
}

/* ---- Zeiträume und Stichtage (E-31) -------------------------------------- */

/** Beginn der Veranstaltung in UTC. */
function anm_beginn_utc(): string
{
    return (string)lokal_nach_utc(va('veranstaltung_datum') . ' ' . (va('veranstaltung_beginn') ?: '00:00'));
}

/** Anmeldeschluss in UTC (leer in den Einstellungen: Beginn der Veranstaltung). */
function anm_schluss_utc(): string
{
    return va('anmeldung_ende') !== '' ? va('anmeldung_ende') : anm_beginn_utc();
}

/** 'vorher', 'offen', 'vorbei' oder 'geschlossen' (keine Veranstaltung, Review B-10). */
function anm_zeitraum(?int $jetzt = null): string
{
    $jetzt ??= time();
    // Keine Veranstaltung (Review B-10): geschlossen
    if (va_keine()) {
        return 'geschlossen';
    }
    // Nur die aktuelle Veranstaltung nimmt Anmeldungen an (E-58)
    $status = va_zeile()['status'] ?? 'aktuell';
    if ($status !== 'aktuell') {
        return $status === 'abgeschlossen' ? 'vorbei' : 'vorher';
    }
    if ($jetzt < db_ts(va('anmeldung_start'))) {
        return 'vorher';
    }
    return $jetzt < db_ts(anm_schluss_utc()) ? 'offen' : 'vorbei';
}

/** Letzter Tag (JJJJ-MM-TT), an dem Teilnehmende das noch selbst ändern können. */
function anm_stichtag(string $schluessel): string
{
    $tag = va($schluessel);
    return $tag !== '' ? $tag : va('veranstaltung_datum');
}

function anm_heute(?int $jetzt = null): string
{
    return (new DateTimeImmutable('@' . ($jetzt ?? time())))->setTimezone(new DateTimeZone(ANZEIGE_ZEITZONE))->format('Y-m-d');
}

/** Ist die Änderung für Teilnehmende noch offen? stichtag_workshop, stichtag_essen, stichtag_storno */
function anm_frist_offen(string $schluessel, ?int $jetzt = null): bool
{
    return anm_heute($jetzt) <= anm_stichtag($schluessel);
}

/** Bis zu diesem Zeitpunkt (UTC) dürfen Nachrück-Angebote laufen (E-19). */
function anm_grenze_ts(): int
{
    $tag = (new DateTimeImmutable(va('veranstaltung_datum'), new DateTimeZone(ANZEIGE_ZEITZONE)))->modify('-' . va_zahl('nachrueck_grenze_tage') . ' days');
    return $tag->getTimestamp();
}

/* ---- Eingaben prüfen ----------------------------------------------------- */

/** Persönliche Angaben prüfen (ohne Workshop-Wahl). */
function anm_daten_pruefen(array $e): array
{
    $f = [];
    $s = fn(string $k) => is_string($e[$k] ?? null) ? trim(preg_replace('/\s+/u', ' ', $e[$k]) ?? '') : '';
    $w = [
        'vorname' => $s('vorname'), 'nachname' => $s('nachname'), 'email' => mb_strtolower($s('email')),
        'berufsgruppe' => $s('berufsgruppe'), 'beruf_sonstige' => $s('beruf_sonstige'), 'institution' => $s('institution'),
        'efn' => preg_replace('/[\s-]/', '', $s('efn')) ?? '', 'essen' => $s('essen'),
    ];
    foreach (['vorname' => 'Vorname', 'nachname' => 'Nachname'] as $k => $t) {
        if ($w[$k] === '') {
            $f[$k] = "Bitte gib deinen $t an.";
        } elseif (mb_strlen($w[$k]) > 80) {
            $f[$k] = "$t: höchstens 80 Zeichen.";
        }
    }
    if (mail_adresse($w['email']) === null) {
        $f['email'] = 'Diese E-Mail-Adresse sieht nicht wie eine Adresse aus.';
    }
    $gruppen = va_liste('berufsgruppen');
    if (!in_array($w['berufsgruppe'], $gruppen, true)) {
        $f['berufsgruppe'] = 'Bitte wähle eine Berufsgruppe.';
    }
    if (mb_strtolower($w['berufsgruppe']) !== 'sonstige') {
        $w['beruf_sonstige'] = '';
    } elseif (mb_strlen($w['beruf_sonstige']) > 80) {
        $f['beruf_sonstige'] = 'Höchstens 80 Zeichen.';
    }
    if ($w['institution'] === '') {
        $f['institution'] = 'Bitte gib deine Klinik oder Institution an.';
    } elseif (mb_strlen($w['institution']) > 160) {
        $f['institution'] = 'Höchstens 160 Zeichen.';
    }
    // Bausteine der Veranstaltung (E-58): ohne Essen kein Essen, ohne Fortbildungspunkte keine EFN
    if (!va_an('mit_essen')) {
        $w['essen'] = 'kein';
    }
    // EFN nur bei der ersten Berufsgruppe (ÄrztIn), freiwillig (E-22)
    if ($w['berufsgruppe'] !== ($gruppen[0] ?? null) || !va_an('mit_cme')) {
        $w['efn'] = '';
    } elseif ($w['efn'] !== '' && !efn_gueltig($w['efn'])) {
        $f['efn'] = 'Diese EFN stimmt nicht: 15 Ziffern, die letzte ist eine Prüfziffer. Lass das Feld leer, wenn du sie gerade nicht zur Hand hast.';
    }
    if (!isset(ANM_ESSEN[$w['essen']])) {
        $f['essen'] = 'Bitte wähle eine Möglichkeit zum Essen.';
    }
    return ['werte' => $w, 'fehler' => $f];
}

/** Workshop-Wahl aus dem Formular: ws[<workshop>] = <termin> (fester Platz), wl[] = <termin> (Warteliste). */
function anm_wahl_lesen(array $e): array
{
    $fest = [];
    foreach ((array)($e['ws'] ?? []) as $wid => $tid) {
        if (is_scalar($tid) && (int)$tid > 0) {
            $fest[(int)$wid] = (int)$tid;
        }
    }
    $warte = [];
    foreach ((array)($e['wl'] ?? []) as $tid) {
        if (is_scalar($tid) && (int)$tid > 0) {
            $warte[(int)$tid] = (int)$tid;
        }
    }
    return ['fest' => $fest, 'warte' => array_values($warte)];
}

function anm_ueberschneiden(array $a, array $b): bool
{
    return $a['beginn'] < $b['ende'] && $b['beginn'] < $a['ende'];
}

/**
 * Wahl prüfen (E-20, E-38): je Workshop ein fester Termin, feste Termine ohne
 * Überschneidung, neue feste Plätze nur mit freiem Platz, Warteliste nur für
 * volle Termine, Limit (Workshops mit Platz oder Warteliste) und Pflicht.
 * $bisher: Plätze, die die Person schon hat (bei Änderungen).
 */
function anm_wahl_pruefen(array $wahl, array $bisher = [], bool $team = false, bool $ueberbuchen = false): array
{
    // $team: Änderung durch das Team (AP-5) – ohne Limit und Pflicht, volle Termine nur mit $ueberbuchen
    $f = [];
    $eigenFest = [];
    $eigenWarte = [];
    foreach ($bisher as $p) {
        if ($p['status'] === 'fest') {
            $eigenFest[(int)$p['termin_id']] = true;
        } elseif ($p['status'] === 'warte') {
            $eigenWarte[(int)$p['termin_id']] = true;
        }
    }
    $feste = [];
    foreach ($wahl['fest'] as $wid => $tid) {
        $t = anm_termin($tid);
        if ($t === null || (int)$t['workshop']['id'] !== (int)$wid) {
            $f[] = 'Ein gewählter Termin ist nicht mehr im Programm. Bitte wähle neu.';
            continue;
        }
        if (empty($eigenFest[$tid]) && anm_frei($tid) <= 0) {
            if (!$team) {
                $f[] = anm_termin_text($tid) . ' ist inzwischen voll. Du kannst dich dafür auf die Warteliste setzen.';
            } elseif (!$ueberbuchen) {
                $f[] = anm_termin_text($tid) . ' ist voll (' . anm_belegt($tid) . ' / ' . anm_kapazitaet($tid) . '). Zum Überbuchen „Überbuchen bestätigen“ ankreuzen.';
            }
        }
        $feste[] = $t;
    }
    for ($i = 0; $i < count($feste); $i++) {
        for ($j = $i + 1; $j < count($feste); $j++) {
            if (anm_ueberschneiden($feste[$i], $feste[$j])) {
                $f[] = anm_termin_text((int)$feste[$i]['id']) . ' und ' . anm_termin_text((int)$feste[$j]['id']) . ' überschneiden sich. Wähle einen davon.';
            }
        }
    }
    $workshops = array_fill_keys(array_keys($wahl['fest']), true);
    foreach ($wahl['warte'] as $tid) {
        $t = anm_termin($tid);
        if ($t === null) {
            $f[] = 'Ein Termin auf deiner Warteliste ist nicht mehr im Programm.';
            continue;
        }
        if (($wahl['fest'][(int)$t['workshop']['id']] ?? 0) === $tid) {
            continue; // fester Platz und Warteliste für denselben Termin: der feste zählt
        }
        if (!$team && empty($eigenWarte[$tid]) && anm_frei($tid) > 0) {
            $f[] = anm_termin_text($tid) . ' hat noch freie Plätze – wähle ihn als festen Termin statt der Warteliste.';
        }
        $workshops[(int)$t['workshop']['id']] = true;
    }
    $limit = va_zahl('workshop_limit');
    if ($team) {
        return array_values(array_unique($f));
    }
    if ($limit > 0 && count($workshops) > $limit) {
        $f[] = "Du kannst höchstens $limit " . ($limit === 1 ? 'Workshop' : 'Workshops') . ' wählen (feste Plätze und Wartelisten zusammen).';
    }
    if (va('workshop_pflicht') === 'pflicht' && $workshops === [] && anm_workshops() !== []) {
        $f[] = 'Bitte wähle mindestens einen Workshop (oder setz dich auf eine Warteliste).';
    }
    return array_values(array_unique($f));
}

/* ---- Sperre -------------------------------------------------------------- */

/**
 * Alle Platzvergaben nacheinander (R-04): eine MySQL-Sperre, darin eine
 * Transaktion. Die Sperre wird vor der Transaktion geholt, damit die
 * Transaktion den Stand nach der vorigen Vergabe sieht.
 */
function anm_sperre(callable $fn): mixed
{
    if (db()->inTransaction()) {
        throw new LogicException('anm_sperre() nicht innerhalb einer Transaktion aufrufen.');
    }
    if (db_zahl("SELECT GET_LOCK('ws_platz', 20)") !== 1) {
        throw new RuntimeException('Platzsperre nicht erhalten.');
    }
    try {
        return db_transaktion($fn);
    } finally {
        db_wert("SELECT RELEASE_LOCK('ws_platz')");
    }
}

/* ---- Lesen --------------------------------------------------------------- */

function anm_laden(int $id): ?array
{
    return db_zeile('SELECT * FROM anmeldung WHERE id = ?', [$id]);
}

function anm_per_email(string $email): ?array
{
    return db_zeile('SELECT * FROM anmeldung WHERE veranstaltung_id = ? AND email = ?', [va_id(), mb_strtolower(trim($email))]);
}

/** Plätze einer Anmeldung (Symposium zuerst, dann nach Uhrzeit). */
function anm_plaetze(int $anmId): array
{
    $liste = db_zeilen('SELECT * FROM platz WHERE anmeldung_id = ? ORDER BY termin_id = 0 DESC, id', [$anmId]);
    usort($liste, function ($a, $b) {
        $ta = (int)$a['termin_id'] === 0 ? '' : (anm_termin((int)$a['termin_id'])['beginn'] ?? '99');
        $tb = (int)$b['termin_id'] === 0 ? '' : (anm_termin((int)$b['termin_id'])['beginn'] ?? '99');
        return [$ta, $a['id']] <=> [$tb, $b['id']];
    });
    return $liste;
}

/** Status beim Symposium: 'fest', 'warte', 'angebot' oder null. */
function anm_symposium_status(int $anmId): ?string
{
    $s = db_wert('SELECT status FROM platz WHERE anmeldung_id = ? AND termin_id = 0', [$anmId]);
    return $s === null ? null : (string)$s;
}

/** Ein Platz als Text: „WS 01 … · 19:30–20:15: Platz bestätigt". */
function anm_platz_zeile(array $p): array
{
    $text = anm_termin_text((int)$p['termin_id']);
    switch ($p['status']) {
        case 'fest':
            $st = 'Platz bestätigt';
            break;
        case 'angebot':
            $st = 'Platzangebot – annehmen bis ' . datum_zeit_text($p['angebot_bis']);
            break;
        default:
            [$pos, $von] = anm_position($p);
            $st = "Warteliste · Platz $pos von $von";
    }
    return ['text' => $text, 'status' => $st];
}

/** Übersicht als Text für Mails. */
function anm_uebersicht_text(int $anmId): string
{
    $a = anm_laden($anmId);
    if ($a === null) {
        return '';
    }
    $z = ['Deine Anmeldung (Nr. ' . anm_nummer((int)$a['id']) . '):', ''];
    foreach (anm_plaetze($anmId) as $p) {
        $r = anm_platz_zeile($p);
        $z[] = '- ' . $r['text'] . ': ' . $r['status'];
    }
    $z[] = '- Essen: ' . (ANM_ESSEN[$a['essen']] ?? $a['essen']);
    $z[] = '';
    $z[] = 'Datum: ' . tag_text(va('veranstaltung_datum')) . ', ' . va('veranstaltung_beginn') . '–' . va('veranstaltung_ende') . (va('veranstaltung_ort') !== '' ? ', ' . va('veranstaltung_ort') : '');
    return implode("\n", $z);
}

/* ---- Einmal-Links -------------------------------------------------------- */

function anm_link_erzeugen(int $anmId, string $zweck, int $bisTs, ?string $neueEmail = null): string
{
    $jetzt = time();
    return link_erzeugen('anmeldung', $anmId, $zweck, $bisTs - $jetzt, $neueEmail !== null ? ['neue_email' => $neueEmail] : [], $jetzt);
}

function anm_link_url(string $token): string
{
    return link_url('anmeldung', $token);
}

/** Gültigen Link finden (ohne ihn zu verbrauchen) oder null. */
function anm_link_finden(string $token, ?int $jetzt = null): ?array
{
    return link_finden('anmeldung', $token, null, $jetzt);
}

/** Frühere bestätigte Anmeldung dieser Adresse bei einer anderen Veranstaltung (Daten noch vorhanden) oder null. */
function anm_frueher_per_email(string $email): ?array
{
    return db_zeile(
        "SELECT * FROM anmeldung WHERE email = ? AND veranstaltung_id <> ? AND status = 'bestaetigt' ORDER BY id DESC LIMIT 1",
        [mb_strtolower(trim($email)), va_id()]
    );
}

/** Link „Daten übernehmen“ an Wiederkehrende ohne Konto (E-58): öffnet die Anmeldung mit den früheren Angaben. */
function anm_uebernehmen_senden(int $alteAnmId, ?int $jetzt = null): void
{
    $a = anm_laden($alteAnmId);
    if ($a === null) {
        return;
    }
    $bis = ($jetzt ?? time()) + ANM_LINK_GUELTIG_S;
    $token = anm_link_erzeugen($alteAnmId, 'uebernehmen', $bis);
    mail_vorlage_einreihen($a['email'], 'uebernehmen', ['vorname' => $a['vorname'], 'link' => anm_link_url($token), 'gueltig' => datum_zeit_text(db_zeit($bis))]);
}

/** Wie lange die Vorlage „Daten übernehmen“ in der Sitzung gilt (Minuten, Review B-13). */
const ANM_VORLAGE_MIN = 30;

/**
 * Vorlage fürs Anmeldeformular aus einer früheren Anmeldung (nach dem Link
 * „Daten übernehmen“) oder null. Der Sitzungseintrag gilt 30 Minuten; danach
 * und nach dem Absenden (anmelden.php) ist er weg.
 */
function anm_vorlage(?int $jetzt = null): ?array
{
    $s = $_SESSION['anm_vorlage'] ?? null;
    if (!is_array($s) || !isset($s['id'], $s['zeit']) || (int)$s['zeit'] < ($jetzt ?? time()) - ANM_VORLAGE_MIN * 60) {
        unset($_SESSION['anm_vorlage']);
        return null;
    }
    $a = anm_laden((int)$s['id']);
    if ($a === null) {
        return null;
    }
    return ['vorname' => $a['vorname'], 'nachname' => $a['nachname'], 'email' => $a['email'], 'berufsgruppe' => $a['berufsgruppe'],
        'beruf_sonstige' => $a['beruf_sonstige'], 'institution' => $a['institution'], 'efn' => (string)$a['efn'], 'essen' => $a['essen']];
}

/** Zugangslink per Mail (Einstieg „Link per Mail“ und bei doppelter Anmeldung). */
function anm_zugang_senden(int $anmId, string $vorlage = 'zugang', ?int $jetzt = null): void
{
    $a = anm_laden($anmId);
    if ($a === null) {
        return;
    }
    $bis = ($jetzt ?? time()) + ANM_LINK_GUELTIG_S;
    $token = anm_link_erzeugen($anmId, 'zugang', $bis);
    mail_vorlage_einreihen($a['email'], $vorlage, ['vorname' => $a['vorname'], 'link' => anm_link_url($token), 'gueltig' => datum_zeit_text(db_zeit($bis))]);
}

/**
 * Link einlösen (einmal). Gibt ['anm' => id, 'zweck' => …, 'meldung' => …]
 * oder null (unbekannt, abgelaufen, benutzt) zurück.
 */
function anm_link_einloesen(string $token, ?int $jetzt = null): ?array
{
    // Im Kontext der Veranstaltung, zu der die Anmeldung gehört (E-58)
    $l = anm_link_finden($token, $jetzt);
    $va = $l !== null ? db_wert('SELECT veranstaltung_id FROM anmeldung WHERE id = ?', [$l['anmeldung_id']]) : null;
    if ($va !== null && (int)$va !== va_id()) {
        return va_mit((int)$va, fn() => anm_link_einloesen($token, $jetzt));
    }
    $jetzt ??= time();
    return anm_sperre(function () use ($token, $jetzt): ?array {
        $l = anm_link_finden($token, $jetzt);
        if ($l === null) {
            return null;
        }
        $l = link_sperren((int)$l['id']);
        if ($l === null) {
            return null;
        }
        link_verbrauchen((int)$l['id']);
        $anmId = (int)$l['anmeldung_id'];
        $a = anm_laden($anmId);
        if ($a === null) {
            return null;
        }
        $meldung = null;
        if ($l['zweck'] === 'bestaetigen' && $a['status'] === 'unbestaetigt') {
            db_ausfuehren("UPDATE anmeldung SET status = 'bestaetigt', bestaetigt_am = ?, bestaetigen_bis = NULL, geaendert_am = ? WHERE id = ?", [db_zeit($jetzt), db_jetzt(), $anmId]);
            link_loeschen('anmeldung', $anmId, 'bestaetigen');
            audit_schreiben('anmeldung', audit_text('anmeldung', $anmId, 'bestätigt'), null, ['anmeldung' => $anmId]);
            // Wer jetzt bestätigt ist, kann Angebote für seine Wartelisten bekommen
            foreach (db_zeilen("SELECT termin_id FROM platz WHERE anmeldung_id = ? AND status = 'warte'", [$anmId]) as $p) {
                anm_nachruecken((int)$p['termin_id'], $jetzt);
            }
            mail_vorlage_einreihen($a['email'], 'bestaetigt', ['vorname' => $a['vorname'], 'uebersicht' => anm_uebersicht_text($anmId)]);
            // Häkchen „Newsletter“: eigene Bestätigungsmail (AP-7, E-52)
            nl_aus_anmeldung($anmId);
            $meldung = 'Danke, deine Anmeldung ist bestätigt.';
            // Konto: gewünscht beim Anmelden → anlegen; vorhandenes bestätigtes Konto → verknüpfen (E-48)
            $k = konto_per_email($a['email']);
            if ((int)$a['konto_wunsch'] === 1) {
                $kontoNeu = tk_anlegen_aus_anmeldung($anmId);
            } elseif ($k !== null && ($k['email_bestaetigt_am'] !== null || konto_ist_team($k))) {
                // ein Konto für alles (E-54): auch Team-Konten
                tk_verknuepfen((int)$k['id']);
            }
        } elseif ($l['zweck'] === 'email') {
            $neu = (string)$l['neue_email'];
            $andererKonto = konto_per_email($neu);
            if (anm_hat_team_konto($a)) {
                // zweite Sperre: ein Team-Konto ändert seine Adresse nie über die Anmeldung (E-63 (2))
                $meldung = ANM_TEAM_ADRESSE_TEXT . ' Deine Adresse bleibt, wie sie war.';
            } elseif (db_gibt_es('SELECT COUNT(*) FROM anmeldung WHERE veranstaltung_id = ? AND email = ? AND id <> ?', [$a['veranstaltung_id'], $neu, $anmId])
                || ($a['konto_id'] !== null && $andererKonto !== null && (int)$andererKonto['id'] !== (int)$a['konto_id'])) {
                $meldung = 'Diese Adresse gehört schon zu einer anderen Anmeldung. Deine Adresse bleibt, wie sie war.';
            } else {
                db_ausfuehren('UPDATE anmeldung SET email = ?, geaendert_am = ? WHERE id = ?', [$neu, db_jetzt(), $anmId]);
                if ($a['konto_id'] !== null) {
                    db_ausfuehren('UPDATE konto SET email = ?, geaendert_am = ? WHERE id = ?', [$neu, db_jetzt(), $a['konto_id']]);
                }
                audit_schreiben('anmeldung', audit_text('anmeldung', $anmId, 'neue E-Mail-Adresse bestätigt'), null, ['anmeldung' => $anmId]);
                $meldung = 'Deine neue Adresse gilt ab jetzt.';
            }
        }
        return ['anm' => $anmId, 'zweck' => $l['zweck'], 'meldung' => $meldung, 'konto_neu' => $kontoNeu ?? null];
    });
}

/* ---- Anmelden ------------------------------------------------------------ */

/**
 * Neue Anmeldung anlegen. Gibt zurück:
 *   ['art' => 'neu', 'id' => …, 'warteliste' => bool]
 *   ['art' => 'vorhanden', 'id' => …]   (Adresse schon angemeldet, E-46)
 *   ['art' => 'fehler', 'fehler' => [...]] (Wahl nicht mehr möglich, z. B. Termin voll)
 */
function anm_anlegen(array $w, array $wahl, bool $newsletter, ?int $jetzt = null, bool $kontoWunsch = false, ?array $konto = null): array
{
    $jetzt ??= time();
    if (va_keine()) {
        return ['art' => 'fehler', 'fehler' => ['_' => 'Derzeit gibt es keine Veranstaltung, zu der du dich anmelden kannst.']]; // Review B-10
    }
    // Eingeloggt mit bestätigtem Konto und gleicher Adresse: keine zweite Bestätigung nötig (AP-4b)
    // Team-Konten gelten als bestätigt (Adresse über die Einladung bewiesen, ein Konto für alles, E-54)
    $vomKonto = $konto !== null && ($konto['email_bestaetigt_am'] !== null || konto_ist_team($konto)) && $konto['email'] === $w['email'];
    return anm_sperre(function () use ($w, $wahl, $newsletter, $jetzt, $kontoWunsch, $konto, $vomKonto): array {
        $vorhanden = anm_per_email($w['email']);
        if ($vorhanden !== null) {
            return ['art' => 'vorhanden', 'id' => (int)$vorhanden['id']];
        }
        $warteliste = anm_frei(0) <= 0;
        if ($warteliste) {
            // Auf der Warteliste des Symposiums noch keine Workshops (Admin 2026-10-29)
            $wahl = ['fest' => [], 'warte' => []];
        } else {
            $f = anm_wahl_pruefen($wahl);
            if ($f !== []) {
                return ['art' => 'fehler', 'fehler' => $f];
            }
        }
        $jetztDb = db_zeit($jetzt);
        $bis = $jetzt + va_zahl('anmeldung_bestaetigung_h') * 3600;
        db_ausfuehren(
            "INSERT INTO anmeldung (veranstaltung_id, konto_id, vorname, nachname, email, berufsgruppe, beruf_sonstige, institution, efn, essen, newsletter, konto_wunsch, status, bestaetigen_bis, einwilligung_am, erstellt_am, bestaetigt_am, geaendert_am)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [va_id(), $vomKonto ? (int)$konto['id'] : null, $w['vorname'], $w['nachname'], $w['email'], $w['berufsgruppe'], $w['beruf_sonstige'], $w['institution'], $w['efn'] !== '' ? $w['efn'] : null,
                $w['essen'], $newsletter ? 1 : 0, $kontoWunsch && !$vomKonto ? 1 : 0, $vomKonto ? 'bestaetigt' : 'unbestaetigt', $vomKonto ? null : db_zeit($bis), $jetztDb, $jetztDb, $vomKonto ? $jetztDb : null, $jetztDb]
        );
        $id = (int)db()->lastInsertId();
        anm_platz_anlegen($id, 0, $warteliste ? 'warte' : 'fest', $jetzt);
        foreach ($wahl['fest'] as $tid) {
            anm_platz_anlegen($id, $tid, 'fest', $jetzt);
        }
        foreach ($wahl['warte'] as $tid) {
            if (!in_array($tid, $wahl['fest'], true)) {
                anm_platz_anlegen($id, $tid, 'warte', $jetzt);
            }
        }
        if ($vomKonto) {
            mail_vorlage_einreihen($w['email'], 'bestaetigt', ['vorname' => $w['vorname'], 'uebersicht' => anm_uebersicht_text($id)]);
            nl_aus_anmeldung($id);
        } else {
            $token = anm_link_erzeugen($id, 'bestaetigen', $bis);
            mail_vorlage_einreihen($w['email'], 'bestaetigen', [
                'vorname' => $w['vorname'], 'link' => anm_link_url($token), 'gueltig' => datum_zeit_text(db_zeit($bis)),
                'uebersicht' => anm_uebersicht_text($id),
            ]);
        }
        audit_schreiben('anmeldung', audit_text('anmeldung', $id, 'angelegt' . ($warteliste ? ' (Warteliste)' : '')), null, ['anmeldung' => $id]);
        return ['art' => 'neu', 'id' => $id, 'warteliste' => $warteliste, 'bestaetigt' => $vomKonto];
    });
}

function anm_platz_anlegen(int $anmId, int $terminId, string $status, int $jetzt): void
{
    $wid = $terminId === 0 ? 0 : (int)(anm_termin($terminId)['workshop']['id'] ?? 0);
    db_ausfuehren(
        'INSERT INTO platz (anmeldung_id, veranstaltung_id, termin_id, workshop_id, status, seit) VALUES (?, (SELECT veranstaltung_id FROM anmeldung WHERE id = ?), ?, ?, ?, ?)',
        [$anmId, $anmId, $terminId, $wid, $status, db_zeit($jetzt)]
    );
}

/* ---- Ändern -------------------------------------------------------------- */

/** Workshops neu setzen. Gibt eine Fehlerliste zurück (leer = gespeichert). */
function anm_workshops_setzen(int $anmId, array $wahl, ?int $jetzt = null, bool $team = false, bool $ueberbuchen = false, bool $mail = true): array
{
    $jetzt ??= time();
    return anm_sperre(function () use ($anmId, $wahl, $jetzt, $team, $ueberbuchen, $mail): array {
        if (anm_symposium_status($anmId) !== 'fest') {
            return ['Workshops kannst du wählen, sobald du einen Platz beim Symposium hast.'];
        }
        $bisher = array_values(array_filter(anm_plaetze($anmId), fn($p) => (int)$p['termin_id'] !== 0));
        $f = anm_wahl_pruefen($wahl, $bisher, $team, $ueberbuchen);
        if ($f !== []) {
            return $f;
        }
        $frei = [];
        $sollFest = array_values($wahl['fest']);
        $sollWarte = array_values(array_diff($wahl['warte'], $sollFest));
        foreach ($bisher as $p) {
            $tid = (int)$p['termin_id'];
            if ($p['status'] === 'angebot') {
                continue; // offene Angebote bleiben, bis sie angenommen, abgelehnt oder abgelaufen sind
            }
            $behalten = $p['status'] === 'fest' ? in_array($tid, $sollFest, true) : in_array($tid, $sollWarte, true);
            if (!$behalten) {
                db_ausfuehren('DELETE FROM platz WHERE id = ?', [$p['id']]);
                if ($p['status'] === 'fest') {
                    $frei[] = $tid;
                }
            }
        }
        $hat = [];
        foreach (db_zeilen('SELECT termin_id, status FROM platz WHERE anmeldung_id = ?', [$anmId]) as $p) {
            $hat[(int)$p['termin_id']] = $p['status'];
        }
        foreach ($sollFest as $tid) {
            if (($hat[$tid] ?? null) === 'warte') {
                db_ausfuehren("UPDATE platz SET status = 'fest', seit = ? WHERE anmeldung_id = ? AND termin_id = ?", [db_zeit($jetzt), $anmId, $tid]);
            } elseif (!isset($hat[$tid])) {
                anm_platz_anlegen($anmId, $tid, 'fest', $jetzt);
            }
        }
        foreach ($sollWarte as $tid) {
            if (!isset($hat[$tid])) {
                anm_platz_anlegen($anmId, $tid, 'warte', $jetzt);
            }
        }
        foreach (array_unique($frei) as $tid) {
            anm_nachruecken($tid, $jetzt);
        }
        anm_geaendert_melden($anmId, 'deine Workshops sind geändert.', $mail, $team);
        return [];
    });
}

function anm_geaendert_melden(int $anmId, string $was, bool $mail = true, bool $team = false): void
{
    $a = anm_laden($anmId);
    db_ausfuehren('UPDATE anmeldung SET geaendert_am = ? WHERE id = ?', [db_jetzt(), $anmId]);
    audit_schreiben('anmeldung', audit_text('anmeldung', $anmId, 'geändert' . ($team ? ' (Team' . ($mail ? '' : ', ohne Mail') . ')' : '')), $team ? anm_team_konto() : null, ['anmeldung' => $anmId]);
    if ($mail && $a !== null && $a['status'] === 'bestaetigt') {
        // „vom Team geändert“ (Admin 2026-10-29)
        $was = $team ? 'das Team hat deine Anmeldung geändert: ' . $was : $was;
        mail_vorlage_einreihen($a['email'], 'geaendert', ['vorname' => $a['vorname'], 'was' => $was, 'uebersicht' => anm_uebersicht_text($anmId)]);
    }
}

function anm_essen_setzen(int $anmId, string $essen, bool $team = false, bool $mail = true): bool
{
    if (!isset(ANM_ESSEN[$essen])) {
        return false;
    }
    db_ausfuehren('UPDATE anmeldung SET essen = ? WHERE id = ?', [$essen, $anmId]);
    anm_geaendert_melden($anmId, 'deine Essenswahl ist geändert: ' . ANM_ESSEN[$essen] . '.', $mail, $team);
    return true;
}

/**
 * Persönliche Angaben ändern. Eine neue Adresse gilt erst nach Bestätigung
 * (Link an die neue Adresse). Gibt ['fehler' => [...], 'email_link' => bool, 'email_gesperrt' => bool] zurück
 * (gesperrt: zu viele Adressänderungen, übrige Daten sind gespeichert).
 */
function anm_daten_setzen(int $anmId, array $e, ?int $jetzt = null): array
{
    $a = anm_laden($anmId);
    if ($a === null) {
        return ['fehler' => ['Die Anmeldung gibt es nicht mehr.'], 'email_link' => false, 'email_gesperrt' => false];
    }
    $r = anm_daten_pruefen($e + ['essen' => $a['essen']]);
    if ($r['fehler'] !== []) {
        return ['fehler' => $r['fehler'], 'email_link' => false, 'email_gesperrt' => false];
    }
    $w = $r['werte'];
    // Login-Adresse eines Team-Kontos nicht über die Anmeldung (E-63 (2), Review S-1)
    if ($w['email'] !== $a['email'] && anm_hat_team_konto($a)) {
        return ['fehler' => ['email' => ANM_TEAM_ADRESSE_TEXT], 'email_link' => false, 'email_gesperrt' => false];
    }
    anm_profil_setzen($anmId, $w);
    audit_schreiben('anmeldung', audit_text('anmeldung', $anmId, 'Daten geändert'), null, ['anmeldung' => $anmId]);
    $link = false;
    if ($w['email'] !== $a['email']) {
        if (!adresse_aendern_erlaubt($w['email'])) {
            // Daten ohne Adresse sind gespeichert, die Adresse bleibt (Review S-2)
            return ['fehler' => [], 'email_link' => false, 'email_gesperrt' => true];
        }
        // Gleiche Antwort, ob die Adresse schon vergeben ist oder nicht; geprüft wird beim Bestätigen.
        // Nur ein offener Link je Anmeldung: ältere verfallen.
        $bis = ($jetzt ?? time()) + ANM_LINK_GUELTIG_S;
        link_loeschen('anmeldung', $anmId, 'email');
        $token = anm_link_erzeugen($anmId, 'email', $bis, $w['email']);
        mail_vorlage_einreihen($w['email'], 'email_bestaetigen', ['link' => anm_link_url($token), 'gueltig' => datum_zeit_text(db_zeit($bis))]);
        $link = true;
    }
    return ['fehler' => [], 'email_link' => $link, 'email_gesperrt' => false];
}

/**
 * Ratenschutz für Mails an eine neue Adresse (Anmeldung und Konto, Review S-2):
 * wie „Link per Mail“ je IP und je Zieladresse; jeder Versand zählt. Gibt
 * false zurück, wenn gesperrt (dann keine Mail).
 */
function adresse_aendern_erlaubt(string $ziel): bool
{
    $ip = rate_merkmal_ip();
    $zielM = rate_merkmal_kennung('link:' . $ziel);
    if (!rate_erlaubt('link_ip', $ip) || !rate_erlaubt('link_ziel', $zielM)) {
        return false;
    }
    rate_misserfolg('link_ip', $ip);
    rate_misserfolg('link_ziel', $zielM);
    return true;
}

/** Ist die Anmeldung mit einem Team-Konto (admin, betreiber …) verknüpft? */
function anm_hat_team_konto(array $a): bool
{
    return $a['konto_id'] !== null && konto_ist_team(konto_laden((int)$a['konto_id']));
}

/** Anmeldung stornieren: Zeile löschen (R-02), Plätze gehen ans Nachrücken. */
function anm_stornieren(int $anmId, ?int $jetzt = null, bool $mail = true): bool
{
    $jetzt ??= time();
    return anm_sperre(function () use ($anmId, $jetzt, $mail): bool {
        $a = db_zeile('SELECT * FROM anmeldung WHERE id = ? FOR UPDATE', [$anmId]);
        if ($a === null) {
            return false;
        }
        $frei = array_map('intval', array_column(db_zeilen("SELECT termin_id FROM platz WHERE anmeldung_id = ? AND status IN ('fest','angebot')", [$anmId]), 'termin_id'));
        if ($mail && $a['status'] === 'bestaetigt') {
            mail_vorlage_einreihen($a['email'], 'storniert', ['vorname' => $a['vorname']]);
        }
        db_ausfuehren('DELETE FROM anmeldung WHERE id = ?', [$anmId]);
        audit_schreiben('anmeldung', audit_text('anmeldung', $anmId, 'storniert'), null, ['anmeldung' => $anmId]);
        foreach ($frei as $tid) {
            anm_nachruecken($tid, $jetzt);
        }
        return true;
    });
}

/* ---- Nachrücken (E-19, E-38, E-42) --------------------------------------- */

/** Feste Plätze, die beim Annehmen eines Angebots wegfallen (gleicher Workshop oder Überschneidung). */
function anm_tausch(int $anmId, array $angebot): array
{
    $tid = (int)$angebot['termin_id'];
    if ($tid === 0) {
        return [];
    }
    $t = anm_termin($tid);
    if ($t === null) {
        return [];
    }
    $weg = [];
    foreach (db_zeilen("SELECT * FROM platz WHERE anmeldung_id = ? AND status = 'fest' AND termin_id <> 0 AND termin_id <> ?", [$anmId, $tid]) as $p) {
        $pt = anm_termin((int)$p['termin_id']);
        if ($pt === null) {
            continue;
        }
        if ((int)$pt['workshop']['id'] === (int)$t['workshop']['id']) {
            $weg[] = $p + ['grund' => 'gleicher Workshop'];
        } elseif (anm_ueberschneiden($pt, $t)) {
            $weg[] = $p + ['grund' => 'überschneidet sich zeitlich'];
        }
    }
    return $weg;
}

/**
 * Freie Plätze eines Termins der Reihe nach anbieten (nur bestätigten
 * Anmeldungen; bei Workshops nur Personen mit Platz beim Symposium). Nach der
 * Grenze keine automatischen Angebote (E-19). Muss in anm_sperre() laufen.
 */
function anm_nachruecken(int $terminId, ?int $jetzt = null): int
{
    $jetzt ??= time();
    $fristTs = $jetzt + va_zahl('nachrueck_frist_h') * 3600;
    if ($fristTs > anm_grenze_ts()) {
        return 0;
    }
    if ($terminId !== 0 && anm_termin($terminId) === null) {
        return 0;
    }
    $n = 0;
    while (anm_frei($terminId) > 0) {
        $p = db_zeile(
            "SELECT p.* FROM platz p JOIN anmeldung a ON a.id = p.anmeldung_id
             WHERE p.veranstaltung_id = ? AND p.termin_id = ? AND p.status = 'warte' AND a.status = 'bestaetigt'
               AND (p.termin_id = 0 OR EXISTS (SELECT 1 FROM platz s WHERE s.anmeldung_id = p.anmeldung_id AND s.termin_id = 0 AND s.status = 'fest'))
             ORDER BY p.seit, p.id LIMIT 1 FOR UPDATE",
            [va_id(), $terminId]
        );
        if ($p === null) {
            break;
        }
        db_ausfuehren("UPDATE platz SET status = 'angebot', angebot_bis = ? WHERE id = ?", [db_zeit($fristTs), $p['id']]);
        $a = anm_laden((int)$p['anmeldung_id']);
        $tausch = anm_tausch((int)$p['anmeldung_id'], $p);
        $token = anm_link_erzeugen((int)$p['anmeldung_id'], 'zugang', max($fristTs, $jetzt + ANM_LINK_GUELTIG_S));
        mail_vorlage_einreihen($a['email'], 'angebot', [
            'vorname' => $a['vorname'], 'was' => anm_termin_text($terminId), 'frist' => datum_zeit_text(db_zeit($fristTs)),
            'link' => anm_link_url($token),
            'tausch' => $tausch === [] ? '' : 'Nimmst du an, fällt dafür weg: ' . implode('; ', array_map(fn($x) => anm_termin_text((int)$x['termin_id']), $tausch)) . '.',
        ]);
        audit_schreiben('nachruecken', audit_text('anmeldung', (int)$p['anmeldung_id'], 'Angebot für Termin ' . $terminId), null, ['anmeldung' => (int)$p['anmeldung_id']]);
        $n++;
    }
    return $n;
}

/** Offene Angebote einer Anmeldung. */
function anm_angebote(int $anmId): array
{
    return db_zeilen("SELECT * FROM platz WHERE anmeldung_id = ? AND status = 'angebot' ORDER BY angebot_bis", [$anmId]);
}

/**
 * Angebot annehmen. Gibt zurück: ['ok' => true] | ['tausch' => [...]]
 * (Bestätigung nötig, E-38/E-42) | ['fehler' => '…'].
 */
function anm_angebot_annehmen(int $anmId, int $platzId, bool $tauschBestaetigt, ?int $jetzt = null): array
{
    $jetzt ??= time();
    return anm_sperre(function () use ($anmId, $platzId, $tauschBestaetigt, $jetzt): array {
        $p = db_zeile("SELECT * FROM platz WHERE id = ? AND anmeldung_id = ? AND status = 'angebot' FOR UPDATE", [$platzId, $anmId]);
        if ($p === null || db_ts($p['angebot_bis']) < $jetzt) {
            return ['fehler' => 'Dieses Angebot gilt nicht mehr.'];
        }
        $tausch = anm_tausch($anmId, $p);
        if ($tausch !== [] && !$tauschBestaetigt) {
            return ['tausch' => $tausch];
        }
        foreach ($tausch as $x) {
            db_ausfuehren('DELETE FROM platz WHERE id = ?', [$x['id']]);
        }
        db_ausfuehren("UPDATE platz SET status = 'fest', angebot_bis = NULL, seit = ? WHERE id = ?", [db_zeit($jetzt), $platzId]);
        foreach ($tausch as $x) {
            anm_nachruecken((int)$x['termin_id'], $jetzt);
        }
        $a = anm_laden($anmId);
        mail_vorlage_einreihen($a['email'], 'platz_erhalten', ['vorname' => $a['vorname'], 'was' => anm_termin_text((int)$p['termin_id']), 'uebersicht' => anm_uebersicht_text($anmId)]);
        audit_schreiben('nachruecken', audit_text('anmeldung', $anmId, 'Angebot für Termin ' . $p['termin_id'] . ' angenommen'), null, ['anmeldung' => $anmId]);
        return ['ok' => true];
    });
}

/** Angebot ablehnen: von der Warteliste nehmen, der Platz geht weiter. */
function anm_angebot_ablehnen(int $anmId, int $platzId, ?int $jetzt = null): bool
{
    $jetzt ??= time();
    return anm_sperre(function () use ($anmId, $platzId, $jetzt): bool {
        $p = db_zeile("SELECT * FROM platz WHERE id = ? AND anmeldung_id = ? AND status = 'angebot' FOR UPDATE", [$platzId, $anmId]);
        if ($p === null) {
            return false;
        }
        db_ausfuehren('DELETE FROM platz WHERE id = ?', [$platzId]);
        if ((int)$p['termin_id'] === 0) {
            // Symposium abgelehnt: dann ist die ganze Anmeldung hinfällig
            db_ausfuehren('DELETE FROM anmeldung WHERE id = ?', [$anmId]);
        }
        audit_schreiben('nachruecken', audit_text('anmeldung', $anmId, 'Angebot für Termin ' . $p['termin_id'] . ' abgelehnt'), null, ['anmeldung' => $anmId]);
        anm_nachruecken((int)$p['termin_id'], $jetzt);
        return true;
    });
}

/* ---- Job ----------------------------------------------------------------- */

/**
 * Regelmäßig: unbestätigte Anmeldungen nach der Frist löschen, abgelaufene
 * Angebote weitergeben (Person ans Ende der Liste, E-19), freie Plätze
 * anbieten, alte Links löschen.
 */
function anm_job(int $jetzt): array
{
    $erg = ['verfallen' => 0, 'abgelaufen' => 0, 'angebote' => 0, 'links' => 0];
    $jetztDb = db_zeit($jetzt);
    foreach (db_zeilen("SELECT id FROM anmeldung WHERE veranstaltung_id = ? AND status = 'unbestaetigt' AND bestaetigen_bis < ?", [va_id(), $jetztDb]) as $z) {
        if (anm_stornieren((int)$z['id'], $jetzt, false)) {
            $erg['verfallen']++;
        }
    }
    foreach (db_zeilen("SELECT id FROM platz WHERE veranstaltung_id = ? AND status = 'angebot' AND angebot_bis < ?", [va_id(), $jetztDb]) as $z) {
        $erg['abgelaufen'] += anm_sperre(function () use ($z, $jetzt, $jetztDb): int {
            $p = db_zeile("SELECT * FROM platz WHERE id = ? AND status = 'angebot' AND angebot_bis < ? FOR UPDATE", [$z['id'], $jetztDb]);
            if ($p === null) {
                return 0;
            }
            db_ausfuehren("UPDATE platz SET status = 'warte', angebot_bis = NULL, seit = ? WHERE id = ?", [$jetztDb, $p['id']]);
            $a = anm_laden((int)$p['anmeldung_id']);
            mail_vorlage_einreihen($a['email'], 'angebot_abgelaufen', ['vorname' => $a['vorname'], 'was' => anm_termin_text((int)$p['termin_id']), 'uebersicht' => anm_uebersicht_text((int)$p['anmeldung_id'])]);
            audit_schreiben('nachruecken', audit_text('anmeldung', (int)$p['anmeldung_id'], 'Angebot für Termin ' . $p['termin_id'] . ' abgelaufen'), null, ['anmeldung' => (int)$p['anmeldung_id']]);
            anm_nachruecken((int)$p['termin_id'], $jetzt);
            return 1;
        });
    }
    // Freie Plätze mit Warteliste (z. B. nach mehr Kapazität in den Einstellungen)
    $termine = array_merge([0], array_merge(...array_map(fn($w) => array_map(fn($t) => (int)$t['id'], $w['termine']), anm_workshops(true) ?: [['termine' => []]])));
    foreach ($termine as $tid) {
        if (anm_wartend($tid) > 0 && anm_frei($tid) > 0) {
            $erg['angebote'] += anm_sperre(fn() => anm_nachruecken($tid, $jetzt));
        }
    }
    $erg['links'] = link_aufraeumen($jetzt, 'anmeldung');
    return $erg;
}

/* ---- Sitzung der Teilnehmenden (Einmal-Link) ----------------------------- */

function anm_sitzung(): void
{
    team_gesehen();
    sitzung_starten('link');
}

/**
 * Anmeldung in der Sitzung merken, mit ihrer Veranstaltung (Review B-4).
 * $link: über einen Einmal-Link geöffnet (bewusst gewählt, bleibt auch in
 * einer anderen Veranstaltung, E-58); sonst aus Konto oder Team-Adresse abgeleitet.
 */
function anm_merken(array $a, bool $link): void
{
    $_SESSION['anm'] = ['id' => (int)$a['id'], 'version' => (int)$a['sitzung_version'], 'va' => (int)$a['veranstaltung_id'], 'link' => $link];
}

/** Nach einem gültigen Link: diese Anmeldung in der Sitzung merken. */
function anm_einloggen(int $anmId): void
{
    $a = anm_laden($anmId);
    if ($a === null) {
        return;
    }
    sitzung_erneuern();
    anm_merken($a, true);
}

/**
 * Eigene Anmeldung eines Team-Kontos in der Veranstaltung des Kontexts: die
 * verknüpfte oder eine bestätigte mit der Login-Adresse. Unbestätigte mit der
 * Team-Adresse kann jede anlegen, sie gelten nicht als eigene (Review S-3).
 */
function anm_eigene_team(array $konto): ?array
{
    $a = tk_anmeldung((int)$konto['id']);
    if ($a !== null) {
        return $a;
    }
    $a = anm_per_email((string)$konto['email']);
    return $a !== null && $a['status'] === 'bestaetigt' ? $a : null;
}

/**
 * Die Anmeldung aus Konto oder Team-Login ableiten nur auf öffentlichen Seiten
 * der aktuellen Veranstaltung: Team-Seiten und Archivseiten setzen einen
 * Kontext und würden sonst eine Anmeldung einer anderen Veranstaltung in die
 * Sitzung legen (Review B-4).
 */
function anm_ableiten_erlaubt(): bool
{
    return !isset($GLOBALS['ws_va_id']) && va_aktuell_id() !== null;
}

/** Die Anmeldung dieser Sitzung oder null (gelöscht oder Sitzung beendet). */
function anm_aktuell(): ?array
{
    // Abgeleitet in einer anderen Veranstaltung: verwerfen und neu ableiten (Review B-4).
    // Einträge aus einem Einmal-Link bleiben (Zertifikat-Mail einer vergangenen Veranstaltung, E-58).
    $s = $_SESSION['anm'] ?? null;
    if (is_array($s) && isset($s['va']) && empty($s['link']) && (int)$s['va'] !== va_id()) {
        unset($_SESSION['anm']);
    }
    if (!isset($_SESSION['anm']) && anm_ableiten_erlaubt()) {
        // Mit Konto eingeloggt: die verknüpfte Anmeldung (AP-4b)
        $a = ($k = tk_aktuell()) !== null ? tk_anmeldung((int)$k['id']) : null;
        // Team-Mitglied mit eigener Anmeldung (verknüpft oder bestätigt mit gleicher Adresse, S-3)
        if ($a === null && ($tk = team_gesehen()) !== null) {
            $a = anm_eigene_team($tk);
        }
        if ($a !== null) {
            anm_merken($a, false);
        }
    }
    $s = $_SESSION['anm'] ?? null;
    if (!is_array($s) || !isset($s['id'], $s['version'])) {
        return null;
    }
    $a = anm_laden((int)$s['id']);
    if ($a === null || (int)$a['sitzung_version'] !== (int)$s['version']) {
        unset($_SESSION['anm']);
        return null;
    }
    return $a;
}

function anm_ausloggen(): void
{
    tk_ausloggen(); // eine Sitzung für alles (E-54)
}

/** Für die Navigation auf öffentlichen Seiten: Anmeldung nur lesen, wenn schon eine Sitzung besteht. */
function anm_aus_keks(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (!isset($_COOKIE[SITZUNG_ARTEN['link']['name']])) {
            return null;
        }
        anm_sitzung();
    }
    return anm_aktuell();
}

/* ---- Team ---------------------------------------------------------------- */

/** Konto-ID des Team-Mitglieds dieser Anfrage (für das Audit-Protokoll) oder null. */
function anm_team_konto(): ?int
{
    return isset($GLOBALS['ws_team_konto']) ? (int)$GLOBALS['ws_team_konto'] : null;
}

/** Alle Anmeldungen mit ihren Plätzen (Team-Liste). */
function anm_liste(): array
{
    $liste = db_zeilen('SELECT * FROM anmeldung WHERE veranstaltung_id = ? ORDER BY id', [va_id()]);
    $plaetze = [];
    foreach (db_zeilen('SELECT * FROM platz WHERE veranstaltung_id = ? ORDER BY id', [va_id()]) as $p) {
        $plaetze[(int)$p['anmeldung_id']][] = $p;
    }
    foreach ($liste as &$a) {
        $a['plaetze'] = $plaetze[(int)$a['id']] ?? [];
    }
    return $liste;
}

/** Zahlen für Team-Übersicht und Startseite. */
function anm_zahlen(): array
{
    $z = ['bestaetigt' => 0, 'unbestaetigt' => 0];
    foreach (db_zeilen('SELECT status, COUNT(*) AS n FROM anmeldung WHERE veranstaltung_id = ? GROUP BY status', [va_id()]) as $r) {
        $z[$r['status']] = (int)$r['n'];
    }
    $z['symposium_belegt'] = anm_belegt(0);
    $z['symposium_warte'] = anm_wartend(0);
    $z['kapazitaet'] = anm_kapazitaet(0);
    $z['angebote'] = db_zahl("SELECT COUNT(*) FROM platz WHERE veranstaltung_id = ? AND status = 'angebot'", [va_id()]);
    return $z;
}
