<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Résout la politique de notification : override BDD par famille, repli .env global.
 */
final class NotificationPolicyResolver
{
    private ?NotificationPolicy $fixedPolicy = null;

    public function __construct(
        private readonly ?\App\Repository\NotificationPolicyRepository $repository,
        private readonly NotificationPolicy $envPolicy
    ) {
    }

    public static function fromEnv(
        \App\Repository\NotificationPolicyRepository $repository,
        ?\App\Service\OperationalSettingsService $operationalSettings = null,
    ): self {
        $envPolicy = $operationalSettings !== null
            ? NotificationPolicy::fromOperationalSettings($operationalSettings)
            : NotificationPolicy::fromEnv();

        return new self($repository, $envPolicy);
    }

    /** Resolver de test : ignore la BDD et renvoie toujours la même politique. */
    public static function fixed(NotificationPolicy $policy): self
    {
        $resolver = new self(null, $policy);
        $resolver->fixedPolicy = $policy;

        return $resolver;
    }

    /**
     * @param string|null $family Chaîne famille alerte (FFP3, MSP1…) ou null pour global
     */
    public function resolve(?string $family): NotificationPolicy
    {
        if ($this->fixedPolicy !== null) {
            return $this->fixedPolicy;
        }

        $enum = NotificationFamily::fromAlertFamily($family);
        if ($enum === null || $this->repository === null) {
            return $this->envPolicy;
        }

        return $this->repository->getStoredPolicy($enum) ?? $this->envPolicy;
    }
}
