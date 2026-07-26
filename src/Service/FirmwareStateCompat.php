<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Couche de COMPATIBILITÉ / ROLLBACK du contrat « état plat firmware » pour la flotte
 * DÉJÀ DÉPLOYÉE (n3pp ≤ 4.65, msp ≤ 2.66), qui ne peut pas être reflashée à court terme.
 *
 * Contexte (audit contrat firmware↔serveur, juillet 2026)
 * ------------------------------------------------------
 * Jusqu'au correctif C1/C2 (v6.25.0), `GET /{module}/api/outputs/state` ne renvoyait que
 * le format *nested* (`{timestamp, outputs:[…]}`). Or les firmwares n3pp/msp lisent des
 * clés PLATES à la racine (`myObject["107"]`, `["112"]`, `["12"]`…) : la config stockée
 * en BDD n'était donc JAMAIS appliquée, chaque clé retombant sur sa valeur locale par
 * défaut. Le correctif a fusionné les clés plates à la racine — et, ce faisant, la flotte
 * a adopté d'un coup une configuration BDD qui n'avait jamais été confrontée au terrain.
 *
 * Deux effets de bord peuvent rendre un module SILENCIEUX sans qu'aucun flash ne puisse
 * le récupérer :
 *
 *  1. **Boucle de rétroaction sur l'état pompe (n3pp, GPIO 12).** À chaque POST, le
 *     serveur recopie la mesure `etatPompe` dans la ligne de COMMANDE GPIO 12
 *     ({@see \App\Controller\N3pp\N3ppPostDataController::insertData}). Une fois cette
 *     ligne à `1`, le firmware la relit en clé plate et exécute
 *     `digitalWrite(POMPE, 1)` à chaque réveil : la pompe reste alimentée en continu
 *     (batterie vidée, arrosage permanent), et l'état `1` se re-poste lui-même.
 *     Avant le correctif la clé était absente → repli sur `0` (pompe coupée).
 *  2. **Config appliquée sans garde-fou.** `SeuilPontDiv` (103) est lu SANS bornage par
 *     le firmware : une valeur haute rend la condition « batterie faible » toujours vraie
 *     et, combinée à `veilleInfinie` (112, défaut 1), envoie le module en veille
 *     GPIO-only — plus aucune donnée, réveil impossible sans intervention physique.
 *     `FreqWakeUp` (107) à 86400 espace les POST à un par jour.
 *
 * Ce service donne donc une manette de rollback graduée ({@see mode()}) :
 *  - `off`  : les clés plates ne sont PAS fusionnées → comportement strictement antérieur
 *             au correctif C1/C2 (la flotte reste sur sa config locale). DÉFAUT.
 *  - `safe` : clés plates fusionnées, mais NETTOYÉES — GPIO d'état miroir retirés, valeurs
 *             de config hors plage omises (le firmware conserve alors sa valeur locale et
 *             journalise `[SERVER][GET][WARN] Cle … absente`).
 *  - `full` : comportement v6.25.0 tel quel (aucun filtrage) — à réserver à une flotte
 *             flashée et à une BDD de contrôle vérifiée.
 *
 * Le nettoyage `safe` s'applique aussi à la route firmware explicite
 * `GET /{module}/api/firmware/outputs/state`, qui, elle, reste toujours servie (le mode
 * `off` ne la vide pas : c'est une route dédiée, pas la fusion litigieuse).
 */
final class FirmwareStateCompat
{
    /** Aucune clé plate fusionnée : contrat d'avant le correctif C1/C2 (v6.25.0). */
    public const MODE_OFF = 'off';
    /** Clés plates fusionnées après nettoyage (miroirs retirés, valeurs bornées). */
    public const MODE_SAFE = 'safe';
    /** Clés plates brutes (comportement v6.25.0). */
    public const MODE_FULL = 'full';

    public const SETTING_KEY = 'FIRMWARE_FLAT_STATE_MODE';

    /** Module N3PP (serre / élevage), board 3. */
    public const MODULE_N3PP = 'n3pp';
    /** Module MSP1 (station météo). */
    public const MODULE_MSP1 = 'msp1';

    /**
     * GPIO portant un ÉTAT MESURÉ recopié par le serveur depuis le POST du firmware :
     * les renvoyer au firmware crée une boucle (le module réapplique ce qu'il a mesuré).
     * Ils restent visibles dans le format nested (affichage UI).
     *
     * @var array<string, int[]>
     */
    private const MIRRORED_STATE_GPIOS = [
        self::MODULE_N3PP => [12],  // etatPompe recopié à chaque POST (cf. N3ppPostDataController)
        self::MODULE_MSP1 => [],
    ];

    /**
     * Plages admissibles des GPIO de configuration, communes n3pp/msp.
     * Format : gpio => [min, max]. Une valeur non numérique ou hors plage est OMISE
     * (le firmware garde alors sa valeur locale, cf. `tryReadIntByKey`).
     *
     * @var array<int, array{int, int}>
     */
    private const COMMON_RANGES = [
        102 => [0, 4095],    // SeuilSec — comparé à une moyenne ADC 12 bits
        103 => [0, 4095],    // SeuilPontDiv — lu SANS bornage par le firmware
        106 => [0, 1],       // WakeUp
        107 => [1, 86400],   // FreqWakeUp (s)
        110 => [0, 1],       // resetMode (one-shot)
        112 => [0, 1],       // veilleInfinie
    ];

    /**
     * Plages spécifiques par module (mêmes GPIO, sémantique différente).
     *
     * @var array<string, array<int, array{int, int}>>
     */
    private const MODULE_RANGES = [
        self::MODULE_N3PP => [
            104 => [0, 23],   // HeureArrosage
            105 => [1, 20],   // tempsArrosage (s) — clamp firmware historique
        ],
        self::MODULE_MSP1 => [
            104 => [0, 180],  // ServoHB (°)
            105 => [0, 180],  // ServoGD (°)
            111 => [0, 1],    // ServoModeAuto
        ],
    ];

    public function __construct(private ?OperationalSettingsService $settings = null)
    {
    }

    /**
     * Mode courant, résolu depuis les réglages opérationnels (BDD > .env), défaut `off`.
     * Toute valeur inconnue retombe sur `off` (le plus conservateur).
     */
    public function mode(): string
    {
        $raw = $this->settings?->string(self::SETTING_KEY, self::MODE_OFF)
            ?? (isset($_ENV[self::SETTING_KEY]) ? (string) $_ENV[self::SETTING_KEY] : self::MODE_OFF);
        $raw = strtolower(trim($raw));

        return in_array($raw, [self::MODE_OFF, self::MODE_SAFE, self::MODE_FULL], true)
            ? $raw
            : self::MODE_OFF;
    }

    /**
     * Vrai si les clés plates doivent être fusionnées à la racine de
     * `GET /{module}/api/outputs/state` (correctif C1/C2 actif).
     */
    public function mergesFlatKeys(): bool
    {
        return $this->mode() !== self::MODE_OFF;
    }

    /**
     * Nettoie une carte plate {gpio: state} destinée au firmware.
     *
     * En mode `full` la carte est retournée telle quelle. Sinon :
     *  - les GPIO d'état miroir du module sont retirés (anti-boucle) ;
     *  - les GPIO de configuration connus sont conservés uniquement si leur valeur est
     *    numérique ET dans la plage admissible ; sinon la clé est OMISE ;
     *  - les autres clés (actionneurs one-shot, GPIO propres à un firmware récent) passent
     *    inchangées : ce garde-fou borne le risque connu, il ne réécrit pas le contrat.
     *
     * @param array<string, string> $flat  Carte {gpio: state} issue de getStateForFirmware()
     * @param string                $module Un des MODULE_* ('' = module inconnu : bornes communes)
     * @return array<string, string>
     */
    public function sanitize(array $flat, string $module): array
    {
        if ($this->mode() === self::MODE_FULL) {
            return $flat;
        }

        $mirrored = array_flip(self::MIRRORED_STATE_GPIOS[$module] ?? []);
        $ranges = self::COMMON_RANGES + (self::MODULE_RANGES[$module] ?? []);

        $clean = [];
        foreach ($flat as $gpio => $state) {
            $gpioInt = (int) $gpio;

            if (isset($mirrored[$gpioInt])) {
                continue;
            }

            if (isset($ranges[$gpioInt]) && !$this->isWithinRange($state, $ranges[$gpioInt])) {
                continue;
            }

            $clean[$gpio] = $state;
        }

        return $clean;
    }

    /**
     * @param array{int, int} $range
     */
    private function isWithinRange(string $state, array $range): bool
    {
        $trimmed = trim($state);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return false;
        }

        $value = (int) $trimmed;

        return $value >= $range[0] && $value <= $range[1];
    }
}
