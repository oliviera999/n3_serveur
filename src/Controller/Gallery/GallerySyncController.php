<?php

declare(strict_types=1);

namespace App\Controller\Gallery;

use App\Notification\NotificationFamily;
use App\Notification\Severity;
use App\Repository\GalleryControlRepository;
use App\Repository\GallerySyncRepository;
use App\Security\AuthService;
use App\Security\DeviceApiKeyValidator;
use App\Security\DeviceSignatureValidator;
use App\Service\LogService;
use App\Service\NotificationService;
use App\Util\RequestHelper;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Synchronisation hors-ligne des galeries photo (uploadphotosserver : msp1/n3pp/ffp3).
 *
 * Contrat firmware <-> serveur :
 *   POST .../sync/start  (device api_key)   : ouvre une session, annonce `total` photos en attente
 *   POST upload.php      (en-tete X-Sync-Session)  : chaque photo incremente le compteur recu
 *   POST .../sync/finish (device api_key)   : cloture, declenche le mail recapitulatif
 *   GET  .../sync/status (auth utilisateur) : etat pour l'indicateur + la jauge (poll navigateur)
 */
class GallerySyncController
{
    public function __construct(
        private readonly GallerySyncRepository $syncRepository,
        private readonly GalleryControlRepository $controlRepository,
        private readonly NotificationService $notifications,
        private readonly AuthService $authService,
        private readonly LogService $logger,
    ) {
    }

    // ------------------------------------------------------------------
    // Routes unifiees (/gallery/{slug}/api/sync/...)
    // ------------------------------------------------------------------

    public function startBySlug(Request $request, Response $response, array $args): Response
    {
        return $this->startInternal($request, $response, (string) ($args['slug'] ?? ''));
    }

    public function finishBySlug(Request $request, Response $response, array $args): Response
    {
        return $this->finishInternal($request, $response, (string) ($args['slug'] ?? ''));
    }

    public function statusBySlug(Request $request, Response $response, array $args): Response
    {
        return $this->statusInternal($request, $response, (string) ($args['slug'] ?? ''));
    }

    // ------------------------------------------------------------------
    // Alias legacy par cible (URLs fixes appelees par les firmwares)
    // ------------------------------------------------------------------

    public function startMsp1(Request $request, Response $response): Response
    {
        return $this->startInternal($request, $response, 'msp1');
    }

    public function startN3pp(Request $request, Response $response): Response
    {
        return $this->startInternal($request, $response, 'n3pp');
    }

    public function startFfp3(Request $request, Response $response): Response
    {
        return $this->startInternal($request, $response, 'ffp3');
    }

    public function finishMsp1(Request $request, Response $response): Response
    {
        return $this->finishInternal($request, $response, 'msp1');
    }

    public function finishN3pp(Request $request, Response $response): Response
    {
        return $this->finishInternal($request, $response, 'n3pp');
    }

    public function finishFfp3(Request $request, Response $response): Response
    {
        return $this->finishInternal($request, $response, 'ffp3');
    }

    // ------------------------------------------------------------------
    // Implementations
    // ------------------------------------------------------------------

    private function startInternal(Request $request, Response $response, string $slug): Response
    {
        if (!$this->isAllowedSlug($slug)) {
            return ResponseHelper::json($response, ['error' => 'Galerie inconnue'], 404);
        }
        $authError = $this->requireDeviceApiKey($request, $response);
        if ($authError !== null) {
            return $authError;
        }

        $params = RequestHelper::extractParams($request);
        $module = $this->controlRepository->getModule($slug);

        $boardError = $this->validateBoard($response, $module['board'], $params);
        if ($boardError !== null) {
            return $boardError;
        }

        $total = RequestHelper::getInt($params, 'total', 0);
        $deviceSession = RequestHelper::getString($params, 'device_session', RequestHelper::getString($params, 'session', ''));
        $firmware = RequestHelper::getString($params, 'version', RequestHelper::getString($params, 'firmware', ''));
        if ($deviceSession === '') {
            return ResponseHelper::json($response, ['error' => 'device_session manquant'], 400);
        }

        try {
            $session = $this->syncRepository->startSession($slug, $module['board'], $deviceSession, $total, $firmware);
            $this->logger->info('GallerySync [' . $slug . ']: session de sync ouverte', [
                'session' => $session['id'],
                'total' => $session['total'],
                'firmware' => $firmware,
            ]);

            return ResponseHelper::json($response, [
                'success' => true,
                'session' => $session['id'],
                'total' => $session['total'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('GallerySync: erreur start — {msg}', ['msg' => $e->getMessage(), 'slug' => $slug]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    private function finishInternal(Request $request, Response $response, string $slug): Response
    {
        if (!$this->isAllowedSlug($slug)) {
            return ResponseHelper::json($response, ['error' => 'Galerie inconnue'], 404);
        }
        $authError = $this->requireDeviceApiKey($request, $response);
        if ($authError !== null) {
            return $authError;
        }

        $params = RequestHelper::extractParams($request);
        $sessionId = RequestHelper::getInt($params, 'session', 0);
        if ($sessionId <= 0) {
            return ResponseHelper::json($response, ['error' => 'session manquante'], 400);
        }

        $sent = array_key_exists('sent', $params) ? RequestHelper::getInt($params, 'sent', 0) : null;
        $failed = array_key_exists('failed', $params) ? RequestHelper::getInt($params, 'failed', 0) : null;
        $bytes = array_key_exists('bytes', $params) ? RequestHelper::getInt($params, 'bytes', 0) : null;
        $statusParam = RequestHelper::getString($params, 'status', 'completed');
        $status = $statusParam === 'aborted' ? 'aborted' : 'completed';
        // A2 (audit 2026-07-05) : le firmware annonce le VRAI backlog comme `total` et pose `final=1`
        // uniquement quand le backlog est réellement vidé après le drain. On ne déclenche donc le mail
        // récapitulatif que sur une clôture finale — sinon un gros backlog drainé en plusieurs réveils
        // générait un récap par réveil. `final` absent (firmware ancien) => on retombe sur received>=total.
        $final = array_key_exists('final', $params) && RequestHelper::getInt($params, 'final', 0) === 1;

        try {
            $finished = $this->syncRepository->finishSession($sessionId, $sent, $failed, $bytes, $status);
            if ($finished === null) {
                return ResponseHelper::json($response, ['error' => 'Session inconnue'], 404);
            }
            // Securite : la session doit appartenir au slug cible.
            if (($finished['slug'] ?? '') !== $slug) {
                return ResponseHelper::json($response, ['error' => 'Session incompatible avec la galerie'], 400);
            }

            $reportTotal = (int) ($finished['total'] ?? 0);
            $reportReceived = (int) ($finished['received'] ?? 0);
            if ($final || ($reportTotal > 0 && $reportReceived >= $reportTotal)) {
                $this->sendTransferReport($slug, $finished);
            } else {
                $this->logger->info('GallerySync [' . $slug . ']: clôture non finale, récap différé', [
                    'session' => $sessionId,
                    'received' => $reportReceived,
                    'total' => $reportTotal,
                ]);
            }

            return ResponseHelper::json($response, [
                'success' => true,
                'session' => $sessionId,
                'received' => (int) ($finished['received'] ?? 0),
                'total' => (int) ($finished['total'] ?? 0),
                'failed' => (int) ($finished['failed'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('GallerySync: erreur finish — {msg}', ['msg' => $e->getMessage(), 'slug' => $slug]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    private function statusInternal(Request $request, Response $response, string $slug): Response
    {
        if (!$this->isAllowedSlug($slug)) {
            return ResponseHelper::json($response, ['error' => 'Galerie inconnue'], 404);
        }
        $authError = $this->requireAuth($request, $response);
        if ($authError !== null) {
            return $authError;
        }

        try {
            return ResponseHelper::json($response, $this->syncRepository->getStatus($slug));
        } catch (\Throwable $e) {
            $this->logger->error('GallerySync: erreur status — {msg}', ['msg' => $e->getMessage(), 'slug' => $slug]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    /**
     * @param array<string, mixed> $session Ligne de session cloturee
     */
    private function sendTransferReport(string $slug, array $session): void
    {
        $module = $this->controlRepository->getModule($slug);
        $label = $module['label'];
        $family = NotificationFamily::fromGallerySlug($slug);
        $familyValue = $family !== null ? $family->value : 'GALLERY_' . strtoupper($slug);

        $total = (int) ($session['total'] ?? 0);
        $received = (int) ($session['received'] ?? 0);
        $failed = (int) ($session['failed'] ?? 0);
        $bytes = (int) ($session['bytes_received'] ?? 0);
        $aborted = ($session['status'] ?? '') === 'aborted';
        $durationSeconds = $this->durationSeconds(
            isset($session['started_at']) ? (string) $session['started_at'] : null,
            isset($session['finished_at']) ? (string) $session['finished_at'] : null
        );

        // Severite : alerte si echecs ou transfert incomplet/avorte, sinon info.
        $incomplete = $aborted || $failed > 0 || ($total > 0 && $received < $total);
        $severity = $incomplete ? Severity::Alert : Severity::Info;

        $subject = sprintf('Transfert photos %s : %d/%d', $label, $received, max($total, $received));
        if ($aborted) {
            $subject .= ' (interrompu)';
        }

        $lines = [
            sprintf('Galerie : %s (%s)', $label, $slug),
            sprintf('Statut : %s', $aborted ? 'interrompu' : 'terminé'),
            sprintf('Photos transférées : %d', $received),
            sprintf('Total annoncé en attente : %d', $total),
            sprintf('Échecs rapportés : %d', $failed),
            sprintf('Volume reçu : %s', $this->humanBytes($bytes)),
            sprintf('Durée du transfert : %s', $this->humanDuration($durationSeconds)),
        ];
        if (($session['firmware_version'] ?? '') !== '') {
            $lines[] = sprintf('Firmware : %s', (string) $session['firmware_version']);
        }
        if (isset($session['finished_at'])) {
            $lines[] = sprintf('Clôturé le : %s', (string) $session['finished_at']);
        }
        $message = implode("\n", $lines);

        $throttleKey = sprintf('gallery:%s:sync:%d', $slug, (int) ($session['id'] ?? 0));
        $this->notifications->sendGalleryTransferReport($severity, $familyValue, $subject, $message, $throttleKey);
    }

    private function durationSeconds(?string $start, ?string $end): int
    {
        if ($start === null || $end === null || $start === '' || $end === '') {
            return 0;
        }
        $a = strtotime($start);
        $b = strtotime($end);
        if ($a === false || $b === false || $b < $a) {
            return 0;
        }
        return $b - $a;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 o';
        }
        $units = ['o', 'Ko', 'Mo', 'Go'];
        $i = (int) floor(log($bytes, 1024));
        $i = max(0, min($i, count($units) - 1));
        $value = $bytes / (1024 ** $i);
        return ($i === 0 ? (string) $bytes : number_format($value, 1, ',', ' ')) . ' ' . $units[$i];
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0 s';
        }
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;
        if ($minutes < 60) {
            return $rest > 0 ? sprintf('%d min %d s', $minutes, $rest) : sprintf('%d min', $minutes);
        }
        $hours = intdiv($minutes, 60);
        $minutes %= 60;
        return sprintf('%d h %d min', $hours, $minutes);
    }

    private function isAllowedSlug(string $slug): bool
    {
        return in_array($slug, ['msp1', 'n3pp', 'ffp3'], true);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function validateBoard(Response $response, int $expectedBoard, array $params): ?Response
    {
        if (!array_key_exists('board', $params)) {
            return null;
        }
        $boardRaw = $params['board'];
        if (!is_scalar($boardRaw) || !is_numeric((string) $boardRaw)) {
            return ResponseHelper::json($response, ['error' => 'Parametre board invalide'], 400);
        }
        if ((int) $boardRaw !== $expectedBoard) {
            return ResponseHelper::json($response, ['error' => 'Board incompatible avec la galerie cible'], 400);
        }
        return null;
    }

    private function requireDeviceApiKey(Request $request, Response $response): ?Response
    {
        $params = array_merge($request->getQueryParams(), RequestHelper::extractParams($request));
        if (!DeviceApiKeyValidator::isValidRequest($request, $params)) {
            $this->logger->warning('GallerySync: rejet auth device api_key', [
                'path' => $request->getUri()->getPath(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
            ]);

            return ResponseHelper::json($response, ['error' => 'Cle API invalide'], 401);
        }

        // A4 : signature HMAC additive sur le corps form-urlencoded (X-Sig-*). Présente => doit être
        // valide ; absente => on reste sur la clé API (rétro-compatible).
        $signatureCheck = DeviceSignatureValidator::verify($request, DeviceSignatureValidator::rawBody($request));
        if ($signatureCheck === false) {
            $this->logger->warning('GallerySync: rejet signature HMAC invalide (X-Sig-*)', [
                'path' => $request->getUri()->getPath(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
            ]);

            return ResponseHelper::json($response, ['error' => 'Signature invalide'], 401);
        }

        return null;
    }

    private function requireAuth(Request $request, Response $response): ?Response
    {
        $isAuth = $this->authService->isAuthenticated()
            || $this->authService->isAuthenticatedByToken($request->getQueryParams());
        if ($isAuth) {
            return null;
        }

        return ResponseHelper::json($response, ['error' => 'Authentification requise'], 401);
    }
}
