#!/usr/bin/env php
<?php

/**
 * Migrations ADDITIVES du dépôt (migrations/*.sql) — même moteur que le CRON (AutoMigrateCommand)
 * et l'outil MCP iot_migrate. DROP / UPDATE / DELETE / MODIFY restent manuels (phpMyAdmin).
 *
 * Usage :
 *   php bin/migrate.php             # applique toutes les migrations additives en attente (force)
 *   php bin/migrate.php --dry-run   # liste seulement ce qui serait appliqué
 *
 * Appelé aussi par bin/deploy.sh et le hook post-merge (non bloquant).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Util/CliGuard.php';
\App\Util\CliGuard::assertCli();

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Command\AutoMigrateCommand;
use App\Mcp\Sql\MigrationRunner;

$dryRun = in_array('--dry-run', $argv, true);

try {
    /** @var \Psr\Container\ContainerInterface $container */
    $container = require dirname(__DIR__) . '/config/container.php';
    /** @var MigrationRunner $runner */
    $runner = $container->get(MigrationRunner::class);

    $pending = $runner->pendingFiles();
    if ($pending === []) {
        echo "✅ Aucune migration additive en attente.\n";
        exit(0);
    }
    echo 'Migrations additives en attente : ' . implode(', ', $pending) . "\n";
    if ($dryRun) {
        echo "(dry-run : rien n'est appliqué)\n";
        exit(0);
    }

    /** @var AutoMigrateCommand $command */
    $command = $container->get(AutoMigrateCommand::class);
    $result = $command->execute(force: true);
    foreach ($result['applied'] as $o) {
        printf("✅ %s : %d instruction(s), état %s\n", $o['file'], $o['executed'], $o['status_after']);
    }
    foreach ($result['skipped'] as $s) {
        printf("⏭️  %s : %s\n", $s['file'], $s['reason']);
    }
    foreach ($result['failed'] as $o) {
        printf("❌ %s : échec\n", $o['file']);
        foreach ($o['results'] as $r) {
            if (($r['status'] ?? '') === 'error') {
                printf("   %s : %s\n", (string) ($r['table'] ?? '?'), (string) ($r['error'] ?? ''));
            }
        }
    }
    exit($result['failed'] === [] ? 0 : 1);
} catch (\Throwable $e) {
    fwrite(STDERR, '❌ ' . $e->getMessage() . "\n");
    exit(1);
}
