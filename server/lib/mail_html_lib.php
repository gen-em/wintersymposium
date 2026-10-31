<?php
declare(strict_types=1);

/*
 * HTML-Fassung der Mails im Erscheinungsbild Allgäu Akut (O-40, Admin
 * 2026-10-31: Variante A „Plakat“). Ein Rahmen für alle Mails: dunkler Kopf
 * mit eingebettetem Logo, Kicker und Überschrift, grüner Streifen, weißer
 * Inhalt, lila Knopf, dunkle Fußzeile. Tabellen und Inline-Stile, weil
 * Mailprogramme (Outlook, Gmail) kaum CSS verstehen; Schrift Arial.
 *
 * Die automatischen Mails behalten ihren Text (mail_vorlage); die HTML-Fassung
 * entsteht daraus nach festen Regeln (mail_html_aus_text): ein Link allein in
 * einem Absatz wird ein Knopf, die Übersicht der Plätze eine Tabelle mit
 * Status-Schildern, der Gruß ohne die letzten zwei Zeilen (stehen im Fuß).
 * Mailings und Newsletter nutzen denselben Rahmen (rundmail_lib).
 */

const MAIL_NAVY = '#001E3A';
const MAIL_LILA = '#B48CFF';
const MAIL_GRUEN = '#B5BD00';
const MAIL_LOGO_DATEI = 'assets/logo/mail-logo.png';
/** Platzhalter für die Quelle des Logos; beim Versand cid:…, in der Vorschau die URL. */
const MAIL_LOGO_PLATZHALTER = '{{mail-logo}}';

/** Überschrift und Knopf je Vorlage (Kopf der Mail und Beschriftung des Knopfs). */
const MAIL_VORLAGE_KOPF = [
    'bestaetigen' => ['Bitte bestätigen', 'Anmeldung bestätigen'],
    'bestaetigt' => ['Anmeldung bestätigt', null],
    'zugang' => ['Dein Link zur Anmeldung', 'Anmeldung ansehen'],
    'schon_angemeldet' => ['Schon angemeldet', 'Anmeldung ansehen'],
    'konto_loeschen_bald' => ['Dein Konto wird bald gelöscht', 'Einloggen und Konto behalten'],
    'geaendert' => ['Anmeldung geändert', null],
    'storniert' => ['Anmeldung storniert', null],
    'angebot' => ['Platzangebot', 'Platz annehmen oder ablehnen'],
    'angebot_abgelaufen' => ['Platzangebot abgelaufen', null],
    'platz_erhalten' => ['Platz bestätigt', null],
    'email_bestaetigen' => ['Neue Adresse bestätigen', 'Neue Adresse bestätigen'],
    'konto_bestaetigen' => ['Konto bestätigen', 'Konto bestätigen'],
    'konto_vorhanden' => ['Du hast schon ein Konto', null],
    'konto_passwort' => ['Neues Passwort', 'Neues Passwort festlegen'],
    'team_rolle' => ['Willkommen im Team', 'Passwort festlegen'],
    'team_einladung' => ['Einladung ins Team', 'Passwort festlegen'],
    'newsletter_bestaetigen' => ['Newsletter bestätigen', 'Newsletter bestätigen'],
    'newsletter_schon' => ['Dein Newsletter', 'Newsletter verwalten'],
    'zweitfaktor_code' => ['Dein Code', null],
    'team_passwort' => ['Neues Passwort', 'Neues Passwort festlegen'],
    'uebernehmen' => ['Wieder dabei?', 'Anmeldung öffnen'],
    'zertifikat' => ['Teilnahmebescheinigung', 'Zur Teilnahmebescheinigung'],
    'test' => ['Testmail', null],
];

/** Vorlagen, die zusätzlich einen Knopf zu „Meine Anmeldung“ bekommen (Text nennt die Adresse nur im Satz). */
const MAIL_KNOPF_ANMELDUNG = ['bestaetigt', 'geaendert', 'platz_erhalten', 'angebot_abgelaufen'];

/** Pfad und Typ des Logos für die Mail (PNG, doppelte Auflösung). */
function mail_logo(): array
{
    return ['png', dirname(__DIR__) . '/public/' . MAIL_LOGO_DATEI];
}

/** Knopf (Link als Schaltfläche) und darunter die Adresse für Mailprogramme, in denen er nicht geht. */
function mail_html_knopf(string $text, string $url, bool $ersatz = true): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 8px;"><tr><td style="background:' . MAIL_LILA . ';border-radius:999px;">'
        . '<a href="' . e($url) . '" style="display:inline-block;padding:13px 26px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;color:' . MAIL_NAVY . ';text-decoration:none;border-radius:999px;">' . e($text) . '</a>'
        . '</td></tr></table>'
        . ($ersatz ? '<p style="margin:0 0 16px;font-size:12px;line-height:1.5;color:#5B6B7C;">Knopf geht nicht? ' . e($url) . '</p>' : '');
}

/** Status-Schild für eine Zeile der Übersicht (wie im Programm: grün sicher, lila Platzangebot, gestrichelt Warteliste). */
function mail_html_schild(string $status): string
{
    $s = mb_strtolower($status);
    $basis = 'display:inline-block;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;letter-spacing:.5px;text-transform:uppercase;padding:2px 8px;border-radius:3px;white-space:nowrap;';
    if (str_contains($s, 'bestätigt')) {
        return '<span style="' . $basis . 'background:' . MAIL_GRUEN . ';color:' . MAIL_NAVY . ';">Bestätigt</span>';
    }
    if (str_starts_with($s, 'warteliste')) {
        $pos = preg_match('/platz (\d+)/u', $s, $m) === 1 ? ' · ' . $m[1] : '';
        return '<span style="' . $basis . 'border:1px dashed #6B3FD1;color:#6B3FD1;padding:1px 7px;">Warteliste' . $pos . '</span>';
    }
    if (str_starts_with($s, 'platzangebot')) {
        return '<span style="' . $basis . 'background:' . MAIL_LILA . ';color:' . MAIL_NAVY . ';">Platzangebot</span>'
            . '<br><span style="font-size:12px;color:#5B6B7C;">' . e(trim(preg_replace('/^platzangebot\s*[–-]\s*/iu', '', $status) ?? '')) . '</span>';
    }
    return '<span style="font-size:14px;color:#33475B;">' . e($status) . '</span>';
}

/** Übersicht der Plätze („- Text: Status“ je Zeile) als Tabelle. */
function mail_html_uebersicht(array $zeilen): string
{
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #D5DBE3;border-radius:6px;border-collapse:separate;margin:0 0 16px;">';
    foreach ($zeilen as $i => $z) {
        $rand = $i < count($zeilen) - 1 ? 'border-bottom:1px solid #E3E7ED;' : '';
        $text = $z;
        $status = '';
        if (preg_match('/^(.*):\s(.+)$/u', $z, $m) === 1) {
            [$text, $status] = [$m[1], $m[2]];
        }
        $zeit = '';
        if (preg_match('/^(.*?)\s·\s(\d{1,2}:\d{2}[–-]\d{1,2}:\d{2})$/u', $text, $m) === 1) {
            [$text, $zeit] = [$m[1], $m[2]];
        }
        $h .= '<tr>'
            . '<td style="' . $rand . 'padding:10px 0 10px 14px;width:96px;font-family:\'Courier New\',monospace;font-size:13px;color:#5B6B7C;vertical-align:top;">' . e($zeit) . '</td>'
            . '<td style="' . $rand . 'padding:10px 8px;font-family:Arial,Helvetica,sans-serif;font-size:15px;color:' . MAIL_NAVY . ';vertical-align:top;">' . e($text) . '</td>'
            . '<td style="' . $rand . 'padding:10px 14px 10px 0;text-align:right;vertical-align:top;">' . ($status !== '' ? mail_html_schild($status) : '') . '</td>'
            . '</tr>';
    }
    return $h . '</table>';
}

/** Klartext eines Absatzes mit klickbaren Adressen und Zeilenumbrüchen. */
function mail_html_satz(string $t): string
{
    $h = e($t);
    $h = preg_replace_callback('#https?://[^\s<>"]+#u', function (array $m): string {
        $url = rtrim($m[0], '.,;:)');
        $rest = substr($m[0], strlen($url));
        return '<a href="' . $url . '" style="color:#6B3FD1;text-decoration:underline;">' . $url . '</a>' . $rest;
    }, $h) ?? $h;
    return nl2br($h, false);
}

/**
 * HTML einer automatischen Mail aus ihrem Text (Regeln oben). $vorlage wählt
 * Überschrift und Knopf; unbekannte Vorlagen nehmen den Betreff.
 */
function mail_html_aus_text(string $vorlage, string $betreff, string $text): string
{
    [$titel, $knopf] = MAIL_VORLAGE_KOPF[$vorlage] ?? [$betreff, null];
    $absaetze = preg_split("/\n[ \t]*\n/", trim(str_replace("\r", '', $text))) ?: [];
    $p = 'margin:0 0 16px;';
    $inhalt = '';
    $knopfGesetzt = false;
    $gruss = mail_gruss();
    $mitAnmeldeKnopf = in_array($vorlage, MAIL_KNOPF_ANMELDUNG, true);
    foreach ($absaetze as $a) {
        $zeilen = explode("\n", $a);
        if (preg_match('#^https?://\S+$#', trim($a)) === 1 && !$knopfGesetzt && $knopf !== null) {
            $inhalt .= mail_html_knopf($knopf, trim($a));
            $knopfGesetzt = true;
        } elseif ($mitAnmeldeKnopf && str_contains($a, url('/deine-anmeldung'))) {
            // Satz „Ändern oder absagen kannst du unter …“: in HTML nur der Knopf (Admin 2026-10-31: nicht doppelt)
            $inhalt .= mail_html_knopf('Anmeldung ansehen oder ändern', url('/deine-anmeldung'));
            $mitAnmeldeKnopf = false;
        } elseif (count(array_filter($zeilen, fn($z) => str_starts_with($z, '- '))) === count($zeilen)) {
            $inhalt .= mail_html_uebersicht(array_map(fn($z) => substr($z, 2), $zeilen));
        } elseif (str_starts_with($a, '    ') && count($zeilen) === 1) {
            // Code zum Einloggen
            $inhalt .= '<p style="' . $p . 'font-family:\'Courier New\',monospace;font-size:30px;font-weight:bold;letter-spacing:6px;color:' . MAIL_NAVY . ';">' . e(trim($a)) . '</p>';
        } elseif (str_starts_with($a, 'Bis bald') && str_contains($gruss, $a)) {
            // Gruß: Name und Adresse stehen im Fuß
            $inhalt .= '<p style="' . $p . '">' . mail_html_satz(implode("\n", array_slice($zeilen, 0, 2))) . '</p>';
        } else {
            $inhalt .= '<p style="' . $p . '">' . mail_html_satz($a) . '</p>';
        }
    }
    if ($mitAnmeldeKnopf) {
        // Kein passender Satz im Text: Knopf vor dem Gruß einfügen
        $knopfHtml = mail_html_knopf('Anmeldung ansehen oder ändern', url('/deine-anmeldung'));
        $pos = strrpos($inhalt, '<p style="' . $p . '">Bis bald');
        $inhalt = $pos === false ? $inhalt . $knopfHtml : substr($inhalt, 0, $pos) . $knopfHtml . substr($inhalt, $pos);
    }
    return mail_rahmen($betreff, mail_kicker($vorlage), $titel, $inhalt, mail_fuss_html());
}

/** Kicker über der Überschrift: Veranstaltung und Datum; Team-Mails „Team-Bereich“. */
function mail_kicker(string $vorlage = ''): string
{
    if (str_starts_with($vorlage, 'team_') || $vorlage === 'zweitfaktor_code') {
        return 'Team-Bereich · ' . va_kurz();
    }
    if (str_starts_with($vorlage, 'newsletter')) {
        return 'Newsletter';
    }
    $datum = va('veranstaltung_datum');
    return va_kurz() . ($datum !== '' ? ' · ' . tag_text($datum) : '');
}

/** Fuß aller automatischen Mails: Absender, Webseite, Impressum, Datenschutz. */
function mail_fuss_html(string $vorne = ''): string
{
    $a = 'color:#B9C6D3;text-decoration:underline;';
    return ($vorne !== '' ? $vorne . '<br>' : '')
        . 'ALLGÄU AKUT – Fortbildung Notfallmedizin · <a href="' . e(url('/')) . '" style="color:' . MAIL_LILA . ';text-decoration:underline;">' . e(preg_replace('#^https?://#', '', rtrim(url('/'), '/')) ?? '') . '</a><br>'
        . '<a href="' . e(url('/impressum')) . '" style="' . $a . '">Impressum</a> · <a href="' . e(url('/datenschutz')) . '" style="' . $a . '">Datenschutz</a>';
}

/**
 * Der Rahmen (Variante A). $inhalt und $fuss sind fertiges HTML. Das Logo
 * steht als MAIL_LOGO_PLATZHALTER drin und wird beim Versand ersetzt.
 */
function mail_rahmen(string $betreff, string $kicker, string $titel, string $inhalt, string $fuss): string
{
    $schrift = 'font-family:Arial,Helvetica,sans-serif;';
    return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light"><title>' . e($betreff) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#E9ECF1;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#E9ECF1;"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#FFFFFF;border-radius:8px;">'
        . '<tr><td style="background:' . MAIL_NAVY . ';padding:24px 32px 22px;border-radius:8px 8px 0 0;' . $schrift . '">'
        . '<img src="' . MAIL_LOGO_PLATZHALTER . '" width="280" height="82" alt="ALLGÄU AKUT – Fortbildung Notfallmedizin" style="display:block;width:280px;max-width:100%;height:auto;border:0;margin:0 0 18px;">'
        . '<div style="font-size:12px;line-height:1.4;letter-spacing:2px;text-transform:uppercase;font-weight:bold;color:' . MAIL_LILA . ';margin:0 0 8px;">' . e($kicker) . '</div>'
        . '<div style="font-size:28px;line-height:1.15;font-weight:bold;text-transform:uppercase;color:#FFFFFF;">' . e($titel) . '</div>'
        . '</td></tr>'
        . '<tr><td style="background:' . MAIL_GRUEN . ';height:4px;line-height:4px;font-size:4px;">&nbsp;</td></tr>'
        . '<tr><td style="padding:28px 32px 12px;' . $schrift . 'font-size:16px;line-height:1.5;color:' . MAIL_NAVY . ';">' . $inhalt . '</td></tr>'
        . '<tr><td style="background:' . MAIL_NAVY . ';padding:16px 32px;border-radius:0 0 8px 8px;' . $schrift . 'font-size:12px;line-height:1.6;color:#B9C6D3;">' . $fuss . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Logo-Platzhalter ersetzen: im Versand als eingebettetes Bild, in der Vorschau als Adresse. */
function mail_logo_einsetzen(string $html, string $quelle): string
{
    return str_replace(MAIL_LOGO_PLATZHALTER, e($quelle), $html);
}
