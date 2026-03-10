<?php
/**
 * Délégation vers l'application principale (serveur unifié).
 *
 * Sur production, les requêtes /ffp3/* peuvent être routées ici (Alias Apache)
 * au lieu de la racine. Ce fichier délègue au front-controller principal pour
 * utiliser le vendor/ et config/ de la racine, évitant l'erreur
 * "platform_check.php: No such file or directory" du vendor ffp3/ obsolète.
 *
 * Les routes /ffp3/api/outputs3-test/state et autres sont définies dans
 * le serveur principal (public/index.php).
 */
require __DIR__ . '/../../public/index.php';
