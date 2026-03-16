<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * DTO pour les donnees capteurs de la station meteo (firmware msp).
 * Les noms de proprietes correspondent aux champs POST du firmware.
 */
class MspSensorData
{
    public function __construct(
        public readonly string $sensor,
        public readonly string $version,
        public readonly ?float $tempAirInt = null,
        public readonly ?float $tempAirExt = null,
        public readonly ?float $humidAirInt = null,
        public readonly ?float $humidAirExt = null,
        public readonly ?float $luminositeA = null,
        public readonly ?float $luminositeB = null,
        public readonly ?float $luminositeC = null,
        public readonly ?float $luminositeD = null,
        public readonly ?float $luminositeMoy = null,
        public readonly ?int $servoHB = null,
        public readonly ?int $servoGD = null,
        public readonly ?float $humidSol = null,
        public readonly ?float $pluie = null,
        public readonly ?float $tempEau = null,
        public readonly ?int $pontDiv = null,
        public readonly ?int $wakeUp = null,
        public readonly ?int $seuilSec = null,
        public readonly ?int $freqWakeUp = null,
        public readonly ?int $seuilPontDiv = null,
        public readonly ?string $mail = null,
        public readonly ?string $mailNotif = null,
        public readonly ?int $resetMode = null,
        public readonly ?int $bootCount = null,
    ) {
    }
}
