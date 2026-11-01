<?php
declare(strict_types=1);

/* Testbereich: Konten für Teilnehmende – Registrieren, Bestätigen, Login, Verknüpfen mit Anmeldungen, Adresse ändern, Löschen und Aufräumen (AP-4b). */

function test_tn_konten(): void
{
    abschnitt('Konten für Teilnehmende (AP-4b)');
    $f = test_frisch();
    $an = $f['bd']['anmeldungen'];
    $kLink = fn(?array $m) => preg_match('~/konto/link\?t=([A-Za-z0-9_-]{40,60})~', (string)($m['text'] ?? ''), $x) === 1 ? $x[1] : '';
    pruefe((int)(tk_login_pruefen('lea@example.org', BEISPIEL_PASSWORT)['konto']['id'] ?? 0) === $an['lea_konto'], 'Login mit Passwort (Lea aus den Beispieldaten)');
    pruefe(passwort_regel_fehler('123456789', '123456789', PASSWORT_MIN_TN) !== null && passwort_regel_fehler('1234567890', '1234567890', PASSWORT_MIN_TN) === null && passwort_regel_fehler('1234567890', '1234567890') !== null, 'Passwort: Teilnehmende ab 10 Zeichen, Team weiter ab 12');
    // Registrieren (geprüft wird das Anlegen selbst)
    tk_registrieren('Rita Registriert', 'rita@example.org');
    $rita = konto_per_email('rita@example.org');
    $tok = $kLink(test_mail_an('rita@example.org'));
    pruefe($rita !== null && $rita['rolle'] === 'teilnehmend' && $rita['email_bestaetigt_am'] === null && $tok !== '', 'Konto ohne Anmeldung: unbestätigt, Mail mit Link');
    pruefe(isset(tk_login_pruefen('rita@example.org', 'egal-egal-egal')['fehler']), 'unbestätigtes Konto kommt nicht rein');
    $r = tk_link_einloesen($tok);
    pruefe($r !== null && $r['konto']['email_bestaetigt_am'] !== null && tk_link_einloesen($tok) === null, 'Link bestätigt die Adresse, gilt nur einmal');
    konto_passwort_setzen((int)$rita['id'], 'ritas-passwort');
    pruefe((int)(tk_login_pruefen('rita@example.org', 'ritas-passwort')['konto']['id'] ?? 0) === (int)$rita['id'] && isset(tk_login_pruefen('rita@example.org', 'falsch-falsch')['fehler']), 'Login mit neuem Passwort, falsches abgelehnt');
    $vor = (int)db_wert('SELECT COUNT(*) FROM mail_ausgang');
    tk_registrieren('Rita Nochmal', 'rita@example.org');
    pruefe(str_starts_with((string)test_mail_an('rita@example.org')['betreff'], 'Du hast schon ein Konto') && konto_per_email('rita@example.org')['name'] === 'Rita Registriert', 'gleiche Adresse: kein zweites Konto, Hinweis per Mail');
    tk_registrieren('Anna', TEST_ADMIN);
    pruefe((int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === $vor + 1 && test_mail_an(TEST_ADMIN) === null && konto_laden($f['admin'])['rolle'] === 'admin', 'Team-Adresse: keine Mail, Konto bleibt Team');
    // Anmeldung mit eingeloggtem Konto: sofort bestätigt und verknüpft (geprüft wird anm_anlegen mit Konto)
    $da = ['vorname' => 'Rita', 'nachname' => 'Registriert', 'email' => 'rita@example.org', 'berufsgruppe' => 'StudentIn', 'institution' => 'Uni', 'efn' => '', 'essen' => 'egal'];
    $e = anm_anlegen(anm_daten_pruefen($da)['werte'], ['fest' => [], 'warte' => []], false, null, false, konto_laden((int)$rita['id']));
    pruefe($e['art'] === 'neu' && $e['bestaetigt'] === true && anm_laden($e['id'])['status'] === 'bestaetigt' && (int)anm_laden($e['id'])['konto_id'] === (int)$rita['id'], 'Anmeldung mit Konto: ohne Bestätigungsmail, verknüpft');
    // Häkchen „Konto anlegen“: nach dem Bestätigungslink Konto angelegt und verknüpft (geprüft wird anm_anlegen mit Kontowunsch)
    $e2 = anm_anlegen(anm_daten_pruefen(['vorname' => 'Kurt', 'email' => 'kurt@example.org'] + $da)['werte'], ['fest' => [], 'warte' => []], false, null, true);
    pruefe(konto_per_email('kurt@example.org') === null, 'Häkchen: vor der Bestätigung kein Konto');
    preg_match('~/link\?t=([A-Za-z0-9_-]{43})~', (string)test_mail_an('kurt@example.org')['text'], $mk);
    $r = anm_link_einloesen($mk[1] ?? '');
    $kurt = konto_per_email('kurt@example.org');
    pruefe($r !== null && !empty($r['konto_neu']) && $kurt !== null && $kurt['passwort_hash'] === null && (int)anm_laden($e2['id'])['konto_id'] === (int)$kurt['id'], 'Häkchen beim Anmelden: Konto nach der Bestätigung, Passwort folgt');
    // Verknüpfung: Paula ist bestätigt angemeldet und legt danach ein Konto mit gleicher Adresse an
    $paulaVorher = anm_laden($an['paula'])['konto_id'];
    tk_registrieren('Paula Probe', 'paula@example.org');
    tk_link_einloesen($kLink(test_mail_an('paula@example.org')));
    pruefe($paulaVorher === null && (int)anm_laden($an['paula'])['konto_id'] === (int)konto_per_email('paula@example.org')['id'], 'bestätigtes Konto und Anmeldung mit gleicher Adresse verknüpft (E-48)');
    // Neue Adresse: Konto und Anmeldung ziehen mit
    tk_email_aendern((int)$rita['id'], 'rita.neu@example.org');
    pruefe(konto_laden((int)$rita['id'])['email'] === 'rita@example.org' && anm_laden($e['id'])['email'] === 'rita@example.org', 'neue Adresse gilt erst nach Bestätigung');
    tk_link_einloesen($kLink(test_mail_an('rita.neu@example.org')));
    pruefe(konto_laden((int)$rita['id'])['email'] === 'rita.neu@example.org' && anm_laden($e['id'])['email'] === 'rita.neu@example.org', 'nach dem Link: Konto und Anmeldung mit neuer Adresse');
    // Löschen: Anmeldung bleibt bzw. wird storniert (Admin 2026-10-29)
    pruefe(tk_loeschen((int)$rita['id'], false) === null && konto_laden((int)$rita['id']) === null && anm_laden($e['id']) !== null && anm_laden($e['id'])['konto_id'] === null, 'Konto löschen, Anmeldung bleibt');
    pruefe(tk_loeschen((int)$kurt['id'], true) === null && konto_laden((int)$kurt['id']) === null && anm_laden($e2['id']) === null, 'Konto löschen mit Storno der Anmeldung');
    // Aufräumen: nur unbestätigte Teilnehmenden-Konten nach 48 h (Nils aus den Beispieldaten)
    beispieldaten_nur_konto();
    pruefe(tk_aufraeumen(time() + 47 * 3600) === 0, 'unbestätigtes Konto vor Ablauf von 48 h bleibt');
    $n = tk_aufraeumen(time() + 49 * 3600);
    pruefe($n === 1 && konto_per_email('nils.nurkonto@example.org') === null && konto_laden($f['admin']) !== null && konto_laden($an['lea_konto']) !== null, "unbestätigte Konten nach 48 h gelöscht, Team- und bestätigte Konten nicht ($n)");
}
