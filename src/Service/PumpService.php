<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\TableConfig;
use App\Util\TableValidator;
use PDO;

/**
 * Service de gestion des pompes (aquarium et réserve) via les GPIO.
 * Permet de lire et modifier l'état des sorties physiques, et d'assurer la compatibilité avec la logique legacy.
 */
class PumpService
{
    /**
     * Source inscrite dans `lastModifiedBy` pour toute écriture de ce service
     * (CRON : sécurité marée, redémarrage différé, reset ESP).
     *
     * Doit appartenir à {@see \App\Repository\OutputRepository::SERVER_OWNED_SOURCES},
     * sans quoi le POST firmware suivant réécrirait immédiatement la commande.
     */
    public const MODIFIED_BY = 'cron';

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
     * Colonnes présentes dans la table d'outputs, par table puis par colonne.
     * Mémoïsé PAR TABLE : `TableConfig::setEnvironment()` peut changer la table
     * cible au sein d'un même process (CRON multi-environnements).
     *
     * @var array<string, array<string, bool>>
     */
    private array $outputsColumnCache = [];

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

    /**
     * Colonnes de la table d'outputs, détectées de façon PORTABLE.
     *
     * Historique (corrigé en 6.31.0) : la détection interrogeait `PRAGMA table_info`,
     * syntaxe propre à SQLite. En MySQL — le seul moteur de production — la requête
     * levait une PDOException (ERRMODE_EXCEPTION, cf. {@see \App\Config\Database}),
     * silencieusement avalée : la garde `name` n'était donc JAMAIS appliquée en prod,
     * et une requête invalide partait au serveur à chaque écriture.
     *
     * Même approche que {@see \App\Service\OutputCacheService} : on branche sur le
     * driver PDO plutôt que de supposer un moteur.
     *
     * @return array<string, bool> colonne => présente
     */
    private function outputsColumns(string $table): array
    {
        if (isset($this->outputsColumnCache[$table])) {
            return $this->outputsColumnCache[$table];
        }

        $columns = [];
        try {
            $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $sql = $driver === 'mysql'
                ? "SHOW COLUMNS FROM `{$table}`"
                : "PRAGMA table_info(`{$table}`)";
            $stmt = $this->pdo->query($sql);
            if ($stmt !== false) {
                // MySQL (SHOW COLUMNS) et SQLite (PRAGMA) exposent tous deux le nom
                // de la colonne, sous la clé `Field` / `name` respectivement.
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $name = $row['Field'] ?? $row['name'] ?? null;
                    if (is_string($name) && $name !== '') {
                        $columns[$name] = true;
                    }
                }
            }
        } catch (\Throwable) {
            // Introspection indisponible : on retombe sur l'UPDATE minimal (state seul),
            // qui reste valide sur tout schéma.
            $columns = [];
        }

        $this->outputsColumnCache[$table] = $columns;

        return $columns;
    }

    private function outputsTableHasColumn(string $table, string $column): bool
    {
        return ($this->outputsColumns($table)[$column] ?? false) === true;
    }

    /**
     * Retourne l'état (0/1) d'un GPIO donné.
     *
     * @param int $gpio Numéro du GPIO
     * @return int|null État du GPIO (1=ON, 0=OFF) ou null si non trouvé
     */
    public function getState(int $gpio): ?int
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());
        $stmt = $this->pdo->prepare("SELECT state FROM `{$table}` WHERE gpio = :gpio");
        $stmt->execute([':gpio' => $gpio]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? (int) $row['state'] : null;
    }

    /**
     * Modifie l'état d'un GPIO donné.
     *
     * CORRECTION v11.38 : ne met à jour QUE les GPIO existants avec un nom défini
     * (garde appliquée dès que la colonne `name` existe — voir {@see outputsColumns()}).
     *
     * CORRECTION 6.31.0 : écrit AUSSI `requestTime` et `lastModifiedBy` quand ces
     * colonnes existent. Sans elles, la commande n'était couverte par AUCUNE fenêtre
     * de priorité : la clause anti-écrasement du POST firmware
     * ({@see \App\Repository\OutputRepository::batchUpdateStatesSingleQuery()}) teste
     * exactement `lastModifiedBy` / `requestTime`. L'arrêt de pompe « sécurité marée »
     * du CRON laissait donc `lastModifiedBy = 'esp32'` et un `requestTime` ancien →
     * le premier POST firmware (l'ESP32 poste bien plus souvent que la fenêtre de
     * 12 s) remettait la pompe à l'état renvoyé par l'ESP32, annulant la sécurité
     * avant même que l'appareil ait relu `/api/outputs/state` (poll 6 s).
     *
     * @param int    $gpio       Numéro du GPIO
     * @param int    $state      Nouvel état (1=ON, 0=OFF)
     * @param string $modifiedBy Source de l'écriture (défaut {@see self::MODIFIED_BY})
     */
    public function setState(int $gpio, int $state, string $modifiedBy = self::MODIFIED_BY): void
    {
        $table = TableValidator::validateOutputsTable(TableConfig::getOutputsTable());

        $assignments = ['state = :state'];
        $params = [':gpio' => $gpio, ':state' => $state];

        if ($this->outputsTableHasColumn($table, 'requestTime')) {
            // CURRENT_TIMESTAMP plutôt que NOW() : identique en MySQL, et portable SQLite.
            $assignments[] = 'requestTime = CURRENT_TIMESTAMP';
        }
        if ($this->outputsTableHasColumn($table, 'lastModifiedBy')) {
            $assignments[] = 'lastModifiedBy = :modifiedBy';
            $params[':modifiedBy'] = $modifiedBy;
        }

        $where = 'gpio = :gpio';
        if ($this->outputsTableHasColumn($table, 'name')) {
            $where .= " AND name IS NOT NULL AND name != ''";
        }

        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $assignments) . ' WHERE ' . $where;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            error_log(sprintf(
                '[%s] PumpService: GPIO %d non mis a jour (ligne absente ou sans nom) dans %s',
                date('Y-m-d H:i:s'),
                $gpio,
                $table
            ));
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
     * Arrête la pompe réserve (GPIO 18 à 0).
     *
     * Convention alignée depuis la 6.30.0 sur le contrat unique du GPIO 18 : `1` = ON,
     * `0` = OFF — celui de la page /aquaponie-control, du `GET /api/outputs/state` et du
     * firmware (`gpio_parser.cpp` déclenche sur front montant). L'ancienne logique
     * relais actif-bas (`stop` écrivait `1`) était inverse du canal réellement lu par
     * l'ESP32 : un « arrêt » y commandait un démarrage de remplissage.
     */
    public function stopPompeTank(): void
    {
        $this->setState($this->gpioPompeTank, 0);
    }

    /**
     * Démarre la pompe réserve (GPIO 18 à 1).
     *
     * Voir {@see stopPompeTank()} pour la convention et l'historique.
     */
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

    public function rebootEsp(): void
    {
        $this->setState($this->gpioResetMode, 1);
    }

}
