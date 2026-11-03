<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Control\ControlPolicy;
use App\Mcp\Control\ControlTarget;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\EnvironmentScope;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;
use App\Repository\McpOAuthRepository;
use App\Repository\McpReadRepository;

/**
 * `iot_plan_output_change` — prépare un changement de sortie SANS RIEN ÉCRIRE sur les
 * appareils : vérifie la liste blanche et les plages, calcule avant/après et les risques,
 * puis stocke le plan (≈ 10 min) pour {@see ApplyPlanTool}.
 */
final class PlanOutputChangeTool extends AbstractTool
{
    public const PLAN_TTL_SECONDS = 600;

    public function __construct(
        McpConfig $config,
        private McpReadRepository $repo,
        private McpOAuthRepository $store,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_plan_output_change';
    }

    public function title(): string
    {
        return 'Préparer un changement de sortie (pilotage)';
    }

    public function description(): string
    {
        return 'PILOTAGE, étape 1/2 — ne modifie rien sur l\'appareil. Prépare le changement d\'une sortie ou d\'un réglage '
            . 'autorisé (liste blanche : chauffage, lumière, pompe réserve, démarrage pompe aquarium, consignes, horaires de '
            . 'nourrissage, nourrissage manuel plafonné, veille, réglages serre et tracker) et renvoie un plan {plan_id, avant, '
            . 'après, risques biologiques et matériels, expiration ~10 min}. MONTRE ce plan à la personne et obtiens son accord '
            . 'explicite AVANT d\'appeler iot_apply_plan. Refusé : arrêt de la pompe aquarium, OTA, redémarrage, e-mails, '
            . 'suppression de photos, gestion des utilisateurs.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function requiredScope(): string
    {
        return McpConfig::SCOPE_CONTROL;
    }

    public function annotations(): array
    {
        return [
            'title' => $this->title(),
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ];
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'family' => $this->familyProperty(false, 'Famille du module à piloter.'),
            'gpio' => [
                'type' => 'integer',
                'enum' => ControlPolicy::gpios(),
                'description' => 'GPIO à modifier (voir iot_outputs_state pour les libellés ; seuls ceux de la liste blanche sont acceptés).',
            ],
            'value' => [
                'type' => 'number',
                'description' => 'Valeur cible (0/1 pour un interrupteur, nombre pour un réglage). Ignorée pour un nourrissage (GPIO 108/109 : +1 repas).',
            ],
            'reason' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Motif du changement (journalisé).'],
            'env' => $this->envProperty(true),
        ], ['family', 'gpio']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $family = (string) $arguments['family'];
        $gpio = (int) $arguments['gpio'];
        $value = isset($arguments['value']) && (is_int($arguments['value']) || is_float($arguments['value']))
            ? $arguments['value'] : null;
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }
        $refusal = ControlPolicy::validate($family, $gpio, $value);
        if ($refusal !== null) {
            throw new ToolException($refusal, 'refused_by_policy');
        }
        $rule = (array) ControlPolicy::rule($family, $gpio);
        $env = $this->resolveEnv($arguments, true);
        // Cible RÉELLE (table outputs résolue par TableConfig) : refus d'une table de
        // production même depuis un environnement « de test » d'une autre famille.
        $target = ControlTarget::resolve($family, $env);
        $target->assertAllowed($this->config);
        $isFeed = $rule['kind'] === ControlPolicy::KIND_FEED;
        if ($isFeed && $family !== 'ffp3') {
            throw new ToolException('Le nourrissage n\'existe que pour l\'aquaponie FFP3.', 'refused_by_policy');
        }

        $planned = EnvironmentScope::run($env, function () use ($family, $gpio, $env, $isFeed, $target): array {
            $current = $this->repo->output($family, $gpio);
            if ($current === null) {
                throw new ToolException(sprintf('GPIO %d absent de la table outputs (%s, %s).', $gpio, $family, $env), 'not_found');
            }
            $fedToday = $isFeed ? $this->store->countAppliedFeeds($target->physicalKey(), date('Y-m-d H:i:s', time() - 86400)) : 0;

            return ['current' => $current, 'fed_today' => $fedToday];
        });

        $before = $planned['current']['state'] === null ? null : (string) $planned['current']['state'];
        $risks = [(string) $rule['risk']];
        if ($isFeed) {
            $max = $this->config->feedDailyMax();
            if ($planned['fed_today'] >= $max) {
                throw new ToolException(sprintf(
                    'Plafond de nourrissage MCP atteint : %d repas manuels sur 24 h (maximum %d). Les nourrissages automatiques continuent.',
                    $planned['fed_today'],
                    $max
                ), 'feed_cap_reached');
            }
            $after = (string) (((int) $before) + 1);
            $risks[] = sprintf('Repas manuels MCP sur 24 h : %d / %d après celui-ci.', $planned['fed_today'] + 1, $max);
        } else {
            $after = (string) $value;
            if ($before !== null && is_numeric($before) && (float) $before === (float) $value) {
                $risks[] = 'La valeur cible est déjà en place : appliquer ne changera rien.';
            }
            if ($family === 'ffp3' && $gpio === 104 && $before !== null && is_numeric($before) && abs((float) $value - (float) $before) > 2) {
                $risks[] = 'Écart de consigne supérieur à 2 °C : risque de choc thermique, préférer plusieurs petits pas.';
            }
        }
        if ($target->isProduction()) {
            // Atteint uniquement avec MCP_CONTROL_ALLOW_PRODUCTION=true (cf. assertAllowed()).
            $risks[] = 'ATTENTION : table de PRODUCTION ' . $target->table . ' (installation réelle, êtres vivants).';
        }
        $risks[] = 'Effet au prochain réveil / poll de l\'ESP32 (de quelques secondes à FreqWakeUp).';

        $planId = bin2hex(random_bytes(16));
        $plan = [
            'plan_id' => $planId,
            'env' => $env,
            'is_production' => $target->isProduction(),
            'target_table' => $target->table,
            'family' => $family,
            'gpio' => $gpio,
            'label' => $rule['label'],
            'action' => $isFeed ? 'feed' : 'set',
            'before' => $before,
            'after' => $after,
            'last_change' => $planned['current']['requestTime'] ?? null,
            'risks' => $risks,
            'reason' => isset($arguments['reason']) ? (string) $arguments['reason'] : null,
            'expires_at' => date('Y-m-d H:i:s', time() + self::PLAN_TTL_SECONDS),
            'next_step' => 'Après accord explicite de la personne : iot_apply_plan avec ce plan_id.',
        ];
        $this->store->storePlan(
            $planId,
            $context->userId,
            $context->clientId,
            $env,
            $family,
            $gpio,
            $isFeed ? 'feed' : 'set',
            $before,
            $after,
            $plan,
            self::PLAN_TTL_SECONDS,
            $target->physicalKey()
        );

        return ToolResult::json($plan);
    }
}
