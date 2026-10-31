/*
 * Stehende Kopfleiste (AP-12, E-60; Rückmeldung 2026-10-31).
 * Desktop: Die Leiste bleibt oben (CSS sticky), mit Schatten beim Scrollen.
 * Ihre Höhe ändert sich nie – sonst springt die Scroll-Position am Seitenende
 * hin und her. Handy: Beim Scrollen nach unten verschwindet sie, rechts
 * oben bleibt ein kleiner „Menü“-Knopf; beim Scrollen nach oben kommt sie
 * zurück. Ohne JavaScript bleibt die Leiste einfach stehen.
 */
(function () {
    "use strict";
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

    var letztes = window.scrollY, versteckt = false, wartet = false;
    function setzen(weg) {
        versteckt = weg;
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
    schmal.addEventListener ? schmal.addEventListener("change", pruefen) : schmal.addListener(pruefen);
    knopf.addEventListener("click", function () {
        setzen(false);
        if (menue) {
            menue.open = true;
            var s = menue.querySelector("summary");
            if (s) { s.focus(); }
        }
    });
    pruefen();
})();
