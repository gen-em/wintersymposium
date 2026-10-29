<?php
declare(strict_types=1);

/*
 * Spamschutz für öffentliche Formulare (R-03; Muster aus NAdoku registrieren.php):
 *   - Honigtopf: ein für Menschen unsichtbares Feld „website“, das leer bleiben muss
 *   - Zeitstempel: signiert; das Formular muss mindestens 4 s offen gewesen sein
 *     und darf höchstens 3 h alt sein
 *   - Rechenaufgabe (Proof-of-Work): der Browser sucht eine Zahl, mit der
 *     SHA-256 über Aufgabe und Zahl mit SPAM_POW_BITS Nullbits beginnt
 *     (assets/pow.js, assets/pow-worker.js); jede Aufgabe gilt einmal
 *   - Wegwerfadressen: Liste daten/wegwerfdomains.txt (Quelle: disposable-email-domains)
 * Greift eine Bremse, bekommt die Person dieselbe Antwort wie bei Erfolg, nur
 * ohne Mail (keine Auskunft für Automaten).
 */

const SPAM_MINDESTDAUER_S = 4;
const SPAM_FORMULAR_GILT_S = 3 * 3600;
/** 16 Bit: im Median unter einer Sekunde, auf alten Handys wenige Sekunden (Messung NAdoku). */
const SPAM_POW_BITS = 16;
const SPAM_POW_OFFEN = 5;
const SPAM_WEGWERF_DATEI = __DIR__ . '/../daten/wegwerfdomains.txt';

function spam_schluessel(): string
{
    return hash_hmac('sha256', 'spamschutz', (string)konfig('geheimnis', ''));
}

/** Signierter Zeitstempel fürs Formular: „<zeit>.<hmac>“. */
function spam_stempel(?int $jetzt = null): string
{
    $t = (string)($jetzt ?? time());
    return $t . '.' . hash_hmac('sha256', $t, spam_schluessel());
}

function spam_stempel_ok(string $roh, ?int $jetzt = null): bool
{
    $teile = explode('.', $roh, 2);
    if (count($teile) !== 2 || !ctype_digit($teile[0]) || !hash_equals(hash_hmac('sha256', $teile[0], spam_schluessel()), $teile[1])) {
        return false;
    }
    $alter = ($jetzt ?? time()) - (int)$teile[0];
    return $alter >= SPAM_MINDESTDAUER_S && $alter <= SPAM_FORMULAR_GILT_S;
}

/** Neue Rechenaufgabe; die Sitzung merkt sich höchstens SPAM_POW_OFFEN offene. */
function spam_pow_aufgabe(): string
{
    $jetzt = time();
    $liste = [];
    foreach ((array)($_SESSION['pow'] ?? []) as $a => $bis) {
        if (is_string($a) && is_int($bis) && $bis >= $jetzt) {
            $liste[$a] = $bis;
        }
    }
    $neu = bin2hex(random_bytes(32));
    $liste[$neu] = $jetzt + SPAM_FORMULAR_GILT_S;
    $_SESSION['pow'] = array_slice($liste, -SPAM_POW_OFFEN, null, true);
    return $neu;
}

/** Ist die Aufgabe gelöst? Die Aufgabe ist danach verbraucht, gelöst oder nicht. */
function spam_pow_ok(string $aufgabe, string $loesung): bool
{
    if (preg_match('/^[0-9a-f]{64}$/', $aufgabe) !== 1) {
        return false;
    }
    $liste = (array)($_SESSION['pow'] ?? []);
    $bis = $liste[$aufgabe] ?? null;
    unset($liste[$aufgabe]);
    $_SESSION['pow'] = $liste;
    if (!is_int($bis) || $bis < time() || preg_match('/^[0-9]{1,12}$/', $loesung) !== 1) {
        return false;
    }
    return spam_nullbits(hash('sha256', $aufgabe . $loesung, true), SPAM_POW_BITS);
}

function spam_nullbits(string $hash, int $bits): bool
{
    $voll = intdiv($bits, 8);
    for ($i = 0; $i < $voll; $i++) {
        if ($hash[$i] !== "\0") {
            return false;
        }
    }
    $rest = $bits % 8;
    return $rest === 0 || (ord($hash[$voll]) >> (8 - $rest)) === 0;
}

/** Wegwerfadresse? Geprüft wird die Domain und jede übergeordnete (ohne die Endung). */
function spam_wegwerf(string $email): bool
{
    static $liste = null;
    $at = strrpos($email, '@');
    if ($at === false) {
        return false;
    }
    if ($liste === null) {
        $liste = [];
        foreach (@file(SPAM_WEGWERF_DATEI, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $z) {
            $liste[trim($z)] = true;
        }
    }
    $teil = mb_strtolower(substr($email, $at + 1));
    while ($teil !== '' && str_contains($teil, '.')) {
        if (isset($liste[$teil])) {
            return true;
        }
        $teil = substr($teil, strpos($teil, '.') + 1);
    }
    return false;
}

/** Felder für das Formular (Honigtopf, Stempel, Aufgabe). */
function spam_felder(): string
{
    return '<div class="honig" aria-hidden="true"><label for="website">Website (bitte leer lassen)</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>'
        . '<input type="hidden" name="zeit" value="' . e(spam_stempel()) . '">'
        . '<input type="hidden" name="pow_aufgabe" value="' . e(spam_pow_aufgabe()) . '">'
        . '<input type="hidden" name="pow_loesung" value="">';
}

/** Alle Bremsen. true = sieht nach Mensch aus. */
function spam_ok(array $post): bool
{
    $s = fn(string $k) => is_string($post[$k] ?? null) ? $post[$k] : '';
    $pow = spam_pow_ok($s('pow_aufgabe'), $s('pow_loesung'));
    return $pow && trim($s('website')) === '' && spam_stempel_ok($s('zeit'));
}
