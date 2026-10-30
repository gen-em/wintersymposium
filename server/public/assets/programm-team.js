/*
 * Programm-Pflege im Team-Bereich (AP-3 Schritt 3, E-39):
 * - Ziehen am Griff ⋮⋮ zwischen zwei Punkte (Admin 2026-10-29), mit Maus,
 *   Finger oder Stift: eine weiße Linie zeigt, ob vor oder nach der Zeile
 *   unter dem Zeiger eingefügt wird. Neuer Beginn = Ende des Punkts darüber
 *   (als erster Punkt in einem Workshop-Block: dessen Beginn; ganz oben:
 *   Beginn des ersten Punkts minus Dauer). Mitte eines Blocks:
 *   Workshop, Station, Vortrag oder Fallbesprechung zuordnen (Rückmeldung 2026-10-30). Die Dauer
 *   bleibt; nachfolgende Punkte rücken auf dem Server nach hinten, bis eine
 *   Lücke reicht. Gespeichert wird über das versteckte Formular „verschieben“.
 * - Felder je Typ ein- und ausblenden (data-fuer), Kürzel-Vorschlag beim Typwechsel.
 * - Termine: „+ Termin“ fügt eine leere Zeile an, „Duplizieren“ legt den
 *   nächsten Termin im Anschluss an (gleiche Dauer und Plätze).
 * Ohne Skript bleibt alles über das Formular bedienbar.
 */
(function () {
  'use strict';

  function min(hm) { var t = String(hm).split(':'); return Number(t[0]) * 60 + Number(t[1]); }
  function hm(m) { m = Math.max(0, Math.min(23 * 60 + 59, m)); var h = Math.floor(m / 60), n = m % 60; return (h < 10 ? '0' : '') + h + ':' + (n < 10 ? '0' : '') + n; }

  // ---- Ziehen im Plan ----
  // Rückmeldung 2026-10-30: weiße Einfügelinie zwischen zwei Punkten; Mitte eines Blocks =
  // Workshop/Station zuordnen. Rückmeldung 2026-10-30 (2): Zeiger-Ereignisse statt HTML-Ziehen,
  // damit es auch mit dem Finger geht; ohne Feld „ans Ende“ (Linie unter dem letzten Punkt).
  var liste = document.getElementById('pg-liste');
  var form = document.getElementById('verschieben-form');
  var zform = document.getElementById('zuordnen-form');
  var gezogen = null, geist = null, versatz = 0, rollen = 0, rollTimer = null;
  var ziel = null, wo = '', letzt = null;
  function aufraeumen() { liste.querySelectorAll('.ziel-vor, .ziel-nach, .ziel-in').forEach(function (z) { z.classList.remove('ziel-vor', 'ziel-nach', 'ziel-in'); }); }
  function kopfVonLi(li) { return li ? li.querySelector(':scope > .pg-zeile') : null; }
  // Rückmeldung 2026-10-30 (2): auch Vorträge und Fallbesprechungen in Blöcke
  function zuordenbar() { var t = gezogen && gezogen.getAttribute('data-typ'); return ['vortrag', 'fall', 'workshop', 'station'].indexOf(t) !== -1; }
  function imBlock(zeile) { var ol = zeile.closest('ol'); return ol && ol.classList.contains('pg-kinder') ? kopfVonLi(ol.closest('li')) : null; }
  function dauer() { return gezogen.getAttribute('data-beginn') && gezogen.getAttribute('data-ende') ? min(gezogen.getAttribute('data-ende')) - min(gezogen.getAttribute('data-beginn')) : 30; }
  /** Beginn für das Einfügen vor bzw. nach der Zeile. */
  function beginnFuer(zeile, vor) {
    if (!vor) {
      return zeile.getAttribute('data-ende');
    }
    var li = zeile.closest('li');
    var davor = li ? li.previousElementSibling : null;
    while (davor && kopfVonLi(davor) === gezogen) { davor = davor.previousElementSibling; }
    if (davor) {
      var k = kopfVonLi(davor);
      if (k && k.getAttribute('data-ende')) { return k.getAttribute('data-ende'); }
    }
    var block = imBlock(zeile);
    if (block) { return block.getAttribute('data-beginn'); }
    return hm(min(zeile.getAttribute('data-beginn')) - dauer());
  }
  /** Zone unter dem Zeiger: vor, nach oder in (nur Workshop/Station auf einen Block). */
  function zone(zeile, y) {
    var r = zeile.getBoundingClientRect();
    var anteil = (y - r.top) / r.height;
    if (zeile.classList.contains('block') && zuordenbar() && anteil > 0.25 && anteil < 0.75) { return 'in'; }
    return anteil < 0.5 ? 'vor' : 'nach';
  }
  /** Die Linie hängt am Listeneintrag: „nach“ einem Block steht sie unter dem ganzen Block. */
  function markieren() {
    aufraeumen();
    if (!ziel) { return; }
    if (wo === 'in') { ziel.classList.add('ziel-in'); return; }
    var li = ziel.closest('li');
    if (li) { li.classList.add(wo === 'vor' ? 'ziel-vor' : 'ziel-nach'); }
  }
  function zielAn(x, y) {
    var el = document.elementFromPoint(x, y);
    var z = el && liste.contains(el) ? el.closest('.pg-zeile') : null;
    if (!z || z === gezogen || !z.getAttribute('data-beginn')) {
      return;
    }
    ziel = z;
    wo = zone(z, y);
    markieren();
  }
  function rollenSetzen(y) {
    var h = window.innerHeight, rand = 56;
    rollen = y < rand ? -Math.ceil((rand - y) / 4) : (y > h - rand ? Math.ceil((y - h + rand) / 4) : 0);
    if (rollen && !rollTimer) {
      rollTimer = window.setInterval(function () {
        if (!rollen) { window.clearInterval(rollTimer); rollTimer = null; return; }
        window.scrollBy(0, rollen);
        if (letzt) { zielAn(letzt.x, letzt.y); }
      }, 16);
    }
  }
  function beenden() {
    if (geist) { geist.remove(); geist = null; }
    if (gezogen) { gezogen.classList.remove('wird-gezogen'); }
    document.documentElement.classList.remove('pg-zieht');
    rollen = 0;
    aufraeumen();
    gezogen = null; ziel = null; wo = ''; letzt = null;
  }
  function absenden() {
    var z = ziel, w = wo, g = gezogen;
    if (!z || !g) { return; }
    if (w === 'in') {
      zform.elements.id.value = g.getAttribute('data-punkt');
      zform.elements.block.value = z.getAttribute('data-punkt');
      zform.submit();
      return;
    }
    var block = '';
    if (zuordenbar()) {
      var b = imBlock(z);
      // „nach“ einem Block = hinter den ganzen Block, nicht hinein
      block = b ? b.getAttribute('data-punkt') : '0';
    }
    form.elements.art.value = g.getAttribute('data-art');
    form.elements.id.value = g.getAttribute('data-id');
    form.elements.beginn.value = beginnFuer(z, w === 'vor');
    form.elements.block.value = block;
    if (form.elements.beginn.value) { form.submit(); }
  }
  if (liste && form) {
    liste.addEventListener('pointerdown', function (ev) {
      var griff = ev.target.closest('.griff');
      if (!griff || (ev.pointerType === 'mouse' && ev.button !== 0)) { return; }
      ev.preventDefault();
      gezogen = griff.closest('.pg-zeile');
      try { griff.setPointerCapture(ev.pointerId); } catch (e) { /* ausgelöste Ereignisse ohne echten Zeiger */ }
      var r = gezogen.getBoundingClientRect();
      versatz = ev.clientY - r.top;
      geist = gezogen.cloneNode(true);
      geist.classList.add('pg-geist');
      geist.setAttribute('aria-hidden', 'true');
      geist.style.width = r.width + 'px';
      geist.style.left = r.left + 'px';
      geist.style.top = r.top + 'px';
      document.body.appendChild(geist);
      gezogen.classList.add('wird-gezogen');
      document.documentElement.classList.add('pg-zieht');
    });
    liste.addEventListener('pointermove', function (ev) {
      if (!gezogen) { return; }
      ev.preventDefault();
      letzt = { x: ev.clientX, y: ev.clientY };
      geist.style.top = (ev.clientY - versatz) + 'px';
      zielAn(ev.clientX, ev.clientY);
      rollenSetzen(ev.clientY);
    });
    liste.addEventListener('pointerup', function (ev) {
      if (!gezogen) { return; }
      if (letzt) { zielAn(ev.clientX, ev.clientY); }
      var los = !!letzt;
      if (los) { absenden(); }
      beenden();
    });
    liste.addEventListener('pointercancel', beenden);
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && gezogen) { beenden(); } });
    // Altes HTML-Ziehen des Griffs unterbinden (Bilder/Text)
    liste.addEventListener('dragstart', function (ev) { if (ev.target.closest && ev.target.closest('.griff')) { ev.preventDefault(); } });
  }

  // ---- Formular: Felder je Typ, Kürzel-Vorschlag ----
  var pf = document.getElementById('pg-formular');
  if (pf) {
    var typ = pf.elements.typ;
    var kuerzel = pf.elements.kuerzel;
    var vorschlaege = JSON.parse(pf.getAttribute('data-vorschlaege') || '{}');
    var vorher = typ.value;
    var anzeigen = function () {
      pf.querySelectorAll('[data-fuer]').forEach(function (el) {
        el.hidden = el.getAttribute('data-fuer').split(' ').indexOf(typ.value) === -1;
      });
    };
    typ.addEventListener('change', function () {
      if (kuerzel && (kuerzel.value === '' || kuerzel.value === (vorschlaege[vorher] || ''))) { kuerzel.value = vorschlaege[typ.value] || ''; }
      vorher = typ.value;
      anzeigen();
    });
    anzeigen();

    // ---- Termine ----
    var tbody = document.getElementById('pg-termine');
    var neu = document.getElementById('pg-termin-neu');
    var zaehler = 0;
    // Dozierende je Termin: Zusammenfassung im Aufklapper („wie Workshop“ oder die Namen)
    var dozText = function (zeile) {
      var s = zeile.querySelector('.t-doz summary');
      if (!s) { return; }
      var namen = [];
      zeile.querySelectorAll('.t-doz input:checked').forEach(function (c) { namen.push(c.parentNode.textContent.trim()); });
      s.textContent = namen.length ? namen.join(', ') : 'wie Workshop';
    };
    var leereZeile = function () {
      var vorlage = tbody.querySelector('tr.vorlage') || tbody.lastElementChild;
      var z = vorlage.cloneNode(true);
      z.classList.add('vorlage');
      zaehler += 1;
      var schl = 'n' + zaehler;
      z.querySelectorAll('input').forEach(function (i) {
        if (i.name === 't_key[]') { i.value = schl; } else if (i.type === 'hidden') { i.value = '0'; } else if (i.type === 'checkbox') { i.checked = false; } else { i.value = ''; }
        if (i.name.indexOf('t_doz[') === 0) { i.name = 't_doz[' + schl + '][]'; }
      });
      var weg = z.querySelector('.mini-haken');
      if (weg) {
        var knopf = document.createElement('button');
        knopf.type = 'button'; knopf.className = 'mini'; knopf.setAttribute('data-entfernen', ''); knopf.textContent = 'Entfernen';
        weg.replaceWith(knopf);
      }
      var ent = z.querySelector('[data-entfernen]'); if (ent) { ent.hidden = false; }
      var det = z.querySelector('details'); if (det) { det.open = false; }
      z.querySelector('.n').textContent = '–';
      dozText(z);
      tbody.appendChild(z);
      return z;
    };
    if (neu && tbody) {
      tbody.querySelectorAll('[data-entfernen]').forEach(function (b) { b.hidden = false; });
      neu.addEventListener('click', function () { leereZeile().querySelector('input[type=time]').focus(); });
      tbody.addEventListener('change', function (ev) { if (ev.target.closest('.t-doz')) { dozText(ev.target.closest('tr')); } });
      tbody.addEventListener('click', function (ev) {
        if (ev.target.closest('[data-entfernen]')) {
          var zeile = ev.target.closest('tr');
          // Die letzte leere Zeile bleibt als Vorlage stehen, nur geleert
          if (tbody.querySelectorAll('tr').length === 1) { leereZeile(); }
          zeile.remove();
          return;
        }
        if (!ev.target.closest('[data-duplizieren]')) { return; }
        var quelle = ev.target.closest('tr');
        var b = quelle.querySelector('[name="t_beginn[]"]').value, e = quelle.querySelector('[name="t_ende[]"]').value;
        var p = quelle.querySelector('[name="t_plaetze[]"]').value;
        var gewaehlt = [];
        quelle.querySelectorAll('.t-doz input:checked').forEach(function (c) { gewaehlt.push(c.value); });
        var z = leereZeile();
        if (b && e) {
          z.querySelector('[name="t_beginn[]"]').value = e;
          z.querySelector('[name="t_ende[]"]').value = hm(min(e) + (min(e) - min(b)));
        }
        z.querySelector('[name="t_plaetze[]"]').value = p;
        z.querySelectorAll('.t-doz input').forEach(function (c) { c.checked = gewaehlt.indexOf(c.value) !== -1; });
        dozText(z);
      });
    }
  }
})();
