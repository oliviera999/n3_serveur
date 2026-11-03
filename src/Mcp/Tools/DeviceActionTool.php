<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Confirm\ConfirmationGate;
use App\Mcp\Confirm\ConfirmCategory;
use App\Mcp\Control\ControlTarget;
use App\Mcp\Control\OutputWriter;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\EnvironmentScope;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;
use App\Service\ControlAuditLogger;
use App\Service\OutputCacheService;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `iot_device_action` — reset distant (GPIO 110) ou déclenchement OTA (ffp3).
 */
final class DeviceActionTool extends AbstractTool
{
    public function __construct(
        McpConfig $config,
        private OutputWriter $writer,
        private OutputCacheService $outputCache,
        private ConfirmationGate $confirm,
        private ControlAuditLogger $audit,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_device_action';
    }

    public function title(): string
    {
        return 'Action appareil sensible (reset / OTA)';
    }

    public function description(): string
    {
        return 'ADMIN — reset distant (GPIO 110 = 1) ou demande de vérification OTA (ffp3). '
            . 'Exige MCP_DEVICE_ACTIONS_ENABLED et une confirmation par code.';
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
        return $this->config->isDeviceActionsEnabled() && $context->isAdmin();
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
            'action' => [
                'type' => 'string',
                'enum' => ['reset', 'ota_check'],
                'description' => 'reset = GPIO 110 ; ota_check = trigger OTA FFP3.',
            ],
            'family' => $this->familyProperty(false),
            'env' => $this->envProperty(true),
            'reason' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 200],
            'confirmation_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
            'confirmation_code' => ['type' => 'string', 'pattern' => '^[0-9]{6}$'],
        ], ['action', 'family', 'reason']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        AdminGuard::assertAdmin($context);
        $action = (string) $arguments['action'];
        $family = (string) $arguments['family'];
        $env = $this->resolveEnv($arguments, true);
        $reason = (string) $arguments['reason'];
        $target = ControlTarget::resolve($family, $env);
        $target->assertAllowed($this->config);

        $category = $action === 'ota_check' ? ConfirmCategory::OTA : ConfirmCategory::RESET;
        if ($action === 'ota_check' && $family !== 'ffp3') {
            throw new ToolException('ota_check n\'existe que pour ffp3.', 'refused_by_policy');
        }

        $pending = $this->confirm->check($this->name(), $arguments, $context, [$category], [
            'cible' => sprintf('%s %s (%s)', $family, $env, $target->table),
            'effet' => $action === 'reset'
                ? 'GPIO 110 = 1 (redémarrage ESP32 au prochain poll)'
                : 'Demande de vérification OTA one-shot',
            'risques' => [
                $action === 'reset'
                    ? 'Interruption temporaire des mesures et de la régulation.'
                    : 'L\'ESP32 peut télécharger et flasher un firmware au prochain cycle.',
                'Motif : ' . $reason,
            ],
        ]);
        if ($pending !== null) {
            return $pending;
        }

        $request = $context->request ?? (new ServerRequestFactory())->createServerRequest('POST', '/mcp');
        $who = 'mcp:' . $context->username;

        if ($action === 'reset') {
            EnvironmentScope::run($env, function () use ($family): void {
                $after = $this->writer->write($family, 110, 1, 0);
                if ($after === null) {
                    throw new ToolException('Écriture resetMode échouée.', 'write_failed');
                }
            });
            $this->audit->logAction($request, 'mcp:' . $family, 'reset', [
                'env' => $env,
                'gpio' => 110,
                'reason' => $reason,
            ], true, null, $who);

            return ToolResult::json([
                'applied' => true,
                'action' => 'reset',
                'family' => $family,
                'env' => $env,
                'gpio' => 110,
                'after' => '1',
            ]);
        }

        EnvironmentScope::run($env, function (): void {
            $this->outputCache->setTriggerOtaCheckRequested();
        });
        $this->audit->logAction($request, 'mcp:ffp3', 'trigger_ota_check', [
            'env' => $env,
            'reason' => $reason,
        ], true, null, $who);

        return ToolResult::json([
            'applied' => true,
            'action' => 'ota_check',
            'family' => 'ffp3',
            'env' => $env,
            'note' => 'Le prochain GET state firmware recevra triggerOtaCheck: true une fois.',
        ]);
    }
}
