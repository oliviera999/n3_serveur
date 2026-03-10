<?php

/**
 * Configuration des routes pour l'authentification et les redirections 301.
 *
 * Utilisé par public/index.php pour :
 * - Middleware global : chemins publics vs protégés
 * - registerRedirects() : anciennes URL vers nouveau schéma de nommage
 */

return [
    // Chemins exacts accessibles sans authentification
    'exact_public_paths' => [
        '/',
        '/login',
        '/logout',
        '/ping',
        '/favicon.ico',
    ],

    // Préfixes de chemins accessibles sans authentification (strpos === 0)
    'public_paths' => [
        '/aquaponie',
        '/aquaponie-alt',
        '/aquaponie-test',
        '/aquaponie-alt-test',
        '/aquamobile',
        '/aquamobile-alt',
        '/aquamobile-test',
        '/aquamobile-alt-test',
        '/aquaponie-description',
        '/meteo-description',
        '/serre-description',
        '/meteo',
        '/serre',
        '/gallery',
        '/post-data',
        '/post-data-test',
        '/post-data3',
        '/post-data3-test',
        '/heartbeat',
        '/heartbeat-test',
        '/heartbeat3',
        '/heartbeat3-test',
        '/api/realtime',
        '/api/realtime-test',
        '/api/realtime3',
        '/api/realtime3-test',
        '/api/health',
        '/api/health-test',
        '/api/outputs/state',
        '/api/outputs-test/state',
        '/api/outputs3/state',
        '/api/outputs3-test/state',
        '/ffp3/api/outputs/state',
        '/ffp3/api/outputs-test/state',
        '/ffp3/api/outputs3/state',
        '/ffp3/api/outputs3-test/state',
        '/ota/',
        '/assets/',
        '/msp1gallery/',
        '/n3ppgallery/',
        '/ffp3gallery/',
        '/ffp3/ffp3gallery/',
        '/msp1/',
        '/n3pp/',
        '/ffp3control/',  // Legacy : getOutputsState
    ],

    // Préfixes de chemins protégés (authentification requise)
    'protected_paths' => [
        '/dashboard',
        '/dashboard-test',
        '/aquaponie-control',
        '/aquaponie-control-test',
        '/aquamobile-control',
        '/aquamobile-control-test',
        '/supervision',
        '/export-data',
        '/tide-stats',
        '/tide-stats-test',
        '/meteo-control',
        '/serre-control',
        '/admin/',
    ],

    // Whitelist des assets servis par registerAssetRoute()
    'asset_js' => [
        'breakpoints.min.js', 'browser.min.js', 'chart-helpers.js', 'chart-updater-generic.js',
        'chart-updater.js', 'control-actions.js', 'control-auto-save.js', 'control-sync.js',
        'control-values-updater.js', 'highcharts-defaults.js', 'jquery.min.js', 'jquery.scrollex.min.js',
        'jquery.scrolly.min.js', 'main.js', 'pwa-init.js', 'realtime-updater.js', 'stats-updater.js',
        'toast-notifications.js', 'util.js',
    ],
    'asset_css' => [
        'aquaponie.css', 'common-data.css', 'control-styles.css', 'login-styles.css',
        'main.css', 'noscript.css', 'realtime-styles.css',
    ],
    'asset_icons' => [
        'icon-72.png', 'icon-96.png', 'icon-128.png', 'icon-144.png', 'icon-152.png',
        'icon-192.png', 'icon-384.png', 'icon-512.png',
    ],

    // Redirections 301 : [ancienne_url, nouvelle_url, méthodes]
    // Méthodes : ['GET'] ou ['GET', 'POST']
    'redirects_301' => [
        ['/control', '/aquaponie-control', ['GET']],
        ['/control-test', '/aquaponie-control-test', ['GET']],
        ['/control3', '/aquamobile-control', ['GET']],
        ['/control3-test', '/aquamobile-control-test', ['GET']],
        ['/aquaponie3', '/aquamobile', ['GET', 'POST']],
        ['/aquaponie3-test', '/aquamobile-test', ['GET', 'POST']],
        ['/aquaponie-alt3', '/aquamobile-alt', ['GET', 'POST']],
        ['/aquaponie-alt3-test', '/aquamobile-alt-test', ['GET', 'POST']],
        ['/ffp3-data', '/aquaponie', ['GET', 'POST']],
        ['/heartbeat.php', '/heartbeat', ['POST']],
        ['/heartbeat-test.php', '/heartbeat-test', ['POST']],
    ],
];
