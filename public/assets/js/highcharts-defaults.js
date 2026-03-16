/**
 * Highcharts – configuration globale partagée (FFP3, MSP1, N3PP).
 * Chargé avant la création des graphiques dans les templates.
 * Support dark mode : applique le thème selon data-theme sur html.
 */
(function () {
    if (typeof Highcharts === 'undefined') return;

    var baseOptions = {
        /* Projet physique à Casablanca : affichage cohérent avec FFP3 et TIMEZONE_MANAGEMENT.md */
        time: { useUTC: false, timezone: 'Africa/Casablanca' },
        plotOptions: {
            series: {
                visible: true,
                dataGrouping: { enabled: false }
            }
        },
        lang: {
            months: ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'],
            shortMonths: ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'],
            weekdays: ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'],
            decimalPoint: ',',
            thousandsSep: ' ',
            loading: 'Chargement...',
            noData: 'Aucune donnée disponible',
            resetZoom: 'Réinitialiser le zoom',
            rangeSelectorFrom: 'Du',
            rangeSelectorTo: 'Au'
        },
        chart: { backgroundColor: 'transparent' },
        credits: { enabled: false },
        /* Réduire la timeline (navigator) pour laisser plus de place au graphique principal */
        navigator: { height: 32 },
        scrollbar: { height: 10 },
        legend: {
            enabled: true,
            floating: false,
            /* Désactive l'accessibilité sur la légende uniquement pour restaurer les clics
             * show/hide des séries (bug connu : accessibility.js casse les itemClick).
             * Le reste du graphique conserve les fonctionnalités d'accessibilité. */
            accessibility: { enabled: false },
            /* Réduire le flickering : config stable, espacement fixe */
            itemDistance: 12,
            margin: 8
        }
    };

    var darkTheme = {
        chart: { backgroundColor: 'transparent' },
        colors: ['#2dd4bf', '#22d3ee', '#a78bfa', '#f472b6', '#fbbf24', '#34d399', '#60a5fa', '#fb923c'],
        xAxis: {
            gridLineColor: '#475569',
            labels: { style: { color: '#94a3b8' } },
            lineColor: '#475569',
            tickColor: '#475569'
        },
        yAxis: {
            gridLineColor: '#475569',
            labels: { style: { color: '#94a3b8' } },
            lineColor: '#475569',
            tickColor: '#475569',
            title: { style: { color: '#94a3b8' } }
        },
        legend: {
            accessibility: { enabled: false },
            itemStyle: { color: '#94a3b8' },
            itemHoverStyle: { color: '#f1f5f9' }
        },
        title: { style: { color: '#f1f5f9' } },
        subtitle: { style: { color: '#94a3b8' } },
        tooltip: {
            backgroundColor: '#1e293b',
            borderColor: '#475569',
            style: { color: '#f1f5f9' }
        },
        rangeSelector: {
            buttonTheme: {
                fill: '#334155',
                stroke: '#475569',
                style: { color: '#94a3b8' },
                states: {
                    hover: { fill: '#475569', style: { color: '#2dd4bf' } },
                    select: { fill: '#2dd4bf', style: { color: '#0f172a' } }
                }
            }
        },
        navigator: {
            series: { lineColor: '#2dd4bf' },
            xAxis: { gridLineColor: '#475569' }
        }
    };

    function isDark() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }

    function applyTheme() {
        var opts = baseOptions;
        if (isDark()) {
            opts = Object.assign({}, baseOptions, darkTheme);
        }
        Highcharts.setOptions(opts);
    }

    applyTheme();

    window.n3HighchartsApplyTheme = applyTheme;
})();
