/*
 * Passkeys (O-14, E-55): Einloggen ohne Passwort und Passkey anlegen.
 * Spricht mit /passkey (JSON, CSRF über das Formularfeld). Ohne WebAuthn im
 * Browser bleiben die Bereiche [data-passkey-login] und [data-passkey-anlage]
 * verborgen; alles andere geht weiter mit Passwort und Code.
 */
(function () {
    "use strict";
    if (!window.PublicKeyCredential || !navigator.credentials) {
        return;
    }
    var csrfFeld = document.querySelector("input[name=csrf]");
    if (!csrfFeld) {
        return;
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
    function senden(felder) {
        var fd = new FormData();
        fd.append("csrf", csrfFeld.value);
        Object.keys(felder).forEach(function (k) { fd.append(k, felder[k]); });
        return fetch("/passkey", { method: "POST", body: fd, credentials: "same-origin", headers: { "Accept": "application/json" } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, fehler: "Unerwartete Antwort. Bitte die Seite neu laden." }; }); });
    }
    function melden(bereich, text) {
        var m = bereich.querySelector("[data-passkey-meldung]");
        if (m) { m.textContent = text || ""; m.hidden = !text; }
    }
    function abgebrochen(e) {
        return e && (e.name === "NotAllowedError" || e.name === "AbortError");
    }

    // ---- Einloggen ----
    Array.prototype.forEach.call(document.querySelectorAll("[data-passkey-login]"), function (bereich) {
        bereich.hidden = false;
        var knopf = bereich.querySelector("[data-passkey-los]");
        knopf.addEventListener("click", function () {
            knopf.disabled = true;
            melden(bereich, "");
            senden({ aktion: "login_optionen" }).then(function (d) {
                if (!d.ok) { throw new Error(d.fehler); }
                var o = d.optionen;
                return navigator.credentials.get({ publicKey: { challenge: bytes(o.challenge), rpId: o.rpId, timeout: o.timeout, userVerification: o.userVerification, allowCredentials: [] } });
            }).then(function (c) {
                return senden({ aktion: "login", antwort: JSON.stringify({
                    rawId: b64u(c.rawId), clientDataJSON: b64u(c.response.clientDataJSON),
                    authenticatorData: b64u(c.response.authenticatorData), signature: b64u(c.response.signature)
                }) });
            }).then(function (d) {
                if (!d.ok) { throw new Error(d.fehler); }
                window.location.href = d.ziel;
            }).catch(function (e) {
                melden(bereich, abgebrochen(e) ? "Abgebrochen. Du kannst es noch einmal versuchen oder das Passwort nehmen." : (e.message || "Das hat nicht geklappt."));
                knopf.disabled = false;
            });
        });
    });

    // ---- Anlegen ----
    Array.prototype.forEach.call(document.querySelectorAll("[data-passkey-anlage]"), function (bereich) {
        bereich.hidden = false;
        var form = bereich.querySelector("[data-passkey-anlegen]");
        form.addEventListener("submit", function (ev) {
            ev.preventDefault();
            var knopf = form.querySelector("button[type=submit]");
            var pw = form.querySelector("input[name=pw]");
            var bez = form.querySelector("input[name=bezeichnung]");
            knopf.disabled = true;
            melden(bereich, "");
            senden({ aktion: "anlage_optionen", pw: pw ? pw.value : "" }).then(function (d) {
                if (!d.ok) { throw new Error(d.fehler); }
                var o = d.optionen;
                return navigator.credentials.create({ publicKey: {
                    challenge: bytes(o.challenge), rp: o.rp, timeout: o.timeout, attestation: o.attestation,
                    user: { id: bytes(o.user.id), name: o.user.name, displayName: o.user.displayName },
                    pubKeyCredParams: o.pubKeyCredParams, authenticatorSelection: o.authenticatorSelection,
                    excludeCredentials: o.excludeCredentials.map(function (x) { return { type: "public-key", id: bytes(x.id) }; })
                } });
            }).then(function (c) {
                return senden({ aktion: "anlegen", bezeichnung: bez ? bez.value : "", antwort: JSON.stringify({
                    rawId: b64u(c.rawId), clientDataJSON: b64u(c.response.clientDataJSON), attestationObject: b64u(c.response.attestationObject)
                }) });
            }).then(function (d) {
                if (!d.ok) { throw new Error(d.fehler); }
                window.location.href = d.ziel;
            }).catch(function (e) {
                melden(bereich, abgebrochen(e) ? "Abgebrochen oder auf diesem Gerät schon ein Passkey für dieses Konto." : (e.message || "Das hat nicht geklappt."));
                knopf.disabled = false;
            });
        });
    });
}());
