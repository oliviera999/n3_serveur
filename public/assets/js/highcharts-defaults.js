/**
 * Configuration Highcharts globale partagée par toutes les pages de données.
 * Timezone Africa/Casablanca, labels en français.
 */
(function () {
    if (typeof Highcharts === 'undefined') return;

    Highcharts.setOptions({
        time: { timezone: 'Africa/Casablanca' },
        lang: {
            months: ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'],
            shortMonths: ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'],
            weekdays: ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'],
            decimalPoint: ',',
            thousandsSep: ' ',
            loading: 'Chargement...',
            noData: 'Aucune donnée',
            resetZoom: 'Reset zoom'
        }
    });
})();
