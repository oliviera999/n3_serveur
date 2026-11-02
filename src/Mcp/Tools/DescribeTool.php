<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\ToolResult;

/**
 * `iot_describe` — dictionnaire des champs (unité, signification, plage usuelle, GPIO).
 */
final class DescribeTool extends AbstractTool
{
    /** Termes du glossaire public (/glossaire) utiles à l'interprétation. */
    private const GLOSSARY = [
        'Marée' => "En aquaponie, la montée et la descente cyclique de l'eau qui irrigue les plantes puis revient aux poissons.",
        'Marnage' => "Différence entre le niveau d'eau le plus haut et le plus bas d'un cycle.",
        'UA' => 'Unité arbitraire : sert à comparer des mesures entre elles, pas à donner une valeur absolue.',
        'Pont diviseur' => 'Montage pour mesurer la tension de la batterie (valeur ADC 0–4095, pas des volts).',
        'Deep sleep' => 'Veille profonde : la carte dort pour économiser la batterie, puis se réveille pour mesurer.',
        'Heartbeat' => 'Signal régulier « je suis vivant » envoyé au serveur, indépendant des mesures.',
        'One-shot' => 'Commande exécutée une seule fois par le firmware puis remise à 0 par le serveur.',
    ];

    public function name(): string
    {
        return 'iot_describe';
    }

    public function title(): string
    {
        return 'Dictionnaire des champs';
    }

    public function description(): string
    {
        return 'Explique chaque champ mesuré ou réglé par famille : unité, signification physique, plage usuelle quand elle '
            . 'est connue, type (mesure, état d\'actionneur, réglage, commande, diagnostic) et table des GPIO (numéro → rôle). '
            . 'Indispensable pour interpréter EauAquarium/EauReserve/EauPotager (DISTANCES capteur→surface en cm : plus grand = '
            . 'moins d\'eau), diffMaree (mm, positif = l\'eau remonte), PontDiv (tension batterie en ADC), Humid1-4 (sol, '
            . 'plus bas = plus sec). Sans « family », renvoie toutes les familles.';
    }

    public function inputSchema(): array
    {
        $family = $this->familyProperty(true, 'Famille à décrire (optionnel).');

        return $this->objectSchema(['family' => $family]);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $families = isset($arguments['family']) ? [(string) $arguments['family']] : FieldCatalog::FAMILIES;
        $out = [];
        foreach ($families as $family) {
            $fields = [];
            foreach (FieldCatalog::fields($family) as $name => $def) {
                $entry = ['unit' => $def['unit'], 'kind' => $def['kind'], 'meaning' => $def['meaning']];
                if (isset($def['range'])) {
                    $entry['typical_range'] = $def['range'];
                }
                $fields[$name] = $entry;
            }
            $gpio = [];
            foreach (FieldCatalog::gpioLabels($family) as $number => $label) {
                if (in_array($label, ['mail', 'mailNotif'], true)) {
                    $label .= ' (donnée personnelle, non exposée)';
                }
                $gpio[(string) $number] = $label;
            }
            ksort($gpio, SORT_NATURAL);
            $out[$family] = [
                'label' => FieldCatalog::label($family),
                'fields' => $fields,
                'gpio' => $gpio,
            ];
        }

        return ToolResult::json([
            'families' => $out,
            'glossary' => self::GLOSSARY,
            'notes' => [
                'Les heures (reading_time) sont en heure locale du serveur.',
                'Les niveaux d\'eau FFP3 sont stockés en mm et exposés en cm par tous les outils (sauf diffMaree, en mm).',
                'Un capteur figé (valeurs identiques sur une longue période) ou 0 constant signale souvent une panne de capteur.',
            ],
        ]);
    }
}
