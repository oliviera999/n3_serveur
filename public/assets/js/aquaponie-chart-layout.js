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

    function isMobileUA() {
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
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
     * @param {'eau'|'temp'} kind — eau : plus d'entrées de légende
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

    /**
     * Force minHeight sur les conteneurs avant création Highcharts (tous viewports).
     * @param {HTMLElement} containerEau
     * @param {HTMLElement} containerTemp
     * @param {function(): number} heightEauFn
     * @param {function(): number} heightTempFn
     */
    function prepareContainers(containerEau, containerTemp, heightEauFn, heightTempFn) {
        if (!containerEau || !containerTemp) return;
        containerEau.style.minHeight = heightEauFn() + 'px';
        containerTemp.style.minHeight = heightTempFn() + 'px';
        if (isMobileUA()) {
            var fallbackWidth = Math.max(280, viewportWidth() - 40);
            if (containerEau.offsetWidth < 100) {
                containerEau.style.width = fallbackWidth + 'px';
                containerTemp.style.width = fallbackWidth + 'px';
                containerEau.offsetHeight;
            }
        }
    }

    /**
     * Planifie l'initialisation des graphiques aquaponie (attente HC, layout, load, resize).
     * @param {function(): void} createFn — ex. createVersionD
     * @param {{ prepare?: function(HTMLElement, HTMLElement): void, containerIds?: string[] }} [options]
     */
    function scheduleInit(createFn, options) {
        options = options || {};
        var containerIds = options.containerIds || ['chart-stock-area-eau-D', 'chart-stock-area-temp-D'];
        var maxInitAttempts = options.maxInitAttempts || 20;
        var initAttempts = 0;
        var loadHooked = false;
        var resizeObserver = null;

        function getContainers() {
            return {
                eau: document.getElementById(containerIds[0]),
                temp: document.getElementById(containerIds[1])
            };
        }

        function chartsReady() {
            return !!(window.chartEau && window.chartTemp);
        }

        function runCreate() {
            var c = getContainers();
            if (!c.eau || !c.temp) return;
            if (typeof options.prepare === 'function') {
                options.prepare(c.eau, c.temp);
            }
            try {
                createFn();
            } catch (err) {
                console.error('[Highcharts] Erreur lors de la création des graphiques:', err);
            }
        }

        function initCharts() {
            initAttempts++;
            var retryDelay = isMobileUA() ? 200 : 100;

            if (typeof Highcharts === 'undefined' || typeof Highcharts.stockChart === 'undefined') {
                if (initAttempts < maxInitAttempts) {
                    console.warn('[Highcharts] Bibliothèque non chargée, tentative ' + initAttempts + '/' + maxInitAttempts);
                    setTimeout(initCharts, retryDelay);
                } else {
                    console.error('[Highcharts] Échec du chargement après ' + maxInitAttempts + ' tentatives');
                }
                return;
            }

            var c = getContainers();
            if (!c.eau || !c.temp) {
                if (initAttempts < maxInitAttempts) {
                    console.warn('[Highcharts] Conteneurs non trouvés, tentative ' + initAttempts + '/' + maxInitAttempts);
                    setTimeout(initCharts, retryDelay);
                } else {
                    console.error('[Highcharts] Conteneurs introuvables après ' + maxInitAttempts + ' tentatives');
                }
                return;
            }

            runCreate();
            hookLoadAndResize();
        }

        function hookLoadAndResize() {
            if (loadHooked) return;
            loadHooked = true;

            window.addEventListener('load', function () {
                if (!chartsReady()) {
                    runCreate();
                }
            });

            if (typeof ResizeObserver === 'undefined') return;
            var c = getContainers();
            if (!c.eau) return;
            resizeObserver = new ResizeObserver(function () {
                if (chartsReady()) return;
                var width = c.eau.getBoundingClientRect().width || c.eau.offsetWidth || 0;
                if (width >= 1) {
                    runCreate();
                }
            });
            resizeObserver.observe(c.eau);
        }

        var initialDelay = isMobileUA() ? 600 : 100;
        function kickoff() {
            setTimeout(initCharts, initialDelay);
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', kickoff);
        } else {
            kickoff();
        }
    }

    window.n3AquaponieCharts = {
        viewportWidth: viewportWidth,
        isMobileLayout: isMobileLayout,
        isMobileUA: isMobileUA,
        chartHeight: chartHeight,
        baseOptions: baseOptions,
        statsHeight: statsHeight,
        prepareContainers: prepareContainers,
        scheduleInit: scheduleInit
    };
})();
