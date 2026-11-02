<?php

declare(strict_types=1);

namespace App\Mcp\Control;

/**
 * Liste blanche des sorties pilotables par MCP, plages bornées et risques associés.
 *
 * Principe : TOUT ce qui n'est pas listé ici est refusé. Exclus définitivement :
 * OTA (aucun GPIO OTA), redémarrage (`resetMode` 110), adresses e-mail (100/101),
 * suppression de photos, administration des utilisateurs, catégories de notification.
 *
 * Garde-fous biologiques (aquaponie) :
 *  - pompe aquarium (GPIO 16) : la coupure n'est PAS autorisée — le seul mécanisme de
 *    rétablissement borné existant (flag CRON `pump_restart_scheduled.flag`, 5 min) est
 *    réservé à la sécurité marée et ne garantit pas un rétablissement si le forçage
 *    GPIO 117 est actif ; on interdit donc l'arrêt plutôt que de risquer l'asphyxie ;
 *  - forçage pompe (GPIO 117) : seuls 0 (auto) et 1 (forcer ON) ; 2 (forcer OFF) refusé ;
 *  - nourrissage (GPIO 108/109) : incrément +1 uniquement, plafonné par jour
 *    (MCP_FEED_DAILY_MAX, défaut 2) — le firmware plafonne aussi à 5 repas en attente ;
 *  - consigne chauffage (GPIO 104) : 18–28 °C.
 */
final class ControlPolicy
{
    public const KIND_SWITCH = 'switch';
    public const KIND_VALUE = 'value';
    public const KIND_FEED = 'feed';

    /**
     * @var array<string, array<int, array{label: string, kind: string, min?: int|float, max?: int|float, allowed?: list<int>, risk: string, step?: int|float}>>
     */
    private const RULES = [
        'ffp3' => [
            2 => ['label' => 'Chauffage eau', 'kind' => self::KIND_SWITCH, 'allowed' => [0, 1], 'risk' => "Chauffage forcé : surchauffe possible si la consigne n'est plus surveillée ; coupé : l'eau peut refroidir (poissons tropicaux stressés sous ~20 °C). L'ESP32 réapplique sa régulation au cycle suivant."],
            15 => ['label' => 'Lumière', 'kind' => self::KIND_SWITCH, 'allowed' => [0, 1], 'risk' => 'Faible : modifie la photopériode des plantes et le rythme des poissons.'],
            16 => ['label' => 'Pompe aquarium', 'kind' => self::KIND_SWITCH, 'allowed' => [1], 'risk' => "Démarrage uniquement. L'ARRÊT est interdit par MCP : sans circulation, oxygénation et filtration cessent (risque de mortalité des poissons)."],
            18 => ['label' => 'Pompe réserve (remplissage)', 'kind' => self::KIND_SWITCH, 'allowed' => [0, 1], 'risk' => "Remplir sans surveillance peut provoquer un trop-plein ; l'ESP32 garde ses sécurités (réserve basse, durée max tempsRemplissageSec)."],
            117 => ['label' => 'Forçage pompe aquarium', 'kind' => self::KIND_VALUE, 'allowed' => [0, 1], 'risk' => 'Seuls « auto » (0) et « forcer ON » (1) sont permis ; « forcer OFF » (2) est interdit (arrêt de circulation).'],
            104 => ['label' => 'Consigne chauffage (°C)', 'kind' => self::KIND_VALUE, 'min' => 18, 'max' => 28, 'risk' => 'Hors 18–28 °C refusé. Un changement brusque de plus de 2 °C stresse les poissons : préférer des pas progressifs.'],
            105 => ['label' => 'Heure nourrissage matin', 'kind' => self::KIND_VALUE, 'min' => 5, 'max' => 11, 'risk' => 'Faible. Garder matin < midi < soir.'],
            106 => ['label' => 'Heure nourrissage midi', 'kind' => self::KIND_VALUE, 'min' => 11, 'max' => 15, 'risk' => 'Faible.'],
            107 => ['label' => 'Heure nourrissage soir', 'kind' => self::KIND_VALUE, 'min' => 15, 'max' => 21, 'risk' => 'Faible.'],
            108 => ['label' => 'Nourrissage manuel petits poissons', 'kind' => self::KIND_FEED, 'risk' => 'Suralimentation = pic d\'ammoniaque. Plafond quotidien appliqué.'],
            109 => ['label' => 'Nourrissage manuel gros poissons', 'kind' => self::KIND_FEED, 'risk' => 'Suralimentation = pic d\'ammoniaque. Plafond quotidien appliqué.'],
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
     * Valide la valeur cible. Retourne null si acceptée, sinon la raison du refus.
     */
    public static function validate(string $family, int $gpio, int|float|null $value): ?string
    {
        $rule = self::rule($family, $gpio);
        if ($rule === null) {
            return sprintf(
                'GPIO %d (%s) non pilotable par MCP. Pilotables : %s.',
                $gpio,
                $family,
                implode(', ', array_map(
                    static fn (int $g, array $r): string => $g . ' (' . $r['label'] . ')',
                    array_keys(self::RULES[$family] ?? []),
                    self::RULES[$family] ?? []
                ))
            );
        }
        if ($rule['kind'] === self::KIND_FEED) {
            return null;
        }
        if ($value === null) {
            return 'Valeur cible requise pour ' . $rule['label'] . '.';
        }
        if (isset($rule['allowed']) && !in_array($value, $rule['allowed'], false)) {
            return sprintf('Valeur %s refusée pour %s : %s', (string) $value, $rule['label'], $rule['risk']);
        }
        if (isset($rule['min']) && $value < $rule['min']) {
            return sprintf('Valeur %s sous le minimum %s pour %s.', (string) $value, (string) $rule['min'], $rule['label']);
        }
        if (isset($rule['max']) && $value > $rule['max']) {
            return sprintf('Valeur %s au-dessus du maximum %s pour %s.', (string) $value, (string) $rule['max'], $rule['label']);
        }
        if ($rule['kind'] === self::KIND_SWITCH && !in_array($value, [0, 1], false)) {
            return 'Un interrupteur n\'accepte que 0 ou 1.';
        }

        return null;
    }
}
