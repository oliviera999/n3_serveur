/**
 * ControlSync - Synchronisation temps réel de l'interface de contrôle
 * 
 * Gère le polling automatique des états GPIO/outputs et met à jour
 * l'interface utilisateur en temps réel.
 * 
 * @version 1.0.0
 * @date 2025-10-11
 */

class ControlSync {
    constructor(options = {}) {
        // Configuration
        this.apiBase = options.apiBase || '/ffp3/api/outputs';
        this.pollInterval = (options.pollInterval || 10) * 1000; // 10 secondes par défaut
        this.maxRetries = options.maxRetries || 5;
        this.useFresh = options.useFresh === true; // ?fresh=1 pour ignorer le cache (valeurs BDD à jour)
        
        // État interne
        this.isRunning = false;
        this.isPaused = false;
        this.retryCount = 0;
        this.pollTimer = null;
        this.lastStates = {}; // Cache des derniers états connus
        
        // Callbacks
        this.onStateChange = options.onStateChange || null;
        this.onStatusChange = options.onStatusChange || null;
        this.onStatesReceived = options.onStatesReceived || null;
        
        // Éléments DOM
        this.switches = new Map();
        this.liveBadge = null;
        
        // Bind methods
        this.poll = this.poll.bind(this);
        this.handleVisibilityChange = this.handleVisibilityChange.bind(this);
        
        this.log('ControlSync initialized');
    }
    
    /**
     * Démarre le polling automatique
     */
    start() {
        if (this.isRunning) {
            this.log('Already running');
            return;
        }
        
        // S'assurer que le badge est trouvé avant de démarrer
        if (!this.liveBadge) {
            this.liveBadge = document.getElementById('control-sync-badge');
            if (!this.liveBadge) {
                this.log('Warning: Badge not found, retrying after DOM ready...', 'warn');
                // Attendre que le DOM soit complètement chargé
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', () => this.start());
                    return;
                }
            }
        }
        
        this.isRunning = true;
        this.isPaused = false;
        this.log('Starting control sync...');
        
        // Mettre à jour le badge avant de démarrer le polling
        this.updateBadgeStatus('connecting');
        
        // Surveiller la visibilité de la page
        document.addEventListener('visibilitychange', this.handleVisibilityChange);
        
        // Première mise à jour immédiate
        this.poll();
    }
    
    /**
     * Arrête le polling
     */
    stop() {
        this.isRunning = false;
        if (this.pollTimer) {
            clearTimeout(this.pollTimer);
            this.pollTimer = null;
        }
        
        document.removeEventListener('visibilitychange', this.handleVisibilityChange);
        this.log('Control sync stopped');
        this.updateBadgeStatus('offline');
    }
    
    /**
     * Met en pause / reprend le polling (changement de visibilité)
     */
    handleVisibilityChange() {
        if (document.hidden) {
            this.isPaused = true;
            this.log('Page hidden - pausing sync');
            if (this.pollTimer) {
                clearTimeout(this.pollTimer);
                this.pollTimer = null;
            }
            this.updateBadgeStatus('paused');
        } else {
            this.isPaused = false;
            this.log('Page visible - resuming sync');
            this.updateBadgeStatus('connecting');
            this.poll(); // Relancer immédiatement
        }
    }
    
    /**
     * Effectue une requête de polling
     */
    async poll() {
        if (!this.isRunning || this.isPaused) {
            return;
        }
        
        // S'assurer que le badge existe avant de faire le polling
        if (!this.liveBadge) {
            this.liveBadge = document.getElementById('control-sync-badge');
        }
        
        // Mettre le badge en état "connecting" si ce n'est pas déjà fait
        if (this.liveBadge && !this.liveBadge.classList.contains('online')) {
            this.updateBadgeStatus('connecting');
        }
        
        try {
            const stateUrl = `${this.apiBase}/state${this.useFresh ? '?fresh=1' : ''}`;
            const response = await fetch(stateUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const states = await response.json();
            
            // Traiter les changements d'état
            this.processStates(states);
            
            // Notifier les écouteurs (ex. mise à jour des champs paramètres)
            if (this.onStatesReceived) {
                this.onStatesReceived(states);
            }
            
            // Succès - reset retry count et mettre à jour le badge
            this.retryCount = 0;
            this.updateBadgeStatus('online');
            this.log('Polling successful - badge updated to SYNC');
            
            // Planifier le prochain poll
            this.schedulePoll();
            
        } catch (error) {
            this.handleError(error);
        }
    }
    
    /**
     * Traite les états reçus et détecte les changements
     */
    processStates(states) {
        const changes = [];
        
        for (const [gpio, state] of Object.entries(states)) {
            const gpioNum = parseInt(gpio);
            
            // Ignorer les clés non numériques (ex: "mail", "heat", "light")
            // Ces clés sont des alias ajoutés pour la compatibilité ESP32
            if (isNaN(gpioNum)) {
                continue;
            }
            
            // Pour les GPIOs < 100 et certains GPIOs spéciaux, c'est un entier (état on/off)
            // Pour les GPIOs >= 100, c'est souvent une valeur (texte, nombre, email, etc.)
            // Ne pas convertir systématiquement en parseInt pour éviter NaN sur les chaînes
            let newState;
            if (gpioNum < 100 || gpioNum === 101 || gpioNum === 108 || gpioNum === 109 || gpioNum === 110 || gpioNum === 115) {
                // États binaires (switches): convertir en entier
                newState = parseInt(state);
            } else if (gpioNum === 100) {
                // GPIO 100 = email (chaîne de caractères)
                newState = String(state || '');
            } else {
                // Autres paramètres (nombres ou texte): garder la valeur telle quelle
                // Tenter de convertir en nombre si possible, sinon garder comme chaîne
                const asNumber = parseFloat(state);
                newState = !isNaN(asNumber) ? asNumber : state;
            }
            
            // Vérifier si l'état a changé
            if (this.lastStates[gpioNum] !== undefined && this.lastStates[gpioNum] !== newState) {
                changes.push({
                    gpio: gpioNum,
                    oldState: this.lastStates[gpioNum],
                    newState: newState
                });
                
                this.log(`GPIO ${gpioNum} changed: ${this.lastStates[gpioNum]} → ${newState}`);
            }
            
            // Mettre à jour le cache
            this.lastStates[gpioNum] = newState;
        }
        
        // Toujours synchroniser les switches avec la réponse serveur (changements distants pris en compte)
        const switchSyncList = [];
        for (const [gpioStr, newState] of Object.entries(this.lastStates)) {
            const g = parseInt(gpioStr, 10);
            if (isNaN(g) || !document.querySelector(`input[data-gpio="${g}"]`)) continue;
            switchSyncList.push({ gpio: g, oldState: this.lastStates[g], newState: this.lastStates[g] });
        }
        if (switchSyncList.length > 0) {
            this.updateSwitches(switchSyncList);
        }

        if (states.dataStates && typeof states.dataStates === 'object') {
            this.updateDataIndicators(states.dataStates, states.dataStatesReadingTime);
        }

        if (changes.length > 0) {
            if (this.onStateChange) this.onStateChange(changes);
            if (window.toastManager) {
                const gpioList = changes.map(c => `GPIO ${c.gpio}`).join(', ');
                window.toastManager.showInfo(`Changement détecté: ${gpioList}`, 5000);
            }
        }
    }
    
    /**
     * Met à jour les switches dans l'interface
     */
    updateSwitches(changes) {
        changes.forEach(change => {
            // Trouver le switch correspondant
            const switchElement = document.querySelector(`input[data-gpio="${change.gpio}"]`);
            
            if (switchElement) {
                // Mettre à jour l'état du switch sans déclencher l'événement onchange
                const currentChecked = switchElement.checked;
                const isInverted = switchElement.dataset.inverted === '1';
                const stateNum = Number(change.newState);
                const shouldBeChecked = isInverted ? stateNum === 0 : stateNum === 1;
                
                if (currentChecked !== shouldBeChecked) {
                    // Animation flash pour indiquer le changement
                    const container = switchElement.closest('.action-card');
                    if (container) {
                        container.classList.add('state-changed');
                        setTimeout(() => container.classList.remove('state-changed'), 1000);
                    }

                    // Mettre à jour le switch
                    switchElement.checked = shouldBeChecked;
                    if (container) {
                        container.setAttribute('data-state', shouldBeChecked ? '1' : '0');
                        const statusLabel = container.querySelector('[data-state-label]');
                        if (statusLabel) {
                            statusLabel.textContent = shouldBeChecked ? 'Activé' : 'Désactivé';
                            statusLabel.style.color = shouldBeChecked ? '#27ae60' : '#7f8c8d';
                            statusLabel.style.fontWeight = shouldBeChecked ? '600' : '400';
                        }
                    }

                    // Mettre à jour le badge de synchronisation vers "synchronisé par ESP32"
                    if (window.updateSyncBadge) {
                        window.updateSyncBadge(change.gpio, 'esp32', 'ESP32 SYNC');
                        
                        // Après 3 secondes, remettre en "synchronisé"
                        setTimeout(() => {
                            if (window.updateSyncBadge) {
                                window.updateSyncBadge(change.gpio, 'synced', 'SYNC');
                            }
                        }, 3000);
                    }
                    
                    this.log(`Updated switch GPIO ${change.gpio} to ${shouldBeChecked}`);
                } else {
                    // Même si l'état n'a pas changé, marquer comme synchronisé (confirmation ESP32)
                    if (window.updateSyncBadge) {
                        window.updateSyncBadge(change.gpio, 'synced', 'SYNC');
                    }
                    const container = switchElement.closest('.action-card');
                    if (container) {
                        container.setAttribute('data-state', shouldBeChecked ? '1' : '0');
                        const statusLabel = container.querySelector('[data-state-label]');
                        if (statusLabel) {
                            statusLabel.textContent = shouldBeChecked ? 'Activé' : 'Désactivé';
                            statusLabel.style.color = shouldBeChecked ? '#27ae60' : '#7f8c8d';
                            statusLabel.style.fontWeight = shouldBeChecked ? '600' : '400';
                        }
                    }
                }
            }
        });
    }
    
    /**
     * Met à jour les témoins "dernier état Data" (point à côté du label Activé/Désactivé)
     * @param {Object} dataStates - { [gpio]: 0|1|null }
     * @param {string|null} readingTime - Date/heure de la dernière ligne data (optionnel)
     */
    updateDataIndicators(dataStates, readingTime) {
        const cards = document.querySelectorAll('.action-card[data-gpio]');
        const titleSuffix = readingTime ? ` – ${readingTime}` : '';
        cards.forEach(card => {
            const gpio = parseInt(card.getAttribute('data-gpio'), 10);
            if (isNaN(gpio)) return;
            const indicator = card.querySelector('[data-data-indicator]');
            if (!indicator) return;
            const value = dataStates[gpio] ?? dataStates[String(gpio)] ?? null;
            indicator.classList.remove('data-indicator-on', 'data-indicator-off', 'data-indicator-unknown');
            if (value === 1) {
                indicator.classList.add('data-indicator-on');
            } else if (value === 0) {
                indicator.classList.add('data-indicator-off');
            } else {
                indicator.classList.add('data-indicator-unknown');
            }
            indicator.setAttribute('title', 'Dernier état enregistré (table data)' + titleSuffix);
        });
    }

    /**
     * Planifie le prochain poll
     */
    schedulePoll() {
        if (this.pollTimer) {
            clearTimeout(this.pollTimer);
        }
        
        if (this.isRunning && !this.isPaused) {
            this.pollTimer = setTimeout(this.poll, this.pollInterval);
        }
    }
    
    /**
     * Gère les erreurs de polling
     */
    handleError(error) {
        this.log(`Polling error: ${error.message}`, 'error');
        
        this.retryCount++;
        
        if (this.retryCount >= this.maxRetries) {
            this.log('Max retries reached - stopping sync', 'error');
            this.updateBadgeStatus('error');
            this.stop();
            
            if (window.toastManager) {
                window.toastManager.showError('Synchronisation interrompue après plusieurs échecs', 10000);
            }
        } else {
            // Retry avec backoff exponentiel
            const retryDelay = Math.min(1000 * Math.pow(2, this.retryCount), 30000);
            this.log(`Retry ${this.retryCount}/${this.maxRetries} in ${retryDelay}ms`);
            
            this.updateBadgeStatus('warning');
            
            if (this.pollTimer) {
                clearTimeout(this.pollTimer);
            }
            
            this.pollTimer = setTimeout(this.poll, retryDelay);
        }
    }
    
    /**
     * Met à jour le badge LIVE
     */
    updateBadgeStatus(status) {
        if (!this.liveBadge) {
            this.liveBadge = document.getElementById('control-sync-badge');
        }
        
        if (!this.liveBadge) {
            this.log(`Badge 'control-sync-badge' not found in DOM`, 'warn');
            return;
        }
        
        // Retirer toutes les classes de statut possibles
        this.liveBadge.classList.remove('connecting', 'online', 'offline', 'error', 'warning', 'paused');
        
        // Ajouter la nouvelle classe
        this.liveBadge.classList.add(status);
        
        // Mettre à jour le texte
        const texts = {
            'connecting': 'CONNEXION...',
            'online': 'SYNC',
            'offline': 'HORS LIGNE',
            'error': 'ERREUR',
            'warning': 'RECONNEXION...',
            'paused': 'PAUSE'
        };
        
        this.liveBadge.textContent = texts[status] || status.toUpperCase();
        
        this.log(`Badge status updated to: ${status} (${texts[status] || status.toUpperCase()})`);
        
        // Notifier via callback
        if (this.onStatusChange) {
            this.onStatusChange(status);
        }
    }
    
    /**
     * Initialise les états depuis l'interface actuelle
     */
    initializeFromDOM() {
        const switches = document.querySelectorAll('input[data-gpio]');
        switches.forEach(switchEl => {
            const gpio = parseInt(switchEl.dataset.gpio);
            const isInverted = switchEl.dataset.inverted === '1';
            const state = isInverted ? (switchEl.checked ? 0 : 1) : (switchEl.checked ? 1 : 0);
            this.lastStates[gpio] = state;
            this.switches.set(gpio, switchEl);
        });
        
        this.log(`Initialized ${switches.length} switches from DOM`);
    }
    
    /**
     * Force une synchronisation immédiate
     */
    forceSync() {
        this.log('Force sync requested');
        if (this.pollTimer) {
            clearTimeout(this.pollTimer);
        }
        this.poll();
    }
    
    /**
     * Log avec préfixe
     */
    log(message, level = 'info') {
        const prefix = '[ControlSync]';
        if (level === 'error') {
            console.error(`${prefix} ${message}`);
        } else if (level === 'warn') {
            console.warn(`${prefix} ${message}`);
        } else {
            console.log(`${prefix} ${message}`);
        }
    }
}

// Export global pour utilisation dans le template
window.ControlSync = ControlSync;

