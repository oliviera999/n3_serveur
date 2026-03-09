(() => {
    const env = document.body?.dataset?.environment || 'prod';
    const API_BASE = env === 'test' ? '/ffp3/api/outputs-test'
        : env === 'test3' ? '/ffp3/api/outputs3-test'
        : env === 's3' ? '/ffp3/api/outputs3'
        : '/ffp3/api/outputs';
    const PARAM_ENDPOINT = `${API_BASE}/parameters`;

    const saveState = {
        isSaving: false,
        lastSaveTime: null,
        pendingSave: null,
        lastSavedValues: new Map(),
        toast: null,
    };

    const selectors = {
        indicator: '[data-save-indicator]',
        inputs: '[data-parameter]',
        wrapper: '[data-parameter-wrapper]',
    };

    function autoSaveParameter(parameterName, value) {
        if (!parameterName) {
            console.warn('[ControlAutoSave] Missing parameter name for auto-save');
            return;
        }

        const normalizedValue = normalizeValue(value);
        const isEmailField = parameterName.toLowerCase() === 'mail';
        const payload = {
            param: parameterName,
            value: normalizedValue,
        };

        if (isEmailField && normalizedValue && !validateEmail(normalizedValue)) {
            showErrorIndicator(parameterName, 'Adresse email invalide');
            return;
        }

        if (saveState.pendingSave) {
            clearTimeout(saveState.pendingSave);
        }

        saveState.pendingSave = setTimeout(() => {
            performSave(parameterName, payload);
        }, 450);
    }

    function performSave(parameterName, payload) {
        const inputElement = findInput(parameterName);
        const wrapper = inputElement ? inputElement.closest(selectors.wrapper) : null;

        if (inputElement) {
            inputElement.setAttribute('data-saving', 'true');
        }
        if (wrapper) {
            wrapper.classList.add('is-saving');
        }

        showSaveIndicator(parameterName);
        saveState.isSaving = true;

        fetch(PARAM_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
            credentials: 'include',
        })
            .then(async response => {
                if (!response.ok) {
                    const errorText = await response.text();
                    throw new Error(`HTTP ${response.status} ${response.statusText}: ${errorText}`);
                }
                return response.json();
            })
            .then(data => {
                saveState.lastSaveTime = Date.now();
                saveState.lastSavedValues.set(parameterName, payload.value);
                showSuccessIndicator(parameterName, data?.message || 'Paramètre enregistré');

                if (typeof window.controlValuesUpdater?.updateParameterDisplay === 'function' && data?.gpio !== undefined) {
                    window.controlValuesUpdater.updateParameterDisplay(data.gpio, payload.value);
                }

                if (typeof toastManager !== 'undefined') {
                    saveState.toast = toastManager.showSuccess(`Paramètre ${parameterName} sauvegardé`, 2500);
                }
            })
            .catch(error => {
                console.error('[ControlAutoSave] Save error', error);
                showErrorIndicator(parameterName, 'Erreur de sauvegarde');

                if (typeof toastManager !== 'undefined') {
                    toastManager.showError(`Échec sauvegarde ${parameterName}`, 5000);
                }
            })
            .finally(() => {
                saveState.isSaving = false;

                if (inputElement) {
                    inputElement.removeAttribute('data-saving');
                }
                if (wrapper) {
                    wrapper.classList.remove('is-saving');
                }
            });
    }

    function normalizeValue(value) {
        if (value === null || value === undefined) {
            return '';
        }

        if (typeof value === 'string') {
            const trimmed = value.trim();

            if (trimmed === '') {
                return '';
            }

            if (!Number.isNaN(Number(trimmed))) {
                return parseFloat(trimmed);
            }

            return trimmed;
        }

        return value;
    }

    function validateEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function findInput(parameterName) {
        return document.querySelector(`${selectors.inputs}[data-parameter="${parameterName}"]`);
    }

    function showSaveIndicator(parameterName) {
        updateIndicator(parameterName, 'saving');
    }

    function showSuccessIndicator(parameterName, message) {
        updateIndicator(parameterName, 'success', message);
        dismissIndicator(parameterName, 2500);
    }

    function showErrorIndicator(parameterName, message) {
        updateIndicator(parameterName, 'error', message);
        dismissIndicator(parameterName, 4000);
    }

    function updateIndicator(parameterName, state, message = null) {
        const indicator = findIndicator(parameterName);
        if (!indicator) {
            return;
        }

        const previousState = indicator.getAttribute('data-state');
        indicator.setAttribute('data-state', state);

        if (message) {
            indicator.textContent = message;
        } else {
            const labels = {
                saving: 'Enregistrement…',
                success: 'Enregistré',
                error: 'Erreur',
            };
            indicator.textContent = labels[state] || state;
        }

        if (previousState !== state) {
            indicator.classList.add('state-changed');
            setTimeout(() => indicator.classList.remove('state-changed'), 500);
        }
    }

    function dismissIndicator(parameterName, delay) {
        const indicator = findIndicator(parameterName);
        if (!indicator) {
            return;
        }

        setTimeout(() => {
            indicator.setAttribute('data-state', 'idle');
            indicator.textContent = '';
        }, delay);
    }

    function findIndicator(parameterName) {
        return document.querySelector(`${selectors.indicator}[data-parameter="${parameterName}"]`);
    }

    function attachAutoSaveListeners() {
        const inputs = document.querySelectorAll(selectors.inputs);
        if (!inputs.length) {
            return;
        }

        inputs.forEach(input => {
            const parameterName = input.dataset.parameter;
            if (!parameterName) {
                return;
            }

            input.addEventListener('change', event => {
                autoSaveParameter(parameterName, event.target.value);
            });

            input.addEventListener('blur', event => {
                if (saveState.isSaving) {
                    return;
                }
                autoSaveParameter(parameterName, event.target.value);
            });
        });
    }

    function initAutoSaveModule() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', attachAutoSaveListeners);
        } else {
            attachAutoSaveListeners();
        }
    }

    window.autoSaveParameter = autoSaveParameter;
    window.showSaveIndicator = showSaveIndicator;
    window.showSuccessIndicator = showSuccessIndicator;
    window.showErrorIndicator = showErrorIndicator;

    initAutoSaveModule();
})();

