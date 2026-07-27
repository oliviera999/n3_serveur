#!/usr/bin/env php
<?php

/**
 * Élagage N3PP prod (niveaux 1a + 2a–2b) — exécution automatique sur le serveur.
 *
 * Lit DB_* via App\Config\Env (même parsing Dotenv que l'application).
 *
 * Usage :
 *   php tools/run-prod-prune-n3pp.php --confirm
 *   php tools/run-prod-prune-n3pp.php --confirm --with-indexes --with-validate
 *   php tools/run-prod-prune-n3pp.php --dry-run
 *   php tools/run-prod-prune-n3pp.php --test-connection
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Util/CliGuard.php';
\App\Util\CliGuard::assertCli();

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

use App\Config\Env;

$insertWindow = 100000;
$deleteBatch = 3000;
$doConfirm = false;
$dryRun = false;
$withIndexes = false;
$withValidate = false;
$testConnection = false;

for ($i = 1, $argc = count($argv); $i < $argc; $i++) {
    $arg = $argv[$i];
    switch ($arg) {
        case '--confirm':
            $doConfirm = true;
            break;
        case '--dry-run':
            $dryRun = true;
            break;
        case '--with-indexes':
            $withIndexes = true;
            break;
        case '--with-validate':
            $withValidate = true;
            break;
        case '--test-connection':
            $testConnection = true;
            break;
        case '--insert-window':
            $insertWindow = (int) ($argv[++$i] ?? $insertWindow);
            break;
        case '--delete-batch':
            $deleteBatch = (int) ($argv[++$i] ?? $deleteBatch);
            break;
    }
}

$helperTable = '_n3pp_dup_delete';
$logDir = $projectRoot . '/var/log';
$logFile = $logDir . '/prune-n3pp-' . date('Ymd-His') . '.log';

function pruneLog(string $message, ?string $logFile = null): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    echo $line . PHP_EOL;
    if ($logFile !== null) {
        file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function createPrunePdo(): PDO
{
    Env::load();

    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $var) {
        if (empty($_ENV[$var])) {
            throw new RuntimeException(
                "Variable .env manquante : {$var} (vérifier .env ou env.dist à la racine du dépôt)"
            );
        }
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
    }

    return new PDO(
        'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';charset=utf8mb4',
        (string) $_ENV['DB_USER'],
        (string) $_ENV['DB_PASS'],
        $options
    );
}

function pruneScalar(PDO $pdo, string $sql): string
{
    $value = $pdo->query($sql)->fetchColumn();
    return (string) $value;
}

function pruneExec(PDO $pdo, string $sql): void
{
    $pdo->exec($sql);
}

/**
 * @return list<string>
 */
function pruneSplitSqlStatements(string $sql): array
{
    $lines = preg_split('/\R/', $sql) ?: [];
    $buffer = '';
    $statements = [];

    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        $buffer .= $line . "\n";
        if (preg_match('/;\s*$/', rtrim($line))) {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
        }
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

function pruneRunSqlFile(PDO $pdo, string $path, ?string $logFile): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Lecture impossible : {$path}");
    }

    foreach (pruneSplitSqlStatements($sql) as $statement) {
        pruneExec($pdo, $statement);
    }
}

function pruneIsBenignIndexError(PDOException $e): bool
{
    $code = (string) $e->getCode();
    if (in_array($code, ['1061', '1826'], true)) {
        return true;
    }

    return (bool) preg_match('/Duplicate key name|already exists/i', $e->getMessage());
}

if ($testConnection) {
    try {
        $pdo = createPrunePdo();
        $db = (string) ($_ENV['DB_NAME'] ?? '');
        $user = (string) ($_ENV['DB_USER'] ?? '');
        $host = (string) ($_ENV['DB_HOST'] ?? '');
        $count = pruneScalar($pdo, 'SELECT COUNT(*) FROM n3ppData');
        echo "OK connexion : {$user}@{$host}/{$db} — n3ppData={$count} lignes\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERREUR connexion : {$e->getMessage()}\n");
        exit(1);
    }
}

if (!$doConfirm && !$dryRun) {
    fwrite(STDERR, "Refus d'exécuter sans --confirm (modification BDD prod).\n");
    fwrite(STDERR, "Simulation : php tools/run-prod-prune-n3pp.php --dry-run\n");
    exit(1);
}

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
@chmod($logFile, 0600);

pruneLog('=== Élagage N3PP prod (PDO / Dotenv) ===', $logFile);

try {
    $pdo = createPrunePdo();
} catch (Throwable $e) {
    fwrite(STDERR, 'ERREUR connexion BDD : ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, "Test rapide : php tools/run-prod-prune-n3pp.php --test-connection\n");
    exit(1);
}

$dbUser = (string) ($_ENV['DB_USER'] ?? '');
$dbHost = (string) ($_ENV['DB_HOST'] ?? '');
$dbName = (string) ($_ENV['DB_NAME'] ?? '');

pruneLog("Racine : {$projectRoot}", $logFile);
pruneLog("BDD    : {$dbUser}@{$dbHost}/{$dbName}", $logFile);
pruneLog("Fenêtre INSERT : {$insertWindow} | lot DELETE : {$deleteBatch}", $logFile);

if ($dryRun) {
    pruneLog('Mode dry-run — aucune écriture', $logFile);
    $maxId = 1858087;
    $chunk = (int) ceil($maxId / $insertWindow);
    pruneLog("Phase B dry-run : {$chunk} fenêtres prévues (max id estimé {$maxId})", $logFile);
    exit(0);
}

$rowCount = pruneScalar($pdo, 'SELECT COUNT(*) FROM n3ppData');
$maxId = (int) pruneScalar($pdo, 'SELECT COALESCE(MAX(id), 0) FROM n3ppData');
pruneLog("n3ppData : {$rowCount} lignes, max(id)={$maxId}", $logFile);

pruneLog("Phase A — table {$helperTable}", $logFile);
pruneExec($pdo, "DROP TABLE IF EXISTS `{$helperTable}`");
pruneExec($pdo, "CREATE TABLE `{$helperTable}` (`id` BIGINT NOT NULL PRIMARY KEY) ENGINE=InnoDB");

if ($maxId === 0) {
    pruneLog('Aucune ligne dans n3ppData — fin.', $logFile);
    exit(0);
}

pruneLog('Phase B — collecte des doublons (LEAD / Δt ≤ 2 s)', $logFile);

$lo = 1;
$chunk = 1;
while ($lo <= $maxId) {
    $hi = min($lo + $insertWindow - 1, $maxId);
    pruneLog("  B.{$chunk} ids {$lo} .. {$hi}", $logFile);

    $insertSql = <<<SQL
INSERT IGNORE INTO `{$helperTable}` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN {$lo} AND {$hi}
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND)
SQL;
    pruneExec($pdo, $insertSql);

    $pending = pruneScalar($pdo, "SELECT COUNT(*) FROM `{$helperTable}`");
    pruneLog("      → cumul ids à supprimer : {$pending}", $logFile);

    $lo = $hi + 1;
    ++$chunk;
}

$pending = (int) pruneScalar($pdo, "SELECT COUNT(*) FROM `{$helperTable}`");
pruneLog("Doublons identifiés : {$pending}", $logFile);

if ($pending > 0) {
    pruneLog("Phase C — suppression par lots de {$deleteBatch}", $logFile);
    $iteration = 0;
    $deleteSql = <<<SQL
DELETE FROM `n3ppData`
WHERE `id` IN (
    SELECT `id` FROM (
        SELECT `id` FROM `{$helperTable}` ORDER BY `id` LIMIT {$deleteBatch}
    ) AS `batch`
);
DELETE FROM `{$helperTable}`
WHERE `id` IN (
    SELECT `id` FROM (
        SELECT `id` FROM `{$helperTable}` ORDER BY `id` LIMIT {$deleteBatch}
    ) AS `batch`
)
SQL;

    while ($pending > 0) {
        ++$iteration;
        pruneExec($pdo, $deleteSql);
        $pending = (int) pruneScalar($pdo, "SELECT COUNT(*) FROM `{$helperTable}`");
        if ($iteration % 20 === 0 || $pending === 0) {
            $rowCount = pruneScalar($pdo, 'SELECT COUNT(*) FROM n3ppData');
            pruneLog("  lot #{$iteration} — reste {$pending} ids | n3ppData={$rowCount} lignes", $logFile);
        }
    }
    pruneLog("Phase C terminée ({$iteration} lots)", $logFile);
} else {
    pruneLog('Rien à supprimer (niveau 1a) — passage au niveau 2.', $logFile);
}

pruneExec($pdo, "DROP TABLE IF EXISTS `{$helperTable}`");

pruneLog('Phase D — niveau 2 (DHT 0/0, emails aberrants)', $logFile);
$level2File = $projectRoot . '/migrations/2026_07_PROD_03c_prune_level2.sql';
if (is_file($level2File)) {
    pruneRunSqlFile($pdo, $level2File, $logFile);
} else {
    pruneExec($pdo, 'DELETE FROM `n3ppData` WHERE `TempAir` = 0 AND `Humidite` = 0');
    pruneExec($pdo, 'DELETE FROM `n3ppDataTest` WHERE `TempAir` = 0 AND `Humidite` = 0');
    pruneExec($pdo, "DELETE FROM `n3ppData` WHERE `mail` LIKE '%gmailsdfg%'");
    pruneExec($pdo, "DELETE FROM `n3ppDataTest` WHERE `mail` LIKE '%gmailsdfg%'");
}

if ($withIndexes) {
    pruneLog('Phase E — indexes (04)', $logFile);
    $idxFile = $projectRoot . '/migrations/2026_07_PROD_04_indexes.sql';
    if (is_file($idxFile)) {
        $content = file_get_contents($idxFile);
        if ($content !== false) {
            foreach (pruneSplitSqlStatements($content) as $statement) {
                if (!preg_match('/^ALTER TABLE/i', ltrim($statement))) {
                    continue;
                }
                try {
                    pruneExec($pdo, $statement);
                    pruneLog('  OK : ' . substr(preg_replace('/\s+/', ' ', $statement) ?? $statement, 0, 80) . '...', $logFile);
                } catch (PDOException $e) {
                    if (pruneIsBenignIndexError($e)) {
                        pruneLog('  Ignoré (déjà présent) : ' . substr($statement, 0, 80) . '...', $logFile);
                        continue;
                    }
                    throw $e;
                }
            }
        }
    } else {
        pruneLog('  Fichier 04 introuvable — ignoré', $logFile);
    }
}

if ($withValidate) {
    pruneLog('Phase F — validation 99_validate_prod.sql', $logFile);
    $validateFile = $projectRoot . '/migrations/99_validate_prod.sql';
    $validateOut = $logDir . '/validate-prod-' . date('Ymd-His') . '.txt';
    if (is_file($validateFile)) {
        $sql = file_get_contents($validateFile);
        $output = '';
        if ($sql !== false) {
            foreach (pruneSplitSqlStatements($sql) as $statement) {
                $stmt = $pdo->query($statement);
                if ($stmt !== false) {
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $output .= $statement . PHP_EOL;
                    foreach ($rows as $row) {
                        $output .= json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
                    }
                    $output .= PHP_EOL;
                }
            }
        }
        file_put_contents($validateOut, $output);
        pruneLog("Résultat validation : {$validateOut}", $logFile);
    }
}

$finalCount = pruneScalar($pdo, 'SELECT COUNT(*) FROM n3ppData');
pruneLog('=== Terminé ===', $logFile);
pruneLog("n3ppData final : {$finalCount} lignes", $logFile);
pruneLog("Journal : {$logFile}", $logFile);
