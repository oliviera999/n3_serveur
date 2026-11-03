<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Confirm\ConfirmationGate;
use App\Mcp\Confirm\ConfirmCategory;
use App\Mcp\Control\BioLimits;
use App\Mcp\Control\ControlExecutor;
use App\Mcp\Control\ControlPolicy;
use App\Mcp\Control\ControlTarget;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;
use App\Repository\McpOAuthRepository;

/**
 * `iot_apply_plan` — applique un plan préparé par {@see PlanOutputChangeTool}.
 */
final class ApplyPlanTool extends AbstractTool
{
    public function __construct(
        McpConfig $config,
        private McpOAuthRepository $store,
        private ControlExecutor $executor,
        private ConfirmationGate $confirm,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_apply_plan';
    }

    public function title(): string
    {
        return 'Appliquer un plan de pilotage';
    }

    public function description(): string
    {
        return 'PILOTAGE, étape 2/2 — modifie réellement une sortie de l\'appareil. N\'appelle cet outil qu\'après avoir montré '
            . 'le plan d\'iot_plan_output_change à la personne et obtenu son accord explicite. Le serveur revérifie le plan '
            . '(propriétaire, expiration, usage unique, liste blanche, état inchangé depuis le plan) puis écrit la consigne '
            . 'comme la page de contrôle ; l\'action est journalisée. Renvoie l\'état avant/après. '
            . 'Si le plan porte une levée de garde-fou, une confirmation par code est exigée.';
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
            'destructiveHint' => true,
            'idempotentHint' => false,
            'openWorldHint' => true,
        ];
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'plan_id' => [
                'type' => 'string',
                'pattern' => '^[a-f0-9]{32}$',
                'description' => 'Identifiant renvoyé par iot_plan_output_change.',
            ],
            'confirmation_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
            'confirmation_code' => ['type' => 'string', 'pattern' => '^[0-9]{6}$'],
        ], ['plan_id']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $planId = (string) $arguments['plan_id'];
        $plan = $this->store->findPlan($planId);
        if ($plan === null || (int) $plan['user_id'] !== $context->userId) {
            throw new ToolException('Plan introuvable (ou préparé par un autre utilisateur).', 'plan_not_found');
        }
        if ($plan['applied_at'] !== null) {
            throw new ToolException('Ce plan a déjà été appliqué : préparez-en un nouveau.', 'plan_already_applied');
        }
        if (strtotime((string) $plan['expires_at']) < time()) {
            throw new ToolException('Plan expiré (validité ~10 min) : préparez-en un nouveau.', 'plan_expired');
        }

        $env = (string) $plan['env'];
        $family = (string) $plan['family'];
        $gpio = (int) $plan['gpio'];
        $isFeed = $plan['action'] === 'feed';
        $targetState = $plan['target_state'];
        $payload = json_decode((string) ($plan['payload'] ?? '{}'), true);
        $payload = is_array($payload) ? $payload : [];
        $override = !empty($payload['override_bio_safeguards']);

        $target = ControlTarget::resolve($family, $env);
        $target->assertAllowed($this->config);
        if ((string) ($plan['target_key'] ?? '') !== $target->physicalKey()) {
            throw new ToolException('La table ciblée a changé depuis le plan : préparez-en un nouveau.', 'target_changed');
        }

        $value = $isFeed ? null : (is_numeric($targetState) ? $targetState + 0 : null);
        $limits = BioLimits::fromConfig($this->config);
        $fedToday = $isFeed
            ? $this->store->countAppliedFeeds($target->physicalKey(), date('Y-m-d H:i:s', time() - 86400))
            : null;
        $decision = ControlPolicy::evaluate($family, $gpio, $value, $limits, $override, $fedToday);
        if ($decision->isMaterial()) {
            throw new ToolException((string) $decision->message, 'refused_by_policy');
        }
        if ($decision->isBiological() && !$override) {
            throw new ToolException((string) $decision->message, 'bio_safeguard');
        }

        $categories = [];
        if ($override || $decision->isBiological()) {
            if (!$context->isAdmin()) {
                throw new ToolException('La levée de garde-fou est réservée aux administrateurs.', 'forbidden');
            }
            $categories[] = ConfirmCategory::BIO_OVERRIDE;
        }
        $pending = $this->confirm->check($this->name(), $arguments, $context, $categories, [
            'cible' => sprintf('%s %s GPIO %d', $family, $env, $gpio),
            'effet' => 'Application du plan ' . $planId,
            'risques' => $payload['risks'] ?? [(string) $decision->message],
        ]);
        if ($pending !== null) {
            return $pending;
        }

        if (!$this->store->claimPlan($planId)) {
            throw new ToolException('Plan déjà appliqué ou expiré entre-temps.', 'plan_already_applied');
        }

        $outcome = $this->executor->execute($context, $target, $gpio, $value, $isFeed, [
            'expected_before' => $plan['before_state'],
            'plan_id' => $planId,
            'origin' => 'plan',
            'override_bio' => $override,
            'reason' => isset($payload['reason']) ? (string) $payload['reason'] : null,
        ]);

        return ToolResult::json([
            'applied' => true,
            'plan_id' => $planId,
            'env' => $env,
            'is_production' => $target->isProduction(),
            'target_table' => $target->table,
            'family' => $family,
            'gpio' => $gpio,
            'before' => $outcome['before'],
            'after' => $outcome['after'],
            'note' => 'Consigne enregistrée ; l\'ESP32 l\'appliquera à son prochain poll. Vérifier ensuite avec iot_outputs_state / iot_latest.',
        ]);
    }
}
