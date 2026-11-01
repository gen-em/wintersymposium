/*
 * anmelden.js – Anmeldung und Workshop-Änderung (AP-4):
 * - EFN-Feld nur bei der ersten Berufsgruppe (ÄrztIn, E-22), Freitext nur bei „Sonstige“
 * - Workshop-Wahl: Termine, die sich mit einem gewählten festen Termin eines
 *   anderen Workshops überschneiden, werden gesperrt, mit Grund (E-20)
 * - Wartelisten-Schalter zeigt „✓ Auf der Warteliste“ / „+ Warteliste“
 * - Newsletter „Allgäu Akut“ (AP-19, O-50): Interessen Klinik/Präklinik nach
 *   der Berufsgruppe vorausgewählt, bis man sie selbst ändert
 * Der Server prüft alles noch einmal; ohne Skript bleiben alle Felder sichtbar.
 */
(function () {
    "use strict";
    var beruf = document.getElementById("berufsgruppe");
    var efn = document.getElementById("efn-feld");
    var sonst = document.getElementById("sonst-feld");
    function berufZeigen() {
        if (!beruf) { return; }
        if (efn) { efn.hidden = beruf.value !== beruf.getAttribute("data-efn-gruppe"); }
        if (sonst) { sonst.hidden = beruf.value.toLowerCase() !== "sonstige"; }
    }
    if (beruf) { beruf.addEventListener("change", berufZeigen); berufZeigen(); }

    var interessen = document.querySelector(".nl-interessen[data-vorauswahl]");
    var selbstGewaehlt = false;
    function vorauswahl() {
        if (!interessen || !beruf || selbstGewaehlt) { return; }
        var wahl = {};
        try { wahl = JSON.parse(interessen.getAttribute("data-vorauswahl")) || {}; } catch (e) { wahl = {}; }
        var w = wahl[beruf.value] || "beides";
        interessen.querySelectorAll("input[type=checkbox]").forEach(function (c) {
            c.checked = w === "beides" || c.value === w;
        });
    }
    if (interessen) {
        interessen.addEventListener("change", function () { selbstGewaehlt = true; });
        if (beruf) { beruf.addEventListener("change", vorauswahl); }
    }

    var radios = Array.prototype.slice.call(document.querySelectorAll("input[type=radio][name^=\"ws[\"][data-von]"));
    function sperren() {
        var gewaehlt = radios.filter(function (r) { return r.checked; });
        radios.forEach(function (r) {
            var konflikt = null;
            gewaehlt.forEach(function (g) {
                if (g.name !== r.name && Number(g.getAttribute("data-von")) < Number(r.getAttribute("data-bis")) && Number(r.getAttribute("data-von")) < Number(g.getAttribute("data-bis"))) {
                    konflikt = g;
                }
            });
            var gesperrt = konflikt !== null && !r.checked;
            r.disabled = gesperrt;
            var label = r.closest("label");
            if (label) { label.classList.toggle("gesperrt", gesperrt); }
            var grund = label ? label.querySelector("[data-grund]") : null;
            if (grund) { grund.textContent = gesperrt ? "Überschneidet sich mit " + konflikt.getAttribute("data-titel") : ""; }
        });
    }
    document.addEventListener("change", function (ev) {
        var el = ev.target;
        if (el.matches && el.matches("input[type=radio][name^=\"ws[\"]")) { sperren(); }
        if (el.matches && el.matches("input[name=\"wl[]\"]")) {
            var label = el.closest("label");
            var schalter = label ? label.querySelector(".wl-toggle") : null;
            if (label) { label.classList.toggle("an", el.checked); }
            if (schalter) { schalter.textContent = schalter.getAttribute(el.checked ? "data-an" : "data-aus"); }
        }
    });
    sperren();
})();
