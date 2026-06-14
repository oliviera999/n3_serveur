<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Page « Glossaire » — définitions simples des termes techniques du projet,
 * pour rendre le site lisible par le public collège visé (voir reco B.9).
 */
final class GlossaireController
{
    public function __construct(
        private TemplateRenderer $renderer,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $html = $this->renderer->render('glossaire.twig', [
            'page_title' => 'Glossaire — n³ IoT',
            'nav_active' => 'glossaire',
            'glossary' => self::GLOSSARY,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Contenu du glossaire, regroupé par thème.
     *
     * @var array<int, array{title: string, icon: string, terms: array<int, array{term: string, definition: string}>}>
     */
    private const GLOSSARY = [
        [
            'title' => 'Matériel & électronique',
            'icon' => 'fa-microchip',
            'terms' => [
                ['term' => 'ESP32', 'definition' => "Petite carte électronique programmable (un microcontrôleur) avec Wi-Fi, qui lit les capteurs et commande le matériel. C'est le « cerveau » de chaque module."],
                ['term' => 'Capteur', 'definition' => 'Composant qui mesure une grandeur physique : température, humidité, lumière, distance…'],
                ['term' => 'Actionneur', 'definition' => 'Sortie pilotée par le module : pompe, chauffage, relais, moteur.'],
                ['term' => 'Capteur à ultrasons', 'definition' => "Mesure une distance avec un son inaudible, comme une chauve-souris ; ici, la hauteur de l'eau dans les bacs."],
                ['term' => 'Photorésistance', 'definition' => "Un capteur dont la résistance électrique change avec la lumière reçue : il sert à mesurer l'ensoleillement."],
                ['term' => 'Pont diviseur', 'definition' => 'Un montage électronique simple pour mesurer une tension, ici celle de la batterie.'],
                ['term' => 'Tracker solaire', 'definition' => 'Un support motorisé qui suit le soleil pour capter le plus de lumière possible.'],
            ],
        ],
        [
            'title' => 'Fonctionnement & réseau',
            'icon' => 'fa-wifi',
            'terms' => [
                ['term' => 'Offline-first', 'definition' => 'Conçu pour fonctionner même sans internet : les réglages sont stockés sur la carte, et les données sont rejouées au retour du réseau.'],
                ['term' => 'OTA', 'definition' => '« Over-The-Air » : mettre à jour le programme de la carte à distance, sans la débrancher ni la relier par un câble.'],
                ['term' => 'Deep sleep', 'definition' => 'Veille profonde : la carte « dort » pour économiser la batterie, puis se réveille pour prendre une mesure.'],
                ['term' => 'Heartbeat', 'definition' => '« Battement » : un petit signal régulier qui dit « je suis vivant » au serveur, pour savoir si un module est en ligne.'],
            ],
        ],
        [
            'title' => 'Mesures & données',
            'icon' => 'fa-chart-line',
            'terms' => [
                ['term' => 'UA (unité arbitraire)', 'definition' => 'Un nombre sans unité physique, utile pour comparer les mesures entre elles, pas pour donner une valeur absolue (ex. luminosité, humidité du sol).'],
                ['term' => 'Marée', 'definition' => "En aquaponie, la montée et la descente cyclique de l'eau qui irrigue les plantes puis revient aux poissons."],
                ['term' => 'Marnage', 'definition' => "La différence entre le niveau d'eau le plus haut et le plus bas d'un cycle."],
                ['term' => 'Aquaponie', 'definition' => "Élevage de poissons et culture de plantes dans un circuit d'eau commun : les déjections des poissons nourrissent les plantes, qui filtrent l'eau."],
                ['term' => 'Timelapse', 'definition' => "Vidéo accélérée composée de photos prises à intervalles réguliers : la croissance d'une plante en quelques secondes."],
            ],
        ],
        [
            'title' => "Chimie de l'eau (aquaponie)",
            'icon' => 'fa-flask',
            'terms' => [
                ['term' => 'pH', 'definition' => "Mesure l'acidité ou la basicité de l'eau. En aquaponie, l'idéal se situe autour de 6,8 à 7,2."],
                ['term' => 'Ammoniaque (NH₃)', 'definition' => 'Déchet azoté rejeté par les poissons, toxique à forte dose : il doit rester très bas.'],
                ['term' => 'Nitrites (NO₂)', 'definition' => "Étape intermédiaire et dangereuse du cycle de l'azote : doivent être quasi indétectables."],
                ['term' => 'Nitrates (NO₃)', 'definition' => "Produit final du cycle de l'azote : c'est l'engrais des plantes."],
                ['term' => 'Oxygène dissous (O₂)', 'definition' => "Oxygène disponible dans l'eau, vital pour les poissons et les bactéries."],
                ['term' => 'Phosphates (PO₄³⁻)', 'definition' => 'Nutriment utile aux plantes.'],
                ['term' => 'Potassium (K⁺)', 'definition' => 'Nutriment des plantes, souvent en manque en aquaponie.'],
                ['term' => 'Fer', 'definition' => 'Oligo-élément indispensable aux plantes, en petite quantité.'],
            ],
        ],
    ];
}
