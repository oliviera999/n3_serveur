/**
 * Lightbox galeries — affichage plein écran au clic sur une photo.
 * Navigation précédent/suivant parmi les photos visibles sur la page.
 * Vanilla JS, pas de dépendance.
 */
(function () {
  'use strict';

  var overlay = null;
  var imgEl = null;
  var captionEl = null;
  var prevBtn = null;
  var nextBtn = null;
  var closeBtn = null;
  var currentIndex = 0;
  var imageUrls = [];

  function getElements() {
    overlay = document.getElementById('gallery-lightbox');
    if (!overlay) return false;
    imgEl = overlay.querySelector('.gallery-lightbox-img');
    captionEl = overlay.querySelector('.gallery-lightbox-caption');
    prevBtn = overlay.querySelector('.gallery-lightbox-prev');
    nextBtn = overlay.querySelector('.gallery-lightbox-next');
    closeBtn = overlay.querySelector('.gallery-lightbox-close');
    return Boolean(imgEl);
  }

  function collectUrls() {
    var links = document.querySelectorAll('.gallery-grid .gallery-item a[href]');
    imageUrls = Array.prototype.map.call(links, function (a) { return a.getAttribute('href'); });
  }

  function show(index) {
    if (!overlay || index < 0 || index >= imageUrls.length) return;
    currentIndex = index;
    imgEl.src = imageUrls[index];
    imgEl.alt = 'Photo ' + (index + 1);
    if (captionEl) captionEl.textContent = 'Photo ' + (index + 1) + ' / ' + imageUrls.length;
    overlay.classList.add('gallery-lightbox-open');
    overlay.setAttribute('aria-hidden', 'false');
    if (prevBtn) prevBtn.style.display = currentIndex > 0 ? '' : 'none';
    if (nextBtn) nextBtn.style.display = currentIndex < imageUrls.length - 1 ? '' : 'none';
    document.body.style.overflow = 'hidden';
  }

  function hide() {
    if (!overlay) return;
    overlay.classList.remove('gallery-lightbox-open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  function goPrev() {
    if (currentIndex > 0) show(currentIndex - 1);
  }

  function goNext() {
    if (currentIndex < imageUrls.length - 1) show(currentIndex + 1);
  }

  function onKeyDown(e) {
    if (!overlay || !overlay.classList.contains('gallery-lightbox-open')) return;
    if (e.key === 'Escape') { e.preventDefault(); hide(); }
    if (e.key === 'ArrowLeft') { e.preventDefault(); goPrev(); }
    if (e.key === 'ArrowRight') { e.preventDefault(); goNext(); }
  }

  function init() {
    if (!getElements()) return;
    collectUrls();

    document.querySelectorAll('.gallery-grid .gallery-item a[href]').forEach(function (a, index) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var idx = imageUrls.indexOf(a.getAttribute('href'));
        if (idx !== -1) show(idx);
      });
    });

    if (closeBtn) closeBtn.addEventListener('click', function (e) { e.preventDefault(); hide(); });
    if (prevBtn) prevBtn.addEventListener('click', function (e) { e.preventDefault(); goPrev(); });
    if (nextBtn) nextBtn.addEventListener('click', function (e) { e.preventDefault(); goNext(); });

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) hide();
    });

    document.addEventListener('keydown', onKeyDown);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
