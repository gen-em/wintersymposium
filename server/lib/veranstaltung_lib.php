<?php
declare(strict_types=1);

/*
 * Veranstaltungs-Einstellungen (AP-3 Schritt 2, R-11): alles, was die
 * Veranstaltung betrifft, ist hier eine Einstellung und im Team-Bereich
 * unter „Einstellungen“ änderbar. Gespeichert in der Tabelle einstellung.
 *
 * Ein Katalog beschreibt jede Einstellung (Typ, Startwert, Grenzen, Text);
 * Formular, Prüfung, Anzeige und Tests lesen von hier. Startwerte gelten,
 * bis jemand speichert. Zeitpunkte werden in Ortszeit eingegeben und in UTC
 * gespeichert; Stichtage gelten „bis einschließlich“ dieses Tages (Ortszeit).
 *
 * Die Einstellungen zur Anmeldung (Zeitraum, Limits, Nachrücken, Stichtage)
 * werden mit AP-4 wirksam; bis dahin werden sie nur gespeichert.
 */

const VA_GRUPPEN = [
    'veranstaltung' => ['Veranstaltung', 'Erscheint in den Eckdaten auf der Startseite.'],
    'cme' => ['Fortbildungspunkte (CME)', 'Die Anzeige erscheint auf der Startseite; Punkte, Kammer und Nummer brauchen Bescheinigung und Meldung (AP-10).'],
    'anmeldung' => ['Anmeldung', 'Zeitraum und Regeln des Anmeldeformulars.'],
    'nachruecken' => ['Warteliste und Nachrücken', 'Ein frei gewordener Platz wird der ersten Person der Warteliste angeboten (E-19).'],
    'stichtage' => ['Stichtage für Teilnehmende', 'Bis einschließlich dieses Tages können Teilnehmende selbst ändern; danach nur noch das Team (E-31). Leer: bis zur Veranstaltung.'],
    'listen' => ['Auswahllisten', 'Je Zeile ein Eintrag; die Reihenfolge gilt im Formular.'],
];

/**
 * typ: datum (Y-m-d), zeit (H:i), zeitpunkt (Ortszeit-Eingabe, UTC gespeichert),
 *      zahl (min/max), text (max), auswahl (optionen), liste (Zeilen)
 */
const VA_KATALOG = [
    'veranstaltung_datum' => ['gruppe' => 'veranstaltung', 'typ' => 'datum', 'titel' => 'Datum', 'start' => '2027-01-20', 'pflicht' => true],
    'veranstaltung_beginn' => ['gruppe' => 'veranstaltung', 'typ' => 'zeit', 'titel' => 'Beginn', 'start' => '18:00'],
    'veranstaltung_ende' => ['gruppe' => 'veranstaltung', 'typ' => 'zeit', 'titel' => 'Ende', 'start' => '22:00'],
    'veranstaltung_ort' => ['gruppe' => 'veranstaltung', 'typ' => 'text', 'titel' => 'Ort', 'start' => '', 'max' => 200, 'hilfe' => 'Leer: Ort erscheint nicht auf der Startseite.'],
    'veranstaltung_kapazitaet' => ['gruppe' => 'veranstaltung', 'typ' => 'zahl', 'titel' => 'Gesamtkapazität (Personen)', 'start' => '240', 'min' => 0, 'max' => 10000, 'hilfe' => '0 = unbegrenzt. Bestimmt „Freie Plätze“ auf der Startseite.'],
    'cme_anzeige' => ['gruppe' => 'cme', 'typ' => 'text', 'titel' => 'Anzeige in den Eckdaten', 'start' => 'Punkte beantragt', 'max' => 60, 'hilfe' => 'z. B. „Punkte beantragt“ oder „4 CME-Punkte“. Leer: CME erscheint nicht in den Eckdaten.'],
    'cme_punkte' => ['gruppe' => 'cme', 'typ' => 'zahl', 'titel' => 'Punkte', 'start' => '', 'min' => 0, 'max' => 50, 'leer_erlaubt' => true],
    'cme_kammer' => ['gruppe' => 'cme', 'typ' => 'text', 'titel' => 'Ärztekammer', 'start' => '', 'max' => 120],
    'cme_veranstaltungsnummer' => ['gruppe' => 'cme', 'typ' => 'text', 'titel' => 'Veranstaltungsnummer (VNR)', 'start' => '', 'max' => 40],
    'anmeldung_start' => ['gruppe' => 'anmeldung', 'typ' => 'zeitpunkt', 'titel' => 'Anmeldung ab', 'start' => '2026-11-24 23:00:00', 'pflicht' => true],
    'anmeldung_ende' => ['gruppe' => 'anmeldung', 'typ' => 'zeitpunkt', 'titel' => 'Anmeldeschluss', 'start' => '', 'hilfe' => 'Leer: bis zum Beginn der Veranstaltung.'],
    'anmeldung_bestaetigung_h' => ['gruppe' => 'anmeldung', 'typ' => 'zahl', 'titel' => 'Bestätigungsfrist (Stunden)', 'start' => '24', 'min' => 1, 'max' => 168, 'hilfe' => 'So lange hält eine neue Anmeldung ihre Plätze, bis die Person den Link in der Mail anklickt. Danach verfällt sie und die Plätze gehen an die Warteliste.'],
    'workshop_limit' => ['gruppe' => 'anmeldung', 'typ' => 'zahl', 'titel' => 'Workshops je Person (höchstens)', 'start' => '0', 'min' => 0, 'max' => 20, 'hilfe' => '0 = kein Limit (E-20).'],
    'workshop_pflicht' => ['gruppe' => 'anmeldung', 'typ' => 'auswahl', 'titel' => 'Workshop-Wahl', 'start' => 'freiwillig', 'optionen' => ['freiwillig' => 'freiwillig', 'pflicht' => 'Pflicht (mindestens ein Workshop)']],
    'nachrueck_frist_h' => ['gruppe' => 'nachruecken', 'typ' => 'zahl', 'titel' => 'Annahmefrist für ein Angebot (Stunden)', 'start' => '48', 'min' => 1, 'max' => 336],
    'nachrueck_grenze_tage' => ['gruppe' => 'nachruecken', 'typ' => 'zahl', 'titel' => 'Automatisch nachrücken bis … Tage vor der Veranstaltung', 'start' => '14', 'min' => 0, 'max' => 90, 'hilfe' => 'Danach vergibt nur noch das Team freie Plätze.'],
    'stichtag_workshop' => ['gruppe' => 'stichtage', 'typ' => 'datum', 'titel' => 'Workshops ändern bis', 'start' => ''],
    'stichtag_essen' => ['gruppe' => 'stichtage', 'typ' => 'datum', 'titel' => 'Essen ändern bis', 'start' => ''],
    'stichtag_storno' => ['gruppe' => 'stichtage', 'typ' => 'datum', 'titel' => 'Selbst stornieren bis', 'start' => ''],
    'berufsgruppen' => ['gruppe' => 'listen', 'typ' => 'liste', 'titel' => 'Berufsgruppen', 'start' => "ÄrztIn\nNotfallsanitäterIn\nRettungssanitäterIn\nPflege/MFA\nStudentIn\nSonstige", 'max_zeilen' => 30, 'hilfe' => 'Die erste Zeile fragt die EFN ab (ÄrztInnen, E-22); „Sonstige“ erlaubt einen Freitext (E-21).'],
];

/** Gespeicherter Wert oder Startwert (Speicherform: UTC bei Zeitpunkten). */
function va(string $schluessel): string
{
    if (!isset(VA_KATALOG[$schluessel])) {
        throw new InvalidArgumentException('Unbekannte Einstellung: ' . $schluessel);
    }
    return einstellung($schluessel) ?? VA_KATALOG[$schluessel]['start'];
}

function va_zahl(string $schluessel): int
{
    return (int)va($schluessel);
}

/** Liste als Array (ohne leere Zeilen). */
function va_liste(string $schluessel): array
{
    return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', va($schluessel)))), fn($z) => $z !== ''));
}

/** Wert für das Formularfeld (Zeitpunkte in Ortszeit als Y-m-d\TH:i). */
function va_formularwert(string $schluessel, ?string $gespeichert = null): string
{
    $w = $gespeichert ?? va($schluessel);
    if (VA_KATALOG[$schluessel]['typ'] === 'zeitpunkt' && $w !== '') {
        return zeit_lokal($w, 'Y-m-d\TH:i');
    }
    return $w;
}

/**
 * Formulareingaben prüfen und in Speicherform bringen.
 * Gibt ['werte' => [schluessel => wert], 'fehler' => [schluessel => text]] zurück.
 */
function va_pruefen(array $eingabe): array
{
    $werte = [];
    $fehler = [];
    foreach (VA_KATALOG as $k => $d) {
        $roh = str_replace("\r", '', trim((string)($eingabe[$k] ?? '')));
        $titel = $d['titel'];
        if ($roh === '') {
            if (!empty($d['pflicht'])) {
                $fehler[$k] = "„{$titel}“ darf nicht leer sein.";
            }
            $werte[$k] = '';
            continue;
        }
        switch ($d['typ']) {
            case 'datum':
                $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $roh);
                if ($dt === false || $dt->format('Y-m-d') !== $roh) {
                    $fehler[$k] = "„{$titel}“: bitte ein gültiges Datum angeben.";
                }
                break;
            case 'zeit':
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $roh) !== 1) {
                    $fehler[$k] = "„{$titel}“: bitte eine Uhrzeit wie 18:00 angeben.";
                }
                break;
            case 'zeitpunkt':
                $roh = str_replace('T', ' ', $roh);
                $utc = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $roh) === 1 ? lokal_nach_utc($roh) : null;
                if ($utc === null) {
                    $fehler[$k] = "„{$titel}“: bitte Datum und Uhrzeit angeben.";
                } else {
                    $roh = $utc;
                }
                break;
            case 'zahl':
                if (preg_match('/^\d{1,6}$/', $roh) !== 1 || (int)$roh < $d['min'] || (int)$roh > $d['max']) {
                    $fehler[$k] = "„{$titel}“: bitte eine ganze Zahl von {$d['min']} bis {$d['max']} angeben.";
                } else {
                    $roh = (string)(int)$roh;
                }
                break;
            case 'text':
                $roh = preg_replace('/\s+/u', ' ', $roh) ?? '';
                if (mb_strlen($roh) > $d['max']) {
                    $fehler[$k] = "„{$titel}“: höchstens {$d['max']} Zeichen.";
                }
                break;
            case 'auswahl':
                if (!isset($d['optionen'][$roh])) {
                    $fehler[$k] = "„{$titel}“: bitte eine der Möglichkeiten wählen.";
                }
                break;
            case 'liste':
                $zeilen = array_values(array_filter(array_map(fn($z) => trim(preg_replace('/\s+/u', ' ', $z) ?? ''), explode("\n", $roh)), fn($z) => $z !== ''));
                if (count($zeilen) > $d['max_zeilen'] || array_filter($zeilen, fn($z) => mb_strlen($z) > 60) !== []) {
                    $fehler[$k] = "„{$titel}“: höchstens {$d['max_zeilen']} Zeilen mit je bis zu 60 Zeichen.";
                } elseif (count(array_unique(array_map('mb_strtolower', $zeilen))) !== count($zeilen)) {
                    $fehler[$k] = "„{$titel}“: ein Eintrag steht doppelt.";
                } elseif ($zeilen === []) {
                    $fehler[$k] = "„{$titel}“ braucht mindestens einen Eintrag.";
                }
                $roh = implode("\n", $zeilen);
                break;
        }
        $werte[$k] = $roh;
    }
    // Zusammenhänge
    if (!isset($fehler['veranstaltung_beginn'], $fehler['veranstaltung_ende']) && $werte['veranstaltung_beginn'] !== '' && $werte['veranstaltung_ende'] !== ''
        && $werte['veranstaltung_ende'] <= $werte['veranstaltung_beginn']) {
        $fehler['veranstaltung_ende'] = '„Ende“ muss nach „Beginn“ liegen.';
    }
    if (!isset($fehler['anmeldung_start'], $fehler['anmeldung_ende']) && $werte['anmeldung_ende'] !== '' && $werte['anmeldung_ende'] <= $werte['anmeldung_start']) {
        $fehler['anmeldung_ende'] = '„Anmeldeschluss“ muss nach „Anmeldung ab“ liegen.';
    }
    if (!isset($fehler['veranstaltung_datum']) && $werte['veranstaltung_datum'] !== '') {
        $tag = $werte['veranstaltung_datum'];
        if (!isset($fehler['anmeldung_start']) && $werte['anmeldung_start'] !== '' && zeit_lokal($werte['anmeldung_start'], 'Y-m-d') > $tag) {
            $fehler['anmeldung_start'] = '„Anmeldung ab“ liegt nach der Veranstaltung.';
        }
        foreach (['stichtag_workshop', 'stichtag_essen', 'stichtag_storno'] as $k) {
            if (!isset($fehler[$k]) && $werte[$k] !== '' && $werte[$k] > $tag) {
                $fehler[$k] = '„' . VA_KATALOG[$k]['titel'] . '“ liegt nach der Veranstaltung.';
            }
        }
    }
    return ['werte' => $werte, 'fehler' => $fehler];
}

/** Geprüfte Werte speichern; gibt die Schlüssel der geänderten Einstellungen zurück. */
function va_speichern(array $werte, ?int $kontoId): array
{
    $geaendert = [];
    db_transaktion(function () use ($werte, &$geaendert): void {
        foreach (VA_KATALOG as $k => $_) {
            if (!array_key_exists($k, $werte)) {
                continue;
            }
            if (va($k) !== $werte[$k] || einstellung($k) === null) {
                if (va($k) !== $werte[$k]) {
                    $geaendert[] = $k;
                }
                einstellung_setzen($k, $werte[$k]);
            }
        }
    });
    if ($geaendert !== []) {
        audit_schreiben('einstellungen', 'Geändert: ' . implode(', ', $geaendert), $kontoId);
    }
    return $geaendert;
}

/** Eckdaten für die Startseite: Liste von [Titel, Text]. */
function va_eckdaten(): array
{
    $eck = [];
    if (va('veranstaltung_datum') !== '') {
        $eck[] = ['Datum', tag_text(va('veranstaltung_datum'))];
    }
    $von = va('veranstaltung_beginn');
    $bis = va('veranstaltung_ende');
    if ($von !== '') {
        $eck[] = ['Zeit', $von . ($bis !== '' ? '–' . $bis : '')];
    }
    if (va('veranstaltung_ort') !== '') {
        $eck[] = ['Ort', va('veranstaltung_ort')];
    }
    if (va('cme_anzeige') !== '') {
        $eck[] = ['CME', va('cme_anzeige')];
    }
    $kap = va_zahl('veranstaltung_kapazitaet');
    if ($kap > 0) {
        // Belegt: feste Plätze und offene Angebote, auch unbestätigte Anmeldungen (E-40, E-46)
        $eck[] = ['Freie Plätze', max(0, $kap - anm_belegt(0)) . ' / ' . $kap];
    }
    return $eck;
}
