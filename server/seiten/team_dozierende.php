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

if (ist_post()) {
    $aktion = aktion();
    $id = post_zahl('id') ?: null;
    if ($id !== null && !dozent_da($id)) {
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
                    fertig('fehler', 'Gespeichert, aber ohne Foto: ' . $ex->getMessage(), '/team/dozierende?id=' . $id);
                }
            } elseif (post_haken('foto_weg')) {
                foto_entfernen($id);
            }
            fertig('ok', 'Gespeichert. Auf der Startseite nach „Jetzt veröffentlichen“ im Reiter Programm.', '/team/dozierende?id=' . $id);
        }
        $eingabe = $_POST;
    } elseif ($aktion === 'loeschen' && $id !== null) {
        dozent_loeschen($id, $ich);
        fertig('ok', 'Gelöscht. Die Zuordnung zu Programmpunkten ist entfernt (noch nicht veröffentlicht).', '/team/dozierende');
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
$w = fn(string $k) => wert($eingabe ?? [], $bearbeiten ?? [], $k);

team_seite_kopf($konto, 'Dozierende', '/team/dozierende');
?>
<p><a href="/team/programm">← zurück zum Programm</a></p>
<div class="filterzeile"><a class="knopf" href="/team/dozierende?neu=1">+ Person</a></div>
<div class="pg-raster">
    <div class="kasten">
        <?php if ($liste === []): ?><?= leer_hinweis('Noch niemand eingetragen.') ?><?php else: ?>
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
        <?= leer_hinweis('Wähle links eine Person oder lege mit „+ Person“ eine neue an.') ?>
    <?php else: ?>
        <h2 class="zwischen"><?= $bearbeiten !== null ? e($bearbeiten['name']) . ' bearbeiten' : 'Neue Person' ?></h2>
        <?= $fehler !== [] ? meldung_html(NICHT_GESPEICHERT_TEXT, 'fehler') : '' ?>
        <form method="post" action="/team/dozierende" class="pg-formular" enctype="multipart/form-data" novalidate>
            <?= csrf_feld() . versteckt_html(['aktion' => 'speichern', 'id' => $bearbeiten !== null ? (int)$bearbeiten['id'] : null]) ?>
            <?= feld('name', 'Name', ['id' => 'd-name', 'wert' => $w('name'), 'fehler' => $fehler['name'] ?? null, 'pflicht' => true, 'attr' => ['maxlength' => 120]]) ?>
            <?= feld('funktion', 'Funktion', ['id' => 'd-funktion', 'wert' => $w('funktion'), 'fehler' => $fehler['funktion'] ?? null, 'hilfe' => 'z. B. „Oberärztin, Zentrale Notaufnahme“', 'attr' => ['maxlength' => 160]]) ?>
            <?php // Editor-Attribute wie editor_attribute() (liefert nur einen String, feld() braucht ein Array) ?>
            <?= feld('kurzbio', 'Kurzbio', ['typ' => 'textarea', 'id' => 'd-kurzbio', 'wert' => $w('kurzbio'), 'fehler' => $fehler['kurzbio'] ?? null,
                'attr' => ['rows' => 4, 'data-editor' => 'block', 'data-werkzeug' => EDITOR_LEISTE['block'], 'data-bild-url' => '/team/texte/bild']]) ?>
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
        <?= bestaetigung_html('Person löschen', $bearbeiten['name'] . ' wird gelöscht und aus ' . (int)$bearbeiten['punkte'] . ' Programmpunkten entfernt.', 'Ja, löschen',
            ['aktion' => 'loeschen', 'id' => (int)$bearbeiten['id']], ['ziel' => '/team/dozierende', 'klasse' => 'gefahrzone', 'knopfklasse' => 'knopf gefahr']) ?>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</div>
<?php
team_seite_fuss();
