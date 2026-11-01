/*
 * JS-Basis (AP-17 Unterpunkt 7, Review V-42 bis V-45): auf jeder Seite zuerst
 * geladen (defer, also nach dem Aufbau der Seite). Enthält
 *   window.ws   gemeinsame Helfer für die übrigen Skripte:
 *     ws.csrf()                 CSRF-Token aus <meta name="csrf"> bzw. dem ersten Formularfeld
 *     ws.post(url, felder)      POST (Objekt oder FormData) mit CSRF, Antwort als Objekt {ok, fehler, …}
 *     ws.get(url)               GET, Antwort als Objekt {ok, fehler, …}
 *     ws.melden(el, text)       Meldung in ein Element schreiben (leer = verbergen)
 *     ws.dialog({titel, inhalt, art, knoepfe})  einheitlicher Dialog (rot, gruen, lila)
 *     ws.oeffnen(dialogEl)      ein <dialog> modal öffnen; Klick daneben schließt
 *     ws.minuten("19:30"), ws.hm(1170)          Uhrzeiten (mit führender Null)
 *     ws.b64u(buffer), ws.bytes(text)           Base64url für WebAuthn
 *   die Bestätigungsdialoge (bisher dialog.js) und
 *   die stehende Kopfleiste (bisher kopf.js).
 *
 * Codestil (CLAUDE.md): doppelte Anführungszeichen, 4 Leerzeichen, NodeList.forEach.
 */
(function () {
    "use strict";

    var ws = {};

    ws.csrf = function () {
        var m = document.querySelector("meta[name=csrf]");
        if (m) { return m.getAttribute("content") || ""; }
        var f = document.querySelector("input[name=csrf]");
        return f ? f.value : "";
    };

    /** Antwort als Objekt: JSON des Servers, ok nur bei HTTP-Erfolg und ohne ok:false. */
    function antwort(r) {
        return r.json().then(function (d) {
            d = d && typeof d === "object" ? d : {};
            d.ok = r.ok && d.ok !== false;
            return d;
        }, function () {
            return { ok: false, fehler: "Unerwartete Antwort. Bitte die Seite neu laden." };
        });
    }
    function netzFehler() {
        return { ok: false, fehler: "Keine Verbindung. Bitte die Seite neu laden." };
    }

    ws.post = function (url, felder) {
        var fd = felder instanceof FormData ? felder : new FormData();
        if (!(felder instanceof FormData)) {
            Object.keys(felder || {}).forEach(function (k) { fd.append(k, felder[k]); });
        }
        if (!fd.has("csrf")) { fd.append("csrf", ws.csrf()); }
        return fetch(url, { method: "POST", body: fd, credentials: "same-origin", headers: { "Accept": "application/json" } })
            .then(antwort, netzFehler);
    };

    ws.get = function (url) {
        return fetch(url, { credentials: "same-origin", headers: { "Accept": "application/json" } }).then(antwort, netzFehler);
    };

    ws.melden = function (el, text) {
        if (!el) { return; }
        el.textContent = text || "";
        el.hidden = !text;
    };

    ws.minuten = function (hm) {
        var t = String(hm).split(":");
        return Number(t[0]) * 60 + Number(t[1]);
    };
    ws.hm = function (min) {
        min = Math.max(0, Math.min(23 * 60 + 59, Math.round(min)));
        var h = Math.floor(min / 60), m = min % 60;
        return (h < 10 ? "0" : "") + h + ":" + (m < 10 ? "0" : "") + m;
    };

    ws.b64u = function (buf) {
        var b = new Uint8Array(buf), s = "";
        for (var i = 0; i < b.length; i++) { s += String.fromCharCode(b[i]); }
        return btoa(s).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
    };
    ws.bytes = function (text) {
        var s = atob(text.replace(/-/g, "+").replace(/_/g, "/") + "===".slice((text.length + 3) % 4));
        var b = new Uint8Array(s.length);
        for (var i = 0; i < s.length; i++) { b[i] = s.charCodeAt(i); }
        return b.buffer;
    };

    /** <dialog> modal öffnen; Klick auf den Hintergrund schließt (Escape schließt der Browser). */
    ws.oeffnen = function (dlg) {
        if (!dlg || typeof dlg.showModal !== "function") { return false; }
        if (!dlg.hasAttribute("data-ws-offen")) {
            dlg.setAttribute("data-ws-offen", "1");
            dlg.addEventListener("click", function (ev) {
                if (ev.target !== dlg) { return; }
                var r = dlg.getBoundingClientRect();
                if (ev.clientX < r.left || ev.clientX > r.right || ev.clientY < r.top || ev.clientY > r.bottom) {
                    dlg.close();
                }
            });
        }
        dlg.showModal();
        return true;
    };

    var nr = 0;
    /**
     * Einheitlicher Dialog (Rückmeldung 2026-10-30, Entwurf C). Liefert das <dialog>.
     *   titel    Überschrift
     *   inhalt   Element(e) oder Text für den Inhalt
     *   art      "rot" (Standard), "gruen" oder "lila"
     *   knoepfe  Liste von Elementen (z. B. Absende-Knöpfe) für die Knopfzeile; „Abbrechen“
     *            (lila: „Schließen“, ohne weitere Knöpfe „Verstanden“) kommt selbst dazu
     *   nach     Element, hinter dem der Dialog im Dokument steht (Standard: Ende von body)
     */
    ws.dialog = function (opt) {
        nr += 1;
        var art = opt.art || "rot";
        var dlg = document.createElement("dialog");
        dlg.className = "dlg dlg-" + art;
        dlg.setAttribute("aria-labelledby", "dlg-t-" + nr);
        var karte = document.createElement("div");
        karte.className = "dlg-karte";
        karte.innerHTML = "<div class=\"dlg-griff\" aria-hidden=\"true\"></div><h2 class=\"dlg-titel\" id=\"dlg-t-" + nr + "\"></h2><div class=\"dlg-inhalt\"></div><div class=\"dlg-knoepfe\"></div>";
        karte.querySelector(".dlg-titel").textContent = opt.titel || "";
        var inhalt = karte.querySelector(".dlg-inhalt");
        [].concat(opt.inhalt || []).forEach(function (n) {
            inhalt.appendChild(typeof n === "string" ? Object.assign(document.createElement("p"), { textContent: n }) : n);
        });
        var knoepfe = karte.querySelector(".dlg-knoepfe");
        (opt.knoepfe || []).forEach(function (b) {
            b.classList.remove("gefahr", "zweit", "klein");
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
        // Bei Löschen ist „Abbrechen“ vorausgewählt
        if (art === "rot") { nein.setAttribute("autofocus", ""); }
        dlg.appendChild(karte);
        if (opt.nach && opt.nach.parentNode) {
            opt.nach.parentNode.insertBefore(dlg, opt.nach.nextSibling);
        } else {
            document.body.appendChild(dlg);
        }
        dlg.addEventListener("click", function (ev) { if (ev.target === dlg) { dlg.close(); } });
        return dlg;
    };

    window.ws = ws;

    /* ---- Bestätigungen (bisher dialog.js) --------------------------------------
     * Ohne JavaScript bleibt jede Nachfrage ein aufklappbares <details class="bestaetigen">
     * mit Formular darin (bestaetigung_html). Mit JavaScript wird daraus ws.dialog:
     *   data-art="gruen|rot|lila", data-titel="…", data-ausloeser="id" (anderer Knopf öffnet).
     * Die Knöpfe bleiben in ihrem Formular (Attribut form), Absenden funktioniert wie vorher.
     * Steht details schon offen (z. B. nach einem Fehler), öffnet sich der Dialog sofort. */
    function umbauen(det) {
        var summary = det.querySelector(":scope > summary");
        if (!summary) { return; }
        var art = det.getAttribute("data-art") || "rot";
        var titel = det.getAttribute("data-titel") || summary.textContent.trim().replace(/\s*…$/, "") + "?";
        var teile = [];
        det.childNodes.forEach(function (n) { if (n !== summary) { teile.push(n); } });
        var halter = document.createElement("div");
        teile.forEach(function (n) { halter.appendChild(n); });
        // Absende-Knöpfe in die Knopfzeile, im eigenen Formular (getAttribute statt f.id:
        // ein Feld name="id" im Formular überdeckt sonst die Eigenschaft)
        var ja = [];
        halter.querySelectorAll("button[type=submit], button:not([type])").forEach(function (b) {
            var f = b.form;
            if (f) {
                if (!f.getAttribute("id")) {
                    f.setAttribute("id", "dlg-form-" + (nr + 1) + "-" + Math.random().toString(36).slice(2, 7));
                }
                b.setAttribute("form", f.getAttribute("id"));
            }
            ja.push(b);
        });
        var inhalt = [];
        halter.childNodes.forEach(function (n) { inhalt.push(n); });
        var dlg = ws.dialog({ titel: titel, inhalt: inhalt, art: art, knoepfe: ja, nach: det });

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
        dlg.addEventListener("close", function () { knopf.focus(); });
        if (offen) { dlg.showModal(); }
    }
    function alleDialoge() {
        if (typeof HTMLDialogElement !== "function") { return; }
        document.querySelectorAll("details.bestaetigen").forEach(umbauen);
    }
    alleDialoge();
    window.wsDialoge = alleDialoge;

    // Zeilen-Menüs (Team-Konten, AP-13): nach der Wahl eines Punkts und bei Klick daneben schließen
    document.addEventListener("click", function (ev) {
        var punkt = ev.target.closest ? ev.target.closest(".zeilen-menue .menue-punkt") : null;
        document.querySelectorAll("details.zeilen-menue[open]").forEach(function (m) {
            if (punkt ? m.contains(punkt) : !m.contains(ev.target)) {
                m.open = false;
            }
        });
    });

    /* ---- Stehende Kopfleiste (bisher kopf.js; AP-12, E-60) ---------------------
     * Desktop: Die Leiste bleibt oben (CSS sticky), mit Schatten beim Scrollen; ihre
     * Höhe ändert sich nie. Handy: Beim Scrollen nach unten verschwindet sie, rechts
     * oben bleibt ein kleiner „Menü“-Knopf; beim Scrollen nach oben kommt sie zurück. */
    (function () {
        var kopf = document.querySelector("header.kopf");
        if (!kopf) { return; }
        var menue = kopf.querySelector("details.menue");
        var schmal = window.matchMedia("(max-width: 759px)");
        var knopf = document.createElement("button");
        knopf.type = "button";
        knopf.className = "kopf-menue-knopf";
        knopf.textContent = "Menü";
        knopf.setAttribute("aria-label", "Menü öffnen");
        document.body.appendChild(knopf);

        var letztes = window.scrollY, wartet = false;
        function setzen(weg) {
            kopf.classList.toggle("versteckt", weg);
            document.body.classList.toggle("kopf-weg", weg);
        }
        function pruefen() {
            wartet = false;
            var y = window.scrollY;
            kopf.classList.toggle("gescrollt", y > 8);
            if (!schmal.matches || (menue && menue.open)) {
                setzen(false);
            } else if (y > 120 && y > letztes + 4) {
                setzen(true);
            } else if (y < letztes - 4 || y <= 120) {
                setzen(false);
            }
            letztes = y;
        }
        window.addEventListener("scroll", function () {
            if (!wartet) { wartet = true; window.requestAnimationFrame(pruefen); }
        }, { passive: true });
        if (schmal.addEventListener) { schmal.addEventListener("change", pruefen); } else { schmal.addListener(pruefen); }
        knopf.addEventListener("click", function () {
            setzen(false);
            if (menue) {
                menue.open = true;
                var s = menue.querySelector("summary");
                if (s) { s.focus(); }
            }
        });
        pruefen();
    }());

    // Fehlermeldung nach dem Absenden sichtbar machen (AP-13): liegt sie außerhalb, dorthin scrollen
    var fehler = document.querySelector(".meldung.fehler");
    if (fehler) {
        var r = fehler.getBoundingClientRect();
        if (r.top < 0 || r.bottom > window.innerHeight) {
            fehler.scrollIntoView({ block: "center" });
        }
    }
}());
