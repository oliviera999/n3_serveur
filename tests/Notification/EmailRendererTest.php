<?php

declare(strict_types=1);

namespace Tests\Notification;

use App\Notification\EmailRenderer;
use App\Notification\NotificationCategory;
use App\Notification\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Tests du rendu Twig des e-mails (alerte unitaire + synthèse).
 */
final class EmailRendererTest extends TestCase
{
    private EmailRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new EmailRenderer();
    }

    public function testRenderAlertHtmlContainsSeverityFamilyAndMessage(): void
    {
        $html = $this->renderer->renderAlertHtml(
            Severity::Critical,
            NotificationCategory::Availability,
            'N3PP',
            'Système hors ligne',
            "Aucune donnée depuis 2 h.\nIntervention requise."
        );

        self::assertStringContainsString('<html', $html);
        self::assertStringContainsString('P1', $html);
        self::assertStringContainsString('N3PP', $html);
        self::assertStringContainsString('Système hors ligne', $html);
        self::assertStringContainsString('Intervention requise', $html);
        // nl2br appliqué dans le template.
        self::assertStringContainsString('<br', $html);
    }

    public function testRenderAlertTextHasNoHtmlAndContainsCode(): void
    {
        $text = $this->renderer->renderAlertText(
            Severity::Alert,
            NotificationCategory::System,
            'FFP3',
            'Anomalie',
            'Détails.'
        );

        self::assertStringContainsString('[P2] Anomalie', $text);
        self::assertStringContainsString('FFP3', $text);
        self::assertStringContainsString('Détails.', $text);
        self::assertStringNotContainsString('<', $text);
    }

    public function testRenderDigestHtmlListsAllItems(): void
    {
        $html = $this->renderer->renderDigestHtml([
            [
                'severity_code' => 'P3',
                'severity_label' => 'Info',
                'severity_emoji' => '🟡',
                'family' => 'MSP1',
                'subject' => 'Réveil',
                'message' => 'corps 1',
                'count' => 2,
                'last_seen' => '2026-06-24 10:00:00',
            ],
            [
                'severity_code' => 'P4',
                'severity_label' => 'Diagnostic',
                'severity_emoji' => '⚪',
                'family' => null,
                'subject' => 'Rapport',
                'message' => 'corps 2',
                'count' => 1,
                'last_seen' => '2026-06-24 10:05:00',
            ],
        ]);

        self::assertStringContainsString('Réveil', $html);
        self::assertStringContainsString('Rapport', $html);
        self::assertStringContainsString('MSP1', $html);
        // Compteur d'occurrences (x2) affiché.
        self::assertStringContainsString('2', $html);
    }

    public function testRenderDigestTextContainsPrefixesAndCounts(): void
    {
        $text = $this->renderer->renderDigestText([
            [
                'severity_code' => 'P3',
                'severity_label' => 'Info',
                'severity_emoji' => '🟡',
                'family' => 'MSP1',
                'subject' => 'Réveil',
                'message' => 'corps',
                'count' => 3,
                'last_seen' => '2026-06-24 10:00:00',
            ],
        ]);

        self::assertStringContainsString('[P3][MSP1] Réveil (x3)', $text);
        self::assertStringContainsString('Dernière occurrence : 2026-06-24 10:00:00', $text);
    }
}
