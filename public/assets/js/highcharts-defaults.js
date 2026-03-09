/**
 * highcharts-defaults.js — Configuration Highcharts partagée et utilitaires
 * Utilisé par msp1_data.twig, n3pp_data.twig et les pages aquaponie.
 */

if (typeof Highcharts !== 'undefined') {
    Highcharts.setOptions({
        time: { useUTC: false },
        lang: {
            months: ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'],
            shortMonths: ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'],
            weekdays: ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'],
            loading: 'Chargement...',
            noData: 'Aucune donnée',
            rangeSelectorFrom: 'Du',
            rangeSelectorTo: 'Au'
        },
        chart: {
            backgroundColor: 'transparent',
            style: { fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif' }
        },
        credits: { enabled: false },
        rangeSelector: { enabled: false },
        navigator: { enabled: true },
        scrollbar: { enabled: true }
    });
}

/**
 * Combine deux tableaux (timestamps ISO et valeurs) en paires [timestamp_ms, value]
 * compatibles avec Highcharts.
 *
 * @param {string[]} times - Tableau de dates ISO (ex: "2025-03-09 14:30:00")
 * @param {(number|string|null)[]} values - Valeurs correspondantes
 * @returns {Array<[number, number|null]>}
 */
function zipSeries(times, values) {
    if (!times || !values) return [];
    var result = [];
    for (var i = 0; i < times.length; i++) {
        var ts = new Date(times[i].replace(' ', 'T')).getTime();
        var v = values[i] !== null && values[i] !== undefined && values[i] !== ''
            ? parseFloat(values[i])
            : null;
        if (!isNaN(ts)) {
            result.push([ts, isNaN(v) ? null : v]);
        }
    }
    return result;
}

/**
 * Raccourci setPeriod pour les filtres rapides des pages données.
 * Modifie les champs start_datetime / end_datetime et soumet le formulaire.
 */
function setPeriod(amount, unit) {
    var now = new Date();
    var start = new Date(now);
    if (unit === 'h') start.setHours(start.getHours() - amount);
    else if (unit === 'd') start.setDate(start.getDate() - amount);

    function toLocal(d) {
        return d.getFullYear() + '-' +
            String(d.getMonth()+1).padStart(2,'0') + '-' +
            String(d.getDate()).padStart(2,'0') + 'T' +
            String(d.getHours()).padStart(2,'0') + ':' +
            String(d.getMinutes()).padStart(2,'0');
    }

    var startEl = document.getElementById('start_datetime');
    var endEl = document.getElementById('end_datetime');
    if (startEl) startEl.value = toLocal(start);
    if (endEl) endEl.value = toLocal(now);

    var form = (startEl || endEl);
    if (form) {
        form = form.closest('form');
        if (form) form.submit();
    }
}
