<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Confirm\ConfirmationGate;
use App\Mcp\Confirm\ConfirmCategory;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;
use App\Service\ControlAuditLogger;
use App\Service\GalleryTrashService;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `iot_gallery_delete` — mise à la corbeille ou purge définitive de photos.
 */
final class GalleryDeleteTool extends AbstractTool
{
    private const MAX_FILES = 50;

    public function __construct(
        McpConfig $config,
        private GalleryTrashService $trash,
        private ConfirmationGate $confirm,
        private ControlAuditLogger $audit,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_gallery_delete';
    }

    public function title(): string
    {
        return 'Supprimer des photos de galerie';
    }

    public function description(): string
    {
        return 'ADMIN — met à la corbeille (trash) ou purge définitivement des photos. '
            . 'Exige MCP_DEVICE_ACTIONS_ENABLED et confirmation par code. Maximum 50 fichiers / appel.';
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
            'openWorldHint' => false,
        ];
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'mode' => ['type' => 'string', 'enum' => ['trash', 'purge']],
            'slug' => [
                'type' => 'string',
                'enum' => ['ffp3', 'n3pp', 'msp1'],
                'description' => 'Galerie cible.',
            ],
            'filenames' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'maxLength' => 200],
                'minItems' => 1,
                'maxItems' => self::MAX_FILES,
            ],
            'reason' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 200],
            'confirmation_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
            'confirmation_code' => ['type' => 'string', 'pattern' => '^[0-9]{6}$'],
        ], ['mode', 'slug', 'filenames', 'reason']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        AdminGuard::assertAdmin($context);
        $mode = (string) $arguments['mode'];
        $slug = (string) $arguments['slug'];
        /** @var list<string> $filenames */
        $filenames = array_values(array_filter(
            array_map('strval', $arguments['filenames'] ?? []),
            static fn (string $f): bool => $f !== ''
        ));
        if ($filenames === [] || count($filenames) > self::MAX_FILES) {
            throw new ToolException('1 à ' . self::MAX_FILES . ' fichiers requis.', 'invalid_arguments');
        }
        $reason = (string) $arguments['reason'];

        $pending = $this->confirm->check($this->name(), $arguments, $context, [ConfirmCategory::PHOTO_DELETE], [
            'cible' => 'galerie ' . $slug,
            'effet' => $mode . ' de ' . count($filenames) . ' fichier(s)',
            'risques' => [
                $mode === 'purge' ? 'Suppression définitive (irrécupérable).' : 'Mise à la corbeille (restorable).',
                'Motif : ' . $reason,
            ],
        ]);
        if ($pending !== null) {
            return $pending;
        }

        $ok = 0;
        $failed = [];
        foreach ($filenames as $filename) {
            $basename = basename($filename);
            $success = $mode === 'purge'
                ? $this->trash->purge($slug, $basename)
                : $this->trash->moveToTrash($slug, $basename, 'mcp:' . $reason);
            if ($success) {
                ++$ok;
            } else {
                $failed[] = $basename;
            }
        }

        $request = $context->request ?? (new ServerRequestFactory())->createServerRequest('POST', '/mcp');
        $this->audit->logAction($request, 'mcp:gallery', $mode, [
            'slug' => $slug,
            'count' => $ok,
            'failed' => count($failed),
            'reason' => $reason,
        ], $failed === [], $failed === [] ? null : 'partial', 'mcp:' . $context->username);

        return ToolResult::json([
            'applied' => true,
            'mode' => $mode,
            'slug' => $slug,
            'deleted' => $ok,
            'failed' => $failed,
        ]);
    }
}
