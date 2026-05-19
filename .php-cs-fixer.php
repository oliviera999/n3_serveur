<?php

declare(strict_types=1);

/**
 * Configuration PHP-CS-Fixer — serveur n3 IoT.
 *
 * Base : PSR-12 + quelques règles modérées (alignement avec les conventions du repo).
 * Pour appliquer : `composer cs:fix`
 * Pour verifier  : `composer cs:check`
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/config',
        __DIR__ . '/public',
    ])
    ->exclude([
        'vendor',
        'archives',
        'analyse-ffp3',
        'ameliorations-visuelles-iot-serveur',
        'var',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'cast_spaces' => ['space' => 'single'],
        'concat_space' => ['spacing' => 'one'],
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
