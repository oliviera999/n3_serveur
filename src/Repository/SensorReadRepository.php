<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\TableConfig;
use App\Service\OperationalSettingsService;
use App\Util\TableValidator;
use DateTimeInterface;
use PDO;

/**
 * Repository dédié à la lecture des données capteurs depuis la base de données.
 * Permet de centraliser les requêtes de récupération, d'export et d'analyse des mesures.
 */
class SensorReadRepository extends AbstractRepository
{
    public function __construct(
        PDO $pdo,
        private ?OperationalSettingsService $operationalSettings = null,
    ) {
        parent::__construct($pdo);
    }

    /**
     * Récupère tous les enregistrements de mesures entre deux dates (incluses).
     *
     * @param DateTimeInterface|string $start Date/heure de début (objet ou string SQL)
     * @param DateTimeInterface|string $end   Date/heure de fin (objet ou string SQL)
     * @param string $order                   Sens du tri sur reading_time : 'DESC' (défaut) ou 'ASC'
     * @return array<array<string, mixed>>    Tableau associatif de mesures (une par ligne)
     *
     * Cette méthode convertit les objets DateTime en string SQL si besoin, puis exécute
     * une requête préparée pour récupérer les mesures dans l'intervalle demandé.
     *
     * Le paramètre $order permet de récupérer directement les lectures en ordre
     * chronologique (ASC) sans array_reverse côté consommateur.
     */
    public function fetchBetween(
        DateTimeInterface|string $start,
        DateTimeInterface|string $end,
        string $order = 'DESC'
    ): array {
        // Conversion des dates en string SQL si besoin
        if ($start instanceof DateTimeInterface) {
            $start = $start->format('Y-m-d H:i:s');
        }
        if ($end instanceof DateTimeInterface) {
            $end = $end->format('Y-m-d H:i:s');
        }

        // Validation stricte du sens de tri (whitelist) pour éviter toute injection.
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        // Requête SQL multi-colonnes, triée par date selon $order
        $table = TableValidator::validateDataTable(TableConfig::getDataTable());
        $fallbackFilter = $this->excludeDhtFallbackFilter();
        $sql = <<<SQL
            SELECT id, TempAir, Humidite, TempEau, EauPotager, EauAquarium, EauReserve, diffMaree, Luminosite,
                   etatPompeAqua, etatPompeTank, etatHeat, etatUV, bouffePetits, bouffeGros, reading_time
            FROM `{$table}`
            WHERE reading_time BETWEEN :start AND :end{$fallbackFilter}
            ORDER BY reading_time {$order}
        SQL;

        return $this->fetchAll($sql, [':start' => $start, ':end' => $end]);
    }

    /**
     * Récupère une série réduite dédiée à l'analyse de marées, en ordre chronologique (ASC).
     *
     * Ne charge que les 4 colonnes nécessaires (EauAquarium, EauReserve, diffMaree,
     * reading_time) afin d'alléger les requêtes couvrant de larges plages
     * (ex. 6 mois pour les séries hebdomadaires).
     *
     * @param DateTimeInterface|string $start Date/heure de début
     * @param DateTimeInterface|string $end   Date/heure de fin
     * @return array<array<string, mixed>>    Lignes en ordre chronologique ASC
     */
    public function fetchTideSeriesBetween(DateTimeInterface|string $start, DateTimeInterface|string $end): array
    {
        if ($start instanceof DateTimeInterface) {
            $start = $start->format('Y-m-d H:i:s');
        }
        if ($end instanceof DateTimeInterface) {
            $end = $end->format('Y-m-d H:i:s');
        }

        $table = TableValidator::validateDataTable(TableConfig::getDataTable());
        $sql = <<<SQL
            SELECT EauAquarium, EauReserve, diffMaree, reading_time
            FROM `{$table}`
            WHERE reading_time BETWEEN :start AND :end
            ORDER BY reading_time ASC
        SQL;

        return $this->fetchAll($sql, [':start' => $start, ':end' => $end]);
    }

    /**
     * Retourne la date/heure de la première mesure enregistrée, ou null si aucune donnée.
     *
     * @return string|null Date/heure SQL de la première mesure, ou null
     */
    public function getFirstReadingDate(): ?string
    {
        $table = TableValidator::validateDataTable(TableConfig::getDataTable());
        $sql = "SELECT MIN(reading_time) AS first_date FROM `{$table}`";

        $result = $this->fetchOne($sql);
        return $result['first_date'] ?? null;
    }

    /**
     * Retourne la date/heure de la dernière mesure enregistrée, ou null si aucune donnée.
     *
     * @return string|null Date/heure SQL de la dernière mesure, ou null
     */
    public function getLastReadingDate(): ?string
    {
        $table = TableValidator::validateDataTable(TableConfig::getDataTable());
        $sql = "SELECT MAX(reading_time) AS last_date FROM `{$table}`";

        $result = $this->fetchOne($sql);
        return $result['last_date'] ?? null;
    }

    /**
     * Exporte les données dans un fichier CSV (chemin fourni) et retourne le nombre de lignes écrites.
     *
     * @param DateTimeInterface|string $start Date/heure de début
     * @param DateTimeInterface|string $end   Date/heure de fin
     * @param string $filePath                Chemin du fichier CSV à créer
     */
    public function exportCsv(DateTimeInterface|string $start, DateTimeInterface|string $end, string $filePath): int
    {
        // Conversion des dates en string SQL si besoin
        if ($start instanceof DateTimeInterface) {
            $start = $start->format('Y-m-d H:i:s');
        }
        if ($end instanceof DateTimeInterface) {
            $end = $end->format('Y-m-d H:i:s');
        }

        // Écriture en streaming : on itère ligne à ligne sur le statement PDO
        // (fetch() dans une boucle) plutôt que de tout charger en mémoire via fetchAll.
        // La liste de colonnes est définie une fois puis réutilisée pour le SELECT ET
        // l'en-tête CSV, garantissant leur cohérence même sur une plage sans donnée.
        $columns = [
            'id', 'TempAir', 'Humidite', 'TempEau', 'EauPotager', 'EauAquarium', 'EauReserve',
            'diffMaree', 'Luminosite', 'etatPompeAqua', 'etatPompeTank', 'etatHeat', 'etatUV',
            'bouffePetits', 'bouffeGros', 'reading_time',
        ];

        $table = TableValidator::validateDataTable(TableConfig::getDataTable());
        $columnList = implode(', ', $columns);
        $sql = <<<SQL
            SELECT {$columnList}
            FROM `{$table}`
            WHERE reading_time BETWEEN :start AND :end
            ORDER BY reading_time DESC
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':start' => $start, ':end' => $end]);

        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Impossible d\'ouvrir le fichier ' . $filePath);
        }

        // En-tête écrit SYSTÉMATIQUEMENT (même sans donnée) afin qu'une plage vide produise
        // un CSV valide listant les colonnes attendues (cf. CsvExportService, bug B1).
        // Les paramètres séparateur/enclosure/escape sont fournis explicitement pour
        // conserver le format historique et éviter la dépréciation PHP 8.1+ de $escape.
        fputcsv($handle, $columns, ',', '"', '\\');

        $count = 0;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            fputcsv($handle, $row, ',', '"', '\\');
            $count++;
        }

        fclose($handle);

        return $count;
    }

    /**
     * Récupère la ou les dernières lectures enregistrées.
     * @param int $limit Nombre de lignes à remonter (1 par défaut).
     *                  Si $limit vaut 1, un tableau associatif représentant la ligne est renvoyé.
     *                  Sinon, un tableau de tableaux est retourné.
     * @return array<string, mixed>|array<int, array<string, mixed>> Tableau(x) des dernières lectures.
     */
    public function getLastReadings(int $limit = 1): array
    {
        $table = TableValidator::validateDataTable(TableConfig::getDataTable());
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 10_000) {
            $limit = 10_000;
        }
        // Limite en SQL entier : avec MySQL, LIMIT lié (:limit) peut produire un ordre incohérent selon le driver PDO.
        // Départage par `id DESC` (6.34.0) : `reading_time` a une résolution d'une SECONDE,
        // donc deux lignes de la même seconde rendaient la « dernière lecture » arbitraire
        // — or les alertes dérivées et la page de contrôle en dépendent. La colonne `id`
        // est déjà sélectionnée explicitement par d'autres requêtes de ce repository.
        $sql = sprintf(
            'SELECT * FROM `%s` ORDER BY reading_time DESC, id DESC LIMIT %d',
            $table,
            $limit
        );
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return [];
        }

        if ($limit === 1) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupère toutes les lectures depuis une date donnée
     *
     * @param string $sinceDate Date au format 'Y-m-d H:i:s'
     * @return array<array<string, mixed>> Tableau de lectures
     */
    public function getReadingsSince(string $sinceDate): array
    {
        $table = TableValidator::validateDataTable(TableConfig::getDataTable());
        $sql = <<<SQL
            SELECT id, TempAir, Humidite, TempEau, EauPotager, EauAquarium, EauReserve, diffMaree, Luminosite,
                   etatPompeAqua, etatPompeTank, etatHeat, etatUV, bouffePetits, bouffeGros, reading_time
            FROM `{$table}`
            WHERE reading_time > :since_date
            ORDER BY reading_time ASC
        SQL;

        return $this->fetchAll($sql, [':since_date' => $sinceDate]);
    }

    /**
     * Compte le nombre de lectures entre deux dates
     *
     * @param string $start Date de début au format 'Y-m-d H:i:s'
     * @param string $end Date de fin au format 'Y-m-d H:i:s'
     * @return int Nombre de lectures
     */
    public function countReadingsBetween(string $start, string $end): int
    {
        $table = TableValidator::validateDataTable(TableConfig::getDataTable());
        $sql = "SELECT COUNT(*) as count FROM `{$table}` WHERE reading_time BETWEEN :start AND :end";

        $result = $this->fetchOne($sql, [':start' => $start, ':end' => $end]);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Récupère la version du firmware de la dernière mesure enregistrée
     *
     * @return string Version du firmware (ex: "10.90") ou "N/A" si aucune donnée
     */
    public function getFirmwareVersion(): string
    {
        $table = TableValidator::validateDataTable(TableConfig::getDataTable());
        $sql = "SELECT version FROM `{$table}` ORDER BY reading_time DESC LIMIT 1";

        $result = $this->fetchOne($sql);
        return $result['version'] ?? 'N/A';
    }

    private function excludeDhtFallbackFilter(): string
    {
        $exclude = $this->operationalSettings?->bool('STATS_EXCLUDE_DHT_FALLBACK', false)
            ?? filter_var($_ENV['STATS_EXCLUDE_DHT_FALLBACK'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

        return $exclude ? ' AND NOT (TempAir = 20 AND Humidite = 50)' : '';
    }
}
