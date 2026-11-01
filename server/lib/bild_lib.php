<?php
declare(strict_types=1);

/*
 * Bilder und Dateien (AP-17 Unterpunkt 10, V-12): ein Weg für hochgeladene
 * Bilder (Mailings und Texte, Fotos der Dozierenden, Unterschrift fürs
 * Zertifikat) und für das Ausliefern von Dateien. Bilder liegen im
 * Laufzeitordner (bilder/), werden beim Hochladen neu geschrieben (keine
 * Metadaten, keine fremden Formate) und nie im Original ausgeliefert.
 */

const FOTO_MAX_BYTES = 8 * 1024 * 1024;

const FOTO_KANTE = 480;

function foto_moeglich(): bool
{
    return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
}

function foto_ordner(): string
{
    return laufzeit_pfad('bilder');
}

/**
 * Hochgeladenes Bild prüfen, quadratisch zuschneiden, auf FOTO_KANTE verkleinern
 * und als JPEG neu schreiben (entfernt Metadaten). Gibt den Dateinamen zurück
 * oder wirft eine RuntimeException mit einem Text für die Oberfläche.
 */
function foto_speichern(int $dozentId, array $upload): string
{
    $b = bild_verkleinern(bild_upload_lesen($upload, FOTO_MAX_BYTES, 'Foto'), FOTO_KANTE, true,
        ['typen' => [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], 'falsch' => 'Bitte ein Foto als JPG, PNG oder WebP hochladen.']);
    $name = 'dozent-' . $dozentId . '-' . bin2hex(random_bytes(6)) . '.jpg';
    bild_schreiben($b['bild'], foto_ordner() . '/' . $name, false);
    db_ausfuehren('UPDATE dozent SET foto = ?, geaendert_am = ? WHERE id = ?', [$name, db_jetzt(), $dozentId]);
    return $name;
}

function foto_entfernen(int $dozentId): void
{
    db_ausfuehren('UPDATE dozent SET foto = NULL, geaendert_am = ? WHERE id = ?', [db_jetzt(), $dozentId]);
}

/** Fotos löschen, die weder im Arbeitsstand noch im veröffentlichten Stand vorkommen (Job 'aufraeumen'). */
function foto_aufraeumen(): int
{
    if (!db_tabelle_da('dozent')) {
        return 0;
    }
    // Genutzt: Dozierende und alle veröffentlichten Stände aller Veranstaltungen, auch
    // die Dozierenden an Terminen – Archivseiten zeigen sie weiter (Review B-8)
    $genutzt = array_filter(array_column(db_zeilen('SELECT foto FROM dozent'), 'foto'));
    $sammeln = function (mixed $knoten) use (&$sammeln, &$genutzt): void {
        if (!is_array($knoten)) {
            return;
        }
        foreach ($knoten as $schluessel => $wert) {
            if ($schluessel === 'foto' && is_string($wert) && $wert !== '') {
                $genutzt[] = $wert;
            } else {
                $sammeln($wert);
            }
        }
    };
    foreach (db_zeilen('SELECT daten FROM programm_stand') as $z) {
        $sammeln(json_decode((string)$z['daten'], true));
    }
    $n = 0;
    foreach (glob(foto_ordner() . '/dozent-*.jpg') ?: [] as $datei) {
        if (!in_array(basename($datei), $genutzt, true) && filemtime($datei) < time() - 3600 && @unlink($datei)) {
            $n++;
        }
    }
    return $n;
}

/**
 * Hochgeladene Datei lesen (V-12): Fehler beim Upload, Größe (höchstens
 * $maxBytes) und echte Upload-Datei prüfen. $was erscheint in den Meldungen
 * („Das Foto ist zu groß …“). Gibt den Inhalt zurück oder wirft RuntimeException.
 */
function bild_upload_lesen(array $upload, int $maxBytes = RM_BILD_MAX_BYTES, string $was = 'Bild'): string
{
    if (!foto_moeglich()) {
        throw new RuntimeException('Bilder werden auf diesem Server nicht unterstützt (PHP-Erweiterung GD fehlt).');
    }
    $fehler = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($fehler === UPLOAD_ERR_INI_SIZE || $fehler === UPLOAD_ERR_FORM_SIZE || (int)($upload['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Das ' . $was . ' ist zu groß (höchstens ' . bytes_text($maxBytes) . ').');
    }
    if ($fehler !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
        throw new RuntimeException('Das ' . $was . ' kam nicht an. Bitte noch einmal versuchen.');
    }
    return (string)file_get_contents((string)$upload['tmp_name']);
}

/**
 * Bild prüfen und verkleinern (V-12). Gibt ['bild' => GdImage, 'png' => bool,
 * 'breite' => int, 'hoehe' => int] zurück; speichern muss der Aufrufer.
 *   $kante   längste Kante (bzw. Breite mit 'nur_breite', Seite mit $quadrat)
 *   $quadrat mittig quadratisch zuschneiden (Fotos)
 * $opt: typen (IMAGETYPE_…, Standard JPEG und PNG), falsch (Meldung bei
 *       falschem Typ), nur_breite (nur die Breite begrenzen), transparent
 *       (Fläche mit Alpha, auch bei JPEG: Unterschrift)
 */
function bild_verkleinern(string $daten, int $kante, bool $quadrat = false, array $opt = []): array
{
    $typen = $opt['typen'] ?? [IMAGETYPE_JPEG, IMAGETYPE_PNG];
    $info = @getimagesizefromstring($daten);
    if ($info === false || !in_array($info[2], $typen, true) || $info[0] * $info[1] > 40_000_000) {
        throw new RuntimeException((string)($opt['falsch'] ?? 'Bitte ein Bild als JPG oder PNG hochladen.'));
    }
    $bild = @imagecreatefromstring($daten);
    if ($bild === false) {
        throw new RuntimeException('Das Bild lässt sich nicht lesen.');
    }
    $w = imagesx($bild);
    $h = imagesy($bild);
    $png = $info[2] === IMAGETYPE_PNG;
    if ($quadrat) {
        $seite = min($w, $h);
        $ziel = imagecreatetruecolor($kante, $kante);
        imagecopyresampled($ziel, $bild, 0, 0, intdiv($w - $seite, 2), intdiv($h - $seite, 2), $kante, $kante, $seite, $seite);
        [$nw, $nh] = [$kante, $kante];
    } else {
        $f = !empty($opt['nur_breite']) ? min(1, $kante / $w) : min(1, $kante / max($w, $h));
        $nw = !empty($opt['nur_breite']) ? min($w, $kante) : max(1, (int)round($w * $f));
        $nh = !empty($opt['nur_breite']) ? max(1, (int)round($h * $nw / $w)) : max(1, (int)round($h * $f));
        $ziel = imagecreatetruecolor($nw, $nh);
        if ($png || !empty($opt['transparent'])) {
            imagealphablending($ziel, false);
            imagesavealpha($ziel, true);
        }
        if (!empty($opt['transparent'])) {
            imagefill($ziel, 0, 0, imagecolorallocatealpha($ziel, 255, 255, 255, 127));
        }
        imagecopyresampled($ziel, $bild, 0, 0, 0, 0, $nw, $nh, $w, $h);
    }
    imagedestroy($bild);
    return ['bild' => $ziel, 'png' => $png, 'breite' => $nw, 'hoehe' => $nh];
}

/** Bild als Datei schreiben (PNG oder JPEG 85); wirft RuntimeException, wenn es nicht geht. */
function bild_schreiben(GdImage $bild, string $pfad, bool $png, int $pngStufe = 6): void
{
    $ok = $png ? imagepng($bild, $pfad, $pngStufe) : imagejpeg($bild, $pfad, 85);
    imagedestroy($bild);
    if (!$ok) {
        throw new RuntimeException('Das Bild konnte nicht gespeichert werden.');
    }
}

/** Datei aus dem Laufzeitordner ausliefern (Bilder, Sicherungen) und beenden. */
function bild_senden(string $pfad, string $typ, string $cache = 'private, no-store', ?string $anhangName = null): never
{
    header('Content-Type: ' . $typ);
    if ($anhangName !== null) {
        header('Content-Disposition: attachment; filename="' . $anhangName . '"');
    }
    header('Content-Length: ' . filesize($pfad));
    header('Cache-Control: ' . $cache);
    header('X-Content-Type-Options: nosniff');
    readfile($pfad);
    exit;
}

/** Erzeugten Inhalt (PDF, Export) ausliefern und beenden; $anhang = Download statt Anzeige. */
function datei_senden(string $inhalt, string $typ, ?string $name = null, bool $anhang = true, string $cache = 'no-store'): never
{
    header('Content-Type: ' . $typ);
    if ($name !== null) {
        header('Content-Disposition: ' . ($anhang ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    }
    header('Content-Length: ' . strlen($inhalt));
    header('Cache-Control: ' . $cache);
    header('X-Content-Type-Options: nosniff');
    echo $inhalt;
    exit;
}

/**
 * Bild prüfen (JPG/PNG), auf höchstens 1200 px verkleinern, neu schreiben
 * (ohne Metadaten) und als <praefix>-<16 hex>.jpg|png in var/bilder ablegen.
 * Für Mailings (mail-) und Texte der Webseite (text-). Wirft RuntimeException.
 */
function bild_verkleinert_speichern(string $daten, string $praefix): array
{
    $b = bild_verkleinern($daten, RM_BILD_MAX_KANTE);
    $name = $praefix . '-' . bin2hex(random_bytes(8)) . ($b['png'] ? '.png' : '.jpg');
    bild_schreiben($b['bild'], foto_ordner() . '/' . $name, $b['png']);
    return ['datei' => $name, 'typ' => $b['png'] ? 'png' : 'jpeg', 'breite' => $b['breite'], 'hoehe' => $b['hoehe']];
}

/** Bild für einen Text der Webseite hochladen. Gibt den öffentlichen Pfad zurück. */
function text_bild_speichern(array $upload, int $kontoId): string
{
    $b = bild_verkleinert_speichern(bild_upload_lesen($upload), 'text');
    audit_schreiben('texte', 'Bild hochgeladen: ' . $b['datei'], $kontoId);
    return '/bild/' . $b['datei'];
}

/**
 * Text-Bilder, die nach einem Tag nirgends vorkommen, löschen. Gesucht wird in
 * den Texten, im Programm (auch im veröffentlichten Stand) und bei den Dozierenden.
 */
function text_bilder_aufraeumen(int $jetzt): int
{
    $quellen = ['inhalt' => 'text', 'programmpunkt' => 'beschreibung', 'dozent' => 'kurzbio', 'programm_stand' => 'daten'];
    $alle = '';
    foreach ($quellen as $tabelle => $spalte) {
        if (db_tabelle_da($tabelle)) {
            $alle .= str_replace('\\/', '/', implode("\n", array_column(db_zeilen("SELECT $spalte AS t FROM $tabelle WHERE $spalte LIKE '%text-%'"), 't'))) . "\n";
        }
    }
    $n = 0;
    foreach (array_merge(glob(foto_ordner() . '/text-*.jpg') ?: [], glob(foto_ordner() . '/text-*.png') ?: []) as $d) {
        if (!str_contains($alle, '/bild/' . basename($d)) && filemtime($d) < $jetzt - 86400 && @unlink($d)) {
            $n++;
        }
    }
    return $n;
}
