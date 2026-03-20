<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OutputRepository;
use App\Repository\BoardRepository;
use App\Repository\SensorReadRepository;
use App\Service\OutputCacheService;

/**
 * Service de gestion des outputs (GPIO/relais)
 * 
 * Gère la logique métier pour les contrôles à distance des GPIO
 */
class OutputService
{
    /** GPIOs affichés comme indicateurs booléens (0/1) sur la page de contrôle */
    private const BOOLEAN_GPIOS_FOR_INDICATOR = [2, 15, 16, 18, 101, 108, 109, 110, 115];
    /** @var array<string, string> */
    private const PROPERTY_TO_PARAM_NAME = [
        'aqThreshold' => 'aqThr',
        'tankThreshold' => 'taThr',
        'chauffageThreshold' => 'chauff',
        'bouffeMatin' => 'bouffeMatin',
        'bouffeMidi' => 'bouffeMidi',
        'bouffeSoir' => 'bouffeSoir',
        'tempsGros' => 'tempsGros',
        'tempsPetits' => 'tempsPetits',
        'tempsRemplissageSec' => 'tempsRemplissageSec',
        'limFlood' => 'limFlood',
        'mail' => 'mail',
        'mailNotif' => 'mailNotif',
        'WakeUp' => 'WakeUp',
        'FreqWakeUp' => 'FreqWakeUp',
    ];

    public function __construct(
        private OutputRepository $outputRepository,
        private BoardRepository $boardRepository,
        private OutputCacheService $outputCache,
        private SensorReadRepository $sensorReadRepository
    ) {}

    /**
     * Derniers états enregistrés dans la table data (dernière ligne POSTée par l'ESP32).
     * Utilisé pour les témoins "dernier état Data" sur la page de contrôle.
     *
     * @return array{states: array<int, int|null>, readingTime: string|null}
     */
    public function getLastDataStates(): array
    {
        $row = $this->sensorReadRepository->getLastReadings(1);
        $readingTime = isset($row['reading_time']) ? (string) $row['reading_time'] : null;
        $states = [];

        if ($row === []) {
            return ['states' => [], 'readingTime' => null];
        }

        $mapping = OutputSyncService::getGpioMapping();
        foreach ($mapping as $gpio => $property) {
            if (!in_array($gpio, self::BOOLEAN_GPIOS_FOR_INDICATOR, true)) {
                continue;
            }
            if (!array_key_exists($property, $row)) {
                $states[$gpio] = null;
                continue;
            }
            $value = $row[$property];
            if ($value === null || $value === '') {
                $states[$gpio] = null;
                continue;
            }
            if ($property === 'mail' || $property === 'mailNotif') {
                $states[$gpio] = trim((string) $value) !== '' ? 1 : 0;
            } else {
                $states[$gpio] = (int) $value;
            }
        }

        return ['states' => $states, 'readingTime' => $readingTime];
    }

    /**
     * Récupère tous les outputs avec leurs états
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getAllOutputs(): array
    {
        return $this->outputRepository->findAll();
    }

    public function getParametersMap(): array
    {
        $outputs = $this->outputRepository->findAll();
        $parameters = [];
        $parameterMap = $this->buildParameterMap();

        foreach ($outputs as $output) {
            $gpio = (int)($output['gpio'] ?? -1);
            $value = $output['state'] ?? null;

            if (isset($parameterMap[$gpio])) {
                $parameters[$parameterMap[$gpio]] = $value;
            }
        }

        return $parameters;
    }

    /**
     * Récupère uniquement les boards actives pour l'environnement actuel
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getActiveBoardsForCurrentEnvironment(): array
    {
        $table = \App\Config\TableConfig::getOutputsTable();
        return $this->boardRepository->findActiveForEnvironment($table);
    }

    /**
     * Récupère la dernière GPIO modifiée d'une board spécifique
     * 
     * @param string $board Numéro de la board
     * @return array<string, mixed>|null
     */
    public function getLastModifiedGpio(string $board): ?array
    {
        return $this->outputRepository->findLastModifiedGpio($board);
    }

    /**
     * Retourne le statut complet d'une board (dernière requête + dernière GPIO modifiée)
     *
     * @param string $board Nom de la board
     * @return array<string, mixed>|null
     */
    public function getBoardStatus(string $board): ?array
    {
        $boardInfo = $this->boardRepository->findByName($board);
        if ($boardInfo === null) {
            return null;
        }

        $lastGpio = $this->outputRepository->findLastModifiedGpio($board);

        return [
            'board' => $boardInfo['board'] ?? $board,
            'last_request' => $boardInfo['last_request'] ?? null,
            'last_gpio' => $lastGpio,
        ];
    }

    /**
     * Met à jour l'état d'un output par son ID
     * 
     * @param int $id ID de l'output
     * @param int $state Nouvel état (0 ou 1)
     * @param string $modifiedBy Source de la modification ('web', 'esp32', etc.)
     * @return bool Succès de l'opération
     */
    public function updateStateById(int $id, int $state, string $modifiedBy = 'web', bool $isTest = false): bool
    {
        if ($state !== 0 && $state !== 1) {
            return false;
        }
        $result = $this->outputRepository->updateStateById($id, $state, $modifiedBy);
        if ($result) {
            $this->outputCache->invalidateCache();
        }
        return $result;
    }

    /**
     * Met à jour plusieurs paramètres depuis un formulaire
     * 
     * @param array $params Paramètres à mettre à jour
     * @return int Nombre de paramètres mis à jour
     */
    public function updateMultipleParameters(array $params): int
    {
        $updated = $this->outputRepository->updateMultipleParameters($params, 'web');
        $this->outputCache->invalidateCache();
        return $updated;
    }

    /** @return array<int, string> */
    private function buildParameterMap(): array
    {
        $map = [];
        foreach (OutputSyncService::getGpioMapping() as $gpio => $propertyName) {
            if (isset(self::PROPERTY_TO_PARAM_NAME[$propertyName])) {
                $map[$gpio] = self::PROPERTY_TO_PARAM_NAME[$propertyName];
            }
        }
        return $map;
    }
}
