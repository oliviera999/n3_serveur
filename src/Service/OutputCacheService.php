<?php

namespace App\Service;

use App\Config\TableConfig;
use App\Util\StateNormalizer;
use App\Service\OutputSyncService;

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
 */
class OutputCacheService
{
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
    ];

    /**
     * Demande de vérification OTA par environnement (page contrôle).
     * Une seule réponse GET state contiendra triggerOtaCheck: true puis le flag est effacé.
     */
    private static array $triggerOtaRequested = [];

    /**
     * Récupère les états outputs depuis la base de données
     *
     * @param \PDO $pdo Connexion PDO
     * @param array $gpioList Liste des GPIOs à récupérer
     * @param bool $skipCache Ignoré (conservé pour compatibilité API)
     * @return array Tableau associatif [gpio => state]
     */
    public function getOutputsState(\PDO $pdo, array $gpioList, bool $skipCache = false): array
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
        
        // Valider le nom de table pour sécurité
        $allowedTables = ['ffp3Outputs', 'ffp3Outputs2', 'ffp3Outputs3', 'ffp3Outputs4'];
        if (!in_array($table, $allowedTables, true)) {
            throw new \InvalidArgumentException("Table name not allowed: {$table}");
        }
        
        $sql = "SELECT gpio, state FROM `{$table}` WHERE gpio IN (" . implode(',', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Indexer par gpio pour accès rapide
        $byGpio = [];
        foreach ($rows as $row) {
            $byGpio[(int)$row['gpio']] = $row['state'];
        }
        
        // Normalisation via StateNormalizer ; si absent en BDD, utiliser valeur par défaut
        $result = [];
        foreach ($gpioList as $gpio) {
            $state = array_key_exists($gpio, $byGpio)
                ? $byGpio[$gpio]
                : (self::DEFAULT_STATE[$gpio] ?? 0);
            $state = StateNormalizer::normalize($gpio, $state);
            $result[(string)$gpio] = $state;
        }
        
        // v11.172: Ajouter noms symboliques (double format rétrocompatible)
        // Permet au firmware d'utiliser les clés numériques OU symboliques
        $gpioToSymbol = OutputSyncService::getGpioMapping();
        foreach ($result as $gpioStr => $state) {
            $gpio = (int)$gpioStr;
            if (isset($gpioToSymbol[$gpio])) {
                $result[$gpioToSymbol[$gpio]] = $state;
            }
        }
        
        // Demande OTA depuis la page contrôle : une seule réponse contient triggerOtaCheck puis le flag est effacé
        if (isset(self::$triggerOtaRequested[$env]) && self::$triggerOtaRequested[$env]) {
            $result['triggerOtaCheck'] = true;
            unset(self::$triggerOtaRequested[$env]);
        }
        
        return $result;
    }

    /**
     * Enregistre une demande de vérification OTA pour l'environnement courant.
     * Le prochain GET state (ESP32 ou page) recevra triggerOtaCheck: true une fois.
     */
    public function setTriggerOtaCheckRequested(): void
    {
        $env = TableConfig::getEnvironment();
        self::$triggerOtaRequested[$env] = true;
    }
    
    /**
     * Invalide le cache (no-op, conservé pour compatibilité API).
     * Le cache a été supprimé ; les appels depuis OutputService restent sans effet.
     */
    public function invalidateCache(): void
    {
        // NOP - cache supprimé
    }

    /**
     * Invalide le cache pour tous les environnements (no-op, conservé pour compatibilité API).
     */
    public function invalidateAllEnvironments(): void
    {
        // NOP - cache supprimé
    }
    
    /**
     * Obtient les statistiques du cache (toujours vide, conservé pour compatibilité API).
     *
     * @return array Statistiques avec valid=false, cached_items=0
     */
    public function getCacheStats(): array
    {
        return [
            'valid' => false,
            'environment' => TableConfig::getEnvironment(),
            'age_seconds' => null,
            'ttl_seconds' => 0,
            'cached_items' => 0,
        ];
    }
}
