<?php
declare(strict_types=1);

/* Testbereich: Vereinheitlichung (AP-17) (mechanisch aus lauf.php ausgelagert, noch nicht auf test_frisch umgestellt). */

function test_vereinheitlichung(): void
{
    abschnitt('Einmal-Links in einer Tabelle (AP-17 Unterpunkt 8, V-1)');
    $lt = link_token();
    pruefe(preg_match('/^[A-Za-z0-9_-]{43}$/', $lt) === 1 && link_token() !== $lt, 'ein Token-Format: 32 Byte Base64url (43 Zeichen)');
    pruefe(!db_tabelle_da('konto_link') && !db_tabelle_da('anmeldung_link') && !db_tabelle_da('newsletter_link') && db_tabelle_da('link'), 'alte Link-Tabellen entfernt, eine Tabelle link (E-69 (7))');
    // Migration der Altdaten: dieselben INSERT … SELECT wie Migration 20, auf vorübergehenden Tabellen mit den alten Namen
    $lAnm = (int)db_wert('SELECT MIN(id) FROM anmeldung');
    $lKonto = (int)db_wert("SELECT MIN(id) FROM konto WHERE rolle = 'teilnehmend'");
    $lPerson = nl_person_anlegen('alt-link@example.org', 'Alt');
    $altHex = bin2hex(random_bytes(24));
    $altKonto = link_token();
    $altNl = bin2hex(random_bytes(24));
    $t0 = time();
    db()->exec('CREATE TEMPORARY TABLE konto_link (konto_id INT UNSIGNED, token_hash CHAR(64), zweck VARCHAR(20), neue_email VARCHAR(254) NULL, gueltig_bis DATETIME, erstellt_am DATETIME)');
    db()->exec('CREATE TEMPORARY TABLE anmeldung_link (anmeldung_id INT UNSIGNED, token_hash CHAR(64), zweck VARCHAR(20), neue_email VARCHAR(190) NULL, gueltig_bis DATETIME, erstellt_am DATETIME)');
    db()->exec('CREATE TEMPORARY TABLE newsletter_link (person_id INT UNSIGNED, token_hash CHAR(64), zweck VARCHAR(20), gueltig_bis DATETIME)');
    db_ausfuehren('INSERT INTO konto_link VALUES (?, ?, ?, ?, ?, ?)', [$lKonto, hash('sha256', $altKonto), 'email', 'neu-alt@example.org', db_zeit($t0 + 3600), db_zeit($t0)]);
    db_ausfuehren('INSERT INTO anmeldung_link VALUES (?, ?, ?, NULL, ?, ?)', [$lAnm, hash('sha256', $altHex), 'zugang', db_zeit($t0 + 3600), db_zeit($t0)]);
    db_ausfuehren('INSERT INTO newsletter_link VALUES (?, ?, ?, ?)', [$lPerson, hash('sha256', $altNl), 'verwalten', db_zeit($t0 + 3600)]);
    foreach (array_slice(migration_katalog()[20]['sql'], 1, 3) as $sql) {
        db()->exec($sql);
    }
    db()->exec('DROP TEMPORARY TABLE konto_link, anmeldung_link, newsletter_link');
    $fk = link_finden('konto', $altKonto);
    pruefe($fk !== null && $fk['ziel_id'] === $lKonto && $fk['zweck'] === 'email' && $fk['neue_email'] === 'neu-alt@example.org', 'Migration: Konto-Link mit neuer Adresse übernommen (Hash bleibt gültig)');
    $fa = anm_link_finden($altHex);
    pruefe($fa !== null && (int)$fa['anmeldung_id'] === $lAnm && $fa['zweck'] === 'zugang', 'Migration: alter Hex-Token einer Anmeldung wird gefunden');
    pruefe(nl_link_finden($altNl, 'verwalten') !== null && nl_link_finden($altNl, 'bestaetigen') === null, 'Migration: Newsletter-Link übernommen, Zweck getrennt');
    pruefe(link_verbrauchen((int)$fa['id']) && anm_link_finden($altHex) === null && !link_verbrauchen((int)$fa['id']), 'alter Hex-Token wird einmal verbraucht');
    // Zweck- und Art-Trennung
    $teamK = (int)db_wert("SELECT MIN(id) FROM konto WHERE rolle <> 'teilnehmend'");
    $teamTok = konto_link_erzeugen($teamK, 'passwort');
    pruefe(konto_link_finden($teamTok) !== null && tk_link_einloesen($teamTok) === null && konto_link_finden($teamTok) !== null, 'Team-Link geht nicht über /konto/link (und bleibt unverbraucht)');
    pruefe(link_finden('anmeldung', $teamTok) === null && link_finden('newsletter', $altKonto) === null, 'Token einer Art gilt nicht für eine andere Art');
    $tk1 = tk_link($lKonto, 'passwort', null, $t0);
    $tk2 = tk_link($lKonto, 'bestaetigen', null, $t0);
    $tk3 = tk_link($lKonto, 'passwort', null, $t0);
    $tokVon = fn(array $l) => (string)substr((string)strstr($l['url'], '?t='), 3);
    pruefe(link_finden('konto', $tokVon($tk1)) === null && link_finden('konto', $tokVon($tk2)) !== null && link_finden('konto', $tokVon($tk3)) !== null, 'Teilnehmenden-Link ersetzt nur ältere mit gleichem Zweck');
    konto_link_erzeugen($lKonto, 'einrichten');
    pruefe(link_finden('konto', $tokVon($tk2)) === null, 'Team-Link ersetzt alle älteren Links des Kontos');
    // Aufräumen
    $kurz = link_erzeugen('anmeldung', $lAnm, 'zugang', 60, [], $t0);
    $lang = link_erzeugen('anmeldung', $lAnm, 'zugang', 7200, [], $t0);
    $weg = link_aufraeumen($t0 + 120);
    pruefe($weg >= 1 && link_finden('anmeldung', $kurz, null, $t0) === null && link_finden('anmeldung', $lang, null, $t0) !== null, 'Aufräumen löscht nur abgelaufene Links');
    pruefe(str_ends_with(konto_link_url('abc'), '/einrichten?t=abc') && str_ends_with(link_url('konto', 'abc'), '/konto/link?t=abc') && str_ends_with(anm_link_url('abc'), '/link?t=abc') && str_ends_with(link_url('newsletter', 'abc', 'bestaetigen'), '/newsletter/bestaetigen?t=abc'), 'Pfade der Links unverändert (verschickte Mails gelten weiter)');
    link_loeschen('anmeldung', $lAnm);
    link_loeschen('konto', $lKonto);
    link_loeschen('konto', $teamK);

    abschnitt('Protokoll mit Objektbezug (AP-17 Unterpunkt 9, V-8)');
    pruefe(audit_text('anmeldung', 12, 'storniert') === 'Anmeldung 12: storniert' && audit_text('rundmail', 3, 'gesendet') === 'Mailing 3: gesendet', 'audit_text: einheitliche Formulierung');
    try {
        audit_schreiben('konto', 'x', null, ['gibtsnicht' => 1]);
        pruefe(false, 'unbekannte Objekt-Art abgewiesen');
    } catch (InvalidArgumentException) {
        pruefe(true, 'unbekannte Objekt-Art abgewiesen');
    }
    $oAnm = (int)db_wert('SELECT MIN(id) FROM anmeldung');
    audit_schreiben('anmeldung', audit_text('anmeldung', $oAnm, 'Test Objektbezug'), null, ['anmeldung' => $oAnm]);
    $oL = audit_letzter('anmeldung', $oAnm);
    pruefe($oL !== null && $oL['text'] === 'Anmeldung ' . $oAnm . ': Test Objektbezug', 'Eintrag mit Bezug wird über objekt_art/objekt_id gefunden (statt LIKE)');
    $oBetr = konto_laden((int)db_wert("SELECT MIN(id) FROM konto WHERE rolle = 'betreiber'"));
    $oS = audit_suchen($oBetr, 'alle', '', '', 1, 50, ['anmeldung', $oAnm]);
    pruefe($oS['gesamt'] >= 1 && array_filter($oS['zeilen'], fn($z) => $z['objekt_art'] !== 'anmeldung' || (int)$z['objekt_id'] !== $oAnm) === [], 'Protokoll-Filter nach Objekt zeigt nur dessen Einträge');
    // Altdaten: dieselben UPDATEs wie Migration 21 auf Einträgen ohne Bezug
    $altIds = [];
    foreach ([['anmeldung', 'Anmeldung 12 storniert', null], ['nachruecken', 'Angebot an Anmeldung 13 für Termin 4', null], ['mailing', 'Entwurf 7 gelöscht', null], ['konto', 'Konto 5 mit Anmeldung 9 verknüpft', null], ['login', 'Ausgeloggt', $oBetr['id']], ['programm', 'Dozent·in 3 gelöscht', null], ['datenschutz', 'Anmeldungen geleert: 4', null]] as [$aArt, $aText, $aKonto]) {
        db_ausfuehren('INSERT INTO audit (zeit, konto_id, art, text) VALUES (?, ?, ?, ?)', [db_jetzt(), $aKonto, $aArt, $aText]);
        $altIds[$aText] = (int)db()->lastInsertId();
    }
    foreach (array_slice(migration_katalog()[21]['sql'], 1) as $sql) {
        db()->exec($sql);
    }
    $bezugVon = fn(string $t) => db_zeile('SELECT objekt_art, objekt_id FROM audit WHERE id = ?', [$altIds[$t]]);
    pruefe($bezugVon('Anmeldung 12 storniert') == ['objekt_art' => 'anmeldung', 'objekt_id' => 12] && $bezugVon('Angebot an Anmeldung 13 für Termin 4') == ['objekt_art' => 'anmeldung', 'objekt_id' => 13], 'Migration ordnet „Anmeldung 12 storniert“ und Angebote der Anmeldung zu');
    pruefe($bezugVon('Entwurf 7 gelöscht') == ['objekt_art' => 'rundmail', 'objekt_id' => 7] && $bezugVon('Konto 5 mit Anmeldung 9 verknüpft') == ['objekt_art' => 'konto', 'objekt_id' => 5] && $bezugVon('Dozent·in 3 gelöscht') == ['objekt_art' => 'dozent', 'objekt_id' => 3], 'Migration: Mailing, Konto (Hauptbezug vor weiteren Nummern), Dozent·in');
    pruefe($bezugVon('Ausgeloggt') == ['objekt_art' => 'konto', 'objekt_id' => (int)$oBetr['id']] && $bezugVon('Anmeldungen geleert: 4') == ['objekt_art' => null, 'objekt_id' => null], 'Migration: eigene Konto-Einträge ohne Nummer, Sammel-Einträge ohne Bezug');
    db_ausfuehren('DELETE FROM audit WHERE id IN (' . implode(',', $altIds) . ')');

    abschnitt('Lib-Aufteilung, Bilder, Kontext (AP-17 Unterpunkt 10)');
    // Ein Weg für Bilder (V-12): dasselbe Testbild als JPG und PNG, zu groß, kein Bild
    $png = (function (): string { $b = imagecreatetruecolor(1600, 800); imagefill($b, 0, 0, imagecolorallocate($b, 180, 140, 255)); ob_start(); imagepng($b); return (string)ob_get_clean(); })();
    $jpg = (function (): string { $b = imagecreatetruecolor(1600, 800); ob_start(); imagejpeg($b); return (string)ob_get_clean(); })();
    $v = bild_verkleinern($png, RM_BILD_MAX_KANTE);
    pruefe($v['png'] && $v['breite'] === 1200 && $v['hoehe'] === 600, 'Mailing-Bild: PNG auf die längste Kante 1200 verkleinert');
    $v = bild_verkleinern($jpg, FOTO_KANTE, true);
    pruefe(!$v['png'] && $v['breite'] === FOTO_KANTE && $v['hoehe'] === FOTO_KANTE, 'Foto: JPG quadratisch zugeschnitten');
    $v = bild_verkleinern($png, UNTERSCHRIFT_BREITE, false, ['nur_breite' => true, 'transparent' => true]);
    pruefe($v['breite'] === UNTERSCHRIFT_BREITE && $v['hoehe'] === 600, 'Unterschrift: nur die Breite begrenzt');
    try {
        bild_verkleinern('kein Bild', 100);
        pruefe(false, 'kein Bild abgewiesen');
    } catch (RuntimeException $ex) {
        pruefe(str_contains($ex->getMessage(), 'JPG oder PNG'), 'kein Bild abgewiesen');
    }
    $meldung = fn(array $u, int $max, string $was) => (function () use ($u, $max, $was) { try { bild_upload_lesen($u, $max, $was); return ''; } catch (RuntimeException $ex) { return $ex->getMessage(); } })();
    pruefe($meldung(['error' => UPLOAD_ERR_OK, 'size' => FOTO_MAX_BYTES + 1, 'tmp_name' => '/tmp/x'], FOTO_MAX_BYTES, 'Foto') === 'Das Foto ist zu groß (höchstens 8 MB).', 'zu groß: Meldung mit Grenze');
    pruefe($meldung(['error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => __FILE__], RM_BILD_MAX_BYTES, 'Bild') === 'Das Bild kam nicht an. Bitte noch einmal versuchen.', 'keine echte Upload-Datei abgewiesen');
    // Kontext statt $GLOBALS (V-10)
    ctx_setzen('test', 5);
    pruefe(ctx('test') === 5 && ctx_da('test'), 'ctx: setzen und lesen');
    ctx_weg('test');
    pruefe(ctx('test') === null && !ctx_da('test'), 'ctx: entfernen');
    $hostVor = ctx('host');
    ctx_leeren();
    pruefe(ctx('host') === null, 'ctx_leeren vergisst alles');
    ctx_setzen('host', $hostVor);
    pruefe(html_bereinigen('<p><b>a</b></p>') === '<p><strong>a</strong></p>' && html_bereinigen('<p>x</p>', 'inline') === 'x' && html_bereinigen('<h1>T</h1>', 'mail') === '<h2>T</h2>', 'html_bereinigen: Profile block, inline, mail');
}
