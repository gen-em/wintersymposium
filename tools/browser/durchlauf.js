/*
 * Browser-Durchlauf (Regel R-12): alle Seiten und Abläufe in Chromium,
 * Firefox und WebKit (Safari-Engine), je auf Handy-, Tablet-, schmaler und
 * breiter Desktopbreite. Prüft je Seite und Breite:
 *   - Antwort ohne Fehlerstatus
 *   - kein seitliches Scrollen (scrollWidth ≤ Fensterbreite)
 *   - keine Konsolen- und Skriptfehler
 *   - jedes Element liegt innerhalb der Fensterbreite
 *   - Code-Felder (autocomplete=one-time-code) tragen ein Stichwort wie
 *     totp in id oder Name, damit Bitwarden sie auch in Firefox erkennt
 *   - Bedienelemente auf jeder Seite: jedes Auswahlfeld mit jeder Option,
 *     jedes Aufklapp-Element (details) auf und zu, jedes Popup (dialog)
 *     über seinen Auslöser auf und mit Escape wieder zu
 *   - Klickwege: Menü auf dem Handy, Team-Reiter, Login mit zweitem Faktor,
 *     Konto anlegen über das Formular, Einrichtungslink, Ausloggen
 * Bildschirmfotos landen im Ausgabeordner, dazu bericht.txt.
 *
 * Einrichtung einmal je Sandbox: bash tools/browser/einrichten.sh
 * Lauf (Testdatenbank wird geleert, wie bei lauf.php):
 *   WS_TEST_DB_HOST=… WS_TEST_DB_NAME=… WS_TEST_DB_USER=… WS_TEST_DB_PASS=… \
 *   node tools/browser/durchlauf.js [Ausgabeordner]
 */
'use strict';
// Eigener Ordner mit allen drei Engines (tools/browser/einrichten.sh); die
// Sandbox gibt sonst /opt/pw-browsers vor, dort liegt nur Chromium.
if (require('fs').existsSync('/opt/ws-browsers')) {
  process.env.PLAYWRIGHT_BROWSERS_PATH = '/opt/ws-browsers';
}
const pw = require('playwright');
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { spawn, execFileSync } = require('child_process');

const WURZEL = path.resolve(__dirname, '..', '..');
const AUS = path.resolve(process.argv[2] || path.join(os.tmpdir(), 'ws-browser-' + Date.now()));
const ENGINES = ['chromium', 'firefox', 'webkit'];
const BREITEN = [
  ['handy', 390, 844],
  ['tablet', 768, 1024],
  ['desktop-schmal', 1024, 768],
  ['desktop-breit', 1440, 900],
];

fs.mkdirSync(AUS, { recursive: true });
const ergebnisse = [];
function notiere(engine, breite, seite, was, ok, info = '') {
  ergebnisse.push({ engine, breite, seite, was, ok, info });
  if (!ok) console.log(`  FEHLER ${engine} ${breite} ${seite}: ${was} ${info}`);
}

function b32(s) {
  const a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = '';
  for (const c of s) bits += a.indexOf(c).toString(2).padStart(5, '0');
  const out = []; for (let i = 0; i + 8 <= bits.length; i += 8) out.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(out);
}
function totp(key, t) {
  const b = Buffer.alloc(8); b.writeBigUInt64BE(BigInt(Math.floor(t / 30)));
  const h = crypto.createHmac('sha1', key).update(b).digest(); const o = h[19] & 15;
  const n = ((h[o] & 127) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3];
  return String(n % 1e6).padStart(6, '0');
}

function php(args, config) {
  return execFileSync('php', args, { env: { ...process.env, WS_CONFIG: config }, encoding: 'utf8' });
}

/** Sichtbare Elemente, die rechts aus dem Fenster ragen. Läuft im Browser. */
function ueberstand() {
  return [...document.querySelectorAll('body *')].filter(e => {
    // Ausnahme nur für ausdrücklich scrollbare Bereiche (.scroll-erlaubt); Tabellen zählen mit.
    if (e.closest('.scroll-erlaubt')) return false;
    if (e.checkVisibility && !e.checkVisibility({ visibilityProperty: true })) return false;
    const r = e.getBoundingClientRect();
    return r.width > 0 && r.right > window.innerWidth + 1;
  }).slice(0, 3).map(e => e.tagName.toLowerCase() + (e.className ? '.' + String(e.className).split(' ')[0] : ''));
}

/**
 * Bedienelemente der Seite durchgehen: Auswahlfelder, Aufklapp-Elemente,
 * Popups. Lässt die Seite danach im Ausgangszustand (Werte zurückgesetzt).
 */
/** Jedes sichtbare Auswahlfeld im Bereich: jede Option wählen, Wert muss übernommen werden. */
async function pruefeAuswahl(bereich, engine, breite, name) {
  const selects = bereich.locator('select');
  for (let i = 0; i < await selects.count(); i++) {
    const sel = selects.nth(i);
    if (!await sel.isVisible()) continue; // in zugeklappten Bereichen: geprüft beim Aufklappen
    const id = (await sel.getAttribute('id')) || ('select-' + i);
    const werte = await sel.locator('option').evaluateAll(os => os.map(o => o.value));
    const vorher = await sel.inputValue();
    let ok = werte.length > 0;
    for (const w of werte) {
      await sel.selectOption(w);
      if (await sel.inputValue() !== w) ok = false;
    }
    await sel.selectOption(vorher);
    notiere(engine, breite, name, `Auswahlfeld ${id}: ${werte.length} Optionen wählbar`, ok);
  }
}

async function pruefeBedienung(p, engine, breite, name) {
  await pruefeAuswahl(p.locator('body'), engine, breite, name);
  // Aufklapp-Elemente (Menü, Infos, Rolle ändern): auf und zu
  const details = p.locator('details');
  for (let i = 0; i < await details.count(); i++) {
    const d = details.nth(i);
    const sum = d.locator(':scope > summary');
    if (!await sum.isVisible()) continue;
    const label = ((await sum.textContent()) || '').trim().slice(0, 30);
    await sum.click();
    const offen = await d.evaluate(e => e.open);
    const inhaltSichtbar = await d.evaluate(e => [...e.children].some(c => c.tagName !== 'SUMMARY' && c.getBoundingClientRect().height > 0));
    const rausOffen = await p.evaluate(ueberstand);
    notiere(engine, breite, name, `Aufgeklappt „${label}“ innerhalb der Fensterbreite`, rausOffen.length === 0, rausOffen.join(', '));
    await pruefeAuswahl(d, engine, breite, name);
    await sum.click();
    const zu = await d.evaluate(e => !e.open);
    notiere(engine, breite, name, `Aufklappen „${label}“`, offen && inhaltSichtbar && zu);
  }
  // Popups: Auslöser mit aria-haspopup="dialog" oder data-dialog öffnen dialog[open]
  const ausloeser = p.locator('[aria-haspopup="dialog"], [data-dialog]');
  for (let i = 0; i < await ausloeser.count(); i++) {
    const a = ausloeser.nth(i);
    if (!await a.isVisible()) continue;
    const label = ((await a.textContent()) || '').trim().slice(0, 30);
    await a.click();
    const auf = await p.locator('dialog[open]').count() === 1 && await p.isVisible('dialog[open]');
    const imFenster = auf ? await p.evaluate(() => { const r = document.querySelector('dialog[open]').getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth + 1; }) : false;
    await p.keyboard.press('Escape');
    await p.waitForTimeout(100);
    const zu = await p.locator('dialog[open]').count() === 0;
    notiere(engine, breite, name, `Popup „${label}“: öffnet im Fenster, Escape schließt`, auf && imFenster && zu);
  }
}

/** Seite prüfen: Status, seitliches Scrollen, Fehler; Bildschirmfoto. */
async function pruefeSeite(p, fehler, engine, breite, name, url) {
  fehler.length = 0;
  const antwort = url ? await p.goto(url, { waitUntil: 'load' }) : null;
  await p.waitForTimeout(150);
  if (antwort) notiere(engine, breite, name, 'Status', antwort.status() < 400, String(antwort.status()));
  const m = await p.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
  notiere(engine, breite, name, 'kein seitliches Scrollen', m.sw <= m.iw, `${m.sw} > ${m.iw}`);
  // Elemente, die rechts aus dem Fenster ragen (außer in scrollbaren Tabellen)
  const raus = await p.evaluate(ueberstand);
  notiere(engine, breite, name, 'alles innerhalb der Fensterbreite', raus.length === 0, raus.join(', '));
  // Abgeschnittenes oder verdecktes Seitwärts-Scrollen in jedem Rahmen mit overflow (Tabellen, Kästen, Popups):
  // Inhalt breiter als der Rahmen. Ausnahmen: .scroll-erlaubt und bewusst gekürzter Text (text-overflow: ellipsis).
  const abgeschnitten = await p.evaluate(() => [...document.body.querySelectorAll('*')].filter(w => {
    if (!w.checkVisibility || !w.checkVisibility() || w.closest('.scroll-erlaubt')) return false;
    const st = getComputedStyle(w);
    if (st.overflowX === 'visible' || st.textOverflow === 'ellipsis') return false;
    return w.scrollWidth > w.clientWidth + 1;
  }).map(w => w.tagName.toLowerCase() + (w.className && typeof w.className === 'string' ? '.' + w.className.trim().split(/\s+/)[0] : '')));
  notiere(engine, breite, name, 'nichts abgeschnitten oder verdeckt seitwärts scrollbar', abgeschnitten.length === 0, [...new Set(abgeschnitten)].join(', '));
  // Code-Felder für Passwortmanager (Bitwarden in Firefox erkennt sie nur über Stichwörter,
  // siehe chodid/server-family docs/erkenntnis-bitwarden-einmalcode.md)
  const otp = await p.evaluate(() => [...document.querySelectorAll('input[autocomplete="one-time-code"]')].map(i => {
    const kennung = (i.id + ' ' + i.name + ' ' + (i.getAttribute('aria-label') || '')).toLowerCase().replace(/-/g, '');
    const stark = /totp|onetimecode|onetimepassword|twofactor|verificationcode|securitycode|mfacode|2facode/.test(kennung);
    const schlecht = i.type === 'password' || /backup|recovery/.test(kennung);
    return stark && !schlecht ? '' : (i.id || i.name || '?');
  }).filter(Boolean));
  notiere(engine, breite, name, 'Code-Felder für Passwortmanager erkennbar', otp.length === 0, otp.join(', '));
  await pruefeBedienung(p, engine, breite, name);
  notiere(engine, breite, name, 'keine Konsolenfehler', fehler.length === 0, fehler.join(' | '));
  await p.screenshot({ path: path.join(AUS, `${engine}-${breite}-${name}.png`), fullPage: true });
}

(async () => {
  // Vorbereitung: Datenbank, Installation, Server
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ws-durchlauf-'));
  const vor = JSON.parse(php([path.join(WURZEL, 'tools/browser/vorbereiten.php'), tmp], ''));
  const port = 19000 + Math.floor(Math.random() * 900);
  const basis = `http://127.0.0.1:${port}`;
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', path.join(WURZEL, 'server/public'), path.join(WURZEL, 'server/public/index.php')],
    { env: { ...process.env, WS_CONFIG: vor.config }, stdio: ['ignore', fs.openSync(path.join(tmp, 'server.log'), 'a'), fs.openSync(path.join(tmp, 'server.log'), 'a')] });
  await new Promise(r => setTimeout(r, 700));

  try {
    for (const engine of ENGINES) {
      console.log(`== ${engine}`);
      // Jede Engine richtet den zweiten Faktor frisch ein.
      php([path.join(WURZEL, 'server/cli/konto.php'), 'zweitfaktor', vor.betreiber], vor.config);
      // Deutsche Oberfläche wie bei den Nutzenden: Datums- und Zeitfelder zeigen dann TT.MM.JJJJ und 24 h.
      // Die Sprache allein reicht nicht: Chromium und Firefox formatieren die Felder nach der Umgebung
      // des Browserprozesses (LANG), Firefox nur, wenn er die Systemeinstellung übernehmen darf.
      const deUmgebung = { ...process.env, LANG: 'de_DE.UTF-8', LANGUAGE: 'de_DE:de', LC_ALL: 'de_DE.UTF-8' };
      const browser = await pw[engine].launch(engine === 'chromium' ? { args: ['--lang=de-DE'], env: deUmgebung }
        : engine === 'firefox' ? { env: deUmgebung, firefoxUserPrefs: { 'intl.locale.requested': 'de-DE', 'intl.accept_languages': 'de-DE, de', 'intl.regional_prefs.use_os_locales': true } }
        : { env: deUmgebung });
      const version = browser.version();
      const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'de-DE', timezoneId: 'Europe/Berlin' });
      const p = await ctx.newPage();
      const fehler = [];
      p.on('console', m => { if (m.type() === 'error') fehler.push(m.text()); });
      p.on('pageerror', e => fehler.push(String(e)));

      // Öffentliche Seiten in allen Breiten, mit Klickwegen
      for (const [breite, w, h] of BREITEN) {
        await p.setViewportSize({ width: w, height: h });
        await pruefeSeite(p, fehler, engine, breite, 'start', basis + '/');
        notiere(engine, breite, 'start', w < 760 ? 'Menü-Knopf sichtbar, Liste versteckt' : 'Navigation sichtbar',
          w < 760 ? (await p.isVisible('.menue summary') && !await p.isVisible('.nav-gross a')) : await p.isVisible('.nav-gross a'));
        // Programm: Raster ab 760 px, Liste darunter
        const raster = await p.isVisible('.gantt-wrap');
        const liste = await p.isVisible('.ws-mobil');
        notiere(engine, breite, 'start', w >= 760 ? 'Workshop-Block als Zeitraster' : 'Workshop-Block als Liste', w >= 760 ? (raster && !liste) : (liste && !raster));
        if (w >= 760) {
          const balken = await p.evaluate(() => [...document.querySelectorAll('.gb')].every(b => b.getBoundingClientRect().width > 8));
          notiere(engine, breite, 'start', 'Balken im Zeitraster gesetzt', balken);
        }
        // Geöffnete Popups als Bildschirmfoto (zum Ansehen: Vortrag mit Dozierenden, Workshop mit Terminen und Zettel)
        for (const [titel, name] of [['Sepsis', 'popup-vortrag'], ['Atemweg', 'popup-workshop']]) {
          const ausloeser = p.locator('[data-dialog]:visible', { hasText: titel }).first();
          await ausloeser.click();
          await p.waitForSelector('dialog[open]');
          await p.screenshot({ path: path.join(AUS, `${engine}-${breite}-${name}.png`) });
          await p.click('dialog[open] [data-schliessen]');
          notiere(engine, breite, 'start', `${name}: ✕ schließt`, await p.locator('dialog[open]').count() === 0);
        }
        for (const s of ['impressum', 'datenschutz', 'einloggen', 'notzugang']) {
          await pruefeSeite(p, fehler, engine, breite, s, basis + '/' + s);
        }
      }

      // ---- Anmeldung (AP-4) ----
      // Formular und Einstieg in allen Breiten: EFN-/Sonstige-Feld je Berufsgruppe, Sperre bei Überschneidung
      for (const [breite, w, h] of BREITEN) {
        await p.setViewportSize({ width: w, height: h });
        await pruefeSeite(p, fehler, engine, breite, 'anmelden', basis + '/anmelden');
        await p.selectOption('#berufsgruppe', 'ÄrztIn');
        const efnAn = await p.isVisible('#efn');
        await p.selectOption('#berufsgruppe', 'StudentIn');
        const efnAus = !await p.isVisible('#efn') && !await p.isVisible('#beruf_sonstige');
        await p.selectOption('#berufsgruppe', 'Sonstige');
        notiere(engine, breite, 'anmelden', 'EFN nur bei ÄrztIn, Freitext nur bei Sonstige', efnAn && efnAus && await p.isVisible('#beruf_sonstige'));
        await p.locator('.ws-gruppe', { hasText: 'Atemweg' }).locator('label', { hasText: '19:30–20:15' }).click();
        const gesperrt = await p.locator('.ws-gruppe', { hasText: 'Kleine' }).locator('label', { hasText: '19:30–20:00' }).locator('input').isDisabled();
        const grund = await p.locator('.ws-gruppe', { hasText: 'Kleine' }).locator('label', { hasText: '19:30–20:00' }).textContent();
        notiere(engine, breite, 'anmelden', 'Überschneidender Termin gesperrt, mit Grund', gesperrt && grund.includes('Überschneidet sich mit WS 01'));
        await pruefeSeite(p, fehler, engine, breite, 'deine-anmeldung-einstieg', basis + '/deine-anmeldung');
      }
      // Anmelden wie eine Person (Handy), Mail lesen, bestätigen
      await p.setViewportSize({ width: 390, height: 844 });
      const mailAdresse = `browser-${engine}@example.org`;
      await p.goto(basis + '/anmelden');
      const geladen = Date.now();
      await p.fill('#vorname', 'Bruno'); await p.fill('#nachname', 'Browser ' + engine); await p.fill('#email', mailAdresse);
      await p.selectOption('#berufsgruppe', 'ÄrztIn'); await p.fill('#institution', 'Klinikum Teststadt'); await p.fill('#efn', '802760012345673');
      await p.locator('.ws-gruppe', { hasText: 'Atemweg' }).locator('label', { hasText: '19:30–20:15' }).click();
      const wl = p.locator('.ws-gruppe', { hasText: 'Kleine' }).locator('label.wl-zeile', { hasText: '20:30–21:00' });
      await wl.click();
      notiere(engine, 'handy', 'anmelden', 'Warteliste umschalten', (await wl.textContent()).includes('✓ Auf der Warteliste'));
      await p.locator('label', { hasText: 'Vegetarisch' }).click();
      await p.locator('label.haken', { hasText: 'Datenschutzerklärung' }).locator('input').check();
      await p.waitForTimeout(Math.max(0, 4500 - (Date.now() - geladen)));
      await Promise.all([p.waitForNavigation({ timeout: 20000 }), p.click('button[data-pow-knopf]')]);
      notiere(engine, 'handy', 'anmelden', 'Absenden mit Sicherheitsprüfung → „Schau in dein Postfach“', (await p.textContent('body')).includes('Schau in dein Postfach'));
      await pruefeSeite(p, fehler, engine, 'handy', 'anmelden-danke', null);
      let mailText = '';
      for (let i = 0; i < 40 && !mailText; i++) {
        for (const f of (fs.existsSync(vor.mails) ? fs.readdirSync(vor.mails) : []).sort().reverse()) {
          const t = fs.readFileSync(path.join(vor.mails, f), 'utf8').replace(/=\r?\n/g, '').replace(/=([0-9A-F]{2})/g, (_, x) => String.fromCharCode(parseInt(x, 16)));
          if (t.includes(`To: <${mailAdresse}>`)) { mailText = t; break; }
        }
        if (!mailText) await p.waitForTimeout(150);
      }
      const linkPfad = (mailText.match(/\/link\?t=[0-9a-f]{48}/) || [''])[0];
      notiere(engine, 'handy', 'anmelden', 'Bestätigungsmail mit Link', linkPfad !== '');
      await pruefeSeite(p, fehler, engine, 'handy', 'link', basis + linkPfad);
      await Promise.all([p.waitForNavigation(), p.click('button:has-text("Anmeldung bestätigen")')]);
      notiere(engine, 'handy', 'deine-anmeldung', 'Bestätigt, Name in der Navigation', (await p.textContent('body')).includes('Anmeldung bestätigt') && (await p.textContent('.nav-gross, .menue')).includes('Bruno'));
      for (const [breite, w, h] of BREITEN) {
        await p.setViewportSize({ width: w, height: h });
        await pruefeSeite(p, fehler, engine, breite, 'deine-anmeldung', basis + '/deine-anmeldung');
        await pruefeSeite(p, fehler, engine, breite, 'deine-anmeldung-workshops', basis + '/deine-anmeldung?aendern=workshops');
        await pruefeSeite(p, fehler, engine, breite, 'deine-anmeldung-daten', basis + '/deine-anmeldung?aendern=daten');
      }
      // Workshops ändern: Termin wechseln
      await p.goto(basis + '/deine-anmeldung?aendern=workshops');
      await p.locator('.ws-gruppe', { hasText: 'Atemweg' }).locator('label', { hasText: '20:30–21:15' }).click();
      await Promise.all([p.waitForNavigation(), p.click('button:has-text("Speichern")')]);
      notiere(engine, 'desktop-breit', 'deine-anmeldung', 'Workshop-Termin gewechselt', (await p.textContent('.liste-def')).includes('20:30–21:15'));
      // Paula: offenes Angebot mit Tausch (nur ansehen und abbrechen, damit es für die nächste Engine bleibt)
      await p.goto(basis + vor.paula_links[ENGINES.indexOf(engine)]);
      await Promise.all([p.waitForNavigation(), p.click('button:has-text("Weiter")')]);
      for (const [breite, w, h] of BREITEN) {
        await p.setViewportSize({ width: w, height: h });
        await pruefeSeite(p, fehler, engine, breite, 'deine-anmeldung-angebot', basis + '/deine-anmeldung');
        notiere(engine, breite, 'deine-anmeldung-angebot', 'Angebot mit Tausch-Hinweis', await p.isVisible('.angebot .ersetzt'));
        await Promise.all([p.waitForNavigation(), p.click('.angebot button:has-text("Platz annehmen")')]);
        notiere(engine, breite, 'deine-anmeldung-angebot', 'Annehmen fragt nach (Tausch bestätigen)', await p.isVisible('.tausch-bestaetigen') && (await p.textContent('.tausch-bestaetigen')).includes('Wird storniert'));
        await pruefeSeite(p, fehler, engine, breite, 'deine-anmeldung-tausch', null);
        await p.click('.tausch-bestaetigen a:has-text("Abbrechen")');
        await p.waitForLoadState('load');
      }
      await p.setViewportSize({ width: 1440, height: 900 });
      await Promise.all([p.waitForNavigation(), p.click('.nav-gross button:has-text("Ausloggen")')]);
      notiere(engine, 'desktop-breit', 'deine-anmeldung', 'Ausloggen', (await p.textContent('body')).includes('Wie willst du rein?'));

      // Login mit Einrichtung des zweiten Faktors (Handybreite)
      await p.setViewportSize({ width: 390, height: 844 });
      await p.goto(basis + '/einloggen');
      await p.fill('#email', vor.betreiber); await p.fill('#passwort', vor.passwort);
      await p.click('form:has(input[value=passwort]) button');
      await p.waitForSelector('svg.qr:not([hidden]) path', { timeout: 5000 }).catch(() => {});
      notiere(engine, 'handy', 'einrichtung', 'QR-Code gezeichnet', await p.isVisible('svg.qr path'));
      await pruefeSeite(p, fehler, engine, 'handy', 'einrichtung', null);
      const uri = await p.getAttribute('svg.qr', 'data-qr');
      const key = b32(new URL(uri.replace('otpauth://', 'http://')).searchParams.get('secret'));
      await p.fill('#totp', totp(key, Date.now() / 1000));
      await p.click('form:has(input[value=einrichten]) button');
      await p.waitForLoadState('load');
      const codes = await p.locator('ul.codes li').allTextContents();
      notiere(engine, 'handy', 'codes', 'zehn Wiederherstellungscodes', codes.length === 10, String(codes.length));
      await pruefeSeite(p, fehler, engine, 'handy', 'codes', null);
      await p.click('a.knopf');
      await p.waitForLoadState('load');
      notiere(engine, 'handy', 'team', 'eingeloggt mit Name', (await p.textContent('body')).includes('Bea Betreiberin'));

      // Team-Seiten in allen Breiten, Reiter anklicken
      for (const [breite, w, h] of BREITEN) {
        await p.setViewportSize({ width: w, height: h });
        await pruefeSeite(p, fehler, engine, breite, 'team', basis + '/team');
        for (const [text, ziel, name] of [['Anmeldungen', '/team/anmeldungen', 'team-anmeldungen'], ['Programm', '/team/programm', 'team-programm'], ['Einstellungen', '/team/einstellungen', 'team-einstellungen'], ['Mein Konto', '/team/profil', 'team-profil'], ['Team-Konten', '/team/konten', 'team-konten']]) {
          await p.click(`.team-nav a:has-text("${text}")`);
          await p.waitForLoadState('load');
          notiere(engine, breite, name, 'Reiter führt zur Seite', new URL(p.url()).pathname === ziel, p.url());
          await pruefeSeite(p, fehler, engine, breite, name, null);
        }
      }

      // Programm: Punkt bearbeiten (Formular, Termine), Dozierende, Vorschau in allen Breiten
      for (const [breite, w, h] of BREITEN) {
        await p.setViewportSize({ width: w, height: h });
        await p.goto(basis + '/team/programm');
        await p.click('.pg-link:has-text("Atemweg")');
        await p.waitForLoadState('load');
        await pruefeSeite(p, fehler, engine, breite, 'team-programm-workshop', null);
        const vorher = await p.locator('#pg-termine tr').count();
        await p.click('#pg-termin-neu');
        await p.locator('#pg-termine tr').first().locator('[data-duplizieren]').click();
        const nachher = await p.locator('#pg-termine tr').count();
        const dup = await p.locator('#pg-termine tr').last().locator('[name="t_beginn[]"]').inputValue();
        notiere(engine, breite, 'team-programm-workshop', '+ Termin und Duplizieren (Anschluss 20:15)', nachher === vorher + 2 && dup === '20:15', `${vorher}→${nachher}, ${dup}`);
        await pruefeSeite(p, fehler, engine, breite, 'team-dozierende', basis + '/team/dozierende?id=1');
        await pruefeSeite(p, fehler, engine, breite, 'team-programm-vorschau', basis + '/team/programm/vorschau');
      }
      // Neuer Punkt ohne Uhrzeit, dann an seine Stelle ziehen (Desktop)
      await p.setViewportSize({ width: 1440, height: 900 });
      await p.goto(basis + '/team/programm?neu=vortrag');
      await p.selectOption('#pg-typ', 'pause');
      notiere(engine, 'desktop-breit', 'team-programm', 'Typwechsel blendet Felder aus', !await p.isVisible('#pg-kuerzel') && !await p.isVisible('#pg-bring'));
      await p.fill('#pg-titel', 'Pause ' + engine);
      await p.click('#pg-formular button[type=submit]');
      await p.waitForLoadState('load');
      const neuZeile = p.locator('.pg-zeile.pg-neu-punkt', { hasText: 'Pause ' + engine });
      notiere(engine, 'desktop-breit', 'team-programm', 'neuer Punkt oben hell-lila', await neuZeile.count() === 1);
      const zielZeile = p.locator('.pg-zeile', { hasText: 'Fall #1' }).first();
      if (engine === 'webkit') {
        // Playwright kann in WebKit unter Linux kein natives Ziehen (HTML5 Drag and Drop) auslösen;
        // dieselben Ereignisse werden deshalb im Browser selbst ausgelöst – die Logik in programm-team.js läuft gleich.
        await Promise.all([p.waitForNavigation(), p.evaluate(([titel]) => {
          const quelle = [...document.querySelectorAll('.pg-zeile')].find(z => z.textContent.includes(titel)).querySelector('.griff');
          const ziel = [...document.querySelectorAll('.pg-zeile')].find(z => z.textContent.includes('Fall #1'));
          const dt = new DataTransfer();
          quelle.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: dt }));
          ziel.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt }));
          ziel.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
        }, ['Pause ' + engine])]);
      } else {
        await Promise.all([p.waitForNavigation(), neuZeile.locator('.griff').dragTo(zielZeile)]);
      }
      await p.waitForLoadState('load');
      await p.screenshot({ path: path.join(AUS, `${engine}-desktop-breit-team-programm-gezogen.png`), fullPage: true });
      notiere(engine, 'desktop-breit', 'team-programm', engine === 'webkit' ? 'Ziehen schlägt Uhrzeit vor (19:15–19:45, Ereignisse simuliert)' : 'Ziehen schlägt Uhrzeit vor (19:15–19:45)', (await p.textContent('body')).includes('Neue Uhrzeit vorgeschlagen: 19:15–19:45'),
        (await p.locator('.meldung').allTextContents()).join(' | ') + ' ' + p.url());
      await p.click('.entwurf-banner summary:has-text("Verwerfen")');
      await p.click('.entwurf-banner button:has-text("Ja, verwerfen")');
      await p.waitForLoadState('load');
      notiere(engine, 'desktop-breit', 'team-programm', 'Verwerfen über den Banner', (await p.textContent('body')).includes('Verworfen'));

      // Einstellungen speichern: Fehler wird markiert, gültige Werte erscheinen auf der Startseite
      await p.setViewportSize({ width: 390, height: 844 });
      await p.goto(basis + '/team/einstellungen');
      await p.fill('#veranstaltung_ende', '17:00');
      await p.click('form.einstellungen button[type=submit]');
      await p.waitForLoadState('load');
      notiere(engine, 'handy', 'team-einstellungen', 'Fehler markiert, Eingabe bleibt', await p.isVisible('#veranstaltung_ende[aria-invalid="true"]') && await p.inputValue('#veranstaltung_ende') === '17:00');
      await pruefeSeite(p, fehler, engine, 'handy', 'team-einstellungen-fehler', null);
      await p.fill('#veranstaltung_ende', '22:00');
      await p.fill('#veranstaltung_ort', 'Ort ' + engine);
      await p.selectOption('#workshop_pflicht', 'pflicht');
      await p.click('form.einstellungen button[type=submit]');
      await p.waitForLoadState('load');
      notiere(engine, 'handy', 'team-einstellungen', 'gespeichert, Auswahl übernommen', (await p.textContent('body')).includes('Gespeichert') && await p.inputValue('#workshop_pflicht') === 'pflicht');
      await p.goto(basis + '/');
      notiere(engine, 'handy', 'start', 'Startseite zeigt neuen Ort', (await p.textContent('.eckdaten')).includes('Ort ' + engine));

      // Konto über das Formular anlegen (Auswahlfeld Rolle), Link erscheint
      await p.setViewportSize({ width: 390, height: 844 });
      await p.goto(basis + '/team/konten');
      await p.fill('#name', 'Neu ' + engine); await p.fill('#email', `neu-${engine}@example.org`);
      await p.selectOption('#rolle', 'betreiber');
      await p.click('form:has(input[value=anlegen]) button');
      await p.waitForLoadState('load');
      notiere(engine, 'handy', 'team-konten', 'Konto über Formular angelegt, Link angezeigt', (await p.textContent('body')).includes('/einrichten?t='));
      notiere(engine, 'handy', 'team-konten', 'gewählte Rolle gespeichert', await p.locator('tr', { hasText: `neu-${engine}@example.org` }).locator('td').nth(1).textContent() === 'Betreiber');

      // Einrichtungslink des Admin-Kontos öffnen (zweites Fenster), Passwort setzen
      const link = php([path.join(WURZEL, 'server/cli/konto.php'), 'link', 'anna@example.org'], vor.config).trim()
        .replace(/^https?:\/\/[^/]+/, basis);
      const ctx2 = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'de-DE', timezoneId: 'Europe/Berlin' });
      const p2 = await ctx2.newPage();
      const fehler2 = [];
      p2.on('console', m => { if (m.type() === 'error') fehler2.push(m.text()); });
      p2.on('pageerror', e => fehler2.push(String(e)));
      await pruefeSeite(p2, fehler2, engine, 'handy', 'einrichten-link', link);
      await p2.fill('#passwort', 'anna-browser-passwort'); await p2.fill('#passwort2', 'anna-browser-passwort');
      await p2.click('form button');
      await p2.waitForLoadState('load');
      notiere(engine, 'handy', 'einrichten-link', 'weiter zur Einrichtung des zweiten Faktors', await p2.isVisible('svg.qr'));
      await ctx2.close();

      // Ausloggen über den Knopf in der Navigation (Desktop) und Login mit Wiederherstellungscode
      await p.setViewportSize({ width: 1440, height: 900 });
      await p.goto(basis + '/team');
      await p.click('.nav-gross .nav-ab');
      await p.waitForLoadState('load');
      notiere(engine, 'desktop-breit', 'ausloggen', 'ausgeloggt', new URL(p.url()).pathname === '/einloggen' && (await p.textContent('body')).includes('ausgeloggt'));
      await p.fill('#email', vor.betreiber); await p.fill('#passwort', vor.passwort);
      await p.click('form:has(input[value=passwort]) button');
      await p.waitForLoadState('load');
      await p.fill('#totp', codes[0]);
      await p.click('form:has(input[value=code]) button');
      await p.waitForLoadState('load');
      notiere(engine, 'desktop-breit', 'code-login', 'Login mit Wiederherstellungscode', new URL(p.url()).pathname === '/team');

      notiere(engine, '-', '-', 'Engine ' + version, true);
      await browser.close();
    }
  } finally {
    server.kill();
  }

  const fehlerZahl = ergebnisse.filter(e => !e.ok).length;
  const zeilen = ergebnisse.map(e => `${e.ok ? 'ok    ' : 'FEHLER'} ${e.engine.padEnd(8)} ${e.breite.padEnd(15)} ${e.seite.padEnd(16)} ${e.was}${e.ok ? '' : ' – ' + e.info}`);
  const zusammen = `${ergebnisse.length - fehlerZahl} ok, ${fehlerZahl} Fehler (${ENGINES.length} Engines × ${BREITEN.length} Breiten)`;
  fs.writeFileSync(path.join(AUS, 'bericht.txt'), zeilen.join('\n') + '\n\n' + zusammen + '\n');
  console.log('\n' + zusammen + '\nBericht und Bildschirmfotos: ' + AUS);
  process.exit(fehlerZahl === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
