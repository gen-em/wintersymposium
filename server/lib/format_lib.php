<?php
declare(strict_types=1);

/*
 * Deutsche Datums- und Zahlenformate (gekürzt aus NAdoku format_lib.php).
 * Die Datenbank speichert UTC, angezeigt wird Europe/Berlin (R-07).
 */

const ANZEIGE_ZEITZONE = 'Europe/Berlin';

/** UTC-Zeit (DATETIME-Text) in Ortszeit formatieren. */
function zeit_lokal(?string $utc, string $format = 'H:i'): string
{
    if ($utc === null || $utc === '') {
        return '–';
    }
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone(ANZEIGE_ZEITZONE))->format($format);
}

/** „20.01.2027" */
function datum_text(?string $utc): string
{
    return zeit_lokal($utc, 'd.m.Y');
}

/** Kalendertag (JJJJ-MM-TT, ohne Uhrzeit) als „Mi 20.01.2027". */
function tag_text(string $tag): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $tag, new DateTimeZone(ANZEIGE_ZEITZONE));
    if ($dt === false) {
        return $tag;
    }
    $wt = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int)$dt->format('w')];
    return $wt . ' ' . $dt->format('d.m.Y');
}

/** „20.01.2027, 14:30" */
/** „30.01.2027, 19:30“, mit $uhr „30.01.2027, 19:30 Uhr“ (Konzept 5a K-g). */
function datum_zeit_text(?string $utc, bool $uhr = false): string
{
    if ($utc === null || $utc === '') {
        return '–';
    }
    return zeit_lokal($utc, 'd.m.Y') . ', ' . zeit_lokal($utc, 'H:i') . ($uhr ? ' Uhr' : '');
}

/** Ortszeit (Text aus einem Formular, Europe/Berlin) nach UTC (DATETIME-Text). */
function lokal_nach_utc(string $lokal): ?string
{
    $lokal = trim($lokal);
    if ($lokal === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($lokal, new DateTimeZone(ANZEIGE_ZEITZONE));
    } catch (Exception) {
        return null;
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** ISO-8601 in UTC, z. B. für JSON: „2026-10-29T12:00:00Z" */
function iso_utc(?string $utc): ?string
{
    if ($utc === null || $utc === '') {
        return null;
    }
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

/** „1.234,5" */
function zahl_text(int|float $n, int $stellen = 0): string
{
    return number_format((float)$n, $stellen, ',', '.');
}

/* ---- Dauern, Größen, Namen, Uhrzeiten (AP-17) ----------------------------- */

/**
 * Dauer in Sekunden als Text: „30 Sekunden“, „eine Minute“, „15 Minuten“,
 * „eine Stunde“, „72 Stunden“ (bis 72 Stunden in Stunden), „7 Tage“.
 * Nicht glatte Werte werden auf die nächstkleinere passende Einheit gerundet.
 */
function dauer_text(int $s): string
{
    $s = max(0, $s);
    $einheit = static fn(int $n, string $eins, string $mehr) => $n === 1 ? $eins : $n . ' ' . $mehr;
    if ($s < 60) {
        return $einheit($s, 'eine Sekunde', 'Sekunden');
    }
    if ($s < 3600 || $s % 3600 !== 0) {
        return $einheit(intdiv($s, 60), 'eine Minute', 'Minuten');
    }
    if ($s <= 72 * 3600 || $s % 86400 !== 0) {
        return $einheit(intdiv($s, 3600), 'eine Stunde', 'Stunden');
    }
    return $einheit(intdiv($s, 86400), 'einen Tag', 'Tage');
}

/** Dateigröße: „0 KB“, „12 KB“ (mindestens 1 KB), „1,5 MB“, „4 MB“. */
function bytes_text(int $b): string
{
    if ($b <= 0) {
        return '0 KB';
    }
    if ($b >= 1048576) {
        return $b % 1048576 === 0 ? zahl_text(intdiv($b, 1048576)) . ' MB' : zahl_text($b / 1048576, 1) . ' MB';
    }
    return zahl_text(max(1, (int)round($b / 1024))) . ' KB';
}

/** „Vorname Nachname“ aus einem Datensatz mit vorname/nachname; fehlende Teile fallen weg. */
function person_name(array $p): string
{
    return trim(trim((string)($p['vorname'] ?? '')) . ' ' . trim((string)($p['nachname'] ?? '')));
}

/** „19:30“ aus „19:30:00“ (Uhrzeit aus der Datenbank). */
function hm(?string $zeit): string
{
    return $zeit === null || $zeit === '' ? '' : substr($zeit, 0, 5);
}

/** Minuten seit Mitternacht aus „19:30“. */
function minuten(string $hm): int
{
    [$h, $m] = array_map('intval', explode(':', $hm));
    return $h * 60 + $m;
}

/** „19:30“ aus Minuten seit Mitternacht (begrenzt auf 00:00 bis 23:59). */
function hm_aus_minuten(int $min): string
{
    $min = max(0, min(23 * 60 + 59, $min));
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

/** „Stand“-Zeit für Seiten: jetzt in Ortszeit, „01.11.2026, 14:05“. */
function jetzt_text(): string
{
    return datum_zeit_text(db_jetzt());
}

/** Jetzt als ISO-8601 in UTC („2026-11-01T13:05:00Z“). */
function jetzt_iso(): string
{
    return (string)iso_utc(db_jetzt());
}
