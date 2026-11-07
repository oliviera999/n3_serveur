<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Garde-fous de DÉPLOIEMENT OTA, pilotés depuis la supervision (réglages opérationnels) :
 *
 *  - `OTA_PAUSED_CHANNELS`  : canaux mis en PAUSE (liste CSV, ex. `esp32-wroom,n3pp`). Toute
 *    requête de ces canaux (manifeste comme binaire) reçoit 503 + Retry-After : aucun module ne
 *    découvre ni ne télécharge la version publiée tant que la pause dure.
 *  - `OTA_CANARY_CHANNELS` : canaux en mode CANARI (CSV). Seules les cartes listées dans
 *    `OTA_CANARY_BOARDS` (CSV d'identifiants envoyés par le firmware dans l'en-tête `X-Ota-Board`)
 *    sont servies ; les autres reçoivent 503. Une requête sans en-tête est refusée (un firmware
 *    antérieur à 15.34 ne s'identifie pas : il attendra la fin du canari).
 *
 * Le canal est le PREMIER segment du chemin servi (`esp32-wroom/firmware.bin` → `esp32-wroom`,
 * `n3pp-test/metadata.json` → `n3pp-test`) ; le manifeste racine `metadata.json` (ffp5cs : un
 * manifeste pour tous les canaux) porte le canal `root`. Mettre `root` en pause bloque donc la
 * découverte de version de TOUTES les cartes ffp5cs ; mettre `esp32-wroom` en pause ne bloque que
 * le binaire WROOM prod (le module voit la version mais ne peut pas la télécharger).
 *
 * Incident du 06/11/2026 : une version publiée sur le canal prod sans palier ni possibilité de la
 * retenir à distance. Sans ces réglages, le seul frein était un commit Git de rollback.
 */
final class OtaChannelPolicy
{
    public const SETTING_PAUSED = 'OTA_PAUSED_CHANNELS';
    public const SETTING_CANARY_CHANNELS = 'OTA_CANARY_CHANNELS';
    public const SETTING_CANARY_BOARDS = 'OTA_CANARY_BOARDS';

    public const ROOT_CHANNEL = 'root';

    public const DECISION_ALLOW = 'allow';
    public const DECISION_PAUSED = 'paused';
    public const DECISION_CANARY_DENIED = 'canary_denied';

    public function __construct(private ?OperationalSettingsService $settings = null)
    {
    }

    /** Canal d'un chemin servi (`esp32-wroom/firmware.bin` → `esp32-wroom`, `metadata.json` → `root`). */
    public static function channelOf(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $slash = strpos($path, '/');
        if ($slash === false) {
            return self::ROOT_CHANNEL;
        }

        return substr($path, 0, $slash);
    }

    /** `metadata` pour un manifeste JSON, `bin` pour un binaire, `other` sinon. */
    public static function kindOf(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'json' => 'metadata',
            'bin' => 'bin',
            default => 'other',
        };
    }

    /**
     * Décision pour une requête : `allow`, `paused` ou `canary_denied`.
     *
     * @param string|null $board Identifiant de carte annoncé par le firmware (`X-Ota-Board`), null si absent
     */
    public function decide(string $channel, ?string $board): string
    {
        if (in_array($channel, $this->pausedChannels(), true)) {
            return self::DECISION_PAUSED;
        }
        if (in_array($channel, $this->canaryChannels(), true)) {
            $board = $board !== null ? trim($board) : '';
            if ($board === '' || !in_array($board, $this->canaryBoards(), true)) {
                return self::DECISION_CANARY_DENIED;
            }
        }

        return self::DECISION_ALLOW;
    }

    /** @return list<string> */
    public function pausedChannels(): array
    {
        return $this->csv(self::SETTING_PAUSED);
    }

    /** @return list<string> */
    public function canaryChannels(): array
    {
        return $this->csv(self::SETTING_CANARY_CHANNELS);
    }

    /** @return list<string> */
    public function canaryBoards(): array
    {
        return $this->csv(self::SETTING_CANARY_BOARDS);
    }

    /**
     * État lisible (MCP / rapport de santé).
     *
     * @return array{paused_channels: list<string>, canary_channels: list<string>, canary_boards: list<string>}
     */
    public function describe(): array
    {
        return [
            'paused_channels' => $this->pausedChannels(),
            'canary_channels' => $this->canaryChannels(),
            'canary_boards' => $this->canaryBoards(),
        ];
    }

    /** @return list<string> */
    private function csv(string $key): array
    {
        $raw = $this->settings?->string($key, '') ?? (string) ($_ENV[$key] ?? '');
        $out = [];
        foreach (explode(',', $raw) as $item) {
            $item = trim($item);
            if ($item !== '' && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $item) === 1) {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }
}
