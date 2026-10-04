<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * DTO des mesures du banc énergie (firmware `energie`, ESP32-S3 + 3 × INA226).
 *
 * Trois canaux INA226 :
 *   - Panneau  : production du panneau solaire ;
 *   - Batterie : batterie plomb/AGM 12 V, bidirectionnelle
 *                (courant > 0 = charge, < 0 = décharge) ;
 *   - Conso    : consommation des périphériques.
 *
 * Le firmware mesure toutes les secondes et poste un agrégat toutes les 10 s.
 * Unités : V, A, W ; `energiePanneauWh` / `energieConsoWh` = énergie (Wh) sur la
 * fenêtre de 10 s (delta) ; `batterieAh` = compteur net cumulé (Ah) ;
 * `batterieSoc` = état de charge (%, 0-100) ; `uptime` en secondes.
 *
 * `inaStatus` (bitmask) : bit i (i = 0 panneau, 1 batterie, 2 conso) = INA présent/configuré ;
 * bit 4+i = saturation du shunt vue dans la fenêtre ; bit 8+i = ré-initialisation dans la fenêtre.
 *
 * Tous les champs numériques sont optionnels : absent ou non numérique → null (NULL en BDD).
 * Voir docs/API_ENERGIE.md.
 */
class EnergieSensorData
{
    public function __construct(
        public readonly string $sensor,
        public readonly string $version,
        public readonly ?float $panneauV = null,
        public readonly ?float $panneauI = null,
        public readonly ?float $panneauP = null,
        public readonly ?float $panneauImax = null,
        public readonly ?float $batterieV = null,
        public readonly ?float $batterieVmin = null,
        public readonly ?float $batterieI = null,
        public readonly ?float $batterieImin = null,
        public readonly ?float $batterieImax = null,
        public readonly ?float $batterieP = null,
        public readonly ?float $batterieVadc = null,
        public readonly ?float $consoV = null,
        public readonly ?float $consoI = null,
        public readonly ?float $consoP = null,
        public readonly ?float $consoImax = null,
        public readonly ?float $energiePanneauWh = null,
        public readonly ?float $energieConsoWh = null,
        public readonly ?float $batterieAh = null,
        public readonly ?float $batterieSoc = null,
        public readonly ?int $inaStatus = null,
        public readonly ?int $i2cErreurs = null,
        public readonly ?int $rssi = null,
        public readonly ?int $freeHeap = null,
        public readonly ?int $bootCount = null,
        public readonly ?int $uptime = null,
    ) {
    }
}
