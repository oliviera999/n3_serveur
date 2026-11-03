<?php

declare(strict_types=1);

namespace App\Mcp\Control;

use App\Mcp\McpConfig;

/**
 * Plafonds biologiques réglables (issus de {@see McpConfig}).
 */
final class BioLimits
{
    public function __construct(
        public readonly bool $enabled,
        public readonly int $heatingMin,
        public readonly int $heatingMax,
        public readonly int $feedDailyMax,
        public readonly int $pumpStopMaxMinutes,
    ) {
    }

    public static function fromConfig(McpConfig $config): self
    {
        return new self(
            $config->bioSafeguardsEnabled(),
            $config->heatingMin(),
            $config->heatingMax(),
            $config->feedDailyMax(),
            $config->pumpStopMaxMinutes(),
        );
    }
}
