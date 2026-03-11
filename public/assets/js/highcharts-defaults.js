/**
 * Highcharts – configuration globale partagée (FFP3, MSP1, N3PP).
 * Chargé avant la création des graphiques dans les templates.
 */
(function () {
    if (typeof Highcharts === 'undefined') return;

    Highcharts.setOptions({
        time: { useUTC: false },
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
        chart: {
            backgroundColor: 'transparent'
        },
        credits: { enabled: false }
    });
})();
