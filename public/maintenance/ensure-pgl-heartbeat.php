<?php

/**
 * Maintenance temporaire : crée la table pglHeartbeat si absente (prod).
 *
 * Usage : https://iot.olution.info/public/maintenance/ensure-pgl-heartbeat.php
 * À SUPPRIMER du dépôt après exécution réussie.
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../vendor/autoload.php';

use App\Config\Env;

Env::load();

$container = require __DIR__ . '/../../config/container.php';
/** @var PDO $pdo */
$pdo = $container->get(PDO::class);

$sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS pglHeartbeat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uptime BIGINT NOT NULL COMMENT 'Secondes depuis dernier boot',
    freeHeap INT NOT NULL COMMENT 'Free heap au moment du heartbeat (octets)',
    minHeap INT NOT NULL COMMENT 'Min free heap observe depuis boot (octets)',
    reboots INT NOT NULL COMMENT 'Compteur reboots cumules (bootCount)',
    rssi INT NULL COMMENT 'RSSI WiFi (dBm)',
    sensor VARCHAR(30) NULL,
    version VARCHAR(30) NULL,
    reading_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pgl_heartbeat_time (reading_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

try {
    $pdo->exec($sql);
    $exists = $pdo->query("SHOW TABLES LIKE 'pglHeartbeat'")->fetchColumn();
    echo $exists ? "OK : table pglHeartbeat presente.\n" : "ERREUR : table introuvable apres CREATE.\n";
    echo "Verifier : POST /pgl/heartbeat doit repondre 200.\n";
    echo "A SUPPRIMER ce script apres utilisation.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERREUR : ' . $e->getMessage() . "\n";
}
