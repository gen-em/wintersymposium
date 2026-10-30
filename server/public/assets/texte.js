/*
 * Werkzeugleiste für die Texte der Webseite (Rückmeldung 2026-10-30): schreibt
 * Markdown nach CommonMark in das Textfeld (fett, kursiv, Überschrift, Liste,
 * Link, Bild). Ohne Skript bleibt das Textfeld wie es ist.
 */
(function () {
    "use strict";
    var form = document.querySelector("form[action='/team/texte']");
    if (!form) { return; }
    var csrf = form.querySelector("input[name=csrf]").value;

    function umschliessen(ta, vor, nach, platzhalter) {
        var a = ta.selectionStart, b = ta.selectionEnd;
        var auswahl = ta.value.slice(a, b) || platzhalter;
        ta.setRangeText(vor + auswahl + nach, a, b, "end");
        ta.focus();
        ta.setSelectionRange(a + vor.length, a + vor.length + auswahl.length);
    }
    function zeilenanfang(ta, zeichen) {
        var a = ta.selectionStart, b = ta.selectionEnd;
        var start = ta.value.lastIndexOf("\n", a - 1) + 1;
        var block = ta.value.slice(start, b);
        var neu = block.split("\n").map(function (z) { return z.indexOf(zeichen) === 0 ? z.slice(zeichen.length) : zeichen + z; }).join("\n");
        ta.setRangeText(neu, start, b, "end");
        ta.focus();
    }
    function einfuegen(ta, text) {
        ta.setRangeText(text, ta.selectionStart, ta.selectionEnd, "end");
        ta.focus();
    }

    Array.prototype.forEach.call(form.querySelectorAll("textarea[data-markdown]"), function (ta) {
        var leiste = document.createElement("div");
        leiste.className = "werkzeug";
        leiste.setAttribute("role", "toolbar");
        leiste.setAttribute("aria-label", "Formatierung");
        leiste.setAttribute("aria-controls", ta.id);
        leiste.innerHTML = '<button type="button" data-b="fett" title="Fett"><b>B</b></button><button type="button" data-b="kursiv" title="Kursiv"><i>I</i></button><button type="button" data-b="titel" title="Überschrift">H</button><span class="trenn"></span>'
            + '<button type="button" data-b="liste" title="Liste">• Liste</button><button type="button" data-b="link" title="Link">Link</button><button type="button" data-b="bild" title="Bild hochladen">Bild</button>';
        var datei = document.createElement("input");
        datei.type = "file";
        datei.accept = "image/jpeg,image/png";
        datei.hidden = true;
        datei.setAttribute("aria-label", "Bild für " + ta.id);
        datei.className = "text-bild-datei";
        var status = document.createElement("p");
        status.className = "meta klein";
        status.setAttribute("role", "status");
        ta.parentNode.insertBefore(leiste, ta);
        ta.parentNode.insertBefore(datei, ta.nextSibling);
        ta.parentNode.insertBefore(status, datei.nextSibling);

        leiste.addEventListener("click", function (ev) {
            var b = ev.target.closest("button");
            if (!b) { return; }
            var w = b.getAttribute("data-b");
            if (w === "fett") { umschliessen(ta, "**", "**", "fett"); }
            else if (w === "kursiv") { umschliessen(ta, "*", "*", "kursiv"); }
            else if (w === "titel") { zeilenanfang(ta, "## "); }
            else if (w === "liste") { zeilenanfang(ta, "- "); }
            else if (w === "link") {
                var a = ta.selectionStart;
                umschliessen(ta, "[", "](https://)", "Linktext");
                var ziel = ta.value.indexOf("](https://)", a) + 2;
                ta.setSelectionRange(ziel, ziel + 8);
            }
            else if (w === "bild") { datei.click(); }
        });
        datei.addEventListener("change", function () {
            if (!datei.files || !datei.files.length) { return; }
            var fd = new FormData();
            fd.append("bild", datei.files[0]);
            status.textContent = "Bild wird hochgeladen …";
            fetch("/team/texte/bild", { method: "POST", body: fd, headers: { "X-CSRF": csrf }, credentials: "same-origin" })
                .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                .then(function (x) {
                    if (!x.ok) { status.textContent = x.d.fehler || "Das Bild ließ sich nicht hochladen."; return; }
                    einfuegen(ta, "\n![Beschreibung](" + x.d.url + ")\n");
                    status.textContent = "Bild eingefügt. Ersetze „Beschreibung“ durch einen kurzen Text für Screenreader, dann speichern.";
                })
                .catch(function () { status.textContent = "Das Bild ließ sich nicht hochladen."; })
                .then(function () { datei.value = ""; });
        });
    });
}());
