/**
 * ChartUpdaterGeneric - Mise à jour temps réel des graphiques Highcharts pour MSP1 et N3PP.
 * Utilise les IDs de conteneurs (chart-temperatures, chart-lights, etc.) et une map capteur -> chartId + seriesIndex.
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
            if (!mapping || mapping.chartId == null || mapping.seriesIndex == null) return;
            var val = sensors[sensorName];
            if (val === null || val === undefined) return;
            var num = parseFloat(val);
            if (isNaN(num)) return;
            self.updateQueue.push({
                chartId: mapping.chartId,
                seriesIndex: mapping.seriesIndex,
                timestamp: timestampMs,
                value: num
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
            byChart[chartId].forEach(function (u) {
                var series = chart.series[u.seriesIndex];
                if (!series || !series.data) return;
                var existingPoint = null;
                if (Array.isArray(series.data)) {
                    for (var i = 0; i < series.data.length; i++) {
                        var p = series.data[i];
                        if (p && p.x === u.timestamp) {
                            existingPoint = p;
                            break;
                        }
                    }
                }
                if (existingPoint) {
                    existingPoint.update(u.value, false);
                } else {
                    self.insertPointSorted(series, u.timestamp, u.value);
                }
            });
            chart.redraw(false);
            if (self.autoScroll) {
                self.scrollToLatest(chart);
            }
        });
    };

    ChartUpdaterGeneric.prototype.insertPointSorted = function (series, timestamp, value) {
        if (!series || !Array.isArray(series.xData)) return;

        var xData = series.xData;
        var lastX = xData.length > 0 ? xData[xData.length - 1] : null;
        if (lastX === null || timestamp > lastX) {
            var shift = series.data.length >= this.maxPoints;
            series.addPoint([timestamp, value], false, shift, false);
            return;
        }
        if (timestamp === lastX && series.data.length > 0) {
            series.data[series.data.length - 1].update(value, false);
            return;
        }

        var mergedData = [];
        var inserted = false;
        for (var i = 0; i < xData.length; i++) {
            var x = xData[i];
            var y = series.yData[i];
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
    };

    ChartUpdaterGeneric.prototype.scrollToLatest = function (chart) {
        if (!chart || !chart.xAxis || !chart.xAxis[0]) return;
        var maxTs = 0;
        chart.series.forEach(function (s) {
            if (s.data && s.data.length > 0) {
                var last = s.data[s.data.length - 1];
                if (last && last.x != null) maxTs = Math.max(maxTs, last.x);
            }
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
