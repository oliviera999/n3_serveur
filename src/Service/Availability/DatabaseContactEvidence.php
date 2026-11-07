<?php

declare(strict_types=1);

namespace App\Service\Availability;

use App\Repository\DeviceContactRepository;
use App\Repository\HeartbeatMonitorRepository;
use App\Repository\MspSensorRepository;
use App\Repository\N3ppSensorRepository;
use App\Repository\SensorReadRepository;
use App\Service\DeviceHealthService;
use App\Service\OfflineThresholdResolver;

/**
 * Preuves de contact lues en base : tables de mesures (POST acceptés), tables heartbeat,
 * et — quand la migration `2026_11_contact_diagnostics.sql` est appliquée — le journal
 * `device_contacts` qui distingue les contacts authentifiés des contacts seulement contrôlés
 * par CRC (heartbeat FFP3 legacy).
 *
 * Tolérante aux pannes : une table absente ou illisible donne une liste vide / null, jamais
 * une exception (la supervision ne doit pas tomber sur une migration en retard).
 */
final class DatabaseContactEvidence implements ContactEvidenceSource
{
    /** Lignes de mesures relues pour reconstituer les rafales (≈ 48 h à 10 POST / rafale). */
    public const DATA_ROWS = 400;
    public const HEARTBEAT_ROWS = 120;

    public function __construct(
        private SensorReadRepository $ffp3Data,
        private N3ppSensorRepository $n3ppData,
        private MspSensorRepository $mspData,
        private HeartbeatMonitorRepository $heartbeats,
        private OfflineThresholdResolver $thresholds,
        private ?DeviceContactRepository $contacts = null,
    ) {
    }

    public function recentContacts(string $family): array
    {
        $family = strtoupper($family);
        $out = [];
        try {
            $dates = match ($family) {
                'FFP3' => $this->ffp3Data->getRecentReadingTimes(self::DATA_ROWS),
                'N3PP' => $this->n3ppData->getRecentReadingTimes(self::DATA_ROWS),
                'MSP1' => $this->mspData->getRecentReadingTimes(self::DATA_ROWS),
                default => [],
            };
            foreach ($dates as $d) {
                $ts = strtotime($d);
                if ($ts !== false) {
                    $out[] = $ts;
                }
            }
        } catch (\Throwable) {
        }
        try {
            $table = $this->heartbeatTable($family);
            if ($table !== null) {
                foreach ($this->heartbeats->getRecentHeartbeatDates($table, self::HEARTBEAT_ROWS) as $d) {
                    $ts = strtotime($d);
                    if ($ts !== false) {
                        $out[] = $ts;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return $out;
    }

    public function lastAuthenticatedContact(string $family): ?int
    {
        $family = strtoupper($family);
        // 1. Journal des contacts (auth réellement vérifiée), si la table existe.
        if ($this->contacts !== null) {
            try {
                $ts = $this->contacts->lastAcceptedContact(strtolower($family), true);
                if ($ts !== null) {
                    return $ts;
                }
            } catch (\Throwable) {
            }
        }
        // 2. Repli : une ligne de mesures n'est insérée qu'après authentification (HMAC ou api_key).
        try {
            $date = match ($family) {
                'FFP3' => $this->ffp3Data->getLastReadingDate(),
                'N3PP' => $this->n3ppData->getLastReadingDate(),
                'MSP1' => $this->mspData->getLastReadingDate(),
                default => null,
            };
            if (is_string($date) && $date !== '') {
                $ts = strtotime($date);

                return $ts === false ? null : $ts;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    public function expectedPeriodSeconds(string $family): ?int
    {
        try {
            return $this->thresholds->expectedSleepSeconds(strtoupper($family));
        } catch (\Throwable) {
            return null;
        }
    }

    private function heartbeatTable(string $family): ?string
    {
        foreach (DeviceHealthService::defaultFamilies() as $entry) {
            if ($entry['family'] === $family) {
                return $entry['table'];
            }
        }

        return null;
    }
}
