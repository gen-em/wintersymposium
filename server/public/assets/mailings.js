/*
 * Mailings (AP-6, E-41): Empfängerart und -zahl, Vorschau-Breite. Den
 * Editor stellt assets/editor.js (derselbe für alle Textfelder, E-54); was
 * gespeichert wird, bereinigt der Server (nur einfache Elemente).
 */
(function () {
    "use strict";
    var form = document.querySelector("form.rm-form");
    if (!form) {
        vorschauBreite();
        return;
    }
    // Der Editor selbst ist assets/editor.js (ein Editor überall, E-54).
    // Ohne Skript gibt es den Knopf „Bild ans Ende setzen“; mit Skript reicht die Werkzeugleiste
    form.querySelector(".rm-bild-feld").hidden = true;

    // Empfängerzahl bei geänderter Auswahl nachladen
    var zahl = document.getElementById("rm-zahl");
    var zahl2 = document.getElementById("rm-zahl2");
    var senden = document.getElementById("rm-senden");
    // Empfängerart (AP-7): Teilnehmende oder Newsletter-Listen; nur die passenden Felder zeigen
    function art() { var r = form.querySelector("input[name=art]:checked"); return r ? r.value : "teilnehmende"; }
    function artZeigen() {
        Array.prototype.forEach.call(form.querySelectorAll("[data-empfaenger]"), function (el) { el.hidden = el.getAttribute("data-empfaenger") !== art(); });
    }
    function auswahlWert() {
        if (art() !== "newsletter") { return form.auswahl.value; }
        var ids = Array.prototype.map.call(form.querySelectorAll("input[name='listen[]']:checked"), function (c) { return c.value; });
        return "nl:" + ids.join(",");
    }
    Array.prototype.forEach.call(form.querySelectorAll("input[name=art], input[name='listen[]']"), function (el) {
        el.addEventListener("change", function () { artZeigen(); zaehlen(); });
    });
    artZeigen();
    function zaehlen() {
        if (auswahlWert() === "nl:") { zahl.textContent = "0"; zahl2.textContent = "0"; senden.textContent = "An 0 senden"; return; }
        var q = "auswahl=" + encodeURIComponent(auswahlWert()) + "&bg=" + encodeURIComponent(art() === "newsletter" ? "" : form.berufsgruppe.value);
        fetch("/team/mailings/empfaenger?" + q, { credentials: "same-origin" })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) { return; }
                zahl.textContent = d.zahl;
                zahl2.textContent = d.zahl;
                senden.textContent = "An " + d.zahl + " senden";
                var liste = document.getElementById("rm-liste");
                if (liste) {
                    liste.textContent = "";
                    d.namen.forEach(function (n) { var li = document.createElement("li"); li.textContent = n; liste.appendChild(li); });
                }
            })
            .catch(function () { /* Zahl bleibt beim gespeicherten Stand */ });
    }
    form.auswahl.addEventListener("change", zaehlen);
    form.berufsgruppe.addEventListener("change", zaehlen);

    vorschauBreite();

    function vorschauBreite() {
        var rahmen = document.getElementById("rm-vorschau");
        if (!rahmen) { return; }
        Array.prototype.forEach.call(document.querySelectorAll("[data-breite]"), function (b) {
            if (b.tagName !== "BUTTON") { return; }
            b.addEventListener("click", function () {
                Array.prototype.forEach.call(document.querySelectorAll("button[data-breite]"), function (x) { x.setAttribute("aria-pressed", x === b ? "true" : "false"); x.classList.toggle("zweit", x !== b); });
                rahmen.className = "ml-vorschau-rahmen " + b.getAttribute("data-breite");
            });
        });
    }
}());
