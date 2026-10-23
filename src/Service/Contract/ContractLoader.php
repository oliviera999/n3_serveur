<?php

declare(strict_types=1);

namespace App\Service\Contract;

/**
 * Charge les contrats machine-readable depuis serveur/contracts/*.json.
 * Source de vérité déclarative pour l'alignement firmware ↔ serveur (Phase 1 D).
 */
final class ContractLoader
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'contracts';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadAll(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($files);

        $contracts = [];
        foreach ($files as $file) {
            $contract = $this->loadFile($file);
            if ($contract !== null) {
                $contracts[] = $contract;
            }
        }

        return $contracts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadById(string $id): ?array
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $id . '.json';
        if (!is_file($path)) {
            return null;
        }

        return $this->loadFile($path);
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadFile(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['id']) || !is_string($data['id'])) {
            return null;
        }

        $data['_path'] = $path;

        return $data;
    }
}
