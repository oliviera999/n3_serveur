<?php

declare(strict_types=1);

namespace App\Command;

use App\Mcp\Sql\MigrationRunner;
use App\Notification\Severity;
use App\Service\ControlAuditLogger;
use App\Service\LogService;
use App\Service\NotificationService;
use App\Service\OperationalSettingsService;
use App\Util\JsonFileStore;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * CRON : le serveur applique LUI-MÊME les migrations ADDITIVES du dépôt (`migrations/*.sql`) restées
 * en attente — typiquement juste après le déploiement git (chaque minute) d'une version qui écrit dans
 * une nouvelle colonne (ex. 2026_11_ffp3_echo_diag.sql : diagnostic d'écho ultrason jeté tant que les
 * colonnes manquaient).
 *
 * Bornes :
 *  - uniquement les fichiers ENTIÈREMENT additifs ({@see \App\Mcp\Sql\MigrationFile} : ADD COLUMN,
 *    ADD INDEX, CREATE TABLE, CREATE INDEX) ; DROP / UPDATE / DELETE / MODIFY… restent manuels ;
 *  - chaque instruction ré-évaluée contre le schéma réel : rejouer est sans effet ;
 *  - examen quand le dossier migrations/ change (déploiement) ou au plus toutes les
 *    AUTO_MIGRATE_INTERVAL_SECONDS (défaut 15 min) ;
 *  - un fichier en échec n'est retenté qu'après 24 h ou dès que son contenu change (pas de DDL
 *    rejoué chaque minute) ; AUTO_MIGRATE_EXCLUDE (CSV de noms de fichiers) les écarte ;
 *  - AUTO_MIGRATE_ENABLED=false (réglage BDD ou .env) coupe tout.
 * Journal [auto-migrate], audit `cron:migrate`, e-mail système (info si appliqué, alerte si échec).
 */
class AutoMigrateCommand
{
    public const RETRY_AFTER_FAILURE_SECONDS = 86400;
    private const DEFAULT_INTERVAL_SECONDS = 900;

    private JsonFileStore $store;

    public function __construct(
        private MigrationRunner $runner,
        private LogService $logger,
        string $stateFile,
        private ?OperationalSettingsService $settings = null,
        private ?ControlAuditLogger $audit = null,
        private ?NotificationService $notifier = null,
    ) {
        $this->store = new JsonFileStore($stateFile);
    }

    public function isEnabled(): bool
    {
        return $this->settings?->bool('AUTO_MIGRATE_ENABLED', true)
            ?? filter_var($_ENV['AUTO_MIGRATE_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param bool $force Ignorer l'échéance et la mémoire des échecs (CLI bin/migrate.php).
     * @return array{checked: bool, applied: list<array<string, mixed>>, failed: list<array<string, mixed>>, skipped: list<array{file: string, reason: string}>}
     */
    public function execute(bool $force = false, ?int $now = null): array
    {
        $result = ['checked' => false, 'applied' => [], 'failed' => [], 'skipped' => []];
        if (!$force && !$this->isEnabled()) {
            return $result;
        }
        $now ??= time();
        $state = $this->store->load();
        $catalog = $this->runner->catalog();
        $fingerprint = $catalog->fingerprint();
        $lastCheck = (int) ($state['last_check'] ?? 0);
        $due = $force
            || ($state['fingerprint'] ?? '') !== $fingerprint
            || $now - $lastCheck >= $this->intervalSeconds();
        if (!$due) {
            return $result;
        }
        $result['checked'] = true;

        /** @var array<string, array{hash: string, at: int, error: string}> $failures */
        $failures = is_array($state['failures'] ?? null) ? $state['failures'] : [];
        $excluded = $this->excluded();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/cron/auto-migrate');

        foreach ($this->runner->pendingFiles() as $file) {
            if (in_array($file, $excluded, true)) {
                $result['skipped'][] = ['file' => $file, 'reason' => 'exclu (AUTO_MIGRATE_EXCLUDE)'];
                continue;
            }
            $inspected = $catalog->inspect($file);
            $blocker = MigrationRunner::blocker($inspected);
            if ($blocker !== null) {
                $result['skipped'][] = ['file' => $file, 'reason' => $blocker['message']];
                continue;
            }
            $hash = $catalog->hash($file);
            $previous = $failures[$file] ?? null;
            if (!$force && is_array($previous) && ($previous['hash'] ?? '') === $hash
                && $now - (int) ($previous['at'] ?? 0) < self::RETRY_AFTER_FAILURE_SECONDS) {
                $result['skipped'][] = ['file' => $file, 'reason' => 'échec récent, nouvel essai après 24 h ou correction du fichier'];
                continue;
            }

            $outcome = $this->runner->apply($file);
            $this->logger->info('[auto-migrate] file={file} ok={ok} executed={n} status_after={st}', [
                'file' => $file,
                'ok' => $outcome['ok'] ? '1' : '0',
                'n' => (string) $outcome['executed'],
                'st' => $outcome['status_after'],
            ]);
            $this->audit?->logAction($request, 'cron:migrate', $file, ['results' => $outcome['results']], $outcome['ok'], null, 'cron');

            if ($outcome['ok']) {
                unset($failures[$file]);
                $result['applied'][] = $outcome;
                continue;
            }
            $error = '';
            foreach ($outcome['results'] as $r) {
                if (($r['status'] ?? '') === 'error') {
                    $error = (string) ($r['table'] ?? '') . ' : ' . (string) ($r['error'] ?? '');
                }
            }
            $failures[$file] = ['hash' => $hash, 'at' => $now, 'error' => $error];
            $result['failed'][] = $outcome;
        }

        $this->store->save([
            'fingerprint' => $fingerprint,
            'last_check' => $now,
            'failures' => $failures,
            'last_applied' => array_column($result['applied'], 'file') ?: ($state['last_applied'] ?? []),
        ]);
        $this->notify($result);

        return $result;
    }

    private function intervalSeconds(): int
    {
        $value = $this->settings?->int('AUTO_MIGRATE_INTERVAL_SECONDS', self::DEFAULT_INTERVAL_SECONDS)
            ?? (int) ($_ENV['AUTO_MIGRATE_INTERVAL_SECONDS'] ?? self::DEFAULT_INTERVAL_SECONDS);

        return max(60, $value);
    }

    /**
     * @return list<string>
     */
    private function excluded(): array
    {
        $raw = $this->settings?->string('AUTO_MIGRATE_EXCLUDE', '') ?? (string) ($_ENV['AUTO_MIGRATE_EXCLUDE'] ?? '');

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @param array{applied: list<array<string, mixed>>, failed: list<array<string, mixed>>} $result
     */
    private function notify(array $result): void
    {
        if ($this->notifier === null || ($result['applied'] === [] && $result['failed'] === [])) {
            return;
        }
        $lines = [];
        foreach ($result['applied'] as $o) {
            $lines[] = sprintf('✅ %s : %d instruction(s) appliquée(s) (état : %s)', (string) $o['file'], (int) $o['executed'], (string) $o['status_after']);
        }
        foreach ($result['failed'] as $o) {
            $errors = array_filter((array) $o['results'], static fn (array $r): bool => ($r['status'] ?? '') === 'error');
            $first = reset($errors);
            $lines[] = sprintf(
                '❌ %s : échec%s — nouvel essai dans 24 h ou dès correction du fichier',
                (string) $o['file'],
                is_array($first) ? ' sur ' . (string) ($first['table'] ?? '?') . ' (' . (string) ($first['error'] ?? '') . ')' : ''
            );
        }
        $failed = $result['failed'] !== [];
        try {
            $this->notifier->sendScheduledReport(
                $failed ? Severity::Alert : Severity::Info,
                $failed ? 'Migration automatique de la base en échec' : 'Migration automatique de la base appliquée',
                implode("\n", $lines),
                'server:auto-migrate:' . sha1(implode('|', $lines))
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[auto-migrate] notification impossible : ' . $e->getMessage());
        }
    }
}
