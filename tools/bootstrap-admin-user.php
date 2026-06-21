#!/usr/bin/env php
<?php

/**
 * Bootstrap du premier administrateur en BDD depuis le .env.
 *
 * Usage : php tools/bootstrap-admin-user.php
 *
 * Crée un compte admin si la table n3_users est vide, en utilisant
 * ADMIN_USERNAME et ADMIN_PASSWORD_HASH du fichier .env.
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

use App\Config\Database;
use App\Config\Env;
use App\Domain\User;
use App\Repository\UserRepository;

Env::load();

echo "=== Bootstrap administrateur n3_users ===\n\n";

try {
    $pdo = Database::getConnection();
    $repo = new UserRepository($pdo);

    if (!$repo->tableExists()) {
        echo "ERREUR : la table n3_users n'existe pas.\n";
        echo "Exécutez d'abord tools/sql/migrate-n3-users.sql\n";
        exit(1);
    }

    if (!$repo->isEmpty()) {
        $count = count($repo->findAll());
        echo "OK : la table contient déjà {$count} utilisateur(s). Rien à faire.\n";
        exit(0);
    }

    $username = trim((string) ($_ENV['ADMIN_USERNAME'] ?? ''));
    $passwordHash = trim((string) ($_ENV['ADMIN_PASSWORD_HASH'] ?? ''));

    if ($username === '' || $passwordHash === '') {
        echo "ERREUR : ADMIN_USERNAME et ADMIN_PASSWORD_HASH doivent être définis dans .env\n";
        exit(1);
    }

    $id = $repo->create(
        username: $username,
        passwordHash: $passwordHash,
        role: User::ROLE_ADMIN,
        email: null,
        displayName: 'Administrateur',
        isActive: true,
    );

    echo "OK : compte admin « {$username} » créé (id={$id}) depuis le .env.\n";
    echo "Vous pouvez maintenant vous connecter et gérer les utilisateurs via /admin/users\n";
    exit(0);
} catch (Throwable $e) {
    echo "ERREUR : " . $e->getMessage() . "\n";
    exit(1);
}
