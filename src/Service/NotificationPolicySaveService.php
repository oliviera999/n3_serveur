<?php

declare(strict_types=1);

namespace App\Service;

use App\Notification\NotificationCategory;
use App\Notification\NotificationFamily;
use App\Notification\NotificationMode;
use App\Repository\NotificationPolicyRepository;

/**
 * Sauvegarde atomique de la politique de notifications (mode + catégories + mailNotif firmware).
 */
class NotificationPolicySaveService
{
    public function __construct(
        private readonly NotificationPolicyRepository $repository
    ) {
    }

    /**
     * @param list<string> $categoryValues
     *
     * @return array{ok: bool, error?: string}
     */
    public function save(NotificationFamily $family, string $modeValue, array $categoryValues): array
    {
        $validated = $this->repository->validatePayload($modeValue, $categoryValues);
        if (($validated['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => $validated['error'] ?? 'Données invalides'];
        }

        /** @var NotificationMode $mode */
        $mode = $validated['mode'];
        /** @var list<NotificationCategory> $categories */
        $categories = $validated['categories'];

        if ($mode === NotificationMode::None) {
            $categories = [];
        }

        $ok = $this->repository->savePolicy($family, $mode, $categories);
        if (!$ok) {
            return ['ok' => false, 'error' => 'Échec de la persistance'];
        }

        return ['ok' => true];
    }

    /**
     * Métadonnées catégories pour les templates Twig.
     *
     * @return list<array{value: string, label: string, emoji: string}>
     */
    public static function categoriesMeta(): array
    {
        $meta = [];
        foreach (NotificationCategory::cases() as $cat) {
            $meta[] = [
                'value' => $cat->value,
                'label' => $cat->label(),
                'emoji' => $cat->emoji(),
            ];
        }

        return $meta;
    }

    /**
     * Modes pour le select UI.
     *
     * @return list<array{value: string, label: string, hint: string}>
     */
    public static function modesMeta(): array
    {
        return [
            ['value' => 'none', 'label' => 'Aucun', 'hint' => 'Aucun e-mail (serveur ni firmware)'],
            ['value' => 'important', 'label' => 'Important', 'hint' => 'P1 critiques + P2 alertes (recommandé)'],
            ['value' => 'partial', 'label' => 'Partiel', 'hint' => 'Important + P3 infos'],
            ['value' => 'full', 'label' => 'Complet', 'hint' => 'Tout, y compris P4 diagnostic'],
        ];
    }
}
