<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Controller\AbstractDataController;
use App\Display\Catalog\N3ppCatalog;
use App\Display\PageCatalog;
use App\Repository\N3ppSensorRepository;
use App\Security\CsrfService;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\DisplayPresetService;
use App\Service\TemplateRenderer;
use App\Util\RealtimeUrlHelper;

/**
 * Page de données de la serre / élevage (N3PP).
 * Hérite du flux commun AbstractDataController ; affichage décrit par N3ppCatalog.
 */
class N3ppDataController extends AbstractDataController
{
    public function __construct(
        TemplateRenderer $renderer,
        N3ppSensorRepository $sensorRepo,
        CsrfService $csrfService,
        DateRangeExtractor $dateRangeExtractor,
        CsvExportService $csvExportService,
        ChartDataService $chartDataService,
        DisplayPresetService $displayPresets,
    ) {
        parent::__construct($renderer, $sensorRepo, $csrfService, $dateRangeExtractor, $csvExportService, $chartDataService, $displayPresets);
    }

    protected function catalog(): PageCatalog
    {
        return new N3ppCatalog();
    }

    protected function getTemplateName(): string
    {
        return 'n3pp_data.twig';
    }

    protected function getPageTitle(string $testSuffix): string
    {
        return 'Données serre / élevage - n3 iot' . $testSuffix;
    }

    protected function getNavActive(): string
    {
        return 'elevage';
    }

    protected function getCsvPrefix(): string
    {
        return 'n3pp_data';
    }

    protected function getRealtimeApiBase(string $environment): string
    {
        return RealtimeUrlHelper::getN3ppRealtimeApiBase($environment);
    }

    protected function getTestEnvironmentName(): string
    {
        return 'n3pp_test';
    }
}
