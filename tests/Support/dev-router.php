<?php

declare(strict_types=1);

/**
 * Routeur du serveur PHP intégré (`php -S`) — développement et tests uniquement.
 *
 *   php -S 127.0.0.1:8099 -t public tests/Support/dev-router.php
 *
 * Le serveur intégré considère `/.well-known/oauth-…` comme un fichier statique (le
 * chemin contient un point) et répond 404 sans passer par Slim. Ce routeur envoie au
 * front-controller toute URL qui ne correspond pas à un fichier réel de `public/`,
 * comme le fait la règle `RewriteCond !-f` du .htaccess en production.
 *
 * Refuse toute exécution hors `cli-server` (le dossier tests/ est aussi bloqué par
 * le .htaccess racine).
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit("Forbidden: routeur réservé au serveur PHP intégré.\n");
}

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
$file = realpath($docRoot . $path);
if ($path !== '/' && $file !== false && is_file($file) && str_starts_with($file, (string) realpath($docRoot))
    && !str_ends_with($file, '.php')) {
    return false; // fichier statique réel : servi tel quel
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require $docRoot . '/index.php';
