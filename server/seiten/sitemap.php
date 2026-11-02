<?php
declare(strict_types=1);

/*
 * /sitemap.xml (AP-21, E-78): nur bei erlaubtem Suchmaschinen-Schalter auf
 * Produktion, sonst 404. Öffentliche Seiten und die Startseiten der
 * öffentlichen Veranstaltungen (keine Entwürfe).
 */

$xml = sitemap_xml();
if ($xml === null) {
    nicht_gefunden();
}
kopf_gemeinsam();
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo $xml;
