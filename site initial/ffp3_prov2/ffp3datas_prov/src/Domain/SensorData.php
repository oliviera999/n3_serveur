<?php

namespace App\Domain;

/**
 * Data Transfer Object (DTO) représentant une mesure complète issue des capteurs.
 * Chaque propriété correspond à une donnée brute ou à un paramètre de configuration
 * relevé ou utilisé par le système aquaponique.
 *
 * Ce type d'objet est utilisé pour transporter les données entre les couches du projet
 * (contrôleur, service, repository, etc.) de façon typée et centralisée.
 */
class SensorData
{
    /**
     * @param ?string $sensor           Identifiant du capteur ou de la station
     * @param ?string $version          Version du firmware ou du matériel
     * @param ?float  $tempAir          Température de l'air (°C)
     * @param ?float  $humidite         Humidité de l'air (%)
     * @param ?float  $tempEau          Température de l'eau (°C)
     * @param ?float  $eauPotager       Niveau d'eau du potager (cm ou %)
     * @param ?float  $eauAquarium      Niveau d'eau de l'aquarium (cm ou %)
     * @param ?float  $eauReserve       Niveau d'eau de la réserve (cm ou %)
     * @param ?float  $diffMaree        Différence de niveau (marée) entre aquarium et réserve
     * @param ?float  $luminosite       Luminosité mesurée (lux)
     * @param ?int    $etatPompeAqua    État de la pompe aquarium (1=ON, 0=OFF)
     * @param ?int    $etatPompeTank    État de la pompe réserve (1=ON, 0=OFF)
     * @param ?int    $etatHeat         État du chauffage (1=ON, 0=OFF)
     * @param ?int    $etatUV           État de la lampe UV (1=ON, 0=OFF)
     * @param ?int    $bouffeMatin      Distribution nourriture matin (1=oui, 0=non)
     * @param ?int    $bouffeMidi       Distribution nourriture midi (1=oui, 0=non)
     * @param ?int    $bouffePetits     Distribution nourriture petits poissons
     * @param ?int    $bouffeGros       Distribution nourriture gros poissons
     * @param ?int    $aqThreshold      Seuil de niveau aquarium pour alerte
     * @param ?int    $tankThreshold    Seuil de niveau réserve pour alerte
     * @param ?int    $chauffageThreshold Seuil de température pour chauffage
     * @param ?string $mail             Adresse e-mail de notification principale
     * @param ?string $mailNotif        Adresse e-mail secondaire pour notifications
     * @param ?int    $resetMode        Indicateur de reset du mode système
     * @param ?int    $bouffeSoir       Distribution nourriture soir (1=oui, 0=non)
     */
    public function __construct(
        public ?string $sensor,
        public ?string $version,
        public ?float  $tempAir,
        public ?float  $humidite,
        public ?float  $tempEau,
        public ?float  $eauPotager,
        public ?float  $eauAquarium,
        public ?float  $eauReserve,
        public ?float  $diffMaree,
        public ?float  $luminosite,
        public ?int    $etatPompeAqua,
        public ?int    $etatPompeTank,
        public ?int    $etatHeat,
        public ?int    $etatUV,
        public ?int    $bouffeMatin,
        public ?int    $bouffeMidi,
        public ?int    $bouffePetits,
        public ?int    $bouffeGros,
        public ?int    $aqThreshold,
        public ?int    $tankThreshold,
        public ?int    $chauffageThreshold,
        public ?string $mail,
        public ?string $mailNotif,
        public ?int    $resetMode,
        public ?int    $bouffeSoir
    ) {
    }
}