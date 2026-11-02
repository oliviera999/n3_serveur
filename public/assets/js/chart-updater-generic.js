/**
 * ChartUpdaterGeneric - Mise à jour temps réel des graphiques Highcharts pour MSP1 et N3PP.
 * Utilise les IDs de conteneurs (chart-temperatures, chart-lights, etc.) et une map capteur -> chartId + seriesIndex
 * (ou `targets: [{ chartId, seriesIndex, onLane? }]` quand le capteur alimente plusieurs séries).
 * Compatible avec realtime-updater.js : window.chartUpdater.addNewReadings(readings).
 */
(function () {
    'use strict';

    function ChartUpdaterGeneric(options) {
        options = options || {};
        this.chartIds = options.chartIds || [];
        this.sensorMap = options.sensorMap || {};
        this.maxPoints = options.maxPoints != null ? options.maxPoints : 10000;
        this.autoScroll = options.autoScroll !== false;
        this.chartsById = new Map();
        this.isInitialized = false;
        this.updateQueue = [];
        this.batchTimer = null;
        this.batchUpdateDelay = options.batchUpdateDelay || 100;
    }

    ChartUpdaterGeneric.prototype.init = function () {
        if (typeof Highcharts === 'undefined' || !Highcharts.charts) {
            console.warn('[ChartUpdaterGeneric] Highcharts non disponible');
            return false;
        }
        var self = this;
        this.chartIds.forEach(function (id) {
            var chart = Highcharts.charts.filter(function (c) {
                return c && c.renderTo && c.renderTo.id === id;
            })[0];
            if (chart) {
                self.chartsById.set(id, chart);
            }
        });
        this.isInitialized = this.chartsById.size > 0;
        return this.isInitialized;
    };

    ChartUpdaterGeneric.prototype.addNewReading = function (timestamp, sensors) {
        if (!this.isInitialized || !sensors) return;
        var timestampMs = timestamp < 10000000000 ? timestamp * 1000 : timestamp;
        var self = this;
        Object.keys(sensors).forEach(function (sensorName) {
            var mapping = self.sensorMap[sensorName];
            if (!mapping) return;
            var val = sensors[sensorName];
            if (val === null || val === undefined) return;
            var num = parseFloat(val);
            if (isNaN(num)) return;
            // `targets` : toutes les séries alimentées par ce capteur (format catalogue) ;
            // sinon cible unique { chartId, seriesIndex } (format historique).
            var targets = Array.isArray(mapping.targets) ? mapping.targets : [mapping];
            targets.forEach(function (t) {
                if (!t || t.chartId == null || t.seriesIndex == null) return;
                var value = num;
                if (t.onLane != null) {
                    // Série « marqueurs ON » : un point à hauteur fixe seulement si l'état est actif.
                    if (num <= 0) return;
                    value = t.onLane;
                }
                self.updateQueue.push({
                    chartId: t.chartId,
                    seriesIndex: t.seriesIndex,
                    timestamp: timestampMs,
                    value: value
                });
            });
        });
        this.scheduleBatchUpdate();
    };

    ChartUpdaterGeneric.prototype.addNewReadings = function (readings) {
        if (!Array.isArray(readings) || readings.length === 0) return;
        var self = this;
        readings.forEach(function (r) {
            var ts = r.timestamp;
            var sensors = r.sensors || r;
            if (ts != null) {
                self.addNewReading(ts, sensors);
            }
        });
        if (readings.length > 10) {
            this.flushBatchUpdate();
        }
    };

    ChartUpdaterGeneric.prototype.scheduleBatchUpdate = function () {
        var self = this;
        if (this.batchTimer) return;
        this.batchTimer = setTimeout(function () {
            self.flushBatchUpdate();
        }, this.batchUpdateDelay);
    };

    ChartUpdaterGeneric.prototype.flushBatchUpdate = function () {
        if (this.batchTimer) {
            clearTimeout(this.batchTimer);
            this.batchTimer = null;
        }
        if (this.updateQueue.length === 0) return;
        var queue = this.updateQueue.slice();
        this.updateQueue = [];
        var byChart = {};
        queue.forEach(function (u) {
            var id = u.chartId;
            if (!byChart[id]) byChart[id] = [];
            byChart[id].push(u);
        });
        var self = this;
        Object.keys(byChart).forEach(function (chartId) {
            var chart = self.chartsById.get(chartId);
            if (!chart || !chart.series) return;
            var changed = false;
            byChart[chartId].forEach(function (u) {
                var series = chart.series[u.seriesIndex];
                if (series && self.insertPointSorted(series, u.timestamp, u.value)) {
                    changed = true;
                }
            });
            if (!changed) return;
            chart.redraw(false);
            if (self.autoScroll) {
                self.scrollToLatest(chart);
            }
        });
    };

    /**
     * Colonne x / y d'une série. Highcharts 12 n'expose plus `series.xData` / `yData`
     * (données dans `series.dataTable`) : sans ce repli, aucun point live n'était ajouté.
     */
    function column(series, key) {
        if (series.dataTable && typeof series.dataTable.getColumn === 'function') {
            return series.dataTable.getColumn(key) || [];
        }
        return (key === 'x' ? series.xData : series.yData) || [];
    }

    /**
     * Ajoute (ou remplace) le point [timestamp, value] en gardant l'axe x croissant.
     * Retourne true si la série a changé. Un point identique déjà présent (cas de la
     * lecture « latest » relue au chargement) est ignoré : `point.update()` sur la série
     * de base du navigator déclenchait l'avertissement Highcharts #15.
     */
    ChartUpdaterGeneric.prototype.insertPointSorted = function (series, timestamp, value) {
        if (!series) return false;
        var xs = column(series, 'x');
        var ys = column(series, 'y');
        var n = xs.length;
        var lastX = n > 0 ? xs[n - 1] : null;

        if (lastX === null || timestamp > lastX) {
            series.addPoint([timestamp, value], false, n >= this.maxPoints, false);
            return true;
        }

        var merged = [];
        var replaced = false;
        var inserted = false;
        for (var i = 0; i < n; i++) {
            var x = xs[i];
            if (!inserted && x === timestamp) {
                if (ys[i] === value) return false;
                merged.push([timestamp, value]);
                inserted = true;
                replaced = true;
                continue;
            }
            if (!inserted && timestamp < x) {
                merged.push([timestamp, value]);
                inserted = true;
            }
            merged.push([x, ys[i]]);
        }
        if (!inserted) {
            merged.push([timestamp, value]);
        }
        if (!replaced && merged.length > this.maxPoints) {
            merged.splice(0, merged.length - this.maxPoints);
        }
        series.setData(merged, false, false, false);
        return true;
    };

    ChartUpdaterGeneric.prototype.scrollToLatest = function (chart) {
        if (!chart || !chart.xAxis || !chart.xAxis[0]) return;
        var maxTs = 0;
        chart.series.forEach(function (s) {
            if (s.options && s.options.isInternal) return;
            var xs = column(s, 'x');
            if (xs.length > 0 && xs[xs.length - 1] != null) maxTs = Math.max(maxTs, xs[xs.length - 1]);
        });
        if (maxTs === 0) return;
        var xAxis = chart.xAxis[0];
        var ext = xAxis.getExtremes();
        if (maxTs > ext.max) {
            var range = ext.max - ext.min;
            xAxis.setExtremes(maxTs - range, maxTs, true, false);
        }
    };

    ChartUpdaterGeneric.prototype.enableAutoScroll = function (enabled) {
        this.autoScroll = !!enabled;
    };

    window.ChartUpdaterGeneric = ChartUpdaterGeneric;
})();
