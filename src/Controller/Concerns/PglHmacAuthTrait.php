<?php

declare(strict_types=1);

namespace App\Controller\Concerns;

use App\Middleware\RawPostBodyMiddleware;
use App\Service\HmacAuditLogger;
use App\Util\SignedBodyResolver;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Authentification HMAC-SHA256 pour les endpoints Poissonglouton
 * (`POST /pgl/post-data`, `POST /pgl/heartbeat`).
 *
 * Compose {@see HmacAuthTrait} (contrat commun FFP3/N3PP/MSP : soit `timestamp`
 * + `signature` dans le body, soit en-têtes `X-Sig-*` signant le corps complet)
 * et l'adapte à PGL :
 *   - clé dédiée `PGL_API_SIG_SECRET` avec repli sur la clé commune
 *     `API_SIG_SECRET`, en miroir de `PGL_API_KEY` / `API_KEY` ;
 *   - extraction du contexte de body-signing (en-têtes `X-Sig-*` + corps brut
 *     capté par {@see RawPostBodyMiddleware}).
 *
 * La classe utilisatrice doit exposer `componentName(): string` et un
 * `LogService $logger` (contraintes de HmacAuthTrait). En cas de succès HMAC,
 * `$authenticatedByHmac` passe à true : l'appelant peut alors sauter la
 * vérification `api_key` legacy.
 */
trait PglHmacAuthTrait
{
    use HmacAuthTrait;
    use HmacPolicyTrait;
    use OperationalSettingsTrait;

    /** True si l'authentification HMAC a réussi (dispense de la clé API legacy). */
    protected bool $authenticatedByHmac = false;

    protected ?HmacAuditLogger $hmacAuditLogger = null;

    /**
     * @param array<string, scalar|null> $context
     */
    protected function recordHmacAudit(
        string $result,
        string $authMode,
        array $context = [],
        ?string $reason = null
    ): void {
        $this->hmacAuditLogger?->record($this->componentName(), $result, $authMode, $context, $reason);
    }

    /**
     * False si l'auth HMAC a réussi : la clé API legacy n'est alors plus exigée.
     * Miroir de {@see \App\Controller\AbstractPostDataController::requiresApiKey()}.
     */
    protected function requiresApiKey(): bool
    {
        return !$this->authenticatedByHmac;
    }

    /**
     * PGL accepte une clé HMAC dédiée `PGL_API_SIG_SECRET` (repli sur la clé
     * commune `API_SIG_SECRET`), en miroir de `PGL_API_KEY` / `API_KEY`.
     */
    protected function hmacSecret(): ?string
    {
        $secret = $_ENV['PGL_API_SIG_SECRET'] ?? $_ENV['API_SIG_SECRET'] ?? null;

        return is_string($secret) ? $secret : null;
    }

    /**
     * Injecte le contexte de body-signing (en-têtes `X-Sig-*` + corps brut)
     * attendu par HmacAuthTrait. Additif : sans en-tête `X-Sig-Hmac`, `$params`
     * est inchangé et l'auth retombe sur `timestamp`/`signature` (body) puis
     * `api_key`.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function prepareHmacParams(Request $request, array $params): array
    {
        $sigHmac = $request->getHeaderLine('X-Sig-Hmac');
        if ($sigHmac !== '') {
            $resolved = SignedBodyResolver::resolve($request);
            $params['__sig_ts'] = $request->getHeaderLine('X-Sig-Timestamp');
            $params['__sig_nonce'] = $request->getHeaderLine('X-Sig-Nonce');
            $params['__sig_hmac'] = $sigHmac;
            $params['__sig_body'] = $resolved['body'];
            $params['__sig_body_source'] = $resolved['source'];
        }

        return $params;
    }
}
