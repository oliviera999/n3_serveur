(function (global) {
    'use strict';

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

        return [
            {
                name: 'Pics marée (distance)',
                type: 'scatter',
                data: peakData,
                color: '#8E24AA',
                marker: { symbol: 'triangle', radius: 5 },
                tooltip: { valueSuffix: ' cm' },
                showInNavigator: false,
                dataGrouping: { enabled: false },
            },
            {
                name: 'Creux marée (distance)',
                type: 'scatter',
                data: troughData,
                color: '#1565C0',
                marker: { symbol: 'triangle-down', radius: 5 },
                tooltip: { valueSuffix: ' cm' },
                showInNavigator: false,
                dataGrouping: { enabled: false },
            }
        ];
    }

    global.n3TideMarkers = {
        normalizePoints: normalizePoints,
        buildSeries: buildSeries
    };
}(window));
