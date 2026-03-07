<?php

namespace App\Service;

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
        $stmt = $this->pdo->prepare('SELECT state FROM ffp3Outputs WHERE gpio = :gpio');
        $stmt->execute([':gpio' => $gpio]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? (int)$row['state'] : null;
    }

    /**
     * Modifie l'état d'un GPIO donné.
     *
     * @param int $gpio  Numéro du GPIO
     * @param int $state Nouvel état (1=ON, 0=OFF)
     */
    public function setState(int $gpio, int $state): void
    {
        $stmt = $this->pdo->prepare('UPDATE ffp3Outputs SET state = :state WHERE gpio = :gpio');
        $stmt->execute([':state' => $state, ':gpio' => $gpio]);

        // Si aucune ligne n'a été mise à jour, on insère un nouvel enregistrement
        if ($stmt->rowCount() === 0) {
            $insert = $this->pdo->prepare('INSERT INTO ffp3Outputs (gpio, state) VALUES (:gpio, :state)');
            $insert->execute([':gpio' => $gpio, ':state' => $state]);
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
     * Arrête la pompe réserve (GPIO à 1, logique inversée pour compatibilité legacy)
     */
    public function stopPompeTank(): void
    {
        // La logique legacy inverse les états pour la pompe Tank (1 = off) → on conserve la compatibilité
        $this->setState($this->gpioPompeTank, 1);
    }

    public function runPompeTank(): void
    {
        $this->setState($this->gpioPompeTank, 0);
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

    public function rebootEsp(): void
    {
        $this->setState($this->gpioResetMode, 1);
    }

    // Expose les valeurs GPIO pour d'éventuels usages externes
    public function getAquaGpio(): int { return $this->gpioPompeAqua; }
    public function getTankGpio(): int { return $this->gpioPompeTank; }
    public function getResetModeGpio(): int { return $this->gpioResetMode; }
} 