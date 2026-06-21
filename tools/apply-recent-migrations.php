#!/usr/bin/env php
<?php

/**
 * Applique les migrations SQL récentes puis bootstrap le compte admin si besoin.
 *
 * Usage :
 *   php tools/apply-recent-migrations.php
 *   php tools/apply-recent-migrations.php --dry-run
 *   php tools/apply-recent-migrations.php --skip-bootstrap
 *
 * Lit DB_* depuis .env (via App\Config\Database).
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

use App\Config\Env;
use App\Domain\User;
use App\Repository\UserRepository;
use PDO;
use PDOException;
use RuntimeException;

$dryRun = in_array('--dry-run', $argv, true);
$skipBootstrap = in_array('--skip-bootstrap', $argv, true);

/** @var list<array{path: string, label: string, requires_table: ?string}> */
$migrations = [
    [
        'path' => 'migrations/2026_06_pgl_device_event_id.sql',
        'label' => 'PGL device_event_id (v5.2.4)',
        'requires_table' => 'pglEvents',
    ],
    [
        'path' => 'tools/sql/migrate-n3-users.sql',
        'label' => 'Table n3_users (v5.3.0)',
        'requires_table' => null,
    ],
    [
        'path' => 'tools/sql/migrate-gpio117-ffp3.sql',
        'label' => 'GPIO 117 ForcePompeAquarium (v5.2.9)',
        'requires_table' => 'ffp3Outputs',
    ],
];

echo "=== Application des migrations SQL récentes ===\n";
if ($dryRun) {
    echo "(mode dry-run — aucune écriture)\n";
}
echo "\n";

Env::load();

if ($dryRun) {
    echo "Base cible (.env) : " . ($_ENV['DB_NAME'] ?? '(non défini)') . "\n\n";
    foreach ($migrations as $migration) {
        $file = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $migration['path']);
        $exists = is_file($file) ? 'OK' : 'fichier absent';
        $tableNote = $migration['requires_table'] !== null
            ? " (requiert {$migration['requires_table']})"
            : '';
        echo ">> {$migration['label']}\n";
        echo "   {$migration['path']} — {$exists}{$tableNote}\n\n";
    }
    echo "Dry-run terminé (aucune connexion BDD).\n";
    exit(0);
}

try {
    $pdo = createMigrationPdo();
    $dbName = (string) ($_ENV['DB_NAME'] ?? '');
    echo "Base cible : {$dbName}\n\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERREUR connexion BDD : {$e->getMessage()}\n");
    exit(1);
}

$hadError = false;

foreach ($migrations as $migration) {
    $file = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $migration['path']);
    $label = $migration['label'];

    if (!is_file($file)) {
        echo "[SKIP] {$label} — fichier absent : {$migration['path']}\n";
        continue;
    }

    if ($migration['requires_table'] !== null && !tableExists($pdo, $migration['requires_table'])) {
        echo "[SKIP] {$label} — table « {$migration['requires_table']} » absente\n";
        continue;
    }

    echo ">> {$label}\n";
    echo "   {$migration['path']}\n";

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "   ERREUR : lecture impossible\n\n");
        $hadError = true;
        continue;
    }

    $statements = splitSqlStatements($sql);
    foreach ($statements as $statement) {
        if (isReadOnlySqlStatement($statement)) {
            continue;
        }
        try {
            executeMigrationStatement($pdo, $statement);
        } catch (PDOException $e) {
            if (isBenignMigrationError($e)) {
                echo "   (ignoré — déjà appliqué) " . trimShort($e->getMessage()) . "\n";
                continue;
            }
            fwrite(STDERR, "   ERREUR : {$e->getMessage()}\n");
            fwrite(STDERR, "   SQL : " . trimShort($statement, 120) . "\n\n");
            $hadError = true;
            break 2;
        }
    }

    echo "   OK\n\n";
}

if ($hadError) {
    fwrite(STDERR, "Échec : corrigez les erreurs ci-dessus avant le bootstrap.\n");
    exit(1);
}

if ($skipBootstrap) {
    echo "Migrations terminées (--skip-bootstrap : bootstrap admin non exécuté).\n";
    exit(0);
}

echo ">> Bootstrap administrateur (tools/bootstrap-admin-user.php)\n\n";
exit(runBootstrap($pdo));

function createMigrationPdo(): PDO
{
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $var) {
        if (empty($_ENV[$var])) {
            throw new RuntimeException("Variable .env manquante : {$var}");
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

function executeMigrationStatement(PDO $pdo, string $statement): void
{
    $pdo->exec($statement);
}

function isReadOnlySqlStatement(string $statement): bool
{
    $normalized = ltrim($statement);
    return (bool) preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $normalized);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @return list<string>
 */
function splitSqlStatements(string $sql): array
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

function isBenignMigrationError(PDOException $e): bool
{
    $msg = $e->getMessage();
    $code = (string) $e->getCode();

    if (in_array($code, ['1060', '1061', '1050', '1826'], true)) {
        return true;
    }

    return (bool) preg_match(
        '/Duplicate column|Duplicate key name|already exists|Duplicate entry.*uq_pgl_board_event_id/i',
        $msg
    );
}

function trimShort(string $text, int $max = 80): string
{
    $oneLine = preg_replace('/\s+/', ' ', $text) ?? $text;
    return strlen($oneLine) > $max ? substr($oneLine, 0, $max - 3) . '...' : $oneLine;
}

function runBootstrap(PDO $pdo): int
{
    try {
        $repo = new UserRepository($pdo);

        if (!$repo->tableExists()) {
            fwrite(STDERR, "ERREUR : n3_users introuvable après migration.\n");
            return 1;
        }

        if (!$repo->isEmpty()) {
            $count = count($repo->findAll());
            echo "OK : {$count} utilisateur(s) déjà présents — bootstrap ignoré.\n";
            return 0;
        }

        $username = trim((string) ($_ENV['ADMIN_USERNAME'] ?? ''));
        $passwordHash = trim((string) ($_ENV['ADMIN_PASSWORD_HASH'] ?? ''));

        if ($username === '' || $passwordHash === '') {
            fwrite(STDERR, "ERREUR : ADMIN_USERNAME / ADMIN_PASSWORD_HASH manquants dans .env\n");
            return 1;
        }

        $id = $repo->create(
            username: $username,
            passwordHash: $passwordHash,
            role: User::ROLE_ADMIN,
            email: null,
            displayName: 'Administrateur',
            isActive: true,
        );

        echo "OK : compte admin « {$username} » créé (id={$id}).\n";
        echo "Gestion des utilisateurs : /admin/users\n";
        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, "ERREUR bootstrap : {$e->getMessage()}\n");
        return 1;
    }
}
