<?php
declare(strict_types=1);

/*
 * Exporte als xlsx und PDF (O-35, E-56). Die Daten liefert export_erzeugen()
 * (statistik_lib.php): Kopf, Zeilen, Titel. CSV bleibt wie bisher.
 *
 * xlsx: selbst geschrieben (Office Open XML, ZipArchive): Kopfzeile in Lila,
 * fixiert, mit Filter; Spaltenbreiten nach Inhalt; Zahlen als Zahl, Datum
 * „TT.MM.JJJJ[, HH:MM]“ als echte Datumszelle. Texte als Inline-Text – nie als
 * Formel (keine Formel-Einschleusung).
 *
 * PDF: tFPDF (LGPL-2.1, server/lib/vendor/tfpdf/HERKUNFT.md), A4 im Branding
 * Allgäu Akut für Papier (heller Modus): Logo, Kicker in Antonio, Titel in
 * Anton, Text in Tajawal, Zahlen in IBM Plex Mono; Nachtblau für Text, Lila
 * 600 als Akzent (Lila 400 nie als Text auf Weiß). Gruppen (Termin, Essen)
 * beginnen je auf einer neuen Seite. Seitenzahlen unten.
 */

const EXPORT_FORMATE = ['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'];

/** Geht das Format auf diesem Server? (xlsx braucht die Erweiterung zip) */
function export_format_moeglich(string $format): bool
{
    return match ($format) {
        'csv' => true,
        'xlsx' => class_exists('ZipArchive'),
        'pdf' => extension_loaded('mbstring') && is_dir(export_pdf_ordner()),
        default => false,
    };
}

/** Datei für das Format: ['datei' => Name, 'typ' => MIME, 'inhalt' => Bytes] oder null. */
function export_datei(array $x, string $format): ?array
{
    return match ($format) {
        'csv' => ['datei' => $x['datei'], 'typ' => 'text/csv; charset=utf-8', 'inhalt' => $x['text']],
        'xlsx' => export_format_moeglich('xlsx') ? ['datei' => $x['basis'] . '.xlsx', 'typ' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'inhalt' => export_xlsx($x)] : null,
        'pdf' => $x['pdf'] !== null && export_format_moeglich('pdf') ? ['datei' => $x['basis'] . '.pdf', 'typ' => 'application/pdf', 'inhalt' => export_pdf($x)] : null,
        default => null,
    };
}

/** Arten ohne PDF (nur Tabellen zum Weiterverarbeiten). */
const EXPORT_OHNE_PDF = ['newsletter'];

/**
 * Knöpfe CSV · Excel · PDF für einen Export (Parameter wie /team/export).
 * Excel nur mit der Erweiterung zip, PDF nicht für EXPORT_OHNE_PDF.
 */
function export_knoepfe(array $param, string $vor = ''): string
{
    $h = [];
    foreach (EXPORT_FORMATE as $f => $name) {
        if (!export_format_moeglich($f) || ($f === 'pdf' && in_array($param['art'] ?? '', EXPORT_OHNE_PDF, true))) {
            continue;
        }
        $q = http_build_query(array_filter($param + ['format' => $f === 'csv' ? null : $f], fn($v) => $v !== null && $v !== ''));
        $h[] = '<a class="knopf zweit klein" href="/team/export?' . e($q) . '" download>' . e($vor . $name) . '</a>';
    }
    return '<span class="export-knoepfe">' . implode(' ', $h) . '</span>';
}

/* ---- xlsx ---------------------------------------------------------------- */

/** Spaltenbuchstabe (0 → A, 26 → AA). */
function xlsx_spalte(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

/** Excel-Seriennummer für „TT.MM.JJJJ“ bzw. „TT.MM.JJJJ, HH:MM“ oder null. */
function xlsx_datum(string $w): ?array
{
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})(?:, (\d{2}):(\d{2}))?$/', $w, $m) !== 1 || !checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        return null;
    }
    $tage = (int)((gmmktime(0, 0, 0, (int)$m[2], (int)$m[1], (int)$m[3]) - gmmktime(0, 0, 0, 12, 30, 1899)) / 86400);
    $zeit = isset($m[4]) ? ((int)$m[4] * 60 + (int)$m[5]) / 1440 : 0.0;
    return ['wert' => isset($m[4]) ? $tage + $zeit : $tage, 'stil' => isset($m[4]) ? 3 : 2];
}

function xlsx_xml(string $s): string
{
    // Steuerzeichen sind in XML nicht erlaubt
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** xlsx-Datei (Bytes) aus Kopf und Zeilen. */
function export_xlsx(array $x): string
{
    $kopf = $x['kopf'];
    $zeilen = $x['zeilen'];
    $breiten = array_map(fn($k) => mb_strlen((string)$k) + 4, $kopf);
    $zellen = function (array $werte, int $r, bool $istKopf) use (&$breiten): string {
        $h = '<row r="' . $r . '">';
        foreach (array_values($werte) as $i => $w) {
            $ref = xlsx_spalte($i) . $r;
            $breiten[$i] = min(60, max($breiten[$i] ?? 8, mb_strlen((string)$w) + 2));
            if ($istKopf) {
                $h .= '<c r="' . $ref . '" s="1" t="inlineStr"><is><t>' . xlsx_xml((string)$w) . '</t></is></c>';
            } elseif (is_int($w) || is_float($w)) {
                $h .= '<c r="' . $ref . '"><v>' . $w . '</v></c>';
            } elseif (($d = xlsx_datum((string)$w)) !== null) {
                $h .= '<c r="' . $ref . '" s="' . $d['stil'] . '"><v>' . $d['wert'] . '</v></c>';
            } elseif ((string)$w !== '') {
                $h .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_xml((string)$w) . '</t></is></c>';
            }
        }
        return $h . '</row>';
    };
    $daten = $zellen($kopf, 1, true);
    foreach ($zeilen as $n => $z) {
        $daten .= $zellen($z, $n + 2, false);
    }
    $letzte = xlsx_spalte(max(0, count($kopf) - 1)) . (count($zeilen) + 1);
    $spalten = '';
    foreach ($breiten as $i => $b) {
        $spalten .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . max(8, $b) . '" customWidth="1"/>';
    }
    $blatt = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>' . $spalten . '</cols><sheetData>' . $daten . '</sheetData>'
        . '<autoFilter ref="A1:' . $letzte . '"/></worksheet>';
    $name = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]/u', ' ', (string)$x['titel']) ?? ''), 0, 31) ?: 'Export';
    $stile = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd.mm.yyyy"/><numFmt numFmtId="165" formatCode="dd.mm.yyyy hh:mm"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF6F52A8"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
    $dateien = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . xlsx_xml('Wintersymposium – ' . $x['titel']) . '</dc:title><dc:creator>Wintersymposium</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . iso_utc(db_jetzt()) . '</dcterms:created></cp:coreProperties>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . xlsx_xml($name) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . str_replace("'", "''", xlsx_xml($name)) . '\'!$A$1:$' . preg_replace('/\d+$/', '', $letzte) . '$' . (count($zeilen) + 1) . '</definedName></definedNames></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => $stile,
        'xl/worksheets/sheet1.xml' => $blatt,
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'wsx');
    $zip = new ZipArchive();
    if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('xlsx: Zip-Datei ließ sich nicht anlegen.');
    }
    foreach ($dateien as $pfad => $inhalt) {
        $zip->addFromString($pfad, $inhalt);
    }
    $zip->close();
    $bytes = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bytes;
}

/* ---- PDF ----------------------------------------------------------------- */

/** Schriften und Logo für die PDFs (Branding, nicht öffentlich). */
function export_pdf_ordner(): string
{
    return dirname(__DIR__) . '/pdf';
}

function export_pdf_laden(): void
{
    if (class_exists('tFPDF', false)) {
        return;
    }
    // Zwischenspeicher der Schriftmaße im Laufzeitordner (tFPDF schreibt nach FPDF_FONTPATH/unifont/)
    $cache = laufzeit_pfad('pdf');
    if (!is_dir($cache . '/unifont')) {
        @mkdir($cache . '/unifont', 0770, true);
    }
    if (!defined('FPDF_FONTPATH')) {
        define('FPDF_FONTPATH', $cache . '/');
    }
    if (!defined('_SYSTEM_TTFONTS')) {
        define('_SYSTEM_TTFONTS', export_pdf_ordner() . '/');
    }
    require_once __DIR__ . '/vendor/tfpdf/ttfonts.php';
    require_once __DIR__ . '/vendor/tfpdf/tfpdf.php';
}

/** Farben (Branding, heller Modus für Papier). */
const PDF_FARBEN = [
    'text' => [0, 30, 58],       // blue-900 Nachtblau
    'meta' => [51, 99, 141],     // blue-500
    'akzent' => [111, 82, 168],  // lila-600
    'linie' => [204, 216, 227],  // blue-200
    'zebra' => [246, 248, 250],  // blue-50
];

/** Neues A4-Dokument mit den Schriften des Brandings und Fußzeile (Hinweis links, Seitenzahl rechts). */
function export_pdf_neu(bool $quer, string $titel, string $fuss): tFPDF
{
    export_pdf_laden();
    $pdf = new class ($quer ? 'L' : 'P', 'mm', 'A4') extends tFPDF {
        public string $fuss = '';
        public bool $seitenzahl = true;
        /** Inhalt drehen (Grad, gegen den Uhrzeigersinn) um den Punkt x/y (mm); mit drehen_ende() schließen. */
        public function drehen(float $grad, float $x, float $y): void
        {
            $w = deg2rad($grad);
            $cx = $x * $this->k;
            $cy = ($this->h - $y) * $this->k;
            $this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm', cos($w), sin($w), -sin($w), cos($w), $cx, $cy, -$cx, -$cy));
        }
        public function drehen_ende(): void
        {
            // Q setzt Farben, Linienstärke und Schrift zurück; tFPDF merkt sich aber die zuletzt
            // gesetzten Werte – deshalb neu ausgeben, sonst erscheint Text in einer falschen Farbe.
            $this->_out('Q');
            $this->_out($this->DrawColor);
            $this->_out($this->FillColor);
            $this->_out(sprintf('%.2F w', $this->LineWidth * $this->k));
            if ($this->CurrentFont) {
                $this->_out(sprintf('BT /F%d %.2F Tf ET', $this->CurrentFont['i'], $this->FontSizePt));
            }
        }
        /** Kreis (Mittelpunkt, Radius in mm), nur Linie. */
        public function kreis(float $x, float $y, float $r): void
        {
            $k = $this->k;
            $hp = $this->h;
            $m = 0.5523 * $r;
            $p = fn(float $a, float $b): string => sprintf('%.2F %.2F', $a * $k, ($hp - $b) * $k);
            $this->_out($p($x + $r, $y) . ' m ' . $p($x + $r, $y - $m) . ' ' . $p($x + $m, $y - $r) . ' ' . $p($x, $y - $r) . ' c '
                . $p($x - $m, $y - $r) . ' ' . $p($x - $r, $y - $m) . ' ' . $p($x - $r, $y) . ' c '
                . $p($x - $r, $y + $m) . ' ' . $p($x - $m, $y + $r) . ' ' . $p($x, $y + $r) . ' c '
                . $p($x + $m, $y + $r) . ' ' . $p($x + $r, $y + $m) . ' ' . $p($x + $r, $y) . ' c S');
        }
        /** Linienzug mit runden Enden (Häkchen, Handstrich); Punkte als [[x, y], …] in mm. */
        public function linienzug(array $punkte): void
        {
            $k = $this->k;
            $teile = [];
            foreach (array_values($punkte) as $i => [$x, $y]) {
                $teile[] = sprintf('%.2F %.2F %s', $x * $k, ($this->h - $y) * $k, $i === 0 ? 'm' : 'l');
            }
            $this->_out('q 1 J 1 j ' . implode(' ', $teile) . ' S Q');
        }
        public function Footer(): void
        {
            if ($this->fuss === '' && !$this->seitenzahl) {
                return;
            }
            $this->SetY(-12);
            $this->SetFont('mono', '', 7.5);
            $this->SetTextColor(...PDF_FARBEN['meta']);
            $this->Cell(0, 5, $this->fuss, 0, 0, 'L');
            if ($this->seitenzahl) {
                $this->SetX($this->lMargin);
                $this->Cell(0, 5, 'Seite ' . $this->PageNo() . ' von {nb}', 0, 0, 'R');
            }
        }
    };
    $pdf->AliasNbPages();
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->SetMargins(16, 14, 16);
    $pdf->AddFont('anton', '', 'Anton-Regular.ttf', true);
    $pdf->AddFont('antonio', 'B', 'Antonio-Bold.ttf', true);
    $pdf->AddFont('tajawal', '', 'Tajawal-Regular.ttf', true);
    $pdf->AddFont('tajawal', 'B', 'Tajawal-Bold.ttf', true);
    $pdf->AddFont('mono', '', 'IBMPlexMono-Regular.ttf', true);
    $pdf->SetTitle('Wintersymposium – ' . $titel, true);
    $pdf->SetAuthor('Wintersymposium', true);
    $pdf->SetCreator('Wintersymposium', true);
    $pdf->fuss = $fuss;
    return $pdf;
}

/** Kopf einer Listenseite: Logo, Stand rechts, Kicker, Titel, Unterzeile. */
function export_pdf_seitenkopf(tFPDF $pdf, string $kicker, string $titel, string $sub, string $stand): void
{
    $pdf->Image(export_pdf_ordner() . '/ALLGAEU-AKUT_Logo_quer_farbig-lila.png', 16, 12, 46);
    $pdf->SetFont('mono', '', 8);
    $pdf->SetTextColor(...PDF_FARBEN['meta']);
    $pdf->SetXY(16, 14);
    $pdf->Cell(0, 5, $stand, 0, 0, 'R');
    $pdf->SetY(30);
    $pdf->SetFont('antonio', 'B', 10);
    $pdf->SetTextColor(...PDF_FARBEN['akzent']);
    $pdf->Cell(0, 5, mb_strtoupper($kicker), 0, 1);
    $pdf->SetFont('anton', '', 22);
    $pdf->SetTextColor(...PDF_FARBEN['text']);
    $pdf->MultiCell(0, 10, mb_strtoupper($titel), 0, 'L');
    $pdf->SetFont('tajawal', '', 9.5);
    $pdf->SetTextColor(...PDF_FARBEN['meta']);
    $pdf->MultiCell(0, 5, $sub, 0, 'L');
}

/** PDF (Bytes) aus Kopf und Zeilen. */
function export_pdf(array $x): string
{
    $opt = $x['pdf'];
    $spalten = array_values(array_diff(array_keys($x['kopf']), $opt['ohne'], $opt['gruppe'] !== null ? [$opt['gruppe']] : []));
    // Gruppen (z. B. je Termin eine Seite); ohne Gruppe eine Liste
    $gruppen = [];
    foreach ($opt['alle_gruppen'] ?? [] as $g) {
        $gruppen[$g] = [];
    }
    foreach ($x['zeilen'] as $z) {
        $gruppen[$opt['gruppe'] !== null ? (string)$z[$opt['gruppe']] : ''][] = $z;
    }
    if ($gruppen === []) {
        $gruppen[''] = [];
    }
    $kopfText = va_kurz() . ' · ' . tag_text(va('veranstaltung_datum'));
    $stand = 'Stand ' . datum_zeit_text(db_jetzt()) . ($x['bg'] !== '' ? ' · Berufsgruppe: ' . $x['bg'] : '');

    $pdf = export_pdf_neu($opt['quer'], $x['titel'], 'Vertraulich – nur für die Organisation · ' . $stand);
    $breite = ($opt['quer'] ? 297 : 210) - 32;

    // Spaltenbreiten nach Inhalt (Kopf und Zeilen), auf die Seitenbreite verteilt
    $pdf->SetFont('tajawal', '', 9);
    $mass = [];
    foreach ($spalten as $s) {
        $m = $pdf->GetStringWidth(mb_strtoupper((string)$x['kopf'][$s])) + 4;
        foreach ($x['zeilen'] as $z) {
            $m = max($m, min(90, $pdf->GetStringWidth((string)($z[$s] ?? '')) + 4));
        }
        $mass[$s] = $m;
    }
    $summe = array_sum($mass) ?: 1;
    $w = array_map(fn($m) => $m / $summe * $breite, $mass);

    $kopfzeile = function () use ($pdf, $spalten, $x, $w): void {
        $pdf->SetFont('antonio', 'B', 8);
        $pdf->SetFillColor(...PDF_FARBEN['text']);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($spalten as $s) {
            $pdf->Cell($w[$s], 7, mb_strtoupper((string)$x['kopf'][$s]), 0, 0, 'L', true);
        }
        $pdf->Ln();
    };
    foreach ($gruppen as $gruppe => $zeilen) {
        $pdf->AddPage();
        $sub = $gruppe !== '' ? $x['titel'] . (($opt['info'][$gruppe] ?? '') !== '' ? ' · ' . $opt['info'][$gruppe] : '') : count($zeilen) . ' Einträge';
        if ($gruppe !== '') {
            $sub .= ' · ' . count($zeilen) . ' ' . (count($zeilen) === 1 ? 'Person' : 'Personen');
        }
        export_pdf_seitenkopf($pdf, $kopfText, $gruppe !== '' ? $gruppe : $x['titel'], $sub, $stand);
        $pdf->Ln(3);
        $kopfzeile();
        if ($zeilen === []) {
            $pdf->SetFont('tajawal', '', 9.5);
            $pdf->SetTextColor(...PDF_FARBEN['meta']);
            $pdf->Cell(0, 8, 'Niemand eingetragen.', 0, 1);
            continue;
        }
        $pdf->SetFont('tajawal', '', 9);
        foreach ($zeilen as $n => $z) {
            // Zeilenhöhe nach dem längsten umbrochenen Feld
            $linien = 1;
            foreach ($spalten as $s) {
                $linien = max($linien, export_pdf_linien($pdf, $w[$s] - 2, (string)($z[$s] ?? '')));
            }
            $h = 5.2 * $linien + 1.6;
            if ($pdf->GetY() + $h > $pdf->GetPageHeight() - 18) {
                $pdf->AddPage();
                $pdf->SetY(16);
                $kopfzeile();
                $pdf->SetFont('tajawal', '', 9);
            }
            $y = $pdf->GetY();
            $x0 = $pdf->GetX();
            if ($n % 2 === 1) {
                $pdf->SetFillColor(...PDF_FARBEN['zebra']);
                $pdf->Rect($x0, $y, array_sum(array_intersect_key($w, array_flip($spalten))), $h, 'F');
            }
            $pdf->SetTextColor(...PDF_FARBEN['text']);
            $xx = $x0;
            foreach ($spalten as $s) {
                $wert = (string)($z[$s] ?? '');
                $pdf->SetFont(preg_match('/^[\d.:,\s–-]+$/', $wert) === 1 && $wert !== '' ? 'mono' : 'tajawal', '', 9);
                $pdf->SetXY($xx + 1, $y + 0.8);
                $pdf->MultiCell($w[$s] - 2, 5.2, $wert, 0, 'L');
                $xx += $w[$s];
            }
            $pdf->SetDrawColor(...PDF_FARBEN['linie']);
            $pdf->Line($x0, $y + $h, $xx, $y + $h);
            $pdf->SetXY($x0, $y + $h);
        }
    }
    return $pdf->Output('S');
}

/** Zeilen, die ein Text in einer Spalte braucht (für die Zeilenhöhe). */
function export_pdf_linien(tFPDF $pdf, float $breite, string $text): int
{
    if ($text === '') {
        return 1;
    }
    $n = 0;
    foreach (explode("\n", $text) as $absatz) {
        $zeile = '';
        $n++;
        foreach (preg_split('/(\s+)/u', $absatz, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $teil) {
            if ($pdf->GetStringWidth($zeile . $teil) > $breite && trim($zeile) !== '') {
                $n++;
                $zeile = ltrim($teil);
            } else {
                $zeile .= $teil;
            }
            // Ein einzelnes Wort länger als die Spalte bricht mitten im Wort
            while ($pdf->GetStringWidth($zeile) > $breite && mb_strlen($zeile) > 1) {
                $n++;
                $zeile = mb_substr($zeile, (int)max(1, floor(mb_strlen($zeile) * $breite / max(1, $pdf->GetStringWidth($zeile)))));
            }
        }
    }
    return $n;
}

/** Einen Export protokollieren (Art export, gehört zur Veranstaltung). */
function export_protokollieren(string $was, int $durch): void
{
    audit_schreiben('export', $was, $durch);
}

/** CSV für Excel: Semikolon, UTF-8 mit BOM; Zellen mit = + - @ am Anfang werden entschärft. */
function csv_text(array $kopf, array $zeilen): string
{
    $zelle = function ($w): string {
        $w = (string)$w;
        if ($w !== '' && strpbrk($w[0], "=+-@\t\r") !== false) {
            $w = "'" . $w;
        }
        return '"' . str_replace('"', '""', $w) . '"';
    };
    $out = "\u{FEFF}" . implode(';', array_map($zelle, $kopf)) . "\r\n";
    foreach ($zeilen as $z) {
        $out .= implode(';', array_map($zelle, $z)) . "\r\n";
    }
    return $out;
}

/**
 * Export erzeugen oder null. Rückgabe: 'datei' und 'text' (CSV wie bisher),
 * dazu für xlsx und PDF (O-35, E-56): 'basis' (Dateiname ohne Endung),
 * 'titel', 'kopf', 'zeilen', 'bg' und 'pdf' (Gruppen-Spalte, ausgelassene
 * Spalten, Querformat, Zusatzzeile je Gruppe) – oder 'pdf' => null, wenn es
 * kein PDF gibt (Newsletter: Adresslisten gehören nicht auf Papier).
 */
function export_erzeugen(string $art, string $bg = '', int $terminId = -1): ?array
{
    $paket = function (string $datei, string $titel, array $kopf, array $zeilen, ?array $pdf = []) use ($bg): array {
        return ['datei' => $datei, 'text' => csv_text($kopf, $zeilen), 'basis' => preg_replace('/\.csv$/', '', $datei), 'titel' => $titel,
            'kopf' => $kopf, 'zeilen' => $zeilen, 'bg' => $bg, 'pdf' => $pdf === null ? null : $pdf + ['gruppe' => null, 'ohne' => [], 'quer' => false, 'info' => []]];
    };
    [$w, $p] = stat_filter($bg);
    $heute = gmdate('Ymd');
    $anm = db_zeilen("SELECT a.* FROM anmeldung a WHERE a.status = 'bestaetigt'" . $w . ' ORDER BY a.nachname, a.vorname', $p);
    $kurz = fn(string $s) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue']))) ?? '', '-');
    $datei = fn(string $n) => (va_zeile()['kuerzel'] ?? 'wintersymposium') . '-' . $n . ($bg !== '' ? '-' . $kurz($bg) : '') . '-' . $heute . '.csv';
    switch ($art) {
        case 'teilnehmende':
            $z = [];
            foreach ($anm as $a) {
                $ws = [];
                $sym = '';
                foreach (anm_plaetze((int)$a['id']) as $pl) {
                    if ((int)$pl['termin_id'] === 0) {
                        $sym = $pl['status'] === 'fest' ? 'Platz' : 'Warteliste';
                    } else {
                        $r = anm_platz_zeile($pl);
                        $ws[] = $r['text'] . ($pl['status'] === 'fest' ? '' : ' (' . $r['status'] . ')');
                    }
                }
                $z[] = [$a['nachname'], $a['vorname'], $a['email'], $a['berufsgruppe'] . ($a['beruf_sonstige'] !== '' ? ' (' . $a['beruf_sonstige'] . ')' : ''), $a['institution'], $sym, implode(' | ', $ws), ANM_ESSEN[$a['essen']] ?? '', datum_zeit_text($a['bestaetigt_am'])];
            }
            return $paket($datei('teilnehmende'), 'Teilnehmende', ['Nachname', 'Vorname', 'E-Mail', 'Berufsgruppe', 'Institution', 'Symposium', 'Workshops', 'Essen', 'Bestätigt am'], $z, ['quer' => true, 'ohne' => [2, 8]]);
        case 'termin':
        case 'termine':
        case 'wartelisten':
            $z = [];
            $info = [];
            foreach (team_termine() as $t) {
                if ($t['id'] === 0 || ($art === 'termin' && $t['id'] !== $terminId)) {
                    continue;
                }
                // PDF: Kontrollliste für Dozierende (Admin 2026-10-30) – je Termin Ort, Leitung, Plätze
                $info[$t['text']] = export_termin_info($t['id']);
                $pers = team_termin_personen($t['id']);
                $liste = $art === 'wartelisten' ? $pers['warte'] : $pers['fest'];
                foreach ($liste as $i => $pl) {
                    if ($bg !== '' && db_wert('SELECT berufsgruppe FROM anmeldung WHERE id = ?', [$pl['anmeldung_id']]) !== $bg) {
                        continue;
                    }
                    $z[] = [$t['text'], $art === 'wartelisten' ? $i + 1 : ($pl['status'] === 'fest' ? 'Platz' : 'Angebot offen'), $pl['nachname'], $pl['vorname'], $pl['berufsgruppe'], $pl['email']];
                }
            }
            if ($art === 'termin' && anm_termin($terminId) === null) {
                return null;
            }
            $name = $art === 'termin' ? 'termin-' . $kurz(anm_termin_text($terminId)) : $art;
            $titel = $art === 'wartelisten' ? 'Wartelisten' : ($art === 'termin' ? 'Liste ' . anm_termin_text($terminId) : 'Listen je Workshop-Termin');
            // PDF ohne E-Mail (Liste für Dozierende); je Termin eine Seite
            return $paket($datei($name), $titel, ['Termin', $art === 'wartelisten' ? 'Platz auf der Liste' : 'Status', 'Nachname', 'Vorname', 'Berufsgruppe', 'E-Mail'], $z, ['gruppe' => 0, 'ohne' => [5], 'info' => $info, 'alle_gruppen' => array_keys($info)]);
        case 'essen':
            $z = [];
            foreach ($anm as $a) {
                if (anm_symposium_status((int)$a['id']) === 'fest') {
                    $z[] = [ANM_ESSEN[$a['essen']] ?? '', $a['nachname'], $a['vorname']];
                }
            }
            usort($z, fn($x, $y) => $x <=> $y);
            return $paket($datei('essen'), 'Essensliste', ['Essen', 'Nachname', 'Vorname'], $z, ['gruppe' => 0]);
        case 'cme':
            $erste = va_liste('berufsgruppen')[0] ?? '';
            $z = [];
            foreach ($anm as $a) {
                if ($a['berufsgruppe'] === $erste && tn_anwesend((int)$a['id'])) {
                    $z[] = [$a['nachname'], $a['vorname'], $a['berufsgruppe'], $a['efn'] ?? ''];
                }
            }
            return $paket($datei('cme'), 'CME-Liste', ['Nachname', 'Vorname', 'Berufsgruppe', 'EFN'], $z);
        case 'cme_nachweise':
            // Betreiber (Betrieb): abgelegte CME-Nachweise aller Veranstaltungen (AP-9)
            $z = array_map(fn($n) => [$n['veranstaltung'], datum_text((string)$n['datum']), $n['nachname'], $n['vorname'], $n['efn'] ?? '', $n['vnr'], $n['punkte'], $n['kammer']], ds_cme_liste());
            return $paket('cme-nachweise-' . $heute . '.csv', 'CME-Nachweise', ['Veranstaltung', 'Datum', 'Nachname', 'Vorname', 'EFN', 'VNR', 'Punkte', 'Kammer'], $z, ['quer' => true]);
        case 'newsletter':
            // AP-7: bestätigte Abonnierende (eine Liste über t=…, sonst alle)
            $par = [];
            $nur = '';
            if ($terminId > 0) {
                $nur = ' AND a.liste_id = ?';
                $par[] = $terminId;
            }
            $zeilen = array_map(fn($r) => [$r['liste'], $r['email'], $r['vorname'], datum_zeit_text($r['bestaetigt_am']), $r['fassung'], $r['quelle']], db_zeilen(
                "SELECT l.name AS liste, p.email, p.vorname, a.bestaetigt_am, a.fassung, a.quelle FROM newsletter_abo a JOIN newsletter_person p ON p.id = a.person_id JOIN newsletter_liste l ON l.id = a.liste_id
                 WHERE a.status = 'bestaetigt'" . $nur . ' ORDER BY l.pos, p.email', $par));
            return $paket('wintersymposium-newsletter-' . $heute . '.csv', 'Newsletter-Listen', ['Liste', 'E-Mail', 'Vorname', 'Bestätigt am', 'Fassung der Einwilligung', 'Quelle'], $zeilen, null);
        case 'statistik':
            $d = stat_daten($bg);
            $z = [['Angemeldet', $d['angemeldet']], ['Kapazität', $d['kapazitaet'] ?? 'unbegrenzt'], ['Mail nicht bestätigt', $d['unbestaetigt']], ['Warteliste Symposium', $d['warteliste']],
                ['Wartelisten-Einträge Workshops', $d['wl_eintraege']], ['Personen auf Workshop-Wartelisten', $d['wl_personen']], ['ÄrztInnen', $d['aerzte']], ['davon mit EFN', $d['mit_efn']],
                ['Anmeldungen mit Konto', $d['konten']], ['Newsletter-Wunsch', $d['newsletter']]];
            foreach ($d['termine'] as $t) {
                $z[] = ['Termin ' . $t['text'] . ' belegt', $t['belegt']];
                $z[] = ['Termin ' . $t['text'] . ' Warteliste', $t['warte']];
            }
            foreach ($d['berufsgruppen'] as $g => $n) {
                $z[] = ['Berufsgruppe ' . $g, $n];
            }
            foreach ($d['essen'] as $k => $n) {
                $z[] = ['Essen ' . (ANM_ESSEN[$k] ?? $k), $n];
            }
            foreach ($d['verlauf'] as $v) {
                $z[] = ['Bestätigt bis ' . $v['tag'], $v['summe']];
            }
            return $paket($datei('statistik'), 'Statistik', ['Kennzahl', 'Wert'], $z);
    }
    return null;
}

/** Zusatzzeile eines Termins für das PDF: Ort, Leitung (abweichend je Termin, E-53), Plätze. */
function export_termin_info(int $terminId): string
{
    foreach (programm_veroeffentlicht()['punkte'] ?? [] as $p) { // Plätze gelten im veröffentlichten Programm
        foreach ($p['termine'] as $t) {
            if ((int)$t['id'] === $terminId) {
                $leitung = implode(', ', array_column($t['dozenten'] ?? $p['dozenten'], 'name'));
                return implode(' · ', array_filter([$p['ort'], $leitung !== '' ? 'Leitung: ' . $leitung : '', termin_belegt($terminId) . ' von ' . $t['plaetze'] . ' Plätzen belegt']));
            }
        }
    }
    return '';
}
