/*
 * Heikle Aktionen im Team-Bereich bestätigen (Review B-7): neben dem Code-Feld
 * „Code per E-Mail schicken“ und „Mit Passkey bestätigen“. Beide Knöpfe sind
 * type="button" und ohne JavaScript verborgen ([data-zf-wege]).
 *   Mail-Code: schickt das Formular mit zf_mailcode=1 an seine eigene Adresse
 *     (zweitfaktor_mailcode_aktion, Antwort JSON) und zeigt die Antwort an.
 *   Passkey: /passkey bestaetigen_optionen → navigator.credentials.get →
 *     bestaetigen; danach wird das Formular ohne Code abgeschickt (die
 *     Bestätigung liegt 5 Minuten in der Sitzung).
 * Ereignisse am document, weil basis.js die Formulare in Dialoge umbaut.
 */
(function () {
    "use strict";
    var webauthn = !!(window.PublicKeyCredential && navigator.credentials);

    document.querySelectorAll("[data-zf-wege]").forEach(function (w) {
        w.hidden = false;
        w.querySelectorAll("[data-zf-passkey]").forEach(function (b) { b.hidden = !webauthn; });
    });

    var b64u = ws.b64u, bytes = ws.bytes;
    function melden(knopf, text) {
        var feld = knopf.closest("[data-zf-wege]");
        ws.melden(feld && feld.nextElementSibling && feld.nextElementSibling.hasAttribute("data-zf-meldung") ? feld.nextElementSibling : null, text);
    }
    function passkeyFrage(felder, csrf) {
        return ws.post("/passkey", Object.assign({ csrf: csrf }, felder));
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
            ws.post(form.getAttribute("action") || window.location.href, fd)
                .then(function (d) { melden(knopf, d.text || d.fehler || "Das hat nicht geklappt."); })
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
