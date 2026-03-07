<?php
/**
 * Point d'entree racine - Redirige vers public/index.php
 *
 * Si le DocumentRoot pointe vers serveur/ plutot que serveur/public/,
 * ce fichier assure le routage vers le front-controller Slim 4.
 */

require __DIR__ . '/public/index.php';
