<?php
declare(strict_types=1);

/*
 * Einmal-Links (AP-17 Unterpunkt 8, Review V-1): ein System für alle Links,
 * die per Mail verschickt werden – Team-Konten (einrichten, passwort),
 * Teilnehmenden-Konten (bestaetigen, passwort, email), Anmeldungen
 * (bestaetigen, zugang, email, uebernehmen) und Newsletter (bestaetigen,
 * verwalten). Eine Tabelle `link`, gespeichert wird nur der SHA-256-Hash
 * des Tokens (R-02).
 *
 * Statt einer ziel_id hat die Tabelle je Art eine eigene Spalte mit
 * Fremdschlüssel (konto_id, anmeldung_id, person_id): So verschwinden Links
 * mit ihrem Konto, ihrer Anmeldung oder Person wie bisher von selbst.
 *
 * Token: 32 Byte als Base64url (43 Zeichen). Links aus der Zeit vor AP-17
 * (48 Hex-Zeichen bei Anmeldung und Newsletter) gelten weiter, bis sie ablaufen.
 */

/**
 * Regeln je Art: Spalte des Ziels, erlaubte Zwecke und welche alten Links ein
 * neuer ersetzt ('alle' des Ziels, 'zweck' = nur gleicher Zweck, 'keine').
 */
const LINK_ARTEN = [
    'konto' => ['spalte' => 'konto_id', 'zwecke' => ['einrichten', 'passwort', 'bestaetigen', 'email'], 'alte' => 'zweck'],
    'anmeldung' => ['spalte' => 'anmeldung_id', 'zwecke' => ['bestaetigen', 'zugang', 'email', 'uebernehmen'], 'alte' => 'keine'],
    'newsletter' => ['spalte' => 'person_id', 'zwecke' => ['bestaetigen', 'verwalten'], 'alte' => 'keine'],
];

/** Neues Token: 32 Zufallsbytes als Base64url ohne Auffüllung (43 Zeichen). */
function link_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function link_art(string $art): array
{
    if (!isset(LINK_ARTEN[$art])) {
        throw new InvalidArgumentException('Unbekannte Link-Art: ' . $art);
    }
    return LINK_ARTEN[$art];
}

/**
 * Link anlegen und das Token zurückgeben (nur hier im Klartext).
 * $daten: Zusatzangaben, z. B. ['neue_email' => …]. $alte überschreibt die
 * Regel der Art für ältere Links desselben Ziels.
 */
function link_erzeugen(string $art, int $zielId, string $zweck, int $gueltigS, array $daten = [], ?int $jetzt = null, ?string $alte = null): string
{
    $a = link_art($art);
    if (!in_array($zweck, $a['zwecke'], true)) {
        throw new InvalidArgumentException('Unbekannter Link-Zweck: ' . $zweck);
    }
    $jetzt ??= time();
    $alte ??= $a['alte'];
    $token = link_token();
    db_transaktion(function () use ($art, $a, $zielId, $zweck, $gueltigS, $daten, $jetzt, $alte, $token): void {
        if ($alte !== 'keine') {
            link_loeschen($art, $zielId, $alte === 'zweck' ? $zweck : null);
        }
        db_ausfuehren(
            'INSERT INTO link (art, ' . $a['spalte'] . ', zweck, token_hash, daten, gueltig_bis, erstellt_am) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$art, $zielId, $zweck, hash('sha256', $token), $daten === [] ? null : json_encode($daten, JSON_UNESCAPED_UNICODE), db_zeit($jetzt + $gueltigS), db_zeit($jetzt)]
        );
    });
    return $token;
}

/** Datenbankzeile als Link: daten entpackt, Ziel als ziel_id und unter dem alten Spaltennamen, neue_email aus daten. */
function link_aufbereiten(array $z): array
{
    $spalte = LINK_ARTEN[$z['art']]['spalte'];
    $z['daten'] = $z['daten'] !== null ? (json_decode((string)$z['daten'], true) ?: []) : [];
    $z['ziel_id'] = (int)$z[$spalte];
    $z['neue_email'] = $z['daten']['neue_email'] ?? null;
    return $z;
}

/**
 * Gültigen Link finden (ohne ihn zu verbrauchen) oder null: richtige Art,
 * nicht abgelaufen, mit $zweck nur dieser Zweck.
 */
function link_finden(string $art, string $token, ?string $zweck = null, ?int $jetzt = null): ?array
{
    link_art($art);
    if (preg_match('/^(?:[A-Za-z0-9_-]{43}|[0-9a-f]{48})$/', $token) !== 1) {
        return null;
    }
    $z = db_zeile('SELECT * FROM link WHERE token_hash = ? AND art = ?', [hash('sha256', $token), $art]);
    if ($z === null || db_ts($z['gueltig_bis']) < ($jetzt ?? time()) || ($zweck !== null && $z['zweck'] !== $zweck)) {
        return null;
    }
    return link_aufbereiten($z);
}

/** Link in einer Transaktion sperren (FOR UPDATE) und zurückgeben oder null, wenn er weg ist. */
function link_sperren(int $id): ?array
{
    $z = db_zeile('SELECT * FROM link WHERE id = ? FOR UPDATE', [$id]);
    return $z === null ? null : link_aufbereiten($z);
}

/** Link verbrauchen (löschen). false, wenn er schon verbraucht war. */
function link_verbrauchen(int $id): bool
{
    return db_ausfuehren('DELETE FROM link WHERE id = ?', [$id])->rowCount() === 1;
}

/** Links eines Ziels löschen (alle oder nur eines Zwecks). Gibt die Zahl zurück. */
function link_loeschen(string $art, int $zielId, ?string $zweck = null): int
{
    $a = link_art($art);
    return db_ausfuehren('DELETE FROM link WHERE art = ? AND ' . $a['spalte'] . ' = ?' . ($zweck !== null ? ' AND zweck = ?' : ''),
        array_merge([$art, $zielId], $zweck !== null ? [$zweck] : []))->rowCount();
}

/**
 * Adresse, unter der ein Link eingelöst wird (bestehende Pfade bleiben, damit
 * verschickte Mails weiter gehen): Team-Konto /einrichten, Teilnehmenden-Konto
 * /konto/link, Anmeldung /link, Newsletter /newsletter/bestaetigen bzw. /verwalten.
 */
function link_url(string $art, string $token, string $zweck = '', bool $team = false): string
{
    link_art($art);
    $pfad = match ($art) {
        'konto' => $team ? '/einrichten' : '/konto/link',
        'anmeldung' => '/link',
        'newsletter' => $zweck === 'bestaetigen' ? '/newsletter/bestaetigen' : '/newsletter/verwalten',
    };
    return url($pfad . '?t=' . rawurlencode($token));
}

/** Abgelaufene Links löschen (alle Arten oder eine). Gibt die Zahl zurück. */
function link_aufraeumen(?int $jetzt = null, ?string $art = null): int
{
    if ($art !== null) {
        link_art($art);
    }
    return db_ausfuehren('DELETE FROM link WHERE gueltig_bis < ?' . ($art !== null ? ' AND art = ?' : ''),
        array_merge([db_zeit($jetzt ?? time())], $art !== null ? [$art] : []))->rowCount();
}
