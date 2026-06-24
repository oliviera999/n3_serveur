<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Notification\AlertThrottler;
use App\Notification\NotificationCategory;
use App\Notification\NotificationMode;
use App\Notification\NotificationPolicy;
use App\Notification\Severity;
use App\Service\LogService;
use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;
use Tests\Notification\FakeDigestQueue;
use Tests\Notification\FakeMailTransport;

/**
 * Tests unitaires de NotificationService.
 *
 * Les envois e-mail sont capturés par un {@see FakeMailTransport} (aucun MTA/SMTP réel) ;
 * la file de synthèse utilise un {@see FakeDigestQueue} en mémoire. Le LogService est mocké
 * pour vérifier les branches de log succès/échec. Le AlertThrottler est mocké (allow=true par
 * défaut) afin d'isoler les tests de la base de données.
 *
 * Couverture :
 *  - configuration du destinataire et de l'expéditeur depuis $_ENV ;
 *  - chaque notification P1/P2 déclenche exactement UN envoi (sujet préfixé [FAMILLE][Pn]) ;
 *  - le corps multipart contient bien le HTML rendu et un repli texte ;
 *  - branche succès -> LogService::info, branche échec -> LogService::error ;
 *  - filtrage par la politique (mode + catégorie coupée) ;
 *  - suppression par l'anti-spam (cooldown) ;
 *  - routage des P3/P4 vers le digest + flush groupé.
 */
final class NotificationServiceTest extends TestCase
{
    /** @var array<string, string> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        // Mémorise puis fixe les variables d'environnement utilisées par le constructeur.
        foreach (['NOTIF_EMAIL_RECIPIENT', 'MAIL_FROM'] as $key) {
            $this->previousEnv[$key] = $_ENV[$key] ?? '';
        }
        $_ENV['NOTIF_EMAIL_RECIPIENT'] = 'destinataire@test.local';
        $_ENV['MAIL_FROM'] = 'Système n3 <noreply@test.local>';

        putenv('LOG_FILE_PATH=php://memory');
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            if ($value === '') {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    /** Throttler permissif (autorise tout envoi) pour isoler les tests de la base. */
    private function permissiveThrottler(): AlertThrottler
    {
        $throttler = $this->createMock(AlertThrottler::class);
        $throttler->method('allow')->willReturn(true);

        return $throttler;
    }

    private function makeService(
        ?LogService $logger = null,
        ?NotificationPolicy $policy = null,
        ?AlertThrottler $throttler = null,
        ?FakeMailTransport $transport = null,
        ?FakeDigestQueue $digest = null
    ): NotificationService {
        return new NotificationService(
            $logger ?? $this->createMock(LogService::class),
            $policy ?? new NotificationPolicy(NotificationMode::Full),
            $throttler ?? $this->permissiveThrottler(),
            $transport ?? new FakeMailTransport(),
            null, // EmailRenderer réel : vérifie aussi le rendu Twig
            $digest ?? new FakeDigestQueue()
        );
    }

    public function testSendCustomAlertSendsSingleMailToConfiguredRecipient(): void
    {
        $transport = new FakeMailTransport();
        $service = $this->makeService(transport: $transport);

        $result = $service->sendCustomAlert('Sujet de test', 'Corps simple');

        self::assertTrue($result);
        self::assertSame(1, $transport->count());
        $last = $transport->last();
        self::assertNotNull($last);
        self::assertSame('destinataire@test.local', $last['to']);
        self::assertSame('Système n3 <noreply@test.local>', $last['from']);
        // sendCustomAlert : sévérité P2 (Alerte), sans famille -> préfixe [P2].
        self::assertSame('[P2] Sujet de test', $last['subject']);
    }

    public function testSendCustomAlertRendersHtmlAndTextBodies(): void
    {
        $transport = new FakeMailTransport();
        $service = $this->makeService(transport: $transport);

        $service->sendCustomAlert('Sujet', "ligne1\nligne2");

        $last = $transport->last();
        self::assertNotNull($last);
        // Le corps HTML rendu par Twig contient le message et la structure HTML.
        self::assertStringContainsString('<html', $last['html']);
        self::assertStringContainsString('ligne1', $last['html']);
        self::assertStringContainsString('ligne2', $last['html']);
        // nl2br est appliqué côté template HTML.
        self::assertStringContainsString('<br', $last['html']);
        // Le repli texte contient les lignes sans balise HTML.
        self::assertStringContainsString('ligne1', $last['text']);
        self::assertStringNotContainsString('<br', $last['text']);
    }

    public function testSuccessfulSendLogsInfo(): void
    {
        $logger = $this->createMock(LogService::class);
        $logger->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->stringContains('destinataire@test.local'));
        $logger->expects($this->never())->method('error');

        $this->makeService($logger)->sendCustomAlert('Sujet', 'Corps');
    }

    public function testFailedSendLogsError(): void
    {
        $transport = new FakeMailTransport();
        $transport->failNext();

        $logger = $this->createMock(LogService::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Échec'));

        $result = $this->makeService($logger, transport: $transport)->sendCustomAlert('Sujet', 'Corps');

        self::assertFalse($result);
    }

    public function testNotifyFloodRiskSendsExpectedSubject(): void
    {
        $transport = new FakeMailTransport();
        $this->makeService(transport: $transport)->notifyFloodRisk();

        self::assertSame(1, $transport->count());
        $last = $transport->last();
        self::assertNotNull($last);
        self::assertSame("[FFP3][P1] Risque d'inondation", $last['subject']);
        // Le corps texte conserve l'apostrophe brute (le HTML l'échappe en &#039;).
        self::assertStringContainsString("niveau d'eau", $last['text']);
        self::assertStringContainsString('aquarium', $last['html']);
    }

    public function testNotifyMareesProblemSendsExpectedSubject(): void
    {
        $transport = new FakeMailTransport();
        $this->makeService(transport: $transport)->notifyMareesProblem();

        $last = $transport->last();
        self::assertNotNull($last);
        self::assertSame('[FFP3][P1] Problème de marées', $last['subject']);
    }

    public function testNotifyNoSensorDataSendsExpectedSubject(): void
    {
        $transport = new FakeMailTransport();
        $this->makeService(transport: $transport)->notifyNoSensorData();

        $last = $transport->last();
        self::assertNotNull($last);
        self::assertSame('[FFP3][P2] Aucune donnée capteur disponible', $last['subject']);
    }

    public function testNotifySystemOfflineSendsExpectedSubject(): void
    {
        $transport = new FakeMailTransport();
        $this->makeService(transport: $transport)->notifySystemOffline();

        $last = $transport->last();
        self::assertNotNull($last);
        self::assertSame('[FFP3][P1] Système hors ligne', $last['subject']);
    }

    public function testSendAlertCriticalBuildsFamilyAndSeverityPrefixAndSendsImmediately(): void
    {
        $transport = new FakeMailTransport();
        $digest = new FakeDigestQueue();
        $this->makeService(transport: $transport, digest: $digest)->sendAlert(
            Severity::Critical,
            NotificationCategory::Energy,
            'N3PP',
            'Batterie critique (1650 mV)',
            'Niveau batterie sous le seuil.'
        );

        self::assertSame(1, $transport->count(), 'Une P1 part immédiatement');
        self::assertSame(0, $digest->pendingCount(), 'Une P1 ne passe jamais par le digest');
        $last = $transport->last();
        self::assertNotNull($last);
        self::assertSame('[N3PP][P1] Batterie critique (1650 mV)', $last['subject']);
    }

    public function testDefaultRecipientUsedWhenEnvMissing(): void
    {
        unset($_ENV['NOTIF_EMAIL_RECIPIENT']);

        $transport = new FakeMailTransport();
        $this->makeService(transport: $transport)->notifyFloodRisk();

        $last = $transport->last();
        self::assertNotNull($last);
        self::assertSame('user@example.com', $last['to']);
    }

    // ------------------------------------------------------------------
    // Filtrage par la politique (mode de verbosité + catégorie coupée)
    // ------------------------------------------------------------------

    public function testModeNoneBlocksEveryMail(): void
    {
        $transport = new FakeMailTransport();
        $service = $this->makeService(
            policy: new NotificationPolicy(NotificationMode::None),
            transport: $transport
        );

        $service->notifyMareesProblem();
        $service->notifySystemOffline();

        self::assertSame(0, $transport->count());
    }

    public function testImportantModeBlocksInfoButAllowsAlert(): void
    {
        $transport = new FakeMailTransport();
        $digest = new FakeDigestQueue();
        $service = $this->makeService(
            policy: new NotificationPolicy(NotificationMode::Important),
            transport: $transport,
            digest: $digest
        );

        $service->sendAlert(Severity::Info, NotificationCategory::Lifecycle, 'FFP3', 'Réveil système', 'corps');
        self::assertSame(0, $transport->count(), 'Une P3 doit être filtrée en mode important');
        self::assertSame(0, $digest->pendingCount(), 'Filtrée par la politique : pas même mise en file');

        $service->sendAlert(Severity::Alert, NotificationCategory::System, 'FFP3', 'Anomalie', 'corps');
        self::assertSame(1, $transport->count(), 'Une P2 doit passer en mode important');
    }

    public function testDisabledCategoryBlocksMatchingMailOnly(): void
    {
        $transport = new FakeMailTransport();
        $policy = new NotificationPolicy(NotificationMode::Full, [NotificationCategory::Hydraulic]);
        $service = $this->makeService(policy: $policy, transport: $transport);

        // Catégorie Hydraulic coupée -> marées filtrées.
        $service->notifyMareesProblem();
        self::assertSame(0, $transport->count());

        // Catégorie Availability toujours active -> passe.
        $service->notifySystemOffline();
        self::assertSame(1, $transport->count());
    }

    public function testThrottledAlertIsNotSent(): void
    {
        $throttler = $this->createMock(AlertThrottler::class);
        $throttler->method('allow')->willReturn(false);

        $transport = new FakeMailTransport();
        $service = $this->makeService(
            policy: new NotificationPolicy(NotificationMode::Full),
            throttler: $throttler,
            transport: $transport
        );

        $service->notifyMareesProblem();

        self::assertSame(0, $transport->count(), 'Le cooldown anti-spam doit supprimer l\'envoi');
    }

    // ------------------------------------------------------------------
    // Digest (synthèse des P3/P4)
    // ------------------------------------------------------------------

    public function testLowSeverityAlertIsQueuedNotSentImmediately(): void
    {
        $transport = new FakeMailTransport();
        $digest = new FakeDigestQueue();
        $service = $this->makeService(transport: $transport, digest: $digest);

        $service->sendAlert(
            Severity::Info,
            NotificationCategory::Lifecycle,
            'MSP1',
            'Réveil programmé',
            'Le device est sorti de veille.'
        );

        self::assertSame(0, $transport->count(), 'Une P3 ne doit pas partir immédiatement');
        self::assertSame(1, $digest->pendingCount(), 'Une P3 doit être mise en file de synthèse');
    }

    public function testDiagnosticAlertIsQueued(): void
    {
        $digest = new FakeDigestQueue();
        $transport = new FakeMailTransport();
        $service = $this->makeService(transport: $transport, digest: $digest);

        $service->sendAlert(Severity::Diagnostic, NotificationCategory::System, 'FFP3', 'Test périodique', 'ok');

        self::assertSame(0, $transport->count());
        self::assertSame(1, $digest->pendingCount());
    }

    public function testLowSeverityFallsBackToDirectSendWhenQueueUnavailable(): void
    {
        $transport = new FakeMailTransport();
        // queue() renvoie false -> simule une base indisponible : bascule en envoi direct.
        $digest = new FakeDigestQueue(queueReturn: false);
        $service = $this->makeService(transport: $transport, digest: $digest);

        $service->sendAlert(Severity::Info, NotificationCategory::Lifecycle, 'N3PP', 'Info diverse', 'corps');

        self::assertSame(1, $transport->count(), 'Repli en envoi direct si la file échoue');
    }

    public function testFlushDigestSendsSingleGroupedEmail(): void
    {
        $transport = new FakeMailTransport();
        $digest = new FakeDigestQueue();
        $digest->primeFlush([
            [
                'severity_code' => 'P3',
                'severity_label' => 'Info',
                'severity_emoji' => '🟡',
                'family' => 'MSP1',
                'subject' => 'Réveil programmé',
                'message' => 'Le device est sorti de veille.',
                'count' => 3,
                'last_seen' => '2026-06-24 10:00:00',
            ],
            [
                'severity_code' => 'P4',
                'severity_label' => 'Diagnostic',
                'severity_emoji' => '⚪',
                'family' => 'FFP3',
                'subject' => 'Test périodique',
                'message' => 'ok',
                'count' => 1,
                'last_seen' => '2026-06-24 10:05:00',
            ],
        ]);
        $service = $this->makeService(transport: $transport, digest: $digest);

        $result = $service->flushDigest();

        self::assertTrue($result);
        self::assertSame(1, $transport->count(), 'Le digest envoie un unique e-mail groupé');
        $last = $transport->last();
        self::assertNotNull($last);
        self::assertStringContainsString('[SYNTHÈSE]', $last['subject']);
        self::assertStringContainsString('Réveil programmé', $last['html']);
        self::assertStringContainsString('Test périodique', $last['html']);
        self::assertStringContainsString('Réveil programmé', $last['text']);
    }

    public function testFlushDigestNoopWhenEmpty(): void
    {
        $transport = new FakeMailTransport();
        $digest = new FakeDigestQueue();
        $service = $this->makeService(transport: $transport, digest: $digest);

        $result = $service->flushDigest();

        self::assertFalse($result);
        self::assertSame(0, $transport->count());
    }
}
