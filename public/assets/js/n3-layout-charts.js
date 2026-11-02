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

    window.n3LayoutCharts = { render: render, onReady: onReady, linearTrend: linearTrend, onMarkerPoints: onMarkerPoints };
})();
