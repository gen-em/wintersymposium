<?php
declare(strict_types=1);

/*
 * /robots.txt (AP-21, E-78): solange der Suchmaschinen-Schalter aus ist (oder
 * nicht Produktion), alles gesperrt; sonst die öffentlichen Seiten frei, Team,
 * Konto und Links gesperrt, mit Verweis auf die Sitemap.
 */

kopf_gemeinsam();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo robots_text();
