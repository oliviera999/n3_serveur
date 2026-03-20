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
            backgroundColor: 'transparent',
            plotBackgroundColor: 'transparent',
            borderColor: '#cccccc',
            style: { color: '#333333' }
        },
        title: { style: { color: '#333333' } },
        subtitle: { style: { color: '#666666' } },
        xAxis: {
            gridLineColor: '#e6e6e6',
            tickColor: '#cccccc',
            labels: { style: { color: '#666666' } },
            lineColor: '#cccccc',
            title: { style: { color: '#333333' } }
        },
        yAxis: {
            gridLineColor: '#e6e6e6',
            tickColor: '#cccccc',
            labels: { style: { color: '#666666' } },
            lineColor: '#cccccc',
            title: { style: { color: '#333333' } }
        },
        legend: {
            itemStyle: { color: '#333333' },
            itemHoverStyle: { color: '#000000' }
        },
        rangeSelector: {
            buttonTheme: {
                fill: '#f2f2f2',
                stroke: '#cccccc',
                style: { color: '#333333' },
                states: {
                    hover: { fill: '#e6e6e6' },
                    select: { fill: '#008B74', style: { color: '#ffffff' } }
                }
            },
            inputStyle: { color: '#333333' },
            labelStyle: { color: '#666666' }
        },
        navigator: {
            series: { color: '#008B74' },
            xAxis: {
                gridLineColor: '#e6e6e6',
                labels: { style: { color: '#666666' } }
            }
        },
        scrollbar: {
            barBackgroundColor: '#cccccc',
            barBorderColor: '#cccccc',
            buttonArrowColor: '#333333',
            buttonBackgroundColor: '#e6e6e6',
            buttonBorderColor: '#cccccc',
            rifleColor: '#333333',
            trackBackgroundColor: '#f2f2f2',
            trackBorderColor: '#cccccc'
        },
        tooltip: {
            backgroundColor: '#ffffff',
            borderColor: '#cccccc',
            style: { color: '#333333' }
        },
        credits: { style: { color: '#999999' } },
        colors: ['#2caffe', '#544fc5', '#00e272', '#fe6a35', '#6b8abc', '#d568fb', '#2ee0ca', '#fa4b42']
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
