/**
 * preset-selector.js — Choix du preset d'affichage d'une page de suivi.
 *
 * - Un clic sur une pastille mémorise le choix dans le cookie `n3_preset_<page>`
 *   (lu côté serveur : la page suivante est directement rendue avec ce preset,
 *   sans rechargement) puis ouvre la page avec ?preset=…, en conservant les autres
 *   paramètres (fenêtre de période ?window=&offset=).
 * - Migration : un choix mémorisé par l'ancienne version (localStorage
 *   `n3.preset.<page>`) est recopié une fois dans le cookie puis oublié — sans
 *   recharger la page (l'ancienne version rechargeait à chaque visite).
 */
(function () {
    'use strict';

    var SLUG = /^[a-z0-9][a-z0-9-]{0,39}$/;
    var MAX_AGE = 365 * 24 * 3600;

    function cookieName(page) {
        return 'n3_preset_' + page;
    }

    function readCookie(name) {
        var parts = document.cookie ? document.cookie.split('; ') : [];
        for (var i = 0; i < parts.length; i++) {
            var eq = parts[i].indexOf('=');
            if (eq > 0 && parts[i].slice(0, eq) === name) {
                return decodeURIComponent(parts[i].slice(eq + 1));
            }
        }
        return null;
    }

    function writeCookie(page, slug) {
        if (!SLUG.test(slug)) return;
        document.cookie = cookieName(page) + '=' + encodeURIComponent(slug)
            + '; Max-Age=' + MAX_AGE + '; Path=/; SameSite=Lax'
            + (window.location.protocol === 'https:' ? '; Secure' : '');
    }

    function migrateLegacyStorage(page) {
        var key = 'n3.preset.' + page;
        try {
            var legacy = window.localStorage.getItem(key);
            if (legacy !== null) {
                if (!readCookie(cookieName(page))) {
                    writeCookie(page, legacy);
                }
                window.localStorage.removeItem(key);
            }
        } catch (e) {
            // Stockage indisponible (navigation privée) : rien à migrer.
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
        if (!page) return;

        migrateLegacyStorage(page);

        nav.querySelectorAll('[data-preset]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                writeCookie(page, link.dataset.preset);
                window.location.assign(urlWithPreset(link.dataset.preset));
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
