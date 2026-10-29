<?php
declare(strict_types=1);

/*
 * Audit-Protokoll: einfache Tabelle audit(zeit, konto_id, art, text)
 * (Muster aus NAdoku protokoll_lib.php). Keine personenbezogenen Inhalte
 * über das Nötige hinaus in den Text schreiben (R-02).
 */

function audit_schreiben(string $art, string $text, ?int $kontoId = null): void
{
    db_ausfuehren(
        'INSERT INTO audit (zeit, konto_id, art, text) VALUES (?, ?, ?, ?)',
        [db_jetzt(), $kontoId, mb_substr($art, 0, 64), $text]
    );
}

/** Die neuesten Einträge, neueste zuerst. */
function audit_liste(int $anzahl = 100): array
{
    $anzahl = max(1, min(1000, $anzahl));
    return db_zeilen("SELECT id, zeit, konto_id, art, text FROM audit ORDER BY id DESC LIMIT $anzahl");
}
