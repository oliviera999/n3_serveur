/**
 * highcharts-theme.js — Adaptation des graphiques Highcharts au thème dark/light
 * Définit n3HighchartsApplyTheme() appelée par theme-toggle.js au changement de thème
 * et à l'init pour les graphiques créés après chargement.
 */
(function () {
    'use strict';

    var DARK_OPTIONS = {
        chart: {
            backgroundColor: '#1e293b',
            plotBackgroundColor: '#1e293b',
            borderColor: '#475569',
            style: { color: '#f1f5f9' }
        },
        title: { style: { color: '#f1f5f9' } },
        subtitle: { style: { color: '#94a3b8' } },
        xAxis: {
            gridLineColor: '#475569',
            tickColor: '#475569',
            labels: { style: { color: '#94a3b8' } },
            lineColor: '#475569',
            title: { style: { color: '#94a3b8' } }
        },
        yAxis: {
            gridLineColor: '#475569',
            tickColor: '#475569',
            labels: { style: { color: '#94a3b8' } },
            lineColor: '#475569',
            title: { style: { color: '#94a3b8' } }
        },
        legend: {
            itemStyle: { color: '#94a3b8' },
            itemHoverStyle: { color: '#f1f5f9' }
        },
        rangeSelector: {
            buttonTheme: {
                fill: '#334155',
                stroke: '#475569',
                style: { color: '#f1f5f9' },
                states: {
                    hover: { fill: '#475569' },
                    select: { fill: '#2dd4bf', style: { color: '#0f172a' } }
                }
            },
            inputStyle: { color: '#f1f5f9' },
            labelStyle: { color: '#94a3b8' }
        },
        navigator: {
            series: { color: '#2dd4bf' },
            xAxis: {
                gridLineColor: '#475569',
                labels: { style: { color: '#94a3b8' } }
            }
        },
        scrollbar: {
            barBackgroundColor: '#334155',
            barBorderColor: '#475569',
            buttonArrowColor: '#f1f5f9',
            buttonBackgroundColor: '#334155',
            buttonBorderColor: '#475569',
            rifleColor: '#f1f5f9',
            trackBackgroundColor: '#1e293b',
            trackBorderColor: '#475569'
        },
        tooltip: {
            backgroundColor: '#334155',
            borderColor: '#475569',
            style: { color: '#f1f5f9' }
        },
        credits: { style: { color: '#64748b' } },
        colors: ['#2dd4bf', '#fb923c', '#94a3b8', '#a78bfa', '#f472b6', '#22d3ee', '#fbbf24', '#4ade80']
    };

    var LIGHT_OPTIONS = {
        chart: {
            backgroundColor: null,
            plotBackgroundColor: null,
            borderColor: null,
            style: null
        },
        title: { style: null },
        subtitle: { style: null },
        xAxis: {
            gridLineColor: null,
            tickColor: null,
            labels: { style: null },
            lineColor: null,
            title: { style: null }
        },
        yAxis: {
            gridLineColor: null,
            tickColor: null,
            labels: { style: null },
            lineColor: null,
            title: { style: null }
        },
        legend: {
            itemStyle: null,
            itemHoverStyle: null
        },
        rangeSelector: null,
        navigator: null,
        scrollbar: null,
        tooltip: null,
        credits: { style: null },
        colors: null
    };

    function isDark() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }

    function applyToChart(chart, opts) {
        if (!chart || !chart.update) return;
        try {
            chart.update(opts, true);
        } catch (e) {
            console.warn('[n3Highcharts] Erreur mise à jour thème:', e);
        }
    }

    function getThemeOptions() {
        return isDark() ? DARK_OPTIONS : LIGHT_OPTIONS;
    }

    function n3HighchartsApplyTheme() {
        if (typeof window.Highcharts === 'undefined' || !window.Highcharts.charts) return;
        var opts = getThemeOptions();
        var charts = window.Highcharts.charts;
        for (var i = 0; i < charts.length; i++) {
            if (charts[i] && charts[i].renderTo) {
                applyToChart(charts[i], opts);
            }
        }
    }

    window.n3HighchartsApplyTheme = n3HighchartsApplyTheme;

    function initApply() {
        n3HighchartsApplyTheme();
        setTimeout(n3HighchartsApplyTheme, 1200);
        setTimeout(n3HighchartsApplyTheme, 2500);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initApply);
    } else {
        initApply();
    }
})();
