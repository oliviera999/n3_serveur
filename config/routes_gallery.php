<?php

/**
 * Routes galeries photo (upload + consultation).
 * Compatibilité firmwares ESP32-CAM : URLs legacy conservées.
 * Inclus depuis public/index.php — variables $app en scope.
 */

use App\Controller\Gallery\GalleryControlController;
use App\Controller\Gallery\GallerySyncController;
use App\Controller\Gallery\GalleryTrashController;
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
$app->get('/gallery', [GalleryViewController::class, 'showIndex']);
$app->get('/gallery/', [GalleryViewController::class, 'showIndex']);
$app->get('/gallery/{slug}/files/{filename}', [GalleryViewController::class, 'serveImage']);
$app->get('/gallery/{slug}/timelapse', [GalleryViewController::class, 'showTimelapse']);
$app->get('/api/gallery/{slug}/photos', [GalleryViewController::class, 'listPhotos']);
$app->get('/api/gallery/{slug}/latest', [GalleryViewController::class, 'latestPhoto']);
$app->get('/gallery/msp1', [GalleryViewController::class, 'showMsp1']);
$app->get('/gallery/n3pp', [GalleryViewController::class, 'showN3pp']);
$app->get('/gallery/ffp3', [GalleryViewController::class, 'showFfp3']);

// Controle distant cameras (uploadphotosserver) — routes REST unifiees
$app->get('/gallery/{slug}/control', [GalleryControlController::class, 'showControlPage']);
$app->get('/gallery/{slug}/api/outputs/state', [GalleryControlController::class, 'getStateBySlug']);
$app->map(['GET', 'POST'], '/gallery/{slug}/api/outputs/toggle', [GalleryControlController::class, 'toggleOutputBySlug']);
$app->post('/gallery/{slug}/api/outputs/parameters', [GalleryControlController::class, 'updateParametersBySlug']);
$app->post('/gallery/{slug}/api/outputs/notification-policy', [GalleryControlController::class, 'saveNotificationPolicyBySlug']);
$app->post('/gallery/{slug}/api/firmware/version', [GalleryControlController::class, 'postFirmwareVersionBySlug']);

// Controle distant cameras — alias legacy .php (3 envs)
$app->get('/msp1gallery/uploadphotoserver-outputs-action.php', [GalleryControlController::class, 'getMsp1State']);
$app->get('/msp1/msp1gallery/uploadphotoserver-outputs-action.php', [GalleryControlController::class, 'getMsp1State']);
$app->post('/msp1gallery/post-uploadphotoserver-version.php', [GalleryControlController::class, 'postMsp1FirmwareVersion']);
$app->post('/msp1/msp1gallery/post-uploadphotoserver-version.php', [GalleryControlController::class, 'postMsp1FirmwareVersion']);

$app->get('/n3ppgallery/uploadphotoserver-outputs-action.php', [GalleryControlController::class, 'getN3ppState']);
$app->get('/n3pp/n3ppgallery/uploadphotoserver-outputs-action.php', [GalleryControlController::class, 'getN3ppState']);
$app->post('/n3ppgallery/post-uploadphotoserver-version.php', [GalleryControlController::class, 'postN3ppFirmwareVersion']);
$app->post('/n3pp/n3ppgallery/post-uploadphotoserver-version.php', [GalleryControlController::class, 'postN3ppFirmwareVersion']);

$app->get('/ffp3gallery/uploadphotoserver-outputs-action.php', [GalleryControlController::class, 'getFfp3State']);
$app->get('/ffp3/ffp3gallery/uploadphotoserver-outputs-action.php', [GalleryControlController::class, 'getFfp3State']);
$app->post('/ffp3gallery/post-uploadphotoserver-version.php', [GalleryControlController::class, 'postFfp3FirmwareVersion']);
$app->post('/ffp3/ffp3gallery/post-uploadphotoserver-version.php', [GalleryControlController::class, 'postFfp3FirmwareVersion']);

// Synchronisation hors-ligne des galeries (uploadphotosserver) — routes REST unifiees
// start/finish : auth device api_key (firmware) ; status : auth utilisateur (page de controle).
$app->post('/gallery/{slug}/api/sync/start', [GallerySyncController::class, 'startBySlug']);
$app->post('/gallery/{slug}/api/sync/finish', [GallerySyncController::class, 'finishBySlug']);
$app->get('/gallery/{slug}/api/sync/status', [GallerySyncController::class, 'statusBySlug']);

// Synchronisation hors-ligne — alias legacy .php (URLs fixes par cible appelees par les firmwares)
$app->post('/msp1gallery/uploadphotoserver-sync-start.php', [GallerySyncController::class, 'startMsp1']);
$app->post('/msp1/msp1gallery/uploadphotoserver-sync-start.php', [GallerySyncController::class, 'startMsp1']);
$app->post('/msp1gallery/uploadphotoserver-sync-finish.php', [GallerySyncController::class, 'finishMsp1']);
$app->post('/msp1/msp1gallery/uploadphotoserver-sync-finish.php', [GallerySyncController::class, 'finishMsp1']);

$app->post('/n3ppgallery/uploadphotoserver-sync-start.php', [GallerySyncController::class, 'startN3pp']);
$app->post('/n3pp/n3ppgallery/uploadphotoserver-sync-start.php', [GallerySyncController::class, 'startN3pp']);
$app->post('/n3ppgallery/uploadphotoserver-sync-finish.php', [GallerySyncController::class, 'finishN3pp']);
$app->post('/n3pp/n3ppgallery/uploadphotoserver-sync-finish.php', [GallerySyncController::class, 'finishN3pp']);

$app->post('/ffp3gallery/uploadphotoserver-sync-start.php', [GallerySyncController::class, 'startFfp3']);
$app->post('/ffp3/ffp3gallery/uploadphotoserver-sync-start.php', [GallerySyncController::class, 'startFfp3']);
$app->post('/ffp3gallery/uploadphotoserver-sync-finish.php', [GallerySyncController::class, 'finishFfp3']);
$app->post('/ffp3/ffp3gallery/uploadphotoserver-sync-finish.php', [GallerySyncController::class, 'finishFfp3']);

// Galerie photo (grille paginée) — accès admin uniquement (/admin/ protégé par middleware)
$app->get('/admin/gallery/{slug}', [GalleryViewController::class, 'showGalleryAdmin']);

// Corbeille galerie — accès admin uniquement
$app->get('/admin/gallery-trash', [GalleryTrashController::class, 'showTrashIndex']);
$app->get('/admin/gallery/{slug}/trash', [GalleryTrashController::class, 'showTrash']);
$app->get('/admin/gallery/{slug}/trash/files/{filename}', [GalleryTrashController::class, 'serveTrashImage']);
$app->post('/admin/api/gallery/auto-sort-all', [GalleryTrashController::class, 'apiAutoSortAll']);
$app->post('/admin/api/gallery/{slug}/move-to-trash', [GalleryTrashController::class, 'apiMoveToTrash']);
$app->post('/admin/api/gallery/{slug}/restore', [GalleryTrashController::class, 'apiRestore']);
$app->post('/admin/api/gallery/{slug}/purge', [GalleryTrashController::class, 'apiPurge']);
$app->post('/admin/api/gallery/{slug}/purge-all', [GalleryTrashController::class, 'apiPurgeAll']);
