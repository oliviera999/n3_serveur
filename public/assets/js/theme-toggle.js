/**
 * theme-toggle.js — Détection préférence système + toggle manuel dark mode
 * Charge en head pour éviter le flash (FOUC).
 * Utilise localStorage clé "n3-iot-theme" : "dark" | "light" | absent (= préférence système)
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'n3-iot-theme';

    function getStoredTheme() {
        try {
            return localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return null;
        }
    }

    function setStoredTheme(value) {
        try {
            if (value) {
                localStorage.setItem(STORAGE_KEY, value);
            } else {
                localStorage.removeItem(STORAGE_KEY);
            }
        } catch (e) { /* ignore */ }
    }

    function prefersDark() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    function applyTheme(theme) {
        var html = document.documentElement;
        if (theme === 'dark') {
            html.setAttribute('data-theme', 'dark');
            updateThemeColor('#0f172a');
        } else {
            html.removeAttribute('data-theme');
            updateThemeColor('#008B74');
        }
    }

    function updateThemeColor(color) {
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.setAttribute('content', color);
        }
    }

    function getEffectiveTheme() {
        var stored = getStoredTheme();
        if (stored === 'dark' || stored === 'light') {
            return stored;
        }
        return prefersDark() ? 'dark' : 'light';
    }

    function init() {
        var theme = getEffectiveTheme();
        applyTheme(theme);
    }

    function toggle() {
        var current = getEffectiveTheme();
        var next = current === 'dark' ? 'light' : 'dark';
        setStoredTheme(next);
        applyTheme(next);
        if (typeof window.n3HighchartsApplyTheme === 'function') {
            window.n3HighchartsApplyTheme();
        }
        return next;
    }

    function isDark() {
        return getEffectiveTheme() === 'dark';
    }

    // Appliquer immédiatement au chargement
    init();

    // Écouter changement préférence système si pas de préférence stockée
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            if (!getStoredTheme()) {
                applyTheme(prefersDark() ? 'dark' : 'light');
            }
        });
    }

    // Exposer API globale
    window.n3Theme = {
        toggle: toggle,
        isDark: isDark,
        getTheme: getEffectiveTheme,
        apply: applyTheme
    };

    // Délégation d'événement : écouter les clics sur document
    // (fonctionne même si le bouton est rendu après ou si le script nav échoue)
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t) return;
        if (t.id === 'theme-toggle-btn') { toggle(); return; }
        if (t.closest && t.closest('#theme-toggle-btn')) { toggle(); }
    });
})();
