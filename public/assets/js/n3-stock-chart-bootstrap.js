/**
 * Initialisation partagée des graphiques Highcharts Stock (MSP1/N3PP).
 * - attend des conteneurs avec largeur exploitable
 * - applique les options de layout communes
 * - bind resize/orientation + reflow sur animation AOS
 */
(function () {
    'use strict';

    function isMobileDevice() {
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent || '');
    }

    function getLayout() {
        return window.n3StockChartLayout || null;
    }

    function getChartHeight(type) {
        var layout = getLayout();
        if (layout && typeof layout.heightForType === 'function') {
            return layout.heightForType(type);
        }
        var width = window.innerWidth || document.documentElement.clientWidth || 1024;
        if (type === 'cycles') {
            if (width <= 420) return 270;
            if (width <= 736) return 300;
            return 380;
        }
        if (width <= 420) return 300;
        if (width <= 736) return 340;
        return 420;
    }

    function getChartBaseOptions(type) {
        var layout = getLayout();
        if (layout && typeof layout.baseOptions === 'function') {
            return layout.baseOptions(type);
        }
        return { chart: { backgroundColor: 'transparent', height: getChartHeight(type) } };
    }

    function getChartLoadExtremes() {
        var layout = getLayout();
        if (layout && typeof layout.chartLoadExtremes === 'function') {
            return layout.chartLoadExtremes;
        }
        return function () {
            var xAxis = this.xAxis && this.xAxis[0];
            if (xAxis && xAxis.dataMin != null && xAxis.dataMax != null) {
                xAxis.setExtremes(xAxis.dataMin, xAxis.dataMax);
            }
        };
    }

    function ensureContainersReady(ids, callback, options) {
        var opts = options || {};
        var attempts = 0;
        var maxAttempts = opts.maxAttempts || 20;
        var minWidth = opts.minWidth || (isMobileDevice() ? 100 : 20);
        var retryDelay = opts.retryDelay || (isMobileDevice() ? 200 : 120);
        var initialDelay = opts.initialDelay || (isMobileDevice() ? 500 : 120);

        function areReady() {
            var allReady = true;
            for (var i = 0; i < ids.length; i++) {
                var el = document.getElementById(ids[i]);
                if (!el) {
                    allReady = false;
                    break;
                }
                var rect = el.getBoundingClientRect();
                if ((rect.width || 0) < minWidth) {
                    allReady = false;
                    break;
                }
            }
            return allReady;
        }

        function tryInit() {
            attempts++;
            if (typeof Highcharts === 'undefined' || typeof Highcharts.stockChart === 'undefined') {
                if (attempts < maxAttempts) {
                    setTimeout(tryInit, retryDelay);
                } else {
                    console.warn('[n3StockChartBootstrap] Highcharts non disponible');
                }
                return;
            }
            if (!areReady()) {
                if (attempts < maxAttempts) {
                    setTimeout(tryInit, retryDelay);
                } else {
                    console.warn('[n3StockChartBootstrap] Conteneurs non prêts après ' + maxAttempts + ' tentatives');
                }
                return;
            }
            callback();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                setTimeout(tryInit, initialDelay);
            });
        } else {
            setTimeout(tryInit, initialDelay);
        }
    }

    function createStockChart(containerId, type, config) {
        var base = getChartBaseOptions(type || 'main');
        var chartCfg = Object.assign({}, base.chart, (config && config.chart) || {});
        if (!chartCfg.events) {
            chartCfg.events = {};
        }
        if (typeof chartCfg.events.load !== 'function') {
            chartCfg.events.load = getChartLoadExtremes();
        }
        var options = Object.assign(
            {
                rangeSelector: { enabled: false },
                title: { text: '' },
                xAxis: { ordinal: false },
                legend: base.legend,
                chart: chartCfg,
                navigator: base.navigator,
                scrollbar: base.scrollbar,
                credits: { enabled: false }
            },
            config || {}
        );
        return Highcharts.stockChart(containerId, options);
    }

    function bindResize(sizeMappings) {
        var layout = getLayout();
        if (layout && typeof layout.bindResize === 'function') {
            return layout.bindResize(sizeMappings, 220, 350);
        }
        var resizeTimer = null;
        function applyMappings() {
            for (var i = 0; i < sizeMappings.length; i++) {
                var item = sizeMappings[i];
                if (!item || !item.chart) continue;
                item.chart.setSize(null, getChartHeight(item.type || 'main'), false);
            }
        }
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(applyMappings, 220);
        });
        window.addEventListener('orientationchange', function () {
            setTimeout(applyMappings, 350);
        });
        return function () {
            if (resizeTimer) clearTimeout(resizeTimer);
        };
    }

    function bindAosReflow(charts) {
        if (!Array.isArray(charts) || charts.length === 0) return;
        function reflowAll() {
            for (var i = 0; i < charts.length; i++) {
                if (charts[i] && typeof charts[i].reflow === 'function') {
                    charts[i].reflow();
                }
            }
        }
        document.addEventListener('aos:in', function () {
            setTimeout(reflowAll, 30);
        });
    }

    window.n3StockChartBootstrap = {
        ensureContainersReady: ensureContainersReady,
        getChartHeight: getChartHeight,
        getChartBaseOptions: getChartBaseOptions,
        getChartLoadExtremes: getChartLoadExtremes,
        createStockChart: createStockChart,
        bindResize: bindResize,
        bindAosReflow: bindAosReflow
    };
})();
