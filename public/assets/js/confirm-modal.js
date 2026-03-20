/**
 * Modale de confirmation accessible — composant réutilisable
 * Usage : confirmModal({ title, message, confirmLabel, onConfirm })
 */
(function() {
    'use strict';

    var overlay = null;
    var previousFocus = null;

    function getOrCreateOverlay() {
        if (overlay) return overlay;

        overlay = document.createElement('div');
        overlay.className = 'confirm-modal-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'confirm-modal-title');
        overlay.innerHTML =
            '<div class="confirm-modal">' +
                '<h3 id="confirm-modal-title"></h3>' +
                '<p id="confirm-modal-message"></p>' +
                '<div class="confirm-modal-actions">' +
                    '<button type="button" class="confirm-modal-cancel">Annuler</button>' +
                    '<button type="button" class="confirm-modal-confirm">Confirmer</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(overlay);

        overlay.querySelector('.confirm-modal-cancel').addEventListener('click', close);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) close();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlay.classList.contains('is-visible')) {
                close();
            }
        });

        return overlay;
    }

    function trapFocus(e) {
        if (!overlay || !overlay.classList.contains('is-visible')) return;
        var focusable = overlay.querySelectorAll('button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable.length === 0) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (e.key === 'Tab') {
            if (e.shiftKey) {
                if (document.activeElement === first) { e.preventDefault(); last.focus(); }
            } else {
                if (document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        }
    }

    function close() {
        if (!overlay) return;
        overlay.classList.remove('is-visible');
        document.removeEventListener('keydown', trapFocus);
        if (previousFocus) {
            previousFocus.focus();
            previousFocus = null;
        }
    }

    window.confirmModal = function(options) {
        var el = getOrCreateOverlay();
        var title = options.title || 'Confirmer';
        var message = options.message || 'Êtes-vous sûr ?';
        var confirmLabel = options.confirmLabel || 'Confirmer';
        var onConfirm = options.onConfirm || function() {};

        el.querySelector('#confirm-modal-title').textContent = title;
        el.querySelector('#confirm-modal-message').textContent = message;

        var confirmBtn = el.querySelector('.confirm-modal-confirm');
        confirmBtn.textContent = confirmLabel;

        var newBtn = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);
        newBtn.addEventListener('click', function() {
            close();
            onConfirm();
        });

        previousFocus = document.activeElement;
        el.classList.add('is-visible');
        document.addEventListener('keydown', trapFocus);
        newBtn.focus();
    };
})();
