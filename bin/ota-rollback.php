#!/usr/bin/env php
<?php

/**
 * CLI de rollback OTA (serveur) — capture / liste / restaure une version servie.
 *
 * Non bloquant : n'agit que sur les fichiers du dossier `ota/`, uniquement quand
 * il est explicitement invoqué. Prérequis à l'activation future de l'enforcement
 * de signature OTA (voir docs/OTA_ROLLBACK.md et le plan côté n3_firmwires).
 *
 * Usage :
 *   php bin/ota-rollback.php --list                       # cibles gérées
 *   php bin/ota-rollback.php --target n3pp --snapshot [--label 4.55]
 *   php bin/ota-rollback.php --target n3pp --snapshots    # snapshots dispo
 *   php bin/ota-rollback.php --target n3pp --to 4.55 [--dry-run]
 *
 * Cibles : n3pp, n3pp-test, msp, msp-test, cam. (ffp5cs : voir doc, procédure Git.)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\OtaRollbackService;

/**
 * @param list<string> $argv
 * @return array<string, string|bool>
 */
function parseArgs(array $argv): array
{
    $opts = [];
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $arg = $argv[$i];
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $key = substr($arg, 2);
        $next = $argv[$i + 1] ?? null;
        if ($next !== null && !str_starts_with($next, '--')) {
            $opts[$key] = $next;
            $i++;
        } else {
            $opts[$key] = true;
        }
    }

    return $opts;
}

$opts = parseArgs($argv);
$otaRoot = dirname(__DIR__) . '/ota';
$service = new OtaRollbackService($otaRoot);

try {
    if (isset($opts['list']) || $opts === []) {
        echo "Cibles OTA gérées :\n";
        foreach ($service->targets() as $t) {
            $cur = $service->currentLabel($t);
            printf("  - %-10s (version servie : %s)\n", $t, $cur ?? '?');
        }
        echo "\nffp5cs (esp32-wroom/s3) : rollback via Git — voir docs/OTA_ROLLBACK.md.\n";
        exit(0);
    }

    $target = is_string($opts['target'] ?? null) ? $opts['target'] : null;
    if ($target === null) {
        fwrite(STDERR, "Erreur : --target requis (voir --list).\n");
        exit(2);
    }

    if (isset($opts['snapshots'])) {
        $snaps = $service->listSnapshots($target);
        if ($snaps === []) {
            echo "Aucun snapshot pour {$target}.\n";
        } else {
            echo "Snapshots disponibles pour {$target} :\n";
            foreach ($snaps as $s) {
                echo "  - {$s}\n";
            }
        }
        exit(0);
    }

    if (isset($opts['snapshot'])) {
        $label = is_string($opts['label'] ?? null) ? $opts['label'] : null;
        $used = $service->snapshot($target, $label);
        echo "✅ Snapshot créé : {$target}/history/{$used}\n";
        exit(0);
    }

    if (isset($opts['to'])) {
        $label = is_string($opts['to']) ? $opts['to'] : '';
        $dryRun = isset($opts['dry-run']);
        $files = $service->restore($target, $label, $dryRun);
        $verb = $dryRun ? 'Restaurerait' : '✅ Restauré';
        echo "{$verb} {$target} ← snapshot « {$label} » :\n";
        foreach ($files as $f) {
            echo "  - {$f}\n";
        }
        if (!$dryRun) {
            echo "\nℹ️  L'état précédent a été auto-sauvegardé (snapshot) avant écrasement.\n";
        }
        exit(0);
    }

    fwrite(STDERR, "Erreur : action requise (--snapshot | --snapshots | --to <label> | --list).\n");
    exit(2);
} catch (\Throwable $e) {
    fwrite(STDERR, '❌ ' . $e->getMessage() . "\n");
    exit(1);
}
