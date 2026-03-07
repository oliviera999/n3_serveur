<?php

namespace App\Repository;

use App\Domain\SensorData;
use PDO;

/**
 * Repository responsable de l'insertion des mesures capteurs dans la base de données.
 * Permet d'abstraire la logique SQL et d'assurer la cohérence des écritures.
 */
class SensorRepository
{
    /**
     * @param PDO $pdo Connexion PDO à la base de données (injectée)
     */
    public function __construct(private PDO $pdo) {}

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
        $sql = "INSERT INTO ffp3Data (
            sensor, version, TempAir, Humidite, TempEau, EauPotager, EauAquarium, EauReserve,
            diffMaree, Luminosite, etatPompeAqua, etatPompeTank, etatHeat, etatUV,
            bouffeMatin, bouffeMidi, bouffePetits, bouffeGros,
            aqThreshold, tankThreshold, chauffageThreshold, mail, mailNotif, resetMode, bouffeSoir
        ) VALUES (
            :sensor, :version, :tempAir, :humidite, :tempEau, :eauPotager, :eauAquarium, :eauReserve,
            :diffMaree, :luminosite, :etatPompeAqua, :etatPompeTank, :etatHeat, :etatUV,
            :bouffeMatin, :bouffeMidi, :bouffePetits, :bouffeGros,
            :aqThreshold, :tankThreshold, :chauffageThreshold, :mail, :mailNotif, :resetMode, :bouffeSoir
        )";

        // Préparation de la requête pour éviter toute injection SQL
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
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
        ]);
    }
}