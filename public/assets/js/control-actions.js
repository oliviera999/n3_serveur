/**
 * Helper de retry réseau borné pour les écritures de contrôle (toggle, parameters, OTA).
 *
 * - 3 tentatives max, backoff exponentiel borné : 300ms, 600ms, 1200ms.
 * - Ne retente QUE sur erreur réseau (fetch rejette) ou réponse 5xx.
 * - NE retente JAMAIS sur 4xx (403 CSRF, 400 validation, etc.) : la réponse est rendue telle quelle.
 * - L'en-tête X-CSRF-Token (meta csrf-token) est (re)lu et réinjecté à CHAQUE tentative.
 *
 * Exposé en global (window.fetchWithRetry) pour réutilisation par control-auto-save.js
 * et le handler OTA inline. Idempotent : ne s'écrase pas s'il est déjà défini.
 *
 * @param {string} url
 * @param {RequestInit} [options]
 * @param {{retries?: number, backoffBaseMs?: number, backoffMaxMs?: number}} [retryConfig]
 * @returns {Promise<Response>}
 */
if (typeof window.fetchWithRetry !== 'function') {
    window.fetchWithRetry = async function fetchWithRetry(url, options = {}, retryConfig = {}) {
        const retries = Number.isInteger(retryConfig.retries) ? retryConfig.retries : 3;
        const backoffBaseMs = retryConfig.backoffBaseMs || 300;
        const backoffMaxMs = retryConfig.backoffMaxMs || 1200;

        const buildOptions = () => {
            const opts = Object.assign({}, options);
            // (Re)lecture du token CSRF à chaque tentative : il peut être renouvelé entre deux essais.
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            opts.headers = Object.assign({}, options.headers || {}, { 'X-CSRF-Token': csrfToken });
            return opts;
        };

        let lastError = null;

        for (let attempt = 1; attempt <= retries; attempt++) {
            try {
                const response = await fetch(url, buildOptions());

                // 4xx : erreur définitive (CSRF/validation) — on NE retente PAS.
                if (response.status >= 400 && response.status < 500) {
                    return response;
                }

                // 5xx : erreur serveur potentiellement transitoire — on retente si possible.
                if (response.status >= 500 && attempt < retries) {
                    await new Promise(resolve => setTimeout(
                        resolve,
                        Math.min(backoffBaseMs * Math.pow(2, attempt - 1), backoffMaxMs)
                    ));
                    continue;
                }

                return response;
            } catch (networkError) {
                // Erreur réseau (timeout, DNS, offline) : on retente si possible.
                lastError = networkError;
                if (attempt < retries) {
                    await new Promise(resolve => setTimeout(
                        resolve,
                        Math.min(backoffBaseMs * Math.pow(2, attempt - 1), backoffMaxMs)
                    ));
                    continue;
                }
                throw lastError;
            }
        }

        // Sécurité (ne devrait pas être atteint) : relance la dernière erreur réseau.
        throw lastError || new Error('fetchWithRetry: échec inattendu');
    };
}

class ControlActions {
    constructor(options = {}) {
        this.apiBase = options.apiBase || (typeof window.CONTROL_API_BASE !== 'undefined' ? window.CONTROL_API_BASE : '/api/outputs');
        this.isProcessing = false;
        this.queue = [];
        // Anti-double-clic : ensemble des contrôles ayant une requête toggle en vol.
        this.inFlight = new Set();
        /** @type {Map<number, { feedCmdId: string, startedAt: number, timeoutId: number|null, elapsedTimerId: number|null, phase: string, lastOutputState: number|null, lastDataState: number|null }>} */
        this.pendingFeeds = new Map();
        this.feedPulseGapMs = 800;
        this.feedAckTimeoutMs = 45000;
        this.feedBoostPollMs = 2000;
        this._feedBoostCount = 0;
    }

    toggleOutput(element) {
        if (!(element instanceof HTMLInputElement)) {
            return;
        }

        const gpio = parseInt(element.dataset.gpio, 10);
        const outputId = parseInt(element.dataset.id, 10);
        const isInverted = element.dataset.inverted === '1';
        const targetState = element.checked ? 1 : 0;

        if (Number.isNaN(gpio) || Number.isNaN(outputId)) {
            console.warn('[ControlActions] Invalid GPIO or output id');
            return;
        }

        const payload = {
            gpio,
            id: outputId,
            state: isInverted ? (targetState === 1 ? 0 : 1) : targetState,
        };

        const criticalGpios = [110, 115];
        if (criticalGpios.includes(gpio) && typeof window.confirmModal === 'function') {
            const label = gpio === 110 ? 'Reset ESP' : 'Forçage réveil';
            element.checked = !element.checked;
            window.confirmModal({
                title: label,
                message: gpio === 110
                    ? 'Cette action va redémarrer le microcontrôleur. Vérifiez la présence sur site.'
                    : 'Cette action force le réveil immédiat de l\'ESP32.',
                confirmLabel: 'Confirmer ' + label.toLowerCase(),
                onConfirm: () => {
                    element.checked = !element.checked;
                    this.enqueueAction(() => this.sendToggleRequest(payload, element));
                }
            });
            return;
        }

        this.enqueueAction(() => this.sendToggleRequest(payload, element));
    }

    /**
     * Nourrissage manuel FFP3 (GPIO 108/109) : impulsion reset → pause → trigger + suivi jusqu'à acquittement (GPIO→0).
     */
    triggerManualFeed(button) {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        const gpio = parseInt(button.dataset.gpio, 10);
        const outputId = parseInt(button.dataset.id, 10);
        if (Number.isNaN(gpio) || Number.isNaN(outputId)) {
            console.warn('[ControlActions] Invalid feed GPIO or output id');
            return;
        }
        if (this.pendingFeeds.has(gpio)) {
            if (typeof toastManager !== 'undefined') {
                toastManager.showWarning('Nourrissage déjà en cours pour cette sortie.', 4000);
            }
            return;
        }
        this.enqueueAction(() => this.sendManualFeedPulse(button, gpio, outputId));
    }

    /**
     * Appelé par control-sync.js après chaque poll (?fresh=1) pendant le suivi nourrissage.
     */
    onFeedStatesPolled(snapshot) {
        const outputs = snapshot.outputs || {};
        const dataStates = snapshot.dataStates || {};
        for (const gpio of [108, 109]) {
            if (!this.pendingFeeds.has(gpio)) {
                continue;
            }
            const outputState = this.normalizeFeedState(outputs[gpio] ?? outputs[String(gpio)]);
            const dataState = this.normalizeFeedState(dataStates[gpio] ?? dataStates[String(gpio)]);
            this.handleFeedStateSnapshot(gpio, outputState, dataState, snapshot.dataReadingTime);
        }
    }

    normalizeFeedState(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        return Number(value) === 1 ? 1 : 0;
    }

    handleFeedStateSnapshot(gpio, outputState, dataState, dataReadingTime) {
        const pending = this.pendingFeeds.get(gpio);
        if (!pending) {
            return;
        }

        const prevOut = pending.lastOutputState;
        const prevData = pending.lastDataState;

        if (outputState === 1 && pending.phase === 'waiting_read') {
            pending.phase = 'command_read';
            this.setFeedStatus(gpio, 'waiting', 'ESP32 a lu la commande');
            this.appendFeedStep(gpio, 'Commande visible côté ESP32 (GPIO=1)', 'ok');
        }

        if (dataState === 1 && prevData !== 1) {
            const suffix = dataReadingTime ? ` — ${dataReadingTime}` : '';
            this.appendFeedStep(gpio, `Trace capteur : distribution enregistrée${suffix}`, 'ok');
            this.setFeedStatus(gpio, 'waiting', 'Distribution enregistrée — attente acquittement');
        }

        if (outputState === 0 && (prevOut === 1 || pending.phase === 'command_read')) {
            this.appendFeedStep(gpio, 'Acquittement reçu (GPIO repassé à 0)', 'ok');
            this.completeFeedPending(gpio, 'executed');
        }

        pending.lastOutputState = outputState;
        pending.lastDataState = dataState;
    }

    /** @deprecated use onFeedStatesPolled */
    handleFeedGpioPoll(gpio, state) {
        this.handleFeedStateSnapshot(gpio, this.normalizeFeedState(state), null, null);
    }

    async sendManualFeedPulse(button, gpio, outputId) {
        const controlKey = `feed:${gpio}`;
        if (this.inFlight.has(controlKey)) {
            return;
        }
        this.inFlight.add(controlKey);

        const card = button.closest('.action-card');
        const fetchWithRetry = window.fetchWithRetry || fetch;
        const endpoint = this.withCurrentToken(`${this.apiBase}/trigger-feed`);

        button.disabled = true;
        card?.classList.remove('is-success', 'is-error');
        card?.classList.add('is-updating');
        this.clearFeedTimeline(gpio);
        this.setFeedStatus(gpio, 'pending', 'Réinitialisation…');
        this.appendFeedStep(gpio, 'Réinitialisation BDD (GPIO→0)', 'info');
        this.startFeedBoostPolling();

        try {
            const resetResponse = await fetchWithRetry(endpoint, {
                method: 'POST',
                headers: this.buildJsonHeaders(),
                credentials: 'include',
                body: JSON.stringify({ id: outputId, gpio, step: 'reset' }),
            });
            if (!resetResponse.ok) {
                const errorText = await resetResponse.text();
                throw new Error(`Reset HTTP ${resetResponse.status}: ${errorText}`);
            }
            this.appendFeedStep(gpio, 'Reset enregistré', 'ok');

            this.setFeedStatus(gpio, 'pending', 'Préparation impulsion…');
            this.appendFeedStep(gpio, `Pause ${this.feedPulseGapMs} ms (fenêtre lecture ESP32)`, 'info');
            await new Promise(resolve => setTimeout(resolve, this.feedPulseGapMs));

            const triggerResponse = await fetchWithRetry(endpoint, {
                method: 'POST',
                headers: this.buildJsonHeaders(),
                credentials: 'include',
                body: JSON.stringify({ id: outputId, gpio, step: 'trigger' }),
            });
            if (!triggerResponse.ok) {
                const errorText = await triggerResponse.text();
                throw new Error(`Trigger HTTP ${triggerResponse.status}: ${errorText}`);
            }

            const data = await triggerResponse.json();
            const feedCmdId = data?.feed_cmd_id || data?.data?.feed_cmd_id || '';

            this.appendFeedStep(gpio, `Impulsion envoyée (GPIO→1) — ref. ${feedCmdId ? feedCmdId.slice(0, 8) : '?'}`, 'ok');
            this.setFeedStatus(gpio, 'waiting', 'En attente lecture ESP32…', feedCmdId);

            const timeoutId = window.setTimeout(() => {
                if (this.pendingFeeds.has(gpio)) {
                    this.appendFeedStep(gpio, 'Délai dépassé sans acquittement GPIO', 'warn');
                    this.completeFeedPending(gpio, 'timeout');
                }
            }, this.feedAckTimeoutMs);

            this.pendingFeeds.set(gpio, {
                feedCmdId,
                startedAt: Date.now(),
                timeoutId,
                elapsedTimerId: null,
                phase: 'waiting_read',
                lastOutputState: 1,
                lastDataState: null,
            });
            this.startFeedElapsedTimer(gpio);

            if (typeof toastManager !== 'undefined') {
                toastManager.showInfo('Suivi en direct activé — polling accéléré.', 4000);
            }

            if (window.controlSync) {
                window.controlSync.forceSync();
            }

            card?.classList.add('is-success');
            setTimeout(() => card?.classList.remove('is-success'), 1500);
        } catch (error) {
            console.error('[ControlActions] Manual feed error', error);
            this.appendFeedStep(gpio, `Erreur : ${error.message || 'inconnue'}`, 'error');
            this.setFeedStatus(gpio, 'error', 'Échec — réessayer');
            this.stopFeedBoostPolling();
            card?.classList.add('is-error');
            setTimeout(() => card?.classList.remove('is-error'), 2500);
            if (typeof toastManager !== 'undefined') {
                toastManager.showError('Nourrissage refusé ou erreur réseau.', 5000);
            }
        } finally {
            button.disabled = false;
            card?.classList.remove('is-updating');
            this.inFlight.delete(controlKey);
        }
    }

    completeFeedPending(gpio, outcome) {
        const pending = this.pendingFeeds.get(gpio);
        if (!pending) {
            return;
        }
        if (pending.timeoutId !== null) {
            window.clearTimeout(pending.timeoutId);
        }
        this.stopFeedElapsedTimer(gpio);
        this.pendingFeeds.delete(gpio);
        this.stopFeedBoostPolling();

        const shortId = pending.feedCmdId ? pending.feedCmdId.slice(0, 8) : '';
        if (outcome === 'executed') {
            this.appendFeedStep(gpio, 'Cycle terminé avec succès', 'ok');
            this.setFeedStatus(gpio, 'executed', 'Terminé', pending.feedCmdId);
            if (typeof toastManager !== 'undefined') {
                const ref = shortId ? ` (ref. ${shortId})` : '';
                toastManager.showSuccess(`Nourrissage confirmé${ref}`, 4000);
            }
        } else if (outcome === 'timeout') {
            this.setFeedStatus(gpio, 'timeout', 'Timeout — réessayer', pending.feedCmdId);
            if (typeof toastManager !== 'undefined') {
                toastManager.showWarning('Pas de confirmation ESP32 — vérifiez la connexion ou réessayez.', 7000);
            }
        }
    }

    startFeedBoostPolling() {
        this._feedBoostCount += 1;
        if (this._feedBoostCount === 1 && window.controlSync?.boostPollingForFeed) {
            window.controlSync.boostPollingForFeed(this.feedBoostPollMs);
        }
    }

    stopFeedBoostPolling() {
        if (this._feedBoostCount <= 0) {
            return;
        }
        this._feedBoostCount -= 1;
        if (this._feedBoostCount === 0 && window.controlSync?.restorePollInterval) {
            window.controlSync.restorePollInterval();
        }
    }

    startFeedElapsedTimer(gpio) {
        const pending = this.pendingFeeds.get(gpio);
        if (!pending) {
            return;
        }
        const el = this.getFeedElapsedEl(gpio);
        if (!el) {
            return;
        }
        el.hidden = false;
        el.removeAttribute('aria-hidden');
        const tick = () => {
            if (!this.pendingFeeds.has(gpio)) {
                return;
            }
            const sec = Math.floor((Date.now() - pending.startedAt) / 1000);
            el.textContent = `${sec} s`;
        };
        tick();
        pending.elapsedTimerId = window.setInterval(tick, 1000);
    }

    stopFeedElapsedTimer(gpio) {
        const pending = this.pendingFeeds.get(gpio);
        if (pending?.elapsedTimerId) {
            window.clearInterval(pending.elapsedTimerId);
        }
        const el = this.getFeedElapsedEl(gpio);
        if (el) {
            el.hidden = true;
            el.setAttribute('aria-hidden', 'true');
            el.textContent = '';
        }
    }

    getFeedPanel(gpio) {
        return document.querySelector(`[data-feed-live][data-gpio="${gpio}"]`);
    }

    getFeedElapsedEl(gpio) {
        return this.getFeedPanel(gpio)?.querySelector('[data-feed-elapsed]') ?? null;
    }

    getFeedTimelineEl(gpio) {
        return this.getFeedPanel(gpio)?.querySelector('[data-feed-timeline]') ?? null;
    }

    clearFeedTimeline(gpio) {
        const list = this.getFeedTimelineEl(gpio);
        if (list) {
            list.innerHTML = '';
        }
        const panel = this.getFeedPanel(gpio);
        panel?.classList.remove('feed-live-active');
    }

    appendFeedStep(gpio, message, level = 'info') {
        const list = this.getFeedTimelineEl(gpio);
        const panel = this.getFeedPanel(gpio);
        if (!list || !panel) {
            return;
        }
        panel.classList.add('feed-live-active');
        const li = document.createElement('li');
        li.className = `feed-timeline-item feed-timeline-${level}`;
        const time = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        li.innerHTML = `<span class="feed-timeline-time">${time}</span><span class="feed-timeline-text">${this.escapeHtml(message)}</span>`;
        list.appendChild(li);
        while (list.children.length > 12) {
            list.removeChild(list.firstElementChild);
        }
        list.scrollTop = list.scrollHeight;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    setFeedStatus(gpio, state, label, feedCmdId) {
        const panel = this.getFeedPanel(gpio);
        const el = panel?.querySelector('[data-feed-status]');
        if (!el) {
            return;
        }
        el.dataset.state = state;
        el.textContent = label;
        if (feedCmdId) {
            el.dataset.feedCmdId = feedCmdId;
            panel?.setAttribute('title', `Réf. commande : ${feedCmdId}`);
        } else {
            delete el.dataset.feedCmdId;
            panel?.removeAttribute('title');
        }
    }

    buildJsonHeaders() {
        return {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'fetch',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        };
    }

    /**
     * Sélecteur tri-état du forçage pompe aquarium (GPIO 117) : 0=Auto, 1=Forcer ON, 2=Forcer OFF.
     * Envoyé sur le même endpoint /toggle (persistance par GPIO côté serveur).
     */
    setPumpForceMode(element) {
        if (!(element instanceof HTMLSelectElement)) {
            return;
        }
        const gpio = parseInt(element.dataset.gpio, 10);
        const id = parseInt(element.dataset.id, 10) || 0;
        const mode = parseInt(element.value, 10);
        if (Number.isNaN(gpio) || ![0, 1, 2].includes(mode)) {
            console.warn('[ControlActions] Mode de forçage pompe invalide');
            return;
        }
        this.enqueueAction(() => this.sendPumpForceRequest({ gpio, id, state: mode }, element));
    }

    enqueueAction(action) {
        this.queue.push(action);
        if (!this.isProcessing) {
            this.processQueue();
        }
    }

    processQueue() {
        if (this.queue.length === 0) {
            this.isProcessing = false;
            return;
        }

        this.isProcessing = true;
        const action = this.queue.shift();

        Promise.resolve()
            .then(action)
            .catch(error => console.error('[ControlActions] Queue error', error))
            .finally(() => {
                this.processQueue();
            });
    }

    async sendToggleRequest(payload, element) {
        const endpoint = this.withCurrentToken(`${this.apiBase}/toggle`);

        // Anti-double-clic : ignore une 2e requête concurrente pour le même contrôle.
        const controlKey = `${payload.gpio}:${payload.id}`;
        if (this.inFlight.has(controlKey)) {
            element.checked = !element.checked; // annule l'effet visuel du clic redondant
            return;
        }
        this.inFlight.add(controlKey);

        const card = element.closest('.action-card');
        element.disabled = true;
        card?.classList.remove('is-success', 'is-error');
        card?.classList.add('is-updating');

        const fetchWithRetry = window.fetchWithRetry || fetch;

        try {
            const response = await fetchWithRetry(endpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'fetch',
                    // X-CSRF-Token est (ré)injecté par fetchWithRetry à chaque tentative.
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                credentials: 'include',
                body: JSON.stringify({
                    id: payload.id,
                    gpio: payload.gpio,
                    state: payload.state,
                }),
            });

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP ${response.status} ${response.statusText} ${errorText}`);
            }

            const data = await response.json();

            if (data?.state !== undefined) {
                const finalState = data.state;
                const isInverted = element.dataset.inverted === '1';
                const shouldBeChecked = isInverted ? finalState === 0 : finalState === 1;
                
                // Petite attente pour uniformiser le comportement visuel avec les autres switches
                await new Promise(resolve => setTimeout(resolve, 150));
                
                if (element.checked !== shouldBeChecked) {
                    element.checked = shouldBeChecked;
                }
                this.updateCardState(element, shouldBeChecked);
            }

            if (data?.blocked) {
                // Arrêt ignoré par le serveur (ex. forçage pompe aquarium GPIO 117 actif) :
                // message explicite plutôt qu'un faux « Commande envoyée ».
                if (typeof toastManager !== 'undefined') {
                    toastManager.showWarning(data.message || 'Commande ignorée par le serveur.', 7000);
                }
                if (card) {
                    card.classList.add('is-error');
                    setTimeout(() => card.classList.remove('is-error'), 2500);
                }
            } else {
                if (typeof toastManager !== 'undefined') {
                    toastManager.showSuccess('Commande envoyée', 2500);
                }
                // Indicateur visuel de succès transitoire.
                if (card) {
                    card.classList.add('is-success');
                    setTimeout(() => card.classList.remove('is-success'), 1500);
                }
            }

            if (window.controlSync) {
                window.controlSync.forceSync();
            }

        } catch (error) {
            console.error('[ControlActions] Toggle error', error);
            element.checked = !element.checked;
            // Indicateur visuel d'échec transitoire.
            if (card) {
                card.classList.add('is-error');
                setTimeout(() => card.classList.remove('is-error'), 2500);
            }
            if (typeof toastManager !== 'undefined') {
                toastManager.showError('Commande refusée', 4000);
            }
        } finally {
            element.disabled = false;
            card?.classList.remove('is-updating');
            this.inFlight.delete(controlKey);
        }
    }

    async sendPumpForceRequest(payload, element) {
        const endpoint = this.withCurrentToken(`${this.apiBase}/toggle`);
        const card = element.closest('.action-card');
        const applyMode = (mode) => {
            element.value = String(mode);
            if (card) {
                card.setAttribute('data-state', String(mode));
                const label = card.querySelector('[data-pump-force-label]');
                if (label) {
                    label.textContent = mode === 1 ? 'Forcé ON' : (mode === 2 ? 'Forcé OFF' : 'Auto');
                }
            }
        };

        element.disabled = true;
        card?.classList.remove('is-success', 'is-error');
        card?.classList.add('is-updating');

        const fetchWithRetry = window.fetchWithRetry || fetch;

        try {
            const response = await fetchWithRetry(endpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'fetch',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                credentials: 'include',
                body: JSON.stringify({ id: payload.id, gpio: payload.gpio, state: payload.state }),
            });

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP ${response.status} ${response.statusText} ${errorText}`);
            }

            const data = await response.json();
            const mode = (data && data.state !== undefined) ? Number(data.state) : payload.state;
            applyMode(mode);

            if (typeof toastManager !== 'undefined') {
                const msg = mode === 1 ? 'Pompe aquarium forcée ON'
                    : (mode === 2 ? 'Pompe aquarium forcée OFF' : 'Pompe aquarium en mode Auto');
                toastManager.showSuccess(msg, 2500);
            }
            if (card) {
                card.classList.add('is-success');
                setTimeout(() => card.classList.remove('is-success'), 1500);
            }
            if (window.controlSync) {
                window.controlSync.forceSync();
            }
        } catch (error) {
            console.error('[ControlActions] Pump force mode error', error);
            if (card) {
                card.classList.add('is-error');
                setTimeout(() => card.classList.remove('is-error'), 2500);
            }
            if (typeof toastManager !== 'undefined') {
                toastManager.showError('Changement de mode refusé', 4000);
            }
            // Re-synchronise le sélecteur sur l'état réel du serveur après échec.
            if (window.controlSync) {
                window.controlSync.forceSync();
            }
        } finally {
            element.disabled = false;
            card?.classList.remove('is-updating');
        }
    }

    updateCardState(element, isActive) {
        const card = element.closest('.action-card');
        if (!card) {
            return;
        }

        card.dataset.state = isActive ? '1' : '0';
        const statusLabel = card.querySelector('[data-state-label]');
        if (statusLabel) {
            statusLabel.textContent = isActive ? 'Activé' : 'Désactivé';
            statusLabel.style.color = isActive ? '#27ae60' : '#7f8c8d';
            statusLabel.style.fontWeight = isActive ? '600' : '400';
        }

        card.classList.add('state-changed');
        setTimeout(() => card.classList.remove('state-changed'), 800);
    }

    withCurrentToken(endpoint) {
        try {
            const currentUrl = new URL(window.location.href);
            const token = currentUrl.searchParams.get('token');
            if (!token) {
                return endpoint;
            }

            const targetUrl = new URL(endpoint, window.location.origin);
            if (!targetUrl.searchParams.has('token')) {
                targetUrl.searchParams.set('token', token);
            }
            return `${targetUrl.pathname}${targetUrl.search}`;
        } catch (error) {
            console.warn('[ControlActions] Impossible d\'ajouter le token', error);
            return endpoint;
        }
    }
}

window.ControlActions = ControlActions;
function ensureControlActions() {
    if (!window.controlActions) {
        const apiBase = typeof window.CONTROL_API_BASE !== 'undefined' ? window.CONTROL_API_BASE : '/api/outputs';
        window.controlActions = new ControlActions({ apiBase });
    }
    return window.controlActions;
}
window.updateOutput = function(element) {
    ensureControlActions().toggleOutput(element);
};
window.setPumpForceMode = function(element) {
    ensureControlActions().setPumpForceMode(element);
};
window.triggerManualFeed = function(button) {
    ensureControlActions().triggerManualFeed(button);
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-feed-trigger]').forEach((btn) => {
        btn.addEventListener('click', () => window.triggerManualFeed(btn));
    });
});
