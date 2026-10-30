<?php
declare(strict_types=1);

/*
 * Dozierende verwalten (AP-3 Schritt 3, E-39): Name, Funktion, Kurzbio, Foto
 * (optional). Mehreren Programmpunkten zuordenbar. Änderungen erscheinen wie
 * alles im Programm erst nach „Jetzt veröffentlichen“ auf der Startseite.
 */

$konto = team_tor();
$ich = (int)$konto['id'];
$fehler = [];
$eingabe = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_pruefen();
    $aktion = (string)($_POST['aktion'] ?? '');
    $id = (int)($_POST['id'] ?? 0) ?: null;
    if ($id !== null && db_wert('SELECT COUNT(*) FROM dozent WHERE id = ?', [$id]) != 1) {
        seite_meldung(404, 'Nicht gefunden', 'Diese Person gibt es nicht mehr.');
    }
    if ($aktion === 'speichern') {
        $r = dozent_pruefen($_POST);
        $fehler = $r['fehler'];
        $upload = $_FILES['foto'] ?? null;
        if ($fehler === []) {
            $id = dozent_speichern($id, $r['werte'], $ich);
            if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    foto_speichern($id, $upload);
                } catch (RuntimeException $ex) {
                    flash_setzen('fehler', 'Gespeichert, aber ohne Foto: ' . $ex->getMessage());
                    umleiten('/team/dozierende?id=' . $id);
                }
            } elseif (!empty($_POST['foto_weg'])) {
                foto_entfernen($id);
            }
            flash_setzen('ok', 'Gespeichert. Auf der Startseite nach „Jetzt veröffentlichen“ im Reiter Programm.');
            umleiten('/team/dozierende?id=' . $id);
        }
        $eingabe = $_POST;
    } elseif ($aktion === 'loeschen' && $id !== null) {
        dozent_loeschen($id, $ich);
        flash_setzen('ok', 'Gelöscht. Die Zuordnung zu Programmpunkten ist entfernt (noch nicht veröffentlicht).');
        umleiten('/team/dozierende');
    }
}

$liste = dozent_liste();
$bearbeiten = null;
if (isset($_GET['id'])) {
    foreach ($liste as $d) {
        if ((int)$d['id'] === (int)$_GET['id']) {
            $bearbeiten = $d;
        }
    }
}
$neu = isset($_GET['neu']) || ($eingabe !== null && empty($eingabe['id']));
$v = fn(string $k) => $eingabe !== null ? (string)($eingabe[$k] ?? '') : (string)($bearbeiten[$k] ?? '');
$fehlerFeld = fn(string $k) => isset($fehler[$k]) ? '<p class="feldfehler" id="f-' . $k . '">' . e($fehler[$k]) . '</p>' : '';
$ungueltig = fn(string $k) => isset($fehler[$k]) ? ' aria-invalid="true" aria-describedby="f-' . $k . '"' : '';

team_seite_kopf($konto, 'Dozierende', '/team/dozierende');
?>
<p><a href="/team/programm">← zurück zum Programm</a></p>
<div class="filterzeile"><a class="knopf" href="/team/dozierende?neu=1">+ Person</a></div>
<div class="pg-raster">
    <div class="kasten">
        <?php if ($liste === []): ?><p class="hinweis-leer">Noch niemand eingetragen.</p><?php else: ?>
        <ul class="doz-liste">
        <?php foreach ($liste as $d): ?>
            <li<?= (int)($bearbeiten['id'] ?? 0) === (int)$d['id'] ? ' class="aktiv"' : '' ?>><a href="/team/dozierende?id=<?= (int)$d['id'] ?>" class="doz">
                <?php if ($d['foto']): ?><img class="foto" src="/bild/<?= e($d['foto']) ?>" alt="" width="56" height="56"><?php else: ?><span class="foto" aria-hidden="true"><?= e(mb_substr($d['name'], 0, 1)) ?></span><?php endif; ?>
                <span><strong><?= e($d['name']) ?></strong><br><span class="meta klein"><?= e($d['funktion'] !== '' ? $d['funktion'] : '–') ?> · <?= (int)$d['punkte'] ?> <?= (int)$d['punkte'] === 1 ? 'Programmpunkt' : 'Programmpunkte' ?></span></span>
            </a></li>
        <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <div class="kasten pg-form">
    <?php if ($bearbeiten === null && !$neu): ?>
        <p class="hinweis-leer">Wähle links eine Person oder lege mit „+ Person“ eine neue an.</p>
    <?php else: ?>
        <h2 class="zwischen"><?= $bearbeiten !== null ? e($bearbeiten['name']) . ' bearbeiten' : 'Neue Person' ?></h2>
        <?php if ($fehler !== []): ?><div class="meldung fehler" role="alert">Nicht gespeichert. Bitte die markierten Felder prüfen.</div><?php endif; ?>
        <form method="post" action="/team/dozierende" class="pg-formular" enctype="multipart/form-data" novalidate>
            <?= csrf_feld() ?>
            <input type="hidden" name="aktion" value="speichern">
            <?php if ($bearbeiten !== null): ?><input type="hidden" name="id" value="<?= (int)$bearbeiten['id'] ?>"><?php endif; ?>
            <div class="feld"><label for="d-name">Name</label><input id="d-name" name="name" type="text" maxlength="120" required value="<?= e($v('name')) ?>"<?= $ungueltig('name') ?>><?= $fehlerFeld('name') ?></div>
            <div class="feld"><label for="d-funktion">Funktion</label><input id="d-funktion" name="funktion" type="text" maxlength="160" value="<?= e($v('funktion')) ?>"<?= $ungueltig('funktion') ?>><p class="hilfe">z. B. „Oberärztin, Zentrale Notaufnahme“</p><?= $fehlerFeld('funktion') ?></div>
            <div class="feld"><label for="d-kurzbio">Kurzbio</label><textarea id="d-kurzbio" name="kurzbio" rows="4" data-editor="block" data-werkzeug="fett kursiv | liste nliste link"<?= $ungueltig('kurzbio') ?>><?= e($v('kurzbio')) ?></textarea><?= $fehlerFeld('kurzbio') ?></div>
            <?php if (foto_moeglich()): ?>
            <div class="feld"><label for="d-foto">Foto (optional)</label>
                <?php if (!empty($bearbeiten['foto'])): ?><img class="foto gross" src="/bild/<?= e($bearbeiten['foto']) ?>" alt="Aktuelles Foto" width="120" height="120">
                <label class="mini-haken"><input type="checkbox" name="foto_weg" value="1"> Foto entfernen</label><?php endif; ?>
                <input id="d-foto" name="foto" type="file" accept="image/jpeg,image/png,image/webp">
                <p class="hilfe">JPG, PNG oder WebP. Wird quadratisch zugeschnitten und verkleinert; Metadaten (z. B. Ort) werden entfernt. Nur mit Einverständnis der Person.</p>
            </div>
            <?php endif; ?>
            <div class="aktionen"><button type="submit" class="knopf">Speichern</button><a class="knopf zweit" href="/team/dozierende">Abbrechen</a></div>
        </form>
        <?php if ($bearbeiten !== null): ?>
        <details class="bestaetigen gefahrzone"><summary class="knopf gefahr">Person löschen</summary>
            <form method="post" action="/team/dozierende"><?= csrf_feld() ?><input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="id" value="<?= (int)$bearbeiten['id'] ?>">
                <p><?= e($bearbeiten['name']) ?> wird gelöscht und aus <?= (int)$bearbeiten['punkte'] ?> Programmpunkten entfernt.</p>
                <button type="submit" class="knopf gefahr">Ja, löschen</button>
            </form>
        </details>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</div>
<?php
team_seite_fuss();
