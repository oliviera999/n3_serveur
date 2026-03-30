/**
 * Helpers de layout Highcharts Stock partagés (MSP1 / N3PP / aquaponie).
 * Centralise hauteurs, options de base et gestion resize/orientation.
 */
(function () {
    'use strict';

    function viewportWidth() {
        return window.innerWidth || document.documentElement.clientWidth || 1024;
    }

    function isMobile(breakpoint) {
        var bp = typeof breakpoint === 'number' ? breakpoint : 736;
        return viewportWidth() <= bp;
    }

    function heightForType(type) {
        var width = viewportWidth();
        if (type === 'cycles') {
            if (width <= 420) return 270;
            if (width <= 736) return 300;
            return 380;
        }
        if (width <= 420) return 300;
        if (width <= 736) return 340;
        return 420;
    }

    function baseOptions(type) {
        var mobile = isMobile();
        return {
            chart: {
                backgroundColor: 'transparent',
                height: heightForType(type),
                spacingTop: mobile ? 8 : 12,
                spacingRight: mobile ? 8 : 12,
                spacingBottom: mobile ? 8 : 14,
                spacingLeft: mobile ? 8 : 12
            },
            legend: {
                enabled: true,
                align: 'center',
                verticalAlign: 'bottom',
                itemStyle: {
                    cursor: 'pointer',
                    fontSize: mobile ? '10px' : '12px'
                },
                itemMarginTop: mobile ? 2 : 4,
                itemMarginBottom: mobile ? 2 : 4
            },
            navigator: {
                enabled: true,
                height: mobile ? 20 : 30,
                margin: mobile ? 4 : 8
            },
            scrollbar: {
                enabled: true,
                height: mobile ? 6 : 10
            }
        };
    }

    function baseOptionsMain() {
        return baseOptions('main');
    }

    function baseOptionsCycles() {
        return baseOptions('cycles');
    }

    function chartLoadExtremes() {
        var xAxis = this.xAxis && this.xAxis[0];
        if (xAxis && xAxis.dataMin != null && xAxis.dataMax != null) {
            xAxis.setExtremes(xAxis.dataMin, xAxis.dataMax);
        }
    }

    function applySizeMappings(sizeMappings) {
        if (!Array.isArray(sizeMappings)) return;
        for (var i = 0; i < sizeMappings.length; i++) {
            var item = sizeMappings[i];
            if (!item || !item.chart) continue;
            var type = item.type === 'cycles' ? 'cycles' : 'main';
            item.chart.setSize(null, heightForType(type), false);
        }
    }

    function bindResize(sizeMappings, debounceMs, orientationDelayMs) {
        var resizeDelay = typeof debounceMs === 'number' ? debounceMs : 220;
        var orientationDelay = typeof orientationDelayMs === 'number' ? orientationDelayMs : 350;
        var resizeTimer = null;

        function handleResize() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                applySizeMappings(sizeMappings);
            }, resizeDelay);
        }

        function handleOrientation() {
            setTimeout(function () {
                applySizeMappings(sizeMappings);
            }, orientationDelay);
        }

        window.addEventListener('resize', handleResize);
        window.addEventListener('orientationchange', handleOrientation);

        return function unbind() {
            if (resizeTimer) {
                clearTimeout(resizeTimer);
            }
            window.removeEventListener('resize', handleResize);
            window.removeEventListener('orientationchange', handleOrientation);
        };
    }

    window.n3StockChartLayout = {
        viewportWidth: viewportWidth,
        isMobile: isMobile,
        heightMain: function () { return heightForType('main'); },
        heightCycles: function () { return heightForType('cycles'); },
        heightForType: heightForType,
        baseOptions: baseOptions,
        baseOptionsMain: baseOptionsMain,
        baseOptionsCycles: baseOptionsCycles,
        chartLoadExtremes: chartLoadExtremes,
        applySizeMappings: applySizeMappings,
        bindResize: bindResize
    };
})();
