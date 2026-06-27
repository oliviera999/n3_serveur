/**
 * Sauvegarde de la politique de notifications (mode + catégories) — pages contrôle.
 */
(function () {
    'use strict';

    function apiBase() {
        if (typeof window.CONTROL_API_BASE === 'string' && window.CONTROL_API_BASE !== '') {
            return window.CONTROL_API_BASE;
        }
        if (typeof window.outputs_api_base === 'string' && window.outputs_api_base !== '') {
            return window.outputs_api_base;
        }
        return '/api/outputs';
    }

    function policyUrl() {
        return withCurrentToken(apiBase().replace(/\/$/, '') + '/notification-policy');
    }

    // Propage le ?token= de l'URL courante vers la requête (parité avec les autres
    // écritures de contrôle) : une requête portant un token explicite est authentifiée
    // et exemptée de CSRF côté serveur.
    function withCurrentToken(endpoint) {
        try {
            var token = new URL(window.location.href).searchParams.get('token');
            if (!token) {
                return endpoint;
            }
            var target = new URL(endpoint, window.location.origin);
            if (!target.searchParams.has('token')) {
                target.searchParams.set('token', token);
            }
            return target.pathname + target.search;
        } catch (e) {
            return endpoint;
        }
    }

    function getPanel() {
        return document.querySelector('[data-notification-policy]');
    }

    function getModeSelect() {
        return document.querySelector('[data-notification-mode-select]');
    }

    function getSelectedMode() {
        const select = getModeSelect();
        return select ? select.value : 'important';
    }

    function getCheckedCategories() {
        const boxes = document.querySelectorAll('[data-notification-category]:checked');
        return Array.from(boxes).map(function (el) { return el.value; });
    }

    function setSaveIndicator(state, message) {
        const indicator = document.querySelector('[data-notification-save-indicator]');
        if (!indicator) {
            return;
        }
        indicator.dataset.state = state;
        indicator.textContent = message || '';
    }

    function updateCategoriesDisabled(mode) {
        const fieldset = document.querySelector('[data-notification-categories]');
        const boxes = document.querySelectorAll('[data-notification-category]');
        const disabled = mode === 'none';
        if (fieldset) {
            fieldset.disabled = disabled;
        }
        boxes.forEach(function (box) {
            box.disabled = disabled;
        });
    }

    function updateModeHint(mode) {
        const hint = document.querySelector('.notification-mode-hint');
        const select = getModeSelect();
        if (!hint || !select) {
            return;
        }
        const option = select.querySelector('option[value="' + mode + '"]');
        const text = option ? (option.getAttribute('title') || option.textContent) : '';
        hint.textContent = text;
    }

    function postPolicy(mode, categories) {
        setSaveIndicator('saving', 'Enregistrement…');
        return fetch(policyUrl(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                // Écriture navigateur protégée par CSRF (cookie de session).
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ mode: mode, categories: categories }),
        })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (result) {
                if (!result.ok || !result.data.success) {
                    setSaveIndicator('error', result.data.error || 'Erreur');
                    return;
                }
                updateCategoriesDisabled(result.data.mode);
                updateModeHint(result.data.mode);
                setSaveIndicator('saved', 'Enregistré');
                setTimeout(function () { setSaveIndicator('idle', ''); }, 2000);
            })
            .catch(function () {
                setSaveIndicator('error', 'Erreur réseau');
            });
    }

    window.setNotificationMode = function (selectEl) {
        const mode = selectEl ? selectEl.value : getSelectedMode();
        updateCategoriesDisabled(mode);
        const categories = mode === 'none' ? [] : getCheckedCategories();
        postPolicy(mode, categories);
    };

    window.toggleNotificationCategory = function () {
        const mode = getSelectedMode();
        if (mode === 'none') {
            return;
        }
        postPolicy(mode, getCheckedCategories());
    };
})();
