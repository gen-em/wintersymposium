<?php
declare(strict_types=1);

/*
 * Test-Beispieldatensatz: feste, erfundene Daten für Prüfungen (Browser-
 * Durchlauf, später auch weitere Tests). Wächst mit jedem Arbeitspaket –
 * neue Bereiche (z. B. Anmeldungen ab AP-4) hier als eigene Funktion ergänzen
 * und in beispieldaten_laden() aufrufen. Nur erfundene Namen und
 * example.org-Adressen, keine echten Personen.
 *
 * Voraussetzung: server/bootstrap.php ist geladen, die Datenbank installiert.
 */

/** Lädt den ganzen Datensatz. Gibt die angelegten IDs je Bereich zurück. */
function beispieldaten_laden(): array
{
    return ['programm' => beispieldaten_programm()];
}

/**
 * Programm (AP-3 Schritt 3): zwei Dozierende, Vortrag, Fallbesprechung,
 * Workshop-Block mit zwei Workshops (mehrere Termine) und einer Station,
 * Pausen. Wird veröffentlicht, damit Startseite und Popups Inhalt haben.
 */
function beispieldaten_programm(): array
{
    $d1 = dozent_speichern(null, ['name' => 'Dr. Erika Muster', 'funktion' => 'Oberärztin, Zentrale Notaufnahme', 'kurzbio' => 'Seit 12 Jahren in der Notaufnahme.'], null);
    $d2 = dozent_speichern(null, ['name' => 'Ben Beispiel', 'funktion' => 'Notfallsanitäter, Praxisanleiter', 'kurzbio' => ''], null);
    $punkt = function (array $e): int {
        $r = programm_punkt_pruefen($e);
        if ($r['fehler'] !== []) {
            throw new RuntimeException('Beispieldaten Programm: ' . json_encode($r['fehler'], JSON_UNESCAPED_UNICODE));
        }
        return programm_punkt_speichern(null, $r, null);
    };
    $ids = [];
    $ids[] = $punkt(['typ' => 'pause', 'titel' => 'Ankommen, Snacks, Getränke', 'ort' => 'Foyer', 'beginn' => '18:00', 'ende' => '18:15']);
    $ids[] = $punkt(['typ' => 'vortrag', 'kuerzel' => 'VT 01', 'titel' => 'Sepsis. Uhr läuft.', 'untertitel' => 'Die erste Stunde', 'beschreibung' => "Was in den ersten 60 Minuten zählt.\n\nMit Zahlen aus der eigenen Notaufnahme.", 'ort' => 'Hörsaal', 'beginn' => '18:15', 'ende' => '18:45', 'dozenten' => [$d1, $d2]]);
    $ids[] = $punkt(['typ' => 'fall', 'kuerzel' => 'FB 01', 'titel' => 'Fall #1', 'untertitel' => 'Was hättest du gemacht?', 'beschreibung' => 'Ein Fall zum Mitentscheiden.', 'ort' => 'Hörsaal', 'beginn' => '18:45', 'ende' => '19:15']);
    $ids[] = $punkt(['typ' => 'block', 'titel' => 'Workshops und offene Stationen', 'beginn' => '19:30', 'ende' => '21:30']);
    $ids[] = $punkt(['typ' => 'workshop', 'kuerzel' => 'WS 01', 'titel' => 'Atemweg. Plan B.', 'beschreibung' => 'Videolaryngoskop, Larynxtubus, Koniotomie.', 'ort' => 'Skills-Lab 1', 'bring' => 'Stethoskop', 'notiz' => 'Brille mitbringen.', 'dozenten' => [$d2],
        't_beginn' => ['19:30', '20:30'], 't_ende' => ['20:15', '21:15'], 't_plaetze' => ['12', '12']]);
    $ids[] = $punkt(['typ' => 'workshop', 'kuerzel' => 'WS 02', 'titel' => 'Kleine PatientInnen', 'beschreibung' => 'Kindernotfälle ohne Panik.', 'ort' => 'Seminarraum 2',
        't_beginn' => ['19:30', '20:00', '20:30'], 't_ende' => ['20:00', '20:30', '21:00'], 't_plaetze' => ['10', '10', '10']]);
    $ids[] = $punkt(['typ' => 'station', 'titel' => 'Reanimation im Kreis', 'beschreibung' => 'Komm vorbei und drück mit.', 'ort' => 'Foyer', 'beginn' => '19:30', 'ende' => '21:30']);
    $ids[] = $punkt(['typ' => 'pause', 'titel' => 'Abschluss', 'ort' => 'Foyer', 'beginn' => '21:30', 'ende' => '22:00']);
    $fehler = programm_veroeffentlichen(null);
    if ($fehler !== []) {
        throw new RuntimeException('Beispieldaten Programm nicht veröffentlicht: ' . implode('; ', $fehler));
    }
    return ['dozenten' => [$d1, $d2], 'punkte' => $ids];
}
