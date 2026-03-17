/**
 * scroll-progress.js — Barre de progression au scroll + header scrolled (fiche 09)
 * Met à jour la largeur en % selon la position de défilement.
 * Toggle la classe header-scrolled sur #header quand scrollY > 100.
 * Respecte prefers-reduced-motion pour la barre (pas pour le header).
 */
(function() {
  'use strict';

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var bar = document.getElementById('scroll-progress');
  var header = document.getElementById('header');

  if (reduceMotion && bar) {
    bar.style.display = 'none';
  }

  function update() {
    var scrollY = window.scrollY || document.documentElement.scrollTop || 0;

    if (header) {
      header.classList.toggle('header-scrolled', scrollY > 100);
    }

    if (bar && !reduceMotion) {
      var docHeight = document.documentElement.scrollHeight - window.innerHeight;
      var pct = docHeight > 0 ? Math.min(100, (scrollY / docHeight) * 100) : 0;
      bar.style.width = pct + '%';
    }
  }

  window.addEventListener('scroll', update, { passive: true });
  window.addEventListener('resize', update);
  update();
})();
