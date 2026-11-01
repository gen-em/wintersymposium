/*
 * pow-worker.js – Rechenaufgabe des Spamschutzes (übernommen aus NAdoku
 * server/assets/pow-worker.js, gleicher Urheber, AGPL-3.0).
 *
 * Gesucht ist eine Zahl, für die SHA-256 über „aufgabe + zahl“ mit
 * mindestens `bits` Nullbits beginnt. Der Server prüft das mit einer Rechnung
 * (spam_pow_ok in lib/spamschutz_lib.php). Läuft als Worker, damit die Seite
 * beim Tippen nicht steht; SHA-256 rechnet er selbst (schneller als ein
 * Promise je Versuch), mit Gegenprobe gegen WebCrypto.
 * Nachricht hinein: {aufgabe, bits}; heraus: {loesung, versuche, ms} oder {fehler}.
 */
"use strict";

const BUENDEL = 64;

/** Beginnt `h` mit mindestens `bits` Nullbits? */
function nullbits(h, bits) {
    let i = 0;
    for (; bits >= 8; bits -= 8, i++) {
        if (h[i] !== 0) { return false; }
    }
    return bits === 0 || (h[i] >> (8 - bits)) === 0;
}

/** `aufgabe` + Dezimalziffern von `n` als Bytes. */
function eingabe(kopf, n) {
    const ziffern = String(n);
    const b = new Uint8Array(kopf.length + ziffern.length);
    b.set(kopf);
    for (let i = 0; i < ziffern.length; i++) { b[kopf.length + i] = ziffern.charCodeAt(i); }
    return b;
}

/* ---- SHA-256 nach FIPS 180-4, für kurze Eingaben ---------------------- */
const K = new Uint32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2]);
const W = new Uint32Array(64);

/** SHA-256 von `m` als 32 Bytes. */
function sha256(m) {
    const l = m.length, nb = ((l + 9 + 63) >> 6) << 6, p = new Uint8Array(nb);
    p.set(m); p[l] = 0x80;
    const bl = l * 8;          // Eingaben hier sind kurz: die Länge passt in 32 Bit
    p[nb - 4] = bl >>> 24; p[nb - 3] = bl >>> 16; p[nb - 2] = bl >>> 8; p[nb - 1] = bl;
    const h = new Uint32Array([0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
                             0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19]);
    for (let o = 0; o < nb; o += 64) {
        for (let i = 0; i < 16; i++) {
            W[i] = (p[o + 4 * i] << 24) | (p[o + 4 * i + 1] << 16) | (p[o + 4 * i + 2] << 8) | p[o + 4 * i + 3];
        }
        for (let i = 16; i < 64; i++) {
            const x = W[i - 15], y = W[i - 2];
            W[i] = (((x >>> 7 | x << 25) ^ (x >>> 18 | x << 14) ^ (x >>> 3))
                            + W[i - 7] + ((y >>> 17 | y << 15) ^ (y >>> 19 | y << 13) ^ (y >>> 10)) + W[i - 16]) | 0;
        }
        let a = h[0], b = h[1], c = h[2], d = h[3], e = h[4], f = h[5], g = h[6], k = h[7];
        for (let i = 0; i < 64; i++) {
            const t1 = (k + ((e >>> 6 | e << 26) ^ (e >>> 11 | e << 21) ^ (e >>> 25 | e << 7))
                                    + ((e & f) ^ (~e & g)) + K[i] + W[i]) | 0;
            const t2 = (((a >>> 2 | a << 30) ^ (a >>> 13 | a << 19) ^ (a >>> 22 | a << 10))
                                    + ((a & b) ^ (a & c) ^ (b & c))) | 0;
            k = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
        }
        h[0] += a; h[1] += b; h[2] += c; h[3] += d; h[4] += e; h[5] += f; h[6] += g; h[7] += k;
    }
    const aus = new Uint8Array(32);
    for (let i = 0; i < 8; i++) {
        aus[4 * i] = h[i] >>> 24; aus[4 * i + 1] = h[i] >>> 16; aus[4 * i + 2] = h[i] >>> 8; aus[4 * i + 3] = h[i];
    }
    return aus;
}

/** Rechnet `sha256()` wie WebCrypto? Ein Versuch je Auftrag. */
async function eigeneRechnungStimmt(kopf) {
    const probe = eingabe(kopf, 1234567);
    const soll = new Uint8Array(await crypto.subtle.digest("SHA-256", probe));
    const ist = sha256(probe);
    return soll.every((x, i) => x === ist[i]);
}

/** Die Suche mit der eigenen Rechnung — ohne ein Promise je Versuch. */
function suchenSelbst(kopf, bits, beginn) {
    for (let n = 0; ; n++) {
        if (nullbits(sha256(eingabe(kopf, n)), bits)) {
            return { loesung: String(n), versuche: n + 1, ms: Math.round(performance.now() - beginn) };
        }
    }
}

async function suchen(aufgabe, bits) {
    const kopf = new Uint8Array(aufgabe.length);
    for (let i = 0; i < aufgabe.length; i++) { kopf[i] = aufgabe.charCodeAt(i) & 0x7f; }
    const beginn = performance.now();
    if (await eigeneRechnungStimmt(kopf)) { return suchenSelbst(kopf, bits, beginn); }
    /* Der alte Weg, nur noch als Rückfall (Kopf, „mit Gegenprobe"). IN
   * BÜNDELN, weil ein einzelnes `await` je Versuch mehr kostet als der Hash. */
    for (let n = 0; ; n += BUENDEL) {
        const auftraege = [];
        for (let k = 0; k < BUENDEL; k++) {
            auftraege.push(crypto.subtle.digest("SHA-256", eingabe(kopf, n + k)));
        }
        const hashes = await Promise.all(auftraege);
        for (let k = 0; k < BUENDEL; k++) {
            if (nullbits(new Uint8Array(hashes[k]), bits)) {
                return { loesung: String(n + k), versuche: n + k + 1,
                 ms: Math.round(performance.now() - beginn) };
            }
        }
    }
}

self.onmessage = async ev => {
    const d = ev.data || {};
    if (!self.crypto || !self.crypto.subtle) {
        self.postMessage({ fehler: "webcrypto" });
        return;
    }
    if (typeof d.aufgabe !== "string" || !/^[0-9a-f]+$/.test(d.aufgabe)
            || !Number.isInteger(d.bits) || d.bits < 1 || d.bits > 32) {
        self.postMessage({ fehler: "auftrag" });
        return;
    }
    try {
        self.postMessage(await suchen(d.aufgabe, d.bits));
    } catch (e) {
        self.postMessage({ fehler: "rechnung" });
    }
};
