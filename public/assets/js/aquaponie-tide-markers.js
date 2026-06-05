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
        var extremeIdx = 0;
        var lastEventTs = null;
        var peak = null;
        var trough = null;
        var n = buffer.length;

        function record(target, idx) {
            var ts = buffer[idx][0];
            if (lastEventTs !== null && (ts - lastEventTs) < MIN_INTERVAL_MS) {
                return;
            }
            target = [ts, buffer[idx][1]];
            lastEventTs = ts;
            return target;
        }

        for (var i = 1; i < n; i++) {
            if (trend === 1 && buffer[i][1] >= buffer[extremeIdx][1]) {
                extremeIdx = i;
            } else if (trend === -1 && buffer[i][1] <= buffer[extremeIdx][1]) {
                extremeIdx = i;
            }

            var delta = buffer[i][1] - buffer[i - 1][1];
            if (Math.abs(delta) <= threshold) {
                continue;
            }
            var dir = delta > 0 ? 1 : -1;

            if (trend === 0) {
                trend = dir;
                extremeIdx = i;
                continue;
            }

            if (trend === 1) {
                if (buffer[i][1] >= buffer[extremeIdx][1]) {
                    continue;
                }
                var drop = buffer[extremeIdx][1] - buffer[i][1];
                if (drop >= threshold) {
                    var p = record('peak', extremeIdx);
                    if (p) { peak = p; }
                    trend = -1;
                    extremeIdx = i;
                }
                continue;
            }

            if (buffer[i][1] <= buffer[extremeIdx][1]) {
                continue;
            }
            var rise = buffer[i][1] - buffer[extremeIdx][1];
            if (rise >= threshold) {
                var t = record('trough', extremeIdx);
                if (t) { trough = t; }
                trend = 1;
                extremeIdx = i;
            }
        }

        if (trend === 1) {
            var fp = record('peak', extremeIdx);
            if (fp) { peak = fp; }
        } else if (trend === -1) {
            var ft = record('trough', extremeIdx);
            if (ft) { trough = ft; }
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
