<?php

declare(strict_types=1);

namespace App\OAuth;

/**
 * Récupération HTTP minimale (documents CIMD). Interface pour les tests (client mocké).
 */
interface HttpFetcher
{
    /**
     * @param string|null $pinnedIp IP à utiliser pour l'hôte de l'URL (anti DNS-rebinding :
     *                              l'adresse validée est celle réellement contactée)
     * @return array{status: int, body: string, content_type: string}
     *
     * @throws \RuntimeException en cas d'échec réseau, de dépassement de délai ou de taille
     */
    public function get(string $url, int $timeoutSeconds, int $maxBytes, ?string $pinnedIp = null): array;
}
