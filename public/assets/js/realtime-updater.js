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

        const sensorsUrl = this.lastTimestamp > 0
            ? `${this.apiBasePath}/sensors/since/${this.lastTimestamp}`
            : `${this.apiBasePath}/sensors/latest`;
        const healthUrl = `${this.apiBasePath}/system/health`;

        try {
            // Requêtes en parallèle : santé toujours récupérée pour mettre à jour le bloc "Temps réel"
            const [sensorsResponse, healthResponse] = await Promise.all([
                fetch(sensorsUrl),
                fetch(healthUrl)
            ]);

            let healthData = null;
            if (healthResponse.ok) {
                try {
                    healthData = await healthResponse.json();
                } catch (e) { /* ignore */ }
            }
            if (healthData) {
                if (this.callbacks.onHealthUpdate) this.callbacks.onHealthUpdate(healthData);
            } else {
                healthData = { last_reading_ago_seconds: null, last_reading: null, readings_today: undefined, online: false };
            }
            this.updateSystemStatus(healthData);

            // Traiter les données capteurs
            let newReadings = [];
            if (sensorsResponse.ok) {
                const data = await sensorsResponse.json();
                if (this.lastTimestamp > 0) {
                    newReadings = data.readings || [];
                } else if (data.timestamp) {
                    this.lastTimestamp = data.timestamp;
                    newReadings = [data];
                }
            } else {
                throw new Error(`Sensors HTTP ${sensorsResponse.status}`);
            }

            this.retryCount = 0;

            if (newReadings.length > 0) {
                console.log(`[RealtimeUpdater] ${newReadings.length} new reading(s) received!`);
                
                const lastReading = newReadings[newReadings.length - 1];
                if (lastReading.timestamp > this.lastTimestamp) {
                    this.lastTimestamp = lastReading.timestamp;
                }
                
                if (this.callbacks.onNewData) {
                    this.callbacks.onNewData(newReadings);
                }
                
                if (window.chartUpdater) {
                    window.chartUpdater.addNewReadings(newReadings);
                }
                
                if (window.statsUpdater && lastReading.sensors) {
                    window.statsUpdater.updateAllStats(lastReading.sensors, lastReading.timestamp);
                }
                
                if (typeof toastManager !== 'undefined' && newReadings.length > 1) {
                    toastManager.showInfo(`${newReadings.length} nouvelles lectures reçues`, 3000);
                }
            }

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
     * Met à jour le badge de statut (optionnel : absent sur pages n3pp/msp1).
     */
    updateBadge(status) {
        const badge = document.getElementById('live-badge');
        if (!badge) return; // Pas d'erreur si le bloc temps réel a été retiré

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
     * Met à jour les informations système dans l'UI (bloc optionnel : absent sur n3pp/msp1).
     */
    updateSystemStatus(health) {
        // Dernière réception
        const lastReadingEl = document.getElementById('last-reading-time');
        if (lastReadingEl) {
            const agoSpan = lastReadingEl.querySelector('[data-reading-ago]');
            const timestampSpan = lastReadingEl.querySelector('[data-reading-timestamp]');

            if (health.last_reading_ago_seconds !== null && health.last_reading_ago_seconds !== undefined) {
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
            } else {
                if (agoSpan) {
                    agoSpan.textContent = '—';
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

        // Temps total depuis le début du fonctionnement du module (section Filtrage)
        const moduleUptimeEl = document.getElementById('module-uptime-total');
        if (moduleUptimeEl) {
            if (health.module_uptime_seconds !== null && health.module_uptime_seconds !== undefined) {
                moduleUptimeEl.textContent = this.formatTimeSince(health.module_uptime_seconds);
            } else {
                moduleUptimeEl.textContent = '—';
            }
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
            } else {
                if (countSpan) {
                    countSpan.textContent = '—';
                }
                if (hintSpan) {
                    hintSpan.style.display = '';
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
     * Formate un temps total (uptime) en secondes en chaîne lisible (jours, semaines, mois)
     */
    formatUptimeTotal(seconds) {
        if (seconds < 60) return `${seconds} s`;
        if (seconds < 3600) return `${Math.floor(seconds / 60)} min`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)} h`;
        const days = Math.floor(seconds / 86400);
        if (days < 30) return `${days} j`;
        if (days < 365) return `${Math.floor(days / 30)} mois`;
        const years = Math.floor(days / 365);
        const remainderMonths = Math.floor((days % 365) / 30);
        if (remainderMonths === 0) return `${years} an${years > 1 ? 's' : ''}`;
        return `${years} an${years > 1 ? 's' : ''} ${remainderMonths} mois`;
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

