<?php
declare(strict_types=1);

/*
 * Vorschau eines Mailings im iframe der Seite „Mailings“: genau das HTML der
 * Mail, Bilder über /team/mailings/bild. Eigene Sicherheitsrichtlinie: Stile
 * im HTML erlaubt (wie in Mailprogrammen), keine Skripte, nur in der eigenen
 * Seite einbettbar.
 */

$konto = team_tor();
$rm = rm_laden((int)($_GET['id'] ?? 0));
if ($rm === null || !rm_sichtbar($rm)) {
    nicht_gefunden();
}
$m = rm_vorschau($rm, $konto);
kopf_gemeinsam();
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
// Kopfzeilen der Mail als Zeile über dem Inhalt; Links öffnen in einem neuen Fenster
$kopf = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#33638D;background:#FFFFFF;padding:8px 12px;border-bottom:1px solid #E1E6EC;">'
    . 'Von: ' . e(mail_absender_name(mail_konfig($rm['postfach']))) . ' · An: ' . e($m['an']) . '<br><strong style="color:#001E3A;font-size:14px;">' . e($m['betreff'] !== '' ? $m['betreff'] : '(ohne Betreff)') . '</strong></div>';
echo str_replace(['<head>', '<body style="margin:0;padding:0;background:#EEF1F5;">'], ['<head><base target="_blank">', '<body style="margin:0;padding:0;background:#EEF1F5;">' . $kopf], $m['html']);
