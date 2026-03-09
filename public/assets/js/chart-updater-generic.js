/**
 * ChartUpdaterGeneric — Mise à jour dynamique des graphiques Highcharts en temps réel.
 * Version générique utilisable par tous les modules (FFP3, MSP1, N3PP).
 *
 * Usage :
 *   var updater = new ChartUpdaterGeneric({
 *       chartIds: ['chart-temperatures', 'chart-lights'],
 *       sensorMap: {
 *           'TempAirInt': { chartId: 'chart-temperatures', seriesIndex: 0 },
 *           'TempAirExt': { chartId: 'chart-temperatures', seriesIndex: 1 },
 *       },
 *       debug: false,
 *       maxPoints: 10000
 *   });
 *   updater.init();
 */
class ChartUpdaterGeneric {
    constructor(options) {
        this.chartIds = options.chartIds || [];
        this.sensorMap = options.sensorMap || {};
        this.maxPoints = options.maxPoints || 10000;
        this.autoScroll = options.autoScroll !== false;
        this.debug = !!options.debug;
        this.batchUpdateDelay = options.batchUpdateDelay || 100;

        this.charts = {};
        this.isInitialized = false;
        this.updateQueue = [];
        this.batchTimer = null;
    }

    init() {
        if (typeof Highcharts === 'undefined') {
            this.log('Highcharts not loaded', 'warn');
            return false;
        }
        var found = 0;
        for (var i = 0; i < this.chartIds.length; i++) {
            var id = this.chartIds[i];
            var chart = Highcharts.charts.find(function (c) {
                return c && c.renderTo && c.renderTo.id === id;
            });
            if (chart) {
                this.charts[id] = chart;
                found++;
            } else {
                this.log('Chart not found: ' + id, 'warn');
            }
        }
        this.isInitialized = found > 0;
        this.log('Initialized with ' + found + '/' + this.chartIds.length + ' chart(s)');
        return this.isInitialized;
    }

    addNewReading(timestamp, sensors) {
        if (!this.isInitialized) return;
        var ts = timestamp < 10000000000 ? timestamp * 1000 : timestamp;
        for (var key in sensors) {
            if (!sensors.hasOwnProperty(key)) continue;
            var mapping = this.sensorMap[key];
            if (!mapping) continue;
            var val = sensors[key];
            if (val === null || val === undefined) continue;
            this.updateQueue.push({ chartId: mapping.chartId, seriesIndex: mapping.seriesIndex, timestamp: ts, value: parseFloat(val) });
        }
        this.scheduleBatchUpdate();
    }

    addNewReadings(readings) {
        if (!Array.isArray(readings) || readings.length === 0) return;
        for (var i = 0; i < readings.length; i++) {
            this.addNewReading(readings[i].timestamp, readings[i].sensors);
        }
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

        var queue = this.updateQueue;
        this.updateQueue = [];

        var byChart = {};
        for (var i = 0; i < queue.length; i++) {
            var u = queue[i];
            if (!byChart[u.chartId]) byChart[u.chartId] = [];
            byChart[u.chartId].push(u);
        }

        for (var chartId in byChart) {
            if (!byChart.hasOwnProperty(chartId)) continue;
            var chart = this.charts[chartId];
            if (!chart) continue;
            var updates = byChart[chartId];
            for (var j = 0; j < updates.length; j++) {
                var up = updates[j];
                var series = chart.series[up.seriesIndex];
                if (!series) continue;
                var shift = series.data.length >= this.maxPoints;
                series.addPoint([up.timestamp, up.value], false, shift, false);
            }
            chart.redraw();
            if (this.autoScroll) this.scrollToLatest(chart);
        }
        this.log('Batch update: ' + queue.length + ' point(s)');
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
        var extremes = chart.xAxis[0].getExtremes();
        if (maxTs > extremes.max) {
            var range = extremes.max - extremes.min;
            chart.xAxis[0].setExtremes(maxTs - range, maxTs, true, false);
        }
    }

    enableAutoScroll(enabled) { this.autoScroll = enabled; }

    log(msg, level) {
        if (!this.debug && level !== 'warn' && level !== 'error') return;
        var prefix = '[ChartUpdaterGeneric]';
        if (level === 'error') console.error(prefix + ' ' + msg);
        else if (level === 'warn') console.warn(prefix + ' ' + msg);
        else console.log(prefix + ' ' + msg);
    }
}

window.ChartUpdaterGeneric = ChartUpdaterGeneric;
