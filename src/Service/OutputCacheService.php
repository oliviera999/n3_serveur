<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\TableConfig;
use App\Util\StateNormalizer;
use App\Util\TableValidator;
use PDO;
use PDOException;

/**
 * Service pour les états outputs (anciennement avec cache, désormais lecture BDD directe)
 *
 * v4.9.41: Lorsque la table outputs est vide ou sans lignes pour les GPIO demandés,
 * retourne des valeurs par défaut pour tous les GPIO attendus (alignées firmware/sql)
 * pour éviter que l'ESP32 reçoive un JSON vide et affiche des défauts.
 *
 * v5.x: Cache supprimé - en PHP-FPM l'invalidation ne s'appliquait qu'au worker courant,
 * créant des données obsolètes (jusqu'à 5s) pour l'ESP32 quand un autre worker servait le GET.
 * Une requête SELECT par poll (60s prod, 6s test) est négligeable.
 *
 * v5.0.300: triggerOtaCheck persisté en table ffp3OtaTrigger (plus de static PHP-FPM multi-workers).
 */
class OutputCacheService
{
    private const OTA_TRIGGER_TABLE = 'ffp3OtaTrigger';

    /**
     * Valeurs par défaut pour chaque GPIO attendu par l'ESP32
     * Alignées avec include/gpio_mapping.h (GPIODefaults) et migrations/INIT_GPIO_BASE_ROWS.sql
     */
    private const DEFAULT_STATE = [
        2 => 0,   15 => 0,   16 => 0,   18 => 1,   // actionneurs physiques (pompe réserve 1 par défaut)
        100 => 0, 101 => 1,  102 => 18, 103 => 80, 104 => 18, // config: email, notif, seuils
        105 => 8, 106 => 12, 107 => 19,             // heures nourrissage
        108 => 0, 109 => 0, 110 => 0,              // commandes nourrissage + reset
        111 => 3, 112 => 2, 113 => 120, 114 => 8, 115 => 0, 116 => 600, // durées / limites / wake
        117 => 0, // forçage pompe aquarium (serveur uniquement, défaut désactivé)
        118 => 88, 119 => 140, 120 => 45, // angles servo gros
        121 => 88, 122 => 140, 123 => 45, // angles servo petits
    ];

    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * Récupère les états outputs depuis la base de données
     *
     * @param \PDO $pdo Connexion PDO
     * @param array $gpioList Liste des GPIOs à récupérer
     * @param bool $skipCache Ignoré (conservé pour compatibilité API)
     * @param bool $consumeOtaTrigger Consomme triggerOtaCheck uniquement pour les lectures firmware
     * @return array Tableau associatif [gpio => state]
     */
    public function getOutputsState(\PDO $pdo, array $gpioList, bool $skipCache = false, bool $consumeOtaTrigger = true): array
    {
        $env = TableConfig::getEnvironment();

        if ($gpioList === []) {
            return [];
        }

        // Lecture directe BDD (cache supprimé)
        $table = TableConfig::getOutputsTable();

        // Construire requête IN sécurisée
        $placeholders = [];
        $params = [];
        foreach ($gpioList as $idx => $gpio) {
            $ph = ":g{$idx}";
            $placeholders[] = $ph;
            $params[$ph] = $gpio;
        }

        // Valider le nom de table via la whitelist centralisée
        TableValidator::validateOutputsTable($table);

        // Exclure les lignes fantômes (name vide) en MySQL ; schéma SQLite minimal des tests sans colonne name.
        $nameFilter = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? " AND name IS NOT NULL AND name != ''"
            : '';
        $sql = "SELECT gpio, state FROM `{$table}` WHERE gpio IN (" . implode(',', $placeholders) . ')' . $nameFilter;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Fusionner les doublons board/gpio : pour les GPIO booléens, 1 l'emporte (ex. forçage 117).
        $byGpio = [];
        foreach ($rows as $row) {
            $gpio = (int) $row['gpio'];
            $state = $row['state'];
            if (!array_key_exists($gpio, $byGpio)) {
                $byGpio[$gpio] = $state;
                continue;
            }
            if (StateNormalizer::isBooleanGpio($gpio)) {
                $prev = (int) StateNormalizer::normalize($gpio, $byGpio[$gpio]);
                $next = (int) StateNormalizer::normalize($gpio, $state);
                $byGpio[$gpio] = max($prev, $next);
            }
        }

        // Normalisation via StateNormalizer ; si absent en BDD, utiliser valeur par défaut
        $result = [];
        foreach ($gpioList as $gpio) {
            $state = array_key_exists($gpio, $byGpio)
                ? $byGpio[$gpio]
                : (self::DEFAULT_STATE[$gpio] ?? 0);
            $state = StateNormalizer::normalize($gpio, $state);
            $result[(string) $gpio] = $state;
        }

        // v11.172: Ajouter noms symboliques (double format rétrocompatible)
        // Permet au firmware d'utiliser les clés numériques OU symboliques
        $gpioToSymbol = OutputSyncService::getGpioMapping();
        foreach ($result as $gpioStr => $state) {
            $gpio = (int) $gpioStr;
            if (isset($gpioToSymbol[$gpio])) {
                $result[$gpioToSymbol[$gpio]] = $state;
            }
        }

        if ($consumeOtaTrigger) {
            $this->maybeAttachAndConsumeOtaTrigger($pdo, $env, $result);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function maybeAttachAndConsumeOtaTrigger(PDO $pdo, string $env, array &$result): void
    {
        try {
            $sql = sprintf(
                'UPDATE `%s` SET `pending` = 0 WHERE `env` = :env AND `pending` = 1',
                self::OTA_TRIGGER_TABLE
            );
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':env' => $env]);
            if ($stmt->rowCount() > 0) {
                $result['triggerOtaCheck'] = true;
            }
        } catch (PDOException $e) {
            // Chemin de lecture firmware (hot path) : ne PAS créer la table ici (pas de DDL
            // par requête). Table absente = aucun trigger en attente -> on ignore simplement.
            // La table est créée par la migration CREATE_FFP3_OTA_TRIGGER_TABLE.sql, et à défaut
            // par le chemin d'écriture admin (setTriggerOtaCheckRequested) lors d'une demande.
            if ($this->isMissingTableException($e)) {
                return;
            }
            throw $e;
        }
    }

    private function isMissingTableException(PDOException $e): bool
    {
        $state = $e->errorInfo[0] ?? '';
        $code = $e->errorInfo[1] ?? null;
        $msg = strtolower($e->getMessage());
        if ($state === '42S02' || ($code === 1146 && str_contains($msg, "doesn't exist"))) {
            return true;
        }
        if (str_contains($msg, 'no such table')) {
            return true;
        }

        return false;
    }

    /**
     * Enregistre une demande de vérification OTA pour l'environnement courant.
     * Le prochain GET state firmware recevra triggerOtaCheck: true une fois.
     */
    public function setTriggerOtaCheckRequested(): void
    {
        $env = TableConfig::getEnvironment();
        $this->upsertOtaTriggerRequested($env);
    }

    private function upsertOtaTriggerRequested(string $env, bool $retried = false): void
    {
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $sql = sprintf(
                    'INSERT INTO `%s` (`env`, `pending`) VALUES (:env, 1) ON DUPLICATE KEY UPDATE `pending` = 1',
                    self::OTA_TRIGGER_TABLE
                );
            } else {
                // SQLite (tests PHPUnit)
                $sql = sprintf(
                    'INSERT INTO `%s` (`env`, `pending`) VALUES (:env, 1) ON CONFLICT(`env`) DO UPDATE SET `pending` = 1',
                    self::OTA_TRIGGER_TABLE
                );
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':env' => $env]);
        } catch (PDOException $e) {
            if ($this->isMissingTableException($e) && !$retried) {
                $this->ensureOtaTriggerTableExists($this->pdo);
                $this->upsertOtaTriggerRequested($env, true);
                return;
            }
            throw $e;
        }
    }

    private function ensureOtaTriggerTableExists(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $sql = sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (
                    `env` VARCHAR(32) NOT NULL,
                    `pending` TINYINT(1) NOT NULL DEFAULT 0,
                    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`env`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                self::OTA_TRIGGER_TABLE
            );
        } else {
            $sql = sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (
                    `env` TEXT PRIMARY KEY,
                    `pending` INTEGER NOT NULL DEFAULT 0,
                    `updated_at` TEXT DEFAULT CURRENT_TIMESTAMP
                )',
                self::OTA_TRIGGER_TABLE
            );
        }
        $pdo->exec($sql);
    }
}
