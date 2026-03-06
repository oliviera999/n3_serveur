<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\TableConfig;
use App\Domain\SensorData;
use PDO;

/**
 * Repository responsable de l'insertion des mesures capteurs dans la base de données.
 * Permet d'abstraire la logique SQL et d'assurer la cohérence des écritures.
 */
class SensorRepository extends AbstractRepository
{
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
        $table = TableConfig::getDataTable();

        $hasPostId = ($data->postId !== null) && $this->columnExists($table, 'post_id');

        $columns = 'sensor, version, TempAir, Humidite, TempEau, EauPotager, EauAquarium, EauReserve,
            diffMaree, Luminosite, etatPompeAqua, etatPompeTank, etatHeat, etatUV,
            bouffeMatin, bouffeMidi, bouffePetits, bouffeGros,
            aqThreshold, tankThreshold, chauffageThreshold, mail, mailNotif, resetMode, bouffeSoir';
        $placeholders = ':sensor, :version, :tempAir, :humidite, :tempEau, :eauPotager, :eauAquarium, :eauReserve,
            :diffMaree, :luminosite, :etatPompeAqua, :etatPompeTank, :etatHeat, :etatUV,
            :bouffeMatin, :bouffeMidi, :bouffePetits, :bouffeGros,
            :aqThreshold, :tankThreshold, :chauffageThreshold, :mail, :mailNotif, :resetMode, :bouffeSoir';

        if ($hasPostId) {
            $columns .= ', post_id';
            $placeholders .= ', :postId';
        }

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";

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

        if ($hasPostId) {
            $params[':postId'] = $data->postId;
        }

        $this->execute($sql, $params);
    }

    /**
     * Vérifie si un enregistrement avec ce post_id existe déjà (déduplication replay SD).
     * Retourne false si la colonne post_id n'existe pas encore en BDD.
     */
    public function existsByPostId(string $postId): bool
    {
        $table = TableConfig::getDataTable();
        if (!$this->columnExists($table, 'post_id')) {
            return false;
        }
        $sql = "SELECT 1 FROM {$table} WHERE post_id = :pid LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':pid' => $postId]);
        return $stmt->fetch() !== false;
    }

    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = "{$table}.{$column}";
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1"
            );
            $stmt->execute([':t' => $table, ':c' => $column]);
            $cache[$key] = ($stmt->fetch() !== false);
        } catch (\Throwable) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}
