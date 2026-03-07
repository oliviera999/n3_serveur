<?php

namespace App\Service;

use PDO;

class PumpService
{
    // Mapping de noms symboliques -> GPIO utilisés dans la table ffp3Outputs
    public const GPIO_POMPE_AQUA  = 16;
    public const GPIO_POMPE_TANK  = 18;
    public const GPIO_RESET_MODE  = 110;

    public function __construct(private PDO $pdo) {}

    /**
     * Retourne l'état (0/1) d'un gpio.
     */
    public function getState(int $gpio): ?int
    {
        $stmt = $this->pdo->prepare('SELECT state FROM ffp3Outputs WHERE gpio = :gpio');
        $stmt->execute([':gpio' => $gpio]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? (int)$row['state'] : null;
    }

    /**
     * Modifie l'état d'un gpio.
     */
    public function setState(int $gpio, int $state): void
    {
        $stmt = $this->pdo->prepare('UPDATE ffp3Outputs SET state = :state WHERE gpio = :gpio');
        $stmt->execute([':state' => $state, ':gpio' => $gpio]);
    }

    // ---------------------------------------------------------------------
    // Méthodes pratiques
    // ---------------------------------------------------------------------
    public function stopPompeAqua(): void
    {
        $this->setState(self::GPIO_POMPE_AQUA, 0);
    }

    public function runPompeAqua(): void
    {
        $this->setState(self::GPIO_POMPE_AQUA, 1);
    }

    public function stopPompeTank(): void
    {
        // La logique legacy inverse les états pour la pompe Tank (1 = off) → on conserve la compatibilité
        $this->setState(self::GPIO_POMPE_TANK, 1);
    }

    public function runPompeTank(): void
    {
        $this->setState(self::GPIO_POMPE_TANK, 0);
    }
} 