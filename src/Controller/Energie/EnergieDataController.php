<?php

declare(strict_types=1);

namespace App\Controller\Energie;

use App\Controller\AbstractDataController;
use App\Display\Catalog\EnergieCatalog;
use App\Display\PageCatalog;
use App\Repository\EnergieSensorRepository;
use App\Security\CsrfService;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\DisplayPresetService;
use App\Service\TemplateRenderer;
use App\Util\RealtimeUrlHelper;

/**
 * Page publique du banc énergie (`/energie` en prod, `/energie-test` en energie_test) :
 * cartes live, polling temps réel et graphiques Highstock, sur le même schéma que MSP1.
 * Affichage décrit par EnergieCatalog.
 */
class EnergieDataController extends AbstractDataController
{
    public function __construct(
        TemplateRenderer $renderer,
        EnergieSensorRepository $sensorRepo,
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
        return new EnergieCatalog();
    }

    protected function getTemplateName(): string
    {
        return 'energie_data.twig';
    }

    protected function getPageTitle(string $testSuffix): string
    {
        return 'Banc énergie — INA226' . $testSuffix;
    }

    protected function getNavActive(): string
    {
        return 'energie';
    }

    protected function getCsvPrefix(): string
    {
        return 'energie_data';
    }

    protected function getRealtimeApiBase(string $environment): string
    {
        return RealtimeUrlHelper::getEnergieRealtimeApiBase($environment);
    }

    protected function getTestEnvironmentName(): string
    {
        return 'energie_test';
    }
}
