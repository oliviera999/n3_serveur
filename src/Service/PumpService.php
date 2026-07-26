<?php

declare(strict_types=1);

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
     * Indique si la table d'outputs expose une colonne `name`.
     * Null = non encore déterminé.
     */
    private ?bool $outputsTableHasNameColumn = null;

    /**
     * @param PDO $pdo Connexion PDO à la base de données (injectée)
     */
    public function __construct(private PDO $pdo)
    {
        // Chargement des GPIO depuis l'environnement (getenv prioritaire) ou valeurs par défaut
        $this->gpioPompeAqua = $this->getEnvInt('GPIO_POMPE_AQUA', 16);
        $this->gpioPompeTank = $this->getEnvInt('GPIO_POMPE_TANK', 18);
        $this->gpioResetMode = $this->getEnvInt('GPIO_RESET_MODE', 110);
    }

    private function getEnvInt(string $key, int $default): int
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (int) $value;
        }
        if (isset($_ENV[$key]) && (string) $_ENV[$key] !== '') {
            return (int) $_ENV[$key];
        }
        return $default;
    }

    private function outputsTableHasNameColumn(string $table): bool
    {
        if ($this->outputsTableHasNameColumn !== null) {
            return $this->outputsTableHasNameColumn;
        }

        try {
            // Compatible SQLite (tests) ; si indisponible/erreur, on bascule en fallback sans filtre.
            $stmt = $this->pdo->query("PRAGMA table_info({$table})");
            if ($stmt === false) {
                $this->outputsTableHasNameColumn = false;
                return $this->outputsTableHasNameColumn;
            }
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                if (($column['name'] ?? null) === 'name') {
                    $this->outputsTableHasNameColumn = true;
                    return true;
                }
            }
        } catch (\Throwable) {
            // No-op: fallback ci-dessous.
        }

        $this->outputsTableHasNameColumn = false;
        return false;
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

        return $row !== false ? (int) $row['state'] : null;
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

        if ($this->outputsTableHasNameColumn($table)) {
            // Préserve la règle de sécurité sur les schémas qui possèdent `name`.
            $stmt = $this->pdo->prepare(
                "UPDATE {$table}
                 SET state = :state
                 WHERE gpio = :gpio
                   AND name IS NOT NULL
                   AND name != ''"
            );
            $stmt->execute([
                ':gpio' => $gpio,
                ':state' => $state,
            ]);

            if ($stmt->rowCount() === 0) {
                error_log(sprintf('[%s] PumpService: GPIO %d ignoré - pas de nom défini dans la table', date('Y-m-d H:i:s'), $gpio));
            }
            return;
        }

        // Fallback pour schémas legacy/minimaux (notamment tests SQLite).
        $stmt = $this->pdo->prepare("UPDATE {$table} SET state = :state WHERE gpio = :gpio");
        $stmt->execute([
            ':gpio' => $gpio,
            ':state' => $state,
        ]);
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
     * Arrête la pompe réserve (relais active-low : state BDD = 1).
     * Ne pas confondre avec la page /aquaponie-control ni le GET /api/outputs/state (1 = ON).
     *
     * ⚠️ SANS APPELANT APPLICATIF depuis la 6.27.0 (le CRON « aquarium bas » n'agit plus sur la
     * pompe). La convention ci-dessus est INVERSE de celle du GPIO 18 servi au firmware : écrire
     * `1` ici revient à commander un DÉMARRAGE côté ESP32 (front montant, `gpio_parser.cpp`).
     * Ne pas réintroduire d'appel sans convertir la convention au préalable.
     */
    public function stopPompeTank(): void
    {
        $this->setState($this->gpioPompeTank, 1);
    }

    /**
     * Démarre la pompe réserve (relais active-low : state BDD = 0).
     *
     * ⚠️ Même réserve que {@see stopPompeTank()} : sans appelant applicatif, convention inverse
     * du contrat GPIO 18 lu par le firmware.
     */
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

}
