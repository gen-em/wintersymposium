<?php
declare(strict_types=1);

/*
 * Status des Betriebs (AP-15, E-65): eine Quelle für die Seite
 * Betrieb → Status und für /health. Jede Zeile hat eine Ampel
 * (ok, warnung, fehler), einen kurzen Text und Details.
 *
 * Die Grenzwerte sind technische Werte (R-11, E-64 (3)), keine
 * Veranstaltungseinstellungen.
 */

/** Cron: gelb ab 15 Minuten, rot ab 60 Minuten ohne Lauf. */
const BETRIEB_CRON_GELB_S = 15 * 60;
const BETRIEB_CRON_ROT_S = 60 * 60;
/** Automatische Sicherung: gelb ab 6 Stunden, rot ab 24 Stunden (Admin 2026-10-31). */
const BETRIEB_SICHERUNG_GELB_S = 6 * 3600;
const BETRIEB_SICHERUNG_ROT_S = 24 * 3600;
/** Mail: gelb, wenn die älteste offene Mail so lange wartet oder ein Postfach diesen Anteil des Tageslimits erreicht. */
const BETRIEB_MAIL_WARTET_S = 15 * 60;
const BETRIEB_MAIL_LIMIT_ANTEIL = 0.9;
/** Fehlerprotokoll: so viele letzte Zeilen auf Abruf, jede höchstens so lang. */
const BETRIEB_LOG_ZEILEN = 30;
const BETRIEB_LOG_ZEICHEN = 500;
/** Laufzeitordner, die beschreibbar sein müssen. */
const BETRIEB_ORDNER = ['sitzungen' => 'Sitzungen', 'sicherungen' => 'Sicherungen', 'bilder' => 'Fotos', 'zertifikat' => 'Zertifikat', 'logs' => 'Logs'];
const BETRIEB_AMPEL = ['ok' => 'in Ordnung', 'warnung' => 'Achtung', 'fehler' => 'Fehler'];

/**
 * Alle Zeilen in fester Reihenfolge: cron, jobs, mail, datenbank, sicherung,
 * fehlerlog, umgebung. Jede Zeile:
 * ['id', 'titel', 'ampel', 'text', 'details' => [[Beschriftung, Wert, 'intern' => bool]], 'link' => ?string].
 * Details mit intern = true erscheinen nur auf der Seite, nicht in /health.
 */
function betrieb_status(?int $jetzt = null): array
{
    $jetzt ??= time();
    $zeilen = [];
    foreach (['cron' => 'Cron', 'jobs' => 'Jobs', 'mail' => 'Mailversand', 'datenbank' => 'Datenbank', 'sicherung' => 'Sicherung', 'fehlerlog' => 'Fehlerprotokoll', 'umgebung' => 'Umgebung'] as $id => $titel) {
        try {
            $z = ('betrieb_zeile_' . $id)($jetzt);
        } catch (Throwable $ex) {
            error_log('Status ' . $id . ': ' . $ex->getMessage());
            $z = ['ampel' => 'fehler', 'text' => 'Konnte nicht geprüft werden.', 'details' => []];
        }
        $zeilen[] = ['id' => $id, 'titel' => $titel] + $z + ['details' => [], 'link' => null];
    }
    return $zeilen;
}

/** Schlechteste Ampel einer Liste von Zeilen. */
function betrieb_gesamt(array $zeilen): string
{
    $ampeln = array_column($zeilen, 'ampel');
    return in_array('fehler', $ampeln, true) ? 'fehler' : (in_array('warnung', $ampeln, true) ? 'warnung' : 'ok');
}

/** Für /health: Zeilen ohne interne Details (keine Logzeilen, kein Token, kein Pfad). */
function betrieb_status_json(array $zeilen): array
{
    $aus = [];
    foreach ($zeilen as $z) {
        $details = [];
        foreach ($z['details'] as $d) {
            if (empty($d['intern'])) {
                $details[$d[0]] = $d[1];
            }
        }
        $aus[$z['id']] = ['ampel' => $z['ampel'], 'text' => $z['text'], 'details' => $details];
    }
    return $aus;
}

/** Dauer seit einem Zeitpunkt als Text („vor 12 Minuten“). */
function betrieb_alter_text(int $sekunden): string
{
    $s = max(0, $sekunden);
    if ($s < 120) {
        return 'gerade eben';
    }
    if ($s < 7200) {
        return 'vor ' . intdiv($s, 60) . ' Minuten';
    }
    if ($s < 172800) {
        return 'vor ' . intdiv($s, 3600) . ' Stunden';
    }
    return 'vor ' . intdiv($s, 86400) . ' Tagen';
}

function betrieb_zeit_ts(?string $utc): ?int
{
    return $utc === null || $utc === '' ? null : db_ts($utc);
}

function betrieb_zeile_cron(int $jetzt): array
{
    $ts = betrieb_zeit_ts(einstellung('jobs_letzter_lauf'));
    $ausloeser = (string)(einstellung('jobs_letzter_ausloeser') ?? '');
    if ($ts === null) {
        return ['ampel' => 'fehler', 'text' => 'Der Cron ist noch nie gelaufen. Ohne ihn gehen keine Mails raus und es entstehen keine Sicherungen.', 'details' => []];
    }
    $alter = $jetzt - $ts;
    $ampel = $alter >= BETRIEB_CRON_ROT_S ? 'fehler' : ($alter >= BETRIEB_CRON_GELB_S ? 'warnung' : 'ok');
    $text = 'Letzter Lauf ' . betrieb_alter_text($alter) . ($ampel === 'ok' ? '.' : ' – er sollte alle 5 Minuten laufen.');
    return ['ampel' => $ampel, 'text' => $text, 'details' => [
        ['Letzter Lauf', datum_zeit_text(db_zeit($ts))],
        ['Ausgelöst über', $ausloeser === 'cli' ? 'Shell-Cronjob' : ($ausloeser === 'url' ? 'URL-Aufruf' : $ausloeser)],
    ]];
}

function betrieb_zeile_jobs(int $jetzt): array
{
    $tab = [];
    foreach (db_zeilen('SELECT name, letzter_lauf, letzter_erfolg, letzte_dauer_ms, letzter_fehler FROM job') as $j) {
        $tab[$j['name']] = $j;
    }
    $fehler = [];
    $nie = [];
    $details = [];
    foreach (array_keys(jobs_katalog()) as $name) {
        $j = $tab[$name] ?? null;
        if ($j === null || $j['letzter_lauf'] === null) {
            $nie[] = $name;
            $details[] = [$name, 'noch nie gelaufen'];
            continue;
        }
        if ($j['letzter_fehler'] !== null) {
            $fehler[] = $name;
        }
        $details[] = [$name, datum_zeit_text($j['letzter_lauf']) . ', ' . (int)$j['letzte_dauer_ms'] . ' ms'
            . ($j['letzter_fehler'] !== null ? ' – Fehler: ' . mb_substr((string)$j['letzter_fehler'], 0, 300) : '')];
    }
    if ($fehler !== []) {
        return ['ampel' => 'fehler', 'text' => 'Fehler beim letzten Lauf: ' . implode(', ', $fehler) . '.', 'details' => $details];
    }
    if ($nie !== []) {
        return ['ampel' => 'warnung', 'text' => 'Noch nie gelaufen: ' . implode(', ', $nie) . '.', 'details' => $details];
    }
    return ['ampel' => 'ok', 'text' => 'Alle ' . count($details) . ' Jobs ohne Fehler.', 'details' => $details];
}

function betrieb_zeile_mail(int $jetzt): array
{
    $ms = mail_stand();
    $details = [['Eingerichtet', $ms['eingerichtet'] ? 'ja, Modus ' . $ms['modus'] : 'nein'], ['Warteschlange', $ms['offen'] . ' offen' . ($ms['aelteste_offen'] !== null ? ' (älteste seit ' . datum_zeit_text($ms['aelteste_offen']) . ')' : '')], ['Endgültig fehlgeschlagen', (string)$ms['fehler']], ['Heute gesendet', (string)$ms['heute']]];
    $knapp = [];
    foreach ($ms['postfaecher'] as $p => $pf) {
        if (!$pf['da']) {
            $details[] = [$pf['titel'], 'nicht eingerichtet – Mailings gehen über anmeldung@'];
            continue;
        }
        $details[] = [$pf['titel'], 'heute ' . $pf['heute'] . ' / ' . $pf['limit'] . ' gesendet (Mailings bis ' . $pf['rundmail'] . ')' . ($pf['offen'] > 0 ? ', ' . $pf['offen'] . ' warten' : '')];
        if ($pf['limit'] > 0 && $pf['heute'] >= $pf['limit'] * BETRIEB_MAIL_LIMIT_ANTEIL) {
            $knapp[] = $pf['titel'];
        }
    }
    if ($ms['letzter_fehler'] !== null) {
        // Fehlertexte des Mailservers können Adressen enthalten: nur auf der Seite
        $details[] = ['Letzter Fehler', $ms['letzter_fehler']['fehler'] . ' (Mail vom ' . datum_zeit_text($ms['letzter_fehler']['erstellt_am']) . ', ' . (int)$ms['letzter_fehler']['versuche'] . ' Versuche)', 'intern' => true];
    }
    if (!$ms['eingerichtet']) {
        return ['ampel' => 'fehler', 'text' => 'Der Mailversand ist nicht eingerichtet: In der config.php fehlt im Abschnitt mail der Eintrag host.', 'details' => $details, 'link' => '/team/daten'];
    }
    if ($ms['fehler'] > 0) {
        return ['ampel' => 'fehler', 'text' => $ms['fehler'] . ($ms['fehler'] === 1 ? ' Mail ließ' : ' Mails ließen') . ' sich nicht zustellen.', 'details' => $details];
    }
    $warn = [];
    $aelteste = betrieb_zeit_ts($ms['aelteste_offen']);
    if ($aelteste !== null && $jetzt - $aelteste >= BETRIEB_MAIL_WARTET_S) {
        $warn[] = 'Die älteste offene Mail wartet seit ' . datum_zeit_text($ms['aelteste_offen']) . '.';
    }
    foreach ($knapp as $t) {
        $warn[] = $t . ': Tageslimit fast erreicht.';
    }
    if ($warn !== []) {
        return ['ampel' => 'warnung', 'text' => implode(' ', $warn), 'details' => $details];
    }
    return ['ampel' => 'ok', 'text' => 'Heute ' . $ms['heute'] . ' gesendet, ' . $ms['offen'] . ' offen.', 'details' => $details];
}

function betrieb_zeile_datenbank(int $jetzt): array
{
    $ist = migration_ist();
    $soll = migration_soll();
    $wartung = wartung_aktiv();
    $details = [['Schema', $ist . ' von ' . $soll], ['Wartungsmodus', $wartung ? 'an' : 'aus']];
    if ($ist < $soll) {
        return ['ampel' => 'fehler', 'text' => 'Schema ' . $ist . ' von ' . $soll . ': Migrationen stehen aus.', 'details' => $details];
    }
    if ($ist > $soll) {
        return ['ampel' => 'warnung', 'text' => 'Die Datenbank (Schema ' . $ist . ') ist neuer als der Code (' . $soll . ').', 'details' => $details];
    }
    if ($wartung) {
        return ['ampel' => 'warnung', 'text' => 'Wartungsmodus ist an.', 'details' => $details];
    }
    return ['ampel' => 'ok', 'text' => 'Erreichbar, Schema ' . $ist . ' aktuell.', 'details' => $details];
}

function betrieb_zeile_sicherung(int $jetzt): array
{
    $auto = null;
    $hand = null;
    $groesse = 0;
    $liste = sicherung_liste();
    foreach ($liste as $s) {
        $groesse += $s['groesse'];
        $ts = betrieb_zeit_ts($s['kopf']['erstellt'] ?? null);
        if ($ts === null) {
            continue;
        }
        if (($s['kopf']['anlass'] ?? '') === DS_SICHERUNG_AUTOMATISCH) {
            $auto = max($auto ?? 0, $ts);
        } else {
            $hand = max($hand ?? 0, $ts);
        }
    }
    $details = [
        ['Vorhanden', count($liste) . ' (' . bytes_text($groesse) . ')'],
        ['Letzte automatische', $auto !== null ? datum_zeit_text(db_zeit($auto)) : 'keine'],
        ['Letzte von Hand', $hand !== null ? datum_zeit_text(db_zeit($hand)) : 'keine'],
    ];
    if ($auto === null) {
        return ['ampel' => 'fehler', 'text' => 'Es gibt keine automatische Sicherung. Sie entsteht über den Cron alle 3 Stunden.', 'details' => $details, 'link' => '/team/daten'];
    }
    $alter = $jetzt - $auto;
    $ampel = $alter >= BETRIEB_SICHERUNG_ROT_S ? 'fehler' : ($alter >= BETRIEB_SICHERUNG_GELB_S ? 'warnung' : 'ok');
    return ['ampel' => $ampel, 'text' => 'Letzte automatische Sicherung ' . betrieb_alter_text($alter) . '.' . ($ampel === 'ok' ? '' : ' Sie sollte alle 3 Stunden entstehen.'), 'details' => $details, 'link' => '/team/daten'];
}

/* ---- Fehlerprotokoll ------------------------------------------------------ */

function betrieb_fehlerlog_datei(): string
{
    return laufzeit_pfad('logs') . '/php-fehler.log';
}

/** Zeilen einer Datei zählen, ohne sie ganz zu laden. */
function betrieb_zeilen_zaehlen(string $datei): int
{
    $h = @fopen($datei, 'rb');
    if ($h === false) {
        return 0;
    }
    $n = 0;
    $letztes = "\n";
    while (!feof($h)) {
        $block = (string)fread($h, 65536);
        if ($block !== '') {
            $n += substr_count($block, "\n");
            $letztes = $block[strlen($block) - 1];
        }
    }
    fclose($h);
    return $n + ($letztes !== "\n" ? 1 : 0);
}

/** Größe und Zeilenzahl des Fehlerprotokolls; geleert = Zeitpunkt des letzten Leerens (UTC) oder null. */
function betrieb_fehlerlog_stand(): array
{
    $datei = betrieb_fehlerlog_datei();
    clearstatcache(true, $datei);
    $da = is_file($datei);
    return ['groesse' => $da ? (int)filesize($datei) : 0, 'zeilen' => $da ? betrieb_zeilen_zaehlen($datei) : 0, 'geleert' => einstellung('betrieb_fehlerlog_geleert')];
}

/** Die letzten Zeilen des Fehlerprotokolls, älteste zuerst, jede höchstens BETRIEB_LOG_ZEICHEN Zeichen. */
function betrieb_fehlerlog_ende(int $anzahl = BETRIEB_LOG_ZEILEN): array
{
    $datei = betrieb_fehlerlog_datei();
    $h = @fopen($datei, 'rb');
    if ($h === false) {
        return [];
    }
    $groesse = (int)fstat($h)['size'];
    $lesen = min($groesse, 512 * 1024);
    fseek($h, $groesse - $lesen);
    $text = (string)fread($h, max(1, $lesen));
    fclose($h);
    $zeilen = preg_split('/\r?\n/', rtrim($text, "\r\n")) ?: [];
    if ($lesen < $groesse) {
        array_shift($zeilen); // erste Zeile ist angeschnitten
    }
    $zeilen = array_slice(array_values(array_filter($zeilen, fn($z) => $z !== '')), -$anzahl);
    return array_map(function (string $z): string {
        $z = mb_scrub($z, 'UTF-8');
        return mb_strlen($z) > BETRIEB_LOG_ZEICHEN ? mb_substr($z, 0, BETRIEB_LOG_ZEICHEN) . ' …' : $z;
    }, $zeilen);
}

/** Fehlerprotokoll auf 0 Byte kürzen, Zeitpunkt merken, protokollieren. */
function betrieb_fehlerlog_leeren(?int $kontoId): bool
{
    $datei = betrieb_fehlerlog_datei();
    if (is_file($datei)) {
        $h = @fopen($datei, 'r+');
        if ($h === false || !ftruncate($h, 0)) {
            return false;
        }
        fclose($h);
    }
    einstellung_setzen('betrieb_fehlerlog_geleert', db_jetzt());
    audit_schreiben('betrieb', 'Fehlerprotokoll geleert', $kontoId);
    return true;
}

function betrieb_zeile_fehlerlog(int $jetzt): array
{
    $st = betrieb_fehlerlog_stand();
    $details = [['Größe', bytes_text($st['groesse'])], ['Einträge', (string)$st['zeilen']], ['Zuletzt geleert', $st['geleert'] !== null ? datum_zeit_text($st['geleert']) : 'noch nie']];
    $seit = $st['geleert'] !== null ? ' seit dem Leeren am ' . datum_zeit_text($st['geleert']) : '';
    if ($st['zeilen'] === 0) {
        return ['ampel' => 'ok', 'text' => 'Keine Einträge' . $seit . '.', 'details' => $details];
    }
    return ['ampel' => 'warnung', 'text' => $st['zeilen'] . ($st['zeilen'] === 1 ? ' Eintrag' : ' Einträge') . $seit . ' (' . bytes_text($st['groesse']) . ').', 'details' => $details];
}

/* ---- Umgebung ------------------------------------------------------------- */

function betrieb_zeile_umgebung(int $jetzt): array
{
    $details = [['Version', app_version()], ['Umgebung', umgebung()], ['PHP', PHP_VERSION]];
    $nicht = [];
    foreach (BETRIEB_ORDNER as $ordner => $titel) {
        $p = laufzeit_pfad($ordner);
        $ok = is_dir($p) && is_writable($p);
        $details[] = ['Ordner ' . $titel, $ok ? 'beschreibbar' : 'nicht beschreibbar'];
        if (!$ok) {
            $nicht[] = $titel;
        }
    }
    if ($nicht !== []) {
        return ['ampel' => 'fehler', 'text' => 'Nicht beschreibbar: ' . implode(', ', $nicht) . '. Rechte des Laufzeitordners beim Hoster prüfen.', 'details' => $details];
    }
    $details = array_merge($details, betrieb_proxy_merkmale($_SERVER, umgebung()));
    if (betrieb_proxy_verdacht($_SERVER, (bool)konfig('https_ueber_proxy', false), umgebung())) {
        return ['ampel' => 'warnung', 'text' => BETRIEB_PROXY_TEXT, 'details' => $details];
    }
    return ['ampel' => 'ok', 'text' => 'Version ' . app_version() . ' (' . umgebung() . '), PHP ' . PHP_VERSION . ', Laufzeitordner beschreibbar.', 'details' => $details];
}

const BETRIEB_PROXY_TEXT = 'Anfragen kommen über einen Proxy; der Ratenschutz zählt dann alle Besucher zusammen. Beim Hoster klären.';

/**
 * Kommen Anfragen über einen Proxy, ohne dass https_ueber_proxy gesetzt ist
 * (Review S-6)? Merkmale: REMOTE_ADDR in einem privaten Bereich (10/8,
 * 172.16/12, 192.168/16, 127/8, ::1) oder eine Kopfzeile X-Forwarded-For,
 * deren letzter Eintrag nicht REMOTE_ADDR ist. Ist er es, hat der Hoster die
 * echte Adresse schon eingesetzt (Staging, E-74 (3) d) – kein Verdacht.
 * In der Entwicklung (lokaler Server, Tests) zählt 127/8 nicht.
 */
function betrieb_proxy_verdacht(array $server, bool $proxyEin, string $umgebung): bool
{
    if ($proxyEin) {
        return false;
    }
    $ip = (string)($server['REMOTE_ADDR'] ?? '');
    if (betrieb_ip_intern($ip, $umgebung)) {
        return true;
    }
    $xff = array_values(array_filter(array_map('trim', explode(',', (string)($server['HTTP_X_FORWARDED_FOR'] ?? ''))), fn($x) => $x !== ''));
    return $xff !== [] && $xff[count($xff) - 1] !== $ip;
}

/** Absenderadresse in einem privaten Bereich (10/8, 172.16/12, 192.168/16, 127/8, ::1); in der Entwicklung zählt die lokale nicht. */
function betrieb_ip_intern(string $ip, string $umgebung): bool
{
    if ($ip === '::1') {
        return $umgebung !== 'entwicklung';
    }
    $n = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? ip2long($ip) : false;
    if ($n === false) {
        return false;
    }
    $in = fn(string $netz, int $bits) => ($n >> (32 - $bits)) === (ip2long($netz) >> (32 - $bits));
    if ($in('127.0.0.0', 8)) {
        return $umgebung !== 'entwicklung';
    }
    return $in('10.0.0.0', 8) || $in('172.16.0.0', 12) || $in('192.168.0.0', 16);
}

/**
 * Merkmale für die Details der Zeile Umgebung (E-74 (3) d), ohne Adressen
 * anzuzeigen: Ist die Absenderadresse intern (dann sieht die App alle mit
 * einer Adresse), gibt es X-Forwarded-For, und ist die Absenderadresse der
 * letzte Eintrag darin (dann hat der Hoster die echte Adresse schon
 * eingesetzt, der Ratenschutz arbeitet richtig)?
 */
function betrieb_proxy_merkmale(array $server, string $umgebung): array
{
    $ip = (string)($server['REMOTE_ADDR'] ?? '');
    $xff = array_values(array_filter(array_map('trim', explode(',', (string)($server['HTTP_X_FORWARDED_FOR'] ?? ''))), fn($x) => $x !== ''));
    return [
        ['Absenderadresse intern', betrieb_ip_intern($ip, $umgebung) ? 'ja' : 'nein'],
        ['Kopfzeile X-Forwarded-For', $xff === [] ? 'fehlt' : 'vorhanden (' . count($xff) . (count($xff) === 1 ? ' Eintrag)' : ' Einträge)')],
        ['Absender = letzter Eintrag darin', $xff === [] ? '–' : ($xff[count($xff) - 1] === $ip ? 'ja' : 'nein')],
    ];
}

/* ---- Cron-Anleitung (E-64 (2), E-65 (2)) ---------------------------------- */

/**
 * Kommando und URL für den Cron. Das Kommando enthält den Serverpfad und
 * erscheint nur auf der Status-Seite (R-10). Die URL trägt den Token nur,
 * wenn $mitToken; sonst einen Platzhalter.
 */
function betrieb_cron_anleitung(bool $mitToken = false): array
{
    $host = host_haupt() ?? (string)(ctx('host') ?? 'localhost');
    return [
        'zeitplan' => '*/5 * * * *',
        'kommando' => 'php ' . dirname(__DIR__) . '/cli/jobs.php',
        'url' => 'https://' . $host . '/jobs?token=' . ($mitToken ? (string)konfig('jobs_token') : '<jobs_token>'),
    ];
}

/** Anzeigen der Cron-URL mit Token protokollieren. */
function betrieb_token_angezeigt(?int $kontoId): void
{
    audit_schreiben('betrieb', 'Cron-URL mit Token angezeigt', $kontoId);
}

/* ---- Suchmaschinen (Release 1.0) ------------------------------------------ */

/**
 * Dürfen Suchmaschinen die öffentlichen Seiten aufnehmen? Nur auf Produktion
 * und nur, wenn eine BetreiberIn den Schalter unter Betrieb eingeschaltet hat
 * (Admin 2026-11-01: am Anfang aus). Staging und Entwicklung bleiben gesperrt.
 */
function betrieb_suchmaschinen_erlaubt(): bool
{
    return umgebung() === 'produktion' && betrieb_suchmaschinen_schalter();
}

/** Stellung des Schalters (unabhängig von der Umgebung). */
function betrieb_suchmaschinen_schalter(): bool
{
    return einstellung('suchmaschinen') === '1';
}

/** Schalter setzen und protokollieren. Gibt null oder einen Fehlertext zurück (K-a). */
function betrieb_suchmaschinen_setzen(bool $erlauben, ?int $durch): ?string
{
    if (umgebung() !== 'produktion') {
        return 'Auf ' . (umgebung() === 'staging' ? 'Staging' : 'der Entwicklung') . ' bleiben Suchmaschinen immer gesperrt.';
    }
    if ($erlauben === betrieb_suchmaschinen_schalter()) {
        return null;
    }
    einstellung_setzen('suchmaschinen', $erlauben ? '1' : '0');
    audit_schreiben('betrieb', $erlauben ? 'Suchmaschinen erlaubt' : 'Suchmaschinen gesperrt', $durch);
    return null;
}

/* ---- Aufgaben auf der Übersicht ------------------------------------------- */

/** Je rote Zeile eine Aufgabe für die Team-Übersicht (nur BetreiberInnen). */
function betrieb_aufgaben(?int $jetzt = null): array
{
    $auf = [];
    foreach (betrieb_status($jetzt) as $z) {
        if ($z['ampel'] === 'fehler') {
            $auf[] = ['art' => 'Betrieb', 'text' => 'Betrieb: ' . $z['titel'] . ' – ' . $z['text'], 'link' => '/team/status', 'knopf' => 'Status'];
        }
    }
    return $auf;
}
