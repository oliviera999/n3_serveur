<?php

namespace App\Domain;

class SensorData
{
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