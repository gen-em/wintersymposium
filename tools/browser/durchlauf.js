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
 *
 * Stufen (R-12, Admin 2026-10-31; docs/betrieb.md Abschnitt 6):
 *   Stufe 3 (voll): ohne weitere Angaben – 3 Engines × 4 Breiten.
 *   Stufe 2 (gezielt): WS_STUFE=2 WS_SEITEN=team-konten,statistik – nur Chromium,
 *     Handy und breiter Desktop; volle Prüfung und Fotos nur für Seiten, deren
 *     Name mit einem der Einträge beginnt (Namen wie in bericht.txt). Die Abläufe
 *     dazwischen laufen trotzdem, weil spätere Seiten auf ihnen aufbauen.
 *   Einzeln überschreibbar: WS_ENGINES=webkit, WS_BREITEN=handy,tablet.
 *
 * Abbruch und Teilwiederholung (E-73):
 *   Bricht eine Engine ab (z. B. Zeitüberschreitung), zählt das als Fehler
 *   „abgebrochen“ mit Seite und Zeile; die übrigen Engines laufen weiter.
 *   Im Ausgabeordner steht ergebnis.json: je Engine grün, rot oder
 *   abgebrochen, mit Zahlen, Engine-Version und Code-Stand (Commit).
 *   WS_WIEDERHOLEN=<alter Ausgabeordner> fährt nur die Engines neu, die dort
 *   rot oder abgebrochen waren (gleiche Breiten und Seiten, frische
 *   Testdatenbank), übernimmt die grünen samt Fotos und schreibt einen
 *   gemeinsamen Bericht. Hat sich der Code seitdem geändert, nennt der
 *   Bericht die geänderten Dateien. Feiner als je Engine geht es nicht: die
 *   Abläufe innerhalb einer Engine bauen aufeinander auf.
 *   Zum Prüfen: WS_TEST_ABBRUCH=firefox bricht Firefox nach der ersten Seite ab.
 */
'use strict';
// Eigener Ordner mit allen drei Engines (tools/browser/einrichten.sh); die
// Sandbox gibt sonst /opt/pw-browsers vor, dort liegt nur Chromium.
if (require('fs').existsSync('/opt/ws-browsers')) {
  process.env.PLAYWRIGHT_BROWSERS_PATH = '/opt/ws-browsers';
}
const pw = require('playwright');
// Editor der Mailings (assets/editor.js, ein Editor überall)
const RM_FELD = '.feld:has(#rm-html)';
const RM_LEISTE = RM_FELD + ' .werkzeug';
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn, execFileSync } = require('child_process');

const WURZEL = path.resolve(__dirname, '..', '..');
const AUS = path.resolve(process.argv[2] || path.join(os.tmpdir(), 'ws-browser-' + Date.now()));
// Teilwiederholung (E-73): Ergebnis des alten Laufs; Engines, Breiten und Seiten kommen von dort
const ALT_ORDNER = process.env.WS_WIEDERHOLEN ? path.resolve(process.env.WS_WIEDERHOLEN) : '';
const ALT = ALT_ORDNER ? JSON.parse(fs.readFileSync(path.join(ALT_ORDNER, 'ergebnis.json'), 'utf8')) : null;
if (ALT && (process.env.WS_ENGINES || process.env.WS_BREITEN || process.env.WS_SEITEN || process.env.WS_STUFE)) {
  console.log('WS_WIEDERHOLEN: WS_ENGINES, WS_BREITEN, WS_SEITEN und WS_STUFE werden ignoriert (Werte aus dem alten Lauf).');
}
// Feste Reihenfolge: danach richten sich die Testdaten je Engine (vorbereiten.php legt drei Sätze an)
const ALLE_ENGINES = ['chromium', 'firefox', 'webkit'];
// WS_ENGINES=webkit (kommagetrennt) prüft nur diese Engines, z. B. zur Fehlersuche
const STUFE2 = ALT ? ALT.stufe === 2 : process.env.WS_STUFE === '2';
const ENGINES_GESAMT = ALT ? ALT.engines_gesamt : (process.env.WS_ENGINES || (STUFE2 ? 'chromium' : 'chromium,firefox,webkit')).split(',');
const ENGINES = ALT ? ENGINES_GESAMT.filter(e => !ALT.engines[e] || ALT.engines[e].status !== 'gruen') : ENGINES_GESAMT;
const satz = engine => ALLE_ENGINES.indexOf(engine);
const ALLE_BREITEN = [
  ['handy', 390, 844],
  ['tablet', 768, 1024],
  ['desktop-schmal', 1024, 768],
  ['desktop-breit', 1440, 900],
];
const BREITEN_WAHL = ALT ? ALT.breiten : (process.env.WS_BREITEN || (STUFE2 ? 'handy,desktop-breit' : '')).split(',').filter(Boolean);
const BREITEN = BREITEN_WAHL.length ? ALLE_BREITEN.filter(b => BREITEN_WAHL.includes(b[0])) : ALLE_BREITEN;
// Stufe 2: nur diese Seiten voll prüfen und fotografieren (Präfixe der Seitennamen)
const SEITEN = ALT ? ALT.seiten : (process.env.WS_SEITEN || '').split(',').map(x => x.trim()).filter(Boolean);
const seiteGewaehlt = name => SEITEN.length === 0 || SEITEN.some(x => name.startsWith(x));

fs.mkdirSync(AUS, { recursive: true });
const ergebnisse = [];
let zuletzt = '';
function notiere(engine, breite, seite, was, ok, info = '') {
  ergebnisse.push({ engine, breite, seite, was, ok, info });
  zuletzt = `${breite} ${seite}`;
  if (!ok) console.log(`  FEHLER ${engine} ${breite} ${seite}: ${was} ${info}`);
}

/** Code-Stand für ergebnis.json: Commit und Prüfsumme der nicht eingecheckten Änderungen. */
function codeStand() {
  const git = args => { try { return execFileSync('git', args, { cwd: WURZEL, encoding: 'utf8' }).trim(); } catch (e) { return ''; } };
  const diff = git(['diff', 'HEAD']);
  return { commit: git(['rev-parse', 'HEAD']), aenderungen: diff ? require('crypto').createHash('sha1').update(diff).digest('hex').slice(0, 12) : '' };
}

/** Dateien, die sich seit einem Code-Stand geändert haben (Teilwiederholung). */
function geaendertSeit(stand) {
  try {
    return execFileSync('git', ['diff', '--name-only', stand.commit], { cwd: WURZEL, encoding: 'utf8' }).trim().split('\n').filter(Boolean);
  } catch (e) {
    return ['(Commit ' + stand.commit.slice(0, 8) + ' nicht gefunden)'];
  }
}

/** TOTP-Code zum Geheimnis aus der otpauth-Adresse – über tools/tests/totp_code.php (dieselbe Rechnung wie die App, V-51). */
/** Seitenliste aus ROUTEN (tools/tests/seiten.php, V-52) und besuchte Pfade für die Abdeckung. */
const SEITENLISTE = JSON.parse(execFileSync('php', [path.join(WURZEL, 'tools/tests/seiten.php')], { encoding: 'utf8' }));
const besucht = new Set();

/** Mit Adresse und Passwort einloggen (erster Schritt; den zweiten Faktor macht der Aufrufer). */
async function einloggenAls(p, basis, wer) {
  await p.goto(basis + '/einloggen');
  await p.fill('#email', wer.email); await p.fill('#passwort', wer.passwort);
  await p.click('form:has(input[value=passwort]) button');
}

function totpCode(uri) {
  const geheim = new URL(uri.replace('otpauth://', 'http://')).searchParams.get('secret');
  return execFileSync('php', [path.join(WURZEL, 'tools/tests/totp_code.php'), geheim], { encoding: 'utf8' }).trim();
}

function php(args, config) {
  return execFileSync('php', args, { env: { ...process.env, WS_CONFIG: config }, encoding: 'utf8' });
}

/**
 * Wanda (AP-14) wieder auf die Warteliste ihres freien Termins setzen: Vor der
 * Nachrück-Grenze bietet ein früherer Schritt, der dort einen Platz freigibt,
 * ihr den Termin an. So entsteht der Stand nach der Grenze (Review B-3).
 */
function wandaWartet(vor) {
  php(['-r', `require '${path.join(WURZEL, 'server/bootstrap.php')}'; db_ausfuehren("UPDATE platz SET status = 'warte', angebot_bis = NULL WHERE anmeldung_id = ? AND termin_id = ?", [${vor.wanda_id}, ${vor.wanda_termin}]);`], vor.config);
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
    if (await sel.isDisabled()) continue; // gesperrt (Einstellungen): geprüft im entsperrten Zustand
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
    // Schon offen (z. B. für das Bildschirmfoto aufgeklappt): erst schließen, am Ende wieder öffnen
    const warOffen = await d.evaluate(e => e.open);
    if (warOffen) await sum.click();
    await sum.click();
    const offen = await d.evaluate(e => e.open);
    const inhaltSichtbar = await d.evaluate(e => [...e.children].some(c => c.tagName !== 'SUMMARY' && c.getBoundingClientRect().height > 0));
    const rausOffen = await p.evaluate(ueberstand);
    notiere(engine, breite, name, `Aufgeklappt „${label}“ innerhalb der Fensterbreite`, rausOffen.length === 0, rausOffen.join(', '));
    await pruefeAuswahl(d, engine, breite, name);
    await sum.click();
    const zu = await d.evaluate(e => !e.open);
    notiere(engine, breite, name, `Aufklappen „${label}“`, offen && inhaltSichtbar && zu);
    if (warOffen) await sum.click();
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
    if (auf) await pruefeAuswahl(p.locator('dialog[open]'), engine, breite, name);
    await p.keyboard.press('Escape');
    await p.waitForTimeout(100);
    const zu = await p.locator('dialog[open]').count() === 0;
    notiere(engine, breite, name, `Popup „${label}“: öffnet im Fenster, Escape schließt`, auf && imFenster && zu);
  }
}

/** Ausloggen über das Personen-Menü oben rechts (Desktop). */
async function ausloggen(p) {
  await p.click('.nav-gross .person-menue summary');
  await Promise.all([p.waitForNavigation(), p.click('.nav-gross .person-ab')]);
}

/** Am Griff ziehen und über dem Ziel loslassen (Anteil = Höhe in der Zielzeile); waehrend() prüft die Anzeige vor dem Loslassen. */
async function ziehen(p, griff, ziel, anteil, waehrend) {
  const g = await griff.boundingBox();
  const z = await ziel.boundingBox();
  await p.mouse.move(g.x + g.width / 2, g.y + g.height / 2);
  await p.mouse.down();
  await p.mouse.move(z.x + 40, z.y + z.height * anteil, { steps: 8 });
  if (waehrend) await waehrend();
  await p.mouse.up();
}

/** Seite prüfen: Status, seitliches Scrollen, Fehler; Bildschirmfoto. */
async function pruefeSeite(p, fehler, engine, breite, name, url) {
  fehler.length = 0;
  zuletzt = `${breite} ${name}`;
  if (!seiteGewaehlt(name)) {
    // Stufe 2: nicht gewählte Seite nur aufrufen (der Ablauf braucht sie), nicht prüfen
    if (url) await p.goto(url, { waitUntil: 'load' });
    return;
  }
  const antwort = url ? await p.goto(url, { waitUntil: 'load' }) : null;
  besucht.add(new URL(p.url()).pathname);
  await p.waitForTimeout(150);
  if (antwort) notiere(engine, breite, name, 'Status', antwort.status() < 400, String(antwort.status()));
  const m = await p.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth,
    // Zur Fehlersuche: was rechts übersteht, auch unsichtbare Elemente
    raus: [...document.querySelectorAll('body *')].filter(e => e.getBoundingClientRect().right > window.innerWidth + 1).slice(-3)
      .map(e => e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (e.className ? '.' + String(e.className).split(' ').join('.') : '') + ' ' + Math.round(e.getBoundingClientRect().right)) }));
  notiere(engine, breite, name, 'kein seitliches Scrollen', m.sw <= m.iw, `${m.sw} > ${m.iw} (${m.raus.join(', ')})`);
  if (m.sw > m.iw && process.env.WS_HTML) fs.writeFileSync(path.join(AUS, `${engine}-${breite}-${name}.html`), await p.content());
  // Elemente, die rechts aus dem Fenster ragen (außer in scrollbaren Tabellen)
  const raus = await p.evaluate(ueberstand);
  notiere(engine, breite, name, 'alles innerhalb der Fensterbreite', raus.length === 0, raus.join(', '));
  // Abgeschnittenes oder verdecktes Seitwärts-Scrollen in jedem Rahmen mit overflow (Tabellen, Kästen, Popups):
  // Inhalt breiter als der Rahmen. Ausnahmen: .scroll-erlaubt und bewusst gekürzter Text (text-overflow: ellipsis).
  const abgeschnitten = await p.evaluate(() => [...document.body.querySelectorAll('*')].filter(w => {
    // .sr: nur für Screenreader, absichtlich auf 1 px beschnitten
    // Eingabefelder: langer Text scrollt im Feld, das ist gewollt
    if (w.matches('input, textarea, select')) return false;
    if (!w.checkVisibility || !w.checkVisibility() || w.closest('.scroll-erlaubt') || w.closest('.sr')) return false;
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
  const server = spawn('php', ['-d', 'output_buffering=0', '-S', `127.0.0.1:${port}`, '-t', path.join(WURZEL, 'server/public'), path.join(WURZEL, 'server/public/index.php')],
    { env: { ...process.env, WS_CONFIG: vor.config }, stdio: ['ignore', fs.openSync(path.join(tmp, 'server.log'), 'a'), fs.openSync(path.join(tmp, 'server.log'), 'a')] });
  await new Promise(r => setTimeout(r, 700));

  const STAND = codeStand();
  const engineInfo = {};
  if (ENGINES.length === 0) console.log('Nichts zu wiederholen: im alten Lauf waren alle Engines grün.');
  try {
    // Eine Engine: alle Seiten und Abläufe. merk hält Browser und Seite für den Abbruch fest.
    const engineLauf = async (engine, merk) => {
      console.log(`== ${engine}`);
      // Jede Engine richtet den zweiten Faktor frisch ein.
      php([path.join(WURZEL, 'server/cli/konto.php'), 'zweitfaktor', vor.betreiber], vor.config);
      php([path.join(WURZEL, 'server/cli/konto.php'), 'zweitfaktor', vor.ada.email], vor.config);
      // Deutsche Oberfläche wie bei den Nutzenden: Datums- und Zeitfelder zeigen dann TT.MM.JJJJ und 24 h.
      // Die Sprache allein reicht nicht: Chromium und Firefox formatieren die Felder nach der Umgebung
      // des Browserprozesses (LANG), Firefox nur, wenn er die Systemeinstellung übernehmen darf.
      const deUmgebung = { ...process.env, LANG: 'de_DE.UTF-8', LANGUAGE: 'de_DE:de', LC_ALL: 'de_DE.UTF-8' };
      const browser = await pw[engine].launch(engine === 'chromium' ? { args: ['--lang=de-DE'], env: deUmgebung }
        : engine === 'firefox' ? { env: deUmgebung, firefoxUserPrefs: { 'intl.locale.requested': 'de-DE', 'intl.accept_languages': 'de-DE, de', 'intl.regional_prefs.use_os_locales': true } }
        : { env: deUmgebung });
      merk.browser = browser;
      const version = browser.version();
      merk.version = version;
      const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'de-DE', timezoneId: 'Europe/Berlin' });
      // Zwischenablage für „Kopieren“ auf der Status-Seite (AP-15); nur Chromium kennt diese Rechte
      if (engine === 'chromium') { await ctx.grantPermissions(['clipboard-read', 'clipboard-write']); }
      const p = await ctx.newPage();
      merk.p = p;
      const fehler = [];
      p.on('console', m => { if (m.type() === 'error') fehler.push(m.text()); });
      p.on('pageerror', e => fehler.push(String(e)));

      // Öffentliche Seiten in allen Breiten, mit Klickwegen
      for (const [breite, w, h] of BREITEN) {
        await p.setViewportSize({ width: w, height: h });
        // E-58: abgeschlossene Vorjahres-Veranstaltung unter ihrem Kürzel (nur Rückblick)
        await pruefeSeite(p, fehler, engine, breite, 'archiv', basis + '/wintersymposium2026');
        // Prüfschalter für Abbruch und Teilwiederholung (E-73): WS_TEST_ABBRUCH=firefox
        if (process.env.WS_TEST_ABBRUCH === engine) await p.waitForSelector('#gibt-es-nicht', { timeout: 500 });
        notiere(engine, breite, 'archiv', 'Rückblick ohne Anmeldung', (await p.textContent('body')).includes('Diese Veranstaltung ist vorbei') && !await p.isVisible('.held-knoepfe'));
        // Fortbildungsabend (Vortragsabend ohne Workshops und Essen, Beispieldaten 2026-10-31)
        await pruefeSeite(p, fehler, engine, breite, 'archiv-abend', basis + '/fortbildungsabend-notfallmedizin');
        notiere(engine, breite, 'archiv-abend', 'Abend: Rückblick mit Vorträgen', (await p.textContent('body')).includes('Hyperkaliämie') && (await p.textContent('body')).includes('Diese Veranstaltung ist vorbei'));
        await pruefeSeite(p, fehler, engine, breite, 'start', basis + '/');
        notiere(engine, breite, 'start', w < 760 ? 'Menü-Knopf sichtbar, Liste versteckt' : 'Navigation sichtbar',
          w < 760 ? (await p.isVisible('.menue summary') && !await p.isVisible('.nav-gross a')) : await p.isVisible('.nav-gross a'));
        // Programm: Raster ab 760 px, Liste darunter
        const raster = await p.isVisible('.gantt-wrap');
        const liste = await p.isVisible('.ws-mobil');
        notiere(engine, breite, 'start', w >= 760 ? 'Workshop-Block als Zeitraster' : 'Workshop-Block als Liste', w >= 760 ? (raster && !liste) : (liste && !raster));
        notiere(engine, breite, 'start', 'Fallbesprechung im Block sichtbar', w >= 760 ? await p.isVisible('.gb.fb') : await p.isVisible('.ws-mobil li.im-block.fb'));
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
          if (name === 'popup-workshop') notiere(engine, breite, 'start', 'Termin mit abweichenden Dozierenden im Popup', ((await p.textContent('dialog[open]')) || '').includes('mit Dr. Erika Muster'));
          await p.click('dialog[open] [data-schliessen]');
          notiere(engine, breite, 'start', `${name}: ✕ schließt`, await p.locator('dialog[open]').count() === 0);
        }
        // Öffentliche Seiten aus der Seitenliste, die kein eigener Ablauf unten prüft
        for (const pfad of SEITENLISTE.oeffentlich.filter(x => !['/', '/anmelden', '/deine-anmeldung', '/konto', '/konto/neu', '/konto/passwort-vergessen', '/newsletter'].includes(x))) {
          await pruefeSeite(p, fehler, engine, breite, pfad.slice(1).replace(/\//g, '-'), basis + pfad);
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
        // AP-19 (O-50): Interessen bei „Allgäu Akut“ folgen der Berufsgruppe
        const iw = async () => (await p.locator('.nl-interessen input:checked').evaluateAll(xs => xs.map(x => x.value))).join(',');
        await p.selectOption('#berufsgruppe', 'NotfallsanitäterIn');
        const iPrae = await iw();
        await p.selectOption('#berufsgruppe', 'Pflege/MFA');
        const iKlin = await iw();
        await p.selectOption('#berufsgruppe', 'ÄrztIn');
        notiere(engine, breite, 'anmelden', 'Zwei Newsletter-Häkchen, Interessen nach Berufsgruppe vorausgewählt', await p.isVisible('input[name=newsletter]') && await p.isVisible('input[name=nl_dachmarke]') && iPrae === 'praeklinik' && iKlin === 'klinik' && await iw() === 'klinik,praeklinik', iPrae + '/' + iKlin);
        await p.selectOption('#berufsgruppe', 'Sonstige');
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
      const linkPfad = (mailText.match(/\/link\?t=[A-Za-z0-9_-]{43}/) || [''])[0];
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
      await p.goto(basis + vor.paula_links[satz(engine)]);
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
      await ausloggen(p);
      notiere(engine, 'desktop-breit', 'deine-anmeldung', 'Ausloggen über das Personen-Menü', (await p.textContent('body')).includes('Wie willst du rein?'));

      // AP-14 (B-3): Wanda steht auf der Warteliste eines Termins mit freien Plätzen
      // Vor der Nachrück-Grenze bietet ein früherer Schritt (frei gewordener Platz) Wanda den Termin an;
      // für die Prüfung den Stand nach der Grenze herstellen: wieder auf der Warteliste
      wandaWartet(vor);
      await p.goto(basis + vor.wanda_links[satz(engine)]);
      await Promise.all([p.waitForNavigation(), p.click('button:has-text("Weiter")')]);
      for (const [breite, w, h] of BREITEN) {
        await p.setViewportSize({ width: w, height: h });
        await pruefeSeite(p, fehler, engine, breite, 'deine-anmeldung-warte', basis + '/deine-anmeldung?aendern=workshops');
        const wl = p.locator(`input[name="wl[]"][value="${vor.wanda_termin}"]`);
        notiere(engine, breite, 'deine-anmeldung-warte', 'Warte-Eintrag bei freiem Termin sichtbar, angekreuzt, mit Hinweis',
          await wl.count() === 1 && await wl.isChecked() && await p.locator('label.wl-zeile.an').isVisible() && (await p.textContent('#workshops')).includes('fest wählen oder auf der Warteliste bleiben'));
      }
      await p.setViewportSize({ width: 1440, height: 900 });
      await Promise.all([p.waitForNavigation(), p.click('form:has(input[value=workshops]) button[type=submit]')]);
      const meldungWarte = (await p.locator('.meldung').allTextContents()).join(' | ');
      await p.goto(basis + '/deine-anmeldung?aendern=workshops');
      notiere(engine, 'desktop-breit', 'deine-anmeldung-warte', 'Speichern ohne Änderung: Warte-Eintrag bleibt', await p.locator(`input[name="wl[]"][value="${vor.wanda_termin}"]`).isChecked(), meldungWarte);
      await ausloggen(p);
      // AP-14 (B-5): Storno mit Konto – danach weiter eingeloggt
      await p.goto(basis + '/deine-anmeldung');
      await p.fill('#zg-lmail', vor.storno[satz(engine)]); await p.fill('#zg-pw', vor.passwort);
      await Promise.all([p.waitForNavigation(), p.click('form:has(input[value=einloggen]) button[type=submit]')]);
      await p.screenshot({ path: path.join(AUS, `${engine}-desktop-breit-storno-login.png`) });
      const stornoKnopf = p.locator('button[aria-haspopup=dialog]:has-text("Anmeldung stornieren")');
      if (await stornoKnopf.count() === 0) {
        notiere(engine, 'desktop-breit', 'storno', 'Storno-Knopf nach dem Login da', false, p.url() + ' ' + (await p.textContent('main')).replace(/\s+/g, ' ').slice(0, 300));
      } else {
      await stornoKnopf.click();
      await p.waitForTimeout(300);
      notiere(engine, 'desktop-breit', 'storno', 'Storno fragt im Dialog (rot)', await p.isVisible('dialog[open] button:has-text("Ja, stornieren")'));
      await p.screenshot({ path: path.join(AUS, `${engine}-desktop-breit-storno-frage.png`) });
      await Promise.all([p.waitForNavigation(), p.click('dialog[open] button:has-text("Ja, stornieren")')]);
      await p.screenshot({ path: path.join(AUS, `${engine}-desktop-breit-storno-danach.png`) });
      const nachStorno = await p.textContent('body');
      notiere(engine, 'desktop-breit', 'storno', 'Storno: Meldung, weiter eingeloggt, „Jetzt anmelden“',
        nachStorno.includes('ist storniert') && nachStorno.includes('Eingeloggt als Sina Storno') && await p.locator('.person-menue').count() > 0 && await p.locator('a[href="/anmelden"]').count() > 0, new URL(p.url()).pathname);
      }
      await ausloggen(p);

      // ---- Konten für Teilnehmende (AP-4b) ----
      for (const [breite, w, h] of BREITEN) {
        await p.setViewportSize({ width: w, height: h });
        await pruefeSeite(p, fehler, engine, breite, 'konto-neu', basis + '/konto/neu');
        await pruefeSeite(p, fehler, engine, breite, 'konto-passwort-vergessen', basis + '/konto/passwort-vergessen');
      }
      // Lea loggt sich im Kasten auf „Deine Anmeldung“ ein
      await p.setViewportSize({ width: 390, height: 844 });
      await p.goto(basis + '/deine-anmeldung');
      await p.fill('#zg-lmail', vor.lea.email); await p.fill('#zg-pw', vor.lea.passwort);
      await Promise.all([p.waitForNavigation(), p.click('form:has(input[value=einloggen]) button[type=submit]')]);
      notiere(engine, 'handy', 'konto', 'Login im Kasten → Deine Anmeldung', (await p.textContent('body')).includes('Hallo Lea.') && (await p.textContent('body')).includes('Mit Konto eingeloggt'));
      // Eigene Plätze im Programm (O-41, Variante B): Handy-Liste und Zeitraster am Desktop
      for (const [breite, w, h] of BREITEN) {
        await p.setViewportSize({ width: w, height: h });
        await pruefeSeite(p, fehler, engine, breite, 'start-meine-plaetze', basis + '/');
        const raster = await p.isVisible('.gantt-wrap');
        notiere(engine, breite, 'start-meine-plaetze', 'eigene Termine markiert (Schild „Dein Platz“, Hinweis)',
          (raster ? await p.locator('.gb.meins .schild.sicher').count() === 2 && await p.isVisible('.gz.meins .du-zeile') : await p.locator('.ws-zeile.meins .schild.sicher').count() === 2) && await p.isVisible('.du-hinweis'));
        if (raster) {
          await p.locator('.gantt-wrap').scrollIntoViewIfNeeded();
          await p.screenshot({ path: path.join(AUS, `${engine}-${breite}-start-meine-plaetze-raster.png`) });
        }
      }
      for (const [breite, w, h] of BREITEN) {
        await p.setViewportSize({ width: w, height: h });
        await pruefeSeite(p, fehler, engine, breite, 'konto', basis + '/konto');
        await pruefeSeite(p, fehler, engine, breite, 'konto-profil', basis + '/konto?aendern=profil');
        await pruefeSeite(p, fehler, engine, breite, 'deine-anmeldung-konto', basis + '/deine-anmeldung');
      }
      // E-58: Rückblick unter „Mein Konto“ – Vorjahr mit Zertifikat
      await p.setViewportSize({ width: 390, height: 844 });
      await p.goto(basis + '/konto');
      notiere(engine, 'handy', 'konto', 'Deine Veranstaltungen: drei mit Zertifikat', await p.isVisible('#veranstaltungen') && (await p.textContent('#veranstaltungen')).includes('teilgenommen') && await p.locator('#veranstaltungen a[href^="/konto/zertifikat"]').count() === 3);
      {
        const [dl] = await Promise.all([p.waitForEvent('download'), p.click('#veranstaltungen a[href^="/konto/zertifikat"] >> nth=1')]);
        const ziel = path.join(AUS, `${engine}-zertifikat-vorjahr.pdf`);
        await dl.saveAs(ziel);
        notiere(engine, 'handy', 'konto', 'Zertifikat des Vorjahrs heruntergeladen', fs.readFileSync(ziel).subarray(0, 5).toString('latin1') === '%PDF-');
      }
      // AP-10: Leas Zertifikat ist im Beispieldatensatz freigeschaltet – Knopf und Download
      await p.setViewportSize({ width: 1440, height: 900 });
      await p.goto(basis + '/deine-anmeldung');
      notiere(engine, 'desktop-breit', 'deine-anmeldung-konto', 'Zertifikat: Knopf zum Herunterladen', await p.isVisible('a[href="/deine-anmeldung/zertifikat"]'));
      {
        const [dl] = await Promise.all([p.waitForEvent('download'), p.click('a[href="/deine-anmeldung/zertifikat"]')]);
        const ziel = path.join(AUS, `${engine}-zertifikat-lea.pdf`);
        await dl.saveAs(ziel);
        notiere(engine, 'desktop-breit', 'deine-anmeldung-konto', 'Zertifikat als PDF heruntergeladen', fs.readFileSync(ziel).subarray(0, 5).toString('latin1') === '%PDF-' && dl.suggestedFilename().startsWith('Teilnahmebescheinigung-Beispiel'), dl.suggestedFilename());
      }
      await p.goto(basis + '/konto');
      await p.click('.nav-gross .person-menue summary');
      notiere(engine, 'desktop-breit', 'konto', '„Mein Konto“ im Personen-Menü', await p.isVisible('.nav-gross .person-liste a[href="/konto"]'));
      await p.screenshot({ path: path.join(AUS, `${engine}-desktop-breit-personen-menue.png`) });
      await p.click('.nav-gross .person-menue summary');
      await ausloggen(p);
      notiere(engine, 'desktop-breit', 'konto', 'Ausloggen', (await p.textContent('body')).includes('Wie willst du rein?'));

      // ---- Passkeys (E-55): Chromium mit simuliertem Gerät (WebAuthn über CDP); Firefox/WebKit: Knopf sichtbar ----
      // Passkeys brauchen eine Domain: localhost statt 127.0.0.1
      const lokal = basis.replace('127.0.0.1', 'localhost');
      await p.setViewportSize({ width: 1440, height: 900 });
      await p.goto(lokal + '/einloggen');
      notiere(engine, 'desktop-breit', 'passkey', 'Knopf „Mit Passkey einloggen“ sichtbar', await p.isVisible('[data-passkey-los]'));
      if (engine === 'chromium') {
        const cdp = await ctx.newCDPSession(p);
        await cdp.send('WebAuthn.enable');
        const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });
        await p.fill('#email', vor.lea.email); await p.fill('#passwort', vor.lea.passwort);
        await Promise.all([p.waitForNavigation(), p.click('form:has(input[value=passwort]) button')]);
        await p.goto(lokal + '/konto');
        await p.click('[data-passkey-anlage] > summary');
        await p.fill('#pk-bez', 'Browser-Test'); await p.fill('#pk-pw', vor.lea.passwort);
        await Promise.all([p.waitForNavigation({ timeout: 10000 }).catch(() => {}), p.click('[data-passkey-anlegen] button[type=submit]')]);
        await p.waitForLoadState('load');
        notiere(engine, 'desktop-breit', 'passkey', 'Passkey anlegen (simuliertes Gerät mit Fingerabdruck)', ((await p.textContent('#passkeys')) || '').includes('Browser-Test'), ((await p.textContent('[data-passkey-meldung]').catch(() => '')) || '') + ' ' + p.url());
        await p.locator('#passkeys').screenshot({ path: path.join(AUS, `${engine}-desktop-breit-passkeys.png`) });
        await ausloggen(p);
        await p.goto(lokal + '/einloggen');
        await Promise.all([p.waitForNavigation({ timeout: 10000 }).catch(() => {}), p.click('[data-passkey-los]')]);
        await p.waitForLoadState('load');
        notiere(engine, 'desktop-breit', 'passkey', 'Einloggen mit Passkey ohne Passwort', new URL(p.url()).pathname === '/deine-anmeldung' && (await p.textContent('body')).includes('Hallo Lea.'), p.url());
        await ausloggen(p);
        await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
      }

      // Login mit Einrichtung des zweiten Faktors (Handybreite)
      await p.setViewportSize({ width: 390, height: 844 });
      await einloggenAls(p, basis, { email: vor.betreiber, passwort: vor.passwort });
      await p.waitForSelector('svg.qr:not([hidden]) path', { timeout: 5000 }).catch(() => {});
      notiere(engine, 'handy', 'einrichtung', 'QR-Code gezeichnet', await p.isVisible('svg.qr path'));
      await pruefeSeite(p, fehler, engine, 'handy', 'einrichtung', null);
      const uri = await p.getAttribute('svg.qr', 'data-qr');
      await p.fill('#totp', totpCode(uri));
      await p.click('form:has(input[value=einrichten]) button');
      await p.waitForLoadState('load');
      const codes = await p.locator('ul.codes li').allTextContents();
      notiere(engine, 'handy', 'codes', 'zehn Wiederherstellungscodes', codes.length === 10, String(codes.length));
      await pruefeSeite(p, fehler, engine, 'handy', 'codes', null);
      await p.click('a.knopf');
      await p.waitForLoadState('load');
      notiere(engine, 'handy', 'team', 'eingeloggt mit Name', (await p.textContent('body')).includes('Bea Betreiberin'));
      // Rückmeldung 2026-10-31: Startseite zeigt eingeloggt das Personen-Menü – direkt und über das Logo
      await p.goto(basis + '/');
      notiere(engine, 'handy', 'start-eingeloggt', 'Startseite direkt: eingeloggt', await p.locator('.person-menue').count() > 0 && await p.locator('a.person-knopf[href="/einloggen"]').count() === 0);
      await p.goto(basis + '/team');
      await Promise.all([p.waitForNavigation(), p.click('header.kopf a[aria-label="Zur Startseite"]')]);
      notiere(engine, 'handy', 'start-eingeloggt', 'Startseite über Logo: eingeloggt', await p.locator('.person-menue').count() > 0 && await p.locator('a.person-knopf[href="/einloggen"]').count() === 0);
      await p.goBack(); await p.goForward();
      notiere(engine, 'handy', 'start-eingeloggt', 'Startseite nach Zurück/Vor: eingeloggt', await p.locator('.person-menue').count() > 0 && await p.locator('a.person-knopf[href="/einloggen"]').count() === 0);

      // Team-Seiten in allen Breiten, Reiter anklicken
      for (const [breite, w, h] of BREITEN) {
        await p.setViewportSize({ width: w, height: h });
        await pruefeSeite(p, fehler, engine, breite, 'team', basis + '/team');
        // Team-Navigation (Variante A): Bereiche und angehängte Unterseiten
        for (const [wahl, ziel, name] of [['.team-nav a:has-text("Anmeldungen")', '/team/personen', 'team-personen'], ['.team-unter a:has-text("Workshops")', '/team/workshops', 'team-workshops'], ['.team-unter a:has-text("Teilnahme")', '/team/teilnahme', 'team-teilnahme'],
          ['.team-nav a:has-text("Programm")', '/team/programm', 'team-programm'], ['.team-unter a:has-text("Dozierende")', '/team/dozierende', 'team-dozierende'],
          ['.team-nav a:has-text("Mailings")', '/team/mailings', 'team-mailings'],
          ['.team-nav a:has-text("Statistik")', '/team/statistik', 'team-statistik'],
          ['.team-nav a:has-text("Verwaltung")', '/team/einstellungen', 'team-einstellungen'], ['.team-unter a:has-text("Startseite")', '/team/startseite', 'team-startseite'], ['.team-unter a:has-text("Protokoll")', '/team/protokoll', 'team-protokoll'],
          // AP-12: Reiter Betrieb (nur BetreiberInnen)
          ['.team-nav a:has-text("Betrieb")', '/team/status', 'team-status'], ['.team-unter a:has-text("Veranstaltungen")', '/team/veranstaltungen', 'team-veranstaltungen'], ['.team-unter a:has-text("Team-Konten")', '/team/konten', 'team-konten'], ['.team-unter a:has-text("Newsletter")', '/team/newsletter', 'team-newsletter'], ['.team-unter a:has-text("Texte")', '/team/texte', 'team-texte'], ['.team-unter a:has-text("Betrieb")', '/team/daten', 'team-daten']]) {
          await p.click(wahl);
          await p.waitForLoadState('load');
          notiere(engine, breite, name, 'Reiter führt zur Seite', new URL(p.url()).pathname === ziel, p.url());
          await pruefeSeite(p, fehler, engine, breite, name, null);
        }
        // AP-17 Unterpunkt 6: Komponentenblatt (Regressionstest für das CSS); seit der Rückmeldung nach AP-19 nur über die Adresse
        await pruefeSeite(p, fehler, engine, breite, 'team-komponenten', basis + '/team/komponenten');
        // AP-12: Wechsler der Veranstaltung aufklappen
        await pruefeSeite(p, fehler, engine, breite, 'team-wechsler', basis + '/team/personen');
        await p.click('.va-wechsler > summary');
        notiere(engine, breite, 'team-wechsler', 'Wechsler klappt auf, mit „Neue Veranstaltung“', await p.isVisible('.va-menue') && await p.locator('.va-menue .va-eintrag').count() >= 3 && await p.isVisible('.va-aktionen a[href="/team/veranstaltungen#neu"]'));
        await p.screenshot({ path: path.join(AUS, `${engine}-${breite}-team-wechsler-offen.png`) });
        await Promise.all([p.waitForNavigation(), p.click('.va-menue .va-eintrag:not([aria-current]) >> nth=0')]);
        notiere(engine, breite, 'team-wechsler', 'Eintrag wechselt die Veranstaltung', new URL(p.url()).searchParams.has('va') && await p.isVisible('.va-hinweis'));
        await p.goto(basis + '/team/personen?va=' + vor.aktuell_id);
        // Personen-Menü (Entwurf A): mit Icons; Team-Bereich grün oben (Rückmeldung 2026-10-31)
        if (w >= 760) {
          await p.click('.nav-gross .person-menue > summary');
          notiere(engine, breite, 'person-menue', 'Personen-Menü: Kopf, Punkte mit Icons, Team-Bereich grün oben', await p.isVisible('.nav-gross .person-liste .person-kopf') && await p.locator('.nav-gross .person-liste a.person-punkt svg').count() >= 2 && await p.locator('.nav-gross .person-liste a.person-punkt.team[href="/team"]').count() === 1 && await p.evaluate(() => getComputedStyle(document.querySelector('.nav-gross .person-liste a.team .person-titel')).color) === 'rgb(181, 189, 0)');
          await p.screenshot({ path: path.join(AUS, `${engine}-${breite}-person-menue-offen.png`) });
          await p.click('.nav-gross .person-menue > summary');
          notiere(engine, breite, 'person-menue', 'Team-Bereich grün in der Leiste', await p.evaluate(() => getComputedStyle(document.querySelector('.nav-gross a.nav-team')).color) === 'rgb(181, 189, 0)');
        }
        // Stehende Kopfleiste: Desktop bleibt oben, Handy blendet aus und zeigt „Menü“
        await p.goto(basis + '/');
        await p.evaluate(() => window.scrollTo(0, 900));
        await p.waitForTimeout(450);
        if (w >= 760) {
          notiere(engine, breite, 'kopf-scroll', 'Kopfleiste bleibt beim Scrollen sichtbar', await p.evaluate(() => Math.round(document.querySelector('header.kopf').getBoundingClientRect().top) === 0) && await p.isVisible('.nav-gross'));
          await p.screenshot({ path: path.join(AUS, `${engine}-${breite}-kopf-scroll.png`) });
        } else {
          notiere(engine, breite, 'kopf-scroll', 'Handy: Leiste weg, „Menü“-Knopf da', await p.evaluate(() => document.querySelector('header.kopf').getBoundingClientRect().bottom <= 1) && await p.isVisible('.kopf-menue-knopf'));
          await p.screenshot({ path: path.join(AUS, `${engine}-${breite}-kopf-scroll.png`) });
          await p.click('.kopf-menue-knopf');
          await p.waitForTimeout(300);
          notiere(engine, breite, 'kopf-scroll', '„Menü“-Knopf öffnet das Menü', await p.evaluate(() => document.querySelector('details.menue').open) && await p.isVisible('.menue .nav-liste'));
          await p.screenshot({ path: path.join(AUS, `${engine}-${breite}-kopf-scroll-menue.png`) });
          await p.evaluate(() => { document.querySelector('details.menue').open = false; });
        }
        // Mein Konto des Team-Kontos (ein Konto für alles, E-54) und Zwei-Faktor
        await pruefeSeite(p, fehler, engine, breite, 'team-mein-konto', basis + '/konto');
        notiere(engine, breite, 'team-mein-konto', 'Mein Konto nach Entwurf 7: Rolle, Einloggen und Sicherheit', await p.isVisible('.rechte-liste') && await p.isVisible('.pflicht-balken') && await p.isVisible('.sicherheit'));
        notiere(engine, breite, 'team-mein-konto', 'Team-Konto mit Zwei-Faktor, ohne Löschen', (await p.textContent('body')).includes('Team-Konto') && await p.locator('a[href="/konto/zweitfaktor"]').count() >= 1);
        await pruefeSeite(p, fehler, engine, breite, 'team-zweitfaktor', basis + '/konto/zweitfaktor');
      }

      // AP-5: ausgewählte Person, voller Termin, Person anlegen
      for (const [breite, w, h] of BREITEN) {
        await p.setViewportSize({ width: w, height: h });
        await pruefeSeite(p, fehler, engine, breite, 'team-person', basis + '/team/personen?a=' + vor.lea_id);
        await pruefeSeite(p, fehler, engine, breite, 'team-person-neu', basis + '/team/personen?neu=1');
        // AP-14 (B-2): Warte-Eintrag im Team-Modus als vorangekreuztes Kästchen
        wandaWartet(vor);
        await pruefeSeite(p, fehler, engine, breite, 'team-person-warte', basis + '/team/personen?a=' + vor.wanda_id);
        const twl = p.locator(`input[name="wl[]"][value="${vor.wanda_termin}"]`);
        notiere(engine, breite, 'team-person-warte', 'Team: Warte-Eintrag sichtbar und angekreuzt', await twl.count() === 1 && await twl.isChecked() && await p.locator('label.wl-zeile.an').isVisible());
        await pruefeSeite(p, fehler, engine, breite, 'team-termin', basis + '/team/workshops?t=' + vor.voll_termin);
        // AP-6: Mailing-Entwurf (Editor, Vorschau) und gesendetes Mailing (Archiv)
        await pruefeSeite(p, fehler, engine, breite, 'team-mailing-entwurf', basis + '/team/mailings?id=' + vor.mailing_entwurf);
        await pruefeSeite(p, fehler, engine, breite, 'team-mailing-gesendet', basis + '/team/mailings?id=' + vor.mailing_gesendet);
        // AP-7: öffentliche Newsletter-Seiten
        await pruefeSeite(p, fehler, engine, breite, 'newsletter', basis + '/newsletter');
        await pruefeSeite(p, fehler, engine, breite, 'newsletter-verwalten', basis + vor.nl_verwalten);
      }
      // Export (O-35): Knöpfe CSV, Excel, PDF; Dateien herunterladen und prüfen
      await p.setViewportSize({ width: 1440, height: 900 });
      await p.goto(basis + '/team/statistik');
      notiere(engine, 'desktop-breit', 'team-statistik', 'Export: je Liste CSV, Excel, PDF (Newsletter ohne PDF)',
        await p.locator('.export-knoepfe a[href*="format=xlsx"]').count() === 7 && await p.locator('.export-knoepfe a[href*="format=pdf"]').count() === 6);
      for (const [art, endung, anfang] of [['teilnehmende', 'pdf', '%PDF-'], ['termine', 'pdf', '%PDF-'], ['wartelisten', 'pdf', '%PDF-'], ['essen', 'pdf', '%PDF-'], ['statistik', 'pdf', '%PDF-'], ['cme', 'xlsx', 'PK'], ['teilnehmende', 'xlsx', 'PK']]) {
        const [dl] = await Promise.all([p.waitForEvent('download'), p.click(`.export-knoepfe a[href*="art=${art}"][href*="format=${endung}"]`)]);
        const ziel = path.join(AUS, `${engine}-export-${art}.${endung}`);
        await dl.saveAs(ziel);
        const kopf = fs.readFileSync(ziel).subarray(0, 5).toString('latin1');
        notiere(engine, 'desktop-breit', 'team-statistik', `Export ${art} als ${endung}`, kopf.startsWith(anfang) && dl.suggestedFilename().endsWith('.' + endung), dl.suggestedFilename());
      }
      await p.goto(basis + '/team/workshops?t=' + vor.voll_termin);
      {
        const [dl] = await Promise.all([p.waitForEvent('download'), p.click('.export-knoepfe a[href*="format=pdf"]')]);
        const ziel = path.join(AUS, `${engine}-export-termin.pdf`);
        await dl.saveAs(ziel);
        notiere(engine, 'desktop-breit', 'team-termin', 'Liste des Termins als PDF', fs.readFileSync(ziel).subarray(0, 5).toString('latin1') === '%PDF-');
      }
      // AP-10: Teilnahme – nicht erschienen eintragen, Listen und Vorschau als PDF
      await p.setViewportSize({ width: 390, height: 844 });
      await p.goto(basis + '/team/teilnahme');
      notiere(engine, 'handy', 'team-teilnahme', 'Paula: Workshop „nicht erschienen“ aus dem Datensatz', (await p.textContent('#a' + vor.paula_id)).includes('nicht erschienen'));
      await Promise.all([p.waitForNavigation(), p.click('#a' + vor.lea_id + ' td[data-label="Symposium"] button')]);
      notiere(engine, 'handy', 'team-teilnahme', 'Lea als nicht erschienen eintragen (Handy)', (await p.textContent('#a' + vor.lea_id)).includes('keins (nicht erschienen)'));
      await pruefeSeite(p, fehler, engine, 'handy', 'team-teilnahme-fehlt', null);
      await Promise.all([p.waitForNavigation(), p.click('#a' + vor.lea_id + ' td[data-label="Symposium"] button')]);
      notiere(engine, 'handy', 'team-teilnahme', 'Doch da', (await p.textContent('#a' + vor.lea_id)).includes('freigeschaltet'));
      await p.setViewportSize({ width: 1440, height: 900 });
      await p.goto(basis + '/team/teilnahme');
      for (const [liste, sort] of [['alle', 'vorname'], ['aerzte', 'nachname']]) {
        await p.selectOption('#liste', liste);
        await p.selectOption('#sort', sort);
        const [dl] = await Promise.all([p.waitForEvent('download'), p.click('form[action="/team/teilnahme/liste"] button')]);
        const ziel = path.join(AUS, `${engine}-unterschriften-${liste}.pdf`);
        await dl.saveAs(ziel);
        notiere(engine, 'desktop-breit', 'team-teilnahme', `Unterschriftenliste ${liste} als PDF`, fs.readFileSync(ziel).subarray(0, 5).toString('latin1') === '%PDF-');
      }
      notiere(engine, 'desktop-breit', 'team-teilnahme', 'Unterschrift sichtbar, Freischalten erst ab dem Veranstaltungstag', await p.isVisible('img.unterschrift-bild') && (await p.textContent('#zertifikat')).includes('ab dem Veranstaltungstag'));
      {
        const r = await p.request.get(basis + '/team/teilnahme/zertifikat?beispiel=1');
        notiere(engine, 'desktop-breit', 'team-teilnahme', 'Vorschau mit Beispiel (PDF)', r.ok() && (await r.body()).subarray(0, 5).toString('latin1') === '%PDF-');
      }
      // Mailings: Empfängerart Newsletter blendet die Listen ein
      await p.goto(basis + '/team/mailings?neu=1');
      notiere(engine, 'desktop-breit', 'team-mailing-neu', 'Listen erst bei „Newsletter-Listen“', !await p.isVisible('.rm-listen'));
      await p.check('input[name=art][value=newsletter]');
      await p.check('.rm-listen input[name="listen[]"] >> nth=0');
      await p.waitForTimeout(500);
      notiere(engine, 'desktop-breit', 'team-mailing-neu', 'Newsletter: Listen sichtbar, Zahl der Abonnierenden', await p.isVisible('.rm-listen') && !await p.isVisible('#auswahl') && Number(await p.textContent('#rm-zahl')) >= 1, await p.textContent('#rm-zahl'));
      // AP-19: Zielgruppe nur mit „Allgäu Akut“; Klinik zählt nur Nina
      await p.uncheck('.rm-listen input[name="listen[]"] >> nth=0');
      const zielVorher = await p.isVisible('#nl_ziel');
      await p.check('.rm-listen input[data-dachmarke]');
      await p.selectOption('#nl_ziel', 'klinik');
      await p.waitForTimeout(500);
      notiere(engine, 'desktop-breit', 'team-mailing-neu', 'Zielgruppe erst mit „Allgäu Akut“, Zahl je Zielgruppe', !zielVorher && await p.isVisible('#nl_ziel') && await p.textContent('#rm-zahl') === '1', await p.textContent('#rm-zahl'));
      // Mailings: Editor bedienen (Desktop)
      await p.setViewportSize({ width: 1440, height: 900 });
      await p.goto(basis + '/team/mailings?id=' + vor.mailing_entwurf);
      notiere(engine, 'desktop-breit', 'team-mailing-entwurf', 'Schreibfeld statt HTML-Feld', await p.isVisible('#rm-html-feld') && !await p.isVisible('#rm-html') && await p.isVisible(RM_LEISTE));
      const vorschau = p.frameLocator('iframe.ml-vorschau-frame');
      notiere(engine, 'desktop-breit', 'team-mailing-entwurf', 'Vorschau mit Name und Bild', (await vorschau.locator('body').textContent({ timeout: 5000 }).catch(() => '')).includes('Hallo Lea') && await vorschau.locator('img[alt=Lageplan]').evaluate(i => i.complete && i.naturalWidth > 0).catch(() => false));
      await p.click('#rm-html-feld p:last-child');
      await p.keyboard.press('End');
      await p.click(RM_LEISTE + ' [data-platzhalter="{nachname}"]');
      notiere(engine, 'desktop-breit', 'team-mailing-entwurf', 'Platzhalter eingefügt', (await p.textContent('#rm-html-feld')).includes('{nachname}'));
      await p.click(RM_LEISTE + ' [data-befehl=knopf]');
      notiere(engine, 'desktop-breit', 'team-mailing-entwurf', 'Knopf-Dialog öffnet', await p.isVisible(RM_FELD + ' .rm-dialog'));
      await p.locator(RM_FELD + ' .rm-dialog input[type=text]').nth(0).fill('Programm ' + engine);
      await p.locator(RM_FELD + ' .rm-dialog input[type=text]').nth(1).fill('https://example.org/programm');
      await p.click(RM_FELD + ' .rm-dialog [data-ok]');
      notiere(engine, 'desktop-breit', 'team-mailing-entwurf', 'Knopf eingefügt', await p.locator('#rm-html-feld a.knopf', { hasText: 'Programm ' + engine }).count() === 1);
      await p.setInputFiles(RM_FELD + ' input[type=file][aria-label="Bild hochladen"]', { name: 'test.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAACgAAAAUCAIAAABwJOjsAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAJ0lEQVRIiWPc0vOfYSAA04DYOmrxqMWjFo9aPGrxqMWjFo9aPCAAAP6CAmf8hOaeAAAAAElFTkSuQmCC', 'base64') });
      await p.waitForFunction(() => document.querySelectorAll('#rm-html-feld img').length >= 2, null, { timeout: 8000 }).catch(() => {});
      notiere(engine, 'desktop-breit', 'team-mailing-entwurf', 'Bild hochgeladen und eingefügt', await p.locator('#rm-html-feld img').count() >= 2, await p.textContent(RM_FELD + ' .editor-status'));
      await p.click(RM_LEISTE + ' [data-befehl=html]');
      notiere(engine, 'desktop-breit', 'team-mailing-entwurf', 'HTML-Ansicht', await p.isVisible('#rm-html') && (await p.inputValue('#rm-html')).includes('class="knopf"'));
      await p.click(RM_LEISTE + ' [data-befehl=html]');
      await p.selectOption('#berufsgruppe', 'ÄrztIn');
      await p.waitForFunction(() => document.getElementById('rm-senden').textContent.trim() !== '', null, { timeout: 3000 }).catch(() => {});
      await p.waitForTimeout(400);
      notiere(engine, 'desktop-breit', 'team-mailing-entwurf', 'Empfängerzahl folgt der Auswahl', (await p.textContent('#rm-zahl')).trim() === '1' && (await p.textContent('#rm-senden')).includes('An 1 senden'), await p.textContent('#rm-zahl'));
      await p.selectOption('#berufsgruppe', '');
      await Promise.all([p.waitForNavigation(), p.click('button[value=speichern]')]);
      notiere(engine, 'desktop-breit', 'team-mailing-entwurf', 'Entwurf gespeichert, Vorschau zeigt Knopf', (await p.textContent('.meldung')).includes('gespeichert') && (await vorschau.locator('body').textContent({ timeout: 5000 }).catch(() => '')).includes('Programm ' + engine));
      await p.click('#rm-senden');
      notiere(engine, 'desktop-breit', 'team-mailing-entwurf', 'Senden fragt im Dialog (grün)', await p.isVisible('dialog.dlg-gruen[open] button[value=senden]'));
      await p.waitForTimeout(400);
      await p.screenshot({ path: path.join(AUS, `${engine}-desktop-breit-dialog-senden.png`) });
      await p.keyboard.press('Escape');
      await p.click('button[data-breite=desktop]');
      notiere(engine, 'desktop-breit', 'team-mailing-entwurf', 'Vorschau Desktop-Breite', (await p.getAttribute('#rm-vorschau', 'class')).includes('desktop'));
      await p.setViewportSize({ width: 1440, height: 900 });
      await p.goto(basis + '/team/personen');
      await p.fill('#suche', 'paula'); await Promise.all([p.waitForNavigation(), p.click('.filterzeile button[type=submit]')]);
      notiere(engine, 'desktop-breit', 'team-personen', 'Suche findet Paula', (await p.locator('table.personen tbody tr').count()) === 1 && (await p.textContent('table.personen')).includes('Paula Probe'));
      await Promise.all([p.waitForNavigation(), p.click('table.personen a:has-text("Paula Probe")')]);
      notiere(engine, 'desktop-breit', 'team-personen', 'Auswahl zeigt Angebot und Aktionen', (await p.textContent('.detail')).includes('Platzangebot') && await p.isVisible('.detail button:has-text("Platz geben")'));

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
        // Neue Zeilen lassen sich entfernen; Dozierende je Termin (Rückmeldung 2026-10-30)
        await p.locator('#pg-termine tr').last().locator('[data-entfernen]').click();
        notiere(engine, breite, 'team-programm-workshop', 'Neue Termin-Zeile entfernen', await p.locator('#pg-termine tr').count() === nachher - 1);
        notiere(engine, breite, 'team-programm-workshop', 'Zweiter Termin mit abweichenden Dozierenden', ((await p.locator('#pg-termine tr').nth(1).locator('.t-doz summary').textContent()) || '').includes('Erika Muster')
          && ((await p.locator('#pg-termine tr').nth(0).locator('.t-doz summary').textContent()) || '').includes('wie Workshop'));
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
      // Ziehen mit der Maus (Zeiger-Ereignisse, in allen drei Engines echt); untere Hälfte: nach Fall #1 einfügen
      await p.setViewportSize({ width: 1440, height: 1800 });
      await Promise.all([p.waitForNavigation(), ziehen(p, neuZeile.locator('.griff'), zielZeile, 0.85, async () => {
        notiere(engine, 'desktop-breit', 'team-programm', 'Einfügelinie beim Ziehen sichtbar', await p.locator('li.ziel-nach').count() === 1);
      })]);
      await p.waitForLoadState('load');
      await p.screenshot({ path: path.join(AUS, `${engine}-desktop-breit-team-programm-gezogen.png`), fullPage: true });
      notiere(engine, 'desktop-breit', 'team-programm', 'Ziehen schlägt Uhrzeit vor (19:15–19:45)', (await p.textContent('body')).includes('Neue Uhrzeit vorgeschlagen: 19:15–19:45') && (await p.textContent('body')).includes('2 Punkte rücken dahinter nach hinten'),
        (await p.locator('.meldung').allTextContents()).join(' | ') + ' ' + p.url());
      await p.setViewportSize({ width: 1440, height: 900 });
      await p.click('.entwurf-banner button:has-text("Verwerfen")');
      notiere(engine, 'desktop-breit', 'team-programm', 'Verwerfen fragt im einheitlichen Dialog (rot)', await p.isVisible('dialog.dlg-rot[open]'));
      await p.waitForTimeout(400);
      const dlgBox = await p.locator('dialog[open]').boundingBox();
      const fensterH = p.viewportSize().height;
      notiere(engine, 'desktop-breit', 'team-programm', 'Dialog mittig im Fenster', dlgBox !== null && Math.abs((dlgBox.y + dlgBox.height / 2) - fensterH / 2) < 40, JSON.stringify(dlgBox) + ' / ' + fensterH);
      await p.screenshot({ path: path.join(AUS, `${engine}-desktop-breit-dialog-verwerfen.png`) });
      await p.click('dialog[open] button:has-text("Ja, verwerfen")');
      await p.waitForLoadState('load');
      notiere(engine, 'desktop-breit', 'team-programm', 'Verwerfen über den Banner', (await p.textContent('body')).includes('Verworfen'));

      // Station auf die Mitte des Workshop-Blocks ziehen: zuordnen (Rückmeldung 2026-10-30), auf dem Handy mit dem Finger
      await p.setViewportSize({ width: 390, height: 844 });
      await p.goto(basis + '/team/programm');
      const stationVorher = await p.locator('.pg-kinder .pg-zeile[data-typ="station"]').count();
      await p.locator('.pg-zeile.block').first().scrollIntoViewIfNeeded();
      await Promise.all([p.waitForNavigation(), p.evaluate(() => {
        // Finger: Zeiger-Ereignisse mit pointerType "touch" (Playwright kann Finger-Ziehen nicht selbst auslösen)
        const griff = document.querySelector('.pg-zeile[data-typ="station"] .griff');
        const ziel = document.querySelector('.pg-zeile.block');
        const g = griff.getBoundingClientRect(), r = ziel.getBoundingClientRect();
        const ev = (typ, el, x, y) => el.dispatchEvent(new PointerEvent(typ, { bubbles: true, cancelable: true, pointerId: 7, pointerType: 'touch', isPrimary: true, clientX: x, clientY: y, button: 0 }));
        ev('pointerdown', griff, g.left + 4, g.top + 4);
        ev('pointermove', griff, r.left + 40, r.top + r.height / 2);
        ev('pointerup', griff, r.left + 40, r.top + r.height / 2);
      })]);
      await p.setViewportSize({ width: 1440, height: 900 });
      notiere(engine, 'handy', 'team-programm', 'Station mit dem Finger auf den Block gezogen: zugeordnet', (await p.textContent('body')).includes('Zugeordnet') && await p.locator('.pg-kinder .pg-zeile[data-typ="station"]').count() >= stationVorher, `${stationVorher}`);
      await p.click('.entwurf-banner button:has-text("Verwerfen")');
      await Promise.all([p.waitForNavigation(), p.click('dialog[open] button:has-text("Ja, verwerfen")')]);

      // Programmpunkt löschen über den Dialog (Rückmeldung 2026-10-30: Knopf ging nicht, Dialog unten)
      await p.goto(basis + '/team/programm');
      await p.click('.pg-link:has-text("Sepsis")');
      await p.waitForLoadState('load');
      await p.click('#pg-loeschen');
      await p.waitForTimeout(400);
      const lBox = await p.locator('dialog[open]').boundingBox();
      notiere(engine, 'desktop-breit', 'team-programm', 'Löschen-Dialog mittig', lBox !== null && Math.abs((lBox.y + lBox.height / 2) - 450) < 40, JSON.stringify(lBox));
      await Promise.all([p.waitForNavigation(), p.click('dialog[open] button:has-text("Ja, löschen")')]);
      notiere(engine, 'desktop-breit', 'team-programm', '„Ja, löschen“ löscht', (await p.textContent('body')).includes('Gelöscht'));
      await p.click('.entwurf-banner button:has-text("Verwerfen")');
      await Promise.all([p.waitForNavigation(), p.click('dialog[open] button:has-text("Ja, verwerfen")')]);

      // Betrieb → Status (AP-15): Zeile aufklappen, Befehl kopieren, Token erst nach Klick
      for (const [breite, w, h] of BREITEN) {
        await p.setViewportSize({ width: w, height: h });
        await pruefeSeite(p, fehler, engine, breite, 'team-status', basis + '/team/status');
        await p.click('#zeile-cron details > summary');
        notiere(engine, breite, 'team-status', 'Cron-Zeile klappt auf, Befehl sichtbar', await p.isVisible('#cron-kommando') && (await p.inputValue('#cron-kommando')).endsWith('/cli/jobs.php'));
        await p.click('button[data-kopieren="cron-kommando"]');
        await p.waitForTimeout(300);
        const rueck = await p.textContent('[data-kopiert="cron-kommando"]');
        // Chromium mit erlaubter Zwischenablage: „Kopiert“; sonst markiert der Ausweichweg das Feld
        notiere(engine, breite, 'team-status', 'Kopieren meldet „Kopiert“ (sonst Feld markiert)', engine === 'chromium' ? rueck === 'Kopiert' : /^(Kopiert|Markiert)/.test(rueck || ''), rueck);
        notiere(engine, breite, 'team-status', 'URL ohne Token', (await p.inputValue('#cron-url')).endsWith('token=<jobs_token>'));
        await p.screenshot({ path: path.join(AUS, `${engine}-${breite}-team-status-cron.png`), fullPage: true });
      }
      await p.setViewportSize({ width: 1440, height: 900 });
      await Promise.all([p.waitForNavigation(), p.click('#zeile-cron button:has-text("URL mit Token anzeigen")')]);
      notiere(engine, 'desktop-breit', 'team-status', 'Token nach Klick sichtbar, Zeile offen', !(await p.inputValue('#cron-url')).includes('<jobs_token>') && await p.isVisible('#cron-url'));
      await p.click('#zeile-fehlerlog details > summary');
      await Promise.all([p.waitForNavigation(), p.click('#zeile-fehlerlog button:has-text("Zeilen anzeigen")')]);
      notiere(engine, 'desktop-breit', 'team-status', 'Fehlerprotokoll auf Abruf', await p.isVisible('#zeile-fehlerlog details[open]'));
      await p.click('#zeile-fehlerlog button:has-text("Leeren")');
      await p.waitForTimeout(300);
      notiere(engine, 'desktop-breit', 'team-status', 'Leeren fragt im Dialog nach', await p.isVisible('dialog.dlg-rot[open]'));
      await Promise.all([p.waitForNavigation(), p.click('dialog[open] button:has-text("Ja, leeren")')]);
      notiere(engine, 'desktop-breit', 'team-status', 'Leeren meldet Erfolg', (await p.textContent('body')).includes('Fehlerprotokoll geleert'));
      await p.screenshot({ path: path.join(AUS, `${engine}-desktop-breit-team-status-geleert.png`), fullPage: true });

      // Dialog auf dem Handy: von unten, Knöpfe untereinander
      await p.setViewportSize({ width: 390, height: 844 });
      await p.goto(basis + '/team/daten');
      await p.click('button:has-text("Testdaten laden")');
      await p.waitForTimeout(400);
      const unten = await p.locator('dialog[open]').boundingBox();
      notiere(engine, 'handy', 'team-daten', 'Dialog kommt von unten (rot)', unten !== null && Math.abs(unten.y + unten.height - 844) < 4 && await p.isVisible('dialog.dlg-rot[open]'), JSON.stringify(unten));
      await p.screenshot({ path: path.join(AUS, `${engine}-handy-dialog-testdaten.png`) });
      await p.keyboard.press('Escape');
      await p.setViewportSize({ width: 1440, height: 900 });

      // AP-18: Seite „Startseite“ mit Vorschau (E-70)
      const vorschauH1 = () => p.frameLocator('iframe.vorschau-frame').locator('.held .titel');
      const vorschauNeu = async (tun) => {
        const geladen = p.waitForEvent('requestfinished', r => r.url().endsWith('/team/startseite/vorschau'));
        await tun();
        await geladen;
        // Die Antwort ist da; warten, bis das iframe sie anzeigt (Firefox lädt unter Last spürbar später)
        await p.waitForFunction(() => { const f = document.querySelector('iframe.vorschau-frame'); return f && f.contentDocument && f.contentDocument.readyState === 'complete' && f.contentDocument.querySelector('.held .titel'); }, null, { timeout: 15000 }).catch(() => {});
        await p.waitForTimeout(250);
      };
      await vorschauNeu(() => p.goto(basis + '/team/startseite'));
      notiere(engine, 'desktop-breit', 'team-startseite', 'Vorschau rechts, Desktop vorausgewählt, Titel mit Trennstelle (Beispieldaten)',
        await p.isVisible('.vorschau-frame') && await p.getAttribute('.geraet-wahl [data-geraet=desktop]', 'aria-pressed') === 'true'
        && (await vorschauH1().getAttribute('class')).includes('titel-xl') && await p.locator('#start_titel-feld .trennstelle').count() === 1);
      notiere(engine, 'desktop-breit', 'team-startseite', 'Hinweise: nur leere Kachel 3, keine Größenangabe', (await p.textContent('#st-hinweise')).includes('Kachel 3') && !(await p.textContent('#st-hinweise')).includes('Größe'));
      // Rückmeldung nach AP-19: Zoom und „Groß ansehen“, Speichern schwebt
      await p.click('[data-zoom="1"]');
      const zoomOk = await p.evaluate(() => { const r = document.querySelector('.vorschau-rahmen'); return r.classList.contains('zoom') && r.scrollWidth > r.clientWidth + 100; });
      await p.click('[data-zoom="0"]');
      await p.click('#st-gross');
      const gross = await p.evaluate(() => { const b = document.getElementById('st-vorschau').getBoundingClientRect(); return document.getElementById('st-vorschau').classList.contains('vorschau-gross') && b.width >= window.innerWidth - 2; });
      await p.screenshot({ path: path.join(AUS, `${engine}-desktop-breit-startseite-gross.png`) });
      await p.keyboard.press('Escape');
      const zu = !await p.evaluate(() => document.getElementById('st-vorschau').classList.contains('vorschau-gross'));
      notiere(engine, 'desktop-breit', 'team-startseite', 'Zoom 100 % scrollt seitlich, „Groß ansehen“ füllt das Fenster, Esc schließt', zoomOk && gross && zu);
      await p.evaluate(() => window.scrollTo(0, 600));
      const knopfUnten = await p.evaluate(() => { const r = document.querySelector('.st-knoepfe').getBoundingClientRect(); return r.bottom <= window.innerHeight + 1 && r.bottom >= window.innerHeight - 90; });
      notiere(engine, 'desktop-breit', 'team-startseite', 'Speichern schwebt am unteren Fensterrand', knopfUnten);
      await p.evaluate(() => window.scrollTo(0, 0));
      await p.click('.geraet-wahl [data-geraet=handy]');
      await p.waitForTimeout(300);
      const handyBreite = await p.frameLocator('iframe.vorschau-frame').locator('body').evaluate(b => b.ownerDocument.defaultView.innerWidth);
      notiere(engine, 'desktop-breit', 'team-startseite', 'Gerätewahl Handy: 390 px, keine Warnung', handyBreite === 390 && await p.locator('#st-hinweise .meldung.fehler').count() === 0, String(handyBreite));
      await p.screenshot({ path: path.join(AUS, `${engine}-desktop-breit-startseite-vorschau-handy.png`) });
      // Titel tippen: Vorschau folgt; Lila und Trennstelle setzen und entfernen
      const titel = p.locator('#start_titel-feld');
      const leiste = p.locator('.feld:has(#start_titel) .werkzeug');
      await vorschauNeu(async () => {
        await titel.click();
        await p.keyboard.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A');
        await p.keyboard.type('Kurzer Testtitel');
      });
      // Bei Abweichung festhalten, was das iframe zeigt (statt abzubrechen)
      const vorschauText = await vorschauH1().textContent({ timeout: 8000 }).catch(async () => 'kein Titel; iframe: ' + await p.evaluate(() => { const f = document.querySelector('iframe.vorschau-frame'); const d = f && f.contentDocument; return d ? (d.location.href + ' | ' + (d.body ? d.body.innerText.slice(0, 160) : 'ohne body')) : 'kein Dokument'; }));
      notiere(engine, 'desktop-breit', 'team-startseite', 'Vorschau folgt dem Tippen', vorschauText.trim() === 'Kurzer Testtitel', vorschauText.trim());
      await titel.press('End');
      for (let i = 0; i < 9; i++) await p.keyboard.press('Shift+ArrowLeft');
      await leiste.locator('[data-befehl=lila]').click();
      const lilaAn = await titel.locator('em, i').count() === 1 && await leiste.locator('[data-befehl=lila]').getAttribute('aria-pressed') === 'true';
      await leiste.locator('[data-befehl=lila]').click();
      const lilaAus = await titel.locator('em, i').count() === 0;
      notiere(engine, 'desktop-breit', 'team-startseite', 'Knopf „Lila“ setzt und entfernt', lilaAn && lilaAus, await titel.innerHTML());
      await titel.press('End');
      for (let i = 0; i < 5; i++) await p.keyboard.press('ArrowLeft');
      await leiste.locator('[data-befehl=trennstelle]').click();
      const trennAn = await titel.locator('.trennstelle').count() === 1 && await leiste.locator('[data-befehl=trennstelle]').getAttribute('aria-pressed') === 'true';
      await leiste.locator('[data-befehl=trennstelle]').click();
      notiere(engine, 'desktop-breit', 'team-startseite', 'Knopf „Trennstelle“ setzt und entfernt', trennAn && await titel.locator('.trennstelle').count() === 0, await titel.innerHTML());
      // Wort ohne Trennstelle, das auf dem Handy nicht passt: Warnung
      await vorschauNeu(async () => {
        await titel.click();
        await p.keyboard.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A');
        await p.keyboard.type('Donaudampfschifffahrtsgesellschaftskapitän');
      });
      notiere(engine, 'desktop-breit', 'team-startseite', 'zu langes Wort: Warnung über der Vorschau', await p.locator('#st-hinweise .meldung.fehler').count() === 1);
      await p.screenshot({ path: path.join(AUS, `${engine}-desktop-breit-startseite-warnung.png`) });
      await Promise.all([p.waitForNavigation(), p.click('.st-knoepfe a:has-text("Verwerfen")')]);
      notiere(engine, 'desktop-breit', 'team-startseite', 'Verwerfen: gespeicherter Titel bleibt', await p.locator('#start_titel-feld .trennstelle').count() === 1);
      // Texte: derselbe Editor; Fett schaltet an und aus, nummerierte Liste (Rückmeldung 2026-10-30 (2))
      const cme = p.locator('#start_cme-feld');
      const cmeLeiste = p.locator('.feld:has(#start_cme) .werkzeug');
      await cme.click();
      await p.keyboard.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A');
      await p.keyboard.type('Wort');
      await p.keyboard.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A');
      await cmeLeiste.locator('[data-befehl=fett]').click();
      const fettAn = await cme.locator('b, strong').count() === 1 && await cmeLeiste.locator('[data-befehl=fett]').getAttribute('aria-pressed') === 'true';
      await cmeLeiste.locator('[data-befehl=fett]').click();
      const fettAus = await cme.locator('b, strong').count() === 0 && await cmeLeiste.locator('[data-befehl=fett]').getAttribute('aria-pressed') === 'false';
      notiere(engine, 'desktop-breit', 'team-texte', 'Editor: Fett schaltet an und wieder aus', fettAn && fettAus, await cme.innerHTML());
      await cmeLeiste.locator('[data-befehl=nliste]').click();
      notiere(engine, 'desktop-breit', 'team-texte', 'Editor: nummerierte Liste', await cme.locator('ol li').count() === 1, await cme.innerHTML());
      notiere(engine, 'desktop-breit', 'team-texte', 'Einzeilige Felder mit Editor (Untertitel)', await p.isVisible('#start_unter-feld'));
      // Handy: Vorschau unter dem Formular, aufklappbar, Handy vorausgewählt (O-49)
      await p.setViewportSize({ width: 390, height: 844 });
      await vorschauNeu(() => p.goto(basis + '/team/startseite'));
      notiere(engine, 'handy', 'team-startseite', 'Vorschau unter dem Formular, Handy vorausgewählt', await p.isVisible('.st-vorschau > summary') && await p.getAttribute('.geraet-wahl [data-geraet=handy]', 'aria-pressed') === 'true'
        && (await p.locator('.st-vorschau').boundingBox()).y > (await p.locator('.st-felder').boundingBox()).y);
      await p.locator('.st-vorschau').scrollIntoViewIfNeeded();
      await p.screenshot({ path: path.join(AUS, `${engine}-handy-startseite-vorschau.png`) });

      // Einstellungen als Übersicht (AP-18, Variante B): Kachel aufklappen, Fehler, Speichern je Kachel
      await p.goto(basis + '/team/einstellungen');
      notiere(engine, 'handy', 'team-einstellungen', 'Kacheln zu, Felder verborgen', !await p.isVisible('#veranstaltung_ende') && await p.locator('.einst-auf[open]').count() === 0);
      await p.click('#veranstaltung summary');
      await p.click('#bausteine summary');
      notiere(engine, 'handy', 'team-einstellungen', 'eine Kachel zur Zeit offen', await p.locator('.einst-auf[open]').count() === 1 && await p.isVisible('#mit_essen'));
      await p.click('#veranstaltung summary');
      await pruefeSeite(p, fehler, engine, 'handy', 'team-einstellungen-offen', null);
      // Die Bedienungsprüfung klappt jede Kachel einmal auf (eine zur Zeit): Grunddaten wieder öffnen
      if (!await p.locator('#veranstaltung details').evaluate(d => d.open)) await p.click('#veranstaltung summary');
      await p.fill('#veranstaltung_ende', '17:00');
      await p.click('#veranstaltung button[type=submit]');
      await p.waitForLoadState('load');
      notiere(engine, 'handy', 'team-einstellungen', 'Fehler markiert, Kachel offen, Eingabe bleibt', await p.isVisible('#veranstaltung_ende[aria-invalid="true"]') && await p.inputValue('#veranstaltung_ende') === '17:00');
      await pruefeSeite(p, fehler, engine, 'handy', 'team-einstellungen-fehler', null);
      await p.fill('#veranstaltung_ende', '22:00');
      await p.fill('#veranstaltung_ort', 'Ort ' + engine);
      await p.click('#veranstaltung button[type=submit]');
      await p.waitForLoadState('load');
      notiere(engine, 'handy', 'team-einstellungen', 'gespeichert, Zusammenfassung zeigt den Ort', (await p.textContent('body')).includes('Gespeichert') && (await p.textContent('#veranstaltung .einst-zus')).includes('Ort ' + engine));
      await p.click('#anmeldung summary');
      await p.selectOption('#workshop_pflicht', 'pflicht');
      await p.click('#anmeldung button[type=submit]');
      await p.waitForLoadState('load');
      notiere(engine, 'handy', 'team-einstellungen', 'Kachel Anmeldung: Auswahl übernommen', (await p.textContent('#anmeldung .einst-zus')).includes('Pflicht'));
      // Rückmeldung nach AP-19: Abbrechen schließt die Kachel, auch mit #anmeldung in der Adresse
      await p.click('#anmeldung summary');
      await p.fill('#workshop_limit', '7');
      await Promise.all([p.waitForNavigation(), p.click('#anmeldung button[value=abbrechen]')]);
      notiere(engine, 'handy', 'team-einstellungen', 'Abbrechen schließt die Kachel und verwirft', await p.locator('.einst-auf[open]').count() === 0 && !(await p.textContent('#anmeldung .einst-zus')).includes('höchstens 7'));
      // Rückmeldung Staging (nach AP-19): Reihe auf „einzeln“ und zurück zur Reihe
      for (const ziel of ['einzeln', 'Wintersymposium']) {
        await p.click('#reihe summary');
        await p.selectOption('#reihe_id', { label: ziel === 'einzeln' ? 'einzeln (keine Reihe)' : ziel });
        await Promise.all([p.waitForNavigation(), p.click('#reihe button[type=submit]')]);
        notiere(engine, 'handy', 'team-einstellungen', 'Reihe gespeichert: ' + ziel, (await p.textContent('#reihe .einst-zus')).includes(ziel) && (await p.textContent('body')).includes('Gespeichert'), (await p.textContent('.meldung')).trim());
      }
      await p.goto(basis + '/');
      notiere(engine, 'handy', 'start', 'Startseite zeigt neuen Ort', (await p.textContent('.eckdaten')).includes('Ort ' + engine));

      // Konto über das Formular anlegen (Auswahlfeld Rolle), Link erscheint
      await p.setViewportSize({ width: 390, height: 844 });
      await p.goto(basis + '/team/konten');
      await p.fill('#name', 'Neu ' + engine); await p.fill('#email', `neu-${engine}@example.org`);
      await p.selectOption('#rolle', 'betreiber');
      await p.check('input[name=nur_anzeigen]');
      await p.click('form:has(input[value=anlegen]) button');
      await p.waitForLoadState('load');
      notiere(engine, 'handy', 'team-konten', 'Konto über Formular angelegt, Link angezeigt („keine Mail“)', (await p.textContent('body')).includes('/einrichten?t='));
      await p.fill('#name', 'Mail ' + engine); await p.fill('#email', `mail-${engine}@example.org`);
      await p.click('form:has(input[value=anlegen]) button');
      await p.waitForLoadState('load');
      notiere(engine, 'handy', 'team-konten', 'Einladung per Mail (AP-6)', (await p.textContent('.meldung')).includes(`Einladung an mail-${engine}@example.org geschickt`) && !(await p.textContent('body')).includes('/einrichten?t='));
      notiere(engine, 'handy', 'team-konten', 'gewählte Rolle gespeichert', await p.locator('tr', { hasText: `neu-${engine}@example.org` }).locator('td').nth(1).textContent() === 'BetreiberIn');
      // AP-13: Aktionen je Zeile im Menü „Bearbeiten“, jede Aktion als Dialog
      for (const [breite, w, h] of [['handy', 390, 844], ['desktop-breit', 1440, 900]]) {
        await p.setViewportSize({ width: w, height: h });
        await p.goto(basis + '/team/konten');
        const zeile = p.locator('tr', { hasText: 'ada.abend@example.org' });
        notiere(engine, breite, 'team-konten', 'je Zeile nur ein Knopf „Bearbeiten“', await zeile.locator('.aktionen-zelle > .knopf, .aktionen-zelle > form').count() === 0 && await zeile.locator('.zeilen-menue > summary').isVisible());
        await zeile.locator('.zeilen-menue > summary').click();
        notiere(engine, breite, 'team-konten', 'Menü mit Veranstaltungen, Rolle, Zugang, Sperren', await zeile.locator('.zeilen-liste').isVisible() && await zeile.locator('.menue-punkt:visible').count() >= 4);
        await p.screenshot({ path: path.join(AUS, `${engine}-${breite}-team-konten-menue.png`) });
        await zeile.locator('.menue-punkt', { hasText: 'Veranstaltungen' }).click();
        await p.waitForTimeout(250);
        notiere(engine, breite, 'team-konten', 'Veranstaltungen im Dialog, Menü zu', await p.isVisible('dialog[open] input[name="umfang"][value="auswahl"]') && !(await zeile.locator('.zeilen-menue').evaluate(d => d.open)));
        await p.screenshot({ path: path.join(AUS, `${engine}-${breite}-team-konten-dialog.png`) });
        await p.keyboard.press('Escape');
      }
      await p.setViewportSize({ width: 390, height: 844 });

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
      await ausloggen(p);
      notiere(engine, 'desktop-breit', 'ausloggen', 'ausgeloggt', new URL(p.url()).pathname === '/einloggen' && (await p.textContent('body')).includes('ausgeloggt'));
      await p.fill('#email', vor.betreiber); await p.fill('#passwort', vor.passwort);
      await p.click('form:has(input[value=passwort]) button');
      await p.waitForLoadState('load');
      await p.fill('#totp', codes[0]);
      await p.click('form:has(input[value=code]) button');
      await p.waitForLoadState('load');
      notiere(engine, 'desktop-breit', 'code-login', 'Login mit Wiederherstellungscode', new URL(p.url()).pathname === '/team');

      // ---- AP-16 (Review B-7): Herunterladen bestätigen mit Code aus der Mail, in Chromium auch mit Passkey ----
      const mailAnBea = () => (fs.existsSync(vor.mails) ? fs.readdirSync(vor.mails) : []).sort().reverse()
        .map(f => fs.readFileSync(path.join(vor.mails, f), 'utf8').replace(/=\r?\n/g, '').replace(/=([0-9A-F]{2})/g, (_, x) => String.fromCharCode(parseInt(x, 16))))
        .filter(t => t.includes(`To: <${vor.betreiber}>`) && t.includes('Er gilt 10 Minuten'));
      await pruefeSeite(p, fehler, engine, 'desktop-breit', 'zf-bestaetigen', basis + '/team/daten');
      await p.locator('button[aria-haspopup="dialog"]', { hasText: 'Herunterladen' }).first().click();
      await p.waitForSelector('dialog[open]');
      notiere(engine, 'desktop-breit', 'zf-bestaetigen', 'Dialog: Code-Feld und „Code per E-Mail schicken“', await p.isVisible('dialog[open] input[name=totp]') && await p.isVisible('dialog[open] [data-zf-mailcode]') && !await p.isVisible('dialog[open] [data-zf-passkey]'));
      const mailsVorher = mailAnBea().length;
      await p.click('dialog[open] [data-zf-mailcode]');
      await p.waitForSelector('dialog[open] [data-zf-meldung]:not([hidden])', { timeout: 5000 }).catch(() => {});
      notiere(engine, 'desktop-breit', 'zf-bestaetigen', 'Code angefordert, Meldung im Dialog', ((await p.textContent('dialog[open] [data-zf-meldung]')) || '').includes('unterwegs'), (await p.textContent('dialog[open] [data-zf-meldung]').catch(() => '')) || '');
      await p.screenshot({ path: path.join(AUS, `${engine}-desktop-breit-zf-mailcode.png`) });
      let zfCode = '';
      for (let i = 0; i < 40 && !zfCode; i++) {
        const m = mailAnBea();
        if (m.length > mailsVorher) zfCode = (m[0].match(/^\s+(\d{6})\s*$/m) || ['', ''])[1];
        if (!zfCode) await p.waitForTimeout(150);
      }
      await p.fill('dialog[open] input[name=totp]', zfCode);
      const [zfDl] = await Promise.all([p.waitForEvent('download', { timeout: 10000 }).catch(() => null), p.click('dialog[open] .dlg-ja')]);
      notiere(engine, 'desktop-breit', 'zf-bestaetigen', 'Herunterladen mit dem Code aus der Mail', zfCode !== '' && zfDl !== null && zfDl.suggestedFilename().endsWith('.gz'), zfCode);
      // Ein Download lädt die Seite nicht neu: der Dialog bleibt offen, bis man ihn schließt
      if (await p.locator('dialog[open]').count()) await p.keyboard.press('Escape');
      if (engine === 'chromium') {
        const cdpZ = await ctx.newCDPSession(p);
        await cdpZ.send('WebAuthn.enable');
        const { authenticatorId: zfGeraet } = await cdpZ.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });
        await einloggenAls(p, lokal, { email: vor.betreiber, passwort: vor.passwort });
        await p.waitForLoadState('load');
        await p.fill('#totp', codes[1]);
        await Promise.all([p.waitForNavigation(), p.click('form:has(input[value=code]) button')]);
        await p.goto(lokal + '/konto');
        await p.click('[data-passkey-anlage] > summary');
        await p.fill('#pk-bez', 'Bea Browser'); await p.fill('#pk-pw', vor.passwort);
        await Promise.all([p.waitForNavigation({ timeout: 10000 }).catch(() => {}), p.click('[data-passkey-anlegen] button[type=submit]')]);
        await p.waitForLoadState('load');
        await p.goto(lokal + '/team/daten');
        await p.locator('button[aria-haspopup="dialog"]', { hasText: 'Herunterladen' }).first().click();
        await p.waitForSelector('dialog[open]');
        notiere(engine, 'desktop-breit', 'zf-bestaetigen', 'mit Passkey: Knopf „Mit Passkey bestätigen“', await p.isVisible('dialog[open] [data-zf-passkey]'));
        await p.screenshot({ path: path.join(AUS, `${engine}-desktop-breit-zf-passkey.png`) });
        const [pkDl] = await Promise.all([p.waitForEvent('download', { timeout: 10000 }).catch(() => null), p.click('dialog[open] [data-zf-passkey]')]);
        notiere(engine, 'desktop-breit', 'zf-bestaetigen', 'Herunterladen mit Passkey bestätigt', pkDl !== null && pkDl.suggestedFilename().endsWith('.gz'), (await p.textContent('dialog[open] [data-zf-meldung]').catch(() => '')) || '');
        await cdpZ.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId: zfGeraet });
        await p.goto(basis + '/team');
      }

      // AP-12: Ada ist Admin nur für den Fortbildungsabend
      await ausloggen(p);
      for (const [breite, w, h] of [['handy', 390, 844], ['desktop-breit', 1440, 900]]) {
        await p.setViewportSize({ width: w, height: h });
        if (breite === 'handy') {
          await einloggenAls(p, basis, { email: vor.ada.email, passwort: vor.ada.passwort });
          await p.waitForSelector('svg.qr:not([hidden]) path', { timeout: 5000 }).catch(() => {});
          const uriA = await p.getAttribute('svg.qr', 'data-qr');
          await p.fill('#totp', totpCode(uriA));
          await p.click('form:has(input[value=einrichten]) button');
          await p.waitForLoadState('load');
          await p.click('a.knopf');
          await p.waitForLoadState('load');
        }
        await pruefeSeite(p, fehler, engine, breite, 'admin-team', basis + '/team');
        await p.click('.va-wechsler > summary');
        notiere(engine, breite, 'admin-team', 'Admin: nur der Fortbildungsabend, kein Betrieb', await p.locator('.va-menue .va-eintrag').count() === 1 && (await p.textContent('.va-menue')).includes('Fortbildungsabend') && await p.locator('.team-nav a:has-text("Betrieb")').count() === 0 && await p.locator('.va-aktionen').count() === 0);
        await p.screenshot({ path: path.join(AUS, `${engine}-${breite}-admin-wechsler-offen.png`) });
        await p.click('.va-wechsler > summary');
        await pruefeSeite(p, fehler, engine, breite, 'admin-konto', basis + '/konto');
        notiere(engine, breite, 'admin-konto', 'Mein Konto: „Admin für“ den Abend', (await p.textContent('.rechte-liste')).includes('Fortbildungsabend'));
        // AP-16 (Review S-3): unbestätigte Anmeldung mit Adas Adresse (Beispieldaten) ist nicht „Meine Anmeldung“
        await pruefeSeite(p, fehler, engine, breite, 'admin-anmeldung', basis + '/deine-anmeldung');
        notiere(engine, breite, 'admin-anmeldung', 'Fremde unbestätigte Anmeldung mit Team-Adresse nicht als eigene', (await p.textContent('body')).includes('Eingeloggt als Ada') && !(await p.textContent('body')).includes('Hallo Ada.'));
      }

      // AP-16 (Review B-10): ohne aktuelle Veranstaltung kein Rückfall auf Veranstaltung 1
      const vaStatus = st => php(['-r', `require '${path.join(WURZEL, 'server/bootstrap.php')}'; db_ausfuehren("UPDATE veranstaltung SET status = '${st}' WHERE id = ?", [${vor.aktuell_id}]);`], vor.config);
      vaStatus('abgeschlossen');
      try {
        for (const [breite, w, h] of BREITEN) {
          await p.setViewportSize({ width: w, height: h });
          await pruefeSeite(p, fehler, engine, breite, 'start-ohne-va', basis + '/');
          notiere(engine, breite, 'start-ohne-va', 'Startseite: „Derzeit keine Veranstaltung“, keine Anmeldung, keine Eckdaten', (await p.textContent('body')).includes('Derzeit keine Veranstaltung') && !await p.isVisible('.held-knoepfe') && !await p.isVisible('dl.eckdaten'));
          await pruefeSeite(p, fehler, engine, breite, 'anmelden-ohne-va', basis + '/anmelden');
          notiere(engine, breite, 'anmelden-ohne-va', '/anmelden mit Hinweis, ohne Formular', (await p.textContent('body')).includes('Derzeit gibt es keine Veranstaltung') && !await p.isVisible('input[name=vorname]'));
        }
      } finally {
        vaStatus('aktuell');
      }

      // Abdeckung (V-52): jede HTML-Seite aus ROUTEN wurde in dieser Engine aufgerufen (nicht bei Stufe 2 mit Seitenauswahl)
      if (!SEITEN.length) {
        const fehlt = [...SEITENLISTE.oeffentlich, ...SEITENLISTE.team, ...SEITENLISTE.betreiber].filter(x => !besucht.has(x));
        notiere(engine, '-', 'abdeckung', 'jede Seite aus ROUTEN besucht', fehlt.length === 0, fehlt.join(', '));
      }
      besucht.clear();
      notiere(engine, '-', '-', 'Engine ' + version, true);
    };

    for (const engine of ENGINES) {
      const merk = {};
      const start = ergebnisse.length;
      let abbruch = '';
      try {
        await engineLauf(engine, merk);
      } catch (e) {
        // Abbruch zählt als Fehler mit Ort; die nächste Engine läuft trotzdem
        const zeile = (String(e.stack || '').match(/durchlauf\.js:(\d+)/) || [])[1];
        abbruch = `${String(e.message || e).split('\n')[0]} (bei ${zuletzt || '-'}${zeile ? ', durchlauf.js Zeile ' + zeile : ''})`;
        notiere(engine, '-', 'abbruch', 'Lauf der Engine abgebrochen', false, abbruch);
        if (merk.p) await merk.p.screenshot({ path: path.join(AUS, `${engine}-abbruch.png`), fullPage: true }).catch(() => {});
        console.error(e);
      } finally {
        besucht.clear();
        if (merk.browser) await merk.browser.close().catch(() => {});
      }
      const eigene = ergebnisse.slice(start);
      const fz = eigene.filter(x => !x.ok).length;
      engineInfo[engine] = { status: abbruch ? 'abgebrochen' : fz ? 'rot' : 'gruen', ok: eigene.length - fz, fehler: fz,
        version: merk.version || '', abbruch, stand: STAND, ordner: AUS, zeit: new Date().toISOString() };
    }
  } finally {
    server.kill();
  }

  // Teilwiederholung: grüne Engines aus dem alten Lauf übernehmen (Einträge und Fotos)
  const kopf = [];
  if (ALT) {
    for (const engine of ENGINES_GESAMT.filter(e => !ENGINES.includes(e))) {
      engineInfo[engine] = ALT.engines[engine];
      ergebnisse.push(...ALT.ergebnisse.filter(x => x.engine === engine));
      for (const datei of fs.readdirSync(ALT_ORDNER).filter(d => d.startsWith(engine + '-'))) {
        fs.copyFileSync(path.join(ALT_ORDNER, datei), path.join(AUS, datei));
      }
    }
    ergebnisse.sort((a, b) => ENGINES_GESAMT.indexOf(a.engine) - ENGINES_GESAMT.indexOf(b.engine));
    kopf.push(`Teilwiederholung von ${ALT_ORDNER}: neu ${ENGINES.join(', ') || '-'}; übernommen ${ENGINES_GESAMT.filter(e => !ENGINES.includes(e)).join(', ') || '-'}`);
  }
  for (const engine of ENGINES_GESAMT) {
    const i = engineInfo[engine];
    if (!i) continue;
    const anders = i.stand.commit !== STAND.commit || i.stand.aenderungen !== STAND.aenderungen;
    kopf.push(`${engine.padEnd(8)} ${i.status.padEnd(11)} ${i.ok} ok, ${i.fehler} Fehler · ${i.version} · Stand ${i.stand.commit.slice(0, 8)}${i.stand.aenderungen ? '+' + i.stand.aenderungen : ''}${i.abbruch ? ' · Abbruch: ' + i.abbruch : ''}`);
    if (anders) kopf.push(`         geändert gegenüber Commit ${i.stand.commit.slice(0, 8)}: ${geaendertSeit(i.stand).join(', ') || '(nur nicht eingecheckte Änderungen)'}`);
  }
  fs.writeFileSync(path.join(AUS, 'ergebnis.json'), JSON.stringify({ stufe: STUFE2 ? 2 : 3, engines_gesamt: ENGINES_GESAMT,
    breiten: BREITEN.map(b => b[0]), seiten: SEITEN, stand: STAND, engines: engineInfo, ergebnisse }, null, 1));

  const fehlerZahl = ergebnisse.filter(e => !e.ok).length;
  const zeilen = ergebnisse.map(e => `${e.ok ? 'ok    ' : 'FEHLER'} ${e.engine.padEnd(8)} ${e.breite.padEnd(15)} ${e.seite.padEnd(16)} ${e.was}${e.ok ? '' : ' – ' + e.info}`);
  const zusammen = `${ergebnisse.length - fehlerZahl} ok, ${fehlerZahl} Fehler (${ENGINES_GESAMT.length} Engines × ${BREITEN.length} Breiten${SEITEN.length ? '; Stufe 2, Seiten: ' + SEITEN.join(', ') : ''})`;
  fs.writeFileSync(path.join(AUS, 'bericht.txt'), zeilen.join('\n') + '\n\n' + kopf.join('\n') + '\n\n' + zusammen + '\n');
  console.log('\n' + kopf.join('\n') + '\n\n' + zusammen + '\nBericht und Bildschirmfotos: ' + AUS);
  process.exit(fehlerZahl === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
