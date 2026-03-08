<?php

/**
 * Analyse des error_log pour le debug en production.
 * Utilisation :
 *   CLI : php tools/analyze_errors.php (depuis le dossier ffp3)
 *   Web : route GET /admin/analyze-errors (protégée par auth)
 */

declare(strict_types=1);

const MAX_LINES_READ = 2000;
const LAST_RELEVANT_LINES = 50;

/**
 * Lit le contenu d'un fichier de log (dernières lignes).
 */
function readLogTail(string $path, int $maxLines = MAX_LINES_READ): array
{
    if (!is_readable($path) || !is_file($path)) {
        return [];
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        return [];
    }
    $lines = explode("\n", $content);
    $lines = array_slice($lines, -$maxLines);
    return array_filter($lines, fn(string $l) => trim($l) !== '');
}

/**
 * Parse les lignes et compte par type + extrait les dernières erreurs pertinentes.
 *
 * @return array{counts: array<string, int>, by_type: array<string, array<int, string>>, last_lines: array<int, string>, sources: array<string, bool>}
 */
function runAnalysis(string $projectRoot): array
{
    $publicLog = $projectRoot . '/public/error_log';
    $rootLog   = $projectRoot . '/error_log';

    $sources = [
        'public/error_log' => is_readable($publicLog),
        'error_log (racine)' => is_readable($rootLog),
    ];

    $allLines = [];
    $sourceByLine = [];

    foreach ([$publicLog => 'public/error_log', $rootLog => 'error_log (racine)'] as $path => $label) {
        if (!is_readable($path)) {
            continue;
        }
        $lines = readLogTail($path);
        foreach ($lines as $line) {
            $allLines[] = $line;
            $sourceByLine[] = $label;
        }
    }

    $counts = [
        'n3_500' => 0,
        'php_fatal' => 0,
        'ffp3_404' => 0,
        'n3_iot_404' => 0,
        'trace' => 0,
    ];

    $byType = [
        'n3_500' => [],
        'php_fatal' => [],
        'ffp3_404' => [],
        'n3_iot_404' => [],
    ];

    $lastRelevant = [];
    $n = count($allLines);

    for ($i = 0; $i < $n; $i++) {
        $line = $allLines[$i];
        $trimmed = trim($line);

        // Ligne de trace (contient [n3 500] + Trace:) : compter comme trace, pas comme n3_500
        if (str_contains($line, '[n3 500]') && str_contains($line, 'Trace:')) {
            $counts['trace']++;
            $lastRelevant[] = ['source' => $sourceByLine[$i] ?? '', 'line' => '[Trace] ' . substr($trimmed, 0, 200) . (strlen($trimmed) > 200 ? '...' : '')];
        } elseif (str_contains($line, '[n3 500]')) {
            $counts['n3_500']++;
            $byType['n3_500'][] = $trimmed;
            $lastRelevant[] = ['source' => $sourceByLine[$i] ?? '', 'line' => $trimmed];
        } elseif (stripos($line, 'PHP Fatal') !== false) {
            $counts['php_fatal']++;
            $byType['php_fatal'][] = $trimmed;
            $lastRelevant[] = ['source' => $sourceByLine[$i] ?? '', 'line' => $trimmed];
        } elseif (str_contains($line, 'FFP3 404]')) {
            $counts['ffp3_404']++;
            $byType['ffp3_404'][] = $trimmed;
            $lastRelevant[] = ['source' => $sourceByLine[$i] ?? '', 'line' => $trimmed];
        } elseif (str_contains($line, 'n3-iot 404') || str_contains($line, 'n3-iot 404]')) {
            $counts['n3_iot_404']++;
            $byType['n3_iot_404'][] = $trimmed;
            $lastRelevant[] = ['source' => $sourceByLine[$i] ?? '', 'line' => $trimmed];
        }
    }

    $lastRelevant = array_slice($lastRelevant, -LAST_RELEVANT_LINES);

    return [
        'counts' => $counts,
        'by_type' => $byType,
        'last_lines' => $lastRelevant,
        'sources' => $sources,
    ];
}

/**
 * Formate le résumé en texte pour CLI ou réponse HTTP.
 */
function formatSummaryAsText(array $result): string
{
    $out = "=== Analyse error_log ===\n\n";
    $out .= "Sources lues :\n";
    foreach ($result['sources'] as $label => $readable) {
        $out .= "  - {$label} : " . ($readable ? 'oui' : 'non') . "\n";
    }
    $out .= "\nComptages :\n";
    $labels = [
        'n3_500' => '[n3 500]',
        'php_fatal' => 'PHP Fatal',
        'ffp3_404' => 'FFP3 404',
        'n3_iot_404' => 'n3-iot 404',
        'trace' => 'Trace',
    ];
    foreach ($result['counts'] as $key => $count) {
        $label = $labels[$key] ?? $key;
        $out .= "  - {$label} : {$count}\n";
    }
    $out .= "\nDernières lignes pertinentes (max " . LAST_RELEVANT_LINES . ") :\n";
    $out .= str_repeat('-', 72) . "\n";
    foreach ($result['last_lines'] as $item) {
        $src = $item['source'] ?? '';
        $line = $item['line'] ?? '';
        if ($src) {
            $out .= "[{$src}] " . substr($line, 0, 500) . (strlen($line) > 500 ? '...' : '') . "\n";
        } else {
            $out .= substr($line, 0, 500) . (strlen($line) > 500 ? '...' : '') . "\n";
        }
    }
    return $out;
}

// CLI : exécution directe
if (php_sapi_name() === 'cli') {
    $projectRoot = dirname(__DIR__);
    $result = runAnalysis($projectRoot);
    echo formatSummaryAsText($result);
}