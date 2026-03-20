/**
 * scroll-reveal.js — Animations au scroll sans dépendance externe
 * Remplace AOS : utilise Intersection Observer pour révéler les éléments [data-aos]
 * Respecte prefers-reduced-motion pour l'accessibilité
 *
 * Stratégie « visible par défaut » :
 * - Le CSS ne masque aucun élément. Tout est visible sans JS.
 * - Le JS ajoute .sr-hidden aux seuls éléments hors viewport.
 * - L'Observer les révèle avec .sr-visible quand ils scrollent en vue.
 * - Filet de sécurité : timeout 3 s révèle tout ce qui reste caché.
 */
(function() {
  'use strict';

  var elements = [];

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }

  var observer = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
      if (entry.isIntersecting) {
        var el = entry.target;
        observer.unobserve(el);
        var delay = parseInt(el.getAttribute('data-aos-delay'), 10) || 0;
        if (delay > 0) {
          setTimeout(function() { reveal(el); }, delay);
        } else {
          reveal(el);
        }
      }
    });
  }, {
    rootMargin: '0px 0px -50px 0px',
    threshold: 0.01
  });

  function reveal(el) {
    el.classList.remove('sr-hidden');
    el.classList.add('sr-visible');
  }

  function isInViewport(el) {
    var rect = el.getBoundingClientRect();
    var vh = window.innerHeight || document.documentElement.clientHeight;
    return rect.top < vh && rect.bottom > 0;
  }

  function init() {
    elements = document.querySelectorAll('[data-aos]');
    document.body.classList.remove('is-preload');

    elements.forEach(function(el) {
      if (isInViewport(el)) {
        el.classList.add('sr-visible');
      } else {
        el.classList.add('sr-hidden');
        observer.observe(el);
      }
    });

    // Re-vérification après stabilisation du layout
    requestAnimationFrame(function() {
      requestAnimationFrame(function() {
        elements.forEach(function(el) {
          if (el.classList.contains('sr-hidden') && isInViewport(el)) {
            reveal(el);
            observer.unobserve(el);
          }
        });
      });
    });

    // Filet de sécurité : révéler tout après 3 s
    setTimeout(function() {
      elements.forEach(function(el) {
        if (el.classList.contains('sr-hidden')) {
          reveal(el);
          observer.unobserve(el);
        }
      });
    }, 3000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
