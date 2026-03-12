/**
 * chartjs-theme.js — Adaptation des graphiques Chart.js au thème dark/light
 * Définit n3ChartJsApplyTheme() appelée par theme-toggle.js au changement de thème
 * et à l'init pour les graphiques tide_stats.
 */
(function () {
    'use strict';

    var DARK_OPTIONS = {
        color: '#94a3b8',
        backgroundColor: '#1e293b',
        plugins: {
            legend: { labels: { color: '#94a3b8' } }
        },
        scales: {
            x: {
                ticks: { color: '#94a3b8' },
                grid: { color: '#475569' }
            },
            y: {
                ticks: { color: '#94a3b8' },
                grid: { color: '#475569' }
            }
        }
    };

    var LIGHT_OPTIONS = {
        color: '#6b7280',
        backgroundColor: 'rgba(255, 255, 255, 0.95)',
        plugins: {
            legend: { labels: { color: '#6b7280' } }
        },
        scales: {
            x: {
                ticks: { color: '#6b7280' },
                grid: { color: 'rgba(0, 0, 0, 0.1)' }
            },
            y: {
                ticks: { color: '#6b7280' },
                grid: { color: 'rgba(0, 0, 0, 0.1)' }
            }
        }
    };

    function isDark() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }

    function applyDefaults() {
        if (typeof window.Chart === 'undefined') return;
        var opts = isDark() ? DARK_OPTIONS : LIGHT_OPTIONS;
        Chart.defaults.color = opts.color;
        Chart.defaults.backgroundColor = opts.backgroundColor;
        if (opts.plugins && opts.plugins.legend) {
            Chart.defaults.plugins.legend.labels.color = opts.plugins.legend.labels.color;
        }
        if (opts.scales) {
            Chart.defaults.scales.linear.ticks.color = opts.scales.y.ticks.color;
            Chart.defaults.scales.linear.grid.color = opts.scales.y.grid.color;
            Chart.defaults.scales.category.ticks.color = opts.scales.x.ticks.color;
            Chart.defaults.scales.category.grid.color = opts.scales.x.grid.color;
        }
    }

    function updateInstances() {
        if (typeof window.Chart === 'undefined' || !Chart.getChart) return;
        var canvases = document.querySelectorAll('.chart-container canvas');
        canvases.forEach(function (canvas) {
            var chart = Chart.getChart(canvas);
            if (chart && chart.canvas) {
                var opts = isDark() ? DARK_OPTIONS : LIGHT_OPTIONS;
                chart.options.plugins = chart.options.plugins || {};
                chart.options.plugins.legend = chart.options.plugins.legend || {};
                chart.options.plugins.legend.labels = chart.options.plugins.legend.labels || {};
                chart.options.plugins.legend.labels.color = opts.plugins.legend.labels.color;
                chart.options.scales = chart.options.scales || {};
                if (chart.options.scales.x) {
                    chart.options.scales.x.ticks = chart.options.scales.x.ticks || {};
                    chart.options.scales.x.ticks.color = opts.scales.x.ticks.color;
                    chart.options.scales.x.grid = chart.options.scales.x.grid || {};
                    chart.options.scales.x.grid.color = opts.scales.x.grid.color;
                }
                if (chart.options.scales.y) {
                    chart.options.scales.y.ticks = chart.options.scales.y.ticks || {};
                    chart.options.scales.y.ticks.color = opts.scales.y.ticks.color;
                    chart.options.scales.y.grid = chart.options.scales.y.grid || {};
                    chart.options.scales.y.grid.color = opts.scales.y.grid.color;
                }
                chart.options.color = opts.color;
                chart.options.backgroundColor = opts.backgroundColor;
                chart.update('none');
            }
        });
    }

    function n3ChartJsApplyTheme() {
        applyDefaults();
        updateInstances();
    }

    window.n3ChartJsApplyTheme = n3ChartJsApplyTheme;

    function initApply() {
        applyDefaults();
        setTimeout(updateInstances, 100);
        setTimeout(updateInstances, 800);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initApply);
    } else {
        initApply();
    }
})();
