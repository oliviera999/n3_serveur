<?php

declare(strict_types=1);

namespace App\Command;

use App\Mcp\Control\OutputWriter;
use App\Mcp\Support\EnvironmentScope;
use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\McpRestoreRepository;
use App\Service\ControlAuditLogger;
use App\Service\LogService;
use App\Service\NotificationService;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * CRON (chaque minute) : rétablit les pompes aquarium après un arrêt MCP borné.
 * À l'échéance : GPIO 117 = 0 (auto) puis GPIO 16 = 1 (ON).
 */
class McpRestoreCommand
{
    public function __construct(
        private McpRestoreRepository $repo,
        private OutputWriter $writer,
        private ControlAuditLogger $audit,
        private NotificationService $notifications,
        private LogService $logger,
    ) {
    }

    /**
     * @return array{done: int, failed: int, purged: int}
     */
    public function execute(): array
    {
        $done = 0;
        $failed = 0;
        try {
            $due = $this->repo->duePending();
        } catch (\Throwable $e) {
            $this->logger->info('Rétablissements MCP ignorés (tables absentes ?) : ' . $e->getMessage());

            return ['done' => 0, 'failed' => 0, 'purged' => 0];
        }

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/cron/mcp-restore');
        foreach ($due as $row) {
            $id = (int) $row['id'];
            $family = (string) $row['family'];
            $env = (string) $row['env'];
            try {
                EnvironmentScope::run($env, function () use ($family): void {
                    $this->writer->write($family, 117, 0, 0);
                    $this->writer->write($family, 16, 1, 0);
                });
                $this->repo->markDone($id);
                $this->audit->logAction($request, 'mcp:' . $family, 'restore', [
                    'env' => $env,
                    'restore_id' => $id,
                    'gpio' => [117, 16],
                ], true, null, 'cron:mcp-restore');
                ++$done;
            } catch (\Throwable $e) {
                $this->repo->markFailed($id, $e->getMessage());
                $this->audit->logAction($request, 'mcp:' . $family, 'restore', [
                    'env' => $env,
                    'restore_id' => $id,
                ], false, $e->getMessage(), 'cron:mcp-restore');
                $this->notifications->sendImmediateAlert(
                    Severity::Critical,
                    NotificationCategory::System,
                    strtoupper($family),
                    'Échec rétablissement pompe MCP',
                    'Le rétablissement automatique de la pompe aquarium a échoué (id=' . $id
                        . ', env=' . $env . ') : ' . $e->getMessage(),
                    'mcp:restore-fail:' . $id
                );
                ++$failed;
            }
        }

        $purged = 0;
        try {
            $purged = $this->repo->purgeOld(30);
        } catch (\Throwable) {
        }

        if ($done + $failed > 0) {
            $this->logger->info('[mcp-restore] done={done} failed={failed} purged={purged}', [
                'done' => (string) $done,
                'failed' => (string) $failed,
                'purged' => (string) $purged,
            ]);
        }

        return ['done' => $done, 'failed' => $failed, 'purged' => $purged];
    }
}
