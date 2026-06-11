(function (global) {
    'use strict';

    var PEAK_SERIES_NAME = 'Basse mer (distance max)';
    var TROUGH_SERIES_NAME = 'Pleine mer (distance min)';
    var DEFAULT_THRESHOLD_CM = 2.0;
    var MIN_INTERVAL_MS = 10000;

    function normalizePoints(points) {
        if (!Array.isArray(points)) {
            return [];
        }
        return points
            .filter(function (point) {
                return Array.isArray(point)
                    && point.length >= 2
                    && Number.isFinite(Number(point[0]))
                    && Number.isFinite(Number(point[1]));
            })
            .map(function (point) {
                return [Number(point[0]), Number(point[1])];
            });
    }

    function buildSeries(peaks, troughs) {
        var peakData = normalizePoints(peaks);
        var troughData = normalizePoints(troughs);

        var series = [];
        if (peakData.length > 0) {
            series.push({
                name: PEAK_SERIES_NAME,
                type: 'scatter',
                data: peakData,
                color: '#8E24AA',
                marker: { symbol: 'triangle', radius: 5 },
                tooltip: { pointFormat: 'Niveau d\'eau le plus bas : <b>{point.y}</b> cm' },
                showInNavigator: false,
                dataGrouping: { enabled: false },
            });
        }
        if (troughData.length > 0) {
            series.push({
                name: TROUGH_SERIES_NAME,
                type: 'scatter',
                data: troughData,
                color: '#1565C0',
                marker: { symbol: 'triangle-down', radius: 5 },
                tooltip: { pointFormat: 'Niveau d\'eau le plus haut : <b>{point.y}</b> cm' },
                showInNavigator: false,
                dataGrouping: { enabled: false },
            });
        }
        return series;
    }

    /**
     * Détection client des extrema (alignée sur TideCycleDetector PHP).
     *
     * Hystérésis cumulée (zigzag) : un extrême est confirmé dès que le niveau
     * s'écarte du dernier extrême courant d'au moins le seuil, même si chaque
     * delta entre deux points consécutifs reste faible (marées lentes).
     *
     * @param {Array<[number, number]>} buffer Points [timestampMs, valueCm] ordre chronologique
     * @param {number} threshold Seuil en cm
     * @returns {{peak: [number, number]|null, trough: [number, number]|null}}
     */
    function detectClientExtremum(buffer, threshold) {
        threshold = threshold || DEFAULT_THRESHOLD_CM;
        if (!Array.isArray(buffer) || buffer.length < 3) {
            return { peak: null, trough: null };
        }

        var trend = 0;
        var minIdx = 0;
        var maxIdx = 0;
        var extremeIdx = 0;
        var lastEventTs = null;
        var peak = null;
        var trough = null;
        var n = buffer.length;
        var extrema = [];

        function pushExtremum(idx, isPeak) {
            extrema.push({ ts: buffer[idx][0], v: buffer[idx][1], isPeak: isPeak });
        }

        for (var i = 1; i < n; i++) {
            var v = buffer[i][1];

            if (trend === 0) {
                if (v <= buffer[minIdx][1]) { minIdx = i; }
                if (v >= buffer[maxIdx][1]) { maxIdx = i; }
                if (v - buffer[minIdx][1] >= threshold) {
                    pushExtremum(minIdx, false);
                    trend = 1;
                    extremeIdx = i;
                } else if (buffer[maxIdx][1] - v >= threshold) {
                    pushExtremum(maxIdx, true);
                    trend = -1;
                    extremeIdx = i;
                }
                continue;
            }

            if (trend === 1) {
                if (v >= buffer[extremeIdx][1]) {
                    extremeIdx = i;
                } else if (buffer[extremeIdx][1] - v >= threshold) {
                    pushExtremum(extremeIdx, true);
                    trend = -1;
                    extremeIdx = i;
                }
                continue;
            }

            if (v <= buffer[extremeIdx][1]) {
                extremeIdx = i;
            } else if (v - buffer[extremeIdx][1] >= threshold) {
                pushExtremum(extremeIdx, false);
                trend = 1;
                extremeIdx = i;
            }
        }

        // Extrême courant provisoire (même comportement que le PHP)
        if (trend !== 0) {
            pushExtremum(extremeIdx, trend === 1);
        }

        for (var j = 0; j < extrema.length; j++) {
            var e = extrema[j];
            if (lastEventTs !== null && (e.ts - lastEventTs) < MIN_INTERVAL_MS) {
                continue;
            }
            if (e.isPeak) {
                peak = [e.ts, e.v];
            } else {
                trough = [e.ts, e.v];
            }
            lastEventTs = e.ts;
        }

        return { peak: peak, trough: trough };
    }

    /**
     * Incrémental : détecte un nouvel extrême quand un point est ajouté au buffer.
     */
    function onAquaPointAdded(buffer, threshold) {
        var result = detectClientExtremum(buffer, threshold);
        return result;
    }

    global.n3TideMarkers = {
        PEAK_SERIES_NAME: PEAK_SERIES_NAME,
        TROUGH_SERIES_NAME: TROUGH_SERIES_NAME,
        DEFAULT_THRESHOLD_CM: DEFAULT_THRESHOLD_CM,
        normalizePoints: normalizePoints,
        buildSeries: buildSeries,
        detectClientExtremum: detectClientExtremum,
        onAquaPointAdded: onAquaPointAdded
    };
}(window));
