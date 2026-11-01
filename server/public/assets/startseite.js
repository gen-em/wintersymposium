/*
 * Verwaltung → Startseite (AP-18, E-70 (6)): Vorschau der Startseite mit den
 * noch nicht gespeicherten Werten. Das Formular geht kurz nach dem Tippen an
 * /team/startseite/vorschau (POST mit CSRF, speichert nichts); die Antwort
 * erscheint im iframe in 1440 / 390 / 768 px, passend verkleinert.
 * Gerätewahl Desktop · Handy · Tablet: auf breitem Bildschirm Desktop, in der
 * schmalen Ansicht Handy (O-49). Hinweise über der Vorschau: Größenstufe des
 * Titels (vom Server), ob der Titel getrennt wurde (im iframe gemessen: an
 * einer Trennstelle = Info, sonst Warnung) und leere Kacheln.
 * Ohne JavaScript bleibt die Vorschau verborgen, das Formular funktioniert.
 */
(function () {
    "use strict";
    var form = document.getElementById("st-form");
    var box = document.getElementById("st-vorschau");
    if (!form || !box) { return; }
    var frame = box.querySelector("iframe");
    var rahmen = box.querySelector(".vorschau-rahmen");
    var hinweise = document.getElementById("st-hinweise");
    var breiteText = document.getElementById("st-breite");
    var knoepfe = box.querySelectorAll("[data-geraet]");
    var breit = window.matchMedia("(min-width: 1000px)");
    var breite = 1440;
    var warten = null;
    var scrollY = 0;

    box.hidden = false;
    document.getElementById("st-ohne-js").hidden = true;
    if (breit.matches) { box.open = true; }

    function skalieren() {
        var w = rahmen.clientWidth, h = rahmen.clientHeight;
        if (!w) { return; }
        var f = Math.min(1, w / breite);
        frame.style.width = breite + "px";
        frame.style.height = Math.ceil(h / f) + "px";
        frame.style.transform = "scale(" + f + ")";
        frame.style.left = Math.max(0, Math.round((w - breite * f) / 2)) + "px";
    }

    function waehlen(geraet) {
        knoepfe.forEach(function (b) {
            var an = b.getAttribute("data-geraet") === geraet;
            b.setAttribute("aria-pressed", an ? "true" : "false");
            if (an) { breite = Number(b.getAttribute("data-breite")); }
        });
        breiteText.textContent = "· " + breite + " px";
        skalieren();
        // Andere Breite, andere Zeilen: Trennung neu messen
        window.setTimeout(zeigen, 80);
    }

    function laden() {
        var doc = frame.contentDocument;
        scrollY = doc && doc.defaultView ? doc.defaultView.scrollY : 0;
        var aktion = form.getAttribute("action");
        form.setAttribute("action", form.getAttribute("data-vorschau"));
        form.setAttribute("target", frame.name);
        HTMLFormElement.prototype.submit.call(form);
        form.setAttribute("action", aktion);
        form.removeAttribute("target");
    }

    /* Wo bricht der Titel um? {trenn: an einer Trennstelle, notfall: mitten im Wort ohne Trennstelle} */
    function messen(doc) {
        var h1 = doc.querySelector(".held .titel");
        var erg = { trenn: false, notfall: false };
        if (!h1) { return erg; }
        var zeichen = [];
        var gang = doc.createTreeWalker(h1, 4);
        var n = gang.nextNode();
        while (n) {
            for (var i = 0; i < n.data.length; i++) { zeichen.push([n, i]); }
            n = gang.nextNode();
        }
        var bereich = doc.createRange();
        function oben(k) {
            var c = zeichen[k][0].data.charAt(zeichen[k][1]);
            if (/\s|­/.test(c)) { return null; }
            bereich.setStart(zeichen[k][0], zeichen[k][1]);
            bereich.setEnd(zeichen[k][0], zeichen[k][1] + 1);
            var r = bereich.getClientRects();
            return r.length && r[0].width > 0 ? r[0].top : null;
        }
        var vor = -1, vorOben = null;
        for (var k = 0; k < zeichen.length; k++) {
            var o = oben(k);
            if (o === null) { continue; }
            if (vor >= 0 && o > vorOben + 2) {
                var dazwischen = "", davor = "";
                for (var j = Math.max(0, vor - 2); j < k; j++) {
                    if (j >= vor) { dazwischen += zeichen[j][0].data.charAt(zeichen[j][1]); } else { davor += zeichen[j][0].data.charAt(zeichen[j][1]); }
                }
                if (/\s/.test(dazwischen) || /-$/.test(dazwischen.replace(/\u00AD/g, ""))) {
                    // Umbruch am Leerzeichen oder nach einem Bindestrich: normal
                } else if ((davor + dazwischen).indexOf("\u00AD") !== -1) {
                    // Chromium meldet den Trennstrich am ersten Zeichen nach der Trennstelle: zwei Zeichen davor mitprüfen
                    erg.trenn = true;
                } else {
                    erg.notfall = true;
                }
            }
            vor = k;
            vorOben = o;
        }
        return erg;
    }

    function hinweis(text, warnung) {
        var p = document.createElement("p");
        p.className = "meldung " + (warnung ? "fehler" : "hinweis");
        p.textContent = text;
        hinweise.appendChild(p);
    }

    function zeigen() {
        var doc = frame.contentDocument;
        hinweise.textContent = "";
        if (!doc || !doc.body || !doc.body.getAttribute("data-hinweise")) { return; }
        var h = {};
        try { h = JSON.parse(doc.body.getAttribute("data-hinweise")); } catch (e) { return; }
        var m = messen(doc);
        var titel = "Titel: Größe " + h.stufe + " (längster Teil „" + h.laengster + "“). ";
        if (m.notfall) {
            hinweis(titel + "Ein Wort passt nicht in die Zeile, der Browser trennt es selbst. Setz eine Trennstelle oder kürze den Titel.", true);
        } else if (m.trenn) {
            hinweis(titel + "Getrennt an deiner Trennstelle – gewollt.", false);
        } else {
            hinweis(titel + "Passt ohne Trennung.", false);
        }
        if (h.leer.length === 3) {
            hinweis("Keine Kachel ausgefüllt – der Abschnitt „Worum es geht“ erscheint nicht.", false);
        } else {
            h.leer.forEach(function (i) { hinweis("Kachel " + i + ": leer – erscheint nicht.", false); });
        }
    }

    frame.addEventListener("load", function () {
        var doc = frame.contentDocument;
        if (doc && doc.defaultView && scrollY) { doc.defaultView.scrollTo(0, scrollY); }
        zeigen();
    });
    knoepfe.forEach(function (b) {
        b.addEventListener("click", function () { waehlen(b.getAttribute("data-geraet")); });
    });
    function spaeter() {
        window.clearTimeout(warten);
        warten = window.setTimeout(laden, 500);
    }
    form.addEventListener("input", spaeter);
    form.addEventListener("change", spaeter);
    form.addEventListener("click", function (ev) {
        // Knöpfe der Werkzeugleiste (Lila, Trennstelle) ändern den Titel ohne input-Ereignis am Formular
        if (ev.target.closest && ev.target.closest(".werkzeug button")) { spaeter(); }
    });
    box.addEventListener("toggle", skalieren);
    window.addEventListener("resize", function () {
        if (breit.matches) { box.open = true; }
        skalieren();
    });

    waehlen(breit.matches ? "desktop" : "handy");
    laden();
}());
