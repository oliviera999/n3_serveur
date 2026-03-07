<?php

namespace App\Service;

use PDO;

class SensorDataService
{
    /**
     * Règles de nettoyage chargées depuis les variables d'environnement avec des valeurs par défaut.
     * @var array<string, array<string, float>>
     */
    private array $cleaningRules = [];

    public function __construct(
        private PDO $pdo,
        private LogService $logger
    ) {
        $this->cleaningRules = [
            'TempEau' => [
                'min' => (float) ($_ENV['CLEAN_MIN_TEMP_EAU'] ?? 3.0),
                'max' => (float) ($_ENV['CLEAN_MAX_TEMP_EAU'] ?? 25.0),
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
     * Compte le nombre de valeurs aberrantes (inférieures au seuil min)
     */
    public function countAbnormalLowValues(string $column, float $threshold): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM ffp3Data WHERE $column < :threshold");
        $stmt->execute([':threshold' => $threshold]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $result['COUNT(*)'];
    }
    
    /**
     * Compte le nombre de valeurs aberrantes (supérieures au seuil max)
     */
    public function countAbnormalHighValues(string $column, float $threshold): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM ffp3Data WHERE $column > :threshold");
        $stmt->execute([':threshold' => $threshold]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $result['COUNT(*)'];
    }
    
    /**
     * Nettoie les valeurs aberrantes en les remplaçant par NULL
     */
    public function cleanAbnormalLowValues(string $column, float $threshold): void
    {
        $stmt = $this->pdo->prepare("UPDATE ffp3Data SET $column = NULL WHERE $column < :threshold");
        $stmt->execute([':threshold' => $threshold]);
    }
    
    /**
     * Nettoie les valeurs aberrantes en les remplaçant par NULL
     */
    public function cleanAbnormalHighValues(string $column, float $threshold): void
    {
        $stmt = $this->pdo->prepare("UPDATE ffp3Data SET $column = NULL WHERE $column > :threshold");
        $stmt->execute([':threshold' => $threshold]);
    }
    
    /**
     * Exécute le nettoyage complet des données des capteurs en suivant les règles définies.
     * @return array Information sur les valeurs nettoyées
     */
    public function cleanAllSensorData(): array
    {
        $cleaningStats = [];
        
        foreach ($this->cleaningRules as $column => $rules) {
            if (isset($rules['min'])) {
                $count = $this->countAbnormalLowValues($column, $rules['min']);
                if ($count > 0) {
                    $this->cleanAbnormalLowValues($column, $rules['min']);
                    $cleaningStats[$column . '_low'] = $count;
                    $this->logger->info("Nettoyage : {$count} valeur(s) basse(s) supprimée(s) pour la colonne {$column}.");
                }
            }
            
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