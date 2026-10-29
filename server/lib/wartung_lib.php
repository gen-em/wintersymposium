<?php
declare(strict_types=1);

/*
 * Wartungsmodus (Muster aus NAdoku wartung_lib.php). Eingeschaltet, solange
 * die Datei wartung.lock im Laufzeitordner liegt (per FTP anlegen/löschen).
 * Öffentliche Seiten antworten dann mit 503; der Team-Bereich (/team…),
 * der Health-Endpunkt und der Installer bleiben erreichbar. Jobs pausieren.
 */

const WARTUNG_AUSNAHMEN = ['/team', '/health', '/install'];

function wartung_datei(): string
{
    return laufzeit_pfad() . '/wartung.lock';
}

function wartung_aktiv(): bool
{
    return is_file(wartung_datei());
}

function wartung_einschalten(string $grund = ''): bool
{
    return @file_put_contents(wartung_datei(), gmdate('c') . ' ' . $grund . "\n") !== false;
}

function wartung_ausschalten(): bool
{
    return !is_file(wartung_datei()) || @unlink(wartung_datei());
}

function wartung_ausnahme(string $pfad): bool
{
    foreach (WARTUNG_AUSNAHMEN as $a) {
        if ($pfad === $a || str_starts_with($pfad, $a . '/')) {
            return true;
        }
    }
    return false;
}

/** Für öffentliche Pfade: bei Wartung 503 mit Hinweisseite. */
function wartung_tor(string $pfad): void
{
    if (!wartung_aktiv() || wartung_ausnahme($pfad)) {
        return;
    }
    header('Retry-After: 600');
    seite_meldung(503, 'Wartung', 'Die Webseite wird gerade gewartet und ist in Kürze wieder erreichbar.');
}
