<?php

declare(strict_types=1);

namespace App\Controller\Gallery;

use App\Config\Env;
use App\Config\GalleryConfig;
use App\Config\Paths;
use App\Repository\GallerySyncRepository;
use App\Security\DeviceApiKeyValidator;
use App\Security\DeviceSignatureValidator;
use App\Service\GalleryTrashService;
use App\Service\LogService;
use App\Service\OperationalSettingsService;
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
        private GallerySyncRepository $syncRepository,
        private ?OperationalSettingsService $operationalSettings = null,
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
        if (!$this->isAuthorizedRequest($request, $gallery)) {
            return ResponseHelper::text($response, 'Non autorise', 401);
        }

        // Rate limiting souple (par IP) — protege contre les rafales (firmware boucle, attaque DoS legere).
        // Defaut : 10 s entre 2 uploads. 0 ou absent = desactive.
        $rateLimit = $this->operationalSettings?->int('GALLERY_UPLOAD_RATE_LIMIT_SECONDS', 10)
            ?? (int) ($_ENV['GALLERY_UPLOAD_RATE_LIMIT_SECONDS'] ?? 10);
        if ($rateLimit > 0 && !$this->checkRateLimit($request, $gallery, $rateLimit)) {
            return ResponseHelper::text($response, 'Trop de requetes — veuillez patienter', 429);
        }

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

        $imageSize = (int) $imageFile->getSize();
        if ($imageSize > self::MAX_FILE_SIZE) {
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

        $filename = $this->buildFilename($request);
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

            // La photo est arrivee : on compte la progression de la session de sync hors-ligne
            // (si l'en-tete X-Sync-Session est present), y compris pour une mise en corbeille auto.
            $this->recordSyncProgress($request, $imageSize);

            $analysis = $this->trashService->analyzeImage($targetPath);
            if ($analysis['quality'] !== 'ok') {
                $this->trashService->moveToTrash($gallery, $filename, $analysis['quality']);
                $this->logger->info("GalleryUpload [{$gallery}]: photo {$filename} envoyée en corbeille ({$analysis['reason']})");
                return ResponseHelper::textClose($response, 'Photo enregistree (corbeille auto: ' . $analysis['reason'] . '): ' . $filename, 202);
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

    /**
     * Incremente le compteur de la session de sync hors-ligne si l'upload en fait partie.
     * L'en-tete X-Sync-Session porte l'identifiant de session renvoye par /sync/start.
     * Best-effort : ne jamais faire echouer un upload pour un probleme de comptage.
     */
    private function recordSyncProgress(Request $request, int $bytes): void
    {
        $sessionHeader = trim($request->getHeaderLine('X-Sync-Session'));
        if ($sessionHeader === '' || !ctype_digit($sessionHeader)) {
            return;
        }
        try {
            $this->syncRepository->incrementReceived((int) $sessionHeader, $bytes);
        } catch (\Throwable $e) {
            $this->logger->warning('GalleryUpload: increment session de sync echoue', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Construit le nom de fichier de la photo.
     *
     * Renforcement offline : le firmware peut fournir l'heure de CAPTURE (en-tête X-Captured-At,
     * format Y-m-d_H-i-s local appareil) et un compteur monotone (en-tete X-Capture-Seq). Le
     * compteur est place EN TETE (zero-padde) pour un classement robuste meme si l'heure est
     * fausse/inconnue ; l'heure de capture (et non l'heure de reception) est utilisee pour le
     * segment date. En l'absence d'en-tetes (photo live sans SD, firmware ancien), on retombe sur
     * le format historique date-first avec l'heure de reception.
     *
     * Formats : `<seq10>_<Y-m-d_H-i-s>_<hex>.jpg`  ou (legacy) `<Y-m-d_H-i-s>_<hex>.jpg`.
     */
    private function buildFilename(Request $request): string
    {
        $rand = bin2hex(random_bytes(4));
        $dateSeg = $this->resolveCaptureDate($request);
        $seq = $this->resolveCaptureSeq($request);
        if ($seq !== null) {
            return sprintf('%010d', $seq) . '_' . $dateSeg . '_' . $rand . '.jpg';
        }

        return $dateSeg . '_' . $rand . '.jpg';
    }

    /**
     * Heure de capture fournie par le firmware (X-Captured-At, format Y-m-d_H-i-s), validee et
     * bornee a une plage plausible ; sinon heure de reception serveur.
     */
    private function resolveCaptureDate(Request $request): string
    {
        $raw = trim($request->getHeaderLine('X-Captured-At'));
        if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', $raw) === 1) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d_H-i-s', $raw);
            if ($dt !== false) {
                $year = (int) $dt->format('Y');
                if ($year >= 2020 && $year <= 2100) {
                    return $raw;
                }
            }
        }

        return date('Y-m-d_H-i-s');
    }

    /**
     * Compteur de capture monotone fourni par le firmware (X-Capture-Seq), ou null.
     */
    private function resolveCaptureSeq(Request $request): ?int
    {
        $raw = trim($request->getHeaderLine('X-Capture-Seq'));
        if ($raw === '' || ctype_digit($raw) === false) {
            return null;
        }
        $seq = (int) $raw;

        return ($seq > 0 && $seq <= 9999999999) ? $seq : null;
    }

    private function isAuthorizedRequest(Request $request, string $gallery): bool
    {
        if (!DeviceApiKeyValidator::isValidRequest($request)) {
            $this->logger->warning("GalleryUpload [{$gallery}]: cle API device absente ou invalide");
            return false;
        }

        // HMAC X-Sig-* optionnel (condensé = clé API pour le multipart). Valide => OK ;
        // absente/invalide => fallback api_key ; GALLERY_HMAC_STRICT=true => rejet.
        Env::load();
        $expectedApiKey = trim((string) ($_ENV['API_KEY'] ?? ''));
        $signatureCheck = DeviceSignatureValidator::verify($request, $expectedApiKey);
        if ($signatureCheck === false) {
            $this->logger->warning("GalleryUpload [{$gallery}]: signature HMAC invalide (X-Sig-*, mode strict)");
            return false;
        }
        if ($signatureCheck === null && trim($request->getHeaderLine('X-Sig-Hmac')) !== '') {
            $this->logger->info("GalleryUpload [{$gallery}]: HMAC invalide ou hors fenetre, fallback api_key");
        }

        return true;
    }

    /**
     * Verifie que le client (IP) n'a pas upload moins de $minIntervalSeconds avant.
     * Stockage local fichier `var/cache/ratelimit/gallery-<slug>-<ipHash>.touch` (mtime).
     * Atomique-enough pour cet usage (1 upload/10 s : pas de race condition critique).
     *
     * @return bool true si autorise, false si trop tot (rate-limit declenche).
     */
    private function checkRateLimit(Request $request, string $gallery, int $minIntervalSeconds): bool
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $fwd = $request->getHeaderLine('X-Forwarded-For');
        if ($fwd !== '') {
            // Premier element = client originel (best effort, le reverse proxy doit etre fiable).
            $first = trim(explode(',', $fwd)[0]);
            if ($first !== '') {
                $ip = $first;
            }
        }
        if (!is_string($ip) || $ip === '') {
            return true; // pas d'IP exploitable : on laisse passer (mode degrade).
        }

        $cacheDir = Paths::getProjectRoot() . '/var/cache/ratelimit';
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return true; // FS lecture seule : on n'echoue pas l'upload pour autant.
        }
        $touchFile = $cacheDir . '/gallery-' . $gallery . '-' . sha1($ip) . '.touch';
        $now = time();
        if (is_file($touchFile)) {
            $last = (int) @filemtime($touchFile);
            if ($last > 0 && ($now - $last) < $minIntervalSeconds) {
                $this->logger->warning("GalleryUpload [{$gallery}]: rate-limit hit code=429", [
                    'wait_s' => $minIntervalSeconds - ($now - $last),
                    'ip_hash' => substr(sha1($ip), 0, 8),
                ]);
                return false;
            }
        }
        @touch($touchFile);
        return true;
    }
}
