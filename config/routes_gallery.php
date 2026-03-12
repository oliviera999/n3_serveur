<?php

/**
 * Routes galeries photo (upload + consultation).
 * Compatibilité firmwares ESP32-CAM : URLs legacy conservées.
 * Inclus depuis public/index.php — variables $app en scope.
 */

use App\Controller\Gallery\GalleryUploadController;
use App\Controller\Gallery\GalleryViewController;

// Routes Galeries photo — compatibilité firmwares ESP32-CAM (upload)
// Route unifiée paramétrée (optionnelle, pour nouveaux clients)
$app->post('/gallery/{slug}/upload', [GalleryUploadController::class, 'handleBySlug']);
// Routes legacy (firmwares ESP32-CAM envoient vers ces URLs)
$app->post('/msp1gallery/upload.php', [GalleryUploadController::class, 'handleMsp1']);
$app->post('/msp1/msp1gallery/upload.php', [GalleryUploadController::class, 'handleMsp1']);
$app->post('/n3ppgallery/upload.php', [GalleryUploadController::class, 'handleN3pp']);
$app->post('/n3pp/n3ppgallery/upload.php', [GalleryUploadController::class, 'handleN3pp']);
$app->post('/ffp3/ffp3gallery/upload.php', [GalleryUploadController::class, 'handleFfp3']);
$app->post('/ffp3gallery/upload.php', [GalleryUploadController::class, 'handleFfp3']);

// Galeries photo — pages de consultation
// /gallery : redirection vers galerie MSP1 (index en 500 sur prod — correctif temporaire)
$basePath = $basePath ?? ($app->getBasePath() ?: '');
$app->get('/gallery', fn($rq, $rs) => $rs->withHeader('Location', $basePath . '/gallery/msp1')->withStatus(302));
$app->get('/gallery/', fn($rq, $rs) => $rs->withHeader('Location', $basePath . '/gallery/msp1')->withStatus(302));
$app->get('/gallery/{slug}/files/{filename}', [GalleryViewController::class, 'serveImage']);
$app->get('/gallery/{slug}/timelapse', [GalleryViewController::class, 'showTimelapse']);
$app->get('/api/gallery/{slug}/photos', [GalleryViewController::class, 'listPhotos']);
$app->get('/gallery/msp1', [GalleryViewController::class, 'showMsp1']);
$app->get('/gallery/n3pp', [GalleryViewController::class, 'showN3pp']);
$app->get('/gallery/ffp3', [GalleryViewController::class, 'showFfp3']);
