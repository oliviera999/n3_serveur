<?php

declare(strict_types=1);

namespace App\Service;

use App\Security\AuthService;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Journalisation des actions de contrôle (audit trail) — AUDIT_PAGE_CONTROL_DISTANT Phase 2.
 *
 * Chaque écriture du panneau de contrôle (toggle, set, mise à jour de paramètre,
 * déclenchement OTA) produit une entrée structurée répondant aux questions :
 *   - QUI    : utilisateur de session si disponible, sinon « token » (auth par jeton),
 *              sinon « anonymous ».
 *   - QUOI   : module, action, cible (gpio / nom de paramètre), valeur ou état appliqué.
 *   - QUAND  : horodatage ajouté par {@see LogService} (LineFormatter).
 *   - OÙ     : IP cliente (extraite des server params PSR-7 ; masquée par LogService si RGPD).
 *   - RÉSULTAT : succès / échec.
 *
 * Le préfixe `[control-audit]` permet un filtrage trivial du cronlog.
 */
class ControlAuditLogger
{
    private const PREFIX = '[control-audit]';

    public function __construct(
        private LogService $logger,
        private AuthService $authService,
    ) {
    }

    /**
     * Émet une entrée d'audit pour une action de contrôle.
     *
     * @param Request              $request Requête PSR-7 (identité + IP)
     * @param string               $module  Module concerné (ffp3, msp, n3pp)
     * @param string               $action  Action effectuée (toggle, set, update_parameter, trigger_ota_check)
     * @param array<string, mixed> $target  Cible : ex. ['gpio' => 16], ['param' => 'aqThr'], ['id' => 12]
     * @param bool                 $success Succès (true) ou échec (false) de l'action
     * @param string|null          $reason  Détail optionnel (raison du rejet, message d'erreur)
     */
    public function logAction(
        Request $request,
        string $module,
        string $action,
        array $target,
        bool $success,
        ?string $reason = null
    ): void {
        $context = [
            'who' => $this->resolveIdentity($request),
            'ip' => $this->resolveClientIp($request),
            'module' => $module,
            'action' => $action,
            'result' => $success ? 'success' : 'failure',
        ];

        foreach ($target as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $context[$key] = $value;
            } else {
                $context[$key] = json_encode($value);
            }
        }

        if ($reason !== null && $reason !== '') {
            $context['reason'] = $reason;
        }

        $message = sprintf(
            '%s who={who} ip={ip} module={module} action={action} result={result}',
            self::PREFIX
        );

        if ($success) {
            $this->logger->info($message, $context);
        } else {
            $this->logger->warning($message, $context);
        }
    }

    /**
     * Détermine l'identité de l'auteur de l'action :
     *  - utilisateur de session si une session authentifiée existe ;
     *  - sinon « token » si un jeton (cookie ou query param) est présent ;
     *  - sinon « anonymous ».
     */
    private function resolveIdentity(Request $request): string
    {
        $user = $this->authService->getCurrentUser();
        if ($user !== null && $user !== '') {
            return $user;
        }

        if ($this->authService->isAuthenticatedByToken($request->getQueryParams())) {
            return 'token';
        }

        return 'anonymous';
    }

    /**
     * Extrait l'IP cliente depuis les server params PSR-7.
     * Tient compte d'un éventuel reverse proxy (X-Forwarded-For : première IP de la liste).
     */
    private function resolveClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();

        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if ($first !== '') {
                return $first;
            }
        }

        $remote = $serverParams['REMOTE_ADDR'] ?? null;
        if (is_string($remote) && $remote !== '') {
            return $remote;
        }

        return 'n/a';
    }
}
