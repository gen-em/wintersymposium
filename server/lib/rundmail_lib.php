<?php
declare(strict_types=1);

/*
 * Mailings (AP-6, E-41, E-42, E-50): Rundmails an Teilnehmende.
 *
 * Ablauf: Entwurf → (geplant) → versand → gesendet. Beim Versand wird die
 * Empfängerliste festgehalten (Archiv, Ausnahme von R-02) und je Person ein
 * Verweis in die Warteschlange gestellt. Den Inhalt setzt mail_lib erst beim
 * Versand zusammen (rm_mail_fuer): aktueller Vorname, aktuelle Workshops und
 * ein frischer Einmal-Link für {link}. Das Tageslimit verteilt große
 * Rundmails auf mehrere Tage; Bestätigungen gehen immer vor.
 *
 * HTML: nur einfache Elemente (Absatz, Überschrift, fett, kursiv, Liste,
 * Link, Knopf, Bild). Was der Editor schickt, geht durch rm_html_bereinigen;
 * gespeichert wird nur das Ergebnis. Bilder werden hochgeladen, verkleinert,
 * neu geschrieben (ohne Metadaten) und in die Mail eingebettet – keine
 * fremden Server (E-41).
 */

const RM_PLATZHALTER = ['{vorname}' => 'Vorname', '{nachname}' => 'Nachname', '{workshops}' => 'Workshops der Person', '{link}' => 'Link zu „Deine Anmeldung“ (Einmal-Link)'];
const RM_STATUS = ['entwurf' => 'Entwurf', 'geplant' => 'Geplant', 'versand' => 'Wird gesendet', 'gesendet' => 'Gesendet', 'abgebrochen' => 'Abgebrochen'];
const RM_HTML_MAX = 200_000;
const RM_BILD_MAX_KANTE = 1200;
const RM_BILD_MAX_BYTES = 10 * 1024 * 1024;
/** Empfängerliste im Archiv: so lange wie Anmeldungen ohne Konto (E-24: 14 Monate nach dem Symposium). */
const RM_ARCHIV_MONATE = 14;
/** Mailbreite in Pixeln (Inhalt ohne Rand). */
const RM_INHALT_BREITE = 544;

/* ---- Empfänger ----------------------------------------------------------- */

/** Auswahl-Möglichkeiten: [wert => text]. Termine aus dem veröffentlichten Programm. */
function rm_auswahl_optionen(): array
{
    $o = [
        'angemeldet' => 'Alle Angemeldeten',
        'symposium_warte' => 'Warteliste Symposium',
        'warte' => 'Warteliste (alle)',
        'essen_kein' => 'Ohne Essen',
    ];
    foreach (team_termine() as $t) {
        if ($t['id'] !== 0) {
            $o['termin:' . $t['id']] = 'Workshop-Termin: ' . $t['text'];
            $o['warte:' . $t['id']] = 'Warteliste: ' . $t['text'];
        }
    }
    return $o;
}

/** Für das Auswahlfeld: Gruppen mit kurzen Einträgen (lange Texte machen Auswahlfelder auf dem Handy zu breit). */
function rm_auswahl_gruppen(): array
{
    $g = ['' => [], 'Teilnehmende eines Workshop-Termins' => [], 'Warteliste eines Termins' => []];
    foreach (rm_auswahl_optionen() as $k => $t) {
        if (str_starts_with($k, 'termin:')) {
            $g['Teilnehmende eines Workshop-Termins'][$k] = mb_substr($t, mb_strlen('Workshop-Termin: '));
        } elseif (str_starts_with($k, 'warte:')) {
            $g['Warteliste eines Termins'][$k] = mb_substr($t, mb_strlen('Warteliste: '));
        } else {
            $g[''][$k] = $t;
        }
    }
    return array_filter($g);
}

/** Muster einer Newsletter-Auswahl: „nl:1,2“, optional mit Zielgruppe für Allgäu Akut „;ziel:klinik“ bzw. „;ziel:va:5“ (AP-19). */
const RM_NL_MUSTER = '/^nl:(\d+(?:,\d+)*)(?:;ziel:(beide|klinik|praeklinik|va:\d+))?$/';

/** Newsletter-Listen einer Auswahl „nl:1,2“ oder null (AP-7). */
function rm_nl_listen(string $auswahl): ?array
{
    return preg_match(RM_NL_MUSTER, $auswahl, $m) === 1 ? array_map('intval', explode(',', $m[1])) : null;
}

/** Zielgruppe einer Newsletter-Auswahl wie gewählt: beide, klinik, praeklinik oder va:<id> (Bezug auf eine Veranstaltung). */
function rm_nl_ziel(string $auswahl): string
{
    return preg_match(RM_NL_MUSTER, $auswahl, $m) === 1 && ($m[2] ?? '') !== '' ? $m[2] : 'beide';
}

/** Wirksame Zielgruppe (E-71 (5)): mit Bezug auf eine Veranstaltung deren Einstellung „Zielgruppe“. */
function rm_nl_ziel_wirksam(string $auswahl): string
{
    $z = rm_nl_ziel($auswahl);
    if (str_starts_with($z, 'va:')) {
        $id = (int)substr($z, 3);
        return va_zeile($id) !== null ? va_von($id, 'zielgruppe') : 'beide';
    }
    return $z;
}

/**
 * Newsletter-Listen, an die das angemeldete Team-Konto Mailings schicken darf:
 * null = alle (BetreiberIn; außerhalb des Team-Bereichs, z. B. Jobs). Admins
 * dürfen die Listen der Reihen, für die sie freigeschaltet sind (O-52); die
 * Dachmarke und weitere Listen bleiben bei den BetreiberInnen (AP-12, E-60).
 */
function rm_nl_erlaubt_ids(): ?array
{
    if (!ctx_da('team_rolle') || ctx('team_rolle') === 'betreiber') {
        return null;
    }
    $konto = ctx('team_konto_zeile');
    $reihen = is_array($konto) ? va_erlaubte_reihen($konto) : [];
    return array_values(array_filter(array_map(fn($r) => $r['liste_id'], array_filter(va_reihen(), fn($r) => $reihen === null || in_array($r['id'], $reihen, true)))));
}

/** Darf das Team-Konto überhaupt Newsletter-Mailings schicken? */
function rm_newsletter_erlaubt(): bool
{
    $ids = rm_nl_erlaubt_ids();
    return $ids === null || $ids !== [];
}

/** Liegen alle Listen einer Auswahl im erlaubten Bereich? */
function rm_nl_darf(array $listeIds): bool
{
    $ids = rm_nl_erlaubt_ids();
    return $ids === null || array_diff($listeIds, $ids) === [];
}

/** Ist die Auswahl gültig (Teilnehmende oder vorhandene, erlaubte Newsletter-Listen)? */
function rm_auswahl_gueltig(string $auswahl): bool
{
    $nl = rm_nl_listen($auswahl);
    if ($nl !== null) {
        return rm_nl_darf($nl) && array_diff($nl, array_map('intval', array_column(nl_listen(), 'id'))) === [];
    }
    return array_key_exists($auswahl, rm_auswahl_optionen());
}

/** Klartext einer Auswahl (mit Berufsgruppe). */
function rm_auswahl_text(string $auswahl, string $bg): string
{
    $nl = rm_nl_listen($auswahl);
    if ($nl !== null) {
        $namen = array_column(array_filter(nl_listen(), fn($l) => in_array((int)$l['id'], $nl, true)), 'name');
        $ziel = rm_nl_ziel_wirksam($auswahl);
        $d = nl_liste_dachmarke();
        $zusatz = $d !== null && in_array((int)$d['id'], $nl, true) && $ziel !== 'beide' ? ' · ' . $d['name'] . ' nur ' . VA_ZIELGRUPPEN[$ziel] : '';
        return 'Newsletter: ' . implode(', ', $namen) . $zusatz;
    }
    $o = rm_auswahl_optionen();
    $t = $o[$auswahl] ?? $auswahl;
    return $t . ($bg !== '' ? ' · nur ' . $bg : '');
}

/** Bestätigte Anmeldungen der Auswahl: Liste mit id, vorname, nachname, email. */
function rm_empfaenger(string $auswahl, string $bg = ''): array
{
    $nl = rm_nl_listen($auswahl);
    if ($nl !== null) {
        return nl_empfaenger($nl, rm_nl_ziel_wirksam($auswahl));
    }
    // Empfänger aus der Veranstaltung des Kontexts (E-58)
    $wo = ["a.status = 'bestaetigt'", 'a.veranstaltung_id = ' . va_id()];
    $par = [];
    if ($auswahl === 'angemeldet') {
        $wo[] = "EXISTS (SELECT 1 FROM platz p WHERE p.anmeldung_id = a.id AND p.termin_id = 0 AND p.status = 'fest')";
    } elseif ($auswahl === 'symposium_warte') {
        $wo[] = "EXISTS (SELECT 1 FROM platz p WHERE p.anmeldung_id = a.id AND p.termin_id = 0 AND p.status IN ('warte','angebot'))";
    } elseif ($auswahl === 'warte') {
        $wo[] = "EXISTS (SELECT 1 FROM platz p WHERE p.anmeldung_id = a.id AND p.status IN ('warte','angebot'))";
    } elseif ($auswahl === 'essen_kein') {
        $wo[] = "a.essen = 'kein'";
        $wo[] = "EXISTS (SELECT 1 FROM platz p WHERE p.anmeldung_id = a.id AND p.termin_id = 0 AND p.status = 'fest')";
    } elseif (preg_match('/^(termin|warte):(\d+)$/', $auswahl, $m) === 1) {
        $wo[] = 'EXISTS (SELECT 1 FROM platz p WHERE p.anmeldung_id = a.id AND p.termin_id = ? AND p.status ' . ($m[1] === 'termin' ? "= 'fest'" : "IN ('warte','angebot')") . ')';
        $par[] = (int)$m[2];
    } else {
        return [];
    }
    if ($bg !== '') {
        $wo[] = 'a.berufsgruppe = ?';
        $par[] = $bg;
    }
    return db_zeilen('SELECT a.id, a.vorname, a.nachname, a.email FROM anmeldung a WHERE ' . implode(' AND ', $wo) . ' ORDER BY a.nachname, a.vorname, a.id', $par);
}

/* ---- Bereinigen und Darstellen ------------------------------------------ */

/** HTML aus dem Editor auf die erlaubten Elemente bringen. */
function rm_html_bereinigen(string $html): string
{
    $html = mb_substr($html, 0, RM_HTML_MAX);
    $wurzel = rm_dom($html);
    return $wurzel === null ? '' : trim(rm_knoten_kinder($wurzel, 'speichern', []));
}

/** HTML als DOM laden (UTF-8, ohne Zusatz-Elemente). Gibt den Wurzelknoten zurück. */
function rm_dom(string $html): ?DOMElement
{
    $d = new DOMDocument();
    $alt = libxml_use_internal_errors(true);
    $ok = $d->loadHTML('<?xml encoding="utf-8"?><div id="rm-wurzel">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($alt);
    if (!$ok) {
        return null;
    }
    foreach ($d->getElementsByTagName('div') as $div) {
        if ($div->getAttribute('id') === 'rm-wurzel') {
            return $div;
        }
    }
    return null;
}

/** Erlaubtes Linkziel: https/http/mailto oder der Platzhalter {link}. */
function rm_href_ok(string $href): ?string
{
    $href = trim($href);
    if ($href === '{link}') {
        return $href;
    }
    if (preg_match('#^(https?://[^\s<>"]+|mailto:[^\s<>"@]+@[^\s<>"]+)$#i', $href) === 1) {
        return $href;
    }
    return null;
}

/** Bild-ID aus dem src im Editor (/team/mailings/bild?id=N) oder null. */
function rm_bild_id(string $src): ?int
{
    return preg_match('#^/team/mailings/bild\?id=(\d+)$#', trim($src), $m) === 1 ? (int)$m[1] : null;
}

/**
 * Knoten ausgeben. $modus: 'speichern' (bereinigtes HTML), 'mail' (HTML mit
 * Stilen für Mailprogramme), 'text' (Textfassung). $k (Kontext für mail/text):
 * werte (Platzhalter → Text), bild (fn(int $id, array $b): ?string src).
 */
function rm_knoten_kinder(DOMNode $n, string $modus, array $k): string
{
    $h = '';
    foreach ($n->childNodes as $c) {
        $h .= rm_knoten($c, $modus, $k);
    }
    return $h;
}

function rm_knoten(DOMNode $n, string $modus, array $k): string
{
    if ($n instanceof DOMText) {
        $t = $n->data;
        if ($modus === 'speichern') {
            return e($t);
        }
        if ($modus === 'text') {
            return rm_platzhalter_text(preg_replace('/\s+/u', ' ', $t) ?? '', $k);
        }
        return rm_platzhalter_html($t, $k);
    }
    if (!$n instanceof DOMElement) {
        return '';
    }
    $tag = strtolower($n->tagName);
    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'head', 'title', 'template', 'svg', 'math', 'form', 'input', 'button', 'select', 'textarea'], true)) {
        return '';
    }
    $map = ['b' => 'strong', 'i' => 'em', 'h1' => 'h2', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3', 'div' => 'p'];
    $tag = $map[$tag] ?? $tag;
    $innen = rm_knoten_kinder($n, $modus, $k);
    switch ($tag) {
        case 'p':
        case 'h2':
        case 'h3':
            // Absatz in Absatz (Chrome-Editor) auflösen
            if ($modus === 'speichern' && preg_match('#<(p|h2|h3|ul|ol)[ >]#', $innen) === 1) {
                return $innen;
            }
            if (trim(strip_tags($innen, '<img><br>')) === '' && !str_contains($innen, '<img')) {
                return '';
            }
            if ($modus === 'text') {
                return trim($innen) . "\n\n";
            }
            if ($modus === 'mail') {
                $stil = ['p' => 'margin:0 0 16px;', 'h2' => 'margin:8px 0 12px;font-size:22px;line-height:1.25;color:#001E3A;', 'h3' => 'margin:8px 0 8px;font-size:18px;line-height:1.3;color:#001E3A;'][$tag];
                return '<' . $tag . ' style="' . $stil . '">' . $innen . '</' . $tag . '>';
            }
            return '<' . $tag . '>' . $innen . '</' . $tag . '>';
        case 'br':
            return $modus === 'text' ? "\n" : '<br>';
        case 'strong':
        case 'em':
            return $modus === 'text' ? $innen : ($innen === '' ? '' : '<' . $tag . '>' . $innen . '</' . $tag . '>');
        case 'ul':
        case 'ol':
            if ($modus === 'text') {
                return $innen . "\n";
            }
            return '<' . $tag . ($modus === 'mail' ? ' style="margin:0 0 16px;padding-left:22px;"' : '') . '>' . $innen . '</' . $tag . '>';
        case 'li':
            if ($modus === 'text') {
                return ($n->parentNode instanceof DOMElement && strtolower($n->parentNode->tagName) === 'ol' ? '1. ' : '- ') . trim($innen) . "\n";
            }
            return '<li' . ($modus === 'mail' ? ' style="margin:0 0 6px;"' : '') . '>' . $innen . '</li>';
        case 'a':
            $href = rm_href_ok($n->getAttribute('href'));
            $knopf = in_array('knopf', preg_split('/\s+/', $n->getAttribute('class')) ?: [], true);
            if ($href === null || trim(strip_tags($innen)) === '') {
                return $innen;
            }
            if ($modus === 'speichern') {
                return '<a href="' . e($href) . '"' . ($knopf ? ' class="knopf"' : '') . '>' . $innen . '</a>';
            }
            $ziel = $href === '{link}' ? (string)($k['werte']['{link}'] ?? '') : $href;
            if ($modus === 'text') {
                return trim($innen) . ': ' . $ziel . ($knopf ? "\n" : '');
            }
            $stil = $knopf
                ? 'display:inline-block;background:' . MAIL_LILA . ';color:' . MAIL_NAVY . ';padding:13px 26px;border-radius:999px;text-decoration:none;font-weight:bold;'
                : 'color:#6B3FD1;text-decoration:underline;';
            return '<a href="' . e($ziel) . '" style="' . $stil . '">' . $innen . '</a>';
        case 'img':
            $id = rm_bild_id($n->getAttribute('src'));
            $b = $id !== null ? rm_bild($id) : null;
            if ($b === null) {
                return '';
            }
            $breite = $n->getAttribute('data-breite') === '50' ? 50 : 100;
            $alt = mb_substr(trim($n->getAttribute('alt')), 0, 200);
            if ($modus === 'speichern') {
                return '<img src="/team/mailings/bild?id=' . $id . '" alt="' . e($alt) . '" data-breite="' . $breite . '">';
            }
            if ($modus === 'text') {
                return $alt !== '' ? '[Bild: ' . $alt . ']' : '';
            }
            $px = (int)min($b['breite'], round(RM_INHALT_BREITE * $breite / 100));
            $src = ($k['bild'])($id, $b);
            return $src === null ? '' : '<img src="' . e($src) . '" alt="' . e($alt) . '" width="' . $px . '" style="display:block;width:' . $px . 'px;max-width:100%;height:auto;border:0;margin:0 0 16px;">';
        default:
            // Unbekannte Elemente (span, font, table …): nur der Inhalt bleibt
            return $innen;
    }
}

/** Platzhalter im Text (HTML-sicher). */
function rm_platzhalter_html(string $t, array $k): string
{
    $t = rm_ohne_leeren_vornamen($t, $k);
    $h = e($t);
    foreach ($k['werte'] ?? [] as $p => $w) {
        if ($p === '{workshops}') {
            $h = str_replace($p, implode('<br>', array_map('e', explode("\n", $w))), $h);
        } else {
            $h = str_replace($p, e($w), $h);
        }
    }
    return $h;
}

function rm_platzhalter_text(string $t, array $k): string
{
    return strtr(rm_ohne_leeren_vornamen($t, $k), $k['werte'] ?? []);
}

/** Ohne Vorname (Newsletter): „Hallo {vorname},“ wird „Hallo,“ (Admin 2026-10-30). */
function rm_ohne_leeren_vornamen(string $t, array $k): string
{
    return ($k['werte']['{vorname}'] ?? null) === '' ? (preg_replace('/[ \t]*\{vorname\}/u', '', $t) ?? $t) : $t;
}

/** Workshops einer Anmeldung als Zeilen (feste Plätze). */
function rm_workshops_text(int $anmId): string
{
    $z = [];
    foreach (db_zeilen("SELECT termin_id FROM platz WHERE anmeldung_id = ? AND termin_id > 0 AND status = 'fest'", [$anmId]) as $p) {
        $z[] = anm_termin_text((int)$p['termin_id']);
    }
    sort($z);
    return $z === [] ? 'keine Workshops' : implode("\n", $z);
}

/**
 * Mail eines Mailings zusammensetzen. $werte: Platzhalter → Text.
 * $bildQuelle: fn(int $id, array $bild): ?string (cid:… im Versand, URL in der Vorschau).
 * Gibt ['betreff', 'html', 'text', 'bilder' => [cid => [typ, pfad]]] zurück.
 */
function rm_zusammensetzen(array $rm, array $werte, string $bildArt, string $domain = 'localhost', ?array $nl = null): array
{
    $bilder = [];
    $k = ['werte' => $werte, 'bild' => function (int $id, array $b) use ($bildArt, &$bilder, $domain): ?string {
        $pfad = foto_ordner() . '/' . $b['datei'];
        if (!is_file($pfad)) {
            return null;
        }
        if ($bildArt === 'url') {
            return '/team/mailings/bild?id=' . $id;
        }
        $cid = 'bild' . $id;
        $bilder[$cid] = [$b['typ'], $pfad];
        return 'cid:' . $cid . '@' . $domain;
    }];
    $wurzel = rm_dom((string)$rm['html']);
    $inhalt = $wurzel === null ? '' : rm_knoten_kinder($wurzel, 'mail', $k);
    $text = $wurzel === null ? '' : rm_knoten_kinder($wurzel, 'text', $k);
    $text = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace(['/[ \t]+\n/', '/\n[ \t]+/'], "\n", $text) ?? '') ?? '');
    $betreff = rm_platzhalter_text((string)$rm['betreff'], $k);
    // Newsletter (AP-7): Grund, Abmelden und Listen ändern; Mailings an Angemeldete ohne Abmeldelink (E-50)
    $fuss = $nl === null ? 'Du bekommst diese Mail, weil du zum ' . va_kurz() . ' angemeldet bist.' : 'Du bekommst diese Mail, weil du den Newsletter „' . $nl['listen'] . '“ abonniert hast.';
    $text .= "\n\n-- \n" . $fuss . ($nl !== null ? "\nAbmelden oder Listen ändern: " . $nl['url'] : '') . "\nImpressum: " . url('/impressum') . "\nDatenschutz: " . url('/datenschutz') . "\n";
    // Rahmen wie alle Mails (O-40, Variante A); Fuß mit Grund, Abmelden, Impressum und Datenschutz
    $fussHtml = mail_fuss_html(e($fuss) . ($nl !== null ? '<br><a href="' . e($nl['url']) . '" style="color:#B9C6D3;text-decoration:underline;">Abmelden oder Listen ändern</a>' : ''));
    $html = mail_rahmen($betreff, $nl === null ? mail_kicker() : mail_kicker('newsletter'), $betreff, $inhalt, $fussHtml);
    if ($bildArt === 'url') {
        $html = mail_logo_einsetzen($html, '/' . MAIL_LOGO_DATEI);
    }
    return ['betreff' => $betreff, 'html' => $html, 'text' => $text, 'bilder' => $bilder];
}

/** Platzhalter-Werte für eine Person; ohne $mitLink steht {link} auf der allgemeinen Seite (Vorschau, Test). */
function rm_werte(array $a, bool $mitLink): array
{
    $link = url('/deine-anmeldung');
    if ($mitLink) {
        $token = anm_link_erzeugen((int)$a['id'], 'zugang', time() + ANM_LINK_GUELTIG_S);
        $link = anm_link_url($token);
    }
    return [
        '{vorname}' => (string)$a['vorname'],
        '{nachname}' => (string)$a['nachname'],
        '{workshops}' => isset($a['id']) ? rm_workshops_text((int)$a['id']) : "WS 01 Beispiel · 19:30–20:15",
        '{link}' => $link,
    ];
}

/**
 * Für die Warteschlange: Newsletter-Mail an eine Person (AP-7). null, wenn sie
 * inzwischen von allen Listen des Mailings abgemeldet ist. Mit Abmeldelink und
 * List-Unsubscribe (One-Click, RFC 8058).
 */
function rm_mail_fuer_nl(int $rmId, int $personId): ?array
{
    $rm = rm_laden($rmId);
    $p = nl_person($personId);
    $listen = $rm !== null ? (rm_nl_listen($rm['auswahl']) ?? []) : [];
    if ($rm === null || $p === null) {
        return null;
    }
    $aktiv = array_keys(array_filter(nl_abos($personId), fn($s) => $s === 'bestaetigt'));
    $treffer = array_values(array_intersect($listen, $aktiv));
    if ($treffer === []) {
        return null;
    }
    $alle = nl_listen();
    $namen = array_column(array_filter($alle, fn($l) => in_array((int)$l['id'], $treffer, true)), 'name');
    $erste = array_values(array_filter($alle, fn($l) => (int)$l['id'] === $treffer[0]))[0] ?? null;
    $token = nl_link_erzeugen($personId, 'verwalten');
    $verwalten = url('/newsletter/verwalten?t=' . $token);
    $absender = mail_absender(mail_konfig($rm['postfach']));
    $m = rm_zusammensetzen($rm, ['{vorname}' => (string)$p['vorname'], '{nachname}' => '', '{workshops}' => '', '{link}' => $verwalten], 'cid',
        substr(strrchr($absender, '@') ?: '@localhost', 1), ['listen' => implode('“, „', $namen), 'url' => $verwalten]);
    $abmelden = url('/newsletter/abmelden?t=' . $token);
    return $m + ['kopf' => ['List-Unsubscribe: <' . $abmelden . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'],
        'absender_name' => $erste !== null && $erste['absender_name'] !== '' ? $erste['absender_name'] : null];
}

/** Für die Warteschlange: Mail eines Mailings an eine Anmeldung (oder null, wenn es sie nicht mehr gibt). */
function rm_mail_fuer(int $rmId, int $anmId): ?array
{
    $va = db_wert('SELECT veranstaltung_id FROM anmeldung WHERE id = ?', [$anmId]);
    if ($va !== null && (int)$va !== va_id()) {
        return va_mit((int)$va, fn() => rm_mail_fuer($rmId, $anmId));
    }
    $rm = rm_laden($rmId);
    $a = db_zeile('SELECT id, vorname, nachname, email FROM anmeldung WHERE id = ?', [$anmId]);
    if ($rm === null || $a === null) {
        return null;
    }
    $absender = mail_absender(mail_konfig($rm['postfach']));
    // Mailing an Angemeldete: Absender ist die Veranstaltung (E-67)
    return rm_zusammensetzen($rm, rm_werte($a, true), 'cid', substr(strrchr($absender, '@') ?: '@localhost', 1)) + ['absender_name' => va_absender_name()];
}

/** Vorschau: mit der ersten Person der Auswahl, sonst mit Beispielwerten. */
function rm_vorschau(array $rm, array $konto): array
{
    $e = rm_empfaenger($rm['auswahl'], $rm['berufsgruppe']);
    $p = $e[0] ?? null;
    $nl = rm_nl_listen($rm['auswahl']);
    if ($nl !== null) {
        $namen = rm_auswahl_text($rm['auswahl'], '');
        $werte = ['{vorname}' => (string)($p['vorname'] ?? ''), '{nachname}' => '', '{workshops}' => '', '{link}' => url('/newsletter')];
        return rm_zusammensetzen($rm, $werte, 'url', 'localhost', ['listen' => substr($namen, strlen('Newsletter: ')), 'url' => url('/newsletter')])
            + ['an' => $p !== null ? ($p['vorname'] !== '' ? $p['vorname'] . ' · ' : '') . $p['email'] : 'Beispiel'];
    }
    $werte = $p !== null ? rm_werte($p, false) : rm_werte(['vorname' => tk_namen($konto)['vorname'], 'nachname' => tk_namen($konto)['nachname']], false);
    return rm_zusammensetzen($rm, $werte, 'url') + ['an' => $p !== null ? person_name($p) : 'Beispiel'];
}

/* ---- Speichern, Senden, Planen ------------------------------------------ */

function rm_laden(int $id): ?array
{
    // Ohne Rechteprüfung: der Mailversand braucht jedes Mailing, auch nach einer
    // Admin-Aktion (Review B-1). Team-Seiten prüfen zusätzlich rm_sichtbar().
    return db_zeile('SELECT * FROM rundmail WHERE id = ?', [$id]);
}

/**
 * Darf das angemeldete Team-Konto dieses Mailing sehen und ändern? Mailings an
 * Angemeldete gehören zur gewählten Veranstaltung; Newsletter-Mailings (ohne
 * Veranstaltung, E-58) nur für BetreiberInnen (AP-12, E-60).
 */
function rm_sichtbar(array $rm): bool
{
    return $rm['veranstaltung_id'] === null ? rm_nl_darf(rm_nl_listen((string)$rm['auswahl']) ?? []) && rm_newsletter_erlaubt() : (int)$rm['veranstaltung_id'] === va_id();
}

/** Eingaben prüfen. Gibt ['werte' => …, 'fehler' => [feld => text]] zurück. */
function rm_pruefen(array $e): array
{
    // Empfängerart Newsletter (AP-7): gewählte Listen als „nl:1,2“
    if (($e['art'] ?? '') === 'newsletter') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($e['listen'] ?? [])))));
        sort($ids);
        $e['auswahl'] = $ids === [] ? 'nl:' : 'nl:' . implode(',', $ids);
        // Zielgruppe nur, wenn Allgäu Akut dabei ist (E-71 (5))
        $ziel = (string)($e['nl_ziel'] ?? 'beide');
        $d = nl_liste_dachmarke();
        if ($ids !== [] && $d !== null && in_array((int)$d['id'], $ids, true) && $ziel !== 'beide' && preg_match('/^(klinik|praeklinik|va:\d+)$/', $ziel) === 1
            && (!str_starts_with($ziel, 'va:') || va_zeile((int)substr($ziel, 3)) !== null)) {
            $e['auswahl'] .= ';ziel:' . $ziel;
        }
        $e['berufsgruppe'] = '';
    }
    $w = [
        'betreff' => trim(mb_substr(preg_replace('/[\r\n]+/', ' ', (string)($e['betreff'] ?? '')) ?? '', 0, 200)),
        'html' => html_bereinigen((string)($e['html'] ?? ''), 'mail'),
        'auswahl' => (string)($e['auswahl'] ?? 'angemeldet'),
        'berufsgruppe' => (string)($e['berufsgruppe'] ?? ''),
        'postfach' => (string)($e['postfach'] ?? 'system'),
    ];
    $f = [];
    if (!rm_auswahl_gueltig($w['auswahl'])) {
        $f['auswahl'] = str_starts_with($w['auswahl'], 'nl:') ? 'Bitte mindestens eine Newsletter-Liste wählen.' : 'Bitte eine Auswahl treffen.';
        $w['auswahl'] = 'angemeldet';
    }
    if ($w['berufsgruppe'] !== '' && !in_array($w['berufsgruppe'], va_liste('berufsgruppen'), true)) {
        $f['berufsgruppe'] = 'Unbekannte Berufsgruppe.';
        $w['berufsgruppe'] = '';
    }
    if (!isset(MAIL_POSTFAECHER[$w['postfach']]) || !mail_postfach_da($w['postfach'])) {
        $f['postfach'] = 'Dieses Postfach ist nicht eingerichtet.';
        $w['postfach'] = 'system';
    }
    return ['werte' => $w, 'fehler' => $f];
}

/** Entwurf speichern (neu oder vorhanden). Gibt die ID zurück; null, wenn er kein Entwurf mehr ist. */
function rm_speichern(?int $id, array $w, ?int $kontoId): ?int
{
    $jetzt = db_jetzt();
    if ($id === null) {
        db_ausfuehren(
            "INSERT INTO rundmail (veranstaltung_id, betreff, html, auswahl, berufsgruppe, postfach, status, erstellt_von, erstellt_am, geaendert_am) VALUES (?, ?, ?, ?, ?, ?, 'entwurf', ?, ?, ?)",
            [rm_nl_listen($w['auswahl']) !== null ? null : va_id(), $w['betreff'], $w['html'], $w['auswahl'], $w['berufsgruppe'], $w['postfach'], $kontoId, $jetzt, $jetzt]
        );
        return (int)db()->lastInsertId();
    }
    $n = db_ausfuehren(
        "UPDATE rundmail SET veranstaltung_id = ?, betreff = ?, html = ?, auswahl = ?, berufsgruppe = ?, postfach = ?, geaendert_am = ? WHERE id = ? AND status = 'entwurf'",
        [rm_nl_listen($w['auswahl']) !== null ? null : va_id(), $w['betreff'], $w['html'], $w['auswahl'], $w['berufsgruppe'], $w['postfach'], $jetzt, $id]
    )->rowCount();
    // Mailing inzwischen gelöscht: kein Entwurf mehr (Review B-14)
    return $n === 1 || (rm_laden($id)['status'] ?? '') === 'entwurf' ? $id : null;
}

/** Fehler, die das Senden verhindern (leer = sendbar). */
function rm_sendefehler(array $rm): array
{
    $f = [];
    if (trim($rm['betreff']) === '') {
        $f[] = 'Bitte einen Betreff eintragen.';
    }
    if (trim(strip_tags($rm['html'], '<img>')) === '') {
        $f[] = 'Die Mail hat noch keinen Text.';
    }
    if (rm_empfaenger($rm['auswahl'], $rm['berufsgruppe']) === []) {
        $f[] = 'Die Auswahl hat keine Empfänger.';
    }
    if (!mail_postfach_da($rm['postfach'])) {
        $f[] = 'Das Postfach ist nicht eingerichtet.';
    }
    return $f;
}

/**
 * Jetzt senden: Empfängerliste festhalten und in die Warteschlange stellen.
 * Gibt die Zahl der Empfänger zurück oder null, wenn das Mailing nicht
 * (mehr) sendbar ist.
 */
function rm_einreihen(int $id, ?int $kontoId, ?int $jetzt = null): ?int
{
    // Empfänger aus der Veranstaltung des Mailings (auch aus dem Job ohne Kontext)
    $va = db_wert('SELECT veranstaltung_id FROM rundmail WHERE id = ?', [$id]);
    if ($va !== null && (int)$va !== va_id()) {
        return va_mit((int)$va, fn() => rm_einreihen($id, $kontoId, $jetzt));
    }
    $jetzt ??= time();
    $zahl = db_transaktion(function () use ($id, $kontoId, $jetzt): ?int {
        $rm = db_zeile('SELECT * FROM rundmail WHERE id = ? FOR UPDATE', [$id]);
        if ($rm === null || !in_array($rm['status'], ['entwurf', 'geplant'], true) || rm_sendefehler($rm) !== []) {
            return null;
        }
        $liste = rm_empfaenger($rm['auswahl'], $rm['berufsgruppe']);
        $jetztDb = db_zeit($jetzt);
        // Archiv bis 14 Monate nach dem Symposium (E-24); gilt das Datum schon als vorbei, ab heute
        // Newsletter (AP-7): Liste 14 Monate ab Versand; sonst bis 14 Monate nach dem Symposium
        $nl = rm_nl_listen($rm['auswahl']) !== null;
        $basis = $nl ? db_tag($jetzt) : max(va('veranstaltung_datum'), db_tag($jetzt));
        $loeschen = (new DateTimeImmutable($basis))->modify('+' . RM_ARCHIV_MONATE . ' months')->format('Y-m-d');
        foreach ($liste as $a) {
            $name = person_name($a);
            db_ausfuehren('INSERT IGNORE INTO rundmail_empfaenger (rundmail_id, anmeldung_id, nl_person_id, name, email) VALUES (?, ?, ?, ?, ?)',
                [$id, $nl ? null : $a['id'], $nl ? $a['id'] : null, $name !== '' ? $name : $a['email'], $a['email']]);
            db_ausfuehren(
                "INSERT INTO mail_ausgang (an, betreff, text, prio, postfach, rundmail_id, anmeldung_id, nl_person_id, status, versuche, naechster_versuch, erstellt_am) VALUES (?, '', '', ?, ?, ?, ?, ?, 'offen', 0, ?, ?)",
                [$a['email'], MAIL_PRIO_RUNDMAIL, $rm['postfach'], $id, $nl ? null : $a['id'], $nl ? $a['id'] : null, $jetztDb, $jetztDb]
            );
        }
        db_ausfuehren(
            "UPDATE rundmail SET status = 'versand', gesendet_am = ?, gesendet_von = ?, empfaenger_zahl = ?, auswahl_text = ?, loeschen_ab = ?, geplant_fuer = NULL WHERE id = ?",
            [$jetztDb, $kontoId ?? $rm['gesendet_von'], count($liste), mb_substr(rm_auswahl_text($rm['auswahl'], $rm['berufsgruppe']), 0, 200), $loeschen, $id]
        );
        return count($liste);
    });
    if ($zahl !== null) {
        audit_schreiben('mailing', audit_text('rundmail', $id, 'an ' . $zahl . ' Empfänger in die Warteschlange gestellt'), $kontoId, ['rundmail' => $id], rm_va((int)$id));
        mail_nach_antwort_vormerken();
    }
    return $zahl;
}

/** Planen: Zeitpunkt (UTC, in der Zukunft). */
function rm_planen(int $id, string $utc, int $kontoId): bool
{
    $n = db_ausfuehren("UPDATE rundmail SET status = 'geplant', geplant_fuer = ?, gesendet_von = ?, geaendert_am = ? WHERE id = ? AND status IN ('entwurf','geplant')", [$utc, $kontoId, db_jetzt(), $id])->rowCount();
    if ($n === 1) {
        audit_schreiben('mailing', audit_text('rundmail', $id, 'geplant für ' . datum_zeit_text($utc)), $kontoId, ['rundmail' => $id], rm_va((int)$id));
    }
    return $n === 1;
}

/**
 * Abbrechen: geplant → wieder Entwurf; im Versand → noch nicht gesendete
 * Mails entfernen. Gibt einen Text für die Oberfläche zurück oder null.
 */
function rm_abbrechen(int $id, int $kontoId): ?string
{
    $rm = rm_laden($id);
    if ($rm === null) {
        return null;
    }
    if ($rm['status'] === 'geplant') {
        db_ausfuehren("UPDATE rundmail SET status = 'entwurf', geplant_fuer = NULL, geaendert_am = ? WHERE id = ?", [db_jetzt(), $id]);
        audit_schreiben('mailing', audit_text('rundmail', $id, 'Planung aufgehoben'), $kontoId, ['rundmail' => $id], rm_va((int)$id));
        return 'Planung aufgehoben – das Mailing ist wieder ein Entwurf.';
    }
    if ($rm['status'] === 'versand') {
        // Wer die Mail nicht mehr bekommt, steht auch nicht in der Empfängerliste (Nachweis, Review B-12)
        [$weg, $raus] = db_transaktion(function () use ($id): array {
            $offen = array_column(db_zeilen("SELECT an FROM mail_ausgang WHERE rundmail_id = ? AND status = 'offen' FOR UPDATE", [$id]), 'an');
            $weg = db_ausfuehren("DELETE FROM mail_ausgang WHERE rundmail_id = ? AND status = 'offen'", [$id])->rowCount();
            foreach ($offen as $an) {
                db_ausfuehren('DELETE FROM rundmail_empfaenger WHERE rundmail_id = ? AND email = ?', [$id, $an]);
            }
            $raus = db_zahl('SELECT COUNT(*) FROM rundmail_empfaenger WHERE rundmail_id = ?', [$id]);
            db_ausfuehren("UPDATE rundmail SET status = 'abgebrochen', empfaenger_zahl = ? WHERE id = ?", [$raus, $id]);
            return [$weg, $raus];
        });
        audit_schreiben('mailing', audit_text('rundmail', $id, 'Versand abgebrochen: ' . $raus . ' gesendet, ' . $weg . ' nicht'), $kontoId, ['rundmail' => $id], rm_va((int)$id));
        return 'Versand abgebrochen: ' . $weg . ' Mails gehen nicht mehr raus.';
    }
    return null;
}

/** Stand des Versands: gesendet, wartet, fehler. */
function rm_fortschritt(int $id): array
{
    $z = ['gesendet' => 0, 'wartet' => 0, 'fehler' => 0];
    foreach (db_zeilen('SELECT status, COUNT(*) AS n FROM mail_ausgang WHERE rundmail_id = ? GROUP BY status', [$id]) as $r) {
        $k = $r['status'] === 'gesendet' ? 'gesendet' : ($r['status'] === 'fehler' ? 'fehler' : 'wartet');
        $z[$k] += (int)$r['n'];
    }
    return $z;
}

/** Als neuen Entwurf kopieren. */
function rm_kopieren(int $id, int $kontoId): ?int
{
    $rm = rm_laden($id);
    if ($rm === null) {
        return null;
    }
    $w = ['betreff' => $rm['betreff'], 'html' => $rm['html'], 'auswahl' => rm_auswahl_gueltig($rm['auswahl']) ? $rm['auswahl'] : 'angemeldet', 'berufsgruppe' => $rm['berufsgruppe'], 'postfach' => $rm['postfach']];
    return rm_speichern(null, $w, $kontoId);
}

function rm_loeschen(int $id, int $kontoId): bool
{
    $va = rm_va($id);
    $n = db_ausfuehren("DELETE FROM rundmail WHERE id = ? AND status = 'entwurf'", [$id])->rowCount();
    if ($n === 1) {
        audit_schreiben('mailing', audit_text('rundmail', $id, 'Entwurf gelöscht'), $kontoId, ['rundmail' => $id], $va);
    }
    return $n === 1;
}

/** Liste für die Übersicht: Entwürfe und geplante zuerst, dann gesendete. */
function rm_liste(): array
{
    $z = db_zeilen(
        "SELECT r.id, r.veranstaltung_id, r.betreff, r.status, r.geplant_fuer, r.gesendet_am, r.empfaenger_zahl, r.auswahl, r.berufsgruppe, r.auswahl_text, r.geaendert_am, k.name AS von
         FROM rundmail r LEFT JOIN konto k ON k.id = COALESCE(r.gesendet_von, r.erstellt_von)
         WHERE " . (rm_newsletter_erlaubt() ? 'r.veranstaltung_id IS NULL OR ' : '') . "r.veranstaltung_id = " . va_id() . "
         ORDER BY FIELD(r.status, 'versand', 'geplant', 'entwurf', 'gesendet', 'abgebrochen'), COALESCE(r.gesendet_am, r.geaendert_am) DESC, r.id DESC"
    );
    // Newsletter-Mailings nur an erlaubte Listen (Admins: ihre Reihen, O-52)
    return array_values(array_filter($z, fn($r) => $r['veranstaltung_id'] !== null || rm_nl_darf(rm_nl_listen((string)$r['auswahl']) ?? [])));
}

/* ---- Job ----------------------------------------------------------------- */

/** Geplante senden, fertige abschließen, Archiv und Bilder aufräumen. */
function rm_job(int $jetzt): array
{
    $erg = ['gestartet' => 0, 'fertig' => 0, 'archiv' => 0, 'bilder' => 0];
    foreach (db_zeilen("SELECT id FROM rundmail WHERE status = 'geplant' AND geplant_fuer <= ?", [db_zeit($jetzt)]) as $r) {
        if (rm_einreihen((int)$r['id'], null, $jetzt) !== null) {
            $erg['gestartet']++;
        } else {
            // Nicht mehr sendbar (z. B. keine Empfänger): zurück zum Entwurf, im Protokoll vermerkt
            db_ausfuehren("UPDATE rundmail SET status = 'entwurf', geplant_fuer = NULL WHERE id = ? AND status = 'geplant'", [$r['id']]);
            audit_schreiben('mailing', audit_text('rundmail', (int)$r['id'], 'geplant, aber nicht gesendet (keine Empfänger oder unvollständig) – wieder Entwurf'), null, ['rundmail' => (int)$r['id']], rm_va((int)$r['id']));
        }
    }
    $erg['fertig'] = db_ausfuehren(
        "UPDATE rundmail r SET r.status = 'gesendet' WHERE r.status = 'versand'
         AND NOT EXISTS (SELECT 1 FROM mail_ausgang m WHERE m.rundmail_id = r.id AND m.status IN ('offen','sendet'))"
    )->rowCount();
    $erg['archiv'] = db_ausfuehren('DELETE e FROM rundmail_empfaenger e JOIN rundmail r ON r.id = e.rundmail_id WHERE r.loeschen_ab IS NOT NULL AND r.loeschen_ab <= ?', [db_tag($jetzt)])->rowCount();
    $erg['bilder'] = rm_bilder_aufraeumen($jetzt) + text_bilder_aufraeumen($jetzt);
    return $erg;
}

/* ---- Bilder -------------------------------------------------------------- */

function rm_bild(int $id): ?array
{
    return db_zeile('SELECT id, datei, typ, breite, hoehe, erstellt_von FROM rundmail_bild WHERE id = ?', [$id]);
}

/**
 * Darf dieses Team-Konto das Bild sehen (Review S-5)? Selbst hochgeladen oder
 * in einem Mailing, das es sehen darf (rm_sichtbar: eigene Veranstaltung,
 * Newsletter nur BetreiberInnen).
 */
function rm_bild_sichtbar(array $b, int $kontoId): bool
{
    if ((int)($b['erstellt_von'] ?? 0) === $kontoId) {
        return true;
    }
    $id = (int)$b['id'];
    foreach (db_zeilen('SELECT * FROM rundmail WHERE html LIKE ?', ['%/team/mailings/bild?id=' . $id . '%']) as $rm) {
        if (preg_match('#/team/mailings/bild\?id=' . $id . '(?!\d)#', (string)$rm['html']) === 1 && rm_sichtbar($rm)) {
            return true;
        }
    }
    return false;
}

/**
 * Hochgeladenes Bild prüfen, auf höchstens 1200 px verkleinern und neu
 * schreiben (JPEG; PNG bleibt PNG wegen Transparenz). Gibt die Zeile zurück
 * oder wirft eine RuntimeException mit einem Text für die Oberfläche.
 */
function rm_bild_speichern(array $upload, int $kontoId): array
{
    return rm_bild_aus_daten(bild_upload_lesen($upload), $kontoId);
}

/** Bilddaten verarbeiten (auch für Tests und Testdaten). */
function rm_bild_aus_daten(string $daten, ?int $kontoId): array
{
    $b = bild_verkleinert_speichern($daten, 'mail');
    db_ausfuehren('INSERT INTO rundmail_bild (datei, typ, breite, hoehe, erstellt_von, erstellt_am) VALUES (?, ?, ?, ?, ?, ?)', [$b['datei'], $b['typ'], $b['breite'], $b['hoehe'], $kontoId, db_jetzt()]);
    return rm_bild((int)db()->lastInsertId());
}

/** Bilder, die nach einem Tag in keinem Mailing vorkommen, löschen. */
function rm_bilder_aufraeumen(int $jetzt): int
{
    $genutzt = [];
    foreach (db_zeilen('SELECT html FROM rundmail') as $r) {
        preg_match_all('#/team/mailings/bild\?id=(\d+)#', (string)$r['html'], $m);
        foreach ($m[1] as $id) {
            $genutzt[(int)$id] = true;
        }
    }
    $n = 0;
    foreach (db_zeilen('SELECT id, datei FROM rundmail_bild WHERE erstellt_am < ?', [db_zeit($jetzt - 86400)]) as $b) {
        if (!isset($genutzt[(int)$b['id']])) {
            @unlink(foto_ordner() . '/' . basename($b['datei']));
            db_ausfuehren('DELETE FROM rundmail_bild WHERE id = ?', [$b['id']]);
            $n++;
        }
    }
    return $n;
}

/** Veranstaltung eines Mailings für das Protokoll; 0 = Newsletter (keine Veranstaltung, AP-12). */
function rm_va(int $id): int
{
    return (int)(db_wert('SELECT veranstaltung_id FROM rundmail WHERE id = ?', [$id]) ?? 0);
}

/** Hochgeladenes Bild ans Ende des Mailing-Textes setzen (volle Breite). */
function rm_bild_anhaengen(int $rmId, int $bildId): void
{
    db_ausfuehren('UPDATE rundmail SET html = CONCAT(html, ?) WHERE id = ?', ['<p><img src="/team/mailings/bild?id=' . $bildId . '" alt="" data-breite="100"></p>', $rmId]);
}

/** Empfängerliste eines verschickten Mailings aus dem Archiv: [['name', 'email'], …]. */
function rm_empfaenger_archiv(int $rmId): array
{
    return db_zeilen('SELECT name, email FROM rundmail_empfaenger WHERE rundmail_id = ? ORDER BY name', [$rmId]);
}
