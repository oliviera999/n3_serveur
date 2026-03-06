/**
 * Realtime Data Updater
 * Système de polling intelligent pour mise à jour automatique des données
 */
class RealtimeUpdater {
    constructor(options = {}) {
        this.pollInterval = options.pollInterval || 15000; // 15 secondes par défaut
        this.apiBasePath = options.apiBasePath || '/ffp3/api/realtime';
        this.enabled = options.enabled !== false;
        // Premier poll : 0 pour appeler sensors/latest et afficher la dernière lecture (cartes + graphique)
        this.lastTimestamp = 0;
        this.pollTimer = null;
        this.retryCount = 0;
        this.maxRetries = 5;
        this.isPaused = false;
        this.callbacks = {
            onNewData: options.onNewData || null,
            onHealthUpdate: options.onHealthUpdate || null,
            onError: options.onError || null
        };

        // Gérer la visibilité de la page (pause si onglet inactif)
        if (typeof document.hidden !== 'undefined') {
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.pause();
                } else {
                    this.resume();
                }
            });
        }
    }

    /**
     * Démarre le polling
     */
    start() {
        if (!this.enabled) return;
        
        console.log('[RealtimeUpdater] Starting polling...');
        this.updateBadge('connecting');
        
        // Première requête immédiate
        this.poll();
        
        // Puis polling régulier
        this.pollTimer = setInterval(() => this.poll(), this.pollInterval);
    }

    /**
     * Arrête le polling
     */
    stop() {
        console.log('[RealtimeUpdater] Stopping polling...');
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
        this.updateBadge('offline');
    }

    /**
     * Met en pause (ne pas confondre avec stop)
     */
    pause() {
        if (!this.isPaused) {
            console.log('[RealtimeUpdater] Pausing (tab hidden)...');
            this.isPaused = true;
            this.updateBadge('paused');
        }
    }

    /**
     * Reprend après pause
     */
    resume() {
        if (this.isPaused) {
            console.log('[RealtimeUpdater] Resuming (tab visible)...');
            this.isPaused = false;
            this.poll(); // Rafraîchir immédiatement
            this.updateBadge('online');
        }
    }

    /**
     * Effectue une requête de polling
     */
    async poll() {
        if (this.isPaused) return;

        try {
            // Récupérer les nouvelles données depuis le dernier timestamp
            // Si c'est le premier poll, récupérer juste la dernière lecture
            let newReadings = [];
            
            if (this.lastTimestamp > 0) {
                // Polling incrémental : récupérer toutes les nouvelles lectures
                const sinceResponse = await fetch(`${this.apiBasePath}/sensors/since/${this.lastTimestamp}`);
                if (!sinceResponse.ok) throw new Error(`HTTP ${sinceResponse.status}`);
                
                const sinceData = await sinceResponse.json();
                newReadings = sinceData.readings || [];
            } else {
                // Premier poll : récupérer juste la dernière lecture
                const latestResponse = await fetch(`${this.apiBasePath}/sensors/latest`);
                if (!latestResponse.ok) throw new Error(`HTTP ${latestResponse.status}`);
                
                const latestData = await latestResponse.json();
                
                if (latestData.timestamp) {
                    this.lastTimestamp = latestData.timestamp;
                    newReadings = [latestData];
                }
            }
            
            // Récupérer le statut système
            const healthResponse = await fetch(`${this.apiBasePath}/system/health`);
            if (!healthResponse.ok) throw new Error(`HTTP ${healthResponse.status}`);
            
            const healthData = await healthResponse.json();

            // Succès : reset retry counter
            this.retryCount = 0;
            
            // NE PAS forcer le badge à 'online' ici, car updateSystemStatus() le fera
            // en fonction du vrai statut du système (health.online)

            // Traiter les nouvelles données
            if (newReadings.length > 0) {
                console.log(`[RealtimeUpdater] ${newReadings.length} new reading(s) received!`);
                
                // Mettre à jour le timestamp avec la dernière lecture
                const lastReading = newReadings[newReadings.length - 1];
                if (lastReading.timestamp > this.lastTimestamp) {
                    this.lastTimestamp = lastReading.timestamp;
                }
                
                // Callback générique
                if (this.callbacks.onNewData) {
                    this.callbacks.onNewData(newReadings);
                }
                
                // Mettre à jour les graphiques
                if (window.chartUpdater) {
                    window.chartUpdater.addNewReadings(newReadings);
                }
                
                // Mettre à jour les statistiques avec la dernière lecture (inclure le timestamp)
                if (window.statsUpdater && lastReading.sensors) {
                    window.statsUpdater.updateAllStats(lastReading.sensors, lastReading.timestamp);
                }
                
                // Notification toast (seulement si plusieurs nouvelles données)
                if (typeof toastManager !== 'undefined' && newReadings.length > 1) {
                    toastManager.showInfo(`${newReadings.length} nouvelles lectures reçues`, 3000);
                }
            }

            // Mettre à jour le statut système
            if (this.callbacks.onHealthUpdate) {
                this.callbacks.onHealthUpdate(healthData);
            }

            this.updateSystemStatus(healthData);

        } catch (error) {
            console.error('[RealtimeUpdater] Error during poll:', error);
            this.retryCount++;
            
            if (this.retryCount >= this.maxRetries) {
                this.updateBadge('error');
                if (typeof toastManager !== 'undefined') {
                    toastManager.showError('Erreur de connexion. Vérifiez votre réseau.');
                }
            } else {
                this.updateBadge('warning');
            }

            if (this.callbacks.onError) {
                this.callbacks.onError(error);
            }
        }
    }

    /**
     * Met à jour le badge de statut
     */
    updateBadge(status) {
        const badge = document.getElementById('live-badge');
        if (!badge) return;

        const statusConfig = {
            connecting: { text: 'CONNEXION...', class: 'badge-warning', icon: 'fa-spinner fa-spin' },
            online: { text: 'LIVE', class: 'badge-success', icon: 'fa-circle' },
            offline: { text: 'HORS LIGNE', class: 'badge-danger', icon: 'fa-circle' },
            error: { text: 'ERREUR', class: 'badge-danger', icon: 'fa-exclamation-triangle' },
            warning: { text: 'INSTABLE', class: 'badge-warning', icon: 'fa-exclamation-circle' },
            paused: { text: 'PAUSE', class: 'badge-secondary', icon: 'fa-pause' }
        };

        const config = statusConfig[status] || statusConfig.offline;
        
        badge.className = `badge ${config.class}`;
        badge.innerHTML = `<i class="fas ${config.icon}"></i> ${config.text}`;
    }

    /**
     * Met à jour les informations système dans l'UI
     */
    updateSystemStatus(health) {
        // Dernière réception
        const lastReadingEl = document.getElementById('last-reading-time');
        if (lastReadingEl) {
            const agoSpan = lastReadingEl.querySelector('[data-reading-ago]');
            const timestampSpan = lastReadingEl.querySelector('[data-reading-timestamp]');

            if (health.last_reading_ago_seconds !== null) {
                const ago = this.formatTimeSince(health.last_reading_ago_seconds);
                const at = health.last_reading ? this.formatTimestamp(health.last_reading) : null;

                if (agoSpan) {
                    agoSpan.textContent = ago;
                }

                if (timestampSpan) {
                    if (at) {
                        timestampSpan.textContent = `(${at})`;
                        timestampSpan.style.display = '';
                    } else {
                        timestampSpan.textContent = '';
                        timestampSpan.style.display = 'none';
                    }
                }

                lastReadingEl.dataset.defaultHandled = 'true';
            } else if (!lastReadingEl.dataset.defaultHandled) {
                if (agoSpan) {
                    agoSpan.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                }

                if (timestampSpan) {
                    timestampSpan.textContent = '';
                    timestampSpan.style.display = 'none';
                }

                lastReadingEl.dataset.defaultHandled = 'true';
            }
        }

        // Uptime
        const uptimeEl = document.getElementById('system-uptime');
        if (uptimeEl && health.uptime_percentage !== undefined) {
            uptimeEl.textContent = `${health.uptime_percentage.toFixed(1)}%`;
        }

        // Lectures aujourd'hui
        const readingsTodayEl = document.getElementById('readings-today');
        if (readingsTodayEl) {
            const countSpan = readingsTodayEl.querySelector('[data-reading-count]');
            const hintSpan = readingsTodayEl.querySelector('[data-reading-hint]');

            if (health.readings_today !== undefined && countSpan) {
                countSpan.textContent = String(health.readings_today);
                if (hintSpan) {
                    hintSpan.style.display = '';
                }
                readingsTodayEl.dataset.defaultHandled = 'true';
            } else if (!readingsTodayEl.dataset.defaultHandled) {
                if (countSpan) {
                    countSpan.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                }
                if (hintSpan) {
                    hintSpan.style.display = 'none';
                }
                readingsTodayEl.dataset.defaultHandled = 'true';
            }
        }

        // IP locale (ESP)
        const deviceIpEl = document.getElementById('device-ip');
        if (deviceIpEl) {
            deviceIpEl.textContent = health.device_ip || '-';
        }

        // Statut online/offline
        const statusIndicator = document.getElementById('system-status-indicator');
        if (statusIndicator) {
            const nextStatusClass = health.online ? 'status-online' : 'status-offline';
            statusIndicator.className = `status-indicator ${nextStatusClass} ${health.online ? 'is-animated' : ''}`.trim();

            const statusBadge = statusIndicator.querySelector('[data-status-badge]');
            const statusLabel = statusIndicator.querySelector('[data-status-label]');

            if (statusBadge) {
                statusBadge.innerHTML = health.online
                    ? '<i class="fas fa-circle"></i><strong>TEMPS RÉEL</strong>'
                    : '<i class="fas fa-exclamation-circle"></i><strong>SUPERVISION</strong>';
            }

            if (statusLabel) {
                statusLabel.innerHTML = health.online
                    ? '<i class="fas fa-check-circle"></i><span>En ligne</span>'
                    : '<i class="fas fa-times-circle"></i><span>Hors ligne</span>';
            }
        }

        // Mettre à jour le badge LIVE en fonction du statut système réel
        // Si le système ESP32 est hors ligne, afficher "HORS LIGNE" même si le polling fonctionne
        if (!health.online) {
            this.updateBadge('offline');
        } else if (!this.isPaused) {
            // Seulement afficher "LIVE" si le système est en ligne ET le polling actif
            this.updateBadge('online');
        }
    }

    /**
     * Formate un temps en secondes en chaîne lisible
     */
    formatTimeSince(seconds) {
        if (seconds < 60) return `${seconds}s`;
        if (seconds < 3600) return `${Math.floor(seconds / 60)}min`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)}h`;
        return `${Math.floor(seconds / 86400)}j`;
    }

    /**
     * Formate un timestamp ISO en heure locale lisible
     */
    formatTimestamp(timestamp) {
        try {
            const date = new Date(timestamp.replace(' ', 'T'));
            const options = {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                day: '2-digit',
                month: '2-digit'
            };
            return date.toLocaleString('fr-FR', options);
        } catch (error) {
            console.warn('[RealtimeUpdater] Unable to format timestamp', timestamp, error);
            return timestamp;
        }
    }

    /**
     * Change l'intervalle de polling (en millisecondes)
     */
    setInterval(newInterval) {
        this.pollInterval = newInterval;
        if (this.pollTimer) {
            this.stop();
            this.start();
        }
    }
}

// Variable globale pour accès facile
let realtimeUpdater = null;

/**
 * Initialise le système de mise à jour temps réel
 */
function initRealtimeUpdater(options = {}) {
    if (realtimeUpdater) {
        realtimeUpdater.stop();
    }
    
    realtimeUpdater = new RealtimeUpdater(options);
    realtimeUpdater.start();
    
    return realtimeUpdater;
}

