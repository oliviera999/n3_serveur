/**
 * period-nav.js — Navigation dynamique par fenêtre temporelle (longues périodes).
 *
 * Amélioration progressive du partiel templates/partials/_period_nav.twig :
 * les liens « précédent / suivant » fonctionnent déjà sans JavaScript (href
 * en query-string relative). Ce script :
 *   - branche le sélecteur de durée de fenêtre (?window=…&offset=0) ;
 *   - recalcule des URLs absolues basées sur location.pathname (robuste quel
 *     que soit le base_path) pour les flèches précédent/suivant ;
 *   - gère le bouton « période courante » (réinitialise les paramètres).
 *
 * Aucune dépendance externe. Sans le conteneur #period-nav, ne fait rien.
 *
 * @version 1.0.0
 */
(function () {
    'use strict';

    function buildUrl(params) {
        var qs = new URLSearchParams();
        Object.keys(params).forEach(function (key) {
            var value = params[key];
            if (value !== null && value !== undefined && value !== '') {
                qs.set(key, String(value));
            }
        });
        var query = qs.toString();
        return window.location.pathname + (query ? '?' + query : '');
    }

    function navigate(url) {
        window.location.assign(url);
    }

    function init() {
        var nav = document.getElementById('period-nav');
        if (!nav) {
            return;
        }

        var currentWindow = nav.dataset.periodWindow || '';
        var currentOffset = parseInt(nav.dataset.periodOffset || '0', 10);
        if (isNaN(currentOffset) || currentOffset < 0) {
            currentOffset = 0;
        }

        // Sélecteur de durée de fenêtre : passe en mode fenêtre glissante (offset 0).
        var select = document.getElementById('period-nav-window');
        if (select) {
            select.addEventListener('change', function () {
                var win = select.value;
                if (!win) {
                    return;
                }
                navigate(buildUrl({ window: win, offset: 0 }));
            });
        }

        // Flèches précédent / suivant : recalcul d'URL absolue si une fenêtre est active.
        nav.querySelectorAll('[data-period-nav]').forEach(function (link) {
            var action = link.dataset.periodNav;

            link.addEventListener('click', function (event) {
                if (link.classList.contains('is-disabled')) {
                    event.preventDefault();
                    return;
                }

                if (action === 'reset') {
                    event.preventDefault();
                    navigate(window.location.pathname);
                    return;
                }

                // prev / next n'ont de sens qu'en mode fenêtre glissante.
                if (!currentWindow) {
                    return; // laisse le href de repli (ou #) gérer
                }

                event.preventDefault();
                var nextOffset = action === 'prev' ? currentOffset + 1 : currentOffset - 1;
                if (nextOffset < 0) {
                    nextOffset = 0;
                }
                navigate(buildUrl({ window: currentWindow, offset: nextOffset }));
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
