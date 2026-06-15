<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\BoardRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;

/**
 * Service de gestion des outputs (GPIO/relais)
 *
 * Gère la logique métier pour les contrôles à distance des GPIO
 */
class OutputService
{
    /** GPIOs affichés comme indicateurs booléens (0/1) sur la page de contrôle */
    private const BOOLEAN_GPIOS_FOR_INDICATOR = [2, 15, 16, 18, 101, 108, 109, 110, 115];

    /**
     * GPIO de forçage serveur de la pompe aquarium (page contrôle / sync JSON).
     * Présent dans la table outputs mais absent du mapping canonique GPIO→propriété.
     */
    private const AQUARIUM_PUMP_FORCE_GPIO = 117;

    public function __construct(
        private OutputRepository $outputRepository,
        private BoardRepository $boardRepository,
        private SensorReadRepository $sensorReadRepository
    ) {
    }

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

    public function isAquariumPumpForceEnabled(): bool
    {
        return $this->outputRepository->getAquariumPumpForceState();
    }

    /**
     * Crée / répare la ligne GPIO 117 avant lecture état (GET state) ou affichage.
     */
    public function ensureAquariumPumpForceOutputRow(): void
    {
        $this->outputRepository->ensureAquariumPumpForceRowExists();
    }

    public function getParametersMap(): array
    {
        $parameterMap = $this->buildParameterMap();
        // Filtrage SQL ciblé (IN ...) plutôt que findAll() suivi d'un tri PHP :
        // seuls les GPIO présents dans le mapping de paramètres nous intéressent ici.
        $outputs = $this->outputRepository->findByGpios(array_keys($parameterMap));
        $parameters = [];

        foreach ($outputs as $output) {
            $gpio = (int) ($output['gpio'] ?? -1);
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
        return $this->outputRepository->updateStateById($id, $state, $modifiedBy);
    }

    public function updateStateByGpio(int $gpio, int $state): bool
    {
        if ($state !== 0 && $state !== 1) {
            return false;
        }
        return $this->outputRepository->updateState($gpio, $state);
    }

    /**
     * Met à jour plusieurs paramètres depuis un formulaire.
     *
     * Valide les horaires de nourrissage avant persistance :
     * - plage [0..23] : contrainte dure (lève \InvalidArgumentException) ;
     * - matin < midi < soir : contrainte consultative (avertissement non bloquant).
     *
     * @param array<string, mixed> $params Paramètres à mettre à jour
     * @return array{updated:int, warnings:list<string>}
     * @throws \InvalidArgumentException Si un horaire de nourrissage est hors de [0..23]
     */
    public function updateMultipleParameters(array $params): array
    {
        // Contrainte dure : plages des horaires de nourrissage
        $incomingHours = \App\Util\FeedingScheduleValidator::assertHourRanges($params);

        // Contrainte consultative : ordre strictement croissant du trio résultant
        $warnings = [];
        if ($incomingHours !== []) {
            $warning = \App\Util\FeedingScheduleValidator::crossFieldWarning(
                $this->getParametersMap(),
                $incomingHours
            );
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        $updated = $this->outputRepository->updateMultipleParameters($params, 'web');

        return ['updated' => $updated, 'warnings' => $warnings];
    }

    /**
     * Ensemble canonique des GPIO autorisés en écriture pour le module FFP3.
     *
     * Dérivé de la source unique de vérité {@see \App\Config\Ffp3GpioMap} (actionneurs +
     * paramètres de configuration + commandes one-shot), complété par le GPIO de forçage
     * pompe aquarium (117) qui n'est pas dans le mapping propriété mais existe en base.
     *
     * @return list<int>
     */
    public function getAllowedGpios(): array
    {
        $gpios = array_keys(\App\Config\Ffp3GpioMap::gpioToProperty());
        $gpios[] = self::AQUARIUM_PUMP_FORCE_GPIO;

        return array_values(array_unique($gpios));
    }

    /**
     * Vérifie qu'un GPIO appartient à l'ensemble canonique autorisé du module FFP3.
     */
    public function isGpioAllowed(int $gpio): bool
    {
        return in_array($gpio, $this->getAllowedGpios(), true);
    }

    /**
     * Ensemble canonique des noms de paramètres autorisés en écriture (FFP3).
     *
     * @return list<string>
     */
    public function getAllowedParameterNames(): array
    {
        return array_keys(\App\Config\Ffp3GpioMap::parameterGpioMap());
    }

    /**
     * Vérifie qu'un nom de paramètre appartient à l'ensemble canonique autorisé (FFP3).
     */
    public function isParameterAllowed(string $name): bool
    {
        return in_array($name, $this->getAllowedParameterNames(), true);
    }

    /** @return array<int, string> */
    private function buildParameterMap(): array
    {
        $propertyToParam = \App\Config\Ffp3GpioMap::propertyToParam();
        $map = [];
        foreach (OutputSyncService::getGpioMapping() as $gpio => $propertyName) {
            if (isset($propertyToParam[$propertyName])) {
                $map[$gpio] = $propertyToParam[$propertyName];
            }
        }
        return $map;
    }
}
