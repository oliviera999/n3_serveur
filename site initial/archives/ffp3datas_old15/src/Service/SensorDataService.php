<?php

namespace App\Service;

use PDO;

/**
 * Service de nettoyage des données capteurs.
 * Permet de détecter et corriger (mettre à NULL) les valeurs aberrantes dans la base de données.
 * Les seuils de nettoyage sont configurables via les variables d'environnement.
 *
 * Utilisation typique :
 *   - Nettoyer les valeurs trop basses ou trop hautes pour chaque capteur.
 *   - Logger les opérations de nettoyage.
 *   - Fournir des statistiques sur le nombre de valeurs corrigées.
 */
class SensorDataService
{
    /**
     * Règles de nettoyage chargées depuis les variables d'environnement avec des valeurs par défaut.
     * Exemple : [ 'TempEau' => [ 'min' => 3.0, 'max' => 50.0 ], ... ]
     * @var array<string, array<string, float>>
     */
    private array $cleaningRules = [];

    /**
     * Constructeur : injection de la connexion PDO et du logger, et chargement des règles de nettoyage.
     */
    public function __construct(
        private PDO $pdo,
        private LogService $logger
    ) {
        // Chargement des seuils depuis l'environnement ou valeurs par défaut
        $this->cleaningRules = [
            'TempEau' => [
                'min' => (float) ($_ENV['CLEAN_MIN_TEMP_EAU'] ?? 3.0),
                'max' => (float) ($_ENV['CLEAN_MAX_TEMP_EAU'] ?? 50.0),
            ],
            'TempAir' => [
                'min' => (float) ($_ENV['CLEAN_MIN_TEMP_AIR'] ?? 3.0),
            ],
            'Humidite' => [
                'min' => (float) ($_ENV['CLEAN_MIN_HUMIDITE'] ?? 3.0),
            ],
            'EauAquarium' => [
                'min' => (float) ($_ENV['CLEAN_MIN_EAU_AQUARIUM'] ?? 4.0),
                'max' => (float) ($_ENV['CLEAN_MAX_EAU_AQUARIUM'] ?? 70.0),
            ],
            'EauReserve' => [
                'min' => (float) ($_ENV['CLEAN_MIN_EAU_RESERVE'] ?? 10.0),
                'max' => (float) ($_ENV['CLEAN_MAX_EAU_RESERVE'] ?? 90.0),
            ],
        ];
    }
    
    /**
     * Compte le nombre de valeurs trop basses pour une colonne donnée.
     * @param string $column Nom de la colonne à vérifier
     * @param float $threshold Seuil minimum
     * @return int Nombre de valeurs trouvées
     */
    public function countAbnormalLowValues(string $column, float $threshold): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM ffp3Data WHERE $column < :threshold");
        $stmt->execute([':threshold' => $threshold]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $result['COUNT(*)'];
    }
    
    /**
     * Compte le nombre de valeurs trop hautes pour une colonne donnée.
     * @param string $column Nom de la colonne à vérifier
     * @param float $threshold Seuil maximum
     * @return int Nombre de valeurs trouvées
     */
    public function countAbnormalHighValues(string $column, float $threshold): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM ffp3Data WHERE $column > :threshold");
        $stmt->execute([':threshold' => $threshold]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $result['COUNT(*)'];
    }
    
    /**
     * Remplace par NULL toutes les valeurs trop basses pour une colonne donnée.
     * @param string $column Nom de la colonne à nettoyer
     * @param float $threshold Seuil minimum
     */
    public function cleanAbnormalLowValues(string $column, float $threshold): void
    {
        $stmt = $this->pdo->prepare("UPDATE ffp3Data SET $column = NULL WHERE $column < :threshold");
        $stmt->execute([':threshold' => $threshold]);
    }
    
    /**
     * Remplace par NULL toutes les valeurs trop hautes pour une colonne donnée.
     * @param string $column Nom de la colonne à nettoyer
     * @param float $threshold Seuil maximum
     */
    public function cleanAbnormalHighValues(string $column, float $threshold): void
    {
        $stmt = $this->pdo->prepare("UPDATE ffp3Data SET $column = NULL WHERE $column > :threshold");
        $stmt->execute([':threshold' => $threshold]);
    }
    
    /**
     * Exécute le nettoyage complet des données des capteurs en suivant les règles définies.
     * Pour chaque colonne, applique les seuils min et max si définis.
     * Logge les opérations et retourne un tableau des corrections effectuées.
     * @return array Information sur les valeurs nettoyées (ex : ['TempEau_low' => 2, 'TempEau_high' => 1])
     */
    public function cleanAllSensorData(): array
    {
        $cleaningStats = [];
        
        foreach ($this->cleaningRules as $column => $rules) {
            // Nettoyage des valeurs trop basses
            if (isset($rules['min'])) {
                $count = $this->countAbnormalLowValues($column, $rules['min']);
                if ($count > 0) {
                    $this->cleanAbnormalLowValues($column, $rules['min']);
                    $cleaningStats[$column . '_low'] = $count;
                    $this->logger->info("Nettoyage : {$count} valeur(s) basse(s) supprimée(s) pour la colonne {$column}.");
                }
            }
            // Nettoyage des valeurs trop hautes
            if (isset($rules['max'])) {
                $count = $this->countAbnormalHighValues($column, $rules['max']);
                if ($count > 0) {
                    $this->cleanAbnormalHighValues($column, $rules['max']);
                    $cleaningStats[$column . '_high'] = $count;
                    $this->logger->info("Nettoyage : {$count} valeur(s) haute(s) supprimée(s) pour la colonne {$column}.");
                }
            }
        }
        
        return $cleaningStats;
    }
}