<?php

declare(strict_types=1);

namespace App\Controller\Gallery;

use App\Service\LogService;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Reception des photos envoyees par les firmwares ESP32-CAM.
 * Compatibilite totale avec le contrat d'interface existant :
 *   POST /msp1gallery/upload.php  (multipart/form-data, champ imageFile)
 *   POST /n3ppgallery/upload.php  (idem)
 *   POST /ffp3/ffp3gallery/upload.php (idem)
 *
 * Les firmwares envoient un JPEG dans le champ "imageFile" avec filename="esp32-cam.jpg".
 */
class GalleryUploadController
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 Mo
    private const ALLOWED_TYPES = ['image/jpeg', 'image/jpg'];

    public function __construct(
        private LogService $logger,
    ) {
    }

    public function handleMsp1(Request $request, Response $response): Response
    {
        $dir = $_ENV['GALLERY_MSP1_DIR'] ?? 'uploads/msp1';
        return $this->processUpload($request, $response, $dir, 'msp1');
    }

    public function handleN3pp(Request $request, Response $response): Response
    {
        $dir = $_ENV['GALLERY_N3PP_DIR'] ?? 'uploads/n3pp';
        return $this->processUpload($request, $response, $dir, 'n3pp');
    }

    public function handleFfp3(Request $request, Response $response): Response
    {
        $dir = $_ENV['GALLERY_FFP3_DIR'] ?? 'uploads/ffp3';
        return $this->processUpload($request, $response, $dir, 'ffp3');
    }

    private function processUpload(Request $request, Response $response, string $uploadDir, string $gallery): Response
    {
        $uploadedFiles = $request->getUploadedFiles();
        $imageFile = $uploadedFiles['imageFile'] ?? null;

        if ($imageFile === null || $imageFile->getError() !== UPLOAD_ERR_OK) {
            $this->logger->warning("GalleryUpload [{$gallery}]: aucun fichier ou erreur upload");
            return ResponseHelper::text($response, 'Aucun fichier recu', 400);
        }

        if ($imageFile->getSize() > self::MAX_FILE_SIZE) {
            return ResponseHelper::text($response, 'Fichier trop volumineux', 413);
        }

        $clientType = $imageFile->getClientMediaType();
        if (!in_array($clientType, self::ALLOWED_TYPES, true)) {
            return ResponseHelper::text($response, 'Type de fichier non autorise', 415);
        }

        // Chemin absolu depuis la racine du projet
        $baseDir = dirname(__DIR__, 3);
        $targetDir = $baseDir . '/' . rtrim($uploadDir, '/');

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        $targetPath = $targetDir . '/' . $filename;

        try {
            $imageFile->moveTo($targetPath);
            $this->logger->info("GalleryUpload [{$gallery}]: photo enregistree {$filename}");
            return ResponseHelper::textClose($response, 'Photo enregistree: ' . $filename, 200);
        } catch (\Throwable $e) {
            $this->logger->error("GalleryUpload [{$gallery}]: erreur", ['error' => $e->getMessage()]);
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }
}
