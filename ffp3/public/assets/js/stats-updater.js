/**
 * StatsUpdater - Mise à jour dynamique des cartes de statistiques en temps réel
 * 
 * Met à jour les valeurs affichées, les barres de progression, et les statistiques
 * sans recharger la page.
 * 
 * @version 1.0.0
 * @date 2025-10-12
 */

class StatsUpdater {
    constructor(options = {}) {
        // Configuration
        this.sensors = options.sensors || [
            'EauAquarium', 'EauReserve', 'EauPotager',
            'TempEau', 'TempAir', 'Humidite', 'Luminosite'
        ];
        
        // Mapping explicite des capteurs vers leurs IDs réels dans le DOM
        this.sensorIdMap = {
            'EauAquarium': 'eauaqua',
            'EauReserve': 'eaureserve',
            'EauPotager': 'eaupota',
            'TempEau': 'tempeau',
            'TempAir': 'tempair',
            'Humidite': 'humi',
            'Luminosite': 'lumi'
        };
        
        // Cache des statistiques actuelles (pour calcul incrémental)
        this.stats = new Map();
        
        // Mapping des capteurs vers leurs éléments DOM
        this.sensorElements = new Map();
        
        // État
        this.isInitialized = false;
        this.readingCount = 0;
        this.initialReadingCount = 0; // Compteur initial (historique)
        this.liveReadingCount = 0; // Compteur des nouvelles lectures en live
        this.startTimestamp = null;
        this.endTimestamp = null;
        this.initialStartTimestamp = null; // Période initiale fixe
        this.initialEndTimestamp = null;
        
        // Fenêtre glissante
        this.slidingWindowEnabled = options.slidingWindow !== false; // true par défaut
        this.slidingWindowDuration = options.windowDuration || (6 * 3600); // 6h en secondes par défaut
        this.isLiveMode = false; // Indique si on est en mode live (reçoit de nouvelles données)
        
        this.log('StatsUpdater initialized');
    }
    
    /**
     * Initialise les références aux éléments DOM
     */
    init() {
        // Scanner les éléments de cartes de statistiques
        this.sensors.forEach(sensor => {
            // Utiliser le mapping explicite pour obtenir l'ID réel
            const sensorKey = this.sensorIdMap[sensor] || sensor.toLowerCase();
            
            // Éléments spécifiques aux niveaux d'eau
            if (sensor.startsWith('Eau')) {
                const displayEl = document.getElementById(`${sensorKey}-display`);
                const progressEl = document.getElementById(`${sensorKey}-progress`);
                
                if (displayEl || progressEl) {
                    this.sensorElements.set(sensor, {
                        display: displayEl,
                        progress: progressEl,
                        type: 'water'
                    });
                }
            }
            // Éléments pour température, humidité, luminosité
            else {
                const displayEl = document.getElementById(`${sensorKey}-display`);
                const progressEl = document.getElementById(`${sensorKey}-progress`);
                
                if (displayEl || progressEl) {
                    this.sensorElements.set(sensor, {
                        display: displayEl,
                        progress: progressEl,
                        type: this.getSensorType(sensor)
                    });
                }
            }
            
            // Initialiser les stats à zéro
            this.stats.set(sensor, {
                last: 0,
                min: Infinity,
                max: -Infinity,
                sum: 0,
                count: 0,
                avg: 0
            });
        });
        
        this.isInitialized = this.sensorElements.size > 0;
        this.log(`Initialized with ${this.sensorElements.size} sensor element(s)`);
        
        return this.isInitialized;
    }
    
    /**
     * Détermine le type de capteur
     */
    getSensorType(sensor) {
        if (sensor.startsWith('Temp')) return 'temperature';
        if (sensor === 'Humidite') return 'humidity';
        if (sensor === 'Luminosite') return 'light';
        if (sensor.startsWith('Eau')) return 'water';
        return 'generic';
    }
    
    /**
     * Met à jour toutes les statistiques avec de nouvelles valeurs
     * 
     * @param {Object} sensors - Objet contenant les valeurs des capteurs
     * @param {number} timestamp - Timestamp de la lecture (optionnel)
     */
    updateAllStats(sensors, timestamp = null) {
        if (!this.isInitialized) {
            this.log('Not initialized, skipping update', 'warn');
            return;
        }
        
        for (const [sensorName, value] of Object.entries(sensors)) {
            if (value !== null && value !== undefined) {
                this.updateStat(sensorName, parseFloat(value));
            }
        }
        
        // Mettre à jour les informations de période si timestamp fourni
        if (timestamp !== null) {
            this.updatePeriodInfo(timestamp);
        } else {
            // Sinon, juste incrémenter le compteur
            this.incrementReadingCount();
        }
    }
    
    /**
     * Met à jour une statistique individuelle
     * 
     * @param {string} sensorName - Nom du capteur
     * @param {number} value - Nouvelle valeur
     */
    updateStat(sensorName, value) {
        // Mettre à jour les stats en cache
        const stat = this.stats.get(sensorName);
        if (stat) {
            stat.last = value;
            stat.min = Math.min(stat.min, value);
            stat.max = Math.max(stat.max, value);
            stat.sum += value;
            stat.count++;
            stat.avg = stat.sum / stat.count;
            
            // Calcul de l'écart-type (approximation simplifiée)
            if (!stat.squareSum) stat.squareSum = 0;
            stat.squareSum += value * value;
            const variance = (stat.squareSum / stat.count) - (stat.avg * stat.avg);
            stat.stddev = Math.sqrt(Math.max(0, variance));
        }
        
        // Mettre à jour l'affichage principal
        this.updateStatCard(sensorName, value);
        
        // Mettre à jour les statistiques détaillées (min, max, avg, stddev)
        this.updateStatDetails(sensorName);
    }
    
    /**
     * Met à jour une carte de statistique dans l'UI
     * 
     * @param {string} sensorName - Nom du capteur
     * @param {number} value - Valeur à afficher
     */
    updateStatCard(sensorName, value) {
        const elements = this.sensorElements.get(sensorName);
        if (!elements) {
            return;
        }
        
        // Déterminer l'unité et les paramètres
        const config = this.getSensorConfig(sensorName);
        
        // Mettre à jour l'affichage de la valeur
        if (elements.display) {
            const formattedValue = value.toFixed(config.decimals);
            elements.display.innerHTML = `${formattedValue} <span class="stat-card-unit">${config.unit}</span>`;
            
            // Animation flash pour indiquer le changement
            elements.display.classList.add('value-updated');
            setTimeout(() => {
                elements.display.classList.remove('value-updated');
            }, 500);
        }
        
        // Mettre à jour la barre de progression
        if (elements.progress && config.max) {
            const percentage = Math.min(100, Math.max(0, (value / config.max) * 100));
            elements.progress.style.width = `${percentage.toFixed(0)}%`;
            
            // Animation de la barre
            elements.progress.classList.add('progress-updated');
            setTimeout(() => {
                elements.progress.classList.remove('progress-updated');
            }, 500);
        }
    }
    
    /**
     * Obtient la configuration d'affichage pour un capteur
     * 
     * @param {string} sensorName - Nom du capteur
     * @returns {Object} Configuration
     */
    getSensorConfig(sensorName) {
        const configs = {
            'EauAquarium': { unit: 'cm', decimals: 0, max: 100 },
            'EauReserve': { unit: 'cm', decimals: 0, max: 100 },
            'EauPotager': { unit: 'cm', decimals: 0, max: 100 },
            'TempEau': { unit: '°C', decimals: 1, max: 40 },
            'TempAir': { unit: '°C', decimals: 1, max: 40 },
            'Humidite': { unit: '%', decimals: 1, max: 100 },
            'Luminosite': { unit: 'UA', decimals: 0, max: 4000 }
        };
        
        return configs[sensorName] || { unit: '', decimals: 1, max: null };
    }
    
    /**
     * Met à jour les statistiques détaillées affichées sous une carte
     * 
     * @param {string} sensorName - Nom du capteur
     */
    updateStatDetails(sensorName) {
        const stat = this.stats.get(sensorName);
        if (!stat || stat.count === 0) return;
        
        const sensorKey = this.sensorIdMap[sensorName] || sensorName.toLowerCase();
        const config = this.getSensorConfig(sensorName);
        
        // Mettre à jour min
        const minEl = document.getElementById(`${sensorKey}-min`);
        if (minEl) {
            minEl.textContent = stat.min.toFixed(config.decimals);
        }
        
        // Mettre à jour max
        const maxEl = document.getElementById(`${sensorKey}-max`);
        if (maxEl) {
            maxEl.textContent = stat.max.toFixed(config.decimals);
        }
        
        // Mettre à jour moyenne
        const avgEl = document.getElementById(`${sensorKey}-avg`);
        if (avgEl) {
            avgEl.textContent = stat.avg.toFixed(config.decimals);
        }
        
        // Mettre à jour écart-type
        const stddevEl = document.getElementById(`${sensorKey}-stddev`);
        if (stddevEl && stat.stddev !== undefined) {
            stddevEl.textContent = stat.stddev.toFixed(2);
        }
    }
    
    /**
     * Met à jour les dates de synthèse
     * 
     * @param {number} startTimestamp - Timestamp de début (Unix en secondes)
     * @param {number} endTimestamp - Timestamp de fin (Unix en secondes)
     */
    updateSummaryDates(startTimestamp, endTimestamp) {
        // Mettre à jour les dates dans le titre
        const summaryStartEl = document.getElementById('summary-start-date');
        const summaryEndEl = document.getElementById('summary-end-date');
        
        if (summaryStartEl) {
            summaryStartEl.textContent = this.formatDateTime(startTimestamp);
        }
        
        if (summaryEndEl) {
            summaryEndEl.textContent = this.formatDateTime(endTimestamp);
        }
        
        // Mettre à jour les dates dans la période
        const periodStartEl = document.getElementById('period-start-date');
        const periodEndEl = document.getElementById('period-end-date');
        
        if (periodStartEl) {
            periodStartEl.textContent = this.formatDateTime(startTimestamp, false);
        }
        
        if (periodEndEl) {
            periodEndEl.textContent = this.formatDateTime(endTimestamp, false);
        }
        
        // Calculer et mettre à jour la durée
        const durationSeconds = endTimestamp - startTimestamp;
        this.updateDuration(durationSeconds);
        
        this.log(`Summary dates updated: ${this.formatDateTime(startTimestamp)} to ${this.formatDateTime(endTimestamp)}`);
    }
    
    /**
     * Met à jour la durée d'analyse
     * 
     * @param {number} durationSeconds - Durée en secondes
     */
    updateDuration(durationSeconds) {
        const durationEl = document.getElementById('period-duration');
        if (!durationEl) return;
        
        const duration = this.formatDuration(durationSeconds);
        durationEl.textContent = duration;
    }
    
    /**
     * Formate un timestamp en date/heure lisible
     * Utilise moment-timezone pour afficher en Europe/Paris
     * 
     * @param {number} timestamp - Timestamp Unix en secondes
     * @param {boolean} withSeconds - Inclure les secondes (défaut: true)
     * @returns {string} Date formatée
     */
    formatDateTime(timestamp, withSeconds = true) {
        // Vérifier que moment et moment-timezone sont disponibles
        if (typeof moment === 'undefined') {
            this.log('moment.js not loaded, using fallback', 'warn');
            // Fallback simple si moment n'est pas disponible
            const date = new Date(timestamp * 1000);
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');
            const seconds = String(date.getSeconds()).padStart(2, '0');
            
            if (withSeconds) {
                return `${day}/${month}/${year} ${hours}:${minutes}:${seconds}`;
            } else {
                return `${day}/${month}/${year} ${hours}:${minutes}`;
            }
        }
        
        // Les timestamps sont stockés en base avec timezone Europe/Paris (voir ChartDataService.php)
        // On les affiche en Africa/Casablanca car c'est l'heure locale réelle du projet physique au Maroc
        // Note: Décalage horaire variable selon saison (0h en hiver, -1h en été depuis Paris)
        const m = moment.unix(timestamp).tz('Africa/Casablanca');
        
        if (withSeconds) {
            return m.format('DD/MM/YYYY HH:mm:ss');
        } else {
            return m.format('DD/MM/YYYY HH:mm');
        }
    }
    
    /**
     * Formate une durée en secondes en chaîne lisible
     * 
     * @param {number} seconds - Durée en secondes
     * @returns {string} Durée formatée
     */
    formatDuration(seconds) {
        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        
        if (days > 0) {
            return `${days}j ${hours}h`;
        } else if (hours > 0) {
            return `${hours}h ${minutes}min`;
        } else {
            return `${minutes}min`;
        }
    }
    
    /**
     * Incrémente le compteur d'enregistrements
     */
    incrementReadingCount() {
        this.readingCount++;
        this.liveReadingCount++;
        
        // Mettre à jour l'affichage des compteurs
        const countElement = document.getElementById('period-measure-count');
        if (countElement) {
            countElement.textContent = this.initialReadingCount;
        }
        
        const liveCountElement = document.getElementById('live-readings-count');
        if (liveCountElement) {
            liveCountElement.textContent = this.liveReadingCount;
        }
    }
    
    /**
     * Met à jour les informations de période avec une nouvelle lecture
     * 
     * @param {number} timestamp - Timestamp de la nouvelle lecture (Unix en secondes)
     */
    updatePeriodInfo(timestamp) {
        // Initialiser les timestamps si c'est la première lecture
        if (this.startTimestamp === null) {
            this.startTimestamp = timestamp;
            this.initialStartTimestamp = timestamp;
        }
        
        if (this.endTimestamp === null) {
            this.endTimestamp = timestamp;
            this.initialEndTimestamp = timestamp;
        }
        
        // Détecter le passage en mode live (nouvelle donnée reçue après l'initialisation)
        if (timestamp > this.initialEndTimestamp) {
            this.isLiveMode = true;
            this.updateModeBadge('live');
        }
        
        // Mettre à jour endTimestamp (toujours la plus récente)
        if (timestamp > this.endTimestamp) {
            this.endTimestamp = timestamp;
            
            // Fenêtre glissante : ajuster le startTimestamp pour maintenir la durée fixe
            if (this.slidingWindowEnabled && this.isLiveMode) {
                this.startTimestamp = this.endTimestamp - this.slidingWindowDuration;
                this.log(`Sliding window updated: ${this.formatDuration(this.slidingWindowDuration)}`);
            }
            
            // Mettre à jour les dates affichées
            if (this.startTimestamp && this.endTimestamp) {
                this.updateSummaryDates(this.startTimestamp, this.endTimestamp);
            }
        }
        
        // Incrémenter le compteur
        this.incrementReadingCount();
    }
    
    /**
     * Met à jour le badge MODE (LIVE / HISTORIQUE)
     * 
     * @param {string} mode - 'live' ou 'historical'
     */
    updateModeBadge(mode) {
        const badgeElement = document.getElementById('period-mode-badge');
        if (!badgeElement) return;
        
        if (mode === 'live') {
            badgeElement.className = 'mode-badge mode-badge-live';
            badgeElement.innerHTML = '<i class="fas fa-circle"></i> LIVE';
        } else {
            badgeElement.className = 'mode-badge mode-badge-historical';
            badgeElement.innerHTML = '<i class="fas fa-history"></i> HISTORIQUE';
        }
    }
    
    /**
     * Met à jour toutes les barres de progression
     */
    updateProgressBars() {
        this.stats.forEach((stat, sensorName) => {
            if (stat.last > 0) {
                this.updateStatCard(sensorName, stat.last);
            }
        });
    }
    
    /**
     * Réinitialise toutes les statistiques
     */
    resetStats() {
        this.stats.forEach((stat) => {
            stat.min = Infinity;
            stat.max = -Infinity;
            stat.sum = 0;
            stat.count = 0;
            stat.avg = 0;
        });
        this.readingCount = 0;
        this.log('Stats reset');
    }
    
    /**
     * Obtient les statistiques actuelles
     * 
     * @returns {Object} Statistiques
     */
    getStats() {
        const result = {
            readingCount: this.readingCount,
            sensors: {}
        };
        
        this.stats.forEach((stat, sensorName) => {
            if (stat.count > 0) {
                result.sensors[sensorName] = {
                    last: stat.last,
                    min: stat.min === Infinity ? null : stat.min,
                    max: stat.max === -Infinity ? null : stat.max,
                    avg: stat.avg,
                    count: stat.count
                };
            }
        });
        
        return result;
    }
    
    /**
     * Affiche un résumé des statistiques dans la console
     */
    logStats() {
        const stats = this.getStats();
        console.table(stats.sensors);
        this.log(`Total readings: ${stats.readingCount}`);
    }
    
    /**
     * Log avec préfixe
     */
    log(message, level = 'info') {
        const prefix = '[StatsUpdater]';
        if (level === 'error') {
            console.error(`${prefix} ${message}`);
        } else if (level === 'warn') {
            console.warn(`${prefix} ${message}`);
        } else {
            console.log(`${prefix} ${message}`);
        }
    }
}

// Export global pour utilisation dans les templates
window.StatsUpdater = StatsUpdater;

