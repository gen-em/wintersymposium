<?php
declare(strict_types=1);

/*
 * Verschachtelungsfeste Transaktion (aus NAdoku transaktion_lib.php).
 *
 * Läuft schon eine Transaktion, wird die Funktion darin ausgeführt und
 * nichts selbst begonnen oder beendet. Nur wer die Transaktion begonnen hat,
 * schreibt fest oder rollt zurück. Wichtig für Platzvergabe und Nachrücken
 * (R-04): dort zusätzlich mit SELECT … FOR UPDATE sperren.
 */

function db_transaktion(callable $fn, ?PDO $pdo = null): mixed
{
    $pdo ??= db();
    $eigene = !$pdo->inTransaction();
    if ($eigene) {
        $pdo->beginTransaction();
    }
    try {
        $ergebnis = $fn($pdo);
        if ($eigene) {
            $pdo->commit();
        }
        return $ergebnis;
    } catch (Throwable $ex) {
        if ($eigene && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}
