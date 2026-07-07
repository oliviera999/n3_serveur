<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\AbstractSensorRepository;
use App\Service\LogService;
use App\Service\NotificationService;

/**
 * Socle des alertes « signes vitaux » dérivées du POST pour les familles deep-sleep
 * (N3PP, MSP1) — Phase 2 arbitrage mails :
 *
 *  - BATTERIE FAIBLE : `PontDiv < SeuilPontDiv` (les deux champs sont au POST),
 *    latch + hystérésis de ré-armement (+5 %, parité firmware) via
 *    {@see LowValueAlertEvaluator}. Pas de mail de « retour à la normale »
 *    (parité firmware : le flag est simplement ré-armé) ;
 *  - REDÉMARRAGE : `bootCount` est un compteur RTC incrémenté à CHAQUE réveil et
 *    remis à zéro sur un vrai redémarrage (mémoire RTC effacée). Un redémarrage se
 *    détecte donc par un DÉCRÉMENT du compteur (valeur < précédente), pas par un
 *    incrément (qui est le rythme normal des réveils).
 *
 * Chaque run ne traite que les NOUVELLES lignes (curseur `lastRowId` persisté) :
 * les latches ne bougent qu'à l'arrivée de données fraîches.
 */
abstract class AbstractVitalsDerivedAlertService
{
    public function __construct(
        protected AbstractSensorRepository $sensorRepo,
        protected NotificationService $notifier,
        protected LogService $logger,
        protected DerivedAlertStateStore $stateStore,
    ) {
    }

    /** Famille de notification (préfixe sujet + politique) : N3PP, MSP1… */
    abstract protected function family(): string;

    /** Préfixe des clés d'anti-spam (ex. `n3pp`, `msp1`). */
    abstract protected function throttleKeyPrefix(): string;

    /**
     * Point d'extension : alertes spécifiques à la famille sur la nouvelle ligne.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $state
     */
    protected function checkFamilySpecific(array $row, array &$state): void
    {
    }

    public function run(): void
    {
        $row = $this->sensorRepo->getLatest();
        if ($row === null || $row === []) {
            return;
        }

        $state = $this->stateStore->load();

        // Ne traiter chaque ligne qu'une fois (le CRON 1 min repasse plus souvent
        // que les réveils deep-sleep n'insèrent).
        $rowId = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
        if ($rowId !== null && $rowId === ($state['lastRowId'] ?? null)) {
            return;
        }

        $this->checkBattery($row, $state);
        $this->checkReboot($row, $state);
        $this->checkFamilySpecific($row, $state);

        $state['lastRowId'] = $rowId;
        $this->stateStore->save($state);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $state
     */
    private function checkBattery(array $row, array &$state): void
    {
        $pontDiv = $this->toFloatOrNull($row['PontDiv'] ?? null);
        $seuil = $this->toFloatOrNull($row['SeuilPontDiv'] ?? null);
        if ($pontDiv === null || $seuil === null || $seuil <= 0) {
            return;
        }

        $alreadyLow = (bool) ($state['batteryLow'] ?? false);
        $decision = LowValueAlertEvaluator::evaluate($pontDiv, $seuil, $alreadyLow);

        if ($decision === LowValueDecision::Raise) {
            $message = sprintf(
                "La batterie de l'appareil %s est faible : PontDiv = %.0f (seuil %.0f).\n"
                . "L'appareil bascule en sommeil protecteur (réveil GPIO uniquement) une fois le seuil franchi.",
                $this->family(),
                $pontDiv,
                $seuil
            );
            $this->notifier->sendAlert(
                Severity::Critical,
                NotificationCategory::Energy,
                $this->family(),
                'Batterie faible',
                $message,
                $this->throttleKeyPrefix() . ':battery-low'
            );
            $state['batteryLow'] = true;
        } elseif ($decision === LowValueDecision::Clear) {
            // Parité firmware : ré-armement silencieux, pas de mail de fin.
            $this->logger->info($this->family() . ' : batterie revenue au-dessus du seuil, alerte ré-armée.');
            $state['batteryLow'] = false;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $state
     */
    private function checkReboot(array $row, array &$state): void
    {
        $bootCount = isset($row['bootCount']) && is_numeric($row['bootCount'])
            ? (int) $row['bootCount']
            : null;
        if ($bootCount === null) {
            return;
        }

        $previous = $state['lastBootCount'] ?? null;
        $state['lastBootCount'] = $bootCount;

        if (!is_int($previous)) {
            return; // premier passage : initialiser sans notifier
        }

        if ($bootCount < $previous) {
            $message = sprintf(
                "L'appareil %s a redémarré (compteur de réveils remis à zéro : %d → %d).\n"
                . 'Cause probable : coupure d\'alimentation, reset matériel ou crash.',
                $this->family(),
                $previous,
                $bootCount
            );
            $this->notifier->sendAlert(
                Severity::Info,
                NotificationCategory::Lifecycle,
                $this->family(),
                'Redémarrage détecté',
                $message,
                $this->throttleKeyPrefix() . ':reboot'
            );
        }
    }

    protected function toFloatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
