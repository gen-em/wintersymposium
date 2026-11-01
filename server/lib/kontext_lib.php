<?php
declare(strict_types=1);

/*
 * Zustand je Anfrage (AP-17 Unterpunkt 10, V-10; Konzept 5a K-e): statt
 * $GLOBALS ein Speicher mit festen Zugriffen. Schlüssel u. a.: host (Host der
 * Anfrage), va_id (Veranstaltung des Kontexts), va_aktuell, va_tab, va_rechte,
 * team_konto, team_rolle (eingeloggtes Team-Konto für Protokoll und Rechte),
 * inhalt_cache, mail_neu, mail_shutdown. Tests leeren ihn mit ctx_leeren().
 */

/** Speicher (intern). */
function &ctx_speicher(): array
{
    static $s = [];
    return $s;
}

/** Wert lesen (null, wenn nicht gesetzt). */
function ctx(string $k): mixed
{
    return ctx_speicher()[$k] ?? null;
}

function ctx_setzen(string $k, mixed $wert): void
{
    $s = &ctx_speicher();
    $s[$k] = $wert;
}

/** Ob der Schlüssel gesetzt ist (auch mit null nicht – wie isset). */
function ctx_da(string $k): bool
{
    return isset(ctx_speicher()[$k]);
}

function ctx_weg(string ...$k): void
{
    $s = &ctx_speicher();
    foreach ($k as $x) {
        unset($s[$x]);
    }
}

/** Alles vergessen (Tests, Wechsel der Datenbank). */
function ctx_leeren(): void
{
    $s = &ctx_speicher();
    $s = [];
}
