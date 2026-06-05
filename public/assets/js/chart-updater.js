/**
 * ChartUpdater - Mise à jour dynamique des graphiques Highcharts en temps réel
 * 
 * Gère l'ajout de nouveaux points aux graphiques sans recharger la page.
 * Supporte l'auto-scroll, la limitation du nombre de points, et les mises à jour batch.
 * 
 * @version 1.1.0
 * @date 2026-06-05
 */

class ChartUpdater {
    constructor(options = {}) {
        this.maxPoints = options.maxPoints || 10000;
        this.autoScroll = options.autoScroll !== false;
        this.animationsEnabled = options.animationsEnabled !== false;
        this.batchUpdateDelay = options.batchUpdateDelay || 100;
        this.tideThresholdCm = options.tideThresholdCm
            || (window.n3TideMarkers ? window.n3TideMarkers.DEFAULT_THRESHOLD_CM : 2.0);

        this.charts = new Map();

        this.chartContainerIds = options.chartContainerIds || ['chart-stock-area-eau-D', 'chart-stock-area-temp-D'];

        var defaultSensorMap = {
            'EauAquarium': { chartIndex: 0, seriesName: 'Eau aquarium' },
            'EauReserve': { chartIndex: 0, seriesName: 'Eau réserve' },
            'EauPotager': { chartIndex: 0, seriesName: 'Eau potager' },
            'etatPompeAqua': { chartIndex: 0, seriesName: 'Pompe aquarium ON', scatterOnlyIfTrue: true },
            'etatPompeTank': { chartIndex: 0, seriesName: 'Pompe réserve ON', scatterOnlyIfTrue: true },
            'etatHeat': { chartIndex: 0, seriesName: 'Chauffage ON', scatterOnlyIfTrue: true },
            'TempEau': { chartIndex: 1, seriesName: 'Température eau' },
            'TempAir': { chartIndex: 1, seriesName: 'Température air' },
            'Humidite': { chartIndex: 1, seriesName: 'Humidité' },
            'Luminosite': { chartIndex: 1, seriesName: 'Luminosité' },
            'etatUV': { chartIndex: 1, seriesName: 'LEDs' },
            'bouffeGros': { chartIndex: 1, seriesName: 'Nourriture gros' },
            'bouffePetits': { chartIndex: 1, seriesName: 'Nourriture petits' }
        };
        this.sensorToSeriesMap = options.sensorToSeriesMap || defaultSensorMap;

        this.isInitialized = false;
        this.updateQueue = [];
        this.batchTimer = null;
        this.aquaBuffer = [];
        this.lastTidePeakTs = null;
        this.lastTideTroughTs = null;

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
        
        const self = this;
        this.chartContainerIds.forEach(function(id, index) {
            const chart = Highcharts.charts.find(c => c && c.renderTo && c.renderTo.id === id);
            if (chart) {
                self.charts.set(index, chart);
                self.log(id + ' chart found and registered');
            } else {
                self.log(id + ' chart not found!', 'warn');
            }
        });
        
        this.isInitialized = this.charts.size > 0;
        this.log(`Initialized with ${this.charts.size} chart(s)`);

        if (this.isInitialized) {
            this.seedAquaBufferFromChart();
        }

        return this.isInitialized;
    }

    seedAquaBufferFromChart() {
        const chart = this.charts.get(0);
        if (!chart) return;
        const series = this.findSeriesByName(chart, 'Eau aquarium');
        if (!series || !Array.isArray(series.xData)) return;
        this.aquaBuffer = [];
        for (let i = 0; i < series.xData.length; i++) {
            const y = series.yData[i];
            if (y !== null && y !== undefined && !isNaN(y)) {
                this.aquaBuffer.push([series.xData[i], y]);
            }
        }
        if (window.n3TideMarkers && this.aquaBuffer.length >= 3) {
            const extrema = window.n3TideMarkers.detectClientExtremum(this.aquaBuffer, this.tideThresholdCm);
            if (extrema.peak) this.lastTidePeakTs = extrema.peak[0];
            if (extrema.trough) this.lastTideTroughTs = extrema.trough[0];
        }
    }

    findSeriesByName(chart, seriesName) {
        if (!chart || !chart.series) return null;
        for (let i = 0; i < chart.series.length; i++) {
            if (chart.series[i] && chart.series[i].name === seriesName) {
                return chart.series[i];
            }
        }
        return null;
    }

    resolveSeriesIndex(chart, seriesName) {
        if (!chart || !chart.series) return -1;
        for (let i = 0; i < chart.series.length; i++) {
            if (chart.series[i] && chart.series[i].name === seriesName) {
                return i;
            }
        }
        return -1;
    }

    maybeAddTideMarkers() {
        if (!window.n3TideMarkers || this.aquaBuffer.length < 3) return;
        const chart = this.charts.get(0);
        if (!chart) return;

        const extrema = window.n3TideMarkers.detectClientExtremum(this.aquaBuffer, this.tideThresholdCm);

        if (extrema.peak && (this.lastTidePeakTs === null || extrema.peak[0] > this.lastTidePeakTs)) {
            this.addTideMarkerPoint(chart, window.n3TideMarkers.PEAK_SERIES_NAME, extrema.peak);
            this.lastTidePeakTs = extrema.peak[0];
        }
        if (extrema.trough && (this.lastTideTroughTs === null || extrema.trough[0] > this.lastTideTroughTs)) {
            this.addTideMarkerPoint(chart, window.n3TideMarkers.TROUGH_SERIES_NAME, extrema.trough);
            this.lastTideTroughTs = extrema.trough[0];
        }
    }

    addTideMarkerPoint(chart, seriesName, point) {
        const series = this.findSeriesByName(chart, seriesName);
        if (!series) return;
        const existing = series.data.find(p => p && p.x === point[0]);
        if (existing) {
            existing.update(point[1], false);
        } else {
            this.insertPointSorted(series, point[0], point[1]);
        }
        chart.redraw();
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

                if (sensorName === 'EauAquarium') {
                    this.aquaBuffer.push([timestampMs, numericValue]);
                    if (this.aquaBuffer.length > this.maxPoints) {
                        this.aquaBuffer.shift();
                    }
                }
            }
        }

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
                seriesName: mapping.seriesName,
                timestamp: update.timestamp,
                value: update.value,
                sensor: update.sensor
            });
        });

        updatesByChart.forEach((updates, chartIndex) => {
            this.updateChartSeries(chartIndex, updates);
        });

        if (updatesToProcess.some(u => u.sensor === 'EauAquarium')) {
            this.maybeAddTideMarkers();
        }

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
        
        const updatesBySeries = new Map();
        updates.forEach(update => {
            const seriesIndex = this.resolveSeriesIndex(chart, update.seriesName);
            if (seriesIndex < 0) {
                this.log(`Series "${update.seriesName}" not found in chart ${chartIndex}`, 'warn');
                return;
            }
            if (!updatesBySeries.has(seriesIndex)) {
                updatesBySeries.set(seriesIndex, []);
            }
            updatesBySeries.get(seriesIndex).push(update);
        });

        const touchedSeriesIndices = new Set();
        updatesBySeries.forEach((seriesUpdates, seriesIndex) => {
            const series = chart.series[seriesIndex];
            if (!series || !series.data) {
                this.log(`Series ${seriesIndex} not found in chart ${chartIndex}`, 'warn');
                return;
            }

            touchedSeriesIndices.add(seriesIndex);
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
                    // Conserver un ordre strictement croissant des X pour Highcharts Stock (#15).
                    this.insertPointSorted(series, update.timestamp, update.value);
                }
            });
        });

        // Filet de sécurité : si xData interne n'est plus strictement croissant, retrier (#15).
        touchedSeriesIndices.forEach((seriesIndex) => {
            const s = chart.series[seriesIndex];
            if (s) {
                this.normalizeSeriesXOrder(s);
            }
        });
        
        // Redraw une seule fois
        chart.redraw();
        
        // Auto-scroll si activé
        if (this.autoScroll) {
            this.scrollToLatest(chart);
        }
    }

    /**
     * Insère un point dans une série en conservant l'ordre croissant des timestamps.
     * Évite Highcharts warning #15 lorsque des points arrivent hors ordre.
     *
     * @param {Object} series - Série Highcharts
     * @param {number} timestamp - Timestamp Unix en millisecondes
     * @param {number} value - Valeur associée
     */
    insertPointSorted(series, timestamp, value) {
        if (!series) {
            return;
        }
        if (!Array.isArray(series.xData)) {
            const shift = series.data && series.data.length >= this.maxPoints;
            series.addPoint([timestamp, value], false, shift, false);
            return;
        }

        const xData = series.xData;
        const lastX = xData.length > 0 ? xData[xData.length - 1] : null;

        // Cas nominal: nouveau point plus récent, append rapide.
        if (lastX === null || timestamp > lastX) {
            const shift = series.data.length >= this.maxPoints;
            series.addPoint([timestamp, value], false, shift, false);
            return;
        }

        // Cas particulier: même timestamp que le dernier point.
        if (timestamp === lastX && series.data.length > 0) {
            series.data[series.data.length - 1].update(value, false);
            return;
        }

        // Point hors ordre: fusion + tri pour conserver la contrainte Highcharts.
        const mergedData = [];
        let inserted = false;
        const len = xData.length;

        for (let i = 0; i < len; i++) {
            const x = xData[i];
            const y = series.yData[i];

            if (!inserted && timestamp < x) {
                mergedData.push([timestamp, value]);
                inserted = true;
            } else if (!inserted && timestamp === x) {
                mergedData.push([timestamp, value]);
                inserted = true;
                continue;
            }

            mergedData.push([x, y]);
        }

        if (!inserted) {
            mergedData.push([timestamp, value]);
        }

        if (mergedData.length > this.maxPoints) {
            mergedData.splice(0, mergedData.length - this.maxPoints);
        }

        series.setData(mergedData, false, false, false);
    }

    /**
     * Garantit des abscisses non décroissantes (requis Highcharts Stock, warning #15).
     * @param {Object} series - Série Highcharts
     */
    normalizeSeriesXOrder(series) {
        if (!series || !Array.isArray(series.xData) || series.xData.length < 2) {
            return;
        }
        const xData = series.xData;
        const yData = series.yData;
        if (!Array.isArray(yData) || yData.length !== xData.length) {
            return;
        }
        for (let i = 1; i < xData.length; i++) {
            if (xData[i] < xData[i - 1]) {
                const pairs = [];
                for (let j = 0; j < xData.length; j++) {
                    pairs.push([xData[j], yData[j]]);
                }
                pairs.sort((a, b) => a[0] - b[0]);
                const deduped = [];
                for (let k = 0; k < pairs.length; k++) {
                    const px = pairs[k][0];
                    const py = pairs[k][1];
                    if (deduped.length > 0 && deduped[deduped.length - 1][0] === px) {
                        deduped[deduped.length - 1][1] = py;
                    } else {
                        deduped.push([px, py]);
                    }
                }
                if (deduped.length > this.maxPoints) {
                    deduped.splice(0, deduped.length - this.maxPoints);
                }
                series.setData(deduped, false, false, false);
                return;
            }
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

