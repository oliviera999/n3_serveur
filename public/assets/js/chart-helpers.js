/**
 * Chart helpers – fonctions utilitaires pour les graphiques Highcharts.
 * Utilisé par les pages données MSP1, N3PP et FFP3.
 */

/**
 * Combine un tableau de timestamps (chaînes ISO) et un tableau de valeurs
 * en un tableau de paires [timestamp_ms, valeur] exploitable par Highcharts.
 *
 * @param {Array<string>} timestamps - Dates au format "YYYY-MM-DD HH:MM:SS"
 * @param {Array<number|null>} values - Valeurs correspondantes
 * @returns {Array<[number, number|null]>}
 */
function zipSeries(timestamps, values) {
    if (!timestamps || !values) return [];
    var result = [];
    var len = Math.min(timestamps.length, values.length);
    for (var i = 0; i < len; i++) {
        var ts = timestamps[i];
        if (ts === null || ts === undefined) continue;
        var ms = (typeof ts === 'number') ? ts : new Date(ts.replace(' ', 'T')).getTime();
        var val = values[i] !== null && values[i] !== undefined ? parseFloat(values[i]) : null;
        result.push([ms, val]);
    }
    return result;
}

/**
 * Raccourci de période pour les filtres rapides (1h, 3h, 6h, 12h, 1j, 1sem, 1mois).
 * Cherche les champs start_datetime et end_datetime dans le formulaire, les remplit et soumet.
 *
 * @param {number} value - Nombre d'unités
 * @param {string} unit - 'h', 'd', 'hours', 'days', 'months'
 */
function setPeriod(value, unit) {
    var now = new Date();
    var start = new Date(now);

    switch (unit) {
        case 'h': case 'hours':
            start.setHours(start.getHours() - value);
            break;
        case 'd': case 'days':
            start.setDate(start.getDate() - value);
            break;
        case 'months':
            start.setMonth(start.getMonth() - value);
            break;
    }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function toLocal(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' +
               pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    var startInput = document.querySelector('[name="start_datetime"]');
    var endInput = document.querySelector('[name="end_datetime"]');
    if (startInput) startInput.value = toLocal(start);
    if (endInput) endInput.value = toLocal(now);

    var form = startInput ? startInput.closest('form') : null;
    if (form) form.submit();
}
