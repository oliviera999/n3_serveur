<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Résultat d'un appel d'outil MCP (`tools/call`).
 *
 * Le contenu texte est un JSON COMPACT (le modèle le lit, pas un humain) ; les données
 * structurées sont aussi exposées dans `structuredContent` (MCP 2025-06-18+). Les images
 * (photos de galerie) sont des blocs `image` base64.
 */
final class ToolResult
{
    /**
     * @param list<array<string, mixed>> $content
     * @param array<string, mixed>|null  $structured
     */
    private function __construct(
        private array $content,
        private bool $isError,
        private ?array $structured,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data): self
    {
        return new self([['type' => 'text', 'text' => self::encode($data)]], false, $data);
    }

    public static function error(string $message, ?string $code = null): self
    {
        $payload = ['error' => $message];
        if ($code !== null) {
            $payload['code'] = $code;
        }

        return new self([['type' => 'text', 'text' => self::encode($payload)]], true, null);
    }

    /**
     * Ajoute un bloc image (base64) au résultat.
     */
    public function withImage(string $base64, string $mimeType): self
    {
        $clone = clone $this;
        $clone->content[] = ['type' => 'image', 'data' => $base64, 'mimeType' => $mimeType];

        return $clone;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function structured(): ?array
    {
        return $this->structured;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = ['content' => $this->content, 'isError' => $this->isError];
        if ($this->structured !== null) {
            $result['structuredContent'] = $this->structured;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function encode(array $data): string
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $json === false ? '{"error":"encodage JSON impossible"}' : $json;
    }
}
