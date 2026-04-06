<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\TableConfig;
use App\Domain\SensorData;
use App\Util\StateNormalizer;
use App\Util\TableValidator;
use PDO;

/**
 * Repository pour gérer les outputs (GPIO/relais) en base de données
 * 
 * Gère la table ffp3Outputs (PROD) ou ffp3Outputs2 (TEST)
 */
class OutputRepository extends AbstractRepository
{
    private const RESET_COMMAND_GPIO = 110;
    private const AQUARIUM_PUMP_GPIO = 16;
    private const AQUARIUM_PUMP_FORCE_GPIO = 117;
    private const RESET_COMMAND_WEB_PRIORITY_SECONDS = 20;
    private const PHYSICAL_COMMAND_WEB_PRIORITY_SECONDS = 12;

    /** @var array<string, int> */
    private const PARAMETER_GPIO_MAP = [
        'mail' => 100,
        'mailNotif' => 101,
        'aqThr' => 102,
        'taThr' => 103,
        'chauff' => 104,
        'bouffeMatin' => 105,
        'bouffeMidi' => 106,
        'bouffeSoir' => 107,
        'tempsGros' => 111,
        'tempsPetits' => 112,
        'tempsRemplissageSec' => 113,
        'limFlood' => 114,
        'WakeUp' => 115,
        'FreqWakeUp' => 116,
    ];

    /**
     * Mapping canonique nom de paramètre (UI / API) → GPIO pour la table outputs.
     *
     * @return array<string, int>
     */
    public static function getParameterGpioMap(): array
    {
        return self::PARAMETER_GPIO_MAP;
    }

    /**
     * Récupère tous les outputs avec leurs états actuels
     * 
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $this->ensureAquariumPumpForceRowExists();

        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        // Filtrer : name NOT NULL et name != '' pour éviter les doublons vides
        // Ordre personnalisé : pompe aquarium, pompe réserve, radiateurs, lumière, nourrissage, reset
        $sql = "SELECT id, board, gpio, name, state 
                FROM `{$table}` 
                WHERE name IS NOT NULL AND name != ''
                ORDER BY 
                    CASE 
                        WHEN name LIKE '%Pompe aquarium%' OR name LIKE '%pompe aquarium%' THEN 1
                        WHEN gpio = 117 THEN 2 -- Forçage pompe aquarium ON
                        WHEN name LIKE '%Pompe r%serve%' OR name LIKE '%pompe r%serve%' THEN 3
                        WHEN name LIKE '%Radiateur%' OR name LIKE '%radiateur%' THEN 4
                        WHEN name LIKE '%Lumi%re%' OR name LIKE '%lumi%re%' THEN 5
                        WHEN gpio = 101 THEN 6  -- Notifications (switch)
                        WHEN gpio = 115 THEN 7  -- Forçage réveil (switch)
                        WHEN name LIKE '%petits poissons%' THEN 8
                        WHEN name LIKE '%gros poissons%' THEN 9
                        WHEN name LIKE '%reset%' OR name LIKE '%Reset%' THEN 10
                        ELSE 99
                    END,
                    gpio ASC";
        
        $results = $this->fetchAll($sql);
        
        // Normaliser les valeurs booléennes via StateNormalizer
        return StateNormalizer::normalizeResults($results);
    }

    /**
     * Récupère un output spécifique par son GPIO
     * 
     * @param int $gpio Numéro GPIO
     * @return array<string, mixed>|null
     */
    public function findByGpio(int $gpio): ?array
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        $sql = "SELECT id, board, gpio, name, state FROM `{$table}` WHERE gpio = :gpio";
        
        $result = $this->fetchOne($sql, [':gpio' => $gpio]);
        if ($result === null) {
            return null;
        }
        
        // Normaliser la valeur via StateNormalizer
        $result['state'] = StateNormalizer::normalize($gpio, $result['state']);
        
        return $result;
    }

    /**
     * Met à jour l'état d'un output
     * 
     * @param int $gpio Numéro GPIO
     * @param int $state Nouvel état (0 ou 1)
     * @return bool Succès de l'opération
     */
    public function updateState(int $gpio, int $state, string $modifiedBy = 'web'): bool
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        $sql = "UPDATE `{$table}`
                SET state = :state,
                    requestTime = NOW(),
                    lastModifiedBy = :modifiedBy
                WHERE gpio = :gpio";

        return $this->execute($sql, [
            ':gpio' => $gpio,
            ':state' => $state,
            ':modifiedBy' => $modifiedBy,
        ]);
    }

    /**
     * Met à jour l'état d'un output par son ID.
     */
    public function updateStateById(int $id, int $state, string $modifiedBy = 'web'): bool
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        $sql = "UPDATE `{$table}`
                SET state = :state,
                    requestTime = NOW(),
                    lastModifiedBy = :modifiedBy
                WHERE id = :id";
        return $this->execute($sql, [
            ':state' => $state,
            ':id' => $id,
            ':modifiedBy' => $modifiedBy,
        ]);
    }

    /**
     * Met à jour plusieurs paramètres depuis un formulaire.
     *
     * @param array<string, mixed> $params
     */
    public function updateMultipleParameters(array $params, string $modifiedBy = 'web'): int
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        return $this->executeInTransaction(function () use ($params, $table, $modifiedBy): int {
            $updated = 0;
            $sql = "UPDATE `{$table}`
                    SET state = :state,
                        requestTime = NOW(),
                        lastModifiedBy = :modifiedBy
                    WHERE gpio = :gpio";
            $stmt = $this->pdo->prepare($sql);

            foreach (self::PARAMETER_GPIO_MAP as $paramName => $gpio) {
                if (!array_key_exists($paramName, $params)) {
                    continue;
                }
                $value = $params[$paramName];
                if ($paramName === 'mail') {
                    $value = (string) $value;
                } elseif ($paramName === 'mailNotif') {
                    $value = (is_string($value) && in_array(strtolower($value), ['1', 'true', 'on', 'yes', 'checked'], true))
                        || $value === 1
                        || $value === true
                        ? 1
                        : 0;
                } else {
                    $value = is_numeric($value) ? (int) $value : 0;
                }
                if ($stmt->execute([':state' => $value, ':gpio' => $gpio, ':modifiedBy' => $modifiedBy])) {
                    $updated++;
                }
            }

            return $updated;
        });
    }

    /**
     * Récupère tous les GPIO d'une board spécifique avec leurs noms et états
     * 
     * @param string $board Numéro de la board
     * @return array<int, array<string, mixed>>
     */
    public function findByBoard(string $board): array
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        $sql = "SELECT id, board, gpio, name, state 
                FROM `{$table}` 
                WHERE board = :board AND name IS NOT NULL AND name != ''
                ORDER BY gpio ASC";
        
        $results = $this->fetchAll($sql, [':board' => $board]);
        
        // Normaliser les valeurs booléennes via StateNormalizer
        return StateNormalizer::normalizeResults($results);
    }

    /**
     * Récupère la dernière GPIO modifiée d'une board spécifique
     * 
     * @param string $board Numéro de la board
     * @return array<string, mixed>|null
     */
    public function findLastModifiedGpio(string $board): ?array
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        
        // Vérifier si la colonne requestTime existe
        $checkColumnSql = "SHOW COLUMNS FROM `{$table}` LIKE 'requestTime'";
        $hasRequestTime = $this->fetchOne($checkColumnSql) !== null;
        
        if ($hasRequestTime) {
            $sql = "SELECT id, board, gpio, name, state, 
                           DATE_FORMAT(requestTime, '%d/%m/%Y %H:%i:%s') as last_modified_time
                    FROM `{$table}` 
                    WHERE board = :board AND name IS NOT NULL AND name != '' AND requestTime IS NOT NULL
                    ORDER BY requestTime DESC 
                    LIMIT 1";
        } else {
            // Fallback: colonne requestTime absente (ancienne base), last_modified_time reste null
            $sql = "SELECT id, board, gpio, name, state,
                           NULL as last_modified_time
                    FROM `{$table}`
                    WHERE board = :board AND name IS NOT NULL AND name != ''
                    ORDER BY gpio ASC
                    LIMIT 1";
        }
        
        $result = $this->fetchOne($sql, [':board' => $board]);
        
        if ($result === null) {
            return null;
        }
        
        // Normaliser la valeur via StateNormalizer
        $gpio = (int)$result['gpio'];
        $result['state'] = StateNormalizer::normalize($gpio, $result['state']);
        
        return $result;
    }

    /**
     * Met à jour plusieurs GPIO avec logique de priorité.
     * 
     * Les modifications web ont priorité pendant la durée spécifiée.
     * 
     * @param array<int, mixed> $gpioValues [gpio => value]
     * @param string $modifiedBy Source de la modification ('esp32', 'web', etc.)
     * @param int $prioritySeconds Durée de priorité pour les changements web
     * @return array Statistiques ['updated' => int, 'skipped' => int]
     */
    public function batchUpdateWithPriority(array $gpioValues, string $modifiedBy, int $prioritySeconds): array
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        
        return $this->executeInTransaction(function() use ($table, $gpioValues, $modifiedBy, $prioritySeconds) {
            $updated = 0;
            $skipped = 0;
            
            foreach ($gpioValues as $gpio => $value) {
                if ($value === null) {
                    $skipped++;
                    continue;
                }
                
                $stateValue = (string)$value;
                
                // Protection contre écrasement des changements web récents
                $sql = "UPDATE `{$table}` 
                        SET state = :state, 
                            requestTime = NOW(), 
                            lastModifiedBy = :modifiedBy
                        WHERE gpio = :gpio 
                          AND name IS NOT NULL 
                          AND name != ''
                          AND (
                              lastModifiedBy != 'web' 
                              OR requestTime IS NULL 
                              OR requestTime < DATE_SUB(NOW(), INTERVAL :priority SECOND)
                          )";
                
                $rowCount = $this->executeWithRowCount($sql, [
                    ':gpio' => $gpio,
                    ':state' => $stateValue,
                    ':modifiedBy' => $modifiedBy,
                    ':priority' => $prioritySeconds
                ]);
                
                if ($rowCount > 0) {
                    $updated++;
                } else {
                    $skipped++;
                }
            }
            
            return ['updated' => $updated, 'skipped' => $skipped];
        });
    }

    /**
     * Synchronise les états des GPIO depuis les données capteurs (POST ESP32).
     * v5.0.168: Simplifié — uniquement actionneurs physiques + acks nourrissage.
     * La config (100–107, 111–116) n'est plus synchronisée depuis le POST :
     * le serveur est source de vérité, l'ESP32 récupère via GET.
     *
     * @param SensorData $data Données capteurs contenant les états à synchroniser
     */
    public function syncStatesFromSensorData(SensorData $data): void
    {
        $forceAquariumPumpOn = $this->getAquariumPumpForceState();

        // Actionneurs physiques — toujours synchronisés (états réels des relais)
        // NOTE: GPIO 110 (reset) est traité à part avec une fenêtre de priorité web
        // pour éviter d'écraser trop vite une commande reset envoyée depuis l'UI.
        $physicalGpios = [
            2 => $data->etatHeat,
            15 => $data->etatUV,
            self::AQUARIUM_PUMP_GPIO => $forceAquariumPumpOn ? 1 : $data->etatPompeAqua,
            18 => $data->etatPompeTank,
        ];
        $physicalFiltered = array_filter($physicalGpios, fn($v) => $v !== null);
        if ($physicalFiltered !== []) {
            // Protège brièvement les commandes web (toggle UI) contre un POST firmware
            // qui peut encore contenir l'ancien état juste après le clic.
            $this->batchUpdateStatesSingleQuery(
                $physicalFiltered,
                'esp32',
                self::PHYSICAL_COMMAND_WEB_PRIORITY_SECONDS
            );
        }

        // Sécurité de cohérence : si le forçage est actif, maintenir explicitement GPIO 16 à 1.
        if ($forceAquariumPumpOn) {
            $this->batchUpdateStatesSingleQuery(
                [self::AQUARIUM_PUMP_GPIO => 1],
                'server-force',
                0
            );
        }

        // GPIO 110 (reset) : synchronisation avec priorité web temporaire.
        // Cela laisse à l'ESP32 le temps de lire la commande "1" avant qu'un POST
        // périodique "resetMode=0" ne la réécrase.
        if ($data->resetMode !== null) {
            $this->batchUpdateStatesSingleQuery(
                [self::RESET_COMMAND_GPIO => $data->resetMode],
                'esp32',
                self::RESET_COMMAND_WEB_PRIORITY_SECONDS
            );
        }

        // 108/109 (acks nourrissage) : toujours forcer 0 ; priorité 20 s pour éviter
        // qu'un POST n'écrase une commande web récente avant que l'ESP32 ne la voie
        if ($data->configSynced === 1) {
            $this->batchUpdateStatesSingleQuery([108 => 0, 109 => 0], 'esp32', 20);
        }
    }

    /**
     * Met à jour plusieurs GPIO en une seule requête (CASE WHEN gpio THEN state).
     * Réduit la latence POST pour rester sous le timeout client (5 s).
     *
     * @param array<int, mixed> $gpioUpdates [gpio => value]
     * @param string $modifiedBy Source de la modification ('esp32', 'web', etc.)
     * @param int $prioritySeconds Durée de priorité pour les changements web
     */
    public function batchUpdateStatesSingleQuery(array $gpioUpdates, string $modifiedBy, int $prioritySeconds): void
    {
        $filtered = array_filter($gpioUpdates, fn($v) => $v !== null);
        if ($filtered === []) {
            return;
        }

        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        $gpioList = array_keys($filtered);

        $caseParts = [];
        $params = [':modifiedBy' => $modifiedBy, ':priority' => $prioritySeconds];
        foreach ($filtered as $gpio => $value) {
            $ph = ':s' . $gpio;
            $caseParts[] = "WHEN {$gpio} THEN {$ph}";
            $params[$ph] = (string) StateNormalizer::normalize($gpio, $value);
        }
        $caseSql = 'CASE gpio ' . implode(' ', $caseParts) . ' END';
        $inList = implode(',', array_map('intval', $gpioList));

        $whereWebProtection = $prioritySeconds > 0
            ? "AND (lastModifiedBy != 'web' OR requestTime IS NULL OR requestTime < DATE_SUB(NOW(), INTERVAL :priority SECOND))"
            : '';
        $sql = "UPDATE `{$table}` SET state = {$caseSql}, requestTime = NOW(), lastModifiedBy = :modifiedBy
                WHERE gpio IN ({$inList}) AND name IS NOT NULL AND name != '' {$whereWebProtection}";

        $execParams = $prioritySeconds > 0 ? $params : array_diff_key($params, [':priority' => 1]);
        $this->execute($sql, $execParams);
    }

    public function getAquariumPumpForceState(): bool
    {
        $this->ensureAquariumPumpForceRowExists();

        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        // Toute ligne GPIO 117 exploitable avec state=1 active le forçage (évite un faux 0 si doublons sans ORDER BY).
        $sql = "SELECT 1 AS ok FROM `{$table}`
                WHERE gpio = :gpio AND state = 1
                  AND name IS NOT NULL AND name != ''
                LIMIT 1";

        return $this->fetchOne($sql, [':gpio' => self::AQUARIUM_PUMP_FORCE_GPIO]) !== null;
    }

    /**
     * Garantit une ligne GPIO 117 utilisable (nom non vide) pour le switch « forçage pompe aquarium ».
     * Appelée au chargement contrôle, au GET état outputs et au POST données — pas seulement la page web.
     */
    public function ensureAquariumPumpForceRowExists(): void
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());

        $boardRow = $this->fetchOne(
            "SELECT board FROM `{$table}` WHERE gpio = :gpio LIMIT 1",
            [':gpio' => self::AQUARIUM_PUMP_GPIO]
        );
        $board = isset($boardRow['board']) && trim((string) $boardRow['board']) !== ''
            ? (string) $boardRow['board']
            : '1';

        $forceName = 'Forcage pompe aquarium ON';

        $existing = $this->fetchOne(
            "SELECT id, name FROM `{$table}` WHERE gpio = :gpio LIMIT 1",
            [':gpio' => self::AQUARIUM_PUMP_FORCE_GPIO]
        );

        // Ligne fantôme (gpio 117 mais name vide) : exclue par findAll → le switch n'apparaît pas
        if ($existing !== null) {
            $name = isset($existing['name']) ? trim((string) $existing['name']) : '';
            if ($name === '') {
                $sql = "UPDATE `{$table}` SET board = :board, name = :name WHERE gpio = :gpio";
                $stmt = $this->pdo->prepare($sql);
                if (!$stmt->execute([
                    ':board' => $board,
                    ':name' => $forceName,
                    ':gpio' => self::AQUARIUM_PUMP_FORCE_GPIO,
                ])) {
                    error_log('[OutputRepository] ensureAquariumPumpForceRowExists UPDATE (name vide) failed: '
                        . json_encode($stmt->errorInfo(), JSON_UNESCAPED_UNICODE));
                }
            }

            return;
        }

        $sql = "INSERT INTO `{$table}` (board, gpio, name, state)
                VALUES (:board, :gpio, :name, :state)";
        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute([
            ':board' => $board,
            ':gpio' => self::AQUARIUM_PUMP_FORCE_GPIO,
            ':name' => $forceName,
            ':state' => '0',
        ]);
        if (!$ok) {
            error_log('[OutputRepository] ensureAquariumPumpForceRowExists INSERT failed: '
                . json_encode($stmt->errorInfo(), JSON_UNESCAPED_UNICODE));
        }
    }
}
