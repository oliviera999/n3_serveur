<?php

declare(strict_types=1);

namespace App\Mcp\Control;

/**
 * Décision de {@see ControlPolicy::evaluate()} : acceptation, refus matériel (non levable)
 * ou violation biologique (levable avec override + confirmation).
 */
final class PolicyDecision
{
    public const OK = 'ok';
    public const MATERIAL = 'material';
    public const BIOLOGICAL = 'biological';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $message = null,
        public readonly ?string $bioCategory = null,
    ) {
    }

    public static function ok(): self
    {
        return new self(self::OK);
    }

    public static function material(string $message): self
    {
        return new self(self::MATERIAL, $message);
    }

    public static function biological(string $message, string $category = 'bio_override'): self
    {
        return new self(self::BIOLOGICAL, $message, $category);
    }

    public function isOk(): bool
    {
        return $this->kind === self::OK;
    }

    public function isBiological(): bool
    {
        return $this->kind === self::BIOLOGICAL;
    }

    public function isMaterial(): bool
    {
        return $this->kind === self::MATERIAL;
    }
}
