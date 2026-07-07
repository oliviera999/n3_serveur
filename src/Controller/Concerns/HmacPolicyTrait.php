<?php

declare(strict_types=1);

namespace App\Controller\Concerns;

use App\Service\HmacPolicyService;

/**
 * Lecture de la politique HMAC (mode strict, nonce requis) avec repli .env.
 */
trait HmacPolicyTrait
{
    protected ?HmacPolicyService $hmacPolicyService = null;

    protected function isHmacStrictMode(): bool
    {
        return $this->hmacPolicyService?->isStrictMode()
            ?? filter_var($_ENV['HMAC_STRICT_MODE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    }

    protected function isHmacNonceRequired(): bool
    {
        return $this->hmacPolicyService?->isNonceRequired()
            ?? filter_var($_ENV['HMAC_NONCE_REQUIRED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    }
}
