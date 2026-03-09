/**
 * ChartUpdaterGeneric — Mise à jour dynamique des graphiques Highcharts en temps réel.
 * Version configurable : le mapping capteurs/séries est passé en options.
 *
 * Usage :
 *   var updater = new ChartUpdaterGeneric({
 *       chartIds: ['chart-temperatures', 'chart-lights'],
 *       sensorMap: {
 *           'TempAirInt': { chartId: 'chart-temperatures', seriesIndex: 0 },
 *           'TempAirExt': { chartId: 'chart-temperatures', seriesIndex: 1 },
 *           'LuminositeMoy': { chartId: 'chart-lights', seriesIndex: 0 }
 *       },
 *       maxPoints: 10000,
 *       debug: false
 *   });
 *   updater.init();
 */
class ChartUpdaterGeneric {
    constructor(options) {
        this.chartIds = options.chartIds || [];
        this.sensorMap = options.sensorMap || {};
        this.maxPoints = options.maxPoints || 10000;
        this.autoScroll = options.autoScroll !== false;
        this.batchUpdateDelay = options.batchUpdateDelay || 100;
        this.debug = options.debug || false;

        this.charts = {};
        this.isInitialized = false;
        this.updateQueue = [];
        this.batchTimer = null;
    }

    init() {
        if (typeof Highcharts === 'undefined') {
            this.log('Highcharts not available', 'error');
            return false;
        }
        var self = this;
        this.chartIds.forEach(function(id) {
            var chart = Highcharts.charts.find(function(c) { return c && c.renderTo && c.renderTo.id === id; });
            if (chart) {
                self.charts[id] = chart;
                self.log('Chart registered: ' + id);
            } else {
                self.log('Chart not found: ' + id, 'warn');
            }
        });
        this.isInitialized = Object.keys(this.charts).length > 0;
        this.log('Initialized with ' + Object.keys(this.charts).length + ' chart(s)');
        return this.isInitialized;
    }

    addNewReading(timestamp, sensors) {
        if (!this.isInitialized) return;
        var tsMs = timestamp < 1e10 ? timestamp * 1000 : timestamp;
        var self = this;
        Object.keys(sensors).forEach(function(name) {
            var mapping = self.sensorMap[name];
            if (!mapping || sensors[name] === null || sensors[name] === undefined) return;
            var val = parseFloat(sensors[name]);
            if (isNaN(val)) return;
            self.updateQueue.push({ sensor: name, timestamp: tsMs, value: val });
        });
        this.scheduleBatchUpdate();
    }

    addNewReadings(readings) {
        if (!Array.isArray(readings) || readings.length === 0) return;
        var self = this;
        readings.forEach(function(r) { self.addNewReading(r.timestamp, r.sensors); });
        if (readings.length > 10) this.flushBatchUpdate();
    }

    scheduleBatchUpdate() {
        if (this.batchTimer) return;
        var self = this;
        this.batchTimer = setTimeout(function() { self.flushBatchUpdate(); }, this.batchUpdateDelay);
    }

    flushBatchUpdate() {
        if (this.batchTimer) { clearTimeout(this.batchTimer); this.batchTimer = null; }
        if (this.updateQueue.length === 0) return;
        var queue = this.updateQueue.splice(0);
        var byChart = {};
        var self = this;

        queue.forEach(function(u) {
            var m = self.sensorMap[u.sensor];
            if (!m) return;
            var cid = m.chartId;
            if (!byChart[cid]) byChart[cid] = [];
            byChart[cid].push({ seriesIndex: m.seriesIndex, timestamp: u.timestamp, value: u.value });
        });

        Object.keys(byChart).forEach(function(cid) {
            self.applyUpdates(cid, byChart[cid]);
        });
        this.log('Batch: ' + queue.length + ' point(s)');
    }

    applyUpdates(chartId, updates) {
        var chart = this.charts[chartId];
        if (!chart) return;
        var bySeries = {};
        updates.forEach(function(u) {
            if (!bySeries[u.seriesIndex]) bySeries[u.seriesIndex] = [];
            bySeries[u.seriesIndex].push(u);
        });
        var self = this;
        Object.keys(bySeries).forEach(function(si) {
            var series = chart.series[parseInt(si, 10)];
            if (!series) return;
            bySeries[si].forEach(function(u) {
                var exists = series.data.find(function(p) { return p && p.x === u.timestamp; });
                if (exists) {
                    exists.update(u.value, false);
                } else {
                    var shift = series.data.length >= self.maxPoints;
                    series.addPoint([u.timestamp, u.value], false, shift, false);
                }
            });
        });
        chart.redraw();
        if (this.autoScroll) this.scrollToLatest(chart);
    }

    scrollToLatest(chart) {
        if (!chart || !chart.xAxis || !chart.xAxis[0]) return;
        var maxTs = 0;
        chart.series.forEach(function(s) {
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

    log(msg, level) {
        if (!this.debug && level !== 'error') return;
        var prefix = '[ChartUpdaterGeneric]';
        if (level === 'error') console.error(prefix + ' ' + msg);
        else if (level === 'warn') console.warn(prefix + ' ' + msg);
        else console.log(prefix + ' ' + msg);
    }
}

window.ChartUpdaterGeneric = ChartUpdaterGeneric;
