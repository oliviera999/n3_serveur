<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * DTO pour les donnees capteurs de la serre/aquaponie (firmware n3pp).
 * Les noms de proprietes correspondent aux champs POST du firmware.
 */
class N3ppSensorData
{
    public function __construct(
        public readonly string $sensor,
        public readonly string $version,
        public readonly ?float $tempAir = null,
        public readonly ?float $humidite = null,
        public readonly ?float $luminosite = null,
        public readonly ?float $humid1 = null,
        public readonly ?float $humid2 = null,
        public readonly ?float $humid3 = null,
        public readonly ?float $humid4 = null,
        public readonly ?float $humidMoy = null,
        public readonly ?int $pontDiv = null,
        public readonly ?int $wakeUp = null,
        public readonly ?int $arrosageManu = null,
        public readonly ?int $seuilSec = null,
        public readonly ?int $freqWakeUp = null,
        public readonly ?int $seuilPontDiv = null,
        public readonly ?string $mail = null,
        public readonly ?string $mailNotif = null,
        public readonly ?int $heureArrosage = null,
        public readonly ?int $resetMode = null,
        public readonly ?int $etatPompe = null,
        public readonly ?int $tempsArrosage = null,
        public readonly ?int $bootCount = null,
    ) {
    }
}
