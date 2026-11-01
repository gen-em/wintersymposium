/*
 * Programm auf der Startseite (AP-3 Schritt 3, E-40):
 * - Popups: Knöpfe mit data-dialog öffnen das <dialog> mit dieser id;
 *   ✕, Klick auf den Hintergrund und Escape schließen es.
 * - Zeitraster der Workshop-Blöcke: setzt Lage und Breite der Balken aus
 *   data-von/data-bis (Minuten). Die Sicherheitsregeln (CSP) verbieten
 *   style-Attribute im HTML; über das DOM gesetzte Stile sind erlaubt.
 *   Ohne Skript bleibt die Liste sichtbar.
 */
(function () {
    "use strict";

    // Popups über die gemeinsame Öffnen-Logik (basis.js); ✕ schließt, Escape und Klick daneben auch
    document.addEventListener("click", function (ev) {
        var a = ev.target.closest("[data-dialog]");
        if (a) {
            if (ws.oeffnen(document.getElementById(a.getAttribute("data-dialog")))) { ev.preventDefault(); }
            return;
        }
        var zu = ev.target.closest("[data-schliessen]");
        if (zu) { zu.closest("dialog").close(); }
    });

    document.querySelectorAll(".gantt-wrap").forEach(function (wrap) {
        var von = Number(wrap.getAttribute("data-von")), bis = Number(wrap.getAttribute("data-bis")), spanne = bis - von;
        if (!(spanne > 0)) { return; }
        wrap.querySelectorAll(".gb").forEach(function (b) {
            var a = Number(b.getAttribute("data-von")), e = Number(b.getAttribute("data-bis"));
            b.style.left = ((a - von) / spanne * 100) + "%";
            b.style.width = ((e - a) / spanne * 100) + "%";
        });
        var skala = wrap.querySelector(".skala");
        var schritt = spanne > 180 ? 30 : 15;
        for (var m = Math.ceil(von / schritt) * schritt; m <= bis; m += schritt) {
            var s = document.createElement("span");
            s.style.left = ((m - von) / spanne * 100) + "%";
            s.textContent = (m % 30 === 0) ? ws.hm(m) : "";
            // Rand-Beschriftungen bündig, damit nichts über den Rahmen ragt
            if (m === bis) { s.style.transform = "translateX(-100%)"; } else if (m === von) { s.style.transform = "none"; }
            skala.appendChild(s);
        }
        wrap.hidden = false;
        wrap.closest(".wsblock").classList.add("mit-raster");
    });
})();
