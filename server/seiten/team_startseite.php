<?php
declare(strict_types=1);

/*
 * Die Texte der Startseite stehen seit 2026-10-31 bei den Einstellungen der
 * Veranstaltung (Admin: alles einer Veranstaltung auf einer Seite). Alte
 * Lesezeichen führen dorthin.
 */
team_tor();
umleiten('/team/einstellungen?bearbeiten=1#startseite');
