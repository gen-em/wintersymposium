/*
 * Bilder für die README (E-77): Startseite, Programm, Anmeldung, Meine
 * Anmeldung, Team-Übersicht, Programm-Pflege, Statistik, Mailing-Editor und
 * Teilnahmebescheinigung – mit dem Test-Beispieldatensatz in neutraler
 * Fassung (beispieldaten_neutral(): keine Marke, erfundene Namen).
 *
 * Neutral nur im Bild: Das Logo und fest eingebaute Beschriftungen mit dem
 * Namen der Marke ersetzt das Skript im Browser, bevor es fotografiert; am
 * Code der App ändert sich nichts (Admin 2026-11-02, Variante A). Vor jedem
 * Foto prüft es, dass nirgends mehr „Allgäu“ steht, und bricht sonst ab.
 *
 * Einrichtung wie beim Browser-Durchlauf (bash tools/browser/einrichten.sh),
 * Testdatenbank über WS_TEST_DB_* (wird geleert!). Braucht pdftoppm und
 * convert (ImageMagick) für die Bescheinigung und das Verkleinern.
 *
 *   node tools/browser/readme_bilder.js
 *
 * Schreibt nach docs/bilder/ (überschreibt die vorhandenen Bilder).
 */
'use strict';
if (require('fs').existsSync('/opt/ws-browsers')) {
  process.env.PLAYWRIGHT_BROWSERS_PATH = '/opt/ws-browsers';
}
const pw = require('playwright');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn, execFileSync } = require('child_process');

const WURZEL = path.resolve(__dirname, '..', '..');
const ZIEL = path.join(WURZEL, 'docs', 'bilder');
const ROH = fs.mkdtempSync(path.join(os.tmpdir(), 'ws-readme-'));

function php(args, config) {
  return execFileSync('php', args, { env: { ...process.env, WS_CONFIG: config }, encoding: 'utf8' });
}
function totpCode(uri) {
  const geheim = new URL(uri.replace('otpauth://', 'http://')).searchParams.get('secret');
  return execFileSync('php', [path.join(WURZEL, 'tools/tests/totp_code.php'), geheim], { encoding: 'utf8' }).trim();
}

/** Im Browser: Logo durch einen neutralen Schriftzug ersetzen, Markennamen in Texten tauschen. */
async function neutral(p) {
  await p.evaluate(() => {
    const ersetzen = [
      ['ALLGÄU AKUT – Fortbildung Notfallmedizin', 'Fortbildung Notfallmedizin'],
      ['ALLGÄU AKUT - Fortbildung Notfallmedizin', 'Fortbildung Notfallmedizin'],
      ['Allgäuer ', ''], ['Allgäu Akut', 'Fortbildung Notfallmedizin'], ['ALLGÄU AKUT', 'FORTBILDUNG NOTFALLMEDIZIN'], ['Allgäu', 'Musterstadt'],
    ];
    const tausch = t => ersetzen.reduce((s, [a, b]) => s.split(a).join(b), t);
    document.querySelectorAll('img.logo').forEach(img => {
      if (getComputedStyle(img).display === 'none') { img.remove(); return; }
      const s = document.createElement('span');
      s.textContent = 'FORTBILDUNG NOTFALLMEDIZIN';
      s.style.cssText = 'display:block;font-family:Antonio,sans-serif;font-weight:700;font-size:20px;letter-spacing:.04em;line-height:46px;color:inherit;white-space:nowrap';
      if (img.parentElement) img.parentElement.style.textDecoration = 'none';
      img.replaceWith(s);
    });
    const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let n = w.nextNode(); n; n = w.nextNode()) n.nodeValue = tausch(n.nodeValue);
    document.querySelectorAll('[alt],[title],[placeholder],[aria-label]').forEach(el => {
      for (const a of ['alt', 'title', 'placeholder', 'aria-label']) if (el.hasAttribute(a)) el.setAttribute(a, tausch(el.getAttribute(a)));
    });
    document.querySelectorAll('input[value],textarea').forEach(el => { el.value = tausch(el.value); });
    // Vorschau einer Mail im iframe: Logo-Bild durch Schriftzug, Texte tauschen
    document.querySelectorAll('iframe').forEach(f => {
      try {
        const d = f.contentDocument;
        if (!d || !d.body) return;
        d.querySelectorAll('img').forEach(img => {
          if (!/logo/i.test(img.getAttribute('src') || '')) return;
          const s = d.createElement('div');
          s.textContent = 'FORTBILDUNG NOTFALLMEDIZIN';
          s.style.cssText = 'font-family:Arial,sans-serif;font-weight:700;font-size:22px;letter-spacing:.04em;color:#fff;text-align:center;padding:8px 0';
          img.replaceWith(s);
        });
        d.body.innerHTML = tausch(d.body.innerHTML);
      } catch (e) { /* fremde Herkunft */ }
    });
  });
  const rest = await p.evaluate(() => {
    const rahmen = [...document.querySelectorAll('iframe')].map(f => { try { return f.contentDocument ? f.contentDocument.body.innerHTML : ''; } catch (e) { return ''; } }).join(' ');
    const html = document.body.innerHTML + rahmen + [...document.querySelectorAll('input,textarea')].map(e => e.value).join(' ');
    return (html.match(/allg(ä|ae|a)u/gi) || []).length;
  });
  if (rest > 0) throw new Error('Auf ' + p.url() + ' steht noch ' + rest + '× „Allgäu“.');
}

/** Foto: Fenster (oder Element), danach auf die Zielbreite verkleinert und als PNG mit 256 Farben gespeichert. */
async function foto(p, name, opt = {}) {
  await neutral(p);
  await p.waitForTimeout(300);
  const roh = path.join(ROH, name + '.png');
  if (opt.element) {
    await p.locator(opt.element).first().screenshot({ path: roh });
  } else {
    await p.screenshot({ path: roh, fullPage: !!opt.ganz, clip: opt.clip });
  }
  const breite = opt.breite || 1200;
  execFileSync('convert', [roh, '-resize', breite + 'x>', '-strip', '+dither', '-colors', '256', 'PNG8:' + path.join(ZIEL, name + '.png')]);
  console.log('  ' + name + '.png');
}

(async () => {
  fs.mkdirSync(ZIEL, { recursive: true });
  const vor = JSON.parse(php([path.join(WURZEL, 'tools/browser/vorbereiten.php'), ROH], ''));
  // Neutrale Fassung der Beispieldaten und die Teilnahmebescheinigung als Bild
  php(['-r', `require '${path.join(WURZEL, 'server/bootstrap.php')}'; require '${path.join(WURZEL, 'server/testdaten/beispieldaten.php')}'; beispieldaten_neutral();
    file_put_contents('${path.join(ROH, 'zertifikat.pdf')}', zertifikat_pdf(zertifikat_beispiel()));
    db_ausfuehren('UPDATE anmeldung SET erstellt_am = erstellt_am - INTERVAL (id * 3) DAY, bestaetigt_am = bestaetigt_am - INTERVAL (id * 3) DAY WHERE bestaetigt_am IS NOT NULL');`], vor.config);
  // Hintergrund-Jobs einmal laufen lassen: sonst meldet die Übersicht „Cron noch nie gelaufen“
  php([path.join(WURZEL, 'server/cli/jobs.php')], vor.config);
  execFileSync('pdftoppm', ['-png', '-r', '110', '-singlefile', path.join(ROH, 'zertifikat.pdf'), path.join(ROH, 'zertifikat-roh')]);
  // Bildmarke der Marke oben links (19 × 19 mm ab 15,6/12,5 mm) mit der Papierfarbe überdecken
  const mm = 110 / 25.4;
  execFileSync('convert', [path.join(ROH, 'zertifikat-roh.png'), '-fill', 'rgb(246,248,250)', '-draw',
    `rectangle ${Math.floor(15 * mm)},${Math.floor(12 * mm)},${Math.ceil(36 * mm)},${Math.ceil(32 * mm)}`, path.join(ROH, 'zertifikat-ohne.png')]);
  const pdfText = execFileSync('pdftotext', [path.join(ROH, 'zertifikat.pdf'), '-'], { encoding: 'utf8' });
  if (/allg(ä|ae|a)u/i.test(pdfText)) throw new Error('Die Bescheinigung nennt noch „Allgäu“.');
  execFileSync('convert', [path.join(ROH, 'zertifikat-ohne.png'), '-resize', '700x>', '-strip', '+dither', '-colors', '256', 'PNG8:' + path.join(ZIEL, 'zertifikat.png')]);
  console.log('  zertifikat.png');

  const port = 19000 + Math.floor(Math.random() * 900);
  const basis = `http://127.0.0.1:${port}`;
  const server = spawn('php', ['-d', 'output_buffering=0', '-S', `127.0.0.1:${port}`, '-t', path.join(WURZEL, 'server/public'), path.join(WURZEL, 'server/public/index.php')],
    { env: { ...process.env, WS_CONFIG: vor.config }, stdio: 'ignore' });
  await new Promise(r => setTimeout(r, 700));
  const deUmgebung = { ...process.env, LANG: 'de_DE.UTF-8', LANGUAGE: 'de_DE:de', LC_ALL: 'de_DE.UTF-8' };
  const browser = await pw.chromium.launch({ args: ['--lang=de-DE'], env: deUmgebung });
  try {
    const neuerKontext = (w, h) => browser.newContext({ viewport: { width: w, height: h }, locale: 'de-DE', timezoneId: 'Europe/Berlin' });

    // Öffentlich: Startseite (Desktop und Handy), Programm, Anmeldung
    let ctx = await neuerKontext(1440, 900);
    let p = await ctx.newPage();
    await p.goto(basis + '/');
    await foto(p, 'startseite');
    await foto(p, 'programm', { element: 'ol.programm' });
    await p.goto(basis + '/anmelden');
    await p.locator('#workshops').scrollIntoViewIfNeeded();
    await p.evaluate(() => window.scrollBy(0, -120));
    await foto(p, 'anmeldung');
    await ctx.close();

    ctx = await neuerKontext(390, 844);
    p = await ctx.newPage();
    await p.goto(basis + '/');
    await foto(p, 'startseite-handy', { breite: 390 });
    await ctx.close();

    // Teilnehmende: Meine Anmeldung (Lea mit Konto)
    ctx = await neuerKontext(1440, 900);
    p = await ctx.newPage();
    await p.goto(basis + '/einloggen');
    await p.fill('#email', vor.lea.email); await p.fill('#passwort', vor.lea.passwort);
    await Promise.all([p.waitForNavigation(), p.click('form:has(input[value=passwort]) button')]);
    await p.goto(basis + '/deine-anmeldung');
    await foto(p, 'meine-anmeldung');
    await ctx.close();

    // Team: BetreiberIn mit Einrichtung des zweiten Faktors
    ctx = await neuerKontext(1440, 900);
    p = await ctx.newPage();
    await p.goto(basis + '/einloggen');
    await p.fill('#email', vor.betreiber); await p.fill('#passwort', vor.passwort);
    await Promise.all([p.waitForNavigation(), p.click('form:has(input[value=passwort]) button')]);
    await p.waitForSelector('svg.qr[data-qr]', { timeout: 5000 });
    await p.fill('#totp', totpCode(await p.getAttribute('svg.qr', 'data-qr')));
    await Promise.all([p.waitForNavigation(), p.click('form:has(input[value=einrichten]) button')]);
    await p.goto(basis + '/team');
    await foto(p, 'team-uebersicht');
    await p.goto(basis + '/team/programm');
    await foto(p, 'team-programm');
    await p.goto(basis + '/team/statistik');
    await foto(p, 'team-statistik');
    await p.goto(basis + '/team/mailings?id=' + vor.mailing_entwurf);
    await p.waitForTimeout(800); // Vorschau im iframe
    await foto(p, 'team-mailing');
    await ctx.close();
  } finally {
    await browser.close();
    server.kill();
  }
  console.log('Bilder in ' + path.relative(WURZEL, ZIEL) + '/');
})().catch(e => { console.error(e); process.exit(1); });
