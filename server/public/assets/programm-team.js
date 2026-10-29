/*
 * Programm-Pflege im Team-Bereich (AP-3 Schritt 3, E-39):
 * - Ziehen am Griff ⋮⋮: Ablegen auf einer Zeile schlägt als neuen Beginn das
 *   Ende dieser Zeile vor (auf einem Workshop-Block: dessen Beginn); die Dauer
 *   bleibt. Gespeichert wird über das versteckte Formular „verschieben“.
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
      liste.querySelectorAll('.ziel').forEach(function (z) { z.classList.remove('ziel'); });
    });
    liste.addEventListener('dragover', function (ev) {
      var ziel = ev.target.closest('.pg-zeile');
      if (!gezogen || !ziel || ziel === gezogen || !ziel.getAttribute('data-beginn')) { return; }
      ev.preventDefault();
      liste.querySelectorAll('.ziel').forEach(function (z) { z.classList.remove('ziel'); });
      ziel.classList.add('ziel');
    });
    liste.addEventListener('drop', function (ev) {
      var ziel = ev.target.closest('.pg-zeile');
      if (!gezogen || !ziel || ziel === gezogen || !ziel.getAttribute('data-beginn')) { return; }
      ev.preventDefault();
      var beginn = ziel.classList.contains('block') ? ziel.getAttribute('data-beginn') : ziel.getAttribute('data-ende');
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
