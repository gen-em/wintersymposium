/*
 * pow.js – steuert die Rechenaufgabe des Spamschutzes (Muster aus NAdoku).
 * Formular mit data-pow: die Rechnung beginnt beim Laden und ist meist fertig,
 * bevor jemand auf „Anmelden“ drückt. Wer schneller ist, sieht kurz
 * „Sicherheitsprüfung läuft …“; das Formular geht dann von selbst ab.
 * Kann der Browser nicht rechnen, sagt die Zustandszeile es (nie still warten).
 */
(function () {
    "use strict";
    var form = document.querySelector("form[data-pow]");
    if (!form) { return; }
    var feld = form.querySelector("input[name=\"pow_loesung\"]");
    var aufgabe = form.querySelector("input[name=\"pow_aufgabe\"]");
    var knopf = form.querySelector("[data-pow-knopf]");
    var zustand = form.querySelector("[data-pow-zustand]");
    if (!feld || !aufgabe || !zustand) { return; }
    var fertig = false, wartet = false, kaputt = false;

    function sagen(text) { zustand.textContent = text; }
    function scheitern() {
        kaputt = true;
        if (knopf) { knopf.disabled = false; }
        zustand.classList.add("fehlertext");
        sagen("Die Sicherheitsprüfung lässt sich in diesem Browser nicht rechnen. Ein aktueller Browser hilft – oder schreib uns eine Mail.");
    }
    sagen("");
    var worker = null;
    try { worker = new Worker(form.getAttribute("data-pow-worker")); } catch (e) { worker = null; }
    if (!worker) { scheitern(); return; }
    worker.onmessage = function (ev) {
        var d = ev.data || {};
        if (d.fehler || typeof d.loesung !== "string") { scheitern(); return; }
        feld.value = d.loesung;
        fertig = true;
        worker.terminate();
        if (wartet) { sagen(""); form.submit(); }
    };
    worker.onerror = function () { scheitern(); };
    worker.postMessage({ aufgabe: aufgabe.value, bits: Number(form.getAttribute("data-pow-bits")) });
    form.addEventListener("submit", function (ev) {
        if (fertig) { return; }
        ev.preventDefault();
        if (kaputt) { return; }
        wartet = true;
        if (knopf) { knopf.disabled = true; }
        sagen("Sicherheitsprüfung läuft …");
    });
})();
