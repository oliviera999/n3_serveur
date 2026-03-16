<?php

declare(strict_types=1);

/**
 * Script de vidage des caches — accessible via HTTP sans passer par le container DI.
 * Utile quand le cache DI est obsolète et provoque des 500 sur toutes les pages.
 *
 * URL : https://iot.olution.info/public/maintenance/clear-cache.php
 * Avec token : ?token=clear-cache-2025 (défaut) ou ADMIN_CACHE_TOKEN du .env
 *
 * À SUPPRIMER ou désactiver après usage (sécurité).
 */

header('Content-Type: text/plain; charset=utf-8');

$projectRoot = dirname(__DIR__, 2);

// Charger .env (pour ADMIN_CACHE_TOKEN éventuel)
if (file_exists($projectRoot . '/.env')) {
    $lines = @file($projectRoot . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (preg_match('/^([^=]+)=(.*)$/', $line, $m)) {
            $key = trim($m[1]);
            $value = trim($m[2], " \t\n\r\0\x0B\"'");
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// Vérification token (éviter les appels non autorisés)
$token = $_GET['token'] ?? '';
$expectedToken = $_ENV['ADMIN_CACHE_TOKEN'] ?? 'clear-cache-2025';
if ($token !== $expectedToken) {
    http_response_code(403);
    echo "Accès refusé. Utilisez ?token=clear-cache-2025\n";
    exit;
}
$cacheDirs = [
    $projectRoot . '/var/cache/twig',
    $projectRoot . '/var/cache/di',
];

echo "Vidage des caches...\n\n";

$totalDeleted = 0;
$errors = [];

foreach ($cacheDirs as $cacheDir) {
    $dirName = basename($cacheDir);

    if (!is_dir($cacheDir)) {
        echo "{$dirName}/ : n'existe pas, rien a vider.\n";
        continue;
    }

    echo "Vidage de {$dirName}/... ";
    try {
        $count = 0;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
                $count++;
            }
        }
        $totalDeleted += $count;
        echo "{$count} fichier(s) supprime(s).\n";
    } catch (Throwable $e) {
        $errors[] = "{$dirName}: " . $e->getMessage();
        echo "ERREUR: " . $e->getMessage() . "\n";
    }
}

// Opcache
if (function_exists('opcache_reset') && opcache_get_status(false)) {
    opcache_reset();
    echo "\nOpCache vide.\n";
}

echo "\n";
if (empty($errors)) {
    echo "Cache vide avec succes ! ({$totalDeleted} fichiers)\n";
    echo "Rechargez la page gallery : https://iot.olution.info/gallery\n";
} else {
    echo "Erreurs : " . implode(' ; ', $errors) . "\n";
}
