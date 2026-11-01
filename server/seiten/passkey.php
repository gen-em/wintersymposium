<?php
declare(strict_types=1);

/*
 * Passkeys (O-14, E-55): JSON-Endpunkt für assets/passkey.js. Nur POST mit
 * CSRF-Token (Kopfzeile X-CSRF). Aktionen:
 *   login_optionen / login          – Einloggen ohne Passwort (alle Konten)
 *   anlage_optionen / anlegen       – Passkey hinzufügen: eingeloggt (mit
 *                                     Passwort bestätigt) oder beim ersten
 *                                     Team-Login als zweiter Faktor
 *   bestaetigen_optionen / bestaetigen – heikle Aktion im Team-Bereich
 *                                     bestätigen (Review B-7): Passkey des
 *                                     eingeloggten Kontos, gilt 5 Minuten
 *                                     für eine Aktion (zweitfaktor_bestaetigt)
 * Antworten: {ok: true, …} oder {ok: false, fehler: "…"} mit Status 400.
 */

team_sitzung();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_antwort(405, ['ok' => false, 'fehler' => 'Nur POST.']);
}
if (!csrf_ok()) {
    json_antwort(403, ['ok' => false, 'fehler' => 'Die Seite war zu alt. Bitte neu laden.']);
}
$aktion = aktion();
$antwort = json_decode(post_text('antwort'), true);
$nein = fn(string $t) => json_antwort(400, ['ok' => false, 'fehler' => $t]);

/** Für wen wird angelegt? Eingeloggt (tk) oder halber Team-Login ohne zweiten Faktor (Einrichtung). */
$anlageKonto = function (): array {
    $halb = anmeldung_halb();
    if ($halb !== null && !totp_zustand((int)$halb['id'])['eingerichtet']) {
        return ['konto' => $halb, 'einrichtung' => true];
    }
    $k = tk_aktuell();
    return ['konto' => $k, 'einrichtung' => false];
};

try {
    switch ($aktion) {
        case 'login_optionen':
            json_antwort(200, ['ok' => true, 'optionen' => pk_login_optionen()]);
        case 'login':
            $ip = rate_merkmal_ip();
            if (!rate_erlaubt('login_ip', $ip)) {
                $nein(rate_text('login_ip'));
            }
            try {
                $kontoId = pk_anmelden(is_array($antwort) ? $antwort : []);
            } catch (PkFehler $f) {
                rate_misserfolg('login_ip', $ip);
                $nein('Dieser Passkey wurde nicht angenommen. Versuch es noch einmal oder logg dich mit dem Passwort ein.');
            }
            $k = konto_laden($kontoId);
            if ($k === null || (int)$k['aktiv'] !== 1 || (!konto_ist_team($k) && $k['email_bestaetigt_am'] === null)) {
                $nein('Dieses Konto ist gesperrt oder noch nicht bestätigt.');
            }
            pk_login_protokollieren($kontoId);
            if (konto_ist_team($k)) {
                anmeldung_voll($k); // Passkey = Besitz + Fingerabdruck/PIN: Passwort und zweiter Faktor (E-55)
                json_antwort(200, ['ok' => true, 'ziel' => '/team']);
            }
            tk_einloggen($k);
            json_antwort(200, ['ok' => true, 'ziel' => tk_anmeldung($kontoId) !== null ? '/deine-anmeldung' : '/konto']);
        case 'anlage_optionen':
            $z = $anlageKonto();
            if ($z['konto'] === null) {
                $nein('Bitte logge dich zuerst ein.');
            }
            if (!$z['einrichtung'] && ($f = passwort_bestaetigen($z['konto'], post_text('pw'))) !== null) {
                $nein($f);
            }
            json_antwort(200, ['ok' => true, 'optionen' => pk_anlage_optionen($z['konto'])]);
        case 'anlegen':
            $z = $anlageKonto();
            if ($z['konto'] === null) {
                $nein('Bitte logge dich zuerst ein.');
            }
            $kid = (int)$z['konto']['id'];
            try {
                pk_anlegen($kid, is_array($antwort) ? $antwort : [], post_text('bezeichnung'));
            } catch (PkFehler $f) {
                $nein(str_contains($f->getMessage(), 'Höchstens') || str_contains($f->getMessage(), 'schon') ? $f->getMessage() . '.' : 'Der Passkey wurde nicht angenommen. Prüfe, ob dein Gerät mit Fingerabdruck, Gesicht oder PIN entsperrt wird, und versuch es noch einmal.');
            }
            if ($z['einrichtung']) {
                // Erster Team-Login: Passkey als zweiter Faktor, dazu Wiederherstellungscodes
                $codes = totp_codes_erzeugen();
                totp_codes_schreiben($kid, $codes);
                anmeldung_voll(konto_laden($kid));
                $_SESSION[SITZUNG_SCHLUESSEL_PK_CODES] = $codes;
                json_antwort(200, ['ok' => true, 'ziel' => '/einloggen?codes=1']);
            }
            flash_setzen('ok', 'Passkey gespeichert. Ab jetzt kannst du dich damit ohne Passwort einloggen.');
            json_antwort(200, ['ok' => true, 'ziel' => '/konto?passkey=neu#passkeys']); // mit Abfrage: lädt neu, statt nur zum Anker zu springen
        case 'bestaetigen_optionen':
            // Heikle Aktion im Team-Bereich mit Passkey bestätigen (Review B-7)
            $k = anmeldung_konto();
            if ($k === null) {
                $nein('Bitte logge dich zuerst ein.');
            }
            json_antwort(200, ['ok' => true, 'optionen' => pk_bestaetigen_optionen($k)]);
        case 'bestaetigen':
            $k = anmeldung_konto();
            if ($k === null) {
                $nein('Bitte logge dich zuerst ein.');
            }
            $kennung = rate_merkmal('konto:' . $k['id']);
            if (!rate_erlaubt('zweitfaktor', $kennung)) {
                $nein(rate_text('zweitfaktor'));
            }
            try {
                pk_bestaetigen((int)$k['id'], is_array($antwort) ? $antwort : []);
            } catch (PkFehler $f) {
                rate_misserfolg('zweitfaktor', $kennung);
                $nein('Dieser Passkey wurde nicht angenommen. Nimm einen Passkey dieses Kontos oder gib einen Code ein.');
            }
            rate_erfolg('zweitfaktor', $kennung);
            zweitfaktor_merken((int)$k['id']);
            json_antwort(200, ['ok' => true]);
        default:
            $nein('Unbekannte Aktion.');
    }
} catch (PkFehler $f) {
    $nein('Passkeys gehen unter dieser Adresse nicht.');
}
