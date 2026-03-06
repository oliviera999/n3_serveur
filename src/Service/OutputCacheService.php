<?php

namespace App\Service;

use App\Config\TableConfig;
use App\Util\StateNormalizer;
use App\Service\OutputSyncService;

/**
 * Service de cache pour les états outputs
 * 
 * Réduit la charge serveur en évitant les requêtes SQL répétées
 * pour getOutputsState() qui est appelé toutes les 4-12 secondes par ESP32
 * 
 * Cache en mémoire avec TTL configurable
 * 
 * v4.9.41: Lorsque la table outputs est vide ou sans lignes pour les GPIO demandés,
 * retourne des valeurs par défaut pour tous les GPIO attendus (alignées firmware/sql)
 * pour éviter que l'ESP32 reçoive un JSON vide et affiche des défauts.
 */
class OutputCacheService
{
    /**
     * Durée de vie du cache (en secondes)
     */
    private const CACHE_TTL_SECONDS = 5; // 5 secondes (moitié de l'intervalle polling)

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
     * Cache en mémoire (static pour persister entre requêtes)
     * Séparé par environnement pour éviter les conflits PROD/TEST
     */
    private static array $cache = [];
    private static array $cacheTimestamp = [];

    /**
     * Demande de vérification OTA par environnement (page contrôle).
     * Une seule réponse GET state contiendra triggerOtaCheck: true puis le flag est effacé.
     */
    private static array $triggerOtaRequested = [];
    
    /**
     * Récupère les états outputs depuis le cache ou la base de données
     *
     * @param \PDO $pdo Connexion PDO
     * @param array $gpioList Liste des GPIOs à récupérer
     * @param bool $skipCache Si true, ignore le cache et lit toujours en BDD (pour la page de contrôle)
     * @return array Tableau associatif [gpio => state]
     */
    public function getOutputsState(\PDO $pdo, array $gpioList, bool $skipCache = false): array
    {
        $now = time();
        $env = TableConfig::getEnvironment();

        if ($gpioList === []) {
            self::$cache[$env] = [];
            self::$cacheTimestamp[$env] = $now;
            return [];
        }
        
        // Cache valide : retourner le cache sauf si skipCache (page de contrôle = données toujours à jour)
        if (!$skipCache &&
            isset(self::$cache[$env]) &&
            isset(self::$cacheTimestamp[$env]) &&
            ($now - self::$cacheTimestamp[$env]) < self::CACHE_TTL_SECONDS) {
            $cached = self::$cache[$env];
            // Demande OTA : injecter triggerOtaCheck dans la réponse même en cas de cache hit (sinon l'ESP32 ne le reçoit jamais)
            if (isset(self::$triggerOtaRequested[$env]) && self::$triggerOtaRequested[$env]) {
                unset(self::$triggerOtaRequested[$env]);
                $cached = $cached + ['triggerOtaCheck' => true];
            }
            return $cached;
        }
        
        // Requête BDD (cache expiré, inexistant, ou bypass demandé)
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
        
        // Mettre à jour le cache uniquement si on n'a pas bypassé (évite de remplir le cache avec une lecture "control")
        if (!$skipCache) {
            self::$cache[$env] = $result;
            self::$cacheTimestamp[$env] = $now;
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
     * Invalide le cache (appelé après modification d'un output)
     * Invalide le cache pour l'environnement actuel
     */
    public function invalidateCache(): void
    {
        $env = TableConfig::getEnvironment();
        unset(self::$cache[$env]);
        unset(self::$cacheTimestamp[$env]);
    }
    
    /**
     * Obtient les statistiques du cache
     * 
     * @return array Statistiques (age, valid, etc.)
     */
    public function getCacheStats(): array
    {
        $now = time();
        $env = TableConfig::getEnvironment();
        $isValid = (isset(self::$cache[$env]) && 
                   isset(self::$cacheTimestamp[$env]) && 
                   ($now - self::$cacheTimestamp[$env]) < self::CACHE_TTL_SECONDS);
        
        return [
            'valid' => $isValid,
            'environment' => $env,
            'age_seconds' => isset(self::$cacheTimestamp[$env]) ? ($now - self::$cacheTimestamp[$env]) : null,
            'ttl_seconds' => self::CACHE_TTL_SECONDS,
            'cached_items' => isset(self::$cache[$env]) ? count(self::$cache[$env]) : 0,
        ];
    }
}
