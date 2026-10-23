<?php

declare(strict_types=1);

namespace App\Display;

use App\Display\Catalog\EnergieCatalog;
use App\Display\Catalog\MspCatalog;
use App\Display\Catalog\N3ppCatalog;

/**
 * Registre des pages de suivi pilotées par catalogue (clé de page → catalogue).
 */
class PageCatalogRegistry
{
    /** @var array<string, PageCatalog> */
    private array $catalogs = [];

    public function __construct()
    {
        foreach ([new MspCatalog(), new N3ppCatalog(), new EnergieCatalog()] as $catalog) {
            $this->catalogs[$catalog->pageKey()] = $catalog;
        }
    }

    public function get(string $pageKey): ?PageCatalog
    {
        return $this->catalogs[$pageKey] ?? null;
    }

    /** @return array<string, PageCatalog> */
    public function all(): array
    {
        return $this->catalogs;
    }
}
