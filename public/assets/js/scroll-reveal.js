/**
 * scroll-reveal.js — Animations au scroll sans dépendance externe
 * Remplace AOS : utilise Intersection Observer pour révéler les éléments [data-aos]
 * Respecte prefers-reduced-motion pour l'accessibilité
 */
(function() {
  document.documentElement.classList.add('js-scroll-reveal');
  'use strict';

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.body.classList.remove('is-preload');
    document.querySelectorAll('[data-aos]').forEach(function(el) {
      el.classList.add('sr-visible');
    });
    return;
  }

  var observer = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
      if (entry.isIntersecting) {
        var el = entry.target;
        observer.unobserve(el);
        var delay = parseInt(el.getAttribute('data-aos-delay'), 10) || 0;
        if (delay > 0) {
          setTimeout(function() { el.classList.add('sr-visible'); }, delay);
        } else {
          el.classList.add('sr-visible');
        }
      }
    });
  }, {
    rootMargin: '0px 0px -50px 0px',
    threshold: 0.01
  });

  /** Vérifie si un élément est déjà dans le viewport (avec marge) et le révèle immédiatement */
  function revealInViewport(el) {
    var rect = el.getBoundingClientRect();
    var margin = 50;
    var inView = rect.top < (window.innerHeight || document.documentElement.clientHeight) - margin
      && rect.bottom > margin;
    if (inView) {
      el.classList.add('sr-visible');
      observer.unobserve(el);
      return true;
    }
    return false;
  }

  function init() {
    document.body.classList.remove('is-preload');
    var elements = document.querySelectorAll('[data-aos]');
    elements.forEach(function(el) {
      observer.observe(el);
    });
    /* Révéler immédiatement les éléments déjà à l'écran (évite fond blanc sur pages serre / données) */
    function revealVisibleNow() {
      elements.forEach(function(el) {
        if (!el.classList.contains('sr-visible')) {
          revealInViewport(el);
        }
      });
    }
    requestAnimationFrame(function() {
      requestAnimationFrame(revealVisibleNow);
    });
    setTimeout(revealVisibleNow, 150);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
