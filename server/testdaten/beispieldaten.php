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
/** Passwort von Ada, Admin nur für den Fortbildungsabend (erfunden, nur Testdaten, AP-12). */
const BEISPIEL_PASSWORT_ADMIN = 'beispiel-ada-admin-2026';

/** Lädt den ganzen Datensatz. Gibt die angelegten IDs je Bereich zurück. */
function beispieldaten_laden(): array
{
    beispieldaten_einstellungen();
    $r = ['programm' => beispieldaten_programm(), 'anmeldungen' => beispieldaten_anmeldungen()];
    $r['mailings'] = beispieldaten_mailings();
    $r['newsletter'] = beispieldaten_newsletter();
    $r['teilnahme'] = beispieldaten_teilnahme($r['anmeldungen']);
    $r['vorjahr'] = beispieldaten_vorjahr($r['anmeldungen']);
    $r['abend'] = beispieldaten_abend($r['anmeldungen']);
    $r['admin'] = beispieldaten_admin((int)$r['abend']['id']);
    $r['review2'] = beispieldaten_review2();
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
        'veranstaltung_name' => 'Allgäuer Winter-Notfallsymposium', 'veranstaltung_auflage' => '3. Auflage',
        'veranstaltung_kapazitaet' => '240', 'cme_anzeige' => 'Punkte beantragt',
        'cme_punkte' => '4', 'cme_kategorie' => 'A', 'cme_kammer' => 'Beispiel-Landesärztekammer', 'cme_veranstaltungsnummer' => '2760000000000000',
        'zertifikat_leitung' => 'Dr. Erika Erfunden', 'zertifikat_leitung_rolle' => 'Wissenschaftliche Leitung',
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
        'html' => html_bereinigen('<p>Hallo {vorname},</p><p>in zwei Wochen ist es so weit. Parken kannst du im Parkhaus P2 (kostenlos mit Einfahrtsticket).</p><p>Deine Workshops:<br>{workshops}</p><p><a class="knopf" href="{link}">Deine Anmeldung ansehen</a></p><p>Dein Orga-Team</p>', 'mail'),
        'auswahl' => 'angemeldet', 'berufsgruppe' => '', 'postfach' => 'system',
    ], null);
    rm_einreihen($gesendet, null);
    db_ausfuehren('DELETE FROM mail_ausgang WHERE rundmail_id = ?', [$gesendet]);
    db_ausfuehren("UPDATE rundmail SET status = 'gesendet' WHERE id = ?", [$gesendet]);
    $entwurf = rm_speichern(null, [
        'betreff' => 'WS 01 Atemweg: Raumänderung',
        'html' => html_bereinigen('<p>Hallo {vorname},</p><p>dein Workshop <strong>WS 01 Atemweg. Plan B.</strong> findet nicht im Skills-Lab 1 statt, sondern im <strong>Hörsaal-Nebenraum (EG)</strong>. Der Weg ist ausgeschildert:</p>'
            . ($bild !== null ? '<p><img src="/team/mailings/bild?id=' . $bild['id'] . '" alt="Lageplan" data-breite="100"></p>' : '')
            . '<p>Alles andere bleibt. Wir freuen uns auf dich!</p><p><a class="knopf" href="{link}">Deine Anmeldung ansehen</a></p><p>Dein Orga-Team</p>', 'mail'),
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

/**
 * Teilnahme (AP-10): Paula ist in ihrem Workshop WS 01 nicht erschienen;
 * Leas Zertifikat ist freigeschaltet (direkt gesetzt, weil die Veranstaltung
 * im Datensatz in der Zukunft liegt); dazu eine erfundene Unterschrift (mit GD
 * gezeichneter Schwung, keine echte Unterschrift). Es geht keine Mail raus.
 */
function beispieldaten_teilnahme(array $anm): array
{
    $ws = db_wert("SELECT id FROM platz WHERE anmeldung_id = ? AND termin_id > 0 AND status = 'fest' ORDER BY id LIMIT 1", [$anm['paula']]);
    if ($ws !== null) {
        tn_setzen((int)$ws, false, null);
    }
    db_ausfuehren('UPDATE anmeldung SET zertifikat_am = ? WHERE id = ?', [db_jetzt(), $anm['lea']]);
    if (foto_moeglich()) {
        $b = imagecreatetruecolor(600, 160);
        imagesavealpha($b, true);
        imagealphablending($b, false);
        imagefill($b, 0, 0, imagecolorallocatealpha($b, 255, 255, 255, 127));
        imagealphablending($b, true);
        imagesetthickness($b, 5);
        $tinte = imagecolorallocate($b, 20, 40, 110);
        $vorher = null;
        for ($x = 20; $x <= 560; $x += 4) {
            $y = (int)(90 + 40 * sin($x / 38) * cos($x / 90) - ($x > 420 ? ($x - 420) / 4 : 0));
            if ($vorher !== null) {
                imageline($b, $vorher[0], $vorher[1], $x, $y, $tinte);
            }
            $vorher = [$x, $y];
        }
        ob_start();
        imagepng($b);
        unterschrift_schreiben((string)ob_get_clean());
        imagedestroy($b);
    }
    return ['paula_ws' => $ws !== null ? (int)$ws : null];
}

/**
 * Mehrere Veranstaltungen (E-58): eine abgeschlossene Vorjahres-Veranstaltung
 * „wintersymposium2026“ mit einem Vortrag und einem Workshop. Lea (mit Konto)
 * war dabei, ihr Zertifikat ist freigeschaltet – für den Rückblick unter
 * „Mein Konto“ und die Archiv-Seite /wintersymposium2026. Keine Mails.
 */
function beispieldaten_vorjahr(array $anm): array
{
    $r = va_anlegen('wintersymposium2026', 'Allgäuer Winter-Notfallsymposium', null, null);
    if (!isset($r['id'])) {
        throw new RuntimeException('Beispieldaten Vorjahr: ' . ($r['fehler'] ?? ''));
    }
    $vid = (int)$r['id'];
    va_mit($vid, function () use ($anm): void {
        $tag = gmdate('Y-m-d', time() - 300 * 86400);
        foreach (['veranstaltung_datum' => $tag, 'veranstaltung_beginn' => '18:00', 'veranstaltung_ende' => '22:00', 'veranstaltung_ort' => 'Hörsaal Testklinik',
            'veranstaltung_kurz' => 'Wintersymposium', 'veranstaltung_auflage' => '2. Auflage', 'veranstaltung_kapazitaet' => '200',
            'anmeldung_start' => gmdate('Y-m-d H:i:s', time() - 360 * 86400), 'berufsgruppen' => VA_KATALOG['berufsgruppen']['start'],
            'cme_punkte' => '4', 'cme_kategorie' => 'A', 'cme_kammer' => 'Beispiel-Landesärztekammer', 'zertifikat_leitung' => 'Dr. Erika Erfunden'] as $k => $w) {
            einstellung_setzen($k, $w);
        }
        einstellung_setzen('veranstaltung_name', 'Wintersymposium 2026');
        inhalt_setzen('start_unter', 'Rückblick auf die zweite Auflage.');
        foreach ([['typ' => 'vortrag', 'kuerzel' => 'VT 01', 'titel' => 'Schock. Was jetzt?', 'ort' => 'Hörsaal', 'beginn' => '18:15', 'ende' => '19:00'],
            ['typ' => 'workshop', 'kuerzel' => 'WS 01', 'titel' => 'Thoraxdrainage', 'ort' => 'Skills-Lab 1', 't_beginn' => ['19:30'], 't_ende' => ['20:30'], 't_plaetze' => ['12']]] as $e) {
            $p = programm_punkt_pruefen($e);
            programm_punkt_speichern(null, $p, null);
        }
        programm_veroeffentlichen(null);
        $lea = anm_laden($anm['lea']);
        $w = anm_daten_pruefen(['vorname' => $lea['vorname'], 'nachname' => $lea['nachname'], 'email' => $lea['email'], 'berufsgruppe' => $lea['berufsgruppe'],
            'beruf_sonstige' => '', 'institution' => $lea['institution'], 'efn' => (string)$lea['efn'], 'essen' => 'egal'])['werte'];
        $ws = anm_workshops()[0];
        $e = anm_anlegen($w, ['fest' => [$ws['id'] => (int)$ws['termine'][0]['id']], 'warte' => []], false);
        db_ausfuehren("UPDATE anmeldung SET status = 'bestaetigt', bestaetigt_am = ?, bestaetigen_bis = NULL, konto_id = ?, zertifikat_am = ? WHERE id = ?",
            [db_jetzt(), $anm['lea_konto'], db_jetzt(), $e['id']]);
    });
    va_abschliessen($vid, null);
    db_ausfuehren('DELETE FROM mail_ausgang');
    return ['id' => $vid];
}

/**
 * Fortbildungsabend klinische Notfallmedizin (Admin 2026-10-31): vergangener,
 * abgeschlossener Vortragsabend vor 100 Tagen – ohne Essen und Workshops, mit
 * Fortbildungspunkten. Lea (mit Konto) und Kai (Arzt ohne Konto) waren da,
 * Nora ist nicht erschienen. Damit stehen drei Veranstaltungen im Datensatz:
 * Wintersymposium 2027 (aktuell), dieser Abend, Wintersymposium 2026.
 */
function beispieldaten_abend(array $anm): array
{
    $r = va_anlegen('fortbildungsabend-notfallmedizin', 'Fortbildungsabend klinische Notfallmedizin', null, null);
    if (!isset($r['id'])) {
        throw new RuntimeException('Beispieldaten Abend: ' . ($r['fehler'] ?? ''));
    }
    $vid = (int)$r['id'];
    $ids = va_mit($vid, function () use ($anm): array {
        $tag = gmdate('Y-m-d', time() - 100 * 86400);
        foreach (['veranstaltung_art' => 'vortrag', 'veranstaltung_datum' => $tag, 'veranstaltung_beginn' => '19:00', 'veranstaltung_ende' => '21:00',
            'veranstaltung_ort' => 'Hörsaal Testklinik', 'veranstaltung_kurz' => 'Fortbildungsabend', 'veranstaltung_kapazitaet' => '80',
            'mit_essen' => 'nein', 'mit_cme' => 'ja', 'mit_zertifikat' => 'ja',
            'anmeldung_start' => gmdate('Y-m-d H:i:s', time() - 160 * 86400), 'berufsgruppen' => VA_KATALOG['berufsgruppen']['start'],
            'cme_punkte' => '2', 'cme_kategorie' => 'A', 'cme_kammer' => 'Beispiel-Landesärztekammer', 'zertifikat_leitung' => 'Dr. Erika Erfunden'] as $k => $w) {
            einstellung_setzen($k, $w);
        }
        einstellung_setzen('veranstaltung_name', 'Fortbildungsabend klinische Notfallmedizin');
        inhalt_setzen('start_unter', 'Zwei Vorträge, ein Fall, viel Zeit für Fragen.');
        foreach ([['typ' => 'vortrag', 'kuerzel' => 'VT 01', 'titel' => 'Lungenembolie in der Notaufnahme', 'ort' => 'Hörsaal', 'beginn' => '19:00', 'ende' => '19:40'],
            ['typ' => 'vortrag', 'kuerzel' => 'VT 02', 'titel' => 'Hyperkaliämie. Erkennen und handeln.', 'ort' => 'Hörsaal', 'beginn' => '19:45', 'ende' => '20:25'],
            ['typ' => 'fall', 'kuerzel' => 'FB 01', 'titel' => 'Fall des Abends', 'ort' => 'Hörsaal', 'beginn' => '20:30', 'ende' => '21:00']] as $e) {
            $p = programm_punkt_pruefen($e);
            if ($p['fehler'] !== []) {
                throw new RuntimeException('Beispieldaten Abend: ' . json_encode($p['fehler'], JSON_UNESCAPED_UNICODE));
            }
            programm_punkt_speichern(null, $p, null);
        }
        programm_veroeffentlichen(null);
        $bg = va_liste('berufsgruppen');
        $lea = anm_laden($anm['lea']);
        $personen = [
            'lea' => [$lea['vorname'], $lea['nachname'], $lea['email'], $lea['berufsgruppe'], $lea['institution'], (string)$lea['efn'], $anm['lea_konto']],
            'kai' => ['Kai', 'Abend', 'kai.abend@example.org', $bg[0], 'Klinikum Beispielstadt', '', null],
            'nora' => ['Nora', 'Notfall', 'nora.notfall@example.org', $bg[1] ?? $bg[0], 'Rettungswache Musterdorf', '', null],
        ];
        $ids = [];
        foreach ($personen as $k => [$vor, $nach, $mail, $beruf, $inst, $efn, $konto]) {
            $w = anm_daten_pruefen(['vorname' => $vor, 'nachname' => $nach, 'email' => $mail, 'berufsgruppe' => $beruf,
                'beruf_sonstige' => '', 'institution' => $inst, 'efn' => $efn, 'essen' => 'egal'])['werte'];
            $e = anm_anlegen($w, ['fest' => [], 'warte' => []], false);
            db_ausfuehren("UPDATE anmeldung SET status = 'bestaetigt', bestaetigt_am = ?, bestaetigen_bis = NULL, konto_id = ?, zertifikat_am = ? WHERE id = ?",
                [db_jetzt(), $konto, $k === 'nora' ? null : db_jetzt(), $e['id']]);
            $ids[$k] = (int)$e['id'];
        }
        tn_setzen((int)db_wert('SELECT id FROM platz WHERE anmeldung_id = ? AND termin_id = 0', [$ids['nora']]), false, null);
        return $ids;
    });
    va_abschliessen($vid, null);
    db_ausfuehren('DELETE FROM mail_ausgang');
    return ['id' => $vid] + $ids;
}

/**
 * Ada Abend (AP-12, E-60): Admin nur für den Fortbildungsabend – sieht im
 * Team-Bereich nur ihn, nicht die Wintersymposien und nicht den Betrieb.
 * Team-Konten bleiben beim erneuten Laden; die Zuordnung wird neu gesetzt.
 */
function beispieldaten_admin(int $abendId): int
{
    $k = konto_per_email('ada.abend@example.org');
    $id = $k !== null ? (int)$k['id'] : konto_anlegen('Ada Abend', 'ada.abend@example.org', 'admin', BEISPIEL_PASSWORT_ADMIN, null);
    va_rechte_setzen($id, false, [$abendId], null);
    return $id;
}

/**
 * Warteliste mit freien Plätzen (AP-14, Review B-2/B-3): Wanda steht seit drei
 * Tagen auf der Warteliste von WS 01 19:30, der Termin hat inzwischen wieder
 * freie Plätze (typisch nach der Nachrück-Grenze). Ihr Eintrag muss beim
 * Speichern – durch das Team oder sie selbst – erhalten bleiben.
 * Nicht Teil von beispieldaten_laden(): Vor der Nachrück-Grenze böte der Job
 * den freien Platz sofort an, das verschiebt die Abläufe der AP-4-Prüfungen.
 * Aufruf im Browser-Durchlauf (vorbereiten.php) und in lauf.php (AP-14).
 * Gibt ['wanda' => Anmeldung, 'termin' => Termin] zurück.
 */
function beispieldaten_warteliste(): array
{
    $termin = 0;
    foreach (anm_workshops(true) as $w) {
        foreach ($w['termine'] as $t) {
            if ($w['kuerzel'] === 'WS 01' && $t['beginn'] === '19:30') {
                $termin = (int)$t['id'];
            }
        }
    }
    if ($termin === 0 || anm_frei($termin) <= 0) {
        throw new RuntimeException('Beispieldaten Warteliste: WS 01 19:30 fehlt oder ist voll');
    }
    $r = anm_daten_pruefen(['vorname' => 'Wanda', 'nachname' => 'Warte', 'email' => 'wanda.warte@example.org', 'berufsgruppe' => 'NotfallsanitäterIn', 'institution' => 'Rettungswache Wartedorf', 'beruf_sonstige' => '', 'efn' => '', 'essen' => 'egal']);
    $e = anm_anlegen($r['werte'], ['fest' => [], 'warte' => []], false);
    if ($e['art'] !== 'neu') {
        throw new RuntimeException('Beispieldaten Warteliste: ' . json_encode($e, JSON_UNESCAPED_UNICODE));
    }
    anm_link_einloesen(anm_link_erzeugen($e['id'], 'bestaetigen', time() + 3600));
    // direkt eingetragen: ein freier Termin nimmt keine neuen Warte-Einträge an
    anm_platz_anlegen($e['id'], $termin, 'warte', time() - 3 * 86400);
    db_ausfuehren('DELETE FROM mail_ausgang');
    return ['wanda' => $e['id'], 'termin' => $termin];
}

/**
 * Review-Behebung 2 (AP-16): (1) eine unbestätigte Anmeldung mit der Adresse
 * von Ada (Team-Konto) in der aktuellen Veranstaltung – jemand Fremdes hat sie
 * angelegt; „Meine Anmeldung“ darf sie Ada nicht als eigene zeigen (Review
 * S-3). (2) Lea (mit Konto) hat ein Abo auf der ausgeblendeten Liste
 * „Referierende (intern)“; Speichern unter „Mein Konto“ lässt es stehen
 * (Review B-6). Es geht keine Mail raus.
 * Gibt ['fremd_ada' => Anmeldung, 'liste_intern' => Liste] zurück.
 */
function beispieldaten_review2(): array
{
    $r = anm_daten_pruefen(['vorname' => 'Ada', 'nachname' => 'Untergeschoben', 'email' => 'ada.abend@example.org', 'berufsgruppe' => 'StudentIn', 'institution' => 'Unbekannt', 'beruf_sonstige' => '', 'efn' => '', 'essen' => 'egal']);
    $e = anm_anlegen($r['werte'], ['fest' => [], 'warte' => []], false);
    if (!in_array($e['art'], ['neu', 'vorhanden'], true)) {
        throw new RuntimeException('Beispieldaten Review 2: ' . json_encode($e, JSON_UNESCAPED_UNICODE));
    }
    $liste = array_column(nl_listen(), 'id', 'name')['Referierende (intern)'] ?? null;
    $liste ??= nl_liste_speichern(null, nl_liste_pruefen(['name' => 'Referierende (intern)', 'beschreibung' => 'Einladungen für Referierende – nur über das Team.', 'sichtbar' => ''])['werte'], null);
    $lea = nl_person_anlegen('lea@example.org', 'Lea');
    db_ausfuehren(
        "INSERT INTO newsletter_abo (person_id, liste_id, status, quelle, angefragt_am, bestaetigt_am, fassung) VALUES (?, ?, 'bestaetigt', 'team', ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = 'bestaetigt'",
        [$lea, (int)$liste, db_jetzt(), db_jetzt(), nl_fassung()]
    );
    db_ausfuehren("DELETE FROM mail_ausgang WHERE an = 'ada.abend@example.org'");
    return ['fremd_ada' => (int)$e['id'], 'liste_intern' => (int)$liste];
}
