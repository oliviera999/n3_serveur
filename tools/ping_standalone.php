<?php
/**
 * Endpoint minimal pour test de latence (diagnostic).
 * À déployer sur n3.olution.info (ou tout autre sous-domaine) pour comparer
 * le RTT avec iot.olution.info/ffp3/ping.
 *
 * Usage : copier ce fichier à la racine web de n3 (ex. public/ping.php),
 * puis appeler GET ou POST https://n3.olution.info/ping.php
 *
 * Réponse : "OK" avec Content-Length et Connection: close (pas de BDD, pas de log).
 */
header('Content-Type: text/plain; charset=utf-8');
header('Content-Length: 2');
header('Connection: close');
echo 'OK';
