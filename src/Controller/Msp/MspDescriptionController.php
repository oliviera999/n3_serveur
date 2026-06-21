<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Controller\AbstractDescriptionController;

/**
 * Page de description du module MSP1 (station météo / Le potager).
 */
class MspDescriptionController extends AbstractDescriptionController
{
    protected function descriptionTemplate(): string
    {
        return 'msp_description.twig';
    }

    protected function descriptionData(): array
    {
        return [
            'page_title' => 'Caractéristiques du module MSP1 - n3 iot datas',
            'hero_title' => 'Caractéristiques du module MSP1',
            'hero_subtitle' => 'Description du système, des capteurs, actionneurs et logiciels — Station météo du potager',
            'hero_icon' => 'fa-sun',
            'data_url' => '/meteo',
            'footer_text' => 'Station météo (Le potager) MSP1',
            'nav_active' => 'potager',
        ];
    }
}
