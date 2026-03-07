<?php

namespace App\Service;

use App\Repository\SensorReadRepository;

class SystemHealthService
{
    public function __construct(
        private SensorReadRepository $sensorReadRepo,
        private NotificationService $notifier,
        private LogService $logger
    ) {
    }

    /**
     * Vérifie si le système est en ligne en se basant sur la date de la dernière lecture.
     * Envoie une notification si la dernière lecture est trop ancienne.
     */
    public function checkOnlineStatus(int $maxOfflineSeconds = 3600): void
    {
        $lastReadingDateStr = $this->sensorReadRepo->getLastReadingDate();
        if ($lastReadingDateStr === null) {
            $this->logger->warning('Aucune donnée de capteur trouvée, impossible de vérifier le statut en ligne.');
            // TODO: Notifier ce cas ?
            return;
        }

        $lastReadingTimestamp = strtotime($lastReadingDateStr);
        $now = time();

        if (($now - $lastReadingTimestamp) > $maxOfflineSeconds) {
            $this->logger->critical('Le système semble hors ligne !');
            // TODO: Créer une notification spécifique pour ce cas dans NotificationService
            // $this->notifier->notifySystemOffline();
        } else {
            $this->logger->info('Le système est en ligne.');
        }
    }

    /**
     * Vérifie le niveau du réservoir et envoie une alerte si le niveau est bas.
     * Note: La logique exacte de "niveau bas" n'était pas claire dans le code legacy,
     * elle est à définir plus précisément.
     */
    public function checkTankLevel(): void
    {
        // Cette fonction est un placeholder. La logique de `checkTankLevel`
        // dans ffp3-config.php semble incomplete ou redondante avec d'autres vérifications.
        // À implémenter si un besoin spécifique est clarifié.
        $this->logger->info('Vérification du niveau du réservoir (logique à définir).');

        // Exemple de ce que ça pourrait être :
        /*
        $lastReading = $this->sensorReadRepo->getLastReadings();
        if ($lastReading && $lastReading['EauReserve'] < 15) {
             $this->notifier->notifyLowTankLevel();
        }
        */
    }
} 