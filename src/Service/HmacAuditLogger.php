<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ServerSettingsRepository;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Journal d'audit des tentatives d'authentification HMAC firmware.
 *
 * Activable/désactivable via la supervision (BDD `serverSettings`) avec repli
 * `.env` `HMAC_AUDIT_LOG`. Fichier dédié (non public) : `hmac-audit.log` à côté
 * du cronlog, ou `HMAC_AUDIT_LOG_PATH`.
 *
 * Préfixe `[hmac-audit]` pour filtrage et lecture admin.
 */
final class HmacAuditLogger
{
    public const PREFIX = '[hmac-audit]';

    private const DEFAULT_FILENAME = 'hmac-audit.log';

    private Logger $logger;

    private string $logFile;

    private ?bool $enabledCache = null;

    public function __construct(
        private readonly ServerSettingsRepository $settings,
        private readonly ?OperationalSettingsService $operationalSettings = null,
    ) {
        $this->logFile = $this->resolveLogPath();

        $this->logger = new Logger('hmac-audit');
        $lineFormatter = new LineFormatter("[%datetime%] [%level_name%] %message%%context%\n", 'Y-m-d H:i:s', true, true);
        $lineFormatter->ignoreEmptyContextAndExtra(true);

        $rotateDays = $this->resolveRotateDays();
        $handler = $rotateDays > 0
            ? new RotatingFileHandler($this->logFile, $rotateDays, Logger::INFO)
            : new StreamHandler($this->logFile, Logger::INFO);
        $handler->setFormatter($lineFormatter);
        $this->logger->pushHandler($handler);
    }

    public function getLogFilePath(): string
    {
        return $this->logFile;
    }

    public function isEnabled(): bool
    {
        if ($this->enabledCache !== null) {
            return $this->enabledCache;
        }

        $envDefault = filter_var($_ENV['HMAC_AUDIT_LOG'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

        try {
            $this->enabledCache = $this->settings->getBool(
                ServerSettingsRepository::KEY_HMAC_AUDIT_ENABLED,
                $envDefault
            );
        } catch (\Throwable) {
            $this->enabledCache = $envDefault;
        }

        return $this->enabledCache;
    }

    public function setEnabled(bool $enabled, ?string $updatedBy): void
    {
        $this->settings->setBool(ServerSettingsRepository::KEY_HMAC_AUDIT_ENABLED, $enabled, $updatedBy);
        $this->enabledCache = $enabled;
    }

    public function clearEnabledCache(): void
    {
        $this->enabledCache = null;
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function record(
        string $component,
        string $result,
        string $authMode,
        array $context = [],
        ?string $reason = null
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $payload = array_merge([
            'component' => $component,
            'result' => $result,
            'auth_mode' => $authMode,
        ], $context);

        if ($reason !== null && $reason !== '') {
            $payload['reason'] = $reason;
        }

        if ($this->shouldMaskIp() && isset($payload['ip']) && is_string($payload['ip'])) {
            $payload['ip'] = $this->maskIp($payload['ip']);
        }

        $message = self::PREFIX
            . ' result=' . $result
            . ' component=' . $component
            . ' auth_mode=' . $authMode;
        if ($reason !== null && $reason !== '') {
            $message .= ' reason=' . $reason;
        }

        $level = $result === 'ok' ? Logger::INFO : Logger::WARNING;
        $this->logger->log($level, $message, $payload);
    }

    /**
     * Lit les dernières lignes du journal (ordre chronologique ascendant).
     *
     * @return list<array{line: string, raw: string}>
     */
    public function readTail(int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, min($limit, 500));
        $offset = max(0, $offset);

        $filtered = $this->collectAuditLines();
        $slice = array_slice($filtered, -($limit + $offset));
        if ($offset > 0) {
            $slice = array_slice($slice, 0, max(0, count($slice) - $offset));
        }

        $entries = [];
        foreach ($slice as $line) {
            $entries[] = [
                'line' => $this->formatLineForDisplay($line),
                'raw' => $line,
            ];
        }

        return $entries;
    }

    public function countEntries(): int
    {
        return count($this->collectAuditLines());
    }
    /**
     * @return list<string>
     */
    private function collectAuditLines(): array
    {
        $lines = [];
        foreach ($this->resolveLogFiles() as $file) {
            if (!is_readable($file)) {
                continue;
            }
            $chunk = @file($file, FILE_IGNORE_NEW_LINES);
            if ($chunk === false) {
                continue;
            }
            foreach ($chunk as $line) {
                if (str_contains($line, self::PREFIX)) {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * Fichiers journal (courant + rotations Monolog `*-YYYY-MM-DD.log`).
     *
     * @return list<string>
     */
    private function resolveLogFiles(): array
    {
        $path = $this->logFile;
        $dir = dirname($path);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'log';
        $pattern = $dir . DIRECTORY_SEPARATOR . $base . '*' . ($ext !== '' ? '.' . $ext : '');
        $files = glob($pattern);
        if ($files === false || $files === []) {
            return is_file($path) ? [$path] : [];
        }
        sort($files);

        return $files;
    }

    private function resolveRotateDays(): int
    {
        if ($this->operationalSettings !== null) {
            $hmacRotate = $this->operationalSettings->optionalInt('HMAC_AUDIT_ROTATE_DAYS');
            if ($hmacRotate !== null) {
                return $hmacRotate;
            }

            return $this->operationalSettings->int('LOG_ROTATE_DAYS', 14);
        }

        return (int) ($_ENV['HMAC_AUDIT_ROTATE_DAYS'] ?? $_ENV['LOG_ROTATE_DAYS'] ?? 14);
    }

    private function shouldMaskIp(): bool
    {
        return $this->operationalSettings?->bool('LOG_MASK_IP', true)
            ?? filter_var($_ENV['LOG_MASK_IP'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
    }

    private function resolveLogPath(): string
    {
        $explicit = trim((string) ($_ENV['HMAC_AUDIT_LOG_PATH'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $cronlog = trim((string) ($_ENV['LOG_FILE_PATH'] ?? 'cronlog.txt'));
        $dir = dirname($cronlog);
        if ($dir === '.' || $dir === '') {
            return self::DEFAULT_FILENAME;
        }

        return $dir . DIRECTORY_SEPARATOR . self::DEFAULT_FILENAME;
    }

    private function formatLineForDisplay(string $line): string
    {
        // Retire le préfixe Monolog pour l'affichage compact.
        if (preg_match('/\] \[INFO\] (.+)$/', $line, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/\] \[WARNING\] (.+)$/', $line, $m) === 1) {
            return $m[1];
        }

        return $line;
    }

    private function maskIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '' || $ip === 'n/a') {
            return 'n/a';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $segments = explode(':', $ip);
            $kept = array_slice($segments, 0, 3);

            return implode(':', $kept) . '::/80';
        }

        return 'invalid';
    }
}
