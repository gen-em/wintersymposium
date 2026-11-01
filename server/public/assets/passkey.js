/*
 * Passkeys (O-14, E-55): Einloggen ohne Passwort und Passkey anlegen.
 * Spricht mit /passkey über ws.post (basis.js, JSON, CSRF). Ohne WebAuthn im
 * Browser bleiben die Bereiche [data-passkey-login] und [data-passkey-anlage]
 * verborgen; alles andere geht weiter mit Passwort und Code.
 */
(function () {
    "use strict";
    if (!window.PublicKeyCredential || !navigator.credentials) {
        return;
    }
    if (!ws.csrf()) {
        return;
    }
    var b64u = ws.b64u, bytes = ws.bytes;
    function senden(felder) {
        return ws.post("/passkey", felder);
    }
    function melden(bereich, text) {
        ws.melden(bereich.querySelector("[data-passkey-meldung]"), text);
    }
    function abgebrochen(e) {
        return e && (e.name === "NotAllowedError" || e.name === "AbortError");
    }

    // ---- Einloggen ----
    document.querySelectorAll("[data-passkey-login]").forEach(function (bereich) {
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
    document.querySelectorAll("[data-passkey-anlage]").forEach(function (bereich) {
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
