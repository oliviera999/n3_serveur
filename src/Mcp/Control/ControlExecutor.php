<?php

declare(strict_types=1);

namespace App\Mcp\Control;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\EnvironmentScope;
use App\Mcp\ToolException;
use App\Repository\McpOAuthRepository;
use App\Service\ControlAuditLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Écriture d'une sortie MCP : verrou de ligne, plafond nourrissage, OutputWriter, audit.
 * Partagé par {@see \App\Mcp\Tools\ApplyPlanTool} et {@see \App\Mcp\Tools\SetOutputTool}.
 */
final class ControlExecutor
{
    public function __construct(
        private McpConfig $config,
        private McpOAuthRepository $store,
        private OutputWriter $writer,
        private ControlAuditLogger $audit,
    ) {
    }

    /**
     * @param array{expected_before?: ?string, plan_id?: ?string, origin?: string, reason?: ?string} $options
     * @return array{before: ?string, after: ?string, target: ControlTarget, is_feed: bool}
     */
    public function execute(
        McpContext $context,
        ControlTarget $target,
        int $gpio,
        int|float|null $value,
        bool $isFeed,
        array $options = [],
    ): array {
        $target->assertAllowed($this->config);
        $family = $target->family;
        $env = $target->env;
        $request = $context->request ?? (new ServerRequestFactory())->createServerRequest('POST', '/mcp');
        $who = 'mcp:' . $context->username;
        $auditModule = 'mcp:' . $family;
        $auditTarget = [
            'gpio' => $gpio,
            'env' => $env,
            'plan_id' => $options['plan_id'] ?? null,
            'origin' => $options['origin'] ?? 'direct',
            'mcp_user' => $context->username,
            'client' => $context->clientId,
            'reason' => $options['reason'] ?? null,
        ];

        try {
            $outcome = EnvironmentScope::run($env, fn (): array => $this->store->transactional(
                function () use ($family, $gpio, $isFeed, $value, $target, $options): array {
                    $current = $this->store->outputForUpdate($family, $gpio);
                    $currentState = $current !== null && $current['state'] !== null ? (string) $current['state'] : null;
                    if (array_key_exists('expected_before', $options)
                        && $options['expected_before'] !== $currentState) {
                        throw new ToolException(sprintf(
                            'L\'état a changé (prévu « %s », actuel « %s ») : rien n\'a été écrit.',
                            (string) $options['expected_before'],
                            (string) $currentState
                        ), 'state_changed');
                    }
                    if ($isFeed) {
                        $fed = $this->store->countAppliedFeeds(
                            $target->physicalKey(),
                            date('Y-m-d H:i:s', time() - 86400)
                        );
                        // origin=plan : claimPlan a déjà posé applied_at → le plan courant est déjà compté.
                        // origin=direct : l'enregistrement (recordDirectAction) n'a pas encore eu lieu.
                        $countThis = ($options['origin'] ?? '') === 'plan' ? 0 : 1;
                        if ($fed + $countThis > $this->config->feedDailyMax()
                            && $this->config->bioSafeguardsEnabled()
                            && empty($options['override_bio'])) {
                            throw new ToolException(
                                'Plafond quotidien de nourrissage MCP atteint pour ' . $target->table . '.',
                                'feed_cap_reached'
                            );
                        }
                        $counter = $this->writer->feed($gpio, isset($current['id']) ? (int) $current['id'] : 0);
                        if ($counter === null) {
                            throw new ToolException('Échec du nourrissage (sortie introuvable).', 'write_failed');
                        }

                        return ['before' => $currentState, 'after' => (string) $counter];
                    }
                    $after = $this->writer->write($family, $gpio, $value ?? 0, isset($current['id']) ? (int) $current['id'] : 0);
                    if ($after === null) {
                        throw new ToolException(
                            'Écriture refusée ou sortie introuvable : vérifier avec iot_outputs_state.',
                            'write_failed'
                        );
                    }

                    return ['before' => $currentState, 'after' => $after];
                }
            ));
        } catch (\Throwable $e) {
            $this->audit->logAction(
                $request,
                $auditModule,
                $isFeed ? 'feed' : 'set',
                $auditTarget,
                false,
                $e->getMessage(),
                $who
            );
            throw $e;
        }

        $this->audit->logAction($request, $auditModule, $isFeed ? 'feed' : 'set', $auditTarget + [
            'table' => $target->table,
            'before' => $outcome['before'],
            'after' => $outcome['after'],
        ], true, null, $who);

        return [
            'before' => $outcome['before'],
            'after' => $outcome['after'],
            'target' => $target,
            'is_feed' => $isFeed,
        ];
    }

    /**
     * Enregistre une action directe comme plan déjà appliqué (compteur nourrissage unique).
     */
    public function recordDirectAction(
        McpContext $context,
        ControlTarget $target,
        int $gpio,
        bool $isFeed,
        ?string $before,
        string $after,
        array $payload,
    ): string {
        $planId = bin2hex(random_bytes(16));
        $this->store->storePlan(
            $planId,
            $context->userId,
            $context->clientId,
            $target->env,
            $target->family,
            $gpio,
            $isFeed ? 'feed' : 'set',
            $before,
            $after,
            $payload + ['origin' => 'direct', 'plan_id' => $planId],
            1,
            $target->physicalKey()
        );
        $this->store->claimPlan($planId);

        return $planId;
    }
}
