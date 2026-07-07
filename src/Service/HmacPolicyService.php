<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ServerSettingsRepository;

/**
 * Politique HMAC runtime : mode strict et nonce requis.
 *
 * Priorité : valeur en BDD (`serverSettings`, pilotée depuis la supervision)
 * puis repli sur `.env` (`HMAC_STRICT_MODE`, `HMAC_NONCE_REQUIRED`).
 *
 * Ne modifie pas le fichier `.env` : l'interface applique un override serveur
 * immédiat, le `.env` reste la valeur par défaut si aucune entrée BDD.
 */
final class HmacPolicyService
{
    public function __construct(
        private readonly ServerSettingsRepository $settings,
    ) {
    }

    public function isStrictMode(): bool
    {
        return $this->settings->getBool(
            ServerSettingsRepository::KEY_HMAC_STRICT_MODE,
            $this->envStrictDefault()
        );
    }

    public function isNonceRequired(): bool
    {
        return $this->settings->getBool(
            ServerSettingsRepository::KEY_HMAC_NONCE_REQUIRED,
            $this->envNonceDefault()
        );
    }

    public function setStrictMode(bool $enabled, ?string $updatedBy): void
    {
        $this->settings->setBool(
            ServerSettingsRepository::KEY_HMAC_STRICT_MODE,
            $enabled,
            $updatedBy
        );
    }

    public function setNonceRequired(bool $enabled, ?string $updatedBy): void
    {
        $this->settings->setBool(
            ServerSettingsRepository::KEY_HMAC_NONCE_REQUIRED,
            $enabled,
            $updatedBy
        );
    }

    public function resetStrictMode(): void
    {
        $this->settings->deleteKey(ServerSettingsRepository::KEY_HMAC_STRICT_MODE);
    }

    public function resetNonceRequired(): void
    {
        $this->settings->deleteKey(ServerSettingsRepository::KEY_HMAC_NONCE_REQUIRED);
    }

    /**
     * @return array{
     *   strict: bool,
     *   nonce_required: bool,
     *   strict_source: 'database'|'env',
     *   nonce_source: 'database'|'env',
     *   env_strict: bool,
     *   env_nonce_required: bool
     * }
     */
    public function getStatus(): array
    {
        $envStrict = $this->envStrictDefault();
        $envNonce = $this->envNonceDefault();

        return [
            'strict' => $this->isStrictMode(),
            'nonce_required' => $this->isNonceRequired(),
            'strict_source' => $this->settings->hasKey(ServerSettingsRepository::KEY_HMAC_STRICT_MODE)
                ? 'database' : 'env',
            'nonce_source' => $this->settings->hasKey(ServerSettingsRepository::KEY_HMAC_NONCE_REQUIRED)
                ? 'database' : 'env',
            'env_strict' => $envStrict,
            'env_nonce_required' => $envNonce,
        ];
    }

    private function envStrictDefault(): bool
    {
        return filter_var($_ENV['HMAC_STRICT_MODE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    }

    private function envNonceDefault(): bool
    {
        return filter_var($_ENV['HMAC_NONCE_REQUIRED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    }
}
