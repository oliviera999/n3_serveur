/**
 * Mise en page Highcharts — pages aquaponie (vue paysage + alt).
 * Compose une base commune n3StockChartLayout puis applique les spécificités aquaponie.
 */
(function () {
    'use strict';

    function viewportWidth() {
        return window.innerWidth || document.documentElement.clientWidth || 1024;
    }

    function isMobileLayout() {
        return viewportWidth() <= 736;
    }

    /**
     * @param {'eau'|'temp'} kind
     * @returns {number}
     */
    function chartHeight(kind) {
        var w = viewportWidth();
        if (kind === 'temp') {
            if (w <= 420) return 340;
            if (w <= 736) return 380;
            return 440;
        }
        if (w <= 420) return 300;
        if (w <= 736) return 340;
        return 420;
    }

    /**
     * @param {'eau'|'temp'} kind — eau : plus d’entrées de légende
     * @returns {{ chart: Object, legend: Object, navigator: Object, scrollbar: Object }}
     */
    function baseOptions(kind) {
        var m = isMobileLayout();
        var sharedLayout = window.n3StockChartLayout;
        var shared = sharedLayout && typeof sharedLayout.baseOptionsMain === 'function'
            ? sharedLayout.baseOptionsMain()
            : null;
        var maxLeg = kind === 'eau' ? (m ? 80 : 96) : (m ? 52 : 60);
        return {
            chart: {
                backgroundColor: 'transparent',
                spacingTop: shared && shared.chart ? shared.chart.spacingTop : (m ? 8 : 12),
                spacingRight: shared && shared.chart ? shared.chart.spacingRight : (m ? 8 : 12),
                spacingBottom: shared && shared.chart ? shared.chart.spacingBottom : (m ? 8 : 14),
                spacingLeft: shared && shared.chart ? shared.chart.spacingLeft : (m ? 8 : 12)
            },
            legend: {
                enabled: true,
                align: 'center',
                verticalAlign: 'bottom',
                layout: 'horizontal',
                maxHeight: maxLeg,
                itemStyle: { fontSize: m ? '10px' : '12px', cursor: 'pointer' },
                itemMarginTop: m ? 2 : 4,
                itemMarginBottom: m ? 2 : 4,
                symbolHeight: m ? 8 : 10,
                symbolWidth: m ? 8 : 10,
                accessibility: { enabled: false },
                navigation: { activeColor: '#3E6F7A', inactiveColor: '#CCC' }
            },
            navigator: {
                enabled: true,
                height: m ? 20 : 28,
                margin: shared && shared.navigator ? shared.navigator.margin : (m ? 4 : 8),
                xAxis: { ordinal: false }
            },
            scrollbar: {
                enabled: true,
                height: shared && shared.scrollbar ? shared.scrollbar.height : (m ? 6 : 10)
            }
        };
    }

    /**
     * Vue alt : hauteur alignée sur la colonne stats (desktop) sinon hauteur standard.
     * @param {number} rowIndex
     * @param {'eau'|'temp'} kind
     */
    function statsHeight(rowIndex, kind) {
        var rows = document.querySelectorAll('.alt-data-chart-row');
        if (viewportWidth() > 768 && rows[rowIndex]) {
            var sc = rows[rowIndex].querySelector('.alt-stats-column');
            if (sc && sc.offsetHeight > 200) {
                return sc.offsetHeight;
            }
        }
        return chartHeight(kind);
    }

    window.n3AquaponieCharts = {
        viewportWidth: viewportWidth,
        isMobileLayout: isMobileLayout,
        chartHeight: chartHeight,
        baseOptions: baseOptions,
        statsHeight: statsHeight
    };
})();
