/**
 * Highcharts – configuration globale partagée (FFP3 / aquaponie, MSP1, N3PP).
 * Chargé avant la création des graphiques dans les templates.
 * Applique uniquement les options de base (timezone, langue, layout).
 * Le thème dark/light est géré exclusivement par highcharts-theme.js.
 */
(function () {
    if (typeof Highcharts === 'undefined') return;

    Highcharts.setOptions({
        /* Projet physique à Casablanca : affichage cohérent avec FFP3 et TIMEZONE_MANAGEMENT.md */
        time: { useUTC: false, timezone: 'Africa/Casablanca' },
        plotOptions: {
            series: {
                visible: true,
                dataGrouping: { enabled: false },
                /* Briser la courbe quand l'écart entre deux points dépasse 2× l'intervalle
                 * médian de la série (capteur hors-ligne). Évite la ligne de connexion
                 * entre le dernier relevé avant interruption et le premier après. */
                gapSize: 2,
                gapUnit: 'relative'
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
    });

    /* Intègre le thème dark/light via setOptions() avant la création des graphiques.
     * Évite le chart.update() post-animation qui causait un saut visuel à ~1s. */
    if (typeof window.n3HighchartsBuildThemeOptions === 'function') {
        Highcharts.setOptions(window.n3HighchartsBuildThemeOptions());
    }
})();
