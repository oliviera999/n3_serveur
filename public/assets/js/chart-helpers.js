/**
 * Helpers pour les graphiques Highcharts (MSP1, N3PP, aquaponie).
 * Fournit zipSeries pour associer timestamps et valeurs en séries [x, y].
 */
(function () {
    'use strict';

    /**
     * Associe un tableau de timestamps (ms) et un tableau de valeurs en points Highcharts [[x, y], ...].
     * Gère les longueurs différentes en tronquant au plus court ; null/undefined dans values donnent des points valides avec y null.
     * @param {number[]} timeArray - Timestamps en millisecondes
     * @param {number|null|undefined[]} valueArray - Valeurs (peuvent être null)
     * @returns {Array<[number, number|null]>}
     */
    function zipSeries(timeArray, valueArray) {
        if (!Array.isArray(timeArray) || !Array.isArray(valueArray)) {
            return [];
        }
        var len = Math.min(timeArray.length, valueArray.length);
        var out = [];
        for (var i = 0; i < len; i++) {
            var t = timeArray[i];
            var v = valueArray[i];
            if (v !== undefined && v !== null && !isNaN(Number(v))) {
                out.push([t, Number(v)]);
            } else {
                out.push([t, null]);
            }
        }
        return out;
    }

    window.zipSeries = zipSeries;
})();
