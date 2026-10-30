<?php
declare(strict_types=1);

/*
 * Abmelden mit einem Klick (AP-7): Ziel von List-Unsubscribe. Mailprogramme
 * schicken nach RFC 8058 ein POST mit „List-Unsubscribe=One-Click“ – das
 * meldet sofort von den Listen ab, ohne Sitzung und ohne CSRF-Token (der Link
 * selbst ist der Nachweis). Ein Aufruf im Browser (GET) zeigt einen Knopf.
 */

$token = is_string($_REQUEST['t'] ?? null) ? $_REQUEST['t'] : '';
$f = nl_link_finden($token, 'verwalten');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($f !== null) {
        nl_abmelden((int)$f['person']['id']);
    }
    if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
        kopf_gemeinsam();
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Abgemeldet.';
        exit;
    }
    seite_kopf('Abgemeldet', ['nav' => false]);
    echo '<div class="innen form-kopf"><p class="kicker">Newsletter</p><h1 class="titel">Abgemeldet</h1><p class="text">Du bekommst keine Newsletter mehr. Deine Adresse ist gelöscht.</p><p><a class="knopf zweit" href="/">Zur Startseite</a></p></div>';
    seite_fuss();
    exit;
}
seite_kopf('Abmelden', ['nav' => false]);
?>
<div class="innen form-kopf">
    <p class="kicker">Newsletter</p>
    <h1 class="titel">Abmelden</h1>
<?php if ($f === null): ?>
    <p class="text">Dieser Link gilt nicht mehr – oder du bist schon abgemeldet.</p>
<?php else: ?>
    <p class="text"><?= e($f['person']['email']) ?> von allen Newslettern abmelden?</p>
    <form method="post" action="/newsletter/abmelden"><input type="hidden" name="t" value="<?= e($token) ?>"><button class="knopf gefahr" type="submit">Ja, abmelden</button></form>
    <p class="meta klein"><a href="/newsletter/verwalten?t=<?= e($token) ?>">Nur einzelne Listen ändern</a></p>
<?php endif; ?>
</div>
<?php
seite_fuss();
