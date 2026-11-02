<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Control\ControlPolicy;
use App\Mcp\Control\OutputWriter;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\EnvironmentScope;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;
use App\Repository\McpOAuthRepository;
use App\Repository\McpReadRepository;
use App\Service\ControlAuditLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `iot_apply_plan` — applique un plan préparé par {@see PlanOutputChangeTool}.
 *
 * Revérifie tout au moment d'appliquer : propriétaire du plan, expiration, usage unique
 * (réclamation atomique), environnement toujours autorisé, politique (liste blanche et
 * plages), état courant inchangé depuis le plan, plafond de nourrissage. Écrit par le
 * chemin de la page de contrôle ({@see OutputWriter}) et journalise via
 * {@see ControlAuditLogger} (préfixe `[control-audit]`, module `mcp:<famille>`).
 */
final class ApplyPlanTool extends AbstractTool
{
    public function __construct(
        McpConfig $config,
        private McpOAuthRepository $store,
        private McpReadRepository $repo,
        private OutputWriter $writer,
        private ControlAuditLogger $audit,
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
            . 'comme la page de contrôle ; l\'action est journalisée. Renvoie l\'état avant/après.';
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
        $target = $plan['target_state'];
        if (!in_array($env, $this->config->controlAllowedEnvs(), true)) {
            throw new ToolException('Environnement ' . $env . ' n\'est plus pilotable (MCP_CONTROL_ALLOWED_ENVS).', 'env_not_allowed');
        }
        $value = $isFeed ? null : (is_numeric($target) ? $target + 0 : null);
        $refusal = ControlPolicy::validate($family, $gpio, $value);
        if ($refusal !== null) {
            throw new ToolException($refusal, 'refused_by_policy');
        }
        if (!$this->store->claimPlan($planId)) {
            throw new ToolException('Plan déjà appliqué ou expiré entre-temps.', 'plan_already_applied');
        }

        $request = $context->request ?? (new ServerRequestFactory())->createServerRequest('POST', '/mcp');
        $auditModule = 'mcp:' . $family;
        $auditTarget = ['gpio' => $gpio, 'env' => $env, 'plan_id' => $planId, 'mcp_user' => $context->username, 'client' => $context->clientId];

        try {
            $outcome = EnvironmentScope::run($env, function () use ($family, $gpio, $isFeed, $value, $plan, $env): array {
                $current = $this->repo->output($family, $gpio);
                $currentState = $current !== null && $current['state'] !== null ? (string) $current['state'] : null;
                if ($currentState !== $plan['before_state']) {
                    throw new ToolException(sprintf(
                        'L\'état a changé depuis le plan (prévu « %s », actuel « %s ») : rien n\'a été écrit, préparez un nouveau plan.',
                        (string) $plan['before_state'],
                        (string) $currentState
                    ), 'state_changed');
                }
                if ($isFeed) {
                    $fed = $this->store->countAppliedFeeds($env, $family, date('Y-m-d H:i:s', time() - 86400));
                    // Le plan courant est déjà réclamé (applied_at posé) : il est compté.
                    if ($fed > $this->config->feedDailyMax()) {
                        throw new ToolException('Plafond quotidien de nourrissage MCP atteint.', 'feed_cap_reached');
                    }
                    $counter = $this->writer->feed($gpio, isset($current['id']) ? (int) $current['id'] : 0);
                    if ($counter === null) {
                        throw new ToolException('Échec du nourrissage (sortie introuvable).', 'write_failed');
                    }

                    return ['before' => $currentState, 'after' => (string) $counter];
                }
                $after = $this->writer->write($family, $gpio, $value ?? 0, isset($current['id']) ? (int) $current['id'] : 0);
                if ($after === null) {
                    throw new ToolException('Écriture refusée ou sortie introuvable : vérifier avec iot_outputs_state.', 'write_failed');
                }

                return ['before' => $currentState, 'after' => $after];
            });
        } catch (\Throwable $e) {
            // Le plan reste consommé (usage unique) : tout nouvel essai repasse par un nouveau plan.
            $this->audit->logAction($request, $auditModule, $isFeed ? 'feed' : 'set', $auditTarget, false, $e->getMessage(), 'mcp:' . $context->username);
            throw $e;
        }

        $this->audit->logAction($request, $auditModule, $isFeed ? 'feed' : 'set', $auditTarget + [
            'before' => $outcome['before'],
            'after' => $outcome['after'],
        ], true, null, 'mcp:' . $context->username);

        return ToolResult::json([
            'applied' => true,
            'plan_id' => $planId,
            'env' => $env,
            'is_production' => FieldCatalog::isProductionTarget($family, $env),
            'family' => $family,
            'gpio' => $gpio,
            'before' => $outcome['before'],
            'after' => $outcome['after'],
            'note' => 'Consigne enregistrée ; l\'ESP32 l\'appliquera à son prochain poll. Vérifier ensuite avec iot_outputs_state / iot_latest.',
        ]);
    }
}
