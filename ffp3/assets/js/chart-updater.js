/**
 * ChartUpdater - Mise à jour dynamique des graphiques Highcharts en temps réel
 * 
 * Gère l'ajout de nouveaux points aux graphiques sans recharger la page.
 * Supporte l'auto-scroll, la limitation du nombre de points, et les mises à jour batch.
 * 
 * @version 1.0.0
 * @date 2025-10-12
 */

class ChartUpdater {
    constructor(options = {}) {
        // Configuration
        this.maxPoints = options.maxPoints || 10000;
        this.autoScroll = options.autoScroll !== false;
        this.animationsEnabled = options.animationsEnabled !== false;
        this.batchUpdateDelay = options.batchUpdateDelay || 100; // ms
        
        // Références aux graphiques Highcharts
        this.charts = new Map();
        
        // Mapping des capteurs vers les séries des graphiques
        this.sensorToSeriesMap = {
            // Graphique Eau (index 0) - Séries areaspline (courbes)
            'EauAquarium': { chartIndex: 0, seriesIndex: 2 },  // Orange
            'EauReserve': { chartIndex: 0, seriesIndex: 0 },   // Vert foncé
            'EauPotager': { chartIndex: 0, seriesIndex: 1 },   // Vert clair
            
            // Graphique Eau (index 0) - Séries scatter (points) - seulement si valeur > 0
            'etatPompeAqua': { chartIndex: 0, seriesIndex: 3, scatterOnlyIfTrue: true },
            'etatPompeTank': { chartIndex: 0, seriesIndex: 4, scatterOnlyIfTrue: true },
            'etatHeat': { chartIndex: 0, seriesIndex: 5, scatterOnlyIfTrue: true },
            
            // Graphique Paramètres physiques (index 1) - Séries areaspline (courbes)
            'TempEau': { chartIndex: 1, seriesIndex: 0 },
            'TempAir': { chartIndex: 1, seriesIndex: 1 },
            'Humidite': { chartIndex: 1, seriesIndex: 2 },
            'Luminosite': { chartIndex: 1, seriesIndex: 3 },
            
            // Graphique Paramètres physiques (index 1) - Séries column (barres)
            'etatUV': { chartIndex: 1, seriesIndex: 4 },
            'bouffeGros': { chartIndex: 1, seriesIndex: 5 },
            'bouffePetits': { chartIndex: 1, seriesIndex: 6 }
        };
        
        // État
        this.isInitialized = false;
        this.updateQueue = [];
        this.batchTimer = null;
        
        this.log('ChartUpdater initialized');
    }
    
    /**
     * Initialise les références aux graphiques Highcharts
     * Doit être appelé après la création des graphiques
     */
    init() {
        // Récupérer les graphiques depuis Highcharts.charts
        if (typeof Highcharts === 'undefined') {
            this.log('Highcharts not found!', 'error');
            return false;
        }
        
        // Trouver nos graphiques par leur conteneur
        const eauChart = Highcharts.charts.find(c => c && c.renderTo.id === 'chart-stock-area-eau-D');
        const tempChart = Highcharts.charts.find(c => c && c.renderTo.id === 'chart-stock-area-temp-D');
        
        if (eauChart) {
            this.charts.set(0, eauChart);
            this.log('Eau chart found and registered');
        } else {
            this.log('Eau chart not found!', 'warn');
        }
        
        if (tempChart) {
            this.charts.set(1, tempChart);
            this.log('Temp chart found and registered');
        } else {
            this.log('Temp chart not found!', 'warn');
        }
        
        this.isInitialized = this.charts.size > 0;
        this.log(`Initialized with ${this.charts.size} chart(s)`);
        
        return this.isInitialized;
    }
    
    /**
     * Ajoute une nouvelle lecture de capteur
     * 
     * @param {number} timestamp - Timestamp Unix en millisecondes
     * @param {Object} sensors - Objet contenant les valeurs des capteurs
     */
    addNewReading(timestamp, sensors) {
        if (!this.isInitialized) {
            this.log('Not initialized, skipping update', 'warn');
            return;
        }
        
        // Convertir timestamp en millisecondes si nécessaire
        const timestampMs = timestamp < 10000000000 ? timestamp * 1000 : timestamp;
        
        // Ajouter chaque capteur à la file d'attente
        for (const [sensorName, value] of Object.entries(sensors)) {
            const mapping = this.sensorToSeriesMap[sensorName];
            if (value !== null && value !== undefined && mapping) {
                const numericValue = parseFloat(value);
                
                // Pour les séries scatter, n'ajouter que si la valeur > 0
                if (mapping.scatterOnlyIfTrue && numericValue <= 0) {
                    continue;
                }
                
                this.updateQueue.push({
                    sensor: sensorName,
                    timestamp: timestampMs,
                    value: numericValue
                });
            }
        }
        
        // Planifier la mise à jour batch
        this.scheduleBatchUpdate();
    }
    
    /**
     * Ajoute plusieurs lectures (utilisé lors du rattrapage)
     * 
     * @param {Array} readings - Tableau de lectures [{timestamp, sensors}]
     */
    addNewReadings(readings) {
        if (!Array.isArray(readings) || readings.length === 0) {
            return;
        }
        
        this.log(`Adding ${readings.length} new reading(s)`);
        
        // Désactiver les animations si beaucoup de points
        const shouldAnimate = readings.length <= 10;
        
        readings.forEach(reading => {
            this.addNewReading(reading.timestamp, reading.sensors);
        });
        
        // Forcer la mise à jour immédiate si beaucoup de données
        if (readings.length > 10) {
            this.flushBatchUpdate();
        }
    }
    
    /**
     * Planifie une mise à jour batch
     */
    scheduleBatchUpdate() {
        if (this.batchTimer) {
            return; // Déjà planifié
        }
        
        this.batchTimer = setTimeout(() => {
            this.flushBatchUpdate();
        }, this.batchUpdateDelay);
    }
    
    /**
     * Exécute la mise à jour batch de tous les points en attente
     */
    flushBatchUpdate() {
        if (this.batchTimer) {
            clearTimeout(this.batchTimer);
            this.batchTimer = null;
        }
        
        if (this.updateQueue.length === 0) {
            return;
        }
        
        const updatesToProcess = [...this.updateQueue];
        this.updateQueue = [];
        
        // Grouper les mises à jour par graphique
        const updatesByChart = new Map();
        
        updatesToProcess.forEach(update => {
            const mapping = this.sensorToSeriesMap[update.sensor];
            if (!mapping) return;
            
            if (!updatesByChart.has(mapping.chartIndex)) {
                updatesByChart.set(mapping.chartIndex, []);
            }
            
            updatesByChart.get(mapping.chartIndex).push({
                seriesIndex: mapping.seriesIndex,
                timestamp: update.timestamp,
                value: update.value,
                sensor: update.sensor
            });
        });
        
        // Appliquer les mises à jour graphique par graphique
        updatesByChart.forEach((updates, chartIndex) => {
            this.updateChartSeries(chartIndex, updates);
        });
        
        this.log(`Batch update completed: ${updatesToProcess.length} point(s) added`);
    }
    
    /**
     * Met à jour les séries d'un graphique
     * 
     * @param {number} chartIndex - Index du graphique
     * @param {Array} updates - Mises à jour à appliquer
     */
    updateChartSeries(chartIndex, updates) {
        const chart = this.charts.get(chartIndex);
        if (!chart) {
            this.log(`Chart ${chartIndex} not found`, 'warn');
            return;
        }
        
        // Désactiver le redraw temporairement
        const shouldRedraw = false;
        
        // Grouper par série
        const updatesBySeries = new Map();
        updates.forEach(update => {
            if (!updatesBySeries.has(update.seriesIndex)) {
                updatesBySeries.set(update.seriesIndex, []);
            }
            updatesBySeries.get(update.seriesIndex).push(update);
        });
        
        // Appliquer les points série par série
        updatesBySeries.forEach((seriesUpdates, seriesIndex) => {
            const series = chart.series[seriesIndex];
            if (!series || !series.data) {
                this.log(`Series ${seriesIndex} not found in chart ${chartIndex}`, 'warn');
                return;
            }
            
            seriesUpdates.forEach(update => {
                // Vérifier les données de mise à jour
                if (!update || update.timestamp === undefined || update.value === undefined) {
                    this.log(`Invalid update data received`, 'warn');
                    return;
                }
                
                // Vérifier si le point existe déjà (éviter les doublons)
                // Filter out null/undefined points before searching
                const existingPoint = series.data.find(p => p && typeof p.x !== 'undefined' && p.x === update.timestamp);
                if (existingPoint) {
                    // Mettre à jour le point existant
                    existingPoint.update(update.value, false);
                } else {
                    // Ajouter nouveau point
                    const shift = series.data.length >= this.maxPoints;
                    series.addPoint([update.timestamp, update.value], false, shift, false);
                }
            });
        });
        
        // Redraw une seule fois
        chart.redraw();
        
        // Auto-scroll si activé
        if (this.autoScroll) {
            this.scrollToLatest(chart);
        }
    }
    
    /**
     * Scroll le graphique pour afficher les dernières données
     * 
     * @param {Object} chart - Instance Highcharts
     */
    scrollToLatest(chart) {
        if (!chart || !chart.xAxis || !chart.xAxis[0]) {
            return;
        }
        
        // Obtenir le dernier timestamp de toutes les séries
        let maxTimestamp = 0;
        chart.series.forEach(series => {
            if (series.data.length > 0) {
                const lastPoint = series.data[series.data.length - 1];
                maxTimestamp = Math.max(maxTimestamp, lastPoint.x);
            }
        });
        
        if (maxTimestamp === 0) {
            return;
        }
        
        const xAxis = chart.xAxis[0];
        const currentExtremes = xAxis.getExtremes();
        
        // Si le dernier point est hors de vue, ajuster
        if (maxTimestamp > currentExtremes.max) {
            const range = currentExtremes.max - currentExtremes.min;
            xAxis.setExtremes(maxTimestamp - range, maxTimestamp, true, false);
        }
    }
    
    /**
     * Active/désactive l'auto-scroll
     * 
     * @param {boolean} enabled - Activer ou non
     */
    enableAutoScroll(enabled) {
        this.autoScroll = enabled;
        this.log(`Auto-scroll ${enabled ? 'enabled' : 'disabled'}`);
    }
    
    /**
     * Définit le nombre maximum de points par série
     * 
     * @param {number} maxPoints - Nombre maximum de points
     */
    setMaxPoints(maxPoints) {
        this.maxPoints = maxPoints;
        this.log(`Max points set to ${maxPoints}`);
    }
    
    /**
     * Réinitialise tous les graphiques (supprime toutes les données)
     */
    resetAllCharts() {
        this.charts.forEach(chart => {
            chart.series.forEach(series => {
                series.setData([], false);
            });
            chart.redraw();
        });
        this.log('All charts reset');
    }
    
    /**
     * Obtient des statistiques sur l'état actuel
     * 
     * @returns {Object} Statistiques
     */
    getStats() {
        const stats = {
            initialized: this.isInitialized,
            chartsCount: this.charts.size,
            queueSize: this.updateQueue.length,
            autoScroll: this.autoScroll,
            maxPoints: this.maxPoints,
            chartStats: []
        };
        
        this.charts.forEach((chart, index) => {
            const seriesStats = chart.series.map(s => ({
                name: s.name,
                pointsCount: s.data.length
            }));
            stats.chartStats.push({
                index: index,
                series: seriesStats
            });
        });
        
        return stats;
    }
    
    /**
     * Log avec préfixe
     */
    log(message, level = 'info') {
        const prefix = '[ChartUpdater]';
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
window.ChartUpdater = ChartUpdater;

