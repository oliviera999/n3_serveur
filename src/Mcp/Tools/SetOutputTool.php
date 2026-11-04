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
use App\Mcp\Support\EnvironmentScope;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;
use App\Repository\McpOAuthRepository;
use App\Repository\McpReadRepository;
use App\Repository\McpRestoreRepository;

/**
 * `iot_set_output` — pilotage direct (admin, sans plan préalable) si MCP_CONTROL_REQUIRE_PLAN=false.
 */
final class SetOutputTool extends AbstractTool
{
    public function __construct(
        McpConfig $config,
        private McpReadRepository $repo,
        private McpOAuthRepository $store,
        private ControlExecutor $executor,
        private ConfirmationGate $confirm,
        private McpRestoreRepository $restores,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_set_output';
    }

    public function title(): string
    {
        return 'Appliquer une consigne directement (admin)';
    }

    public function description(): string
    {
        return 'PILOTAGE DIRECT (admin) — applique une consigne en un seul appel (même validation, verrou de ligne '
            . 'et audit que iot_apply_plan). Réservé aux administrateurs ; exige MCP_CONTROL_REQUIRE_PLAN=false. '
            . 'Les cibles de production exigent MCP_CONTROL_ALLOW_PRODUCTION. Une levée de garde-fou biologique '
            . '(override_bio_safeguards) ou un arrêt pompe en maintenance exige une confirmation par code.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function requiredScope(): string
    {
        return McpConfig::SCOPE_CONTROL;
    }

    public function isAvailableFor(McpContext $context): bool
    {
        // Scope iot.control : step-up OAuth dans McpServer::callTool (pas ici).
        return $this->config->isControlEnabled()
            && !$this->config->requirePlan()
            && $context->isAdmin();
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
            'family' => $this->familyProperty(false, 'Famille du module à piloter.'),
            'gpio' => [
                'type' => 'integer',
                'enum' => ControlPolicy::gpios(),
                'description' => 'GPIO à modifier (liste blanche ControlPolicy).',
            ],
            'value' => [
                'type' => 'number',
                'description' => 'Valeur cible (ignorée pour nourrissage 108/109).',
            ],
            'reason' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Motif (journalisé).'],
            'env' => $this->envProperty(true),
            'restore_after_minutes' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 60,
                'description' => 'Pour un arrêt pompe : minutes avant rétablissement automatique.',
            ],
            'maintenance' => [
                'type' => 'boolean',
                'description' => 'Arrêt pompe sans rétablissement auto (exige override + confirmation).',
            ],
            'override_bio_safeguards' => [
                'type' => 'boolean',
                'description' => 'Lève les garde-fous biologiques (admin + confirmation).',
            ],
            'override_reason' => [
                'type' => 'string',
                'minLength' => 10,
                'maxLength' => 200,
                'description' => 'Motif obligatoire si override_bio_safeguards.',
            ],
            'confirmation_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
            'confirmation_code' => ['type' => 'string', 'pattern' => '^[0-9]{6}$'],
        ], ['family', 'gpio']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        AdminGuard::assertAdmin($context);
        $family = (string) $arguments['family'];
        $gpio = (int) $arguments['gpio'];
        $value = isset($arguments['value']) && (is_int($arguments['value']) || is_float($arguments['value']))
            ? $arguments['value'] : null;
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }
        $override = !empty($arguments['override_bio_safeguards']);
        $maintenance = !empty($arguments['maintenance']);
        $overrideReason = isset($arguments['override_reason']) ? trim((string) $arguments['override_reason']) : '';
        if ($override && (strlen($overrideReason) < 10 || strlen($overrideReason) > 200)) {
            throw new ToolException('override_reason requis (10–200 caractères) avec override_bio_safeguards.', 'invalid_arguments');
        }

        $env = $this->resolveEnv($arguments, true);
        $target = ControlTarget::resolve($family, $env);
        $target->assertAllowed($this->config);
        $limits = BioLimits::fromConfig($this->config);
        $rule = ControlPolicy::rule($family, $gpio);
        if ($rule === null) {
            throw new ToolException('GPIO non pilotable.', 'refused_by_policy');
        }
        $isFeed = $rule['kind'] === ControlPolicy::KIND_FEED;

        $fedToday = null;
        if ($isFeed) {
            $fedToday = EnvironmentScope::run(
                $env,
                fn (): int => $this->store->countAppliedFeeds($target->physicalKey(), date('Y-m-d H:i:s', time() - 86400))
            );
        }

        $decision = ControlPolicy::evaluate($family, $gpio, $value, $limits, $override, $fedToday);
        if ($decision->isMaterial()) {
            throw new ToolException((string) $decision->message, 'refused_by_policy');
        }

        $categories = [];
        if ($decision->isBiological()) {
            if (!$override) {
                throw new ToolException((string) $decision->message . ' Passez override_bio_safeguards=true + motif + confirmation.', 'bio_safeguard');
            }
            $categories[] = ConfirmCategory::BIO_OVERRIDE;
        }

        $restoreMinutes = isset($arguments['restore_after_minutes']) ? (int) $arguments['restore_after_minutes'] : null;
        $isPumpStop = $family === 'ffp3' && (
            ($gpio === 16 && (int) $value === 0)
            || ($gpio === 117 && (int) $value === 2)
        );
        if ($isPumpStop) {
            if ($maintenance) {
                $categories[] = ConfirmCategory::PUMP_MAINTENANCE;
                if (!$override) {
                    throw new ToolException('Mode maintenance : override_bio_safeguards + motif + confirmation requis.', 'bio_safeguard');
                }
            } else {
                if ($limits->pumpStopMaxMinutes <= 0) {
                    throw new ToolException(
                        'Arrêt pompe interdit (MCP_CONTROL_PUMP_STOP_MAX_MINUTES=0). Utilisez maintenance=true + confirmation, ou activez un plafond.',
                        'pump_stop_disabled'
                    );
                }
                if ($restoreMinutes === null || $restoreMinutes < 1 || $restoreMinutes > $limits->pumpStopMaxMinutes) {
                    throw new ToolException(sprintf(
                        'restore_after_minutes requis entre 1 et %d.',
                        $limits->pumpStopMaxMinutes
                    ), 'invalid_arguments');
                }
                // Écriture via forçage OFF
                $gpio = 117;
                $value = 2;
                $isFeed = false;
            }
        }

        $current = EnvironmentScope::run($env, fn (): ?array => $this->repo->output($family === 'ffp3' && $gpio === 117 ? 'ffp3' : $family, $gpio));
        $beforePreview = $current !== null && $current['state'] !== null ? (string) $current['state'] : null;
        $risks = [(string) ($rule['risk'] ?? '')];
        if ($target->isProduction()) {
            $risks[] = 'ATTENTION : table de PRODUCTION ' . $target->table;
        }
        if ($override) {
            $risks[] = 'Levée de garde-fou : ' . $overrideReason;
        }
        if ($maintenance) {
            $risks[] = 'Mode maintenance : PAS de rétablissement automatique.';
        } elseif ($restoreMinutes !== null) {
            $risks[] = sprintf('Rétablissement automatique dans %d min.', $restoreMinutes);
        }

        $pending = $this->confirm->check($this->name(), $arguments, $context, array_values(array_unique($categories)), [
            'cible' => sprintf('%s %s GPIO %d → %s', $family, $env, $gpio, $isFeed ? '+1 repas' : (string) $value),
            'effet' => 'Écriture immédiate via OutputWriter (source web).',
            'risques' => $risks,
        ]);
        if ($pending !== null) {
            return $pending;
        }

        // Annuler un restore si on redémarre la pompe
        if ($family === 'ffp3' && (($gpio === 16 && (int) $value === 1) || ($gpio === 117 && (int) $value === 0))) {
            $this->restores->cancelPendingForTarget($target->physicalKey());
        }

        $result = $this->executor->execute($context, $target, $gpio, $isFeed ? null : $value, $isFeed, [
            'origin' => 'direct',
            'reason' => isset($arguments['reason']) ? (string) $arguments['reason'] : $overrideReason,
            'override_bio' => $override,
        ]);

        $planId = $this->executor->recordDirectAction(
            $context,
            $target,
            $gpio,
            $isFeed,
            $result['before'],
            (string) $result['after'],
            [
                'reason' => $arguments['reason'] ?? null,
                'override' => $override,
                'override_reason' => $overrideReason !== '' ? $overrideReason : null,
            ]
        );

        $restoreAt = null;
        if ($isPumpStop) {
            // Le nouveau minuteur est inséré avant l'annulation des autres : si l'insertion
            // échoue, l'échéance précédente reste en place. Sinon un délai plus court
            // (ou un passage en maintenance) ne peut plus rallumer la pompe trop tôt.
            $newId = null;
            if (!$maintenance && $restoreMinutes !== null) {
                $ts = time() + ($restoreMinutes * 60);
                $newId = $this->restores->schedule(
                    $family,
                    $env,
                    $target->physicalKey(),
                    117,
                    '0',
                    $ts,
                    $context->userId,
                    isset($arguments['reason']) ? (string) $arguments['reason'] : 'arrêt borné MCP'
                );
                // Le CRON remet 117 = 0 puis 16 = 1 : le POST firmware a épinglé 16 à 0 pendant le forçage.
                $restoreAt = date('Y-m-d H:i:s', $ts);
            }
            $this->restores->cancelPendingForTarget($target->physicalKey(), $newId);
        }

        return ToolResult::json([
            'applied' => true,
            'plan_id' => $planId,
            'env' => $env,
            'is_production' => $target->isProduction(),
            'target_table' => $target->table,
            'family' => $family,
            'gpio' => $gpio,
            'before' => $result['before'] ?? $beforePreview,
            'after' => $result['after'],
            'restore_at' => $restoreAt,
            'note' => 'Consigne enregistrée ; l\'ESP32 l\'appliquera à son prochain poll.',
        ]);
    }
}
