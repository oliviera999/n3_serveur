<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Controller\AbstractDataController;
use App\Display\Catalog\MspCatalog;
use App\Display\PageCatalog;
use App\Repository\MspSensorRepository;
use App\Security\CsrfService;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\DisplayPresetService;
use App\Service\TemplateRenderer;
use App\Util\RealtimeUrlHelper;

/**
 * Page de données de la station météo (MSP1).
 * Hérite du flux commun AbstractDataController ; affichage décrit par MspCatalog.
 */
class MspDataController extends AbstractDataController
{
    public function __construct(
        TemplateRenderer $renderer,
        MspSensorRepository $sensorRepo,
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
        return new MspCatalog();
    }

    protected function getTemplateName(): string
    {
        return 'msp1_data.twig';
    }

    protected function getPageTitle(string $testSuffix): string
    {
        return 'Données station météo - Le potager' . $testSuffix;
    }

    protected function getNavActive(): string
    {
        return 'potager';
    }

    protected function getCsvPrefix(): string
    {
        return 'msp1_data';
    }

    protected function getRealtimeApiBase(string $environment): string
    {
        return RealtimeUrlHelper::getMspRealtimeApiBase($environment);
    }

    protected function getTestEnvironmentName(): string
    {
        return 'msp_test';
    }
}
