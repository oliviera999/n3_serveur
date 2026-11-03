<?php

declare(strict_types=1);

namespace App\Mcp\Control;

use App\Repository\MspOutputRepository;
use App\Repository\N3ppOutputRepository;
use App\Repository\OutputRepository;
use App\Service\OutputService;

/**
 * Écrit une sortie par le MÊME chemin que la page de contrôle web.
 *
 *  - FFP3 : {@see OutputService} (toggle / forçage 117 / compteur nourrissage) ou
 *    {@see OutputRepository::updateState()} pour les paramètres — source `web`, donc
 *    protégée de l'écrasement par le POST firmware pendant la fenêtre de priorité
 *    ({@see OutputRepository::SERVER_OWNED_SOURCES}) ;
 *  - N3PP / MSP1 : `updateByGpio()` des repositories outputs (board 3 / board 2),
 *    comme `doToggle()` / `updateParameters()` des contrôleurs.
 *
 * L'environnement doit déjà être positionné par l'appelant (EnvironmentScope).
 */
class OutputWriter
{
    private const AQUARIUM_PUMP_GPIO = 16;
    private const AQUARIUM_PUMP_FORCE_GPIO = 117;

    public function __construct(
        private OutputService $outputService,
        private OutputRepository $ffp3Outputs,
        private N3ppOutputRepository $n3ppOutputs,
        private MspOutputRepository $mspOutputs,
    ) {
    }

    /**
     * Applique `$value` au GPIO. Retourne la nouvelle valeur lue après écriture.
     */
    public function write(string $family, int $gpio, int|float $value, int $outputId = 0): ?string
    {
        if ($family === 'ffp3') {
            return $this->writeFfp3($gpio, $value, $outputId);
        }
        $repo = $family === 'n3pp' ? $this->n3ppOutputs : $this->mspOutputs;
        $board = $family === 'n3pp' ? 3 : 2;
        $repo->updateByGpio($gpio, (string) (int) $value, $board);
        $row = $repo->getOutputByGpioAndBoard($board, $gpio);

        return $row !== null && $row['state'] !== null ? (string) $row['state'] : null;
    }

    /**
     * Nourrissage manuel FFP3 : +1 sur le compteur monotone (contrat ffp5cs 15+).
     */
    public function feed(int $gpio, int $outputId): ?int
    {
        if ($outputId <= 0) {
            return null;
        }
        $result = $this->outputService->triggerManualFeed($outputId, $gpio);

        return $result['success'] ? (int) $result['counter'] : null;
    }

    private function writeFfp3(int $gpio, int|float $value, int $outputId): ?string
    {
        if ($gpio === self::AQUARIUM_PUMP_FORCE_GPIO) {
            $ok = $this->outputService->updatePumpForceMode((int) $value);
            if ($ok && (int) $value === 1) {
                $this->outputService->updateStateByGpio(self::AQUARIUM_PUMP_GPIO, 1);
            }
        } elseif (in_array($gpio, [2, 15, 16, 18], true)) {
            // Même écriture que le toggle de la page de contrôle (par id de la ligne nommée).
            if ($outputId <= 0 || !$this->outputService->updateStateById($outputId, (int) $value, 'web')) {
                return null;
            }
        } else {
            $this->ffp3Outputs->updateState($gpio, (int) round((float) $value), 'web');
        }
        $after = $outputId > 0 ? $this->ffp3Outputs->getStateById($outputId) : null;
        if ($after !== null) {
            return (string) $after;
        }
        $row = $this->ffp3Outputs->findByGpio($gpio);

        return $row !== null && $row['state'] !== null ? (string) $row['state'] : null;
    }
}
