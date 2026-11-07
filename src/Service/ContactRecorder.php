<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\TableConfig;
use App\Repository\DeviceContactRepository;

/**
 * Enregistre chaque contact d'un module (POST, heartbeat, rapport OTA) dans `device_contacts`,
 * accepté comme rejeté, et calcule le décalage d'horloge `ts_received − maintenant`.
 *
 * Appelé depuis les contrôleurs d'ingestion. JAMAIS bloquant : une table absente est créée,
 * toute autre erreur est journalisée et avalée — la traçabilité ne doit pas faire échouer
 * la réception d'une mesure. N'enregistre ni corps de requête ni secret ni e-mail.
 *
 * Fournit aussi l'heure serveur à renvoyer au firmware ({@see serverTimeHeaders()}) pour qu'il
 * se recale quand NTP échoue : en-têtes `Date` (RFC 7231) et `X-Server-Ts` (epoch).
 */
class ContactRecorder
{
    public function __construct(
        private DeviceContactRepository $repo,
        private LogService $logger,
    ) {
    }

    /**
     * @param array{
     *   family: string, kind: string, accepted: bool, http_status: int, auth_mode?: ?string,
     *   reject_reason?: ?string, ts_received?: string|int|null, firmware_version?: ?string,
     *   post_id?: ?string, ip?: ?string, env?: ?string
     * } $contact
     */
    public function record(array $contact): void
    {
        $tsReceived = null;
        $offset = null;
        if (isset($contact['ts_received']) && $contact['ts_received'] !== '' && is_numeric($contact['ts_received'])) {
            $tsReceived = (int) $contact['ts_received'];
            $offset = $tsReceived - time();
            // Valeur aberrante (horloge à 1970 ou en 2050) : on garde le brut, l'offset est borné.
            if (abs($offset) > 10 * 366 * 86400) {
                $offset = $offset > 0 ? 10 * 366 * 86400 : -10 * 366 * 86400;
            }
        }

        try {
            $this->repo->record([
                'family' => $contact['family'],
                'env' => $contact['env'] ?? TableConfig::getEnvironment(),
                'kind' => $contact['kind'],
                'accepted' => $contact['accepted'],
                'http_status' => $contact['http_status'],
                'auth_mode' => $contact['auth_mode'] ?? null,
                'reject_reason' => $contact['reject_reason'] ?? null,
                'ts_received' => $tsReceived,
                'clock_offset_s' => $offset,
                'firmware_version' => $contact['firmware_version'] ?? null,
                'post_id' => $contact['post_id'] ?? null,
                'ip' => $contact['ip'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('ContactRecorder: enregistrement impossible', [
                'family' => $contact['family'],
                'kind' => $contact['kind'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * En-têtes à ajouter à TOUTE réponse firmware (acceptée ou rejetée) : l'heure serveur.
     *
     * @return array<string, string>
     */
    public static function serverTimeHeaders(?int $now = null): array
    {
        $now ??= time();

        return [
            'Date' => gmdate('D, d M Y H:i:s', $now) . ' GMT',
            'X-Server-Ts' => (string) $now,
        ];
    }
}
