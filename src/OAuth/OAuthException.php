<?php

declare(strict_types=1);

namespace App\OAuth;

/**
 * Erreur OAuth 2.0 normalisée (RFC 6749 §5.2 / §4.1.2.1, RFC 7591 §3.2.2, RFC 8707).
 *
 * `error` est un code standard (`invalid_request`, `invalid_client`, `invalid_grant`,
 * `unauthorized_client`, `unsupported_grant_type`, `invalid_scope`, `invalid_target`,
 * `access_denied`, `invalid_redirect_uri`, `invalid_client_metadata`, `server_error`…).
 */
final class OAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($description);
    }

    /**
     * @return array{error: string, error_description: string}
     */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
