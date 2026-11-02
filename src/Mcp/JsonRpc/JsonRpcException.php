<?php

declare(strict_types=1);

namespace App\Mcp\JsonRpc;

/**
 * Erreur de protocole JSON-RPC 2.0 (renvoyée dans `error`, et non comme résultat d'outil).
 *
 * Codes standards : https://www.jsonrpc.org/specification#error_object
 */
final class JsonRpcException extends \RuntimeException
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(int $code, string $message, private ?array $data = null)
    {
        parent::__construct($message, $code);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    public static function invalidParams(string $message): self
    {
        return new self(self::INVALID_PARAMS, $message);
    }

    public static function methodNotFound(string $method): self
    {
        return new self(self::METHOD_NOT_FOUND, 'Méthode inconnue : ' . $method);
    }
}
