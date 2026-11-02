<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Erreur métier d'un outil : renvoyée au modèle comme résultat `isError: true`
 * (et non comme erreur de protocole JSON-RPC), avec un message actionnable.
 */
final class ToolException extends \RuntimeException
{
    public function __construct(string $message, private string $errorCode = 'tool_error')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
