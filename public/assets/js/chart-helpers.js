/**
 * chart-helpers.js — Fonctions utilitaires pour les graphiques
 * Re-export de zipSeries pour compatibilité (défini dans highcharts-defaults.js).
 * Ce fichier peut être supprimé si highcharts-defaults.js est toujours chargé avant.
 */

if (typeof zipSeries === 'undefined') {
    /**
     * Fallback : combine timestamps ISO ou millisecondes et valeurs en paires Highcharts.
     * Préférer le chargement de highcharts-defaults.js qui inclut cette fonction.
     */
    function zipSeries(times, values) {
        if (!times || !values) return [];
        var result = [];
        for (var i = 0; i < times.length; i++) {
            var ts;
            if (typeof times[i] === 'number') {
                ts = times[i];
            } else if (typeof times[i] === 'string') {
                ts = new Date(times[i].replace(' ', 'T')).getTime();
            } else {
                continue;
            }
            
            var v = values[i] !== null && values[i] !== undefined && values[i] !== ''
                ? parseFloat(values[i])
                : null;
            if (!isNaN(ts)) {
                result.push([ts, isNaN(v) ? null : v]);
            }
        }
        return result;
    }
}
