/**
 * n3-layout-charts.js — Création des graphiques Highcharts Stock des pages de suivi
 * pilotées par catalogue (MSP1, N3PP, ENERGIE, FFP3) à partir d'un payload JSON :
 *   { readingTime: [ms…], columns: { Col: [v…] }, charts: [ { id, kind, yAxis, tooltip,
 *     series: [ { id, name, column, trendOf, trendRound, type, color, fill, lineWidth,
 *                 dashStyle, yAxis, tooltip, opacity, step, onMarkers, visible } ] } ] }
 *
 * Les séries arrivent déjà filtrées et ordonnées par le preset d'affichage : leur
 * index est celui de la sensor map générée côté serveur (mise à jour live).
 *
 * - `step` : ligne en escalier (états 0/1).
 * - `onMarkers` { lane, symbol, radius } : un marqueur par mesure > 0, posé à la
 *   hauteur fixe `lane` (axe d'événements masqué 0–1, ou index de ligne d'un
 *   chronogramme) — reprend les marqueurs « Pompe ON / Chauffage ON » de l'ancienne
 *   page aquaponie au lieu de tracer des lignes 0/1 sur l'axe des mesures.
 * - `payload.stale` { colonne: [index] } : points « réchauffés » au réveil, omis du tracé.
 * - Tendances (`trendOf`) : régression sur le temps, recalculée sur la plage zoomée.
 * - `onReady(fn)` : rappel une fois les graphiques créés (création différée par
 *   n3StockChartBootstrap.ensureContainersReady) ; reçoit { chartId: chart }.
 *
 * Dépend de chart-helpers.js (zipSeries, n3AreaGradientFill) et n3-stock-chart-bootstrap.js.
 */
(function () {
    'use strict';

    var chartsById = null;
    var readyCallbacks = [];

    /**
     * Droite de régression linéaire (moindres carrés) sur les points non nuls,
     * en fonction du TEMPS (et non de l'index) : les relevés arrivent par rafales
     * (ex. 10 mesures en 5 min puis 30 min de veille), une régression sur l'index
     * donnait une pente faussée.
     * @returns {Array<[number, number]>|null}
     */
    function linearTrend(data, roundToInt) {
        if (!Array.isArray(data) || data.length < 2) return null;
        var t0 = data[0][0];
        var n = 0, sumX = 0, sumY = 0, sumXY = 0, sumXX = 0;
        for (var i = 0; i < data.length; i++) {
            var y = data[i][1];
            if (y === null || y === undefined || isNaN(y)) continue;
            var x = (data[i][0] - t0) / 3600000;
            n++; sumX += x; sumY += y; sumXY += x * y; sumXX += x * x;
        }
        if (n < 2) return null;
        var denom = n * sumXX - sumX * sumX;
        if (denom === 0) return null;
        var a = (n * sumXY - sumX * sumY) / denom;
        var b = (sumY - a * sumX) / n;
        var out = [];
        for (var j = 0; j < data.length; j++) {
            var yTrend = a * ((data[j][0] - t0) / 3600000) + b;
            out.push([data[j][0], roundToInt ? Math.round(yTrend) : yTrend]);
        }
        return out;
    }

    /**
     * Moyenne mobile centrée sur une fenêtre de `windowMin` minutes (en temps, pas en
     * nombre de points : les relevés arrivent par rafales). Points nuls ignorés.
     * @returns {Array<[number, number|null]>}
     */
    function movingAverage(data, windowMin) {
        if (!Array.isArray(data) || data.length === 0) return [];
        var half = (windowMin || 30) * 30000;
        var out = [];
        var lo = 0, hi = 0, sum = 0, count = 0;
        for (var i = 0; i < data.length; i++) {
            var t = data[i][0];
            while (hi < data.length && data[hi][0] <= t + half) {
                var vh = data[hi][1];
                if (vh !== null && vh !== undefined && !isNaN(vh)) { sum += vh; count++; }
                hi++;
            }
            while (lo < hi && data[lo][0] < t - half) {
                var vl = data[lo][1];
                if (vl !== null && vl !== undefined && !isNaN(vl)) { sum -= vl; count--; }
                lo++;
            }
            out.push([t, count > 0 ? sum / count : null]);
        }
        return out;
    }

    /**
     * Données [t, v] d'une colonne, sans les points « réchauffés » au réveil
     * (payload.stale[colonne], cf. ChartDataService::staleWakeIndexes) : la courbe
     * relie directement l'avant et l'après veille au lieu de dessiner un escalier.
     */
    function columnData(payload, column) {
        var data = zipSeries(payload.readingTime || [], payload.columns[column] || []);
        var skip = payload.stale && payload.stale[column];
        if (!Array.isArray(skip) || skip.length === 0) return data;
        var drop = {};
        skip.forEach(function (i) { drop[i] = true; });
        return data.filter(function (_, i) { return !drop[i]; });
    }

    /**
     * Points « ON » : un point [t, lane] par mesure dont la valeur est > 0.
     * @returns {Array<[number, number]>}
     */
    function onMarkerPoints(data, lane) {
        var out = [];
        for (var i = 0; i < data.length; i++) {
            var v = data[i][1];
            if (v !== null && v !== undefined && !isNaN(v) && v > 0) {
                out.push([data[i][0], lane]);
            }
        }
        return out;
    }

    function buildSeries(payload, s) {
        var data = s.onMarkers
            ? zipSeries(payload.readingTime || [], payload.columns[s.column] || [])
            : columnData(payload, s.column || s.trendOf);
        if (s.trendOf) {
            data = s.trendKind === 'moving_avg'
                ? movingAverage(data, s.trendWindow)
                : (linearTrend(data, s.trendRound) || []);
        }
        var out = {
            name: s.name,
            data: data,
            type: s.type || 'spline',
            color: s.color,
            lineWidth: s.lineWidth != null ? s.lineWidth : 2,
            yAxis: s.yAxis || 0,
            visible: s.visible !== false
        };
        if (s.id) out.id = s.id;
        if (Array.isArray(s.fill)) out.fillColor = n3AreaGradientFill.apply(null, s.fill);
        if (s.dashStyle) out.dashStyle = s.dashStyle;
        if (s.tooltip) out.tooltip = s.tooltip;
        if (s.opacity != null) out.opacity = s.opacity;
        if (s.step) out.step = s.step;
        if (s.onMarkers) {
            out.type = 'scatter';
            out.data = onMarkerPoints(data, s.onMarkers.lane || 0);
            out.lineWidth = 0;
            out.marker = { enabled: true, symbol: s.onMarkers.symbol || 'circle', radius: s.onMarkers.radius || 4 };
            out.showInNavigator = false;
            out.dataGrouping = { enabled: false };
            out.tooltip = Object.assign({}, s.tooltip || {}, {
                pointFormat: '<span style="color:{point.color}">●</span> {series.name} : <b>ON</b><br/>'
            });
        }
        return out;
    }

    /**
     * Séries de tendance d'un graphique : index dans le graphique + données source.
     * @returns {Array<{index: number, round: boolean, data: Array}>}
     */
    function trendSources(payload, chartDef) {
        var out = [];
        chartDef.series.forEach(function (s, index) {
            if (s.trendOf && s.trendKind !== 'moving_avg') {
                out.push({ index: index, round: !!s.trendRound, data: columnData(payload, s.trendOf) });
            }
        });
        return out;
    }

    /** Recalcule les tendances sur la plage visible après un zoom / déplacement. */
    function refreshTrends(chart, trends, e) {
        if (!chart || !e || e.trigger === undefined || e.trigger === null) return;
        var min = e.min, max = e.max;
        trends.forEach(function (t) {
            var series = chart.series[t.index];
            if (!series) return;
            var visible = t.data.filter(function (p) { return p[0] >= min && p[0] <= max; });
            series.setData(linearTrend(visible, t.round) || [], false, false, false);
        });
        chart.redraw(false);
    }

    function onReady(fn) {
        if (typeof fn !== 'function') return;
        if (chartsById) {
            fn(chartsById);
        } else {
            readyCallbacks.push(fn);
        }
    }

    function render(payload) {
        var bootstrap = window.n3StockChartBootstrap;
        if (!bootstrap || !payload || !Array.isArray(payload.charts) || payload.charts.length === 0) {
            return;
        }
        var ids = payload.charts.map(function (c) { return c.id; });
        bootstrap.ensureContainersReady(ids, function () {
            var charts = [];
            var sizes = [];
            var byId = {};
            payload.charts.forEach(function (c) {
                var cfg = {
                    // showEmpty:false : un axe dont toutes les séries sont masquées par le preset disparaît.
                    yAxis: (c.yAxis || []).map(function (axis) { return Object.assign({ showEmpty: false }, axis); }),
                    series: c.series.map(function (s) { return buildSeries(payload, s); })
                };
                var trends = trendSources(payload, c);
                if (trends.length > 0) {
                    // Tendances recalculées sur la plage zoomée (comme l'ancienne page aquaponie).
                    cfg.xAxis = { ordinal: false, events: { afterSetExtremes: function (e) { refreshTrends(this.chart, trends, e); } } };
                }
                if (c.tooltip) cfg.tooltip = c.tooltip;
                var chart = bootstrap.createStockChart(c.id, c.kind || 'main', cfg);
                charts.push(chart);
                sizes.push({ chart: chart, type: c.kind || 'main' });
                byId[c.id] = chart;
            });
            bootstrap.bindResize(sizes);
            bootstrap.bindAosReflow(charts);
            // Le ChartUpdaterGeneric (live) est instancié avant la création asynchrone des graphiques.
            if (window.chartUpdater && !window.chartUpdater.isInitialized && typeof window.chartUpdater.init === 'function') {
                window.chartUpdater.init();
            }
            chartsById = byId;
            readyCallbacks.splice(0).forEach(function (fn) {
                try {
                    fn(byId);
                } catch (e) {
                    console.error('[n3LayoutCharts] rappel onReady en erreur', e);
                }
            });
            try {
                document.dispatchEvent(new CustomEvent('n3:layout-charts-ready', { detail: { charts: byId } }));
            } catch (e) {
                // CustomEvent indisponible (navigateur très ancien) : onReady suffit.
            }
        });
    }

    window.n3LayoutCharts = { render: render, onReady: onReady, linearTrend: linearTrend, movingAverage: movingAverage, onMarkerPoints: onMarkerPoints, columnData: columnData };
})();
