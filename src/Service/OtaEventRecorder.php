<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OtaEventRepository;

/**
 * Enregistre les requêtes OTA servies ({@see \App\Controller\Ffp3\OtaFileController}) dans
 * `ota_events`. Jamais bloquant : une erreur d'écriture est journalisée, le binaire est servi.
 */
class OtaEventRecorder
{
    public function __construct(
        private OtaEventRepository $repo,
        private LogService $logger,
    ) {
    }

    /**
     * @param array{
     *   ip?: ?string, path: string, channel: string, kind: string, board?: ?string, firmware_version?: ?string,
     *   range_header?: ?string, http_status: int, bytes_sent?: int, file_size?: ?int, duration_ms?: ?int,
     *   complete?: bool, note?: ?string
     * } $event
     */
    public function record(array $event): void
    {
        try {
            $this->repo->record($event);
        } catch (\Throwable $e) {
            $this->logger->warning('OtaEventRecorder: enregistrement impossible', [
                'path' => $event['path'],
                'error' => $e->getMessage(),
            ]);
        }
        $this->logger->info(sprintf(
            '[ota] %s %s status=%d bytes=%d/%s complete=%s ip=%s board=%s%s',
            $event['kind'],
            $event['path'],
            $event['http_status'],
            $event['bytes_sent'] ?? 0,
            isset($event['file_size']) ? (string) $event['file_size'] : '?',
            ($event['complete'] ?? false) ? 'oui' : 'non',
            $event['ip'] ?? 'n/a',
            $event['board'] ?? '-',
            isset($event['note']) && $event['note'] !== '' ? ' note=' . $event['note'] : ''
        ));
    }
}
