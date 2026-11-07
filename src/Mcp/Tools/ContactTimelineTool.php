<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\TimeWindow;
use App\Mcp\Timeline\ContactLossClassifier;
use App\Mcp\Timeline\ContactTimelineBuilder;
use App\Mcp\ToolResult;

/**
 * `iot_contact_timeline` — frise des contacts d'un module et classification de la cause d'une
 * perte de contact (OTA, crash, Wi-Fi / réseau, horloge / signature, alimentation, serveur),
 * avec les preuves horodatées pour et contre, le dernier contact, le prochain réveil attendu et
 * ce qui reste invérifiable à distance. Lecture seule.
 */
final class ContactTimelineTool extends AbstractTool
{
    public const MAX_SPAN_DAYS = 30;
    public const DEFAULT_SPAN_SECONDS = 24 * 3600;

    public function __construct(McpConfig $config, private ContactTimelineBuilder $builder)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_contact_timeline';
    }

    public function title(): string
    {
        return 'Frise des contacts et cause d\'une perte de contact';
    }

    public function description(): string
    {
        return 'Après une perte de contact d\'un module (FFP3 WROOM ou S3, N3PP, MSP1), répond en UNE lecture : '
            . 'frise horodatée fusionnant les POST acceptés (regroupés en rafales) et les requêtes rejetées avec '
            . 'leur motif (signature invalide, clé API, fenêtre d\'horloge), les heartbeats (uptime, reboots, raison de '
            . 'boot, partition, état OTA, mode sans échec), les événements OTA côté serveur (manifeste consulté, binaire '
            . 'servi complet ou partiel, canal en pause), les publications OTA (ota_releases), les événements rapportés '
            . 'par le firmware (check / start / progress / done / fail / rollback / marked_valid, journal de bord), les '
            . 'photos de la caméra du même site (preuve que le réseau du site fonctionne), les e-mails envoyés, les erreurs '
            . 'serveur et le décalage d\'horloge firmware − serveur. Donne le dernier contact, le prochain réveil attendu '
            . '(FreqWakeUp × facteur nuit confronté aux rafales observées, réveils manqués), une classification '
            . 'probabiliste de la cause (' . implode(', ', ContactLossClassifier::CAUSES) . ') avec les indices pour et '
            . 'contre, et la liste de ce qui reste invérifiable à distance. Défaut : 24 dernières heures, max '
            . self::MAX_SPAN_DAYS . ' j. Heures en heure serveur.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema(array_merge([
            'family' => $this->familyProperty(false, 'Module à analyser.'),
        ], $this->windowProperties('-24h'), ['env' => $this->envProperty()]), ['family']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $family = (string) $arguments['family'];
        $window = TimeWindow::parse(
            isset($arguments['from']) ? (string) $arguments['from'] : null,
            isset($arguments['to']) ? (string) $arguments['to'] : null,
            self::DEFAULT_SPAN_SECONDS,
            self::MAX_SPAN_DAYS
        );

        return $this->inEnv(
            $arguments,
            fn (string $env): array => $this->builder->build($family, $env, $window->from, $window->to),
            $family
        );
    }
}
