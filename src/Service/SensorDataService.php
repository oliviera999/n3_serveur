<?php

declare(strict_types=1);

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
     * Liste des colonnes autorisées pour les opérations de nettoyage.
     * Protection contre l'injection SQL via les noms de colonnes.
     */
    private const ALLOWED_COLUMNS = [
        'TempEau',
        'TempAir',
        'Humidite',
        'EauAquarium',
        'EauReserve',
        'EauPotager',
        'Luminosite',
    ];

    /**
     * Règles de nettoyage chargées depuis les variables d'environnement avec des valeurs par défaut.
     * Exemple : [ 'TempEau' => [ 'min' => 3.0, 'max' => 50.0 ], ... ]
     * @var array<string, array<string, float>>
     */
    private array $cleaningRules = [];

    private function getEnvFloat(string $key, float $default): float
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (float) $value;
        }
        if (isset($_ENV[$key]) && (string) $_ENV[$key] !== '') {
            return (float) $_ENV[$key];
        }
        return $default;
    }

    private function getConfigFloat(string $key, float $default): float
    {
        return $this->operationalSettings?->float($key, $default)
            ?? $this->getEnvFloat($key, $default);
    }

    /**
     * Constructeur : injection de la connexion PDO et du logger, et chargement des règles de nettoyage.
     */
    public function __construct(
        private PDO $pdo,
        private LogService $logger,
        private ?OperationalSettingsService $operationalSettings = null,
    ) {
        $this->loadCleaningRules();
    }

    private function loadCleaningRules(): void
    {
        $this->cleaningRules = [
            'TempEau' => [
                'min' => $this->getConfigFloat('CLEAN_MIN_TEMP_EAU', 3.0),
                'max' => $this->getConfigFloat('CLEAN_MAX_TEMP_EAU', 50.0),
            ],
            'TempAir' => [
                'min' => $this->getConfigFloat('CLEAN_MIN_TEMP_AIR', 3.0),
            ],
            'Humidite' => [
                'min' => $this->getConfigFloat('CLEAN_MIN_HUMIDITE', 3.0),
            ],
            // EauAquarium = distance capteur→surface (mm). Une distance FAIBLE est un
            // trop-plein légitime (limFlood typique 80 mm), pas une valeur aberrante.
            // Défaut min = 0 : seuls les négatifs (glitch capteur) sont nullifiés.
            // Un CLEAN_MIN trop haut (ex. ancien défaut 40) effaçait les débordements
            // sévères avant l'alerte dérivée — voir CronOrchestrator::runFrequentTasks.
            'EauAquarium' => [
                'min' => $this->getConfigFloat('CLEAN_MIN_EAU_AQUARIUM', 0.0),
                'max' => $this->getConfigFloat('CLEAN_MAX_EAU_AQUARIUM', 700.0),
            ],
            'EauReserve' => [
                'min' => $this->getConfigFloat('CLEAN_MIN_EAU_RESERVE', 15.0),
                'max' => $this->getConfigFloat('CLEAN_MAX_EAU_RESERVE', 1000.0),
            ],
        ];
    }

    /**
     * Valide qu'un nom de colonne est autorisé.
     *
     * @param string $column Nom de la colonne à valider
     * @throws \InvalidArgumentException Si la colonne n'est pas autorisée
     */
    private function validateColumn(string $column): void
    {
        if (!in_array($column, self::ALLOWED_COLUMNS, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Colonne non autorisée: %s. Colonnes valides: %s',
                    $column,
                    implode(', ', self::ALLOWED_COLUMNS)
                )
            );
        }
    }

    /**
     * Compte le nombre de valeurs trop basses pour une colonne donnée.
     * @param string $column Nom de la colonne à vérifier
     * @param float $threshold Seuil minimum
     * @return int Nombre de valeurs trouvées
     * @throws \InvalidArgumentException Si la colonne n'est pas autorisée
     */
    public function countAbnormalLowValues(string $column, float $threshold): int
    {
        $this->validateColumn($column);
        $table = \App\Config\TableConfig::getDataTable();
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` < :threshold");
        $stmt->execute([':threshold' => $threshold]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $result['COUNT(*)'];
    }

    /**
     * Compte le nombre de valeurs trop hautes pour une colonne donnée.
     * @param string $column Nom de la colonne à vérifier
     * @param float $threshold Seuil maximum
     * @return int Nombre de valeurs trouvées
     * @throws \InvalidArgumentException Si la colonne n'est pas autorisée
     */
    public function countAbnormalHighValues(string $column, float $threshold): int
    {
        $this->validateColumn($column);
        $table = \App\Config\TableConfig::getDataTable();
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` > :threshold");
        $stmt->execute([':threshold' => $threshold]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $result['COUNT(*)'];
    }

    /**
     * Remplace par NULL toutes les valeurs trop basses pour une colonne donnée.
     *
     * @param string $column Nom de la colonne à nettoyer
     * @param float $threshold Seuil minimum
     * @param string|null $since Si fourni, ne balaie que les lignes `reading_time > :since`
     *                           (profite de l'index reading_time et évite un full-scan).
     * @return int Nombre de lignes effectivement corrigées (rowCount de l'UPDATE)
     * @throws \InvalidArgumentException Si la colonne n'est pas autorisée
     */
    public function cleanAbnormalLowValues(string $column, float $threshold, ?string $since = null): int
    {
        return $this->cleanAbnormalValues($column, '<', $threshold, $since);
    }

    /**
     * Remplace par NULL toutes les valeurs trop hautes pour une colonne donnée.
     *
     * @param string $column Nom de la colonne à nettoyer
     * @param float $threshold Seuil maximum
     * @param string|null $since Si fourni, ne balaie que les lignes `reading_time > :since`.
     * @return int Nombre de lignes effectivement corrigées (rowCount de l'UPDATE)
     * @throws \InvalidArgumentException Si la colonne n'est pas autorisée
     */
    public function cleanAbnormalHighValues(string $column, float $threshold, ?string $since = null): int
    {
        return $this->cleanAbnormalValues($column, '>', $threshold, $since);
    }

    /**
     * Facteur commun des nettoyages bas/haut : émet un unique UPDATE et renvoie
     * son rowCount (plus de COUNT préalable). L'opérateur est une constante littérale
     * ('<' ou '>'), jamais une entrée externe → aucune injection possible.
     *
     * @param string $operator '<' ou '>'
     */
    private function cleanAbnormalValues(string $column, string $operator, float $threshold, ?string $since): int
    {
        $this->validateColumn($column);
        $table = \App\Config\TableConfig::getDataTable();

        $sql = "UPDATE `{$table}` SET `{$column}` = NULL WHERE `{$column}` {$operator} :threshold";
        $params = [':threshold' => $threshold];

        // Fenêtre glissante : restreint le balayage aux lignes récentes pour
        // exploiter l'index reading_time (les anciennes lignes ont déjà été nettoyées
        // lors des passages précédents ; inutile de les re-scanner chaque minute).
        if ($since !== null) {
            $sql .= ' AND reading_time > :since';
            $params[':since'] = $since;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Exécute le nettoyage complet des données des capteurs en suivant les règles définies.
     * Pour chaque colonne, applique les seuils min et max si définis.
     * Logge les opérations et retourne un tableau des corrections effectuées.
     *
     * Perf : un seul UPDATE par colonne/borne (le rowCount fournit le compte), au
     * lieu d'un COUNT + un UPDATE. Combiné à `$since`, le balayage reste borné aux
     * lignes récentes (index reading_time) plutôt qu'à toute la table à chaque minute.
     *
     * @param string|null $since Borne basse `reading_time > :since` (fenêtre glissante).
     *                           null = balayage complet (rétro-compat / tables sans reading_time).
     * @return array Information sur les valeurs nettoyées (ex : ['TempEau_low' => 2, 'TempEau_high' => 1])
     */
    public function cleanAllSensorData(?string $since = null): array
    {
        $cleaningStats = [];

        foreach ($this->cleaningRules as $column => $rules) {
            // Nettoyage des valeurs trop basses
            if (isset($rules['min'])) {
                $count = $this->cleanAbnormalLowValues($column, $rules['min'], $since);
                if ($count > 0) {
                    $cleaningStats[$column . '_low'] = $count;
                    $this->logger->info("Nettoyage : {$count} valeur(s) basse(s) supprimée(s) pour la colonne {$column}.");
                }
            }

            // Nettoyage des valeurs trop hautes
            if (isset($rules['max'])) {
                $count = $this->cleanAbnormalHighValues($column, $rules['max'], $since);
                if ($count > 0) {
                    $cleaningStats[$column . '_high'] = $count;
                    $this->logger->info("Nettoyage : {$count} valeur(s) haute(s) supprimée(s) pour la colonne {$column}.");
                }
            }
        }

        return $cleaningStats;
    }
}
