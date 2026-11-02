<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Config\Ffp3GpioMap;
use App\Config\MspGpioMap;
use App\Config\N3ppGpioMap;
use App\Config\TableConfig;

/**
 * Dictionnaire des champs exposés par le connecteur MCP, par famille d'appareils.
 *
 * Rôle triple :
 *  1. LISTE BLANCHE des colonnes lisibles (aucun nom de colonne ne vient de l'appelant
 *     sans passer par ce catalogue) ; les colonnes porteuses de données personnelles
 *     (`mail`, `mailNotif`) n'y figurent PAS et ne sortent donc jamais ;
 *  2. CONVERSION d'unités : les niveaux d'eau FFP3 sont stockés en mm et exposés en cm
 *     (comme l'interface web, cf. {@see \App\Util\Ffp3WaterLevelUnit}) ;
 *  3. SÉMANTIQUE pour le modèle (`iot_describe`) : unité, signification, plage usuelle,
 *     GPIO associé (dérivé des *GpioMap, source unique de vérité).
 *
 * Sources : docs/ENDPOINTS_ESP32_SERVEUR.md (unités niveaux d'eau, diffMaree),
 * docs/API_MSP1_N3PP.md, docs/API_ENERGIE.md, docs/COMPAT_FLOTTE_DEPLOYEE.md (plages GPIO),
 * src/Display/Catalog/* (unités affichées), src/Service/DerivedAlert/* (sens des seuils).
 */
final class FieldCatalog
{
    public const FAMILIES = ['ffp3', 'n3pp', 'msp1', 'energie'];

    /** Familles disposant d'une table outputs (GPIO). */
    public const OUTPUT_FAMILIES = ['ffp3', 'n3pp', 'msp1'];

    /** Board des familles capteurs (tables outputs partagées par board). */
    public const BOARDS = ['n3pp' => 3, 'msp1' => 2];

    private const MM_TO_CM = 0.1;

    /**
     * @var array<string, array{label: string, fields: array<string, array{unit: string, meaning: string, range?: string, kind: string, scale?: float, numeric?: bool}>}>
     */
    private const CATALOG = [
        'ffp3' => [
            'label' => 'Aquaponie FFP3 (aquarium + réserve + bac potager, firmware ffp5cs)',
            'fields' => [
                'TempAir' => ['unit' => '°C', 'kind' => 'mesure', 'range' => '10–35', 'meaning' => "Température de l'air près du bac. Un couple exact 20 °C / 50 % signale le repli du capteur DHT (valeur par défaut, pas une mesure)."],
                'Humidite' => ['unit' => '%', 'kind' => 'mesure', 'range' => '30–90', 'meaning' => "Humidité relative de l'air."],
                'TempEau' => ['unit' => '°C', 'kind' => 'mesure', 'range' => '18–28', 'meaning' => "Température de l'eau de l'aquarium (poissons). Le chauffage (GPIO 2) se déclenche sous le seuil chauffageThreshold (GPIO 104)."],
                'EauAquarium' => ['unit' => 'cm', 'kind' => 'mesure', 'scale' => self::MM_TO_CM, 'range' => 'dépend du bac (comparer aux seuils)', 'meaning' => "DISTANCE capteur à ultrasons → surface de l'eau de l'aquarium (PAS une hauteur d'eau) : plus la valeur est GRANDE, plus l'eau est BASSE. Au-dessus de aqThreshold (GPIO 102) le niveau est bas et l'ESP32 remplit depuis la réserve ; sous limFlood (GPIO 114) c'est un trop-plein. Oscille avec les marées (pompe aquarium). Stocké en mm, exposé en cm."],
                'EauReserve' => ['unit' => 'cm', 'kind' => 'mesure', 'scale' => self::MM_TO_CM, 'range' => 'dépend de la cuve', 'meaning' => "DISTANCE capteur → surface de la réserve d'eau : valeur GRANDE = réserve VIDE. Au-dessus de tankThreshold (GPIO 103) la réserve est basse et l'ESP32 bloque la pompe réserve. Stocké en mm, exposé en cm."],
                'EauPotager' => ['unit' => 'cm', 'kind' => 'mesure', 'scale' => self::MM_TO_CM, 'range' => 'varie avec les marées', 'meaning' => 'DISTANCE capteur → surface du bac potager (plantes) : grande valeur = bac vidé (marée basse). Stocké en mm, exposé en cm.'],
                'diffMaree' => ['unit' => 'mm', 'kind' => 'mesure', 'range' => '≈ −50…+50', 'meaning' => "Variation de la distance aquarium sur la fenêtre glissante du firmware : (distance passée − distance actuelle). Positive = la surface remonte vers le capteur (aquarium qui se remplit) ; négative = l'eau descend (marée vers le potager). Brut firmware, en mm."],
                'tideTrend' => ['unit' => '', 'kind' => 'mesure', 'meaning' => 'Tendance de marée calculée par le firmware (entier signé : sens de variation du niveau aquarium).'],
                'tideNoiseMm' => ['unit' => 'mm', 'kind' => 'mesure', 'meaning' => 'Bruit de mesure estimé par le firmware sur la fenêtre de détection des marées.'],
                'tideWindowMs' => ['unit' => 'ms', 'kind' => 'mesure', 'meaning' => 'Durée de la fenêtre de détection de marée utilisée par le firmware.'],
                'tideExtremeMm' => ['unit' => 'mm', 'kind' => 'mesure', 'meaning' => "Distance à l'extrême (pleine/basse mer) détecté par le firmware."],
                'tideEvent' => ['unit' => '', 'kind' => 'événement', 'numeric' => false, 'meaning' => 'Événement de marée signalé par le firmware (texte court : pleine mer / basse mer…), vide sinon.'],
                'Luminosite' => ['unit' => 'UA', 'kind' => 'mesure', 'meaning' => 'Luminosité (photorésistance), unité arbitraire : sert à comparer, pas une valeur absolue.'],
                'Pression' => ['unit' => 'hPa', 'kind' => 'mesure', 'range' => '980–1040', 'meaning' => 'Pression atmosphérique (BME280), vide si capteur absent.'],
                'etatPompeAqua' => ['unit' => '0/1', 'kind' => 'état actionneur', 'meaning' => "Pompe aquarium (GPIO 16) : 1 = en marche. Elle crée les marées vers le potager ; coupée trop longtemps, l'eau ne circule plus (oxygénation, filtration)."],
                'etatPompeTank' => ['unit' => '0/1', 'kind' => 'état actionneur', 'meaning' => 'Pompe réserve (GPIO 18) : 1 = remplissage de l\'aquarium depuis la réserve en cours.'],
                'etatHeat' => ['unit' => '0/1', 'kind' => 'état actionneur', 'meaning' => "Chauffage de l'eau (GPIO 2) : 1 = allumé."],
                'etatUV' => ['unit' => '0/1', 'kind' => 'état actionneur', 'meaning' => 'Lumière (GPIO 15) : 1 = allumée.'],
                'bouffeMatin' => ['unit' => 'h', 'kind' => 'réglage', 'range' => '0–23', 'meaning' => 'Heure du nourrissage automatique du matin (GPIO 105).'],
                'bouffeMidi' => ['unit' => 'h', 'kind' => 'réglage', 'range' => '0–23', 'meaning' => 'Heure du nourrissage automatique de midi (GPIO 106).'],
                'bouffeSoir' => ['unit' => 'h', 'kind' => 'réglage', 'range' => '0–23', 'meaning' => 'Heure du nourrissage automatique du soir (GPIO 107).'],
                'bouffePetits' => ['unit' => 'compteur', 'kind' => 'commande', 'meaning' => 'Compteur monotone de nourrissages manuels « petits poissons » (GPIO 108) : chaque +1 = un repas ; le firmware rattrape l\'écart.'],
                'bouffeGros' => ['unit' => 'compteur', 'kind' => 'commande', 'meaning' => 'Compteur monotone de nourrissages manuels « gros poissons » (GPIO 109).'],
                'aqThreshold' => ['unit' => 'cm', 'kind' => 'réglage', 'meaning' => 'Seuil aquarium bas (GPIO 102) : EauAquarium au-dessus = niveau bas → remplissage.'],
                'tankThreshold' => ['unit' => 'cm', 'kind' => 'réglage', 'meaning' => 'Seuil réserve basse (GPIO 103) : EauReserve au-dessus = réserve basse.'],
                'chauffageThreshold' => ['unit' => '°C', 'kind' => 'réglage', 'range' => '18–28', 'meaning' => 'Consigne chauffage (GPIO 104) : chauffage si TempEau en dessous.'],
                'tempsGros' => ['unit' => 's', 'kind' => 'réglage', 'meaning' => 'Durée de distribution gros poissons (GPIO 111).'],
                'tempsPetits' => ['unit' => 's', 'kind' => 'réglage', 'meaning' => 'Durée de distribution petits poissons (GPIO 112).'],
                'tempsRemplissageSec' => ['unit' => 's', 'kind' => 'réglage', 'meaning' => 'Durée maximale d\'un remplissage depuis la réserve (GPIO 113).'],
                'limFlood' => ['unit' => 'cm', 'kind' => 'réglage', 'meaning' => 'Seuil de trop-plein (GPIO 114) : EauAquarium SOUS ce seuil = eau trop haute.'],
                'WakeUp' => ['unit' => '0/1', 'kind' => 'commande', 'meaning' => 'Réveil forcé (GPIO 115) : 1 = rester éveillé.'],
                'FreqWakeUp' => ['unit' => 's', 'kind' => 'réglage', 'meaning' => 'Durée de veille entre deux réveils (GPIO 116), multipliée la nuit par le firmware.'],
                'resetMode' => ['unit' => '0/1', 'kind' => 'commande', 'meaning' => 'Commande de redémarrage ESP32 (GPIO 110, one-shot).'],
                'configSynced' => ['unit' => '0/1', 'kind' => 'état', 'meaning' => 'Le firmware a appliqué la configuration serveur.'],
            ],
        ],
        'n3pp' => [
            'label' => 'Serre / élevage N3PP (arrosage automatique, sur batterie)',
            'fields' => [
                'TempAir' => ['unit' => '°C', 'kind' => 'mesure', 'range' => '5–40', 'meaning' => "Température de l'air dans la serre."],
                'Humidite' => ['unit' => '%', 'kind' => 'mesure', 'range' => '30–95', 'meaning' => "Humidité relative de l'air."],
                'Pression' => ['unit' => 'hPa', 'kind' => 'mesure', 'range' => '980–1040', 'meaning' => 'Pression atmosphérique (BME280), vide si absent.'],
                'Luminosite' => ['unit' => 'UA', 'kind' => 'mesure', 'meaning' => 'Luminosité, unité arbitraire.'],
                'Humid1' => ['unit' => 'UA', 'kind' => 'mesure', 'range' => '0–4095', 'meaning' => 'Humidité du sol, sonde 1 (lecture ADC). Plus la valeur est BASSE, plus le sol est SEC (comparer à SeuilSec).'],
                'Humid2' => ['unit' => 'UA', 'kind' => 'mesure', 'range' => '0–4095', 'meaning' => 'Humidité du sol, sonde 2 (même convention que Humid1).'],
                'Humid3' => ['unit' => 'UA', 'kind' => 'mesure', 'range' => '0–4095', 'meaning' => 'Humidité du sol, sonde 3.'],
                'Humid4' => ['unit' => 'UA', 'kind' => 'mesure', 'range' => '0–4095', 'meaning' => 'Humidité du sol, sonde 4.'],
                'HumidMoy' => ['unit' => 'UA', 'kind' => 'mesure', 'range' => '0–4095', 'meaning' => 'Moyenne des sondes de sol. HumidMoy < SeuilSec = sol sec (alerte serveur, arrosage firmware).'],
                'PontDiv' => ['unit' => 'ADC', 'kind' => 'mesure', 'range' => '0–4095', 'meaning' => 'Tension batterie lue par un pont diviseur (valeur ADC 12 bits, pas des volts). PontDiv < SeuilPontDiv = batterie faible (veille longue). Souvent vide.'],
                'etatPompe' => ['unit' => '0/1', 'kind' => 'état actionneur', 'meaning' => "Pompe d'irrigation (GPIO 12) : 1 = arrosage en cours (état MESURÉ, pas une commande)."],
                'tempsArrosage' => ['unit' => 's', 'kind' => 'réglage', 'range' => '1–20', 'meaning' => "Durée d'un arrosage (GPIO 105)."],
                'HeureArrosage' => ['unit' => 'h', 'kind' => 'réglage', 'range' => '0–23', 'meaning' => "Heure de l'arrosage programmé (GPIO 104)."],
                'SeuilSec' => ['unit' => 'UA', 'kind' => 'réglage', 'range' => '0–4095', 'meaning' => 'Seuil « sol sec » (GPIO 102).'],
                'SeuilPontDiv' => ['unit' => 'ADC', 'kind' => 'réglage', 'range' => '0–4095', 'meaning' => 'Seuil batterie faible (GPIO 103), à comparer à PontDiv.'],
                'ArrosageManu' => ['unit' => '0/1', 'kind' => 'commande', 'meaning' => 'Arrosage manuel demandé (GPIO 13, one-shot).'],
                'WakeUp' => ['unit' => '0/1', 'kind' => 'commande', 'meaning' => 'Réveil forcé (GPIO 106).'],
                'FreqWakeUp' => ['unit' => 's', 'kind' => 'réglage', 'meaning' => 'Durée de veille entre deux réveils (GPIO 107).'],
                'bootCount' => ['unit' => '', 'kind' => 'diagnostic', 'meaning' => 'Nombre de démarrages de l\'ESP32 (une hausse brutale = redémarrages anormaux).'],
                'resetMode' => ['unit' => '0/1', 'kind' => 'commande', 'meaning' => 'Commande de redémarrage (GPIO 110, one-shot).'],
            ],
        ],
        'msp1' => [
            'label' => 'Station météo / potager MSP1 (tracker solaire, sur batterie)',
            'fields' => [
                'TempAirInt' => ['unit' => '°C', 'kind' => 'mesure', 'range' => '0–45', 'meaning' => 'Température dans le boîtier.'],
                'TempAirExt' => ['unit' => '°C', 'kind' => 'mesure', 'range' => '−5–40', 'meaning' => 'Température extérieure (alertes gel / canicule serveur si seuils configurés).'],
                'HumidAirInt' => ['unit' => '%', 'kind' => 'mesure', 'meaning' => 'Humidité relative dans le boîtier.'],
                'HumidAirExt' => ['unit' => '%', 'kind' => 'mesure', 'meaning' => 'Humidité relative extérieure.'],
                'Pression' => ['unit' => 'hPa', 'kind' => 'mesure', 'range' => '980–1040', 'meaning' => 'Pression atmosphérique (BME280).'],
                'LuminositeA' => ['unit' => 'UA', 'kind' => 'mesure', 'meaning' => 'Photorésistance A du tracker solaire (unité arbitraire).'],
                'LuminositeB' => ['unit' => 'UA', 'kind' => 'mesure', 'meaning' => 'Photorésistance B du tracker solaire.'],
                'LuminositeC' => ['unit' => 'UA', 'kind' => 'mesure', 'meaning' => 'Photorésistance C du tracker solaire.'],
                'LuminositeD' => ['unit' => 'UA', 'kind' => 'mesure', 'meaning' => 'Photorésistance D du tracker solaire.'],
                'LuminositeMoy' => ['unit' => 'UA', 'kind' => 'mesure', 'meaning' => 'Moyenne des 4 photorésistances (parfois 0 constant : capteur défaillant).'],
                'ServoHB' => ['unit' => '°', 'kind' => 'état actionneur', 'range' => '40–145', 'meaning' => 'Angle du servo haut-bas du tracker (consigne GPIO 104).'],
                'ServoGD' => ['unit' => '°', 'kind' => 'état actionneur', 'range' => '1–179', 'meaning' => 'Angle du servo gauche-droite du tracker (consigne GPIO 105).'],
                'HumidSol' => ['unit' => 'UA', 'kind' => 'mesure', 'meaning' => 'Humidité du sol du potager (plus bas = plus sec).'],
                'Pluie' => ['unit' => 'UA', 'kind' => 'mesure', 'meaning' => 'Capteur de pluie : valeur BASSE = capteur mouillé (pluie).'],
                'TempEau' => ['unit' => '°C', 'kind' => 'mesure', 'meaning' => "Température de l'eau (sonde du potager)."],
                'PontDiv' => ['unit' => 'ADC', 'kind' => 'mesure', 'range' => '0–4095', 'meaning' => 'Tension batterie via pont diviseur (ADC 12 bits). PontDiv < SeuilPontDiv = batterie faible.'],
                'SeuilSec' => ['unit' => 'UA', 'kind' => 'réglage', 'meaning' => 'Seuil sol sec (GPIO 102).'],
                'SeuilPontDiv' => ['unit' => 'ADC', 'kind' => 'réglage', 'meaning' => 'Seuil batterie faible (GPIO 103).'],
                'WakeUp' => ['unit' => '0/1', 'kind' => 'commande', 'meaning' => 'Réveil forcé (GPIO 106).'],
                'FreqWakeUp' => ['unit' => 's', 'kind' => 'réglage', 'meaning' => 'Durée de veille (GPIO 107).'],
                'bootCount' => ['unit' => '', 'kind' => 'diagnostic', 'meaning' => 'Nombre de démarrages.'],
                'resetMode' => ['unit' => '0/1', 'kind' => 'commande', 'meaning' => 'Commande de redémarrage (GPIO 110, one-shot).'],
            ],
        ],
        'energie' => [
            'label' => 'Banc énergie INA226 (panneau solaire / batterie 12 V / consommation)',
            'fields' => [
                'PanneauV' => ['unit' => 'V', 'kind' => 'mesure', 'range' => '0–22', 'meaning' => 'Tension du panneau solaire.'],
                'PanneauI' => ['unit' => 'A', 'kind' => 'mesure', 'meaning' => 'Courant produit par le panneau.'],
                'PanneauP' => ['unit' => 'W', 'kind' => 'mesure', 'meaning' => 'Puissance produite par le panneau.'],
                'PanneauImax' => ['unit' => 'A', 'kind' => 'mesure', 'meaning' => 'Courant panneau maximal sur la fenêtre de 10 s.'],
                'BatterieV' => ['unit' => 'V', 'kind' => 'mesure', 'range' => '11.8–14.4', 'meaning' => 'Tension batterie plomb/AGM 12 V (≈ 12,7 V pleine au repos, < 11,8 V très déchargée).'],
                'BatterieVmin' => ['unit' => 'V', 'kind' => 'mesure', 'meaning' => 'Tension batterie minimale sur la fenêtre.'],
                'BatterieI' => ['unit' => 'A', 'kind' => 'mesure', 'meaning' => 'Courant batterie : > 0 = CHARGE, < 0 = DÉCHARGE.'],
                'BatterieImin' => ['unit' => 'A', 'kind' => 'mesure', 'meaning' => 'Courant batterie minimal (décharge la plus forte).'],
                'BatterieImax' => ['unit' => 'A', 'kind' => 'mesure', 'meaning' => 'Courant batterie maximal (charge la plus forte).'],
                'BatterieP' => ['unit' => 'W', 'kind' => 'mesure', 'meaning' => 'Puissance batterie (même signe que BatterieI).'],
                'BatterieVadc' => ['unit' => 'V', 'kind' => 'mesure', 'meaning' => 'Tension batterie lue par l\'ADC (contrôle croisé de BatterieV).'],
                'ConsoV' => ['unit' => 'V', 'kind' => 'mesure', 'meaning' => 'Tension côté consommation.'],
                'ConsoI' => ['unit' => 'A', 'kind' => 'mesure', 'meaning' => 'Courant consommé.'],
                'ConsoP' => ['unit' => 'W', 'kind' => 'mesure', 'meaning' => 'Puissance consommée.'],
                'ConsoImax' => ['unit' => 'A', 'kind' => 'mesure', 'meaning' => 'Courant consommé maximal sur la fenêtre.'],
                'EnergiePanneauWh' => ['unit' => 'Wh', 'kind' => 'mesure', 'meaning' => 'Énergie produite PENDANT la fenêtre de 10 s (delta, à sommer).'],
                'EnergieConsoWh' => ['unit' => 'Wh', 'kind' => 'mesure', 'meaning' => 'Énergie consommée PENDANT la fenêtre de 10 s (delta, à sommer).'],
                'BatterieAh' => ['unit' => 'Ah', 'kind' => 'mesure', 'meaning' => 'Compteur net cumulé de la batterie (coulomb-mètre).'],
                'BatterieSoc' => ['unit' => '%', 'kind' => 'mesure', 'range' => '0–100', 'meaning' => 'État de charge estimé.'],
                'InaStatus' => ['unit' => 'bitmask', 'kind' => 'diagnostic', 'meaning' => 'État des 3 capteurs INA226 (voir docs/API_ENERGIE.md § 2.3).'],
                'I2cErreurs' => ['unit' => '', 'kind' => 'diagnostic', 'meaning' => 'Compteur d\'erreurs I²C.'],
                'Rssi' => ['unit' => 'dBm', 'kind' => 'diagnostic', 'range' => '−90…−30', 'meaning' => 'Signal WiFi (plus proche de 0 = meilleur).'],
                'FreeHeap' => ['unit' => 'octets', 'kind' => 'diagnostic', 'meaning' => 'Mémoire libre de l\'ESP32.'],
                'BootCount' => ['unit' => '', 'kind' => 'diagnostic', 'meaning' => 'Nombre de démarrages.'],
                'Uptime' => ['unit' => 's', 'kind' => 'diagnostic', 'meaning' => 'Temps depuis le démarrage.'],
            ],
        ],
    ];

    /** Champs résumant une famille dans `iot_overview`. */
    private const SUMMARY_FIELDS = [
        'ffp3' => ['TempEau', 'TempAir', 'EauAquarium', 'EauReserve', 'EauPotager', 'etatPompeAqua', 'etatHeat'],
        'n3pp' => ['TempAir', 'Humidite', 'HumidMoy', 'PontDiv', 'etatPompe'],
        'msp1' => ['TempAirExt', 'HumidAirExt', 'Pression', 'LuminositeMoy', 'Pluie', 'PontDiv'],
        'energie' => ['PanneauP', 'BatterieV', 'BatterieI', 'BatterieSoc', 'ConsoP'],
    ];

    public static function isFamily(string $family): bool
    {
        return isset(self::CATALOG[$family]);
    }

    public static function label(string $family): string
    {
        return self::CATALOG[$family]['label'] ?? $family;
    }

    /**
     * @return array<string, array{unit: string, meaning: string, range?: string, kind: string, scale?: float, numeric?: bool}>
     */
    public static function fields(string $family): array
    {
        return self::CATALOG[$family]['fields'] ?? [];
    }

    /**
     * @return list<string> Champs numériques (agrégeables)
     */
    public static function numericFields(string $family): array
    {
        $out = [];
        foreach (self::fields($family) as $name => $def) {
            if (($def['numeric'] ?? true) === true) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Union des champs numériques de toutes les familles (pour l'énumération du schéma).
     *
     * @return list<string>
     */
    public static function allNumericFields(): array
    {
        $all = [];
        foreach (self::FAMILIES as $family) {
            $all = array_merge($all, self::numericFields($family));
        }

        return array_values(array_unique($all));
    }

    /**
     * @return list<string>
     */
    public static function summaryFields(string $family): array
    {
        return self::SUMMARY_FIELDS[$family] ?? [];
    }

    public static function scale(string $family, string $field): float
    {
        return (float) (self::fields($family)[$field]['scale'] ?? 1.0);
    }

    public static function unit(string $family, string $field): string
    {
        return self::fields($family)[$field]['unit'] ?? '';
    }

    /**
     * Applique la conversion d'unité et l'arrondi (lisibilité, taille de réponse).
     */
    public static function present(string $family, string $field, mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $def = self::fields($family)[$field] ?? null;
        if ($def === null) {
            return null;
        }
        if (($def['numeric'] ?? true) === false) {
            return is_scalar($raw) ? (string) $raw : null;
        }
        if (!is_numeric($raw)) {
            return null;
        }

        return round((float) $raw * self::scale($family, $field), 3);
    }

    /**
     * Table de données de la famille pour l'environnement COURANT (TableConfig).
     */
    public static function dataTable(string $family): string
    {
        return match ($family) {
            'ffp3' => TableConfig::getDataTable(),
            'n3pp' => TableConfig::getN3ppDataTable(),
            'msp1' => TableConfig::getMspDataTable(),
            'energie' => TableConfig::getEnergieDataTable(),
            default => throw new \InvalidArgumentException('Famille inconnue : ' . $family),
        };
    }

    public static function outputsTable(string $family): string
    {
        return match ($family) {
            'ffp3' => TableConfig::getOutputsTable(),
            'n3pp' => TableConfig::getN3ppOutputsTable(),
            'msp1' => TableConfig::getMspOutputsTable(),
            default => throw new \InvalidArgumentException('Pas de table outputs pour : ' . $family),
        };
    }

    public static function heartbeatTable(string $family): ?string
    {
        return match ($family) {
            'ffp3' => TableConfig::getHeartbeatTable(),
            'n3pp' => TableConfig::getN3ppHeartbeatTable(),
            'msp1' => TableConfig::getMspHeartbeatTable(),
            default => null,
        };
    }

    /**
     * Vrai si, pour cette famille, l'environnement cible des tables de PRODUCTION.
     *
     * Plus précis que {@see TableConfig::isTest()} : en `test` ou `s3test`, les tables
     * N3PP / MSP1 / ENERGIE restent celles de production (seuls `n3pp_test`, `msp_test`,
     * `energie_test` les suffixent). `s3` est de la production.
     */
    public static function isProductionTarget(string $family, string $env): bool
    {
        return match ($family) {
            'ffp3' => in_array($env, ['prod', 's3', 'n3pp_test', 'msp_test', 'energie_test'], true),
            'n3pp' => $env !== 'n3pp_test',
            'msp1' => $env !== 'msp_test',
            'energie' => $env !== 'energie_test',
            default => true,
        };
    }

    /**
     * Libellés GPIO de la famille (actionneurs + paramètres), dérivés des *GpioMap.
     *
     * @return array<int, string>
     */
    public static function gpioLabels(string $family): array
    {
        return match ($family) {
            'ffp3' => [
                2 => 'Chauffage eau (etatHeat)',
                15 => 'Lumière (etatUV)',
                16 => 'Pompe aquarium (etatPompeAqua)',
                18 => 'Pompe réserve (etatPompeTank)',
                23 => 'Relais auxiliaire 230 V n°1',
                25 => 'Relais auxiliaire 230 V n°2',
                117 => 'Forçage pompe aquarium (0 = auto, 1 = forcer ON, 2 = forcer OFF)',
                124 => 'Mode de notification serveur',
                125 => 'Catégories de notification',
                126 => 'Multiplicateur de veille nocturne',
                127 => 'Heure de début de nuit',
                128 => 'Heure de fin de nuit',
                129 => 'Seuil d\'écart-type marées (sécurité pompe)',
            ] + Ffp3GpioMap::gpioToProperty(),
            'n3pp' => [
                12 => 'Pompe irrigation (état)',
                13 => 'Arrosage manuel (one-shot)',
                15 => 'Lampe (legacy, non lue par le firmware)',
                16 => 'Ventilation (legacy, non lue par le firmware)',
            ] + N3ppGpioMap::paramGpioMap(),
            'msp1' => MspGpioMap::paramGpioMap(),
            default => [],
        };
    }
}
