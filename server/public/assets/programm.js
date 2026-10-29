/*
 * Programm auf der Startseite (AP-3 Schritt 3, E-40):
 * - Popups: Knöpfe mit data-dialog öffnen das <dialog> mit dieser id;
 *   ✕, Klick auf den Hintergrund und Escape schließen es.
 * - Zeitraster der Workshop-Blöcke: setzt Lage und Breite der Balken aus
 *   data-von/data-bis (Minuten). Die Sicherheitsregeln (CSP) verbieten
 *   style-Attribute im HTML; über das DOM gesetzte Stile sind erlaubt.
 *   Ohne Skript bleibt die Liste sichtbar.
 */
(function () {
  'use strict';

  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('[data-dialog]');
    if (a) {
      var d = document.getElementById(a.getAttribute('data-dialog'));
      if (d && typeof d.showModal === 'function') { ev.preventDefault(); d.showModal(); }
      return;
    }
    var zu = ev.target.closest('[data-schliessen]');
    if (zu) { zu.closest('dialog').close(); return; }
    if (ev.target.tagName === 'DIALOG' && ev.target.open) {
      // Klick auf den Hintergrund (außerhalb des Inhalts)
      var r = ev.target.getBoundingClientRect();
      if (ev.clientX < r.left || ev.clientX > r.right || ev.clientY < r.top || ev.clientY > r.bottom) { ev.target.close(); }
    }
  });

  function hm(min) { var h = Math.floor(min / 60), m = min % 60; return h + ':' + (m < 10 ? '0' : '') + m; }

  document.querySelectorAll('.gantt-wrap').forEach(function (wrap) {
    var von = Number(wrap.getAttribute('data-von')), bis = Number(wrap.getAttribute('data-bis')), spanne = bis - von;
    if (!(spanne > 0)) { return; }
    wrap.querySelectorAll('.gb').forEach(function (b) {
      var a = Number(b.getAttribute('data-von')), e = Number(b.getAttribute('data-bis'));
      b.style.left = ((a - von) / spanne * 100) + '%';
      b.style.width = ((e - a) / spanne * 100) + '%';
    });
    var skala = wrap.querySelector('.skala');
    var schritt = spanne > 180 ? 30 : 15;
    for (var m = Math.ceil(von / schritt) * schritt; m <= bis; m += schritt) {
      var s = document.createElement('span');
      s.style.left = ((m - von) / spanne * 100) + '%';
      s.textContent = (m % 30 === 0) ? hm(m) : '';
      // Rand-Beschriftungen bündig, damit nichts über den Rahmen ragt
      if (m === bis) { s.style.transform = 'translateX(-100%)'; } else if (m === von) { s.style.transform = 'none'; }
      skala.appendChild(s);
    }
    wrap.hidden = false;
    wrap.closest('.wsblock').classList.add('mit-raster');
  });
})();
