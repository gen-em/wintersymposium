/*
 * Programm-Pflege im Team-Bereich (AP-3 Schritt 3, E-39):
 * - Ziehen am Griff ⋮⋮ zwischen zwei Punkte (Admin 2026-10-29): eine grüne
 *   Linie zeigt, ob vor oder nach der Zeile unter dem Zeiger eingefügt wird.
 *   Neuer Beginn = Ende des Punkts darüber (direkt unter einem Workshop-Block:
 *   dessen Beginn; ganz oben: Beginn des ersten Punkts minus Dauer). Die Dauer
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
  var liste = document.getElementById('pg-liste');
  var form = document.getElementById('verschieben-form');
  var gezogen = null;
  var zielZeile = null, zielVor = false;
  function aufraeumen() { liste.querySelectorAll('.ziel-vor, .ziel-nach').forEach(function (z) { z.classList.remove('ziel-vor', 'ziel-nach'); }); }
  function kopfVonLi(li) { return li ? li.querySelector(':scope > .pg-zeile') : null; }
  /** Beginn für das Einfügen vor bzw. nach der Zeile. */
  function beginnFuer(zeile, vor) {
    if (!vor) {
      return zeile.classList.contains('block') ? zeile.getAttribute('data-beginn') : zeile.getAttribute('data-ende');
    }
    var li = zeile.closest('li');
    var davor = li ? li.previousElementSibling : null;
    while (davor && kopfVonLi(davor) === gezogen) { davor = davor.previousElementSibling; }
    if (davor) {
      var k = kopfVonLi(davor);
      if (k && k.getAttribute('data-ende')) { return k.getAttribute('data-ende'); }
    }
    var block = li && li.parentElement && li.parentElement.classList.contains('pg-kinder') ? kopfVonLi(li.parentElement.closest('li')) : null;
    if (block) { return block.getAttribute('data-beginn'); }
    var g = gezogen.getAttribute('data-beginn') && gezogen.getAttribute('data-ende') ? min(gezogen.getAttribute('data-ende')) - min(gezogen.getAttribute('data-beginn')) : 30;
    return hm(min(zeile.getAttribute('data-beginn')) - g);
  }
  if (liste && form) {
    liste.addEventListener('dragstart', function (ev) {
      var griff = ev.target.closest('.griff');
      if (!griff) { return; }
      gezogen = griff.closest('.pg-zeile');
      gezogen.classList.add('wird-gezogen');
      ev.dataTransfer.effectAllowed = 'move';
      ev.dataTransfer.setData('text/plain', gezogen.getAttribute('data-id'));
    });
    liste.addEventListener('dragend', function () {
      if (gezogen) { gezogen.classList.remove('wird-gezogen'); }
      aufraeumen();
    });
    liste.addEventListener('dragover', function (ev) {
      var ziel = ev.target.closest('.pg-zeile');
      if (!gezogen || !ziel || ziel === gezogen || !ziel.getAttribute('data-beginn')) { return; }
      ev.preventDefault();
      var r = ziel.getBoundingClientRect();
      zielZeile = ziel;
      zielVor = ev.clientY < r.top + r.height / 2;
      aufraeumen();
      ziel.classList.add(zielVor ? 'ziel-vor' : 'ziel-nach');
    });
    liste.addEventListener('drop', function (ev) {
      var ziel = ev.target.closest('.pg-zeile');
      if (!gezogen || !ziel || ziel === gezogen || !ziel.getAttribute('data-beginn')) { return; }
      ev.preventDefault();
      var r = ziel.getBoundingClientRect();
      var vor = ev.clientY ? ev.clientY < r.top + r.height / 2 : (ziel === zielZeile && zielVor);
      var beginn = beginnFuer(ziel, vor);
      form.elements.art.value = gezogen.getAttribute('data-art');
      form.elements.id.value = gezogen.getAttribute('data-id');
      form.elements.beginn.value = beginn;
      form.submit();
    });
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
    var leereZeile = function () {
      var vorlage = tbody.querySelector('tr.vorlage') || tbody.lastElementChild;
      var z = vorlage.cloneNode(true);
      z.classList.add('vorlage');
      z.querySelectorAll('input').forEach(function (i) { if (i.type === 'hidden') { i.value = '0'; } else if (i.type !== 'checkbox') { i.value = ''; } });
      var weg = z.querySelector('.mini-haken'); if (weg) { weg.remove(); }
      z.querySelector('.n').textContent = '–';
      tbody.appendChild(z);
      return z;
    };
    if (neu && tbody) {
      neu.addEventListener('click', function () { leereZeile().querySelector('input[type=time]').focus(); });
      tbody.addEventListener('click', function (ev) {
        if (!ev.target.closest('[data-duplizieren]')) { return; }
        var quelle = ev.target.closest('tr');
        var b = quelle.querySelector('[name="t_beginn[]"]').value, e = quelle.querySelector('[name="t_ende[]"]').value;
        var p = quelle.querySelector('[name="t_plaetze[]"]').value;
        var z = leereZeile();
        if (b && e) {
          z.querySelector('[name="t_beginn[]"]').value = e;
          z.querySelector('[name="t_ende[]"]').value = hm(min(e) + (min(e) - min(b)));
        }
        z.querySelector('[name="t_plaetze[]"]').value = p;
      });
    }
  }
})();
