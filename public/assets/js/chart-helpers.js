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

    /**
     * Dégradé vertical sous courbe (même principe que les graphiques aquaponie).
     * @param {number} r
     * @param {number} g
     * @param {number} b
     * @param {number} [topAlpha=0.28]
     * @param {number} [bottomAlpha=0.06]
     * @returns {{ linearGradient: Object, stops: Array }}
     */
    function n3AreaGradientFill(r, g, b, topAlpha, bottomAlpha) {
        var ta = topAlpha != null ? topAlpha : 0.28;
        var ba = bottomAlpha != null ? bottomAlpha : 0.06;
        return {
            linearGradient: { x1: 0, x2: 0, y1: 0, y2: 1 },
            stops: [
                [0, 'rgba(' + r + ',' + g + ',' + b + ',' + ta + ')'],
                [1, 'rgba(' + r + ',' + g + ',' + b + ',' + ba + ')']
            ]
        };
    }

    window.zipSeries = zipSeries;
    window.n3AreaGradientFill = n3AreaGradientFill;
})();
