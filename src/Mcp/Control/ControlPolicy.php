<?php

declare(strict_types=1);

namespace App\Mcp\Control;

/**
 * Liste blanche des sorties pilotables par MCP, plages bornées et risques associés.
 *
 * Sépare les refus **matériels** (jamais levables : liste blanche, butées, interrupteurs)
 * des violations **biologiques** (levables via override_bio_safeguards + confirmation) :
 * arrêt pompe aquarium, forçage OFF, consigne chauffage hors plage, plafond nourrissage.
 */
final class ControlPolicy
{
    public const KIND_SWITCH = 'switch';
    public const KIND_VALUE = 'value';
    public const KIND_FEED = 'feed';

    /**
     * @var array<string, array<int, array{label: string, kind: string, min?: int|float, max?: int|float, allowed?: list<int>, risk: string, material_allowed?: list<int>, bio?: bool}>>
     */
    private const RULES = [
        'ffp3' => [
            2 => ['label' => 'Chauffage eau', 'kind' => self::KIND_SWITCH, 'allowed' => [0, 1], 'risk' => "Chauffage forcé : surchauffe possible si la consigne n'est plus surveillée ; coupé : l'eau peut refroidir (poissons tropicaux stressés sous ~20 °C). L'ESP32 réapplique sa régulation au cycle suivant."],
            15 => ['label' => 'Lumière', 'kind' => self::KIND_SWITCH, 'allowed' => [0, 1], 'risk' => 'Faible : modifie la photopériode des plantes et le rythme des poissons.'],
            16 => [
                'label' => 'Pompe aquarium',
                'kind' => self::KIND_SWITCH,
                'allowed' => [1],
                'material_allowed' => [0, 1],
                'bio' => true,
                'risk' => 'Sans circulation, oxygénation et filtration cessent (risque de mortalité). Arrêt seulement avec rétablissement automatique borné, ou mode maintenance + confirmation.',
            ],
            18 => ['label' => 'Pompe réserve (remplissage)', 'kind' => self::KIND_SWITCH, 'allowed' => [0, 1], 'risk' => "Remplir sans surveillance peut provoquer un trop-plein ; l'ESP32 garde ses sécurités (réserve basse, durée max tempsRemplissageSec)."],
            117 => [
                'label' => 'Forçage pompe aquarium',
                'kind' => self::KIND_VALUE,
                'allowed' => [0, 1],
                'material_allowed' => [0, 1, 2],
                'bio' => true,
                'risk' => '« forcer OFF » (2) arrête la circulation : réservé au rétablissement automatique ou à la maintenance confirmée.',
            ],
            104 => [
                'label' => 'Consigne chauffage (°C)',
                'kind' => self::KIND_VALUE,
                'min' => 18,
                'max' => 28,
                'bio' => true,
                'risk' => 'Hors plage configurée : stress thermique. Un changement brusque de plus de 2 °C stresse les poissons.',
            ],
            105 => ['label' => 'Heure nourrissage matin', 'kind' => self::KIND_VALUE, 'min' => 5, 'max' => 11, 'risk' => 'Faible. Garder matin < midi < soir.'],
            106 => ['label' => 'Heure nourrissage midi', 'kind' => self::KIND_VALUE, 'min' => 11, 'max' => 15, 'risk' => 'Faible.'],
            107 => ['label' => 'Heure nourrissage soir', 'kind' => self::KIND_VALUE, 'min' => 15, 'max' => 21, 'risk' => 'Faible.'],
            108 => ['label' => 'Nourrissage manuel petits poissons', 'kind' => self::KIND_FEED, 'bio' => true, 'risk' => 'Suralimentation = pic d\'ammoniaque. Plafond quotidien appliqué.'],
            109 => ['label' => 'Nourrissage manuel gros poissons', 'kind' => self::KIND_FEED, 'bio' => true, 'risk' => 'Suralimentation = pic d\'ammoniaque. Plafond quotidien appliqué.'],
            116 => ['label' => 'Durée de veille (s)', 'kind' => self::KIND_VALUE, 'min' => 60, 'max' => 3600, 'risk' => 'Veille longue = mesures et réactions plus espacées.'],
        ],
        'n3pp' => [
            102 => ['label' => 'Seuil sol sec', 'kind' => self::KIND_VALUE, 'min' => 0, 'max' => 4095, 'risk' => 'Seuil trop haut = arrosages fréquents ; trop bas = plantes desséchées.'],
            104 => ['label' => 'Heure d\'arrosage', 'kind' => self::KIND_VALUE, 'min' => 0, 'max' => 23, 'risk' => 'Faible.'],
            105 => ['label' => 'Durée d\'arrosage (s)', 'kind' => self::KIND_VALUE, 'min' => 1, 'max' => 20, 'risk' => 'Au-delà de 20 s refusé (inondation, vidage de la réserve).'],
            107 => ['label' => 'Durée de veille (s)', 'kind' => self::KIND_VALUE, 'min' => 60, 'max' => 86400, 'risk' => 'Veille courte = batterie vidée plus vite.'],
        ],
        'msp1' => [
            104 => ['label' => 'Angle servo haut-bas', 'kind' => self::KIND_VALUE, 'min' => 40, 'max' => 145, 'risk' => 'Hors 40–145° refusé (butée mécanique).'],
            105 => ['label' => 'Angle servo gauche-droite', 'kind' => self::KIND_VALUE, 'min' => 1, 'max' => 179, 'risk' => 'Hors 1–179° refusé (butée mécanique).'],
            107 => ['label' => 'Durée de veille (s)', 'kind' => self::KIND_VALUE, 'min' => 60, 'max' => 86400, 'risk' => 'Veille courte = batterie vidée plus vite.'],
            111 => ['label' => 'Tracker solaire automatique', 'kind' => self::KIND_SWITCH, 'allowed' => [0, 1], 'risk' => 'Faible : 0 = angles manuels, 1 = suivi automatique du soleil.'],
        ],
    ];

    /**
     * @return array{label: string, kind: string, min?: int|float, max?: int|float, allowed?: list<int>, risk: string}|null
     */
    public static function rule(string $family, int $gpio): ?array
    {
        return self::RULES[$family][$gpio] ?? null;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function all(): array
    {
        return self::RULES;
    }

    /**
     * @return list<int>
     */
    public static function gpios(): array
    {
        $all = [];
        foreach (self::RULES as $rules) {
            $all = array_merge($all, array_keys($rules));
        }

        return array_values(array_unique($all));
    }

    /**
     * Compatibilité : refuse matériel + biologique (garde-fous actifs, plages par défaut).
     */
    public static function validate(string $family, int $gpio, int|float|null $value): ?string
    {
        $decision = self::evaluate($family, $gpio, $value, new BioLimits(true, 18, 28, 2, 0));
        if ($decision->isOk()) {
            return null;
        }

        return $decision->message;
    }

    /**
     * Évalue une consigne. Les refus matériels ne sont jamais levables.
     * Les violations biologiques le sont si `$overrideBio` ou si les garde-fous sont coupés.
     *
     * @param int|null $fedToday nourrissages MCP déjà comptés (pour le plafond)
     */
    public static function evaluate(
        string $family,
        int $gpio,
        int|float|null $value,
        BioLimits $limits,
        bool $overrideBio = false,
        ?int $fedToday = null,
    ): PolicyDecision {
        $rule = self::rule($family, $gpio);
        if ($rule === null) {
            return PolicyDecision::material(sprintf(
                'GPIO %d (%s) non pilotable par MCP. Pilotables : %s.',
                $gpio,
                $family,
                implode(', ', array_map(
                    static fn (int $g, array $r): string => $g . ' (' . $r['label'] . ')',
                    array_keys(self::RULES[$family] ?? []),
                    self::RULES[$family] ?? []
                ))
            ));
        }

        if ($rule['kind'] === self::KIND_FEED) {
            if ($fedToday !== null && $limits->enabled && !$overrideBio && $fedToday >= $limits->feedDailyMax) {
                return PolicyDecision::biological(sprintf(
                    'Plafond de nourrissage MCP atteint : %d / %d sur 24 h.',
                    $fedToday,
                    $limits->feedDailyMax
                ));
            }

            return PolicyDecision::ok();
        }

        if ($value === null) {
            return PolicyDecision::material('Valeur cible requise pour ' . $rule['label'] . '.');
        }

        $materialAllowed = $rule['material_allowed'] ?? $rule['allowed'] ?? null;
        if (is_array($materialAllowed) && !in_array($value, $materialAllowed, false)) {
            return PolicyDecision::material(sprintf(
                'Valeur %s hors des valeurs matérielles pour %s.',
                (string) $value,
                $rule['label']
            ));
        }

        if ($rule['kind'] === self::KIND_SWITCH && !in_array($value, [0, 1], false)
            && !(($family === 'ffp3' && $gpio === 16 && (int) $value === 0))) {
            // GPIO 16 = 0 est une violation bio (traitée plus bas), pas un refus switch générique.
            if (!($family === 'ffp3' && $gpio === 16)) {
                return PolicyDecision::material('Un interrupteur n\'accepte que 0 ou 1.');
            }
        }

        // Limites matérielles non biologiques (n3pp arrosage, servos, veilles, horaires).
        $isBioRule = (bool) ($rule['bio'] ?? false);
        if (!$isBioRule) {
            if (isset($rule['allowed']) && !in_array($value, $rule['allowed'], false)) {
                return PolicyDecision::material(sprintf('Valeur %s refusée pour %s : %s', (string) $value, $rule['label'], $rule['risk']));
            }
            if (isset($rule['min']) && $value < $rule['min']) {
                return PolicyDecision::material(sprintf('Valeur %s sous le minimum %s pour %s.', (string) $value, (string) $rule['min'], $rule['label']));
            }
            if (isset($rule['max']) && $value > $rule['max']) {
                return PolicyDecision::material(sprintf('Valeur %s au-dessus du maximum %s pour %s.', (string) $value, (string) $rule['max'], $rule['label']));
            }

            return PolicyDecision::ok();
        }

        // Règles biologiques : si garde-fous coupés ou override, on accepte dans les bornes matérielles.
        if (!$limits->enabled || $overrideBio) {
            if ($family === 'ffp3' && $gpio === 104 && !self::withinHeatingHardBounds($value)) {
                return PolicyDecision::material('Consigne chauffage hors bornes dures 10–35 °C.');
            }

            return PolicyDecision::ok();
        }

        // Pompe / forçage OFF
        if ($family === 'ffp3' && $gpio === 16 && (int) $value === 0) {
            return PolicyDecision::biological($rule['risk'], 'bio_override');
        }
        if ($family === 'ffp3' && $gpio === 117 && (int) $value === 2) {
            return PolicyDecision::biological($rule['risk'], 'bio_override');
        }
        if (isset($rule['allowed']) && !in_array($value, $rule['allowed'], false)) {
            return PolicyDecision::biological(sprintf('Valeur %s refusée pour %s : %s', (string) $value, $rule['label'], $rule['risk']));
        }

        // Chauffage : plage configurable
        if ($family === 'ffp3' && $gpio === 104) {
            if ($value < $limits->heatingMin || $value > $limits->heatingMax) {
                return PolicyDecision::biological(sprintf(
                    'Consigne %s °C hors plage biologique %d–%d °C.',
                    (string) $value,
                    $limits->heatingMin,
                    $limits->heatingMax
                ));
            }

            return PolicyDecision::ok();
        }

        if (isset($rule['min']) && $value < $rule['min']) {
            return PolicyDecision::biological(sprintf('Valeur %s sous le minimum %s pour %s.', (string) $value, (string) $rule['min'], $rule['label']));
        }
        if (isset($rule['max']) && $value > $rule['max']) {
            return PolicyDecision::biological(sprintf('Valeur %s au-dessus du maximum %s pour %s.', (string) $value, (string) $rule['max'], $rule['label']));
        }

        return PolicyDecision::ok();
    }

    /**
     * Bornes dures matériel chauffage (10–35 °C), indépendantes des plages biologiques réglables.
     */
    private static function withinHeatingHardBounds(int|float $value): bool
    {
        return $value >= 10 && $value <= 35;
    }
}
