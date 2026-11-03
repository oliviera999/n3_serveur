<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Control\ControlPolicy;
use App\Mcp\Control\ControlTarget;
use App\Mcp\Control\OutputWriter;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\EnvironmentScope;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;
use App\Repository\McpOAuthRepository;
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
        $request = $context->request ?? (new ServerRequestFactory())->createServerRequest('POST', '/mcp');
        $who = 'mcp:' . $context->username;
        $family = is_array($plan) ? (string) $plan['family'] : 'inconnu';
        $isFeed = is_array($plan) && $plan['action'] === 'feed';
        $auditModule = 'mcp:' . $family;
        $auditTarget = [
            'gpio' => is_array($plan) ? (int) $plan['gpio'] : null,
            'env' => is_array($plan) ? (string) $plan['env'] : null,
            'plan_id' => $planId,
            'mcp_user' => $context->username,
            'client' => $context->clientId,
        ];

        try {
            [$outcome, $target] = $this->apply($planId, $plan, $context);
        } catch (\Throwable $e) {
            // Tout refus ou échec est audité. Le plan reste consommé s'il a été réclamé
            // (usage unique) : un nouvel essai repasse par un nouveau plan.
            $this->audit->logAction($request, $auditModule, $isFeed ? 'feed' : 'set', $auditTarget, false, $e->getMessage(), $who);
            throw $e;
        }

        $this->audit->logAction($request, $auditModule, $isFeed ? 'feed' : 'set', $auditTarget + [
            'table' => $target->table,
            'before' => $outcome['before'],
            'after' => $outcome['after'],
        ], true, null, $who);

        $env = $target->env;
        $gpio = (int) $auditTarget['gpio'];

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

    /**
     * @param array<string, mixed>|null $plan
     * @return array{0: array{before: ?string, after: ?string}, 1: ControlTarget}
     */
    private function apply(string $planId, ?array $plan, McpContext $context): array
    {
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

        // Revérification COMPLÈTE de la cible au moment d'écrire (la configuration a pu
        // changer depuis le plan) : table réellement résolue, liste blanche, production.
        $target = ControlTarget::resolve($family, $env);
        $target->assertAllowed($this->config);
        if ((string) ($plan['target_key'] ?? '') !== $target->physicalKey()) {
            throw new ToolException('La table ciblée a changé depuis le plan : préparez-en un nouveau.', 'target_changed');
        }
        $value = $isFeed ? null : (is_numeric($targetState) ? $targetState + 0 : null);
        $refusal = ControlPolicy::validate($family, $gpio, $value);
        if ($refusal !== null) {
            throw new ToolException($refusal, 'refused_by_policy');
        }
        if (!$this->store->claimPlan($planId)) {
            throw new ToolException('Plan déjà appliqué ou expiré entre-temps.', 'plan_already_applied');
        }

        $outcome = EnvironmentScope::run($env, fn (): array => $this->store->transactional(function () use ($family, $gpio, $isFeed, $value, $plan, $target): array {
            // Verrou de ligne : aucune écriture concurrente entre la relecture et l'écriture.
            $current = $this->store->outputForUpdate($family, $gpio);
            $currentState = $current !== null && $current['state'] !== null ? (string) $current['state'] : null;
            if ($currentState !== $plan['before_state']) {
                throw new ToolException(sprintf(
                    'L\'état a changé depuis le plan (prévu « %s », actuel « %s ») : rien n\'a été écrit, préparez un nouveau plan.',
                    (string) $plan['before_state'],
                    (string) $currentState
                ), 'state_changed');
            }
            if ($isFeed) {
                // Le plan courant est déjà réclamé (applied_at posé) : il est compté.
                $fed = $this->store->countAppliedFeeds($target->physicalKey(), date('Y-m-d H:i:s', time() - 86400));
                if ($fed > $this->config->feedDailyMax()) {
                    throw new ToolException('Plafond quotidien de nourrissage MCP atteint pour ' . $target->table . '.', 'feed_cap_reached');
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
        }));

        return [$outcome, $target];
    }
}
