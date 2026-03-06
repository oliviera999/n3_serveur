class ControlActions {
    constructor(options = {}) {
        this.apiBase = options.apiBase || '/ffp3/api/outputs';
        this.isProcessing = false;
        this.queue = [];
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

        this.enqueueAction(() => this.sendToggleRequest(payload, element));
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
        const env = document.body?.dataset?.environment || 'prod';
        const endpoint = env === 'test' ? '/ffp3/api/outputs-test/toggle'
            : env === 'test3' ? '/ffp3/api/outputs3-test/toggle'
            : env === 's3' ? '/ffp3/api/outputs3/toggle'
            : `${this.apiBase}/toggle`;
        element.disabled = true;
        element.closest('.action-card')?.classList.add('is-updating');

        try {
            const query = new URLSearchParams({
                id: String(payload.id),
                gpio: String(payload.gpio),
                state: String(payload.state),
            });

            const response = await fetch(`${endpoint}?${query.toString()}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'fetch',
                },
                credentials: 'include',
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

            if (typeof toastManager !== 'undefined') {
                toastManager.showSuccess('Commande envoyée', 2500);
            }

            if (window.controlSync) {
                window.controlSync.forceSync();
            }

        } catch (error) {
            console.error('[ControlActions] Toggle error', error);
            element.checked = !element.checked;
            if (typeof toastManager !== 'undefined') {
                toastManager.showError('Commande refusée', 4000);
            }
        } finally {
            element.disabled = false;
            element.closest('.action-card')?.classList.remove('is-updating');
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
}

window.ControlActions = ControlActions;
window.updateOutput = function(element) {
    if (!window.controlActions) {
        window.controlActions = new ControlActions();
    }
    window.controlActions.toggleOutput(element);
};

