/**
 * QR-Code aus einer Adresse — für die Einrichtung des zweiten Faktors
 * (übernommen aus NAdoku assets/qr.js, E-18).
 *
 * WAS VON WEM KOMMT. Die Modulmatrix rechnet `qrcode-generator`
 * (assets/vendor/qrcode.js, MIT, lokal). Das SVG baut diese Datei selbst:
 * ein Rechteck `.qr-grund`, ein Pfad `.qr-modul`, Farben über die Token im
 * Stylesheet. Die Bibliothek könnte selbst SVG oder ein Bild liefern — mit
 * Farbwerten im Markup und einem `style`-Attribut, das die CSP verwirft.
 *
 * DER SERVER GIBT EIN LEERES `<svg class="qr" data-qr="…" hidden>` AUS.
 * Ohne JavaScript bleibt es verborgen; die beiden anderen Wege in die App
 * (der Verweis und das Geheimnis zum Abtippen) stehen daneben und brauchen
 * keins.
 *
 * FEHLERSTUFE M, VIER MODULE RUHEZONE. M verträgt rund 15 % Beschädigung —
 * genug für einen Bildschirm mit Spiegelung; H machte den Code bei gleicher
 * Breite gröber. Die Ruhezone gehört zum Code: Ohne sie lesen manche Apps
 * ihn am Rand der Karte nicht.
 */
(function () {
    "use strict";

    var RUHE = 4;

    function zeichnen(svg) {
        var text = svg.getAttribute("data-qr") || "";
        if (text === "" || typeof qrcode !== "function") { return; }
        var qr = qrcode(0, "M");
        qr.addData(text, "Byte");
        qr.make();
        var n = qr.getModuleCount();
        var seite = n + 2 * RUHE;
        var d = "";
        for (var r = 0; r < n; r++) {
            for (var c = 0; c < n; c++) {
                if (qr.isDark(r, c)) {
                    d += "M" + (c + RUHE) + " " + (r + RUHE) + "h1v1h-1z";
                }
            }
        }
        /* Der Namensraum des `<svg>`, das schon im Markup steht — nicht als
       Adresse im Text: Eine absolute Adresse im eigenen Quelltext ist fuer
       die Zusage „keine fremde Quelle" ein Treffer, auch wenn sie nie
       abgerufen wird. */
        var ns = svg.namespaceURI;
        var grund = document.createElementNS(ns, "rect");
        grund.setAttribute("class", "qr-grund");
        grund.setAttribute("width", String(seite));
        grund.setAttribute("height", String(seite));
        var modul = document.createElementNS(ns, "path");
        modul.setAttribute("class", "qr-modul");
        modul.setAttribute("d", d);
        while (svg.firstChild) { svg.removeChild(svg.firstChild); }
        svg.setAttribute("viewBox", "0 0 " + seite + " " + seite);
        svg.appendChild(grund);
        svg.appendChild(modul);
        svg.removeAttribute("hidden");
    }

    function alle() {
        var liste = document.querySelectorAll("svg.qr[data-qr]");
        for (var i = 0; i < liste.length; i++) { zeichnen(liste[i]); }
    }

    alle();
})();
