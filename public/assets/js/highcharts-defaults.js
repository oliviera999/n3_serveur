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
                /* Rupture de courbe après une absence de données (axe datetime = ms).
                 * Ne PAS utiliser gapUnit "relative" : Highcharts compare à l'écart entre
                 * les deux points les plus proches du jeu, pas à la médiane. Deux relevés
                 * rapprochés (rafale, doublon) rendent le seuil quasi nul → la série se
                 * fragmente en points isolés ; sans marqueurs, la courbe paraît vide
                 * alors que le survol (tooltip) fonctionne encore. */
                gapUnit: 'value',
                gapSize: 3600000
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
