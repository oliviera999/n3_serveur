<?php

namespace App\Service;

use PDO;

class PumpService
{
    // GPIO configurables via .env
    private int $gpioPompeAqua;
    private int $gpioPompeTank;
    private int $gpioResetMode;

    public function __construct(private PDO $pdo)
    {
        $this->gpioPompeAqua = (int) ($_ENV['GPIO_POMPE_AQUA'] ?? 16);
        $this->gpioPompeTank = (int) ($_ENV['GPIO_POMPE_TANK'] ?? 18);
        $this->gpioResetMode = (int) ($_ENV['GPIO_RESET_MODE'] ?? 110);
    }

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
        $this->setState($this->gpioPompeAqua, 0);
    }

    public function runPompeAqua(): void
    {
        $this->setState($this->gpioPompeAqua, 1);
    }

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