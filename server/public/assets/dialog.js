/*
 * Einheitlicher Bestätigungsdialog (Rückmeldung 2026-10-30, Mockup-Entwurf C).
 *
 * Ohne JavaScript bleibt jede Nachfrage ein aufklappbares <details class="bestaetigen">
 * mit Formular darin. Mit JavaScript wird daraus ein Fenster über der Seite:
 *   data-art="gruen|rot|lila" – Farbe (Standard: rot, „knopf gefahr“ ist immer rot)
 *   data-titel="…"           – Überschrift (Standard: Text des Auslösers mit „?“)
 *   data-ausloeser="id"      – ein anderer Knopf öffnet den Dialog (der eigene bleibt verborgen)
 * Die Knöpfe bleiben in ihrem Formular (Attribut form), Absenden funktioniert wie vorher.
 * Steht details schon offen (z. B. nach einem Fehler), öffnet sich der Dialog sofort.
 */
(function () {
    "use strict";
    if (typeof HTMLDialogElement !== "function") {
        return;
    }
    var nr = 0;

    function umbauen(det) {
        var summary = det.querySelector(":scope > summary");
        if (!summary) {
            return;
        }
        nr += 1;
        var art = det.getAttribute("data-art") || "rot";
        var titel = det.getAttribute("data-titel") || summary.textContent.trim().replace(/\s*…$/, "") + "?";

        var dlg = document.createElement("dialog");
        dlg.className = "dlg dlg-" + art;
        dlg.setAttribute("aria-labelledby", "dlg-t-" + nr);
        var karte = document.createElement("div");
        karte.className = "dlg-karte";
        karte.innerHTML = '<div class="dlg-griff" aria-hidden="true"></div><h2 class="dlg-titel" id="dlg-t-' + nr + '"></h2><div class="dlg-inhalt"></div><div class="dlg-knoepfe"></div>';
        karte.querySelector(".dlg-titel").textContent = titel;
        var inhalt = karte.querySelector(".dlg-inhalt");
        var knoepfe = karte.querySelector(".dlg-knoepfe");

        // Inhalt (alles außer summary) übernehmen; Absende-Knöpfe in die Knopfzeile, im eigenen Formular
        Array.prototype.slice.call(det.childNodes).forEach(function (n) {
            if (n !== summary) {
                inhalt.appendChild(n);
            }
        });
        Array.prototype.slice.call(inhalt.querySelectorAll("button[type=submit], button:not([type])")).forEach(function (b) {
            var f = b.form;
            if (f) {
                // getAttribute statt f.id: ein Feld name="id" im Formular überdeckt sonst die Eigenschaft
                if (!f.getAttribute("id")) {
                    f.setAttribute("id", "dlg-form-" + nr + "-" + Math.random().toString(36).slice(2, 7));
                }
                b.setAttribute("form", f.getAttribute("id"));
            }
            b.classList.remove("gefahr", "zweit", "klein-knopf");
            b.classList.add("knopf", "dlg-ja");
            knoepfe.appendChild(b);
        });
        var nein = document.createElement("button");
        nein.type = "button";
        nein.className = "knopf zweit dlg-nein";
        nein.textContent = art === "lila" ? "Schließen" : "Abbrechen";
        nein.addEventListener("click", function () { dlg.close(); });
        knoepfe.insertBefore(nein, knoepfe.firstChild);
        if (knoepfe.querySelectorAll(".dlg-ja").length === 0 && art === "lila") {
            nein.textContent = "Verstanden";
            nein.className = "knopf dlg-ja";
        }
        dlg.appendChild(karte);
        // Bleibt an derselben Stelle im Dokument (z. B. innerhalb des Formulars der Seite)
        det.parentNode.insertBefore(dlg, det.nextSibling);

        var ausloeser = det.getAttribute("data-ausloeser") ? document.getElementById(det.getAttribute("data-ausloeser")) : null;
        var knopf = ausloeser || document.createElement("button");
        if (!ausloeser) {
            knopf.type = "button";
            knopf.className = summary.className;
            knopf.innerHTML = summary.innerHTML;
            if (summary.id) {
                var sid = summary.id;
                summary.removeAttribute("id");
                knopf.id = sid;
            }
            det.parentNode.insertBefore(knopf, det);
        }
        knopf.hidden = false;
        knopf.setAttribute("aria-haspopup", "dialog");
        knopf.addEventListener("click", function () { dlg.showModal(); });
        var offen = det.hasAttribute("open");
        det.remove();

        dlg.addEventListener("click", function (ev) {
            if (ev.target === dlg) {
                dlg.close();
            }
        });
        dlg.addEventListener("close", function () { knopf.focus(); });
        if (offen) {
            dlg.showModal();
        }
        // Bei Löschen ist „Abbrechen“ vorausgewählt
        if (art === "rot") {
            nein.setAttribute("autofocus", "");
        }
    }

    function alle() {
        Array.prototype.slice.call(document.querySelectorAll("details.bestaetigen")).forEach(umbauen);
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", alle);
    } else {
        alle();
    }
    window.wsDialoge = alle;
}());
