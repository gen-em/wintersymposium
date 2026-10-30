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

/** Passwort des Beispielkontos von Lea (erfunden, nur Testdaten). */
const BEISPIEL_PASSWORT = 'beispiel-lea-2026';

/** Lädt den ganzen Datensatz. Gibt die angelegten IDs je Bereich zurück. */
function beispieldaten_laden(): array
{
    beispieldaten_einstellungen();
    $r = ['programm' => beispieldaten_programm(), 'anmeldungen' => beispieldaten_anmeldungen()];
    $r['mailings'] = beispieldaten_mailings();
    $r['newsletter'] = beispieldaten_newsletter();
    return $r;
}

/**
 * Einstellungen relativ zu heute, damit Anmeldung und Nachrücken in jedem
 * Testlauf offen sind: Veranstaltung in 60 Tagen, Anmeldung seit gestern.
 */
function beispieldaten_einstellungen(): void
{
    $w = va_pruefen([
        'veranstaltung_datum' => gmdate('Y-m-d', time() + 60 * 86400),
        'veranstaltung_beginn' => '18:00', 'veranstaltung_ende' => '22:00', 'veranstaltung_ort' => 'Hörsaal Testklinik',
        'veranstaltung_kapazitaet' => '240', 'cme_anzeige' => 'Punkte beantragt',
        'anmeldung_start' => zeit_lokal(gmdate('Y-m-d H:i:s', time() - 86400), 'Y-m-d H:i'),
        'anmeldung_bestaetigung_h' => '48', 'workshop_limit' => '0', 'workshop_pflicht' => 'freiwillig',
        'nachrueck_frist_h' => '48', 'nachrueck_grenze_tage' => '14',
        'berufsgruppen' => VA_KATALOG['berufsgruppen']['start'],
    ]);
    if ($w['fehler'] !== []) {
        throw new RuntimeException('Beispieldaten Einstellungen: ' . json_encode($w['fehler'], JSON_UNESCAPED_UNICODE));
    }
    va_speichern($w['werte'], null);
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
        't_beginn' => ['19:30', '20:30'], 't_ende' => ['20:15', '21:15'], 't_plaetze' => ['12', '12'],
        // zweiter Termin mit anderer Leitung (Rückmeldung 2026-10-30: Dozierende je Termin)
        't_key' => ['a', 'b'], 't_doz' => ['b' => [$d1]]]);
    $ids[] = $punkt(['typ' => 'workshop', 'kuerzel' => 'WS 02', 'titel' => 'Kleine PatientInnen', 'beschreibung' => 'Kindernotfälle ohne Panik.', 'ort' => 'Seminarraum 2',
        't_beginn' => ['19:30', '20:00', '20:30'], 't_ende' => ['20:00', '20:30', '21:00'], 't_plaetze' => ['10', '10', '2']]);
    // Fallbesprechung im Workshop-Block, ausdrücklich zugeordnet (Rückmeldung 2026-10-30)
    $ids[] = $punkt(['typ' => 'fall', 'kuerzel' => 'FB 02', 'titel' => 'Fall im Block', 'beschreibung' => 'Kurzer Fall parallel zu den Workshops.', 'ort' => 'Seminarraum 3', 'beginn' => '20:00', 'ende' => '20:30', 'block_id' => (string)$ids[3], 'dozenten' => [$d1]]);
    $ids[] = $punkt(['typ' => 'station', 'titel' => 'Reanimation im Kreis', 'beschreibung' => 'Komm vorbei und drück mit.', 'ort' => 'Foyer', 'beginn' => '19:30', 'ende' => '21:30']);
    $ids[] = $punkt(['typ' => 'pause', 'titel' => 'Abschluss', 'ort' => 'Foyer', 'beginn' => '21:30', 'ende' => '22:00']);
    $fehler = programm_veroeffentlichen(null);
    if ($fehler !== []) {
        throw new RuntimeException('Beispieldaten Programm nicht veröffentlicht: ' . implode('; ', $fehler));
    }
    return ['dozenten' => [$d1, $d2], 'punkte' => $ids];
}

/**
 * Anmeldungen (AP-4): drei bestätigte mit Workshops, eine unbestätigte. Der
 * Termin WS 02 20:30 (2 Plätze) wird voll, Paula steht auf seiner Warteliste;
 * nach Max' Storno hat sie ein offenes Angebot, das sich mit ihrem festen
 * Termin WS 01 20:30 überschneidet (Tausch, E-42).
 * Gibt die IDs zurück: ['lea' => …, 'paula' => …, 'tom' => …].
 */
function beispieldaten_anmeldungen(): array
{
    $termin = function (string $kuerzel, string $beginn): int {
        foreach (anm_workshops(true) as $w) {
            foreach ($w['termine'] as $t) {
                if ($w['kuerzel'] === $kuerzel && $t['beginn'] === $beginn) {
                    return (int)$t['id'];
                }
            }
        }
        throw new RuntimeException("Beispieldaten: Termin $kuerzel $beginn fehlt");
    };
    $ws = fn(string $k) => (int)array_values(array_filter(anm_workshops(), fn($w) => $w['kuerzel'] === $k))[0]['id'];
    $person = function (array $daten, array $fest, array $warte, bool $bestaetigen) {
        $r = anm_daten_pruefen($daten + ['beruf_sonstige' => '', 'efn' => '']);
        if ($r['fehler'] !== []) {
            throw new RuntimeException('Beispieldaten Anmeldung: ' . json_encode($r['fehler'], JSON_UNESCAPED_UNICODE));
        }
        $e = anm_anlegen($r['werte'], ['fest' => $fest, 'warte' => $warte], false);
        if ($e['art'] !== 'neu') {
            throw new RuntimeException('Beispieldaten Anmeldung: ' . json_encode($e, JSON_UNESCAPED_UNICODE));
        }
        if ($bestaetigen) {
            $token = anm_link_erzeugen($e['id'], 'bestaetigen', time() + 3600);
            anm_link_einloesen($token);
        }
        return $e['id'];
    };
    $lea = $person(['vorname' => 'Lea', 'nachname' => 'Beispiel', 'email' => 'lea@example.org', 'berufsgruppe' => 'ÄrztIn', 'institution' => 'Klinikum Beispielstadt, ZNA', 'efn' => '802760012345673', 'essen' => 'vegetarisch'],
        [$ws('WS 01') => $termin('WS 01', '19:30'), $ws('WS 02') => $termin('WS 02', '20:30')], [], true);
    $max = $person(['vorname' => 'Max', 'nachname' => 'Muster', 'email' => 'max@example.org', 'berufsgruppe' => 'NotfallsanitäterIn', 'institution' => 'Rettungswache Musterdorf', 'essen' => 'egal'],
        [$ws('WS 02') => $termin('WS 02', '20:30')], [], true);
    $paula = $person(['vorname' => 'Paula', 'nachname' => 'Probe', 'email' => 'paula@example.org', 'berufsgruppe' => 'Pflege/MFA', 'institution' => 'Klinik am Probeberg', 'essen' => 'kein'],
        [$ws('WS 01') => $termin('WS 01', '20:30')], [$termin('WS 02', '20:30')], true);
    $tom = $person(['vorname' => 'Tom', 'nachname' => 'Test', 'email' => 'tom@example.org', 'berufsgruppe' => 'StudentIn', 'institution' => 'Universität Teststadt', 'essen' => 'egal'], [], [], false);
    anm_stornieren($max);
    // Lea hat ein Konto (AP-4b): Login auf Staging mit lea@example.org und diesem erfundenen Test-Passwort
    $leaKonto = tk_anlegen_aus_anmeldung($lea);
    konto_passwort_setzen((int)$leaKonto, BEISPIEL_PASSWORT);
    // Mails des Datensatzes nicht versenden (Warteschlange leeren)
    db_ausfuehren('DELETE FROM mail_ausgang');
    return ['lea' => $lea, 'paula' => $paula, 'tom' => $tom, 'lea_konto' => (int)$leaKonto];
}

/**
 * Mailings (AP-6): ein gesendetes an alle Angemeldeten (mit Empfängerliste im
 * Archiv) und ein Entwurf an WS 01 19:30 mit Lageplan-Bild und Knopf.
 * Es geht keine Mail raus (Warteschlange danach leer).
 */
function beispieldaten_mailings(): array
{
    $termin = 0;
    foreach (anm_workshops(true) as $w) {
        foreach ($w['termine'] as $t) {
            if ($w['kuerzel'] === 'WS 01' && $t['beginn'] === '19:30') {
                $termin = (int)$t['id'];
            }
        }
    }
    // Lageplan als einfaches Bild (erfunden), wie ein Upload verarbeitet
    $bild = null;
    if (foto_moeglich()) {
        $g = imagecreatetruecolor(640, 320);
        imagefill($g, 0, 0, imagecolorallocate($g, 238, 241, 245));
        imagefilledrectangle($g, 40, 40, 300, 280, imagecolorallocate($g, 0, 60, 113));
        imagefilledrectangle($g, 340, 40, 600, 160, imagecolorallocate($g, 180, 140, 255));
        imagefilledrectangle($g, 340, 190, 600, 280, imagecolorallocate($g, 181, 189, 0));
        imagestring($g, 5, 60, 60, 'Hoersaal', imagecolorallocate($g, 255, 255, 255));
        imagestring($g, 5, 360, 60, 'Nebenraum (EG)', imagecolorallocate($g, 0, 30, 58));
        ob_start();
        imagepng($g);
        $daten = (string)ob_get_clean();
        imagedestroy($g);
        $bild = rm_bild_aus_daten($daten, null);
    }
    $gesendet = rm_speichern(null, [
        'betreff' => 'Anfahrt und Parken',
        'html' => rm_html_bereinigen('<p>Hallo {vorname},</p><p>in zwei Wochen ist es so weit. Parken kannst du im Parkhaus P2 (kostenlos mit Einfahrtsticket).</p><p>Deine Workshops:<br>{workshops}</p><p><a class="knopf" href="{link}">Deine Anmeldung ansehen</a></p><p>Dein Orga-Team</p>'),
        'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system',
    ], null);
    rm_einreihen($gesendet, null);
    db_ausfuehren('DELETE FROM mail_ausgang WHERE rundmail_id = ?', [$gesendet]);
    db_ausfuehren("UPDATE rundmail SET status = 'gesendet' WHERE id = ?", [$gesendet]);
    $entwurf = rm_speichern(null, [
        'betreff' => 'WS 01 Atemweg: Raumänderung',
        'html' => rm_html_bereinigen('<p>Hallo {vorname},</p><p>dein Workshop <strong>WS 01 Atemweg. Plan B.</strong> findet nicht im Skills-Lab 1 statt, sondern im <strong>Hörsaal-Nebenraum (EG)</strong>. Der Weg ist ausgeschildert:</p>'
            . ($bild !== null ? '<p><img src="/team/mailings/bild?id=' . $bild['id'] . '" alt="Lageplan" data-breite="100"></p>' : '')
            . '<p>Alles andere bleibt. Wir freuen uns auf dich!</p><p><a class="knopf" href="{link}">Deine Anmeldung ansehen</a></p><p>Dein Orga-Team</p>'),
        'auswahl' => $termin > 0 ? 'termin:' . $termin : 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system',
    ], null);
    return ['gesendet' => $gesendet, 'entwurf' => $entwurf, 'bild' => $bild['id'] ?? null];
}

/**
 * Newsletter (AP-7): drei erfundene Abonnierende – Nina auf beiden Listen,
 * Otto auf „Allgäu Akut“ (beide bestätigt), Pia wartet noch auf die Bestätigung.
 * Es geht keine Mail raus.
 */
function beispieldaten_newsletter(): array
{
    $listen = array_column(nl_listen(), 'id', 'name');
    $nina = nl_person_anlegen('nina@example.org', 'Nina');
    nl_listen_setzen($nina, [$listen['Wintersymposium'] ?? 0, $listen['Allgäu Akut'] ?? 0], 'formular');
    $otto = nl_person_anlegen('otto@example.org', '');
    nl_listen_setzen($otto, [$listen['Allgäu Akut'] ?? 0], 'formular');
    nl_anfragen('pia@example.org', 'Pia', [$listen['Wintersymposium'] ?? 0], 'formular');
    db_ausfuehren("DELETE FROM mail_ausgang WHERE an = 'pia@example.org'");
    return ['nina' => $nina, 'otto' => $otto];
}
