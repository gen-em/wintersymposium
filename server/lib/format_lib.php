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
function datum_zeit_text(?string $utc): string
{
    if ($utc === null || $utc === '') {
        return '–';
    }
    return zeit_lokal($utc, 'd.m.Y') . ', ' . zeit_lokal($utc, 'H:i');
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
