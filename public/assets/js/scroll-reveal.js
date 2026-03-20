/**
 * scroll-reveal.js — Animations au scroll sans dépendance externe
 * Remplace AOS : utilise Intersection Observer pour révéler les éléments [data-aos]
 * Respecte prefers-reduced-motion pour l'accessibilité
 *
 * Stratégie : .js-scroll-reveal n'est ajouté qu'au moment de init() APRÈS
 * avoir pré-détecté les éléments dans le viewport, évitant tout fondu de
 * disparition (le CSS opacity:0 et sr-visible sont appliqués dans le même
 * tick, donc aucune transition indésirable).
 */
(function() {
  'use strict';

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.documentElement.classList.add('js-scroll-reveal');
    document.querySelectorAll('[data-aos]').forEach(function(el) {
      el.classList.add('sr-visible');
    });
    document.body.classList.remove('is-preload');
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

  function isInViewport(el) {
    var rect = el.getBoundingClientRect();
    var vh = window.innerHeight || document.documentElement.clientHeight;
    return rect.top < vh && rect.bottom > 0;
  }

  function init() {
    var elements = document.querySelectorAll('[data-aos]');

    // Pré-détecter les éléments visibles AVANT d'activer le CSS de masquage
    var visibleNow = [];
    elements.forEach(function(el) {
      if (isInViewport(el)) {
        visibleNow.push(el);
      }
    });

    // Activer le masquage CSS (opacity: 0 via .js-scroll-reveal)
    document.documentElement.classList.add('js-scroll-reveal');

    // Révéler immédiatement les éléments pré-détectés (même tick, pas de fondu)
    visibleNow.forEach(function(el) {
      el.classList.add('sr-visible');
    });

    // Maintenant activer les transitions en retirant is-preload
    document.body.classList.remove('is-preload');

    // Observer les éléments restants pour le reveal au scroll
    elements.forEach(function(el) {
      if (!el.classList.contains('sr-visible')) {
        observer.observe(el);
      }
    });

    // Re-vérification après stabilisation du layout
    requestAnimationFrame(function() {
      requestAnimationFrame(function() {
        elements.forEach(function(el) {
          if (!el.classList.contains('sr-visible') && isInViewport(el)) {
            el.classList.add('sr-visible');
            observer.unobserve(el);
          }
        });
      });
    });

    // Filet de sécurité : révéler tout après 3 s si le JS échoue
    setTimeout(function() {
      elements.forEach(function(el) {
        if (!el.classList.contains('sr-visible')) {
          el.classList.add('sr-visible');
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
