/**
 * preset-selector.js — Choix du preset d'affichage d'une page de suivi.
 *
 * - Un clic sur une pastille mémorise le choix (localStorage `n3.preset.<page>`)
 *   et recharge la page avec ?preset=…, en conservant les autres paramètres
 *   (fenêtre de période ?window=&offset=).
 * - Au chargement (GET sans ?preset), un choix mémorisé différent du preset
 *   affiché est réappliqué. Jamais après un POST (le formulaire de période
 *   serait perdu) ; un choix mémorisé qui n'existe plus est oublié.
 */
(function () {
    'use strict';

    function storageKey(page) {
        return 'n3.preset.' + page;
    }

    function readStored(page) {
        try {
            return window.localStorage.getItem(storageKey(page));
        } catch (e) {
            return null;
        }
    }

    function writeStored(page, slug) {
        try {
            window.localStorage.setItem(storageKey(page), slug);
        } catch (e) {
            // Stockage indisponible (navigation privée) : le choix vaut pour cette page seulement.
        }
    }

    function forgetStored(page) {
        try {
            window.localStorage.removeItem(storageKey(page));
        } catch (e) {
            // Rien à faire.
        }
    }

    function urlWithPreset(slug) {
        var params = new URLSearchParams(window.location.search);
        params.set('preset', slug);
        return window.location.pathname + '?' + params.toString();
    }

    function init() {
        var nav = document.querySelector('.preset-selector');
        if (!nav) return;

        var page = nav.dataset.pageKey;
        var active = nav.dataset.activePreset;
        var available = Array.prototype.map.call(nav.querySelectorAll('[data-preset]'), function (el) {
            return el.dataset.preset;
        });

        nav.querySelectorAll('[data-preset]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                writeStored(page, link.dataset.preset);
                window.location.assign(urlWithPreset(link.dataset.preset));
            });
        });

        var stored = readStored(page);
        if (!stored) return;
        if (available.indexOf(stored) === -1) {
            forgetStored(page);
            return;
        }
        var isGet = (nav.dataset.requestMethod || 'GET').toUpperCase() === 'GET';
        if (isGet && !nav.dataset.requestedPreset && stored !== active) {
            window.location.replace(urlWithPreset(stored));
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
