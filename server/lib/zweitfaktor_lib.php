<?php
declare(strict_types=1);

/*
 * Zweiter Faktor über TOTP hinaus (AP-17 Unterpunkt 10, V-6): welche Faktoren
 * ein Konto hat, Code per E-Mail (E-55), Bestätigung heikler Aktionen mit
 * jedem zweiten Faktor (AP-16, B-7, S-4, V-4). Die Code-Rechnung selbst
 * (RFC 6238, Wiederherstellungscodes) steht in totp_lib.
 */

const MAILCODE_GUELTIG_S = 600;

const MAILCODE_GRENZE_TEXT = 'Ein Code ist gerade erst unterwegs, oder es waren in der letzten Stunde schon 10. Bitte warte etwas, bevor du einen neuen anforderst.';

const ZWEITFAKTOR_BESTAETIGT_S = 300;

const ZWEITFAKTOR_FELD_TEXT = 'Code aus der App, aus der Mail oder ein Wiederherstellungscode';

/** Ist ein zweiter Faktor eingerichtet (App, E-Mail-Code oder Passkey, E-55)? Für Konto-Zeilen aus der Datenbank. */
function zweitfaktor_da(array $k): bool
{
    return ($k['totp_seit'] ?? null) !== null || (int)($k['zweitfaktor_mail'] ?? 0) === 1 || pk_zahl((int)($k['id'] ?? 0)) > 0;
}

/**
 * Neuen Code per Mail schicken (der vorige gilt nicht mehr). Höchstens einer
 * je Minute und 10 je Stunde je Konto, gezählt in rate_limit (Review S-9).
 * Gibt null zurück, wenn verschickt, sonst den Grund.
 */
function mailcode_senden(array $k): ?string
{
    $merkmal = rate_merkmal('konto:' . (int)$k['id']);
    if (!rate_erlaubt('mailcode_minute', $merkmal) || !rate_erlaubt('mailcode', $merkmal)) {
        return MAILCODE_GRENZE_TEXT;
    }
    rate_misserfolg('mailcode_minute', $merkmal); // jeder Versand zählt
    rate_misserfolg('mailcode', $merkmal);
    $code = str_pad((string)random_int(0, 999999), TOTP_STELLEN, '0', STR_PAD_LEFT);
    db_ausfuehren('UPDATE konto SET mailcode_hash = ?, mailcode_bis = ? WHERE id = ?', [password_hash($code, PASSWORD_DEFAULT), db_zeit(time() + MAILCODE_GUELTIG_S), (int)$k['id']]);
    mail_vorlage_einreihen((string)$k['email'], 'zweitfaktor_code', ['vorname' => tk_namen($k)['vorname'], 'code' => $code]);
    return null;
}

/** Code aus der Mail prüfen (einmal gültig). */
function mailcode_pruefen(int $kontoId, string $eingabe): bool
{
    $ziffern = preg_replace('/\s+/', '', $eingabe) ?? '';
    if (preg_match('/^\d{' . TOTP_STELLEN . '}$/', $ziffern) !== 1) {
        return false;
    }
    $z = db_zeile('SELECT mailcode_hash, mailcode_bis FROM konto WHERE id = ?', [$kontoId]);
    if ($z === null || $z['mailcode_hash'] === null || db_ts($z['mailcode_bis']) < time() || !password_verify($ziffern, (string)$z['mailcode_hash'])) {
        return false;
    }
    return db_ausfuehren('UPDATE konto SET mailcode_hash = NULL, mailcode_bis = NULL WHERE id = ? AND mailcode_hash = ?', [$kontoId, $z['mailcode_hash']])->rowCount() === 1;
}

/**
 * E-Mail-Code ein- oder ausschalten. Beim ersten Einrichten (noch keine App)
 * gibt es Wiederherstellungscodes zurück, sonst null.
 */
function mailcode_einschalten(int $kontoId, bool $an, ?int $durch = null): ?array
{
    $z = totp_zustand($kontoId);
    $codes = null;
    db_transaktion(function () use ($kontoId, $an, $z, &$codes): void {
        db_ausfuehren('UPDATE konto SET zweitfaktor_mail = ? WHERE id = ?', [$an ? 1 : 0, $kontoId]);
        if ($an && !$z['eingerichtet']) {
            $codes = totp_codes_erzeugen();
            totp_codes_schreiben($kontoId, $codes);
        }
    });
    audit_schreiben('zweitfaktor', audit_text('konto', $kontoId, 'Code per E-Mail ' . ($an ? 'eingeschaltet' : 'ausgeschaltet')), $durch ?? $kontoId, ['konto' => $kontoId]);
    return $codes;
}

/**
 * Eine heikle Aktion (Rolle, Sicherung, Wiederherstellen, zweiten Faktor
 * zurücksetzen, Sperren) mit dem zweiten Faktor bestätigen: Code aus der App,
 * Code aus der Mail (wenn gerade einer gilt, auch ohne eingeschalteten
 * Mail-Code) oder Wiederherstellungscode, mit Ratenschutz je Konto.
 * Bei Erfolg gilt die Bestätigung für eine Aktion innerhalb von 5 Minuten
 * (zweitfaktor_bestaetigt). Gibt null oder den Fehlertext zurück.
 */
function zweitfaktor_bestaetigen(int $kontoId, string $code): ?string
{
    $kennung = rate_merkmal('konto:' . $kontoId);
    if (!rate_erlaubt('zweitfaktor', $kennung)) {
        return rate_text('zweitfaktor', 'falsche Codes');
    }
    if (!mailcode_pruefen($kontoId, $code) && !totp_anmeldung_pruefen($kontoId, $code)['ok']) {
        rate_misserfolg('zweitfaktor', $kennung);
        return 'Der Code stimmt nicht oder ist abgelaufen.';
    }
    rate_erfolg('zweitfaktor', $kennung);
    zweitfaktor_merken($kontoId);
    return null;
}

/** Bestätigung in der Sitzung merken (nach Code oder Passkey). */
function zweitfaktor_merken(int $kontoId): void
{
    $_SESSION['zf_bestaetigt'] = ['konto' => $kontoId, 'bis' => time() + ZWEITFAKTOR_BESTAETIGT_S];
}

/** Liegt eine frische Bestätigung vor? Gilt einmal: wird beim Lesen gelöscht. */
function zweitfaktor_bestaetigt(int $kontoId): bool
{
    $z = $_SESSION['zf_bestaetigt'] ?? null;
    unset($_SESSION['zf_bestaetigt']);
    return is_array($z) && (int)($z['konto'] ?? 0) === $kontoId && (int)($z['bis'] ?? 0) >= time();
}

/**
 * Für die Seiten: Bestätigung aus dem Formular. Vorher mit Passkey bestätigt
 * (passkey.php, Aktion bestaetigen) oder Code im Feld totp. Null = bestätigt.
 */
function zweitfaktor_aus_formular(int $kontoId): ?string
{
    if (zweitfaktor_bestaetigt($kontoId)) {
        return null;
    }
    $f = zweitfaktor_bestaetigen($kontoId, (string)($_POST['totp'] ?? ''));
    return $f ?? (zweitfaktor_bestaetigt($kontoId) ? null : 'Bitte noch einmal bestätigen.');
}

/**
 * „Code per E-Mail schicken“ aus einem Bestätigungs-Formular
 * (assets/zweitfaktor.js schickt das Formular mit zf_mailcode=1). Antwort als
 * JSON, ohne Accept-Kopfzeile zurück zur Seite. Kehrt nur zurück, wenn
 * zf_mailcode fehlt.
 */
function zweitfaktor_mailcode_aktion(array $konto, string $ziel): void
{
    if (empty($_POST['zf_mailcode'])) {
        return;
    }
    $f = mailcode_senden($konto);
    $text = $f ?? 'Code ist unterwegs an ' . $konto['email'] . '. Er gilt ' . dauer_text(MAILCODE_GUELTIG_S) . '.';
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        json_antwort(200, ['ok' => $f === null, 'text' => $text]);
    }
    flash_setzen($f === null ? 'ok' : 'hinweis', $text);
    umleiten($ziel);
}

/**
 * Feld für die Bestätigung mit Knöpfen „Code per E-Mail schicken“ und (mit
 * Passkey und WebAuthn im Browser) „Mit Passkey bestätigen“. Beide sind
 * type="button" und brauchen assets/zweitfaktor.js; ohne JavaScript bleiben
 * sie verborgen. Ein zweiter Absende-Knopf würde sonst mit der Eingabetaste
 * statt „Bestätigen“ ausgelöst.
 */
function zweitfaktor_feld_html(array $konto, string $id): string
{
    $pk = pk_zahl((int)$konto['id']) > 0
        ? ' <button type="button" class="knopf zweit klein" data-zf-passkey hidden>Mit Passkey bestätigen</button>'
        : '';
    return '<div class="feld"><label for="' . e($id) . '">' . e(ZWEITFAKTOR_FELD_TEXT) . '</label>'
        . '<input id="' . e($id) . '" name="totp" type="text" required autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" maxlength="12"></div>'
        . '<p class="aktionen" data-zf-wege hidden><button type="button" class="knopf zweit klein" data-zf-mailcode>Code per E-Mail schicken</button>' . $pk . '</p>'
        . '<p class="hilfe" data-zf-meldung role="status" hidden></p>';
}
