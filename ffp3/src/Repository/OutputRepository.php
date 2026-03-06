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
    /**
     * Récupère tous les outputs avec leurs états actuels
     * 
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        // Filtrer : name NOT NULL et name != '' pour éviter les doublons vides
        // Ordre personnalisé : pompe aquarium, pompe réserve, radiateurs, lumière, nourrissage, reset
        $sql = "SELECT id, board, gpio, name, state 
                FROM `{$table}` 
                WHERE name IS NOT NULL AND name != ''
                ORDER BY 
                    CASE 
                        WHEN name LIKE '%Pompe aquarium%' OR name LIKE '%pompe aquarium%' THEN 1
                        WHEN name LIKE '%Pompe r%serve%' OR name LIKE '%pompe r%serve%' THEN 2
                        WHEN name LIKE '%Radiateur%' OR name LIKE '%radiateur%' THEN 3
                        WHEN name LIKE '%Lumi%re%' OR name LIKE '%lumi%re%' THEN 4
                        WHEN gpio = 101 THEN 5  -- Notifications (switch)
                        WHEN gpio = 115 THEN 6  -- Forçage réveil (switch)
                        WHEN name LIKE '%petits poissons%' THEN 7
                        WHEN name LIKE '%gros poissons%' THEN 8
                        WHEN name LIKE '%reset%' OR name LIKE '%Reset%' THEN 9
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
    public function updateState(int $gpio, int $state): bool
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        $sql = "UPDATE `{$table}` SET state = :state WHERE gpio = :gpio";
        
        return $this->execute($sql, [':gpio' => $gpio, ':state' => $state]);
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
            // Fallback: utiliser la première GPIO trouvée avec l'heure actuelle
            $sql = "SELECT id, board, gpio, name, state, 
                           DATE_FORMAT(NOW(), '%d/%m/%Y %H:%i:%s') as last_modified_time
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
     * Synchronise les états des GPIO depuis les données capteurs
     * Met à jour ffp3Outputs ou ffp3Outputs2 selon l'environnement
     * 
     * v11.168: Si configSynced=0 (ou null), ignore les GPIO de configuration (100-116)
     *          pour éviter l'écrasement par des valeurs par défaut de l'ESP32
     * 
     * @deprecated Utilisez OutputSyncService::syncFromSensorData() à la place
     * 
     * @param SensorData $data Données capteurs contenant les états à synchroniser
     */
    public function syncStatesFromSensorData(SensorData $data): void
    {
        // v11.168: Vérifier si la config ESP est synchronisée
        // configSynced=1 signifie que l'ESP a fait au moins un poll serveur réussi
        // et donc ses valeurs de config sont fiables
        $configIsSynced = ($data->configSynced === 1);
        
        // Actionneurs physiques - toujours synchronisés (états réels des relais)
        // 110 (resetMode) : l'ESP32 envoie toujours 0 (après reset ou idle) ; le sync permet
        // de remettre le switch à "désactivé" une fois le reset effectif (évite switch bloqué à "activé")
        $gpioUpdates = [
            2 => $data->etatHeat,
            15 => $data->etatUV,
            16 => $data->etatPompeAqua,
            18 => $data->etatPompeTank,
            110 => $data->resetMode,
        ];
        
        // v11.168: Variables de configuration - UNIQUEMENT si configSynced=1
        // Sinon, on risque d'écraser les vraies valeurs avec les défauts du firmware
        if ($configIsSynced) {
            $gpioUpdates += [
                // Configuration
                100 => $data->mail,
                101 => $data->mailNotif,
                102 => $data->aqThreshold,
                103 => $data->tankThreshold,
                104 => $data->chauffageThreshold,
                105 => $data->bouffeMatin,
                106 => $data->bouffeMidi,
                107 => $data->bouffeSoir,
                
                // Commandes nourrissage (108/109) : ne jamais écrire 1 depuis le POST ESP32.
                // Un 1 reçu = ack "nourrissage exécuté" (auto ou manuel). Si on le persistait ici,
                // le prochain GET renverrait 1 → front montant côté ESP → nourrissage manuel en double.
                // On force 0 pour que le serveur ne réinjecte pas 108/109=1 au prochain poll.
                // Le suivi en BDD est assuré par SensorRepository::insert() (table données capteurs),
                // appelé avant syncStatesFromSensorData : bouffePetits/bouffeGros y sont enregistrés.
                108 => 0,
                109 => 0,
                
                // Paramètres timing
                111 => $data->tempsGros,
                112 => $data->tempsPetits,
                113 => $data->tempsRemplissageSec,
                114 => $data->limFlood,
                115 => $data->wakeUp,
                116 => $data->freqWakeUp,
            ];
        }
        // Note: Si configSynced=0, on ne log pas ici pour éviter spam
        // Le log est fait côté ESP32
        
        // Priorité plus longue pour 108/109 (nourrissage) : l'ESP32 poll toutes les 6 s,
        // on protège 20 s pour qu'au moins un GET voie la commande avant qu'un POST n'écrase.
        $feedGpios = [108 => $gpioUpdates[108] ?? null, 109 => $gpioUpdates[109] ?? null];
        $feedGpios = array_filter($feedGpios, fn($v) => $v !== null);
        $rest = array_diff_key($gpioUpdates, [108 => 1, 109 => 1]);
        if ($feedGpios !== []) {
            $this->batchUpdateStatesSingleQuery($feedGpios, 'esp32', 20);
        }
        if ($rest !== []) {
            $this->batchUpdateStatesSingleQuery($rest, 'esp32', 10);
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
            $params[$ph] = (string) $value;
        }
        $caseSql = 'CASE gpio ' . implode(' ', $caseParts) . ' END';
        $inList = implode(',', array_map('intval', $gpioList));

        $sql = "UPDATE `{$table}` SET state = {$caseSql}, requestTime = NOW(), lastModifiedBy = :modifiedBy
                WHERE gpio IN ({$inList}) AND name IS NOT NULL AND name != ''
                AND (lastModifiedBy != 'web' OR requestTime IS NULL OR requestTime < DATE_SUB(NOW(), INTERVAL :priority SECOND))";

        $this->execute($sql, $params);
    }
}
