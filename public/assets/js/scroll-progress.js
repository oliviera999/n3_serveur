/**
 * scroll-progress.js — Barre de progression au scroll (style index.olution.info)
 * Met à jour la largeur en % selon la position de défilement.
 * Respecte prefers-reduced-motion pour l'accessibilité.
 */
(function() {
  'use strict';

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var el = document.getElementById('scroll-progress');
    if (el) el.style.display = 'none';
    return;
  }

  var bar = document.getElementById('scroll-progress');
  if (!bar) return;

  function update() {
    var scrollY = window.scrollY || window.pageYOffset;
    var docHeight = document.documentElement.scrollHeight - window.innerHeight;
    var pct = docHeight > 0 ? Math.min(100, (scrollY / docHeight) * 100) : 0;
    bar.style.width = pct + '%';
  }

  window.addEventListener('scroll', update, { passive: true });
  window.addEventListener('resize', update);
  update();
})();
