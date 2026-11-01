<?php
declare(strict_types=1);

/*
 * Job-Auslöser per URL für den Cron (T-105):
 *   https://<host>/jobs?token=<jobs_token>
 * Ohne oder mit falschem Token 404. Die Antwort ist eine kurze JSON-Übersicht.
 */

$token = (string)($_GET['token'] ?? post_text('token'));
$merkmal = rate_merkmal_ip();
if (!rate_erlaubt('jobs', $merkmal)) {
    nicht_gefunden();
}
if ($token === '' || !hash_equals((string)konfig('jobs_token'), $token)) {
    rate_misserfolg('jobs', $merkmal);
    nicht_gefunden();
}
rate_erfolg('jobs', $merkmal);

ignore_user_abort(true);
set_time_limit(240);
$ergebnis = jobs_lauf('url');
json_antwort(200, $ergebnis);
