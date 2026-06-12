<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\TableConfig;
use App\Domain\SensorData;
use App\Util\TableValidator;
use PDO;

/**
 * Repository responsable de l'insertion des mesures capteurs dans la base de données.
 * Permet d'abstraire la logique SQL et d'assurer la cohérence des écritures.
 */
class SensorRepository extends AbstractRepository
{
    /** @var array<string, bool> Cache colonnes (persiste entre requêtes FPM du même worker). */
    private static array $columnExistsCache = [];

    /** TTL du cache APCu pour la présence d'une colonne (1 heure). */
    private const COLUMN_EXISTS_APCU_TTL = 3600;
    /**
     * Exécute une opération atomique sur la même connexion PDO que les autres repositories FFP3.
     */
    public function runInTransaction(callable $callback): mixed
    {
        return $this->executeInTransaction($callback);
    }

    /**
     * Insère une mesure et ses effets de bord dans une transaction unique.
     *
     * @param callable(SensorData): void $afterInsert
     */
    public function insertAtomically(SensorData $data, callable $afterInsert): void
    {
        $this->executeInTransaction(function () use ($data, $afterInsert): void {
            $this->insert($data);
            $afterInsert($data);
        });
    }

    /**
     * Insère une nouvelle mesure complète dans la table ffp3Data.
     *
     * @param SensorData $data Objet contenant toutes les valeurs à insérer
     *
     * Chaque champ correspond à une colonne de la table. L'utilisation de requêtes préparées
     * protège contre les injections SQL et garantit la correspondance des types.
     */
    public function insert(SensorData $data): void
    {
        $table = TableValidator::validateDataTable(TableConfig::getDataTable());

        $hasPostId = ($data->postId !== null) && $this->columnExists($table, 'post_id');

        $columns = 'sensor, version, TempAir, Humidite, TempEau, EauPotager, EauAquarium, EauReserve,
            diffMaree, Luminosite, etatPompeAqua, etatPompeTank, etatHeat, etatUV,
            bouffeMatin, bouffeMidi, bouffePetits, bouffeGros,
            aqThreshold, tankThreshold, chauffageThreshold, mail, mailNotif, resetMode, bouffeSoir';
        $placeholders = ':sensor, :version, :tempAir, :humidite, :tempEau, :eauPotager, :eauAquarium, :eauReserve,
            :diffMaree, :luminosite, :etatPompeAqua, :etatPompeTank, :etatHeat, :etatUV,
            :bouffeMatin, :bouffeMidi, :bouffePetits, :bouffeGros,
            :aqThreshold, :tankThreshold, :chauffageThreshold, :mail, :mailNotif, :resetMode, :bouffeSoir';

        $params = [
            ':sensor' => $data->sensor,
            ':version' => $data->version,
            ':tempAir' => $data->tempAir,
            ':humidite' => $data->humidite,
            ':tempEau' => $data->tempEau,
            ':eauPotager' => $data->eauPotager,
            ':eauAquarium' => $data->eauAquarium,
            ':eauReserve' => $data->eauReserve,
            ':diffMaree' => $data->diffMaree,
            ':luminosite' => $data->luminosite,
            ':etatPompeAqua' => $data->etatPompeAqua,
            ':etatPompeTank' => $data->etatPompeTank,
            ':etatHeat' => $data->etatHeat,
            ':etatUV' => $data->etatUV,
            ':bouffeMatin' => $data->bouffeMatin,
            ':bouffeMidi' => $data->bouffeMidi,
            ':bouffePetits' => $data->bouffePetits,
            ':bouffeGros' => $data->bouffeGros,
            ':aqThreshold' => $data->aqThreshold,
            ':tankThreshold' => $data->tankThreshold,
            ':chauffageThreshold' => $data->chauffageThreshold,
            ':mail' => $data->mail,
            ':mailNotif' => $data->mailNotif,
            ':resetMode' => $data->resetMode,
            ':bouffeSoir' => $data->bouffeSoir,
        ];

        /** @var array<string, array{0: string, 1: mixed}> Colonne SQL => [placeholder PDO, valeur] */
        $optionalCols = [
            'tempsGros' => [':tempsGros', $data->tempsGros],
            'tempsPetits' => [':tempsPetits', $data->tempsPetits],
            'tempsRemplissageSec' => [':tempsRemplissageSec', $data->tempsRemplissageSec],
            'limFlood' => [':limFlood', $data->limFlood],
            'WakeUp' => [':wakeUp', $data->wakeUp],
            'FreqWakeUp' => [':freqWakeUp', $data->freqWakeUp],
            'configSynced' => [':configSynced', $data->configSynced],
            'Pression' => [':pression', $data->pression],
            'tideEvent' => [':tideEvent', $data->tideEvent],
            'tideTrend' => [':tideTrend', $data->tideTrend],
            'tideNoiseMm' => [':tideNoiseMm', $data->tideNoiseMm],
            'tideWindowMs' => [':tideWindowMs', $data->tideWindowMs],
            'tideExtremeMm' => [':tideExtremeMm', $data->tideExtremeMm],
        ];

        foreach ($optionalCols as $colName => [$ph, $value]) {
            if (!$this->columnExists($table, $colName)) {
                continue;
            }
            $columns .= ', ' . $colName;
            $placeholders .= ', ' . $ph;
            $params[$ph] = $value;
        }

        if ($hasPostId) {
            $columns .= ', post_id';
            $placeholders .= ', :postId';
            $params[':postId'] = $data->postId;
        }

        $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";

        $this->execute($sql, $params);
    }

    /**
     * Vérifie si un enregistrement avec ce post_id existe déjà (déduplication replay SD).
     * Retourne false si la colonne post_id n'existe pas encore en BDD.
     */
    public function existsByPostId(string $postId): bool
    {
        $table = TableValidator::validateDataTable(TableConfig::getDataTable());
        if (!$this->columnExists($table, 'post_id')) {
            return false;
        }
        $sql = "SELECT 1 FROM `{$table}` WHERE post_id = :pid LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':pid' => $postId]);
        return $stmt->fetch() !== false;
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";
        // 1) Cache statique par worker PHP-FPM (le plus rapide, durée = vie du worker).
        if (isset(self::$columnExistsCache[$key])) {
            return self::$columnExistsCache[$key];
        }

        // 2) Cache APCu partagé entre workers (si l'extension est disponible) :
        // évite d'interroger INFORMATION_SCHEMA sur le chemin chaud d'ingestion ESP32.
        $apcuKey = 'sensorRepo.columnExists.' . $key;
        $useApcu = function_exists('apcu_fetch');
        if ($useApcu) {
            $hit = apcu_fetch($apcuKey, $ok);
            if ($ok === true && is_bool($hit)) {
                self::$columnExistsCache[$key] = $hit;
                return $hit;
            }
        }

        // 3) Source de vérité : INFORMATION_SCHEMA.
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
            );
            $stmt->execute([':t' => $table, ':c' => $column]);
            $exists = ($stmt->fetch() !== false);
        } catch (\Throwable) {
            $exists = false;
        }

        self::$columnExistsCache[$key] = $exists;
        if ($useApcu) {
            apcu_store($apcuKey, $exists, self::COLUMN_EXISTS_APCU_TTL);
        }

        return $exists;
    }
}
