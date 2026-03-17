/**
 * Back-to-top : affiche le bouton après scroll > 100px, scroll smooth au clic
 * Respecte prefers-reduced-motion
 */
(function() {
  'use strict';
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var btn = document.querySelector('.back-to-top');
    if (btn) btn.style.display = 'none';
    return;
  }
  var backToTop = document.querySelector('.back-to-top');
  if (!backToTop) return;
  function onScroll() {
    backToTop.classList.toggle('active', (window.scrollY || document.documentElement.scrollTop) > 100);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('load', onScroll);
  backToTop.addEventListener('click', function(e) {
    e.preventDefault();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
})();
