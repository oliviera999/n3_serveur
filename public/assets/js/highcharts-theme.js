/**
 * highcharts-theme.js — Adaptation des graphiques Highcharts au thème dark/light
 * Définit n3HighchartsApplyTheme() appelée par theme-toggle.js au changement de thème
 * et à l'init pour les graphiques créés après chargement.
 */
(function () {
    'use strict';

    function cssVar(name, fallbackValue) {
        var root = document.documentElement;
        if (!root || !window.getComputedStyle) return fallbackValue;
        var value = window.getComputedStyle(root).getPropertyValue(name);
        return value ? value.trim() : fallbackValue;
    }

    function buildThemeOptions() {
        var dark = isDark();
        var bgMain = cssVar('--bg-main', dark ? '#1e293b' : '#ffffff');
        var bgSecondary = cssVar('--bg-secondary', dark ? '#334155' : '#f8f9fa');
        var borderColor = cssVar('--border-color', dark ? '#475569' : '#cccccc');
        var textPrimary = cssVar('--text-primary', dark ? '#f1f5f9' : '#333333');
        var textSecondary = cssVar('--text-secondary', dark ? '#94a3b8' : '#666666');
        var textMuted = cssVar('--text-muted', dark ? '#64748b' : '#999999');
        var accentPrimary = cssVar('--accent-primary', dark ? '#2dd4bf' : '#008B74');
        var accentPrimaryHover = cssVar('--accent-primary-hover', dark ? '#5eead4' : '#00A896');
        var accentSecondary = cssVar('--accent-secondary', dark ? '#fb923c' : '#FF6300');
        var textInverse = cssVar('--text-inverse', dark ? '#0f172a' : '#ffffff');

        return {
            chart: {
                backgroundColor: dark ? bgMain : 'transparent',
                plotBackgroundColor: dark ? bgMain : 'transparent',
                borderColor: borderColor,
                style: { color: textPrimary }
            },
            title: { style: { color: textPrimary } },
            subtitle: { style: { color: textSecondary } },
            xAxis: {
                gridLineColor: borderColor,
                tickColor: borderColor,
                labels: { style: { color: textSecondary } },
                lineColor: borderColor,
                title: { style: { color: textSecondary } }
            },
            yAxis: {
                gridLineColor: borderColor,
                tickColor: borderColor,
                labels: { style: { color: textSecondary } },
                lineColor: borderColor,
                title: { style: { color: textSecondary } }
            },
            legend: {
                itemStyle: { color: textSecondary },
                itemHoverStyle: { color: textPrimary }
            },
            rangeSelector: {
                buttonTheme: {
                    fill: bgSecondary,
                    stroke: borderColor,
                    style: { color: textPrimary },
                    states: {
                        hover: { fill: borderColor },
                        select: { fill: accentPrimary, style: { color: textInverse } }
                    }
                },
                inputStyle: { color: textPrimary },
                labelStyle: { color: textSecondary }
            },
            navigator: {
                series: { color: accentPrimary },
                xAxis: {
                    gridLineColor: borderColor,
                    labels: { style: { color: textSecondary } }
                }
            },
            scrollbar: {
                barBackgroundColor: bgSecondary,
                barBorderColor: borderColor,
                buttonArrowColor: textPrimary,
                buttonBackgroundColor: bgSecondary,
                buttonBorderColor: borderColor,
                rifleColor: textPrimary,
                trackBackgroundColor: bgMain,
                trackBorderColor: borderColor
            },
            tooltip: {
                backgroundColor: bgSecondary,
                borderColor: borderColor,
                style: { color: textPrimary }
            },
            credits: { style: { color: textMuted } },
            colors: [
                accentPrimary,
                accentSecondary,
                textSecondary,
                accentPrimaryHover,
                '#a78bfa',
                '#22d3ee',
                '#fbbf24',
                '#4ade80'
            ].map(function (color) {
                return color || '#2caffe';
            })
        };
    }

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
        return buildThemeOptions();
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
    /* Exposé pour highcharts-defaults.js : permet d'intégrer le thème via
     * Highcharts.setOptions() avant la création des graphiques, évitant
     * tout chart.update() post-animation. */
    window.n3HighchartsBuildThemeOptions = buildThemeOptions;
})();
