<?php
declare(strict_types=1);

/* Testbereich: Konten für Teilnehmende (mechanisch aus lauf.php ausgelagert, noch nicht auf test_frisch umgestellt). */

function test_tn_konten(): void
{
    abschnitt('Konten für Teilnehmende (AP-4b)');
    $letzteMail = fn(string $an) => db_zeile('SELECT * FROM mail_ausgang WHERE an = ? ORDER BY id DESC LIMIT 1', [$an]);
    $kLink = fn(?array $m) => preg_match('~/konto/link\?t=([A-Za-z0-9_-]{40,60})~', (string)($m['text'] ?? ''), $x) === 1 ? $x[1] : '';
    $lk = konto_laden($bd['anmeldungen']['lea_konto'] ?? 0);
    pruefe($lk !== null && $lk['rolle'] === 'teilnehmend' && anmeldung_passwort_ok($lk, BEISPIEL_PASSWORT), 'Beispieldaten: Lea hat ein Konto mit Passwort');
    pruefe(passwort_regel_fehler('123456789', '123456789', PASSWORT_MIN_TN) !== null && passwort_regel_fehler('1234567890', '1234567890', PASSWORT_MIN_TN) === null && passwort_regel_fehler('1234567890', '1234567890') !== null, 'Passwort: Teilnehmende ab 10 Zeichen, Team weiter ab 12');
    tk_registrieren('Rita Registriert', 'rita@example.org');
    $rita = konto_per_email('rita@example.org');
    $tok = $kLink($letzteMail('rita@example.org'));
    pruefe($rita !== null && $rita['email_bestaetigt_am'] === null && $tok !== '', 'Konto ohne Anmeldung: unbestätigt, Mail mit Link');
    pruefe(isset(tk_login_pruefen('rita@example.org', 'egal-egal-egal')['fehler']), 'unbestätigtes Konto kommt nicht rein');
    $r = tk_link_einloesen($tok);
    pruefe($r !== null && $r['konto']['email_bestaetigt_am'] !== null && tk_link_einloesen($tok) === null, 'Link bestätigt die Adresse, gilt nur einmal');
    konto_passwort_setzen((int)$rita['id'], 'ritas-passwort');
    pruefe(isset(tk_login_pruefen('rita@example.org', 'ritas-passwort')['konto']) && isset(tk_login_pruefen('rita@example.org', 'falsch-falsch')['fehler']), 'Login mit Passwort');
    $vor = (int)db_wert('SELECT COUNT(*) FROM mail_ausgang');
    tk_registrieren('Rita Nochmal', 'rita@example.org');
    pruefe(str_starts_with((string)$letzteMail('rita@example.org')['betreff'], 'Du hast schon ein Konto') && konto_per_email('rita@example.org')['name'] === 'Rita Registriert', 'gleiche Adresse: kein zweites Konto, Hinweis per Mail');
    $teamId = konto_anlegen('Team Test', 'team-test@example.org', 'admin');
    tk_registrieren('Team', 'team-test@example.org');
    pruefe((int)db_wert('SELECT COUNT(*) FROM mail_ausgang') === $vor + 1, 'Team-Adresse: keine Mail');
    // Anmeldung mit eingeloggtem Konto: sofort bestätigt und verknüpft
    $da = ['vorname' => 'Rita', 'nachname' => 'Registriert', 'email' => 'rita@example.org', 'berufsgruppe' => 'StudentIn', 'institution' => 'Uni', 'efn' => '', 'essen' => 'egal'];
    $e = anm_anlegen(anm_daten_pruefen($da)['werte'], ['fest' => [], 'warte' => []], false, null, false, konto_laden((int)$rita['id']));
    pruefe($e['art'] === 'neu' && $e['bestaetigt'] === true && anm_laden($e['id'])['konto_id'] == $rita['id'], 'Anmeldung mit Konto: ohne Bestätigungsmail, verknüpft');
    // Häkchen „Konto anlegen“: nach dem Bestätigungslink Konto angelegt und verknüpft
    $e2 = anm_anlegen(anm_daten_pruefen(['vorname' => 'Kurt', 'email' => 'kurt@example.org'] + $da)['werte'], ['fest' => [], 'warte' => []], false, null, true);
    preg_match('~/link\?t=([A-Za-z0-9_-]{43})~', (string)$letzteMail('kurt@example.org')['text'], $mk);
    $r = anm_link_einloesen($mk[1] ?? '');
    $kurt = konto_per_email('kurt@example.org');
    pruefe($r !== null && !empty($r['konto_neu']) && $kurt !== null && $kurt['passwort_hash'] === null && anm_laden($e2['id'])['konto_id'] == $kurt['id'], 'Häkchen beim Anmelden: Konto nach der Bestätigung, Passwort folgt');
    // Verknüpfung: Anmeldung erst, Konto danach mit gleicher Adresse
    $e3 = anm_anlegen(anm_daten_pruefen(['vorname' => 'Vera', 'email' => 'vera@example.org'] + $da)['werte'], ['fest' => [], 'warte' => []], false);
    db_ausfuehren("UPDATE anmeldung SET status = 'bestaetigt' WHERE id = ?", [$e3['id']]);
    tk_registrieren('Vera V', 'vera@example.org');
    tk_link_einloesen($kLink($letzteMail('vera@example.org')));
    pruefe(anm_laden($e3['id'])['konto_id'] == konto_per_email('vera@example.org')['id'], 'bestätigtes Konto und Anmeldung mit gleicher Adresse verknüpft (E-48)');
    // Neue Adresse: Konto und Anmeldung ziehen mit
    tk_email_aendern((int)$rita['id'], 'rita.neu@example.org');
    pruefe(konto_laden((int)$rita['id'])['email'] === 'rita@example.org', 'neue Adresse gilt erst nach Bestätigung');
    tk_link_einloesen($kLink($letzteMail('rita.neu@example.org')));
    pruefe(konto_laden((int)$rita['id'])['email'] === 'rita.neu@example.org' && anm_laden($e['id'])['email'] === 'rita.neu@example.org', 'nach dem Link: Konto und Anmeldung mit neuer Adresse');
    // Löschen: Anmeldung bleibt bzw. wird storniert (Admin 2026-10-29)
    pruefe(tk_loeschen((int)$rita['id'], false) === null && konto_laden((int)$rita['id']) === null && anm_laden($e['id']) !== null && anm_laden($e['id'])['konto_id'] === null, 'Konto löschen, Anmeldung bleibt');
    pruefe(tk_loeschen((int)$kurt['id'], true) === null && konto_laden((int)$kurt['id']) === null && anm_laden($e2['id']) === null, 'Konto löschen mit Storno der Anmeldung');
    tk_registrieren('Ute Unbestätigt', 'unbestaetigt@example.org');
    $n = tk_aufraeumen(time() + 49 * 3600);
    pruefe($n >= 1 && konto_per_email('unbestaetigt@example.org') === null && konto_laden($teamId) !== null, "unbestätigte Konten nach 48 h gelöscht, Team-Konten nicht ($n)");
    db_ausfuehren('DELETE FROM konto WHERE id = ?', [$teamId]);
}
