/*
 * Editor der Mailings (AP-6, E-41). Ohne JavaScript bleibt ein Textfeld mit
 * HTML; mit JavaScript ein Schreibfeld mit Werkzeugleiste. Was gespeichert
 * wird, bereinigt der Server (nur einfache Elemente).
 */
(function () {
    "use strict";
    var form = document.querySelector("form.rm-form");
    if (!form) {
        vorschauBreite();
        return;
    }
    var ta = document.getElementById("rm-html");
    var ed = document.getElementById("rm-editor");
    var leiste = document.getElementById("rm-leiste");
    var status = document.getElementById("rm-status");
    var dialog = document.getElementById("rm-dialog");
    var csrf = form.querySelector("input[name=csrf]").value;
    var htmlModus = false;
    var gemerkt = null;

    ed.innerHTML = ta.value.trim() !== "" ? ta.value : "<p><br></p>";
    ed.hidden = false;
    leiste.hidden = false;
    ta.hidden = true;
    document.getElementById("rm-html-label").setAttribute("for", "");
    try { document.execCommand("defaultParagraphSeparator", false, "p"); } catch (e) { /* ältere Browser */ }

    function sync() { if (!htmlModus) { ta.value = ed.innerHTML; } }
    ed.addEventListener("input", sync);
    form.addEventListener("submit", function () {
        if (htmlModus) { ed.innerHTML = ta.value; }
        ta.value = ed.innerHTML;
    });

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
    ed.addEventListener("keyup", auswahlMerken);
    ed.addEventListener("mouseup", auswahlMerken);
    ed.addEventListener("blur", auswahlMerken);

    function esc(t) {
        return String(t).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; });
    }
    function einfuegen(html) {
        auswahlZurueck();
        document.execCommand("insertHTML", false, html);
        sync();
    }
    function melden(t) { status.textContent = t; }

    // Kleiner Dialog für Link und Knopf (statt prompt)
    var dialogArt = null;
    function dialogAuf(art) {
        auswahlMerken();
        dialogArt = art;
        var s = window.getSelection();
        var markiert = s && s.rangeCount > 0 && ed.contains(s.anchorNode) ? s.toString() : "";
        document.getElementById("rm-dialog-text-label").textContent = art === "knopf" ? "Text auf dem Knopf" : "Linktext";
        document.getElementById("rm-dialog-text").value = markiert || (art === "knopf" ? "Deine Anmeldung ansehen" : "");
        document.getElementById("rm-dialog-ziel").value = art === "knopf" ? "{link}" : "https://";
        dialog.hidden = false;
        document.getElementById("rm-dialog-text").focus();
    }
    document.getElementById("rm-dialog-weg").addEventListener("click", function () { dialog.hidden = true; auswahlZurueck(); });
    document.getElementById("rm-dialog-ok").addEventListener("click", function () {
        var text = document.getElementById("rm-dialog-text").value.trim();
        var ziel = document.getElementById("rm-dialog-ziel").value.trim();
        if (!/^(https?:\/\/\S+|mailto:\S+@\S+|\{link\})$/i.test(ziel)) {
            melden("Bitte eine Adresse mit https:// oder {link} eintragen.");
            return;
        }
        dialog.hidden = true;
        text = text || ziel;
        if (dialogArt === "knopf") {
            einfuegen('<p><a class="knopf" href="' + esc(ziel) + '">' + esc(text) + "</a></p><p><br></p>");
        } else {
            einfuegen('<a href="' + esc(ziel) + '">' + esc(text) + "</a>");
        }
        melden("");
    });

    // Bild hochladen und einfügen
    var datei = document.getElementById("rm-bild");
    datei.addEventListener("change", function () {
        if (!datei.files || datei.files.length === 0) { return; }
        var fd = new FormData();
        fd.append("bild", datei.files[0]);
        melden("Bild wird hochgeladen …");
        fetch("/team/mailings/bild", { method: "POST", body: fd, headers: { "X-CSRF": csrf }, credentials: "same-origin" })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (x) {
                if (!x.ok) { melden(x.d.fehler || "Das Bild ließ sich nicht hochladen."); return; }
                einfuegen('<p><img src="' + esc(x.d.url) + '" alt="" data-breite="100"></p>');
                melden("Bild eingefügt (" + x.d.breite + " × " + x.d.hoehe + " px). Klick aufs Bild: halbe oder volle Breite.");
            })
            .catch(function () { melden("Das Bild ließ sich nicht hochladen. Bitte die Seite neu laden."); })
            .then(function () { datei.value = ""; });
    });
    // Ohne Skript gibt es den Knopf „Bild ans Ende setzen“; mit Skript reicht die Werkzeugleiste
    form.querySelector(".rm-bild-feld").hidden = true;

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
        if (befehl === "bold" || befehl === "italic") {
            document.execCommand(befehl, false, null);
        } else if (befehl === "titel") {
            var block = document.queryCommandValue("formatBlock").toLowerCase();
            document.execCommand("formatBlock", false, block === "h2" ? "p" : "h2");
        } else if (befehl === "liste") {
            document.execCommand("insertUnorderedList", false, null);
        } else if (befehl === "link" || befehl === "knopf") {
            dialogAuf(befehl);
            return;
        } else if (befehl === "bild") {
            datei.click();
            return;
        }
        sync();
    });

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
