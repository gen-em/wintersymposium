/*
 * Heikle Aktionen im Team-Bereich bestätigen (Review B-7): neben dem Code-Feld
 * „Code per E-Mail schicken“ und „Mit Passkey bestätigen“. Beide Knöpfe sind
 * type="button" und ohne JavaScript verborgen ([data-zf-wege]).
 *   Mail-Code: schickt das Formular mit zf_mailcode=1 an seine eigene Adresse
 *     (zweitfaktor_mailcode_aktion, Antwort JSON) und zeigt die Antwort an.
 *   Passkey: /passkey bestaetigen_optionen → navigator.credentials.get →
 *     bestaetigen; danach wird das Formular ohne Code abgeschickt (die
 *     Bestätigung liegt 5 Minuten in der Sitzung).
 * Ereignisse am document, weil dialog.js die Formulare in Dialoge umbaut.
 */
(function () {
    "use strict";
    var webauthn = !!(window.PublicKeyCredential && navigator.credentials);

    function zeigen() {
        Array.prototype.forEach.call(document.querySelectorAll("[data-zf-wege]"), function (w) {
            w.hidden = false;
            Array.prototype.forEach.call(w.querySelectorAll("[data-zf-passkey]"), function (b) { b.hidden = !webauthn; });
        });
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", zeigen);
    } else {
        zeigen();
    }

    function b64u(buf) {
        var b = new Uint8Array(buf), s = "";
        for (var i = 0; i < b.length; i++) { s += String.fromCharCode(b[i]); }
        return btoa(s).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
    }
    function bytes(text) {
        var s = atob(text.replace(/-/g, "+").replace(/_/g, "/") + "===".slice((text.length + 3) % 4));
        var b = new Uint8Array(s.length);
        for (var i = 0; i < s.length; i++) { b[i] = s.charCodeAt(i); }
        return b.buffer;
    }
    function json(r) {
        return r.json().catch(function () { return { ok: false, fehler: "Unerwartete Antwort. Bitte die Seite neu laden." }; });
    }
    function melden(knopf, text) {
        var feld = knopf.closest("[data-zf-wege]");
        var m = feld && feld.nextElementSibling && feld.nextElementSibling.hasAttribute("data-zf-meldung") ? feld.nextElementSibling : null;
        if (m) { m.textContent = text || ""; m.hidden = !text; }
    }
    function passkeyFrage(felder, csrf) {
        var fd = new FormData();
        fd.append("csrf", csrf);
        Object.keys(felder).forEach(function (k) { fd.append(k, felder[k]); });
        return fetch("/passkey", { method: "POST", body: fd, credentials: "same-origin", headers: { "Accept": "application/json" } }).then(json);
    }

    document.addEventListener("click", function (ev) {
        var knopf = ev.target.closest ? ev.target.closest("[data-zf-mailcode], [data-zf-passkey]") : null;
        if (!knopf || !knopf.form) {
            return;
        }
        var form = knopf.form;
        var csrf = form.querySelector("input[name=csrf]");
        knopf.disabled = true;
        melden(knopf, "");

        if (knopf.hasAttribute("data-zf-mailcode")) {
            var fd = new FormData(form);
            fd.append("zf_mailcode", "1");
            fetch(form.getAttribute("action") || window.location.href, { method: "POST", body: fd, credentials: "same-origin", headers: { "Accept": "application/json" } })
                .then(json)
                .then(function (d) { melden(knopf, d.text || d.fehler || "Das hat nicht geklappt."); })
                .catch(function () { melden(knopf, "Das hat nicht geklappt. Bitte die Seite neu laden."); })
                .then(function () { knopf.disabled = false; });
            return;
        }

        passkeyFrage({ aktion: "bestaetigen_optionen" }, csrf ? csrf.value : "").then(function (d) {
            if (!d.ok) { throw new Error(d.fehler); }
            var o = d.optionen;
            return navigator.credentials.get({ publicKey: {
                challenge: bytes(o.challenge), rpId: o.rpId, timeout: o.timeout, userVerification: o.userVerification,
                allowCredentials: o.allowCredentials.map(function (x) { return { type: "public-key", id: bytes(x.id) }; })
            } });
        }).then(function (c) {
            return passkeyFrage({ aktion: "bestaetigen", antwort: JSON.stringify({
                rawId: b64u(c.rawId), clientDataJSON: b64u(c.response.clientDataJSON),
                authenticatorData: b64u(c.response.authenticatorData), signature: b64u(c.response.signature)
            }) }, csrf ? csrf.value : "");
        }).then(function (d) {
            if (!d.ok) { throw new Error(d.fehler); }
            melden(knopf, "Bestätigt. Einen Moment …");
            form.submit(); // ohne Code: die Bestätigung liegt in der Sitzung
        }).catch(function (e) {
            var abgebrochen = e && (e.name === "NotAllowedError" || e.name === "AbortError");
            melden(knopf, abgebrochen ? "Abgebrochen. Du kannst es noch einmal versuchen oder einen Code eingeben." : (e.message || "Das hat nicht geklappt."));
            knopf.disabled = false;
        });
    });
}());
