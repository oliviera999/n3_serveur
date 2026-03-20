<?php

declare(strict_types=1);

namespace App\Controller\Gallery;

use App\Config\GalleryConfig;
use App\Config\Paths;
use App\Service\GalleryTrashService;
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
        private GalleryTrashService $trashService,
    ) {
    }

    /**
     * Handler unifié paramétré par slug. Utilisé par les routes legacy et par /gallery/{slug}/upload si besoin.
     */
    public function handleBySlug(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        if (!GalleryConfig::isValidSlug($slug)) {
            return ResponseHelper::text($response, 'Galerie invalide', 400);
        }
        $dir = GalleryConfig::resolveUploadDir($slug);
        return $this->processUpload($request, $response, $dir, $slug);
    }

    public function handleMsp1(Request $request, Response $response): Response
    {
        return $this->handleBySlug($request, $response, ['slug' => 'msp1']);
    }

    public function handleN3pp(Request $request, Response $response): Response
    {
        return $this->handleBySlug($request, $response, ['slug' => 'n3pp']);
    }

    public function handleFfp3(Request $request, Response $response): Response
    {
        return $this->handleBySlug($request, $response, ['slug' => 'ffp3']);
    }

    private function processUpload(Request $request, Response $response, string $uploadDir, string $gallery): Response
    {
        $uploadedFiles = $request->getUploadedFiles();
        $imageFile = $uploadedFiles['imageFile'] ?? null;
        $contentType = $request->getHeaderLine('Content-Type');

        if ($imageFile === null || $imageFile->getError() !== UPLOAD_ERR_OK) {
            $fileKeys = array_keys($uploadedFiles);
            $errorCode = $imageFile !== null ? $imageFile->getError() : null;
            $errorMsg = $this->uploadErrorMessage($errorCode);
            $this->logger->warning("GalleryUpload [{$gallery}]: aucun fichier ou erreur upload", [
                'content_type' => $contentType,
                'uploaded_keys' => $fileKeys,
                'upload_error' => $errorCode,
                'upload_error_msg' => $errorMsg,
            ]);
            $body = $errorMsg ?? 'Aucun fichier recu (champ imageFile attendu, recu: ' . implode(', ', $fileKeys ?: ['aucun']) . ')';
            return ResponseHelper::text($response, $body, 400);
        }

        if ($imageFile->getSize() > self::MAX_FILE_SIZE) {
            return ResponseHelper::text($response, 'Fichier trop volumineux', 413);
        }

        $clientType = $imageFile->getClientMediaType();
        if (!in_array($clientType, self::ALLOWED_TYPES, true)) {
            return ResponseHelper::text($response, 'Type de fichier non autorise', 415);
        }

        // Chemin absolu depuis la racine du projet (config centralisée)
        $baseDir = Paths::getProjectRoot();
        $targetDir = $baseDir . '/' . rtrim($uploadDir, '/');

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        $targetPath = $targetDir . '/' . $filename;

        try {
            $imageFile->moveTo($targetPath);

            // Vérification du contenu réel (magic bytes) après écriture
            $imageInfo = @getimagesize($targetPath);
            if ($imageInfo === false || !in_array($imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_JPEG2000], true)) {
                @unlink($targetPath);
                $this->logger->warning("GalleryUpload [{$gallery}]: fichier rejete (contenu non-JPEG)", [
                    'client_type' => $clientType,
                    'detected_type' => $imageInfo[2] ?? 'inconnu',
                ]);
                return ResponseHelper::text($response, 'Contenu du fichier non valide (JPEG attendu)', 415);
            }

            $analysis = $this->trashService->analyzeImage($targetPath);
            if ($analysis['quality'] !== 'ok') {
                $this->trashService->moveToTrash($gallery, $filename, $analysis['quality']);
                $this->logger->info("GalleryUpload [{$gallery}]: photo {$filename} envoyée en corbeille ({$analysis['reason']})");
                return ResponseHelper::textClose($response, 'Photo enregistree (corbeille auto: ' . $analysis['reason'] . '): ' . $filename, 200);
            }

            $this->logger->info("GalleryUpload [{$gallery}]: photo enregistree {$filename}");
            return ResponseHelper::textClose($response, 'Photo enregistree: ' . $filename, 200);
        } catch (\Throwable $e) {
            $this->logger->error("GalleryUpload [{$gallery}]: erreur", ['error' => $e->getMessage()]);
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }

    /**
     * Message lisible pour les codes d'erreur PHP UPLOAD_ERR_*.
     * Aide au diagnostic quand les photos ne sont pas recues (upload_max_filesize, post_max_size, etc.).
     */
    private function uploadErrorMessage(?int $code): ?string
    {
        if ($code === null) {
            return null;
        }
        return match ($code) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE => 'Fichier trop volumineux (upload_max_filesize PHP)',
            UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux (limite formulaire)',
            UPLOAD_ERR_PARTIAL => 'Upload partiel (interruption reseau?)',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier envoye (verifier champ imageFile et Content-Type multipart)',
            UPLOAD_ERR_NO_TMP_DIR => 'Erreur serveur: repertoire temporaire manquant',
            UPLOAD_ERR_CANT_WRITE => 'Erreur serveur: impossible d ecrire le fichier',
            UPLOAD_ERR_EXTENSION => 'Erreur serveur: extension PHP a bloque l upload',
            default => 'Erreur upload inconnue (code ' . $code . ')',
        };
    }
}
