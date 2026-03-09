/**
 * Fonctions utilitaires partagées pour les graphiques Highcharts.
 * Utilisées par msp1_data, n3pp_data, aquaponie, etc.
 */

/**
 * Combine un tableau de timestamps (reading_time) avec un tableau de valeurs
 * en paires [timestamp, valeur] pour Highcharts.
 *
 * @param {number[]} timestamps - reading_time (ms epoch)
 * @param {(number|null)[]} values - valeurs capteur
 * @returns {Array<[number, number|null]>}
 */
function zipSeries(timestamps, values) {
    var r = [];
    for (var i = 0; i < timestamps.length; i++) {
        r.push([timestamps[i], values[i]]);
    }
    return r;
}

/**
 * Soumet le formulaire de filtre avec une période rapide (1h, 6h, 1j, 1sem, etc.).
 *
 * @param {number} value - durée
 * @param {string} unit  - 'h' pour heures, 'd' pour jours
 */
function setPeriod(value, unit) {
    var now = new Date();
    var start = new Date(now);
    if (unit === 'h' || unit === 'hours') {
        start.setHours(start.getHours() - value);
    } else if (unit === 'd' || unit === 'days') {
        start.setDate(start.getDate() - value);
    }
    function fmt(d) {
        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0') + 'T' +
            String(d.getHours()).padStart(2, '0') + ':' +
            String(d.getMinutes()).padStart(2, '0');
    }
    var startField = document.getElementById('start_datetime');
    var endField = document.getElementById('end_datetime');
    if (startField && endField) {
        startField.value = fmt(start);
        endField.value = fmt(now);
        startField.closest('form').submit();
    }
}
