/**
 * page-nav-toggles.js — Gestion des pages visibles dans la barre de navigation
 * Les états sont persistés en localStorage sous la clé 'n3_nav_pages'.
 * Sur la page supervision, des toggles permettent d'activer/désactiver chaque page.
 * Les pages activées apparaissent dynamiquement dans le menu de navigation.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'n3_nav_pages';

    function getState() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
        } catch (e) {
            return {};
        }
    }

    function setState(state) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {}
    }

    /**
     * Injecte les <li> dynamiques dans la barre de nav, avant le bouton thème.
     */
    function renderNavItems() {
        var nav = document.querySelector('#nav ul.links');
        if (!nav) return;

        // Supprimer les items dynamiques existants
        var existing = nav.querySelectorAll('.nav-dyn-item');
        for (var i = 0; i < existing.length; i++) {
            existing[i].parentNode.removeChild(existing[i]);
        }

        var state = getState();
        var keys = Object.keys(state);
        if (keys.length === 0) return;

        var themeToggle = nav.querySelector('.nav-theme-toggle');

        keys.forEach(function (key) {
            var page = state[key];
            if (!page || !page.active || !page.url || !page.label) return;

            var li = document.createElement('li');
            li.className = 'nav-dyn-item';

            var a = document.createElement('a');
            a.href = page.url;
            a.textContent = page.label;
            a.title = page.label;

            li.appendChild(a);

            if (themeToggle) {
                nav.insertBefore(li, themeToggle);
            } else {
                nav.appendChild(li);
            }
        });
    }

    /**
     * Initialise les checkboxes toggle sur la page supervision.
     */
    function initToggles() {
        var checkboxes = document.querySelectorAll('.page-nav-cb');
        if (checkboxes.length === 0) return;

        var state = getState();

        for (var i = 0; i < checkboxes.length; i++) {
            (function (cb) {
                var key = cb.getAttribute('data-page-key');
                var label = cb.getAttribute('data-page-label');
                var url = cb.getAttribute('data-page-url');

                // État initial depuis localStorage
                if (state[key] && state[key].active) {
                    cb.checked = true;
                    markWrapActive(cb, true);
                }

                cb.addEventListener('change', function (e) {
                    e.stopPropagation();
                    var s = getState();
                    if (cb.checked) {
                        s[key] = { active: true, label: label, url: url };
                    } else {
                        delete s[key];
                    }
                    setState(s);
                    renderNavItems();
                    markWrapActive(cb, cb.checked);
                });
            })(checkboxes[i]);
        }
    }

    function markWrapActive(cb, active) {
        var wrap = cb;
        while (wrap && !wrap.classList.contains('link-card-wrap')) {
            wrap = wrap.parentElement;
        }
        if (wrap) {
            if (active) {
                wrap.classList.add('nav-active');
            } else {
                wrap.classList.remove('nav-active');
            }
        }
    }

    /**
     * Empêche le clic sur le toggle de suivre le lien parent.
     */
    function blockToggleNavigation() {
        document.addEventListener('click', function (e) {
            var el = e.target;
            // Remonter jusqu'à un label.page-nav-toggle
            while (el && el !== document) {
                if (el.classList && el.classList.contains('page-nav-toggle')) {
                    e.stopPropagation();
                    return;
                }
                el = el.parentElement;
            }
        }, true);
    }

    function onReady(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    onReady(function () {
        blockToggleNavigation();
        renderNavItems();
        initToggles();
    });
})();
