/**
 * ChartUpdaterGeneric – mise à jour temps réel des graphiques Highcharts
 *
 * Supporte deux modes de mapping capteur -> graphique :
 *   - Par ID de container (chartId): { chartId: "chart-temperatures", seriesIndex: 0 }
 *   - Par index numérique (chartIndex): { chartIndex: 0, seriesIndex: 2 }
 *   - Support scatterOnlyIfTrue pour filtrer les valeurs <= 0 (actionneurs FFP3)
 *
 * Compatible avec tous les modules : MSP1, N3PP, FFP3.
 */
class ChartUpdaterGeneric {
    constructor(options) {
        this.maxPoints = options.maxPoints || 10000;
        this.autoScroll = options.autoScroll !== false;
        this.batchUpdateDelay = options.batchUpdateDelay || 100;

        this.sensorMap = options.sensorMap || options.sensorToSeriesMap || {};
        this.chartIds = options.chartIds || [];
        this.chartContainerIds = options.chartContainerIds || [];
        this.charts = {};
        this.chartsByIndex = new Map();
        this.updateQueue = [];
        this.batchTimer = null;
        this.isInitialized = false;
    }

    init() {
        if (typeof Highcharts === 'undefined') {
            console.warn('[ChartUpdaterGeneric] Highcharts non disponible');
            return false;
        }

        var self = this;

        // Mode chartId (MSP1, N3PP)
        this.chartIds.forEach(function (id) {
            var chart = Highcharts.charts.find(function (c) {
                return c && c.renderTo && c.renderTo.id === id;
            });
            if (chart) {
                self.charts[id] = chart;
            } else {
                console.warn('[ChartUpdaterGeneric] Graphique non trouvé : ' + id);
            }
        });

        // Mode chartIndex (FFP3)
        this.chartContainerIds.forEach(function (id, index) {
            var chart = Highcharts.charts.find(function (c) {
                return c && c.renderTo && c.renderTo.id === id;
            });
            if (chart) {
                self.chartsByIndex.set(index, chart);
            } else {
                console.warn('[ChartUpdaterGeneric] Graphique non trouvé : ' + id);
            }
        });

        var count = Object.keys(this.charts).length + this.chartsByIndex.size;
        this.isInitialized = count > 0;
        console.log('[ChartUpdaterGeneric] Init : ' + count + ' graphique(s)');
        return this.isInitialized;
    }

    addNewReading(timestamp, sensors) {
        if (!this.isInitialized) return;

        var timestampMs = timestamp < 10000000000 ? timestamp * 1000 : timestamp;

        for (var sensorName in sensors) {
            if (!sensors.hasOwnProperty(sensorName)) continue;
            var mapping = this.sensorMap[sensorName];
            var value = sensors[sensorName];
            if (value === null || value === undefined || !mapping) continue;

            var numericValue = parseFloat(value);

            if (mapping.scatterOnlyIfTrue && numericValue <= 0) {
                continue;
            }

            if (mapping.chartId) {
                this.updateQueue.push({
                    chartId: mapping.chartId,
                    seriesIndex: mapping.seriesIndex,
                    timestamp: timestampMs,
                    value: numericValue
                });
            } else if (mapping.chartIndex !== undefined) {
                this.updateQueue.push({
                    chartIndex: mapping.chartIndex,
                    seriesIndex: mapping.seriesIndex,
                    timestamp: timestampMs,
                    value: numericValue
                });
            }
        }
        this.scheduleBatchUpdate();
    }

    addNewReadings(readings) {
        if (!Array.isArray(readings) || readings.length === 0) return;
        var self = this;
        readings.forEach(function (r) { self.addNewReading(r.timestamp, r.sensors); });
        if (readings.length > 10) this.flushBatchUpdate();
    }

    scheduleBatchUpdate() {
        if (this.batchTimer) return;
        var self = this;
        this.batchTimer = setTimeout(function () { self.flushBatchUpdate(); }, this.batchUpdateDelay);
    }

    flushBatchUpdate() {
        if (this.batchTimer) { clearTimeout(this.batchTimer); this.batchTimer = null; }
        if (this.updateQueue.length === 0) return;

        var updates = this.updateQueue.splice(0);

        // Grouper par chartId
        var byChartId = {};
        // Grouper par chartIndex
        var byChartIndex = {};

        updates.forEach(function (u) {
            if (u.chartId) {
                if (!byChartId[u.chartId]) byChartId[u.chartId] = [];
                byChartId[u.chartId].push(u);
            } else if (u.chartIndex !== undefined) {
                if (!byChartIndex[u.chartIndex]) byChartIndex[u.chartIndex] = [];
                byChartIndex[u.chartIndex].push(u);
            }
        });

        var self = this;
        Object.keys(byChartId).forEach(function (chartId) {
            self.applyUpdatesToChart(self.charts[chartId], byChartId[chartId]);
        });
        Object.keys(byChartIndex).forEach(function (idx) {
            self.applyUpdatesToChart(self.chartsByIndex.get(parseInt(idx, 10)), byChartIndex[idx]);
        });
    }

    applyUpdatesToChart(chart, updates) {
        if (!chart) return;

        var bySeries = {};
        updates.forEach(function (u) {
            if (!bySeries[u.seriesIndex]) bySeries[u.seriesIndex] = [];
            bySeries[u.seriesIndex].push(u);
        });

        var self = this;
        Object.keys(bySeries).forEach(function (si) {
            var series = chart.series[parseInt(si, 10)];
            if (!series) return;

            bySeries[si].forEach(function (u) {
                var existing = series.data.find(function (p) {
                    return p && typeof p.x !== 'undefined' && p.x === u.timestamp;
                });
                if (existing) {
                    existing.update(u.value, false);
                } else {
                    var shift = series.data.length >= self.maxPoints;
                    series.addPoint([u.timestamp, u.value], false, shift, false);
                }
            });
        });

        chart.redraw();

        if (this.autoScroll) {
            this.scrollToLatest(chart);
        }
    }

    scrollToLatest(chart) {
        if (!chart || !chart.xAxis || !chart.xAxis[0]) return;
        var maxTs = 0;
        chart.series.forEach(function (s) {
            if (s.data.length > 0) {
                var last = s.data[s.data.length - 1];
                if (last && last.x > maxTs) maxTs = last.x;
            }
        });
        if (maxTs === 0) return;
        var ext = chart.xAxis[0].getExtremes();
        if (maxTs > ext.max) {
            var range = ext.max - ext.min;
            chart.xAxis[0].setExtremes(maxTs - range, maxTs, true, false);
        }
    }

    enableAutoScroll(enabled) {
        this.autoScroll = enabled;
    }

    setMaxPoints(maxPoints) {
        this.maxPoints = maxPoints;
    }

    resetAllCharts() {
        var redraw = function (chart) {
            chart.series.forEach(function (s) { s.setData([], false); });
            chart.redraw();
        };
        Object.values(this.charts).forEach(redraw);
        this.chartsByIndex.forEach(redraw);
    }

    getStats() {
        var stats = {
            initialized: this.isInitialized,
            chartsCount: Object.keys(this.charts).length + this.chartsByIndex.size,
            queueSize: this.updateQueue.length,
            autoScroll: this.autoScroll,
            maxPoints: this.maxPoints
        };
        return stats;
    }
}

window.ChartUpdaterGeneric = ChartUpdaterGeneric;

// Alias pour compatibilité FFP3 (l'ancien ChartUpdater)
window.ChartUpdater = ChartUpdaterGeneric;
