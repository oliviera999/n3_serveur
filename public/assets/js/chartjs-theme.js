/**
 * chartjs-theme.js — Adaptation des graphiques Chart.js au theme dark/light
 * Definit n3ChartJsApplyTheme() appelee par theme-toggle.js au changement de theme.
 */
(function () {
    'use strict';

    var DARK = {
        bg: '#1e293b',
        text: '#f1f5f9',
        textMuted: '#94a3b8',
        grid: '#475569',
        border: '#475569'
    };

    var LIGHT = {
        bg: 'transparent',
        text: '#333333',
        textMuted: '#666666',
        grid: '#e6e6e6',
        border: '#cccccc'
    };

    function isDark() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }

    function getColors() {
        return isDark() ? DARK : LIGHT;
    }

    function applyToAllCharts() {
        if (typeof Chart === 'undefined') return;

        var c = getColors();

        Chart.defaults.color = c.text;
        Chart.defaults.borderColor = c.border;

        if (Chart.defaults.scales && Chart.defaults.scales.linear) {
            Chart.defaults.scales.linear.grid = Chart.defaults.scales.linear.grid || {};
            Chart.defaults.scales.linear.grid.color = c.grid;
            Chart.defaults.scales.linear.ticks = Chart.defaults.scales.linear.ticks || {};
            Chart.defaults.scales.linear.ticks.color = c.textMuted;
        }
        if (Chart.defaults.scales && Chart.defaults.scales.category) {
            Chart.defaults.scales.category.grid = Chart.defaults.scales.category.grid || {};
            Chart.defaults.scales.category.grid.color = c.grid;
            Chart.defaults.scales.category.ticks = Chart.defaults.scales.category.ticks || {};
            Chart.defaults.scales.category.ticks.color = c.textMuted;
        }

        var instances = Object.values(Chart.instances || {});
        for (var i = 0; i < instances.length; i++) {
            var chart = instances[i];
            if (!chart || !chart.options) continue;

            chart.options.color = c.text;

            if (chart.options.scales) {
                var axes = ['x', 'y'];
                for (var j = 0; j < axes.length; j++) {
                    var axis = chart.options.scales[axes[j]];
                    if (axis) {
                        axis.grid = axis.grid || {};
                        axis.grid.color = c.grid;
                        axis.ticks = axis.ticks || {};
                        axis.ticks.color = c.textMuted;
                        if (axis.title) {
                            axis.title.color = c.textMuted;
                        }
                    }
                }
            }

            if (chart.options.plugins && chart.options.plugins.legend) {
                chart.options.plugins.legend.labels = chart.options.plugins.legend.labels || {};
                chart.options.plugins.legend.labels.color = c.text;
            }

            try {
                chart.update('none');
            } catch (e) {
                console.warn('[n3ChartJs] Erreur mise a jour theme:', e);
            }
        }
    }

    window.n3ChartJsApplyTheme = applyToAllCharts;

    function initApply() {
        applyToAllCharts();
        setTimeout(applyToAllCharts, 1200);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initApply);
    } else {
        initApply();
    }
})();
