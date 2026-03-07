<?php

namespace App\Service;

use App\Repository\SensorReadRepository;

/**
 * Service de surveillance de l'état de santé du système.
 * Permet de vérifier si le système est en ligne (données récentes) et d'alerter en cas de problème.
 * Peut être enrichi pour surveiller d'autres indicateurs (niveau d'eau, batterie, etc.).
 */
class SystemHealthService
{
    /**
     * @param SensorReadRepository $sensorReadRepo Accès aux données capteurs
     * @param NotificationService  $notifier       Service de notification (e-mail)
     * @param LogService           $logger         Service de log
     */
    public function __construct(
        private SensorReadRepository $sensorReadRepo,
        private NotificationService $notifier,
        private LogService $logger
    ) {
    }

    /**
     * Vérifie si le système est en ligne en se basant sur la date de la dernière lecture capteur.
     * Si la dernière donnée est trop ancienne, logge une alerte critique (et peut notifier).
     *
     * @param int $maxOfflineSeconds Délai max (en secondes) sans nouvelle donnée avant alerte
     */
    public function checkOnlineStatus(int $maxOfflineSeconds = 3600): void
    {
        $lastReadingDateStr = $this->sensorReadRepo->getLastReadingDate();
        if ($lastReadingDateStr === null) {
            $this->logger->warning('Aucune donnée de capteur trouvée, impossible de vérifier le statut en ligne.');
            // Notification dédiée
            $this->notifier->notifyNoSensorData();
            return;
        }

        $lastReadingTimestamp = strtotime($lastReadingDateStr);
        $now = time();

        if (($now - $lastReadingTimestamp) > $maxOfflineSeconds) {
            $this->logger->critical('Le système semble hors ligne !');
            $this->notifier->notifySystemOffline();
        } else {
            $this->logger->info('Le système est en ligne.');
        }
    }

    /**
     * Vérifie le niveau du réservoir et envoie une alerte si le niveau est bas.
     * Note : la logique exacte de "niveau bas" est à définir selon les besoins métier.
     * Cette méthode est un exemple de point d'extension pour la supervision.
     */
    public function checkTankLevel(): void
    {
        // Cette fonction est un placeholder. La logique de `checkTankLevel`
        // dans ffp3-config.php semble incomplète ou redondante avec d'autres vérifications.
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