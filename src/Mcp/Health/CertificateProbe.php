<?php

declare(strict_types=1);

namespace App\Mcp\Health;

/**
 * Lecture de la date d'expiration du certificat TLS d'un hôte (injectable pour les tests).
 */
interface CertificateProbe
{
    /**
     * @return array{ok: bool, host: string, expires_at?: string, days_remaining?: int, issuer?: string|null, subject?: string|null, error?: string}
     */
    public function probe(string $host, int $port = 443): array;
}
