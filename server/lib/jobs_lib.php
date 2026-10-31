<?php
declare(strict_types=1);

/*
 * Hintergrundjobs: nur der Rahmen (nach NAdoku jobs_lib.php jobs_lauf /
 * jobs_einen_lauf). Auslöser ist der Cron, entweder auf der Kommandozeile
 * (server/cli/jobs.php) oder per URL mit Token (/jobs?token=…).
 *
 * Jeder Aufruf schreibt die Zeit in die Einstellung 'jobs_letzter_lauf'
 * (Nachweis, dass der Cron läuft, T-105). Jeder Job hat einen Mindestabstand;
 * ist er noch nicht erreicht, wird der Job übersprungen. Ein Cron jede
 * Minute bis alle 15 Minuten ist daher unbedenklich.
 *
 * Spätere Arbeitspakete hängen ihre Jobs in jobs_katalog() an (Mail-
 * Warteschlange, Nachrück-Fristen, Ablauf von Links, Löschfristen).
 */

function jobs_katalog(): array
{
    return [
        'aufraeumen' => [
            'abstand_s' => 3600,
            'fn' => 'job_aufraeumen',
        ],
        // Bestätigungsfristen, abgelaufene Angebote, Nachrücken (AP-4)
        'anmeldung' => [
            'abstand_s' => 60,
            'fn' => 'job_anmeldung',
        ],
        // Mailings: geplante starten, fertige abschließen, Archiv und Bilder aufräumen (AP-6)
        'rundmail' => [
            'abstand_s' => 60,
            'fn' => 'job_rundmail',
        ],
        // Warteschlange senden (nach anmeldung, damit dessen Mails gleich mitgehen)
        'mail' => [
            'abstand_s' => 60,
            'fn' => 'job_mail',
        ],
    ];
}

/**
 * Alle fälligen Jobs ausführen. Gibt eine Übersicht zurück:
 * ['status' => 'ok'|'laeuft_schon'|'wartung', 'jobs' => [name => ergebnis]].
 */
function jobs_lauf(string $ausloeser, ?int $jetzt = null): array
{
    $jetzt ??= time();
    if (wartung_aktiv()) {
        return ['status' => 'wartung', 'jobs' => []];
    }
    if ((int)db_wert("SELECT GET_LOCK('ws_jobs', 0)") !== 1) {
        return ['status' => 'laeuft_schon', 'jobs' => []];
    }
    try {
        migration_bei_bedarf();
        einstellung_setzen('jobs_letzter_lauf', gmdate('Y-m-d H:i:s', $jetzt));
        einstellung_setzen('jobs_letzter_ausloeser', $ausloeser);
        $ergebnisse = [];
        foreach (jobs_katalog() as $name => $job) {
            $ergebnisse[$name] = jobs_einen_lauf($name, $job, $jetzt);
        }
        return ['status' => 'ok', 'jobs' => $ergebnisse];
    } finally {
        db_wert("SELECT RELEASE_LOCK('ws_jobs')");
    }
}

/** Einen Job ausführen, falls fällig. Fehler werden protokolliert, nicht weitergeworfen. */
function jobs_einen_lauf(string $name, array $job, int $jetzt): array
{
    $z = db_zeile('SELECT letzter_lauf FROM job WHERE name = ?', [$name]);
    if ($z !== null && $z['letzter_lauf'] !== null
        && strtotime($z['letzter_lauf'] . ' UTC') > $jetzt - (int)$job['abstand_s']) {
        return ['status' => 'nicht_faellig'];
    }
    $beginn = microtime(true);
    $jetztDb = gmdate('Y-m-d H:i:s', $jetzt);
    try {
        $ergebnis = ($job['fn'])($jetzt);
        $fehler = null;
    } catch (Throwable $ex) {
        $ergebnis = null;
        $fehler = get_class($ex) . ': ' . $ex->getMessage();
        error_log("Job $name: $fehler");
    }
    $dauer = (int)round((microtime(true) - $beginn) * 1000);
    db_ausfuehren(
        'INSERT INTO job (name, letzter_lauf, letzter_erfolg, letzte_dauer_ms, letzter_fehler, laeufe)
         VALUES (?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE letzter_lauf = VALUES(letzter_lauf),
             letzter_erfolg = COALESCE(VALUES(letzter_erfolg), letzter_erfolg),
             letzte_dauer_ms = VALUES(letzte_dauer_ms), letzter_fehler = VALUES(letzter_fehler),
             laeufe = laeufe + 1',
        [$name, $jetztDb, $fehler === null ? $jetztDb : null, $dauer, $fehler]
    );
    return $fehler === null
        ? ['status' => 'ok', 'ergebnis' => $ergebnis, 'dauer_ms' => $dauer]
        : ['status' => 'fehler', 'dauer_ms' => $dauer];
}

/* ---- Jobs ---------------------------------------------------------------- */

/** Abgelaufene Sitzungsdateien und Ratenschutz-Einträge entfernen. */
function job_aufraeumen(int $jetzt): array
{
    return [
        'sitzungen' => sitzung_aufraeumen(),
        'rate_limit' => rate_aufraeumen($jetzt),
        'konto_links' => konto_link_aufraeumen($jetzt),
        'fotos' => foto_aufraeumen(),
        'mails' => mail_aufraeumen($jetzt),
        'newsletter' => nl_aufraeumen($jetzt),
    ];
}

/** Anmeldungen (Fristen, Angebote) und unbestätigte Teilnehmenden-Konten. */
function job_anmeldung(int $jetzt): array
{
    // Fristen und Nachrücken je Veranstaltung, die noch nicht abgeschlossen ist (E-58)
    $erg = ['verfallen' => 0, 'abgelaufen' => 0, 'angebote' => 0, 'links' => 0];
    $ids = va_tabellen_da() ? array_map('intval', array_column(db_zeilen("SELECT id FROM veranstaltung WHERE status <> 'abgeschlossen'"), 'id')) : [1];
    foreach ($ids as $va) {
        foreach (va_mit($va, fn() => anm_job($jetzt)) as $k => $n) {
            $erg[$k] += $n;
        }
    }
    return $erg + ['konten' => tk_aufraeumen($jetzt), 'loeschfrist' => tk_loeschfrist($jetzt)];
}

function job_rundmail(int $jetzt): array
{
    return rm_job($jetzt);
}

/** Fällige Mails senden. */
function job_mail(int $jetzt): array
{
    return mail_senden_faellige(40, 30.0, $jetzt);
}
