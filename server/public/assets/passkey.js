/*
 * Passkeys (O-14, E-55, E-75): Einloggen ohne Passwort und Passkey anlegen.
 * Spricht mit /passkey über ws.post (basis.js, JSON, CSRF). Ohne WebAuthn im
 * Browser bleiben die Bereiche [data-passkey-login] und [data-passkey-anlage]
 * verborgen; alles andere geht weiter mit Passwort und Code.
 *
 * Login-Seite ([data-passkey-direkt], E-75):
 *   - Adressfeld: Der Browser bietet die Passkeys beim Tippen ins Feld an
 *     (Conditional UI, autocomplete="username webauthn"). Die Abfrage läuft
 *     still im Hintergrund und beginnt kurz vor Ablauf der Herausforderung neu.
 *   - Merker: Nach einem Login mit Passkey merkt sich das Gerät nur „zuletzt
 *     mit Passkey“ (localStorage, ohne Name oder Adresse). Mit Merker geht die
 *     Abfrage beim Öffnen von selbst auf. Lehnt der Browser das ohne Tipp ab
 *     (Safari), steht der Knopf hervorgehoben oben; bricht die Person ab, ist
 *     der Merker weg. Ausloggen lässt ihn stehen; „Mein Konto“ löscht ihn,
 *     wenn der letzte Passkey entfernt wurde ([data-passkey-merker-weg]).
 *   - Der Knopf bricht die stille Abfrage vorher ab (AbortController).
 */
(function () {
    "use strict";
    var MERKER = "ws-passkey";
    var BLOCKIERT_MS = 1000; // schneller abgelehnt: Browser verlangt einen Tipp, kein Abbruch durch die Person
    var NEU_VOR_ABLAUF_MS = 20000; // stille Abfrage so lange vor Ablauf der Herausforderung neu beginnen

    /** Merker lesen (ohne Wert) oder setzen bzw. löschen; ohne Speicher (privates Fenster) gibt es keinen. */
    function merker(an) {
        try {
            if (an === undefined) {
                return window.localStorage.getItem(MERKER) === "1";
            }
            if (an) {
                window.localStorage.setItem(MERKER, "1");
            } else {
                window.localStorage.removeItem(MERKER);
            }
        } catch (e) {
            return false;
        }
        return false;
    }
    if (document.querySelector("[data-passkey-merker-weg]")) {
        merker(false);
    }

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
    function optionen() {
        return senden({ aktion: "login_optionen" }).then(function (d) {
            if (!d.ok) { throw new Error(d.fehler); }
            return d.optionen;
        });
    }
    function abfragen(o, zusatz) {
        var opt = { publicKey: { challenge: bytes(o.challenge), rpId: o.rpId, timeout: o.timeout, userVerification: o.userVerification, allowCredentials: [] } };
        Object.keys(zusatz || {}).forEach(function (k) { opt[k] = zusatz[k]; });
        return navigator.credentials.get(opt);
    }
    /** Antwort an den Server; bei Erfolg Merker setzen und weiter. Fehler des Servers kommen als Error mit .server. */
    function anmelden(c) {
        return senden({ aktion: "login", antwort: JSON.stringify({
            rawId: b64u(c.rawId), clientDataJSON: b64u(c.response.clientDataJSON),
            authenticatorData: b64u(c.response.authenticatorData), signature: b64u(c.response.signature)
        }) }).then(function (d) {
            if (!d.ok) {
                var f = new Error(d.fehler || "Das hat nicht geklappt.");
                f.server = true;
                throw f;
            }
            merker(true);
            window.location.href = d.ziel;
        });
    }

    document.querySelectorAll("[data-passkey-login]").forEach(function (bereich) {
        bereich.hidden = false;
        var knopf = bereich.querySelector("[data-passkey-los]");
        var direkt = bereich.hasAttribute("data-passkey-direkt");
        var still = null; // laufende Abfrage im Adressfeld: {ctrl, uhr, fertig}

        function stillStoppen() {
            if (!still) { return Promise.resolve(); }
            var s = still;
            still = null;
            clearTimeout(s.uhr);
            s.ctrl.abort();
            return s.fertig;
        }
        function stillStarten() {
            if (!direkt || still || !window.AbortController || typeof PublicKeyCredential.isConditionalMediationAvailable !== "function") {
                return;
            }
            var s = { ctrl: new AbortController(), uhr: null, gewaehlt: false };
            still = s;
            s.fertig = PublicKeyCredential.isConditionalMediationAvailable().then(function (ja) {
                if (!ja || still !== s) { return null; }
                return optionen().then(function (o) {
                    if (still !== s) { return null; }
                    s.uhr = setTimeout(function () {
                        stillStoppen().then(stillStarten);
                    }, Math.max(o.timeout - NEU_VOR_ABLAUF_MS, 30000));
                    return abfragen(o, { mediation: "conditional", signal: s.ctrl.signal });
                });
            }).then(function (c) {
                if (!c || still !== s) { return; }
                clearTimeout(s.uhr);
                s.gewaehlt = true;
                knopf.disabled = true;
                melden(bereich, "");
                return anmelden(c);
            }).catch(function (e) {
                if (still === s) {
                    clearTimeout(s.uhr);
                    still = null;
                }
                // Nur nach einer Wahl im Adressfeld melden und neu beginnen; sonst still (abgebrochen, nicht unterstützt)
                if (s.gewaehlt) {
                    knopf.disabled = false;
                    melden(bereich, abgebrochen(e) ? "Abgebrochen. Du kannst es noch einmal versuchen oder das Passwort nehmen." : (e.message || "Das hat nicht geklappt."));
                    stillStarten();
                }
            });
        }
        /** Knopf nach oben und hervorgehoben (Browser verlangt einen Tipp); „oder“ wandert unter den Knopf. */
        function hervorheben() {
            var form = bereich.previousElementSibling;
            var trenner = bereich.querySelector(".trenner-text");
            if (trenner) { bereich.appendChild(trenner); }
            knopf.classList.remove("zweit");
            if (form) { bereich.parentNode.insertBefore(bereich, form); }
        }
        /** Mit Merker: normale Abfrage beim Öffnen der Seite (E-75). */
        function vonSelbst() {
            var start = 0;
            knopf.disabled = true;
            optionen().then(function (o) {
                start = Date.now();
                return abfragen(o);
            }).then(anmelden).catch(function (e) {
                knopf.disabled = false;
                if (e && e.name === "NotAllowedError" && start && Date.now() - start < BLOCKIERT_MS) {
                    hervorheben();
                } else if (abgebrochen(e)) {
                    merker(false);
                } else {
                    if (e && e.server) { merker(false); } // Passkey dieses Geräts nicht (mehr) angenommen
                    melden(bereich, (e && e.message) || "Das hat nicht geklappt.");
                }
                stillStarten();
            });
        }

        knopf.addEventListener("click", function () {
            knopf.disabled = true;
            melden(bereich, "");
            stillStoppen().then(optionen).then(function (o) {
                return abfragen(o);
            }).then(anmelden).catch(function (e) {
                melden(bereich, abgebrochen(e) ? "Abgebrochen. Du kannst es noch einmal versuchen oder das Passwort nehmen." : (e.message || "Das hat nicht geklappt."));
                knopf.disabled = false;
                stillStarten();
            });
        });

        if (direkt && merker()) {
            vonSelbst();
        } else {
            stillStarten();
        }
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
