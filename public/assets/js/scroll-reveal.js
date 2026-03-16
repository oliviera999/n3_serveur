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

  function init() {
    document.body.classList.remove('is-preload');
    document.querySelectorAll('[data-aos]').forEach(function(el) {
      observer.observe(el);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
