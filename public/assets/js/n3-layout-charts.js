/**
 * n3-layout-charts.js — Création des graphiques Highcharts Stock des pages de suivi
 * pilotées par catalogue (MSP1, N3PP, ENERGIE) à partir d'un payload JSON :
 *   { readingTime: [ms…], columns: { Col: [v…] }, charts: [ { id, kind, yAxis, tooltip,
 *     series: [ { name, column, trendOf, trendRound, type, color, fill, lineWidth,
 *                 dashStyle, yAxis, tooltip, opacity, visible } ] } ] }
 *
 * Les séries arrivent déjà filtrées et ordonnées par le preset d'affichage : leur
 * index est celui de la sensor map générée côté serveur (mise à jour live).
 * Dépend de chart-helpers.js (zipSeries, n3AreaGradientFill) et n3-stock-chart-bootstrap.js.
 */
(function () {
    'use strict';

    /**
     * Droite de régression linéaire (moindres carrés) sur les points non nuls.
     * @returns {Array<[number, number]>|null}
     */
    function linearTrend(data, roundToInt) {
        if (!Array.isArray(data) || data.length < 2) return null;
        var n = 0, sumX = 0, sumY = 0, sumXY = 0, sumXX = 0;
        for (var i = 0; i < data.length; i++) {
            var y = data[i][1];
            if (y === null || y === undefined || isNaN(y)) continue;
            n++; sumX += i; sumY += y; sumXY += i * y; sumXX += i * i;
        }
        if (n < 2) return null;
        var denom = n * sumXX - sumX * sumX;
        if (denom === 0) return null;
        var a = (n * sumXY - sumX * sumY) / denom;
        var b = (sumY - a * sumX) / n;
        var out = [];
        for (var j = 0; j < data.length; j++) {
            var yTrend = a * j + b;
            out.push([data[j][0], roundToInt ? Math.round(yTrend) : yTrend]);
        }
        return out;
    }

    function buildSeries(payload, s) {
        var values = payload.columns[s.column || s.trendOf] || [];
        var data = zipSeries(payload.readingTime || [], values);
        if (s.trendOf) {
            data = linearTrend(data, s.trendRound) || [];
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
        if (Array.isArray(s.fill)) out.fillColor = n3AreaGradientFill.apply(null, s.fill);
        if (s.dashStyle) out.dashStyle = s.dashStyle;
        if (s.tooltip) out.tooltip = s.tooltip;
        if (s.opacity != null) out.opacity = s.opacity;
        return out;
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
            payload.charts.forEach(function (c) {
                var cfg = {
                    // showEmpty:false : un axe dont toutes les séries sont masquées par le preset disparaît.
                    yAxis: (c.yAxis || []).map(function (axis) { return Object.assign({ showEmpty: false }, axis); }),
                    series: c.series.map(function (s) { return buildSeries(payload, s); })
                };
                if (c.tooltip) cfg.tooltip = c.tooltip;
                var chart = bootstrap.createStockChart(c.id, c.kind || 'main', cfg);
                charts.push(chart);
                sizes.push({ chart: chart, type: c.kind || 'main' });
            });
            bootstrap.bindResize(sizes);
            bootstrap.bindAosReflow(charts);
            // Le ChartUpdaterGeneric (live) est instancié avant la création asynchrone des graphiques.
            if (window.chartUpdater && !window.chartUpdater.isInitialized && typeof window.chartUpdater.init === 'function') {
                window.chartUpdater.init();
            }
        });
    }

    window.n3LayoutCharts = { render: render, linearTrend: linearTrend };
})();
