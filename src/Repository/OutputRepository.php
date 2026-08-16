<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\Ffp3GpioMap;
use App\Config\TableConfig;
use App\Domain\SensorData;
use App\Util\StateNormalizer;
use App\Util\TableValidator;
use PDOException;

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

    /**
     * Sources d'écriture « détenues par le serveur » : leurs commandes sont protégées
     * de l'écrasement par le POST firmware pendant la fenêtre de priorité.
     *
     * - `web`  : toggle depuis la page de contrôle (historique).
     * - `cron` : commandes du CronOrchestrator via {@see \App\Service\PumpService}
     *   (sécurité marée, redémarrage différé, reset ESP). Ajouté en 6.31.0 : sans
     *   lui, l'arrêt de pompe de sécurité était réécrit par le POST firmware suivant.
     *
     * Toute nouvelle source serveur doit être ajoutée ICI **et** écrire
     * `lastModifiedBy` (sinon la protection ne s'applique pas).
     *
     * Publique : c'est un contrat que tout écrivain serveur doit respecter
     * (vérifié par PumpServiceTest).
     *
     * @var list<string>
     */
    public const SERVER_OWNED_SOURCES = ['web', 'cron'];

    /**
     * Relais auxiliaires AUX1/AUX2 (firmware ffp5cs v15.13, carte porteuse 230V
     * 6 canaux — WROOM GPIO 23/25). Rangées auto-créées comme les angles servo.
     *
     * @var array<int, array{name: string, state: string}>
     */
    private const AUX_RELAY_ROWS = [
        23 => ['name' => 'Relais AUX 1', 'state' => '0'],
        25 => ['name' => 'Relais AUX 2', 'state' => '0'],
    ];

    /**
     * GPIO 118-123 : angles servo nourrissage (défauts alignés OutputCacheService / migrate-gpio118-123).
     *
     * @var array<int, array{name: string, state: string}>
     */
    private const SERVO_ANGLE_ROWS = [
        118 => ['name' => 'angleReposGros', 'state' => '88'],
        119 => ['name' => 'angleDistribGros', 'state' => '140'],
        120 => ['name' => 'angleInterGros', 'state' => '45'],
        121 => ['name' => 'angleReposPetits', 'state' => '88'],
        122 => ['name' => 'angleDistribPetits', 'state' => '140'],
        123 => ['name' => 'angleInterPetits', 'state' => '45'],
    ];

    /**
     * Mapping nom de paramètre (UI / API) → GPIO pour la table outputs.
     *
     * Dérivé de la source unique {@see Ffp3GpioMap} : plus de tableau hardcodé
     * dupliqué avec OutputSyncService.
     *
     * @return array<string, int>
     */
    public static function getParameterGpioMap(): array
    {
        return array_merge(
            Ffp3GpioMap::parameterGpioMap(),
            Ffp3GpioMap::serverOnlyParameterGpioMap()
        );
    }

    /**
     * Récupère tous les outputs avec leurs états actuels
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $this->ensureAquariumPumpForceRowExists();
        $this->ensureServoAngleRowsExist();

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
                        WHEN name LIKE '%AUX%' THEN 6 -- Relais auxiliaires 230V
                        WHEN gpio = 101 THEN 7  -- Notifications (switch)
                        WHEN gpio = 115 THEN 8  -- Forçage réveil (switch)
                        WHEN name LIKE '%petits poissons%' THEN 9
                        WHEN name LIKE '%gros poissons%' THEN 10
                        WHEN name LIKE '%reset%' OR name LIKE '%Reset%' THEN 11
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
     * Récupère les outputs correspondant à une liste de GPIO (filtrage SQL via IN).
     *
     * Évite de charger toute la table via findAll() puis de filtrer en PHP lorsque
     * seul un sous-ensemble de GPIO est nécessaire (ex. mapping des paramètres).
     *
     * @param array<int, int> $gpios Liste de numéros GPIO
     * @return array<int, array<string, mixed>>
     */
    public function findByGpios(array $gpios): array
    {
        // Normalise et déduplique en entiers stricts (les valeurs viennent d'un mapping interne).
        $gpios = array_values(array_unique(array_map('intval', $gpios)));
        if ($gpios === []) {
            return [];
        }

        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());

        $placeholders = [];
        $params = [];
        foreach ($gpios as $i => $gpio) {
            $ph = ':g' . $i;
            $placeholders[] = $ph;
            $params[$ph] = $gpio;
        }
        $inList = implode(', ', $placeholders);

        // Même filtre que findAll() (name non vide) pour exclure les doublons vides historiques.
        $sql = "SELECT id, board, gpio, name, state FROM `{$table}`"
            . " WHERE gpio IN ({$inList}) AND name IS NOT NULL AND name != ''";

        $results = $this->fetchAll($sql, $params);

        // Normaliser les valeurs booléennes via StateNormalizer
        return StateNormalizer::normalizeResults($results);
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

        return $this->executeWithRowCount($sql, [
            ':gpio' => $gpio,
            ':state' => $state,
            ':modifiedBy' => $modifiedBy,
        ]) > 0;
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
        return $this->executeWithRowCount($sql, [
            ':state' => $state,
            ':id' => $id,
            ':modifiedBy' => $modifiedBy,
        ]) > 0;
    }

    /**
     * Incrémente de 1 le compteur de nourrissage d'une sortie (GPIO 108/109).
     *
     * Contrat « compteur monotone » (serveur 6.0.0 / firmware 15.0) : chaque clic
     * « Nourrir » fait `state = state + 1`. Le serveur ne remet JAMAIS ce compteur à
     * zéro ; le firmware mémorise son propre compteur exécuté (NVS) et rattrape l'écart
     * (un repas par poll, plafonné). Web n'écrit que ce compteur, le firmware ne l'écrit
     * jamais → aucune course bidirectionnelle, robuste aux reboots et aux polls manqués.
     *
     * @param int $id Identifiant de la ligne outputs (PK)
     * @return int|null Nouvelle valeur du compteur, ou null si la ligne est introuvable
     */
    public function incrementFeedCounter(int $id): ?int
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        $sql = "UPDATE `{$table}`
                SET state = CAST(state AS UNSIGNED) + 1,
                    requestTime = NOW(),
                    lastModifiedBy = 'web'
                WHERE id = :id";
        if ($this->executeWithRowCount($sql, [':id' => $id]) <= 0) {
            return null;
        }

        return $this->getStateById($id);
    }

    /**
     * Lit l'état (entier) d'une sortie par son ID, ou null si introuvable.
     */
    public function getStateById(int $id): ?int
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        $row = $this->fetchOne("SELECT state FROM `{$table}` WHERE id = :id", [':id' => $id]);
        if ($row === null || !isset($row['state'])) {
            return null;
        }

        return (int) $row['state'];
    }

    /**
     * Met à jour plusieurs paramètres depuis un formulaire.
     *
     * @param array<string, mixed> $params
     * @return int Nombre de paramètres RÉELLEMENT persistés (lignes touchées).
     *             Corrigé en 6.34.0 : le compteur incrémentait sur le retour de
     *             `PDOStatement::execute()`, qui vaut `true` dès que la requête part
     *             sans erreur — **y compris quand elle ne touche aucune ligne** (GPIO
     *             absent de la table). L'API annonçait donc des paramètres
     *             « enregistrés » qui ne l'étaient pas.
     *             NB MySQL : `rowCount()` compte les lignes *modifiées*, pas trouvées.
     *             Sans effet ici, la requête écrivant toujours `requestTime = NOW()` —
     *             seul un ré-enregistrement à l'identique dans la MÊME seconde pourrait
     *             renvoyer 0 (cas bénin).
     */
    public function updateMultipleParameters(array $params, string $modifiedBy = 'web'): int
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        return $this->executeInTransaction(function () use ($params, $table, $modifiedBy): int {
            $updated = 0;
            // CURRENT_TIMESTAMP plutôt que NOW() : synonyme exact en MySQL, et portable
            // SQLite — ce qui rend enfin cette méthode couvrable par un test unitaire
            // (OutputRepositoryUpdateCountTest), là où NOW() la réservait à la suite
            // d'intégration MySQL.
            $sql = "UPDATE `{$table}`
                    SET state = :state,
                        requestTime = CURRENT_TIMESTAMP,
                        lastModifiedBy = :modifiedBy
                    WHERE gpio = :gpio";
            $stmt = $this->pdo->prepare($sql);

            foreach (self::getParameterGpioMap() as $paramName => $gpio) {
                if (!array_key_exists($paramName, $params)) {
                    continue;
                }
                $value = $params[$paramName];
                if ($paramName === 'mail' || $paramName === 'notifMode' || $paramName === 'notifCategories') {
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
                $stmt->execute([':state' => $value, ':gpio' => $gpio, ':modifiedBy' => $modifiedBy]);
                if ($stmt->rowCount() > 0) {
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
        $gpio = (int) $result['gpio'];
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

        return $this->executeInTransaction(function () use ($table, $gpioValues, $modifiedBy, $prioritySeconds) {
            $updated = 0;
            $skipped = 0;

            foreach ($gpioValues as $gpio => $value) {
                if ($value === null) {
                    $skipped++;
                    continue;
                }

                $stateValue = (string) $value;

                // Protection contre écrasement des commandes serveur récentes
                $sql = "UPDATE `{$table}`
                        SET state = :state,
                            requestTime = NOW(),
                            lastModifiedBy = :modifiedBy
                        WHERE gpio = :gpio
                          AND name IS NOT NULL
                          AND name != ''
                          AND (
                              " . self::serverOwnedSourcesSql() . '
                              OR requestTime IS NULL
                              OR requestTime < DATE_SUB(NOW(), INTERVAL :priority SECOND)
                          )';

                $rowCount = $this->executeWithRowCount($sql, [
                    ':gpio' => $gpio,
                    ':state' => $stateValue,
                    ':modifiedBy' => $modifiedBy,
                    ':priority' => $prioritySeconds,
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
        // Mode de forçage pompe aquarium : 0=auto (suit l'ESP32), 1=forcer ON, 2=forcer OFF.
        $forceMode = $this->getAquariumPumpForceMode();
        $aquaPumpState = match ($forceMode) {
            1 => 1,
            2 => 0,
            default => $data->etatPompeAqua,
        };

        // Actionneurs physiques — toujours synchronisés (états réels des relais)
        // NOTE: GPIO 110 (reset) est traité à part avec une fenêtre de priorité web
        // pour éviter d'écraser trop vite une commande reset envoyée depuis l'UI.
        $physicalGpios = [
            2 => $data->etatHeat,
            15 => $data->etatUV,
            self::AQUARIUM_PUMP_GPIO => $aquaPumpState,
            18 => $data->etatPompeTank,
        ];
        $physicalFiltered = array_filter($physicalGpios, fn ($v) => $v !== null);
        if ($physicalFiltered !== []) {
            // Protège brièvement les commandes web (toggle UI) contre un POST firmware
            // qui peut encore contenir l'ancien état juste après le clic.
            $this->batchUpdateStatesSingleQuery(
                $physicalFiltered,
                'esp32',
                self::PHYSICAL_COMMAND_WEB_PRIORITY_SECONDS
            );
        }

        // Sécurité de cohérence : si un forçage est actif, ré-épingler explicitement GPIO 16
        // (priorité 0 = toujours appliqué) à la valeur forcée, quel que soit l'état ESP32.
        if ($forceMode === 1) {
            $this->batchUpdateStatesSingleQuery([self::AQUARIUM_PUMP_GPIO => 1], 'server-force', 0);
        } elseif ($forceMode === 2) {
            $this->batchUpdateStatesSingleQuery([self::AQUARIUM_PUMP_GPIO => 0], 'server-force', 0);
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

        // 108/109 (nourrissage) : NE PLUS écrire depuis le POST firmware.
        // Contrat « compteur monotone » (serveur 6.0.0 / firmware 15.0) : ces sorties
        // sont des compteurs croissants détenus exclusivement par le serveur (le web
        // incrémente, le firmware ne fait que lire et mémorise son propre compteur
        // exécuté en NVS). Les remettre à 0 ici effacerait les repas en attente.
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
        $filtered = array_filter($gpioUpdates, fn ($v) => $v !== null);
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
            ? 'AND (' . self::serverOwnedSourcesSql()
                . ' OR requestTime IS NULL OR requestTime < DATE_SUB(NOW(), INTERVAL :priority SECOND))'
            : '';
        $sql = "UPDATE `{$table}` SET state = {$caseSql}, requestTime = NOW(), lastModifiedBy = :modifiedBy
                WHERE gpio IN ({$inList}) AND name IS NOT NULL AND name != '' {$whereWebProtection}";

        $execParams = $prioritySeconds > 0 ? $params : array_diff_key($params, [':priority' => 1]);
        $this->execute($sql, $execParams);
    }

    /**
     * Mode de forçage de la pompe aquarium (GPIO 117) :
     *   0 = Auto (la pompe suit l'état renvoyé par l'ESP32),
     *   1 = Forcer ON  (serveur épingle GPIO 16 à 1),
     *   2 = Forcer OFF (serveur épingle GPIO 16 à 0).
     */
    public function getAquariumPumpForceMode(): int
    {
        $this->ensureAquariumPumpForceRowExists();

        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        // Post-nettoyage : une seule ligne 117. En cas de doublon résiduel, la valeur la plus
        // haute l'emporte (2=OFF prioritaire sur 1=ON : choix conservateur pour un actionneur).
        $sql = "SELECT state FROM `{$table}`
                WHERE gpio = :gpio AND name IS NOT NULL AND name != ''
                ORDER BY state DESC
                LIMIT 1";

        $row = $this->fetchOne($sql, [':gpio' => self::AQUARIUM_PUMP_FORCE_GPIO]);
        $mode = (is_array($row) && isset($row['state']) && is_numeric($row['state'])) ? (int) $row['state'] : 0;

        return in_array($mode, [1, 2], true) ? $mode : 0;
    }

    /**
     * Compat : « forçage ON actif ? ». Conserve la sémantique historique (mode 1).
     */
    public function getAquariumPumpForceState(): bool
    {
        return $this->getAquariumPumpForceMode() === 1;
    }

    /**
     * Garantit une ligne GPIO 117 utilisable (nom non vide) pour le switch « forçage pompe aquarium ».
     * Appelée au chargement contrôle, au GET état outputs et au POST données — pas seulement la page web.
     */
    public function ensureAquariumPumpForceRowExists(): void
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());

        $forceName = 'Forcage pompe aquarium ON';

        // Perf : lire d'abord l'existant ; ne résoudre la board (SELECT supplémentaire)
        // que lorsqu'on doit réellement écrire (INSERT / réparation de ligne fantôme).
        // Le cas stable (ligne présente, nom non vide) ne coûte donc plus qu'un SELECT.
        $existing = $this->fetchOne(
            "SELECT id, name FROM `{$table}` WHERE gpio = :gpio LIMIT 1",
            [':gpio' => self::AQUARIUM_PUMP_FORCE_GPIO]
        );

        // Ligne fantôme (gpio 117 mais name vide) : exclue par findAll → le switch n'apparaît pas
        if ($existing !== null) {
            $name = isset($existing['name']) ? trim((string) $existing['name']) : '';
            if ($name === '') {
                $board = $this->resolveDefaultBoardForNewRows($table);
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

        $board = $this->resolveDefaultBoardForNewRows($table);

        try {
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
        } catch (PDOException $e) {
            if ($this->isDuplicateOutputRowException($e)) {
                return;
            }
            throw $e;
        }
    }

    /**
     * Garantit les lignes GPIO 118-123 (angles servo nourrissage) pour la page contrôle et la persistance.
     * Idempotent : ne modifie pas state si la ligne existe déjà avec un nom non vide.
     */
    public function ensureServoAngleRowsExist(): void
    {
        $this->ensureNamedRowsExist(self::SERVO_ANGLE_ROWS);
    }

    /**
     * Garantit les lignes GPIO 23/25 (relais auxiliaires AUX1/AUX2 de la carte
     * porteuse 230V). Idempotent, même contrat que les angles servo.
     */
    public function ensureAuxRelayRowsExist(): void
    {
        $this->ensureNamedRowsExist(self::AUX_RELAY_ROWS);
    }

    /**
     * Corps commun des « ensure rows » : crée/répare des lignes nommées sans
     * jamais toucher au state d'une ligne existante.
     *
     * @param array<int, array{name: string, state: string}> $rowsByGpio
     */
    private function ensureNamedRowsExist(array $rowsByGpio): void
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());

        // Perf : un seul SELECT ... WHERE gpio IN (118..123) au lieu de six SELECT
        // unitaires. On indexe l'existant par gpio pour décider insert/réparation
        // sans re-requêter. La board (SELECT supplémentaire) n'est résolue qu'à la
        // première écriture réelle — le cas stable ne coûte donc plus qu'un SELECT.
        $gpios = array_keys($rowsByGpio);
        $placeholders = [];
        $params = [];
        foreach ($gpios as $i => $gpio) {
            $ph = ':g' . $i;
            $placeholders[] = $ph;
            $params[$ph] = $gpio;
        }
        $inList = implode(', ', $placeholders);

        // La lecture groupée participe au même contrat de tolérance que les INSERT :
        // une contrainte/course transitoire (doublon) retombe sur le chemin INSERT
        // idempotent ci-dessous ; toute autre erreur SQL est propagée.
        try {
            $rows = $this->fetchAll(
                "SELECT gpio, name FROM `{$table}` WHERE gpio IN ({$inList})",
                $params
            );
        } catch (PDOException $e) {
            if (!$this->isDuplicateOutputRowException($e)) {
                throw $e;
            }
            $rows = [];
        }

        // gpio => name (trim) des lignes déjà présentes.
        $existingByGpio = [];
        foreach ($rows as $row) {
            $existingByGpio[(int) $row['gpio']] = isset($row['name']) ? trim((string) $row['name']) : '';
        }

        $board = null; // résolution paresseuse (une seule fois, au premier write)

        foreach ($rowsByGpio as $gpio => $meta) {
            $exists = array_key_exists($gpio, $existingByGpio);

            if ($exists) {
                // Ligne fantôme (name vide) : réparer nom + board, sans toucher au state.
                if ($existingByGpio[$gpio] === '') {
                    $board ??= $this->resolveDefaultBoardForNewRows($table);
                    $sql = "UPDATE `{$table}` SET board = :board, name = :name WHERE gpio = :gpio";
                    $stmt = $this->pdo->prepare($sql);
                    if (!$stmt->execute([
                        ':board' => $board,
                        ':name' => $meta['name'],
                        ':gpio' => $gpio,
                    ])) {
                        error_log('[OutputRepository] ensureServoAngleRowsExist UPDATE (name vide) failed: '
                            . json_encode($stmt->errorInfo(), JSON_UNESCAPED_UNICODE));
                    }
                }

                continue;
            }

            $board ??= $this->resolveDefaultBoardForNewRows($table);
            try {
                $sql = "INSERT INTO `{$table}` (board, gpio, name, state)
                        VALUES (:board, :gpio, :name, :state)";
                $stmt = $this->pdo->prepare($sql);
                if (!$stmt->execute([
                    ':board' => $board,
                    ':gpio' => $gpio,
                    ':name' => $meta['name'],
                    ':state' => $meta['state'],
                ])) {
                    error_log('[OutputRepository] ensureServoAngleRowsExist INSERT failed: '
                        . json_encode($stmt->errorInfo(), JSON_UNESCAPED_UNICODE));
                }
            } catch (PDOException $e) {
                if ($this->isDuplicateOutputRowException($e)) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Fragment SQL « la ligne n'appartient PAS à une source serveur » — condition qui
     * AUTORISE le POST firmware à écraser. Littéraux constants (liste blanche interne),
     * jamais d'entrée utilisateur : pas de placeholder nécessaire ni possible ici
     * (l'expression est concaténée dans plusieurs requêtes aux paramètres distincts).
     */
    private static function serverOwnedSourcesSql(): string
    {
        $quoted = array_map(
            static fn (string $source): string => "'" . $source . "'",
            self::SERVER_OWNED_SOURCES
        );

        return 'lastModifiedBy NOT IN (' . implode(', ', $quoted) . ')';
    }

    private function isDuplicateOutputRowException(PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? '');
        $message = strtolower($e->getMessage());

        return $state === '23000'
            && (str_contains($message, 'duplicate') || str_contains($message, 'unique'));
    }

    private function resolveDefaultBoardForNewRows(string $table): string
    {
        $boardRow = $this->fetchOne(
            "SELECT board FROM `{$table}` WHERE gpio = :gpio LIMIT 1",
            [':gpio' => self::AQUARIUM_PUMP_GPIO]
        );

        return isset($boardRow['board']) && trim((string) $boardRow['board']) !== ''
            ? (string) $boardRow['board']
            : '1';
    }
}
