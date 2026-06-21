<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Controller\AbstractDescriptionController;

/**
 * Page de description du module N3PP (serre / élevage d'insectes).
 */
class N3ppDescriptionController extends AbstractDescriptionController
{
    protected function descriptionTemplate(): string
    {
        return 'n3pp_description.twig';
    }

    protected function descriptionData(): array
    {
        return [
            'page_title' => 'Caractéristiques du module N3PP - n3 iot datas',
            'hero_title' => 'Caractéristiques du module N3PP',
            'hero_subtitle' => "Description du système, des capteurs, actionneurs et logiciels — Serre / élevage d'insectes",
            'hero_icon' => 'fa-leaf',
            'data_url' => '/serre',
            'footer_text' => "Serre / élevage d'insectes (N3PP)",
            'nav_active' => 'elevage',
        ];
    }
}
