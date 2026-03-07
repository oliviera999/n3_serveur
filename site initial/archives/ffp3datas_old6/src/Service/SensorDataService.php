<?php

namespace App\Service;

use PDO;

class SensorDataService
{
    /** Seuils pour les valeurs aberrantes */
    private const MIN_TEMP_EAU = 3.0;
    private const MIN_TEMP_AIR = 3.0;
    private const MIN_HUMIDITE = 3.0;
    private const MIN_EAU_AQUARIUM = 4.0;
    private const MIN_EAU_RESERVE = 10.0;
    
    private const MAX_EAU_AQUARIUM = 70.0;
    private const MAX_EAU_RESERVE = 90.0;
    private const MAX_TEMP_EAU = 25.0;

    /**
     * @var array<string, array<string, float>>
     */
    private const CLEANING_RULES = [
        'TempEau'     => ['min' => self::MIN_TEMP_EAU, 'max' => self::MAX_TEMP_EAU],
        'TempAir'     => ['min' => self::MIN_TEMP_AIR],
        'Humidite'    => ['min' => self::MIN_HUMIDITE],
        'EauAquarium' => ['min' => self::MIN_EAU_AQUARIUM, 'max' => self::MAX_EAU_AQUARIUM],
        'EauReserve'  => ['min' => self::MIN_EAU_RESERVE, 'max' => self::MAX_EAU_RESERVE],
    ];
    
    public function __construct(
        private PDO $pdo,
        private LogService $logger
    ) {}
    
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
        
        foreach (self::CLEANING_RULES as $column => $rules) {
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