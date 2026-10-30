/*
 * Ein Editor für alle Textfelder (Rückmeldung 2026-10-30 (2), E-54: „Nur ein
 * Editor überall“ – der Editor der Mailings). Macht aus jedem
 * <textarea data-editor="block|inline"> ein Schreibfeld mit Werkzeugleiste;
 * das Textfeld bleibt (versteckt) die Quelle fürs Absenden. Ohne Skript bleibt
 * das Textfeld; der Server bereinigt in jedem Fall.
 *
 * Attribute am Textfeld:
 *   data-editor="block"   Absätze, Überschrift, Listen, Links (Standard)
 *   data-editor="inline"  eine Zeile: fett, kursiv, Link (Titel, Untertitel …)
 *   data-werkzeug="…"     Knöpfe, durch Leerzeichen getrennt, aus:
 *                         fett kursiv titel liste nliste link bild knopf html
 *   data-bild-url="…"     Hochladen für „Bild“ (Antwort JSON {url, breite, hoehe})
 *   data-platzhalter='{"{vorname}": "Vorname", …}'  Knöpfe für Platzhalter
 *   data-knopf-ziel="{link}"  Vorgabe-Ziel für „Knopf“
 * Die Knöpfe zeigen an, was an der Schreibmarke gilt (fett an/aus usw.), und
 * schalten es um.
 */
(function () {
    "use strict";
    var KNOPF = {
        fett: ['<b>B</b>', "Fett (Strg+B)"],
        kursiv: ['<i>I</i>', "Kursiv (Strg+I)"],
        titel: ["H", "Zwischenüberschrift"],
        liste: ["• Liste", "Aufzählung"],
        nliste: ["1. Liste", "Nummerierte Liste"],
        link: ["Link", "Link einfügen"],
        bild: ["Bild", "Bild hochladen und einfügen"],
        knopf: ["Knopf", "Knopf (Link als Schaltfläche) einfügen"],
        html: ["&lt;/&gt; HTML", "HTML-Quelltext bearbeiten"]
    };
    var STANDARD = { block: "fett kursiv titel liste nliste link", inline: "fett kursiv link" };
    var nr = 0;

    function esc(t) {
        return String(t).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; });
    }

    function bauen(ta) {
        if (ta.getAttribute("data-editor-fertig")) { return; }
        ta.setAttribute("data-editor-fertig", "1");
        nr += 1;
        var art = ta.getAttribute("data-editor") === "inline" ? "inline" : "block";
        var werkzeug = (ta.getAttribute("data-werkzeug") || STANDARD[art]).split(/\s+/);
        var bildUrl = ta.getAttribute("data-bild-url");
        var platzhalter = {};
        try { platzhalter = JSON.parse(ta.getAttribute("data-platzhalter") || "{}"); } catch (e) { platzhalter = {}; }
        var form = ta.form;
        var csrfFeld = form ? form.querySelector("input[name=csrf]") : null;
        var label = ta.id ? document.querySelector("label[for='" + ta.id + "']") : null;

        // Werkzeugleiste
        var leiste = document.createElement("div");
        leiste.className = "werkzeug";
        leiste.setAttribute("role", "toolbar");
        leiste.setAttribute("aria-label", "Formatierung" + (label ? ": " + label.textContent.trim() : ""));
        var html = "";
        werkzeug.forEach(function (w) {
            if (w === "|") { html += '<span class="trenn"></span>'; return; }
            if (!KNOPF[w] || (w === "bild" && !bildUrl)) { return; }
            var toggle = ["fett", "kursiv", "titel", "liste", "nliste", "html"].indexOf(w) !== -1;
            html += '<button type="button" data-befehl="' + w + '" title="' + KNOPF[w][1] + '"' + (toggle ? ' aria-pressed="false"' : "") + ">" + KNOPF[w][0] + "</button>";
        });
        var phs = Object.keys(platzhalter);
        if (phs.length) {
            html += '<span class="trenn"></span>';
            phs.forEach(function (p) { html += '<button type="button" data-platzhalter="' + esc(p) + '" title="Platzhalter: ' + esc(platzhalter[p]) + '">' + esc(p) + "</button>"; });
        }
        leiste.innerHTML = html;

        // Schreibfeld
        var ed = document.createElement("div");
        ed.className = "schreibfeld" + (art === "inline" ? " schreibfeld-zeile" : "");
        ed.contentEditable = "true";
        ed.setAttribute("role", "textbox");
        ed.setAttribute("aria-multiline", art === "inline" ? "false" : "true");
        ed.id = (ta.id || "editor-" + nr) + "-feld";
        if (label) {
            if (!label.id) { label.id = ed.id + "-label"; }
            ed.setAttribute("aria-labelledby", label.id);
            label.setAttribute("for", ed.id);
        }
        // Höhe nach den Zeilen des Textfelds (kurze Felder wie Beschreibung, Kurzbio)
        if (art === "block" && ta.rows && ta.rows < 10) { ed.style.minHeight = (ta.rows * 1.7 + 1.8) + "em"; }
        var start = ta.value.trim();
        if (start !== "" && start.indexOf("<") === -1) {
            // Klartext: Absätze übernehmen
            start = art === "inline" ? esc(start) : start.split(/\n\s*\n/).map(function (a) { return "<p>" + esc(a).replace(/\n/g, "<br>") + "</p>"; }).join("");
        }
        ed.innerHTML = start !== "" ? start : (art === "inline" ? "" : "<p><br></p>");

        var status = document.createElement("p");
        status.className = "meta klein editor-status";
        status.setAttribute("role", "status");
        status.setAttribute("aria-live", "polite");

        // Kleiner Dialog für Link und Knopf (statt prompt)
        var dlg = document.createElement("div");
        dlg.className = "rm-dialog";
        dlg.hidden = true;
        dlg.innerHTML = '<label for="ed-' + nr + '-text" data-text-label>Linktext</label><input id="ed-' + nr + '-text" type="text">'
            + '<label for="ed-' + nr + '-ziel">Adresse (https://…' + (ta.getAttribute("data-knopf-ziel") ? " oder " + esc(ta.getAttribute("data-knopf-ziel")) : "") + ')</label><input id="ed-' + nr + '-ziel" type="text">'
            + '<div class="aktionen"><button type="button" class="knopf klein-knopf" data-ok>Einfügen</button><button type="button" class="knopf zweit klein-knopf" data-weg>Abbrechen</button></div>';

        var datei = null;
        if (bildUrl) {
            datei = document.createElement("input");
            datei.type = "file";
            datei.accept = "image/jpeg,image/png";
            datei.hidden = true;
            datei.setAttribute("aria-label", "Bild hochladen");
        }

        ta.parentNode.insertBefore(leiste, ta);
        ta.parentNode.insertBefore(ed, ta);
        ta.parentNode.insertBefore(dlg, ta.nextSibling);
        dlg.parentNode.insertBefore(status, dlg.nextSibling);
        if (datei) { status.parentNode.insertBefore(datei, status.nextSibling); }
        ta.hidden = true;

        try { document.execCommand("defaultParagraphSeparator", false, "p"); } catch (e) { /* ältere Browser */ }

        var htmlModus = false;
        var gemerkt = null;
        function sync() { if (!htmlModus) { ta.value = ed.innerHTML; } }
        ed.addEventListener("input", function () { sync(); zustand(); });
        if (form) {
            form.addEventListener("submit", function () {
                if (htmlModus) { ed.innerHTML = ta.value; }
                ta.value = ed.innerHTML;
            });
        }
        function melden(t) { status.textContent = t; }
        function auswahlMerken() {
            var s = window.getSelection();
            if (s && s.rangeCount > 0 && ed.contains(s.anchorNode)) { gemerkt = s.getRangeAt(0).cloneRange(); }
        }
        function auswahlZurueck() {
            ed.focus();
            if (gemerkt) {
                var s = window.getSelection();
                s.removeAllRanges();
                s.addRange(gemerkt);
            }
        }
        function einfuegen(h) {
            auswahlZurueck();
            document.execCommand("insertHTML", false, h);
            sync();
        }

        // Anzeige, was an der Schreibmarke gilt
        function zustand() {
            var s = window.getSelection();
            if (!s || s.rangeCount === 0 || !ed.contains(s.anchorNode)) { return; }
            var setz = function (befehl, an) {
                var b = leiste.querySelector("[data-befehl=" + befehl + "]");
                if (b) { b.setAttribute("aria-pressed", an ? "true" : "false"); }
            };
            setz("fett", document.queryCommandState("bold"));
            setz("kursiv", document.queryCommandState("italic"));
            setz("liste", document.queryCommandState("insertUnorderedList"));
            setz("nliste", document.queryCommandState("insertOrderedList"));
            setz("titel", String(document.queryCommandValue("formatBlock")).toLowerCase().replace(/[<>]/g, "") === "h2");
        }
        document.addEventListener("selectionchange", function () {
            var s = window.getSelection();
            if (s && s.rangeCount > 0 && ed.contains(s.anchorNode)) { auswahlMerken(); zustand(); }
        });
        ed.addEventListener("keyup", auswahlMerken);
        ed.addEventListener("mouseup", auswahlMerken);
        ed.addEventListener("blur", auswahlMerken);

        if (art === "inline") {
            // Eine Zeile: Enter nicht als Absatz, Einfügen nur als Text
            ed.addEventListener("keydown", function (ev) { if (ev.key === "Enter") { ev.preventDefault(); } });
            ed.addEventListener("paste", function (ev) {
                var t = (ev.clipboardData || window.clipboardData).getData("text");
                ev.preventDefault();
                document.execCommand("insertText", false, t.replace(/\s+/g, " "));
            });
        }

        var dialogArt = null;
        function dialogAuf(w) {
            auswahlMerken();
            dialogArt = w;
            var s = window.getSelection();
            var markiert = s && s.rangeCount > 0 && ed.contains(s.anchorNode) ? s.toString() : "";
            dlg.querySelector("[data-text-label]").textContent = w === "knopf" ? "Text auf dem Knopf" : "Linktext";
            dlg.querySelector("input[type=text]").value = markiert || (w === "knopf" ? "Deine Anmeldung ansehen" : "");
            dlg.querySelectorAll("input[type=text]")[1].value = w === "knopf" && ta.getAttribute("data-knopf-ziel") ? ta.getAttribute("data-knopf-ziel") : "https://";
            dlg.hidden = false;
            dlg.querySelector("input[type=text]").focus();
        }
        dlg.querySelector("[data-weg]").addEventListener("click", function () { dlg.hidden = true; auswahlZurueck(); });
        dlg.querySelector("[data-ok]").addEventListener("click", function () {
            var felder = dlg.querySelectorAll("input[type=text]");
            var text = felder[0].value.trim();
            var ziel = felder[1].value.trim();
            var erlaubt = ta.getAttribute("data-knopf-ziel");
            if (!/^(https:\/\/\S+|mailto:\S+@\S+)$/i.test(ziel) && !(erlaubt && ziel === erlaubt)) {
                melden("Bitte eine Adresse mit https:// (oder mailto:) eintragen.");
                return;
            }
            dlg.hidden = true;
            text = text || ziel;
            if (dialogArt === "knopf") {
                einfuegen('<p><a class="knopf" href="' + esc(ziel) + '">' + esc(text) + "</a></p><p><br></p>");
            } else {
                einfuegen('<a href="' + esc(ziel) + '">' + esc(text) + "</a>");
            }
            melden("");
        });

        if (datei) {
            datei.addEventListener("change", function () {
                if (!datei.files || datei.files.length === 0) { return; }
                var fd = new FormData();
                fd.append("bild", datei.files[0]);
                melden("Bild wird hochgeladen …");
                fetch(bildUrl, { method: "POST", body: fd, headers: { "X-CSRF": csrfFeld ? csrfFeld.value : "" }, credentials: "same-origin" })
                    .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                    .then(function (x) {
                        if (!x.ok) { melden(x.d.fehler || "Das Bild ließ sich nicht hochladen."); return; }
                        einfuegen('<p><img src="' + esc(x.d.url) + '" alt="" data-breite="100"></p>');
                        melden("Bild eingefügt. Klick aufs Bild: halbe oder volle Breite.");
                    })
                    .catch(function () { melden("Das Bild ließ sich nicht hochladen. Bitte die Seite neu laden."); })
                    .then(function () { datei.value = ""; });
            });
        }
        ed.addEventListener("click", function (ev) {
            var img = ev.target.closest ? ev.target.closest("img") : null;
            if (img && ed.contains(img)) {
                img.setAttribute("data-breite", img.getAttribute("data-breite") === "50" ? "100" : "50");
                sync();
                melden("Bildbreite: " + img.getAttribute("data-breite") + " %");
            }
        });

        leiste.addEventListener("mousedown", function (ev) {
            if (ev.target.closest("button")) { ev.preventDefault(); }
        });
        leiste.addEventListener("click", function (ev) {
            var b = ev.target.closest("button");
            if (!b) { return; }
            var ph = b.getAttribute("data-platzhalter");
            if (ph) {
                if (htmlModus) { return; }
                auswahlZurueck();
                document.execCommand("insertText", false, ph);
                sync();
                return;
            }
            var befehl = b.getAttribute("data-befehl");
            if (befehl === "html") {
                htmlModus = !htmlModus;
                if (htmlModus) { ta.value = ed.innerHTML; } else { ed.innerHTML = ta.value; }
                ta.hidden = !htmlModus;
                ed.hidden = htmlModus;
                b.setAttribute("aria-pressed", htmlModus ? "true" : "false");
                Array.prototype.forEach.call(leiste.querySelectorAll("button"), function (x) { if (x !== b) { x.disabled = htmlModus; } });
                (htmlModus ? ta : ed).focus();
                return;
            }
            if (htmlModus) { return; }
            auswahlZurueck();
            if (befehl === "fett") {
                document.execCommand("bold", false, null);
            } else if (befehl === "kursiv") {
                document.execCommand("italic", false, null);
            } else if (befehl === "titel") {
                var block = String(document.queryCommandValue("formatBlock")).toLowerCase().replace(/[<>]/g, "");
                document.execCommand("formatBlock", false, block === "h2" ? "<p>" : "<h2>");
            } else if (befehl === "liste") {
                document.execCommand("insertUnorderedList", false, null);
            } else if (befehl === "nliste") {
                document.execCommand("insertOrderedList", false, null);
            } else if (befehl === "link" || befehl === "knopf") {
                dialogAuf(befehl);
                return;
            } else if (befehl === "bild") {
                datei.click();
                return;
            }
            sync();
            zustand();
        });
    }

    function alle() {
        Array.prototype.forEach.call(document.querySelectorAll("textarea[data-editor]"), bauen);
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", alle);
    } else {
        alle();
    }
    window.wsEditor = alle;
}());
