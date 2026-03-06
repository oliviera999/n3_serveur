<?php

namespace App\Service;

use App\Config\TableConfig;
use PDO;

/**
 * Service de gestion des pompes (aquarium et réserve) via les GPIO.
 * Permet de lire et modifier l'état des sorties physiques, et d'assurer la compatibilité avec la logique legacy.
 */
class PumpService
{
    /**
     * Numéro du GPIO pour la pompe aquarium (configurable via .env)
     */
    private int $gpioPompeAqua;

    /**
     * Numéro du GPIO pour la pompe réserve (configurable via .env)
     */
    private int $gpioPompeTank;

    /**
     * Numéro du GPIO pour le reset mode (configurable via .env)
     */
    private int $gpioResetMode;

    /**
     * @param PDO $pdo Connexion PDO à la base de données (injectée)
     */
    public function __construct(private PDO $pdo)
    {
        // Chargement des GPIO depuis l'environnement ou valeurs par défaut
        $this->gpioPompeAqua = (int) ($_ENV['GPIO_POMPE_AQUA'] ?? 16);
        $this->gpioPompeTank = (int) ($_ENV['GPIO_POMPE_TANK'] ?? 18);
        $this->gpioResetMode = (int) ($_ENV['GPIO_RESET_MODE'] ?? 110);
    }

    /**
     * Retourne l'état (0/1) d'un GPIO donné.
     *
     * @param int $gpio Numéro du GPIO
     * @return int|null État du GPIO (1=ON, 0=OFF) ou null si non trouvé
     */
    public function getState(int $gpio): ?int
    {
        $table = TableConfig::getOutputsTable();
        $stmt = $this->pdo->prepare("SELECT state FROM {$table} WHERE gpio = :gpio");
        $stmt->execute([':gpio' => $gpio]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? (int)$row['state'] : null;
    }

    /**
     * Modifie l'état d'un GPIO donné.
     * CORRECTION v11.38 : Ne met à jour QUE les GPIO existants avec des noms définis.
     * Évite la création de lignes NULL/inutiles.
     *
     * @param int $gpio  Numéro du GPIO
     * @param int $state Nouvel état (1=ON, 0=OFF)
     */
    public function setState(int $gpio, int $state): void
    {
        $table = TableConfig::getOutputsTable();
        
        // CORRECTION v11.38 : Ne mettre à jour QUE les GPIO qui ont des noms définis
        // Cela évite la création de lignes NULL/inutiles
        $stmt = $this->pdo->prepare(
            "UPDATE {$table} 
             SET state = :state 
             WHERE gpio = :gpio 
               AND name IS NOT NULL 
               AND name != ''"
        );
        $stmt->execute([
            ':gpio' => $gpio, 
            ':state' => $state
        ]);
        
        // Log si le GPIO n'a pas été trouvé avec un nom
        if ($stmt->rowCount() === 0) {
            error_log("PumpService: GPIO {$gpio} ignoré - pas de nom défini dans la table");
        }
    }

    // ---------------------------------------------------------------------
    // Méthodes pratiques pour piloter les pompes
    // ---------------------------------------------------------------------

    /**
     * Arrête la pompe aquarium (GPIO à 0)
     */
    public function stopPompeAqua(): void
    {
        $this->setState($this->gpioPompeAqua, 0);
    }

    /**
     * Démarre la pompe aquarium (GPIO à 1)
     */
    public function runPompeAqua(): void
    {
        $this->setState($this->gpioPompeAqua, 1);
    }

    /**
     * Arrête la pompe réserve (GPIO à 0)
     */
    public function stopPompeTank(): void
    {
        $this->setState($this->gpioPompeTank, 0);
    }

    public function runPompeTank(): void
    {
        $this->setState($this->gpioPompeTank, 1);
    }

    public function getAquaPumpState(): ?int
    {
        return $this->getState($this->gpioPompeAqua);
    }

    public function getTankPumpState(): ?int
    {
        return $this->getState($this->gpioPompeTank);
    }

    public function getResetModeState(): ?int
    {
        return $this->getState($this->gpioResetMode);
    }

}
