/**
 * Lightbox simple pour la galerie photo n3 IoT.
 * Navigation clavier (fleches, Echap), clic overlay pour fermer.
 */
(function () {
  'use strict';

  var lightbox = document.getElementById('gallery-lightbox');
  if (!lightbox) return;

  var img = lightbox.querySelector('.gallery-lightbox-img');
  var caption = lightbox.querySelector('.gallery-lightbox-caption');
  var closeBtn = lightbox.querySelector('.gallery-lightbox-close');
  var prevBtn = lightbox.querySelector('.gallery-lightbox-prev');
  var nextBtn = lightbox.querySelector('.gallery-lightbox-next');

  var triggers = Array.from(document.querySelectorAll('.gallery-lightbox-trigger'));
  var currentIndex = -1;

  function open(index) {
    if (index < 0 || index >= triggers.length) return;
    currentIndex = index;
    var href = triggers[index].getAttribute('href');
    img.src = href;
    img.alt = triggers[index].getAttribute('title') || '';
    caption.textContent = 'Photo ' + (index + 1) + ' / ' + triggers.length;
    lightbox.setAttribute('aria-hidden', 'false');
    lightbox.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }

  function close() {
    lightbox.setAttribute('aria-hidden', 'true');
    lightbox.style.display = 'none';
    img.src = '';
    document.body.style.overflow = '';
    currentIndex = -1;
  }

  function prev() {
    if (currentIndex > 0) open(currentIndex - 1);
  }

  function next() {
    if (currentIndex < triggers.length - 1) open(currentIndex + 1);
  }

  triggers.forEach(function (trigger, i) {
    trigger.addEventListener('click', function (e) {
      e.preventDefault();
      open(i);
    });
  });

  if (closeBtn) closeBtn.addEventListener('click', close);
  if (prevBtn) prevBtn.addEventListener('click', prev);
  if (nextBtn) nextBtn.addEventListener('click', next);

  lightbox.addEventListener('click', function (e) {
    if (e.target === lightbox) close();
  });

  document.addEventListener('keydown', function (e) {
    if (currentIndex < 0) return;
    if (e.key === 'Escape') close();
    else if (e.key === 'ArrowLeft') prev();
    else if (e.key === 'ArrowRight') next();
  });
})();
