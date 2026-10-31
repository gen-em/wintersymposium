<?php
declare(strict_types=1);

/*
 * Teilnahme und Zertifikate (AP-10, E-57).
 *
 * Anwesenheit: Wer einen festen Platz beim Symposium hat, gilt als anwesend
 * (E-42); das Team markiert nur „nicht erschienen“ – beim Symposium und je
 * Workshop-Termin (Spalte platz.nicht_erschienen). Jede Änderung steht im
 * Protokoll (Art „teilnahme“).
 *
 * Zertifikat: Teilnahmebescheinigung als PDF nach Entwurf C (Admin
 * 2026-10-30) mit Name, EFN (nur wenn angegeben; keine Berufsgruppe),
 * Vorträgen, besuchten Workshops, Punkten und Unterschrift (PNG). Das Team
 * schaltet es frei (einzeln oder alle Anwesenden); die Person bekommt eine
 * Mail mit Link und lädt es unter „Meine Anmeldung“ herunter. Ohne
 * Anwesenheit kein Zertifikat – auch nicht nach der Freischaltung.
 *
 * Unterschriftenliste: PDF zum Ausdrucken, alle oder nur ÄrztInnen (dann mit
 * EFN als Barcode, Code 128, Inhalt *EFN*, E-42; ohne EFN ein Feld fürs
 * Etikett), Sortierung wählbar.
 */

/** Gültigkeit des Links in der Mail „Zertifikat verfügbar“ (Tage). */
const ZERTIFIKAT_LINK_TAGE = 30;
const UNTERSCHRIFT_MAX_BYTES = 4 * 1024 * 1024;
const UNTERSCHRIFT_BREITE = 1200;
const TN_SORTIERUNG = ['nachname' => 'Nachname', 'vorname' => 'Vorname', 'berufsgruppe' => 'Berufsgruppe'];

/** Name der Veranstaltung für Zertifikat und Mail. */
function tn_name(): string
{
    return va('veranstaltung_name') !== '' ? va('veranstaltung_name') : 'Wintersymposium';
}

/* ---- Anwesenheit --------------------------------------------------------- */

/**
 * Alle mit festem Platz beim Symposium (bestätigte Anmeldungen), nach Nachname.
 * Je Eintrag: anm (Zeile), platz (Symposium), workshops (feste Plätze mit 'text', 'beginn'),
 * anwesend (bool), aerztin (bool).
 */
function tn_liste(): array
{
    $erste = va_liste('berufsgruppen')[0] ?? '';
    $liste = [];
    foreach (anm_liste() as $a) {
        if ($a['status'] !== 'bestaetigt') {
            continue;
        }
        $sym = null;
        $ws = [];
        foreach ($a['plaetze'] as $p) {
            if ($p['status'] !== 'fest') {
                continue;
            }
            if ((int)$p['termin_id'] === 0) {
                $sym = $p;
            } else {
                $t = anm_termin((int)$p['termin_id']);
                $ws[] = $p + ['text' => anm_termin_text((int)$p['termin_id']), 'beginn' => $t['beginn'] ?? '99'];
            }
        }
        if ($sym === null) {
            continue;
        }
        usort($ws, fn($x, $y) => [$x['beginn'], $x['id']] <=> [$y['beginn'], $y['id']]);
        $liste[] = ['anm' => $a, 'platz' => $sym, 'workshops' => $ws, 'anwesend' => (int)$sym['nicht_erschienen'] === 0, 'aerztin' => $a['berufsgruppe'] === $erste];
    }
    return tn_sortieren($liste, 'nachname');
}

function tn_sortieren(array $liste, string $nach): array
{
    $schluessel = fn(array $e): array => match ($nach) {
        'vorname' => [mb_strtolower($e['anm']['vorname']), mb_strtolower($e['anm']['nachname'])],
        'berufsgruppe' => [mb_strtolower($e['anm']['berufsgruppe']), mb_strtolower($e['anm']['nachname']), mb_strtolower($e['anm']['vorname'])],
        default => [mb_strtolower($e['anm']['nachname']), mb_strtolower($e['anm']['vorname'])],
    };
    usort($liste, function ($x, $y) use ($schluessel): int {
        foreach (array_map(null, $schluessel($x), $schluessel($y)) as [$a, $b]) {
            if (($c = strcoll($a, $b)) !== 0) {
                return $c;
            }
        }
        return (int)$x['anm']['id'] <=> (int)$y['anm']['id'];
    });
    return $liste;
}

/** Anwesend = bestätigt, fester Platz beim Symposium, nicht als „nicht erschienen“ markiert. */
function tn_anwesend(int $anmId): bool
{
    return db_wert(
        "SELECT COUNT(*) FROM anmeldung a JOIN platz p ON p.anmeldung_id = a.id AND p.termin_id = 0 AND p.status = 'fest' AND p.nicht_erschienen = 0 WHERE a.id = ? AND a.status = 'bestaetigt'",
        [$anmId]
    ) > 0;
}

/** „Nicht erschienen“ setzen oder zurücknehmen (fester Platz). Gibt false zurück, wenn es den Platz nicht gibt. */
function tn_setzen(int $platzId, bool $erschienen, ?int $kontoId): bool
{
    $p = db_zeile("SELECT * FROM platz WHERE id = ? AND status = 'fest'", [$platzId]);
    if ($p === null) {
        return false;
    }
    if ((int)$p['nicht_erschienen'] === ($erschienen ? 0 : 1)) {
        return true;
    }
    db_ausfuehren('UPDATE platz SET nicht_erschienen = ? WHERE id = ?', [$erschienen ? 0 : 1, $platzId]);
    $a = anm_laden((int)$p['anmeldung_id']);
    audit_schreiben('teilnahme', 'Anmeldung ' . $p['anmeldung_id'] . ' (' . ($a['vorname'] ?? '') . ' ' . ($a['nachname'] ?? '') . '): '
        . anm_termin_text((int)$p['termin_id']) . ' – ' . ($erschienen ? 'doch erschienen' : 'nicht erschienen'), $kontoId);
    return true;
}

/** Zahlen für Teilnahme und Statistik. */
function tn_zahlen(): array
{
    $l = tn_liste();
    $anw = array_filter($l, fn($e) => $e['anwesend']);
    return [
        'angemeldet' => count($l),
        'anwesend' => count($anw),
        'nicht_erschienen' => count($l) - count($anw),
        'freigeschaltet' => count(array_filter($anw, fn($e) => $e['anm']['zertifikat_am'] !== null)),
        'offen' => count(array_filter($anw, fn($e) => $e['anm']['zertifikat_am'] === null)),
    ];
}

/* ---- Zertifikat: Freischalten ------------------------------------------- */

/** Ab dem Veranstaltungstag (Ortszeit) darf freigeschaltet werden. */
function zertifikat_frei_ab_heute(?int $jetzt = null): bool
{
    return anm_heute($jetzt) >= va('veranstaltung_datum');
}

/** Herunterladen möglich: anwesend und freigeschaltet. */
function zertifikat_verfuegbar(array $anm): bool
{
    return $anm['zertifikat_am'] !== null && tn_anwesend((int)$anm['id']) && va_von((int)$anm['veranstaltung_id'], 'mit_zertifikat') !== 'nein';
}

/** PDF einer Anmeldung, im Kontext ihrer Veranstaltung (auch für vergangene, E-58). */
function zertifikat_pdf_fuer(array $anm): string
{
    return va_mit((int)$anm['veranstaltung_id'], fn() => zertifikat_pdf(zertifikat_daten($anm)));
}

/**
 * Freischalten: eine Anmeldung oder (null) alle Anwesenden ohne Freischaltung.
 * Schickt je Person die Mail „Zertifikat verfügbar“ mit einem Link (außer $mail = false).
 * Gibt die Zahl der freigeschalteten Personen zurück.
 */
function zertifikat_freischalten(?int $anmId, ?int $kontoId, bool $mail = true, ?int $jetzt = null): int
{
    $jetzt ??= time();
    $ids = [];
    foreach (tn_liste() as $e) {
        if ($e['anwesend'] && $e['anm']['zertifikat_am'] === null && ($anmId === null || (int)$e['anm']['id'] === $anmId)) {
            $ids[] = (int)$e['anm']['id'];
        }
    }
    foreach ($ids as $id) {
        db_ausfuehren('UPDATE anmeldung SET zertifikat_am = ? WHERE id = ? AND zertifikat_am IS NULL', [gmdate('Y-m-d H:i:s', $jetzt), $id]);
        if ($mail) {
            zertifikat_mail($id, $jetzt);
        }
    }
    if ($ids !== []) {
        audit_schreiben('teilnahme', $anmId === null ? 'Zertifikate freigeschaltet: ' . count($ids) . ' Personen' . ($mail ? '' : ' (ohne Mail)')
            : 'Zertifikat freigeschaltet: Anmeldung ' . $anmId . ($mail ? '' : ' (ohne Mail)'), $kontoId);
    }
    return count($ids);
}

/** Freischaltung zurücknehmen (z. B. versehentlich). */
function zertifikat_sperren(int $anmId, ?int $kontoId): bool
{
    $n = db_ausfuehren('UPDATE anmeldung SET zertifikat_am = NULL WHERE id = ? AND zertifikat_am IS NOT NULL', [$anmId])->rowCount();
    if ($n === 1) {
        audit_schreiben('teilnahme', 'Zertifikat gesperrt: Anmeldung ' . $anmId, $kontoId);
    }
    return $n === 1;
}

/** Mail „Zertifikat verfügbar“ mit Link zu „Meine Anmeldung“ (gilt ZERTIFIKAT_LINK_TAGE Tage, einmal). */
function zertifikat_mail(int $anmId, ?int $jetzt = null): void
{
    $a = anm_laden($anmId);
    if ($a === null) {
        return;
    }
    $bis = ($jetzt ?? time()) + ZERTIFIKAT_LINK_TAGE * 86400;
    $token = anm_link_erzeugen($anmId, 'zugang', $bis);
    mail_vorlage_einreihen($a['email'], 'zertifikat', ['vorname' => $a['vorname'], 'link' => anm_link_url($token), 'gueltig' => datum_zeit_text(gmdate('Y-m-d H:i:s', $bis)),
        'name' => tn_name()]);
}

/* ---- Zertifikat: Inhalt -------------------------------------------------- */

/** Nummer der Bescheinigung: „WS27-0142“ (Jahr der Veranstaltung, Nummer der Anmeldung). */
function zertifikat_nr(int $anmId): string
{
    return 'WS' . substr(va('veranstaltung_datum'), 2, 2) . '-' . sprintf('%04d', $anmId);
}

/** Vorträge und Fallbesprechungen im Plenum (nicht einem Workshop-Block zugeordnet): ['zeit' => …, 'titel' => [...]] oder null. */
function zertifikat_plenum(): ?array
{
    $punkte = array_filter(programm_veroeffentlicht()['punkte'] ?? [], fn($p) => in_array($p['typ'], ['vortrag', 'fall'], true) && empty($p['block_id']));
    $punkte = programm_sortieren(array_values($punkte));
    if ($punkte === []) {
        return null;
    }
    $beginn = array_filter(array_column($punkte, 'beginn'));
    $ende = array_filter(array_column($punkte, 'ende'));
    $fall = in_array('fall', array_column($punkte, 'typ'), true);
    return [
        'zeit' => $beginn !== [] && $ende !== [] ? min($beginn) . '–' . max($ende) : '',
        'art' => $fall && in_array('vortrag', array_column($punkte, 'typ'), true) ? 'Vorträge und Fallbesprechungen' : ($fall ? 'Fallbesprechungen' : 'Vorträge'),
        'titel' => array_map(fn($p) => $p['titel'], $punkte),
    ];
}

/** Daten für das PDF einer Anmeldung (besuchte Workshops: fest und nicht „nicht erschienen“). */
function zertifikat_daten(array $anm): array
{
    $ws = [];
    foreach (anm_plaetze((int)$anm['id']) as $p) {
        if ((int)$p['termin_id'] === 0 || $p['status'] !== 'fest' || (int)$p['nicht_erschienen'] === 1) {
            continue;
        }
        $t = anm_termin((int)$p['termin_id']);
        if ($t !== null) {
            $ws[] = ['zeit' => $t['beginn'] . '–' . $t['ende'], 'kuerzel' => $t['workshop']['kuerzel'], 'titel' => $t['workshop']['titel'], 'beginn' => $t['beginn']];
        }
    }
    usort($ws, fn($x, $y) => $x['beginn'] <=> $y['beginn']);
    return [
        'name' => trim($anm['vorname'] . ' ' . $anm['nachname']),
        'efn' => $anm['berufsgruppe'] === (va_liste('berufsgruppen')[0] ?? '') ? ($anm['efn'] ?? '') : '',
        'nr' => zertifikat_nr((int)$anm['id']),
        'plenum' => zertifikat_plenum(),
        'workshops' => $ws,
    ];
}

/** Beispiel für die Vorschau im Team-Bereich (erfundene Person). */
function zertifikat_beispiel(): array
{
    $ws = [];
    foreach (array_slice(anm_workshops(), 0, 2) as $w) {
        $t = $w['termine'][0] ?? null;
        if ($t !== null) {
            $ws[] = ['zeit' => $t['beginn'] . '–' . $t['ende'], 'kuerzel' => $w['kuerzel'], 'titel' => $w['titel'], 'beginn' => $t['beginn']];
        }
    }
    return ['name' => 'Dr. Lea Beispiel', 'efn' => '802761234567890', 'nr' => zertifikat_nr(42), 'plenum' => zertifikat_plenum(), 'workshops' => $ws];
}

/* ---- Unterschrift (PNG) -------------------------------------------------- */

/** Unterschrift der Veranstaltung des Kontexts (je Veranstaltung, E-58). */
function unterschrift_pfad(): string
{
    $pfad = laufzeit_pfad('zertifikat/' . va_id()) . '/unterschrift.png';
    // Ablage bis 0.17 (eine Veranstaltung): einmal an die neue Stelle der ersten Veranstaltung schieben
    $alt = laufzeit_pfad('zertifikat') . '/unterschrift.png';
    if (va_id() === 1 && is_file($alt) && !is_file($pfad)) {
        @rename($alt, $pfad);
    }
    return $pfad;
}

function unterschrift_da(): bool
{
    return is_file(unterschrift_pfad());
}

/**
 * Bild der Unterschrift speichern: PNG oder JPG, höchstens UNTERSCHRIFT_BREITE
 * breit, als PNG neu geschrieben (Transparenz bleibt, Metadaten fallen weg).
 */
function unterschrift_speichern(array $upload, ?int $kontoId): void
{
    if (!foto_moeglich()) {
        throw new RuntimeException('Bilder werden auf diesem Server nicht unterstützt (PHP-Erweiterung GD fehlt).');
    }
    $fehler = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($fehler === UPLOAD_ERR_INI_SIZE || $fehler === UPLOAD_ERR_FORM_SIZE || (int)($upload['size'] ?? 0) > UNTERSCHRIFT_MAX_BYTES) {
        throw new RuntimeException('Das Bild ist zu groß (höchstens 4 MB).');
    }
    if ($fehler !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
        throw new RuntimeException('Das Bild kam nicht an. Bitte noch einmal versuchen.');
    }
    unterschrift_schreiben((string)file_get_contents((string)$upload['tmp_name']));
    audit_schreiben('teilnahme', 'Unterschrift für das Zertifikat hochgeladen', $kontoId);
}

/** Bilddaten prüfen und als PNG ablegen (auch für Tests und Beispieldaten). */
function unterschrift_schreiben(string $daten): void
{
    $info = @getimagesizefromstring($daten);
    if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || $info[0] * $info[1] > 40_000_000) {
        throw new RuntimeException('Bitte die Unterschrift als PNG (am besten mit durchsichtigem Hintergrund) oder JPG hochladen.');
    }
    $bild = @imagecreatefromstring($daten);
    if ($bild === false) {
        throw new RuntimeException('Das Bild lässt sich nicht lesen.');
    }
    $w = imagesx($bild);
    $h = imagesy($bild);
    $nw = min($w, UNTERSCHRIFT_BREITE);
    $nh = max(1, (int)round($h * $nw / $w));
    $ziel = imagecreatetruecolor($nw, $nh);
    imagealphablending($ziel, false);
    imagesavealpha($ziel, true);
    imagefill($ziel, 0, 0, imagecolorallocatealpha($ziel, 255, 255, 255, 127));
    imagecopyresampled($ziel, $bild, 0, 0, 0, 0, $nw, $nh, $w, $h);
    if (!imagepng($ziel, unterschrift_pfad(), 9)) {
        throw new RuntimeException('Das Bild konnte nicht gespeichert werden.');
    }
    imagedestroy($bild);
    imagedestroy($ziel);
}

function unterschrift_entfernen(?int $kontoId): void
{
    if (unterschrift_da()) {
        @unlink(unterschrift_pfad());
        audit_schreiben('teilnahme', 'Unterschrift für das Zertifikat entfernt', $kontoId);
    }
}

/* ---- Zertifikat: PDF (Entwurf C) ----------------------------------------- */

/** Teilnahmebescheinigung als PDF (Bytes). $d aus zertifikat_daten() oder zertifikat_beispiel(). */
function zertifikat_pdf(array $d): string
{
    $pdf = export_pdf_neu(false, 'Teilnahmebescheinigung ' . $d['name'], '');
    $pdf->seitenzahl = false;
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(17, 14, 17);
    $pdf->AddPage();
    $f = PDF_FARBEN;
    $links = 17.0;
    $breite = 176.0;

    // Grund: Papier hell (blue-50)
    $pdf->SetFillColor(246, 248, 250);
    $pdf->Rect(0, 0, 210, 297, 'F');

    // Kopf: Logo, Name der Veranstaltung, Badge „3. Auflage · 2027“
    // Nur die Bildmarke (Admin 2026-10-30); die Datei hat eine Schutzzone von 7 % rundum
    $pdf->Image(export_pdf_ordner() . '/ALLGAEU-AKUT_Bildzeichen_lila.png', $links - 1.35, 12.5, 19.0);
    // Name der Veranstaltung groß über die ganze Breite neben der Bildmarke (Admin 2026-10-30)
    $name = mb_strtoupper(tn_name());
    $nb = $links + $breite - 38.5;
    $groesse = 30.0;
    $pdf->SetFont('antonio', 'B', $groesse);
    while ($pdf->GetStringWidth($name) > $nb && $groesse > 12) {
        $groesse -= 0.5;
        $pdf->SetFont('antonio', 'B', $groesse);
    }
    $pdf->SetTextColor(...$f['text']);
    // Versalien optisch mittig zur Bildmarke (sichtbar 13,8–25,1 mm, Mitte 19,5 mm)
    $pdf->SetXY(38.5, 19.47 - $groesse * 0.3528 * 0.476);
    $pdf->Cell($nb, $groesse * 0.3528 * 1.24, $name, 0, 0, 'L');
    // Badge „3. Auflage · 2027“ darunter rechts, leicht gedreht
    $jahr = substr(va('veranstaltung_datum'), 0, 4);
    $badge = mb_strtoupper(trim(va('veranstaltung_auflage') !== '' ? va('veranstaltung_auflage') . ' · ' . $jahr : $jahr));
    $pdf->SetFont('anton', '', 13.5);
    $bw = $pdf->GetStringWidth($badge) + 7.4;
    $bx = $links + $breite - $bw;
    $by = 31.0;
    $pdf->drehen(-3, $bx + $bw / 2, $by + 4.6);
    $pdf->SetFillColor(181, 189, 0);
    $pdf->Rect($bx + 1.6, $by + 1.6, $bw, 9.2, 'F');
    $pdf->SetFillColor(...$f['text']);
    $pdf->Rect($bx, $by, $bw, 9.2, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY($bx, $by + 0.2);
    $pdf->Cell($bw, 9, $badge, 0, 0, 'C');
    $pdf->drehen_ende();

    // Kicker, Titel, Einleitung
    $pdf->SetXY($links, 43.5);
    $pdf->SetFont('antonio', 'B', 13.5);
    $pdf->SetTextColor(...$f['akzent']);
    $pdf->Cell(0, 6, 'TEILNAHMEBESCHEINIGUNG · ' . mb_strtoupper(tag_text(va('veranstaltung_datum'))), 0, 1);
    $pdf->SetFont('anton', '', 64);
    $pdf->SetTextColor(...$f['text']);
    $pdf->SetXY($links, 53.0);
    $pdf->Cell(0, 24, 'NICHT NUR', 0, 1);
    $pdf->SetX($links);
    $pdf->SetTextColor(...$f['akzent']);
    $pdf->Cell(0, 24, 'DABEI GEWESEN.', 0, 1);
    $pdf->SetXY($links, 104.5);
    $pdf->SetFont('tajawal', '', 12.5);
    $pdf->SetTextColor(...$f['text']);
    $pdf->Cell(0, 6.5, 'Zugehört, mitgemacht, geübt: beim ' . tn_name() . '.', 0, 1);
    $pdf->SetX($links);
    $pdf->SetFont('mono', '', 10.5);
    $pdf->SetTextColor(...$f['meta']);
    $pdf->Cell(0, 6, implode(' · ', array_filter([tag_text(va('veranstaltung_datum')), va('veranstaltung_beginn') . '–' . va('veranstaltung_ende'), va('veranstaltung_ort')])), 0, 1);

    // Zettel mit Name und Programm (leicht gedreht, Klebeband oben)
    $zy = 127.0;
    $innen = $breite - 17.0;
    $pdf->SetFont('tajawal', '', 11.25);
    $zeilen = [];
    if ($d['plenum'] !== null) {
        $zeilen[] = ['zeit' => $d['plenum']['zeit'], 'fett' => $d['plenum']['art'], 'text' => implode(', ', $d['plenum']['titel'])];
    }
    foreach ($d['workshops'] as $w) {
        $zeilen[] = ['zeit' => $w['zeit'], 'fett' => $w['kuerzel'] !== '' ? $w['kuerzel'] : 'Workshop', 'text' => $w['titel']];
    }
    $textBreite = $innen - 37.0;
    $hoehen = [];
    foreach ($zeilen as $i => $z) {
        $hoehen[$i] = 5.6 * export_pdf_linien($pdf, $textBreite, $z['fett'] . ' · ' . $z['text']);
    }
    $zh = 8.5 + 4.5 + 13.0 + ($d['efn'] !== '' ? 6.0 : 0) + 4.8 + array_sum($hoehen) + 2.6 * max(0, count($zeilen) - 1) + 7.5;
    $pdf->drehen(0.8, $links + $breite / 2, $zy + $zh / 2);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(...$f['linie']);
    $pdf->SetLineWidth(0.26);
    $pdf->Rect($links, $zy, $breite, $zh, 'DF');
    $y = $zy + 8.5;
    $pdf->SetXY($links + 8.5, $y);
    $pdf->SetFont('antonio', 'B', 9.75);
    $pdf->SetTextColor(...$f['meta']);
    $pdf->Cell(0, 4.5, 'TEILGENOMMEN HAT', 0, 1);
    $y += 4.5;
    $groesse = 33.0;
    $pdf->SetFont('anton', '', $groesse);
    while ($pdf->GetStringWidth(mb_strtoupper($d['name'])) > $innen && $groesse > 18) {
        $groesse -= 1;
        $pdf->SetFont('anton', '', $groesse);
    }
    $pdf->SetTextColor(...$f['text']);
    $pdf->SetXY($links + 8.5, $y);
    $pdf->Cell(0, 13.0, mb_strtoupper($d['name']), 0, 1);
    $y += 13.0;
    if ($d['efn'] !== '') {
        $pdf->SetXY($links + 8.5, $y);
        $pdf->SetFont('mono', '', 10.5);
        $pdf->SetTextColor(...$f['meta']);
        $pdf->Cell(0, 6, 'EFN ' . $d['efn'], 0, 1);
        $y += 6.0;
    }
    $y += 4.8;
    foreach ($zeilen as $i => $z) {
        $pdf->SetDrawColor(142, 108, 206);
        $pdf->SetLineWidth(0.8);
        $pdf->linienzug([[$links + 9.0, $y + 2.9], [$links + 10.4, $y + 4.3], [$links + 12.8, $y + 1.5]]);
        $pdf->SetFont('mono', '', 9.75);
        $pdf->SetTextColor(...$f['text']);
        $pdf->SetXY($links + 15.5, $y);
        $pdf->Cell(27, 5.6, $z['zeit'], 0, 0);
        $pdf->SetXY($links + 8.5 + 37.0, $y);
        $pdf->SetFont('tajawal', 'B', 11.25);
        $pdf->Write(5.6, $z['fett']);
        $pdf->SetFont('tajawal', '', 11.25);
        // Umbruch innerhalb der Spalte: rechter Rand vorübergehend auf die Textspalte
        $pdf->SetRightMargin(210 - ($links + 8.5 + 37.0 + $textBreite));
        $pdf->SetLeftMargin($links + 8.5 + 37.0);
        $pdf->Write(5.6, ' · ' . $z['text']);
        $pdf->SetLeftMargin($links);
        $pdf->SetRightMargin(17);
        $y += $hoehen[$i] + 2.6;
    }
    $pdf->drehen_ende();
    // Klebeband (Grün, 85 % auf Papier gemischt)
    $pdf->drehen(4, $links + 10.6 + 15.9, $zy);
    $pdf->SetFillColor(191, 198, 38);
    $pdf->Rect($links + 10.6, $zy - 3.7, 31.8, 7.4, 'F');
    $pdf->drehen_ende();

    // Stempel „Teilnahme bestätigt“
    $sx = 173.6;
    $sy = max(210.6, $zy + $zh + 10.0);
    $pdf->drehen(-9, $sx, $sy);
    $pdf->SetDrawColor(...$f['akzent']);
    $pdf->SetLineWidth(0.66);
    $pdf->kreis($sx, $sy, 17.1);
    $pdf->SetLineWidth(0.4);
    $pdf->kreis($sx, $sy, 14.9);
    $pdf->SetTextColor(...$f['akzent']);
    $pdf->SetFont('mono', '', 7.5);
    $pdf->SetXY($sx - 15, $sy - 8.3);
    $pdf->Cell(30, 4, 'TEILNAHME', 0, 2, 'C');
    $pdf->SetFont('mono', '', 12);
    $pdf->Cell(30, 6, 'BESTÄTIGT', 0, 2, 'C');
    $pdf->SetFont('mono', '', 7.5);
    $pdf->Cell(30, 4, date('d.m.Y', (int)strtotime(va('veranstaltung_datum'))), 0, 2, 'C');
    $pdf->drehen_ende();

    // Fuß: Punkte links, Unterschrift rechts, Fußnote, Adresse
    $punkte = va('cme_punkte');
    $kammer = va('cme_kammer');
    $unten = 268.0;
    if ($punkte !== '') {
        $pdf->SetXY($links, $unten - 16.5);
        $pdf->SetFont('antonio', 'B', 10.5);
        $pdf->SetTextColor(...$f['text']);
        $pdf->Cell(80, 5, 'FORTBILDUNGSPUNKTE' . ($kammer !== '' ? '¹' : ''), 0, 2);
        $pdf->SetFont('mono', '', 9.75);
        $pdf->Cell(80, 5.5, $punkte . ' ' . ((int)$punkte === 1 ? 'Punkt' : 'Punkte') . (va('cme_kategorie') !== '' ? ' · Kategorie ' . va('cme_kategorie') : ''), 0, 2);
        if (va('cme_veranstaltungsnummer') !== '') {
            $pdf->Cell(80, 5.5, 'VNR ' . va('cme_veranstaltungsnummer'), 0, 2);
        }
    }
    $ux = 124.4;
    $linieY = 255.0;
    if (unterschrift_da()) {
        $info = @getimagesize(unterschrift_pfad());
        if ($info !== false && $info[0] > 0) {
            $h = 16.0;
            $w = $h * $info[0] / $info[1];
            if ($w > 62.0) {
                $w = 62.0;
                $h = $w * $info[1] / $info[0];
            }
            $pdf->Image(unterschrift_pfad(), $ux, $linieY - 0.8 - $h, $w, $h);
        }
    }
    $pdf->SetDrawColor(...$f['text']);
    $pdf->SetLineWidth(0.26);
    $pdf->Line($ux, $linieY, $links + $breite, $linieY);
    $pdf->SetXY($ux, $linieY + 1.8);
    $pdf->SetFont('tajawal', '', 10.5);
    $pdf->SetTextColor(...$f['text']);
    if (va('zertifikat_leitung') !== '') {
        $pdf->Cell(68, 5, va('zertifikat_leitung'), 0, 2);
    }
    $pdf->SetTextColor(...$f['meta']);
    $pdf->Cell(68, 5, va('zertifikat_leitung_rolle'), 0, 2);

    // Fußzeile in einer Zeile: links Hinweis und Nummer, rechts die Webadresse, beides grün (Admin 2026-10-31).
    // Passt der Kammer-Hinweis nicht mehr daneben, steht er eine Zeile darüber.
    $kammerHinweis = $punkte !== '' && $kammer !== '' ? '¹ anerkannt durch die ' . $kammer : '';
    $domain = (string)parse_url(url('/'), PHP_URL_HOST);
    $pdf->SetFont('mono', '', 8.25);
    $pdf->SetTextColor(107, 112, 0);
    $links_text = implode(' · ', array_filter([$kammerHinweis, 'Teilnahme vom Team erfasst', 'Nr. ' . $d['nr']]));
    if ($kammerHinweis !== '' && $pdf->GetStringWidth($links_text) + $pdf->GetStringWidth($domain) + 6 > $breite) {
        $pdf->SetXY($links, 276.5);
        $pdf->Cell($breite, 4, $kammerHinweis, 0, 0, 'L');
        $links_text = 'Teilnahme vom Team erfasst · Nr. ' . $d['nr'];
    }
    $pdf->SetXY($links, 281.0);
    $pdf->Cell($breite, 4.5, $links_text, 0, 0, 'L');
    $pdf->SetXY($links, 281.0);
    $pdf->Cell($breite, 4.5, $domain, 0, 0, 'R');
    return $pdf->Output('S');
}

/** Langer Name: an der ersten Lücke umbrechen, damit er zweizeilig neben dem Logo steht. */
function zertifikat_umbruch(string $name): string
{
    $teile = explode(' ', trim($name), 2);
    return count($teile) === 2 ? $teile[0] . "\n" . $teile[1] : $name;
}

/* ---- Barcode (Code 128, Zeichensatz B) ----------------------------------- */

/** Balkenbreiten je Zeichenwert 0–105 und Stopp (106), je sechs bzw. sieben Module abwechselnd Balken/Lücke. */
const CODE128_MUSTER = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
    '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
    '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
    '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
    '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
    '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
    '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
    '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
    '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
    '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];

/** Module (Balkenbreiten) für einen Text in Code 128 B, mit Prüfzeichen; null bei Zeichen außerhalb von ASCII 32–126. */
function code128_module(string $text): ?array
{
    $werte = [104];
    foreach (str_split($text) as $z) {
        $o = ord($z);
        if ($o < 32 || $o > 126) {
            return null;
        }
        $werte[] = $o - 32;
    }
    $summe = 104;
    foreach (array_slice($werte, 1) as $i => $w) {
        $summe += ($i + 1) * $w;
    }
    $werte[] = $summe % 103;
    $werte[] = 106;
    $module = [];
    foreach ($werte as $w) {
        foreach (str_split(CODE128_MUSTER[$w]) as $b) {
            $module[] = (int)$b;
        }
    }
    return $module;
}

/** Barcode zeichnen: x/y oben links, Modulbreite und Höhe in mm. Gibt die Breite zurück. */
function code128_zeichnen(tFPDF $pdf, string $text, float $x, float $y, float $modul, float $hoehe): float
{
    $module = code128_module($text) ?? [];
    $pdf->SetFillColor(0, 0, 0);
    $pos = $x;
    foreach ($module as $i => $b) {
        if ($i % 2 === 0) {
            $pdf->Rect($pos, $y, $b * $modul, $hoehe, 'F');
        }
        $pos += $b * $modul;
    }
    return $pos - $x;
}

/* ---- Unterschriftenliste ------------------------------------------------- */

/**
 * Unterschriftenliste als PDF (Bytes): alle mit festem Platz oder nur ÄrztInnen
 * (mit EFN als Barcode bzw. Feld fürs Etikett).
 */
function unterschriftenliste_pdf(bool $nurAerzte, string $sortierung): string
{
    $sortierung = isset(TN_SORTIERUNG[$sortierung]) ? $sortierung : 'nachname';
    $liste = tn_sortieren(array_values(array_filter(tn_liste(), fn($e) => !$nurAerzte || $e['aerztin'])), $sortierung);
    $titel = $nurAerzte ? 'Unterschriftenliste ÄrztInnen' : 'Unterschriftenliste';
    $stand = 'Stand ' . datum_zeit_text(gmdate('Y-m-d H:i:s'));
    $pdf = export_pdf_neu(false, $titel, 'Vertraulich – nur für die Organisation · ' . $stand);
    $pdf->SetAutoPageBreak(false);
    // Spalten: Nr, Nachname, Vorname, Berufsgruppe bzw. EFN, Unterschrift (zusammen 178 mm)
    $sp = $nurAerzte
        ? [['NR.', 10], ['NACHNAME', 34], ['VORNAME', 28], ['EFN (BARCODE) ODER ETIKETT', 62], ['UNTERSCHRIFT', 44]]
        : [['NR.', 10], ['NACHNAME', 40], ['VORNAME', 34], ['BERUFSGRUPPE', 40], ['UNTERSCHRIFT', 54]];
    $zeile = $nurAerzte ? 15.0 : 11.0;
    $kopf = function () use ($pdf, $sp): void {
        $pdf->SetFont('antonio', 'B', 8);
        $pdf->SetFillColor(...PDF_FARBEN['text']);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetX(16);
        foreach ($sp as [$t, $w]) {
            $pdf->Cell($w, 7, $t, 0, 0, 'L', true);
        }
        $pdf->Ln();
    };
    $sub = tn_name() . ' · ' . tag_text(va('veranstaltung_datum')) . ' · sortiert nach ' . TN_SORTIERUNG[$sortierung] . ' · ' . count($liste) . ' ' . (count($liste) === 1 ? 'Person' : 'Personen');
    $pdf->AddPage();
    export_pdf_seitenkopf($pdf, va_kurz() . ' · ' . tag_text(va('veranstaltung_datum')), $titel, $sub, $stand);
    $pdf->Ln(3);
    $kopf();
    if ($liste === []) {
        $pdf->SetFont('tajawal', '', 9.5);
        $pdf->SetTextColor(...PDF_FARBEN['meta']);
        $pdf->Cell(0, 8, 'Niemand eingetragen.', 0, 1);
    }
    foreach ($liste as $n => $e) {
        if ($pdf->GetY() + $zeile > 297 - 18) {
            $pdf->AddPage();
            $pdf->SetY(16);
            $kopf();
        }
        $y = $pdf->GetY();
        $a = $e['anm'];
        $pdf->SetTextColor(...PDF_FARBEN['text']);
        $pdf->SetX(16);
        $pdf->SetFont('mono', '', 9);
        $pdf->Cell($sp[0][1], $zeile, (string)($n + 1), 0, 0);
        $pdf->SetFont('tajawal', 'B', 10);
        $pdf->Cell($sp[1][1], $zeile, zertifikat_kuerzen($pdf, $a['nachname'], $sp[1][1] - 2), 0, 0);
        $pdf->SetFont('tajawal', '', 10);
        $pdf->Cell($sp[2][1], $zeile, zertifikat_kuerzen($pdf, $a['vorname'], $sp[2][1] - 2), 0, 0);
        if ($nurAerzte) {
            $x = $pdf->GetX();
            if (($a['efn'] ?? '') !== '') {
                code128_zeichnen($pdf, '*' . $a['efn'] . '*', $x + 1.5, $y + 1.6, 0.25, 8.4);
                $pdf->SetFont('mono', '', 7.5);
                $pdf->SetXY($x + 1.5, $y + 10.1);
                $pdf->Cell($sp[3][1] - 3, 3.6, efn_text($a['efn']), 0, 0, 'L');
            } else {
                $pdf->SetDrawColor(...PDF_FARBEN['linie']);
                $pdf->SetLineWidth(0.3);
                $pdf->Rect($x + 1.5, $y + 1.5, $sp[3][1] - 4, $zeile - 3, 'D');
                $pdf->SetFont('tajawal', '', 7.5);
                $pdf->SetTextColor(...PDF_FARBEN['meta']);
                $pdf->SetXY($x + 3, $y + 2.2);
                $pdf->Cell($sp[3][1] - 6, 4, 'EFN-Etikett hier aufkleben', 0, 0);
            }
            $pdf->SetXY($x + $sp[3][1], $y);
        } else {
            $pdf->SetFont('tajawal', '', 9.5);
            $pdf->SetTextColor(...PDF_FARBEN['meta']);
            $pdf->Cell($sp[3][1], $zeile, zertifikat_kuerzen($pdf, $a['berufsgruppe'] === 'Sonstige' && $a['beruf_sonstige'] !== '' ? $a['beruf_sonstige'] : $a['berufsgruppe'], $sp[3][1] - 2), 0, 0);
        }
        $pdf->SetDrawColor(...PDF_FARBEN['linie']);
        $pdf->SetLineWidth(0.26);
        $pdf->Line(16, $y + $zeile, 194, $y + $zeile);
        $pdf->SetXY(16, $y + $zeile);
    }
    return $pdf->Output('S');
}

/** Text auf eine Breite kürzen (mit …). */
function zertifikat_kuerzen(tFPDF $pdf, string $text, float $breite): string
{
    if ($pdf->GetStringWidth($text) <= $breite) {
        return $text;
    }
    while (mb_strlen($text) > 1 && $pdf->GetStringWidth($text . '…') > $breite) {
        $text = mb_substr($text, 0, -1);
    }
    return $text . '…';
}
