/*
 * Betrieb → Status (AP-15, E-65): Knöpfe „Kopieren“ für Cron-Befehl und URL.
 * Ein Knopf mit data-kopieren="<id>" kopiert den Wert des Feldes mit dieser
 * id und meldet „Kopiert“ im Element mit data-kopiert="<id>". Ohne
 * Zwischenablage (alte Browser, kein HTTPS) wird das Feld markiert, damit es
 * sich von Hand kopieren lässt.
 */
(function () {
    "use strict";
    function melden(id, text) {
        var m = document.querySelector('[data-kopiert="' + id + '"]');
        if (m) { m.textContent = text; }
    }
    function markieren(feld, id) {
        feld.focus();
        feld.select();
        melden(id, "Markiert – mit Strg+C bzw. ⌘+C kopieren");
    }
    document.querySelectorAll("button[data-kopieren]").forEach(function (knopf) {
        knopf.addEventListener("click", function () {
            var id = knopf.getAttribute("data-kopieren");
            var feld = document.getElementById(id);
            if (!feld) { return; }
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(feld.value).then(function () {
                    melden(id, "Kopiert");
                }, function () {
                    markieren(feld, id);
                });
            } else {
                markieren(feld, id);
            }
        });
    });
})();
